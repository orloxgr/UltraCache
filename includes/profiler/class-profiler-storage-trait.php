<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Profiler_Storage_Trait
{
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
}
