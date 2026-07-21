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
            $status = sanitize_key((string) ($capability['status'] ?? 'capability-unverified'));
            $message = (string) ($capability['message'] ?? __('Stale-while-revalidate requires an active HTTP soft-purge capability.', 'ultracache'));
        } else {
            $status = 'observed';
            $message = (string) ($capability['message'] ?? __('Soft purge and the stale-to-fresh refill sequence are verified.', 'ultracache'));
        }

        $observed = !empty($capability['supported'])
            && !empty($capability['staleVerified'])
            && !empty($capability['freshHitVerified']);

        return array(
            'configuredSeconds' => $seconds,
            'enabled' => $varnish_enabled && $ttl_minutes > 0 && $seconds > 0 && !empty($capability['supported']),
            'status' => $status,
            'observed' => $observed,
            'staleVerified' => !empty($capability['staleVerified']),
            'freshHitVerified' => !empty($capability['freshHitVerified']),
            'verificationAttemptCount' => absint($capability['verificationAttemptCount'] ?? 0),
            'softPurgeCapability' => $capability,
            'message' => $message,
        );
    }

}
