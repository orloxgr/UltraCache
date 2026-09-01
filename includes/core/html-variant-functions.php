<?php
/**
 * Shared HTML image-variant policy helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the canonical UltraCache HTML image-variant policy.
 *
 * The helper accepts either dashboard-style camelCase settings or the
 * normalized runtime snake_case settings used by the engine and drop-in.
 *
 * @param array $settings UltraCache settings.
 * @return array{enabled:bool,mode:string,fallback:string,buckets:array<int,string>,vary_accept:bool}
 */
function ultracache_get_html_variant_policy(array $settings)
{
    $enabled = !empty($settings['media_optimization_enabled'])
        || !empty($settings['mediaOptimizationEnabled']);

    $mode = isset($settings['media_output_mode'])
        ? strtolower(trim((string) $settings['media_output_mode']))
        : strtolower(trim((string) ($settings['mediaOutputMode'] ?? 'webp')));
    $mode = in_array($mode, array('avif', 'webp'), true) ? $mode : 'webp';

    $fallback = isset($settings['media_fallback_format'])
        ? strtolower(trim((string) $settings['media_fallback_format']))
        : strtolower(trim((string) ($settings['mediaFallbackFormat'] ?? 'original')));
    $fallback = ('avif' === $mode && 'webp' === $fallback) ? 'webp' : 'original';

    $buckets = array('orig');
    if ($enabled) {
        if ('avif' === $mode) {
            $buckets[] = 'avif';
            if ('webp' === $fallback) {
                $buckets[] = 'webp';
            }
        } else {
            $buckets[] = 'webp';
        }
    }

    $buckets = array_values(array_unique($buckets));

    return array(
        'enabled'     => $enabled,
        'mode'        => $mode,
        'fallback'    => $fallback,
        'buckets'     => $buckets,
        'vary_accept' => count($buckets) > 1,
    );
}

/**
 * Determine whether cached HTML can differ according to the Accept header.
 *
 * @param array $settings UltraCache settings.
 * @return bool
 */
function ultracache_should_vary_html_by_accept(array $settings)
{
    $policy = ultracache_get_html_variant_policy($settings);
    return !empty($policy['vary_accept']);
}

/**
 * Return the effective quality assigned to one exact media type.
 *
 * Wildcard image ranges are intentionally ignored because they do not prove
 * browser support for a specific codec. A valid explicit q=0 range refuses
 * the codec and takes precedence over duplicate ranges; malformed q parameters
 * retain the default server preference so all runtime layers resolve the same
 * bucket.
 *
 * @param string $accept_header HTTP Accept header.
 * @param string $media_type    Exact media type, for example image/avif.
 * @return float
 */
function ultracache_get_accept_media_type_quality($accept_header, $media_type)
{
    $media_type = strtolower(trim((string) $media_type));
    if (1 !== preg_match('/\A[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+\z/', $media_type)) {
        return 0.0;
    }

    $best_quality = 0.0;
    $accept_header = substr(strtolower((string) $accept_header), 0, 8192);
    foreach (array_slice(explode(',', $accept_header), 0, 64) as $range) {
        $parts = array_map('trim', explode(';', (string) $range));
        $token = (string) array_shift($parts);
        if ($token !== $media_type) {
            continue;
        }

        $quality = 1.0;
        foreach ($parts as $parameter) {
            $parameter = trim((string) $parameter);
            if (1 !== preg_match('/\Aq\s*=/i', $parameter)) {
                continue;
            }
            if (1 === preg_match('/\Aq\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)\z/i', $parameter, $matches)) {
                $quality = max(0.0, min(1.0, (float) $matches[1]));
            }
            break;
        }

        if ($quality <= 0.0) {
            return 0.0;
        }
        $best_quality = max($best_quality, $quality);
    }

    return $best_quality;
}

/**
 * Whether an Accept header explicitly permits one exact image media type.
 *
 * @param string $accept_header HTTP Accept header.
 * @param string $media_type    Exact media type.
 * @return bool
 */
function ultracache_accept_header_allows_media_type($accept_header, $media_type)
{
    return ultracache_get_accept_media_type_quality($accept_header, $media_type) > 0.0;
}

/**
 * Resolve an HTTP Accept header to one of the active UltraCache HTML buckets.
 *
 * UltraCache keeps its configured AVIF-before-WebP server preference, but an
 * explicit q=0 refusal can never select that representation.
 *
 * @param string $accept_header HTTP Accept header.
 * @param array  $settings      UltraCache settings.
 * @return string
 */
