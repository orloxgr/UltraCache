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
     * Execute one manual warm stage while preventing another source from
     * processing the same URL at the same time.
     *
     * @param string $url   Local public URL.
     * @param string $stage Stage name.
     * @param array  $args  Stage arguments.
     * @return array
     */
    public function warm_page_pipeline_stage($url, $stage, array $args = array())
    {
        $lock = $this->acquire_warm_pipeline_url_lock($url, 'manual-stage-' . sanitize_key((string) $stage));
        if (empty($lock['acquired'])) {
            return $this->get_coalesced_warm_pipeline_result($url, sanitize_key((string) $stage));
        }

        $heartbeat = function ($heartbeat_stage = '') use (&$lock) {
            return $this->renew_warm_pipeline_url_lock($lock, $heartbeat_stage);
        };

        try {
            if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'manual-stage-start')) {
                return $this->get_warm_pipeline_ownership_lost_result($url, sanitize_key((string) $stage));
            }
            $args['_warm_pipeline_heartbeat'] = $heartbeat;
            return $this->run_warm_page_pipeline_stage($url, $stage, $args);
        } finally {
            $this->release_warm_pipeline_url_lock($lock);
        }
    }

    /**
     * Execute one resumable manual warm stage for a local page.
     *
     * @param string $url   Local public URL.
     * @param string $stage Stage name: html or css.
     * @param array  $args  Stage arguments.
     * @return array
     */
    private function run_warm_page_pipeline_stage($url, $stage, array $args = array())
    {
        $url = esc_url_raw((string) $url);
        $stage = sanitize_key((string) $stage);
        $args = is_array($args) ? $args : array();
        $heartbeat = isset($args['_warm_pipeline_heartbeat']) && is_callable($args['_warm_pipeline_heartbeat'])
            ? $args['_warm_pipeline_heartbeat']
            : null;
        unset($args['_warm_pipeline_heartbeat']);
        if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'manual-preflight')) {
            return $this->get_warm_pipeline_ownership_lost_result($url, $stage);
        }
        $preflight = $this->get_warm_pipeline_preflight($url);

        if (empty($preflight['eligible'])) {
            return array(
                'success' => false,
                'skipped' => true,
                'stage' => $stage,
                'stageStatus' => 'skipped',
                'url' => $url,
                'message' => (string) ($preflight['message'] ?? __('URL is not eligible for HTML warm-up.', 'ultracache')),
                'reason' => (string) ($preflight['reason'] ?? 'ineligible'),
            );
        }

        if ('html' === $stage) {
            $warm_args = array(
                'force_refresh' => !empty($args['force_refresh']),
                'ignore_runtime_bypass' => !empty($args['ignore_runtime_bypass']),
                'skip_css_bundle' => true,
                '_warm_pipeline_heartbeat' => $heartbeat,
            );
            $warm_result = $this->warm_url($url, $warm_args);
            $success = !empty($warm_result['success']);
            $skipped = !empty($warm_result['skipped']);

            return array(
                'success' => $success,
                'skipped' => $skipped,
                'stage' => 'html',
                'stageStatus' => $success ? 'completed' : ($skipped ? 'skipped' : 'failed'),
                'url' => $url,
                'message' => (string) ($warm_result['message'] ?? ''),
                'cached' => !empty($warm_result['cached']),
                'files' => isset($warm_result['files']) && is_array($warm_result['files']) ? array_values($warm_result['files']) : array(),
                'buckets' => isset($warm_result['buckets']) && is_array($warm_result['buckets']) ? array_values($warm_result['buckets']) : array('orig'),
                'forceRefreshRequested' => !empty($warm_result['forceRefreshRequested']),
                'forceRefreshReachedOrigin' => !empty($warm_result['forceRefreshReachedOrigin']),
                'forceRefreshReachedBucketCount' => absint($warm_result['forceRefreshReachedBucketCount'] ?? 0),
                'forceRefreshExpectedBucketCount' => absint($warm_result['forceRefreshExpectedBucketCount'] ?? 0),
                'warmResult' => $warm_result,
            );
        }

        if ('css' === $stage) {
            if (empty($args['build_css_bundle'])) {
                return array(
                    'success' => true,
                    'skipped' => true,
                    'stage' => 'css',
                    'stageStatus' => 'disabled',
                    'url' => $url,
                    'message' => __('CSS bundle warm-up is not selected for this job.', 'ultracache'),
                );
            }

            if (!method_exists($this, 'build_frontpage_css_bundle')) {
                return array(
                    'success' => false,
                    'skipped' => false,
                    'stage' => 'css',
                    'stageStatus' => 'failed',
                    'url' => $url,
                    'message' => __('CSS bundle integration is unavailable.', 'ultracache'),
                );
            }

            if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'manual-css-before')) {
                return $this->get_warm_pipeline_ownership_lost_result($url, 'css');
            }
            $css_result = $this->build_frontpage_css_bundle($url, array(
                'skip_final_warm' => true,
                'ignore_runtime_bypass' => !empty($args['ignore_runtime_bypass']),
            ));
            if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'manual-css-after')) {
                return $this->get_warm_pipeline_ownership_lost_result($url, 'css');
            }
            $success = !empty($css_result['success']);
            $skipped = !empty($css_result['skipped']);

            return array(
                'success' => $success,
                'skipped' => $skipped,
                'stage' => 'css',
                'stageStatus' => $success ? 'completed' : ($skipped ? 'skipped' : 'failed'),
                'url' => $url,
                'message' => (string) ($css_result['message'] ?? ''),
                'bundleCount' => absint($css_result['bundleCount'] ?? 0),
                'bundleFile' => sanitize_text_field((string) ($css_result['bundleFile'] ?? '')),
                'cssResult' => $css_result,
            );
        }

        return array(
            'success' => false,
            'skipped' => false,
            'stage' => $stage,
            'stageStatus' => 'failed',
            'url' => $url,
            'message' => __('Unknown manual warm-up stage.', 'ultracache'),
        );
    }
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
        $queue_heartbeat = isset($args['_queue_lease_heartbeat']) && is_callable($args['_queue_lease_heartbeat'])
            ? $args['_queue_lease_heartbeat']
            : null;
        unset($args['_queue_lease_heartbeat']);

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
     * contract so selected HTML, CSS, and Varnish work completes
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
        $warm_context = sanitize_key((string) ($args['warm_context'] ?? 'manual'));
        $requires_verified_origin = !empty($args['requires_verified_origin']);
        $stages = array(
            'html' => $this->get_warm_pipeline_stage('planned', true),
            'css' => $this->get_warm_pipeline_stage($build_css_bundle ? 'planned' : 'disabled', $build_css_bundle),
            'varnish' => $this->get_warm_pipeline_stage($include_varnish ? 'planned' : 'disabled', $include_varnish),
        );

        if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'preflight-before')) {
            return $this->get_warm_pipeline_ownership_lost_result($url, 'pipeline', $stages);
        }
        $preflight = $this->get_warm_pipeline_preflight($url);
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
        unset($warm_args['include_varnish'], $warm_args['warm_context'], $warm_args['requires_verified_origin']);
        $warm_args['_warm_pipeline_heartbeat'] = $heartbeat;
        $warm_result = $this->warm_url($url, $warm_args);
        $result = is_array($warm_result) ? $warm_result : array();
        if (!empty($result['ownershipLost'])) {
            return $this->get_warm_pipeline_ownership_lost_result($url, 'html', $stages, $preflight);
        }
        if (!$this->invoke_warm_pipeline_heartbeat($heartbeat, 'html-after')) {
            return $this->get_warm_pipeline_ownership_lost_result($url, 'html', $stages, $preflight);
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
                'retryable' => $html_retryable,
                'failureClass' => sanitize_key((string) ($result['failureClass'] ?? '')),
            )
        );

        $css_retryable = false;
        if ($build_css_bundle) {
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
        $varnish_required = false;
        $varnish_completed = true;
        $varnish_retryable = false;

        if ($include_varnish) {
            if (!$html_completed || !$css_completed) {
                $blocked_message = __('Varnish warm-up skipped because an earlier page stage did not complete.', 'ultracache');
                $stages['varnish'] = $this->get_warm_pipeline_stage('skipped', false, $blocked_message, array('reason' => 'dependency'));
            } elseif (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'refill_varnish_after_site_warm')) {
                $varnish_required = true;
                $varnish_completed = false;
                $unavailable_message = __('Varnish warm-up integration is unavailable.', 'ultracache');
                $stages['varnish'] = $this->get_warm_pipeline_stage('failed', true, $unavailable_message, array(
                    'retryable' => false,
                    'failureClass' => 'integration-unavailable',
                ));
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
                $varnish_status = $varnish_skipped ? 'disabled' : (!empty($varnish_result['success']) ? 'completed' : 'failed');
                $varnish_retryable = 'failed' === $varnish_status && !empty($varnish_result['retryable']);
                $varnish_completed = !$varnish_required || 'completed' === $varnish_status;
                $stages['varnish'] = $this->get_warm_pipeline_stage(
                    $varnish_status,
                    $varnish_required,
                    (string) ($varnish_result['message'] ?? ''),
                    array(
                        'variantCount' => absint($varnish_result['variantCount'] ?? 0),
                        'refilledCount' => absint($varnish_result['refilledCount'] ?? 0),
                        'originRevalidationRequired' => !empty($varnish_result['originRevalidationRequired']),
                        'fallbackBlocked' => !empty($varnish_result['fallbackBlocked']),
                        'retryable' => $varnish_retryable,
                        'failureClass' => sanitize_key((string) ($varnish_result['failureClass'] ?? '')),
                    )
                );

            }
        }

        $pipeline_success = $html_completed && $css_completed && $varnish_completed;
        $pipeline_retryable = !$pipeline_success && ($html_retryable || $css_retryable || $varnish_retryable);
        $pipeline_status = $pipeline_success
            ? 'completed'
            : (!empty($result['skipped']) ? 'skipped' : 'failed');
        $pipeline_message = $pipeline_success
            ? __('All selected warm-up stages completed for this page.', 'ultracache')
            : (string) ($result['message'] ?? __('One or more selected warm-up stages did not complete.', 'ultracache'));

        $result['success'] = $pipeline_success;
        $result['retryable'] = $pipeline_retryable;
        $result['terminal'] = !$pipeline_success && !$pipeline_retryable;
        $result['warning'] = false;
        if (!$pipeline_success && 'completed' === $html_status && $varnish_required && !$varnish_completed) {
            $result['message'] = __('The page cache warmed, but the selected Varnish refill did not complete.', 'ultracache');
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
        return max(60, min(900, (int) apply_filters('ultracache_warm_pipeline_url_lock_ttl', 180)));
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
        $ttl = isset($lock['ttl']) ? max(60, min(900, (int) $lock['ttl'])) : $this->get_warm_pipeline_lock_ttl();
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
     * Return a successful coalesced result when another source already owns the URL.
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
            'success' => true,
            'skipped' => true,
            'coalesced' => true,
            'url' => $url,
            'stage' => $stage,
            'stageStatus' => 'coalesced',
            'message' => __('Another warm-up source is already processing this URL; duplicate work was coalesced.', 'ultracache'),
        );
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
            'preflight' => array(
                'eligible' => !empty($preflight['eligible']),
                'reason' => sanitize_key((string) ($preflight['reason'] ?? '')),
            ),
            'counts' => $counts,
            'stages' => $stages,
        );
    }
}
