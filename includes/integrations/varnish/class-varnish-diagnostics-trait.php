<?php
/**
 * Varnish and reverse-proxy diagnostics for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Diagnostics_Trait
{
    /**
     * Return the authoritative persistent reverse-proxy diagnostic state name.
     *
     * @return string
     */
    private static function get_reverse_proxy_status_state_name()
    {
        return 'ultracache_state:varnish.reverse_proxy_detection';
    }


    /**
     * Bind reverse-proxy evidence to the relevant local transport configuration.
     *
     * @return string
     */
    private static function get_reverse_proxy_status_fingerprint()
    {
        $settings = self::get_dashboard_settings();
        $payload = array(
            'schema' => 1,
            'siteOrigin' => function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '',
            'pageCacheEnabled' => !empty($settings['pageCacheEnabled']),
            'varnishEnabled' => self::is_varnish_runtime_enabled($settings),
            'varnishMode' => self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http'),
            'varnishEndpoints' => self::sanitize_varnish_servers_string(
                (string) ($settings['varnishCliServers'] ?? ''),
                self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http')
            ),
            'liteSpeedCacheEnabled' => !empty($settings['liteSpeedCacheEnabled']),
        );

        return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
    }

    /**
     * Return an untested reverse-proxy diagnostic shape.
     *
     * @return array<string,mixed>
     */
    private static function get_default_reverse_proxy_status()
    {
        return array(
            'tested' => false,
            'testedAt' => 0,
            'fingerprint' => self::get_reverse_proxy_status_fingerprint(),
            'diagnosticStatus' => 'not-tested',
            'configurationChanged' => false,
            'stale' => false,
            'ageSeconds' => 0,
            'statusMessage' => self::maybe_translate('No explicit reverse-proxy detection has been run yet.'),
            'detected' => false,
            'varnish' => false,
            'nginx_cache' => false,
            'litespeed_cache' => false,
            'litespeed_server' => false,
            'server_cache' => false,
            'provider' => '',
            'providers' => array(),
            'via' => '',
            'x_varnish' => '',
            'x_cache' => '',
            'x_cache_status' => '',
            'x_proxy_cache' => '',
            'x_fastcgi_cache' => '',
            'x_litespeed_cache' => '',
            'x_qc_cache' => '',
            'cf_cache_status' => '',
            'age' => '',
            'server' => '',
            'httpCode' => 0,
            'message' => '',
        );
    }

    /**
     * Persist one bounded reverse-proxy detection result.
     *
     * @param array $status Detection result.
     * @return bool
     */
    private static function persist_reverse_proxy_status(array $status)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }

        $status = self::sanitize_varnish_result($status);
        $mutation = ultracache_mutate_state_record(
            self::get_reverse_proxy_status_state_name(),
            static function () use ($status) {
                return array(
                    'schemaVersion' => 1,
                    'recordedAt' => max(0, (int) ($status['testedAt'] ?? time())),
                    'status' => $status,
                );
            },
            5,
            array()
        );

        return !empty($mutation['success']);
    }

    /**
     * Run an explicit reverse-proxy detection request and persist its evidence.
     * Ordinary diagnostics reads never perform network I/O.
     *
     * @return array<string,mixed>
     */
    public static function refresh_reverse_proxy_status()
    {
        $status = self::get_default_reverse_proxy_status();
        $status['tested'] = true;
        $status['testedAt'] = time();
        $status['fingerprint'] = self::get_reverse_proxy_status_fingerprint();
        $status['diagnosticStatus'] = 'not-detected';
        $status['statusMessage'] = self::maybe_translate('No reverse-proxy cache headers were observed during the explicit detection request.');

        $response = ultracache_safe_loopback_remote_request(home_url('/'), array(
            'method' => 'HEAD',
            'timeout' => 5,
            'redirection' => 2,
            'headers' => array(
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ),
        ), 'reverse_proxy_status');

        if (is_wp_error($response)) {
            $status['diagnosticStatus'] = 'request-failed';
            $status['statusMessage'] = self::sanitize_varnish_string($response->get_error_message());
            self::persist_reverse_proxy_status($status);
            return $status;
        }

        $status['httpCode'] = absint(wp_remote_retrieve_response_code($response));
        $headers = wp_remote_retrieve_headers($response);
        $status['via'] = trim((string) ($headers['via'] ?? ''));
        $status['x_varnish'] = trim((string) ($headers['x-varnish'] ?? ''));
        $status['x_cache'] = trim((string) ($headers['x-cache'] ?? ''));
        $status['x_cache_status'] = trim((string) ($headers['x-cache-status'] ?? ''));
        $status['x_proxy_cache'] = trim((string) ($headers['x-proxy-cache'] ?? ''));
        $status['x_fastcgi_cache'] = trim((string) ($headers['x-fastcgi-cache'] ?? ''));
        $status['x_litespeed_cache'] = trim((string) ($headers['x-litespeed-cache'] ?? ''));
        $status['x_qc_cache'] = trim((string) ($headers['x-qc-cache'] ?? ''));
        $status['cf_cache_status'] = trim((string) ($headers['cf-cache-status'] ?? ''));
        $status['age'] = trim((string) ($headers['age'] ?? ''));
        $status['server'] = trim((string) ($headers['server'] ?? ''));

        $via_lower = strtolower($status['via']);
        $server_lower = strtolower($status['server']);

        $status['varnish'] = ('' !== $status['x_varnish']) || (false !== strpos($via_lower, 'varnish'));
        $status['nginx_cache'] = ('' !== $status['x_fastcgi_cache'])
            || ('' !== $status['x_proxy_cache'])
            || ('' !== $status['x_cache_status'])
            || ((false !== strpos($server_lower, 'nginx'))
                && (preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache'])
                    || preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache_status'])));
        $status['litespeed_server'] = false !== strpos($server_lower, 'litespeed');
        $status['litespeed_cache'] = ('' !== $status['x_litespeed_cache']) || ('' !== $status['x_qc_cache']);

        $providers = array();
        if ($status['varnish']) {
            $providers[] = 'Varnish';
        }
        if ($status['nginx_cache']) {
            $providers[] = 'Nginx Cache';
        }
        if ($status['litespeed_cache']) {
            $providers[] = 'LiteSpeed Cache';
        }
        if ('' !== $status['cf_cache_status']) {
            $providers[] = 'Cloudflare Cache';
        }
        if (!$providers && ('' !== $status['via'] || '' !== $status['x_cache'] || '' !== $status['age'])) {
            $providers[] = 'Reverse Proxy Cache';
        }

        $status['providers'] = array_values(array_unique($providers));
        $status['provider'] = !empty($status['providers']) ? implode(' + ', $status['providers']) : '';
        $status['server_cache'] = !empty($status['providers']);
        $status['detected'] = $status['server_cache'];

        if ($status['detected']) {
            $provider_label = $status['provider'] ? $status['provider'] : self::maybe_translate('Reverse Proxy Cache');
            $status['diagnosticStatus'] = 'detected';
            $status['statusMessage'] = self::maybe_translate_sprintf('%s was detected by an explicit local request.', $provider_label);
            $status['message'] = self::maybe_translate_sprintf(
                '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
                $provider_label
            );
        }

        self::persist_reverse_proxy_status($status);
        return $status;
    }

    /**
     * Read the authoritative persistent reverse-proxy diagnostic state.
     *
     * @return array<string,mixed>
     */
    private static function get_reverse_proxy_status()
    {
        if (!function_exists('ultracache_get_state_record_read_only')) {
            return self::get_default_reverse_proxy_status();
        }

        $record = ultracache_get_state_record_read_only(self::get_reverse_proxy_status_state_name());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : array();
        if (empty($status)) {
            return self::get_default_reverse_proxy_status();
        }

        $status = array_merge(self::get_default_reverse_proxy_status(), $status);
        $tested_at = max(0, (int) ($status['testedAt'] ?? 0));
        $age = $tested_at > 0 ? max(0, time() - $tested_at) : 0;
        $current_fingerprint = self::get_reverse_proxy_status_fingerprint();
        $configuration_changed = '' === (string) ($status['fingerprint'] ?? '')
            || !hash_equals((string) ($status['fingerprint'] ?? ''), $current_fingerprint);
        $stale = !$configuration_changed && $tested_at > 0 && $age > DAY_IN_SECONDS;

        $status['tested'] = $tested_at > 0;
        $status['ageSeconds'] = $age;
        $status['configurationChanged'] = $configuration_changed;
        $status['stale'] = $stale;
        if ($configuration_changed) {
            $status['diagnosticStatus'] = 'configuration-changed';
            $status['statusMessage'] = self::maybe_translate('The site URL or external-cache configuration changed. Run Detect Varnish Configuration again.');
        } elseif ($stale) {
            $status['diagnosticStatus'] = 'stale';
            $status['statusMessage'] = self::maybe_translate('The reverse-proxy detection is older than 24 hours. Run Detect Varnish Configuration again to refresh it.');
        }

        return self::sanitize_varnish_result($status);
    }
}
