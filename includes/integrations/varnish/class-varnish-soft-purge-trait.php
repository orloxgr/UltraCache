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
    private static function get_varnish_soft_purge_configuration_signature(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_varnish_cli_settings();
        }

        $payload = array(
            'mode' => (string) ($settings['mode'] ?? 'http'),
            'servers' => array_values((array) ($settings['servers'] ?? array())),
            'host' => (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: ''),
            'tokenConfigured' => !empty($settings['key']),
        );

        return hash('sha256', (string) wp_json_encode($payload));
    }

    private static function set_varnish_soft_purge_capability(array $capability)
    {
        $capability['configurationSignature'] = self::get_varnish_soft_purge_configuration_signature();
        $capability['testedAt'] = max(0, (int) ($capability['testedAt'] ?? time()));
        set_transient(
            'ultracache_varnish_soft_purge_capability_v1',
            self::sanitize_varnish_result($capability),
            WEEK_IN_SECONDS
        );
    }

    private static function get_varnish_soft_purge_capability(array $settings = array())
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
                'message' => self::maybe_translate('Run Test Varnish to verify whether the configured HTTP endpoints support soft purge with stale/grace delivery.'),
                'testedAt' => 0,
            );
        }

        $current_signature = self::get_varnish_soft_purge_configuration_signature($settings);
        if (!hash_equals((string) ($value['configurationSignature'] ?? ''), $current_signature)) {
            return array(
                'supported' => false,
                'status' => 'configuration-changed',
                'message' => self::maybe_translate('The Varnish endpoint configuration changed after the soft purge test. Run Test Varnish again.'),
                'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
            );
        }

        return array(
            'supported' => !empty($value['supported']),
            'status' => sanitize_key((string) ($value['status'] ?? 'inconclusive')),
            'message' => self::sanitize_varnish_string((string) ($value['message'] ?? '')),
            'testedAt' => max(0, (int) ($value['testedAt'] ?? 0)),
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

    private static function send_varnish_soft_purge_prepared_urls(array $prepared, $scope = 'batch')
    {
        $settings = self::get_varnish_cli_settings();
        $details = array();
        $all_ok = true;
        $request_count = 0;
        $urls = array_values((array) ($prepared['urls'] ?? array()));

        foreach ($urls as $url_index => $item) {
            $expr = self::build_varnish_ban_expression((string) ($item['host'] ?? ''), (string) ($item['path'] ?? '/'), false);
            foreach ((array) ($settings['servers'] ?? array()) as $terminal) {
                $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                $endpoint = !empty($endpoint_check['valid']) ? self::normalize_varnish_endpoint($terminal) : array();
                if (empty($endpoint)) {
                    ++$request_count;
                    $all_ok = false;
                    $details[] = array(
                        'server' => $terminal,
                        'success' => false,
                        'detail' => self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.')),
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
                $all_ok = $all_ok && $success;
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

        $detail_count = count($details);
        $details_truncated = $detail_count > 100;
        if ($details_truncated) {
            $details = array_slice($details, 0, 100);
        }

        $result = array(
            'success' => $all_ok,
            'message' => $all_ok
                ? self::maybe_translate_sprintf('Varnish soft purge expired %1$d unique URL(s) with %2$d request(s); affected-page refill will follow.', (int) ($prepared['uniqueCount'] ?? count($urls)), $request_count)
                : self::maybe_translate('Varnish soft purge failed on one or more endpoint requests.'),
            'time' => time(),
            'mode' => 'http',
            'method' => 'PURGE',
            'effectiveMethod' => 'soft PURGE',
            'invalidationStrategy' => 'soft',
            'softPurge' => true,
            'endpointCount' => count((array) ($settings['servers'] ?? array())),
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
            'rejections' => (array) ($prepared['rejections'] ?? array()),
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'detailCount' => $detail_count,
            'detailsTruncated' => $details_truncated,
            'details' => $details,
        );

        self::set_varnish_last_result($result);
        return $result;
    }

    private static function run_varnish_soft_purge_capability_test($url, $timeout)
    {
        $settings = self::get_varnish_cli_settings();
        $steps = array();
        $invalidation = array('success' => false, 'details' => array());

        if ('http' !== (string) ($settings['mode'] ?? 'http')) {
            $capability = array(
                'supported' => false,
                'status' => 'http-only',
                'message' => self::maybe_translate('Soft purge capability testing is available only in HTTP endpoint mode.'),
                'testedAt' => time(),
            );
            self::set_varnish_soft_purge_capability($capability);
            return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
        }

        $steps['baseline'] = self::run_varnish_behavior_request($url, 'soft_purge_baseline', $timeout);
        if ('HIT' !== strtoupper((string) ($steps['baseline']['status'] ?? ''))) {
            $capability = array(
                'supported' => false,
                'status' => 'baseline-not-hit',
                'message' => self::maybe_translate('The front page was not a verified Varnish HIT before the soft purge probe.'),
                'testedAt' => time(),
            );
            self::set_varnish_soft_purge_capability($capability);
            return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
        }

        $prepared = self::prepare_varnish_invalidation_urls(array($url));
        if (empty($prepared['urls'])) {
            $capability = array(
                'supported' => false,
                'status' => 'invalid-url',
                'message' => self::maybe_translate('The front-page URL was not eligible for the soft purge probe.'),
                'testedAt' => time(),
            );
            self::set_varnish_soft_purge_capability($capability);
            return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
        }

        $invalidation = self::send_varnish_soft_purge_prepared_urls($prepared, 'soft-purge-test');
        if (!empty($invalidation['success'])) {
            $steps['afterSoftPurge'] = self::run_varnish_behavior_request($url, 'after_soft_purge', $timeout);
            if (!empty($steps['afterSoftPurge']['success'])) {
                $steps['verification'] = self::run_varnish_behavior_request($url, 'soft_purge_verification', $timeout);
            }
        }

        $after_status = strtoupper((string) ($steps['afterSoftPurge']['status'] ?? ''));
        $verification_status = strtoupper((string) ($steps['verification']['status'] ?? ''));
        $supported = !empty($invalidation['success']) && 'STALE' === $after_status && 'HIT' === $verification_status;

        if ($supported) {
            $status = 'verified';
            $message = self::maybe_translate('Soft purge was verified: the expired object was served stale/grace and the following request returned a fresh Varnish HIT.');
        } elseif (in_array($after_status, array('MISS', 'BYPASS'), true)) {
            $status = 'hard-or-bypass';
            $message = self::maybe_translate('The soft purge request was accepted, but the next request was a MISS/BYPASS rather than stale/grace. UltraCache will not enable soft purge automatically.');
        } elseif (empty($invalidation['success'])) {
            $status = 'request-failed';
            $message = self::maybe_translate('The configured Varnish endpoint rejected or failed the soft purge request.');
        } else {
            $status = 'inconclusive';
            $message = self::maybe_translate('The soft purge probe completed without visible stale/grace and fresh-HIT evidence, so the capability remains disabled.');
        }

        $capability = array(
            'supported' => $supported,
            'status' => $status,
            'message' => $message,
            'testedAt' => time(),
        );
        self::set_varnish_soft_purge_capability($capability);

        return array('capability' => $capability, 'steps' => $steps, 'invalidation' => $invalidation);
    }
}
