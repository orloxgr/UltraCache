<?php
/**
 * Unified per-page warm pipeline.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_Page_Pipeline_Trait
{
    /**
     * Run the complete page pipeline under a URL-scoped lock shared by manual,
     * cron, warm-after-flush, and targeted-purge sources.
     *
     * @param string $url  Local public URL.
     * @param array  $args Pipeline arguments.
     * @return array
     */
    public function warm_page_pipeline($url, array $args = array())
    {
        $context = sanitize_key((string) ($args['warm_context'] ?? 'warm'));
        $execution_profile = $this->get_warm_pipeline_execution_profile($context);
        $args['execution_profile'] = (string) $execution_profile['key'];
        if (!isset($args['time_budget'])) {
            $args['time_budget'] = (int) $execution_profile['pageTimeBudget'];
        }
        $queue_heartbeat = isset($args['_queue_lease_heartbeat']) && is_callable($args['_queue_lease_heartbeat'])
            ? $args['_queue_lease_heartbeat']
            : null;
        unset($args['_queue_lease_heartbeat']);

        // A global UI/WP-CLI/cron owner must still hold its exact committed
        // token/generation before it may acquire a per-URL lock. Frontend visits
        // do not provide this callback and continue to use URL-only coordination.
        if (is_callable($queue_heartbeat)) {
            try {
                if (false === call_user_func($queue_heartbeat, 'url-claim-before')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline');
                }
            } catch (Throwable $error) {
                unset($error);
                return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline');
            }
        }

        $lock = $this->acquire_warm_pipeline_url_lock($url, $context);
        if (empty($lock['acquired'])) {
            return $this->get_coalesced_warm_pipeline_result($url, 'pipeline');
        }

        $heartbeat = function ($heartbeat_stage = '') use (&$lock, $queue_heartbeat) {
            if (is_callable($queue_heartbeat) && false === call_user_func($queue_heartbeat, $heartbeat_stage)) {
                return false;
            }
            return $this->renew_warm_pipeline_url_lock($lock, $heartbeat_stage);
        };

        try {
            if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'pipeline-start')) {
                return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline');
            }

            // Targeted invalidation belongs inside the same URL-scoped ownership
            // boundary as the warm itself. Never purge a URL while another warm
            // source owns that URL and is actively rebuilding it.
            if (!empty($args['purge_target_first'])) {
                unset($args['purge_target_first']);
                if (!method_exists($this, 'purge_url') || !$this->purge_url($url)) {
                    return array(
                        'success' => false,
                        'skipped' => false,
                        'retryable' => false,
                        'terminal' => true,
                        'failureClass' => 'target-purge-failed',
                        'url' => esc_url_raw((string) $url),
                        'stage' => 'purge',
                        'stageStatus' => 'failed',
                        'message' => __('Selected URL could not be invalidated before warm-up.', 'ultracache'),
                    );
                }
                if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'target-purged')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline');
                }
            } else {
                unset($args['purge_target_first']);
            }

            $args['_warm_pipeline_heartbeat'] = $heartbeat;
            return $this->run_warm_page_pipeline($url, $args);
        } finally {
            $this->release_warm_pipeline_url_lock($lock);
        }
    }

    /**
     * Run every selected warm stage for one local page before reporting completion.
     *
     * Dashboard, cron, and warm-after-flush paths use this same per-page
     * contract so selected HTML, CSS, Varnish, and LiteSpeed work completes
     * before the page is reported as finished.
     *
     * @param string $url  Local public URL.
     * @param array  $args Pipeline arguments.
     * @return array
     */
    private function run_warm_page_pipeline($url, array $args = array())
    {
        $args = is_array($args) ? $args : array();
        $heartbeat = isset($args['_warm_pipeline_heartbeat']) && is_callable($args['_warm_pipeline_heartbeat'])
            ? $args['_warm_pipeline_heartbeat']
            : null;
        unset($args['_warm_pipeline_heartbeat']);

        $url = esc_url_raw((string) $url);
        $build_css_bundle = !empty($args['build_css_bundle']);
        $include_varnish = !empty($args['include_varnish']);
        $include_litespeed = !empty($args['include_litespeed']);
        $warm_context = sanitize_key((string) ($args['warm_context'] ?? 'manual'));
        $external_refills_best_effort = 'runtime_scan' === $warm_context;
        $execution_profile = sanitize_key((string) ($args['execution_profile'] ?? ''));
        if ('' === $execution_profile) {
            $execution_profile = (string) $this->get_warm_pipeline_execution_profile($warm_context)['key'];
        }
        $requires_verified_origin = !empty($args['requires_verified_origin']);
        $allowed_stage_names = array('html', 'css_bundle', 'lcp_refresh', 'varnish', 'litespeed');
        $required_stages = isset($args['required_stages']) && is_array($args['required_stages'])
            ? array_values(array_unique(array_intersect($allowed_stage_names, array_map('sanitize_key', $args['required_stages']))))
            : array('html');
        $completed_stages = isset($args['completed_stages']) && is_array($args['completed_stages'])
            ? array_values(array_unique(array_intersect($allowed_stage_names, array_map('sanitize_key', $args['completed_stages']))))
            : array();
        $html_precompleted = in_array('html', $completed_stages, true);
        $css_precompleted = in_array('css_bundle', $completed_stages, true);
        $lcp_precompleted = in_array('lcp_refresh', $completed_stages, true);
        $varnish_precompleted = in_array('varnish', $completed_stages, true);
        $litespeed_precompleted = in_array('litespeed', $completed_stages, true);
        $lcp_refresh_pending = in_array('lcp_refresh', $required_stages, true)
            && !$lcp_precompleted;
        $css_stage_pending = $build_css_bundle && !$css_precompleted;

        $stages = array(
            'html' => $this->get_warm_pipeline_stage($html_precompleted ? 'completed' : 'planned', true, $html_precompleted ? __('HTML cache was already satisfied by a frontend visit.', 'ultracache') : '', array('source' => $html_precompleted ? 'frontend-visit' : '')),
            'css' => $this->get_warm_pipeline_stage($css_precompleted ? 'completed' : ($build_css_bundle ? 'planned' : 'disabled'), $build_css_bundle, $css_precompleted ? __('CSS bundle stage was already completed.', 'ultracache') : ''),
            'lcp' => $this->get_warm_pipeline_stage($lcp_precompleted ? 'completed' : ($lcp_refresh_pending ? 'planned' : 'disabled'), $lcp_precompleted || $lcp_refresh_pending, $lcp_precompleted ? __('LCP refresh stage was already completed.', 'ultracache') : ''),
            'varnish' => $this->get_warm_pipeline_stage($varnish_precompleted ? 'completed' : ($include_varnish ? 'planned' : 'disabled'), $include_varnish || $varnish_precompleted, $varnish_precompleted ? __('Varnish stage was already completed.', 'ultracache') : ''),
            'litespeed' => $this->get_warm_pipeline_stage($litespeed_precompleted ? 'completed' : ($include_litespeed ? 'planned' : 'disabled'), $include_litespeed || $litespeed_precompleted, $litespeed_precompleted ? __('LiteSpeed stage was already completed.', 'ultracache') : ''),
        );

        if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'preflight-before')) {
            return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline', $stages);
        }
        $preflight = $this->get_warm_pipeline_preflight($url);
        $preflight['executionProfile'] = $execution_profile;
        if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'preflight-after')) {
            return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline', $stages, $preflight);
        }
        if (empty($preflight['eligible'])) {
            $message = (string) ($preflight['message'] ?? __('URL is not eligible for HTML warm-up.', 'ultracache'));
            foreach ($stages as $stage_key => $stage) {
                if (!empty($stage['required'])) {
                    $stages[$stage_key] = $this->get_warm_pipeline_stage('skipped', true, $message, array(
                        'reason' => (string) ($preflight['reason'] ?? 'ineligible'),
                        'retryable' => false,
                        'failureClass' => 'ineligible',
                    ));
                }
            }

            $result = array(
                'success' => false,
                'cached' => false,
                'skipped' => true,
                'retryable' => false,
                'terminal' => true,
                'url' => $url,
                'message' => $message,
                'files' => array(),
                'pipeline' => $this->build_warm_pipeline_summary($url, $stages, 'skipped', $message, $preflight),
            );
            if (method_exists($this, 'record_analytics_warm')) {
                $this->record_analytics_warm($url, $result);
            }
            return $result;
        }

        $warm_args = $args;
        unset($warm_args['include_varnish'], $warm_args['include_litespeed'], $warm_args['warm_context'], $warm_args['requires_verified_origin'], $warm_args['required_stages'], $warm_args['completed_stages']);
        $warm_args['_warm_pipeline_heartbeat'] = $heartbeat;

        $reuse_frontend_html = false;
        $frontend_cache = array();
        if ($html_precompleted && !$css_stage_pending && !$lcp_refresh_pending && method_exists($this, 'get_frontend_visit_cache_satisfaction')) {
            $frontend_cache = $this->get_frontend_visit_cache_satisfaction($url);
            $reuse_frontend_html = !empty($frontend_cache['htmlComplete']);
        }

        if ($reuse_frontend_html) {
            $result = array(
                'success' => true,
                'cached' => true,
                'skipped' => false,
                'retryable' => false,
                'terminal' => false,
                'url' => $url,
                'message' => __('HTML cache already exists from a frontend visit; continuing with the remaining pipeline stages.', 'ultracache'),
                'files' => array_values((array) ($frontend_cache['files'] ?? array())),
                'buckets' => array_values((array) ($frontend_cache['cachedBuckets'] ?? array())),
                'visitSatisfied' => true,
            );
        } else {
            $warm_result = $this->warm_url($url, $warm_args);
            $result = is_array($warm_result) ? $warm_result : array();
            if (!empty($result['ownershipLost'])) {
                return $this->get_warm_pipeline_ownership_lost_result($url, 'html', $stages, $preflight);
            }
            if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'html-after')) {
                return $this->get_warm_pipeline_ownership_lost_result($url, 'html', $stages, $preflight);
            }
        }

        $html_status = !empty($result['success']) ? 'completed' : (!empty($result['skipped']) ? 'skipped' : 'failed');
        $html_retryable = 'failed' === $html_status && !empty($result['retryable']);
        $stages['html'] = $this->get_warm_pipeline_stage(
            $html_status,
            true,
            (string) ($result['message'] ?? ''),
            array(
                'cached' => !empty($result['cached']),
                'fileCount' => isset($result['files']) && is_array($result['files']) ? count($result['files']) : 0,
                'bucketCount' => isset($result['buckets']) && is_array($result['buckets']) ? count($result['buckets']) : 0,
                'cachedBuckets' => array_values(array_map('strval', (array) ($result['cachedBuckets'] ?? array()))),
                'failedBuckets' => array_values(array_map('strval', (array) ($result['failedBuckets'] ?? array()))),
                'bucketErrors' => is_array($result['bucketErrors'] ?? null) ? $result['bucketErrors'] : array(),
                'responseCookieNames' => array_values(array_map('strval', (array) ($result['responseCookieNames'] ?? array()))),
                'responseCookiePolicies' => array_values(array_map('strval', (array) ($result['responseCookiePolicies'] ?? array()))),
                'retryable' => $html_retryable,
                'failureClass' => sanitize_key((string) ($result['failureClass'] ?? '')),
            )
        );

        $css_retryable = false;
        if ($build_css_bundle && !$css_precompleted) {
            $css_result = isset($result['cssBundle']) && is_array($result['cssBundle']) ? $result['cssBundle'] : array();
            $css_status = !empty($css_result['success']) ? 'completed' : (!empty($css_result['skipped']) ? 'skipped' : 'failed');
            $css_retryable = 'failed' === $css_status && !empty($css_result['retryable']);
            $stages['css'] = $this->get_warm_pipeline_stage(
                $css_status,
                true,
                (string) ($css_result['message'] ?? __('CSS bundle result was not returned.', 'ultracache')),
                array(
                    'bundleCount' => absint($css_result['bundleCount'] ?? 0),
                    'bundleFile' => sanitize_text_field((string) ($css_result['bundleFile'] ?? '')),
                    'retryable' => $css_retryable,
                    'failureClass' => sanitize_key((string) ($css_result['failureClass'] ?? '')),
                )
            );
        }

        $html_completed = 'completed' === $html_status;
        $css_completed = !$build_css_bundle || in_array((string) $stages['css']['status'], array('completed', 'skipped'), true);
        $lcp_required = $lcp_precompleted || $lcp_refresh_pending;
        $lcp_completed = !$lcp_required || $lcp_precompleted;
        if ($lcp_refresh_pending) {
            if ($html_completed) {
                $lcp_completed = true;
                $stages['lcp'] = $this->get_warm_pipeline_stage(
                    'completed',
                    true,
                    __('The page-specific LCP refresh completed with the HTML regeneration.', 'ultracache')
                );
            } elseif ('skipped' === $html_status) {
                $lcp_completed = true;
                $stages['lcp'] = $this->get_warm_pipeline_stage(
                    'skipped',
                    true,
                    __('The LCP refresh was skipped with the ineligible HTML page.', 'ultracache'),
                    array('reason' => 'dependency')
                );
            } else {
                $stages['lcp'] = $this->get_warm_pipeline_stage(
                    'skipped',
                    false,
                    __('The LCP refresh is waiting for the HTML stage to complete.', 'ultracache'),
                    array('reason' => 'dependency')
                );
            }
        }
        $varnish_required = $varnish_precompleted;
        $varnish_completed = true;
        $varnish_retryable = false;

        if ($include_varnish && !$varnish_precompleted) {
            if (!$html_completed || !$css_completed) {
                $blocked_message = __('Varnish warm-up skipped because an earlier page stage did not complete.', 'ultracache');
                $stages['varnish'] = $this->get_warm_pipeline_stage('skipped', false, $blocked_message, array('reason' => 'dependency'));
            } elseif (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'refill_varnish_after_site_warm')) {
                $varnish_required = true;
                $varnish_completed = $external_refills_best_effort;
                $unavailable_message = __('Varnish warm-up integration is unavailable.', 'ultracache');
                $stages['varnish'] = $this->get_warm_pipeline_stage(
                    $external_refills_best_effort ? 'warning' : 'failed',
                    true,
                    $unavailable_message,
                    array(
                        'retryable' => false,
                        'failureClass' => 'integration-unavailable',
                    )
                );
            } else {
                if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'varnish-before')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'varnish', $stages, $preflight);
                }
                $varnish_result = Ultra_Cache_WP::refill_varnish_after_site_warm(
                    $url,
                    $result,
                    $warm_context,
                    $requires_verified_origin,
                    $heartbeat
                );
                $result['varnishRefill'] = is_array($varnish_result) ? $varnish_result : array();
                unset($result['forceRefreshDetails']);
                if (!empty($varnish_result['ownershipLost']) || !$this->invoke_warm_pipeline_heartbeat($heartbeat, 'varnish-after')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'varnish', $stages, $preflight);
                }

                $varnish_skipped = !empty($varnish_result['skipped']);
                $varnish_required = !$varnish_skipped;
                $varnish_stage_retryable = !$varnish_skipped && empty($varnish_result['success']) && !empty($varnish_result['retryable']);
                $varnish_status = $varnish_skipped
                    ? 'disabled'
                    : (!empty($varnish_result['success'])
                        ? 'completed'
                        : ($external_refills_best_effort ? 'warning' : 'failed'));
                $varnish_retryable = 'failed' === $varnish_status && $varnish_stage_retryable;
                $varnish_completed = !$varnish_required || in_array($varnish_status, array('completed', 'warning'), true);
                $stages['varnish'] = $this->get_warm_pipeline_stage(
                    $varnish_status,
                    $varnish_required,
                    (string) ($varnish_result['message'] ?? ''),
                    array(
                        'variantCount' => absint($varnish_result['variantCount'] ?? 0),
                        'refilledCount' => absint($varnish_result['refilledCount'] ?? 0),
                        'invalidationCompleted' => !empty($varnish_result['invalidationCompleted']),
                        'originRevalidationRequired' => !empty($varnish_result['originRevalidationRequired']),
                        'fallbackBlocked' => !empty($varnish_result['fallbackBlocked']),
                        'esiParentVariantCount' => absint($varnish_result['esiParentVariantCount'] ?? 0),
                        'esiFragmentReferenceCount' => absint($varnish_result['esiFragmentReferenceCount'] ?? 0),
                        'esiUniqueFragmentReferenceCount' => absint($varnish_result['esiUniqueFragmentReferenceCount'] ?? 0),
                        'esiMinTtl' => absint($varnish_result['esiMinTtl'] ?? 0),
                        'esiMaxTtl' => absint($varnish_result['esiMaxTtl'] ?? 0),
                        'retryable' => $varnish_stage_retryable,
                        'failureClass' => sanitize_key((string) ($varnish_result['failureClass'] ?? '')),
                        'refillDetails' => is_array($varnish_result['details'] ?? null)
                            ? array_slice($varnish_result['details'], 0, 3)
                            : array(),
                    )
                );

            }
        }

        $litespeed_required = $litespeed_precompleted;
        $litespeed_completed = true;
        $litespeed_retryable = false;

        if ($include_litespeed && !$litespeed_precompleted) {
            if (!$html_completed || !$css_completed) {
                $blocked_message = __('LiteSpeed warm-up skipped because an earlier page stage did not complete.', 'ultracache');
                $stages['litespeed'] = $this->get_warm_pipeline_stage('skipped', false, $blocked_message, array('reason' => 'dependency'));
            } elseif (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'refill_litespeed_after_site_warm')) {
                $litespeed_required = true;
                $litespeed_completed = $external_refills_best_effort;
                $unavailable_message = __('LiteSpeed warm-up integration is unavailable.', 'ultracache');
                $stages['litespeed'] = $this->get_warm_pipeline_stage(
                    $external_refills_best_effort ? 'warning' : 'failed',
                    true,
                    $unavailable_message,
                    array(
                        'retryable' => false,
                        'failureClass' => 'integration-unavailable',
                    )
                );
            } else {
                if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'litespeed-before')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'litespeed', $stages, $preflight);
                }
                $litespeed_result = Ultra_Cache_WP::refill_litespeed_after_site_warm(
                    $url,
                    $result,
                    $warm_context,
                    false,
                    $heartbeat
                );
                $result['liteSpeedRefill'] = is_array($litespeed_result) ? $litespeed_result : array();
                unset($result['forceRefreshDetails']);
                if (!empty($litespeed_result['ownershipLost']) || !$this->invoke_warm_pipeline_heartbeat($heartbeat, 'litespeed-after')) {
                    return $this->get_warm_pipeline_ownership_lost_result($url, 'litespeed', $stages, $preflight);
                }

                $litespeed_skipped = !empty($litespeed_result['skipped']);
                $litespeed_required = !$litespeed_skipped;
                $litespeed_stage_retryable = !$litespeed_skipped && empty($litespeed_result['success']) && !empty($litespeed_result['retryable']);
                $litespeed_status = $litespeed_skipped
                    ? 'disabled'
                    : (!empty($litespeed_result['success'])
                        ? (!empty($litespeed_result['warning']) ? 'warning' : 'completed')
                        : ($external_refills_best_effort ? 'warning' : 'failed'));
                $litespeed_retryable = 'failed' === $litespeed_status && $litespeed_stage_retryable;
                $litespeed_completed = !$litespeed_required || in_array($litespeed_status, array('completed', 'warning'), true);
                $stages['litespeed'] = $this->get_warm_pipeline_stage(
                    $litespeed_status,
                    $litespeed_required,
                    (string) ($litespeed_result['message'] ?? ''),
                    array(
                        'variantCount' => absint($litespeed_result['variantCount'] ?? 0),
                        'refilledCount' => absint($litespeed_result['refilledCount'] ?? 0),
                        'verifiedCount' => absint($litespeed_result['verifiedCount'] ?? 0),
                        'verified' => !empty($litespeed_result['verified']),
                        'invalidationCompleted' => !empty($litespeed_result['invalidationCompleted']),
                        'retryable' => $litespeed_stage_retryable,
                        'failureClass' => sanitize_key((string) ($litespeed_result['failureClass'] ?? '')),
                        'refillDetails' => is_array($litespeed_result['details'] ?? null)
                            ? array_slice($litespeed_result['details'], 0, 3)
                            : array(),
                    )
                );
            }
        }

        $pipeline_success = $html_completed && $css_completed && $lcp_completed && $varnish_completed && $litespeed_completed;
        $pipeline_retryable = !$pipeline_success && ($html_retryable || $css_retryable || $varnish_retryable || $litespeed_retryable);
        $pipeline_has_completed_stage = false;
        $pipeline_has_failed_stage = false;
        $pipeline_has_warning_stage = false;
        foreach ($stages as $stage_result) {
            if (empty($stage_result['required'])) {
                continue;
            }
            $stage_status = sanitize_key((string) ($stage_result['status'] ?? ''));
            if ('completed' === $stage_status) {
                $pipeline_has_completed_stage = true;
            } elseif ('warning' === $stage_status) {
                $pipeline_has_completed_stage = true;
                $pipeline_has_warning_stage = true;
            } elseif ('failed' === $stage_status) {
                $pipeline_has_failed_stage = true;
            }
        }
        $pipeline_partial = !$pipeline_success && $pipeline_has_completed_stage && $pipeline_has_failed_stage;
        $pipeline_status = $pipeline_success
            ? ($pipeline_has_warning_stage ? 'completed_with_warnings' : 'completed')
            : (!empty($result['skipped']) ? 'skipped' : ($pipeline_partial ? 'partial' : 'failed'));
        $pipeline_message = $pipeline_success
            ? (($external_refills_best_effort && $pipeline_has_warning_stage)
                ? __('The page cache warmed successfully; one or more auxiliary refills reported a non-blocking warning.', 'ultracache')
                : __('All selected warm-up stages completed for this page.', 'ultracache'))
            : (string) ($result['message'] ?? __('One or more selected warm-up stages did not complete.', 'ultracache'));

        $result['success'] = $pipeline_success;
        $result['retryable'] = $pipeline_retryable;
        $result['terminal'] = !$pipeline_success && !$pipeline_retryable;
        $result['partial'] = $pipeline_partial;
        $result['warning'] = $pipeline_partial || ($pipeline_success && $pipeline_has_warning_stage);
        $result['auxiliaryWarnings'] = array();
        foreach ($stages as $stage_name => $stage_result) {
            if ('warning' !== sanitize_key((string) ($stage_result['status'] ?? ''))) {
                continue;
            }
            $stage_details = is_array($stage_result['details'] ?? null) ? $stage_result['details'] : array();
            $result['auxiliaryWarnings'][] = array(
                'stage' => sanitize_key((string) $stage_name),
                'message' => sanitize_text_field((string) ($stage_result['message'] ?? '')),
                'failureClass' => sanitize_key((string) ($stage_details['failureClass'] ?? '')),
                'retryable' => !empty($stage_details['retryable']),
            );
        }
        if (!$pipeline_success && 'completed' === $html_status) {
            if ($varnish_required && !$varnish_completed && $litespeed_required && !$litespeed_completed) {
                $result['message'] = __('The page cache warmed, but the selected Varnish and LiteSpeed refills did not complete.', 'ultracache');
            } elseif ($varnish_required && !$varnish_completed) {
                $result['message'] = __('The page cache warmed, but the selected Varnish refill did not complete.', 'ultracache');
            } elseif ($litespeed_required && !$litespeed_completed) {
                $result['message'] = __('The page cache warmed, but the selected LiteSpeed refill did not complete.', 'ultracache');
            }
        }
        if (!$pipeline_success) {
            foreach ($stages as $stage_name => $stage_result) {
                if (empty($stage_result['required']) || 'failed' !== sanitize_key((string) ($stage_result['status'] ?? ''))) {
                    continue;
                }

                $stage_details = is_array($stage_result['details'] ?? null) ? $stage_result['details'] : array();
                $stage_failure_class = sanitize_key((string) ($stage_details['failureClass'] ?? ''));
                $result['failedStage'] = sanitize_key((string) $stage_name);
                $result['failureClass'] = '' !== $stage_failure_class ? $stage_failure_class : sanitize_key((string) $stage_name . '-failed');
                $result['failureMessage'] = sanitize_text_field((string) ($stage_result['message'] ?? ''));
                $result['failureDetails'] = $stage_details;
                break;
            }
        } else {
            $result['failureClass'] = '';
            unset($result['failedStage'], $result['failureMessage'], $result['failureDetails']);
        }

        $result['pipeline'] = $this->build_warm_pipeline_summary($url, $stages, $pipeline_status, $pipeline_message, $preflight);

        return $result;
    }

    /**
     * Acquire the shared per-URL warm lock.
     *
     * @param string $url     Local page URL.
     * @param string $context Warm source.
     * @return array
     */
    private function acquire_warm_pipeline_url_lock($url, $context)
    {
        $url = esc_url_raw((string) $url);
        $context = sanitize_key((string) $context);
        $token = wp_generate_password(20, false, false);
        $lock_name = 'ultracache_warm_page_' . sha1($url);
        if ('' === $url || !function_exists('ultracache_acquire_lock')) {
            return array('acquired' => true, 'name' => '', 'token' => '');
        }

        $payload = array(
            'urlHash' => sha1($url),
            'context' => $context,
            'startedAt' => time(),
        );
        $ttl = $this->get_warm_pipeline_lock_ttl();
        $acquired = ultracache_acquire_lock($lock_name, $token, $ttl, $payload);
        return array(
            'acquired' => (bool) $acquired,
            'name' => $lock_name,
            'token' => $token,
            'ttl' => $ttl,
            'payload' => $payload,
        );
    }

    /**
     * Return the bounded lifetime for one URL-scoped warm lock.
     *
     * @return int
     */
    private function get_warm_pipeline_lock_ttl()
    {
        $ttl = max(60, (int) apply_filters('ultracache_warm_pipeline_url_lock_ttl', 180));
        $max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
            ? ultracache_get_php_max_execution_time_seconds()
            : max(0, (int) ini_get('max_execution_time'));
        return $max_execution > 0 ? max($ttl, $max_execution) : $ttl;
    }

    /**
     * Renew one URL-scoped warm lock while this token still owns it.
     *
     * @param array  $lock  Lock descriptor.
     * @param string $stage Current pipeline stage.
     * @return bool
     */
    private function renew_warm_pipeline_url_lock(array &$lock, $stage = '')
    {
        if (empty($lock['name']) || empty($lock['token'])) {
            return true;
        }
        if (!function_exists('ultracache_renew_lock')) {
            return false;
        }

        $payload = isset($lock['payload']) && is_array($lock['payload']) ? $lock['payload'] : array();
        $now = time();
        $ttl = isset($lock['ttl']) ? max(60, (int) $lock['ttl']) : $this->get_warm_pipeline_lock_ttl();
        $renew_interval = max(15, min(60, (int) floor($ttl / 3)));
        $last_renewed_at = max(0, (int) ($payload['renewedAt'] ?? ($payload['startedAt'] ?? 0)));
        if ($last_renewed_at > 0 && ($now - $last_renewed_at) < $renew_interval) {
            return true;
        }

        $payload['stage'] = sanitize_key((string) $stage);
        $payload['renewedAt'] = $now;
        $renewed = ultracache_renew_lock((string) $lock['name'], (string) $lock['token'], $ttl, $payload);
        if ($renewed) {
            $lock['payload'] = $payload;
        }
        return (bool) $renewed;
    }

    /**
     * Run one internal ownership heartbeat without exposing callback failures.
     *
     * @param callable|null $heartbeat Internal ownership callback.
     * @param string        $stage     Current pipeline stage.
     * @return bool
     */
    private function invoke_warm_pipeline_heartbeat($heartbeat, $stage)
    {
        if (!is_callable($heartbeat)) {
            return true;
        }

        try {
            return false !== call_user_func($heartbeat, sanitize_key((string) $stage));
        } catch (Throwable $error) {
            unset($error);
            return false;
        }
    }

    /**
     * Return a retryable result when URL-lock or queue-lease ownership was lost.
     *
     * @param string $url       Local page URL.
     * @param string $stage     Current stage.
     * @param array  $stages    Existing stage results.
     * @param array  $preflight Existing preflight result.
     * @return array
     */
    private function get_warm_pipeline_ownership_lost_result($url, $stage = 'pipeline', array $stages = array(), array $preflight = array())
    {
        $url = esc_url_raw((string) $url);
        $stage = sanitize_key((string) $stage);
        $message = __('Warm-up ownership expired or was transferred before the current stage completed.', 'ultracache');
        if (empty($stages)) {
            return array(
                'success' => false,
                'skipped' => false,
                'retryable' => true,
                'terminal' => false,
                'ownershipLost' => true,
                'failureClass' => 'ownership-lost',
                'url' => $url,
                'stage' => $stage,
                'stageStatus' => 'failed',
                'message' => $message,
            );
        }

        $failed_stage = isset($stages[$stage]) ? $stage : '';
        if ('' === $failed_stage) {
            foreach ($stages as $stage_key => $stage_result) {
                if ('planned' === sanitize_key((string) ($stage_result['status'] ?? '')) && !empty($stage_result['required'])) {
                    $failed_stage = (string) $stage_key;
                    break;
                }
            }
        }
        if ('' !== $failed_stage && isset($stages[$failed_stage])) {
            $stages[$failed_stage] = $this->get_warm_pipeline_stage('failed', true, $message, array(
                'retryable' => true,
                'failureClass' => 'ownership-lost',
            ));
        }
        return array(
            'success' => false,
            'cached' => false,
            'skipped' => false,
            'retryable' => true,
            'terminal' => false,
            'ownershipLost' => true,
            'failureClass' => 'ownership-lost',
            'url' => $url,
            'message' => $message,
            'files' => array(),
            'pipeline' => $this->build_warm_pipeline_summary($url, $stages, 'failed', $message, $preflight),
        );
    }

    /**
     * Release one per-URL warm lock.
     *
     * @param array $lock Lock descriptor.
     * @return void
     */
    private function release_warm_pipeline_url_lock(array $lock)
    {
        if (
            !empty($lock['name'])
            && !empty($lock['token'])
            && function_exists('ultracache_release_lock')
        ) {
            ultracache_release_lock((string) $lock['name'], (string) $lock['token']);
        }
    }

    /**
     * Return a retryable coalesced result when another source already owns the URL.
     *
     * @param string $url   Local page URL.
     * @param string $stage Requested stage.
     * @return array
     */
    private function get_coalesced_warm_pipeline_result($url, $stage)
    {
        $url = esc_url_raw((string) $url);
        $stage = sanitize_key((string) $stage);
        return array(
            'success' => false,
            'skipped' => false,
            'retryable' => true,
            'terminal' => false,
            'deferred' => true,
            'coalesced' => true,
            'failureClass' => 'url-lock-busy',
            'url' => $url,
            'stage' => $stage,
            'stageStatus' => 'coalesced',
            'message' => __('Another warm-up source is already processing this URL; the requested pipeline stages remain pending.', 'ultracache'),
        );
    }

    /**
     * Resolve the execution profile without changing the common page pipeline.
     *
     * The profile controls only bounded per-page work budgets. URL discovery,
     * stage ordering, locking, eligibility, and completion remain identical.
     *
     * @param string $context Warm source context.
     * @return array{key:string,pageTimeBudget:int,hardCap:int}
     */
    private function get_warm_pipeline_execution_profile($context)
    {
        $context = sanitize_key((string) $context);
        $max_execution = function_exists('ultracache_get_php_max_execution_time_seconds')
            ? ultracache_get_php_max_execution_time_seconds()
            : max(0, (int) ini_get('max_execution_time'));
        if ('cli' === $context) {
            return array('key' => 'cli', 'pageTimeBudget' => $max_execution, 'hardCap' => $max_execution);
        }
        if (in_array($context, array('manual', 'ui', 'dashboard', 'diagnostic', 'runtime_scan'), true)) {
            return array('key' => 'ui', 'pageTimeBudget' => $max_execution, 'hardCap' => $max_execution);
        }
        if (in_array($context, array('visit', 'frontend', 'revalidate'), true)) {
            return array('key' => 'visit', 'pageTimeBudget' => $max_execution, 'hardCap' => $max_execution);
        }
        return array('key' => 'cron', 'pageTimeBudget' => $max_execution, 'hardCap' => $max_execution);
    }

    /**
     * Expose the shared preflight decision to queue producers.
     *
     * @param string $url Local public URL.
     * @return array
     */
    public function get_warm_pipeline_eligibility($url)
    {
        return $this->get_warm_pipeline_preflight($url);
    }

    /**
     * Reject known non-HTML endpoints before any warm request is made.
     *
     * @param string $url Local public URL.
     * @return array
     */
    private function get_warm_pipeline_preflight($url)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url || !$this->is_cacheable_local_url($url)) {
            return array(
                'eligible' => false,
                'reason' => 'non-local-url',
                'message' => __('Only local site URLs can be warmed.', 'ultracache'),
            );
        }

        if (method_exists($this, 'is_feed_url') && $this->is_feed_url($url)) {
            return array(
                'eligible' => false,
                'reason' => 'feed',
                'message' => __('Feed URLs are not HTML warm-up targets.', 'ultracache'),
            );
        }

        $parts = wp_parse_url($url);
        $path = isset($parts['path']) ? strtolower((string) $parts['path']) : '/';
        $query = isset($parts['query']) ? (string) $parts['query'] : '';
        $normalized_path = '/' . ltrim($path, '/');

        if (
            '/wp-json' === untrailingslashit($normalized_path)
            || 0 === strpos($normalized_path, '/wp-json/')
            || '/xmlrpc.php' === $normalized_path
            || preg_match('#(?:^|/)(?:wp-sitemap[^/]*|sitemap[^/]*)\.xml(?:\.gz)?$#', $normalized_path)
            || preg_match('#\.(?:xml|json|rss|atom)(?:\.gz)?$#', $normalized_path)
        ) {
            return array(
                'eligible' => false,
                'reason' => 'non-html-endpoint',
                'message' => __('This endpoint is not an HTML warm-up target.', 'ultracache'),
            );
        }

        if ('' !== $query) {
            wp_parse_str($query, $query_vars);
            if (isset($query_vars['rest_route']) || isset($query_vars['feed'])) {
                return array(
                    'eligible' => false,
                    'reason' => isset($query_vars['feed']) ? 'feed' : 'rest',
                    'message' => __('This endpoint is not an HTML warm-up target.', 'ultracache'),
                );
            }
        }

        // Reuse the authoritative anonymous page-cache decision instead of
        // maintaining a second warm-only list of WooCommerce/private/excluded
        // paths. This keeps refresh-ahead, targeted warm, and direct cache
        // storage aligned through one WordPress-aware cacheability contract.
        if (method_exists($this, 'inspect_url')) {
            $inspection = $this->inspect_url($url);
            if (is_array($inspection) && !empty($inspection['success']) && empty($inspection['cacheable'])) {
                $decision_reason = sanitize_key((string) ($inspection['reason'] ?? 'not-cacheable'));
                $reason_label = trim((string) ($inspection['reasonLabel'] ?? ''));
                return array(
                    'eligible' => false,
                    'reason' => '' !== $decision_reason ? $decision_reason : 'not-cacheable',
                    'message' => '' !== $reason_label
                        ? $reason_label
                        : __('The page cache policy excludes this URL.', 'ultracache'),
                );
            }
        }

        return array(
            'eligible' => true,
            'reason' => '',
            'message' => '',
        );
    }

    /**
     * Create one normalized pipeline stage result.
     *
     * @param string $status  Stage status.
     * @param bool   $required Whether this stage is required.
     * @param string $message Stage message.
     * @param array  $details Additional bounded details.
     * @return array
     */
    private function get_warm_pipeline_stage($status, $required, $message = '', array $details = array())
    {
        $status = sanitize_key((string) $status);
        if (!in_array($status, array('planned', 'completed', 'warning', 'skipped', 'failed', 'disabled'), true)) {
            $status = 'failed';
        }

        return array(
            'status' => $status,
            'required' => (bool) $required,
            'message' => sanitize_text_field((string) $message),
            'details' => $details,
        );
    }

    /**
     * Whether any failed stage explicitly identified a transient condition.
     *
     * @param array $stages Pipeline stage results.
     * @return bool
     */
    private function warm_pipeline_stages_have_retryable_failure(array $stages)
    {
        foreach ($stages as $stage) {
            if (
                'failed' === sanitize_key((string) ($stage['status'] ?? ''))
                && !empty($stage['details']['retryable'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the common per-page result contract.
     *
     * @param string $url       Local public URL.
     * @param array  $stages    Stage results.
     * @param string $status    Overall status.
     * @param string $message   Overall message.
     * @param array  $preflight Preflight result.
     * @return array
     */
    private function build_warm_pipeline_summary($url, array $stages, $status, $message, array $preflight)
    {
        $counts = array(
            'completed' => 0,
            'warning' => 0,
            'skipped' => 0,
            'failed' => 0,
            'disabled' => 0,
            'planned' => 0,
        );
        foreach ($stages as $stage) {
            $stage_status = sanitize_key((string) ($stage['status'] ?? 'failed'));
            if (isset($counts[$stage_status])) {
                ++$counts[$stage_status];
            }
        }

        return array(
            'version' => 2,
            'url' => esc_url_raw((string) $url),
            'status' => sanitize_key((string) $status),
            'success' => in_array(sanitize_key((string) $status), array('completed', 'completed_with_warnings'), true),
            'hasWarnings' => $counts['warning'] > 0,
            'retryable' => $counts['failed'] > 0 && $this->warm_pipeline_stages_have_retryable_failure($stages),
            'message' => sanitize_text_field((string) $message),
            'executionProfile' => sanitize_key((string) ($preflight['executionProfile'] ?? '')),
            'preflight' => array(
                'eligible' => !empty($preflight['eligible']),
                'reason' => sanitize_key((string) ($preflight['reason'] ?? '')),
            ),
            'counts' => $counts,
            'stages' => $stages,
        );
    }
}
