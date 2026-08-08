<?php
/**
 * Native LiteSpeed behavior diagnostics.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Diagnostics_Trait
{
    /** @var int */
    private static $litespeed_test_run_depth = 0;

    private static function get_litespeed_behavior_test_option_name()
    {
        return 'ultracache_litespeed_diagnostic_behavior_v1';
    }

    private static function begin_litespeed_test_run()
    {
        self::$litespeed_test_run_depth = min(10, max(0, (int) self::$litespeed_test_run_depth) + 1);
    }

    private static function end_litespeed_test_run()
    {
        self::$litespeed_test_run_depth = max(0, (int) self::$litespeed_test_run_depth - 1);
    }

    private static function is_litespeed_test_run_active()
    {
        return (int) self::$litespeed_test_run_depth > 0;
    }

    private static function sanitize_litespeed_behavior_step($step)
    {
        if (!is_array($step)) {
            return array();
        }

        $headers = is_array($step['headers'] ?? null) ? $step['headers'] : array();
        $safe_headers = array();
        foreach (array('server', 'xLiteSpeedCache', 'xQcCache', 'xLiteSpeedVary', 'ultraCache', 'ultraCacheSource', 'ultraCacheVariant') as $key) {
            $value = sanitize_text_field((string) ($headers[$key] ?? ''));
            $safe_headers[$key] = strlen($value) > 190 ? substr($value, 0, 190) : $value;
        }

        $status = strtoupper(sanitize_text_field((string) ($step['cacheStatus'] ?? 'INCONCLUSIVE')));
        if (!in_array($status, array('HIT', 'MISS', 'BYPASS', 'INCONCLUSIVE', 'ERROR', 'REDIRECT'), true)) {
            $status = 'INCONCLUSIVE';
        }

        return array(
            'success' => !empty($step['success']),
            'httpCode' => max(0, min(599, (int) ($step['httpCode'] ?? 0))),
            'cacheStatus' => $status,
            'durationMs' => max(0, min(600000, (int) ($step['durationMs'] ?? 0))),
            'signalSource' => substr(sanitize_key((string) ($step['signalSource'] ?? '')), 0, 40),
            'detail' => substr(sanitize_text_field((string) ($step['detail'] ?? '')), 0, 240),
            'headers' => $safe_headers,
        );
    }

    private static function sanitize_litespeed_behavior_result($result)
    {
        if (!is_array($result)) {
            return array();
        }

        $status = sanitize_key((string) ($result['status'] ?? 'not-run'));
        if (!in_array($status, array('pass', 'fail', 'inconclusive', 'configuration-incomplete', 'not-run'), true)) {
            $status = 'not-run';
        }

        $buckets = array();
        foreach (array_slice((array) ($result['buckets'] ?? array()), 0, 3) as $bucket_result) {
            if (!is_array($bucket_result)) {
                continue;
            }
            $bucket = sanitize_key((string) ($bucket_result['bucket'] ?? ''));
            if (!in_array($bucket, array('orig', 'webp', 'avif'), true)) {
                continue;
            }
            $bucket_status = sanitize_key((string) ($bucket_result['status'] ?? 'inconclusive'));
            if (!in_array($bucket_status, array('pass', 'fail', 'inconclusive'), true)) {
                $bucket_status = 'inconclusive';
            }

            $steps = array();
            foreach (array('beforeFirst', 'beforeSecond', 'afterPurgeFirst', 'afterPurgeSecond') as $step_key) {
                $steps[$step_key] = self::sanitize_litespeed_behavior_step($bucket_result['steps'][$step_key] ?? array());
            }

            $purge = is_array($bucket_result['purge'] ?? null) ? $bucket_result['purge'] : array();
            $buckets[] = array(
                'bucket' => $bucket,
                'status' => $bucket_status,
                'verified' => !empty($bucket_result['verified']),
                'message' => substr(sanitize_text_field((string) ($bucket_result['message'] ?? '')), 0, 300),
                'purge' => array(
                    'success' => !empty($purge['success']),
                    'httpStatus' => max(0, min(599, (int) ($purge['httpStatus'] ?? 0))),
                    'processedCount' => max(0, (int) ($purge['processedCount'] ?? 0)),
                    'message' => substr(sanitize_text_field((string) ($purge['message'] ?? '')), 0, 240),
                ),
                'steps' => $steps,
            );
        }

        $url = esc_url_raw((string) ($result['url'] ?? ''));

        return array(
            'success' => !empty($result['success']),
            'verified' => !empty($result['verified']),
            'status' => $status,
            'message' => substr(sanitize_text_field((string) ($result['message'] ?? '')), 0, 400),
            'time' => max(0, (int) ($result['time'] ?? 0)),
            'timeHuman' => !empty($result['time']) ? gmdate('Y-m-d H:i:s', (int) $result['time']) . ' UTC' : '',
            'url' => $url,
            'bucketCount' => count($buckets),
            'verifiedBucketCount' => max(0, (int) ($result['verifiedBucketCount'] ?? 0)),
            'inconclusiveBucketCount' => max(0, (int) ($result['inconclusiveBucketCount'] ?? 0)),
            'failedBucketCount' => max(0, (int) ($result['failedBucketCount'] ?? 0)),
            'buckets' => $buckets,
        );
    }

    private static function store_litespeed_behavior_test_result(array $result)
    {
        $result = self::sanitize_litespeed_behavior_result($result);
        update_option(self::get_litespeed_behavior_test_option_name(), $result, false);
        return $result;
    }

    public static function get_litespeed_behavior_test_result()
    {
        $stored = get_option(self::get_litespeed_behavior_test_option_name(), array());
        if (!is_array($stored) || empty($stored)) {
            $stored = array(
                'success' => true,
                'verified' => false,
                'status' => 'not-run',
                'message' => '',
                'time' => 0,
                'url' => '',
                'buckets' => array(),
            );
        }

        return self::sanitize_litespeed_behavior_result($stored);
    }

    private static function get_litespeed_behavior_signal_source(array $summary)
    {
        $headers = is_array($summary['headers'] ?? null) ? $summary['headers'] : array();
        if ('' !== trim((string) ($headers['xLiteSpeedCache'] ?? ''))) {
            return 'x-litespeed-cache';
        }
        if ('' !== trim((string) ($headers['xQcCache'] ?? ''))) {
            return 'x-qc-cache';
        }
        return '';
    }

    private static function run_litespeed_behavior_request($url, $bucket)
    {
        $started = microtime(true);
        $summary = self::summarize_litespeed_refill_response(
            self::send_single_litespeed_refill_request($url, $bucket)
        );
        $summary['durationMs'] = max(0, (int) round((microtime(true) - $started) * 1000));
        $summary['signalSource'] = self::get_litespeed_behavior_signal_source($summary);
        return self::sanitize_litespeed_behavior_step($summary);
    }

    private static function classify_litespeed_behavior_bucket($bucket, array $steps, array $purge)
    {
        foreach ($steps as $step) {
            if (empty($step['success'])) {
                return array(
                    'bucket' => $bucket,
                    'status' => 'fail',
                    'verified' => false,
                    'message' => __('A public LiteSpeed behavior-test request failed.', 'ultracache'),
                    'purge' => $purge,
                    'steps' => $steps,
                );
            }
        }

        if (empty($purge['success'])) {
            return array(
                'bucket' => $bucket,
                'status' => 'fail',
                'verified' => false,
                'message' => __('LiteSpeed exact URL invalidation failed during the behavior test.', 'ultracache'),
                'purge' => $purge,
                'steps' => $steps,
            );
        }

        $after_first = strtoupper((string) ($steps['afterPurgeFirst']['cacheStatus'] ?? 'INCONCLUSIVE'));
        $after_second = strtoupper((string) ($steps['afterPurgeSecond']['cacheStatus'] ?? 'INCONCLUSIVE'));
        $visible_sources = array_filter(array(
            (string) ($steps['beforeFirst']['signalSource'] ?? ''),
            (string) ($steps['beforeSecond']['signalSource'] ?? ''),
            (string) ($steps['afterPurgeFirst']['signalSource'] ?? ''),
            (string) ($steps['afterPurgeSecond']['signalSource'] ?? ''),
        ));

        if ('MISS' === $after_first && 'HIT' === $after_second) {
            return array(
                'bucket' => $bucket,
                'status' => 'pass',
                'verified' => true,
                'message' => __('Exact purge was followed by an observable LiteSpeed MISS and HIT.', 'ultracache'),
                'purge' => $purge,
                'steps' => $steps,
            );
        }

        if ('BYPASS' === $after_first || 'BYPASS' === $after_second) {
            return array(
                'bucket' => $bucket,
                'status' => 'fail',
                'verified' => false,
                'message' => __('LiteSpeed explicitly bypassed the tested public page.', 'ultracache'),
                'purge' => $purge,
                'steps' => $steps,
            );
        }

        if ('HIT' === $after_first) {
            return array(
                'bucket' => $bucket,
                'status' => 'fail',
                'verified' => false,
                'message' => __('The first request after exact purge remained a LiteSpeed HIT.', 'ultracache'),
                'purge' => $purge,
                'steps' => $steps,
            );
        }

        if (empty($visible_sources) || ('INCONCLUSIVE' === $after_first && 'INCONCLUSIVE' === $after_second)) {
            return array(
                'bucket' => $bucket,
                'status' => 'inconclusive',
                'verified' => false,
                'message' => __('Purge and public requests completed, but LiteSpeed HIT/MISS headers were hidden.', 'ultracache'),
                'purge' => $purge,
                'steps' => $steps,
            );
        }

        return array(
            'bucket' => $bucket,
            'status' => 'fail',
            'verified' => false,
            'message' => __('LiteSpeed headers were visible, but the post-purge MISS-to-HIT sequence was not completed.', 'ultracache'),
            'purge' => $purge,
            'steps' => $steps,
        );
    }

    public static function run_litespeed_behavior_test()
    {
        $tested_at = time();
        if (!self::is_native_litespeed_html_cache_enabled()) {
            return self::store_litespeed_behavior_test_result(array(
                'success' => false,
                'verified' => false,
                'status' => 'configuration-incomplete',
                'message' => __('Enable Page Cache and LiteSpeed HTML Cache before running the behavior test.', 'ultracache'),
                'time' => $tested_at,
                'url' => home_url('/'),
                'buckets' => array(),
            ));
        }

        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $transport = self::get_litespeed_transport_status($server_software, $reverse_proxy);
        if (empty($transport['serverDetected']) && empty($transport['nativeHeaderAvailable'])) {
            return self::store_litespeed_behavior_test_result(array(
                'success' => false,
                'verified' => false,
                'status' => 'configuration-incomplete',
                'message' => __('A LiteSpeed origin or active LiteSpeed cache response has not been confirmed.', 'ultracache'),
                'time' => $tested_at,
                'url' => home_url('/'),
                'buckets' => array(),
            ));
        }

        $url = self::normalize_litespeed_purge_url(home_url('/'));
        if ('' === $url || !function_exists('ultracache_is_strict_frontend_loopback_url') || !ultracache_is_strict_frontend_loopback_url($url)) {
            return self::store_litespeed_behavior_test_result(array(
                'success' => false,
                'verified' => false,
                'status' => 'configuration-incomplete',
                'message' => __('The canonical front-page URL is not eligible for the LiteSpeed behavior test.', 'ultracache'),
                'time' => $tested_at,
                'url' => $url,
                'buckets' => array(),
            ));
        }

        $bucket_results = array();
        $bucket_steps = array();
        self::begin_litespeed_test_run();
        try {
            foreach (self::get_litespeed_refill_buckets() as $bucket) {
                $bucket_steps[$bucket] = array(
                    'beforeFirst' => self::run_litespeed_behavior_request($url, $bucket),
                    'beforeSecond' => self::run_litespeed_behavior_request($url, $bucket),
                );
            }

            // Exact URL tags are shared by every image-format vary bucket. Purge
            // once after the preflight requests, then refill every bucket so the
            // diagnostic leaves the tested public page fully warm.
            $purge = self::purge_litespeed_urls(array($url), false, 'behavior-test-hard-invalidation');

            foreach ($bucket_steps as $bucket => $steps) {
                $steps['afterPurgeFirst'] = self::run_litespeed_behavior_request($url, $bucket);
                $steps['afterPurgeSecond'] = self::run_litespeed_behavior_request($url, $bucket);
                $bucket_results[] = self::classify_litespeed_behavior_bucket($bucket, $steps, $purge);
            }
        } finally {
            self::end_litespeed_test_run();
        }

        $verified_count = 0;
        $inconclusive_count = 0;
        $failed_count = 0;
        foreach ($bucket_results as $bucket_result) {
            if ('pass' === ($bucket_result['status'] ?? '')) {
                ++$verified_count;
            } elseif ('inconclusive' === ($bucket_result['status'] ?? '')) {
                ++$inconclusive_count;
            } else {
                ++$failed_count;
            }
        }

        if ($failed_count > 0) {
            $status = 'fail';
            $success = false;
            $message = __('LiteSpeed behavior test failed for one or more active HTML buckets.', 'ultracache');
        } elseif ($inconclusive_count > 0) {
            $status = 'inconclusive';
            $success = true;
            $message = __('LiteSpeed purge and public requests completed, but one or more HIT/MISS sequences could not be observed.', 'ultracache');
        } else {
            $status = 'pass';
            $success = true;
            $message = __('LiteSpeed behavior test passed for every active HTML bucket.', 'ultracache');
        }

        return self::store_litespeed_behavior_test_result(array(
            'success' => $success,
            'verified' => 'pass' === $status,
            'status' => $status,
            'message' => $message,
            'time' => $tested_at,
            'url' => $url,
            'verifiedBucketCount' => $verified_count,
            'inconclusiveBucketCount' => $inconclusive_count,
            'failedBucketCount' => $failed_count,
            'buckets' => $bucket_results,
        ));
    }

    public static function get_litespeed_diagnostics_status()
    {
        $server_software = isset($_SERVER['SERVER_SOFTWARE'])
            ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']))
            : '';
        $reverse_proxy = method_exists(__CLASS__, 'get_reverse_proxy_status') ? self::get_reverse_proxy_status() : array();
        $transport = self::get_litespeed_transport_status($server_software, $reverse_proxy);
        $settings = self::get_dashboard_settings();
        $rules_active = false;
        $rules_path = '';
        if (method_exists(__CLASS__, 'get_browser_cache_htaccess_path')) {
            $rules_path = self::get_browser_cache_htaccess_path();
            $contents = file_exists($rules_path)
                ? (string) ultracache_safe_file_get_contents($rules_path, 'litespeed_cache diagnostics')
                : '';
            $rules_active = false !== strpos($contents, '# BEGIN UltraCache LiteSpeed Cache')
                && false !== strpos($contents, '# END UltraCache LiteSpeed Cache');
        }

        $query_policy = method_exists(static::class, 'get_litespeed_query_cache_policy')
            ? self::get_litespeed_query_cache_policy()
            : array();
        $query_key_proof = method_exists(static::class, 'get_litespeed_query_cache_key_proof')
            ? self::get_litespeed_query_cache_key_proof()
            : array();

        return array_merge($transport, array(
            'nativeEnabled' => self::is_native_litespeed_html_cache_enabled(),
            'queryPolicy' => array(
                'version' => (int) ($query_policy['version'] ?? 1),
                'enabled' => !empty($query_policy['enabled']),
                'allowlist' => (array) ($query_policy['allowlist'] ?? array()),
                'hardBlockedKeys' => (array) ($query_policy['hard_blocked_keys'] ?? array()),
                'fingerprint' => (string) ($query_policy['fingerprint'] ?? ''),
                'safeQueryRetrievalEnabled' => !empty($query_key_proof['safe_query_retrieval_enabled']),
            ),
            'queryKeyProof' => array(
                'version' => (int) ($query_key_proof['version'] ?? 2),
                'status' => sanitize_key((string) ($query_key_proof['status'] ?? 'blocked')),
                'verified' => !empty($query_key_proof['verified']),
                'strategy' => sanitize_key((string) ($query_key_proof['strategy'] ?? 'none')),
                'fingerprint' => (string) ($query_key_proof['fingerprint'] ?? ''),
                'policyFingerprint' => (string) ($query_key_proof['policy_fingerprint'] ?? ''),
                'reason' => sanitize_key((string) ($query_key_proof['reason'] ?? '')),
                'missingCapabilities' => array_values((array) ($query_key_proof['missing_capabilities'] ?? array())),
                'safeQueryRetrievalEnabled' => !empty($query_key_proof['safe_query_retrieval_enabled']),
                'operationContract' => array(
                    'retrieval' => sanitize_key((string) ($query_key_proof['operation_contract']['retrieval'] ?? 'bypass')),
                    'storage' => sanitize_key((string) ($query_key_proof['operation_contract']['storage'] ?? 'no-cache')),
                    'exactPurge' => sanitize_key((string) ($query_key_proof['operation_contract']['exact_purge'] ?? 'skip')),
                    'publicRefill' => sanitize_key((string) ($query_key_proof['operation_contract']['public_refill'] ?? 'skip')),
                    'baseUrlAliasing' => !empty($query_key_proof['operation_contract']['base_url_aliasing']),
                ),
            ),
            'rulesActive' => $rules_active,
            'rulesPath' => $rules_path,
            'activeBuckets' => self::get_litespeed_refill_buckets(),
            'refillAfterTargetedInvalidation' => !empty($settings['liteSpeedRefillAfterTargetedInvalidation']),
            'warmWithSiteWarmup' => !empty($settings['liteSpeedWarmDuringSiteWarmup']),
            'stalePurgeEnabled' => !empty($settings['liteSpeedStalePurgeEnabled']),
            'refreshAhead' => method_exists(static::class, 'get_litespeed_refresh_ahead_status')
                ? self::get_litespeed_refresh_ahead_status($settings)
                : array(),
            'behaviorTest' => self::get_litespeed_behavior_test_result(),
            'metrics' => self::get_litespeed_metrics_status(),
            'testable' => self::is_native_litespeed_html_cache_enabled()
                && (!empty($transport['serverDetected']) || !empty($transport['nativeHeaderAvailable'])),
        ));
    }
}
