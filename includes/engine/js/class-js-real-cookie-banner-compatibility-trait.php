<?php
/**
 * Real Cookie Banner JavaScript infrastructure identification.
 *
 * Only the consent controller/blocker infrastructure is protected. Service
 * scripts materialized after consent continue through the unified runtime
 * NATIVE / DEFER / DELAY classifier.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Real_Cookie_Banner_Compatibility_Trait
{
    /** Runtime provenance fragments that identify RCB-owned infrastructure. */
    private function ultracache_real_cookie_banner_runtime_infrastructure_patterns()
    {
        return array(
            'data-webpack=realcookiebanner_:',
            'data-webpack="realcookiebanner_:',
            "data-webpack='realcookiebanner_:",
            'real-cookie-banner-pro-banner-js',
            'real-cookie-banner-pro-banner_tcf-js',
            'real-cookie-banner-pro-blocker-js',
            'real-cookie-banner-pro-blocker_tcf-js',
            'real-cookie-banner-banner-js',
            'real-cookie-banner-banner_tcf-js',
            'real-cookie-banner-blocker-js',
            'real-cookie-banner-blocker_tcf-js',
            'iabtcf-stub-js',
            '/real-cookie-banner-pro/public/dist/banner.',
            '/real-cookie-banner-pro/public/dist/banner_tcf.',
            '/real-cookie-banner-pro/public/dist/blocker.',
            '/real-cookie-banner-pro/public/dist/blocker_tcf.',
            '/real-cookie-banner-pro/public/dist/vendor-banner.',
            '/real-cookie-banner-pro/public/dist/vendor-banner_tcf.',
            '/real-cookie-banner-pro/public/dist/vendor-blocker.',
            '/real-cookie-banner-pro/public/dist/vendor-blocker_tcf.',
            '/real-cookie-banner/public/dist/banner.',
            '/real-cookie-banner/public/dist/banner_tcf.',
            '/real-cookie-banner/public/dist/blocker.',
            '/real-cookie-banner/public/dist/blocker_tcf.',
            '/real-cookie-banner/public/dist/vendor-banner.',
            '/real-cookie-banner/public/dist/vendor-banner_tcf.',
            '/real-cookie-banner/public/dist/vendor-blocker.',
            '/real-cookie-banner/public/dist/vendor-blocker_tcf.',
        );
    }

    /** Whether a registered/existing tag is RCB controller/blocker infrastructure. */
    private function ultracache_is_real_cookie_banner_infrastructure_script($handle, $src = '', $tag = '')
    {
        $handle = strtolower(trim((string) $handle));
        if ('iabtcf-stub' === $handle) {
            return true;
        }

        if (preg_match('/^real-cookie-banner(?:-pro)?-(?:banner(?:_tcf)?|blocker(?:_tcf)?|vendor-real-cookie-banner(?:-pro)?-(?:banner(?:_tcf)?|blocker(?:_tcf)?))$/', $handle)) {
            return true;
        }

        $haystack = strtolower((string) $src . ' ' . (string) $tag);
        foreach ($this->ultracache_real_cookie_banner_runtime_infrastructure_patterns() as $pattern) {
            if (false !== strpos($haystack, strtolower((string) $pattern))) {
                return true;
            }
        }
        return false;
    }
}