function ultracache_get_html_variant_bucket_for_accept($accept_header, array $settings)
{
    $policy = ultracache_get_html_variant_policy($settings);
    $allowed = array_fill_keys((array) $policy['buckets'], true);

    if (isset($allowed['avif']) && ultracache_accept_header_allows_media_type($accept_header, 'image/avif')) {
        return 'avif';
    }

    if (isset($allowed['webp']) && ultracache_accept_header_allows_media_type($accept_header, 'image/webp')) {
        return 'webp';
    }

    return 'orig';
}


/**
 * Return the canonical browser Accept header for one UltraCache HTML bucket.
 *
 * @param string $bucket UltraCache HTML bucket.
 * @return string
 */
function ultracache_get_accept_header_for_html_bucket($bucket)
{
    switch ((string) $bucket) {
        case 'avif':
            return 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
        case 'webp':
            return 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
        case 'orig':
        default:
            return 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    }
}

/**
 * Return the stable LiteSpeed tag identity for this WordPress site.
 *
 * LiteSpeed cache tags must be identical when the same site is reached through
 * different request contexts. Request-aware home_url() filters may add a
 * language path or change the apparent scheme, so tag identity is derived from
 * the configured WordPress home value instead. The scheme is intentionally
 * excluded: HTTP and HTTPS objects should carry the same UltraCache tags so a
 * tag purge can invalidate every representation of the same WordPress site.
 *
 * @return string
 */
function ultracache_get_litespeed_stable_site_identity()
{
    $candidates = array();
    if (function_exists('get_option')) {
        $candidates[] = (string) get_option('home');
        $candidates[] = (string) get_option('siteurl');
    }

    foreach ($candidates as $candidate) {
        $parts = wp_parse_url(trim((string) $candidate));
        if (!is_array($parts)) {
            continue;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('' === $host) {
            continue;
        }

        $identity = $host;
        if (!empty($parts['port'])) {
            $identity .= ':' . (int) $parts['port'];
        }

        $base_path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
        $base_path = '/' . trim(str_replace('\\', '/', $base_path), '/');
        if ('/' !== $base_path) {
            $identity .= $base_path;
        }

        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        return $identity . '|' . (string) $blog_id;
    }

    $origin = function_exists('ultracache_get_configured_site_origin')
        ? (string) ultracache_get_configured_site_origin()
        : '';
    $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
    if ('' === $host) {
        return '';
    }

    $port = (int) wp_parse_url($origin, PHP_URL_PORT);
    $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return $host . ($port > 0 ? ':' . $port : '') . '|' . (string) $blog_id;
}

/**
 * Return the stable LiteSpeed public-cache tag for this WordPress site.
 *
 * @return string
 */
function ultracache_get_litespeed_site_tag()
{
    $identity = ultracache_get_litespeed_stable_site_identity();
    if ('' === $identity) {
        return '';
    }

    return 'uc_s_' . substr(hash('sha256', $identity), 0, 20);
}

/**
 * Return the stable LiteSpeed public-cache tag for one exact local URL.
 *
 * URL tags intentionally ignore scheme and query/fragment state. The request
 * path remains part of the identity, so multilingual paths such as /el/ and
 * /en/ continue to receive different exact-URL tags while HTTP/HTTPS and
 * request-filtered home_url() contexts resolve to the same tag.
 *
 * @param string $url Public URL or root-relative local path.
 * @return string
 */
function ultracache_get_litespeed_url_tag($url)
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
    if ('' === $url) {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ('' !== $host) {
        $local_hosts = array();
        if (function_exists('get_option')) {
            foreach (array('home', 'siteurl') as $option_name) {
                $candidate_host = strtolower((string) wp_parse_url((string) get_option($option_name), PHP_URL_HOST));
                if ('' !== $candidate_host) {
                    $local_hosts[$candidate_host] = true;
                }
            }
        }
        if (function_exists('home_url')) {
            $candidate_host = strtolower((string) wp_parse_url((string) home_url('/'), PHP_URL_HOST));
            if ('' !== $candidate_host) {
                $local_hosts[$candidate_host] = true;
            }
        }
        if (function_exists('site_url')) {
            $candidate_host = strtolower((string) wp_parse_url((string) site_url('/'), PHP_URL_HOST));
            if ('' !== $candidate_host) {
                $local_hosts[$candidate_host] = true;
            }
        }

        if (empty($local_hosts[$host])) {
            return '';
        }
    } elseif (0 !== strpos($url, '/')) {
        return '';
    }

    $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '/';
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if ('' === $path) {
        $path = '/';
    }

    $site_identity = ultracache_get_litespeed_stable_site_identity();
    if ('' === $site_identity) {
        return '';
    }

    $identity = $site_identity . '|' . $path;
    return 'uc_u_' . substr(hash('sha256', $identity), 0, 24);
}


/**
 * Normalize and deduplicate LiteSpeed semantic cache tags.
 *
 * @param array $tags  Candidate tags.
 * @param int   $limit Maximum returned tags.
 * @return array<int,string>
 */
function ultracache_normalize_litespeed_cache_tags(array $tags, $limit = 64)
{
    $limit = max(1, min(128, (int) $limit));
    $normalized = array();
    foreach ($tags as $tag) {
        if (!is_scalar($tag)) {
            continue;
        }
        $tag = trim((string) $tag);
        if ('' === $tag || 1 !== preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $tag)) {
            continue;
        }
        $normalized[$tag] = $tag;
        if (count($normalized) >= $limit) {
            break;
        }
    }
    return array_values($normalized);
}

