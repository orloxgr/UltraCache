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
                return new WP_Error('ucwp_profile_url_not_allowed', 'Only same-site URLs can be scanned.');
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
            $add('Buffer start tail', 'send_debug_headers_end', 'buffer_start', 'Final tail before ob_start cache output buffering.');
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

            usort($items, function ($a, $b) {
                return (int) ($b['durationMs'] ?? 0) <=> (int) ($a['durationMs'] ?? 0);
            });

            $top_deltas = array();
            foreach ($checkpoints as $checkpoint) {
                if (!is_array($checkpoint) || empty($checkpoint['stage'])) {
                    continue;
                }
                $stage = (string) $checkpoint['stage'];
                if (!preg_match('/^(maybe_start_buffering|should_bypass|early_hit|page_generation|record_analytics_miss|send_debug_headers|buffer_start|cache_output_callback|css_bundle_ref_validation)/', $stage)) {
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
                'lcp-boundary-defer' => 'LCP Boundary Defer',
                'defer-all-js-final-pass' => 'Defer all JS final pass',
                'safe-html-minify' => 'Safe HTML minify',
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
                'version'                       => isset($profile['version']) ? (string) $profile['version'] : (defined('UCWP_VERSION') ? UCWP_VERSION : ''),
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
                return new WP_REST_Response(array('success' => false, 'message' => 'Speed diagnostics helper is not available.'), 500);
            }

            $profile = $engine->get_last_store_profile();
            if (!is_array($profile) || empty($profile)) {
                return new WP_REST_Response(array('success' => true, 'message' => 'No speed timing breakdown found yet.', 'performanceProfile' => array(), 'profile' => null), 200);
            }

            return new WP_REST_Response(array(
                'success'            => true,
                'message'            => 'Last speed timing breakdown loaded.',
                'performanceProfile' => $this->summarize_performance_profile($profile),
                'profile'            => $profile,
            ), 200);
        }

        public function clear_performance_profile_last()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'clear_last_store_profile')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Speed diagnostics helper is not available.'), 500);
            }

            $ok = (bool) $engine->clear_last_store_profile();
            return new WP_REST_Response(array(
                'success' => true,
                'cleared' => $ok,
                'message' => $ok ? 'Last speed timing breakdown cleared.' : 'No speed timing breakdown was present.',
            ), 200);
        }

        private function run_performance_profile_job(array $params)
        {
            $mode = $this->normalize_performance_profile_mode($params['mode'] ?? 'compact');
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
                return array('success' => false, 'message' => 'Speed diagnostics helper is not available.');
            }

            if (method_exists($engine, 'clear_last_store_profile')) {
                $engine->clear_last_store_profile();
            }

            $headers = array(
                'X-UltraCache-Store-Profile'  => '1',
                'X-UltraCache-Debug'          => '1',
                'X-UltraCache-Profile-Bypass' => '1',
                'X-UltraCache-Token'          => wp_hash('ucwp-revalidate-v1'),
                'Cache-Control'                     => 'no-cache, no-store, max-age=0',
                'Pragma'                            => 'no-cache',
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
                'ucwp_store_profile'  => '1',
                'ucwp_profile_bypass' => '1',
                'ucwp_profile_run'    => substr(md5((string) microtime(true) . wp_rand()), 0, 12),
                'ucwp_rt'             => wp_hash('ucwp-revalidate-v1'),
            );
            if ('verbose' === $mode) {
                $profile_query_args['ucwp_store_profile_verbose'] = '1';
            }
            if ('callback' === $mode) {
                $profile_query_args['ucwp_callback_profile'] = '1';
            }
            $profile_url = add_query_arg($profile_query_args, $url);

            $started = microtime(true);
            $response = ucwp_safe_loopback_remote_request($profile_url, array(
                'timeout'     => 90,
                'redirection' => 3,
                'headers'     => $headers,
                'user-agent'  => 'UltraCache Dashboard Profiler/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown') . '; ' . home_url('/'),
            ));
            $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Profiler request failed: ' . $response->get_error_message(),
                    'performanceProfile' => array('available' => false, 'mode' => $mode),
                );
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $cache_status = (string) wp_remote_retrieve_header($response, 'x-ultra-cache');
            $cache_source = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-source');
            $profile_header = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile');
            $body = wp_remote_retrieve_body($response);

            $profile = $engine->get_last_store_profile();
            if ((!is_array($profile) || empty($profile)) && 'STORE' === strtoupper((string) $cache_status)) {
                // Some stacks finish the profiler response before late shutdown writes
                // become visible to this REST request. Give the timing breakdown one
                // brief chance to appear without adding delay to normal visitors.
                usleep(250000);
                $profile = $engine->get_last_store_profile();
            }

            if (!is_array($profile) || empty($profile)) {
                return array(
                    'success' => false,
                    'message' => 'The page was generated and cached, but the timing breakdown was not saved. This is a Speed Diagnostics issue, not necessarily a site speed issue. Cache status: ' . ($cache_status ?: 'unknown') . '.',
                    'performanceProfile' => array(
                        'available' => false,
                        'mode' => $mode,
                        'responseCode' => $code,
                        'requestMs' => $elapsed_ms,
                        'cacheStatus' => $cache_status,
                        'cacheSource' => $cache_source,
                        'profileHeader' => $profile_header,
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
            $summary['bodyBytes'] = is_string($body) ? strlen($body) : 0;
            $summary['cacheBypassedForDiagnostic'] = true;

            return array(
                'success' => true,
                'message' => strtoupper($mode) . ' performance profile completed.',
                'performanceProfile' => $summary,
            );
        }

    }
}
