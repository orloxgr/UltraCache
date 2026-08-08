<?php
/**
 * Varnish site-flush scope and capability helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Flush_Scope_Trait
{
    /**
     * Persist the latest verified flush-topology capability.
     *
     * @param array $capability Capability result.
     * @return void
     */
    protected static function set_varnish_html_flush_capability(array $capability)
    {
        $capability = self::bind_varnish_capability_contracts($capability, array('html-invalidation'));
        $capability['testedAt'] = isset($capability['testedAt']) ? absint($capability['testedAt']) : time();
        $capability['supported'] = !empty($capability['supported']);
        $capability['manualSupported'] = !empty($capability['supported']);
        $capability['topologyVerified'] = !empty($capability['topologyVerified']);
        $capability['tested'] = !empty($capability['tested']);
        $capability['transportVerified'] = !empty($capability['transportVerified']);
        $capability['partialEndpointOutage'] = !empty($capability['partialEndpointOutage']);
        $capability['transportFailure'] = !empty($capability['transportFailure']);
        $capability['endpointBehaviorVerified'] = !empty($capability['endpointBehaviorVerified']);
        $capability['exactInvalidationVerified'] = !empty($capability['exactInvalidationVerified']);
        $capability['htmlInvalidationVerified'] = !empty($capability['htmlInvalidationVerified']);
        $capability['entireHostVerified'] = !empty($capability['entireHostVerified']);
        $capability['staticOpaqueStable'] = !empty($capability['staticOpaqueStable']);
        $capability['controlDataPathIsolated'] = !empty($capability['controlDataPathIsolated']);
        $capability['controlEndpointSetTested'] = !empty($capability['controlEndpointSetTested']);
        $capability['transportMode'] = sanitize_key((string) ($capability['transportMode'] ?? ''));
        $capability['transportMethod'] = self::sanitize_varnish_string((string) ($capability['transportMethod'] ?? ''));
        $capability['configuredEndpointCount'] = absint($capability['configuredEndpointCount'] ?? 0);
        $capability['successfulEndpointCount'] = absint($capability['successfulEndpointCount'] ?? 0);
        $capability['failedEndpointCount'] = absint($capability['failedEndpointCount'] ?? 0);
        $capability['staticRoute'] = sanitize_key((string) ($capability['staticRoute'] ?? 'inconclusive'));
        $capability['staticPreservation'] = sanitize_key((string) ($capability['staticPreservation'] ?? 'not-tested'));
        $capability['entireHostStatus'] = sanitize_key((string) ($capability['entireHostStatus'] ?? 'not-tested'));
        $capability['entireHostStaticInvalidation'] = sanitize_key((string) ($capability['entireHostStaticInvalidation'] ?? 'not-tested'));
        $capability['staticRouteMessage'] = self::sanitize_varnish_string((string) ($capability['staticRouteMessage'] ?? ''));
        $capability['status'] = sanitize_key((string) ($capability['status'] ?? 'inconclusive'));
        $capability['message'] = self::sanitize_varnish_string((string) ($capability['message'] ?? ''));

        $stored_capability = $capability;
        unset($stored_capability['endpointCapabilities']);
        if (method_exists(static::class, 'persist_varnish_capability_diagnostic')) {
            self::persist_varnish_capability_diagnostic(
                'topology',
                $stored_capability,
                array('html-invalidation')
            );
        }
        if (method_exists(static::class, 'get_configured_varnish_registry_endpoints')
            && method_exists(static::class, 'update_varnish_endpoint_capability_profile')) {
            $settings = self::get_varnish_cli_settings();
            $configured_endpoints = self::get_configured_varnish_registry_endpoints($settings);
            $endpoint_capabilities = is_array($capability['endpointCapabilities'] ?? null)
                ? $capability['endpointCapabilities']
                : array();
            if (!empty($configured_endpoints) && empty($endpoint_capabilities)) {
                foreach ($configured_endpoints as $configured_endpoint) {
                    $endpoint_capabilities[] = array(
                        'endpoint' => (string) ($configured_endpoint['endpoint'] ?? ''),
                        'htmlFlush' => !empty($capability['topologyVerified']) && !empty($capability['htmlInvalidationVerified']),
                        'hostFlush' => !empty($capability['topologyVerified']) && !empty($capability['entireHostVerified']),
                        'htmlTested' => !empty($capability['htmlTestedAllEndpoints']),
                        'hostTested' => !empty($capability['hostTestedAllEndpoints']),
                        'reachable' => !empty($capability['transportVerified']),
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
                $verified_reachable = !empty($endpoint_capability['reachable']);
                $html_tested = !empty($endpoint_capability['htmlTested']);
                $host_tested = !empty($endpoint_capability['hostTested']);
                $mode = (string) ($settings['mode'] ?? 'http');
                $tested_at = absint($capability['testedAt'] ?? time());
                $html_test = is_array($endpoint_capability['htmlTest'] ?? null) ? $endpoint_capability['htmlTest'] : array();
                $host_test = is_array($endpoint_capability['hostTest'] ?? null) ? $endpoint_capability['hostTest'] : array();
                $html_status = sanitize_key((string) ($html_test['status'] ?? ($endpoint_capability['status'] ?? ($capability['status'] ?? 'not-tested'))));
                $host_status = sanitize_key((string) ($host_test['status'] ?? ($endpoint_capability['status'] ?? ($capability['status'] ?? 'not-tested'))));
                $html_message = self::sanitize_varnish_string((string) ($html_test['message'] ?? ($endpoint_capability['message'] ?? ($capability['message'] ?? ''))));
                $host_message = self::sanitize_varnish_string((string) ($host_test['message'] ?? ($endpoint_capability['message'] ?? ($capability['message'] ?? ''))));
                $host_applicable = array_key_exists('applicable', $host_test)
                    ? !empty($host_test['applicable'])
                    : !in_array($host_status, array('not-applicable', 'not-applicable-static-bypass'), true);
                self::persist_varnish_endpoint_capability_outcome(
                    $mode,
                    $endpoint,
                    'htmlFlush',
                    self::build_varnish_capability_outcome(
                        !empty($endpoint_capability['htmlFlush']),
                        $html_tested,
                        true,
                        !empty($endpoint_capability['htmlFlush']) || !empty($endpoint_capability['htmlConclusive']) || ($html_tested && 'observation-incomplete' !== $html_status),
                        $html_status,
                        $html_message,
                        $html_tested ? $tested_at : 0,
                        !empty($endpoint_capability['htmlFlush']) ? $tested_at + WEEK_IN_SECONDS : 0
                    ),
                    $settings
                );
                self::persist_varnish_endpoint_capability_outcome(
                    $mode,
                    $endpoint,
                    'hostFlush',
                    self::build_varnish_capability_outcome(
                        !empty($endpoint_capability['hostFlush']),
                        $host_tested,
                        $host_applicable,
                        !empty($endpoint_capability['hostFlush']) || !empty($endpoint_capability['hostConclusive']) || ($host_tested && 'observation-incomplete' !== $host_status),
                        $host_status,
                        $host_message,
                        $host_tested ? $tested_at : 0,
                        !empty($endpoint_capability['hostFlush']) ? $tested_at + WEEK_IN_SECONDS : 0
                    ),
                    $settings
                );
                if (!$html_tested && !$host_tested) {
                    continue;
                }

                $profile_key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
                $current = isset($current_profiles[$profile_key]) && is_array($current_profiles[$profile_key])
                    ? $current_profiles[$profile_key]
                    : array();
                $html_verified = $html_tested
                    ? !empty($endpoint_capability['htmlFlush'])
                    : !empty($current['htmlFlush']);
                $host_verified = $host_tested
                    ? !empty($endpoint_capability['hostFlush'])
                    : !empty($current['hostFlush']);
                $html_tested_at = $html_tested
                    ? $tested_at
                    : absint($current['htmlFlushTestedAt'] ?? 0);
                $host_tested_at = $host_tested
                    ? $tested_at
                    : absint($current['hostFlushTestedAt'] ?? 0);
                $html_proof_expires_at = $html_tested
                    ? ($html_verified ? $tested_at + WEEK_IN_SECONDS : 0)
                    : absint($current['htmlFlushProofExpiresAt'] ?? ($current['topologyProofExpiresAt'] ?? 0));
                $host_proof_expires_at = $host_tested
                    ? ($host_verified ? $tested_at + WEEK_IN_SECONDS : 0)
                    : absint($current['hostFlushProofExpiresAt'] ?? ($current['topologyProofExpiresAt'] ?? 0));
                $topology_expiries = array_filter(array(
                    $html_proof_expires_at,
                    $host_proof_expires_at,
                ));
                $changes = array(
                    'htmlFlush' => $html_verified,
                    'hostFlush' => $host_verified,
                    'htmlFlushTestedAt' => $html_tested_at,
                    'hostFlushTestedAt' => $host_tested_at,
                    'topologyTestedAt' => max($html_tested_at, $host_tested_at),
                    'htmlFlushProofExpiresAt' => $html_proof_expires_at,
                    'hostFlushProofExpiresAt' => $host_proof_expires_at,
                    'topologyProofExpiresAt' => !empty($topology_expiries) ? min($topology_expiries) : 0,
                    'topologyRuntimeAvailable' => $html_verified || $host_verified,
                    'testedAt' => $tested_at,
                    'source' => 'flush-topology-canary',
                    'status' => sanitize_key((string) ($endpoint_capability['status'] ?? ($capability['status'] ?? 'inconclusive'))),
                );
                if ($verified_reachable) {
                    $changes['reachable'] = true;
                }
                self::update_varnish_endpoint_capability_profile(
                    $mode,
                    $endpoint,
                    $changes,
                    $settings
                );
            }
        }
        if (method_exists(static::class, 'sync_shared_cache_runtime_after_varnish_capability_change')) {
            self::sync_shared_cache_runtime_after_varnish_capability_change();
        }
    }

    /**
     * Return an unverified topology capability.
     *
     * @param string $status  Capability status.
     * @param string $message Capability message.
     * @param int    $tested_at Test timestamp.
     * @return array
     */
    private static function get_unverified_varnish_flush_capability($status, $message, $tested_at = 0)
    {
        return array(
            'supported' => false,
            'manualSupported' => false,
            'topologyVerified' => false,
            'transportVerified' => false,
            'partialEndpointOutage' => false,
            'transportFailure' => false,
            'endpointBehaviorVerified' => false,
            'exactInvalidationVerified' => false,
            'htmlInvalidationVerified' => false,
            'entireHostVerified' => false,
            'staticOpaqueStable' => false,
            'controlDataPathIsolated' => false,
            'controlEndpointSetTested' => false,
            'transportMode' => '',
            'transportMethod' => '',
            'configuredEndpointCount' => 0,
            'successfulEndpointCount' => 0,
            'failedEndpointCount' => 0,
            'staticRoute' => 'untested',
            'staticPreservation' => 'not-tested',
            'entireHostStatus' => 'not-tested',
            'entireHostStaticInvalidation' => 'not-tested',
            'staticRouteMessage' => '',
            'status' => sanitize_key((string) $status),
            'message' => self::sanitize_varnish_string((string) $message),
            'testedAt' => absint($tested_at),
            'htmlTestedAt' => 0,
            'hostTestedAt' => 0,
        );
    }

    /**
     * Read the flush-topology capability for the current endpoint configuration.
     *
     * @return array
     */
    protected static function get_varnish_html_flush_capability()
    {
        $settings = self::get_varnish_cli_settings();
        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $effective = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
        $html_verified = !empty($effective['htmlFlush']);
        $host_verified = !empty($effective['hostFlush']);
        $diagnostic = method_exists(static::class, 'get_varnish_capability_diagnostic')
            ? self::get_varnish_capability_diagnostic('topology', array('html-invalidation'))
            : array();
        $diagnostic_current = empty($diagnostic['configurationChanged']);
        $endpoint_rows = is_array($registry['endpoints'] ?? null) ? $registry['endpoints'] : array();
        $html_tested_at = 0;
        $host_tested_at = 0;
        $successful = 0;
        foreach ($endpoint_rows as $endpoint_row) {
            if (!is_array($endpoint_row)) {
                continue;
            }
            $html_tested_at = max($html_tested_at, absint($endpoint_row['htmlFlushTestedAt'] ?? 0));
            $host_tested_at = max($host_tested_at, absint($endpoint_row['hostFlushTestedAt'] ?? 0));
            $current_capabilities = is_array($endpoint_row['currentCapabilities'] ?? null)
                ? $endpoint_row['currentCapabilities']
                : array();
            if (!empty($current_capabilities['htmlFlush']) || !empty($current_capabilities['hostFlush'])) {
                $successful++;
            }
        }

        if (!$html_verified && !$host_verified) {
            $html_state = is_array($registry['capabilityStates']['htmlFlush'] ?? null)
                ? $registry['capabilityStates']['htmlFlush']
                : array();
            $host_state = is_array($registry['capabilityStates']['hostFlush'] ?? null)
                ? $registry['capabilityStates']['hostFlush']
                : array();
            $diagnostic_tested_at = $diagnostic_current && !empty($diagnostic['tested'])
                ? absint($diagnostic['testedAt'] ?? 0)
                : 0;
            $diagnostic_status = $diagnostic_current
                ? sanitize_key((string) ($diagnostic['status'] ?? ''))
                : 'configuration-changed';
            $aggregate_status = 'untested';
            if (!empty($registry['mixedTopology'])) {
                $aggregate_status = 'mixed-topology-unverified';
            } elseif ('proof-expired' === (string) ($html_state['state'] ?? '')
                || 'proof-expired' === (string) ($host_state['state'] ?? '')) {
                $aggregate_status = 'proof-expired';
            } elseif ($diagnostic_tested_at > 0 && '' !== $diagnostic_status) {
                $aggregate_status = $diagnostic_status;
            } elseif (!empty($html_state['tested']) || !empty($host_state['tested'])) {
                $aggregate_status = 'not-supported';
            }

            if ('mixed-topology-unverified' === $aggregate_status) {
                $message = self::maybe_translate('Site-wide invalidation is not enabled because every configured Varnish endpoint must independently prove the requested scope.');
            } elseif ('proof-expired' === $aggregate_status) {
                $message = self::maybe_translate('The stored HTML-only or entire-host behavior proof has expired. Run Test Varnish to renew it.');
            } elseif ($diagnostic_current && '' !== (string) ($diagnostic['message'] ?? '')) {
                $message = self::sanitize_varnish_string((string) $diagnostic['message']);
            } elseif ('configuration-changed' === $aggregate_status) {
                $message = self::maybe_translate('The Varnish invalidation contract changed. Run Test Varnish to verify HTML-only or entire-host invalidation again.');
            } elseif ('untested' === $aggregate_status) {
                $message = self::maybe_translate('Run Test Varnish to verify HTML-only or entire-host invalidation for every configured endpoint.');
            } else {
                $message = self::maybe_translate('The configured Varnish transport did not pass the HTML-only or entire-host behavior proof.');
            }

            $capability = self::get_unverified_varnish_flush_capability(
                $aggregate_status,
                $message,
                max($html_tested_at, $host_tested_at, $diagnostic_tested_at)
            );
            $capability['htmlTestedAt'] = $html_tested_at;
            $capability['hostTestedAt'] = $host_tested_at;
            if ($diagnostic_current) {
                foreach (array(
                    'transportVerified',
                    'partialEndpointOutage',
                    'transportFailure',
                    'controlDataPathIsolated',
                    'controlEndpointSetTested',
                    'staticRoute',
                    'staticPreservation',
                    'entireHostStatus',
                    'entireHostStaticInvalidation',
                    'staticRouteMessage',
                ) as $field) {
                    if (array_key_exists($field, $diagnostic)) {
                        $capability[$field] = $diagnostic[$field];
                    }
                }
            }
            $capability['configuredEndpointCount'] = absint($registry['configuredEndpointCount'] ?? 0);
            $capability['successfulEndpointCount'] = $successful;
            $capability['failedEndpointCount'] = max(0, $capability['configuredEndpointCount'] - $successful);
            $capability['proofExpiresAt'] = absint($registry['proofExpiresAtByCapability']['topology'] ?? 0);
            $capability['endpointRegistry'] = $registry;
            return $capability;
        }

        $static_route = $diagnostic_current
            ? sanitize_key((string) ($diagnostic['staticRoute'] ?? 'through-varnish'))
            : 'through-varnish';
        $static_preservation = $diagnostic_current
            ? sanitize_key((string) ($diagnostic['staticPreservation'] ?? ($html_verified ? 'verified' : 'not-tested')))
            : ($html_verified ? 'verified' : 'not-tested');
        $entire_host_status = $diagnostic_current
            ? sanitize_key((string) ($diagnostic['entireHostStatus'] ?? ($host_verified ? 'verified' : 'not-tested')))
            : ($host_verified ? 'verified' : 'not-tested');
        $entire_host_static = $diagnostic_current
            ? sanitize_key((string) ($diagnostic['entireHostStaticInvalidation'] ?? ($host_verified ? 'verified' : 'not-tested')))
            : ($host_verified ? 'verified' : 'not-tested');
        $diagnostic_tested_at = $diagnostic_current ? absint($diagnostic['testedAt'] ?? 0) : 0;
        $diagnostic_html_tested_at = $diagnostic_current ? absint($diagnostic['htmlTestedAt'] ?? 0) : 0;
        $diagnostic_host_tested_at = $diagnostic_current ? absint($diagnostic['hostTestedAt'] ?? 0) : 0;
        $status = $diagnostic_current && '' !== (string) ($diagnostic['status'] ?? '')
            ? sanitize_key((string) $diagnostic['status'])
            : ($host_verified ? 'entire-host-verified' : 'html-verified');
        $message = $diagnostic_current && '' !== (string) ($diagnostic['message'] ?? '')
            ? self::sanitize_varnish_string((string) $diagnostic['message'])
            : ($host_verified
                ? self::maybe_translate('Every configured Varnish endpoint has current entire-host invalidation proof.')
                : self::maybe_translate('Every configured Varnish endpoint has current HTML-only invalidation proof.'));

        return array(
            'supported' => true,
            'manualSupported' => true,
            'topologyVerified' => true,
            'transportVerified' => true,
            'partialEndpointOutage' => false,
            'transportFailure' => false,
            'endpointBehaviorVerified' => true,
            'exactInvalidationVerified' => !empty($registry['exactInvalidationVerified']),
            'htmlInvalidationVerified' => $html_verified,
            'entireHostVerified' => $host_verified,
            'staticOpaqueStable' => $diagnostic_current
                ? !empty($diagnostic['staticOpaqueStable'])
                : $html_verified,
            'controlDataPathIsolated' => 'http' === self::sanitize_varnish_mode($settings['mode'] ?? 'http'),
            'controlEndpointSetTested' => 'admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http'),
            'transportMode' => sanitize_key((string) ($settings['mode'] ?? 'http')),
            'transportMethod' => self::sanitize_varnish_string((string) ($settings['method'] ?? 'BAN')),
            'configuredEndpointCount' => absint($registry['configuredEndpointCount'] ?? 0),
            'successfulEndpointCount' => $successful,
            'failedEndpointCount' => max(0, absint($registry['configuredEndpointCount'] ?? 0) - $successful),
            'staticRoute' => $static_route,
            'staticPreservation' => $static_preservation,
            'entireHostStatus' => $entire_host_status,
            'entireHostStaticInvalidation' => $entire_host_static,
            'staticRouteMessage' => $diagnostic_current
                ? self::sanitize_varnish_string((string) ($diagnostic['staticRouteMessage'] ?? ''))
                : '',
            'status' => $status,
            'message' => $message,
            'testedAt' => max($html_tested_at, $host_tested_at, $diagnostic_tested_at),
            'htmlTestedAt' => max($html_tested_at, $diagnostic_html_tested_at),
            'hostTestedAt' => max($host_tested_at, $diagnostic_host_tested_at),
            'proofExpiresAt' => absint($registry['proofExpiresAtByCapability']['topology'] ?? 0),
            'endpointRegistry' => $registry,
        );
    }

    /**
     * Build a BAN expression that targets cached HTML objects for one host.
     *
     * @param string $host Site host.
     * @return string
     */
    private static function build_varnish_html_host_ban_expression($host)
    {
        $host = self::escape_varnish_vcl_string((string) $host);
        if ('' === $host) {
            return '';
        }

        return 'req.http.host == "' . $host . '" && obj.http.Content-Type ~ "^text/html"';
    }

    /**
     * Resolve the current WordPress host used by Varnish expressions.
     *
     * @return string
     */
    private static function get_varnish_current_site_host()
    {
        $parsed = wp_parse_url(home_url('/'));

        return is_array($parsed) && !empty($parsed['host']) ? strtolower((string) $parsed['host']) : '';
    }


    /**
     * Resolve the invalidation contracts available to the configured transport.
     * Every exact invalidation transport requires a current WordPress-served
     * canary proof. Broader site scopes require an independent topology proof;
     * admin socket reachability alone is not behavior evidence.
     *
     * @param array $settings Normalized Varnish settings.
     * @return array
     */
    private static function get_varnish_flush_method_capabilities(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $method = 'admin' === $mode
            ? 'BAN'
            : (('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'PURGE' : 'BAN');
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        $endpoint_count = count((array) ($settings['servers'] ?? array()));
        $secret_ready = 'admin' !== $mode || !empty($settings['secretConfigured']);
        $transport_available = !empty($support['available'])
            && !empty($settings['enabled'])
            && $endpoint_count > 0
            && $secret_ready;
        $topology = self::get_varnish_html_flush_capability();
        $admin_contract = 'admin' === $mode;
        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $effective_registry = is_array($registry['effective'] ?? null) ? $registry['effective'] : array();
        $html_contract_supported = !empty($topology['topologyVerified'])
            && !empty($topology['htmlInvalidationVerified']);
        $host_contract_supported = !empty($topology['topologyVerified'])
            && !empty($topology['entireHostVerified']);
        $html_runtime_verified = $admin_contract
            ? !empty($effective_registry['htmlFlush'])
            : (!empty($topology['topologyVerified']) && !empty($topology['htmlInvalidationVerified']));
        $host_runtime_verified = $admin_contract
            ? !empty($effective_registry['hostFlush'])
            : (!empty($topology['topologyVerified']) && !empty($topology['entireHostVerified']));
        $html_supported = $transport_available && $html_runtime_verified;
        $host_supported = $transport_available && $host_runtime_verified;
        $soft_capability = ('http' === $mode && method_exists(static::class, 'get_varnish_soft_purge_capability'))
            ? self::get_varnish_soft_purge_capability($settings)
            : array('supported' => false, 'status' => 'http-only');
        $exact_capability = method_exists(static::class, 'get_varnish_exact_invalidation_capability')
            ? self::get_varnish_exact_invalidation_capability($settings)
            : array(
                'supported' => false,
                'verified' => false,
                'status' => $admin_contract ? 'admin-contract-unverified' : 'test-required',
                'message' => '',
                'testedAt' => 0,
                'proofExpiresAt' => 0,
            );

        $contract_all = !empty($registry['contractAllEndpoints']);

        if ($admin_contract) {
            $contract = 'admin-ban';
            $message = self::maybe_translate('Admin BAN transport is configured. Exact URL invalidation requires a successful isolated canary, while HTML-only and entire-host scopes require independent topology proof.');
        } elseif ($contract_all) {
            $contract = 'ultracache-vcl-v2';
            $message = self::maybe_translate('Every configured endpoint exposes the authenticated UltraCache VCL v2 contract for exact PURGE and object-side BAN scopes.');
        } elseif ('BAN' === $method) {
            $contract = 'http-ban';
            $message = self::maybe_translate('Generic HTTP BAN is treated as an exact-URL transport only until the configured receiver proves broader HTML-only or entire-host behavior.');
        } else {
            $contract = 'http-purge';
            $message = self::maybe_translate('Generic HTTP PURGE exposes only an exact-URL contract. PURGE / is not treated as an entire-host flush unless a separate behavior test proves that scope.');
        }

        if (!$transport_available) {
            $message .= ' ' . self::maybe_translate('Complete the Varnish transport configuration before running invalidation.');
        } elseif (!$html_supported && !$host_supported) {
            $message .= ' ' . self::maybe_translate('Site-wide flush actions remain unavailable until the requested scope is independently behavior-verified.');
        }
        if (empty($exact_capability['verified']) && !empty($exact_capability['message'])) {
            $message .= ' ' . (string) $exact_capability['message'];
        }

        return array(
            'transportAvailable' => $transport_available,
            'exactInvalidationSupported' => !empty($exact_capability['verified']),
            'exactInvalidationVerified' => !empty($exact_capability['verified']),
            'exactInvalidationStatus' => sanitize_key((string) ($exact_capability['status'] ?? 'test-required')),
            'exactInvalidationTestedAt' => absint($exact_capability['testedAt'] ?? 0),
            'exactInvalidationProofExpiresAt' => absint($exact_capability['proofExpiresAt'] ?? 0),
            'htmlInvalidationSupported' => $html_supported,
            'hostInvalidationSupported' => $host_supported,
            'siteWideInvalidationSupported' => $html_supported || $host_supported,
            'exactInvalidationContractSupported' => $transport_available,
            'htmlInvalidationContractSupported' => $html_contract_supported,
            'hostInvalidationContractSupported' => $host_contract_supported,
            'softPurgeSupported' => !empty($soft_capability['supported']),
            'mode' => $mode,
            'method' => $method,
            'contract' => $contract,
            'endpointCount' => $endpoint_count,
            'status' => !$transport_available
                ? 'configuration-incomplete'
                : (($html_supported || $host_supported)
                    ? 'site-scopes-available'
                    : (!empty($exact_capability['verified']) ? 'exact-url-verified' : 'exact-url-unverified')),
            'message' => self::sanitize_varnish_string($message),
        );
    }

    /**
     * Resolve configured and effective site-wide flush scopes.
     *
     * @param string $requested_scope Requested scope or configured.
     * @return array
     */
    private static function resolve_varnish_flush_scope($requested_scope = 'configured')
    {
        $settings = self::get_varnish_cli_settings();
        $configured = self::sanitize_varnish_flush_scope($settings['flushScope'] ?? 'auto');
        $requested = sanitize_key((string) $requested_scope);
        if (in_array($requested, array('', 'configured', 'site'), true)) {
            $requested = $configured;
        } elseif (in_array($requested, array('entire-host', 'all', 'all-host'), true)) {
            $requested = 'host';
        } else {
            $requested = self::sanitize_varnish_flush_scope($requested);
        }

        $method_capability = self::get_varnish_flush_method_capabilities($settings);
        $topology_diagnostic = self::get_varnish_html_flush_capability();
        $effective = 'unsupported';
        $fallback = false;
        $fallback_reason = '';

        if ('html' === $requested) {
            if (!empty($method_capability['htmlInvalidationSupported'])) {
                $effective = 'html';
            } else {
                $fallback_reason = self::maybe_translate('HTML-only Varnish invalidation is not behavior-verified for the configured HTTP receiver.');
            }
        } elseif ('host' === $requested) {
            if (!empty($method_capability['hostInvalidationSupported'])) {
                $effective = 'host';
            } else {
                $fallback_reason = self::maybe_translate('Entire-host Varnish invalidation is not behavior-verified for the configured HTTP receiver.');
            }
        } else {
            if (!empty($method_capability['htmlInvalidationSupported'])) {
                $effective = 'html';
            } elseif (!empty($method_capability['hostInvalidationSupported'])) {
                $effective = 'host';
            } else {
                $fallback_reason = self::maybe_translate('No site-wide Varnish invalidation scope has been verified. Exact URL invalidation remains available when its canary test succeeds.');
            }
        }

        $capability = array_merge(
            $topology_diagnostic,
            array(
                'supported' => !empty($method_capability['htmlInvalidationSupported']),
                'manualSupported' => !empty($method_capability['htmlInvalidationSupported']),
                'contractBased' => true,
            )
        );

        return array(
            'configured' => $configured,
            'requested' => $requested,
            'effective' => $effective,
            'fallback' => $fallback,
            'fallbackReason' => $fallback_reason,
            'supported' => 'unsupported' !== $effective,
            'htmlCapability' => $capability,
            'methodCapability' => $method_capability,
            'topologyDiagnostic' => $topology_diagnostic,
        );
    }

    /**
     * Expose the configured and effective scope to dashboard diagnostics.
     *
     * @return array
     */
    public static function get_varnish_flush_scope_status()
    {
        $status = self::resolve_varnish_flush_scope('configured');
        $runtime_plan = self::plan_varnish_runtime_operation('site-flush', array(
            'requestedScope' => 'configured',
        ));
        $status['runtimePlan'] = self::sanitize_varnish_runtime_plan($runtime_plan);
        $status['actionAvailable'] = !empty($runtime_plan['canExecute']);
        $status['runtimeStrategy'] = sanitize_key((string) ($runtime_plan['selectedStrategy'] ?? 'none'));
        $status['runtimePlannedOutcome'] = sanitize_key((string) ($runtime_plan['plannedOutcome'] ?? 'unsupported'));
        $status['runtimeDegraded'] = 'degraded' === (string) ($runtime_plan['plannedOutcome'] ?? '');
        $status['runtimeFallback'] = !empty($runtime_plan['usingFallback']);

        return $status;
    }

    /**
     * Execute one site-wide Varnish flush.
     *
     * @param string $requested_scope Requested scope.
     * @return array
     */
    private static function execute_varnish_site_flush($requested_scope = 'configured', array $runtime_plan = array(), array $context = array())
    {
        $scope = self::resolve_varnish_flush_scope($requested_scope);
        $probe_strategy = sanitize_key((string) ($context['probeStrategy'] ?? ''));
        $probe_scope = sanitize_key((string) ($context['probeScope'] ?? ''));
        $probe_endpoints = (array) ($context['probeEndpoints'] ?? array());
        $probe_urls = (array) ($context['probeUrls'] ?? array());
        $probe_method = strtoupper(sanitize_key((string) ($context['probeMethod'] ?? '')));
        if (!in_array($probe_method, array('PURGE', 'BAN'), true)) {
            $probe_method = '';
        }
        $probe_authorized = !empty($runtime_plan['capabilityProbeAuthorized'])
            && in_array($probe_scope, array('html', 'host'), true)
            && $probe_strategy === ('html' === $probe_scope ? 'html-flush' : 'host-flush')
            && sanitize_key((string) ($runtime_plan['selectedStrategy'] ?? '')) === $probe_strategy
            && sanitize_key((string) ($runtime_plan['directScope'] ?? '')) === $probe_scope
            && method_exists(static::class, 'is_varnish_capability_probe_authorized')
            && self::is_varnish_capability_probe_authorized('site-flush', array(
                'strategy' => $probe_strategy,
                'requestedScope' => $probe_scope,
                'endpoints' => $probe_endpoints,
                'urls' => $probe_urls,
            ));
        if ($probe_authorized) {
            $scope['requested'] = $probe_scope;
            $scope['effective'] = $probe_scope;
            $scope['fallback'] = false;
            $scope['fallbackReason'] = '';
            $scope['supported'] = true;
        }
        $effective = (string) $scope['effective'];
        if ('unsupported' === $effective) {
            $result = array(
                'success' => false,
                'partial' => false,
                'skipped' => true,
                'unsupported' => true,
                'message' => self::sanitize_varnish_string((string) ($scope['fallbackReason'] ?? self::maybe_translate('No site-wide Varnish invalidation scope has been verified.'))),
                'time' => time(),
                'operationType' => 'site-flush',
                'configuredScope' => (string) $scope['configured'],
                'requestedScope' => (string) $scope['requested'],
                'effectiveScope' => 'unsupported',
                'scopeFallback' => false,
                'scopeFallbackReason' => '',
                'htmlScopeSupported' => !empty($scope['methodCapability']['htmlInvalidationSupported']),
                'hostScopeSupported' => !empty($scope['methodCapability']['hostInvalidationSupported']),
                'htmlScopeVerified' => !empty($scope['topologyDiagnostic']['htmlInvalidationVerified']),
                'hostScopeVerified' => !empty($scope['topologyDiagnostic']['entireHostVerified']),
                'topologyVerified' => !empty($scope['topologyDiagnostic']['topologyVerified']),
                'exactInvalidationAvailable' => !empty($scope['methodCapability']['exactInvalidationSupported']),
                'flushContract' => sanitize_key((string) ($scope['methodCapability']['contract'] ?? '')),
                'configuredEndpointCount' => absint($scope['methodCapability']['endpointCount'] ?? 0),
                'successfulEndpointRequestCount' => 0,
                'failedEndpointRequestCount' => 0,
                'details' => array(),
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        $host = self::get_varnish_current_site_host();
        if ('' === $host) {
            $result = array(
                'success' => false,
                'partial' => false,
                'message' => self::maybe_translate('Could not determine site host for Varnish.'),
                'time' => time(),
                'operationType' => 'site-flush',
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        $settings = self::get_varnish_cli_settings();
        $strategy_status = self::get_varnish_invalidation_strategy_status($settings);
        $site_flush_method = 'admin' === self::sanitize_varnish_mode($settings['mode'] ?? 'http')
            ? 'BAN'
            : ($probe_authorized && '' !== $probe_method
                ? $probe_method
                : ('ban' === sanitize_key((string) ($strategy_status['verifiedHardStrategy'] ?? '')) ? 'BAN' : 'PURGE'));
        $expr = 'html' === $effective
            ? self::build_varnish_html_host_ban_expression($host)
            : self::build_varnish_ban_expression($host, '/', true);
        $label = 'html' === $effective ? 'html-host' : 'entire-host';
        $result = self::varnish_send_expr_to_all(
            $expr,
            $label,
            $probe_authorized ? $probe_endpoints : array(),
            $probe_authorized,
            $site_flush_method
        );

        $result['operationType'] = 'site-flush';
        $result['configuredScope'] = (string) $scope['configured'];
        $result['requestedScope'] = (string) $scope['requested'];
        $result['effectiveScope'] = $effective;
        $result['scopeFallback'] = !empty($scope['fallback']);
        $result['scopeFallbackReason'] = self::sanitize_varnish_string((string) $scope['fallbackReason']);
        $result['htmlScopeSupported'] = !empty($scope['methodCapability']['htmlInvalidationSupported']);
        $result['hostScopeSupported'] = !empty($scope['methodCapability']['hostInvalidationSupported']);
        $result['htmlScopeVerified'] = !empty($scope['topologyDiagnostic']['htmlInvalidationVerified']);
        $result['hostScopeVerified'] = !empty($scope['topologyDiagnostic']['entireHostVerified']);
        $result['topologyVerified'] = !empty($scope['topologyDiagnostic']['topologyVerified']);
        $result['flushContract'] = sanitize_key((string) ($scope['methodCapability']['contract'] ?? ''));
        $result['capabilityProbeAuthorized'] = $probe_authorized;
        $result['verifiedHardMethod'] = $site_flush_method;

        $successful_endpoints = absint($result['successfulEndpointRequestCount'] ?? 0);
        $failed_endpoints = absint($result['failedEndpointRequestCount'] ?? 0);
        if (!empty($result['success'])) {
            $result['message'] = 'html' === $effective
                ? self::maybe_translate_sprintf('Varnish HTML-page flush succeeded on %d endpoint(s).', $successful_endpoints)
                : self::maybe_translate_sprintf('Varnish entire-host flush succeeded on %d endpoint(s).', $successful_endpoints);
        } elseif (!empty($result['partial'])) {
            $result['message'] = 'html' === $effective
                ? self::maybe_translate_sprintf('Varnish HTML-page flush succeeded on %1$d endpoint(s) and failed on %2$d endpoint(s).', $successful_endpoints, $failed_endpoints)
                : self::maybe_translate_sprintf('Varnish entire-host flush succeeded on %1$d endpoint(s) and failed on %2$d endpoint(s).', $successful_endpoints, $failed_endpoints);
        }

        self::set_varnish_last_result($result);
        return $result;
    }

    /**
     * Flush the configured site-wide scope.
     *
     * @return array
     */
    public static function varnish_flush_all_current_host()
    {
        return self::execute_varnish_site_runtime_plan('configured');
    }

    /**
     * Flush every cached object for the current host.
     *
     * @return array
     */
    public static function varnish_flush_entire_current_host()
    {
        return self::execute_varnish_site_runtime_plan('host');
    }

}
