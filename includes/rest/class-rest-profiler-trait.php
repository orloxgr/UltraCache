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
                return new WP_Error('ucwp_profile_url_not_allowed', __('Only same-site URLs can be scanned.', 'ultracache'));
            }

            if (function_exists('ucwp_is_strict_frontend_loopback_url') && !ucwp_is_strict_frontend_loopback_url($url)) {
                return new WP_Error('ucwp_profile_url_not_allowed', __('Only same-site frontend URLs on the site port can be scanned.', 'ultracache'));
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
                'lcp-boundary-defer' => 'LCP Boundary Defer',
                'defer-all-js-final-pass' => 'Defer all JS final pass',
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
                'X-UltraCache-Profile-Bypass' => '1',
                'X-UltraCache-Profile-Run'    => $run_id,
                'X-UltraCache-Token'          => (function_exists('ucwp_create_runtime_control_token') ? ucwp_create_runtime_control_token() : ''),
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
                'ucwp_store_profile'  => '1',
                'ucwp_profile_bypass' => '1',
                'ucwp_profile_run'    => $run_id,
                'ucwp_rt'             => (function_exists('ucwp_create_runtime_control_token') ? ucwp_create_runtime_control_token() : ''),
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
            $body = wp_remote_retrieve_body($response);

            $profile = $this->wait_for_performance_profile_for_run($engine, $run_id, 8, 250000);

            if (!is_array($profile) || empty($profile)) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %s: cache status returned during the speed diagnostic request. */
                        __('The page was generated and cached, but the timing breakdown was not saved. This is a Speed Diagnostics issue, not necessarily a site speed issue. Cache status: %s.', 'ultracache'),
                        ($cache_status ?: 'unknown')
                    ),
                    'performanceProfile' => array(
                        'available' => false,
                        'mode' => $mode,
                        'responseCode' => $code,
                        'requestMs' => $elapsed_ms,
                        'cacheStatus' => $cache_status,
                        'cacheSource' => $cache_source,
                        'profileHeader' => $profile_header,
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
            $summary['profileRunId'] = $run_id;
            $summary['bodyBytes'] = is_string($body) ? strlen($body) : 0;
            $summary['cacheBypassedForDiagnostic'] = true;

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
            return 'ucwp_runtime_js_scan_' . $scan_id;
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
                $raw = get_option(UCWP_SETTINGS_KEY, array());
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
                if ($line === $suggestion || false !== strpos($suggestion, $line)) {
                    return true;
                }
                if (strlen($line) >= 4 && strlen($suggestion) >= 4 && false !== strpos($line, $suggestion)) {
                    return true;
                }
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
                $is_jquery_path = false !== strpos($suggested_lc, 'jquery/');
                if (!$has_path_context || $is_jquery_path) {
                    return;
                }
            }
            $confidence = strtolower(trim((string) $confidence));
            if ('' === $confidence) {
                $confidence = 'high';
            }
            $appendable = !in_array($confidence, array('review', 'review-only', 'manual'), true);
            $key = strtolower($suggested_exclusion . '|' . (string) $source . '|' . (string) $symbol);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $suggestions[] = array(
                'symbol'             => (string) $symbol,
                'source'             => 'browser-runtime-error',
                'category'           => $appendable ? 'browser-runtime-error' : 'review-only',
                'categoryLabel'      => $appendable ? 'Browser runtime errors' : 'Review-only candidates',
                'sample'             => substr((string) $message, 0, 500),
                'definingScriptUrl'  => (string) $source,
                'definingHandle'     => '',
                'suggestedExclusion' => $suggested_exclusion,
                'confidence'         => (string) $confidence,
                'reason'             => (string) $reason,
                'alreadyExcluded'    => $this->runtime_js_scan_exclusion_already_matches($suggested_exclusion, $exclusions),
                'appendable'         => $appendable,
            );
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
            $path_lc = strtolower($path);
            $is_targeted_wp_content = (false !== strpos($path_lc, 'wp-content/plugins/') || false !== strpos($path_lc, 'wp-content/themes/') || 0 === strpos($path_lc, 'plugins/') || 0 === strpos($path_lc, 'themes/'));
            if ($this->runtime_js_scan_is_generic_script_basename($base) && !$is_targeted_wp_content) {
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
                'ucwp_runtime_js_scan',
                'ucwp_runtime_js_scan_id',
                'ucwp_runtime_js_scan_nonce',
                'ucwp_runtime_js_scan_context',
                'ucwp_rt',
                'ucwp_profile_bypass',
                'ucwp_store_profile',
                'ucwp_callback_profile',
                'ucwp_store_profile_verbose',
                'ucwp_store_profile_verbose_settings',
                'ucwp_profile_run',
                'ucwp_revalidate',
            ), $url);
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
                'jQuery',
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

        private function runtime_js_scan_attribute_from_tag($attributes, $name)
        {
            $attributes = (string) $attributes;
            $name = preg_quote((string) $name, '/');
            if (preg_match('/\\b' . $name . '\\s*=\\s*(["\\\'])(.*?)\\1/is', $attributes, $match)) {
                return html_entity_decode((string) $match[2], ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/\\b' . $name . '\\s*=\\s*([^\\s>]+)/is', $attributes, $match)) {
                return html_entity_decode((string) $match[1], ENT_QUOTES, 'UTF-8');
            }
            return '';
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
            if ('' !== $fragment) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, $label, $source_for_display, $message, $reason, $exclusions, $confidence);
            } else {
                $service_fragment = $this->runtime_js_scan_service_fragment_from_source($script_src, $global);
                if ('' !== $service_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $service_fragment, $label . ' service endpoint', $source_for_display, $message, $reason, $exclusions, 'review');
                }
            }
            if ('' !== $script_id) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $script_id, $label . ' handle/id', $source_for_display, $message, $reason, $exclusions, $confidence);
                $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($script_id);
                if ('' !== $related_id && isset($GLOBALS['ucwp_runtime_js_scan_scripts'])) {
                    $related = $this->runtime_js_scan_find_script_by_id((array) $GLOBALS['ucwp_runtime_js_scan_scripts'], $related_id);
                    if (!empty($related)) {
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

            $GLOBALS['ucwp_runtime_js_scan_scripts'] = $scripts;
            foreach ($globals as $global) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $global, 'resolved dynamic window callback global', $source, $message, $reason, $exclusions, 'review');
                foreach ($this->runtime_js_scan_find_scripts_with_symbol_text($global, $scripts) as $provider) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback context script', $source, $message, $reason, $exclusions, 'review', $global);
                }
                foreach ($this->runtime_js_scan_find_scripts_by_global_source_hint($global, $scripts) as $provider) {
                    $this->runtime_js_scan_add_script_identity_suggestions($suggestions, $seen, $provider, 'resolved dynamic callback source/provider hint', $source, $message, $reason, $exclusions, 'review', $global);
                }
            }
            unset($GLOBALS['ucwp_runtime_js_scan_scripts']);
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
                'ucwp_js_inventory' => '1',
                'ucwp_rt'           => time(),
            ), $normalized);

            $response = wp_remote_get($request_url, array(
                'timeout'     => 8,
                'redirection' => 3,
                'headers'     => array(
                    'User-Agent' => 'UltraCache JS inventory/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown'),
                    'Accept'     => 'text/html,application/xhtml+xml',
                ),
            ));
            if (is_wp_error($response)) {
                return array();
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                return array();
            }

            $html = (string) wp_remote_retrieve_body($response);
            if ('' === $html || !preg_match_all('/<script\\b([^>]*)>(.*?)<\\/script>/is', $html, $matches, PREG_SET_ORDER)) {
                return array();
            }

            $scripts = array();
            foreach ($matches as $match) {
                $attributes = isset($match[1]) ? (string) $match[1] : '';
                $body = isset($match[2]) ? (string) $match[2] : '';
                $src = $this->runtime_js_scan_attribute_from_tag($attributes, 'src');
                if ('' === $src) {
                    $src = $this->runtime_js_scan_attribute_from_tag($attributes, 'data-ucwp-src');
                }
                if ('' === $src) {
                    $src = $this->runtime_js_scan_attribute_from_tag($attributes, 'data-ucwp-original-src');
                }
                $id = $this->runtime_js_scan_attribute_from_tag($attributes, 'id');
                if ('' === $id) {
                    $id = $this->runtime_js_scan_attribute_from_tag($attributes, 'data-ucwp-id');
                }
                if ('' === $id) {
                    $source_url_id = $this->runtime_js_scan_source_url_id_from_inline_text($body);
                    if ('' !== $source_url_id) {
                        $id = $source_url_id;
                    }
                }
                if ('' === $id) {
                    $handle_id = $this->runtime_js_scan_attribute_from_tag($attributes, 'data-ucwp-handle');
                    if ('' !== $handle_id) {
                        $id = $handle_id;
                    }
                }
                $type = $this->runtime_js_scan_attribute_from_tag($attributes, 'type');
                $is_delayed = (bool) preg_match('/\bdata-ucwp-(?:src|inline|delayed)\b/i', $attributes) || false !== stripos($type, 'ucwp-delayed');

                $scripts[] = array(
                    'id'       => sanitize_text_field(substr($id, 0, 160)),
                    'handle'   => sanitize_text_field(substr((string) $this->runtime_js_scan_attribute_from_tag($attributes, 'data-ucwp-handle'), 0, 160)),
                    'src'      => '' !== $src ? $this->runtime_js_scan_url_to_absolute($src, $normalized) : '',
                    'type'     => sanitize_text_field(substr($type, 0, 120)),
                    'defer'    => (bool) preg_match('/\bdefer(?:\s|=|>|$)/i', $attributes),
                    'async'    => (bool) preg_match('/\basync(?:\s|=|>|$)/i', $attributes),
                    'strategy' => $this->runtime_js_scan_attribute_from_tag($attributes, 'data-wp-strategy'),
                    'delayed'  => $is_delayed,
                    'text'     => '' === $src || $is_delayed ? sanitize_textarea_field(substr($body, 0, 60000)) : '',
                );

                if (count($scripts) >= 240) {
                    break;
                }
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
            if ('' === $absolute) {
                return '';
            }

            $parts = wp_parse_url($absolute);
            $home_parts = wp_parse_url(home_url('/'));
            $site_parts = wp_parse_url(site_url('/'));
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            $allowed_hosts = array_filter(array(
                isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '',
                isset($site_parts['host']) ? strtolower((string) $site_parts['host']) : '',
            ));
            if ('' === $host || !in_array($host, $allowed_hosts, true)) {
                return '';
            }

            $url_path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
            $url_path = '/' . ltrim(str_replace('\\\\', '/', $url_path), '/');
            if (!preg_match('/\\.(?:js|mjs)$/i', $url_path)) {
                return '';
            }

            $candidates = array();
            $content_url_path = (string) wp_parse_url(content_url('/'), PHP_URL_PATH);
            $content_url_path = '/' . trim(str_replace('\\\\', '/', $content_url_path), '/') . '/';
            if ('//' === $content_url_path) {
                $content_url_path = '/wp-content/';
            }
            if (0 === strpos($url_path, $content_url_path)) {
                $relative = ltrim(substr($url_path, strlen($content_url_path)), '/');
                $candidates[] = trailingslashit(WP_CONTENT_DIR) . $relative;
            }

            $includes_url_path = (string) wp_parse_url(includes_url('/'), PHP_URL_PATH);
            $includes_url_path = '/' . trim(str_replace('\\\\', '/', $includes_url_path), '/') . '/';
            if (0 === strpos($url_path, $includes_url_path)) {
                $relative = ltrim(substr($url_path, strlen($includes_url_path)), '/');
                $candidates[] = trailingslashit(ABSPATH . WPINC) . $relative;
            }

            $home_path = isset($home_parts['path']) ? '/' . trim((string) $home_parts['path'], '/') : '';
            if ('' !== $home_path && '/' !== $home_path && 0 === strpos($url_path, trailingslashit($home_path))) {
                $relative = ltrim(substr($url_path, strlen(trailingslashit($home_path))), '/');
                $candidates[] = trailingslashit(ABSPATH) . $relative;
            } elseif (0 === strpos($url_path, '/wp-content/') || 0 === strpos($url_path, '/wp-includes/')) {
                $candidates[] = trailingslashit(ABSPATH) . ltrim($url_path, '/');
            }

            $allowed_roots = array_filter(array(
                function_exists('wp_normalize_path') ? wp_normalize_path(WP_CONTENT_DIR) : str_replace('\\\\', '/', WP_CONTENT_DIR),
                function_exists('wp_normalize_path') ? wp_normalize_path(ABSPATH . WPINC) : str_replace('\\\\', '/', ABSPATH . WPINC),
            ));

            foreach ($candidates as $candidate) {
                $candidate = function_exists('wp_normalize_path') ? wp_normalize_path($candidate) : str_replace('\\\\', '/', $candidate);
                $real = realpath($candidate);
                if (false === $real) {
                    continue;
                }
                $real_norm = function_exists('wp_normalize_path') ? wp_normalize_path($real) : str_replace('\\\\', '/', $real);
                $allowed = false;
                foreach ($allowed_roots as $root) {
                    $root = rtrim($root, '/');
                    if (0 === strpos($real_norm, $root . '/')) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed || !is_file($real_norm) || !is_readable($real_norm)) {
                    continue;
                }
                $size = filesize($real_norm);
                if (false === $size || $size <= 0 || $size > 786432) {
                    continue;
                }
                return $real_norm;
            }

            return '';
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
            if (function_exists('ucwp_guarded_asset_file_get_contents')) {
                $raw = ucwp_guarded_asset_file_get_contents($path, 'js', 'runtime_js_scan_read_local_script_content', true);
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
                $script['_ucwp_match_score'] = $score;
                $matches[] = $script;
            }

            usort($matches, static function ($a, $b) {
                $a_score = isset($a['_ucwp_match_score']) ? (int) $a['_ucwp_match_score'] : 0;
                $b_score = isset($b['_ucwp_match_score']) ? (int) $b['_ucwp_match_score'] : 0;
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
                $fragment = $this->runtime_js_scan_path_fragment_from_source($script_src, 4);
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
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime function error source', $source, $message, $reason, $exclusions, 'review');
            }

            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $function_name, $has_callback_consumer ? 'missing global callback' : 'missing runtime function', $source, $message, $reason, $exclusions, 'recommended');

            foreach ($this->runtime_js_scan_split_symbol_tokens($function_name) as $token) {
                if ($this->runtime_js_scan_is_generic_token($token)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $token, $has_callback_consumer ? 'callback symbol token' : 'missing runtime function token', $source, $message, $reason, $exclusions, 'recommended');
            }

            foreach ((array) ($context['providers'] ?? array()) as $provider) {
                $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 4);
                if ('' !== $provider_fragment) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'callback provider script', $provider_src, $message, $reason, $exclusions, 'recommended');
                }
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'callback provider script basename', $provider_src, $message, $reason, $exclusions, 'review');
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
                }
                $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'jQuery plugin provider basename', $provider_src, $message, 'Fallback basename for the JS file that registers the missing jQuery plugin method. Prefer the path-based suggestion when available.', $exclusions, 'high');
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

            $candidate_symbols = array($method => true);

            if (preg_match_all('/(?:\$|jQuery)\.fn\.([A-Za-z_$][A-Za-z0-9_$-]*)/i', $stack_text, $matches)) {
                foreach ((array) $matches[1] as $symbol) {
                    $symbol = sanitize_text_field((string) $symbol);
                    if ('' !== $symbol) {
                        $candidate_symbols[$symbol] = true;
                    }
                }
            }

            if (preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$-]*\.([A-Za-z_$][A-Za-z0-9_$-]*(?:[_-][A-Za-z0-9_$-]+)+)/', $stack_text, $stack_symbol_matches)) {
                foreach ((array) $stack_symbol_matches[1] as $symbol) {
                    $symbol = sanitize_text_field((string) $symbol);
                    if ('' !== $symbol) {
                        $candidate_symbols[$symbol] = true;
                    }
                }
            }

            foreach (array_keys($candidate_symbols) as $symbol) {
                $symbol = trim((string) $symbol);
                if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $symbol, 'missing jQuery plugin method', $source, $message, $reason, $exclusions, 'recommended');
            }
        }

        private function runtime_js_scan_add_known_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions, array $scripts = array())
        {
            $text = strtolower((string) $message . ' ' . (string) $source . ' ' . (string) $detail);
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $matched = false;

            if (false !== strpos($text, 'wp is not defined') || false !== strpos($text, 'wp.')) {
                $matched = true;
                $reason = 'Browser runtime error points to a WordPress core JS dependency/global that executed out of order. These are generic WordPress dependency handles, not plugin/theme-specific rules.';
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-i18n', 'WordPress core dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-hooks', 'WordPress core dependency', $source, $message, $reason, $exclusions, 'recommended');
                if (false !== strpos($text, 'api-fetch') || false !== strpos($text, 'apifetch')) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-api-fetch', 'WordPress apiFetch dependency', $source, $message, $reason, $exclusions, 'recommended');
                }
                if ('' !== $source_base && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'wp-dependent source script', $source, $message, $reason, $exclusions, 'review');
                }
            }

            if (false !== strpos($text, 'react is not defined') || false !== strpos($text, "react' is not defined") || false !== strpos($text, "can't find variable: react") || false !== strpos($text, 'reactdom is not defined')) {
                $matched = true;
                $reason = 'Browser runtime error points to a React/ReactDOM dependency that executed out of order. Suggestions are limited to generic React/WordPress dependency handles and the resolved failing script, where available.';
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react', 'React dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react-dom', 'ReactDOM dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-element', 'WordPress React wrapper', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_script_source_resolution_suggestions($suggestions, $seen, $scripts, $source, $message, $reason, $exclusions, 'React dependent resolved source', 'review', true);
            }

            return $matched;
        }

        private function build_runtime_js_scan_suggestions(array $errors, array $scripts = array())
        {
            $exclusions = $this->get_runtime_js_scan_current_exclusions();
            $suggestions = array();
            $seen = array();

            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
                $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
                $detail = isset($error['detail']) ? sanitize_text_field((string) $error['detail']) : '';
                if ('' === $source) {
                    $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
                }
                $text = strtolower($message . ' ' . $source . ' ' . $detail);
                $source_base = $this->runtime_js_scan_basename_from_source($source);

                if ($this->runtime_js_scan_add_known_dependency_suggestions($suggestions, $seen, $source, $message, $detail, $exclusions, $scripts)) {
                    continue;
                }

                if (false !== strpos($text, 'jquery is not defined') || preg_match('/\$ is not defined/', $text)) {
                    $reason = 'Browser runtime error says jQuery was not available when an inline block or script executed. Add jQuery to the visible JS Delay / Defer Exclusions list, then scan again.';
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'jquery', 'jQuery', $source, $message, $reason, $exclusions);
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'jquery-core', 'jQuery', $source, $message, $reason, $exclusions);
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'jquery-migrate', 'jQuery', $source, $message, $reason, $exclusions, 'recommended');
                    if ('' !== $source_base && 'jquery.min.js' !== strtolower($source_base) && 'jquery-migrate.min.js' !== strtolower($source_base)) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'jQuery dependent script', $source, $message, 'This script failed while jQuery was unavailable. Excluding jQuery is usually the primary fix; excluding the failing script is an alternate targeted fix.', $exclusions, 'recommended');
                    }
                    continue;
                }

                if (false !== strpos($text, 'wp is not defined')) {
                    $reason = 'Browser runtime error says the WordPress wp global was not available. This usually means wp-i18n/wp-hooks/wp-api-fetch or a translation block executed before its dependency.';
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-i18n', 'wp global', $source, $message, $reason, $exclusions);
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-hooks', 'wp global', $source, $message, $reason, $exclusions);
                    if (false !== strpos($text, 'api-fetch')) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-api-fetch', 'wp.apiFetch', $source, $message, $reason, $exclusions);
                    }
                    if (false !== strpos($text, 'contact-form-7')) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'contact-form-7', 'Contact Form 7 translations', $source, $message, 'Contact Form 7 translation/config inline block failed because wp was unavailable. Exclude wp-i18n/wp-hooks and optionally contact-form-7.', $exclusions, 'recommended');
                    }
                    if ('' !== $source_base) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'wp dependent script', $source, $message, 'This source emitted a wp-global error. Exclude the relevant wp-* dependency first; this source is shown as an alternate targeted exclusion.', $exclusions, 'recommended');
                    }
                    continue;
                }

                if (preg_match('/(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s+is\s+not\s+defined/i', $message . ' ' . $detail, $missing_match)) {
                    $missing_symbol = sanitize_text_field((string) $missing_match[1]);
                    if ('' !== $missing_symbol && !$this->runtime_js_scan_is_generic_token($missing_symbol)) {
                        $reason = 'Runtime Scan found a missing global/config object. The full missing symbol is suggested; broad source files such as jquery.min.js, main.js, and functions.js are intentionally ignored unless a more targeted path is available.';
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $missing_symbol, 'missing global/config object', $source, $message, $reason, $exclusions, 'recommended');
                        foreach ($this->runtime_js_scan_find_symbol_provider_scripts($missing_symbol, $scripts) as $provider) {
                            $provider_src = isset($provider['src']) ? (string) $provider['src'] : '';
                            $provider_id = isset($provider['id']) ? (string) $provider['id'] : '';
                            $provider_fragment = $this->runtime_js_scan_path_fragment_from_source($provider_src, 4);
                            if ('' !== $provider_fragment) {
                                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_fragment, 'missing global provider script', $provider_src, $message, 'Runtime Scan read the local JS files loaded by this page and found the file that defines the missing global/config object. Exclude the provider script so it executes before dependent callers.', $exclusions, 'recommended');
                            }
                            $provider_base = $this->runtime_js_scan_basename_from_source($provider_src);
                            if ('' !== $provider_base && !$this->runtime_js_scan_is_generic_script_basename($provider_base)) {
                                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_base, 'missing global provider basename', $provider_src, $message, 'Fallback basename for the JS file that defines the missing global/config object. Prefer the path-based suggestion when available.', $exclusions, 'high');
                            }
                            if ('' !== $provider_id) {
                                $provider_confidence = ('' === $provider_src && preg_match('/-js-(?:before|after|extra|translations)$/i', $provider_id)) ? 'review' : 'recommended';
                                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $provider_id, 'missing global provider handle/id', $provider_src, $message, 'The scanned page contains an inline or external script id/handle that defines the missing global/config object.', $exclusions, $provider_confidence);

                                $related_id = $this->runtime_js_scan_related_external_id_for_inline_id($provider_id);
                                if ('' !== $related_id) {
                                    $related = $this->runtime_js_scan_find_script_by_id($scripts, $related_id);
                                    if (!empty($related)) {
                                        $related_src = isset($related['src']) ? (string) $related['src'] : '';
                                        $related_fragment = $this->runtime_js_scan_path_fragment_from_source($related_src, 4);
                                        if ('' !== $related_fragment) {
                                            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_fragment, 'missing global related external script', $related_src, $message, 'The missing global was found in an inline companion script. This is the related external script id/path from the same scanned page inventory.', $exclusions, 'review');
                                        }
                                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $related_id, 'missing global related handle/id', $related_src, $message, 'The missing global was found in an inline companion script. This is the related WordPress script handle/id from the same scanned page inventory.', $exclusions, 'review');
                                    }
                                }
                            }
                        }
                        $this->runtime_js_scan_add_inline_stack_frame_suggestions(
                            $suggestions,
                            $seen,
                            $scripts,
                            (string) $detail . "\n" . (string) $message,
                            $message,
                            'The browser stack references a WordPress inline script handle. UltraCache shows that exact inline id and any related external handle/path found in the scanned page inventory as review-only candidates.',
                            $exclusions,
                            'review'
                        );
                        $this->runtime_js_scan_add_script_source_resolution_suggestions(
                            $suggestions,
                            $seen,
                            $scripts,
                            $source,
                            $message,
                            'A script failed because a global/config object was unavailable. UltraCache resolves the failing browser source against the scanned page script inventory and suggests exact loaded script ids/paths instead of broad basenames.',
                            $exclusions,
                            'missing global dependent resolved source',
                            'review',
                            true
                        );
                        foreach ($this->runtime_js_scan_url_fragments_from_text((string) $detail . "
" . (string) $message) as $fragment) {
                            $fragment = trim((string) $fragment);
                            if ('' === $fragment || $this->runtime_js_scan_is_generic_token($fragment)) {
                                continue;
                            }
                            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, 'runtime stack URL fragment', $source, $message, $reason, $exclusions, 'recommended');
                        }
                        if ('' !== $source_base && !$this->runtime_js_scan_is_generic_script_basename($source_base)) {
                            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'missing global error source', $source, $message, $reason, $exclusions, 'review');
                        }
                    }
                    continue;
                }

                if (preg_match('/\$\(\.\.\.\)\.([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $message . ' ' . $detail, $method_match)) {
                    $this->runtime_js_scan_add_jquery_plugin_dependency_suggestions($suggestions, $seen, (string) $method_match[1], $source, $message, $detail, $exclusions, $scripts);
                    continue;
                }

                if (preg_match('/(?:InvalidValueError:\s*)?([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $message, $function_match)) {
                    $this->runtime_js_scan_add_function_dependency_suggestions($suggestions, $seen, (string) $function_match[1], $source, $message, $detail, $exclusions, $scripts);
                    continue;
                }

                if (preg_match('/window\s*\[\s*[A-Za-z_$][A-Za-z0-9_$]*\s*\]\s+is\s+not\s+a\s+function/i', $message . ' ' . $detail)) {
                    $dynamic_reason = 'A script made a dynamic window[callbackName]() call, but the callback name is not visible in the browser error. UltraCache resolves the failing browser source, sourceURL inline stack frames, and matching inline config from the scanned page inventory. It shows exact loaded ids/paths and any resolved callback global as review-only candidates.';
                    $this->runtime_js_scan_add_dynamic_window_global_suggestions($suggestions, $seen, $scripts, $source, $message, $detail, $exclusions);
                    $this->runtime_js_scan_add_script_source_resolution_suggestions(
                        $suggestions,
                        $seen,
                        $scripts,
                        $source,
                        $message,
                        $dynamic_reason,
                        $exclusions,
                        'dynamic callback caller resolved source',
                        'review',
                        true
                    );
                    $this->runtime_js_scan_add_inline_stack_frame_suggestions(
                        $suggestions,
                        $seen,
                        $scripts,
                        (string) $detail . "\n" . (string) $message,
                        $message,
                        $dynamic_reason,
                        $exclusions,
                        'review'
                    );
                    continue;
                }

                if (false !== strpos($text, ' is not a function') || false !== strpos($text, 'c is not a function')) {
                    $function_reason = 'A function call failed at runtime. UltraCache resolves the failing browser source and any inline stack-frame handles against the scanned page script inventory and shows exact loaded ids/paths as review-only candidates.';
                    $this->runtime_js_scan_add_script_source_resolution_suggestions(
                        $suggestions,
                        $seen,
                        $scripts,
                        $source,
                        $message,
                        $function_reason,
                        $exclusions,
                        'runtime function error resolved source',
                        'review',
                        true
                    );
                    $this->runtime_js_scan_add_inline_stack_frame_suggestions(
                        $suggestions,
                        $seen,
                        $scripts,
                        (string) $detail . "\n" . (string) $message,
                        $message,
                        $function_reason,
                        $exclusions,
                        'review'
                    );
                    continue;
                }

                if ('' !== $source_base && preg_match('/\.js$/i', $source_base)) {
                    $this->runtime_js_scan_add_script_source_resolution_suggestions(
                        $suggestions,
                        $seen,
                        $scripts,
                        $source,
                        $message,
                        'This script produced a browser runtime error. UltraCache resolves the console source against the scanned page script inventory where possible.',
                        $exclusions,
                        'runtime error resolved source',
                        'review',
                        true
                    );
                }
            }

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

            return new WP_REST_Response(array('success' => true, 'runtimeJsScan' => $report), 200);
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
