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
        $capability['transportVerified'] = !empty($capability['transportVerified']);
        $capability['partialEndpointOutage'] = !empty($capability['partialEndpointOutage']);
        $capability['transportFailure'] = !empty($capability['transportFailure']);
        $capability['endpointBehaviorVerified'] = !empty($capability['endpointBehaviorVerified']);
        $capability['exactInvalidationVerified'] = !empty($capability['exactInvalidationVerified']);
        $capability['htmlInvalidationVerified'] = !empty($capability['htmlInvalidationVerified']);
        $capability['entireHostVerified'] = !empty($capability['entireHostVerified']);
        $capability['staticOpaqueStable'] = !empty($capability['staticOpaqueStable']);
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

        set_transient('ultracache_varnish_html_flush_capability_v1', self::sanitize_varnish_result($capability), WEEK_IN_SECONDS);
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
        );
    }

    /**
     * Read the flush-topology capability for the current endpoint configuration.
     *
     * @return array
     */
    protected static function get_varnish_html_flush_capability()
    {
        $value = get_transient('ultracache_varnish_html_flush_capability_v1');
        if (!is_array($value)) {
            return self::get_unverified_varnish_flush_capability(
                'untested',
                self::maybe_translate('Run the optional flush-topology diagnostic to inspect exact URL, HTML-only, entire-host, endpoint, and public static-route behavior.')
            );
        }

        if (!self::varnish_capability_contracts_match($value, array('html-invalidation'))) {
            $capability = self::get_unverified_varnish_flush_capability(
                'configuration-changed',
                self::maybe_translate('The Varnish transport or HTML invalidation contract changed. Runtime scope selection continues to follow the configured method contract.'),
                absint($value['testedAt'] ?? 0)
            );
            $capability['staticRoute'] = 'configuration-changed';
            return $capability;
        }

        $supported = !empty($value['supported']) && !empty($value['topologyVerified']);

        return array(
            'supported' => $supported,
            'manualSupported' => $supported,
            'topologyVerified' => !empty($value['topologyVerified']),
            'transportVerified' => !empty($value['transportVerified']),
            'partialEndpointOutage' => !empty($value['partialEndpointOutage']),
            'transportFailure' => !empty($value['transportFailure']),
            'endpointBehaviorVerified' => !empty($value['endpointBehaviorVerified']),
            'exactInvalidationVerified' => !empty($value['exactInvalidationVerified']),
            'htmlInvalidationVerified' => !empty($value['htmlInvalidationVerified']),
            'entireHostVerified' => !empty($value['entireHostVerified']),
            'staticOpaqueStable' => !empty($value['staticOpaqueStable']),
            'transportMode' => sanitize_key((string) ($value['transportMode'] ?? '')),
            'transportMethod' => self::sanitize_varnish_string((string) ($value['transportMethod'] ?? '')),
            'configuredEndpointCount' => absint($value['configuredEndpointCount'] ?? 0),
            'successfulEndpointCount' => absint($value['successfulEndpointCount'] ?? 0),
            'failedEndpointCount' => absint($value['failedEndpointCount'] ?? 0),
            'staticRoute' => sanitize_key((string) ($value['staticRoute'] ?? 'inconclusive')),
            'staticPreservation' => sanitize_key((string) ($value['staticPreservation'] ?? 'not-tested')),
            'entireHostStatus' => sanitize_key((string) ($value['entireHostStatus'] ?? 'not-tested')),
            'entireHostStaticInvalidation' => sanitize_key((string) ($value['entireHostStaticInvalidation'] ?? 'not-tested')),
            'staticRouteMessage' => self::sanitize_varnish_string((string) ($value['staticRouteMessage'] ?? '')),
            'status' => sanitize_key((string) ($value['status'] ?? 'inconclusive')),
            'message' => self::sanitize_varnish_string((string) ($value['message'] ?? '')),
            'testedAt' => absint($value['testedAt'] ?? 0),
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
     * Resolve the portable invalidation contracts exposed by the configured
     * transport. These capabilities describe what UltraCache can request from
     * the selected method; they do not depend on topology probes or cache
     * response headers.
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
        $html_contract_supported = 'admin' === $mode || 'BAN' === $method;
        $html_supported = $transport_available && $html_contract_supported;
        $soft_capability = ('http' === $mode && method_exists(static::class, 'get_varnish_soft_purge_capability'))
            ? self::get_varnish_soft_purge_capability($settings)
            : array('supported' => false, 'status' => 'http-only');

        if ('admin' === $mode) {
            $contract = 'admin-ban';
            $message = self::maybe_translate('Admin BAN uses bounded expressions for exact URL, HTML-only, and entire-host invalidation.');
        } elseif ('BAN' === $method) {
            $contract = 'http-ban';
            $message = self::maybe_translate('HTTP BAN uses the configured BAN-expression contract for exact URL, HTML-only, and entire-host invalidation.');
        } else {
            $contract = 'http-purge';
            $message = self::maybe_translate('HTTP PURGE uses exact URL invalidation and entire-host site flushes; it has no portable HTML-only site-flush contract.');
        }

        if (!$transport_available) {
            $message .= ' ' . self::maybe_translate('Complete the Varnish transport configuration before running invalidation.');
        }

        return array(
            'transportAvailable' => $transport_available,
            'exactInvalidationSupported' => $transport_available,
            'htmlInvalidationSupported' => $html_supported,
            'hostInvalidationSupported' => $transport_available,
            'exactInvalidationContractSupported' => true,
            'htmlInvalidationContractSupported' => $html_contract_supported,
            'hostInvalidationContractSupported' => true,
            'softPurgeSupported' => !empty($soft_capability['supported']),
            'mode' => $mode,
            'method' => $method,
            'contract' => $contract,
            'endpointCount' => $endpoint_count,
            'status' => $transport_available ? 'ready' : 'configuration-incomplete',
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
        $effective = 'host';
        $fallback = false;
        $fallback_reason = '';

        if ('html' === $requested || 'auto' === $requested) {
            if (!empty($method_capability['htmlInvalidationContractSupported'])) {
                $effective = 'html';
            } elseif ('html' === $requested) {
                $fallback = true;
                $fallback_reason = self::maybe_translate('The configured Varnish method does not expose a portable HTML-only invalidation contract, so UltraCache used an entire-host flush.');
            }
        }

        $capability = array_merge(
            $topology_diagnostic,
            array(
                'supported' => !empty($method_capability['htmlInvalidationSupported']),
                'manualSupported' => !empty($method_capability['htmlInvalidationContractSupported']),
                'contractBased' => true,
            )
        );

        return array(
            'configured' => $configured,
            'requested' => $requested,
            'effective' => $effective,
            'fallback' => $fallback,
            'fallbackReason' => $fallback_reason,
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
        return self::resolve_varnish_flush_scope('configured');
    }

    /**
     * Execute one site-wide Varnish flush.
     *
     * @param string $requested_scope Requested scope.
     * @return array
     */
    private static function execute_varnish_site_flush($requested_scope = 'configured')
    {
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

        $scope = self::resolve_varnish_flush_scope($requested_scope);
        $effective = (string) $scope['effective'];
        $expr = 'html' === $effective
            ? self::build_varnish_html_host_ban_expression($host)
            : self::build_varnish_ban_expression($host, '/', true);
        $label = 'html' === $effective ? 'html-host' : 'entire-host';
        $result = self::varnish_send_expr_to_all($expr, $label);

        $result['operationType'] = 'site-flush';
        $result['configuredScope'] = (string) $scope['configured'];
        $result['requestedScope'] = (string) $scope['requested'];
        $result['effectiveScope'] = $effective;
        $result['scopeFallback'] = !empty($scope['fallback']);
        $result['scopeFallbackReason'] = self::sanitize_varnish_string((string) $scope['fallbackReason']);
        $result['htmlScopeSupported'] = !empty($scope['methodCapability']['htmlInvalidationSupported']);
        $result['htmlScopeVerified'] = !empty($scope['topologyDiagnostic']['htmlInvalidationVerified']);
        $result['topologyVerified'] = !empty($scope['topologyDiagnostic']['topologyVerified']);
        $result['flushContract'] = sanitize_key((string) ($scope['methodCapability']['contract'] ?? ''));

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

        if (!empty($scope['fallback'])) {
            $result['message'] .= ' ' . (string) $scope['fallbackReason'];
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
        return self::execute_varnish_site_flush('configured');
    }

    /**
     * Flush every cached object for the current host.
     *
     * @return array
     */
    public static function varnish_flush_entire_current_host()
    {
        return self::execute_varnish_site_flush('host');
    }

    /**
     * Send the HTML-only expression during the bounded topology test.
     *
     * @return array
     */
    protected static function varnish_flush_html_current_host_for_test()
    {
        $capability = self::get_varnish_flush_method_capabilities();
        if (empty($capability['htmlInvalidationSupported'])) {
            return array(
                'success' => false,
                'partial' => false,
                'skipped' => true,
                'unsupported' => true,
                'message' => self::sanitize_varnish_string((string) ($capability['message'] ?? self::maybe_translate('HTML-only invalidation is not supported by the configured Varnish method.'))),
                'time' => time(),
                'operationType' => 'site-flush-test',
                'configuredEndpointCount' => absint($capability['endpointCount'] ?? 0),
                'successfulEndpointRequestCount' => 0,
                'failedEndpointRequestCount' => 0,
                'details' => array(),
            );
        }

        return self::execute_varnish_site_flush('html');
    }

    /**
     * Send the entire-host expression during the bounded topology test.
     *
     * @return array
     */
    protected static function varnish_flush_entire_current_host_for_test()
    {
        return self::execute_varnish_site_flush('host');
    }
}
