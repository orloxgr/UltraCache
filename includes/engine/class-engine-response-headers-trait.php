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
            || !empty($settings['shared_cache_delivery_enabled'])
            || !empty($settings['varnish_cli_enabled']);
    }

    /**
     * Return the configured positive shared-cache TTL in seconds.
     *
     * @param array $settings Optional normalized settings.
     * @return int
     */
    private function get_shared_cache_html_ttl_seconds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        $delivery_enabled = !empty($settings['shared_cache_delivery_enabled']);
        if (!$delivery_enabled) {
            return 0;
        }

        $control_verified = !empty($settings['shared_cache_control_verified']);
        $proof_expires_at = absint($settings['shared_cache_control_proof_expires_at'] ?? 0);
        if ($control_verified && $proof_expires_at > 0 && $proof_expires_at <= time()) {
            $control_verified = false;
        }
        $minutes = $control_verified
            ? absint($settings['shared_cache_managed_ttl_minutes'] ?? $settings['cache_fresh_ttl_minutes'] ?? 1440)
            : absint($settings['shared_cache_ttl_only_minutes'] ?? 10);

        return max(1, min(525600, $minutes)) * MINUTE_IN_SECONDS;
    }

    /**
     * Return the configured stale-while-revalidate window in seconds.
     *
     * @param array $settings Optional normalized settings.
     * @return int
     */
    private function get_shared_cache_stale_while_revalidate_seconds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        $proof_expires_at = absint($settings['shared_cache_control_proof_expires_at'] ?? 0);
        $control_verified = !empty($settings['shared_cache_control_verified'])
            && (0 === $proof_expires_at || $proof_expires_at > time());
        if ($this->get_shared_cache_html_ttl_seconds($settings) <= 0 || !$control_verified) {
            return 0;
        }

        $seconds = isset($settings['varnish_stale_while_revalidate_seconds'])
            ? absint($settings['varnish_stale_while_revalidate_seconds'])
            : 0;

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
     * Whether this response may approve a shared parent for WooCommerce-cookie
     * candidate requests in the bundled Varnish handshake template.
     *
     * Approval is emitted only for anonymous/non-candidate responses that are
     * already cacheable and contain the exact WooCommerce mini-cart ESI fragment.
     * Candidate misses keep their real WooCommerce cookies at the origin and
     * remain uncacheable; only a previously approved anonymous object may be
     * reused by Varnish for those visitors.
     *
     * @param bool  $cacheable   Whether the response may enter shared cache.
     * @param array $esi_metadata Normalized ESI parent metadata.
     * @return bool
     */
    private function should_approve_varnish_esi_shared_parent($cacheable, array $esi_metadata)
    {
        if (!$cacheable || empty($esi_metadata)) {
            return false;
        }

        $fragment_count = max(0, (int) ($esi_metadata['fragmentCount'] ?? 0));
        $private_count = max(0, min($fragment_count, (int) ($esi_metadata['privateCount'] ?? 0)));
        if ($private_count <= 0 || empty($esi_metadata['woocommerceMiniCart'])) {
            return false;
        }

        return '1' !== trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_ESI_CANDIDATE'));
    }

    /**
     * Send the standard shared-cache contract for a public HTML response.
     *
     * @param bool  $cacheable   Whether this response should receive a positive shared TTL.
     * @param array $esi_metadata Optional normalized ESI parent metadata.
     * @return void
     */
    private function send_standard_shared_html_headers($cacheable = true, array $esi_metadata = array())
    {
        if (headers_sent()) {
            return;
        }

        $settings = $this->get_settings();
        $seconds = $this->get_shared_cache_html_ttl_seconds($settings);
        if ($seconds <= 0) {
            return;
        }

        $esi_metadata = method_exists($this, 'normalize_page_cache_esi_metadata')
            ? $this->normalize_page_cache_esi_metadata($esi_metadata)
            : $esi_metadata;
        $is_esi_parent = !empty($esi_metadata);
        if ($is_esi_parent && function_exists('header_remove')) {
            header_remove('ETag');
            header_remove('Last-Modified');
        }
        $esi_count = $is_esi_parent ? max(1, (int) ($esi_metadata['fragmentCount'] ?? 1)) : 0;
        $esi_public_count = $is_esi_parent ? max(0, min($esi_count, (int) ($esi_metadata['publicCount'] ?? $esi_count))) : 0;
        $esi_private_count = $is_esi_parent ? max(0, min($esi_count - $esi_public_count, (int) ($esi_metadata['privateCount'] ?? 0))) : 0;
        $esi_unique_count = $is_esi_parent ? max(1, min($esi_count, (int) ($esi_metadata['uniqueFragmentCount'] ?? $esi_count))) : 0;
        $esi_min_ttl = $is_esi_parent ? max(0, min(WEEK_IN_SECONDS, (int) ($esi_metadata['minTtl'] ?? 0))) : 0;
        $esi_max_ttl = $is_esi_parent ? max($esi_min_ttl, min(WEEK_IN_SECONDS, (int) ($esi_metadata['maxTtl'] ?? $esi_min_ttl))) : 0;

        if (!$cacheable) {
            header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
            header('Surrogate-Control: ' . ($is_esi_parent ? 'content="ESI/1.0", no-store' : 'no-store'), true);
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-UltraCache-Stale-While-Revalidate: 0');
            if ($is_esi_parent) {
                header('X-UltraCache-ESI: 1');
                header('X-UltraCache-ESI-Count: ' . (string) $esi_count);
                header('X-UltraCache-ESI-Public-Count: ' . (string) $esi_public_count);
                header('X-UltraCache-ESI-Private-Count: ' . (string) $esi_private_count);
                header('X-UltraCache-ESI-Unique-Count: ' . (string) $esi_unique_count);
                header('X-UltraCache-ESI-TTL-Min: ' . (string) $esi_min_ttl);
                header('X-UltraCache-ESI-TTL-Max: ' . (string) $esi_max_ttl);
            }
            return;
        }

        if ($this->response_forbids_public_shared_cache()) {
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-UltraCache-Stale-While-Revalidate: 0');
            return;
        }

        $stale_seconds = $this->get_shared_cache_stale_while_revalidate_seconds($settings);
        $cache_control = 'public, max-age=0, s-maxage=' . (string) $seconds
            . ', stale-while-revalidate=' . (string) $stale_seconds;

        header('Cache-Control: ' . $cache_control, true);
        if ($is_esi_parent) {
            header('Surrogate-Control: content="ESI/1.0"', true);
            header('X-UltraCache-ESI: 1');
            header('X-UltraCache-ESI-Count: ' . (string) $esi_count);
            header('X-UltraCache-ESI-Public-Count: ' . (string) $esi_public_count);
            header('X-UltraCache-ESI-Private-Count: ' . (string) $esi_private_count);
            header('X-UltraCache-ESI-Unique-Count: ' . (string) $esi_unique_count);
            header('X-UltraCache-ESI-TTL-Min: ' . (string) $esi_min_ttl);
            header('X-UltraCache-ESI-TTL-Max: ' . (string) $esi_max_ttl);
            if ($this->should_approve_varnish_esi_shared_parent($cacheable, $esi_metadata)) {
                header('X-UltraCache-ESI-Shared-Parent: 1');
            }
        }
        header('X-UltraCache-Cacheable: 1');
        header('X-UltraCache-Surrogate-TTL: ' . (string) $seconds);
        header('X-UltraCache-Stale-While-Revalidate: ' . (string) $stale_seconds);
        $proof_expires_at = absint($settings['shared_cache_control_proof_expires_at'] ?? 0);
        $delivery_mode = !empty($settings['shared_cache_control_verified'])
            && (0 === $proof_expires_at || $proof_expires_at > time())
            ? 'managed'
            : 'ttl-only';
        header('X-UltraCache-Shared-Cache-Mode: ' . $delivery_mode);
    }

    /**
     * Send every configured shared HTML cache contract.
     *
     * @param bool        $cacheable Whether this response may enter public shared cache.
     * @param string|null $bucket    Optional known UltraCache HTML bucket.
     * @param string      $url          Optional exact public URL.
     * @param array       $esi_metadata Optional normalized ESI parent metadata.
     * @return void
     */
    private function send_shared_html_cache_headers($cacheable = true, $bucket = null, $url = '', array $esi_metadata = array())
    {
        $this->send_standard_shared_html_headers($cacheable, $esi_metadata);
        $this->send_litespeed_shared_html_headers($cacheable, $bucket, $url);
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
