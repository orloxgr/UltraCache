<?php
/**
 * Bounded Varnish performance measurements for UltraCache.
 *
 * The snapshot is diagnostic only. It does not purge objects or change runtime
 * settings; sampled pages may be warmed by the two anonymous request passes.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Performance_Trait
{
    private static function get_varnish_performance_snapshot_state_name()
    {
        return 'ultracache_state:varnish.performance_snapshot';
    }

    /**
     * Persist one bounded performance snapshot in the authoritative state table.
     *
     * @param array $snapshot Snapshot payload.
     * @return bool
     */
    private static function persist_varnish_performance_snapshot(array $snapshot)
    {
        if (!function_exists('ultracache_mutate_state_record')) {
            return false;
        }

        $snapshot = self::sanitize_varnish_result($snapshot);
        $mutation = ultracache_mutate_state_record(
            self::get_varnish_performance_snapshot_state_name(),
            static function () use ($snapshot) {
                return array(
                    'schemaVersion' => 1,
                    'recordedAt' => max(0, (int) ($snapshot['testedAt'] ?? time())),
                    'snapshot' => $snapshot,
                );
            },
            5,
            array()
        );

        return !empty($mutation['success']);
    }

    /**
     * Read the latest durable performance snapshot.
     *
     * @return array<string,mixed>
     */
    private static function read_varnish_performance_snapshot()
    {
        if (!function_exists('ultracache_get_state_record_read_only')) {
            return array();
        }

        $record = ultracache_get_state_record_read_only(self::get_varnish_performance_snapshot_state_name());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : array();
        return is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : array();
    }

    private static function get_varnish_performance_snapshot_fingerprint(array $buckets)
    {
        $dashboard_settings = self::get_dashboard_settings();
        $cli_settings = self::get_varnish_cli_settings();
        $registry = method_exists(static::class, 'get_varnish_endpoint_capability_registry_status')
            ? self::get_varnish_endpoint_capability_registry_status($cli_settings)
            : array();
        $endpoint_contracts = array();
        foreach ((array) ($registry['endpoints'] ?? array()) as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $capabilities = array_values(array_unique(array_map('strval', (array) ($endpoint['contractCapabilities'] ?? array()))));
            sort($capabilities, SORT_STRING);
            $current = is_array($endpoint['currentCapabilities'] ?? null)
                ? array_map('boolval', $endpoint['currentCapabilities'])
                : array();
            ksort($current, SORT_STRING);
            $endpoint_contracts[] = array(
                'mode' => sanitize_key((string) ($endpoint['mode'] ?? 'http')),
                'endpoint' => self::normalize_varnish_registry_endpoint($endpoint['endpoint'] ?? ''),
                'adapter' => sanitize_key((string) ($endpoint['adapter'] ?? '')),
                'contractVersion' => absint($endpoint['contractVersion'] ?? 0),
                'contractAuthenticated' => !empty($endpoint['contractAuthenticated']),
                'contractId' => sanitize_key((string) ($endpoint['contractId'] ?? '')),
                'contractCapabilities' => $capabilities,
                'currentCapabilities' => $current,
            );
        }
        usort($endpoint_contracts, static function ($left, $right) {
            return strcmp(
                (string) ($left['mode'] ?? '') . '|' . (string) ($left['endpoint'] ?? ''),
                (string) ($right['mode'] ?? '') . '|' . (string) ($right['endpoint'] ?? '')
            );
        });

        $payload = array(
            'schema' => 2,
            'siteOrigin' => function_exists('ultracache_get_configured_site_origin') ? ultracache_get_configured_site_origin() : '',
            'runtimeEnabled' => self::is_varnish_runtime_enabled($dashboard_settings),
            'buckets' => array_values($buckets),
            'transportFingerprint' => method_exists(static::class, 'get_varnish_capability_contract_fingerprint')
                ? self::get_varnish_capability_contract_fingerprint('transport', $cli_settings, $dashboard_settings)
                : '',
            'variantFingerprint' => method_exists(static::class, 'get_varnish_capability_contract_fingerprint')
                ? self::get_varnish_capability_contract_fingerprint('variant', $cli_settings, $dashboard_settings)
                : '',
            'endpointContracts' => $endpoint_contracts,
            'publicPath' => array(
                'publicEsi' => !empty($registry['effective']['publicEsi']),
                'privateEsi' => !empty($registry['effective']['privateEsi']),
                'htmlVariants' => !empty($registry['effective']['htmlVariants']),
            ),
        );

        return substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
    }

    /**
     * Select a very small local URL sample without scanning the full site.
     *
     * @param int $limit Maximum URLs.
     * @return array<int,string>
     */
    private static function get_varnish_performance_sample_urls($limit = 3)
    {
        $limit = max(1, min(5, absint($limit)));
        $urls = array();
        $home = esc_url_raw(home_url('/'));
        if ('' !== $home) {
            $urls[$home] = $home;
        }

        try {
            $engine = method_exists(static::class, 'get_engine_instance') ? self::get_engine_instance() : null;
            if ($engine && method_exists($engine, 'get_crawl_urls')) {
                foreach ((array) $engine->get_crawl_urls('menu') as $candidate) {
                    $candidate = esc_url_raw((string) $candidate);
                    if ('' === $candidate) {
                        continue;
                    }
                    if (function_exists('ultracache_is_strict_frontend_loopback_url') && !ultracache_is_strict_frontend_loopback_url($candidate)) {
                        continue;
                    }
                    $urls[$candidate] = $candidate;
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }
        } catch (Throwable $error) {
            unset($error);
        }

        return array_slice(array_values($urls), 0, $limit);
    }

    /**
     * Send one anonymous public cache measurement request.
     *
     * @param string $url    Local public URL.
     * @param string $bucket HTML variant bucket.
     * @return array<string,mixed>
     */
    private static function send_varnish_performance_probe_request($url, $bucket)
    {
        $url = esc_url_raw((string) $url);
        $bucket = in_array((string) $bucket, array('orig', 'webp', 'avif'), true) ? (string) $bucket : 'orig';
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)) {
            return array(
                'success' => false,
                'httpCode' => 0,
                'status' => 'ERROR',
                'durationMs' => 0,
                'evidence' => 'invalid-local-url',
                'variant' => '',
                'esiParent' => false,
                'esiFragmentCount' => 0,
            );
        }

        $accept = function_exists('ultracache_get_accept_header_for_html_bucket')
            ? ultracache_get_accept_header_for_html_bucket($bucket)
            : 'text/html,application/xhtml+xml';
        $request_timeout = (int) apply_filters('ultracache_varnish_performance_request_timeout', 5);
        $request_timeout = max(2, min(10, $request_timeout));
        $args = array(
            'method' => 'GET',
            'timeout' => $request_timeout,
            'redirection' => 0,
            'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-Varnish-Performance/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; +https://wordpress.org)',
            'headers' => array(
                'Accept' => $accept,
                'PageSpeed' => 'off',
                'ModPagespeed' => 'off',
            ),
            'sslverify' => !function_exists('ultracache_is_local_https_url') || !ultracache_is_local_https_url($url),
        );

        $started = microtime(true);
        $response = function_exists('ultracache_safe_loopback_remote_request')
            ? ultracache_safe_loopback_remote_request($url, $args, 'varnish_performance_snapshot')
            : wp_safe_remote_get($url, $args);
        $duration_ms = max(0, min(600000, (int) round((microtime(true) - $started) * 1000)));
        $summary = self::summarize_varnish_refill_response($response);
        $status = strtoupper((string) ($summary['status'] ?? 'INCONCLUSIVE'));
        $headers = is_array($summary['headers'] ?? null) ? $summary['headers'] : array();
        $x_cache = strtoupper(trim((string) ($headers['xCache'] ?? '')));

        if ('INCONCLUSIVE' === $status) {
            if (preg_match('/\b(STALE|GRACE|UPDATING)\b/', $x_cache)) {
                $status = 'STALE';
                $summary['evidence'] = 'visible-x-cache';
            } elseif (preg_match('/\bHIT\b/', $x_cache)) {
                $status = 'HIT';
                $summary['evidence'] = 'visible-x-cache';
            } elseif (preg_match('/\bMISS\b/', $x_cache)) {
                $status = 'MISS';
                $summary['evidence'] = 'visible-x-cache';
            } elseif (preg_match('/\b(PASS|BYPASS)\b/', $x_cache)) {
                $status = 'BYPASS';
                $summary['evidence'] = 'visible-x-cache';
            }
        }

        return array(
            'success' => !empty($summary['success']),
            'httpCode' => absint($summary['httpCode'] ?? 0),
            'status' => $status,
            'durationMs' => $duration_ms,
            'evidence' => sanitize_key((string) ($summary['evidence'] ?? 'none')),
            'variant' => sanitize_key((string) ($headers['ultraCacheVariant'] ?? '')),
            'esiParent' => !empty($summary['esiParent']),
            'esiFragmentCount' => max(0, (int) ($summary['esiFragmentCount'] ?? 0)),
            'esiUniqueFragmentCount' => max(0, (int) ($summary['esiUniqueFragmentCount'] ?? 0)),
        );
    }

    private static function get_varnish_performance_snapshot_recommendations(array $snapshot, array $metrics)
    {
        $recommendations = array();
        if (!empty($snapshot['budgetExhausted'])) {
            $recommendations[] = self::maybe_translate('The bounded measurement reached its time budget before every URL/variant pair completed. Review origin latency or retry during lower load before drawing tuning conclusions.');
        }
        if (empty($snapshot['signalsVisible'])) {
            $recommendations[] = self::maybe_translate('Public cache-status signals are hidden, so the snapshot cannot calculate a parent HIT rate. No automatic tuning was applied.');
        } elseif ((float) ($snapshot['hitRatePercent'] ?? 0) < 70.0) {
            $recommendations[] = self::maybe_translate('Repeated anonymous requests produced a low parent HIT rate. Review cache bypass headers, cookies, Vary values, and object retention before changing ESI or lifetime settings.');
        }

        $status_counts = is_array($snapshot['statusCounts'] ?? null) ? $snapshot['statusCounts'] : array();
        if (absint($status_counts['BYPASS'] ?? 0) > 0) {
            $recommendations[] = self::maybe_translate_sprintf('%d sampled URL/variant request(s) bypassed shared caching. Review cookies, query strings, authorization, and response cache-control before tuning object lifetime.', absint($status_counts['BYPASS']));
        }

        $expected_variants = absint($snapshot['expectedVariantCount'] ?? 0);
        $observed_variants = absint($snapshot['observedVariantCount'] ?? 0);
        if ($expected_variants > 1 && $observed_variants > 0 && $observed_variants < $expected_variants) {
            $recommendations[] = self::maybe_translate('Not every active HTML variant was observed in the sampled responses. Verify the origin variant header and Varnish Accept bucketing before increasing warm-up work.');
        }

        $esi = is_array($metrics['esi']['last24Hours'] ?? null) ? $metrics['esi']['last24Hours'] : array();
        $woo = is_array($esi['woocommerceMiniCart'] ?? null) ? $esi['woocommerceMiniCart'] : array();
        if (absint($woo['estimatedRequests'] ?? 0) >= 20 && (float) ($woo['averageRenderMs'] ?? 0) >= 100.0) {
            $recommendations[] = self::maybe_translate('The sampled WooCommerce mini-cart fragment has meaningful traffic and an average render cost above 100 ms. Measure a replacement runtime before changing cart-fragments or the first add-to-cart flow.');
        }
        if (absint($woo['containedErrors'] ?? 0) > 0) {
            $recommendations[] = self::maybe_translate('WooCommerce mini-cart ESI contained renderer errors during the last 24 hours. Resolve correctness errors before performance tuning.');
        }

        $outcomes = is_array($metrics['runtimeOutcomes'] ?? null) ? $metrics['runtimeOutcomes'] : array();
        if (absint($outcomes['failed'] ?? 0) > 0 || absint($outcomes['partial'] ?? 0) > 0) {
            $recommendations[] = self::maybe_translate('Recorded Varnish operations include failed or partial outcomes. Stabilize invalidation health before tuning grace, keep, or refresh-ahead thresholds.');
        }

        if (empty($recommendations)) {
            $recommendations[] = self::maybe_translate('The bounded sample does not justify an automatic ESI, cart-fragments, grace, or keep change. Continue collecting measurements before tuning behavior.');
        }

        return array_slice(array_values(array_unique($recommendations)), 0, 6);
    }

    /**
     * Run a bounded two-pass parent-cache performance snapshot.
     *
     * @return array<string,mixed>
     */
    public static function run_varnish_performance_snapshot()
    {
        if (method_exists(static::class, 'refresh_reverse_proxy_status')) {
            self::refresh_reverse_proxy_status();
        }

        $settings = self::get_dashboard_settings();
        if (!self::is_varnish_runtime_enabled($settings)) {
            return array(
                'success' => false,
                'status' => 'shared-cache-disabled',
                'message' => self::maybe_translate('Enable shared-cache delivery before measuring Varnish parent-cache performance.'),
            );
        }

        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('orig', 'webp', 'avif'), (array) ($policy['buckets'] ?? array('orig'))));
        if (empty($buckets)) {
            $buckets = array('orig');
        }

        $max_urls = (int) apply_filters('ultracache_varnish_performance_sample_url_limit', 3);
        $urls = self::get_varnish_performance_sample_urls(max(1, min(5, $max_urls)));
        if (empty($urls)) {
            return array(
                'success' => false,
                'status' => 'no-local-urls',
                'message' => self::maybe_translate('No trusted local URL was available for the Varnish performance snapshot.'),
            );
        }

        $time_budget = (int) apply_filters('ultracache_varnish_performance_time_budget', 45);
        $time_budget = max(15, min(90, $time_budget));
        $measurement_started = microtime(true);
        $budget_exhausted = false;

        $status_counts = array(
            'HIT' => 0,
            'MISS' => 0,
            'STALE' => 0,
            'BYPASS' => 0,
            'INCONCLUSIVE' => 0,
            'ERROR' => 0,
        );
        $details = array();
        $observed_variants = array();
        $first_duration_total = 0;
        $second_duration_total = 0;
        $second_successes = 0;
        $esi_parent_count = 0;
        $esi_fragment_references = 0;

        foreach ($urls as $url) {
            foreach ($buckets as $bucket) {
                if ((microtime(true) - $measurement_started) >= $time_budget) {
                    $budget_exhausted = true;
                    break 2;
                }
                $first = self::send_varnish_performance_probe_request($url, $bucket);
                $second = self::send_varnish_performance_probe_request($url, $bucket);
                $first_duration_total += absint($first['durationMs'] ?? 0);
                $second_duration_total += absint($second['durationMs'] ?? 0);
                if (!empty($second['success'])) {
                    ++$second_successes;
                }

                $status = strtoupper((string) ($second['status'] ?? 'INCONCLUSIVE'));
                if (!isset($status_counts[$status])) {
                    $status = 'INCONCLUSIVE';
                }
                ++$status_counts[$status];

                $variant = sanitize_key((string) ($second['variant'] ?? ''));
                if (in_array($variant, array('orig', 'webp', 'avif'), true)) {
                    $observed_variants[$variant] = true;
                }
                if (!empty($second['esiParent'])) {
                    ++$esi_parent_count;
                    $esi_fragment_references += max(0, (int) ($second['esiFragmentCount'] ?? 0));
                }

                $parts = wp_parse_url($url);
                $path = is_array($parts) && !empty($parts['path']) ? (string) $parts['path'] : '/';
                if (is_array($parts) && !empty($parts['query'])) {
                    $path .= '?' . (string) $parts['query'];
                }
                $details[] = array(
                    'path' => substr(sanitize_text_field($path), 0, 240),
                    'bucket' => $bucket,
                    'firstStatus' => sanitize_key(strtolower((string) ($first['status'] ?? 'inconclusive'))),
                    'secondStatus' => sanitize_key(strtolower($status)),
                    'firstDurationMs' => absint($first['durationMs'] ?? 0),
                    'secondDurationMs' => absint($second['durationMs'] ?? 0),
                    'evidence' => sanitize_key((string) ($second['evidence'] ?? 'none')),
                    'variant' => $variant,
                    'esiParent' => !empty($second['esiParent']),
                    'esiFragmentCount' => max(0, (int) ($second['esiFragmentCount'] ?? 0)),
                );
            }
        }

        $cache_denominator = $status_counts['HIT'] + $status_counts['MISS'] + $status_counts['STALE'];
        $signals_visible = $cache_denominator > 0 || $status_counts['BYPASS'] > 0;
        $hit_rate = $cache_denominator > 0 ? round(($status_counts['HIT'] / $cache_denominator) * 100, 1) : 0.0;
        $cache_served_rate = $cache_denominator > 0 ? round((($status_counts['HIT'] + $status_counts['STALE']) / $cache_denominator) * 100, 1) : 0.0;
        $visible_count = $cache_denominator + $status_counts['BYPASS'];
        $bypass_rate = $visible_count > 0 ? round(($status_counts['BYPASS'] / $visible_count) * 100, 1) : 0.0;
        $sample_count = count($details);
        $status = !$signals_visible
            ? 'signals-hidden'
            : ($hit_rate >= 80.0 ? 'healthy' : ($hit_rate >= 50.0 ? 'mixed' : 'low-hit-rate'));

        $snapshot = array(
            'success' => $second_successes > 0,
            'status' => $second_successes > 0 ? $status : 'request-failed',
            'testedAt' => time(),
            'fingerprint' => self::get_varnish_performance_snapshot_fingerprint($buckets),
            'urlCount' => count($urls),
            'expectedVariantCount' => count($buckets),
            'expectedVariants' => $buckets,
            'observedVariantCount' => count($observed_variants),
            'observedVariants' => array_keys($observed_variants),
            'sampleCount' => $sample_count,
            'plannedSampleCount' => count($urls) * count($buckets),
            'successfulSecondPassCount' => $second_successes,
            'timeBudgetSeconds' => $time_budget,
            'budgetExhausted' => $budget_exhausted,
            'signalsVisible' => $signals_visible,
            'hitRatePercent' => $hit_rate,
            'cacheServedRatePercent' => $cache_served_rate,
            'bypassRatePercent' => $bypass_rate,
            'statusCounts' => $status_counts,
            'averageFirstPassMs' => $sample_count > 0 ? round($first_duration_total / $sample_count, 1) : 0.0,
            'averageSecondPassMs' => $sample_count > 0 ? round($second_duration_total / $sample_count, 1) : 0.0,
            'esiParentSampleCount' => $esi_parent_count,
            'esiFragmentReferenceCount' => $esi_fragment_references,
            'details' => array_slice($details, 0, 15),
            'detailsTruncated' => count($details) > 15,
            'message' => $budget_exhausted
                ? self::maybe_translate_sprintf('Varnish performance snapshot reached its time budget after %1$d of %2$d planned URL/variant sample(s).', $sample_count, count($urls) * count($buckets))
                : (!$signals_visible
                    ? self::maybe_translate('The bounded requests completed, but public cache-status signals were hidden. No parent HIT rate was inferred.')
                    : self::maybe_translate_sprintf('Varnish performance snapshot completed: %1$s%% second-pass HIT rate across %2$d URL/variant sample(s).', number_format_i18n($hit_rate, 1), $sample_count)),
        );
        $snapshot['recommendations'] = self::get_varnish_performance_snapshot_recommendations(
            $snapshot,
            self::get_varnish_metrics_status()
        );

        self::persist_varnish_performance_snapshot($snapshot);

        return $snapshot;
    }

    public static function get_varnish_performance_snapshot_status()
    {
        $value = self::read_varnish_performance_snapshot();
        if (empty($value)) {
            return array(
                'tested' => false,
                'status' => 'not-measured',
                'message' => self::maybe_translate('No bounded Varnish performance snapshot has been run yet.'),
                'recommendations' => array(),
            );
        }

        $settings = self::get_dashboard_settings();
        $policy = function_exists('ultracache_get_html_variant_policy')
            ? ultracache_get_html_variant_policy($settings)
            : array('buckets' => array('orig'));
        $buckets = array_values(array_intersect(array('orig', 'webp', 'avif'), (array) ($policy['buckets'] ?? array('orig'))));
        if (empty($buckets)) {
            $buckets = array('orig');
        }
        $current_fingerprint = self::get_varnish_performance_snapshot_fingerprint($buckets);
        $configuration_changed = !hash_equals((string) ($value['fingerprint'] ?? ''), $current_fingerprint);
        $tested_at = max(0, (int) ($value['testedAt'] ?? 0));
        $age = $tested_at > 0 ? max(0, time() - $tested_at) : 0;
        $stale = !$configuration_changed && $tested_at > 0 && $age > WEEK_IN_SECONDS;
        $value['tested'] = true;
        $value['ageSeconds'] = $age;
        $value['configurationChanged'] = $configuration_changed;
        $value['stale'] = $stale;
        $value['measuredStatus'] = sanitize_key((string) ($value['status'] ?? ''));
        if ($configuration_changed) {
            $value['status'] = 'configuration-changed';
            $value['message'] = self::maybe_translate('The Varnish transport, endpoint/VCL capability contract, or HTML variant policy changed. Run the performance snapshot again.');
        } elseif ($stale) {
            $value['status'] = 'stale';
            $value['message'] = self::maybe_translate('The Varnish performance snapshot is older than seven days. Run the measurement again to refresh it.');
        }

        return self::sanitize_varnish_result($value);
    }
}
