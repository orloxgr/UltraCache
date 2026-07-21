<?php
/**
 * HTML variant and response-header policy for the UltraCache engine.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Response_Headers_Trait
{
    /**
     * Return the canonical active HTML image-variant policy.
     *
     * @param array $settings Optional normalized settings.
     * @return array
     */
    private function get_html_variant_policy(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        return ultracache_get_html_variant_policy($settings);
    }

    /**
     * Whether cached HTML can differ according to the Accept header.
     *
     * @param array $settings Optional normalized settings.
     * @return bool
     */
    private function should_vary_html_by_accept(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        return ultracache_should_vary_html_by_accept($settings);
    }

    /**
     * Resolve the active image bucket for a request.
     *
     * @param string|null $accept_header Optional explicit Accept header.
     * @return string
     */
    private function get_request_image_bucket($accept_header = null)
    {
        if (null === $accept_header) {
            $accept_header = ultracache_server_value('HTTP_ACCEPT');
        }

        return ultracache_get_html_variant_bucket_for_accept((string) $accept_header, $this->get_settings());
    }

    /**
     * Whether the diagnostic HTML bucket header should be emitted.
     *
     * @param array $settings Optional normalized settings.
     * @return bool
     */
    private function should_send_html_variant_header(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        return !empty($settings['debug_headers_enabled'])
            || !empty($settings['debugHeadersEnabled'])
            || !empty($settings['varnish_cli_enabled'])
            || !empty($settings['varnishCliEnabled']);
    }

    /**
     * Return the configured positive shared-cache TTL in seconds.
     *
     * @param array $settings Optional normalized settings.
     * @return int
     */
    private function get_varnish_html_ttl_seconds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        $varnish_enabled = !empty($settings['varnish_cli_enabled']) || !empty($settings['varnishCliEnabled']);
        $minutes = isset($settings['varnish_html_ttl_minutes'])
            ? absint($settings['varnish_html_ttl_minutes'])
            : absint($settings['varnishHtmlTtlMinutes'] ?? 0);

        return $varnish_enabled ? max(0, min(525600, $minutes)) * MINUTE_IN_SECONDS : 0;
    }

    /**
     * Return the configured stale-while-revalidate window in seconds.
     *
     * @param array $settings Optional normalized settings.
     * @return int
     */
    private function get_varnish_stale_while_revalidate_seconds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        if ($this->get_varnish_html_ttl_seconds($settings) <= 0) {
            return 0;
        }

        $seconds = isset($settings['varnish_stale_while_revalidate_seconds'])
            ? absint($settings['varnish_stale_while_revalidate_seconds'])
            : absint($settings['varnishStaleWhileRevalidateSeconds'] ?? 0);

        return max(0, min(86400, $seconds));
    }

    /**
     * Whether existing response headers forbid public shared caching.
     *
     * @return bool
     */
    private function response_forbids_public_shared_cache()
    {
        foreach (headers_list() as $header_line) {
            $header_line = (string) $header_line;
            if (0 === stripos($header_line, 'Set-Cookie:')) {
                return true;
            }
            if (0 === stripos($header_line, 'Pragma:') && false !== stripos($header_line, 'no-cache')) {
                return true;
            }
            if (0 === stripos($header_line, 'Surrogate-Control:') && 1 === preg_match('/(?:^|[,\s])(private|no-store)(?:$|[,=\s])/', strtolower($header_line))) {
                return true;
            }
            if (0 !== stripos($header_line, 'Cache-Control:')) {
                continue;
            }

            $value = strtolower(trim(substr($header_line, strlen('Cache-Control:'))));
            if (1 === preg_match('/(?:^|[,\s])(private|no-store|no-cache)(?:$|[,=\s])/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send the Varnish shared-cache contract for a public HTML response.
     *
     * @param bool $cacheable Whether this response should receive a positive shared TTL.
     * @return void
     */
    private function send_varnish_shared_html_headers($cacheable = true)
    {
        if (headers_sent()) {
            return;
        }

        $seconds = $this->get_varnish_html_ttl_seconds();
        if ($seconds <= 0) {
            return;
        }

        if (!$cacheable) {
            header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
            header('Surrogate-Control: no-store', true);
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-UltraCache-Stale-While-Revalidate: 0');
            return;
        }

        if ($this->response_forbids_public_shared_cache()) {
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-UltraCache-Stale-While-Revalidate: 0');
            return;
        }

        $stale_seconds = $this->get_varnish_stale_while_revalidate_seconds();
        $cache_control = 'public, max-age=0, s-maxage=' . (string) $seconds;
        if ($stale_seconds > 0) {
            $cache_control .= ', stale-while-revalidate=' . (string) $stale_seconds;
        }

        header('Cache-Control: ' . $cache_control, true);
        header('X-UltraCache-Cacheable: 1');
        header('X-UltraCache-Surrogate-TTL: ' . (string) $seconds);
        header('X-UltraCache-Stale-While-Revalidate: ' . (string) $stale_seconds);
    }

    /**
     * Send the canonical Vary header and optional bucket diagnostic header.
     *
     * @param string|null $bucket Optional known bucket.
     * @return void
     */
    private function send_html_variant_headers($bucket = null)
    {
        if (headers_sent()) {
            return;
        }

        $settings = $this->get_settings();
        $vary = $this->should_vary_html_by_accept($settings)
            ? 'Accept, Accept-Encoding'
            : 'Accept-Encoding';
        header('Vary: ' . $vary, false);

        if ($this->should_send_html_variant_header($settings)) {
            $bucket = null === $bucket
                ? ultracache_get_html_variant_bucket_for_accept(ultracache_server_value('HTTP_ACCEPT'), $settings)
                : (string) $bucket;
            $bucket = in_array($bucket, array('orig', 'webp', 'avif'), true) ? $bucket : 'orig';
            header('X-UltraCache-Variant: ' . $bucket);
        }
    }
}
