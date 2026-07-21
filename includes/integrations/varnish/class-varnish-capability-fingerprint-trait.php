<?php
/**
 * Independent Varnish capability contract fingerprints for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Capability_Fingerprint_Trait
{
    /**
     * Return the supported independent capability contracts.
     *
     * Each contract includes only configuration that can affect that specific
     * runtime behavior. Unrelated settings therefore do not invalidate proof.
     *
     * @return array<string,int>
     */
    private static function get_varnish_capability_contract_versions()
    {
        return array(
            'schema'            => 2,
            'transport'         => 1,
            'html-invalidation' => 1,
            'variant'           => 1,
            'soft-purge'        => 1,
            'refill'            => 2,
        );
    }

    /**
     * Normalize endpoint identity without retaining credentials.
     *
     * @param array $servers Configured endpoints.
     * @return array<int,string>
     */
    private static function normalize_varnish_capability_endpoints(array $servers)
    {
        $normalized = array();
        foreach ($servers as $server) {
            $server = strtolower(trim((string) $server));
            if ('' !== $server) {
                $normalized[] = $server;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Normalize one or more contract names.
     *
     * @param string|array $contracts Requested contracts.
     * @return array<int,string>
     */
    private static function normalize_varnish_capability_contracts($contracts)
    {
        $allowed = array('transport', 'html-invalidation', 'variant', 'soft-purge', 'refill');
        $normalized = array();
        foreach ((array) $contracts as $contract) {
            $contract = sanitize_key((string) $contract);
            if (in_array($contract, $allowed, true)) {
                $normalized[] = $contract;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Return normalized Varnish configuration used by contract builders.
     *
     * @param array $settings           Optional normalized Varnish settings.
     * @param array $dashboard_settings Optional dashboard settings.
     * @return array<string,mixed>
     */
    private static function get_varnish_capability_context(array $settings = array(), array $dashboard_settings = array())
    {
        if (empty($dashboard_settings)) {
            $dashboard_settings = self::get_dashboard_settings();
        }

        $mode = isset($settings['mode'])
            ? self::sanitize_varnish_mode($settings['mode'])
            : self::sanitize_varnish_mode($dashboard_settings['varnishCliMode'] ?? 'http');

        if (isset($settings['servers']) && is_array($settings['servers'])) {
            $servers = $settings['servers'];
        } else {
            $servers_raw = self::sanitize_varnish_servers_string(
                $dashboard_settings['varnishCliServers'] ?? '',
                $mode
            );
            $servers = array_values(array_filter(array_map('trim', preg_split('/\s+/', $servers_raw))));
        }

        $method = isset($settings['method'])
            ? strtoupper(trim((string) $settings['method']))
            : strtoupper(trim((string) ($dashboard_settings['varnishCliMethod'] ?? 'BAN')));
        $method = 'PURGE' === $method ? 'PURGE' : 'BAN';

        $invalidation_strategy = self::sanitize_varnish_invalidation_strategy(
            $settings['invalidationStrategy'] ?? ($dashboard_settings['varnishInvalidationStrategy'] ?? strtolower($method))
        );
        $stale_seconds = max(
            0,
            min(
                86400,
                absint($settings['staleWhileRevalidateSeconds'] ?? ($dashboard_settings['varnishStaleWhileRevalidateSeconds'] ?? 0))
            )
        );
        $secret_configured = isset($settings['secretConfigured'])
            ? !empty($settings['secretConfigured'])
            : ('' !== (function_exists('ultracache_get_varnish_password') ? (string) ultracache_get_varnish_password() : ''));

        $variant_policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($dashboard_settings)
            : array(
                'enabled' => false,
                'mode' => 'webp',
                'fallback' => 'original',
                'buckets' => array('orig'),
                'vary_accept' => false,
            );
        $active_buckets = array_values(array_unique(array_map('strval', (array) ($variant_policy['buckets'] ?? array('orig')))));
        sort($active_buckets, SORT_STRING);

        return array(
            'dashboardSettings' => $dashboard_settings,
            'mode' => $mode,
            'servers' => self::normalize_varnish_capability_endpoints($servers),
            'method' => $method,
            'invalidationStrategy' => $invalidation_strategy,
            'staleWhileRevalidateSeconds' => $stale_seconds,
            'secretConfigured' => $secret_configured,
            'variantPolicy' => $variant_policy,
            'activeBuckets' => $active_buckets,
        );
    }

    /**
     * Build one independent capability payload.
     *
     * @param string $contract           Contract name.
     * @param array  $settings           Optional normalized Varnish settings.
     * @param array  $dashboard_settings Optional dashboard settings.
     * @return array<string,mixed>
     */
    private static function get_varnish_capability_contract_payload($contract, array $settings = array(), array $dashboard_settings = array())
    {
        $contract = sanitize_key((string) $contract);
        $versions = self::get_varnish_capability_contract_versions();
        $context = self::get_varnish_capability_context($settings, $dashboard_settings);
        $transport_payload = array(
            'version' => (int) ($versions['transport'] ?? 1),
            'mode' => $context['mode'],
            'endpoints' => $context['servers'],
            'method' => $context['method'],
            'secretConfigured' => !empty($context['secretConfigured']),
        );
        $transport_fingerprint = hash('sha256', (string) wp_json_encode($transport_payload));

        if ('transport' === $contract) {
            return $transport_payload;
        }

        if ('html-invalidation' === $contract) {
            $method_contract = 'http-purge-host';
            if ('admin' === $context['mode']) {
                $method_contract = 'admin-ban-html';
            } elseif ('BAN' === $context['method']) {
                $method_contract = 'http-ban-html';
            }

            return array(
                'version' => (int) ($versions['html-invalidation'] ?? 1),
                'transportFingerprint' => $transport_fingerprint,
                'methodContract' => $method_contract,
                'invalidationStrategy' => $context['invalidationStrategy'],
                'hostScope' => array(
                    'homeUrl' => (string) home_url('/'),
                    'siteUrl' => (string) site_url('/'),
                    'blogId' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
                ),
            );
        }

        if ('variant' === $contract) {
            $variant_policy = is_array($context['variantPolicy']) ? $context['variantPolicy'] : array();
            return array(
                'version' => (int) ($versions['variant'] ?? 1),
                'enabled' => !empty($variant_policy['enabled']),
                'outputMode' => sanitize_key((string) ($variant_policy['mode'] ?? 'webp')),
                'fallbackMode' => sanitize_key((string) ($variant_policy['fallback'] ?? 'original')),
                'activeBuckets' => $context['activeBuckets'],
                'varyAccept' => !empty($variant_policy['vary_accept']),
            );
        }

        if ('soft-purge' === $contract) {
            return array(
                'version' => (int) ($versions['soft-purge'] ?? 1),
                'transportFingerprint' => $transport_fingerprint,
                'httpMode' => 'http' === $context['mode'],
                'strategy' => $context['invalidationStrategy'],
                'staleWhileRevalidateSeconds' => (int) $context['staleWhileRevalidateSeconds'],
                'softPurgeContract' => 2,
            );
        }

        if ('refill' === $contract) {
            $dashboard_settings = is_array($context['dashboardSettings']) ? $context['dashboardSettings'] : array();
            return array(
                'version' => (int) ($versions['refill'] ?? 1),
                'publicRefillPolicy' => array(
                    'afterTargetedInvalidation' => !empty($dashboard_settings['varnishRefillAfterTargetedInvalidation']),
                    'withSiteWarmup' => !empty($dashboard_settings['varnishWarmDuringManualWarmup']),
                ),
                'canonicalUrl' => (string) home_url('/'),
            );
        }

        return array();
    }

    /**
     * Return one independent capability fingerprint.
     *
     * @param string $contract           Contract name.
     * @param array  $settings           Optional normalized Varnish settings.
     * @param array  $dashboard_settings Optional dashboard settings.
     * @return string
     */
    private static function get_varnish_capability_contract_fingerprint($contract, array $settings = array(), array $dashboard_settings = array())
    {
        $payload = self::get_varnish_capability_contract_payload($contract, $settings, $dashboard_settings);
        return empty($payload) ? '' : hash('sha256', (string) wp_json_encode($payload));
    }

    /**
     * Bind a result to only the capability contracts it actually proves.
     *
     * @param array        $value     Result payload.
     * @param string|array $contracts Relevant contracts.
     * @param array        $settings  Optional normalized Varnish settings.
     * @return array
     */
    private static function bind_varnish_capability_contracts(array $value, $contracts, array $settings = array())
    {
        $normalized = self::normalize_varnish_capability_contracts($contracts);
        $fingerprints = array();
        foreach ($normalized as $contract) {
            $fingerprint = self::get_varnish_capability_contract_fingerprint($contract, $settings);
            if ('' !== $fingerprint) {
                $fingerprints[$contract] = $fingerprint;
            }
        }

        $value['capabilityFingerprints'] = $fingerprints;
        $value['capabilityContracts'] = array_intersect_key(
            self::get_varnish_capability_contract_versions(),
            array_fill_keys($normalized, true)
        );
        unset($value['capabilityFingerprint'], $value['capabilityContractVersions'], $value['configurationSignature']);

        return $value;
    }

    /**
     * Check only the capability contracts relevant to a stored result.
     *
     * @param array        $value     Stored result.
     * @param string|array $contracts Relevant contracts.
     * @param array        $settings  Optional normalized Varnish settings.
     * @return bool
     */
    private static function varnish_capability_contracts_match(array $value, $contracts, array $settings = array())
    {
        $normalized = self::normalize_varnish_capability_contracts($contracts);
        $stored = is_array($value['capabilityFingerprints'] ?? null) ? $value['capabilityFingerprints'] : array();
        if (empty($normalized) || empty($stored)) {
            return false;
        }

        foreach ($normalized as $contract) {
            $stored_fingerprint = (string) ($stored[$contract] ?? '');
            $current_fingerprint = self::get_varnish_capability_contract_fingerprint($contract, $settings);
            if (64 !== strlen($stored_fingerprint)
                || 64 !== strlen($current_fingerprint)
                || !hash_equals($stored_fingerprint, $current_fingerprint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark an old basic test as non-authoritative without changing storage.
     *
     * @param array $result Stored basic-test result.
     * @return array
     */
    private static function mark_varnish_behavior_result_configuration_changed(array $result)
    {
        $tested_at = max(0, (int) ($result['time'] ?? 0));
        $result['success'] = false;
        $result['verified'] = false;
        $result['configurationChanged'] = true;
        $result['status'] = 'configuration-changed';
        $result['message'] = self::maybe_translate('The Varnish transport, invalidation contract, or refill policy changed after this test. Run Test Varnish again.');
        $result['varnishDetected'] = false;
        $result['connectionTested'] = false;
        $result['connectionVerified'] = false;
        $result['invalidationAttempted'] = false;
        $result['invalidationAccepted'] = false;
        $result['invalidationVerified'] = false;
        $result['hitVerified'] = false;
        $result['previousTestedAt'] = $tested_at;
        $result['steps'] = array();
        $result['connectionDetails'] = array();
        $result['details'] = array();

        return $result;
    }

    /**
     * Expose bounded non-secret diagnostics for every independent contract.
     *
     * @return array
     */
    public static function get_varnish_capability_fingerprint_status()
    {
        $contracts = array();
        foreach (array('transport', 'html-invalidation', 'variant', 'soft-purge', 'refill') as $contract) {
            $contracts[$contract] = array(
                'fingerprint' => self::get_varnish_capability_contract_fingerprint($contract),
                'configuration' => self::get_varnish_capability_contract_payload($contract),
            );
        }

        return array(
            'schema' => (int) (self::get_varnish_capability_contract_versions()['schema'] ?? 2),
            'contractVersions' => self::get_varnish_capability_contract_versions(),
            'contracts' => $contracts,
        );
    }
}
