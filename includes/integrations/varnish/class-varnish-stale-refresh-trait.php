<?php
/**
 * Capability-gated stale-while-refresh diagnostics for UltraCache Varnish.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Stale_Refresh_Trait
{
    /**
     * Return the effective stale-while-revalidate diagnostic status.
     *
     * @param array $settings Optional dashboard settings.
     * @return array
     */
    private static function get_varnish_stale_while_revalidate_status(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }

        $seconds = max(0, min(86400, absint($settings['varnishStaleWhileRevalidateSeconds'] ?? 0)));
        $ttl_minutes = max(0, min(525600, absint($settings['varnishHtmlTtlMinutes'] ?? 0)));
        $varnish_enabled = !empty($settings['varnishCliEnabled']);
        $cli_settings = self::get_varnish_cli_settings();
        $capability = self::get_varnish_soft_purge_capability($cli_settings);
        $last = self::get_varnish_last_result();
        $test = is_array($last['staleWhileRevalidateTest'] ?? null) ? $last['staleWhileRevalidateTest'] : array();
        if ((int) ($test['configuredSeconds'] ?? -1) !== $seconds) {
            $test = array();
        }

        if ($seconds <= 0) {
            $status = 'disabled';
            $message = __('0 disables the UltraCache stale-while-revalidate response directive.', 'ultracache');
        } elseif (!$varnish_enabled) {
            $status = 'inactive';
            $message = __('Stale-while-revalidate is configured but inactive because Varnish integration is disabled.', 'ultracache');
        } elseif ($ttl_minutes <= 0) {
            $status = 'requires-ttl';
            $message = __('Set a positive Varnish HTML TTL before enabling stale-while-revalidate.', 'ultracache');
        } elseif (empty($capability['supported'])) {
            $status = 'capability-unverified';
            $message = __('Run Test Varnish and verify soft purge with stale/grace delivery before enabling stale-while-revalidate.', 'ultracache');
        } else {
            $status = sanitize_key((string) ($test['status'] ?? 'not-tested'));
            $message = (string) ($test['message'] ?? __('Run Test Varnish to verify that the stale-while-revalidate directive remains visible on a verified Varnish HIT.', 'ultracache'));
        }

        return array(
            'configuredSeconds' => $seconds,
            'enabled' => $varnish_enabled && $ttl_minutes > 0 && $seconds > 0 && !empty($capability['supported']),
            'status' => $status,
            'observed' => !empty($test['observed']),
            'originHeaderObserved' => !empty($test['originHeaderObserved']),
            'hitHeaderObserved' => !empty($test['hitHeaderObserved']),
            'hitVerified' => !empty($test['hitVerified']),
            'softPurgeCapability' => $capability,
            'message' => $message,
        );
    }

    /**
     * Evaluate visible stale-while-revalidate evidence from the behavior test.
     *
     * @param array $steps            Base behavior-test request steps.
     * @param int   $configured       Configured seconds.
     * @param array $soft_capability  Soft-purge capability result.
     * @return array
     */
    private static function evaluate_varnish_stale_while_revalidate(array $steps, $configured, array $soft_capability)
    {
        $configured = max(0, min(86400, absint($configured)));
        if ($configured <= 0) {
            return array(
                'status' => 'disabled',
                'observed' => false,
                'configuredSeconds' => 0,
                'message' => __('UltraCache is not sending stale-while-revalidate because the setting is 0.', 'ultracache'),
            );
        }

        if (empty($soft_capability['supported'])) {
            return array(
                'status' => 'capability-unverified',
                'observed' => false,
                'configuredSeconds' => $configured,
                'message' => __('The stale-while-revalidate directive was not considered active because soft purge with stale/grace delivery was not verified.', 'ultracache'),
            );
        }

        $origin = is_array($steps['afterInvalidation'] ?? null) ? $steps['afterInvalidation'] : array();
        $hit = is_array($steps['verification'] ?? null) ? $steps['verification'] : array();
        if (empty($origin['success']) || empty($hit['success'])) {
            return array(
                'status' => 'inconclusive',
                'observed' => false,
                'configuredSeconds' => $configured,
                'message' => __('The stale-while-revalidate directive could not be checked because the refill sequence did not complete.', 'ultracache'),
            );
        }

        $pattern = '/(?:^|[,\s])stale-while-revalidate\s*=\s*' . preg_quote((string) $configured, '/') . '(?:$|[,\s])/';
        $origin_cache_control = strtolower((string) ($origin['headers']['cacheControl'] ?? ''));
        $hit_cache_control = strtolower((string) ($hit['headers']['cacheControl'] ?? ''));
        $origin_custom = trim((string) ($origin['headers']['ultraCacheStaleWhileRevalidate'] ?? ''));
        $hit_custom = trim((string) ($hit['headers']['ultraCacheStaleWhileRevalidate'] ?? ''));
        $origin_header = 1 === preg_match($pattern, $origin_cache_control) || (ctype_digit($origin_custom) && (int) $origin_custom === $configured);
        $hit_header = 1 === preg_match($pattern, $hit_cache_control) || (ctype_digit($hit_custom) && (int) $hit_custom === $configured);
        $hit_verified = 'HIT' === strtoupper((string) ($hit['status'] ?? ''));
        $observed = $origin_header && $hit_header && $hit_verified;

        if ($observed) {
            $status = 'observed';
            $message = __('The configured stale-while-revalidate directive was visible on the refilled response and on a verified Varnish HIT.', 'ultracache');
        } elseif (!$origin_header) {
            $status = 'header-missing';
            $message = __('The stale-while-revalidate directive was not visible on the post-invalidation response. Existing server policy may be replacing or hiding Cache-Control.', 'ultracache');
        } else {
            $status = 'inconclusive';
            $message = __('The stale-while-revalidate directive was emitted, but the following Varnish HIT behavior could not be verified.', 'ultracache');
        }

        return array(
            'status' => $status,
            'observed' => $observed,
            'configuredSeconds' => $configured,
            'originHeaderObserved' => $origin_header,
            'hitHeaderObserved' => $hit_header,
            'hitVerified' => $hit_verified,
            'message' => $message,
        );
    }
}
