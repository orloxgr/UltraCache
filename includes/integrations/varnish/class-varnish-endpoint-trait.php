<?php
/**
 * Varnish endpoint parsing and normalization helpers for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Endpoint_Trait
{
        private static function normalize_varnish_endpoint($terminal)
        {
            $terminal = trim((string) $terminal);
            if ('' === $terminal) {
                return array();
            }

            $check = self::validate_varnish_http_endpoint($terminal);
            if (empty($check['valid'])) {
                return array();
            }

            $host = (string) ($check['host'] ?? '');
            $port = (int) ($check['port'] ?? 0);
            if ('' === $host || $port <= 0) {
                return array();
            }

            return array(
                'scheme' => 'http',
                'host'   => $host,
                'port'   => $port,
            );
        }

        private static function build_varnish_target_url(array $endpoint, $path = '/')
        {
            $path = '/' . ltrim((string) $path, '/');
            return $endpoint['scheme'] . '://' . $endpoint['host'] . ':' . $endpoint['port'] . $path;
        }

        private static function summarize_varnish_http_body($body, $max_length = 180)
        {
            $body = trim(wp_strip_all_tags((string) $body));
            if ('' === $body) {
                return '';
            }

            $body = preg_replace('/\s+/', ' ', $body);
            if (!is_string($body) || '' === $body) {
                return '';
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($body) > $max_length) {
                    $body = mb_substr($body, 0, $max_length - 1) . '…';
                }
            } elseif (strlen($body) > $max_length) {
                $body = substr($body, 0, $max_length - 1) . '…';
            }

            return $body;
        }

        private static function parse_varnish_terminal($terminal)
        {
            $terminal = trim((string) $terminal);
            if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $terminal, $matches)) {
                return array($matches[1], (int) $matches[2]);
            }

            $pos = strrpos($terminal, ':');
            if (false === $pos) {
                return array('', 0);
            }

            return array(substr($terminal, 0, $pos), (int) substr($terminal, $pos + 1));
        }
}
