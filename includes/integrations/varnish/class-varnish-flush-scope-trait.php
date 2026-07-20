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
     * Return a configuration signature that does not include the Varnish secret.
     *
     * @return string
     */
    private static function get_varnish_flush_scope_configuration_signature()
    {
        $settings = self::get_varnish_cli_settings();
        $payload = array(
            'mode' => (string) ($settings['mode'] ?? 'http'),
            'method' => (string) ($settings['method'] ?? 'BAN'),
            'servers' => array_values(array_map('strval', is_array($settings['servers'] ?? null) ? $settings['servers'] : array())),
            'siteHost' => self::get_varnish_current_site_host(),
        );

        return hash('sha256', (string) wp_json_encode($payload));
    }

    /**
     * Persist the latest verified HTML-only flush capability for this endpoint configuration.
     *
     * @param array $capability Capability result.
     * @return void
     */
    private static function set_varnish_html_flush_capability(array $capability)
    {
        $capability['configurationSignature'] = self::get_varnish_flush_scope_configuration_signature();
        $capability['testedAt'] = isset($capability['testedAt']) ? absint($capability['testedAt']) : time();
        $capability['supported'] = !empty($capability['supported']);
        $capability['manualSupported'] = !empty($capability['manualSupported']);
        $capability['htmlInvalidationVerified'] = !empty($capability['htmlInvalidationVerified']);
        $capability['staticRoute'] = sanitize_key((string) ($capability['staticRoute'] ?? 'inconclusive'));
        $capability['staticPreservation'] = sanitize_key((string) ($capability['staticPreservation'] ?? 'not-tested'));
        $capability['staticRouteMessage'] = self::sanitize_varnish_string((string) ($capability['staticRouteMessage'] ?? ''));
        $capability['status'] = sanitize_key((string) ($capability['status'] ?? 'inconclusive'));
        $capability['message'] = self::sanitize_varnish_string((string) ($capability['message'] ?? ''));

        set_transient('ultracache_varnish_html_flush_capability_v1', self::sanitize_varnish_result($capability), WEEK_IN_SECONDS);
    }

    /**
     * Read the HTML-only flush capability for the current endpoint configuration.
     *
     * @return array
     */
    private static function get_varnish_html_flush_capability()
    {
        $value = get_transient('ultracache_varnish_html_flush_capability_v1');
        if (!is_array($value)) {
            return array(
                'supported' => false,
                'manualSupported' => false,
                'htmlInvalidationVerified' => false,
                'staticRoute' => 'untested',
                'staticPreservation' => 'not-tested',
                'staticRouteMessage' => '',
                'status' => 'untested',
                'message' => self::maybe_translate('Run Test Varnish to verify HTML-only invalidation and classify the public static delivery route.'),
                'testedAt' => 0,
            );
        }

        $current_signature = self::get_varnish_flush_scope_configuration_signature();
        if (empty($value['configurationSignature']) || !hash_equals((string) $value['configurationSignature'], $current_signature)) {
            return array(
                'supported' => false,
                'manualSupported' => false,
                'htmlInvalidationVerified' => false,
                'staticRoute' => 'configuration-changed',
                'staticPreservation' => 'not-tested',
                'staticRouteMessage' => '',
                'status' => 'configuration-changed',
                'message' => self::maybe_translate('The Varnish endpoint configuration changed. Run Test Varnish again before using HTML-only flushing.'),
                'testedAt' => 0,
            );
        }

        return array(
            'supported' => !empty($value['supported']),
            'manualSupported' => !empty($value['manualSupported']) || !empty($value['supported']),
            'htmlInvalidationVerified' => !empty($value['htmlInvalidationVerified']) || !empty($value['supported']),
            'staticRoute' => sanitize_key((string) ($value['staticRoute'] ?? 'legacy-verified')),
            'staticPreservation' => sanitize_key((string) ($value['staticPreservation'] ?? (!empty($value['supported']) ? 'verified' : 'not-tested'))),
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
     * Resolve configured and effective site-wide flush scopes.
     *
     * @param string $requested_scope Requested scope or configured.
     * @param bool   $allow_unverified_html Allow HTML scope during the capability test.
     * @return array
     */
    private static function resolve_varnish_flush_scope($requested_scope = 'configured', $allow_unverified_html = false)
    {
        $settings = self::get_dashboard_settings();
        $configured = self::sanitize_varnish_flush_scope($settings['varnishFlushScope'] ?? 'auto');
        $requested = sanitize_key((string) $requested_scope);
        if (in_array($requested, array('', 'configured', 'site'), true)) {
            $requested = $configured;
        } elseif (in_array($requested, array('entire-host', 'all', 'all-host'), true)) {
            $requested = 'host';
        } else {
            $requested = self::sanitize_varnish_flush_scope($requested);
        }

        $capability = self::get_varnish_html_flush_capability();
        $effective = 'host';
        $fallback = false;
        $fallback_reason = '';

        if ('html' === $requested) {
            if ($allow_unverified_html || !empty($capability['manualSupported']) || !empty($capability['supported'])) {
                $effective = 'html';
            } else {
                $fallback = true;
                $fallback_reason = self::maybe_translate('HTML-only invalidation has not been verified for the current Varnish configuration, so UltraCache used an entire-host flush.');
            }
        } elseif ('auto' === $requested && !empty($capability['supported'])) {
            $effective = 'html';
        }

        return array(
            'configured' => $configured,
            'requested' => $requested,
            'effective' => $effective,
            'fallback' => $fallback,
            'fallbackReason' => $fallback_reason,
            'htmlCapability' => $capability,
        );
    }

    /**
     * Expose the configured and effective scope to dashboard diagnostics.
     *
     * @return array
     */
    public static function get_varnish_flush_scope_status()
    {
        return self::resolve_varnish_flush_scope('configured', false);
    }

    /**
     * Execute one site-wide Varnish flush.
     *
     * @param string $requested_scope Requested scope.
     * @param bool   $allow_unverified_html Allow the capability test to send the HTML expression.
     * @return array
     */
    private static function execute_varnish_site_flush($requested_scope = 'configured', $allow_unverified_html = false)
    {
        $host = self::get_varnish_current_site_host();
        if ('' === $host) {
            $result = array(
                'success' => false,
                'message' => self::maybe_translate('Could not determine site host for Varnish.'),
                'time' => time(),
                'operationType' => 'site-flush',
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        $scope = self::resolve_varnish_flush_scope($requested_scope, $allow_unverified_html);
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
        $result['htmlScopeVerified'] = !empty($scope['htmlCapability']['supported']);
        $result['htmlScopeManualSupported'] = !empty($scope['htmlCapability']['manualSupported']);

        if (!empty($result['success'])) {
            if ('html' === $effective) {
                $result['message'] = self::maybe_translate_sprintf('Varnish HTML-page flush succeeded on %d endpoint(s).', (int) ($result['endpointCount'] ?? 0));
            } else {
                $result['message'] = self::maybe_translate_sprintf('Varnish entire-host flush succeeded on %d endpoint(s).', (int) ($result['endpointCount'] ?? 0));
            }
            if (!empty($scope['fallback'])) {
                $result['message'] .= ' ' . (string) $scope['fallbackReason'];
            }
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
        return self::execute_varnish_site_flush('configured', false);
    }

    /**
     * Flush every cached object for the current host.
     *
     * @return array
     */
    public static function varnish_flush_entire_current_host()
    {
        return self::execute_varnish_site_flush('host', false);
    }

    /**
     * Send the HTML-only expression during the bounded capability test.
     *
     * @return array
     */
    private static function varnish_flush_html_current_host_for_test()
    {
        return self::execute_varnish_site_flush('html', true);
    }
}
