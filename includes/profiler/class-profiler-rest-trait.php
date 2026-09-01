<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Profiler_Rest_Trait
{
    private function normalize_performance_profile_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('compact', 'verbose', 'callback'), true) ? $mode : 'compact';
    }

    private function normalize_performance_profile_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            $url = home_url('/');
        }

        if (0 === strpos($url, '/')) {
            $url = home_url($url);
        }

        $url = esc_url_raw($url);
        $parts = wp_parse_url($url);
        $home_parts = wp_parse_url(home_url('/'));
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if ('' === $host || (function_exists('ultracache_is_public_site_url') && !ultracache_is_public_site_url($url))) {
            return new WP_Error('ultracache_profile_url_not_allowed', __('Only same-site URLs can be scanned.', 'ultracache'));
        }
        if (!function_exists('ultracache_is_public_site_url')) {
            $home_host = isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '';
            if ('' === $home_host || $host !== $home_host) {
                return new WP_Error('ultracache_profile_url_not_allowed', __('Only same-site URLs can be scanned.', 'ultracache'));
            }
        }

        if (function_exists('ultracache_is_strict_frontend_loopback_url') && !ultracache_is_strict_frontend_loopback_url($url)) {
            return new WP_Error('ultracache_profile_url_not_allowed', __('Only same-site frontend URLs on the site port can be scanned.', 'ultracache'));
        }

        $scheme = isset($parts['scheme']) && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true) ? strtolower((string) $parts['scheme']) : '';
        if ('' === $scheme) {
            $scheme = isset($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https';
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ('' === $path) {
            $path = '/';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $query = isset($parts['query']) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public function get_performance_profile_last()
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Speed diagnostics helper is not available.', 'ultracache')), 500);
        }

        $profile = $engine->get_last_store_profile();
        if (!is_array($profile) || empty($profile)) {
            return new WP_REST_Response(array('success' => true, 'message' => __('No speed timing breakdown found yet.', 'ultracache'), 'performanceProfile' => array(), 'profile' => null), 200);
        }

        return new WP_REST_Response(array(
            'success'            => true,
            'message'            => __('Last speed timing breakdown loaded.', 'ultracache'),
            'performanceProfile' => $this->summarize_performance_profile($profile),
            'profile'            => $profile,
        ), 200);
    }

    public function clear_performance_profile_last()
    {
        $engine = $this->get_engine();
        if (!$engine || !method_exists($engine, 'clear_last_store_profile')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Speed diagnostics helper is not available.', 'ultracache')), 500);
        }

        $ok = (bool) $engine->clear_last_store_profile();
        return new WP_REST_Response(array(
            'success' => true,
            'cleared' => $ok,
            'message' => $ok ? __('Last speed timing breakdown cleared.', 'ultracache') : __('No speed timing breakdown was present.', 'ultracache'),
        ), 200);
    }

}
