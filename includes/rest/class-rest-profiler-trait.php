<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Profiler_Trait')) {
    trait Ultra_Cache_Rest_Profiler_Trait
    {
        private function normalize_performance_profile_mode($mode)
        {
            $mode = sanitize_key((string) $mode);
            return in_array($mode, array('compact', 'verbose', 'callback'), true) ? $mode : 'compact';
        }

        private function normalize_performance_profile_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                $url = home_url('/');
            }

            if (0 === strpos($url, '/')) {
                $url = home_url($url);
            }

            $url = esc_url_raw($url);
            $parts = wp_parse_url($url);
            $home_parts = wp_parse_url(home_url('/'));
            $home_host = isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '';
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

            if ('' === $home_host || '' === $host || $host !== $home_host) {
                return new WP_Error('ultracache_profile_url_not_allowed', __('Only same-site URLs can be scanned.', 'ultracache'));
            }

            if (function_exists('ultracache_is_strict_frontend_loopback_url') && !ultracache_is_strict_frontend_loopback_url($url)) {
                return new WP_Error('ultracache_profile_url_not_allowed', __('Only same-site frontend URLs on the site port can be scanned.', 'ultracache'));
            }

            $scheme = isset($parts['scheme']) && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true) ? strtolower((string) $parts['scheme']) : '';
            if ('' === $scheme) {
                $scheme = isset($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https';
            }

            $path = isset($parts['path']) ? (string) $parts['path'] : '/';
            if ('' === $path) {
                $path = '/';
            }

            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $query = isset($parts['query']) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

            return $scheme . '://' . $host . $port . $path . $query;
        }

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
        private function summarize_performance_profile($profile)
        {
            if (!is_array($profile) || empty($profile)) {
                return array();
            }

            $request_profile = isset($profile['request_profile']) && is_array($profile['request_profile']) ? $profile['request_profile'] : array();
            $checkpoints = isset($request_profile['checkpoints']) && is_array($request_profile['checkpoints']) ? $request_profile['checkpoints'] : array();
            $callbacks = isset($request_profile['callback_timing_summary']) && is_array($request_profile['callback_timing_summary']) ? $request_profile['callback_timing_summary'] : array();
            $callback_timings = isset($request_profile['callback_timings']) && is_array($request_profile['callback_timings']) ? $request_profile['callback_timings'] : array();
            $slow_checkpoints = array();
            foreach ($checkpoints as $checkpoint) {
                if (!is_array($checkpoint)) {
                    continue;
                }
                $delta = isset($checkpoint['since_previous_ms']) ? (int) $checkpoint['since_previous_ms'] : 0;
                if ($delta < 200) {
                    continue;
                }
                $slow_checkpoints[] = array(
                    'stage'    => isset($checkpoint['stage']) ? (string) $checkpoint['stage'] : '',
                    'deltaMs'  => $delta,
                    'atMs'     => isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0,
                    'hook'     => isset($checkpoint['hook']) ? (string) $checkpoint['hook'] : '',
                    'reason'   => isset($checkpoint['reason']) ? (string) $checkpoint['reason'] : '',
                    'source'   => isset($checkpoint['source']) ? (string) $checkpoint['source'] : '',
                    'callback' => isset($checkpoint['callback_label']) ? (string) $checkpoint['callback_label'] : (isset($checkpoint['callback']) ? (string) $checkpoint['callback'] : ''),
                    'origin'   => isset($checkpoint['origin_name']) ? (string) $checkpoint['origin_name'] : '',
                );
            }
            usort($slow_checkpoints, function ($a, $b) {
                return (int) ($b['deltaMs'] ?? 0) <=> (int) ($a['deltaMs'] ?? 0);
            });

            $callback_top = array();
            $origin_totals = array();
            foreach ($callbacks as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $origin_type = isset($entry['origin_type']) ? (string) $entry['origin_type'] : 'unknown';
                $origin_name = isset($entry['origin_name']) ? (string) $entry['origin_name'] : 'unknown';
                $origin_key = trim($origin_type . ':' . $origin_name, ':');
                if ('' === $origin_key) {
                    $origin_key = 'unknown:unknown';
                    $origin_type = 'unknown';
                    $origin_name = 'unknown';
                }

                $total_ms = isset($entry['total_ms']) ? (int) $entry['total_ms'] : 0;
                $max_ms = isset($entry['max_ms']) ? (int) $entry['max_ms'] : 0;
                $count = isset($entry['count']) ? (int) $entry['count'] : 0;
                $callback_label = isset($entry['callback_label']) ? (string) $entry['callback_label'] : '';

                if (!isset($origin_totals[$origin_key])) {
                    $origin_totals[$origin_key] = array(
                        'origin'        => $origin_key,
                        'originType'    => $origin_type,
                        'originName'    => $origin_name,
                        'totalMs'       => 0,
                        'maxMs'         => 0,
                        'count'         => 0,
                        'callbackCount' => 0,
                        'topCallback'   => '',
                        'topCallbackMs' => 0,
                    );
                }

                $origin_totals[$origin_key]['totalMs'] += $total_ms;
                $origin_totals[$origin_key]['count'] += $count;
                $origin_totals[$origin_key]['callbackCount']++;
                if ($max_ms > $origin_totals[$origin_key]['maxMs']) {
                    $origin_totals[$origin_key]['maxMs'] = $max_ms;
                }
                if ($total_ms > $origin_totals[$origin_key]['topCallbackMs']) {
                    $origin_totals[$origin_key]['topCallbackMs'] = $total_ms;
                    $origin_totals[$origin_key]['topCallback'] = $callback_label;
                }
            }

            usort($origin_totals, function ($a, $b) {
                return (int) ($b['totalMs'] ?? 0) <=> (int) ($a['totalMs'] ?? 0);
            });

            foreach (array_slice($callbacks, 0, 12) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $callback_top[] = array(
                    'hook'      => isset($entry['hook']) ? (string) $entry['hook'] : '',
                    'priority'  => isset($entry['priority']) ? (string) $entry['priority'] : '',
                    'callback'  => isset($entry['callback_label']) ? (string) $entry['callback_label'] : '',
                    'origin'    => trim((isset($entry['origin_type']) ? (string) $entry['origin_type'] : '') . ':' . (isset($entry['origin_name']) ? (string) $entry['origin_name'] : ''), ':'),
                    'file'      => isset($entry['origin_file']) ? (string) $entry['origin_file'] : '',
                    'count'     => isset($entry['count']) ? (int) $entry['count'] : 0,
                    'totalMs'   => isset($entry['total_ms']) ? (int) $entry['total_ms'] : 0,
                    'maxMs'     => isset($entry['max_ms']) ? (int) $entry['max_ms'] : 0,
                    'avgMs'     => isset($entry['avg_ms']) ? (int) $entry['avg_ms'] : 0,
                );
            }
            $raw_request_mode = isset($request_profile['mode']) ? sanitize_key((string) $request_profile['mode']) : 'compact';
            if (!in_array($raw_request_mode, array('compact', 'verbose', 'callback'), true)) {
                $raw_request_mode = 'compact';
            }
            $display_mode = count($callbacks) > 0 ? 'callback' : $raw_request_mode;


            $slowest = isset($profile['slowest_stage']) && is_array($profile['slowest_stage']) ? $profile['slowest_stage'] : array();
            $largest = isset($profile['largest_positive_delta']) && is_array($profile['largest_positive_delta']) ? $profile['largest_positive_delta'] : array();
            $css_context = isset($profile['css_bundle_context_after']) && is_array($profile['css_bundle_context_after']) ? $profile['css_bundle_context_after'] : array();
            $critical_request_chain = isset($profile['critical_request_chain']) && is_array($profile['critical_request_chain']) ? $profile['critical_request_chain'] : array();
            $ultracache_overhead_probe = $this->build_ultracache_overhead_probe($checkpoints);
            $css_link_duplication = $this->build_css_link_duplication_diagnostics(isset($critical_request_chain['style_candidates']) && is_array($critical_request_chain['style_candidates']) ? $critical_request_chain['style_candidates'] : array());
            $frontend_rewrite_breakdown = $this->build_frontend_rewrite_stage_breakdown(isset($profile['stages']) && is_array($profile['stages']) ? $profile['stages'] : array());
            $js_delay_safety_scan = isset($profile['js_delay_safety_scan']) && is_array($profile['js_delay_safety_scan']) ? $profile['js_delay_safety_scan'] : array();
            $leftover_css_bundle = isset($profile['leftover_css_bundle']) && is_array($profile['leftover_css_bundle']) ? $profile['leftover_css_bundle'] : array();            $async_css_diagnostics = isset($profile['async_css_diagnostics']) && is_array($profile['async_css_diagnostics']) ? $profile['async_css_diagnostics'] : array();
            $final_stage = array();
            if (isset($profile['stages']) && is_array($profile['stages'])) {
                foreach ($profile['stages'] as $stage_entry) {
                    if (is_array($stage_entry)) {
                        $final_stage = $stage_entry;
                    }
                }
            }

            return array(
                'available'                     => true,
                'version'                       => isset($profile['version']) ? (string) $profile['version'] : (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : ''),
                'requestId'                     => isset($profile['request_id']) ? (string) $profile['request_id'] : '',
                'url'                           => isset($profile['url']) ? (string) $profile['url'] : '',
                'status'                        => isset($profile['status']) ? (string) $profile['status'] : '',
                'reason'                        => isset($profile['reason']) ? (string) $profile['reason'] : '',
                'mode'                          => $display_mode,
                'requestMode'                   => $display_mode,
                'rawRequestMode'                => $raw_request_mode,
                'finishedAtUtc'                 => isset($profile['finished_at_utc']) ? (string) $profile['finished_at_utc'] : '',
                'totalRequestDurationMs'        => isset($profile['total_request_duration_ms']) ? (int) $profile['total_request_duration_ms'] : 0,
                'storeProfileDurationMs'        => isset($profile['total_duration_ms']) ? (int) $profile['total_duration_ms'] : 0,
                'shutdownTotalDurationMs'       => isset($profile['shutdown_total_duration_ms']) ? (int) $profile['shutdown_total_duration_ms'] : 0,
                'unmeasuredBeforeStoreProfileMs'=> isset($request_profile['unmeasured_before_store_profile_ms']) ? (int) $request_profile['unmeasured_before_store_profile_ms'] : 0,
                'checkpointCount'               => count($checkpoints),
                'callbackTimingSummaryCount'    => count($callbacks),
                'callbackTimingsCount'          => count($callback_timings),
                'slowestStage'                  => array(
                    'stage'      => isset($slowest['stage']) ? (string) $slowest['stage'] : '',
                    'durationMs' => isset($slowest['duration_ms']) ? (int) $slowest['duration_ms'] : 0,
                ),
                'largestPositiveDelta'          => array(
                    'stage'      => isset($largest['stage']) ? (string) $largest['stage'] : '',
                    'deltaBytes' => isset($largest['delta_bytes']) ? (int) $largest['delta_bytes'] : 0,
                ),
                'criticalRequestChain'          => array(
                    'available'                   => !empty($critical_request_chain['available']),
                    'renderBlockingStyleCount'    => isset($critical_request_chain['render_blocking_style_count']) ? (int) $critical_request_chain['render_blocking_style_count'] : 0,
                    'renderBlockingScriptCount'   => isset($critical_request_chain['render_blocking_script_count']) ? (int) $critical_request_chain['render_blocking_script_count'] : 0,
                    'delayedScriptCount'          => isset($critical_request_chain['delayed_script_count']) ? (int) $critical_request_chain['delayed_script_count'] : 0,
                    'protectedScriptCount'        => isset($critical_request_chain['protected_script_count']) ? (int) $critical_request_chain['protected_script_count'] : 0,
                    'protectedStyleCount'         => isset($critical_request_chain['protected_style_count']) ? (int) $critical_request_chain['protected_style_count'] : 0,
                    'styleCandidates'             => isset($critical_request_chain['style_candidates']) && is_array($critical_request_chain['style_candidates']) ? array_slice($critical_request_chain['style_candidates'], 0, 40) : array(),
                    'scriptCandidates'            => isset($critical_request_chain['script_candidates']) && is_array($critical_request_chain['script_candidates']) ? array_slice($critical_request_chain['script_candidates'], 0, 60) : array(),
                ),
                'ultraCacheOverheadProbe'      => $ultracache_overhead_probe,
                'frontendRewriteBreakdown'     => $frontend_rewrite_breakdown,
                'cssLinkDuplication'           => $css_link_duplication,
                'jsDelaySafetyScan'            => array(
                    'available'              => !empty($js_delay_safety_scan['available']),
                    'suggestionCount'        => isset($js_delay_safety_scan['suggestion_count']) ? (int) $js_delay_safety_scan['suggestion_count'] : 0,
                    'missingCount'           => isset($js_delay_safety_scan['missing_count']) ? (int) $js_delay_safety_scan['missing_count'] : 0,
                    'alreadyExcludedCount'   => isset($js_delay_safety_scan['already_excluded_count']) ? (int) $js_delay_safety_scan['already_excluded_count'] : 0,
                    'suggestions'            => isset($js_delay_safety_scan['suggestions']) && is_array($js_delay_safety_scan['suggestions']) ? array_slice($js_delay_safety_scan['suggestions'], 0, 80) : array(),
                ),
                'cssBundle'                     => array(
                    'fileExists'                  => !empty($css_context['bundle_file_exists']),
                    'fileBytes'                   => isset($css_context['bundle_file_bytes']) ? (int) $css_context['bundle_file_bytes'] : 0,
                    'sourceUrlCount'              => isset($css_context['source_url_count']) ? (int) $css_context['source_url_count'] : 0,
                    'sourceBytesTotal'            => isset($css_context['source_bytes_total']) ? (int) $css_context['source_bytes_total'] : 0,
                    'largestSourceBytes'          => isset($css_context['largest_source_bytes']) ? (int) $css_context['largest_source_bytes'] : 0,
                    'largestSourceUrl'            => isset($css_context['largest_source_url']) ? (string) $css_context['largest_source_url'] : '',
                    'sourceTop'                   => isset($css_context['source_top']) && is_array($css_context['source_top']) ? array_slice($css_context['source_top'], 0, 12) : array(),
                    'mode'                        => isset($css_context['mode']) ? (string) $css_context['mode'] : '',
                    'largeBundleWarning'          => !empty($css_context['large_bundle_warning']),
                    'veryLargeBundleWarning'      => !empty($css_context['very_large_bundle_warning']),
                    'sourceControlReady'          => !empty($css_context['source_control_ready']),
                    'leftoverCssBundle'          => array(
                        'enabled'               => !empty($leftover_css_bundle['enabled']),
                        'success'               => !empty($leftover_css_bundle['success']),
                        'candidateCount'        => isset($leftover_css_bundle['candidate_count']) ? (int) $leftover_css_bundle['candidate_count'] : 0,
                        'replacedLinkCount'     => isset($leftover_css_bundle['replaced_link_count']) ? (int) $leftover_css_bundle['replaced_link_count'] : 0,
                        'skippedProtectedCount' => isset($leftover_css_bundle['skipped_protected_count']) ? (int) $leftover_css_bundle['skipped_protected_count'] : 0,
                        'skippedReason'         => isset($leftover_css_bundle['skipped_reason']) ? (string) $leftover_css_bundle['skipped_reason'] : '',
                        'bundleUrl'             => isset($leftover_css_bundle['bundle_url']) ? (string) $leftover_css_bundle['bundle_url'] : '',
                        'bundleBytes'           => isset($leftover_css_bundle['bundle_bytes']) ? (int) $leftover_css_bundle['bundle_bytes'] : 0,
                        'sourceBytesTotal'      => isset($leftover_css_bundle['source_bytes_total']) ? (int) $leftover_css_bundle['source_bytes_total'] : 0,
                    ),
                    'finalHtmlBytes'              => isset($final_stage['bytes_out']) ? (int) $final_stage['bytes_out'] : 0,
                    'stylesheetLinks'             => isset($final_stage['stylesheet_links']) ? (int) $final_stage['stylesheet_links'] : 0,
                    'renderBlockingStylesheets'   => isset($final_stage['render_blocking_stylesheet_links']) ? (int) $final_stage['render_blocking_stylesheet_links'] : 0,
                    'renderBlockingBundleLinks'   => isset($final_stage['render_blocking_css_bundle_links']) ? (int) $final_stage['render_blocking_css_bundle_links'] : 0,
                    'renderBlockingNonBundleLinks'=> isset($final_stage['render_blocking_non_bundle_stylesheet_links']) ? (int) $final_stage['render_blocking_non_bundle_stylesheet_links'] : 0,
                    'renderBlockingHrefs'         => isset($final_stage['render_blocking_stylesheet_hrefs']) && is_array($final_stage['render_blocking_stylesheet_hrefs']) ? array_slice($final_stage['render_blocking_stylesheet_hrefs'], 0, 20) : array(),
                    'renderBlockingNonBundleHrefs'=> isset($final_stage['render_blocking_non_bundle_stylesheet_hrefs']) && is_array($final_stage['render_blocking_non_bundle_stylesheet_hrefs']) ? array_slice($final_stage['render_blocking_non_bundle_stylesheet_hrefs'], 0, 20) : array(),
                    'noscriptTags'                => isset($final_stage['noscript_tags']) ? (int) $final_stage['noscript_tags'] : 0,
                    'externalLinks'               => isset($final_stage['page_css_bundle_external_links']) ? (int) $final_stage['page_css_bundle_external_links'] : 0,
                    'inlineStyleTags'             => isset($final_stage['page_css_bundle_inline_style_tags']) ? (int) $final_stage['page_css_bundle_inline_style_tags'] : 0,
                    'inlineStyleBytes'            => isset($final_stage['page_css_bundle_inline_style_bytes']) ? (int) $final_stage['page_css_bundle_inline_style_bytes'] : 0,
                    'fallbackMarkers'             => isset($final_stage['page_css_bundle_fallback_markers']) ? (int) $final_stage['page_css_bundle_fallback_markers'] : 0,
                    'fallbackBlocks'              => isset($final_stage['page_css_bundle_fallback_blocks']) ? (int) $final_stage['page_css_bundle_fallback_blocks'] : 0,
                    'fallbackLinks'               => isset($final_stage['page_css_bundle_fallback_links']) ? (int) $final_stage['page_css_bundle_fallback_links'] : 0,
                    'leftoverBundleRefs'        => isset($final_stage['leftover_css_bundle_refs']) ? (int) $final_stage['leftover_css_bundle_refs'] : 0,
                    'leftoverBundleMarkers'     => isset($final_stage['leftover_css_bundle_markers']) ? (int) $final_stage['leftover_css_bundle_markers'] : 0,                    'asyncCssDiagnostics'        => array(
                        'available'          => !empty($async_css_diagnostics['available']),
                        'enabled'            => !empty($async_css_diagnostics['enabled']),
                        'aggressiveEnabled'  => !empty($async_css_diagnostics['aggressive_enabled']),
                        'externalEnabled'    => !empty($async_css_diagnostics['external_enabled']),
                        'safe'               => !isset($async_css_diagnostics['safe']) || !empty($async_css_diagnostics['safe']),
                        'scanned'            => isset($async_css_diagnostics['scanned']) ? (int) $async_css_diagnostics['scanned'] : 0,
                        'rewritten'          => isset($async_css_diagnostics['rewritten']) ? (int) $async_css_diagnostics['rewritten'] : 0,
                        'skipped'            => isset($async_css_diagnostics['skipped']) ? (int) $async_css_diagnostics['skipped'] : 0,
                        'unresolved'         => isset($async_css_diagnostics['unresolved']) ? (int) $async_css_diagnostics['unresolved'] : 0,
                        'reasonCounts'       => isset($async_css_diagnostics['reason_counts']) && is_array($async_css_diagnostics['reason_counts']) ? $async_css_diagnostics['reason_counts'] : array(),
                        'items'              => isset($async_css_diagnostics['items']) && is_array($async_css_diagnostics['items']) ? array_slice($async_css_diagnostics['items'], 0, 80) : array(),
                    ),
                ),
                'slowCheckpoints'               => array_slice($slow_checkpoints, 0, 12),
                'callbackTop'                   => $callback_top,
                'originTop'                     => array_slice($origin_totals, 0, 12),
            );
        }

        public function get_performance_profile_last()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Speed diagnostics helper is not available.', 'ultracache')), 500);
            }

            $profile = $engine->get_last_store_profile();
            if (!is_array($profile) || empty($profile)) {
                return new WP_REST_Response(array('success' => true, 'message' => __('No speed timing breakdown found yet.', 'ultracache'), 'performanceProfile' => array(), 'profile' => null), 200);
            }

            return new WP_REST_Response(array(
                'success'            => true,
                'message'            => __('Last speed timing breakdown loaded.', 'ultracache'),
                'performanceProfile' => $this->summarize_performance_profile($profile),
                'profile'            => $profile,
            ), 200);
        }

        public function clear_performance_profile_last()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'clear_last_store_profile')) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Speed diagnostics helper is not available.', 'ultracache')), 500);
            }

            $ok = (bool) $engine->clear_last_store_profile();
            return new WP_REST_Response(array(
                'success' => true,
                'cleared' => $ok,
                'message' => $ok ? __('Last speed timing breakdown cleared.', 'ultracache') : __('No speed timing breakdown was present.', 'ultracache'),
            ), 200);
        }

        private function get_performance_profile_for_run($engine, $run_id)
        {
            $run_id = sanitize_key((string) $run_id);
            if ('' !== $run_id && method_exists($engine, 'get_store_profile_by_run_id')) {
                $profile = $engine->get_store_profile_by_run_id($run_id);
                if (is_array($profile) && !empty($profile)) {
                    return $profile;
                }
            }

            $profile = $engine->get_last_store_profile();
            if (!is_array($profile) || empty($profile)) {
                return array();
            }

            if ('' !== $run_id && !empty($profile['profile_run_id']) && sanitize_key((string) $profile['profile_run_id']) !== $run_id) {
                return array();
            }

            return $profile;
        }

        private function wait_for_performance_profile_for_run($engine, $run_id, $attempts = 8, $delay_microseconds = 250000)
        {
            $attempts = max(1, (int) $attempts);
            $delay_microseconds = max(50000, (int) $delay_microseconds);

            for ($i = 0; $i < $attempts; $i++) {
                clearstatcache();
                $profile = $this->get_performance_profile_for_run($engine, $run_id);
                if (is_array($profile) && !empty($profile)) {
                    return $profile;
                }

                if ($i + 1 < $attempts) {
                    usleep($delay_microseconds);
                }
            }

            return array();
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

        private function get_runtime_js_scan_transient_key($scan_id)
        {
            $scan_id = sanitize_key((string) $scan_id);
            return 'ultracache_runtime_js_scan_' . md5($scan_id);
        }

        private function get_runtime_js_scan_current_exclusions()
        {
            $value = '';
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                $settings = Ultra_Cache_WP::get_dashboard_settings_for_client();
                if (is_array($settings) && isset($settings['deferJsExcludeList'])) {
                    $value = (string) $settings['deferJsExcludeList'];
                }
            }
            if ('' === $value) {
                $raw = get_option(ULTRACACHE_SETTINGS_KEY, array());
                if (is_array($raw) && isset($raw['deferJsExcludeList'])) {
                    $value = (string) $raw['deferJsExcludeList'];
                }
            }
            $lines = preg_split('/\r\n|\r|\n/', (string) $value);
            $out = array();
            foreach ((array) $lines as $line) {
                $line = trim((string) $line);
                if ('' !== $line) {
                    $out[] = strtolower($line);
                }
            }
            return array_values(array_unique($out));
        }

        private function runtime_js_scan_exclusion_already_matches($suggestion, array $exclusions)
        {
            $suggestion = strtolower(trim((string) $suggestion));
            if ('' === $suggestion) {
                return false;
            }
            foreach ($exclusions as $line) {
                $line = strtolower(trim((string) $line));
                if ('' === $line) {
                    continue;
                }
                if ($this->runtime_js_scan_is_generic_root_exclusion_line($line)) {
                    if ($this->runtime_js_scan_generic_root_exclusion_covers_suggestion($line, $suggestion)) {
                        return true;
                    }
                    continue;
                }
                if ($line === $suggestion || false !== strpos($suggestion, $line)) {
                    return true;
                }
                if (strlen($line) >= 4 && strlen($suggestion) >= 4 && false !== strpos($line, $suggestion)) {
                    return true;
                }
            }
            return false;
        }

        private function runtime_js_scan_is_generic_root_exclusion_line($line)
        {
            $line = strtolower(trim((string) $line));
            if ('' === $line) {
                return false;
            }

            return in_array($line, array(
                'woocommerce',
                'wordpress',
                'frontend',
                'main',
                'plugin',
                'plugins',
                'script',
                'scripts',
                'data',
                'params',
                'cart',
                'checkout',
                'account',
            ), true);
        }

        private function runtime_js_scan_generic_root_exclusion_covers_suggestion($line, $suggestion)
        {
            $line = strtolower(trim((string) $line));
            $suggestion = strtolower(trim((string) $suggestion));
            if ('' === $line || '' === $suggestion) {
                return false;
            }

            if ($suggestion === $line) {
                return true;
            }

            if ('woocommerce' === $line) {
                return (function_exists('ultracache_public_path_contains') && ultracache_public_path_contains($suggestion, ultracache_plugins_public_path('woocommerce')))
                    || false !== strpos($suggestion, '/woocommerce/assets/');
            }

            return false;
        }

        private function runtime_js_scan_clean_console_candidate($candidate)
        {
            $candidate = html_entity_decode((string) $candidate, ENT_QUOTES, 'UTF-8');
            $candidate = preg_replace('/^[\s\(\[\{\"\'`@]+/', '', $candidate);
            $candidate = preg_replace('/[\s\)\]\}\"\'`,;]+$/', '', (string) $candidate);
            $candidate = preg_replace('/(?::\d+){1,2}$/', '', (string) $candidate);
            $candidate = preg_replace('/[?#].*$/', '', (string) $candidate);
            return trim((string) $candidate);
        }

        private function runtime_js_scan_add_suggestion(&$suggestions, &$seen, $suggested_exclusion, $symbol, $source, $message, $reason, array $exclusions, $confidence = 'high')
        {
            $suggested_exclusion = $this->runtime_js_scan_clean_console_candidate($suggested_exclusion);
            if ('' === $suggested_exclusion) {
                return;
            }
            if ($this->runtime_js_scan_is_generic_token($suggested_exclusion)) {
                return;
            }
            if (preg_match('/\.js$/i', $suggested_exclusion) && $this->runtime_js_scan_is_generic_script_basename(basename($suggested_exclusion))) {
                $suggested_lc = strtolower($suggested_exclusion);
                $has_path_context = false !== strpos($suggested_lc, '/');
                $is_confirmed_provider_path = $this->runtime_js_scan_is_explicit_missing_global_provider_path($suggested_lc, (string) $symbol);
                if (!$has_path_context || !$is_confirmed_provider_path) {
                    return;
                }
            }
            $confidence = strtolower(trim((string) $confidence));
            if ('' === $confidence) {
                $confidence = 'recommended';
            }
            $ignored = in_array($confidence, array('ignored', 'not-fixable'), true);
            $already_excluded = $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $exclusions);
            $appendable = !$ignored && !$already_excluded;
            $key = strtolower($suggested_exclusion . '|' . (string) $source . '|' . (string) $symbol);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $category = $ignored ? 'ignored' : ($already_excluded ? 'already-listed' : 'appendable-fix');
            $category_label = $ignored ? 'Ignored / not fixable by exclusion' : ($already_excluded ? 'Already listed' : 'Appendable fixes');
            $suggestions[] = array(
                'symbol'             => (string) $symbol,
                'source'             => 'browser-runtime-error',
                'category'           => $category,
                'categoryLabel'      => $category_label,
                'sample'             => substr((string) $message, 0, 500),
                'definingScriptUrl'  => (string) $source,
                'definingHandle'     => '',
                'suggestedExclusion' => $suggested_exclusion,
                'confidence'         => $ignored ? 'ignored' : (string) $confidence,
                'reason'             => (string) $reason,
                'alreadyExcluded'    => $already_excluded,
                'appendable'         => $appendable,
            );
        }

        private function runtime_js_scan_add_evidence_source_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
        {
            $text = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
            $candidates = array();
            $candidate_seen = array();
            $push = function ($candidate) use (&$candidates, &$candidate_seen) {
                $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
                if ('' === $candidate) {
                    return;
                }
                $base = $this->runtime_js_scan_basename_from_source($candidate);
                if ('' !== $base && $this->runtime_js_scan_is_generic_script_basename($base)) {
                    return;
                }
                $key = strtolower($candidate);
                if (isset($candidate_seen[$key])) {
                    return;
                }
                $candidate_seen[$key] = true;
                $candidates[] = $candidate;
            };

            foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
                $push($candidate);
            }
            foreach ($this->runtime_js_scan_console_sources_from_text($text) as $candidate) {
                $push($candidate);
            }
            foreach ($this->runtime_js_scan_script_basenames_from_text($text) as $candidate) {
                $push($candidate);
            }

            $added = false;
            foreach ($candidates as $candidate) {
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
                if ('' !== $fragment) {
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $fragment,
                        'console stack source',
                        $candidate,
                        $message,
                        'Found directly in the console error or stack trace. Add this visible exclusion so this script is not delayed/deferred while testing the failing dependency chain.',
                        $exclusions,
                        'recommended'
                    );
                    $added = true;
                }

                $base = $this->runtime_js_scan_basename_from_source($candidate);
                if ('' !== $base && !$this->runtime_js_scan_is_generic_script_basename($base)) {
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $base,
                        'console stack source basename',
                        $candidate,
                        $message,
                        'Found directly in the console error or stack trace. The basename is appendable when the pasted console output does not include a full local WordPress path.',
                        $exclusions,
                        'recommended'
                    );
                    $added = true;
                }

                foreach ($this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts) as $script) {
                    $script_src = isset($script['src']) ? (string) $script['src'] : '';
                    $script_fragment = $this->runtime_js_scan_path_fragment_from_source($script_src, 5);
                    if ('' !== $script_fragment) {
                        $this->runtime_js_scan_add_suggestion(
                            $suggestions,
                            $seen,
                            $script_fragment,
                            'final HTML script inventory match',
                            $script_src,
                            $message,
                            'Matched the console source against the final HTML script inventory and found the exact loaded script path.',
                            $exclusions,
                            'recommended'
                        );
                        $added = true;
                    }
                }
            }

            return $added;
        }

        private function runtime_js_scan_targeted_source_fragment_from_source($source, $fallback_parts = 4)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return '';
            }

            $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
            $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
            $path = (string) wp_parse_url($source, PHP_URL_PATH);
            if ('' === $path) {
                $path = preg_replace('/[?#].*$/', '', $source);
            }

            $path = trim(strtolower((string) $path), '/');
            if ('' === $path) {
                return '';
            }

            $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . $path) : array();
            if (!empty($owner['slug'])) {
                $relative = isset($owner['relative']) ? trim((string) $owner['relative'], '/') : '';
                if ('' === $relative) {
                    return $owner['slug'] . '/';
                }
                return sanitize_text_field(substr($owner['slug'] . '/' . $relative, 0, 220));
            }

            if (false !== strpos($path, 'wp-includes/js/')) {
                return '';
            }

            return $this->runtime_js_scan_path_fragment_from_source($source, $fallback_parts);
        }

        private function runtime_js_scan_add_direct_source_review_suggestion(&$suggestions, &$seen, $source, $message, $reason, array $exclusions, $label = 'runtime error direct source')
        {
            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($source, 4);
            if ('' !== $fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $source, $message, $reason, $exclusions, 'recommended');
                return;
            }

            $source_base = $this->runtime_js_scan_basename_from_source($source);
            if ('' !== $source_base && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, $label . ' basename', $source, $message, $reason, $exclusions, 'recommended');
            }
        }

        private function runtime_js_scan_add_known_specific_error_group_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
        {
            // Intentionally disabled. JS error suggestions must be discovery-only: direct stack sources,
            // final HTML inventory matches for those exact sources, and active plugin/theme code search.
            return false;
        }


        private function runtime_js_scan_owner_group_from_source($source)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return array();
            }

            $decoded = html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8');
            $decoded = preg_replace('/(?::\d+){1,2}$/', '', (string) $decoded);
            $path = (string) wp_parse_url($decoded, PHP_URL_PATH);
            if ('' === $path) {
                $path = preg_replace('/[?#].*$/', '', (string) $decoded);
            }

            $path = trim(strtolower((string) $path), '/');
            if ('' === $path) {
                return array();
            }

            $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . $path) : array();
            if (empty($owner['kind']) || empty($owner['slug'])) {
                return array();
            }

            return array(
                'kind'     => sanitize_text_field((string) $owner['kind']),
                'slug'     => sanitize_key((string) $owner['slug']),
                'group'    => sanitize_text_field((string) $owner['group']),
                'relative' => sanitize_text_field(substr((string) $owner['relative'], 0, 220)),
                'source'   => sanitize_text_field(substr((string) $decoded, 0, 300)),
            );
        }

        private function runtime_js_scan_source_candidates_from_error($source, $message, $detail)
        {
            $candidates = array();
            $seen = array();

            $push = static function ($candidate) use (&$candidates, &$seen) {
                $candidate = trim((string) $candidate);
                if ('' === $candidate) {
                    return;
                }
                $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
                $candidate = preg_replace('/[\s\)\]\}"\'<>;,]+$/', '', (string) $candidate);
                $candidate = preg_replace('/(?::\d+){1,2}$/', '', (string) $candidate);
                $candidate = preg_replace('/[?#].*$/', '', (string) $candidate);
                $candidate = trim((string) $candidate);
                if ('' === $candidate) {
                    return;
                }
                $key = strtolower($candidate);
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $candidates[] = $candidate;
            };

            $push($source);

            $haystack = (string) $source . "\n" . (string) $message . "\n" . (string) $detail;
            if (preg_match_all('#(?:https?:)?//[^\s\)\]\}"\'<>]+#i', $haystack, $matches)) {
                foreach ((array) $matches[0] as $candidate) {
                    $push($candidate);
                }
            }
            $dynamic_root_markers = array();
            if (function_exists('ultracache_plugins_public_path')) {
                $dynamic_root_markers[] = ultracache_plugins_public_path();
            }
            if (function_exists('ultracache_themes_public_paths')) {
                $dynamic_root_markers = array_merge($dynamic_root_markers, ultracache_themes_public_paths());
            }
            foreach (array_filter($dynamic_root_markers) as $marker) {
                $quoted = preg_quote(rtrim((string) $marker, '/'), '#');
                if ('' !== $quoted && preg_match_all('#' . $quoted . '/[^\s\)\]\}"\'<>]+#i', $haystack, $path_matches)) {
                    foreach ((array) $path_matches[0] as $candidate) {
                        $push($candidate);
                    }
                }
            }

            return $candidates;
        }

        private function runtime_js_scan_add_runtime_error_group_resolver_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
        {
            // Intentionally disabled. Owner/group suggestions are produced only by the strict
            // discovery resolver when the current error stack and code search prove the relationship.
            return false;
        }

        private function runtime_js_scan_basename_from_source($source)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return '';
            }

            $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
            $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
            $path = (string) wp_parse_url($source, PHP_URL_PATH);
            if ('' === $path) {
                $path = preg_replace('/[?#].*$/', '', $source);
            }
            $base = basename($path);
            return sanitize_text_field($base);
        }

        private function runtime_js_scan_is_generic_script_basename($basename)
        {
            $basename = strtolower(trim((string) $basename));
            if ('' === $basename) {
                return true;
            }

            return in_array($basename, array(
                'jquery.js',
                'jquery.min.js',
                'jquery-migrate.js',
                'jquery-migrate.min.js',
                'i18n.js',
                'i18n.min.js',
                'hooks.js',
                'hooks.min.js',
                'api-fetch.js',
                'api-fetch.min.js',
                'main.js',
                'main.min.js',
                'functions.js',
                'functions.min.js',
                'function.js',
                'function.min.js',
                'scripts.js',
                'scripts.min.js',
                'script.js',
                'script.min.js',
                'custom.js',
                'custom.min.js',
                'app.js',
                'app.min.js',
                'index.js',
                'index.min.js',
                'site.js',
                'site.min.js',
                'frontend.js',
                'frontend.min.js',
                'public.js',
                'public.min.js',
                'plugin.js',
                'plugin.min.js',
            ), true);
        }

        private function runtime_js_scan_path_fragment_from_source($source, $parts = 4)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return '';
            }

            $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
            $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
            $path = (string) wp_parse_url($source, PHP_URL_PATH);
            if ('' === $path) {
                $path = preg_replace('/[?#].*$/', '', $source);
            }

            $path = trim((string) $path, '/');
            if ('' === $path || false === stripos($path, '.js')) {
                return '';
            }

            $segments = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
            if (empty($segments)) {
                return '';
            }

            $parts = max(2, min(6, (int) $parts));
            $fragment = implode('/', array_slice($segments, -1 * min($parts, count($segments))));
            $base = basename($fragment);
            $owner = function_exists('ultracache_plugin_theme_owner_from_public_source') ? ultracache_plugin_theme_owner_from_public_source('/' . trim((string) $path, '/')) : array();
            $is_targeted_local_asset = !empty($owner['slug']);
            if ($this->runtime_js_scan_is_generic_script_basename($base) && !$is_targeted_local_asset) {
                return '';
            }

            return sanitize_text_field($fragment);
        }

        private function runtime_js_scan_service_fragment_from_source($source, $global = '')
        {
            $source = html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8');
            $source = preg_replace('/(?::\d+){1,2}$/', '', $source);
            $source = trim((string) $source);
            if ('' === $source) {
                return '';
            }

            $parts = wp_parse_url($source);
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            $path = isset($parts['path']) ? trim(strtolower((string) $parts['path']), '/') : '';
            if ('' === $host || '' === $path || false !== stripos($path, '.css')) {
                return '';
            }

            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            $site_host = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
            if ($host === $home_host || $host === $site_host) {
                return '';
            }

            $global = strtolower(trim((string) $global));
            if ('' !== $global && !$this->runtime_js_scan_is_generic_token($global)) {
                $haystack = $host . '/' . $path;
                if (false === strpos($haystack, $global)) {
                    return '';
                }
            }

            $segments = array_values(array_filter(explode('/', $path), 'strlen'));
            if (empty($segments)) {
                return '';
            }
            $path_fragment = implode('/', array_slice($segments, -1 * min(3, count($segments))));
            $fragment = $host . '/' . $path_fragment;
            return sanitize_text_field(substr($fragment, 0, 220));
        }

        private function runtime_js_scan_sanitize_source($source)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return '';
            }

            $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
            if (preg_match('#^https?://#i', $source) || 0 === strpos($source, '/') || 0 === strpos($source, '//')) {
                return esc_url_raw($source);
            }

            // Preserve browser pseudo-sources such as wp-api-fetch-js-after:3225.
            // These inline WordPress handles are not valid URLs, but they are the
            // only reliable clue for mapping an inline-after error to its handle.
            return sanitize_text_field($source);
        }

        private function runtime_js_scan_sanitize_display_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            $url = esc_url_raw($url);
            if ('' === $url) {
                return '';
            }

            return remove_query_arg(array(
                'ultracache_runtime_js_scan',
                'ultracache_runtime_js_scan_id',
                'ultracache_runtime_js_scan_nonce',
                'ultracache_runtime_js_scan_context',
                'ultracache_rt',
                'ultracache_profile_bypass',
                'ultracache_store_profile',
                'ultracache_callback_profile',
                'ultracache_store_profile_verbose',
                'ultracache_store_profile_verbose_settings',
                'ultracache_profile_run',
                'ultracache_revalidate',
            ), $url);
        }

        private function runtime_js_scan_is_explicit_missing_global($symbol)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol) {
                return false;
            }
            $normalized = strtolower(str_replace(array('window.', 'globalThis.'), '', $symbol));
            return in_array($normalized, array(
                'jquery',
                '$',
                '_',
                'underscore',
                'wp',
                'wp.i18n',
                'wp.hooks',
                'wp.template',
                'wp.apifetch',
                'wp.domready',
            ), true);
        }

        private function runtime_js_scan_is_explicit_missing_global_provider_path($path, $symbol)
        {
            $path = strtolower(trim((string) $path));
            $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
            if (false !== strpos($symbol, 'jquery')) {
                $symbol = 'jquery';
            } elseif (false !== strpos($symbol, 'underscore')) {
                $symbol = 'underscore';
            } elseif (false !== strpos($symbol, 'wp.template')) {
                $symbol = 'wp.template';
            } elseif (false !== strpos($symbol, 'wp.i18n')) {
                $symbol = 'wp.i18n';
            } elseif (false !== strpos($symbol, 'wp.hooks')) {
                $symbol = 'wp.hooks';
            } elseif (false !== strpos($symbol, 'wp.apifetch')) {
                $symbol = 'wp.apifetch';
            } elseif (false !== strpos($symbol, 'wp.domready')) {
                $symbol = 'wp.domready';
            }
            if ('' === $path || '' === $symbol) {
                return false;
            }
            if (in_array($symbol, array('jquery', '$'), true)) {
                return false !== strpos($path, 'jquery/jquery.js')
                    || false !== strpos($path, 'jquery/jquery.min.js')
                    || false !== strpos($path, '/jquery.js')
                    || false !== strpos($path, '/jquery.min.js')
                    || false !== strpos($path, 'jquery-core-js');
            }
            if (in_array($symbol, array('_', 'underscore'), true)) {
                return false !== strpos($path, 'underscore.js') || false !== strpos($path, 'underscore.min.js') || false !== strpos($path, 'underscore-js');
            }
            if ('wp.i18n' === $symbol) {
                return false !== strpos($path, 'dist/i18n.js') || false !== strpos($path, 'dist/i18n.min.js') || false !== strpos($path, 'wp-i18n-js');
            }
            if ('wp.hooks' === $symbol) {
                return false !== strpos($path, 'dist/hooks.js') || false !== strpos($path, 'dist/hooks.min.js') || false !== strpos($path, 'wp-hooks-js');
            }
            if ('wp.apifetch' === $symbol) {
                return false !== strpos($path, 'dist/api-fetch.js') || false !== strpos($path, 'dist/api-fetch.min.js') || false !== strpos($path, 'wp-api-fetch-js');
            }
            if ('wp.domready' === $symbol) {
                return false !== strpos($path, 'dist/dom-ready.js') || false !== strpos($path, 'dist/dom-ready.min.js') || false !== strpos($path, 'wp-dom-ready-js');
            }
            if (in_array($symbol, array('wp', 'wp.template'), true)) {
                return false !== strpos($path, 'wp-util.js') || false !== strpos($path, 'wp-util.min.js') || false !== strpos($path, 'wp-util-js');
            }
            return false;
        }

        private function runtime_js_scan_wp_provider_handles_for_missing_global($symbol)
        {
            $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
            if ('$' === $symbol || 'jquery' === $symbol) {
                return array('jquery-core', 'jquery');
            }
            if ('_' === $symbol || 'underscore' === $symbol) {
                return array('underscore');
            }
            if ('wp.template' === $symbol) {
                return array('wp-util');
            }
            if ('wp.i18n' === $symbol) {
                return array('wp-i18n');
            }
            if ('wp.hooks' === $symbol) {
                return array('wp-hooks');
            }
            if ('wp.apifetch' === $symbol) {
                return array('wp-api-fetch');
            }
            if ('wp.domready' === $symbol) {
                return array('wp-dom-ready');
            }
            return array();
        }

        private function runtime_js_scan_registered_script_fragment_for_handle($handle, $symbol = '', array $visited = array())
        {
            $handle = sanitize_key((string) $handle);
            if ('' === $handle || isset($visited[$handle]) || !function_exists('wp_scripts')) {
                return '';
            }
            $visited[$handle] = true;

            $wp_scripts = wp_scripts();
            if (!is_object($wp_scripts) || empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
                return '';
            }

            $registered = $wp_scripts->registered[$handle];
            $src = isset($registered->src) ? (string) $registered->src : '';
            if ('' !== $src) {
                if (0 === strpos($src, '//')) {
                    $src = (is_ssl() ? 'https:' : 'http:') . $src;
                } elseif (0 === strpos($src, '/')) {
                    $src = home_url($src);
                } elseif (!preg_match('#^https?://#i', $src)) {
                    $base_url = isset($wp_scripts->base_url) ? (string) $wp_scripts->base_url : includes_url();
                    $src = trailingslashit($base_url) . ltrim($src, '/');
                }

                $fragment = $this->runtime_js_scan_provider_path_fragment_from_source($src, $symbol);
                if ('' === $fragment) {
                    $fragment = $this->runtime_js_scan_path_fragment_from_source($src, 6);
                }
                if ('' !== $fragment) {
                    return $fragment;
                }
            }

            foreach ((array) ($registered->deps ?? array()) as $dependency) {
                $fragment = $this->runtime_js_scan_registered_script_fragment_for_handle($dependency, $symbol, $visited);
                if ('' !== $fragment) {
                    return $fragment;
                }
            }

            return '';
        }

        private function runtime_js_scan_wp_provider_fragment_for_missing_global($symbol)
        {
            foreach ($this->runtime_js_scan_wp_provider_handles_for_missing_global($symbol) as $handle) {
                $fragment = $this->runtime_js_scan_registered_script_fragment_for_handle($handle, $symbol);
                if ('' !== $fragment) {
                    return $fragment;
                }
            }

            return $this->runtime_js_scan_wp_core_provider_fragment_fallback($symbol);
        }

        /**
         * Resolve well-known WordPress core dependency providers with WordPress URL
         * helpers only when the script registry did not return a registered source.
         *
         * This is not a broad default list. It is only used after a browser error
         * explicitly names the missing dependency, for example "_ is not defined".
         */
        private function runtime_js_scan_wp_core_provider_fragment_fallback($symbol)
        {
            $symbol = strtolower(str_replace(array('window.', 'globalthis.'), '', trim((string) $symbol)));
            if ('' === $symbol || !function_exists('includes_url')) {
                return '';
            }

            $relative = '';
            if ('_' === $symbol || 'underscore' === $symbol) {
                $relative = 'js/underscore.min.js';
            } elseif ('$' === $symbol || 'jquery' === $symbol) {
                $relative = 'js/jquery/jquery.min.js';
            } elseif ('wp.template' === $symbol || 'wp' === $symbol) {
                $relative = 'js/wp-util.min.js';
            } elseif ('wp.i18n' === $symbol) {
                $relative = 'js/dist/i18n.min.js';
            } elseif ('wp.hooks' === $symbol) {
                $relative = 'js/dist/hooks.min.js';
            } elseif ('wp.apifetch' === $symbol) {
                $relative = 'js/dist/api-fetch.min.js';
            } elseif ('wp.domready' === $symbol) {
                $relative = 'js/dist/dom-ready.min.js';
            }

            if ('' === $relative) {
                return '';
            }

            return $this->runtime_js_scan_provider_path_fragment_from_source(includes_url($relative), $symbol);
        }

        private function runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text(&$suggestions, &$seen, $message, $detail, array $exclusions)
        {
            $text = (string) $message . "\n" . (string) $detail;
            if ('' === trim($text)) {
                return false;
            }

            $symbols = array();
            if (preg_match('/(?:ReferenceError:\s*)?_\s+is\s+not\s+defined/i', $text)) {
                $symbols['_'] = '_';
            }
            if (preg_match('/(?:ReferenceError:\s*)?(?:jQuery|\$)\s+is\s+not\s+defined/i', $text)) {
                $symbols['jquery'] = 'jQuery';
            }
            if (preg_match('/(?:TypeError:\s*)?wp\.template\s+is\s+not\s+a\s+function/i', $text)) {
                $symbols['wp.template'] = 'wp.template';
            }
            if (preg_match('/(?:ReferenceError:\s*)?wp\.i18n\s+is\s+not\s+defined/i', $text)) {
                $symbols['wp.i18n'] = 'wp.i18n';
            }
            if (preg_match('/(?:ReferenceError:\s*)?wp\.hooks\s+is\s+not\s+defined/i', $text)) {
                $symbols['wp.hooks'] = 'wp.hooks';
            }
            if (preg_match('/(?:ReferenceError:\s*)?wp\.apiFetch\s+is\s+not\s+defined/i', $text)) {
                $symbols['wp.apifetch'] = 'wp.apiFetch';
            }
            if (preg_match('/(?:ReferenceError:\s*)?wp\.domReady\s+is\s+not\s+defined/i', $text)) {
                $symbols['wp.domready'] = 'wp.domReady';
            }

            $added = false;
            foreach ($symbols as $lookup_symbol => $display_symbol) {
                $provider = $this->runtime_js_scan_wp_provider_fragment_for_missing_global($lookup_symbol);
                if ('' === $provider) {
                    continue;
                }

                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $provider,
                    $display_symbol,
                    $provider,
                    (string) $message,
                    'The browser error explicitly names the missing WordPress dependency "' . sanitize_text_field($display_symbol) . '". UltraCache resolved the exact provider through the WordPress script registry or WordPress core URL helpers. No broad core dependency list was inferred.',
                    $exclusions,
                    'recommended'
                );
                $added = true;
            }

            return $added;
        }

        private function runtime_js_scan_file_uses_missing_symbol($content, $symbol)
        {
            $content = (string) $content;
            $symbol = trim((string) $symbol);
            if ('' === $content || '' === $symbol) {
                return false;
            }
            $normalized = strtolower(str_replace(array('window.', 'globalThis.'), '', $symbol));
            if (in_array($normalized, array('jquery', '$'), true)) {
                return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])(?:jQuery|\$)\s*(?:\.|\(|\[|;|,|\))/m', $content)
                    || false !== strpos($content, 'window.jQuery');
            }
            if (in_array($normalized, array('_', 'underscore'), true)) {
                return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])_\s*(?:\.|\(|\[)/m', $content);
            }
            $quoted = preg_quote($symbol, '/');
            if (false !== strpos($symbol, '.')) {
                return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*(?:\.|\(|\[|;|,|\))/m', $content);
            }
            return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*(?:\.|\(|\[|;|,|\))/m', $content);
        }

        private function runtime_js_scan_source_uses_missing_symbol($source, $symbol, array $scripts = array())
        {
            $source = $this->runtime_js_scan_sanitize_source((string) $source);
            $symbol = trim((string) $symbol);
            if ('' === $source || '' === $symbol) {
                return false;
            }

            foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
                $content = $this->runtime_js_scan_script_content($script);
                if ('' !== $content && $this->runtime_js_scan_file_uses_missing_symbol($content, $symbol)) {
                    return true;
                }
            }

            $content = $this->runtime_js_scan_read_local_script_content($source);
            return '' !== $content && $this->runtime_js_scan_file_uses_missing_symbol($content, $symbol);
        }

        private function runtime_js_scan_provider_path_fragment_from_source($source, $symbol)
        {
            $source = $this->runtime_js_scan_clean_console_candidate($source);
            if ('' === $source) {
                return '';
            }
            $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
            $path = (string) wp_parse_url($source, PHP_URL_PATH);
            if ('' === $path) {
                $path = preg_replace('/[?#].*$/', '', $source);
            }
            $path = '/' . ltrim(str_replace('\\', '/', (string) $path), '/');
            if (!preg_match('/\.(?:js|mjs)$/i', $path)) {
                return '';
            }
            if (!$this->runtime_js_scan_is_explicit_missing_global_provider_path($path, $symbol)) {
                return '';
            }
            return sanitize_text_field(substr($path, 0, 220));
        }

        private function runtime_js_scan_find_provider_scripts_for_missing_global($symbol, array $scripts)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || empty($scripts)) {
                return array();
            }
            $providers = array();
            $seen = array();
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $handle = isset($script['handle']) ? (string) $script['handle'] : '';
                $haystack = strtolower($src . ' ' . $id . ' ' . $handle);
                if (!$this->runtime_js_scan_is_explicit_missing_global_provider_path($haystack, $symbol)) {
                    continue;
                }
                $key = strtolower($src . '|' . $id . '|' . $handle);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $providers[] = array(
                    'src'    => $src,
                    'id'     => $id,
                    'handle' => $handle,
                );
                if (count($providers) >= 6) {
                    break;
                }
            }
            return $providers;
        }

        private function runtime_js_scan_find_scripts_defining_symbol_text($symbol, array $scripts)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return array();
            }

            $matches = array();
            $seen = array();
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }

                $content = $this->runtime_js_scan_script_content($script);
                if ('' === $content || !$this->runtime_js_scan_file_defines_symbol($content, $symbol)) {
                    continue;
                }

                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $handle = isset($script['handle']) ? (string) $script['handle'] : '';
                $key = strtolower($src . '|' . $id . '|' . $handle . '|' . $symbol);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $matches[] = array(
                    'src'    => $src,
                    'id'     => $id,
                    'handle' => $handle,
                );
                if (count($matches) >= 8) {
                    break;
                }
            }
            return $matches;
        }

        private function runtime_js_scan_add_inventory_symbol_provider_suggestions(&$suggestions, &$seen, $symbol, array $scripts, $message, array $exclusions)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return false;
            }

            $providers = $this->runtime_js_scan_find_scripts_defining_symbol_text($symbol, $scripts);
            if (empty($providers)) {
                return false;
            }

            $added = false;
            foreach ($providers as $provider) {
                $this->runtime_js_scan_add_script_identity_suggestions(
                    $suggestions,
                    $seen,
                    $provider,
                    'scanned HTML/global provider',
                    isset($provider['src']) ? (string) $provider['src'] : '',
                    $message,
                    'Runtime Scan found the missing global "' . sanitize_text_field($symbol) . '" in the browser error and found a scanned HTML script block or loaded local script that defines that same global. Keep that provider out of Delay/Defer so the dependent code can execute in order.',
                    $exclusions,
                    'recommended',
                    $symbol
                );
                $added = true;
            }
            return $added;
        }

        private function runtime_js_scan_add_missing_global_provider_suggestions(&$suggestions, &$seen, $symbol, array $direct_sources, array $scripts, $message, array $exclusions)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || !$this->runtime_js_scan_is_explicit_missing_global($symbol)) {
                return false;
            }

            $evidence_sources = array();
            foreach ($direct_sources as $direct) {
                $direct_source = isset($direct['source']) ? (string) $direct['source'] : '';
                if ('' === $direct_source) {
                    continue;
                }
                if ($this->runtime_js_scan_source_uses_missing_symbol($direct_source, $symbol, $scripts)) {
                    $evidence_sources[] = $direct;
                }
            }

            $core_provider_fragment = $this->runtime_js_scan_wp_provider_fragment_for_missing_global($symbol);
            if ('' !== $core_provider_fragment) {
                $this->runtime_js_scan_add_suggestion(
                    $suggestions,
                    $seen,
                    $core_provider_fragment,
                    sanitize_text_field($symbol),
                    $core_provider_fragment,
                    $message,
                    'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing. UltraCache resolved that exact missing dependency through the WordPress script registry. No broad core dependency list was inferred.',
                    $exclusions,
                    'recommended'
                );
                return true;
            }

            $providers = $this->runtime_js_scan_find_provider_scripts_for_missing_global($symbol, $scripts);
            if (empty($providers)) {
                return false;
            }

            $added = false;
            $evidence_fragments = array();
            foreach ($evidence_sources as $direct) {
                if (!empty($direct['fragment'])) {
                    $evidence_fragments[] = (string) $direct['fragment'];
                }
            }
            $evidence_text = !empty($evidence_fragments) ? implode(', ', array_unique($evidence_fragments)) : 'the browser error stack';
            foreach ($providers as $provider) {
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                $provider_fragment = $this->runtime_js_scan_provider_path_fragment_from_source($provider_src, $symbol);
                if ('' === $provider_fragment) {
                    $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 6);
                }
                if ('' !== $provider_fragment) {
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $provider_fragment,
                        'explicit missing global provider: ' . sanitize_text_field($symbol),
                        $provider_src,
                        $message,
                        'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing. Runtime Scan used ' . sanitize_text_field($evidence_text) . ' and matched the loaded provider script from the final page inventory. Add only this provider script; no other core dependencies were inferred.',
                        $exclusions,
                        'recommended'
                    );
                    $added = true;
                } elseif ('' !== $provider_id) {
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $provider_id,
                        'explicit missing global provider handle: ' . sanitize_text_field($symbol),
                        $provider_src,
                        $message,
                        'The browser error explicitly says the global "' . sanitize_text_field($symbol) . '" is missing, and the final page inventory matched this provider handle/id.',
                        $exclusions,
                        'recommended'
                    );
                    $added = true;
                }
            }
            return $added;
        }

        private function runtime_js_scan_is_inline_extra_handle_suggestion($suggestion)
        {
            $suggestion = strtolower(trim((string) $suggestion));
            return '' !== $suggestion && (bool) preg_match('/-js-(?:extra|before|after)$/', $suggestion);
        }

        private function runtime_js_scan_suggestion_base_token($suggestion)
        {
            $suggestion = strtolower(trim((string) $suggestion));
            $suggestion = preg_replace('/-js-(?:extra|before|after)$/', '', $suggestion);
            $suggestion = preg_replace('/-js$/', '', (string) $suggestion);
            $suggestion = preg_replace('/[^a-z0-9_-]+/', '', (string) $suggestion);
            return (string) $suggestion;
        }

        private function runtime_js_scan_finalize_suggestions(array $suggestions)
        {
            $path_items = array();
            foreach ($suggestions as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line = isset($item['suggestedExclusion']) ? strtolower(trim((string) $item['suggestedExclusion'])) : '';
                $source = isset($item['definingScriptUrl']) ? strtolower(trim((string) $item['definingScriptUrl'])) : '';
                if ('' === $line || false === strpos($line, '/')) {
                    continue;
                }
                $path_items[] = array(
                    'line'   => $line,
                    'source' => $source,
                    'base'   => $this->runtime_js_scan_suggestion_base_token(basename($line)),
                );
            }

            $out = array();
            $seen_final = array();
            foreach ($suggestions as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line = isset($item['suggestedExclusion']) ? strtolower(trim((string) $item['suggestedExclusion'])) : '';
                if ('' === $line) {
                    continue;
                }

                $source = isset($item['definingScriptUrl']) ? strtolower(trim((string) $item['definingScriptUrl'])) : '';
                $is_handle_like = false === strpos($line, '/') && !preg_match('/^https?:/i', $line);
                if ($is_handle_like) {
                    $token = $this->runtime_js_scan_suggestion_base_token($line);
                    foreach ($path_items as $path_item) {
                        $path_line = isset($path_item['line']) ? (string) $path_item['line'] : '';
                        $path_source = isset($path_item['source']) ? (string) $path_item['source'] : '';
                        $path_base = isset($path_item['base']) ? (string) $path_item['base'] : '';
                        if ('' !== $source && '' !== $path_line && false !== strpos($source, $path_line)) {
                            continue 2;
                        }
                        if ('' !== $source && '' !== $path_source && $source === $path_source) {
                            continue 2;
                        }
                        if ('' !== $token && '' !== $path_base && (false !== strpos($path_base, $token) || false !== strpos($token, $path_base))) {
                            continue 2;
                        }
                    }
                    if ($this->runtime_js_scan_is_inline_extra_handle_suggestion($line) && !empty($path_items)) {
                        continue;
                    }
                }

                $key = strtolower((string) ($item['suggestedExclusion'] ?? '') . '|' . (string) ($item['definingScriptUrl'] ?? '') . '|' . (string) ($item['symbol'] ?? ''));
                if (isset($seen_final[$key])) {
                    continue;
                }
                $seen_final[$key] = true;
                $out[] = $item;
            }

            return $out;
        }

        private function runtime_js_scan_source_from_text($text)
        {
            $text = (string) $text;
            if ('' === $text) {
                return '';
            }

            if (preg_match('#https?://[^\s\)\]\}"\'<>]+\.js(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}#i', $text, $match)) {
                return $this->runtime_js_scan_sanitize_source((string) $match[0]);
            }

            if (preg_match('/([A-Za-z0-9._\/-]+\.js)(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}/i', $text, $match)) {
                return $this->runtime_js_scan_sanitize_source((string) $match[0]);
            }

            return '';
        }

        private function runtime_js_scan_is_generic_token($token)
        {
            $token = strtolower(trim((string) $token));
            if ('' === $token || strlen($token) < 3) {
                return true;
            }

            return in_array($token, array(
                'function',
                'anonymous',
                'jquery',
                'jquery-core',
                'jquery-migrate',
                'jquery.min.js',
                'jquery-migrate.min.js',
                'wp',
                'wp-i18n',
                'wp-hooks',
                'wp-util',
                'wp-api-fetch',
                'api-fetch',
                'api-fetch.min.js',
                'wp-element',
                'react',
                'react-dom',
                'underscore',
                'backbone',
                'dom-ready',
                'wp-dom-ready',
                'js-translations',
                '-js-translations',
                'core',
                'index',
                'indexof',
                'foreach',
                'forEach',
                'hooks',
                'i18n',
                'setlocaledata',
                'setLocaleData',
                'use',
                'then',
                'catch',
                'prototype',
                'plugin',
                'plugins',
                'script',
                'scripts',
                'javascript',
                'dispatch',
                'handle',
                'each',
                'init',
                'ready',
                'main',
                'map',
                'maps',
                'load',
                'callback',
                'min',
                'ver',
                'html',
                'div',
                'body',
                'window',
                'document',
                'event',
                'error',
                'typeerror',
                'undefined',
                'computed',
                'woocommerce',
                'wordpress',
                'functions',
                'params',
                'data',
                'site',
                'frontend',
                'public',
            ), true);
        }

        private function runtime_js_scan_split_symbol_tokens($symbol)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol) {
                return array();
            }

            $expanded = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $symbol);
            $parts = preg_split('/[^A-Za-z0-9]+/', (string) $expanded);
            $tokens = array();
            foreach ((array) $parts as $part) {
                $token = strtolower(trim((string) $part));
                if ($this->runtime_js_scan_is_generic_token($token)) {
                    continue;
                }
                $tokens[$token] = true;
            }

            return array_keys($tokens);
        }

        private function runtime_js_scan_script_basenames_from_text($text)
        {
            $text = (string) $text;
            $out = array();
            if ('' === $text) {
                return array();
            }

            if (preg_match_all('/(?:https?:\/\/[^\s\)\]\}\"\']+\/)?([^\s\)\]\}\"\'\/]+\.js)(?:\?[^\s\)\]\}\"\']*)?(?::\d+){0,2}/i', $text, $matches)) {
                foreach ((array) $matches[1] as $base) {
                    $base = sanitize_text_field(basename((string) $base));
                    if ('' === $base) {
                        continue;
                    }
                    $lower = strtolower($base);
                    if ($this->runtime_js_scan_is_generic_script_basename($lower)) {
                        continue;
                    }
                    $out[$base] = true;
                }
            }

            return array_keys($out);
        }

        private function runtime_js_scan_url_fragments_from_text($text)
        {
            $text = (string) $text;
            $out = array();
            if ('' === $text) {
                return array();
            }

            if (preg_match_all('#https?://[^\s\)\]\}\"\'<>]+#i', $text, $matches)) {
                foreach ((array) $matches[0] as $url) {
                    $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
                    $url = preg_replace('/(?::\d+){1,2}$/', '', $url);
                    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
                    $path = (string) wp_parse_url($url, PHP_URL_PATH);

                    if ('' !== $host && !$this->runtime_js_scan_is_generic_token($host)) {
                        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
                        $site_host = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
                        if ($host !== $home_host && $host !== $site_host) {
                            $out[$host] = true;
                        }
                    }

                    $path = trim($path, '/');
                    if ('' === $path) {
                        continue;
                    }

                    $parts = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
                    if (count($parts) >= 2) {
                        $last_two = implode('/', array_slice($parts, -2));
                        if (false === strpos($last_two, '.js') && false === strpos($last_two, '.css')) {
                            $out[$last_two] = true;
                        }
                    }
                    if (count($parts) >= 3) {
                        $last_three = implode('/', array_slice($parts, -3));
                        if (false === strpos($last_three, '.js') && false === strpos($last_three, '.css')) {
                            $out[$last_three] = true;
                        }
                    }
                }
            }

            return array_keys($out);
        }

        private function runtime_js_scan_normalize_script_inventory(array $scripts)
        {
            $out = array();
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }

                $src = isset($script['src']) ? $this->runtime_js_scan_sanitize_source((string) $script['src']) : '';
                $id = isset($script['id']) ? sanitize_text_field(substr((string) $script['id'], 0, 160)) : '';
                $handle = isset($script['handle']) ? sanitize_text_field(substr((string) $script['handle'], 0, 160)) : '';
                if ('' === $id && '' !== $handle) {
                    $id = $handle;
                }
                $type = isset($script['type']) ? sanitize_text_field(substr((string) $script['type'], 0, 120)) : '';
                $strategy = isset($script['strategy']) ? sanitize_text_field(substr((string) $script['strategy'], 0, 80)) : '';
                $text = isset($script['text']) ? sanitize_textarea_field(substr((string) $script['text'], 0, 60000)) : '';
                if ('' === $id && '' !== $text) {
                    $source_url_id = $this->runtime_js_scan_source_url_id_from_inline_text($text);
                    if ('' !== $source_url_id) {
                        $id = $source_url_id;
                    }
                }

                if ('' === $src && '' === $id && '' === $text) {
                    continue;
                }

                $out[] = array(
                    'id'       => $id,
                    'handle'   => $handle,
                    'src'      => $src,
                    'type'     => $type,
                    'defer'    => !empty($script['defer']),
                    'async'    => !empty($script['async']),
                    'strategy' => $strategy,
                    'delayed'  => !empty($script['delayed']),
                    'text'     => $text,
                );

                if (count($out) >= 240) {
                    break;
                }
            }

            return $out;
        }

        private function runtime_js_scan_inventory_summary(array $scripts)
        {
            $summary = array(
                'total'      => count($scripts),
                'external'   => 0,
                'inline'     => 0,
                'delayed'    => 0,
                'sourceUrl'  => 0,
            );

            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                if (!empty($script['src'])) {
                    $summary['external']++;
                } else {
                    $summary['inline']++;
                }
                if (!empty($script['delayed'])) {
                    $summary['delayed']++;
                }
                $text = isset($script['text']) ? (string) $script['text'] : '';
                if ('' !== $text && '' !== $this->runtime_js_scan_source_url_id_from_inline_text($text)) {
                    $summary['sourceUrl']++;
                }
            }

            return $summary;
        }

        private function runtime_js_scan_processor_attribute($processor, $name)
        {
            if (!$processor instanceof WP_HTML_Tag_Processor) {
                return '';
            }

            $value = $processor->get_attribute((string) $name);
            if (null === $value || false === $value) {
                return '';
            }
            if (true === $value) {
                return (string) $name;
            }

            return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        private function runtime_js_scan_url_to_absolute($url, $base_url = '')
        {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ('' === $url) {
                return '';
            }
            if (preg_match('#^https?://#i', $url)) {
                return esc_url_raw($url);
            }
            if (0 === strpos($url, '//')) {
                $scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
                return esc_url_raw(($scheme ? $scheme : 'https') . ':' . $url);
            }
            if (0 === strpos($url, '/')) {
                return esc_url_raw(home_url($url));
            }

            $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
            $parts = wp_parse_url($base_url);
            if (empty($parts['host'])) {
                return esc_url_raw(home_url('/' . ltrim($url, '/')));
            }

            $scheme = !empty($parts['scheme']) ? (string) $parts['scheme'] : 'https';
            $host = (string) $parts['host'];
            $port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = !empty($parts['path']) ? (string) $parts['path'] : '/';
            $dir = rtrim(str_replace('\\\\', '/', dirname($path)), '/');
            if ('.' === $dir || '' === $dir) {
                $dir = '';
            }

            $combined = $dir . '/' . ltrim($url, '/');
            $segments = array();
            foreach (explode('/', $combined) as $segment) {
                if ('' === $segment || '.' === $segment) {
                    continue;
                }
                if ('..' === $segment) {
                    array_pop($segments);
                    continue;
                }
                $segments[] = $segment;
            }

            return esc_url_raw($scheme . '://' . $host . $port . '/' . implode('/', $segments));
        }

        private function runtime_js_scan_source_url_id_from_inline_text($text)
        {
            $text = (string) $text;
            if ('' === $text) {
                return '';
            }
            if (preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $text, $match)) {
                $id = trim((string) $match[1]);
                $id = preg_replace('/[?#].*$/', '', $id);
                $id = basename($id);
                $id = sanitize_text_field(substr((string) $id, 0, 160));
                if ('' !== $id && !$this->runtime_js_scan_is_generic_token($id)) {
                    return $id;
                }
            }
            return '';
        }

        private function runtime_js_scan_find_scripts_with_symbol_text($symbol, array $scripts)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return array();
            }
            $matches = array();
            $seen = array();
            $symbol_regex = preg_quote($symbol, '/');
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $content = $this->runtime_js_scan_script_content($script);
                if ('' === $content || !preg_match('/\b' . $symbol_regex . '\b/', $content)) {
                    continue;
                }
                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $key = strtolower($src . '|' . $id);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $matches[] = array('src' => $src, 'id' => $id);
                if (count($matches) >= 12) {
                    break;
                }
            }
            return $matches;
        }

        private function runtime_js_scan_find_scripts_by_global_source_hint($global, array $scripts)
        {
            $global = strtolower(trim((string) $global));
            if ('' === $global || $this->runtime_js_scan_is_generic_token($global)) {
                return array();
            }

            $matches = array();
            $seen = array();
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }

                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $handle = isset($script['handle']) ? (string) $script['handle'] : '';
                $haystack = strtolower(html_entity_decode($src . ' ' . $id . ' ' . $handle, ENT_QUOTES, 'UTF-8'));
                if ('' === trim($haystack)) {
                    continue;
                }

                $matched = false;
                if (preg_match('/(?:^|[^a-z0-9_$])' . preg_quote($global, '/') . '(?:[^a-z0-9_$]|$)/i', $haystack)) {
                    $matched = true;
                } elseif ('' !== $src && '' !== $this->runtime_js_scan_service_fragment_from_source($src, $global)) {
                    $matched = true;
                }

                if (!$matched) {
                    continue;
                }

                $key = strtolower($src . '|' . $id . '|' . $handle);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $matches[] = array(
                    'src'    => $src,
                    'id'     => $id,
                    'handle' => $handle,
                );
                if (count($matches) >= 12) {
                    break;
                }
            }

            return $matches;
        }

        private function runtime_js_scan_dynamic_callback_globals_from_text($text)
        {
            $text = (string) $text;
            if ('' === trim($text)) {
                return array();
            }
            $out = array();
            $identifier = '[A-Za-z_$][A-Za-z0-9_$]*';
            if (preg_match_all('/["\']?([A-Za-z0-9_$.-]*(?:function|callback|handler|method)[A-Za-z0-9_$.-]*)["\']?\s*:\s*["\'](' . $identifier . ')["\']/i', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $value = isset($match[2]) ? sanitize_text_field((string) $match[2]) : '';
                    if ('' !== $value && !$this->runtime_js_scan_is_generic_token($value)) {
                        $out[$value] = $value;
                    }
                }
            }
            if (preg_match_all('/["\'](' . $identifier . ')["\']\s*,\s*["\'](?:event|config|consent|set|js)["\']/i', $text, $call_matches, PREG_SET_ORDER)) {
                foreach ($call_matches as $match) {
                    $value = isset($match[1]) ? sanitize_text_field((string) $match[1]) : '';
                    if ('' !== $value && !$this->runtime_js_scan_is_generic_token($value)) {
                        $out[$value] = $value;
                    }
                }
            }
            return array_values($out);
        }

        private function runtime_js_scan_add_script_identity_suggestions(&$suggestions, &$seen, array $script, $label, $source, $message, $reason, array $exclusions, $confidence = 'review', $global = '')
        {
            $script_src = isset($script['src']) ? (string) $script['src'] : '';
            $script_id = isset($script['id']) ? (string) $script['id'] : '';
            $source_for_display = '' !== $script_src ? $script_src : ('' !== $script_id ? $script_id : $source);
            $fragment = $this->runtime_js_scan_path_fragment_from_source($script_src, 4);
            $has_path_or_service_suggestion = false;
            if ('' !== $fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $source_for_display, $message, $reason, $exclusions, $confidence);
                $has_path_or_service_suggestion = true;
            } else {
                $service_fragment = $this->runtime_js_scan_service_fragment_from_source($script_src, $global);
                if ('' !== $service_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $service_fragment, $label . ' service endpoint', $source_for_display, $message, $reason, $exclusions, 'recommended');
                    $has_path_or_service_suggestion = true;
                }
            }
            if ('' !== $script_id) {
                $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($script_id);
                if (!$has_path_or_service_suggestion && '' !== $related_id && isset($GLOBALS['ultracache_runtime_js_scan_scripts'])) {
                    $related = $this->runtime_js_scan_find_script_by_id((array) $GLOBALS['ultracache_runtime_js_scan_scripts'], $related_id);
                    if (!empty($related) && !empty($related['src'])) {
                        $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $related, $label . ' related external', $source_for_display, $message, $reason, $exclusions, $confidence, $global);
                        return;
                    }
                }
                if (!$has_path_or_service_suggestion) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $script_id, $label . ' handle/id', $source_for_display, $message, $reason, $exclusions, $confidence);
                }
                if ('' !== $related_id && isset($GLOBALS['ultracache_runtime_js_scan_scripts'])) {
                    $related = $this->runtime_js_scan_find_script_by_id((array) $GLOBALS['ultracache_runtime_js_scan_scripts'], $related_id);
                    if (!empty($related) && empty($related['src'])) {
                        $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $related, $label . ' related external', $source_for_display, $message, $reason, $exclusions, $confidence, $global);
                    }
                }
            }
        }

        private function runtime_js_scan_add_dynamic_window_global_suggestions(&$suggestions, &$seen, array $scripts, $source, $message, $detail, array $exclusions)
        {
            $reason = 'A dynamic window[callbackName]() call failed. UltraCache resolved possible callback globals from scanned inline config, sourceURL markers, and stack-frame context. It only shows actual symbols and script ids/paths found in that scanned page.';
            $context_ids = $this->runtime_js_scan_inline_frame_ids_from_text((string) $detail . "
" . (string) $message);
            $context_scripts = array();
            foreach ($context_ids as $inline_id) {
                $script = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
                if (!empty($script)) {
                    $context_scripts[] = $script;
                }
            }
            foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
                if (!empty($script)) {
                    $context_scripts[] = $script;
                }
            }
            if (empty($context_scripts)) {
                $context_scripts = $scripts;
            }

            $globals = array();
            foreach ($context_scripts as $script) {
                $content = $this->runtime_js_scan_script_content($script);
                foreach ($this->runtime_js_scan_dynamic_callback_globals_from_text($content) as $global) {
                    $globals[$global] = $global;
                }
            }

            $GLOBALS['ultracache_runtime_js_scan_scripts'] = $scripts;
            foreach ($globals as $global) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $global, 'resolved dynamic window callback global', $source, $message, $reason, $exclusions, 'recommended');
                foreach ($this->runtime_js_scan_find_scripts_with_symbol_text($global, $scripts) as $provider) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback context script', $source, $message, $reason, $exclusions, 'recommended', $global);
                }
                foreach ($this->runtime_js_scan_find_scripts_by_global_source_hint($global, $scripts) as $provider) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback source/provider hint', $source, $message, $reason, $exclusions, 'recommended', $global);
                }
            }
            unset($GLOBALS['ultracache_runtime_js_scan_scripts']);
        }

        private function runtime_js_scan_fetch_script_inventory_for_url($url = '')
        {
            $url = trim((string) $url);
            if ('' === $url) {
                $url = home_url('/');
            }

            $normalized = $this->normalize_performance_profile_url($url);
            if (is_wp_error($normalized)) {
                return array();
            }

            $request_url = add_query_arg(array(
                'ultracache_js_inventory' => '1',
                'ultracache_rt'           => time(),
            ), $normalized);

            $response = ultracache_safe_loopback_remote_request($request_url, array(
                'timeout'     => 8,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'        => 'text/html,application/xhtml+xml',
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
                'user-agent'  => 'UltraCache JS inventory/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
            ), 'runtime-js-inventory-scan');
            if (is_wp_error($response)) {
                return array();
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                return array();
            }

            $html = (string) wp_remote_retrieve_body($response);
            if ('' === $html || !class_exists('WP_HTML_Tag_Processor')) {
                return array();
            }

            $scripts = array();
            try {
                $processor = new WP_HTML_Tag_Processor($html);
                while ($processor->next_tag('SCRIPT')) {
                    $src = $this->runtime_js_scan_processor_attribute($processor, 'src');
                    if ('' === $src) {
                        $src = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-src');
                    }
                    if ('' === $src) {
                        $src = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-original-src');
                    }

                    $body = method_exists($processor, 'get_modifiable_text') ? (string) $processor->get_modifiable_text() : '';
                    $id = $this->runtime_js_scan_processor_attribute($processor, 'id');
                    if ('' === $id) {
                        $id = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-id');
                    }
                    if ('' === $id) {
                        $source_url_id = $this->runtime_js_scan_source_url_id_from_inline_text($body);
                        if ('' !== $source_url_id) {
                            $id = $source_url_id;
                        }
                    }
                    if ('' === $id) {
                        $handle_id = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-handle');
                        if ('' !== $handle_id) {
                            $id = $handle_id;
                        }
                    }

                    $type = $this->runtime_js_scan_processor_attribute($processor, 'type');
                    $handle = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-handle');
                    $strategy = $this->runtime_js_scan_processor_attribute($processor, 'data-wp-strategy');
                    $is_delayed = (null !== $processor->get_attribute('data-ultracache-src')
                        || null !== $processor->get_attribute('data-ultracache-inline')
                        || null !== $processor->get_attribute('data-ultracache-delayed')
                        || false !== stripos($type, 'ultracache-delayed'));

                    $scripts[] = array(
                        'id'       => sanitize_text_field(substr($id, 0, 160)),
                        'handle'   => sanitize_text_field(substr($handle, 0, 160)),
                        'src'      => '' !== $src ? $this->runtime_js_scan_url_to_absolute($src, $normalized) : '',
                        'type'     => sanitize_text_field(substr($type, 0, 120)),
                        'defer'    => null !== $processor->get_attribute('defer'),
                        'async'    => null !== $processor->get_attribute('async'),
                        'strategy' => $strategy,
                        'delayed'  => $is_delayed,
                        'text'     => '' === $src || $is_delayed ? sanitize_textarea_field(substr($body, 0, 60000)) : '',
                    );

                    if (count($scripts) >= 240) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                return array();
            }

            return $this->runtime_js_scan_normalize_script_inventory($scripts);
        }

        private function runtime_js_scan_local_file_path_from_script_src($src)
        {
            $src = $this->runtime_js_scan_clean_console_candidate($src);
            if ('' === $src) {
                return '';
            }

            $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
            $absolute = $this->runtime_js_scan_url_to_absolute($src);
            if ('' === $absolute || !function_exists('ultracache_local_path_from_public_url')) {
                return '';
            }

            $path = ultracache_local_path_from_public_url($absolute, array('js', 'mjs'));
            if ('' === $path || !is_file($path) || !is_readable($path)) {
                return '';
            }

            $size = filesize($path);
            if (false === $size || $size <= 0 || $size > 786432) {
                return '';
            }

            return $path;
        }

        private function runtime_js_scan_read_local_script_content($src)
        {
            static $cache = array();
            $src_key = md5((string) $src);
            if (array_key_exists($src_key, $cache)) {
                return $cache[$src_key];
            }

            $path = $this->runtime_js_scan_local_file_path_from_script_src($src);
            if ('' === $path) {
                $cache[$src_key] = '';
                return '';
            }

            $content = '';
            if (function_exists('ultracache_guarded_asset_file_get_contents')) {
                $raw = ultracache_guarded_asset_file_get_contents($path, 'js', 'runtime_js_scan_read_local_script_content', true);
                if (is_string($raw)) {
                    $content = $raw;
                }
            }

            if (strlen($content) > 786432) {
                $content = '';
            }
            $cache[$src_key] = $content;
            return $content;
        }

        private function runtime_js_scan_script_content($script)
        {
            if (!is_array($script)) {
                return '';
            }
            $text = isset($script['text']) ? (string) $script['text'] : '';
            if ('' !== trim($text)) {
                return $text;
            }
            $src = isset($script['src']) ? (string) $script['src'] : '';
            if ('' === $src) {
                return '';
            }
            return $this->runtime_js_scan_read_local_script_content($src);
        }

        private function runtime_js_scan_find_jquery_plugin_provider_scripts($method, array $scripts)
        {
            $method = trim((string) $method);
            if ('' === $method || empty($scripts)) {
                return array();
            }

            $providers = array();
            $seen = array();
            $method_regex = preg_quote($method, '/');
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $content = $this->runtime_js_scan_script_content($script);
                if ('' === $content) {
                    continue;
                }

                $matched = false;
                if (preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\.\\s*' . $method_regex . '\\s*=|(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\[\\s*["\\\']' . $method_regex . '["\\\']\\s*\\]\\s*=/i', $content)) {
                    $matched = true;
                } elseif (preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\s*\\.\\s*extend\\s*\\(/i', $content) && preg_match('/["\\\']?' . $method_regex . '["\\\']?\\s*:/i', $content)) {
                    $matched = true;
                } elseif (false !== stripos($content, $method) && preg_match('/(?:jQuery|\\$)\\s*\\.\\s*fn\\b|\\.fn\\b/i', $content)) {
                    $matched = true;
                }

                if (!$matched) {
                    continue;
                }

                $key = strtolower($src . '|' . $id);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $providers[] = array(
                    'src' => $src,
                    'id'  => $id,
                );

                if (count($providers) >= 8) {
                    break;
                }
            }

            return $providers;
        }

        private function runtime_js_scan_find_symbol_provider_scripts($symbol, array $scripts)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol) || empty($scripts)) {
                return array();
            }

            $providers = array();
            $seen = array();
            $symbol_regex = preg_quote($symbol, '/');
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $content = $this->runtime_js_scan_script_content($script);
                if ('' === $content) {
                    continue;
                }

                $matched = false;
                if (preg_match('/(?:function|class|var|let|const)\\s+' . $symbol_regex . '\\b/i', $content)) {
                    $matched = true;
                } elseif (preg_match('/(?:window|globalThis)\\s*\\.\\s*' . $symbol_regex . '\\b\\s*=/i', $content)) {
                    $matched = true;
                } elseif (preg_match('/\\b' . $symbol_regex . '\\s*=\\s*(?:function|\\(|\\{|new\\s+|class\\b)/i', $content)) {
                    $matched = true;
                } elseif (false !== stripos($content, $symbol) && false !== stripos((string) $src . ' ' . (string) $id, strtolower($symbol))) {
                    $matched = true;
                }

                if (!$matched) {
                    continue;
                }

                $key = strtolower($src . '|' . $id);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $providers[] = array(
                    'src' => $src,
                    'id'  => $id,
                );

                if (count($providers) >= 8) {
                    break;
                }
            }

            return $providers;
        }

        private function runtime_js_scan_find_scripts_by_source_hint($source, array $scripts)
        {
            $source = $this->runtime_js_scan_sanitize_source((string) $source);
            if ('' === $source || empty($scripts)) {
                return array();
            }

            $source_lc = strtolower(html_entity_decode((string) $source, ENT_QUOTES, 'UTF-8'));
            $source_lc = preg_replace('/(?::\d+){1,2}$/', '', $source_lc);
            $source_base = strtolower($this->runtime_js_scan_basename_from_source($source_lc));
            $source_fragment = strtolower($this->runtime_js_scan_path_fragment_from_source($source_lc, 6));
            $source_path = (string) wp_parse_url($source_lc, PHP_URL_PATH);
            if ('' === $source_path) {
                $source_path = preg_replace('/[?#].*$/', '', $source_lc);
            }
            $source_path = trim(strtolower((string) $source_path), '/');

            $matches = array();
            $seen = array();
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }

                $script_src = isset($script['src']) ? $this->runtime_js_scan_sanitize_source((string) $script['src']) : '';
                $script_id = isset($script['id']) ? sanitize_text_field((string) $script['id']) : '';
                $script_src_lc = strtolower(html_entity_decode((string) $script_src, ENT_QUOTES, 'UTF-8'));
                $script_id_lc = strtolower((string) $script_id);
                $script_base = strtolower($this->runtime_js_scan_basename_from_source($script_src_lc));
                $script_fragment = strtolower($this->runtime_js_scan_path_fragment_from_source($script_src_lc, 6));
                $script_path = (string) wp_parse_url($script_src_lc, PHP_URL_PATH);
                if ('' === $script_path) {
                    $script_path = preg_replace('/[?#].*$/', '', $script_src_lc);
                }
                $script_path = trim(strtolower((string) $script_path), '/');

                $matched = false;
                $score = 0;
                if ('' !== $source_fragment && '' !== $script_fragment && (false !== strpos($script_fragment, $source_fragment) || false !== strpos($source_fragment, $script_fragment))) {
                    $matched = true;
                    $score = 100;
                } elseif ('' !== $source_path && '' !== $script_path && (false !== strpos($script_path, $source_path) || false !== strpos($source_path, $script_path))) {
                    $matched = true;
                    $score = 90;
                } elseif ('' !== $source_base && '' !== $script_base && $source_base === $script_base) {
                    $matched = true;
                    $score = $this->runtime_js_scan_is_generic_script_basename($source_base) ? 55 : 75;
                } elseif ('' !== $source_lc && '' !== $script_id_lc && false !== strpos($source_lc, $script_id_lc)) {
                    $matched = true;
                    $score = 60;
                }

                if (!$matched) {
                    continue;
                }

                $key = strtolower($script_src . '|' . $script_id);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $script['_ultracache_match_score'] = $score;
                $matches[] = $script;
            }

            usort($matches, static function ($a, $b) {
                $a_score = isset($a['_ultracache_match_score']) ? (int) $a['_ultracache_match_score'] : 0;
                $b_score = isset($b['_ultracache_match_score']) ? (int) $b['_ultracache_match_score'] : 0;
                if ($a_score === $b_score) {
                    return 0;
                }
                return ($a_score > $b_score) ? -1 : 1;
            });

            return array_slice($matches, 0, 12);
        }

        private function runtime_js_scan_find_script_by_id(array $scripts, $id)
        {
            $id = trim((string) $id);
            if ('' === $id) {
                return array();
            }
            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $script_id = isset($script['id']) ? trim((string) $script['id']) : '';
                $script_handle = isset($script['handle']) ? trim((string) $script['handle']) : '';
                if ($script_id === $id || $script_handle === $id) {
                    return $script;
                }
            }
            return array();
        }

        private function runtime_js_scan_add_existing_inline_companion_suggestions(&$suggestions, &$seen, array $scripts, $script_id, $source, $message, $reason, array $exclusions)
        {
            $script_id = trim((string) $script_id);
            if ('' === $script_id || !preg_match('/-js$/i', $script_id)) {
                return;
            }

            foreach (array($script_id . '-before' => 'inline-before config block', $script_id . '-after' => 'inline-after config block') as $companion_id => $label) {
                $companion = $this->runtime_js_scan_find_script_by_id($scripts, $companion_id);
                if (empty($companion)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $companion_id, $label, $source, $message, $reason, $exclusions, 'recommended');
            }
        }


        private function runtime_js_scan_inline_text_uses_symbol($text, $symbol)
        {
            $text = (string) $text;
            $symbol = trim((string) $symbol);
            if ('' === $text || '' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return false;
            }

            $symbol_regex = preg_quote($symbol, '/');
            return (bool) preg_match('/(?:^|[^A-Za-z0-9_$])' . $symbol_regex . '\s*(?:\[|\.|\(|;|,|=|\)|\}|$)/', $text);
        }

        private function runtime_js_scan_find_html_adjacency_dependencies($symbol, array $scripts)
        {
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol) || count($scripts) < 2) {
                return array();
            }

            $matches = array();
            $seen = array();
            $count = count($scripts);
            for ($index = 1; $index < $count; $index++) {
                $inline = isset($scripts[$index]) && is_array($scripts[$index]) ? $scripts[$index] : array();
                $provider = isset($scripts[$index - 1]) && is_array($scripts[$index - 1]) ? $scripts[$index - 1] : array();
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $inline_src = isset($inline['src']) ? (string) $inline['src'] : '';
                $inline_text = isset($inline['text']) ? (string) $inline['text'] : '';

                if ('' === $provider_src || '' !== $inline_src || '' === trim($inline_text)) {
                    continue;
                }
                if (!$this->runtime_js_scan_inline_text_uses_symbol($inline_text, $symbol)) {
                    continue;
                }

                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 5);
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                if ('' === $provider_fragment && ('' === $provider_base || $this->runtime_js_scan_is_generic_script_basename($provider_base))) {
                    continue;
                }

                $dedupe_key = strtolower($provider_src . '|' . (isset($inline['id']) ? (string) $inline['id'] : '') . '|' . $symbol);
                if (isset($seen[$dedupe_key])) {
                    continue;
                }
                $seen[$dedupe_key] = true;
                $matches[] = array(
                    'provider' => $provider,
                    'inline'   => $inline,
                );

                if (count($matches) >= 6) {
                    break;
                }
            }

            return $matches;
        }

        private function runtime_js_scan_add_html_adjacency_suggestions(&$suggestions, &$seen, $symbol, array $scripts, $source, $message, array $exclusions)
        {
            $pairs = $this->runtime_js_scan_find_html_adjacency_dependencies($symbol, $scripts);
            if (empty($pairs)) {
                return false;
            }

            $matched = false;
            foreach ($pairs as $pair) {
                $provider = isset($pair['provider']) && is_array($pair['provider']) ? $pair['provider'] : array();
                $inline = isset($pair['inline']) && is_array($pair['inline']) ? $pair['inline'] : array();
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                $inline_id = isset($inline['id']) ? (string) $inline['id'] : '';
                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 5);
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                $context = trim((string) $provider_id . ('' !== $inline_id ? ' → ' . $inline_id : ''));
                $reason = 'Final HTML adjacency resolver found an external script immediately followed by an inline block that reads the missing global "' . $symbol . '". Keep the external provider script out of Safe Defer/Delay so the inline dependency can execute in order.' . ('' !== $context ? ' Script order: ' . $context . '.' : '');

                if ('' !== $provider_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'HTML adjacency external provider', $provider_src, $message, $reason, $exclusions, 'confirmed');
                    $matched = true;
                }

                if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'HTML adjacency provider basename', $provider_src, $message, $reason, $exclusions, 'confirmed');
                    $matched = true;
                }
            }

            return $matched;
        }


        private function runtime_js_scan_inline_frame_ids_from_text($text)
        {
            $text = (string) $text;
            if ('' === trim($text)) {
                return array();
            }

            $ids = array();
            if (preg_match_all('/\b([A-Za-z0-9_.-]+-js-(?:before|after|extra|translations))(?::\d+(?::\d+)?)?/i', $text, $matches)) {
                foreach ((array) $matches[1] as $id) {
                    $id = sanitize_text_field(substr((string) $id, 0, 160));
                    if ('' !== $id) {
                        $ids[strtolower($id)] = $id;
                    }
                }
            }

            return array_values($ids);
        }

        private function runtime_js_scan_related_external_id_for_inline_id($inline_id)
        {
            $inline_id = trim((string) $inline_id);
            if ('' === $inline_id) {
                return '';
            }
            if (preg_match('/^(.*-js)-(?:before|after|extra|translations)$/i', $inline_id, $match)) {
                return sanitize_text_field((string) $match[1]);
            }
            return '';
        }

        private function runtime_js_scan_add_inline_stack_frame_suggestions(&$suggestions, &$seen, array $scripts, $text, $message, $reason, array $exclusions, $confidence = 'review')
        {
            foreach ($this->runtime_js_scan_inline_frame_ids_from_text($text) as $inline_id) {
                $script = $this->runtime_js_scan_find_script_by_id($scripts, $inline_id);
                $source = !empty($script['src']) ? (string) $script['src'] : $inline_id;
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $inline_id, 'inline stack-frame handle/id', $source, $message, $reason, $exclusions, $confidence);

                $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($inline_id);
                if ('' === $related_id) {
                    continue;
                }

                $related = $this->runtime_js_scan_find_script_by_id($scripts, $related_id);
                if (!empty($related)) {
                    $related_src = isset($related['src']) ? (string) $related['src'] : '';
                    $related_fragment = $this->runtime_js_scan_path_fragment_from_source($related_src, 4);
                    if ('' !== $related_fragment) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_fragment, 'inline stack-frame related external script', $related_src, $message, $reason, $exclusions, $confidence);
                    }
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_id, 'inline stack-frame related handle/id', $related_src, $message, $reason, $exclusions, $confidence);
                }
            }
        }

        private function runtime_js_scan_add_script_source_resolution_suggestions(&$suggestions, &$seen, array $scripts, $source, $message, $reason, array $exclusions, $label = 'resolved error source script', $confidence = 'review', $include_existing_inline_companions = false)
        {
            foreach ($this->runtime_js_scan_find_scripts_by_source_hint($source, $scripts) as $script) {
                $script_src = isset($script['src']) ? (string) $script['src'] : '';
                $script_id = isset($script['id']) ? (string) $script['id'] : '';
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($script_src, 4);
                if ('' !== $fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $script_src, $message, $reason, $exclusions, $confidence);
                }

                if ('' !== $script_id) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $script_id, $label . ' handle/id', $script_src, $message, $reason, $exclusions, $confidence);
                    if ($include_existing_inline_companions) {
                        $this->runtime_js_scan_add_existing_inline_companion_suggestions($suggestions, $seen, $scripts, $script_id, $script_src, $message, 'The scanned page contains an inline companion script next to this external script. Keep existing inline companion ids ordered with their dependent external script.', $exclusions);
                    }
                }
            }
        }

        private function runtime_js_scan_find_callback_dependency_context($function_name, array $scripts)
        {
            $function_name = trim((string) $function_name);
            if ('' === $function_name || empty($scripts)) {
                return array('consumers' => array(), 'providers' => array());
            }

            $function_lc = strtolower($function_name);
            $tokens = $this->runtime_js_scan_split_symbol_tokens($function_name);
            $consumers = array();
            $providers = array();

            foreach ($scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }

                $src = isset($script['src']) ? (string) $script['src'] : '';
                $id = isset($script['id']) ? (string) $script['id'] : '';
                $text = isset($script['text']) ? (string) $script['text'] : '';
                $content = $this->runtime_js_scan_script_content($script);
                $provider_text = '' !== $content ? $content : $text;
                $haystack = strtolower($src . ' ' . $id . ' ' . $text . ' ' . substr($content, 0, 24000));

                $is_consumer = false;
                if ('' !== $src) {
                    $decoded_src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
                    if (preg_match('/(?:[?&]|&amp;)(?:callback|cb|jsonp)=' . preg_quote($function_name, '/') . '(?:[&#]|$)/i', $decoded_src)) {
                        $is_consumer = true;
                    } elseif (false !== strpos(strtolower($decoded_src), 'callback=' . $function_lc)) {
                        $is_consumer = true;
                    }
                }

                if ($is_consumer) {
                    $consumers[] = $script;
                }

                $is_provider = false;
                if ('' !== $provider_text && preg_match('/(?:function\s+' . preg_quote($function_name, '/') . '\b|window\s*\.\s*' . preg_quote($function_name, '/') . '\b|' . preg_quote($function_name, '/') . '\s*=)/i', $provider_text)) {
                    $is_provider = true;
                }

                if (!$is_provider && false !== strpos($haystack, $function_lc)) {
                    $is_provider = true;
                }

                if (!$is_provider) {
                    foreach ($tokens as $token) {
                        if ($this->runtime_js_scan_is_generic_token($token)) {
                            continue;
                        }
                        if (false !== strpos($haystack, strtolower($token))) {
                            $is_provider = true;
                            break;
                        }
                    }
                }

                if ($is_provider && !$is_consumer) {
                    $providers[] = $script;
                }
            }

            return array(
                'consumers' => array_slice($consumers, 0, 8),
                'providers' => array_slice($providers, 0, 12),
            );
        }

        private function runtime_js_scan_add_function_dependency_suggestions(&$suggestions, &$seen, $function_name, $source, $message, $detail, array $exclusions, array $scripts = array())
        {
            $function_name = trim((string) $function_name);
            if ('' === $function_name || $this->runtime_js_scan_is_generic_token($function_name)) {
                return;
            }

            $context = $this->runtime_js_scan_find_callback_dependency_context($function_name, $scripts);
            $has_callback_consumer = !empty($context['consumers']);
            $reason = $has_callback_consumer
                ? 'A browser runtime error says a global callback/function was called before it existed, and Runtime Scan found a script URL using that callback name. Keep the callback provider before the callback consumer, or exclude the smallest provider/consumer script fragments and scan again.'
                : 'A runtime error says a callback/function was called before it was available. Suggestions are derived from the missing function name and stack/source URLs; add the smallest matching exclusions and scan again.';
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $stack_text = (string) $source . "
" . (string) $detail . "
" . (string) $message;

            if ('' !== $source_base && preg_match('/\.js$/i', $source_base) && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime function error source', $source, $message, $reason, $exclusions, 'recommended');
            }

            // Do not append raw function/global names as exclusions. Only exact provider/consumer scripts or resolved URL fragments are actionable.

            foreach ((array) ($context['providers'] ?? array()) as $provider) {
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 4);
                if ('' !== $provider_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'callback provider script', $provider_src, $message, $reason, $exclusions, 'recommended');
                    continue;
                }
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'callback provider script basename', $provider_src, $message, $reason, $exclusions, 'recommended');
                    continue;
                }
                if ('' !== $provider_id) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_id, 'callback provider handle/id', $provider_src, $message, $reason, $exclusions, 'recommended');
                }
            }

            foreach ((array) ($context['consumers'] ?? array()) as $consumer) {
                $consumer_src = isset($consumer['src']) ? (string) $consumer['src'] : '';
                $consumer_fragment = $this->runtime_js_scan_path_fragment_from_source($consumer_src, 4);
                if ('' !== $consumer_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $consumer_fragment, 'callback consumer script', $consumer_src, $message, $reason, $exclusions, 'recommended');
                }
                foreach ($this->runtime_js_scan_url_fragments_from_text($consumer_src) as $consumer_url_fragment) {
                    if ('' === $consumer_url_fragment || $this->runtime_js_scan_is_generic_token($consumer_url_fragment)) {
                        continue;
                    }
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $consumer_url_fragment, 'callback consumer URL fragment', $consumer_src, $message, $reason, $exclusions, 'recommended');
                }
                if (false !== stripos($consumer_src, 'callback=' . $function_name)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'callback=' . $function_name, 'callback consumer query arg', $consumer_src, $message, $reason, $exclusions, 'recommended');
                }
            }

            foreach ($this->runtime_js_scan_url_fragments_from_text($stack_text) as $fragment) {
                $fragment = trim((string) $fragment);
                if ('' === $fragment || $this->runtime_js_scan_is_generic_token($fragment)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, 'runtime stack URL fragment', $source, $message, $reason, $exclusions, 'recommended');
            }
        }

        private function runtime_js_scan_add_jquery_plugin_dependency_suggestions(&$suggestions, &$seen, $method, $source, $message, $detail, array $exclusions, array $scripts = array())
        {
            $method = trim((string) $method);
            if ('' === $method) {
                return;
            }

            $reason = 'A runtime error says a jQuery plugin method was called before it was registered. Suggestions are derived by the Runtime Scan engine from the failing script path and the missing method name; broad generic files are kept out of automatic append suggestions.';
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $stack_text = (string) $source . "\n" . (string) $detail . "\n" . (string) $message;

            foreach ($this->runtime_js_scan_find_jquery_plugin_provider_scripts($method, $scripts) as $provider) {
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 4);
                if ('' !== $provider_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'jQuery plugin provider script', $provider_src, $message, 'Runtime Scan read the local JS files loaded by this page and found the file that registers the missing jQuery plugin method. Exclude the provider script so it executes before dependent callers.', $exclusions, 'recommended');
                    continue;
                }
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'jQuery plugin provider basename', $provider_src, $message, 'Basename candidate for the JS file that registers the missing jQuery plugin method. Prefer the path-based suggestion when available.', $exclusions, 'high');
                    continue;
                }
                if ('' !== $provider_id) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_id, 'jQuery plugin provider handle/id', $provider_src, $message, 'The loaded script id/handle belongs to the file that registers the missing jQuery plugin method.', $exclusions, 'recommended');
                }
            }

            if (preg_match_all('#https?://[^\s\)\]\}"\'<>]+\.js(?:\?[^\s\)\]\}"\'<>]*)?#i', $stack_text, $url_matches)) {
                foreach ((array) $url_matches[0] as $url) {
                    $fragment = $this->runtime_js_scan_path_fragment_from_source((string) $url, 4);
                    if ('' !== $fragment) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, 'failing script path', (string) $url, $message, $reason, $exclusions, 'recommended');
                    }
                }
            }

            if ('' !== $source_base && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'failing script basename', $source, $message, $reason, $exclusions, 'high');
            }
            foreach ($this->runtime_js_scan_script_basenames_from_text($stack_text) as $base) {
                if ($this->runtime_js_scan_is_generic_script_basename($base)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $base, 'failing script basename', $source, $message, $reason, $exclusions, 'high');
            }

            // Do not append raw jQuery plugin method names as exclusions. Provider/source paths above are the only actionable fixes.
        }

        private function runtime_js_scan_add_known_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
        {
            $text = strtolower((string) $message . ' ' . (string) $source . ' ' . (string) $detail);
            $matched = false;

            if (false !== strpos($text, 'wp is not defined') || false !== strpos($text, 'wp.')) {
                $matched = true;
                $reason = 'Browser runtime error points to a WordPress core dependency that executed before its provider. If the recommended dependency paths are already listed, this indicates a script execution-order issue rather than a missing exclusion.';
                $this->runtime_js_scan_add_direct_source_review_suggestion($suggestions, $seen, $source, $message, $reason, $exclusions, 'wp-dependent direct source');
                $this->runtime_js_scan_add_script_source_resolution_suggestions($suggestions, $seen, $scripts, $source, $message, $reason, $exclusions, 'wp-dependent resolved source', 'recommended', true);
                $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
            }

            if (false !== strpos($text, 'react is not defined') || false !== strpos($text, "react' is not defined") || false !== strpos($text, "can't find variable: react") || false !== strpos($text, 'reactdom is not defined')) {
                $matched = true;
                $reason = 'Browser runtime error points to a React dependency that executed before its provider. Review the exact source shown by the scanner; do not add broad framework handles blindly.';
                $this->runtime_js_scan_add_direct_source_review_suggestion($suggestions, $seen, $source, $message, $reason, $exclusions, 'React dependent direct source');
                $this->runtime_js_scan_add_script_source_resolution_suggestions($suggestions, $seen, $scripts, $source, $message, $reason, $exclusions, 'React dependent resolved source', 'recommended', true);
                $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
            }

            return $matched;
        }

        private function runtime_js_scan_error_theme_lookup_tokens($message, $detail)
        {
            $text = (string) $message . "\n" . (string) $detail;
            $tokens = array();
            $push = function ($token) use (&$tokens) {
                $token = trim((string) $token);
                $token = trim($token, " \t\n\r\0\x0B.\\/[](){}'\"");
                if ('' === $token) {
                    return;
                }
                if (false !== strpos($token, '.')) {
                    $parts = array_values(array_filter(array_map('trim', explode('.', $token))));
                    foreach ($parts as $part) {
                        if ('' !== $part && !$this->runtime_js_scan_is_generic_token($part)) {
                            $tokens[$part] = $part;
                        }
                    }
                }
                if (!$this->runtime_js_scan_is_generic_token($token)) {
                    $tokens[$token] = $token;
                }
            };

            if (preg_match_all('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+defined/i', $text, $matches)) {
                foreach ((array) $matches[1] as $match) {
                    $push($match);
                }
            }

            if (preg_match_all('/(?:InvalidValueError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s+is\s+not\s+a\s+function/i', $text, $matches)) {
                foreach ((array) $matches[1] as $match) {
                    $push($match);
                }
            }

            if (preg_match_all('/Cannot\s+read\s+properties\s+of\s+undefined\s+\(reading\s+[\'\"]([^\'\"]+)[\'\"]\)/i', $text, $matches)) {
                foreach ((array) $matches[1] as $match) {
                    $push($match);
                }
            }

            if (preg_match_all('/window\s*\[\s*[\'\"]?([A-Za-z_$][A-Za-z0-9_$.-]{2,})[\'\"]?\s*\]\s+is\s+not\s+a\s+function/i', $text, $matches)) {
                foreach ((array) $matches[1] as $match) {
                    $push($match);
                }
            }

            return array_slice(array_values($tokens), 0, 8);
        }

        private function runtime_js_scan_theme_stage_roots()
        {
            $roots = array();
            $seen = array();
            $stylesheet = function_exists('get_stylesheet') ? sanitize_key((string) get_stylesheet()) : '';
            $template = function_exists('get_template') ? sanitize_key((string) get_template()) : '';

            $push = function ($stage, $slug, $dir, $uri) use (&$roots, &$seen) {
                $slug = sanitize_key((string) $slug);
                $dir = function_exists('wp_normalize_path') ? wp_normalize_path((string) $dir) : str_replace('\\', '/', (string) $dir);
                $uri = esc_url_raw((string) $uri);
                if ('' === $slug || '' === $dir || '' === $uri) {
                    return;
                }
                $key = strtolower($slug . '|' . $dir);
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $roots[] = array(
                    'stage' => sanitize_text_field((string) $stage),
                    'slug'  => $slug,
                    'dir'   => untrailingslashit($dir),
                    'uri'   => untrailingslashit($uri),
                );
            };

            if (function_exists('get_stylesheet_directory') && function_exists('get_stylesheet_directory_uri')) {
                $push(('' !== $template && '' !== $stylesheet && $stylesheet !== $template) ? 'active child theme' : 'active theme', $stylesheet, get_stylesheet_directory(), get_stylesheet_directory_uri());
            }

            if ('' !== $template && $template !== $stylesheet && function_exists('get_template_directory') && function_exists('get_template_directory_uri')) {
                $push('parent theme', $template, get_template_directory(), get_template_directory_uri());
            }

            return $roots;
        }

        private function runtime_js_scan_theme_stage_files($root, $max_files = 80, $max_depth = 6)
        {
            $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
            $root = untrailingslashit($root);
            if ('' === $root) {
                return array();
            }

            $files = array();
            $queue = array(array($root, 0));
            $blocked_dirs = array('node_modules', 'vendor', '.git', 'cache', 'dist/cache', 'build/cache');

            $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
            if (!$filesystem || !is_object($filesystem)) {
                return array();
            }

            while (!empty($queue) && count($files) < (int) $max_files) {
                $current = array_shift($queue);
                $dir = isset($current[0]) ? (string) $current[0] : '';
                $depth = isset($current[1]) ? (int) $current[1] : 0;
                if ('' === $dir || $depth > (int) $max_depth) {
                    continue;
                }

                $items = ultracache_safe_scandir($dir, 'runtime_js_theme_stage_scan');
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $item = (string) $item;
                    if ('.' === $item || '..' === $item || '' === $item) {
                        continue;
                    }
                    $path = function_exists('wp_normalize_path') ? wp_normalize_path(trailingslashit($dir) . $item) : str_replace('\\', '/', trailingslashit($dir) . $item);
                    $lower_item = strtolower($item);
                    if ($filesystem->is_dir($path)) {
                        if ($depth >= (int) $max_depth || in_array($lower_item, $blocked_dirs, true)) {
                            continue;
                        }
                        $queue[] = array($path, $depth + 1);
                        continue;
                    }
                    if (!$filesystem->is_file($path)) {
                        continue;
                    }
                    if (method_exists($filesystem, 'size') && (int) $filesystem->size($path) > 786432) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                    if (!in_array($ext, array('js', 'mjs'), true)) {
                        continue;
                    }
                    $files[] = $path;
                    if (count($files) >= (int) $max_files) {
                        break;
                    }
                }
            }

            return $files;
        }

        private function runtime_js_scan_theme_stage_relative_path($file, $root)
        {
            $file = function_exists('wp_normalize_path') ? wp_normalize_path((string) $file) : str_replace('\\', '/', (string) $file);
            $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
            $root = trailingslashit($root);
            if (0 !== strpos($file, $root)) {
                return '';
            }
            return ltrim(substr($file, strlen($root)), '/');
        }

        private function runtime_js_scan_theme_file_uses_token($content, $token)
        {
            $content = (string) $content;
            $token = trim((string) $token);
            if ('' === $content || '' === $token || $this->runtime_js_scan_is_generic_token($token)) {
                return false;
            }

            $quoted = preg_quote($token, '/');
            if (preg_match('/(?:function|class|var|let|const)\s+' . $quoted . '\b/i', $content)) {
                return true;
            }
            if (preg_match('/(?:window|globalThis)\s*\.\s*' . $quoted . '\s*=/i', $content)) {
                return true;
            }
            if (preg_match('/[\'\"]' . $quoted . '[\'\"]\s*:/i', $content)) {
                return true;
            }
            if (preg_match('/\b' . $quoted . '\b/i', $content)) {
                return true;
            }

            return false;
        }

        private function runtime_js_scan_add_theme_stage_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
        {
            $tokens = $this->runtime_js_scan_error_theme_lookup_tokens($message, $detail);
            if (empty($tokens)) {
                return false;
            }

            $matched = false;
            foreach ($this->runtime_js_scan_theme_stage_roots() as $root) {
                $root_dir = isset($root['dir']) ? (string) $root['dir'] : '';
                $root_uri = isset($root['uri']) ? (string) $root['uri'] : '';
                $stage = isset($root['stage']) ? (string) $root['stage'] : 'theme';
                if ('' === $root_dir || '' === $root_uri) {
                    continue;
                }

                foreach ($this->runtime_js_scan_theme_stage_files($root_dir) as $file) {
                    $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_theme_stage_scan', true) : false;
                    if (!is_string($content) || '' === $content) {
                        continue;
                    }

                    $matched_tokens = array();
                    foreach ($tokens as $token) {
                        if ($this->runtime_js_scan_theme_file_uses_token($content, $token)) {
                            $matched_tokens[] = $token;
                        }
                    }
                    if (empty($matched_tokens)) {
                        continue;
                    }

                    $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                    if ('' === $relative) {
                        continue;
                    }
                    $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                    $fragment = $this->runtime_js_scan_path_fragment_from_source($url, 5);
                    if ('' === $fragment) {
                        continue;
                    }

                    $matched = true;
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $fragment,
                        'Theme Scan Stage ' . $stage,
                        $url,
                        $message,
                        'Theme code search found unresolved token(s) ' . implode(', ', array_map('sanitize_text_field', $matched_tokens)) . ' in this exact active theme JS file.',
                        $exclusions,
                        'recommended'
                    );

                    if (count($matched_tokens) > 0 && count($suggestions) >= 80) {
                        return true;
                    }
                }

                if ($matched) {
                    return true;
                }
            }

            return $matched;
        }


        private function runtime_js_scan_active_plugin_slugs()
        {
            $slugs = array();
            $push = static function ($plugin_file) use (&$slugs) {
                $plugin_file = trim((string) $plugin_file);
                if ('' === $plugin_file) {
                    return;
                }
                if (function_exists('plugin_basename')) {
                    $plugin_file = plugin_basename($plugin_file);
                }
                $plugin_file = str_replace('\\', '/', $plugin_file);
                $dir = dirname($plugin_file);
                $slug = ('.' === $dir || '' === $dir) ? preg_replace('/\.php$/i', '', basename($plugin_file)) : $dir;
                $slug = sanitize_key((string) $slug);
                if ('' !== $slug) {
                    $slugs[$slug] = true;
                }
            };

            foreach ((array) get_option('active_plugins', array()) as $plugin_file) {
                $push($plugin_file);
            }

            if (is_multisite()) {
                foreach (array_keys((array) get_site_option('active_sitewide_plugins', array())) as $plugin_file) {
                    $push($plugin_file);
                }
            }

            return array_keys($slugs);
        }

        private function runtime_js_scan_plugin_stage_owner_slugs($source, $message, $detail)
        {
            $slugs = array();
            foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
                $owner = $this->runtime_js_scan_owner_group_from_source($candidate);
                if (empty($owner) || !isset($owner['kind']) || 'plugin' !== $owner['kind']) {
                    continue;
                }
                $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
                if ('' !== $slug) {
                    $slugs[$slug] = true;
                }
            }
            return array_keys($slugs);
        }

        private function runtime_js_scan_plugin_stage_has_any_owner($source, $message, $detail)
        {
            foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
                if (!empty($this->runtime_js_scan_owner_group_from_source($candidate))) {
                    return true;
                }
            }
            return false;
        }

        private function runtime_js_scan_plugin_stage_roots($source, $message, $detail)
        {
            if (!function_exists('ultracache_plugin_root_dir')) {
                return array();
            }

            $active_slugs = array_fill_keys($this->runtime_js_scan_active_plugin_slugs(), true);
            if (empty($active_slugs)) {
                return array();
            }

            $owner_slugs = $this->runtime_js_scan_plugin_stage_owner_slugs($source, $message, $detail);
            $has_clear_owner = !empty($owner_slugs);
            if (!$has_clear_owner && $this->runtime_js_scan_plugin_stage_has_any_owner($source, $message, $detail)) {
                return array();
            }
            $scan_slugs = $has_clear_owner ? $owner_slugs : array_keys($active_slugs);
            $roots = array();
            $seen = array();
            $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
            if (!$filesystem || !is_object($filesystem)) {
                return array();
            }

            foreach ($scan_slugs as $slug) {
                $slug = sanitize_key((string) $slug);
                if ('' === $slug || empty($active_slugs[$slug])) {
                    continue;
                }

                $dir = ultracache_plugin_root_dir($slug);
                if (!$filesystem->is_dir($dir)) {
                    continue;
                }

                $key = strtolower($slug . '|' . $dir);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $roots[] = array(
                    'stage'     => $has_clear_owner ? 'targeted active plugin' : 'active plugin scan',
                    'slug'      => $slug,
                    'dir'       => untrailingslashit($dir),
                    'uri'       => function_exists('ultracache_plugin_root_uri') ? ultracache_plugin_root_uri($slug) : '',
                    'max_files' => $has_clear_owner ? 120 : 35,
                    'max_depth' => $has_clear_owner ? 6 : 4,
                );

                if (!$has_clear_owner && count($roots) >= 30) {
                    break;
                }
            }

            return $roots;
        }

        private function runtime_js_scan_plugin_stage_files($root, $max_files = 60, $max_depth = 5)
        {
            $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
            $root = untrailingslashit($root);
            if ('' === $root) {
                return array();
            }

            $files = array();
            $queue = array(array($root, 0));
            $blocked_dirs = array('node_modules', 'vendor', '.git', 'cache', 'dist/cache', 'build/cache', 'tests', 'test');

            $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
            if (!$filesystem || !is_object($filesystem)) {
                return array();
            }

            while (!empty($queue) && count($files) < (int) $max_files) {
                $current = array_shift($queue);
                $dir = isset($current[0]) ? (string) $current[0] : '';
                $depth = isset($current[1]) ? (int) $current[1] : 0;
                if ('' === $dir || $depth > (int) $max_depth) {
                    continue;
                }

                $items = ultracache_safe_scandir($dir, 'runtime_js_plugin_stage_scan');
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $item = (string) $item;
                    if ('.' === $item || '..' === $item || '' === $item) {
                        continue;
                    }
                    $path = function_exists('wp_normalize_path') ? wp_normalize_path(trailingslashit($dir) . $item) : str_replace('\\', '/', trailingslashit($dir) . $item);
                    $lower_item = strtolower($item);
                    if ($filesystem->is_dir($path)) {
                        if ($depth >= (int) $max_depth || in_array($lower_item, $blocked_dirs, true)) {
                            continue;
                        }
                        $queue[] = array($path, $depth + 1);
                        continue;
                    }
                    if (!$filesystem->is_file($path)) {
                        continue;
                    }
                    if (method_exists($filesystem, 'size') && (int) $filesystem->size($path) > 786432) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                    if (!in_array($ext, array('js', 'mjs'), true)) {
                        continue;
                    }
                    $files[] = $path;
                    if (count($files) >= (int) $max_files) {
                        break;
                    }
                }
            }

            return $files;
        }

        private function runtime_js_scan_add_plugin_stage_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
        {
            $tokens = $this->runtime_js_scan_error_theme_lookup_tokens($message, $detail);
            if (empty($tokens)) {
                return false;
            }

            $matched = false;
            foreach ($this->runtime_js_scan_plugin_stage_roots($source, $message, $detail) as $root) {
                $root_dir = isset($root['dir']) ? (string) $root['dir'] : '';
                $root_uri = isset($root['uri']) ? (string) $root['uri'] : '';
                $stage = isset($root['stage']) ? (string) $root['stage'] : 'plugin';
                $max_files = isset($root['max_files']) ? (int) $root['max_files'] : 60;
                $max_depth = isset($root['max_depth']) ? (int) $root['max_depth'] : 5;
                if ('' === $root_dir || '' === $root_uri) {
                    continue;
                }

                foreach ($this->runtime_js_scan_plugin_stage_files($root_dir, $max_files, $max_depth) as $file) {
                    $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_plugin_stage_scan', true) : false;
                    if (!is_string($content) || '' === $content) {
                        continue;
                    }

                    $matched_tokens = array();
                    foreach ($tokens as $token) {
                        if ($this->runtime_js_scan_theme_file_uses_token($content, $token)) {
                            $matched_tokens[] = $token;
                        }
                    }
                    if (empty($matched_tokens)) {
                        continue;
                    }

                    $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                    if ('' === $relative) {
                        continue;
                    }
                    $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                    $fragment = $this->runtime_js_scan_path_fragment_from_source($url, 5);
                    if ('' === $fragment) {
                        continue;
                    }

                    $matched = true;
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $fragment,
                        'Plugin Scan Stage ' . $stage,
                        $url,
                        $message,
                        'Plugin code search found unresolved token(s) ' . implode(', ', array_map('sanitize_text_field', $matched_tokens)) . ' in this exact active plugin JS file.',
                        $exclusions,
                        'recommended'
                    );

                    if (count($suggestions) >= 80) {
                        return true;
                    }
                }

                if ($matched) {
                    return true;
                }
            }

            return $matched;
        }

        private function runtime_js_scan_is_ignorable_console_error($message, $detail = '', $source = '')
        {
            $text = strtolower(trim((string) $message . ' ' . (string) $detail . ' ' . (string) $source));
            if ('' === $text) {
                return true;
            }
            if (preg_match('/^\s*\d+\s*$/', $text)) {
                return true;
            }
            if (false !== strpos($text, 'jqmigrate: migrate is installed')) {
                return true;
            }
            if (false !== strpos($text, 'google maps javascript api warning') || false !== strpos($text, 'noapikeys')) {
                return true;
            }
            if (preg_match('/^\s*understand this (?:error|warning)\s*$/i', $text)) {
                return true;
            }
            if (false !== strpos($text, ' opt-in') && false === strpos($text, 'error') && false === strpos($text, 'uncaught')) {
                return true;
            }
            return false;
        }

        private function runtime_js_scan_extract_missing_symbols_from_error($message, $detail = '')
        {
            $text = (string) $message . "\n" . (string) $detail;
            $symbols = array();
            $push = function ($symbol) use (&$symbols) {
                $symbol = trim((string) $symbol);
                $symbol = preg_replace('/[^A-Za-z0-9_$.-]/', '', $symbol);
                if ('' === $symbol) {
                    return;
                }
                if ($this->runtime_js_scan_is_generic_token($symbol) && !$this->runtime_js_scan_is_explicit_missing_global($symbol)) {
                    return;
                }
                $symbols[strtolower($symbol)] = sanitize_text_field(substr($symbol, 0, 120));
            };

            if (preg_match_all('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+defined/i', $text, $matches)) {
                foreach ((array) $matches[1] as $symbol) {
                    $push($symbol);
                }
            }
            if (preg_match_all('/(?:TypeError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]*)\s+is\s+not\s+a\s+function/i', $text, $matches)) {
                foreach ((array) $matches[1] as $symbol) {
                    $push($symbol);
                }
            }
            if (preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s*\.\s*[A-Za-z_$][A-Za-z0-9_$-]*\s+is\s+not\s+a\s+function/i', $text, $matches)) {
                foreach ((array) $matches[1] as $symbol) {
                    $push($symbol);
                }
            }

            return array_values($symbols);
        }

        private function runtime_js_scan_file_defines_symbol($content, $symbol)
        {
            $content = (string) $content;
            $symbol = trim((string) $symbol);
            if ('' === $content || '' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return false;
            }
            $quoted = preg_quote($symbol, '/');
            $patterns = array(
                '/(?:^|[^A-Za-z0-9_$])function\s+' . $quoted . '\s*\(/',
                '/(?:^|[^A-Za-z0-9_$])(?:var|let|const)\s+' . $quoted . '\s*=/',
                '/(?:^|[^A-Za-z0-9_$])' . $quoted . '\s*=\s*function\b/',
                '/(?:window|globalThis)\s*\.\s*' . $quoted . '\s*=/',
                '/(?:window|globalThis)\s*\[\s*["\']' . $quoted . '["\']\s*\]\s*=/',
            );
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return true;
                }
            }
            return false;
        }

        private function runtime_js_scan_owner_from_script_source($source)
        {
            $owner = $this->runtime_js_scan_owner_group_from_source($source);
            if (empty($owner) || empty($owner['kind']) || empty($owner['slug']) || empty($owner['group'])) {
                return array();
            }
            return $owner;
        }

        private function runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, array $scripts = array())
        {
            $sources = array();
            $seen = array();
            $push = function ($candidate) use (&$sources, &$seen, $scripts) {
                $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
                if ('' === $candidate) {
                    return;
                }
                $base = $this->runtime_js_scan_basename_from_source($candidate);
                if ('' === $base || !preg_match('/\.js$/i', $base) || $this->runtime_js_scan_is_generic_script_basename($base)) {
                    return;
                }

                $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
                if (empty($owner) && !empty($scripts)) {
                    $matches = $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts);
                    if (1 === count($matches)) {
                        $matched_src = isset($matches[0]['src']) ? (string) $matches[0]['src'] : '';
                        if ('' !== $matched_src) {
                            $candidate = $matched_src;
                            $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
                        }
                    }
                }

                if (empty($owner)) {
                    return;
                }

                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
                if ('' === $fragment) {
                    return;
                }
                $key = strtolower($fragment . '|' . (string) $owner['kind'] . '|' . (string) $owner['slug']);
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $sources[] = array(
                    'source'   => $candidate,
                    'fragment' => $fragment,
                    'owner'    => $owner,
                );
            };

            foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
                $push($candidate);
            }
            foreach ($this->runtime_js_scan_console_sources_from_text((string) $source . "\n" . (string) $message . "\n" . (string) $detail) as $candidate) {
                $push($candidate);
            }

            return array_values($sources);
        }

        private function runtime_js_scan_owner_root_for_discovery(array $owner)
        {
            $kind = isset($owner['kind']) ? (string) $owner['kind'] : '';
            $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
            if ('' === $kind || '' === $slug) {
                return array();
            }
            if ('plugin' === $kind && function_exists('ultracache_plugin_root_dir')) {
                $dir = ultracache_plugin_root_dir($slug);
                if (is_dir($dir)) {
                    return array('kind' => 'plugin', 'slug' => $slug, 'dir' => untrailingslashit($dir), 'uri' => function_exists('ultracache_plugin_root_uri') ? ultracache_plugin_root_uri($slug) : '');
                }
            }
            if ('theme' === $kind) {
                foreach ($this->runtime_js_scan_theme_stage_roots() as $root) {
                    if (isset($root['slug']) && sanitize_key((string) $root['slug']) === $slug) {
                        return $root;
                    }
                }
            }
            return array();
        }

        private function runtime_js_scan_find_symbol_definitions_for_owners($symbol, array $owners)
        {
            $definitions = array();
            $seen = array();
            $symbol = trim((string) $symbol);
            if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                return array();
            }

            foreach ($owners as $owner) {
                if (!is_array($owner)) {
                    continue;
                }
                $root = $this->runtime_js_scan_owner_root_for_discovery($owner);
                if (empty($root) || empty($root['dir']) || empty($root['uri'])) {
                    continue;
                }
                $kind = isset($root['kind']) ? (string) $root['kind'] : (isset($owner['kind']) ? (string) $owner['kind'] : '');
                $slug = isset($root['slug']) ? sanitize_key((string) $root['slug']) : (isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '');
                $root_dir = (string) $root['dir'];
                $root_uri = (string) $root['uri'];
                $files = ('plugin' === $kind) ? $this->runtime_js_scan_plugin_stage_files($root_dir, 140, 7) : $this->runtime_js_scan_theme_stage_files($root_dir, 120, 7);
                foreach ($files as $file) {
                    $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_discovery_symbol_search', true) : false;
                    if (!is_string($content) || !$this->runtime_js_scan_file_defines_symbol($content, $symbol)) {
                        continue;
                    }
                    $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                    if ('' === $relative) {
                        continue;
                    }
                    $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                    $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($url, 5);
                    if ('' === $fragment) {
                        continue;
                    }
                    $key = strtolower($kind . '|' . $slug . '|' . $fragment . '|' . $symbol);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $definitions[] = array(
                        'symbol'   => $symbol,
                        'source'   => $url,
                        'fragment' => $fragment,
                        'owner'    => array(
                            'kind'  => $kind,
                            'slug'  => $slug,
                            'group' => isset($owner['group']) ? (string) $owner['group'] : ($slug . '/'),
                        ),
                    );
                    if (count($definitions) >= 12) {
                        return $definitions;
                    }
                }
            }

            return $definitions;
        }

        private function runtime_js_scan_unique_direct_source_owners(array $direct_sources)
        {
            $owners = array();
            $seen = array();
            foreach ($direct_sources as $entry) {
                if (empty($entry['owner']) || !is_array($entry['owner'])) {
                    continue;
                }
                $owner = $entry['owner'];
                $kind = isset($owner['kind']) ? (string) $owner['kind'] : '';
                $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
                if ('' === $kind || '' === $slug) {
                    continue;
                }
                $key = strtolower($kind . '|' . $slug);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $owners[] = $owner;
            }
            return $owners;
        }

        private function build_runtime_js_scan_suggestions(array $errors, array $scripts = array())
        {
            $exclusions = $this->get_runtime_js_scan_current_exclusions();
            $suggestions = array();
            $seen = array();

            $explicit_dependency_text = '';
            foreach ($errors as $error_for_dependency_pass) {
                if (!is_array($error_for_dependency_pass)) {
                    continue;
                }
                $explicit_dependency_text .= "\n" . (string) ($error_for_dependency_pass['message'] ?? '');
                $explicit_dependency_text .= "\n" . (string) ($error_for_dependency_pass['detail'] ?? '');
            }
            if ('' !== trim($explicit_dependency_text)) {
                $this->runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text(
                    $suggestions,
                    $seen,
                    $explicit_dependency_text,
                    '',
                    $exclusions
                );
            }

            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
                $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
                $detail = isset($error['detail']) ? sanitize_textarea_field((string) $error['detail']) : '';
                if ('' === $source) {
                    $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
                }

                if ($this->runtime_js_scan_is_ignorable_console_error($message, $detail, $source)) {
                    continue;
                }

                $direct_sources = $this->runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, $scripts);
                $direct_owners = !empty($direct_sources) ? $this->runtime_js_scan_unique_direct_source_owners($direct_sources) : array();
                $symbols = $this->runtime_js_scan_extract_missing_symbols_from_error($message, $detail);

                $explicit_wp_provider_added = $this->runtime_js_scan_add_explicit_wp_dependency_suggestions_from_text($suggestions, $seen, $message, $detail, $exclusions);

                $provider_added = false;
                foreach ($symbols as $symbol) {
                    if ($this->runtime_js_scan_add_missing_global_provider_suggestions($suggestions, $seen, $symbol, $direct_sources, $scripts, $message, $exclusions)) {
                        $provider_added = true;
                    }
                }

                if ($explicit_wp_provider_added || $provider_added) {
                    continue;
                }

                $inventory_provider_added = false;
                foreach ($symbols as $symbol) {
                    if ($this->runtime_js_scan_add_inventory_symbol_provider_suggestions($suggestions, $seen, $symbol, $scripts, $message, $exclusions)) {
                        $inventory_provider_added = true;
                    }
                }

                if ($inventory_provider_added) {
                    continue;
                }

                if (empty($direct_sources)) {
                    $reason = 'Runtime Scan did not find an external plugin/theme stack source, so it inspected scanned inline handles/sourceURL markers and final HTML adjacency for the same error in this same pass.';
                    $this->runtime_js_scan_add_inline_stack_frame_suggestions($suggestions, $seen, $scripts, (string) $detail . "\n" . (string) $message, $message, $reason, $exclusions, 'recommended');
                    foreach ($symbols as $symbol) {
                        $this->runtime_js_scan_add_html_adjacency_suggestions($suggestions, $seen, $symbol, $scripts, $source, $message, $exclusions);
                    }
                    continue;
                }

                $group_added = false;
                foreach ($symbols as $symbol) {
                    $definitions = $this->runtime_js_scan_find_symbol_definitions_for_owners($symbol, $direct_owners);
                    foreach ($definitions as $definition) {
                        if (empty($definition['owner']) || !is_array($definition['owner'])) {
                            continue;
                        }
                        $def_owner = $definition['owner'];
                        $def_kind = isset($def_owner['kind']) ? (string) $def_owner['kind'] : '';
                        $def_slug = isset($def_owner['slug']) ? sanitize_key((string) $def_owner['slug']) : '';
                        $def_group = isset($def_owner['group']) ? (string) $def_owner['group'] : '';
                        if ('' === $def_kind || '' === $def_slug || '' === $def_group) {
                            continue;
                        }

                        foreach ($direct_sources as $direct) {
                            if (empty($direct['owner']) || !is_array($direct['owner'])) {
                                continue;
                            }
                            $src_owner = $direct['owner'];
                            $src_kind = isset($src_owner['kind']) ? (string) $src_owner['kind'] : '';
                            $src_slug = isset($src_owner['slug']) ? sanitize_key((string) $src_owner['slug']) : '';
                            if ($src_kind !== $def_kind || $src_slug !== $def_slug) {
                                continue;
                            }

                            $this->runtime_js_scan_add_suggestion(
                                $suggestions,
                                $seen,
                                $def_group,
                                'same-owner symbol provider group',
                                isset($definition['source']) ? (string) $definition['source'] : '',
                                $message,
                                'Discovery-only resolver: the error stack points to this owner and active code search found the missing symbol "' . sanitize_text_field($symbol) . '" defined inside the same owner. Add the owner group instead of many individual files.',
                                $exclusions,
                                'recommended'
                            );
                            $group_added = true;
                            break 2;
                        }
                    }
                    if ($group_added) {
                        break;
                    }
                }

                if ($group_added) {
                    continue;
                }

                foreach ($direct_sources as $direct) {
                    $fragment = isset($direct['fragment']) ? (string) $direct['fragment'] : '';
                    $direct_source = isset($direct['source']) ? (string) $direct['source'] : '';
                    if ('' === $fragment) {
                        continue;
                    }
                    $this->runtime_js_scan_add_suggestion(
                        $suggestions,
                        $seen,
                        $fragment,
                        'direct error stack source',
                        $direct_source,
                        $message,
                        'Discovery-only resolver: this exact plugin/theme script appears directly in the browser error stack. No page-wide inventory or hardcoded plugin rule was used.',
                        $exclusions,
                        'recommended'
                    );
                }
            }

            $suggestions = $this->runtime_js_scan_finalize_suggestions($suggestions);

            $missing = 0;
            foreach ($suggestions as $suggestion) {
                if (empty($suggestion['alreadyExcluded'])) {
                    $missing++;
                }
            }

            return array(
                'available'              => true,
                'source'                 => 'browser-runtime',
                'suggestion_count'       => count($suggestions),
                'missing_count'          => (int) $missing,
                'already_excluded_count' => count($suggestions) - (int) $missing,
                'suggestions'            => $suggestions,
            );
        }

        private function normalize_runtime_js_scan_report_payload($payload)
        {
            $payload = is_array($payload) ? $payload : array();
            $scan_id = isset($payload['scanId']) ? sanitize_key((string) $payload['scanId']) : '';
            $errors = array();
            foreach ((array) ($payload['errors'] ?? array()) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
                $detail = isset($error['detail']) ? sanitize_textarea_field(substr((string) $error['detail'], 0, 3000)) : '';
                $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
                if ('' === $source) {
                    $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
                }
                $errors[] = array(
                    'kind'    => isset($error['kind']) ? sanitize_text_field((string) $error['kind']) : '',
                    'message' => $message,
                    'source'  => $source,
                    'line'    => isset($error['line']) ? (int) $error['line'] : 0,
                    'column'  => isset($error['column']) ? (int) $error['column'] : 0,
                    'detail'  => $detail,
                    'atMs'    => isset($error['atMs']) ? (int) $error['atMs'] : 0,
                );
                if (count($errors) >= 80) {
                    break;
                }
            }

            return array(
                'scanId'    => $scan_id,
                'url'       => isset($payload['url']) ? $this->runtime_js_scan_sanitize_display_url((string) $payload['url']) : '',
                'completed' => !empty($payload['completed']),
                'errors'    => $errors,
                'scripts'   => isset($payload['scripts']) && is_array($payload['scripts']) ? $this->runtime_js_scan_normalize_script_inventory((array) $payload['scripts']) : array(),
                'scanContext' => isset($payload['scanContext']) && 'logged-in' === sanitize_key((string) $payload['scanContext']) ? 'logged-in' : 'anonymous',
                'userAgent' => isset($payload['userAgent']) ? sanitize_text_field((string) $payload['userAgent']) : '',
                'elapsedMs' => isset($payload['elapsedMs']) ? (int) $payload['elapsedMs'] : 0,
                'queueJobId' => isset($payload['queueJobId']) ? sanitize_text_field((string) $payload['queueJobId']) : '',
            );
        }

        private function runtime_js_scan_console_sources_from_text($text)
        {
            $text = (string) $text;
            $sources = array();
            if ('' === trim($text)) {
                return array();
            }

            if (preg_match_all('#https?://[^\s\)\]\}"\'<>]+\.js(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}#i', $text, $url_matches)) {
                foreach ((array) $url_matches[0] as $source) {
                    $source = $this->runtime_js_scan_sanitize_source((string) $source);
                    $path_fragment = $this->runtime_js_scan_path_fragment_from_source($source, 4);
                    if ('' !== $path_fragment) {
                        $sources[strtolower($path_fragment)] = $path_fragment;
                    } elseif ('' !== $source && !$this->runtime_js_scan_is_generic_script_basename($this->runtime_js_scan_basename_from_source($source))) {
                        $sources[strtolower($source)] = $source;
                    }
                }
            }


            if (preg_match_all('/\b([A-Za-z0-9_-]+-js-(?:after|before|extra|translations))(?::\d+(?::\d+)?)?/i', $text, $inline_matches)) {
                foreach ((array) $inline_matches[1] as $source) {
                    $source = $this->runtime_js_scan_sanitize_source((string) $source);
                    if ('' !== $source) {
                        $sources[strtolower($source)] = $source;
                    }
                }
            }

            if (preg_match_all('/\b([A-Za-z0-9_.\/-]+\.(?:min\.)?js)(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+(?::\d+)?)?/i', $text, $file_matches)) {
                foreach ((array) $file_matches[1] as $source) {
                    $source = $this->runtime_js_scan_sanitize_source((string) $source);
                    $base = $this->runtime_js_scan_basename_from_source($source);
                    $path_fragment = $this->runtime_js_scan_path_fragment_from_source($source, 4);
                    if ('' !== $path_fragment) {
                        $sources[strtolower($path_fragment)] = $path_fragment;
                    } elseif ('' !== $source && !$this->runtime_js_scan_is_generic_script_basename($base)) {
                        $sources[strtolower($source)] = $source;
                    }
                }
            }

            return array_slice(array_values($sources), 0, 12);
        }

        private function runtime_js_scan_console_text_to_errors($text)
        {
            $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
            $text = substr($text, 0, 30000);
            if ('' === trim($text)) {
                return array();
            }

            $lines = preg_split('/\n/', $text);
            $blocks = array();
            $current = array();
            $in_error = false;

            foreach ((array) $lines as $line) {
                $line = trim((string) $line);
                if ('' === $line) {
                    if (!empty($current)) {
                        $blocks[] = $current;
                        $current = array();
                        $in_error = false;
                    }
                    continue;
                }

                if (preg_match('/^(?:Understand this (?:error|warning)|JQMIGRATE:\s*Migrate is installed.*|opt-in)$/i', $line)) {
                    continue;
                }

                $starts_error = (bool) preg_match('/(?:Uncaught\s+)?(?:ReferenceError|TypeError|SyntaxError|RangeError|EvalError|URIError|Error):|jQuery\.Deferred exception|\bis not defined\b|\bis not a function\b|Cannot read properties|window\[[^\]]+\]\s+is\s+not\s+a\s+function/i', $line);
                $is_stack_line = (bool) (preg_match('/^at\s+/i', $line) || preg_match('/\.(?:m?js)(?:\?[^\s\)]*)?(?::\d+(?::\d+)?)?/i', $line));

                if ($starts_error) {
                    if (!empty($current)) {
                        $blocks[] = $current;
                    }
                    $current = array($line);
                    $in_error = true;
                    continue;
                }

                if ($in_error && $is_stack_line) {
                    $current[] = $line;
                    continue;
                }

                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = array();
                }
                $in_error = false;
            }

            if (!empty($current)) {
                $blocks[] = $current;
            }

            if (empty($blocks)) {
                return array();
            }

            $errors = array();
            foreach ($blocks as $block) {
                $block_text = trim(implode("\n", (array) $block));
                if ('' === $block_text) {
                    continue;
                }
                $message = '';
                foreach ((array) $block as $line) {
                    $line = trim((string) $line);
                    if ('' === $line || preg_match('/^at\s+/i', $line) || preg_match('/^\(?anonymous\)?\s*@/i', $line)) {
                        continue;
                    }
                    $message = $line;
                    break;
                }
                if ('' === $message) {
                    $message = substr($block_text, 0, 500);
                }

                $sources = $this->runtime_js_scan_console_sources_from_text($block_text);
                if (empty($sources)) {
                    $source = $this->runtime_js_scan_source_from_text($block_text);
                    if ('' !== $source) {
                        $sources[] = $source;
                    }
                }
                if (empty($sources)) {
                    $sources[] = '';
                }

                foreach ($sources as $source) {
                    $errors[] = array(
                        'kind' => 'console-paste',
                        'message' => sanitize_text_field(substr($message, 0, 500)),
                        'source'  => $this->runtime_js_scan_sanitize_source((string) $source),
                        'line'    => 0,
                        'column'  => 0,
                        'detail'  => sanitize_textarea_field(substr($block_text, 0, 4000)),
                        'atMs'    => 0,
                    );
                    if (count($errors) >= 80) {
                        break 2;
                    }
                }
            }

            return $errors;
        }

        public function parse_runtime_js_scan_console_errors(WP_REST_Request $request)
        {
            $text = (string) $request->get_param('text');
            if ('' === trim($text)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Missing console error text.', 'ultracache'),
                ), 400);
            }

            $url = (string) $request->get_param('url');
            $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($url);
            $errors = $this->runtime_js_scan_console_text_to_errors($text);
            $scan = $this->build_runtime_js_scan_suggestions($errors, $scripts);
            $response = array(
                'available'            => true,
                'source'               => 'console-paste-runtime-engine',
                'runtimeErrorCount'    => count($errors),
                'resourceErrorCount'   => 0,
                'suggestionCount'      => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
                'missingCount'         => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
                'alreadyExcludedCount' => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
                'suggestions'          => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
                'errors'               => array_slice($errors, 0, 40),
                'resourceErrors'       => array(),
                'scannedUrl'           => '' !== $url ? $this->runtime_js_scan_sanitize_display_url($url) : home_url('/'),
                'scriptInventoryCount' => count($scripts),
                'scriptInventorySummary' => $this->runtime_js_scan_inventory_summary($scripts),
                'scanContext'          => 'console-paste',
                'completed'            => true,
            );

            return new WP_REST_Response(array('success' => true, 'consoleErrorScan' => $response), 200);
        }

        public function save_runtime_js_scan_report(WP_REST_Request $request)
        {
            $payload = $this->normalize_runtime_js_scan_report_payload($request->get_json_params());
            $scan_id = (string) $payload['scanId'];
            if ('' === $scan_id) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Missing runtime JS scan id.', 'ultracache')), 400);
            }

            $existing = get_transient($this->get_runtime_js_scan_transient_key($scan_id));
            $existing = is_array($existing) ? $existing : array();
            $merged_errors = array();
            foreach (array_merge((array) ($existing['errors'] ?? array()), (array) $payload['errors']) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $dedupe_key = md5((string) ($error['kind'] ?? '') . '|' . (string) ($error['message'] ?? '') . '|' . (string) ($error['source'] ?? '') . '|' . (string) ($error['line'] ?? ''));
                $merged_errors[$dedupe_key] = $error;
            }
            $errors = array_values($merged_errors);
            if (count($errors) > 80) {
                $errors = array_slice($errors, -80);
            }

            $report = array(
                'scanId'     => $scan_id,
                'url'        => !empty($payload['url']) ? $payload['url'] : $this->runtime_js_scan_sanitize_display_url((string) ($existing['url'] ?? '')),
                'startedAt'  => isset($existing['startedAt']) ? (int) $existing['startedAt'] : time(),
                'updatedAt'  => time(),
                'completed'  => !empty($payload['completed']) || !empty($existing['completed']),
                'errors'     => $errors,
                'scripts'    => !empty($payload['scripts']) ? $payload['scripts'] : (array) ($existing['scripts'] ?? array()),
                'scanContext' => !empty($payload['scanContext']) ? (string) $payload['scanContext'] : (string) ($existing['scanContext'] ?? 'anonymous'),
                'errorCount' => count($errors),
                'userAgent'  => !empty($payload['userAgent']) ? $payload['userAgent'] : (string) ($existing['userAgent'] ?? ''),
                'elapsedMs'  => max((int) ($existing['elapsedMs'] ?? 0), (int) $payload['elapsedMs']),
            );
            $report['jsDelaySafetyScan'] = $this->summarize_runtime_js_scan_for_dashboard($report);
            set_transient($this->get_runtime_js_scan_transient_key($scan_id), $report, 10 * MINUTE_IN_SECONDS);

            $queue_job = null;
            if (!empty($payload['queueJobId'])) {
                $queue_status = !empty($report['completed']) ? 'done' : 'running';
                $queue_progress = !empty($report['completed']) ? 100 : 60;
                $queue_result = $this->runtime_js_diagnostic_queue_result_from_scan($this->summarize_runtime_js_scan_for_dashboard($report), $report);
                $queue_job = $this->runtime_js_diagnostic_queue_update_job($payload['queueJobId'], array(
                    'status' => $queue_status,
                    'message' => !empty($report['completed']) ? __('Browser runtime JS diagnostic queue completed.', 'ultracache') : __('Browser runtime JS diagnostic queue received an interim report.', 'ultracache'),
                    'progress_current' => $queue_progress,
                    'result' => $queue_result,
                    'finished_at' => !empty($report['completed']) ? time() : 0,
                ));
            }

            $response = array('success' => true, 'runtimeJsScan' => $report);
            if (is_array($queue_job)) {
                $response['jsDiagnosticQueue'] = $queue_job;
            }
            return new WP_REST_Response($response, 200);
        }

        private function get_runtime_js_scan_resource_errors(array $errors)
        {
            $resources = array();
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $kind = isset($error['kind']) ? strtolower((string) $error['kind']) : '';
                $message = isset($error['message']) ? strtolower((string) $error['message']) : '';
                $source = isset($error['source']) ? (string) $error['source'] : '';
                if ('resource-error' !== $kind && false === strpos($message, 'err_blocked_by_client') && false === strpos($message, 'failed to load resource')) {
                    continue;
                }
                if ('' === $source) {
                    continue;
                }
                $resources[] = array(
                    'kind'    => sanitize_text_field((string) ($error['kind'] ?? 'resource-error')),
                    'message' => sanitize_text_field((string) ($error['message'] ?? 'Resource failed to load')),
                    'source'  => $this->runtime_js_scan_sanitize_source($source),
                    'detail'  => isset($error['detail']) ? sanitize_text_field((string) $error['detail']) : '',
                    'atMs'    => isset($error['atMs']) ? (int) $error['atMs'] : 0,
                    'likelyClientBlocked' => $this->runtime_js_scan_resource_likely_client_blocked($source, (string) ($error['message'] ?? ''), (string) ($error['detail'] ?? '')),
                );
                if (count($resources) >= 40) {
                    break;
                }
            }
            return $resources;
        }

        private function runtime_js_scan_resource_likely_client_blocked($source, $message = '', $detail = '')
        {
            $text = strtolower((string) $source . ' ' . (string) $message . ' ' . (string) $detail);
            if (false !== strpos($text, 'err_blocked_by_client') || false !== strpos($text, 'blocked by client')) {
                return true;
            }
            foreach (array('googletagmanager.com', 'google-analytics.com', 'gtag/js', 'gtm.js', 'doubleclick.net', 'googleadservices.com', 'connect.facebook.net', 'fbevents.js', 'analytics.tiktok.com', 'clarity.ms', 'hotjar.com', 'taboola', 'outbrain', 'pixel', 'tracking', '/ads/', '/adservice') as $needle) {
                if (false !== strpos($text, $needle)) {
                    return true;
                }
            }
            return false;
        }

        private function summarize_runtime_js_scan_for_dashboard(array $report)
        {
            $all_errors = isset($report['errors']) && is_array($report['errors']) ? (array) $report['errors'] : array();
            $resource_errors = $this->get_runtime_js_scan_resource_errors($all_errors);
            $runtime_errors = array_values(array_filter($all_errors, static function ($error) {
                return is_array($error) && 'resource-error' !== strtolower((string) ($error['kind'] ?? ''));
            }));
            $scan = $this->build_runtime_js_scan_suggestions($runtime_errors, (array) ($report['scripts'] ?? array()));
            return array(
                'available'            => true,
                'source'               => 'browser-runtime',
                'runtimeErrorCount'    => count($runtime_errors),
                'resourceErrorCount'   => count($resource_errors),
                'blockedResourceCount' => count(array_filter($resource_errors, static function ($item) { return !empty($item['likelyClientBlocked']); })),
                'suggestionCount'      => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
                'missingCount'         => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
                'alreadyExcludedCount' => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
                'suggestions'          => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
                'errors'               => array_slice($runtime_errors, 0, 40),
                'resourceErrors'       => array_slice($resource_errors, 0, 40),
                'blockedResources'     => array_slice($resource_errors, 0, 40),
                'scannedUrl'           => isset($report['url']) ? (string) $report['url'] : '',
                'scanContext'          => isset($report['scanContext']) && 'logged-in' === $report['scanContext'] ? 'logged-in' : 'anonymous',
                'completed'            => !empty($report['completed']),
            );
        }


        private function runtime_js_diagnostic_queue_table_name()
        {
            global $wpdb;
            return $wpdb->prefix . 'ultracache_js_diagnostic_jobs';
        }

        private function runtime_js_diagnostic_queue_db_version()
        {
            return '1';
        }

        private function runtime_js_diagnostic_queue_db_version_option_key()
        {
            return 'ultracache_js_diagnostic_queue_db_version';
        }

        private function ensure_runtime_js_diagnostic_queue_table()
        {
            global $wpdb;
            if (!($wpdb instanceof wpdb)) {
                return false;
            }

            $table = $this->runtime_js_diagnostic_queue_table_name();
            $version = (string) get_option($this->runtime_js_diagnostic_queue_db_version_option_key(), '');
            if ($version === $this->runtime_js_diagnostic_queue_db_version()) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue schema check.
                $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                if ((string) $found === (string) $table) {
                    return true;
                }
            }

            if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
                return false;
            }
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                job_id varchar(64) NOT NULL,
                scan_type varchar(30) NOT NULL DEFAULT 'runtime',
                status varchar(20) NOT NULL DEFAULT 'queued',
                target_url text NULL,
                scan_context varchar(30) NOT NULL DEFAULT 'anonymous',
                message text NULL,
                console_text longtext NULL,
                payload longtext NULL,
                result longtext NULL,
                progress_current int(10) unsigned NOT NULL DEFAULT 0,
                progress_total int(10) unsigned NOT NULL DEFAULT 100,
                created_at bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
                started_at bigint(20) unsigned NOT NULL DEFAULT 0,
                finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (job_id),
                KEY status_updated (status, updated_at),
                KEY scan_type_status (scan_type, status),
                KEY created_at (created_at)
            ) {$charset_collate};";

            dbDelta($sql);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue schema check immediately after dbDelta.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) $found === (string) $table) {
                update_option($this->runtime_js_diagnostic_queue_db_version_option_key(), $this->runtime_js_diagnostic_queue_db_version(), false);
                return true;
            }

            return false;
        }

        private function runtime_js_diagnostic_queue_new_job_id()
        {
            $random = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('jsdq_', true);
            return 'jsdq_' . substr(str_replace('-', '', sanitize_key((string) $random)), 0, 40);
        }

        private function runtime_js_diagnostic_queue_buckets_from_scan(array $scan)
        {
            $suggestions = isset($scan['suggestions']) && is_array($scan['suggestions']) ? $scan['suggestions'] : array();
            $buckets = array(
                'confirmedErrorFixes' => array(),
                'suggestions'         => array(),
                'reviewOnly'          => array(),
                'alreadyListed'       => array(),
                'ignored'             => array(),
            );

            foreach ($suggestions as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['ignored']) || (isset($item['confidence']) && 'ignored' === strtolower((string) $item['confidence']))) {
                    $buckets['ignored'][] = $item;
                    continue;
                }
                if (!empty($item['alreadyExcluded'])) {
                    $buckets['alreadyListed'][] = $item;
                    continue;
                }
                if (empty($item['appendable'])) {
                    $buckets['reviewOnly'][] = $item;
                    continue;
                }
                if (isset($scan['source']) && 'browser-runtime' === (string) $scan['source']) {
                    $buckets['confirmedErrorFixes'][] = $item;
                } elseif (isset($item['category']) && in_array((string) $item['category'], array('browser-runtime-error', 'appendable-fix'), true)) {
                    $buckets['confirmedErrorFixes'][] = $item;
                } else {
                    $buckets['suggestions'][] = $item;
                }
            }

            return $buckets;
        }

        private function runtime_js_diagnostic_queue_result_from_scan(array $scan, array $report = array())
        {
            $buckets = $this->runtime_js_diagnostic_queue_buckets_from_scan($scan);
            return array(
                'available'            => true,
                'dashboardScan'        => $scan,
                'report'               => $report,
                'buckets'              => $buckets,
                'bucketCounts'         => array(
                    'confirmedErrorFixes' => count($buckets['confirmedErrorFixes']),
                    'suggestions'         => count($buckets['suggestions']),
                    'reviewOnly'          => count($buckets['reviewOnly']),
                    'alreadyListed'       => count($buckets['alreadyListed']),
                    'ignored'             => count($buckets['ignored']),
                ),
                'runtimeErrorCount'    => isset($scan['runtimeErrorCount']) ? (int) $scan['runtimeErrorCount'] : (isset($report['errorCount']) ? (int) $report['errorCount'] : 0),
                'resourceErrorCount'   => isset($scan['resourceErrorCount']) ? (int) $scan['resourceErrorCount'] : 0,
                'suggestionCount'      => isset($scan['suggestionCount']) ? (int) $scan['suggestionCount'] : (isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0),
                'missingCount'         => isset($scan['missingCount']) ? (int) $scan['missingCount'] : (isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0),
                'alreadyExcludedCount' => isset($scan['alreadyExcludedCount']) ? (int) $scan['alreadyExcludedCount'] : (isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0),
            );
        }

        private function runtime_js_diagnostic_queue_cache_group()
        {
            return 'ultracache_js_diagnostic_queue';
        }

        private function runtime_js_diagnostic_queue_job_cache_key($job_id)
        {
            return 'ultracache_runtime_js_diagnostic_job_' . md5(sanitize_text_field((string) $job_id));
        }

        private function runtime_js_diagnostic_queue_latest_cache_key()
        {
            return 'ultracache_runtime_js_diagnostic_latest_job';
        }

        private function runtime_js_diagnostic_queue_delete_cache($job_id = '')
        {
            $group = $this->runtime_js_diagnostic_queue_cache_group();
            wp_cache_delete($this->runtime_js_diagnostic_queue_latest_cache_key(), $group);
            $job_id = sanitize_text_field((string) $job_id);
            if ('' !== $job_id) {
                wp_cache_delete($this->runtime_js_diagnostic_queue_job_cache_key($job_id), $group);
            }
        }

        private function runtime_js_diagnostic_queue_row_to_job(array $row)
        {
            $result = array();
            if (!empty($row['result'])) {
                $decoded = maybe_unserialize($row['result']);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
            $payload = array();
            if (!empty($row['payload'])) {
                $decoded_payload = maybe_unserialize($row['payload']);
                if (is_array($decoded_payload)) {
                    $payload = $decoded_payload;
                }
            }

            return array(
                'id'              => sanitize_text_field((string) ($row['job_id'] ?? '')),
                'scanType'        => sanitize_key((string) ($row['scan_type'] ?? 'runtime')),
                'status'          => sanitize_key((string) ($row['status'] ?? 'queued')),
                'targetUrl'       => isset($row['target_url']) ? esc_url_raw((string) $row['target_url']) : '',
                'scanContext'     => isset($row['scan_context']) && 'logged-in' === (string) $row['scan_context'] ? 'logged-in' : 'anonymous',
                'message'         => isset($row['message']) ? sanitize_text_field((string) $row['message']) : '',
                'progressCurrent' => (int) ($row['progress_current'] ?? 0),
                'progressTotal'   => max(1, (int) ($row['progress_total'] ?? 100)),
                'createdAt'       => (int) ($row['created_at'] ?? 0),
                'updatedAt'       => (int) ($row['updated_at'] ?? 0),
                'startedAt'       => (int) ($row['started_at'] ?? 0),
                'finishedAt'      => (int) ($row['finished_at'] ?? 0),
                'payload'         => $payload,
                'result'          => $result,
            );
        }

        private function runtime_js_diagnostic_queue_get_job($job_id)
        {
            global $wpdb;
            if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
                return null;
            }
            $job_id = sanitize_text_field((string) $job_id);
            if ('' === $job_id) {
                return null;
            }
            $cache_group = $this->runtime_js_diagnostic_queue_cache_group();
            $cache_key = $this->runtime_js_diagnostic_queue_job_cache_key($job_id);
            $cache_found = false;
            $cached_job = wp_cache_get($cache_key, $cache_group, false, $cache_found);
            if ($cache_found) {
                return is_array($cached_job) ? $cached_job : null;
            }

            $table = $this->runtime_js_diagnostic_queue_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue row read cached by job id below.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE job_id = %s', $table, $job_id), ARRAY_A);
            $job = is_array($row) ? $this->runtime_js_diagnostic_queue_row_to_job($row) : null;
            wp_cache_set($cache_key, $job, $cache_group, MINUTE_IN_SECONDS);
            return $job;
        }

        private function runtime_js_diagnostic_queue_latest_job()
        {
            global $wpdb;
            if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
                return null;
            }
            $cache_group = $this->runtime_js_diagnostic_queue_cache_group();
            $cache_key = $this->runtime_js_diagnostic_queue_latest_cache_key();
            $cache_found = false;
            $cached_job = wp_cache_get($cache_key, $cache_group, false, $cache_found);
            if ($cache_found) {
                return is_array($cached_job) ? $cached_job : null;
            }

            $table = $this->runtime_js_diagnostic_queue_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue latest row read cached below.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i ORDER BY updated_at DESC, created_at DESC LIMIT 1', $table), ARRAY_A);
            $job = is_array($row) ? $this->runtime_js_diagnostic_queue_row_to_job($row) : null;
            wp_cache_set($cache_key, $job, $cache_group, MINUTE_IN_SECONDS);
            return $job;
        }

        private function runtime_js_diagnostic_queue_insert_job(array $data)
        {
            global $wpdb;
            if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
                return null;
            }
            $now = time();
            $job_id = $this->runtime_js_diagnostic_queue_new_job_id();
            $table = $this->runtime_js_diagnostic_queue_table_name();
            $row = array(
                'job_id'           => $job_id,
                'scan_type'        => sanitize_key((string) ($data['scan_type'] ?? 'runtime')),
                'status'           => sanitize_key((string) ($data['status'] ?? 'running')),
                'target_url'       => esc_url_raw((string) ($data['target_url'] ?? '')),
                'scan_context'     => isset($data['scan_context']) && 'logged-in' === (string) $data['scan_context'] ? 'logged-in' : 'anonymous',
                'message'          => sanitize_text_field((string) ($data['message'] ?? 'JS diagnostic queue started.')),
                'console_text'     => isset($data['console_text']) ? sanitize_textarea_field((string) $data['console_text']) : '',
                'payload'          => maybe_serialize(isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array()),
                'result'           => maybe_serialize(isset($data['result']) && is_array($data['result']) ? $data['result'] : array()),
                'progress_current' => isset($data['progress_current']) ? absint($data['progress_current']) : 5,
                'progress_total'   => 100,
                'created_at'       => $now,
                'updated_at'       => $now,
                'started_at'       => $now,
                'finished_at'      => isset($data['finished_at']) ? absint($data['finished_at']) : 0,
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue insert; related queue object-cache entries are invalidated immediately after write.
            $ok = $wpdb->insert($table, $row, array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d'));
            if (!$ok) {
                return null;
            }
            $this->runtime_js_diagnostic_queue_delete_cache($job_id);
            return $this->runtime_js_diagnostic_queue_get_job($job_id);
        }

        private function runtime_js_diagnostic_queue_update_job($job_id, array $changes)
        {
            global $wpdb;
            if (!$this->ensure_runtime_js_diagnostic_queue_table()) {
                return null;
            }
            $job_id = sanitize_text_field((string) $job_id);
            if ('' === $job_id) {
                return null;
            }
            $row = array('updated_at' => time());
            $formats = array('%d');
            if (isset($changes['status'])) {
                $row['status'] = sanitize_key((string) $changes['status']);
                $formats[] = '%s';
            }
            if (isset($changes['message'])) {
                $row['message'] = sanitize_text_field((string) $changes['message']);
                $formats[] = '%s';
            }
            if (isset($changes['progress_current'])) {
                $row['progress_current'] = absint($changes['progress_current']);
                $formats[] = '%d';
            }
            if (isset($changes['result']) && is_array($changes['result'])) {
                $row['result'] = maybe_serialize($changes['result']);
                $formats[] = '%s';
            }
            if (isset($changes['payload']) && is_array($changes['payload'])) {
                $row['payload'] = maybe_serialize($changes['payload']);
                $formats[] = '%s';
            }
            if (isset($changes['finished_at'])) {
                $row['finished_at'] = absint($changes['finished_at']);
                $formats[] = '%d';
            }
            $table = $this->runtime_js_diagnostic_queue_table_name();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned diagnostic queue update; related queue object-cache entries are invalidated immediately after write.
            $wpdb->update($table, $row, array('job_id' => $job_id), $formats, array('%s'));
            $this->runtime_js_diagnostic_queue_delete_cache($job_id);
            return $this->runtime_js_diagnostic_queue_get_job($job_id);
        }

        private function runtime_js_diagnostic_queue_response($job)
        {
            if (!is_array($job)) {
                return array('success' => false, 'message' => __('JS diagnostic queue job not found.', 'ultracache'));
            }
            return array('success' => true, 'jsDiagnosticQueue' => $job);
        }

        public function runtime_js_diagnostic_queue_start(WP_REST_Request $request)
        {
            $params = $request->get_json_params();
            $params = is_array($params) ? $params : array();
            $scan_type = sanitize_key((string) ($params['scanType'] ?? $params['type'] ?? 'runtime'));
            if (!in_array($scan_type, array('runtime', 'console'), true)) {
                $scan_type = 'runtime';
            }
            $target_url = isset($params['url']) ? esc_url_raw((string) $params['url']) : home_url('/');
            $scan_context = isset($params['scanContext']) && 'logged-in' === sanitize_key((string) $params['scanContext']) ? 'logged-in' : 'anonymous';
            $console_text = isset($params['text']) ? (string) $params['text'] : '';

            $job = $this->runtime_js_diagnostic_queue_insert_job(array(
                'scan_type'        => $scan_type,
                'status'           => 'running',
                'target_url'       => $target_url,
                'scan_context'     => $scan_context,
                'console_text'     => $console_text,
                'message'          => 'runtime' === $scan_type ? __('Browser runtime JS diagnostic queue started.', 'ultracache') : __('Console JS diagnostic queue started.', 'ultracache'),
                'progress_current' => 10,
                'payload'          => array('url' => $target_url, 'scanContext' => $scan_context),
            ));
            if (!is_array($job)) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Could not create JS diagnostic queue job.', 'ultracache')), 500);
            }

            if ('console' === $scan_type) {
                if ('' === trim($console_text)) {
                    $job = $this->runtime_js_diagnostic_queue_update_job($job['id'], array(
                        'status' => 'failed',
                        'message' => __('Console diagnostic queue needs pasted console text.', 'ultracache'),
                        'progress_current' => 100,
                        'finished_at' => time(),
                    ));
                    return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 400);
                }
                $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($target_url);
                $errors = $this->runtime_js_scan_console_text_to_errors($console_text);
                $scan = $this->build_runtime_js_scan_suggestions($errors, $scripts);
                $dashboard_scan = array(
                    'available'              => true,
                    'source'                 => 'console-paste-runtime-engine',
                    'runtimeErrorCount'      => count($errors),
                    'resourceErrorCount'     => 0,
                    'suggestionCount'        => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
                    'missingCount'           => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
                    'alreadyExcludedCount'   => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
                    'suggestions'            => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
                    'errors'                 => array_slice($errors, 0, 40),
                    'resourceErrors'         => array(),
                    'scannedUrl'             => '' !== $target_url ? $this->runtime_js_scan_sanitize_display_url($target_url) : home_url('/'),
                    'scriptInventoryCount'   => count($scripts),
                    'scriptInventorySummary' => $this->runtime_js_scan_inventory_summary($scripts),
                    'scanContext'            => 'console-paste',
                    'completed'              => true,
                );
                $result = $this->runtime_js_diagnostic_queue_result_from_scan($dashboard_scan, array('errors' => $errors, 'scripts' => $scripts));
                $job = $this->runtime_js_diagnostic_queue_update_job($job['id'], array(
                    'status' => 'done',
                    'message' => __('Console JS diagnostic queue completed.', 'ultracache'),
                    'progress_current' => 100,
                    'result' => $result,
                    'finished_at' => time(),
                ));
            }

            return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 200);
        }

        public function runtime_js_diagnostic_queue_status(WP_REST_Request $request)
        {
            $job_id = sanitize_text_field((string) $request->get_param('jobId'));
            $job = '' !== $job_id ? $this->runtime_js_diagnostic_queue_get_job($job_id) : $this->runtime_js_diagnostic_queue_latest_job();
            if (!is_array($job)) {
                return new WP_REST_Response(array(
                    'success' => true,
                    'message' => __('No JS diagnostic queue job is stored yet.', 'ultracache'),
                    'jsDiagnosticQueue' => null,
                ), 200);
            }
            return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), 200);
        }

        private function runtime_js_diagnostic_queue_transition(WP_REST_Request $request, $status, $message)
        {
            $params = $request->get_json_params();
            $params = is_array($params) ? $params : array();
            $job_id = sanitize_text_field((string) ($params['jobId'] ?? $request->get_param('jobId')));
            if ('' === $job_id) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Missing JS diagnostic queue job id.', 'ultracache')), 400);
            }
            $changes = array('status' => $status, 'message' => $message);
            if (in_array($status, array('cancelled', 'done', 'failed'), true)) {
                $changes['progress_current'] = 100;
                $changes['finished_at'] = time();
            }
            $job = $this->runtime_js_diagnostic_queue_update_job($job_id, $changes);
            return new WP_REST_Response($this->runtime_js_diagnostic_queue_response($job), is_array($job) ? 200 : 404);
        }

        public function runtime_js_diagnostic_queue_pause(WP_REST_Request $request)
        {
            return $this->runtime_js_diagnostic_queue_transition($request, 'paused', __('JS diagnostic queue paused.', 'ultracache'));
        }

        public function runtime_js_diagnostic_queue_resume(WP_REST_Request $request)
        {
            return $this->runtime_js_diagnostic_queue_transition($request, 'running', __('JS diagnostic queue resumed.', 'ultracache'));
        }

        public function runtime_js_diagnostic_queue_cancel(WP_REST_Request $request)
        {
            return $this->runtime_js_diagnostic_queue_transition($request, 'cancelled', __('JS diagnostic queue cancelled.', 'ultracache'));
        }

        public function get_runtime_js_scan_report(WP_REST_Request $request)
        {
            $scan_id = sanitize_key((string) $request->get_param('scanId'));
            if ('' === $scan_id) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Missing runtime JS scan id.', 'ultracache')), 400);
            }

            $report = get_transient($this->get_runtime_js_scan_transient_key($scan_id));
            if (!is_array($report)) {
                return new WP_REST_Response(array(
                    'success' => true,
                    'runtimeJsScan' => array(
                        'scanId' => $scan_id,
                        'available' => false,
                        'completed' => false,
                        'errorCount' => 0,
                        'jsDelaySafetyScan' => array('available' => false, 'suggestions' => array(), 'suggestionCount' => 0, 'missingCount' => 0),
                    ),
                ), 200);
            }
            $report['jsDelaySafetyScan'] = $this->summarize_runtime_js_scan_for_dashboard($report);

            return new WP_REST_Response(array('success' => true, 'runtimeJsScan' => $report), 200);
        }


    }
}
