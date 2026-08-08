<?php
/**
 * Lightweight Varnish configuration discovery orchestration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Discovery_Trait
{
    /**
     * Confirm Varnish on the public homepage, then test a bounded set of HTTP
     * listener candidates and keep the first accepted PURGE or BAN contract.
     *
     * @return array
     */
    public static function discover_varnish_configuration()
    {
        self::reset_settings_cache();
        $settings = self::get_varnish_cli_settings();
        $home_url = home_url('/');
        $home = wp_parse_url($home_url);
        $site_host = self::normalize_varnish_discovery_host($home['host'] ?? '');
        $site_scheme = 'https' === strtolower((string) ($home['scheme'] ?? '')) ? 'https' : 'http';
        $home_request_target = isset($home['path']) && '' !== (string) $home['path'] ? (string) $home['path'] : '/';
        $timeout = max(2, min(4, (int) ($settings['timeout'] ?? 2)));
        $token = (string) ($settings['key'] ?? '');

        if ('' === $site_host || !ultracache_is_trusted_loopback_url($home_url)) {
            return array(
                'success' => false,
                'verified' => false,
                'status' => 'site-url-unavailable',
                'message' => __('The canonical site URL is unavailable for Varnish discovery.', 'ultracache'),
            );
        }

        $homepage_probe = self::probe_varnish_discovery_homepage($home_url, $timeout);
        if (empty($homepage_probe['success']) || empty($homepage_probe['varnishDetected'])) {
            return array(
                'success' => false,
                'verified' => false,
                'status' => 'varnish-not-detected',
                'message' => __('The homepage response did not expose a Varnish header.', 'ultracache'),
            );
        }

        $hosts = self::get_varnish_discovery_hosts($settings);
        $ports = self::get_varnish_discovery_ports($settings);
        $candidates = array();
        $authentication_candidate = array();

        if ('http' === (string) ($settings['mode'] ?? 'http')) {
            foreach ((array) ($settings['servers'] ?? array()) as $terminal) {
                $configured = self::parse_varnish_discovery_http_endpoint($terminal);
                if (empty($configured)) {
                    continue;
                }
                $configured['source'] = 'configured';
                $configured['priority'] = 130;
                $candidates[] = $configured;
            }
        }

        foreach ($hosts as $host_info) {
            foreach ($ports as $port) {
                $candidates[] = array(
                    'scheme' => in_array((int) $port, array(443, 8443), true) ? 'https' : 'http',
                    'host' => (string) ($host_info['host'] ?? ''),
                    'port' => (int) $port,
                    'source' => (string) ($host_info['source'] ?? 'server'),
                    'priority' => (int) ($host_info['priority'] ?? 0),
                );
            }
        }

        $candidates = array_slice(self::deduplicate_varnish_discovery_candidates($candidates), 0, 36);

        self::begin_varnish_test_run();
        try {
            foreach ($candidates as $candidate) {
                if (!self::varnish_discovery_socket_is_open((string) ($candidate['host'] ?? ''), (int) ($candidate['port'] ?? 0))) {
                    continue;
                }

                foreach (array('PURGE', 'BAN') as $method) {
                    $result = self::send_varnish_discovery_invalidation(
                        $candidate,
                        $site_host,
                        $site_scheme,
                        $home_request_target,
                        $method,
                        $token,
                        $timeout
                    );

                    if (!empty($result['accepted'])) {
                        $endpoint = (string) ($result['endpoint'] ?? '');
                        return array(
                            'success' => true,
                            'verified' => false,
                            'status' => 'candidate-found',
                            'message' => sprintf(
                                /* translators: 1: Varnish method, 2: endpoint. */
                                __('Found a Varnish %1$s candidate at %2$s. The request was accepted, but exact invalidation is not verified until the isolated canary test succeeds.', 'ultracache'),
                                $method,
                                $endpoint
                            ),
                            'configuration' => array(
                                'varnishConnectionConfigured' => true,
                                'varnishCliMode' => 'http',
                                'varnishCliServers' => $endpoint,
                                'varnishCliTimeoutSeconds' => 2,
                                'varnishCliMethod' => $method,
                                'varnishInvalidationStrategy' => strtolower($method),
                                'varnishFlushScope' => 'auto',
                            ),
                        );
                    }

                    if (empty($authentication_candidate) && !empty($result['authenticationRequired'])) {
                        $authentication_candidate = array(
                            'endpoint' => (string) ($result['endpoint'] ?? ''),
                            'method' => $method,
                        );
                    }
                }
            }
        } finally {
            self::end_varnish_test_run();
        }

        if (!empty($authentication_candidate['endpoint'])) {
            $method = in_array((string) ($authentication_candidate['method'] ?? ''), array('PURGE', 'BAN'), true)
                ? (string) $authentication_candidate['method']
                : 'PURGE';
            return array(
                'success' => true,
                'verified' => false,
                'status' => 'http-token-required',
                'message' => __('A Varnish HTTP listener was found, but it requires a token or control key.', 'ultracache'),
                'configuration' => array(
                    'varnishConnectionConfigured' => true,
                    'varnishCliMode' => 'http',
                    'varnishCliServers' => (string) $authentication_candidate['endpoint'],
                    'varnishCliTimeoutSeconds' => 2,
                    'varnishCliMethod' => $method,
                    'varnishInvalidationStrategy' => strtolower($method),
                    'varnishFlushScope' => 'auto',
                ),
                'requiresToken' => true,
            );
        }

        return array(
            'success' => false,
            'verified' => false,
            'status' => 'not-found',
            'message' => __('Varnish was detected on the homepage, but no tested HTTP PURGE or BAN endpoint accepted the request.', 'ultracache'),
        );
    }
}
