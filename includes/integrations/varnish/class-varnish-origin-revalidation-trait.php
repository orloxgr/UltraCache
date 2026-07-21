<?php
/**
 * Authenticated Varnish-to-WordPress origin revalidation contract.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Origin_Revalidation_Trait
{
    /**
     * Return the origin-revalidation contract and latest result.
     *
     * @return array
     */
    public static function get_varnish_origin_revalidation_status()
    {
        $two_stage = self::get_varnish_two_stage_refill_status();
        $applicable = !empty($two_stage['applicable']);
        $available = !empty($two_stage['available']);
        $status = sanitize_key((string) ($two_stage['status'] ?? 'untested'));
        if ($available) {
            $status = 'verified';
        }

        return array(
            'applicable' => $applicable,
            'requiredForSoftPurge' => true,
            'verified' => $available,
            'status' => $status,
            'testedAt' => absint($two_stage['testedAt'] ?? 0),
            'reachedBucketCount' => absint($two_stage['reachedBucketCount'] ?? 0),
            'expectedBucketCount' => absint($two_stage['expectedBucketCount'] ?? 0),
            'message' => self::sanitize_varnish_string((string) ($two_stage['message'] ?? '')),
        );
    }

    /**
     * Run an authenticated force-refresh and prove that every active HTML
     * bucket reached the WordPress engine rather than being served by Varnish.
     *
     * @param string $url Local public URL.
     * @return array
     */
    protected static function run_varnish_origin_revalidation_contract_test($url)
    {
        if (!self::is_varnish_origin_revalidation_applicable()) {
            return self::get_varnish_origin_revalidation_status();
        }

        $origin_result = self::perform_varnish_origin_refresh($url);
        $status = self::assess_varnish_origin_refresh_result(is_array($origin_result) ? $origin_result : array());
        self::set_varnish_two_stage_refill_status($status);

        $contract = self::get_varnish_origin_revalidation_status();
        $contract['verified'] = !empty($status['available']);
        $contract['status'] = !empty($status['available']) ? 'verified' : sanitize_key((string) ($status['status'] ?? 'inconclusive'));
        $contract['testedAt'] = absint($status['testedAt'] ?? time());
        $contract['reachedBucketCount'] = absint($status['reachedBucketCount'] ?? 0);
        $contract['expectedBucketCount'] = absint($status['expectedBucketCount'] ?? 0);
        $contract['message'] = self::sanitize_varnish_string((string) ($status['message'] ?? ''));

        return $contract;
    }
}
