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
        private static function get_reverse_proxy_status()
        {
            $cached = get_transient('ultracache_reverse_proxy_status_v2');
            if (is_array($cached)) {
                return $cached;
            }

            $status = array(
                'detected'           => false,
                'varnish'            => false,
                'nginx_cache'        => false,
                'litespeed_cache'    => false,
                'server_cache'       => false,
                'provider'           => '',
                'providers'          => array(),
                'via'                => '',
                'x_varnish'          => '',
                'x_cache'            => '',
                'x_cache_status'     => '',
                'x_proxy_cache'      => '',
                'x_fastcgi_cache'    => '',
                'x_litespeed_cache'  => '',
                'x_qc_cache'         => '',
                'cf_cache_status'    => '',
                'age'                => '',
                'server'             => '',
                'message'            => '',
            );

            $response = ultracache_safe_loopback_remote_request(home_url('/'), array(
                'method'      => 'HEAD',
                'timeout'     => 5,
                'redirection' => 2,
                'headers'     => array(
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
            ), 'reverse_proxy_status');

            if (!is_wp_error($response)) {
                $headers = wp_remote_retrieve_headers($response);
                $status['via']               = trim((string) ($headers['via'] ?? ''));
                $status['x_varnish']         = trim((string) ($headers['x-varnish'] ?? ''));
                $status['x_cache']           = trim((string) ($headers['x-cache'] ?? ''));
                $status['x_cache_status']    = trim((string) ($headers['x-cache-status'] ?? ''));
                $status['x_proxy_cache']     = trim((string) ($headers['x-proxy-cache'] ?? ''));
                $status['x_fastcgi_cache']   = trim((string) ($headers['x-fastcgi-cache'] ?? ''));
                $status['x_litespeed_cache'] = trim((string) ($headers['x-litespeed-cache'] ?? ''));
                $status['x_qc_cache']        = trim((string) ($headers['x-qc-cache'] ?? ''));
                $status['cf_cache_status']   = trim((string) ($headers['cf-cache-status'] ?? ''));
                $status['age']               = trim((string) ($headers['age'] ?? ''));
                $status['server']            = trim((string) ($headers['server'] ?? ''));

                $via_lower             = strtolower($status['via']);
                $x_cache_lower         = strtolower($status['x_cache']);
                $x_cache_status_lower  = strtolower($status['x_cache_status']);
                $x_proxy_cache_lower   = strtolower($status['x_proxy_cache']);
                $x_fastcgi_cache_lower = strtolower($status['x_fastcgi_cache']);
                $x_litespeed_lower     = strtolower($status['x_litespeed_cache']);
                $x_qc_cache_lower      = strtolower($status['x_qc_cache']);
                $cf_cache_lower        = strtolower($status['cf_cache_status']);
                $server_lower          = strtolower($status['server']);

                $status['varnish'] = ('' !== $status['x_varnish']) || (false !== strpos($via_lower, 'varnish'));
                $status['nginx_cache'] = ('' !== $status['x_fastcgi_cache'])
                    || ('' !== $status['x_proxy_cache'])
                    || ('' !== $status['x_cache_status'])
                    || ((false !== strpos($server_lower, 'nginx')) && (preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache']) || preg_match('/\b(hit|miss|bypass|expired|stale|updating|revalidated)\b/i', $status['x_cache_status'])));
                $status['litespeed_cache'] = ('' !== $status['x_litespeed_cache'])
                    || ('' !== $status['x_qc_cache'])
                    || (false !== strpos($server_lower, 'litespeed'));

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
                    $status['message'] = self::maybe_translate_sprintf(
                        '%s detected. UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
                        $provider_label
                    );
                }
            }

            set_transient('ultracache_reverse_proxy_status_v2', $status, MINUTE_IN_SECONDS);
            return $status;
        }

}
