<?php
/**
 * Per-endpoint Varnish capability registry for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Capability_Registry_Trait
{
    /**
     * Return the non-autoload option used by the endpoint capability registry.
     *
     * @return string
     */
    private static function get_varnish_endpoint_capability_registry_option_name()
    {
        return 'ultracache_varnish_endpoint_capabilities_v1';
    }

    /**
     * Return the UltraCache-owned state record for endpoint capability proofs.
     *
     * @return string
     */
    private static function get_varnish_endpoint_capability_registry_state_name()
    {
        return 'ultracache_state:varnish.endpoint_capabilities';
    }

    /**
     * Return the current registry schema version.
     *
     * @return int
     */
    private static function get_varnish_endpoint_capability_registry_schema()
    {
        return 8;
    }

    /**
     * Return the persistent state record used only for aggregate Varnish
     * capability diagnostics. Runtime permission always comes from the
     * per-endpoint capability registry, never from this record.
     *
     * @return string
     */
    private static function get_varnish_capability_diagnostics_state_name()
    {
        return 'ultracache_state:varnish.capability_diagnostics';
    }


    /**
     * Persist aggregate diagnostic evidence without granting a runtime
     * capability. Endpoint profiles remain authoritative.
     *
     * @param string $diagnostic_key Diagnostic identifier.
     * @param array  $diagnostic     Diagnostic payload.
     * @param array  $contracts      Capability contracts that bind the payload.
     * @return bool
     */
    private static function persist_varnish_capability_diagnostic($diagnostic_key, array $diagnostic, array $contracts = array())
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }

        $diagnostic_key = sanitize_key((string) $diagnostic_key);
        if (!in_array($diagnostic_key, array('topology', 'softpurge', 'originrevalidation'), true)) {
            return false;
        }

        if (!empty($contracts)) {
            $diagnostic = self::bind_varnish_capability_contracts($diagnostic, $contracts);
        }
        $diagnostic['testedAt'] = absint($diagnostic['testedAt'] ?? time());
        $diagnostic['status'] = sanitize_key((string) ($diagnostic['status'] ?? 'inconclusive'));
        $diagnostic['message'] = self::sanitize_varnish_string((string) ($diagnostic['message'] ?? ''));
        $diagnostic = self::sanitize_varnish_result($diagnostic);

        $mutation = ultracache_mutate_state_record(
            self::get_varnish_capability_diagnostics_state_name(),
            static function (array $current) use ($diagnostic_key, $diagnostic) {
                $diagnostics = is_array($current['diagnostics'] ?? null) ? $current['diagnostics'] : array();
                $diagnostics[$diagnostic_key] = $diagnostic;

                return array(
                    'schemaVersion' => 1,
                    'updatedAt' => time(),
                    'diagnostics' => $diagnostics,
                );
            },
            5,
            array('schemaVersion' => 1, 'updatedAt' => 0, 'diagnostics' => array())
        );

        return !empty($mutation['success']);
    }

    /**
     * Read aggregate diagnostic evidence bound to the current configuration.
     * A configuration-changed record remains visible but cannot grant support.
     *
     * @param string $diagnostic_key Diagnostic identifier.
     * @param array  $contracts      Capability contracts that bind the payload.
     * @return array<string,mixed>
     */
    private static function get_varnish_capability_diagnostic($diagnostic_key, array $contracts = array())
    {
        if (!function_exists('ultracache_get_state_record_read_only')) {
            return array();
        }

        $diagnostic_key = sanitize_key((string) $diagnostic_key);
        $record = ultracache_get_state_record_read_only(self::get_varnish_capability_diagnostics_state_name());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        $diagnostics = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : array();
        $diagnostic = is_array($diagnostics[$diagnostic_key] ?? null) ? $diagnostics[$diagnostic_key] : array();
        if (empty($diagnostic)) {
            return array();
        }

        if (!empty($contracts) && !self::varnish_capability_contracts_match($diagnostic, $contracts)) {
            $diagnostic['configurationChanged'] = true;
            $diagnostic['supported'] = false;
            $diagnostic['available'] = false;
            $diagnostic['status'] = 'configuration-changed';
        }

        return self::sanitize_varnish_result($diagnostic);
    }

    /**
     * Return the canonical per-capability outcome fields.
     *
     * @return array<int,string>
     */
    private static function get_varnish_capability_outcome_fields()
    {
        return array('exactPurge', 'exactBan', 'batchBan', 'htmlFlush', 'hostFlush', 'softPurge', 'originRevalidation', 'swr');
    }

    /**
     * Normalize one persisted capability outcome.
     *
     * @param array $outcome Candidate outcome.
     * @return array<string,mixed>
     */
    private static function sanitize_varnish_capability_outcome(array $outcome)
    {
        $state = sanitize_key((string) ($outcome['state'] ?? 'not-tested'));
        $legacy_proof_expired = 'proof-expired' === $state;
        if ($legacy_proof_expired) {
            $state = 'supported';
        } elseif (!in_array($state, array('supported', 'not-supported', 'not-applicable', 'not-tested', 'observation-incomplete', 'configuration-changed'), true)) {
            $state = 'not-tested';
        }
        $reason_code = $legacy_proof_expired
            ? 'behavior-verified'
            : sanitize_key((string) ($outcome['reasonCode'] ?? 'probe-not-run'));
        if ('' === $reason_code) {
            $reason_code = 'probe-not-run';
        }
        $message = $legacy_proof_expired
            ? self::maybe_translate('The capability behavior was verified.')
            : self::sanitize_varnish_string((string) ($outcome['message'] ?? ''));
        if ('' === $message) {
            $reason_label = str_replace('-', ' ', $reason_code);
            if ('supported' === $state) {
                $message = self::maybe_translate_sprintf('The capability behavior was verified (%s).', $reason_label);
            } elseif ('not-applicable' === $state) {
                $message = self::maybe_translate_sprintf('The capability does not apply to this endpoint or topology (%s).', $reason_label);
            } elseif ('observation-incomplete' === $state) {
                $message = self::maybe_translate_sprintf('The capability probe did not produce a conclusive behavior observation (%s).', $reason_label);
            } elseif ('not-supported' === $state) {
                $message = self::maybe_translate_sprintf('The capability probe completed without verifying the required behavior (%s).', $reason_label);
            } elseif ('configuration-changed' === $state) {
                $message = self::maybe_translate_sprintf('The configured endpoint contract changed after the stored capability proof (%s).', $reason_label);
            } else {
                $message = self::maybe_translate_sprintf('The capability probe was not run (%s).', $reason_label);
            }
        }

        return array(
            'state' => $state,
            'reasonCode' => $reason_code,
            'message' => $message,
            'probeAttempted' => !empty($outcome['probeAttempted']),
            'applicable' => !array_key_exists('applicable', $outcome) || !empty($outcome['applicable']),
            'conclusive' => !empty($outcome['conclusive']),
            'testedAt' => absint($outcome['testedAt'] ?? 0),
            'proofExpiresAt' => 0,
        );
    }

    /**
     * Build one explicit capability outcome from a probe result.
     *
     * @param bool   $supported      Whether behavior was verified.
     * @param bool   $probe_attempted Whether transport/behavior was attempted.
     * @param bool   $applicable     Whether the capability applies to this topology/configuration.
     * @param bool   $conclusive     Whether the observation was conclusive.
     * @param string $reason_code    Stable reason code.
     * @param string $message        Specific human-readable reason.
     * @param int    $tested_at      Probe timestamp.
     * @param int    $proof_expires_at Proof expiry.
     * @return array<string,mixed>
     */
    private static function build_varnish_capability_outcome($supported, $probe_attempted, $applicable, $conclusive, $reason_code, $message, $tested_at = 0, $proof_expires_at = 0)
    {
        if ($supported) {
            $state = 'supported';
            $reason_code = 'behavior-verified';
        } elseif (!$applicable) {
            $state = 'not-applicable';
        } elseif (!$probe_attempted) {
            $state = 'not-tested';
        } elseif (!$conclusive) {
            $state = 'observation-incomplete';
        } else {
            $state = 'not-supported';
        }

        return self::sanitize_varnish_capability_outcome(array(
            'state' => $state,
            'reasonCode' => $reason_code,
            'message' => $message,
            'probeAttempted' => $probe_attempted,
            'applicable' => $applicable,
            'conclusive' => $conclusive,
            'testedAt' => $tested_at,
            'proofExpiresAt' => $proof_expires_at,
        ));
    }

    /**
     * Normalize one endpoint label without retaining credentials.
     *
     * @param mixed $endpoint Endpoint label.
     * @return string
     */
    private static function normalize_varnish_registry_endpoint($endpoint)
    {
        $endpoint = strtolower(trim((string) $endpoint));
        $endpoint = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', '', $endpoint);

        return substr((string) $endpoint, 0, 512);
    }

    /**
     * Normalize an internal exact-operation capability identifier.
     *
     * @param mixed $capability Candidate identifier.
     * @return string
     */
    private static function normalize_varnish_registry_exact_capability($capability)
    {
        $capability = preg_replace('/[^a-z]/', '', strtolower((string) $capability));
        if ('exactpurge' === $capability) {
            return 'exactPurge';
        }
        if ('exactban' === $capability) {
            return 'exactBan';
        }

        return '';
    }

    /**
     * Normalize and deduplicate exact-operation identifiers.
     *
     * @param array $capabilities Candidate identifiers.
     * @return array<int,string>
     */
    private static function normalize_varnish_registry_exact_capabilities(array $capabilities)
    {
        $normalized = array();
        foreach ($capabilities as $capability) {
            $capability = self::normalize_varnish_registry_exact_capability($capability);
            if ('' !== $capability) {
                $normalized[$capability] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * Return a stable endpoint registry key.
     *
     * @param string $mode     Transport mode.
     * @param string $endpoint Endpoint label.
     * @return string
     */
    private static function get_varnish_registry_endpoint_key($mode, $endpoint)
    {
        $mode = self::sanitize_varnish_mode($mode);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        if ('' === $endpoint) {
            return '';
        }

        return hash('sha256', $mode . '|' . $endpoint);
    }

    /**
     * Build a fingerprint for one endpoint's current control configuration.
     *
     * @param string $mode     Transport mode.
     * @param string $endpoint Endpoint label.
     * @param array  $settings Normalized Varnish settings.
     * @return string
     */
    private static function get_varnish_registry_endpoint_fingerprint($mode, $endpoint, array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $mode = self::sanitize_varnish_mode($mode);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $method = ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'PURGE' : 'BAN';
        $secret_value = array_key_exists('key', $settings)
            ? (string) $settings['key']
            : (function_exists('ultracache_get_varnish_password')
                ? (string) ultracache_get_varnish_password()
                : '');
        $secret_fingerprint = '';
        if ('' !== $secret_value) {
            $fingerprint_key = function_exists('wp_salt') ? (string) wp_salt('auth') : 'ultracache-varnish-endpoint';
            $secret_fingerprint = hash_hmac('sha256', $secret_value, $fingerprint_key);
        }

        $payload = array(
            'schema' => self::get_varnish_endpoint_capability_registry_schema(),
            'mode' => $mode,
            'endpoint' => $endpoint,
            'method' => $method,
            'secretConfigured' => '' !== $secret_value,
            'secretFingerprint' => $secret_fingerprint,
        );

        return hash('sha256', (string) wp_json_encode($payload));
    }

    /**
     * Bind soft-purge endpoint proof to the settings that control stale expiry
     * and authenticated origin refill.
     *
     * @param array $settings Normalized Varnish settings.
     * @return string
     */
    private static function get_varnish_registry_soft_purge_fingerprint(array $settings = array())
    {
        $soft = method_exists(static::class, 'get_varnish_capability_contract_fingerprint')
            ? self::get_varnish_capability_contract_fingerprint('soft-purge', $settings)
            : '';
        $refill = method_exists(static::class, 'get_varnish_capability_contract_fingerprint')
            ? self::get_varnish_capability_contract_fingerprint('refill', $settings)
            : '';

        return ('' === $soft || '' === $refill)
            ? ''
            : hash('sha256', $soft . '|' . $refill);
    }

    /**
     * Return the configured endpoint set from normalized settings.
     *
     * @param array $settings Normalized Varnish settings.
     * @return array<int,array<string,string>>
     */
    private static function get_configured_varnish_registry_endpoints(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        // Capability proofs belong to configured endpoints. Runtime enablement
        // is enforced by the planner and must not erase or hide a valid proof.
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $endpoints = array();
        foreach ((array) ($settings['servers'] ?? array()) as $endpoint) {
            $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
            $key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
            if ('' === $endpoint || '' === $key) {
                continue;
            }

            $endpoints[$key] = array(
                'key' => $key,
                'mode' => $mode,
                'endpoint' => $endpoint,
            );
        }

        return array_values($endpoints);
    }

    /**
     * Return an empty bounded endpoint profile.
     *
     * @param string $mode     Transport mode.
     * @param string $endpoint Endpoint label.
     * @param array  $settings Normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function get_empty_varnish_endpoint_capability_profile($mode, $endpoint, array $settings = array())
    {
        $mode = self::sanitize_varnish_mode($mode);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);

        return array(
            'schema' => self::get_varnish_endpoint_capability_registry_schema(),
            'key' => self::get_varnish_registry_endpoint_key($mode, $endpoint),
            'mode' => $mode,
            'endpoint' => $endpoint,
            'adapter' => 'admin' === $mode ? 'admin-ban' : 'http-unverified',
            'configurationFingerprint' => self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings),
            'reachable' => false,
            'controlConnectionVerified' => false,
            'exactPurge' => false,
            'exactBan' => false,
            'batchBan' => false,
            'htmlFlush' => false,
            'hostFlush' => false,
            'softPurge' => false,
            'originRevalidation' => false,
            'swr' => false,
            'exactRuntimeAvailable' => false,
            'batchRuntimeAvailable' => false,
            'topologyRuntimeAvailable' => false,
            'softPurgeRuntimeAvailable' => false,
            'testedAt' => 0,
            'exactTestedAt' => 0,
            'exactPurgeTestedAt' => 0,
            'exactBanTestedAt' => 0,
            'batchTestedAt' => 0,
            'topologyTestedAt' => 0,
            'htmlFlushTestedAt' => 0,
            'hostFlushTestedAt' => 0,
            'softPurgeTestedAt' => 0,
            'originRevalidationTestedAt' => 0,
            'swrTestedAt' => 0,
            'proofExpiresAt' => 0,
            'exactProofExpiresAt' => 0,
            'batchProofExpiresAt' => 0,
            'topologyProofExpiresAt' => 0,
            'htmlFlushProofExpiresAt' => 0,
            'hostFlushProofExpiresAt' => 0,
            'softPurgeProofExpiresAt' => 0,
            'softPurgeBehaviorProofExpiresAt' => 0,
            'originRevalidationProofExpiresAt' => 0,
            'swrProofExpiresAt' => 0,
            'softPurgeConfigurationFingerprint' => '',
            'lastSuccessAt' => 0,
            'lastFailureAt' => 0,
            'lastFailure' => '',
            'source' => 'unverified',
            'status' => 'untested',
            'contractVersion' => 1,
            'contractAuthenticated' => false,
            'contractId' => '',
            'contractCapabilities' => array(),
            'contractReportedAt' => 0,
            'contractStatus' => 'not-tested',
            'contractMessage' => self::maybe_translate('The optional authenticated HTTP/VCL contract probe has not run for this endpoint.'),
            'capabilityOutcomes' => array(),
        );
    }

    /**
     * Sanitize one endpoint capability profile.
     *
     * @param array $profile  Candidate profile.
     * @param array $settings Normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function sanitize_varnish_endpoint_capability_profile(array $profile, array $settings = array())
    {
        $mode = self::sanitize_varnish_mode($profile['mode'] ?? 'http');
        $endpoint = self::normalize_varnish_registry_endpoint($profile['endpoint'] ?? '');
        $clean = self::get_empty_varnish_endpoint_capability_profile($mode, $endpoint, $settings);
        $boolean_fields = array(
            'reachable',
            'controlConnectionVerified',
            'exactPurge',
            'exactBan',
            'batchBan',
            'htmlFlush',
            'hostFlush',
            'softPurge',
            'originRevalidation',
            'swr',
            'exactRuntimeAvailable',
            'batchRuntimeAvailable',
            'topologyRuntimeAvailable',
            'softPurgeRuntimeAvailable',
        );
        foreach ($boolean_fields as $field) {
            $clean[$field] = !empty($profile[$field]);
        }
        if (!empty($clean['exactRuntimeAvailable'])) {
            $clean['controlConnectionVerified'] = true;
        }

        $clean['adapter'] = sanitize_key((string) ($profile['adapter'] ?? $clean['adapter']));
        $clean['testedAt'] = absint($profile['testedAt'] ?? 0);
        $legacy_exact_tested_at = absint($profile['exactTestedAt'] ?? 0);
        $clean['exactPurgeTestedAt'] = absint($profile['exactPurgeTestedAt'] ?? (!empty($profile['exactPurge']) ? $legacy_exact_tested_at : 0));
        $clean['exactBanTestedAt'] = absint($profile['exactBanTestedAt'] ?? (!empty($profile['exactBan']) ? $legacy_exact_tested_at : 0));
        $clean['exactTestedAt'] = max($clean['exactPurgeTestedAt'], $clean['exactBanTestedAt']);
        $clean['batchTestedAt'] = absint($profile['batchTestedAt'] ?? 0);
        $legacy_topology_tested_at = absint($profile['topologyTestedAt'] ?? 0);
        $clean['htmlFlushTestedAt'] = absint($profile['htmlFlushTestedAt'] ?? (!empty($profile['htmlFlush']) ? $legacy_topology_tested_at : 0));
        $clean['hostFlushTestedAt'] = absint($profile['hostFlushTestedAt'] ?? (!empty($profile['hostFlush']) ? $legacy_topology_tested_at : 0));
        $clean['topologyTestedAt'] = max($clean['htmlFlushTestedAt'], $clean['hostFlushTestedAt']);
        $clean['softPurgeTestedAt'] = absint($profile['softPurgeTestedAt'] ?? 0);
        $clean['originRevalidationTestedAt'] = absint($profile['originRevalidationTestedAt'] ?? 0);
        $clean['swrTestedAt'] = absint($profile['swrTestedAt'] ?? 0);
        // Capability proofs do not expire by age. Keep these legacy fields
        // in the serialized schema as inert zeros for compatibility with older
        // readers while configuration fingerprints remain authoritative.
        $clean['proofExpiresAt'] = 0;
        $clean['exactProofExpiresAt'] = 0;
        $clean['batchProofExpiresAt'] = 0;
        $clean['htmlFlushProofExpiresAt'] = 0;
        $clean['hostFlushProofExpiresAt'] = 0;
        $clean['topologyProofExpiresAt'] = 0;
        $clean['softPurgeBehaviorProofExpiresAt'] = 0;
        $clean['originRevalidationProofExpiresAt'] = 0;
        $clean['swrProofExpiresAt'] = 0;
        $clean['softPurgeProofExpiresAt'] = 0;
        $clean['softPurgeConfigurationFingerprint'] = substr((string) ($profile['softPurgeConfigurationFingerprint'] ?? ''), 0, 64);
        $current_soft_purge_fingerprint = self::get_varnish_registry_soft_purge_fingerprint($settings);
        if ('' === $clean['softPurgeConfigurationFingerprint']
            || '' === $current_soft_purge_fingerprint
            || !hash_equals($clean['softPurgeConfigurationFingerprint'], $current_soft_purge_fingerprint)) {
            $clean['softPurge'] = false;
            $clean['originRevalidation'] = false;
            $clean['swr'] = false;
            $clean['softPurgeRuntimeAvailable'] = false;
            $clean['softPurgeProofExpiresAt'] = 0;
            $clean['softPurgeBehaviorProofExpiresAt'] = 0;
            $clean['originRevalidationProofExpiresAt'] = 0;
            $clean['swrProofExpiresAt'] = 0;
            $clean['softPurgeTestedAt'] = 0;
            $clean['originRevalidationTestedAt'] = 0;
            $clean['swrTestedAt'] = 0;
        }
        $clean['lastSuccessAt'] = absint($profile['lastSuccessAt'] ?? 0);
        $clean['lastFailureAt'] = absint($profile['lastFailureAt'] ?? 0);
        $clean['lastFailure'] = self::sanitize_varnish_string((string) ($profile['lastFailure'] ?? ''));
        $clean['source'] = sanitize_key((string) ($profile['source'] ?? 'unverified'));
        $clean['status'] = sanitize_key((string) ($profile['status'] ?? 'untested'));
        $clean['contractVersion'] = max(1, min(100, absint($profile['contractVersion'] ?? 1)));
        $clean['contractAuthenticated'] = !empty($profile['contractAuthenticated']);
        $clean['contractId'] = substr(sanitize_key((string) ($profile['contractId'] ?? '')), 0, 64);
        $clean['contractCapabilities'] = method_exists(static::class, 'sanitize_varnish_http_contract_capabilities')
            ? self::sanitize_varnish_http_contract_capabilities($profile['contractCapabilities'] ?? array())
            : array();
        $clean['contractReportedAt'] = absint($profile['contractReportedAt'] ?? 0);
        $clean['contractStatus'] = sanitize_key((string) ($profile['contractStatus'] ?? 'not-tested'));
        $clean['contractMessage'] = self::sanitize_varnish_string((string) ($profile['contractMessage'] ?? self::maybe_translate('The optional authenticated HTTP/VCL contract probe has not run for this endpoint.')));
        $clean['capabilityOutcomes'] = array();
        $raw_outcomes = is_array($profile['capabilityOutcomes'] ?? null) ? $profile['capabilityOutcomes'] : array();
        foreach (self::get_varnish_capability_outcome_fields() as $capability_field) {
            if (is_array($raw_outcomes[$capability_field] ?? null)) {
                $clean['capabilityOutcomes'][$capability_field] = self::sanitize_varnish_capability_outcome($raw_outcomes[$capability_field]);
            }
        }
        $clean['configurationFingerprint'] = substr((string) ($profile['configurationFingerprint'] ?? $clean['configurationFingerprint']), 0, 64);

        return $clean;
    }

    /**
     * Read the raw endpoint capability registry.
     *
     * @return array<string,mixed>
     */
    private static function read_varnish_endpoint_capability_registry()
    {
        if (function_exists('ultracache_get_state_record_read_only')) {
            $record = ultracache_get_state_record_read_only(self::get_varnish_endpoint_capability_registry_state_name());
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
            if (!empty($payload)) {
                return $payload;
            }
        }

        $legacy = get_option(self::get_varnish_endpoint_capability_registry_option_name(), array());
        if (!is_array($legacy) || empty($legacy)) {
            return array();
        }

        if (function_exists('ultracache_mutate_state_record')) {
            $mutation = ultracache_mutate_state_record(
                self::get_varnish_endpoint_capability_registry_state_name(),
                static function () use ($legacy) {
                    return $legacy;
                },
                5,
                $legacy
            );
            if (!empty($mutation['success'])) {
                delete_option(self::get_varnish_endpoint_capability_registry_option_name());
            }
        }

        return $legacy;
    }

    /**
     * Persist the complete endpoint capability registry.
     *
     * @param array $profiles Endpoint profiles keyed by registry key.
     * @param array $settings Normalized settings used to create the profiles.
     * @return array<string,mixed>
     */
    private static function write_varnish_endpoint_capability_registry(array $profiles, array $settings = array())
    {
        $clean_profiles = array();
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $clean = self::sanitize_varnish_endpoint_capability_profile($profile, $settings);
            if ('' === (string) $clean['key'] || '' === (string) $clean['endpoint']) {
                continue;
            }
            $clean_profiles[$clean['key']] = $clean;
        }

        $payload = array(
            'schema' => self::get_varnish_endpoint_capability_registry_schema(),
            'updatedAt' => time(),
            'profiles' => $clean_profiles,
        );
        if (function_exists('ultracache_mutate_state_record')) {
            $mutation = ultracache_mutate_state_record(
                self::get_varnish_endpoint_capability_registry_state_name(),
                static function () use ($payload) {
                    return $payload;
                },
                5,
                $payload
            );
            if (!empty($mutation['success'])) {
                delete_option(self::get_varnish_endpoint_capability_registry_option_name());
            }
        }

        return $payload;
    }

    /**
     * Project the configured admin transport contract without assuming behavior.
     *
     * Admin mode proves only that UltraCache knows how to speak the authenticated
     * Varnish CLI protocol. Exact BAN, batch BAN, HTML-only flush and host flush
     * remain unavailable until the relevant isolated behavior proof succeeds.
     *
     * @param array $settings Normalized Varnish settings.
     * @param array $profiles Existing profiles.
     * @return array<string,array<string,mixed>>
     */
    private static function project_native_admin_endpoint_contract(array $settings, array $profiles)
    {
        if ('admin' !== self::sanitize_varnish_mode($settings['mode'] ?? 'http')) {
            return $profiles;
        }

        foreach (self::get_configured_varnish_registry_endpoints($settings) as $configured) {
            $key = (string) $configured['key'];
            $profile = isset($profiles[$key]) && is_array($profiles[$key])
                ? self::sanitize_varnish_endpoint_capability_profile($profiles[$key], $settings)
                : self::get_empty_varnish_endpoint_capability_profile('admin', (string) $configured['endpoint'], $settings);
            $profile['adapter'] = 'admin-ban';
            $profile['configurationFingerprint'] = self::get_varnish_registry_endpoint_fingerprint('admin', (string) $configured['endpoint'], $settings);
            $profiles[$key] = $profile;
        }

        return $profiles;
    }

    /**
     * Return the current configured profile projection without mutating the registry.
     *
     * @param array $settings Normalized Varnish settings.
     * @return array<string,array<string,mixed>>
     */
    private static function get_current_varnish_endpoint_capability_profiles(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $raw = self::read_varnish_endpoint_capability_registry();
        $stored = is_array($raw['profiles'] ?? null) ? $raw['profiles'] : array();
        $profiles = array();
        foreach (self::get_configured_varnish_registry_endpoints($settings) as $configured) {
            $key = (string) $configured['key'];
            $profile = isset($stored[$key]) && is_array($stored[$key])
                ? self::sanitize_varnish_endpoint_capability_profile($stored[$key], $settings)
                : self::get_empty_varnish_endpoint_capability_profile((string) $configured['mode'], (string) $configured['endpoint'], $settings);
            $current_fingerprint = self::get_varnish_registry_endpoint_fingerprint((string) $configured['mode'], (string) $configured['endpoint'], $settings);
            if (!hash_equals((string) $profile['configurationFingerprint'], $current_fingerprint)) {
                $profile = self::get_empty_varnish_endpoint_capability_profile((string) $configured['mode'], (string) $configured['endpoint'], $settings);
                $profile['status'] = 'configuration-changed';
                $profile['source'] = 'configuration-reset';
                foreach (self::get_varnish_capability_outcome_fields() as $capability_field) {
                    $profile['capabilityOutcomes'][$capability_field] = self::sanitize_varnish_capability_outcome(array(
                        'state' => 'configuration-changed',
                        'reasonCode' => 'configuration-changed',
                        'message' => self::maybe_translate('The configured Varnish endpoint contract changed; run Redetect Varnish Capabilities to create a new capability proof.'),
                        'probeAttempted' => false,
                        'applicable' => true,
                        'conclusive' => false,
                        'testedAt' => 0,
                        'proofExpiresAt' => 0,
                    ));
                }
            }
            $profiles[$key] = $profile;
        }

        // Admin transport identity is derived from configuration, but no
        // behavior capability is seeded by this side-effect-free projection.
        return self::project_native_admin_endpoint_contract($settings, $profiles);
    }

    /**
     * Persist changes for one configured endpoint.
     *
     * @param string $mode     Transport mode.
     * @param string $endpoint Endpoint label.
     * @param array  $changes  Capability changes.
     * @param array  $settings Normalized Varnish settings.
     * @return array<string,mixed>
     */
    private static function update_varnish_endpoint_capability_profile($mode, $endpoint, array $changes, array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $mode = self::sanitize_varnish_mode($mode);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
        if ('' === $key) {
            return array();
        }
        $configured_keys = array();
        foreach (self::get_configured_varnish_registry_endpoints($settings) as $configured_endpoint) {
            $configured_keys[(string) $configured_endpoint['key']] = true;
        }
        if (!isset($configured_keys[$key])) {
            return array();
        }

        $profiles = self::get_current_varnish_endpoint_capability_profiles($settings);
        $profile = isset($profiles[$key]) && is_array($profiles[$key])
            ? $profiles[$key]
            : self::get_empty_varnish_endpoint_capability_profile($mode, $endpoint, $settings);
        foreach ($changes as $field => $value) {
            if (array_key_exists($field, $profile)) {
                $profile[$field] = $value;
            }
        }
        $profile['configurationFingerprint'] = self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings);
        $profile = self::sanitize_varnish_endpoint_capability_profile($profile, $settings);
        $profiles[$key] = $profile;
        self::write_varnish_endpoint_capability_registry($profiles, $settings);

        return $profile;
    }

    /**
     * Persist one explicit capability outcome without changing unrelated proofs.
     *
     * @param string $mode       Transport mode.
     * @param string $endpoint   Endpoint label.
     * @param string $capability Capability field.
     * @param array  $outcome    Explicit outcome contract.
     * @param array  $settings   Normalized settings.
     * @return array<string,mixed>
     */
    private static function persist_varnish_endpoint_capability_outcome($mode, $endpoint, $capability, array $outcome, array $settings = array())
    {
        if (!in_array($capability, self::get_varnish_capability_outcome_fields(), true)) {
            return array();
        }
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }
        $mode = self::sanitize_varnish_mode($mode);
        $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
        $key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
        $profiles = self::get_current_varnish_endpoint_capability_profiles($settings);
        $profile = is_array($profiles[$key] ?? null)
            ? $profiles[$key]
            : self::get_empty_varnish_endpoint_capability_profile($mode, $endpoint, $settings);
        $outcomes = is_array($profile['capabilityOutcomes'] ?? null) ? $profile['capabilityOutcomes'] : array();
        $outcomes[$capability] = self::sanitize_varnish_capability_outcome($outcome);

        return self::update_varnish_endpoint_capability_profile(
            $mode,
            $endpoint,
            array('capabilityOutcomes' => $outcomes),
            $settings
        );
    }

    /**
     * Fail closed after a runtime operation contradicts a stored capability.
     *
     * A current proof is an execution permission, not a permanent declaration.
     * When a verified transport later fails, only the affected configured
     * endpoints are downgraded so the planner selects the next verified fallback.
     *
     * @param array $result Runtime operation result.
     * @param array $plan   Runtime plan used for the operation.
     * @return bool Whether at least one endpoint profile was downgraded.
     */
    private static function downgrade_varnish_runtime_capabilities_after_failure(array $result, array $plan)
    {
        if (!empty($plan['testProbeActive']) || !empty($result['success'])) {
            return false;
        }

        $request_count = absint($result['requestCount'] ?? 0);
        $endpoint_request_count = absint($result['successfulEndpointRequestCount'] ?? 0)
            + absint($result['failedEndpointRequestCount'] ?? 0);
        if ($request_count <= 0 && $endpoint_request_count <= 0) {
            return false;
        }

        $strategy = sanitize_key((string) ($plan['selectedStrategy'] ?? ''));
        $exact_failure = in_array($strategy, array('admin-ban', 'exact-ban', 'exact-purge'), true)
            || 0 === strpos($strategy, 'known-url-admin-ban')
            || 0 === strpos($strategy, 'known-url-exact-ban')
            || 0 === strpos($strategy, 'known-url-exact-purge');
        $batch_failure = $exact_failure && !empty($result['batchBanUsed']);
        if ($batch_failure) {
            /*
             * A failed bounded batch contradicts Batch BAN first. Preserve the
             * independently verified exact capability so the next production
             * attempt can retry the same URLs one expression at a time. If that
             * exact fallback also fails, its own result will downgrade exact BAN.
             */
            $exact_failure = false;
        }
        $soft_failure = 'soft-purge' === $strategy || 0 === strpos($strategy, 'known-url-soft-purge');
        $html_failure = 'html-flush' === $strategy;
        $host_failure = 'host-flush' === $strategy;
        if (!$batch_failure && !$exact_failure && !$soft_failure && !$html_failure && !$host_failure) {
            return false;
        }

        $settings = self::get_varnish_cli_settings();
        $configured = self::get_configured_varnish_registry_endpoints($settings);
        if (empty($configured)) {
            return false;
        }

        $failed = array();
        foreach ((array) ($result['failedEndpointTargets'] ?? array()) as $endpoint) {
            $endpoint = self::normalize_varnish_registry_endpoint($endpoint);
            if ('' !== $endpoint) {
                $failed[$endpoint] = true;
            }
        }
        foreach ((array) ($result['details'] ?? array()) as $detail) {
            if (!is_array($detail) || !array_key_exists('success', $detail) || !empty($detail['success'])) {
                continue;
            }
            $endpoint = self::normalize_varnish_registry_endpoint($detail['server'] ?? '');
            if ('' !== $endpoint) {
                $failed[$endpoint] = true;
            }
        }

        /*
         * A terminal global failure without endpoint accounting contradicts the
         * whole configured topology. Partial results downgrade only named peers.
         */
        if (empty($failed) && empty($result['partial'])) {
            foreach ($configured as $configured_endpoint) {
                $failed[(string) $configured_endpoint['endpoint']] = true;
            }
        }
        if (empty($failed)) {
            return false;
        }

        $tested_at = time();
        $message = substr(
            self::sanitize_varnish_string((string) ($result['message'] ?? 'A verified Varnish runtime operation failed.')),
            0,
            1000
        );
        $current_profiles = self::get_current_varnish_endpoint_capability_profiles($settings);
        $changed = false;
        foreach ($configured as $configured_endpoint) {
            $endpoint = (string) $configured_endpoint['endpoint'];
            if (empty($failed[$endpoint])) {
                continue;
            }
            $mode = (string) ($configured_endpoint['mode'] ?? ($settings['mode'] ?? 'http'));
            $profile_key = self::get_varnish_registry_endpoint_key($mode, $endpoint);
            $current = isset($current_profiles[$profile_key]) && is_array($current_profiles[$profile_key])
                ? $current_profiles[$profile_key]
                : array();

            $changes = array(
                'lastFailureAt' => $tested_at,
                'lastFailure' => $message,
                'source' => 'runtime-failure',
                'status' => 'runtime-failure',
            );
            if ($batch_failure) {
                $changes['batchBan'] = false;
                $changes['batchRuntimeAvailable'] = false;
                $changes['batchProofExpiresAt'] = 0;
            }
            if ($exact_failure) {
                $changes['exactRuntimeAvailable'] = false;
                $changes['exactProofExpiresAt'] = 0;
                if (false !== strpos($strategy, 'purge')) {
                    $changes['exactPurge'] = false;
                } else {
                    $changes['exactBan'] = false;
                }
            }
            if ($soft_failure) {
                $changes['softPurge'] = false;
                $changes['originRevalidation'] = false;
                $changes['swr'] = false;
                $changes['softPurgeRuntimeAvailable'] = false;
                $changes['softPurgeBehaviorProofExpiresAt'] = 0;
                $changes['originRevalidationProofExpiresAt'] = 0;
                $changes['swrProofExpiresAt'] = 0;
                $changes['softPurgeProofExpiresAt'] = 0;
            }
            if ($html_failure || $host_failure) {
                $html_verified = $html_failure ? false : !empty($current['htmlFlush']);
                $host_verified = $host_failure ? false : !empty($current['hostFlush']);
                $html_expires_at = $html_failure ? 0 : absint($current['htmlFlushProofExpiresAt'] ?? 0);
                $host_expires_at = $host_failure ? 0 : absint($current['hostFlushProofExpiresAt'] ?? 0);
                $topology_expiries = array_filter(array($html_expires_at, $host_expires_at));
                $changes['htmlFlush'] = $html_verified;
                $changes['hostFlush'] = $host_verified;
                $changes['htmlFlushProofExpiresAt'] = $html_expires_at;
                $changes['hostFlushProofExpiresAt'] = $host_expires_at;
                $changes['topologyProofExpiresAt'] = !empty($topology_expiries) ? min($topology_expiries) : 0;
                $changes['topologyRuntimeAvailable'] = $html_verified || $host_verified;
            }

            self::update_varnish_endpoint_capability_profile(
                $mode,
                $endpoint,
                $changes,
                $settings
            );
            $changed = true;
        }

        if ($changed && method_exists(static::class, 'sync_shared_cache_runtime_after_varnish_capability_change')) {
            self::sync_shared_cache_runtime_after_varnish_capability_change();
        }

        return $changed;
    }


    /**
     * Synchronize the exact-canary result into per-endpoint profiles.
     *
     * @param array $result   Basic canary result.
     * @param array $settings Exact normalized settings used by the test.
     * @return array<string,mixed>
     */
    private static function sync_varnish_basic_test_result_to_endpoint_registry(array $result, array $settings)
    {
        $mode = self::sanitize_varnish_mode($settings['mode'] ?? 'http');
        $configured = self::get_configured_varnish_registry_endpoints($settings);
        $raw = self::read_varnish_endpoint_capability_registry();
        $stored = is_array($raw['profiles'] ?? null) ? $raw['profiles'] : array();
        $profiles = array();
        $tested_at = absint($result['time'] ?? time());
        $method = ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'PURGE' : 'BAN';
        $single_endpoint = 1 === count($configured);

        $endpoint_result_map = array();
        foreach ((array) ($result['endpointResults'] ?? array()) as $endpoint_result) {
            if (!is_array($endpoint_result)) {
                continue;
            }
            $endpoint_label = self::normalize_varnish_registry_endpoint($endpoint_result['endpoint'] ?? '');
            if ('' !== $endpoint_label) {
                $endpoint_result_map[$endpoint_label] = $endpoint_result;
            }
        }

        $detail_map = array();
        foreach ((array) ($result['details'] ?? array()) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $label = self::normalize_varnish_registry_endpoint($detail['server'] ?? '');
            if ('' !== $label) {
                $detail_map[$label] = $detail;
            }
        }

        foreach ($configured as $configured_endpoint) {
            $key = (string) $configured_endpoint['key'];
            $endpoint = (string) $configured_endpoint['endpoint'];
            $profile = isset($stored[$key]) && is_array($stored[$key])
                ? self::sanitize_varnish_endpoint_capability_profile($stored[$key], $settings)
                : self::get_empty_varnish_endpoint_capability_profile($mode, $endpoint, $settings);
            $current_fingerprint = self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings);
            if (!hash_equals((string) $profile['configurationFingerprint'], $current_fingerprint)) {
                $profile = self::get_empty_varnish_endpoint_capability_profile($mode, $endpoint, $settings);
            }
            $profile['adapter'] = 'admin' === $mode ? 'admin-ban' : 'http-unverified';

            $endpoint_result = is_array($endpoint_result_map[$endpoint] ?? null)
                ? $endpoint_result_map[$endpoint]
                : array();
            $detail = is_array($detail_map[$endpoint] ?? null) ? $detail_map[$endpoint] : array();

            if ('admin' === $mode) {
                /*
                 * Public canary reachability is not evidence that this admin
                 * endpoint authenticated or accepted a BAN. Transport evidence
                 * must come from the endpoint's actual control response.
                 */
                $control_connection_verified = !empty($detail['connectionAccepted'])
                    || !empty($endpoint_result['controlConnectionAccepted'])
                    || ($single_endpoint && !empty($result['controlConnectionAccepted']));
                $transport_accepted = !empty($detail['success'])
                    || !empty($endpoint_result['transportAccepted'])
                    || ($single_endpoint && !empty($result['controlTransportAccepted']));
                $endpoint_exact_verified = !empty($endpoint_result['exactInvalidationVerified']);
                if (!$endpoint_exact_verified && $single_endpoint && empty($endpoint_result)) {
                    $endpoint_exact_verified = !empty($result['exactInvalidationVerified'])
                        && $transport_accepted;
                }

                $tested_exact_capabilities = self::normalize_varnish_registry_exact_capabilities((array) ($endpoint_result['testedExactCapabilities'] ?? ($single_endpoint ? ($result['testedExactCapabilities'] ?? array()) : array())
                ));
                if ($endpoint_exact_verified && empty($tested_exact_capabilities)) {
                    $tested_exact_capabilities[] = 'exactBan';
                }
                $profile['controlConnectionVerified'] = $control_connection_verified;
                $profile['reachable'] = $control_connection_verified;
                $profile['exactPurge'] = false;
                $profile['testedAt'] = $tested_at;
                $profile['exactPurgeTestedAt'] = 0;
                $profile['exactBanTestedAt'] = in_array('exactBan', $tested_exact_capabilities, true) ? $tested_at : 0;
                $profile['exactTestedAt'] = $profile['exactBanTestedAt'];

                if ($endpoint_exact_verified) {
                    $proof_expires_at = 0;
                    $profile['exactBan'] = true;
                    $profile['exactRuntimeAvailable'] = true;
                    $profile['proofExpiresAt'] = $proof_expires_at;
                    $profile['exactProofExpiresAt'] = $proof_expires_at;
                    $profile['lastSuccessAt'] = $tested_at;
                    $profile['lastFailureAt'] = 0;
                    $profile['lastFailure'] = '';
                    $profile['source'] = 'isolated-canary';
                    $profile['status'] = 'verified';
                } else {
                    $profile['exactBan'] = false;
                    $profile['exactRuntimeAvailable'] = false;
                    $profile['proofExpiresAt'] = 0;
                    $profile['exactProofExpiresAt'] = 0;

                    $profile['lastFailureAt'] = $tested_at;
                    $profile['lastFailure'] = self::sanitize_varnish_string(
                        (string) ($endpoint_result['message'] ?? ($detail['detail'] ?? ($result['message'] ?? 'The configured Varnish admin endpoint did not verify exact invalidation.')))
                    );
                    $profile['source'] = 'isolated-canary';
                    $profile['status'] = sanitize_key((string) ($endpoint_result['status'] ?? ($result['status'] ?? 'not-verified')));
                }

                $admin_message = self::sanitize_varnish_string((string) ($endpoint_result['message'] ?? ($detail['detail'] ?? ($result['message'] ?? ''))));
                $profile['capabilityOutcomes']['exactPurge'] = self::build_varnish_capability_outcome(
                    false,
                    false,
                    false,
                    true,
                    'admin-mode-no-http-purge',
                    self::maybe_translate('Exact PURGE is not applicable through the configured Varnish admin BAN interface.'),
                    0,
                    0
                );
                $profile['capabilityOutcomes']['exactBan'] = self::build_varnish_capability_outcome(
                    $endpoint_exact_verified,
                    in_array('exactBan', $tested_exact_capabilities, true),
                    true,
                    $endpoint_exact_verified || !in_array(sanitize_key((string) ($endpoint_result['status'] ?? '')), array('observation-incomplete'), true),
                    sanitize_key((string) ($endpoint_result['status'] ?? ($result['status'] ?? 'not-verified'))),
                    $admin_message,
                    $profile['exactBanTestedAt'],
                    $endpoint_exact_verified ? $profile['exactProofExpiresAt'] : 0
                );
                $profile['configurationFingerprint'] = self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings);
                $profiles[$key] = self::sanitize_varnish_endpoint_capability_profile($profile, $settings);
                continue;
            }
            if (!empty($endpoint_result)) {
                $endpoint_tested_at = absint($endpoint_result['time'] ?? $tested_at);
                $verified = !empty($endpoint_result['exactInvalidationVerified']);
                $contract = is_array($endpoint_result['contract'] ?? null) ? $endpoint_result['contract'] : array();
                $contract_verified = !empty($contract['ok'])
                    && !empty($contract['authenticated'])
                    && self::get_varnish_http_contract_version() === absint($contract['version'] ?? 0)
                    && self::get_varnish_http_contract_adapter() === sanitize_key((string) ($contract['adapter'] ?? ''));
                $profile['reachable'] = !empty($endpoint_result['reachable'])
                    || !empty($endpoint_result['transportAccepted']);
                $profile['controlConnectionVerified'] = !empty($endpoint_result['transportAccepted'])
                    || !empty($endpoint_result['exactInvalidationVerified']);
                $tested_exact_capabilities = self::normalize_varnish_registry_exact_capabilities((array) ($endpoint_result['testedExactCapabilities'] ?? array()));
                if ($verified && empty($tested_exact_capabilities)) {
                    $verified_capability = self::normalize_varnish_registry_exact_capability($endpoint_result['verifiedExactCapability'] ?? '');
                    $tested_exact_capabilities[] = in_array($verified_capability, array('exactPurge', 'exactBan'), true)
                        ? $verified_capability
                        : ('PURGE' === $method ? 'exactPurge' : 'exactBan');
                }
                $profile['testedAt'] = $endpoint_tested_at;
                $profile['exactPurgeTestedAt'] = in_array('exactPurge', $tested_exact_capabilities, true) ? $endpoint_tested_at : 0;
                $profile['exactBanTestedAt'] = in_array('exactBan', $tested_exact_capabilities, true) ? $endpoint_tested_at : 0;
                $profile['exactTestedAt'] = max($profile['exactPurgeTestedAt'], $profile['exactBanTestedAt']);

                if (!empty($contract)) {
                    $profile['contractStatus'] = $contract_verified
                        ? 'verified'
                        : sanitize_key((string) ($contract['status'] ?? 'not-available'));
                    $profile['contractMessage'] = self::sanitize_varnish_string((string) ($contract['detail'] ?? self::maybe_translate('The endpoint did not return the authenticated UltraCache HTTP/VCL contract.')));
                }
                if ($contract_verified) {
                    $profile['adapter'] = self::get_varnish_http_contract_adapter();
                    $profile['contractAuthenticated'] = true;
                    $profile['contractId'] = self::get_varnish_http_contract_adapter();
                    $profile['contractCapabilities'] = self::sanitize_varnish_http_contract_capabilities($contract['capabilities'] ?? array());
                    $profile['contractReportedAt'] = $endpoint_tested_at;
                    $profile['contractVersion'] = self::get_varnish_http_contract_version();
                } elseif (!empty($contract)) {
                    $profile['contractAuthenticated'] = false;
                    $profile['contractId'] = '';
                    $profile['contractCapabilities'] = array();
                    $profile['contractReportedAt'] = $endpoint_tested_at;
                    $profile['contractVersion'] = 1;
                }

                $exact_capability_results = is_array($endpoint_result['exactCapabilityResults'] ?? null)
                    ? $endpoint_result['exactCapabilityResults']
                    : array();
                $has_independent_exact_results = is_array($exact_capability_results['exactPurge'] ?? null)
                    && is_array($exact_capability_results['exactBan'] ?? null);
                if ($has_independent_exact_results) {
                    $verified_any = false;
                    $latest_exact_tested_at = 0;
                    foreach (array('exactPurge', 'exactBan') as $exact_capability_name) {
                        $exact_result = (array) $exact_capability_results[$exact_capability_name];
                        $exact_status = sanitize_key((string) ($exact_result['status'] ?? 'not-tested'));
                        $exact_verified = !empty($exact_result['exactInvalidationVerified']);
                        $exact_probe_attempted = !in_array($exact_status, array('canary-create-failed', 'endpoint-route-unavailable', 'canary-not-cacheable', 'production-canary-unavailable'), true);
                        $exact_conclusive = $exact_verified || ($exact_probe_attempted && 'observation-incomplete' !== $exact_status);
                        $exact_result_tested_at = absint($exact_result['time'] ?? $endpoint_tested_at);
                        $exact_proof_expires_at = 0;
                        $profile[$exact_capability_name] = $exact_verified;
                        $profile[$exact_capability_name . 'TestedAt'] = $exact_probe_attempted ? $exact_result_tested_at : 0;
                        $profile['capabilityOutcomes'][$exact_capability_name] = self::build_varnish_capability_outcome(
                            $exact_verified,
                            $exact_probe_attempted,
                            true,
                            $exact_conclusive,
                            $exact_status,
                            self::sanitize_varnish_string((string) ($exact_result['message'] ?? '')),
                            $exact_probe_attempted ? $exact_result_tested_at : 0,
                            $exact_proof_expires_at
                        );
                        $verified_any = $verified_any || $exact_verified;
                        $latest_exact_tested_at = max($latest_exact_tested_at, $exact_probe_attempted ? $exact_result_tested_at : 0);
                    }
                    $profile['exactTestedAt'] = $latest_exact_tested_at;
                    if (!$contract_verified) {
                        if (!empty($profile['exactPurge']) && !empty($profile['exactBan'])) {
                            $profile['adapter'] = 'http-exact-purge-ban';
                        } elseif (!empty($profile['exactPurge'])) {
                            $profile['adapter'] = 'http-exact-purge';
                        } elseif (!empty($profile['exactBan'])) {
                            $profile['adapter'] = 'http-exact-ban';
                        } else {
                            $profile['adapter'] = 'http-unverified';
                        }
                    }
                    $profile['exactRuntimeAvailable'] = $verified_any;
                    $profile['exactProofExpiresAt'] = 0;
                    $profile['proofExpiresAt'] = $profile['exactProofExpiresAt'];
                    if ($verified_any) {
                        $profile['lastSuccessAt'] = $endpoint_tested_at;
                        $profile['lastFailureAt'] = 0;
                        $profile['lastFailure'] = '';
                        $profile['source'] = $contract_verified ? 'independent-exact-canaries-contract' : 'independent-exact-canaries';
                        $profile['status'] = 'verified';
                    } else {
                        $profile['lastFailureAt'] = $endpoint_tested_at;
                        $profile['lastFailure'] = self::sanitize_varnish_string((string) ($endpoint_result['message'] ?? 'Neither exact PURGE nor exact BAN was behavior-verified.'));
                        $profile['source'] = 'independent-exact-canaries';
                        $profile['status'] = 'not-verified';
                    }
                } elseif ($verified) {
                    $proof_expires_at = 0;
                    $verified_exact_capability = self::normalize_varnish_registry_exact_capability($endpoint_result['verifiedExactCapability'] ?? '');
                    if (!in_array($verified_exact_capability, array('exactPurge', 'exactBan'), true)) {
                        $verified_exact_capability = 'PURGE' === $method ? 'exactPurge' : 'exactBan';
                    }
                    $profile['exactPurge'] = 'exactPurge' === $verified_exact_capability;
                    $profile['exactBan'] = 'exactBan' === $verified_exact_capability;
                    if ($contract_verified) {
                        $profile['source'] = 'isolated-endpoint-canary-contract';
                        $profile['status'] = 'verified-contract-v2';
                    } else {
                        $profile['adapter'] = 'exactPurge' === $verified_exact_capability ? 'http-exact-purge' : 'http-exact-ban';
                        $profile['source'] = 'isolated-endpoint-canary';
                        $profile['status'] = 'verified';
                    }
                    $profile['proofExpiresAt'] = $proof_expires_at;
                    $profile['exactProofExpiresAt'] = $proof_expires_at;
                    $profile['exactRuntimeAvailable'] = true;
                    $profile['lastSuccessAt'] = $endpoint_tested_at;
                    $profile['lastFailureAt'] = 0;
                    $profile['lastFailure'] = '';
                } elseif (!$has_independent_exact_results) {
                    // Keep the latest authenticated contract advertisement as
                    // metadata, but remove only the exact behavior proof that
                    // this canary contradicted.
                    $profile['exactPurge'] = false;
                    $profile['exactBan'] = false;
                    $profile['exactRuntimeAvailable'] = false;
                    $profile['exactProofExpiresAt'] = 0;
                    $profile['lastFailureAt'] = $endpoint_tested_at;
                    $profile['lastFailure'] = self::sanitize_varnish_string(
                        (string) ($endpoint_result['message'] ?? 'The isolated endpoint canary did not verify exact invalidation.')
                    );
                    $profile['source'] = $contract_verified ? 'isolated-endpoint-canary-contract' : 'isolated-endpoint-canary';
                    $profile['status'] = sanitize_key((string) ($endpoint_result['status'] ?? 'not-verified'));
                }

                $profile['configurationFingerprint'] = self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings);
                $profiles[$key] = self::sanitize_varnish_endpoint_capability_profile($profile, $settings);
                continue;
            }

            $accepted = !empty($detail['success']);
            $exact_verified = $single_endpoint && !empty($result['exactInvalidationVerified']);
            $profile['reachable'] = $accepted || ($single_endpoint && !empty($result['controlTransportAccepted']));
            $tested_exact_capabilities = self::normalize_varnish_registry_exact_capabilities((array) ($result['testedExactCapabilities'] ?? array()));
            if ($exact_verified && empty($tested_exact_capabilities)) {
                $verified_capability = self::normalize_varnish_registry_exact_capability($result['verifiedExactCapability'] ?? '');
                $tested_exact_capabilities[] = in_array($verified_capability, array('exactPurge', 'exactBan'), true)
                    ? $verified_capability
                    : ('PURGE' === $method ? 'exactPurge' : 'exactBan');
            }
            $profile['testedAt'] = $tested_at;
            $profile['exactPurgeTestedAt'] = in_array('exactPurge', $tested_exact_capabilities, true) ? $tested_at : 0;
            $profile['exactBanTestedAt'] = in_array('exactBan', $tested_exact_capabilities, true) ? $tested_at : 0;
            $profile['exactTestedAt'] = max($profile['exactPurgeTestedAt'], $profile['exactBanTestedAt']);
            if ($exact_verified) {
                $proof_expires_at = 0;
                $verified_exact_capability = self::normalize_varnish_registry_exact_capability($result['verifiedExactCapability'] ?? '');
                if (!in_array($verified_exact_capability, array('exactPurge', 'exactBan'), true)) {
                    $verified_exact_capability = 'PURGE' === $method ? 'exactPurge' : 'exactBan';
                }
                $profile['exactPurge'] = 'exactPurge' === $verified_exact_capability;
                $profile['exactBan'] = 'exactBan' === $verified_exact_capability;
                $profile['proofExpiresAt'] = $proof_expires_at;
                $profile['exactProofExpiresAt'] = $proof_expires_at;
                $profile['exactRuntimeAvailable'] = true;
                $profile['lastSuccessAt'] = $tested_at;
                $profile['lastFailureAt'] = 0;
                $profile['lastFailure'] = '';
                $profile['source'] = 'isolated-canary';
                $profile['status'] = 'verified';
            } elseif ($single_endpoint) {
                $profile['exactPurge'] = false;
                $profile['exactBan'] = false;
                $profile['exactRuntimeAvailable'] = false;
                $profile['exactProofExpiresAt'] = 0;
                $profile['lastFailureAt'] = $tested_at;
                $profile['lastFailure'] = self::sanitize_varnish_string((string) ($result['message'] ?? 'Exact invalidation was not verified.'));
                $profile['source'] = 'isolated-canary';
                $profile['status'] = sanitize_key((string) ($result['status'] ?? 'not-verified'));
            } elseif (empty($profile['exactPurge']) && empty($profile['exactBan'])) {
                $profile['lastFailureAt'] = $tested_at;
                $profile['lastFailure'] = self::sanitize_varnish_string((string) ($result['message'] ?? 'Per-endpoint canary proof was not available.'));
                $profile['source'] = 'per-endpoint-proof-missing';
                $profile['status'] = 'per-endpoint-proof-missing';
            }


            $profile['configurationFingerprint'] = self::get_varnish_registry_endpoint_fingerprint($mode, $endpoint, $settings);
            $profiles[$key] = self::sanitize_varnish_endpoint_capability_profile($profile, $settings);
        }

        self::write_varnish_endpoint_capability_registry($profiles, $settings);

        return self::get_varnish_endpoint_capability_registry_status($settings, $result);
    }

    /**
     * Return the fail-closed capability definitions for one endpoint profile.
     *
     * Capability support, current transport reachability, and behavior proof are
     * deliberately separate. Native admin BAN has a configuration-level
     * transport contract, but every runtime behavior still requires a current
     * isolated proof.
     *
     * @param array $profile Endpoint profile.
     * @return array<string,array<string,mixed>>
     */
    private static function get_varnish_endpoint_capability_definitions(array $profile)
    {
        return array(
            'exactPurge' => array('testedField' => 'exactPurgeTestedAt', 'proofField' => 'exactProofExpiresAt', 'availabilityField' => 'exactRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'exactBan' => array('testedField' => 'exactBanTestedAt', 'proofField' => 'exactProofExpiresAt', 'availabilityField' => 'exactRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'batchBan' => array('testedField' => 'batchTestedAt', 'proofField' => 'batchProofExpiresAt', 'availabilityField' => 'batchRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'htmlFlush' => array('testedField' => 'htmlFlushTestedAt', 'proofField' => 'htmlFlushProofExpiresAt', 'availabilityField' => 'topologyRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'hostFlush' => array('testedField' => 'hostFlushTestedAt', 'proofField' => 'hostFlushProofExpiresAt', 'availabilityField' => 'topologyRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'softPurge' => array('testedField' => 'softPurgeTestedAt', 'proofField' => 'softPurgeBehaviorProofExpiresAt', 'availabilityField' => 'softPurgeRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'originRevalidation' => array('testedField' => 'originRevalidationTestedAt', 'proofField' => 'originRevalidationProofExpiresAt', 'availabilityField' => 'softPurgeRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
            'swr' => array('testedField' => 'swrTestedAt', 'proofField' => 'swrProofExpiresAt', 'availabilityField' => 'softPurgeRuntimeAvailable', 'proofRequired' => true, 'requiresReachable' => true),
        );
    }

    /**
     * Resolve one capability into an explicit, fail-closed state.
     *
     * @param array  $profile    Endpoint profile.
     * @param string $capability Capability field.
     * @return array<string,mixed>
     */
    private static function get_varnish_endpoint_capability_state(array $profile, $capability)
    {
        $capability = (string) $capability;
        $definitions = self::get_varnish_endpoint_capability_definitions($profile);
        $definition = is_array($definitions[$capability] ?? null) ? $definitions[$capability] : array();
        $result = array(
            'state' => 'unverified',
            'current' => false,
            'contractSupported' => false,
            'runtimeReachable' => false,
            'behaviorVerified' => false,
            'tested' => false,
            'testedAt' => 0,
            'expired' => false,
            'proofRequired' => true,
            'proofExpiresAt' => 0,
            'reasonCode' => 'probe-not-run',
            'message' => self::maybe_translate('The capability probe has not run for this endpoint.'),
            'applicable' => true,
            'conclusive' => false,
        );
        if (empty($definition)) {
            return $result;
        }

        $persisted_outcomes = is_array($profile['capabilityOutcomes'] ?? null) ? $profile['capabilityOutcomes'] : array();
        $persisted_outcome = is_array($persisted_outcomes[$capability] ?? null)
            ? self::sanitize_varnish_capability_outcome($persisted_outcomes[$capability])
            : array();
        if (!empty($persisted_outcome)) {
            $result['outcome'] = $persisted_outcome;
            $result['reasonCode'] = (string) $persisted_outcome['reasonCode'];
            $result['message'] = (string) $persisted_outcome['message'];
            $result['applicable'] = !empty($persisted_outcome['applicable']);
            $result['conclusive'] = !empty($persisted_outcome['conclusive']);
        }

        $tested_field = (string) ($definition['testedField'] ?? '');
        $tested_at = '' !== $tested_field ? absint($profile[$tested_field] ?? 0) : 0;
        if (!empty($persisted_outcome)) {
            $tested_at = max($tested_at, absint($persisted_outcome['testedAt'] ?? 0));
        }
        $result['testedAt'] = $tested_at;
        $result['tested'] = !empty($persisted_outcome)
            ? !empty($persisted_outcome['probeAttempted'])
            : $tested_at > 0;

        // Explicit terminal outcomes are authoritative. In particular, a
        // topology result such as not-applicable must never be reclassified as
        // not-supported merely because Admin mode has a native host-BAN command.
        if (!empty($persisted_outcome)) {
            $persisted_state = sanitize_key((string) ($persisted_outcome['state'] ?? 'not-tested'));
            if (in_array($persisted_state, array(
                'not-supported',
                'not-applicable',
                'not-tested',
                'observation-incomplete',
                'configuration-changed',
            ), true)) {
                $result['state'] = $persisted_state;
                $result['testedAt'] = absint($persisted_outcome['testedAt'] ?? 0);
                $result['tested'] = !empty($persisted_outcome['probeAttempted']);
                $result['expired'] = false;
                $result['proofExpiresAt'] = 0;
                return $result;
            }
        }

        $native_admin_contract = 'admin' === self::sanitize_varnish_mode($profile['mode'] ?? 'http')
            && 'admin-ban' === sanitize_key((string) ($profile['adapter'] ?? ''))
            && in_array($capability, array('exactBan', 'batchBan', 'htmlFlush', 'hostFlush'), true);
        $http_contract_map = array(
            'exactPurge' => 'exact-purge',
            'exactBan' => 'exact-ban',
            'batchBan' => 'batch-ban',
            'htmlFlush' => 'html-ban',
            'hostFlush' => 'host-ban',
            'softPurge' => 'soft-purge',
            'originRevalidation' => 'origin-revalidation',
            'swr' => 'swr',
        );
        $http_contract_capability = (string) ($http_contract_map[$capability] ?? '');
        $http_contract_supported = '' !== $http_contract_capability
            && self::get_varnish_http_contract_adapter() === sanitize_key((string) ($profile['adapter'] ?? ''))
            && !empty($profile['contractAuthenticated'])
            && self::get_varnish_http_contract_version() === absint($profile['contractVersion'] ?? 0)
            && in_array($http_contract_capability, (array) ($profile['contractCapabilities'] ?? array()), true);
        $contract_supported = !empty($profile[$capability]) || $native_admin_contract || $http_contract_supported;
        if (!$contract_supported) {
            if (!empty($persisted_outcome)) {
                $result['state'] = (string) $persisted_outcome['state'];
                $result['tested'] = !empty($persisted_outcome['probeAttempted']);
                $result['testedAt'] = absint($persisted_outcome['testedAt']);
                $result['expired'] = false;
                $result['proofExpiresAt'] = 0;
            } elseif ($result['tested']) {
                $result['state'] = 'not-supported';
                $result['reasonCode'] = 'behavior-not-verified';
                $result['message'] = self::maybe_translate('The capability probe ran but did not verify the required behavior.');
                $result['conclusive'] = true;
            }
            return $result;
        }

        $result['contractSupported'] = true;
        $result['proofRequired'] = !empty($definition['proofRequired']);
        $requires_reachable = !empty($definition['requiresReachable']);
        $result['runtimeReachable'] = !$requires_reachable || !empty($profile['reachable']);
        if (!$result['runtimeReachable']) {
            $result['state'] = 'contract-supported';
            return $result;
        }

        if (empty($profile[$capability])) {
            $result['state'] = $result['tested'] ? 'not-supported' : 'contract-supported';
            return $result;
        }

        $availability_field = (string) ($definition['availabilityField'] ?? '');
        if ('' !== $availability_field && empty($profile[$availability_field])) {
            $result['state'] = 'runtime-reachable';
            return $result;
        }

        if ($result['proofRequired']) {
            // A verified behavior probe has no wall-clock expiry. The persisted
            // configuration/contract fingerprint remains the invalidation
            // boundary. Legacy expiry fields are deliberately ignored.
            $proof_field = (string) ($definition['proofField'] ?? '');
            if (!$result['tested'] && '' !== $proof_field && absint($profile[$proof_field] ?? 0) > 0) {
                $result['tested'] = true;
            }
            $result['proofExpiresAt'] = 0;
        }

        $result['state'] = 'behavior-verified';
        $result['current'] = true;
        $result['behaviorVerified'] = true;
        $result['reasonCode'] = 'behavior-verified';
        $result['message'] = !empty($persisted_outcome['message'])
            ? (string) $persisted_outcome['message']
            : self::maybe_translate('The production behavior was verified on this endpoint.');
        $result['conclusive'] = true;
        return $result;
    }

    /**
     * Return whether one profile capability is current and verified.
     *
     * @param array  $profile    Endpoint profile.
     * @param string $capability Capability field.
     * @return bool
     */
    private static function is_varnish_endpoint_capability_current(array $profile, $capability)
    {
        $state = self::get_varnish_endpoint_capability_state($profile, $capability);
        return !empty($state['current']);
    }


    /**
     * Persist one behavior-verified batch BAN capability per control endpoint.
     *
     * @param array $capability Batch BAN diagnostic result.
     * @return array<string,mixed>
     */
    protected static function set_varnish_batch_ban_capability(array $capability)
    {
        $settings = self::get_varnish_cli_settings();
        $tested_at = absint($capability['testedAt'] ?? time());
        $endpoint_capabilities = is_array($capability['endpointCapabilities'] ?? null)
            ? $capability['endpointCapabilities']
            : array();
        $updated = 0;

        foreach ($endpoint_capabilities as $endpoint_capability) {
            if (!is_array($endpoint_capability)) {
                continue;
            }
            $endpoint = self::normalize_varnish_registry_endpoint($endpoint_capability['endpoint'] ?? '');
            if ('' === $endpoint) {
                continue;
            }
            $verified = !empty($endpoint_capability['batchBan']);
            $tested = !empty($endpoint_capability['tested']);
            $reachable = !empty($endpoint_capability['reachable']);
            $applicable = !array_key_exists('applicable', $endpoint_capability) || !empty($endpoint_capability['applicable']);
            $status = sanitize_key((string) ($endpoint_capability['status'] ?? ($capability['status'] ?? 'not-tested')));
            $message = self::sanitize_varnish_string((string) ($endpoint_capability['message'] ?? ($capability['message'] ?? '')));
            $conclusive = $verified || !empty($endpoint_capability['conclusive']) || ($tested && 'observation-incomplete' !== $status);
            self::persist_varnish_endpoint_capability_outcome(
                (string) ($settings['mode'] ?? 'http'),
                $endpoint,
                'batchBan',
                self::build_varnish_capability_outcome(
                    $verified,
                    $tested,
                    $applicable,
                    $conclusive,
                    $status,
                    $message,
                    $tested ? $tested_at : 0,
                    0
                ),
                $settings
            );
            if (!$tested) {
                continue;
            }
            $changes = array(
                'batchBan' => $verified,
                'batchRuntimeAvailable' => $verified,
                'batchProofExpiresAt' => 0,
                'batchTestedAt' => $tested ? $tested_at : 0,
                'source' => 'batch-ban-canary',
                'status' => sanitize_key((string) ($endpoint_capability['status'] ?? ($capability['status'] ?? 'inconclusive'))),
            );
            if ($tested) {
                $changes['testedAt'] = $tested_at;
            }
            if ($verified || $reachable) {
                $changes['reachable'] = true;
            }
            if ($tested && !$verified) {
                $changes['lastFailureAt'] = $tested_at;
                $changes['lastFailure'] = self::sanitize_varnish_string((string) ($endpoint_capability['message'] ?? ($capability['message'] ?? 'Batch BAN was not behavior-verified.')));
            }
            self::update_varnish_endpoint_capability_profile(
                (string) ($settings['mode'] ?? 'http'),
                $endpoint,
                $changes,
                $settings
            );
            $updated++;
        }

        return array(
            'updatedEndpointCount' => $updated,
            'endpointCapabilityRegistry' => self::get_varnish_endpoint_capability_registry_status($settings),
        );
    }

    /**
     * Return capabilities that belong to the public site route rather than to a
     * configured control socket or HTTP purge listener.
     *
     * @param array $settings    Normalized Varnish settings.
     * @param array $test_result Optional in-memory Redetect Varnish Capabilities result.
     * @return array<string,mixed>
     */
    private static function get_varnish_public_path_capability_status(array $settings = array(), array $test_result = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $esi = method_exists(static::class, 'get_varnish_esi_capability_status')
            ? self::get_varnish_esi_capability_status()
            : array();
        $using_test_result = !empty($test_result);
        $basic = $using_test_result ? $test_result : array();
        if (!is_array($basic)) {
            $basic = array();
        }
        $variant = is_array($basic['htmlVariantCapability'] ?? null)
            ? $basic['htmlVariantCapability']
            : (method_exists(static::class, 'get_varnish_html_variant_capability_status')
                ? self::get_varnish_html_variant_capability_status()
                : array());
        $variant_status = sanitize_key((string) ($variant['status'] ?? 'not-tested'));
        $variant_contract_current = empty($variant)
            || !method_exists(static::class, 'varnish_capability_contracts_match')
            || self::varnish_capability_contracts_match($variant, array('variant'));
        if (!$variant_contract_current) {
            $variant = array();
            $variant_status = 'configuration-changed';
        }
        $variant_tested = array_key_exists('tested', $variant)
            ? !empty($variant['tested'])
            : array_key_exists('applicable', $variant);
        $variant_supported = $variant_tested
            && !empty($variant['applicable'])
            && !empty($variant['supported']);
        $variant_expires_at = 0;

        $esi_configuration_current = empty($esi['configurationChanged']);
        $public_esi = $esi_configuration_current && !empty($esi['supported']) && !empty($esi['verified']);
        $private_esi = $public_esi
            && !empty($esi['privateTransportVerified'])
            && !empty($esi['privateSessionIsolationVerified'])
            && !empty($esi['privateParentCacheVerified'])
            && !empty($esi['privateFragmentNoStoreVerified'])
            && !empty($esi['privateOnerrorVerified']);
        $esi_tested_at = absint($esi['testedAt'] ?? 0);
        $esi_status = $esi_configuration_current
            ? sanitize_key((string) ($esi['status'] ?? 'not-tested'))
            : 'configuration-changed';
        $esi_tested = $esi_tested_at > 0
            && !in_array($esi_status, array('not-tested', 'probe-skipped', 'configuration-incomplete', 'configuration-changed'), true);
        $esi_expires_at = 0;

        return array(
            'scope' => 'public-path',
            'mode' => self::sanitize_varnish_mode($settings['mode'] ?? 'http'),
            'publicEsi' => $public_esi,
            'privateEsi' => $private_esi,
            'htmlVariants' => $variant_supported,
            'esiTested' => $esi_tested,
            'variantTested' => $variant_tested,
            'esiTestedAt' => $esi_tested_at,
            'variantTestedAt' => absint($variant['time'] ?? ($basic['time'] ?? 0)),
            'esiProofExpiresAt' => $esi_expires_at,
            'variantProofExpiresAt' => $variant_expires_at,
            'esiStatus' => $esi_status,
            'variantStatus' => $variant_status,
            'esiMessage' => self::sanitize_varnish_string((string) ($esi['message'] ?? '')),
            'variantMessage' => self::sanitize_varnish_string((string) ($variant['message'] ?? '')),
            'configurationCurrent' => $variant_contract_current && $esi_configuration_current,
            'variantConfigurationCurrent' => $variant_contract_current,
            'esiConfigurationCurrent' => $esi_configuration_current,
        );
    }

    /**
     * Return the effective per-endpoint capability registry status.
     *
     * Effective capabilities use the intersection of all configured endpoints.
     * This prevents a mixed topology from being treated as fully managed before
     * every active endpoint has independently proven the required behavior.
     *
     * @param array $settings           Normalized Varnish settings.
     * @param array $public_path_result Optional in-memory public-path test result.
     * @return array<string,mixed>
     */
    public static function get_varnish_endpoint_capability_registry_status(array $settings = array(), array $public_path_result = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $profiles = self::get_current_varnish_endpoint_capability_profiles($settings);
        $capability_fields = array(
            'exactPurge',
            'exactBan',
            'batchBan',
            'htmlFlush',
            'hostFlush',
            'softPurge',
            'originRevalidation',
            'swr',
        );
        $effective = array_fill_keys($capability_fields, !empty($profiles));
        $capability_counts = array();
        foreach ($capability_fields as $capability_field) {
            $capability_counts[$capability_field] = array(
                'tested' => 0,
                'contractSupported' => 0,
                'behaviorVerified' => 0,
                'expired' => 0,
                'testedAt' => 0,
                'proofExpiresAt' => array(),
                'reasons' => array(),
                'states' => array(),
            );
        }
        $endpoint_rows = array();
        $verified_exact_count = 0;
        $reachable_count = 0;
        $tested_count = 0;
        $contract_endpoint_count = 0;
        $proof_expiries = array();
        $proof_expiries_by_capability = array(
            'exact' => array(),
            'batch' => array(),
            'topology' => array(),
            'softPurge' => array(),
        );
        $signatures = array();

        foreach ($profiles as $profile) {
            $exact = self::is_varnish_endpoint_capability_current($profile, 'exactPurge')
                || self::is_varnish_endpoint_capability_current($profile, 'exactBan');
            $expires_at = absint($profile['exactProofExpiresAt'] ?? 0);
            if ($expires_at > 0) {
                $proof_expiries[] = $expires_at;
                $proof_expiries_by_capability['exact'][] = $expires_at;
            }
            foreach (array(
                'batch' => 'batchProofExpiresAt',
                'topology' => 'topologyProofExpiresAt',
                'softPurge' => 'softPurgeProofExpiresAt',
            ) as $expiry_key => $expiry_field) {
                $capability_expiry = absint($profile[$expiry_field] ?? 0);
                if ($capability_expiry > 0) {
                    $proof_expiries_by_capability[$expiry_key][] = $capability_expiry;
                }
            }
            if ($exact) {
                $verified_exact_count++;
            }
            if (absint($profile['testedAt'] ?? 0) > 0) {
                $tested_count++;
            }
            if (!empty($profile['reachable'])) {
                $reachable_count++;
            }
            if (
                self::get_varnish_http_contract_adapter() === sanitize_key((string) ($profile['adapter'] ?? ''))
                && !empty($profile['contractAuthenticated'])
                && self::get_varnish_http_contract_version() === absint($profile['contractVersion'] ?? 0)
            ) {
                $contract_endpoint_count++;
            }

            $signature = array();
            $capability_states = array();
            $contract_supported = array();
            $behavior_verified = array();
            foreach ($capability_fields as $field) {
                $capability_state = self::get_varnish_endpoint_capability_state($profile, $field);
                $capability_states[$field] = $capability_state;
                $signature[$field] = !empty($capability_state['current']);
                $contract_supported[$field] = !empty($capability_state['contractSupported']);
                $behavior_verified[$field] = !empty($capability_state['behaviorVerified']);
                $effective[$field] = $effective[$field] && $signature[$field];
                if (!empty($capability_state['tested'])) {
                    $capability_counts[$field]['tested']++;
                    $capability_counts[$field]['testedAt'] = max(
                        absint($capability_counts[$field]['testedAt']),
                        absint($capability_state['testedAt'] ?? 0)
                    );
                }
                if (!empty($capability_state['contractSupported'])) {
                    $capability_counts[$field]['contractSupported']++;
                }
                if (!empty($capability_state['behaviorVerified'])) {
                    $capability_counts[$field]['behaviorVerified']++;
                }
                if (!empty($capability_state['expired'])) {
                    $capability_counts[$field]['expired']++;
                }
                if (absint($capability_state['proofExpiresAt'] ?? 0) > 0) {
                    $capability_counts[$field]['proofExpiresAt'][] = absint($capability_state['proofExpiresAt']);
                }
                $endpoint_state = sanitize_key((string) ($capability_state['state'] ?? 'not-tested'));
                if ('' === $endpoint_state) {
                    $endpoint_state = 'not-tested';
                }
                $capability_counts[$field]['states'][$endpoint_state] = absint($capability_counts[$field]['states'][$endpoint_state] ?? 0) + 1;
                $reason_code = sanitize_key((string) ($capability_state['reasonCode'] ?? ''));
                $reason_message = self::sanitize_varnish_string((string) ($capability_state['message'] ?? ''));
                if ('' !== $reason_code || '' !== $reason_message) {
                    $reason_key = $reason_code . '|' . $reason_message;
                    $capability_counts[$field]['reasons'][$reason_key] = array(
                        'reasonCode' => '' !== $reason_code ? $reason_code : 'unspecified',
                        'message' => $reason_message,
                    );
                }
            }
            $signatures[] = hash('sha256', (string) wp_json_encode($signature));

            $row = $profile;
            $row['currentCapabilities'] = $signature;
            $row['contractSupportedCapabilities'] = $contract_supported;
            $row['behaviorVerifiedCapabilities'] = $behavior_verified;
            $row['capabilityStates'] = $capability_states;
            $row['controlConnectionVerified'] = !empty($profile['controlConnectionVerified']);
            $row['runtimeReachable'] = !empty($profile['reachable']);
            $row['exactInvalidation'] = $exact;
            $row['proofCurrent'] = $exact;
            unset($row['configurationFingerprint']);
            $endpoint_rows[] = $row;
        }

        $endpoint_count = count($endpoint_rows);
        $exact_all = $endpoint_count > 0 && $verified_exact_count === $endpoint_count;
        $mixed = count(array_unique($signatures)) > 1;
        $status = 'unconfigured';
        if ($endpoint_count > 0) {
            if (0 === $tested_count) {
                $status = 'untested';
            } elseif ($exact_all) {
                $status = $mixed ? 'verified-mixed-advanced' : 'verified';
            } elseif ($verified_exact_count > 0) {
                $status = 'partial';
            } elseif ($reachable_count > 0) {
                $status = 'reachable-unverified';
            } else {
                $status = 'unverified';
            }
        }

        $proof_expiry_status = array();
        foreach ($proof_expiries_by_capability as $capability => $expiries) {
            $proof_expiry_status[$capability] = !empty($expiries) ? min($expiries) : 0;
        }
        $aggregate_capability_states = array();
        foreach ($capability_fields as $field) {
            $counts = $capability_counts[$field];
            $capability_tested_count = absint($counts['tested']);
            $capability_contract_count = absint($counts['contractSupported']);
            $capability_verified_count = absint($counts['behaviorVerified']);
            $capability_expired_count = absint($counts['expired']);
            $state_counts = is_array($counts['states'] ?? null) ? $counts['states'] : array();
            $not_applicable_count = absint($state_counts['not-applicable'] ?? 0);
            $observation_incomplete_count = absint($state_counts['observation-incomplete'] ?? 0);
            $configuration_changed_count = absint($state_counts['configuration-changed'] ?? 0);
            $aggregate_state = 'not-tested';
            if (0 === $endpoint_count) {
                $aggregate_state = 'unconfigured';
            } elseif ($capability_verified_count === $endpoint_count) {
                $aggregate_state = 'behavior-verified';
            } elseif ($not_applicable_count === $endpoint_count) {
                $aggregate_state = 'not-applicable';
            } elseif ($capability_verified_count > 0) {
                $aggregate_state = 'partial';
            } elseif ($configuration_changed_count > 0) {
                $aggregate_state = 'configuration-changed';
            } elseif ($observation_incomplete_count > 0) {
                $aggregate_state = 'observation-incomplete';
            } elseif ($capability_tested_count === $endpoint_count) {
                $aggregate_state = 'not-supported';
            } elseif ($capability_tested_count > 0) {
                $aggregate_state = 'partially-tested';
            } elseif ($capability_contract_count === $endpoint_count) {
                $aggregate_state = 'contract-supported';
            }
            $reason_values = array_values((array) ($counts['reasons'] ?? array()));
            $reason_count = count($reason_values);
            $single_reason = 1 === $reason_count ? (array) $reason_values[0] : array();
            $aggregate_capability_states[$field] = array(
                'state' => $aggregate_state,
                'tested' => $capability_tested_count > 0,
                'testedAllEndpoints' => $endpoint_count > 0 && $capability_tested_count === $endpoint_count,
                'testedEndpointCount' => $capability_tested_count,
                'contractSupported' => $capability_contract_count > 0,
                'contractSupportedAllEndpoints' => $endpoint_count > 0 && $capability_contract_count === $endpoint_count,
                'contractSupportedEndpointCount' => $capability_contract_count,
                'behaviorVerified' => $capability_verified_count > 0,
                'behaviorVerifiedAllEndpoints' => $endpoint_count > 0 && $capability_verified_count === $endpoint_count,
                'behaviorVerifiedEndpointCount' => $capability_verified_count,
                'expiredEndpointCount' => $capability_expired_count,
                'testedAt' => absint($counts['testedAt']),
                'proofExpiresAt' => !empty($counts['proofExpiresAt']) ? min($counts['proofExpiresAt']) : 0,
                'reasons' => $reason_values,
                'reasonCode' => 1 === $reason_count
                    ? (string) ($single_reason['reasonCode'] ?? 'unspecified')
                    : ($reason_count > 1 ? 'mixed-endpoint-reasons' : 'probe-not-run'),
                'message' => 1 === $reason_count
                    ? (string) ($single_reason['message'] ?? '')
                    : ($reason_count > 1
                        ? self::maybe_translate('Configured endpoints reported different capability outcomes.')
                        : self::maybe_translate('The capability probe has not run for the configured endpoint set.')),
            );
        }
        $public_path = self::get_varnish_public_path_capability_status($settings, $public_path_result);
        $effective['publicEsi'] = !empty($public_path['publicEsi']);
        $effective['privateEsi'] = !empty($public_path['privateEsi']);
        $effective['htmlVariants'] = !empty($public_path['htmlVariants']);

        return array(
            'schema' => self::get_varnish_endpoint_capability_registry_schema(),
            'status' => $status,
            'mode' => self::sanitize_varnish_mode($settings['mode'] ?? 'http'),
            'configuredEndpointCount' => $endpoint_count,
            'testedEndpointCount' => $tested_count,
            'reachableEndpointCount' => $reachable_count,
            'verifiedExactEndpointCount' => $verified_exact_count,
            'contractEndpointCount' => $contract_endpoint_count,
            'contractAllEndpoints' => $endpoint_count > 0 && $contract_endpoint_count === $endpoint_count,
            'unverifiedExactEndpointCount' => max(0, $endpoint_count - $verified_exact_count),
            'mixedTopology' => $mixed,
            'exactInvalidationVerified' => $exact_all,
            'proofExpiresAt' => !empty($proof_expiries) ? min($proof_expiries) : 0,
            'proofExpiresAtByCapability' => $proof_expiry_status,
            'capabilityStates' => $aggregate_capability_states,
            'effective' => array_merge($effective, array(
                'exactInvalidation' => $exact_all,
                'htmlOrHostFlush' => !empty($effective['htmlFlush']) || !empty($effective['hostFlush']),
            )),
            'endpoints' => $endpoint_rows,
            'publicPath' => $public_path,
            'message' => $exact_all
                ? self::maybe_translate('Every configured Varnish endpoint has current exact-invalidation capability proof.')
                : ($verified_exact_count > 0
                    ? self::maybe_translate('Only part of the configured Varnish endpoint topology has exact-invalidation proof.')
                    : self::maybe_translate('No configured Varnish endpoint has current exact-invalidation proof.')),
        );
    }
}
