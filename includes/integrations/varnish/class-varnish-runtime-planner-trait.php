<?php
/**
 * Capability-driven Varnish runtime planning for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Runtime_Planner_Trait
{
    /**
     * Return the bounded URL limit used by the degraded site-flush fallback.
     *
     * @return int
     */
    private static function get_varnish_known_url_fallback_limit()
    {
        $limit = (int) apply_filters('ultracache_varnish_known_url_fallback_limit', 5000);
        return max(1, min(5000, $limit));
    }


    /**
     * Return whether the runtime can build a bounded list of known local URLs.
     *
     * @return bool
     */
    private static function can_build_varnish_known_url_fallback()
    {
        return class_exists('Ultra_Cache_Engine')
            && method_exists('Ultra_Cache_Engine', 'get_instance');
    }

    /**
     * Build the bounded local URL set used when no verified site-wide scope exists.
     *
     * This is intentionally a degraded fallback. It can invalidate every URL known
     * to the UltraCache crawl planner, but it is not equivalent to an entire-host
     * BAN because unknown, uncrawled, or non-WordPress objects may remain cached.
     *
     * @param int $limit Maximum URL count.
     * @return array<string,mixed>
     */
    private static function get_varnish_known_url_fallback_set($limit = 0)
    {
        $limit = $limit > 0 ? min(self::get_varnish_known_url_fallback_limit(), absint($limit)) : self::get_varnish_known_url_fallback_limit();
        $urls = array();
        $source = 'unavailable';
        $error = '';

        if (self::can_build_varnish_known_url_fallback()) {
            try {
                $engine = Ultra_Cache_Engine::get_instance();
                if ($engine && method_exists($engine, 'get_crawl_urls')) {
                    $source = 'full-crawl-plan';
                    $candidates = $engine->get_crawl_urls('full');
                    if (is_array($candidates)) {
                        foreach ($candidates as $candidate) {
                            $url = esc_url_raw((string) $candidate);
                            if ('' === $url) {
                                continue;
                            }
                            $urls[$url] = $url;
                            if (count($urls) >= $limit) {
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $error_object) {
                $error = self::sanitize_varnish_string($error_object->getMessage());
            }
        }

        $home = esc_url_raw(home_url('/'));
        if ('' !== $home && count($urls) < $limit) {
            $urls[$home] = $home;
            if ('unavailable' === $source) {
                $source = 'home-only';
            }
        }

        $prepared = self::prepare_varnish_invalidation_urls(array_values($urls));
        $canonical_urls = array();
        foreach ((array) ($prepared['urls'] ?? array()) as $item) {
            $url = esc_url_raw((string) ($item['url'] ?? ''));
            if ('' !== $url) {
                $canonical_urls[] = $url;
            }
        }

        return array(
            'urls' => array_slice(array_values(array_unique($canonical_urls)), 0, $limit),
            'count' => min($limit, count($canonical_urls)),
            'limit' => $limit,
            'bounded' => count($canonical_urls) >= $limit,
            'source' => sanitize_key($source),
            'error' => $error,
            'receivedCount' => absint($prepared['receivedCount'] ?? count($urls)),
            'rejectedCount' => absint($prepared['rejectedCount'] ?? 0),
        );
    }

    /**
     * Return the normalized runtime fallback outcome when no immediate operation
     * can be safely executed.
     *
     * @param array $dashboard_settings Dashboard settings.
     * @return array<string,mixed>
     */
    private static function get_varnish_ttl_runtime_fallback(array $dashboard_settings)
    {
        $shared_delivery = self::is_varnish_runtime_enabled($dashboard_settings);
        $automation = self::get_varnish_automation_policy($dashboard_settings);
        $ttl_minutes = max(1, min(1440, absint($automation['ttlOnlyMinutes'] ?? 10)));

        return array(
            'available' => $shared_delivery,
            'strategy' => $shared_delivery ? 'ttl-expiry' : 'none',
            'outcome' => $shared_delivery ? 'degraded' : 'unsupported',
            'ttlMinutes' => $shared_delivery ? $ttl_minutes : 0,
        );
    }

    /**
     * Select the verified automatic site-flush scope from the observed cache
     * topology rather than from web-server product assumptions.
     *
     * @param string $static_route  Observed public static-object route.
     * @param bool   $html_verified Whether HTML-only flush is verified.
     * @param bool   $host_verified Whether entire-host flush is verified.
     * @return array<string,string>
     */
    private static function get_varnish_auto_site_flush_choice($static_route, $html_verified, $host_verified)
    {
        $static_route = sanitize_key((string) $static_route);
        $html_verified = (bool) $html_verified;
        $host_verified = (bool) $host_verified;

        if ('through-varnish' === $static_route) {
            if ($host_verified) {
                return array(
                    'strategy' => 'host-flush',
                    'scope' => 'host',
                    'outcome' => 'complete',
                    'reason' => self::maybe_translate('Automatic site invalidation selected the verified entire-host scope because public static objects are cached through Varnish.'),
                );
            }
            if ($html_verified) {
                return array(
                    'strategy' => 'html-flush',
                    'scope' => 'html',
                    'outcome' => 'degraded',
                    'reason' => self::maybe_translate('Automatic site invalidation selected the verified HTML-only scope, but public static objects also pass through Varnish and no entire-host capability is verified.'),
                );
            }
        }

        if ('varnish-bypass' === $static_route) {
            if ($html_verified) {
                return array(
                    'strategy' => 'html-flush',
                    'scope' => 'html',
                    'outcome' => 'complete',
                    'reason' => self::maybe_translate('Automatic site invalidation selected the verified HTML-only scope because public static objects bypass Varnish.'),
                );
            }
            if ($host_verified) {
                return array(
                    'strategy' => 'host-flush',
                    'scope' => 'host',
                    'outcome' => 'complete',
                    'reason' => self::maybe_translate('Automatic site invalidation selected the verified entire-host scope; public static objects bypass Varnish, so the operation covers every Varnish-owned object.'),
                );
            }
        }

        if ($host_verified) {
            return array(
                'strategy' => 'host-flush',
                'scope' => 'host',
                'outcome' => 'complete',
                'reason' => self::maybe_translate('Automatic site invalidation selected the verified entire-host scope because the public static-object route is not conclusively classified.'),
            );
        }
        if ($html_verified) {
            return array(
                'strategy' => 'html-flush',
                'scope' => 'html',
                'outcome' => 'degraded',
                'reason' => self::maybe_translate('Automatic site invalidation selected the verified HTML-only scope, but the public static-object route is not conclusively classified.'),
            );
        }

        return array();
    }

    /**
     * Build a capability-driven runtime plan.
     *
     * @param string $operation Runtime operation: targeted or site-flush.
     * @param array  $context   Optional planning context.
     * @return array<string,mixed>
     */
    private static function plan_varnish_runtime_operation($operation, array $context = array())
    {
        $operation = sanitize_key((string) $operation);
        $settings = self::get_varnish_cli_settings();
        $dashboard_settings = self::get_dashboard_settings();
        $registry = self::get_varnish_endpoint_capability_registry_status($settings);
        $effective = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
        $exact_capability = self::get_varnish_exact_invalidation_capability($settings);
        $strategy_status = self::get_varnish_invalidation_strategy_status(
            $settings,
            sanitize_key((string) ($context['strategyOverride'] ?? ''))
        );
        $ttl_fallback = self::get_varnish_ttl_runtime_fallback($dashboard_settings);
        $transport_available = !empty($settings['support']['available'])
            && !empty($settings['enabled'])
            && !empty($settings['servers'])
            && ('admin' !== (string) ($settings['mode'] ?? 'http') || !empty($settings['secretConfigured']));
        $exact_verified = $transport_available && !empty($exact_capability['verified']);
        $batch_ban_verified = $transport_available && !empty($effective['batchBan']);
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $method = ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'PURGE' : 'BAN';
        $hard_strategy = 'admin' === $mode ? 'admin-ban' : ('PURGE' === $method ? 'exact-purge' : 'exact-ban');
        $effective_strategy = sanitize_key((string) ($strategy_status['effective'] ?? strtolower($method)));
        $targeted_strategy = 'soft' === $effective_strategy
            ? 'soft-purge'
            : ('admin' === $mode
                ? 'admin-ban'
                : ('ban' === $effective_strategy ? 'exact-ban' : 'exact-purge'));
        $test_probe_active = method_exists(static::class, 'is_varnish_test_run_active')
            && self::is_varnish_test_run_active();
        $probe_strategy = sanitize_key((string) ($context['probeStrategy'] ?? ''));
        $probe_scope = sanitize_key((string) ($context['probeScope'] ?? ''));
        $probe_endpoints = (array) ($context['probeEndpoints'] ?? array());
        $probe_urls = (array) ($context['probeUrls'] ?? array());
        $probe_authorized = '' !== $probe_strategy
            && '' !== $probe_scope
            && method_exists(static::class, 'is_varnish_capability_probe_authorized')
            && self::is_varnish_capability_probe_authorized($operation, array(
                'strategy' => $probe_strategy,
                'requestedScope' => $probe_scope,
                'endpoints' => $probe_endpoints,
                'urls' => $probe_urls,
            ));
        $base = array(
            'plannerVersion' => 1,
            'operation' => $operation,
            'canExecute' => false,
            'selectedStrategy' => 'none',
            'fallbackStrategy' => (string) $ttl_fallback['strategy'],
            'plannedOutcome' => (string) $ttl_fallback['outcome'],
            'usingFallback' => false,
            'reason' => '',
            'mode' => $mode,
            'method' => $method,
            'configuredStrategy' => sanitize_key((string) ($strategy_status['configured'] ?? '')),
            'effectiveTargetedStrategy' => $targeted_strategy,
            'softPurgeVerified' => 'soft-purge' === $targeted_strategy,
            'exactInvalidationVerified' => $exact_verified,
            'batchBanVerified' => $batch_ban_verified,
            'htmlFlushVerified' => !empty($effective['htmlFlush']),
            'hostFlushVerified' => !empty($effective['hostFlush']),
            'configuredEndpointCount' => absint($registry['configuredEndpointCount'] ?? count((array) ($settings['servers'] ?? array()))),
            'verifiedExactEndpointCount' => absint($registry['verifiedExactEndpointCount'] ?? 0),
            'registryStatus' => sanitize_key((string) ($registry['status'] ?? 'unverified')),
            'mixedTopology' => !empty($registry['mixedTopology']),
            'testProbeActive' => $test_probe_active,
            'capabilityProbeAuthorized' => $probe_authorized,
            'ttlFallbackAvailable' => !empty($ttl_fallback['available']),
            'ttlFallbackMinutes' => absint($ttl_fallback['ttlMinutes'] ?? 0),
            'knownUrlFallbackAvailable' => $exact_verified && self::can_build_varnish_known_url_fallback(),
            'knownUrlCount' => 0,
            'knownUrlLimit' => self::get_varnish_known_url_fallback_limit(),
            'knownUrlSource' => '',
            'knownUrlSetBounded' => false,
            'knownUrlDiscoveryError' => '',
            'requestedScope' => '',
            'effectiveScope' => 'unsupported',
            'directScope' => '',
            'staticRoute' => 'unverified',
        );

        if ('targeted' === $operation) {
            if ($probe_authorized && $transport_available) {
                $base['canExecute'] = true;
                $base['selectedStrategy'] = $targeted_strategy;
                $base['fallbackStrategy'] = 'none';
                $base['plannedOutcome'] = 'complete';
                $base['usingFallback'] = false;
                $base['reason'] = self::maybe_translate('The scoped Varnish capability probe may exercise only its authorized production operation before runtime proof is stored.');
                return $base;
            }

            if ($exact_verified) {
                $base['canExecute'] = true;
                $base['selectedStrategy'] = $targeted_strategy;
                $base['plannedOutcome'] = !empty($strategy_status['usingFallback']) ? 'degraded' : 'complete';
                $base['usingFallback'] = !empty($strategy_status['usingFallback']);
                $base['fallbackStrategy'] = !empty($strategy_status['usingFallback']) ? $hard_strategy : 'none';
                $base['reason'] = self::sanitize_varnish_string((string) ($strategy_status['message'] ?? ''));
                return $base;
            }

            $base['usingFallback'] = !empty($ttl_fallback['available']);
            $base['reason'] = self::sanitize_varnish_string(
                (string) ($exact_capability['message'] ?? self::maybe_translate('Exact Varnish invalidation is not verified.'))
            );
            return $base;
        }

        $requested_scope = sanitize_key((string) ($context['requestedScope'] ?? 'configured'));
        $configured_scope = self::sanitize_varnish_flush_scope($settings['flushScope'] ?? 'auto');
        if (in_array($requested_scope, array('', 'configured', 'site'), true)) {
            $requested_scope = $configured_scope;
        } elseif (in_array($requested_scope, array('entire-host', 'all', 'all-host'), true)) {
            $requested_scope = 'host';
        } else {
            $requested_scope = self::sanitize_varnish_flush_scope($requested_scope);
        }
        $base['requestedScope'] = $requested_scope;

        if ('auto' === $requested_scope && method_exists(static::class, 'get_varnish_capability_diagnostic')) {
            $topology_diagnostic = self::get_varnish_capability_diagnostic(
                'topology',
                array('html-invalidation')
            );
            if (empty($topology_diagnostic['configurationChanged'])) {
                $base['staticRoute'] = sanitize_key((string) ($topology_diagnostic['staticRoute'] ?? 'unverified'));
            }
        }

        if ($probe_authorized && $transport_available && in_array($requested_scope, array('html', 'host'), true)) {
            $expected_strategy = 'html' === $requested_scope ? 'html-flush' : 'host-flush';
            if ($probe_strategy === $expected_strategy && $probe_scope === $requested_scope) {
                $base['canExecute'] = true;
                $base['selectedStrategy'] = $expected_strategy;
                $base['fallbackStrategy'] = 'none';
                $base['plannedOutcome'] = 'complete';
                $base['usingFallback'] = false;
                $base['effectiveScope'] = $requested_scope;
                $base['directScope'] = $requested_scope;
                $base['reason'] = self::maybe_translate('The scoped Varnish capability probe may exercise only its authorized production site-flush operation before runtime proof is stored.');
                return $base;
            }
        }

        if ('html' === $requested_scope && !empty($effective['htmlFlush'])) {
            $base['canExecute'] = true;
            $base['selectedStrategy'] = 'html-flush';
            $base['fallbackStrategy'] = 'none';
            $base['plannedOutcome'] = 'complete';
            $base['effectiveScope'] = 'html';
            $base['directScope'] = 'html';
            $base['reason'] = self::maybe_translate('Every configured endpoint has current HTML-only invalidation proof.');
            return $base;
        }

        if ('host' === $requested_scope && !empty($effective['hostFlush'])) {
            $base['canExecute'] = true;
            $base['selectedStrategy'] = 'host-flush';
            $base['fallbackStrategy'] = 'none';
            $base['plannedOutcome'] = 'complete';
            $base['effectiveScope'] = 'host';
            $base['directScope'] = 'host';
            $base['reason'] = self::maybe_translate('Every configured endpoint has current entire-host invalidation proof.');
            return $base;
        }

        if ('auto' === $requested_scope) {
            $automatic_choice = self::get_varnish_auto_site_flush_choice(
                (string) ($base['staticRoute'] ?? 'unverified'),
                !empty($effective['htmlFlush']),
                !empty($effective['hostFlush'])
            );
            if (!empty($automatic_choice)) {
                $base['canExecute'] = true;
                $base['selectedStrategy'] = sanitize_key((string) $automatic_choice['strategy']);
                $base['fallbackStrategy'] = 'none';
                $base['plannedOutcome'] = sanitize_key((string) $automatic_choice['outcome']);
                $base['usingFallback'] = false;
                $base['effectiveScope'] = sanitize_key((string) $automatic_choice['scope']);
                $base['directScope'] = sanitize_key((string) $automatic_choice['scope']);
                $base['reason'] = self::sanitize_varnish_string((string) $automatic_choice['reason']);
                return $base;
            }
        }

        if ($exact_verified && self::can_build_varnish_known_url_fallback()) {
            $base['canExecute'] = true;
            $base['selectedStrategy'] = 'known-url-' . $targeted_strategy;
            $base['fallbackStrategy'] = 'known-url-exact';
            $base['plannedOutcome'] = 'degraded';
            $base['usingFallback'] = true;
            $base['effectiveScope'] = 'known-urls';
            $base['reason'] = self::maybe_translate('No requested site-wide scope is verified. UltraCache will invalidate the bounded set of local URLs known to its full-site crawl planner. Unknown or non-WordPress objects may remain cached.');

            if (!empty($context['includeKnownUrls'])) {
                $known = self::get_varnish_known_url_fallback_set(absint($context['knownUrlLimit'] ?? 0));
                $base['knownUrls'] = (array) ($known['urls'] ?? array());
                $base['knownUrlCount'] = absint($known['count'] ?? 0);
                $base['knownUrlLimit'] = absint($known['limit'] ?? self::get_varnish_known_url_fallback_limit());
                $base['knownUrlSource'] = sanitize_key((string) ($known['source'] ?? ''));
                $base['knownUrlSetBounded'] = !empty($known['bounded']);
                $base['knownUrlDiscoveryError'] = self::sanitize_varnish_string((string) ($known['error'] ?? ''));
                if (empty($base['knownUrls'])) {
                    $base['canExecute'] = false;
                    $base['selectedStrategy'] = (string) $ttl_fallback['strategy'];
                    $base['fallbackStrategy'] = (string) $ttl_fallback['strategy'];
                    $base['plannedOutcome'] = (string) $ttl_fallback['outcome'];
                    $base['effectiveScope'] = 'unsupported';
                    $base['reason'] = self::maybe_translate('No verified site-wide scope exists and the bounded known-URL fallback produced no eligible local URLs. Shared objects will expire by TTL.');
                }
            }
            return $base;
        }

        $base['usingFallback'] = !empty($ttl_fallback['available']);
        $base['selectedStrategy'] = (string) $ttl_fallback['strategy'];
        $base['fallbackStrategy'] = (string) $ttl_fallback['strategy'];
        $base['reason'] = $exact_verified
            ? self::maybe_translate('No requested site-wide scope is verified and a bounded known-URL set cannot be built. Shared objects will expire by TTL.')
            : self::maybe_translate('No requested site-wide scope or exact URL invalidation capability is verified. Shared objects will expire by TTL.');
        return $base;
    }

    /**
     * Strip internal URL payloads before exposing a runtime plan.
     *
     * @param array $plan Runtime plan.
     * @return array<string,mixed>
     */
    private static function sanitize_varnish_runtime_plan(array $plan)
    {
        unset($plan['knownUrls']);
        return self::sanitize_varnish_result($plan);
    }

    /**
     * Attach a normalized runtime outcome to an operation result.
     *
     * @param array $result             Operation result.
     * @param array $plan               Runtime plan.
     * @param bool  $execution_attempted Whether a transport operation was attempted.
     * @return array<string,mixed>
     */
    private static function finalize_varnish_runtime_result(array $result, array $plan, $execution_attempted = true)
    {
        if (!empty($result['partial'])) {
            $outcome = 'partial';
        } elseif (!empty($result['success'])) {
            $outcome = 'degraded' === (string) ($plan['plannedOutcome'] ?? '') ? 'degraded' : 'complete';
        } elseif (!$execution_attempted) {
            $outcome = (string) ($plan['plannedOutcome'] ?? 'unsupported');
        } elseif (!empty($result['unsupported'])) {
            $outcome = !empty($plan['ttlFallbackAvailable']) ? 'degraded' : 'unsupported';
        } else {
            $outcome = 'failed';
        }

        if (!in_array($outcome, array('complete', 'partial', 'degraded', 'unsupported', 'failed'), true)) {
            $outcome = 'failed';
        }

        $result['runtimeOutcome'] = $outcome;
        $result['runtimeComplete'] = 'complete' === $outcome;
        $result['runtimePartial'] = 'partial' === $outcome;
        $result['runtimeDegraded'] = 'degraded' === $outcome;
        $result['runtimeUnsupported'] = 'unsupported' === $outcome;
        $result['runtimeFailed'] = 'failed' === $outcome;
        $result['runtimeStrategy'] = sanitize_key((string) ($plan['selectedStrategy'] ?? 'none'));
        $result['runtimeFallbackStrategy'] = sanitize_key((string) ($plan['fallbackStrategy'] ?? 'none'));
        $result['runtimeUsingFallback'] = !empty($plan['usingFallback']);
        $result['runtimeExecutionAttempted'] = (bool) $execution_attempted;
        $result['runtimePlan'] = self::sanitize_varnish_runtime_plan($plan);

        if (
            $execution_attempted
            && in_array($outcome, array('partial', 'failed'), true)
            && method_exists(static::class, 'downgrade_varnish_runtime_capabilities_after_failure')
        ) {
            self::downgrade_varnish_runtime_capabilities_after_failure($result, $plan);
        }

        return $result;
    }

    /**
     * Build a no-transport result for TTL-expiry or unsupported fallback.
     *
     * @param array  $plan           Runtime plan.
     * @param string $operation_type Result operation type.
     * @param string $scope          Result scope.
     * @param array  $extra          Additional result fields.
     * @return array<string,mixed>
     */
    private static function build_varnish_runtime_fallback_result(array $plan, $operation_type, $scope, array $extra = array())
    {
        $ttl_minutes = absint($plan['ttlFallbackMinutes'] ?? 0);
        $message = self::sanitize_varnish_string((string) ($plan['reason'] ?? ''));
        if ('degraded' === (string) ($plan['plannedOutcome'] ?? '') && $ttl_minutes > 0) {
            $message .= ' ' . self::maybe_translate_sprintf(
                'No immediate Varnish invalidation was attempted; eligible shared-cache objects will expire within the configured %d-minute TTL-only window.',
                $ttl_minutes
            );
        }

        $result = array_merge(array(
            'success' => false,
            'partial' => false,
            'skipped' => true,
            'unsupported' => 'unsupported' === (string) ($plan['plannedOutcome'] ?? ''),
            'degraded' => 'degraded' === (string) ($plan['plannedOutcome'] ?? ''),
            'message' => trim($message),
            'time' => time(),
            'operationType' => sanitize_key((string) $operation_type),
            'scope' => sanitize_key((string) $scope),
            'requestCount' => 0,
            'successfulEndpointRequestCount' => 0,
            'failedEndpointRequestCount' => 0,
            'details' => array(),
        ), $extra);

        return self::finalize_varnish_runtime_result($result, $plan, false);
    }

    /**
     * Execute the planned site-level strategy.
     *
     * @param string $requested_scope Requested site scope.
     * @return array<string,mixed>
     */
    private static function execute_varnish_site_runtime_plan($requested_scope = 'configured', array $context = array())
    {
        $planning_context = array_merge($context, array(
            'requestedScope' => $requested_scope,
            'includeKnownUrls' => true,
        ));
        $plan = self::plan_varnish_runtime_operation('site-flush', $planning_context);
        $strategy = sanitize_key((string) ($plan['selectedStrategy'] ?? 'none'));

        if (in_array($strategy, array('html-flush', 'host-flush'), true) && !empty($plan['directScope'])) {
            $result = self::execute_varnish_site_flush((string) $plan['directScope'], $plan, $planning_context);
            $result = self::finalize_varnish_runtime_result($result, $plan, true);
            self::set_varnish_last_result($result, false);
            return $result;
        }

        if (0 === strpos($strategy, 'known-url-') && !empty($plan['knownUrls'])) {
            $known_urls = array_values((array) $plan['knownUrls']);
            $queue_decision = self::maybe_queue_varnish_invalidation($known_urls, 'site-known-url-fallback');
            $queue_mode = sanitize_key((string) ($queue_decision['mode'] ?? 'failed'));
            $sync_urls = 'direct' === $queue_mode
                ? (array) ($queue_decision['directUrls'] ?? array())
                : (array) ($queue_decision['fallbackDirectUrls'] ?? array());
            $deferred_to_ttl_count = max(0, (int) ($queue_decision['deferredToTtlUrlCount'] ?? 0));

            if ('queued' === $queue_mode) {
                $result = (array) ($queue_decision['result'] ?? array());
            } elseif (!empty($sync_urls)) {
                $result = self::varnish_flush_url_batch($sync_urls, 'site-known-url-fallback');
                $result = self::apply_varnish_queue_decision_to_direct_result($result, $queue_decision);
            } else {
                $result = (array) ($queue_decision['result'] ?? array());
            }

            $result['operationType'] = 'site-flush-fallback';
            $result['configuredScope'] = self::sanitize_varnish_flush_scope(self::get_varnish_cli_settings()['flushScope'] ?? 'auto');
            $result['requestedScope'] = sanitize_key((string) ($plan['requestedScope'] ?? $requested_scope));
            $result['effectiveScope'] = 'known-urls';
            $result['knownUrlFallback'] = true;
            $result['knownUrlCount'] = absint($plan['knownUrlCount'] ?? count($known_urls));
            $result['knownUrlLimit'] = absint($plan['knownUrlLimit'] ?? self::get_varnish_known_url_fallback_limit());
            $result['knownUrlSource'] = sanitize_key((string) ($plan['knownUrlSource'] ?? ''));
            $result['knownUrlSetBounded'] = !empty($plan['knownUrlSetBounded']);
            $result['knownUrlProcessedCount'] = 'queued' === $queue_mode
                ? absint($result['acceptedUrlCount'] ?? $result['queuedUrlCount'] ?? 0)
                : count($sync_urls);
            $result['knownUrlSyncLimit'] = max(0, (int) ($queue_decision['fallbackDirectUrlLimit'] ?? 0));
            $result['knownUrlDeferredToTtlCount'] = $deferred_to_ttl_count;
            $result['message'] = self::maybe_translate('No verified site-wide Varnish scope was available. ') . (string) ($result['message'] ?? '');
            $result = self::finalize_varnish_runtime_result(
                $result,
                $plan,
                !empty($result['requestCount']) || !empty($result['queued'])
            );
            self::set_varnish_last_result($result, false);
            return $result;
        }

        $result = self::build_varnish_runtime_fallback_result(
            $plan,
            'site-flush',
            'all',
            array(
                'configuredScope' => self::sanitize_varnish_flush_scope(self::get_varnish_cli_settings()['flushScope'] ?? 'auto'),
                'requestedScope' => sanitize_key((string) ($plan['requestedScope'] ?? $requested_scope)),
                'effectiveScope' => 'unsupported',
                'knownUrlFallback' => false,
                'knownUrlCount' => absint($plan['knownUrlCount'] ?? 0),
            )
        );
        self::set_varnish_last_result($result);
        return $result;
    }

    /**
     * Expose current planner decisions without executing a transport action.
     *
     * @return array<string,mixed>
     */
    public static function get_varnish_runtime_planner_status()
    {
        return array(
            'plannerVersion' => 1,
            'targeted' => self::sanitize_varnish_runtime_plan(
                self::plan_varnish_runtime_operation('targeted')
            ),
            'site' => self::sanitize_varnish_runtime_plan(
                self::plan_varnish_runtime_operation('site-flush', array('requestedScope' => 'configured'))
            ),
            'entireHost' => self::sanitize_varnish_runtime_plan(
                self::plan_varnish_runtime_operation('site-flush', array('requestedScope' => 'host'))
            ),
        );
    }
}
