<?php
/**
 * Conditional HTML validator diagnostics for the Varnish integration.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Conditional_Revalidation_Test_Trait
{
    /**
     * Test public ETag and Last-Modified handling without assuming the response source.
     *
     * @param string $url            Public URL.
     * @param int    $timeout        Request timeout.
     * @param array  $reference_step Verified cache response used for validators.
     * @return array
     */
    private static function run_varnish_conditional_revalidation_test($url, $timeout, array $reference_step)
    {
        $headers = is_array($reference_step['headers'] ?? null) ? $reference_step['headers'] : array();
        $etag = trim((string) ($headers['etag'] ?? ''));
        $last_modified = trim((string) ($headers['lastModified'] ?? ''));
        $source = self::sanitize_varnish_string((string) ($headers['ultraCacheSource'] ?? ''));
        $result = array(
            'status'                => 'unavailable',
            'observed'              => false,
            'etagAvailable'         => '' !== $etag,
            'lastModifiedAvailable' => '' !== $last_modified,
            'etagObserved'          => false,
            'lastModifiedObserved'  => false,
            'source'                => $source,
            'etagStep'              => array(),
            'lastModifiedStep'      => array(),
            'message'               => __('The verified HTML response did not expose ETag or Last-Modified validators.', 'ultracache'),
        );

        if ('' !== $etag) {
            $result['etagStep'] = self::run_varnish_behavior_request(
                $url,
                'conditional_etag',
                $timeout,
                'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                array('If-None-Match' => $etag)
            );
            $result['etagObserved'] = 304 === (int) ($result['etagStep']['httpCode'] ?? 0)
                && 0 === (int) ($result['etagStep']['bodyBytes'] ?? 0);
        }

        if ('' !== $last_modified) {
            $result['lastModifiedStep'] = self::run_varnish_behavior_request(
                $url,
                'conditional_last_modified',
                $timeout,
                'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                array('If-Modified-Since' => $last_modified)
            );
            $result['lastModifiedObserved'] = 304 === (int) ($result['lastModifiedStep']['httpCode'] ?? 0)
                && 0 === (int) ($result['lastModifiedStep']['bodyBytes'] ?? 0);
        }

        $available_count = (int) $result['etagAvailable'] + (int) $result['lastModifiedAvailable'];
        $observed_count = (int) $result['etagObserved'] + (int) $result['lastModifiedObserved'];
        if ($available_count <= 0) {
            return $result;
        }

        if ($observed_count === $available_count) {
            $result['status'] = 'observed';
            $result['observed'] = true;
            $result['message'] = __('Public conditional HTML requests returned bodyless 304 responses for every exposed validator.', 'ultracache');
        } elseif ($observed_count > 0) {
            $result['status'] = 'partial';
            $result['message'] = __('Only one of the exposed HTML validators returned a bodyless 304 response.', 'ultracache');
        } else {
            $has_error = (!empty($result['etagStep']) && empty($result['etagStep']['success']))
                || (!empty($result['lastModifiedStep']) && empty($result['lastModifiedStep']['success']));
            $result['status'] = $has_error ? 'error' : 'not-observed';
            $result['message'] = $has_error
                ? __('The conditional HTML validator requests could not be completed.', 'ultracache')
                : __('ETag or Last-Modified was exposed, but the public request did not return a bodyless 304 response.', 'ultracache');
        }

        return $result;
    }
}
