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
     * Return semantic LiteSpeed tags for the current resolved WordPress query.
     *
     * Singular pages receive only their own object identity. Archive/group tags
     * are attached only to responses whose HTML actually represents that group,
     * keeping later semantic invalidation narrow instead of purging unrelated
     * singular objects that merely share an author, term, or post type.
     *
     * @param string $url Optional canonical public URL for filter context.
     * @return array<int,string>
     */
    private function get_litespeed_semantic_tags_for_current_query($url = '')
    {
        $tags = array();

        if (function_exists('is_front_page') && is_front_page() && function_exists('ultracache_get_litespeed_front_tag')) {
            $tags[] = ultracache_get_litespeed_front_tag();
        }

        if (function_exists('is_home') && is_home()) {
            if (function_exists('ultracache_get_litespeed_posts_index_tag')) {
                $tags[] = ultracache_get_litespeed_posts_index_tag();
            }
            if (function_exists('ultracache_get_litespeed_post_type_archive_tag')) {
                $tags[] = ultracache_get_litespeed_post_type_archive_tag('post');
            }
            $posts_page_id = absint(get_option('page_for_posts', 0));
            if ($posts_page_id > 0 && function_exists('ultracache_get_litespeed_post_tag')) {
                $tags[] = ultracache_get_litespeed_post_tag($posts_page_id);
            }
        }

        if (function_exists('is_singular') && is_singular()) {
            $post_id = function_exists('get_queried_object_id') ? absint(get_queried_object_id()) : 0;
            if ($post_id > 0 && function_exists('ultracache_get_litespeed_post_tag')) {
                $tags[] = ultracache_get_litespeed_post_tag($post_id);
            }
        }

        if (function_exists('is_post_type_archive') && is_post_type_archive()) {
            $post_types = get_query_var('post_type');
            $post_types = is_array($post_types) ? $post_types : array($post_types);
            foreach ($post_types as $post_type) {
                if (function_exists('ultracache_get_litespeed_post_type_archive_tag')) {
                    $tags[] = ultracache_get_litespeed_post_type_archive_tag($post_type);
                }
            }
        }

        if (function_exists('is_category') && (is_category() || is_tag() || is_tax())) {
            $term = function_exists('get_queried_object') ? get_queried_object() : null;
            if ($term instanceof WP_Term && function_exists('ultracache_get_litespeed_term_tag')) {
                $tags[] = ultracache_get_litespeed_term_tag($term->term_id);
            }
        }

        if (function_exists('is_author') && is_author()) {
            $author_id = function_exists('get_queried_object_id') ? absint(get_queried_object_id()) : 0;
            if ($author_id > 0 && function_exists('ultracache_get_litespeed_author_tag')) {
                $tags[] = ultracache_get_litespeed_author_tag($author_id);
            }
        }

        if (function_exists('is_date') && is_date() && function_exists('ultracache_get_litespeed_date_archive_tag')) {
            $tags[] = ultracache_get_litespeed_date_archive_tag();
        }

        if (function_exists('is_shop') && is_shop()) {
            if (function_exists('ultracache_get_litespeed_shop_tag')) {
                $tags[] = ultracache_get_litespeed_shop_tag();
            }
            if (function_exists('ultracache_get_litespeed_post_type_archive_tag')) {
                $tags[] = ultracache_get_litespeed_post_type_archive_tag('product');
            }
        }

        $tags = apply_filters('ultracache_litespeed_semantic_tags', $tags, (string) $url);
        return function_exists('ultracache_normalize_litespeed_cache_tags')
            ? ultracache_normalize_litespeed_cache_tags((array) $tags)
            : array_values(array_unique(array_filter(array_map('strval', (array) $tags))));
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

        $litespeed_esi_parent = !empty($this->litespeed_esi_parent_response_enabled)
            && function_exists('ultracache_litespeed_esi_is_enabled')
            && ultracache_litespeed_esi_is_enabled();
        $litespeed_woocommerce_shared_parent = $litespeed_esi_parent
            && !empty($this->litespeed_woocommerce_esi_provisional_request);

        if (!$cacheable || $this->response_forbids_litespeed_public_cache()) {
            header('X-LiteSpeed-Cache-Control: no-cache' . ($litespeed_esi_parent ? ',esi=on' : ''), true);
            if ($litespeed_esi_parent) {
                header('X-UltraCache-LiteSpeed-ESI: 1', true);
            }
            return;
        }

        if ('' === (string) $url && method_exists($this, 'get_current_request_url')) {
            $url = (string) $this->get_current_request_url();
        }

        /*
         * Native LiteSpeed query-key canonicalization is intentionally not
         * claimed by UltraCache. Query variants, including WPML `?lang=xx`,
         * remain PHP/advanced-cache page-cache variants until a native query-key
         * equivalence proof exists. The managed .htaccess rules already bypass
         * query requests; this response guard makes the same contract explicit.
         */
        $url_parts = '' !== (string) $url ? wp_parse_url((string) $url) : array();
        if (is_array($url_parts) && !empty($url_parts['query'])) {
            header('X-LiteSpeed-Cache-Control: no-cache' . ($litespeed_esi_parent ? ',esi=on' : ''), true);
            if ($litespeed_esi_parent) {
                header('X-UltraCache-LiteSpeed-ESI: 1', true);
            }
            return;
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
        foreach ($this->get_litespeed_semantic_tags_for_current_query($url) as $semantic_tag) {
            $tags[] = $semantic_tag;
        }
        $tags = function_exists('ultracache_normalize_litespeed_cache_tags')
            ? ultracache_normalize_litespeed_cache_tags($tags)
            : array_values(array_unique($tags));

        header('X-LiteSpeed-Cache-Control: public,max-age=' . (string) $seconds . ($litespeed_esi_parent ? ',esi=on' : ''), true);
        if ($litespeed_esi_parent) {
            header('X-UltraCache-LiteSpeed-ESI: 1', true);
        }
        if (!empty($tags)) {
            header('X-LiteSpeed-Tag: ' . implode(',', array_values(array_unique($tags))), true);
        }

        if ($this->should_vary_html_by_accept($settings)) {
            $bucket = null === $bucket
                ? ultracache_get_html_variant_bucket_for_accept(ultracache_server_value('HTTP_ACCEPT'), $settings)
                : (string) $bucket;
            $vary_value = $litespeed_woocommerce_shared_parent
                ? 'uc_woo_esi_' . (in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig')
                : (function_exists('ultracache_get_litespeed_vary_value_for_bucket')
                    ? ultracache_get_litespeed_vary_value_for_bucket($bucket)
                    : 'uc_orig');
            header('X-LiteSpeed-Vary: value=' . $vary_value, true);
        } elseif ($litespeed_woocommerce_shared_parent) {
            header('X-LiteSpeed-Vary: value=uc_woo_esi', true);
        }
    }
}
