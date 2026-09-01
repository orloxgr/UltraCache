<?php
/**
 * Complianz JavaScript infrastructure identification.
 *
 * Only Complianz controller/TCF/router infrastructure is protected. Service
 * scripts released after consent continue through the unified runtime
 * NATIVE / DEFER / DELAY classifier.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Complianz_Compatibility_Trait
{
    /** Runtime provenance fragments that identify Complianz-owned infrastructure. */
    private function ultracache_complianz_runtime_infrastructure_patterns()
    {
        return array(
            'cmplz-cookiebanner-js',
            'cmplz-tcf-stub-js',
            'cmplz-tcf-js',
            'cmplz-postscribe-js',
            'cmplz-router-js-module',
            '/complianz-gdpr-premium/cookiebanner/js/complianz.',
            '/complianz-gdpr/cookiebanner/js/complianz.',
            '/complianz-gdpr-premium/cookiebanner/js/complianz-router.',
            '/complianz-gdpr/cookiebanner/js/complianz-router.',
            '/complianz-gdpr-premium/pro/tcf-stub/build/index.js',
            '/complianz-gdpr/pro/tcf-stub/build/index.js',
            '/complianz-gdpr-premium/pro/tcf/build/index.js',
            '/complianz-gdpr/pro/tcf/build/index.js',
            '/complianz-gdpr-premium/assets/js/postscribe.min.js',
            '/complianz-gdpr/assets/js/postscribe.min.js',
        );
    }

    /** Whether a registered/existing tag is Complianz controller/TCF/router infrastructure. */
    private function ultracache_is_complianz_infrastructure_script($handle, $src = '', $tag = '')
    {
        $handle = strtolower(trim((string) $handle));
        if (in_array($handle, array('cmplz-cookiebanner', 'cmplz-tcf-stub', 'cmplz-tcf', 'cmplz-postscribe', 'cmplz-router'), true)) {
            return true;
        }

        $haystack = strtolower((string) $src . ' ' . (string) $tag);
        foreach ($this->ultracache_complianz_runtime_infrastructure_patterns() as $pattern) {
            if (false !== strpos($haystack, strtolower((string) $pattern))) {
                return true;
            }
        }
        return false;
    }
}
