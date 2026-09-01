<?php
/**
 * Minimal Varnish test action for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Test_Action_Trait
{
    /**
     * Return the non-autoload option used by the latest basic Varnish test.
     *
     * @return string
     */
    private static function get_varnish_basic_test_option_name()
    {
        return 'ultracache_varnish_diagnostic_basic_v1';
    }

    /**
     * Return the non-autoload option used by the latest HTML variant proof.
     *
     * @return string
     */
    private static function get_varnish_html_variant_capability_option_name()
    {
        return 'ultracache_varnish_html_variant_capability_v1';
    }

    /**
     * Return the UltraCache-owned state record used by the latest basic test.
     *
     * @return string
     */
    private static function get_varnish_basic_test_state_name()
    {
        return 'ultracache_state:varnish.basic_test';
    }

    /**
     * Return the UltraCache-owned state record used by HTML-variant evidence.
     *
     * @return string
     */
    private static function get_varnish_html_variant_capability_state_name()
    {
        return 'ultracache_state:varnish.html_variants';
    }

    /**
     * Read one Varnish diagnostic state and migrate a legacy option once.
     *
     * @param string $state_name  State record name.
     * @param string $option_name Legacy option name.
     * @param string $payload_key Payload key.
     * @return array<string,mixed>
     */
    private static function read_varnish_diagnostic_state($state_name, $option_name, $payload_key)
    {
        if (function_exists('ultracache_get_state_record_read_only')) {
            $record = ultracache_get_state_record_read_only($state_name);
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
            $value = is_array($payload[$payload_key] ?? null) ? $payload[$payload_key] : array();
            if (!empty($value)) {
                return self::sanitize_varnish_result($value);
            }
        }

        $legacy = get_option($option_name, array());
        if (!is_array($legacy) || empty($legacy)) {
            return array();
        }
        $legacy = self::sanitize_varnish_result($legacy);
        if (self::persist_varnish_diagnostic_state($state_name, $payload_key, $legacy)) {
            delete_option($option_name);
        }

        return $legacy;
    }

    /**
     * Persist one Varnish diagnostic in the existing UltraCache state table.
     *
     * @param string $state_name  State record name.
     * @param string $payload_key Payload key.
     * @param array  $value       Sanitized value.
     * @return bool
     */
    private static function persist_varnish_diagnostic_state($state_name, $payload_key, array $value)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }
        $payload = array(
            'schemaVersion' => 1,
            'updatedAt' => time(),
            $payload_key => $value,
        );
        $mutation = ultracache_mutate_state_record(
            $state_name,
            static function () use ($payload) {
                return $payload;
            },
            5,
            array()
        );

        return !empty($mutation['success']);
    }

    /**
     * Persist the complete HTML variant capability contract.
     *
     * @param array $capability Capability result.
     * @return array
     */
    protected static function set_varnish_html_variant_capability(array $capability)
    {
        $capability['time'] = absint($capability['time'] ?? time());
        $capability = self::bind_varnish_capability_contracts($capability, array('variant'));
        $capability = self::sanitize_varnish_result($capability);

        self::persist_varnish_diagnostic_state(
            self::get_varnish_html_variant_capability_state_name(),
            'capability',
            $capability
        );
        delete_option(self::get_varnish_html_variant_capability_option_name());

        return $capability;
    }

    /**
     * Read the current HTML variant capability contract.
     *
     * @return array
     */
    public static function get_varnish_html_variant_capability_status()
    {
        $value = self::read_varnish_diagnostic_state(
            self::get_varnish_html_variant_capability_state_name(),
            self::get_varnish_html_variant_capability_option_name(),
            'capability'
        );
        if (!is_array($value) || empty($value)) {
            $basic = self::read_varnish_diagnostic_state(
                self::get_varnish_basic_test_state_name(),
                self::get_varnish_basic_test_option_name(),
                'result'
            );
            $value = is_array($basic['htmlVariantCapability'] ?? null)
                ? self::sanitize_varnish_result($basic['htmlVariantCapability'])
                : array();
            if (!empty($value)
                && self::varnish_capability_contracts_match($value, array('variant'))) {
                self::persist_varnish_diagnostic_state(
                    self::get_varnish_html_variant_capability_state_name(),
                    'capability',
                    $value
                );
            }
        }
        if (!is_array($value) || empty($value)) {
            return array();
        }

        $value = self::sanitize_varnish_result($value);
        if (!self::varnish_capability_contracts_match($value, array('variant'))) {
            $value['configurationChanged'] = true;
            $value['supported'] = false;
            $value['status'] = 'configuration-changed';
            return $value;
        }

        // Capability proofs are invalidated by their configuration/contract
        // fingerprints, not by elapsed wall-clock time. Keep legacy expiry
        // metadata inert so older persisted PASS results remain valid.
        $value['proofExpiresAt'] = 0;
        unset($value['proofExpired']);

        return $value;
    }

    /**
     * Refresh generated delivery artifacts after a Varnish capability changes.
     *
     * @return array
     */
    private static function sync_shared_cache_runtime_after_varnish_capability_change()
    {
        if (method_exists(static::class, 'is_varnish_test_run_active') && self::is_varnish_test_run_active()) {
            return array(
                'pageCacheRuntimeSynced' => false,
                'apacheStaticRulesSynced' => false,
                'deferredUntilTestCompletes' => true,
            );
        }

        self::reset_settings_cache();
        $settings = self::get_dashboard_settings();
        $page_cache_sync = self::sync_page_cache_bootstrap(!empty($settings['pageCacheEnabled']), false);
        $apache_static_sync = self::sync_apache_static_html_delivery_rules();

        return array(
            'pageCacheRuntimeSynced' => true === $page_cache_sync,
            'apacheStaticRulesSynced' => true === $apache_static_sync,
        );
    }


    /**
     * Persist the complete latest basic Varnish test result.
     *
     * @param array $result          Basic test result.
     * @param array $tested_settings Exact normalized settings used by the test.
     * @return array
     */
    private static function store_varnish_basic_test_result(array $result, array $tested_settings)
    {
        $result['time'] = absint($result['time'] ?? time());
        if (is_array($result['htmlVariantCapability'] ?? null)) {
            $result['htmlVariantCapability'] = self::set_varnish_html_variant_capability(
                $result['htmlVariantCapability']
            );
            $result['htmlVariantsSupported'] = !empty($result['htmlVariantCapability']['supported']);
        }

        $registry = array();
        if (method_exists(static::class, 'sync_varnish_basic_test_result_to_endpoint_registry')) {
            // Synchronize from the complete result before it is persisted.
            $registry = self::sync_varnish_basic_test_result_to_endpoint_registry($result, $tested_settings);
        }
        if (!empty($result['batchBanCapability'])
            && is_array($result['batchBanCapability'])
            && method_exists(static::class, 'set_varnish_batch_ban_capability')) {
            self::set_varnish_batch_ban_capability($result['batchBanCapability']);
        }
        if (!empty($result['flushTopologyCapability'])
            && is_array($result['flushTopologyCapability'])
            && method_exists(static::class, 'set_varnish_html_flush_capability')) {
            self::set_varnish_html_flush_capability($result['flushTopologyCapability']);
        }
        if (!empty($result['softPurgeCapability'])
            && is_array($result['softPurgeCapability'])
            && method_exists(static::class, 'set_varnish_soft_purge_capability')) {
            self::set_varnish_soft_purge_capability($result['softPurgeCapability']);
        }
        if (method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')) {
            $registry = self::get_varnish_endpoint_capability_registry_status($tested_settings, $result);
        }

        $stored_result = self::sanitize_varnish_result($result);
        if (!is_array($stored_result)) {
            $stored_result = array();
        }
        if (method_exists(static::class, 'bind_varnish_capability_contracts')) {
            $stored_result = self::bind_varnish_capability_contracts(
                $stored_result,
                array('transport')
            );
        }

        if (!empty($registry)) {
            $stored_result['endpointCapabilityRegistry'] = $registry;
            if (absint($registry['configuredEndpointCount'] ?? 0) > 1
                && empty($registry['exactInvalidationVerified'])) {
                $stored_result['success'] = false;
                $stored_result['verified'] = false;
                $stored_result['exactInvalidationVerified'] = false;
                $stored_result['mixedEndpointTopologyUnverified'] = true;
                if ('exact-url-canary-per-endpoint' !== (string) ($stored_result['capabilityTest'] ?? '')) {
                    $stored_result['status'] = 'mixed-topology-unverified';
                    $stored_result['message'] = __('The aggregate canary cannot assign exact-invalidation proof to multiple Varnish endpoints. Per-endpoint proof is required before runtime invalidation is enabled.', 'ultracache');
                }
            }
        }
        $persisted = $stored_result;
        unset($persisted['endpointCapabilityRegistry']);
        self::persist_varnish_diagnostic_state(
            self::get_varnish_basic_test_state_name(),
            'result',
            $persisted
        );
        delete_option(self::get_varnish_basic_test_option_name());
        $stored_result['sharedCacheRuntimeSync'] = self::sync_shared_cache_runtime_after_varnish_capability_change();
        return $stored_result;
    }

    /**
     * Read the latest basic Varnish test result.
     *
     * @return array
     */
    public static function get_varnish_basic_test_result()
    {
        $value = self::read_varnish_diagnostic_state(
            self::get_varnish_basic_test_state_name(),
            self::get_varnish_basic_test_option_name(),
            'result'
        );
        if (!is_array($value)) {
            return array();
        }

        if (!empty($value)
            && method_exists(static::class, 'varnish_capability_contracts_match')
            && !self::varnish_capability_contracts_match(
                $value,
                array('transport')
            )) {
            $value['success'] = false;
            $value['verified'] = false;
            $value['exactInvalidationVerified'] = false;
            $value['configurationChanged'] = true;
            $value['status'] = 'configuration-changed';
            $value['message'] = __('The Varnish configuration changed. Run Redetect Varnish Capabilities again.', 'ultracache');
        } elseif (!empty($value['exactInvalidationVerified'])) {
            // A successful behavior proof remains valid until its bound
            // Varnish configuration/capability contract changes.
            $value['proofExpiresAt'] = 0;
            unset($value['proofExpired']);
        }

        $variant_capability = self::get_varnish_html_variant_capability_status();
        if (!empty($variant_capability)) {
            $value['htmlVariantCapability'] = $variant_capability;
            $value['htmlVariantsSupported'] = !empty($variant_capability['supported']);
        }

        if (!empty($value) && method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')) {
            $registry = self::get_varnish_endpoint_capability_registry_status();
            $value['endpointCapabilityRegistry'] = $registry;
            if (absint($registry['configuredEndpointCount'] ?? 0) > 0
                && empty($registry['exactInvalidationVerified'])) {
                $value['success'] = false;
                $value['verified'] = false;
                $value['exactInvalidationVerified'] = false;
                if (absint($registry['verifiedExactEndpointCount'] ?? 0) > 0) {
                    $value['status'] = 'partial-topology';
                    $value['message'] = __('The canary proved exact invalidation for only part of the configured endpoint topology. Runtime invalidation remains disabled until every endpoint is verified.', 'ultracache');
                }
            }
        }

        return $value;
    }

    /**
     * Return whether exact URL invalidation is trusted for runtime use.
     *
     * Every transport, including authenticated admin BAN, requires a current
     * isolated canary proof. Accepting a control command does not prove that the
     * active VCL matched and invalidated the cached WordPress object.
     *
     * @param array $settings Normalized Varnish settings.
     * @return array
     */
    protected static function get_varnish_exact_invalidation_capability(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();
        $transport_available = !empty($support['available'])
            && !empty($settings['enabled'])
            && !empty($settings['servers'])
            && ('admin' !== $mode || !empty($settings['secretConfigured']));

        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($settings)
            : array();
        $strategy_status = method_exists(static::class, 'get_varnish_invalidation_strategy_status')
            ? self::get_varnish_invalidation_strategy_status($settings)
            : array();
        $effective_strategy = sanitize_key((string) ($strategy_status['effective'] ?? ''));
        if ('admin' === $mode) {
            $required_capability = 'exactBan';
        } elseif ('ban' === $effective_strategy) {
            $required_capability = 'exactBan';
        } elseif ('purge' === $effective_strategy) {
            $required_capability = 'exactPurge';
        } else {
            $required_capability = 'PURGE' === strtoupper((string) ($settings['method'] ?? 'PURGE'))
                ? 'exactPurge'
                : 'exactBan';
        }

        $capability_state = is_array($registry['capabilityStates'][$required_capability] ?? null)
            ? $registry['capabilityStates'][$required_capability]
            : array();
        $verified = $transport_available && !empty($capability_state['behaviorVerifiedAllEndpoints']);
        $verified_endpoint_count = absint($capability_state['behaviorVerifiedEndpointCount'] ?? 0);
        $endpoint_count = absint($registry['configuredEndpointCount'] ?? count((array) ($settings['servers'] ?? array())));
        $proof_expires_at = 0;
        $tested_at = absint($capability_state['testedAt'] ?? 0);
        $status = 'verified';
        if (!$transport_available) {
            $status = 'configuration-incomplete';
        } elseif ($verified) {
            $status = 'verified';
        } elseif ($verified_endpoint_count > 0) {
            $status = 'partial-topology';
        } else {
            $status = sanitize_key((string) ($capability_state['state'] ?? 'not-tested'));
        }

        $method_label = 'exactBan' === $required_capability ? 'BAN' : 'PURGE';
        $message = self::sanitize_varnish_string((string) ($capability_state['message'] ?? ''));
        if ($verified) {
            $message = self::maybe_translate_sprintf(
                'Every configured Varnish endpoint has current isolated-canary proof for exact %s behavior.',
                $method_label
            );
        } elseif ('partial-topology' === $status) {
            $message = self::maybe_translate_sprintf(
                'Exact %1$s is verified on %2$d of %3$d configured Varnish endpoints. Runtime invalidation remains disabled until every active endpoint is verified.',
                $method_label,
                $verified_endpoint_count,
                $endpoint_count
            );
        } elseif ('' === $message) {
            $message = self::maybe_translate_sprintf(
                'Run Redetect Varnish Capabilities successfully before UltraCache treats exact %s as a managed runtime capability.',
                $method_label
            );
        }

        return array(
            'supported' => $verified,
            'verified' => $verified,
            'testBypass' => false,
            'status' => $status,
            'message' => $message,
            'requiredCapability' => $required_capability,
            'requiredMethod' => $method_label,
            'testedAt' => $tested_at,
            'proofExpiresAt' => $proof_expires_at,
            'configuredEndpointCount' => $endpoint_count,
            'verifiedEndpointCount' => $verified_endpoint_count,
            'mixedTopology' => !empty($registry['mixedTopology']),
            'endpointRegistry' => $registry,
        );
    }

    /**
     * Run the isolated exact URL invalidation, public refill, and ESI test.
     *
     * @param bool $diagnostic_enable Allow the isolated test to exercise a configured-but-disabled connection without persisting an enable-state change.
     * @return array
     */
    public static function run_varnish_basic_test($diagnostic_enable = false)
    {
        $probe_requested_url = esc_url_raw(home_url('/'));
        $probe_resolution = function_exists('ultracache_resolve_anonymous_frontend_url')
            ? ultracache_resolve_anonymous_frontend_url($probe_requested_url, 'varnish_capability')
            : array(
                'success' => '' !== $probe_requested_url,
                'requestedUrl' => $probe_requested_url,
                'resolvedUrl' => $probe_requested_url,
                'httpCode' => 200,
                'redirected' => false,
                'redirectCount' => 0,
            );

        if (!is_callable(array(static::class, 'varnish_test_behavior'))) {
            $unavailable = array(
                'success' => false,
                'status' => 'runner-unavailable',
                'message' => __('Varnish test behavior is unavailable.', 'ultracache'),
                'time' => time(),
            );
            $unavailable['requestedUrl'] = $probe_requested_url;
            $unavailable['requestedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($probe_requested_url)
                : '';
            if (is_wp_error($probe_resolution)) {
                $unavailable['resolvedUrl'] = '';
                $unavailable['resolvedLanguage'] = '';
                $unavailable['resolutionError'] = sanitize_key((string) $probe_resolution->get_error_code());
            } else {
                $unavailable['resolvedUrl'] = esc_url_raw((string) ($probe_resolution['resolvedUrl'] ?? ''));
                $unavailable['resolvedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
                    ? ultracache_multilingual_get_public_url_language($unavailable['resolvedUrl'])
                    : '';
                $unavailable['redirected'] = !empty($probe_resolution['redirected']);
                $unavailable['redirectCount'] = max(0, (int) ($probe_resolution['redirectCount'] ?? 0));
            }
            return $unavailable;
        }

        self::reset_settings_cache();
        if (method_exists(static::class, 'refresh_reverse_proxy_status')) {
            self::refresh_reverse_proxy_status();
        }
        $tested_settings = self::get_varnish_cli_settings();
        $diagnostic_override_active = false;
        if ($diagnostic_enable
            && !empty($tested_settings['connectionConfigured'])
            && !empty($tested_settings['servers'])
        ) {
            $tested_settings['enabled'] = true;
            self::set_varnish_cli_settings_diagnostic_override($tested_settings);
            $diagnostic_override_active = true;
        }

        try {
            $result = self::varnish_test_behavior($tested_settings);
        } finally {
            if ($diagnostic_override_active) {
                self::clear_varnish_cli_settings_diagnostic_override();
            }
        }
        if (!is_array($result)) {
            $result = array(
                'success' => false,
                'status' => 'invalid-result',
                'message' => __('Varnish test returned an invalid result.', 'ultracache'),
                'time' => time(),
            );
        }

        $result['requestedUrl'] = $probe_requested_url;
        $result['requestedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
            ? ultracache_multilingual_get_public_url_language($probe_requested_url)
            : '';
        if (is_wp_error($probe_resolution)) {
            $result['resolvedUrl'] = '';
            $result['resolvedLanguage'] = '';
            $result['resolutionError'] = sanitize_key((string) $probe_resolution->get_error_code());
            $result['redirected'] = false;
            $result['redirectCount'] = 0;
        } else {
            $result['resolvedUrl'] = esc_url_raw((string) ($probe_resolution['resolvedUrl'] ?? ''));
            $result['resolvedLanguage'] = function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($result['resolvedUrl'])
                : '';
            $result['resolutionError'] = empty($probe_resolution['success']) ? 'frontend_target_http_status' : '';
            $result['redirected'] = !empty($probe_resolution['redirected']);
            $result['redirectCount'] = max(0, (int) ($probe_resolution['redirectCount'] ?? 0));
        }

        return self::store_varnish_basic_test_result($result, $tested_settings);
    }

}
