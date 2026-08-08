<?php
/**
 * Bounded Varnish discovery host, port, and endpoint candidates.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Discovery_Candidates_Trait
{
    /**
     * Normalize one discovery host without accepting paths or arbitrary URLs.
     *
     * @param mixed $host Candidate host.
     * @return string
     */
    private static function normalize_varnish_discovery_host($host)
    {
        $host = trim((string) $host);
        $host = trim($host, " \t\n\r\0\x0B[]");
        if ('' === $host || false !== strpos($host, '/') || false !== strpos($host, '\\')) {
            return '';
        }

        if (strlen($host) > 253 || !preg_match('/^[A-Za-z0-9:._-]+$/', $host)) {
            return '';
        }

        return strtolower($host);
    }

    /**
     * Add one bounded host candidate while preserving source priority.
     *
     * @param array  $hosts    Candidate map.
     * @param mixed  $host     Candidate host.
     * @param string $source   Discovery source.
     * @param int    $priority Source priority.
     * @return void
     */
    private static function add_varnish_discovery_host(array &$hosts, $host, $source, $priority)
    {
        $host = self::normalize_varnish_discovery_host($host);
        if ('' === $host) {
            return;
        }

        $home_host = self::normalize_varnish_discovery_host(wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' !== $home_host && $host === $home_host) {
            return;
        }

        $priority = (int) $priority;
        if (!isset($hosts[$host]) || $priority > (int) ($hosts[$host]['priority'] ?? 0)) {
            $hosts[$host] = array(
                'host' => $host,
                'source' => sanitize_key((string) $source),
                'priority' => $priority,
            );
        }
    }

    /**
     * Parse one configured HTTP endpoint for discovery seeding.
     *
     * @param string $terminal Endpoint string.
     * @return array
     */
    private static function parse_varnish_discovery_http_endpoint($terminal)
    {
        $terminal = trim((string) $terminal);
        if ('' === $terminal) {
            return array();
        }

        $url = preg_match('#^https?://#i', $terminal) ? $terminal : 'http://' . $terminal;
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return array();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = self::normalize_varnish_discovery_host($parts['host'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host || $port <= 0 || $port > 65535) {
            return array();
        }

        return array(
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        );
    }

    /**
     * Build a scheme-preserving endpoint label.
     *
     * @param string $scheme Scheme.
     * @param string $host   Host.
     * @param int    $port   Port.
     * @return string
     */
    private static function build_varnish_discovery_endpoint_label($scheme, $host, $port)
    {
        $scheme = 'https' === strtolower((string) $scheme) ? 'https' : 'http';
        $host = self::normalize_varnish_discovery_host($host);
        $port = (int) $port;
        if ('' === $host || $port <= 0) {
            return '';
        }

        $url_host = false !== strpos($host, ':') ? '[' . $host . ']' : $host;
        return $scheme . '://' . $url_host . ':' . $port;
    }

    /**
     * Return locally grounded discovery hosts and explicitly configured hosts.
     *
     * @param array $settings Current Varnish settings.
     * @return array
     */
    private static function get_varnish_discovery_hosts(array $settings)
    {
        $hosts = array();

        foreach ((array) ($settings['servers'] ?? array()) as $terminal) {
            $endpoint = self::parse_varnish_discovery_http_endpoint($terminal);
            if (!empty($endpoint['host'])) {
                self::add_varnish_discovery_host($hosts, $endpoint['host'], 'configured', 140);
            }
        }

        self::add_varnish_discovery_host($hosts, '127.0.0.1', 'loopback', 130);
        self::add_varnish_discovery_host($hosts, 'localhost', 'loopback-name', 129);
        self::add_varnish_discovery_host($hosts, '::1', 'loopback-ipv6', 128);

        foreach (array('SERVER_ADDR' => 120, 'LOCAL_ADDR' => 119) as $key => $priority) {
            $value = function_exists('ultracache_server_value') ? ultracache_server_value($key) : '';
            self::add_varnish_discovery_host($hosts, $value, strtolower($key), $priority);
        }

        $runtime_hostname = function_exists('ultracache_server_value') ? ultracache_server_value('HOSTNAME') : '';
        self::add_varnish_discovery_host($hosts, $runtime_hostname, 'hostname', 110);
        if (function_exists('gethostname')) {
            self::add_varnish_discovery_host($hosts, gethostname(), 'gethostname', 109);
        }
        if (function_exists('php_uname')) {
            self::add_varnish_discovery_host($hosts, php_uname('n'), 'php-uname', 108);
        }

        $server_address = function_exists('ultracache_server_value') ? ultracache_server_value('SERVER_ADDR') : '';
        $server_address = self::normalize_varnish_discovery_host($server_address);
        if ('' !== $server_address && filter_var($server_address, FILTER_VALIDATE_IP) && function_exists('gethostbyaddr')) {
            $reverse = @gethostbyaddr($server_address); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reverse DNS failure is expected during bounded infrastructure discovery.
            if (is_string($reverse) && $reverse !== $server_address) {
                self::add_varnish_discovery_host($hosts, $reverse, 'reverse-dns', 100);
            }
        }

        uasort($hosts, static function ($left, $right) {
            return (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);
        });

        return array_slice(array_values($hosts), 0, 10);
    }

    /**
     * Return bounded candidate ports, preferring configured values.
     *
     * @param array $settings Current Varnish settings.
     * @return array
     */
    private static function get_varnish_discovery_ports(array $settings)
    {
        $ports = array();
        if ('http' === (string) ($settings['mode'] ?? 'http')) {
            foreach ((array) ($settings['servers'] ?? array()) as $terminal) {
                $endpoint = self::parse_varnish_discovery_http_endpoint($terminal);
                if (!empty($endpoint['port'])) {
                    $ports[] = (int) $endpoint['port'];
                }
            }
        }

        $ports = array_merge($ports, array(80, 81, 82, 443, 6081, 8080, 8081, 8443));
        $ports = array_values(array_unique(array_filter(array_map('intval', $ports), static function ($port) {
            return $port > 0 && $port <= 65535;
        })));

        return array_slice($ports, 0, 10);
    }

    /**
     * Open and immediately close one candidate socket.
     *
     * @param string $host    Host.
     * @param int    $port    Port.
     * @param float  $timeout Timeout seconds.
     * @return bool
     */
    private static function varnish_discovery_socket_is_open($host, $port, $timeout = 0.35)
    {
        $errno = 0;
        $errstr = '';
        $socket = ultracache_safe_fsockopen(
            (string) $host,
            (int) $port,
            $errno,
            $errstr,
            max(0.1, min(1.0, (float) $timeout)),
            'configured_varnish_discovery'
        );
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The bounded discovery socket is opened only through UltraCache's guarded socket wrapper.
        return true;
    }

    /**
     * Remove duplicate listener aliases while keeping the highest-priority one.
     *
     * @param array $candidates Candidate list.
     * @return array
     */
    private static function deduplicate_varnish_discovery_candidates(array $candidates)
    {
        $deduplicated = array();
        foreach ($candidates as $candidate) {
            $host = (string) ($candidate['host'] ?? '');
            $resolved = $host;
            if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('gethostbyname')) {
                $candidate_ip = @gethostbyname($host); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is expected during bounded infrastructure discovery.
                if (is_string($candidate_ip) && '' !== $candidate_ip) {
                    $resolved = $candidate_ip;
                }
            }
            $key = strtolower((string) ($candidate['scheme'] ?? 'http')) . '|' . strtolower($resolved) . '|' . (int) ($candidate['port'] ?? 0);
            if (!isset($deduplicated[$key]) || (int) ($candidate['priority'] ?? 0) > (int) ($deduplicated[$key]['priority'] ?? 0)) {
                $deduplicated[$key] = $candidate;
            }
        }

        uasort($deduplicated, static function ($left, $right) {
            return (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);
        });

        return array_values($deduplicated);
    }
}
