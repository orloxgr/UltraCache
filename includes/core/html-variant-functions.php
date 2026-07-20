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
 * Resolve an HTTP Accept header to one of the active UltraCache HTML buckets.
 *
 * @param string $accept_header HTTP Accept header.
 * @param array  $settings      UltraCache settings.
 * @return string
 */
function ultracache_get_html_variant_bucket_for_accept($accept_header, array $settings)
{
    $policy = ultracache_get_html_variant_policy($settings);
    $allowed = array_fill_keys((array) $policy['buckets'], true);
    $accept_header = strtolower((string) $accept_header);

    if (isset($allowed['avif']) && false !== strpos($accept_header, 'image/avif')) {
        return 'avif';
    }

    if (isset($allowed['webp']) && false !== strpos($accept_header, 'image/webp')) {
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
