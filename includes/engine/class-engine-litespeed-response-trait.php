<?php
/**
 * Native LiteSpeed response cache contract for UltraCache HTML.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LiteSpeed_Response_Trait
{
    /**
     * Whether UltraCache should publish the native LiteSpeed HTML cache contract.
     *
     * @param array $settings Optional normalized settings.
     * @return bool
     */
    private function is_litespeed_html_cache_enabled(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        $enabled = !empty($settings['litespeed_cache_enabled'])
            || !empty($settings['liteSpeedCacheEnabled']);
        $page_cache_enabled = !empty($settings['enabled'])
            || !empty($settings['pageCacheEnabled']);

        return $enabled && $page_cache_enabled;
    }

    /**
     * Return the LiteSpeed public HTML TTL in seconds.
     *
     * @param array $settings Optional normalized settings.
     * @return int
     */
    private function get_litespeed_html_ttl_seconds(array $settings = array())
    {
        if (empty($settings)) {
            $settings = $this->get_settings();
        }

        if (!$this->is_litespeed_html_cache_enabled($settings)) {
            return 0;
        }

        $minutes = isset($settings['cache_fresh_ttl_minutes'])
            ? absint($settings['cache_fresh_ttl_minutes'])
            : absint($settings['cacheFreshTtlMinutes'] ?? 1440);

        return max(1, min(525600, $minutes)) * MINUTE_IN_SECONDS;
    }

    /**
     * Whether an existing LiteSpeed response header already forbids public cache.
     *
     * @return bool
     */
    private function response_forbids_litespeed_public_cache()
    {
        if ($this->response_forbids_public_shared_cache()) {
            return true;
        }

        foreach (headers_list() as $header_line) {
            $header_line = (string) $header_line;
            if (0 !== stripos($header_line, 'X-LiteSpeed-Cache-Control:')) {
                continue;
            }

            $value = strtolower(trim(substr($header_line, strlen('X-LiteSpeed-Cache-Control:'))));
            if (1 === preg_match('/(?:^|[,\s])(private|no-store|no-cache)(?:$|[,=\s])/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send the native LiteSpeed cache-control, tags, and HTML variant contract.
     *
     * @param bool        $cacheable Whether this response may enter public LSCache.
     * @param string|null $bucket    Optional known UltraCache HTML bucket.
     * @param string      $url       Optional exact public URL.
     * @return void
     */
    private function send_litespeed_shared_html_headers($cacheable = true, $bucket = null, $url = '')
    {
        if (headers_sent()) {
            return;
        }

        $settings = $this->get_settings();
        $seconds = $this->get_litespeed_html_ttl_seconds($settings);
        if ($seconds <= 0) {
            return;
        }

        if (!$cacheable || $this->response_forbids_litespeed_public_cache()) {
            header('X-LiteSpeed-Cache-Control: no-cache', true);
            return;
        }

        if ('' === (string) $url && method_exists($this, 'get_current_request_url')) {
            $url = (string) $this->get_current_request_url();
        }

        $tags = array();
        if (function_exists('ultracache_get_litespeed_site_tag')) {
            $tags[] = ultracache_get_litespeed_site_tag();
        }
        if ('' !== (string) $url && function_exists('ultracache_get_litespeed_url_tag')) {
            $url_tag = ultracache_get_litespeed_url_tag($url);
            if ('' !== $url_tag) {
                $tags[] = $url_tag;
            }
        }

        header('X-LiteSpeed-Cache-Control: public,max-age=' . (string) $seconds, true);
        if (!empty($tags)) {
            header('X-LiteSpeed-Tag: ' . implode(',', array_values(array_unique($tags))), true);
        }

        if ($this->should_vary_html_by_accept($settings)) {
            $bucket = null === $bucket
                ? ultracache_get_html_variant_bucket_for_accept(ultracache_server_value('HTTP_ACCEPT'), $settings)
                : (string) $bucket;
            $vary_value = function_exists('ultracache_get_litespeed_vary_value_for_bucket')
                ? ultracache_get_litespeed_vary_value_for_bucket($bucket)
                : 'uc_orig';
            header('X-LiteSpeed-Vary: value=' . $vary_value, true);
        }
    }
}
