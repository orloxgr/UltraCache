<?php
/**
 * Automation-owned stale-grace diagnostics for UltraCache Varnish.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Stale_Refresh_Trait
{
    /**
     * Return the configured response-driven Varnish grace diagnostic status.
     *
     * @param array $settings Optional dashboard settings.
     * @return array
     */
    private static function get_varnish_stale_while_revalidate_status(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }

        $automation = self::get_varnish_automation_policy($settings);
        $seconds = max(0, min(86400, absint($automation['staleWhileRevalidateSeconds'] ?? 0)));
        $varnish_enabled = self::is_varnish_runtime_enabled($settings);
        $cli_settings = self::get_varnish_cli_settings();
        $soft_purge_capability = self::get_varnish_soft_purge_capability($cli_settings);

        if ($seconds <= 0) {
            $status = 'disabled';
            $message = __('Varnish grace follows the Automation & Scheduling lifetime settings and is currently 0 seconds.', 'ultracache');
        } elseif (!$varnish_enabled) {
            $status = 'inactive';
            $message = __('Varnish grace is configured by Automation & Scheduling but inactive because Varnish integration is disabled.', 'ultracache');
        } else {
            $status = 'configured';
            $message = sprintf(
                /* translators: %d: Configured Varnish stale-while-revalidate window in seconds. */
                __('Varnish grace is configured from the Automation & Scheduling stale window: %d seconds.', 'ultracache'),
                $seconds
            );
        }

        $observed = !empty($soft_purge_capability['supported'])
            && !empty($soft_purge_capability['staleVerified'])
            && !empty($soft_purge_capability['freshHitVerified']);

        return array(
            'configuredSeconds' => $seconds,
            'freshTtlMinutes' => max(1, absint($automation['freshTtlMinutes'] ?? 1)),
            'maxStaleMinutes' => max(1, absint($automation['maxStaleMinutes'] ?? 1)),
            'enabled' => $varnish_enabled && $seconds > 0,
            'status' => $status,
            'observed' => $observed,
            'staleVerified' => !empty($soft_purge_capability['staleVerified']),
            'freshHitVerified' => !empty($soft_purge_capability['freshHitVerified']),
            'verificationAttemptCount' => absint($soft_purge_capability['verificationAttemptCount'] ?? 0),
            'softPurgeCapability' => $soft_purge_capability,
            'message' => $message,
        );
    }

}
