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
