<?php
/**
 * Varnish discovery action facade for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Discovery_Action_Trait
{
    /**
     * Persist one detected Varnish connection patch.
     *
     * @param array $configuration Detected Varnish settings patch.
     * @return true|WP_Error
     */
    private static function persist_verified_varnish_discovery_configuration(array $configuration)
    {
        $current = self::get_dashboard_settings();
        $next = self::sanitize_dashboard_settings(array_merge($current, $configuration));
        $validation = self::validate_varnish_settings($next);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $next['redisPassword'] = '';
        $next['varnishCliKey'] = '';
        $updated = update_option(ULTRACACHE_SETTINGS_KEY, $next);
        if (!$updated) {
            $stored = get_option(ULTRACACHE_SETTINGS_KEY, array());
            if (!is_array($stored)) {
                return new WP_Error(
                    'ultracache_varnish_discovery_save_failed',
                    __('The detected Varnish configuration could not be stored.', 'ultracache')
                );
            }

            foreach ($configuration as $key => $value) {
                if (($stored[$key] ?? null) !== ($next[$key] ?? null)) {
                    return new WP_Error(
                        'ultracache_varnish_discovery_save_failed',
                        __('The detected Varnish configuration could not be stored.', 'ultracache')
                    );
                }
            }
        }

        self::reset_settings_cache();
        return true;
    }

    /**
     * Run lightweight Varnish configuration discovery.
     *
     * @param bool $verify_candidate Verify a no-secret HTTP candidate with the existing isolated canary flow.
     * @param bool $persist_verified Persist a verified candidate directly; callers may disable this to save through the normal settings flow.
     * @return array
     */
    public static function run_varnish_discovery($verify_candidate = false, $persist_verified = true)
    {
        $runner_class = 'Ultra_Cache_WP_Varnish_Discovery_Runner';
        if (!class_exists($runner_class, false)) {
            require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-discovery-runner.php');
        }

        if (!is_callable(array($runner_class, 'run'))) {
            return array(
                'success' => false,
                'verified' => false,
                'saved' => false,
                'status' => 'runner-unavailable',
                'message' => __('Varnish discovery runner is unavailable.', 'ultracache'),
            );
        }

        $result = call_user_func(array($runner_class, 'run'));
        if (!is_array($result)) {
            return array(
                'success' => false,
                'verified' => false,
                'saved' => false,
                'status' => 'invalid-result',
                'message' => __('Varnish discovery returned an invalid result.', 'ultracache'),
            );
        }

        $configuration = isset($result['configuration']) && is_array($result['configuration'])
            ? self::sanitize_varnish_result($result['configuration'])
            : array();

        if ($verify_candidate
            && empty($result['verified'])
            && empty($result['requiresToken'])
            && !empty($configuration)
            && 'http' === self::sanitize_varnish_mode($configuration['varnishCliMode'] ?? 'http')
        ) {
            $candidate_settings = self::get_varnish_cli_settings();
            $candidate_servers_raw = self::sanitize_varnish_servers_string(
                $configuration['varnishCliServers'] ?? '',
                'http'
            );
            $candidate_servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $candidate_servers_raw))));
            $candidate_method = 'PURGE' === strtoupper(trim((string) ($configuration['varnishCliMethod'] ?? 'BAN')))
                ? 'PURGE'
                : 'BAN';

            if (!empty($candidate_servers)) {
                $candidate_settings['enabled'] = true;
                $candidate_settings['connectionConfigured'] = true;
                $candidate_settings['connectionSource'] = 'discovery-candidate';
                $candidate_settings['mode'] = 'http';
                $candidate_settings['configuredMode'] = 'http';
                $candidate_settings['servers_raw'] = $candidate_servers_raw;
                $candidate_settings['servers'] = $candidate_servers;
                $candidate_settings['endpointCount'] = count($candidate_servers);
                $candidate_settings['key'] = '';
                $candidate_settings['secretConfigured'] = false;
                $candidate_settings['timeout'] = max(1, min(15, absint($configuration['varnishCliTimeoutSeconds'] ?? 2)));
                $candidate_settings['method'] = $candidate_method;
                $candidate_settings['invalidationStrategy'] = self::sanitize_varnish_invalidation_strategy(
                    $configuration['varnishInvalidationStrategy'] ?? strtolower($candidate_method)
                );
                $candidate_settings['flushScope'] = self::sanitize_varnish_flush_scope(
                    $configuration['varnishFlushScope'] ?? 'auto'
                );
                $candidate_settings['effectiveMethod'] = $candidate_method;
                $candidate_settings['adminModeUsed'] = false;
                $candidate_settings['httpEndpointModeUsed'] = true;

                self::set_varnish_cli_settings_diagnostic_override($candidate_settings);
                try {
                    $candidate_verification = self::varnish_test_behavior($candidate_settings);
                } finally {
                    self::clear_varnish_cli_settings_diagnostic_override();
                }
                $candidate_verified = is_array($candidate_verification)
                    && !empty($candidate_verification['verified'])
                    && !empty($candidate_verification['exactInvalidationVerified']);
                $result['candidateVerification'] = is_array($candidate_verification)
                    ? array(
                        'success' => !empty($candidate_verification['success']),
                        'verified' => !empty($candidate_verification['verified']),
                        'exactInvalidationVerified' => !empty($candidate_verification['exactInvalidationVerified']),
                        'status' => sanitize_key((string) ($candidate_verification['status'] ?? '')),
                        'message' => self::sanitize_varnish_string((string) ($candidate_verification['message'] ?? '')),
                        'endpointCount' => absint($candidate_verification['endpointCount'] ?? 0),
                        'verifiedEndpointCount' => absint($candidate_verification['verifiedEndpointCount'] ?? 0),
                    )
                    : array();

                if ($candidate_verified) {
                    $result['verified'] = true;
                    $result['status'] = 'candidate-verified';
                    $result['message'] = __('Varnish discovery found an HTTP endpoint and verified exact invalidation on the isolated canary.', 'ultracache');
                } else {
                    $result['verified'] = false;
                    $result['status'] = 'candidate-unverified';
                    $result['message'] = is_array($candidate_verification) && !empty($candidate_verification['message'])
                        ? self::sanitize_varnish_string((string) $candidate_verification['message'])
                        : __('A Varnish HTTP endpoint accepted the control request, but exact invalidation could not be verified.', 'ultracache');
                }
            }
        }

        if (!empty($result['verified']) && !empty($configuration)) {
            if (!$persist_verified) {
                return array(
                    'success' => true,
                    'verified' => true,
                    'saved' => false,
                    'status' => sanitize_key((string) ($result['status'] ?? 'candidate-verified')),
                    'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('Varnish was detected and verified automatically.', 'ultracache'))),
                    'configuration' => $configuration,
                    'candidateVerification' => isset($result['candidateVerification']) && is_array($result['candidateVerification'])
                        ? self::sanitize_varnish_result($result['candidateVerification'])
                        : array(),
                );
            }

            $save_result = self::persist_verified_varnish_discovery_configuration($configuration);
            if (is_wp_error($save_result)) {
                return array(
                    'success' => false,
                    'verified' => true,
                    'saved' => false,
                    'status' => 'save-failed',
                    'message' => self::sanitize_varnish_string($save_result->get_error_message()),
                    'configuration' => $configuration,
                );
            }

            return array(
                'success' => true,
                'verified' => true,
                'saved' => true,
                'status' => 'working',
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('Varnish was detected and configured automatically.', 'ultracache'))),
                'configuration' => $configuration,
                'candidateVerification' => isset($result['candidateVerification']) && is_array($result['candidateVerification'])
                    ? self::sanitize_varnish_result($result['candidateVerification'])
                    : array(),
            );
        }

        if (!empty($configuration)) {
            return array(
                'success' => !empty($result['success']),
                'verified' => false,
                'saved' => false,
                'status' => sanitize_key((string) ($result['status'] ?? 'candidate-found')),
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('A probable Varnish endpoint was detected.', 'ultracache'))),
                'configuration' => $configuration,
                'requiresToken' => !empty($result['requiresToken']),
                'candidateVerification' => isset($result['candidateVerification']) && is_array($result['candidateVerification'])
                    ? self::sanitize_varnish_result($result['candidateVerification'])
                    : array(),
            );
        }

        return array(
            'success' => false,
            'verified' => false,
            'saved' => false,
            'status' => sanitize_key((string) ($result['status'] ?? 'not-found')),
            'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('No working Varnish configuration was detected.', 'ultracache'))),
        );
    }
}
