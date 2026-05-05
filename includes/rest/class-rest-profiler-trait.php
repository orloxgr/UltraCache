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

            if (function_exists('ucwp_is_strict_frontend_loopback_url') && !ucwp_is_strict_frontend_loopback_url($url)) {
                return new WP_Error('ucwp_profile_url_not_allowed', 'Only same-site frontend URLs on the site port can be scanned.');
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
                return array('success' => false, 'message' => 'Speed diagnostics helper is not available.');
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
                'X-UltraCache-Token'          => wp_hash('ucwp-revalidate-v1'),
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

            $profile = $this->wait_for_performance_profile_for_run($engine, $run_id, 8, 250000);

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
                'message' => strtoupper($mode) . ' performance profile completed.',
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

        private function runtime_js_scan_add_suggestion(&$suggestions, &$seen, $suggested_exclusion, $symbol, $source, $message, $reason, array $exclusions, $confidence = 'high')
        {
            $suggested_exclusion = trim((string) $suggested_exclusion);
            if ('' === $suggested_exclusion) {
                return;
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
            $source = trim((string) $source);
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

        private function runtime_js_scan_path_fragment_from_source($source, $parts = 4)
        {
            $source = trim((string) $source);
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
            return sanitize_text_field($fragment);
        }

        private function runtime_js_scan_sanitize_source($source)
        {
            $source = trim((string) $source);
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
                    if (in_array($lower, array('jquery.min.js', 'jquery-migrate.min.js'), true)) {
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
                        $out[$host] = true;
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

        private function runtime_js_scan_add_function_dependency_suggestions(&$suggestions, &$seen, $function_name, $source, $message, $detail, array $exclusions)
        {
            $function_name = trim((string) $function_name);
            if ('' === $function_name || $this->runtime_js_scan_is_generic_token($function_name)) {
                return;
            }

            $reason = 'A runtime error says a callback/function was called before it was available. Suggestions are derived from the missing function name and stack/source URLs; add the smallest matching exclusions and scan again.';
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $stack_text = (string) $source . "\n" . (string) $detail . "\n" . (string) $message;

            if ('' !== $source_base && preg_match('/\.js$/i', $source_base) && !in_array(strtolower($source_base), array('jquery.min.js', 'jquery-migrate.min.js', 'main.js'), true)) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime function error source', $source, $message, $reason, $exclusions, 'review');
            }

            $this->runtime_js_scan_add_suggestion($suggestions, $seen, $function_name, 'missing runtime function', $source, $message, $reason, $exclusions, 'recommended');

            foreach ($this->runtime_js_scan_split_symbol_tokens($function_name) as $token) {
                if ($this->runtime_js_scan_is_generic_token($token)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $token, 'missing runtime function token', $source, $message, $reason, $exclusions, 'recommended');
            }

            foreach ($this->runtime_js_scan_url_fragments_from_text($stack_text) as $fragment) {
                $fragment = trim((string) $fragment);
                if ('' === $fragment || $this->runtime_js_scan_is_generic_token($fragment)) {
                    continue;
                }
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $fragment, 'runtime stack URL fragment', $source, $message, $reason, $exclusions, 'recommended');
            }
        }

        private function runtime_js_scan_add_jquery_plugin_dependency_suggestions(&$suggestions, &$seen, $method, $source, $message, $detail, array $exclusions)
        {
            $method = trim((string) $method);
            if ('' === $method) {
                return;
            }

            $reason = 'A runtime error says a jQuery plugin method was called before it was registered. These suggestions are derived from the failing script, missing method name, and stack trace symbols; add the smallest matching exclusions and scan again.';
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $stack_text = (string) $source . "\n" . (string) $detail . "\n" . (string) $message;

            $candidate_scripts = array();
            if ('' !== $source_base && 'jquery.min.js' !== strtolower($source_base) && 'jquery-migrate.min.js' !== strtolower($source_base)) {
                $candidate_scripts[$source_base] = true;
            }
            foreach ($this->runtime_js_scan_script_basenames_from_text($stack_text) as $base) {
                $candidate_scripts[$base] = true;
            }

            foreach (array_keys($candidate_scripts) as $base) {
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, $base, 'failing script', $source, $message, $reason, $exclusions, 'high');
            }

            $candidate_symbols = array();
            $candidate_symbols[$method] = true;
            foreach ($this->runtime_js_scan_split_symbol_tokens($method) as $token) {
                $candidate_symbols[$token] = true;
            }

            if (preg_match_all('/(?:\$|jQuery)\.fn\.([A-Za-z_$][A-Za-z0-9_$-]*)/i', $stack_text, $matches)) {
                foreach ((array) $matches[1] as $symbol) {
                    $symbol = sanitize_text_field((string) $symbol);
                    if ('' === $symbol) {
                        continue;
                    }
                    $candidate_symbols[$symbol] = true;
                    foreach ($this->runtime_js_scan_split_symbol_tokens($symbol) as $token) {
                        $candidate_symbols[$token] = true;
                    }
                }
            }

            if (preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$-]*\.([A-Za-z_$][A-Za-z0-9_$-]*(?:[_-][A-Za-z0-9_$-]+)+)/', $stack_text, $stack_symbol_matches)) {
                foreach ((array) $stack_symbol_matches[1] as $symbol) {
                    $symbol = sanitize_text_field((string) $symbol);
                    if ('' === $symbol) {
                        continue;
                    }
                    $candidate_symbols[$symbol] = true;
                    foreach ($this->runtime_js_scan_split_symbol_tokens($symbol) as $token) {
                        $candidate_symbols[$token] = true;
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

        private function runtime_js_scan_add_known_dependency_suggestions(&$suggestions, &$seen, $source, $message, $detail, array $exclusions)
        {
            $text = strtolower((string) $message . ' ' . (string) $source . ' ' . (string) $detail);
            $source_base = $this->runtime_js_scan_basename_from_source($source);
            $matched = false;

            if (false !== strpos($text, 'react is not defined') || false !== strpos($text, "react' is not defined") || false !== strpos($text, "can't find variable: react")) {
                $matched = true;
                $reason = 'Browser runtime error says React was unavailable when a dependent script executed. Keep the WordPress React dependency chain and the failing dependent script out of Defer/Delay until the page scans cleanly.';
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react', 'React global', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react-dom', 'ReactDOM global', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-element', 'WordPress React wrapper', $source, $message, $reason, $exclusions, 'recommended');
                if ('' !== $source_base) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'React dependent script', $source, $message, 'This script threw while React was missing. Excluding the dependency chain is the primary fix; this filename is the targeted dependent script to review.', $exclusions, 'high');
                }
            }

            if (false !== strpos($text, 'reactdom is not defined') || false !== strpos($text, 'react-dom')) {
                $matched = true;
                $reason = 'Browser runtime error points to the ReactDOM dependency. Keep React, ReactDOM, and wp-element outside Defer/Delay before dependent blocks execute.';
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react', 'ReactDOM dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'react-dom', 'ReactDOM dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-element', 'WordPress React wrapper', $source, $message, $reason, $exclusions, 'recommended');
                if ('' !== $source_base) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'ReactDOM dependent script', $source, $message, $reason, $exclusions, 'high');
                }
            }

            if (false !== strpos($text, 'elementormodules is not defined') || false !== strpos($text, 'elementor modules is not defined')) {
                $matched = true;
                $reason = 'Browser runtime error says Elementor modules were unavailable. Keep Elementor module/runtime providers and the failing Elementor dependent script in the visible JS Delay / Defer Exclusions list, then scan again.';
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor-frontend-modules', 'Elementor modules dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'frontend-modules', 'Elementor modules dependency', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementorModules', 'Elementor modules global', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor-webpack-runtime', 'Elementor webpack runtime', $source, $message, $reason, $exclusions, 'recommended');
                $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor-pro-webpack-runtime', 'Elementor Pro webpack runtime', $source, $message, $reason, $exclusions, 'recommended');

                $source_fragment = $this->runtime_js_scan_path_fragment_from_source($source, 4);
                if ('' !== $source_fragment && false !== strpos(strtolower($source_fragment), 'elementor')) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_fragment, 'Elementor dependent script path', $source, $message, 'This targeted Elementor script path is safer than excluding a broad basename such as common.min.js when fixing the missing elementorModules order.', $exclusions, 'recommended');
                }

                if (false !== strpos($text, 'common.min.js')) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor/assets/js/common.min.js', 'Elementor common script path', $source, $message, 'Elementor common.min.js executed before elementorModules existed. Exclude the Elementor common path or its exact basename only if the path is unavailable.', $exclusions, 'recommended');
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'common.min.js', 'Elementor common script basename', $source, $message, 'Fallback basename for Elementor common.min.js. Prefer the path-based suggestion when possible because common.min.js can be broad.', $exclusions, 'review');
                }

                if (false !== strpos($text, 'elementor-admin-bar.min.js')) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor/assets/js/elementor-admin-bar.min.js', 'Elementor admin-bar script path', $source, $message, 'Elementor admin-bar script executed before elementorModules existed. Keep this dependent admin-bar script ordered with Elementor modules.', $exclusions, 'recommended');
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'elementor-admin-bar.min.js', 'Elementor admin-bar script basename', $source, $message, 'Fallback basename for Elementor admin-bar. Use only if the path-based suggestion is not present in the final HTML.', $exclusions, 'high');
                }

                if ('' !== $source_base) {
                    $source_base_confidence = in_array(strtolower($source_base), array('common.min.js'), true) ? 'review' : 'high';
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'Elementor dependent script', $source, $message, 'This Elementor script executed before elementorModules existed. Keep its module dependency chain protected and review this script as the failing dependent source.', $exclusions, $source_base_confidence);
                }
            }

            if (false !== strpos($text, 'wp-api-fetch-js-after') || false !== strpos($text, 'wp.apifetch') || false !== strpos($text, 'api-fetch') || false !== strpos($text, "reading 'use'") || false !== strpos($text, 'reading \"use\"')) {
                if (false !== strpos($text, 'wp-api-fetch') || false !== strpos($text, 'api-fetch') || false !== strpos($text, "reading 'use'") || false !== strpos($text, 'reading \"use\"')) {
                    $matched = true;
                    $reason = 'Browser runtime error points to the WordPress apiFetch inline-after block. Keep wp-api-fetch and its hook dependency available before inline-after configuration runs.';
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-api-fetch', 'wp.apiFetch dependency', $source, $message, $reason, $exclusions, 'recommended');
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-hooks', 'wp.apiFetch hook dependency', $source, $message, $reason, $exclusions, 'recommended');
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, 'wp-api-fetch-js-after', 'wp-api-fetch inline-after block', $source, $message, $reason, $exclusions, 'recommended');
                    if ('' !== $source_base && false !== strpos(strtolower($source_base), 'api-fetch') && 'wp-api-fetch-js-after' !== strtolower($source_base)) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'wp-api-fetch pseudo-source', $source, $message, $reason, $exclusions, 'recommended');
                    }
                }
            }

            return $matched;
        }

        private function build_runtime_js_scan_suggestions(array $errors)
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

                if ($this->runtime_js_scan_add_known_dependency_suggestions($suggestions, $seen, $source, $message, $detail, $exclusions)) {
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

                if (preg_match('/\$\(\.\.\.\)\.([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $message, $method_match)) {
                    $this->runtime_js_scan_add_jquery_plugin_dependency_suggestions($suggestions, $seen, (string) $method_match[1], $source, $message, $detail, $exclusions);
                    continue;
                }

                if (preg_match('/(?:InvalidValueError:\s*)?([A-Za-z_$][A-Za-z0-9_$-]*)\s+is\s+not\s+a\s+function/i', $message, $function_match)) {
                    $this->runtime_js_scan_add_function_dependency_suggestions($suggestions, $seen, (string) $function_match[1], $source, $message, $detail, $exclusions);
                    continue;
                }

                if (false !== strpos($text, ' is not a function') || false !== strpos($text, 'c is not a function')) {
                    if ('' !== $source_base && preg_match('/\.js$/i', $source_base)) {
                        $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime function error source', $source, $message, 'A function call failed at runtime. The failing script is shown as a targeted exclusion candidate. If the error names a missing jQuery plugin method, Runtime Scan will also derive method/token suggestions from the message and stack trace.', $exclusions, 'review');
                    }
                    continue;
                }

                if ('' !== $source_base && preg_match('/\.js$/i', $source_base)) {
                    $this->runtime_js_scan_add_suggestion($suggestions, $seen, $source_base, 'runtime error source', $source, $message, 'This script produced a browser runtime error during the Defer all JS scan. Review it and add this filename to exclusions if the error is caused by defer order.', $exclusions, 'review');
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
                'userAgent' => isset($payload['userAgent']) ? sanitize_text_field((string) $payload['userAgent']) : '',
                'elapsedMs' => isset($payload['elapsedMs']) ? (int) $payload['elapsedMs'] : 0,
            );
        }

        public function save_runtime_js_scan_report(WP_REST_Request $request)
        {
            $payload = $this->normalize_runtime_js_scan_report_payload($request->get_json_params());
            $scan_id = (string) $payload['scanId'];
            if ('' === $scan_id) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Missing runtime JS scan id.'), 400);
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
                'errorCount' => count($errors),
                'userAgent'  => !empty($payload['userAgent']) ? $payload['userAgent'] : (string) ($existing['userAgent'] ?? ''),
                'elapsedMs'  => max((int) ($existing['elapsedMs'] ?? 0), (int) $payload['elapsedMs']),
            );
            $report['jsDelaySafetyScan'] = $this->summarize_runtime_js_scan_for_dashboard($report);
            set_transient($this->get_runtime_js_scan_transient_key($scan_id), $report, 10 * MINUTE_IN_SECONDS);

            return new WP_REST_Response(array('success' => true, 'runtimeJsScan' => $report), 200);
        }

        private function summarize_runtime_js_scan_for_dashboard(array $report)
        {
            $scan = $this->build_runtime_js_scan_suggestions((array) ($report['errors'] ?? array()));
            return array(
                'available'            => true,
                'source'               => 'browser-runtime',
                'runtimeErrorCount'    => isset($report['errors']) && is_array($report['errors']) ? count($report['errors']) : 0,
                'suggestionCount'      => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
                'missingCount'         => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
                'alreadyExcludedCount' => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
                'suggestions'          => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
                'errors'               => isset($report['errors']) && is_array($report['errors']) ? array_slice($report['errors'], 0, 40) : array(),
                'scannedUrl'           => isset($report['url']) ? (string) $report['url'] : '',
                'completed'            => !empty($report['completed']),
            );
        }

        public function get_runtime_js_scan_report(WP_REST_Request $request)
        {
            $scan_id = sanitize_key((string) $request->get_param('scanId'));
            if ('' === $scan_id) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Missing runtime JS scan id.'), 400);
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