/** Return the stable site namespace embedded into semantic LiteSpeed tags. */
function ultracache_get_litespeed_semantic_namespace()
{
    $site_tag = ultracache_get_litespeed_site_tag();
    $hash = 0 === strpos($site_tag, 'uc_s_') ? substr($site_tag, 5, 12) : substr(hash('sha256', $site_tag), 0, 12);
    return preg_match('/^[a-f0-9]{12}$/', (string) $hash) ? (string) $hash : substr(hash('sha256', (string) $site_tag), 0, 12);
}

/** Return the semantic LiteSpeed tag for one singular WordPress object. */
function ultracache_get_litespeed_post_tag($post_id)
{
    $post_id = absint($post_id);
    return $post_id > 0 ? 'uc_p_' . ultracache_get_litespeed_semantic_namespace() . '_' . (string) $post_id : '';
}

/** Return the semantic LiteSpeed tag for one public post-type archive dependency. */
function ultracache_get_litespeed_post_type_archive_tag($post_type)
{
    $post_type = sanitize_key((string) $post_type);
    return '' !== $post_type ? 'uc_pta_' . ultracache_get_litespeed_semantic_namespace() . '_' . substr($post_type, 0, 48) : '';
}

/** Return the semantic LiteSpeed tag for one taxonomy term archive. */
function ultracache_get_litespeed_term_tag($term_id)
{
    $term_id = absint($term_id);
    return $term_id > 0 ? 'uc_t_' . ultracache_get_litespeed_semantic_namespace() . '_' . (string) $term_id : '';
}

/** Return the semantic LiteSpeed tag for one author archive. */
function ultracache_get_litespeed_author_tag($author_id)
{
    $author_id = absint($author_id);
    return $author_id > 0 ? 'uc_a_' . ultracache_get_litespeed_semantic_namespace() . '_' . (string) $author_id : '';
}

/** Return the shared semantic tag for the site front page dependency. */
function ultracache_get_litespeed_front_tag()
{
    return 'uc_front_' . ultracache_get_litespeed_semantic_namespace();
}

/** Return the shared semantic tag for the WordPress posts index. */
function ultracache_get_litespeed_posts_index_tag()
{
    return 'uc_posts_' . ultracache_get_litespeed_semantic_namespace();
}

/** Return the shared semantic tag for date archives. */
function ultracache_get_litespeed_date_archive_tag()
{
    return 'uc_date_' . ultracache_get_litespeed_semantic_namespace();
}

/** Return the shared semantic tag for the WooCommerce shop archive. */
function ultracache_get_litespeed_shop_tag()
{
    return 'uc_shop_' . ultracache_get_litespeed_semantic_namespace();
}

/**
 * Return the LiteSpeed cache-vary environment value for one HTML bucket.
 *
 * @param string $bucket UltraCache HTML bucket.
 * @return string
 */
function ultracache_get_litespeed_vary_value_for_bucket($bucket)
{
    $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true)
        ? (string) $bucket
        : 'orig';

    return 'uc_' . $bucket;
}

