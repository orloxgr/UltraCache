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
        set_transient(
            'ultracache_varnish_soft_purge_capability_v1',
            self::sanitize_varnish_result($capability),
            WEEK_IN_SECONDS
        );
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

        $value = get_transient('ultracache_varnish_soft_purge_capability_v1');
        if (!is_array($value)) {
            return array(
                'supported' => false,
                'status' => 'untested',
                'message' => self::maybe_translate('Soft purge is not active for the configured HTTP endpoints.'),
                'testedAt' => 0,
            );
        }

        if (!self::varnish_capability_contracts_match($value, array('soft-purge'), $settings)) {
            return array(
                'supported' => false,
                'status' => 'configuration-changed',
                'message' => self::maybe_translate('The HTTP transport, soft-purge strategy, or stale-while-revalidate contract changed, so soft purge is inactive.'),
                'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
            );
        }

        $origin_revalidation = self::get_varnish_origin_revalidation_status();
        if (empty($origin_revalidation['verified'])) {
            return array(
                'supported' => false,
                'status' => 'origin-revalidation-unverified',
                'message' => self::maybe_translate('Soft purge remains disabled because authenticated force-refresh requests have not reached the WordPress origin.'),
                'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
                'originRevalidationVerified' => false,
            );
        }

        return array(
            'supported' => !empty($value['supported']) && !empty($value['originRevalidationVerified']),
            'status' => sanitize_key((string) ($value['status'] ?? 'inconclusive')),
            'message' => self::sanitize_varnish_string((string) ($value['message'] ?? '')),
            'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
            'originRevalidationVerified' => !empty($value['originRevalidationVerified']),
            'staleVerified' => !empty($value['staleVerified']),
            'freshHitVerified' => !empty($value['freshHitVerified']),
            'verificationAttemptCount' => absint($value['verificationAttemptCount'] ?? 0),
            'invalidationEndpointCount' => absint($value['invalidationEndpointCount'] ?? 0),
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
        $fallback = ('PURGE' === strtoupper((string) ($settings['method'] ?? 'BAN'))) ? 'purge' : 'ban';

        if ('hard' === $override) {
            $effective = $fallback;
        } elseif ('admin' === (string) ($settings['mode'] ?? 'http')) {
            $effective = 'ban';
        } elseif ('soft' === $configured) {
            $effective = !empty($capability['supported']) ? 'soft' : $fallback;
        } elseif ('auto' === $configured) {
            $effective = !empty($capability['supported']) ? 'soft' : $fallback;
        } else {
            $effective = $configured;
        }

        return array(
            'configured' => $configured,
            'effective' => $effective,
            'effectiveLabel' => 'soft' === $effective ? 'soft PURGE' : (('admin' === (string) ($settings['mode'] ?? 'http')) ? 'admin BAN' : strtoupper($effective)),
            'fallback' => $fallback,
            'softCapability' => $capability,
            'usingFallback' => 'soft' === $configured && 'soft' !== $effective,
            'message' => 'soft' === $effective
                ? self::maybe_translate('Verified soft purge is active and successful targeted invalidations are followed by queued refill.')
                : (('soft' === $configured || 'auto' === $configured) && empty($capability['supported'])
                    ? self::maybe_translate_sprintf('Soft purge is not verified; UltraCache is using %s invalidation.', strtoupper($fallback))
                    : self::maybe_translate_sprintf('UltraCache is using %s invalidation.', strtoupper($effective))),
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
