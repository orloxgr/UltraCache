<?php
/**
 * Varnish HTML variant compatibility diagnostics for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Variant_Test_Trait
{
    /**
     * Return the canonical Accept profiles used by the bounded variant test.
     *
     * @return array<string,string>
     */
    private static function get_varnish_html_variant_accept_profiles()
    {
        return array(
            'orig' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'webp' => 'text/html,application/xhtml+xml,image/webp,*/*;q=0.8',
            'avif' => 'text/html,application/xhtml+xml,image/avif,image/webp,*/*;q=0.8',
        );
    }

    /**
     * Verify that Varnish maps canonical Accept profiles to UltraCache's
     * normalized orig/webp/avif HTML buckets without duplicate raw-Accept
     * cache objects for profiles that resolve to the same bucket.
     *
     * The normal behavior test has already invalidated all URL variants and
     * refilled the orig profile before this method runs, so an unexpected MISS
     * for another profile that maps to orig is evidence of fragmentation.
     *
     * @param string $url              Front-page URL.
     * @param int    $timeout          Request timeout.
     * @param array  $baseline_steps   Main behavior-test steps.
     * @return array
     */
    private static function run_varnish_html_variant_test($url, $timeout, array $baseline_steps)
    {
        $settings = self::get_settings();
        $policy = ultracache_get_html_variant_policy($settings);
        $profiles = self::get_varnish_html_variant_accept_profiles();
        $results = array();
        $populated_buckets = array('orig' => true);
        $fragmented = false;
        $inconclusive = false;
        $variant_header_seen = false;

        $baseline_after = isset($baseline_steps['afterInvalidation']) && is_array($baseline_steps['afterInvalidation'])
            ? $baseline_steps['afterInvalidation']
            : array();
        $baseline_verification = isset($baseline_steps['verification']) && is_array($baseline_steps['verification'])
            ? $baseline_steps['verification']
            : array();

        $results['orig'] = array(
            'expectedBucket' => 'orig',
            'first' => $baseline_after,
            'second' => $baseline_verification,
            'reusedPopulatedBucket' => false,
        );

        foreach (array('webp', 'avif') as $profile_name) {
            $accept = (string) $profiles[$profile_name];
            $expected_bucket = ultracache_get_html_variant_bucket_for_accept($accept, $settings);
            $bucket_was_populated = !empty($populated_buckets[$expected_bucket]);
            $first = self::run_varnish_behavior_request(
                $url,
                'variant_' . $profile_name . '_first',
                $timeout,
                $accept
            );
            $second = array();

            $first_status = strtoupper((string) ($first['status'] ?? 'INCONCLUSIVE'));
            if (!$bucket_was_populated && !empty($first['success'])) {
                $second = self::run_varnish_behavior_request(
                    $url,
                    'variant_' . $profile_name . '_second',
                    $timeout,
                    $accept
                );
            }

            foreach (array($first, $second) as $profile_response) {
                $observed_variant = strtolower(trim((string) ($profile_response['headers']['ultraCacheVariant'] ?? '')));
                if ('' === $observed_variant) {
                    continue;
                }
                $variant_header_seen = true;
                if ($observed_variant !== $expected_bucket) {
                    $fragmented = true;
                }
            }

            if ($bucket_was_populated) {
                if ('MISS' === $first_status) {
                    $fragmented = true;
                } elseif ('HIT' !== $first_status) {
                    $inconclusive = true;
                }
            } else {
                $second_status = strtoupper((string) ($second['status'] ?? ''));
                if (!empty($second) && 'HIT' !== $second_status) {
                    $inconclusive = true;
                } elseif (empty($second) && 'HIT' !== $first_status) {
                    $inconclusive = true;
                }
                $populated_buckets[$expected_bucket] = true;
            }

            $results[$profile_name] = array(
                'expectedBucket' => $expected_bucket,
                'first' => $first,
                'second' => $second,
                'reusedPopulatedBucket' => $bucket_was_populated,
            );
        }

        if ($fragmented) {
            $status = 'fragmented';
            $message = self::maybe_translate('Varnish created or exposed a separate object for an Accept profile that should reuse the same UltraCache HTML bucket.');
        } elseif ($inconclusive) {
            $status = 'inconclusive';
            $message = self::maybe_translate('The canonical Accept-profile requests completed without enough visible HIT/MISS evidence to verify Varnish HTML variant handling.');
        } else {
            $status = 'compatible';
            $message = self::maybe_translate('Varnish HTML variant handling is compatible with the active UltraCache orig/WebP/AVIF bucket policy.');
        }

        return array(
            'status' => $status,
            'compatible' => 'compatible' === $status,
            'message' => $message,
            'varyAcceptRequired' => !empty($policy['vary_accept']),
            'activeBuckets' => array_values((array) $policy['buckets']),
            'variantHeaderSeen' => $variant_header_seen,
            'profiles' => $results,
        );
    }
}
