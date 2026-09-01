<?php
/**
 * Capability-tested Varnish soft purge support for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Soft_Purge_Trait
{
    protected static function set_varnish_soft_purge_capability(array $capability)
    {
        $capability = self::bind_varnish_capability_contracts($capability, array('soft-purge'));
        $capability['testedAt'] = max(0, (int) ($capability['testedAt'] ?? time()));
        if (method_exists(static::class, 'get_configured_varnish_registry_endpoints')
            && method_exists(static::class, 'update_varnish_endpoint_capability_profile')) {
            $settings = self::get_varnish_cli_settings();
            $configured_endpoints = self::get_configured_varnish_registry_endpoints($settings);
            $endpoint_capabilities = is_array($capability['endpointCapabilities'] ?? null)
                ? $capability['endpointCapabilities']
                : array();
            if (!empty($configured_endpoints) && empty($endpoint_capabilities)) {
                $aggregate_tested = !empty($capability['tested']);
                $origin_tested = absint($capability['originRevalidation']['testedAt'] ?? 0) > 0;
                $origin = is_array($capability['originRevalidation'] ?? null) ? $capability['originRevalidation'] : array();
                foreach ($configured_endpoints as $configured_endpoint) {
                    $endpoint_capabilities[] = array(
                        'endpoint' => (string) ($configured_endpoint['endpoint'] ?? ''),
                        'softPurge' => !empty($capability['supported']),
                        'originRevalidation' => !empty($capability['originRevalidationVerified']),
                        'swr' => !empty($capability['staleVerified']) && !empty($capability['freshHitVerified']),
                        'softPurgeTested' => $aggregate_tested,
                        'originRevalidationTested' => $origin_tested,
                        'swrTested' => $aggregate_tested,
                        'softPurgeApplicable' => !array_key_exists('applicable', $capability) || !empty($capability['applicable']),
                        'originRevalidationApplicable' => !array_key_exists('applicable', $origin) || !empty($origin['applicable']),
                        'swrApplicable' => !array_key_exists('applicable', $capability) || !empty($capability['applicable']),
                        'softPurgeStatus' => sanitize_key((string) ($capability['status'] ?? 'not-tested')),
                        'originRevalidationStatus' => sanitize_key((string) ($origin['status'] ?? 'not-tested')),
                        'swrStatus' => sanitize_key((string) ($capability['status'] ?? 'not-tested')),
                        'softPurgeMessage' => self::sanitize_varnish_string((string) ($capability['message'] ?? '')),
                        'originRevalidationMessage' => self::sanitize_varnish_string((string) ($origin['message'] ?? '')),
                        'swrMessage' => self::sanitize_varnish_string((string) ($capability['message'] ?? '')),
                        'reachable' => $aggregate_tested || $origin_tested,
                        'testedAt' => max(absint($capability['testedAt'] ?? 0), absint($origin['testedAt'] ?? 0)),
                        'status' => sanitize_key((string) ($capability['status'] ?? 'not-tested')),
                        'message' => self::sanitize_varnish_string((string) ($capability['message'] ?? '')),
                    );
                }
            }
            $current_profiles = method_exists(static::class, 'get_current_varnish_endpoint_capability_profiles')
                ? self::get_current_varnish_endpoint_capability_profiles($settings)
                : array();
            foreach ($endpoint_capabilities as $endpoint_capability) {
                if (!is_array($endpoint_capability)) {
                    continue;
                }
                $endpoint = self::normalize_varnish_registry_endpoint($endpoint_capability['endpoint'] ?? '');
                if ('' === $endpoint) {
                    continue;
                }
                $tested_at = absint($endpoint_capability['testedAt'] ?? ($capability['testedAt'] ?? time()));
                $soft_tested_at = !empty($endpoint_capability['softPurgeTested']) ? $tested_at : 0;
                $origin_tested_at = !empty($endpoint_capability['originRevalidationTested']) ? $tested_at : 0;
                $swr_tested_at = !empty($endpoint_capability['swrTested']) ? $tested_at : 0;
                $mode = (string) ($settings['mode'] ?? 'http');
                $soft_applicable = !array_key_exists('softPurgeApplicable', $endpoint_capability)
                    ? (!array_key_exists('applicable', $capability) || !empty($capability['applicable']))
                    : !empty($endpoint_capability['softPurgeApplicable']);
                $origin_applicable = !array_key_exists('originRevalidationApplicable', $endpoint_capability)
                    || !empty($endpoint_capability['originRevalidationApplicable']);
                $swr_applicable = !array_key_exists('swrApplicable', $endpoint_capability)
                    ? $soft_applicable
                    : !empty($endpoint_capability['swrApplicable']);
                $soft_status = sanitize_key((string) ($endpoint_capability['softPurgeStatus'] ?? ($endpoint_capability['status'] ?? ($capability['status'] ?? 'not-tested'))));
                $origin_status = sanitize_key((string) ($endpoint_capability['originRevalidationStatus'] ?? 'not-tested'));
                $swr_status = sanitize_key((string) ($endpoint_capability['swrStatus'] ?? ($endpoint_capability['status'] ?? ($capability['status'] ?? 'not-tested'))));
                $soft_message = self::sanitize_varnish_string((string) ($endpoint_capability['softPurgeMessage'] ?? ($endpoint_capability['message'] ?? ($capability['message'] ?? ''))));
                $origin_message = self::sanitize_varnish_string((string) ($endpoint_capability['originRevalidationMessage'] ?? ''));
                $swr_message = self::sanitize_varnish_string((string) ($endpoint_capability['swrMessage'] ?? ($endpoint_capability['message'] ?? ($capability['message'] ?? ''))));
                $soft_conclusive = 'observation-incomplete' !== $soft_status && !in_array($soft_status, array('not-tested', 'probe-skipped'), true);
                $origin_conclusive = 'observation-incomplete' !== $origin_status && !in_array($origin_status, array('not-tested', 'probe-skipped'), true);
                $swr_conclusive = 'observation-incomplete' !== $swr_status && !in_array($swr_status, array('not-tested', 'probe-skipped'), true);
                self::persist_varnish_endpoint_capability_outcome(
                    $mode,
                    $endpoint,
                    'softPurge',
                    self::build_varnish_capability_outcome(
                        !empty($endpoint_capability['softPurge']),
                        $soft_tested_at > 0,
                        $soft_applicable,
                        !empty($endpoint_capability['softPurge']) || ($soft_tested_at > 0 && $soft_conclusive),
                        $soft_status,
                        $soft_message,
                        $soft_tested_at,
                        0
                    ),
                    $settings
                );
                self::persist_varnish_endpoint_capability_outcome(
                    $mode,
                    $endpoint,
                    'originRevalidation',
                    self::build_varnish_capability_outcome(
                        !empty($endpoint_capability['originRevalidation']),
                        $origin_tested_at > 0,
                        $origin_applicable,
                        !empty($endpoint_capability['originRevalidation']) || ($origin_tested_at > 0 && $origin_conclusive),
                        !empty($endpoint_capability['originRevalidation']) ? 'behavior-verified' : $origin_status,
                        $origin_message,
                        $origin_tested_at,
                        0
                    ),
                    $settings
                );
                self::persist_varnish_endpoint_capability_outcome(
                    $mode,
                    $endpoint,
                    'swr',
                    self::build_varnish_capability_outcome(
                        !empty($endpoint_capability['swr']),
                        $swr_tested_at > 0,
                        $swr_applicable,
                        !empty($endpoint_capability['swr']) || ($swr_tested_at > 0 && $swr_conclusive),
                        !empty($endpoint_capability['swr']) ? 'behavior-verified' : $swr_status,
                        $swr_message,
                        $swr_tested_at,
                        0
                    ),
                    $settings
                );
                if (0 === $soft_tested_at && 0 === $origin_tested_at && 0 === $swr_tested_at) {
                    continue;
                }
                $profile_key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
                $current = isset($current_profiles[$profile_key]) && is_array($current_profiles[$profile_key])
                    ? $current_profiles[$profile_key]
                    : array();
                $soft_verified = $soft_tested_at > 0
                    ? !empty($endpoint_capability['softPurge'])
                    : !empty($current['softPurge']);
                $origin_verified = $origin_tested_at > 0
                    ? !empty($endpoint_capability['originRevalidation'])
                    : !empty($current['originRevalidation']);
                $swr_verified = $swr_tested_at > 0
                    ? !empty($endpoint_capability['swr'])
                    : !empty($current['swr']);
                $soft_effective_tested_at = $soft_tested_at > 0
                    ? $soft_tested_at
                    : absint($current['softPurgeTestedAt'] ?? 0);
                $origin_effective_tested_at = $origin_tested_at > 0
                    ? $origin_tested_at
                    : absint($current['originRevalidationTestedAt'] ?? 0);
                $swr_effective_tested_at = $swr_tested_at > 0
                    ? $swr_tested_at
                    : absint($current['swrTestedAt'] ?? 0);
                $soft_proof_expires_at = 0;
                $origin_proof_expires_at = 0;
                $swr_proof_expires_at = 0;
                $soft_purge_expiries = array();
                $proof_verified = $soft_verified && $origin_verified && $swr_verified;
                $any_behavior_verified = $soft_verified || $origin_verified || $swr_verified;
                $changes = array(
                    'softPurge' => $soft_verified,
                    'originRevalidation' => $origin_verified,
                    'swr' => $swr_verified,
                    'testedAt' => max($tested_at, $soft_effective_tested_at, $origin_effective_tested_at, $swr_effective_tested_at),
                    'softPurgeTestedAt' => $soft_effective_tested_at,
                    'originRevalidationTestedAt' => $origin_effective_tested_at,
                    'swrTestedAt' => $swr_effective_tested_at,
                    'softPurgeBehaviorProofExpiresAt' => $soft_proof_expires_at,
                    'originRevalidationProofExpiresAt' => $origin_proof_expires_at,
                    'swrProofExpiresAt' => $swr_proof_expires_at,
                    'softPurgeProofExpiresAt' => !empty($soft_purge_expiries) ? min($soft_purge_expiries) : 0,
                    'softPurgeConfigurationFingerprint' => self::get_varnish_registry_soft_purge_fingerprint($settings),
                    'softPurgeRuntimeAvailable' => $any_behavior_verified,
                    'source' => 'soft-purge-canary',
                    'status' => sanitize_key((string) ($endpoint_capability['status'] ?? ($capability['status'] ?? 'inconclusive'))),
                );
                if (!empty($endpoint_capability['reachable'])) {
                    $changes['reachable'] = true;
                }
                if ($proof_verified) {
                    $changes['lastSuccessAt'] = $tested_at;
                } elseif ($soft_tested_at > 0 || $origin_tested_at > 0 || $swr_tested_at > 0) {
                    $changes['lastFailureAt'] = $tested_at;
                    $changes['lastFailure'] = substr(
                        self::sanitize_varnish_string((string) ($endpoint_capability['message'] ?? ($capability['message'] ?? 'Soft purge was not verified.'))),
                        0,
                        1000
                    );
                }
                self::update_varnish_endpoint_capability_profile(
                    $mode,
                    $endpoint,
                    $changes,
                    $settings
                );
            }
        }

        $diagnostic = $capability;
        unset($diagnostic['endpointCapabilities']);
        if (method_exists(static::class, 'persist_varnish_capability_diagnostic')) {
            self::persist_varnish_capability_diagnostic(
                'softpurge',
                $diagnostic,
                array('soft-purge')
            );
        }
    }

    protected static function get_varnish_soft_purge_capability(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        if ('http' !== (string) ($settings['mode'] ?? 'http')) {
            return array(
                'supported' => false,
                'status' => 'http-only',
                'message' => self::maybe_translate('Soft purge requires HTTP endpoint mode and is not available through the Varnish admin BAN interface.'),
                'testedAt' => 0,
            );
        }

        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $effective = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
        $supported = !empty($effective['softPurge'])
            && !empty($effective['originRevalidation'])
            && !empty($effective['swr']);
        $tested_at = 0;
        foreach ((array) ($registry['endpoints'] ?? array()) as $endpoint) {
            if (is_array($endpoint)) {
                $tested_at = max(
                    $tested_at,
                    absint($endpoint['softPurgeTestedAt'] ?? 0),
                    absint($endpoint['originRevalidationTestedAt'] ?? 0),
                    absint($endpoint['swrTestedAt'] ?? 0)
                );
            }
        }

        $soft_state = is_array($registry['capabilityStates']['softPurge'] ?? null)
            ? $registry['capabilityStates']['softPurge']
            : array();
        $diagnostic = method_exists(static::class, 'get_varnish_capability_diagnostic')
            ? self::get_varnish_capability_diagnostic('softpurge', array('soft-purge'))
            : array();
        $status = $supported ? 'verified' : sanitize_key((string) ($soft_state['state'] ?? 'not-tested'));
        if (!empty($registry['mixedTopology']) && !$supported) {
            $status = 'mixed-topology-unverified';
        } elseif (!empty($diagnostic['configurationChanged']) && !$supported) {
            $status = 'configuration-changed';
        }

        if ($supported) {
            $message = self::maybe_translate('Every configured HTTP Varnish endpoint has current soft-purge, origin-revalidation, and stale-refresh proof.');
        } elseif (empty($diagnostic['configurationChanged']) && '' !== (string) ($diagnostic['message'] ?? '')) {
            $message = self::sanitize_varnish_string((string) $diagnostic['message']);
        } elseif ('configuration-changed' === $status) {
            $message = self::maybe_translate('The soft-purge contract changed and must be verified again.');
        } else {
            $message = self::maybe_translate('Soft purge remains inactive until every configured HTTP Varnish endpoint independently proves soft expiry, origin revalidation, and fresh refill.');
        }

        return array(
            'supported' => $supported,
            'status' => $status,
            'message' => $message,
            'testedAt' => max($tested_at, empty($diagnostic['configurationChanged']) ? absint($diagnostic['testedAt'] ?? 0) : 0),
            'proofExpiresAt' => absint($registry['proofExpiresAtByCapability']['softPurge'] ?? 0),
            'originRevalidationVerified' => !empty($effective['originRevalidation']),
            'staleVerified' => !empty($effective['swr']),
            'freshHitVerified' => !empty($effective['swr']),
            'verificationAttemptCount' => absint($diagnostic['verificationAttemptCount'] ?? 0),
            'invalidationEndpointCount' => absint($registry['configuredEndpointCount'] ?? 0),
            'endpointRegistry' => $registry,
        );
    }

    private static function get_varnish_invalidation_strategy_status(array $settings = array(), $override = '')
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $configured = self::sanitize_varnish_invalidation_strategy($settings['invalidationStrategy'] ?? 'ban');
        $override = sanitize_key((string) $override);
        $capability = self::get_varnish_soft_purge_capability($settings);
        $configured_fallback = ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'purge' : 'ban';
        $fallback = $configured_fallback;
        if ('http' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')
            && method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')) {
            $registry = self::get_varnish_endpoint_capability_registry_status($settings);
            $effective_capabilities = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
            $configured_verified = 'purge' === $configured_fallback
                ? !empty($effective_capabilities['exactPurge'])
                : !empty($effective_capabilities['exactBan']);
            if (!$configured_verified) {
                if (!empty($effective_capabilities['exactPurge'])) {
                    $fallback = 'purge';
                } elseif (!empty($effective_capabilities['exactBan'])) {
                    $fallback = 'ban';
                }
            }
        }

        $explicit_hard_override = in_array($override, array('purge', 'ban'), true);
        if ($explicit_hard_override) {
            $effective = $override;
        } elseif ('hard' === $override) {
            $effective = $fallback;
        } elseif ('soft' === $override) {
            $effective = 'soft';
        } elseif ('admin' === (string) ($settings['mode'] ?? 'http')) {
            $effective = 'ban';
        } elseif ('soft' === $configured) {
            $effective = !empty($capability['supported']) ? 'soft' : $fallback;
        } elseif ('auto' === $configured) {
            $effective = !empty($capability['supported']) ? 'soft' : $fallback;
        } elseif (in_array($configured, array('purge', 'ban'), true)) {
            $effective = $fallback;
        } else {
            $effective = $configured;
        }

        $using_hard_capability_fallback = !$explicit_hard_override
            && in_array($configured, array('purge', 'ban'), true)
            && $effective !== $configured;
        $using_soft_fallback = 'soft' === $configured && 'soft' !== $effective;
        if ($using_hard_capability_fallback) {
            $message = self::maybe_translate_sprintf(
                'Configured %1$s invalidation is not behavior-verified on every endpoint; UltraCache is using verified %2$s invalidation.',
                strtoupper($configured),
                strtoupper($effective)
            );
        } elseif ('soft' === $effective) {
            $message = self::maybe_translate('Verified soft purge is active and successful targeted invalidations are followed by queued refill.');
        } elseif (('soft' === $configured || 'auto' === $configured) && empty($capability['supported'])) {
            $message = self::maybe_translate_sprintf('Soft purge is not verified; UltraCache is using %s invalidation.', strtoupper($fallback));
        } else {
            $message = self::maybe_translate_sprintf('UltraCache is using %s invalidation.', strtoupper($effective));
        }

        return array(
            'configured' => $configured,
            'effective' => $effective,
            'effectiveLabel' => 'soft' === $effective ? 'soft PURGE' : (('admin' === (string) ($settings['mode'] ?? 'http')) ? 'admin BAN' : strtoupper($effective)),
            'fallback' => $fallback,
            'softCapability' => $capability,
            'configuredHardStrategy' => $configured_fallback,
            'verifiedHardStrategy' => $fallback,
            'usingFallback' => $using_soft_fallback || $using_hard_capability_fallback,
            'message' => $message,
        );
    }

    protected static function send_varnish_soft_purge_prepared_urls(array $prepared, $scope = 'batch', array $endpoint_targets = array(), $requested_targets_supplied = false)
    {
        $settings = self::get_varnish_cli_settings();
        $details = array();
        $request_count = 0;
        $successful_endpoint_requests = 0;
        $failed_endpoint_requests = 0;
        $urls = array_values((array) ($prepared['urls'] ?? array()));
        $targets = empty($endpoint_targets)
            ? self::resolve_varnish_invalidation_targets((array) ($settings['servers'] ?? array()))
            : self::resolve_varnish_invalidation_targets((array) ($settings['servers'] ?? array()), $endpoint_targets);
        $url_results = self::initialize_varnish_url_results($urls);

        foreach ($urls as $url_index => $item) {
            $expr = self::build_varnish_ban_expression((string) ($item['host'] ?? ''), (string) ($item['path'] ?? '/'), false);
            $canonical_url = (string) ($item['url'] ?? '');
            foreach ($targets as $terminal) {
                $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                $endpoint = !empty($endpoint_check['valid']) ? self::normalize_varnish_endpoint($terminal) : array();
                if (empty($endpoint)) {
                    ++$request_count;
                    ++$failed_endpoint_requests;
                    $failure_detail = self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.'));
                    $response = array('ok' => false, 'detail' => $failure_detail, 'code' => 0);
                    self::record_varnish_endpoint_result($terminal, 'http', false, 0, $failure_detail);
                    self::record_varnish_url_endpoint_result($url_results, array($canonical_url), $terminal, $response);
                    $details[] = array(
                        'server' => $terminal,
                        'success' => false,
                        'detail' => $failure_detail,
                    );
                    continue;
                }

                $target_url = self::build_varnish_target_url($endpoint, (string) ($item['path'] ?? '/'));
                $response = self::send_varnish_http_request(
                    $endpoint,
                    $target_url,
                    (string) ($item['host'] ?? ''),
                    $settings['timeout'],
                    $expr,
                    'PURGE',
                    array(
                        'X-Purge' => 'soft',
                        'X-UltraCache-Purge-Mode' => 'soft',
                        'X-UltraCache-Soft-Purge' => '1',
                    )
                );
                ++$request_count;
                $success = !empty($response['ok']);
                if ($success) {
                    ++$successful_endpoint_requests;
                } else {
                    ++$failed_endpoint_requests;
                }
                self::record_varnish_url_endpoint_result($url_results, array($canonical_url), $terminal, $response);
                $details[] = array(
                    'server' => $terminal,
                    'success' => $success,
                    'detail' => self::sanitize_varnish_string(
                        self::maybe_translate_sprintf(
                            'Soft URL %1$d/%2$d · %3$s',
                            $url_index + 1,
                            count($urls),
                            (string) ($response['detail'] ?? '')
                        )
                    ),
                );
            }
        }

        $accounting = self::finalize_varnish_url_results($url_results);
        $all_ok = (int) $accounting['fullyInvalidatedUrlCount'] === (int) ($prepared['uniqueCount'] ?? count($urls));
        $partial = !$all_ok && ((int) $accounting['partiallyInvalidatedUrlCount'] > 0 || (int) $accounting['fullyInvalidatedUrlCount'] > 0);
        $detail_count = count($details);
        $details_truncated = $detail_count > 100;
        if ($details_truncated) {
            $details = array_slice($details, 0, 100);
        }

        if ($all_ok) {
            $message = self::maybe_translate_sprintf(
                'Varnish soft purge expired %1$d unique URL(s) with %2$d request(s); affected-page refill will follow.',
                (int) ($prepared['uniqueCount'] ?? count($urls)),
                $request_count
            );
        } elseif ($partial) {
            $message = self::maybe_translate_sprintf(
                'Varnish soft purge fully expired %1$d URL(s), partially expired %2$d, and failed %3$d; refill will follow only for fully expired URLs.',
                (int) $accounting['fullyInvalidatedUrlCount'],
                (int) $accounting['partiallyInvalidatedUrlCount'],
                (int) $accounting['failedUrlCount']
            );
        } else {
            $message = self::maybe_translate('Varnish soft purge failed on every requested URL.');
        }

        $result = array_merge(array(
            'success' => $all_ok,
            'partial' => $partial,
            'message' => $message,
            'time' => time(),
            'mode' => 'http',
            'method' => 'PURGE',
            'effectiveMethod' => 'soft PURGE',
            'invalidationStrategy' => 'soft',
            'softPurge' => true,
            'endpointCount' => count($targets),
            'configuredEndpointCount' => count((array) ($settings['servers'] ?? array())),
            'adminModeUsed' => false,
            'httpEndpointModeUsed' => true,
            'secretConfigured' => !empty($settings['key']),
            'scope' => sanitize_key((string) $scope),
            'operationType' => 'batch-invalidation',
            'receivedUrlCount' => (int) ($prepared['receivedCount'] ?? count($urls)),
            'validUrlCount' => (int) ($prepared['validCount'] ?? count($urls)),
            'uniqueUrlCount' => (int) ($prepared['uniqueCount'] ?? count($urls)),
            'duplicateUrlCount' => (int) ($prepared['duplicateCount'] ?? 0),
            'rejectedUrlCount' => (int) ($prepared['rejectedCount'] ?? 0),
            'hostCount' => count(array_unique(array_map(static function ($item) { return (string) ($item['host'] ?? ''); }, $urls))),
            'batchCount' => 0,
            'requestCount' => $request_count,
            'successfulEndpointRequestCount' => $successful_endpoint_requests,
            'failedEndpointRequestCount' => $failed_endpoint_requests,
            'requestedEndpointTargets' => $requested_targets_supplied ? array_values($endpoint_targets) : array(),
            'attemptedEndpointTargets' => array_values($targets),
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'detailCount' => $detail_count,
            'detailsTruncated' => $details_truncated,
            'details' => $details,
        ), $accounting);

        $persisted_result = $result;
        unset($persisted_result['urlResults']);
        self::set_varnish_last_result($persisted_result);
        return $result;
    }
}
