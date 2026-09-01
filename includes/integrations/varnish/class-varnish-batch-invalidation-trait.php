<?php
/**
 * Varnish URL normalization, deduplication and bounded batch invalidation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Batch_Invalidation_Trait
{
    /**
     * Maximum exact paths combined in one BAN expression.
     *
     * @return int
     */
    private static function get_varnish_ban_batch_path_limit()
    {
        return 20;
    }

    /**
     * Maximum byte length for one generated BAN expression.
     *
     * @return int
     */
    private static function get_varnish_ban_batch_expression_limit()
    {
        return 3000;
    }

    /**
     * Build one exact-path BAN expression for multiple paths on one host.
     *
     * @param string $host  Request host.
     * @param array  $paths Exact request paths, optionally including query strings.
     * @return string
     */
    private static function build_varnish_batched_ban_expression($host, array $paths)
    {
        $host = (string) $host;
        if ('' === $host || empty($paths)) {
            return '';
        }

        if (1 === count($paths)) {
            return self::build_varnish_ban_expression($host, (string) reset($paths), false);
        }

        $host = self::escape_varnish_vcl_string($host);
        $alternatives = array();
        foreach ($paths as $path) {
            $quoted = preg_quote((string) $path, '/');
            $alternatives[] = self::escape_varnish_vcl_string($quoted);
        }

        return 'req.http.host == "' . $host . '" && req.url ~ "^(?:' . implode('|', $alternatives) . ')$"';
    }

    /**
     * Split normalized URLs into bounded BAN batches grouped by host.
     *
     * @param array $prepared_urls Normalized URL records.
     * @return array
     */
    private static function build_varnish_ban_batches(array $prepared_urls)
    {
        $by_host = array();
        foreach ($prepared_urls as $item) {
            $host = (string) ($item['host'] ?? '');
            $path = (string) ($item['path'] ?? '');
            $url = (string) ($item['url'] ?? '');
            if ('' === $host || '' === $path || '' === $url) {
                continue;
            }
            if (!isset($by_host[$host])) {
                $by_host[$host] = array();
            }
            $by_host[$host][] = array(
                'url' => $url,
                'path' => $path,
            );
        }

        $batches = array();
        $path_limit = self::get_varnish_ban_batch_path_limit();
        $expression_limit = self::get_varnish_ban_batch_expression_limit();

        foreach ($by_host as $host => $items) {
            $current = array();
            foreach ($items as $item) {
                $candidate = array_merge($current, array($item));
                $candidate_paths = array_values(array_map(static function ($candidate_item) {
                    return (string) ($candidate_item['path'] ?? '');
                }, $candidate));
                $candidate_expression = self::build_varnish_batched_ban_expression($host, $candidate_paths);
                $would_exceed_count = count($candidate) > $path_limit;
                $would_exceed_length = strlen($candidate_expression) > $expression_limit;

                if (!empty($current) && ($would_exceed_count || $would_exceed_length)) {
                    $paths = array_values(array_map(static function ($current_item) {
                        return (string) ($current_item['path'] ?? '');
                    }, $current));
                    $urls = array_values(array_map(static function ($current_item) {
                        return (string) ($current_item['url'] ?? '');
                    }, $current));
                    $batches[] = array(
                        'host' => $host,
                        'paths' => $paths,
                        'urls' => $urls,
                        'expression' => self::build_varnish_batched_ban_expression($host, $paths),
                    );
                    $current = array($item);
                    continue;
                }

                $current = $candidate;
            }

            if (!empty($current)) {
                $paths = array_values(array_map(static function ($current_item) {
                    return (string) ($current_item['path'] ?? '');
                }, $current));
                $urls = array_values(array_map(static function ($current_item) {
                    return (string) ($current_item['url'] ?? '');
                }, $current));
                $batches[] = array(
                    'host' => $host,
                    'paths' => $paths,
                    'urls' => $urls,
                    'expression' => self::build_varnish_batched_ban_expression($host, $paths),
                );
            }
        }

        return $batches;
    }

    /**
     * Build one exact BAN operation per normalized URL.
     *
     * This is the production fallback when exact BAN is verified but the
     * configured endpoint set does not have current two-or-more URL batch proof.
     *
     * @param array $prepared_urls Normalized URL records.
     * @return array
     */
    private static function build_varnish_exact_ban_batches(array $prepared_urls)
    {
        $operations = array();
        foreach ($prepared_urls as $item) {
            $host = (string) ($item['host'] ?? '');
            $path = (string) ($item['path'] ?? '');
            $url = (string) ($item['url'] ?? '');
            if ('' === $host || '' === $path || '' === $url) {
                continue;
            }

            $expression = self::build_varnish_ban_expression($host, $path, false);
            if ('' === $expression || strlen($expression) > self::get_varnish_ban_batch_expression_limit()) {
                continue;
            }

            $operations[] = array(
                'host' => $host,
                'paths' => array($path),
                'urls' => array($url),
                'expression' => $expression,
            );
        }

        return $operations;
    }

    /**
     * Limit a retry target set to currently configured Varnish endpoints.
     *
     * @param array $configured_targets Configured endpoint labels.
     * @param array $requested_targets  Optional retry endpoint labels.
     * @return array
     */
    private static function resolve_varnish_invalidation_targets(array $configured_targets, array $requested_targets = array())
    {
        $configured = array();
        foreach ($configured_targets as $target) {
            $target = trim((string) $target);
            if ('' !== $target && strlen($target) <= 512) {
                $configured[$target] = true;
            }
        }

        if (empty($requested_targets)) {
            return array_keys($configured);
        }

        $resolved = array();
        foreach ($requested_targets as $target) {
            $target = trim((string) $target);
            if (isset($configured[$target])) {
                $resolved[$target] = true;
            }
        }

        return array_keys($resolved);
    }

    /**
     * Determine whether a failed endpoint request should be retried.
     *
     * @param array $response Transport response.
     * @return bool
     */
    private static function is_varnish_invalidation_response_retryable(array $response)
    {
        if (!empty($response['ok'])) {
            return false;
        }

        $code = max(0, (int) ($response['code'] ?? 0));
        if (in_array($code, array(408, 425, 429), true) || $code >= 500) {
            return true;
        }
        if ($code >= 400) {
            return false;
        }

        $detail = strtolower((string) ($response['detail'] ?? ''));
        $terminal_fragments = array(
            'invalid or blocked',
            'invalid or oversized',
            'admin auth failed',
            'secret is required',
            'secret exceeds',
            'missing challenge',
            'unexpected admin banner',
            'returned an html page',
        );
        foreach ($terminal_fragments as $fragment) {
            if (false !== strpos($detail, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Initialize per-URL invalidation accounting.
     *
     * @param array $prepared_urls Prepared URL records.
     * @return array
     */
    private static function initialize_varnish_url_results(array $prepared_urls)
    {
        $results = array();
        foreach ($prepared_urls as $item) {
            $url = (string) ($item['url'] ?? '');
            if ('' === $url) {
                continue;
            }
            $results[$url] = array(
                'url' => $url,
                'success' => false,
                'partial' => false,
                'retryable' => false,
                'requestCount' => 0,
                'successfulEndpointTargets' => array(),
                'failedEndpointTargets' => array(),
                'failedEndpointRetryability' => array(),
                'message' => '',
            );
        }
        return $results;
    }

    /**
     * Attach one endpoint result to every URL covered by the request.
     *
     * @param array  $url_results Per-URL result map.
     * @param array  $urls        Covered canonical URLs.
     * @param string $target      Endpoint label.
     * @param array  $response    Transport response.
     * @return void
     */
    private static function record_varnish_url_endpoint_result(array &$url_results, array $urls, $target, array $response)
    {
        $target = (string) $target;
        $success = !empty($response['ok']);
        $retryable = !$success && self::is_varnish_invalidation_response_retryable($response);
        $detail = self::sanitize_varnish_string((string) ($response['detail'] ?? ''));
        $code = max(0, (int) ($response['code'] ?? 0));

        foreach ($urls as $url) {
            $url = (string) $url;
            if (!isset($url_results[$url])) {
                continue;
            }
            ++$url_results[$url]['requestCount'];
            if ($success) {
                $url_results[$url]['successfulEndpointTargets'][$target] = true;
            } else {
                $url_results[$url]['failedEndpointTargets'][$target] = true;
                $url_results[$url]['failedEndpointRetryability'][$target] = $retryable;
            }
        }
    }

    /**
     * Finalize per-URL and aggregate invalidation accounting.
     *
     * @param array $url_results Per-URL result map.
     * @return array
     */
    private static function finalize_varnish_url_results(array $url_results)
    {
        $fully_invalidated = 0;
        $partially_invalidated = 0;
        $failed = 0;
        foreach ($url_results as $url => $url_result) {
            $successful_targets = array_keys((array) ($url_result['successfulEndpointTargets'] ?? array()));
            $failed_targets = array_keys((array) ($url_result['failedEndpointTargets'] ?? array()));
            $success = !empty($successful_targets) && empty($failed_targets);
            $partial = !empty($successful_targets) && !empty($failed_targets);

            if ($success) {
                ++$fully_invalidated;
                $message = self::maybe_translate('Varnish invalidation completed on every required endpoint.');
            } elseif ($partial) {
                ++$partially_invalidated;
                $message = self::maybe_translate_sprintf(
                    'Varnish invalidation completed on %1$d endpoint(s) and failed on %2$d endpoint(s).',
                    count($successful_targets),
                    count($failed_targets)
                );
            } else {
                ++$failed;
                $message = self::maybe_translate_sprintf(
                    'Varnish invalidation failed on %d required endpoint(s).',
                    count($failed_targets)
                );
            }

            $failed_retryability = (array) ($url_result['failedEndpointRetryability'] ?? array());
            $retryable = !empty($failed_targets);
            foreach ($failed_targets as $failed_target) {
                if (empty($failed_retryability[$failed_target])) {
                    $retryable = false;
                    break;
                }
            }

            $url_results[$url]['success'] = $success;
            $url_results[$url]['partial'] = $partial;
            $url_results[$url]['retryable'] = !$success && $retryable;
            $url_results[$url]['successfulEndpointTargets'] = $successful_targets;
            $url_results[$url]['failedEndpointTargets'] = $failed_targets;
            $url_results[$url]['message'] = $message;
            unset($url_results[$url]['failedEndpointRetryability']);
        }

        return array(
            'urlResults' => $url_results,
            'fullyInvalidatedUrlCount' => $fully_invalidated,
            'partiallyInvalidatedUrlCount' => $partially_invalidated,
            'failedUrlCount' => $failed,
        );
    }

    /**
     * Send a normalized invalidation set using bounded BAN batches or exact PURGE.
     *
     * @param array  $urls             Candidate URLs.
     * @param string $scope            Invalidation scope label.
     * @param string $strategy_override Optional invalidation strategy override.
     * @param array  $endpoint_targets Optional configured endpoint labels to retry.
     * @param array  $settings_snapshot Optional immutable settings snapshot for bounded queue dispatch.
     * @return array
     */
    private static function varnish_flush_url_batch(array $urls, $scope = 'batch', $strategy_override = '', array $endpoint_targets = array(), array $settings_snapshot = array())
    {
        $settings = !empty($settings_snapshot) ? $settings_snapshot : self::get_varnish_cli_settings();
        $mode = (string) ($settings['mode'] ?? 'http');
        $method = (string) ($settings['method'] ?? 'BAN');
        $requested_strategy_override = sanitize_key((string) $strategy_override);
        $probe_urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        $probe_endpoints = !empty($endpoint_targets)
            ? array_values(array_unique(array_filter(array_map('strval', $endpoint_targets))))
            : array_values(array_unique(array_filter(array_map('strval', (array) ($settings['servers'] ?? array())))));
        $probe_scope = count($probe_urls) > 1 ? 'batch' : 'exact-url';
        $soft_probe_authorized = 'soft' === $requested_strategy_override
            && method_exists(static::class, 'is_varnish_capability_probe_authorized')
            && self::is_varnish_capability_probe_authorized('targeted', array(
                'strategy' => 'soft-purge',
                'requestedScope' => $probe_scope,
                'endpoints' => $probe_endpoints,
                'urls' => $probe_urls,
            ));
        $effective_strategy_override = 'soft' === $requested_strategy_override && !$soft_probe_authorized
            ? ''
            : $requested_strategy_override;
        $strategy_status = self::get_varnish_invalidation_strategy_status($settings, $effective_strategy_override);
        $effective_strategy = (string) ($strategy_status['effective'] ?? 'ban');
        $method = 'purge' === $effective_strategy || 'soft' === $effective_strategy ? 'PURGE' : 'BAN';
        $effective_method = 'soft' === $effective_strategy ? 'soft PURGE' : (('admin' === $mode) ? 'admin BAN' : $method);
        if ('soft' === $effective_strategy) {
            $probe_strategy = 'soft-purge';
        } elseif ('admin' === $mode || 'BAN' === $method) {
            $probe_strategy = count($probe_urls) > 1 ? 'batch-ban' : 'exact-ban';
        } else {
            $probe_strategy = 'exact-purge';
        }
        $runtime_plan = self::plan_varnish_runtime_operation('targeted', array(
            'strategyOverride' => $effective_strategy_override,
            'probeStrategy' => $probe_strategy,
            'probeScope' => $probe_scope,
            'probeEndpoints' => $probe_endpoints,
            'probeUrls' => $probe_urls,
        ));
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();

        if (empty($support['available'])) {
            $result = array(
                'success' => false,
                'message' => (string) ($support['message'] ?? self::maybe_translate('Varnish integration is unavailable.')),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
            );
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
            self::set_varnish_last_result($result);
            return $result;
        }

        if (empty($settings['enabled'])) {
            $result = array(
                'success' => false,
                'message' => self::maybe_translate('Varnish integration is disabled.'),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
            );
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
            self::set_varnish_last_result($result);
            return $result;
        }

        if (empty($settings['servers'])) {
            $result = array(
                'success' => false,
                'message' => self::maybe_translate('No Varnish endpoints are configured.'),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
            );
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
            self::set_varnish_last_result($result);
            return $result;
        }

        $prepared = self::prepare_varnish_invalidation_urls($urls);
        if (empty($prepared['urls'])) {
            $result = array(
                'success' => false,
                'skipped' => true,
                'message' => self::maybe_translate('No eligible local cache URLs were available for Varnish invalidation.'),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
                'operationType' => 'batch-invalidation',
                'receivedUrlCount' => (int) $prepared['receivedCount'],
                'validUrlCount' => 0,
                'uniqueUrlCount' => 0,
                'duplicateUrlCount' => (int) $prepared['duplicateCount'],
                'rejectedUrlCount' => (int) $prepared['rejectedCount'],
                'rejections' => $prepared['rejections'],
                'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
                'urlResults' => array(),
            );
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
            self::set_varnish_last_result($result);
            return $result;
        }

        $requested_targets_supplied = !empty($endpoint_targets);
        $requested_targets = $requested_targets_supplied
            ? self::resolve_varnish_invalidation_targets($endpoint_targets)
            : array();
        $targets = self::resolve_varnish_invalidation_targets((array) $settings['servers'], $endpoint_targets);
        $missing_targets = $requested_targets_supplied ? array_values(array_diff($requested_targets, $targets)) : array();
        if (empty($targets) || !empty($missing_targets)) {
            $url_results = self::initialize_varnish_url_results($prepared['urls']);
            foreach ($url_results as $url => $url_result) {
                $failed_targets = !empty($requested_targets) ? $requested_targets : array_values(array_filter(array_map('strval', $endpoint_targets)));
                $url_results[$url]['failedEndpointTargets'] = $failed_targets;
                $url_results[$url]['retryable'] = false;
                $url_results[$url]['message'] = self::maybe_translate('The queued Varnish endpoint target set no longer matches the current configuration.');
            }
            $result = array(
                'success' => false,
                'message' => self::maybe_translate('One or more requested Varnish endpoint targets are no longer configured.'),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
                'operationType' => 'batch-invalidation',
                'receivedUrlCount' => (int) $prepared['receivedCount'],
                'validUrlCount' => (int) $prepared['validCount'],
                'uniqueUrlCount' => (int) $prepared['uniqueCount'],
                'duplicateUrlCount' => (int) $prepared['duplicateCount'],
                'rejectedUrlCount' => (int) $prepared['rejectedCount'],
                'rejections' => $prepared['rejections'],
                'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
                'urlResults' => $url_results,
                'fullyInvalidatedUrlCount' => 0,
                'partiallyInvalidatedUrlCount' => 0,
                'failedUrlCount' => count($url_results),
                'successfulEndpointRequestCount' => 0,
                'failedEndpointRequestCount' => 0,
                'requestedEndpointTargets' => array_values($requested_targets),
                'missingEndpointTargets' => $missing_targets,
                'attemptedEndpointTargets' => array(),
            );
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, true);
            $persisted_result = $result;
            unset($persisted_result['urlResults']);
            self::set_varnish_last_result($persisted_result);
            return $result;
        }

        if ('soft' === $effective_strategy && !empty($runtime_plan['canExecute'])) {
            $result = self::send_varnish_soft_purge_prepared_urls($prepared, $scope, $targets, $requested_targets_supplied);
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, true);
            $persisted_result = $result;
            unset($persisted_result['urlResults']);
            self::set_varnish_last_result($persisted_result, false);
            return $result;
        }

        $exact_capability = self::get_varnish_exact_invalidation_capability($settings);
        if (empty($runtime_plan['canExecute'])) {
            $capability_message = self::sanitize_varnish_string(
                (string) ($runtime_plan['reason'] ?? ($exact_capability['message'] ?? self::maybe_translate('Exact URL invalidation is not verified for the configured Varnish transport.')))
            );
            $url_results = self::initialize_varnish_url_results($prepared['urls']);
            foreach ($url_results as $url => $url_result) {
                $url_results[$url]['success'] = false;
                $url_results[$url]['partial'] = false;
                $url_results[$url]['retryable'] = false;
                $url_results[$url]['failedEndpointTargets'] = array_values($targets);
                $url_results[$url]['message'] = $capability_message;
            }

            $result = array(
                'success' => false,
                'partial' => false,
                'skipped' => true,
                'unsupported' => 'unsupported' === (string) ($runtime_plan['plannedOutcome'] ?? ''),
                'degraded' => 'degraded' === (string) ($runtime_plan['plannedOutcome'] ?? ''),
                'verificationRequired' => true,
                'exactInvalidationVerified' => false,
                'transportAccepted' => false,
                'message' => $capability_message,
                'time' => time(),
                'mode' => $mode,
                'method' => $method,
                'effectiveMethod' => $effective_method,
                'invalidationStrategy' => $effective_strategy,
                'capabilityStatus' => sanitize_key((string) ($exact_capability['status'] ?? 'not-verified')),
                'capabilityTestedAt' => absint($exact_capability['testedAt'] ?? 0),
                'endpointCount' => count($targets),
                'configuredEndpointCount' => count((array) $settings['servers']),
                'adminModeUsed' => ('admin' === $mode),
                'httpEndpointModeUsed' => ('http' === $mode),
                'secretConfigured' => !empty($settings['key']),
                'scope' => sanitize_key((string) $scope),
                'operationType' => 'batch-invalidation',
                'receivedUrlCount' => (int) $prepared['receivedCount'],
                'validUrlCount' => (int) $prepared['validCount'],
                'uniqueUrlCount' => (int) $prepared['uniqueCount'],
                'duplicateUrlCount' => (int) $prepared['duplicateCount'],
                'rejectedUrlCount' => (int) $prepared['rejectedCount'],
                'hostCount' => count(array_unique(array_map(static function ($item) {
                    return (string) ($item['host'] ?? '');
                }, $prepared['urls']))),
                'batchCount' => 0,
                'requestCount' => 0,
                'successfulEndpointRequestCount' => 0,
                'failedEndpointRequestCount' => 0,
                'requestedEndpointTargets' => $requested_targets_supplied ? array_values($requested_targets) : array(),
                'attemptedEndpointTargets' => array(),
                'blockedEndpointTargets' => array_values($targets),
                'rejections' => $prepared['rejections'],
                'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
                'detailCount' => 0,
                'detailsTruncated' => false,
                'details' => array(),
                'urlResults' => $url_results,
                'fullyInvalidatedUrlCount' => 0,
                'partiallyInvalidatedUrlCount' => 0,
                'failedUrlCount' => count($url_results),
            );
            if (!empty($runtime_plan['ttlFallbackAvailable']) && !empty($runtime_plan['ttlFallbackMinutes'])) {
                $result['message'] .= ' ' . self::maybe_translate_sprintf(
                    'No transport request was sent; eligible shared-cache objects will expire within the configured %d-minute TTL-only window.',
                    absint($runtime_plan['ttlFallbackMinutes'])
                );
            }
            $result = self::finalize_varnish_runtime_result($result, $runtime_plan, false);
            $persisted_result = $result;
            unset($persisted_result['urlResults']);
            self::set_varnish_last_result($persisted_result);
            return $result;
        }

        $batch_probe_authorized = !empty($runtime_plan['capabilityProbeAuthorized'])
            && 'batch-ban' === $probe_strategy
            && 'batch' === $probe_scope;
        $batch_ban_verified = !empty($runtime_plan['batchBanVerified']);
        $batch_ban_allowed = count($prepared['urls']) > 1
            && ($batch_ban_verified || $batch_probe_authorized);

        $details = array();
        $request_count = 0;
        $successful_endpoint_requests = 0;
        $failed_endpoint_requests = 0;
        $batch_count = 0;
        $host_lookup = array();
        $url_results = self::initialize_varnish_url_results($prepared['urls']);
        foreach ($prepared['urls'] as $item) {
            $host_lookup[(string) $item['host']] = true;
        }

        if ('admin' === $mode || 'BAN' === $method) {
            $batches = $batch_ban_allowed
                ? self::build_varnish_ban_batches($prepared['urls'])
                : self::build_varnish_exact_ban_batches($prepared['urls']);
            $batch_count = count($batches);
            foreach ($batches as $batch_index => $batch) {
                foreach ($targets as $server) {
                    $res = self::varnish_command_for_expr(
                        $server,
                        $settings['key'],
                        $settings['timeout'],
                        (string) $batch['expression'],
                        'BAN',
                        false,
                        !empty($runtime_plan['capabilityProbeAuthorized']) ? 3 : 1,
                        (string) ($batch['host'] ?? '')
                    );
                    ++$request_count;
                    $success = !empty($res['ok']);
                    if ($success) {
                        ++$successful_endpoint_requests;
                    } else {
                        ++$failed_endpoint_requests;
                    }
                    self::record_varnish_url_endpoint_result($url_results, (array) ($batch['urls'] ?? array()), $server, $res);
                    $detail_format = $batch_ban_allowed
                        ? self::maybe_translate('Batch %1$d/%2$d · %3$d URL(s) · %4$s')
                        : self::maybe_translate('Exact BAN %1$d/%2$d · %3$d URL(s) · %4$s');
                    $details[] = array(
                        'server' => $server,
                        'success' => $success,
                        'connectionAccepted' => !empty($res['connectionAccepted']),
                        'commandAccepted' => !empty($res['commandAccepted']) || $success,
                        'code' => absint($res['code'] ?? 0),
                        'detail' => self::sanitize_varnish_string(sprintf(
                            $detail_format,
                            $batch_index + 1,
                            $batch_count,
                            count($batch['paths']),
                            (string) ($res['detail'] ?? '')
                        )),
                    );
                }
            }
        } else {
            /*
             * HTTP PURGE remains exact-path. Combining multiple URLs would assume
             * custom VCL support that the current capability test does not prove.
             */
            foreach ($prepared['urls'] as $url_index => $item) {
                $expr = self::build_varnish_ban_expression((string) $item['host'], (string) $item['path'], false);
                $canonical_url = (string) ($item['url'] ?? '');
                foreach ($targets as $terminal) {
                    $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                    if (empty($endpoint_check['valid'])) {
                        ++$request_count;
                        ++$failed_endpoint_requests;
                        $failure_detail = self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.'));
                        $response = array('ok' => false, 'detail' => $failure_detail, 'code' => 0);
                        self::record_varnish_endpoint_result($terminal, 'http', false, 0, $failure_detail);
                        self::record_varnish_url_endpoint_result($url_results, array($canonical_url), $terminal, $response);
                        $details[] = array(
                            'server' => $terminal,
                            'success' => false,
                            'detail' => $failure_detail,
                        );
                        continue;
                    }

                    $endpoint = self::normalize_varnish_endpoint($terminal);
                    if (empty($endpoint)) {
                        ++$request_count;
                        ++$failed_endpoint_requests;
                        $failure_detail = self::sanitize_varnish_string('Invalid or blocked Varnish HTTP endpoint.');
                        $response = array('ok' => false, 'detail' => $failure_detail, 'code' => 0);
                        self::record_varnish_endpoint_result($terminal, 'http', false, 0, $failure_detail);
                        self::record_varnish_url_endpoint_result($url_results, array($canonical_url), $terminal, $response);
                        $details[] = array(
                            'server' => $terminal,
                            'success' => false,
                            'detail' => $failure_detail,
                        );
                        continue;
                    }

                    $target_url = self::build_varnish_target_url($endpoint, (string) $item['path']);
                    $res = self::send_varnish_http_request(
                        $endpoint,
                        $target_url,
                        (string) $item['host'],
                        $settings['timeout'],
                        $expr,
                        'PURGE'
                    );
                    ++$request_count;
                    $success = !empty($res['ok']);
                    if ($success) {
                        ++$successful_endpoint_requests;
                    } else {
                        ++$failed_endpoint_requests;
                    }
                    self::record_varnish_url_endpoint_result($url_results, array($canonical_url), $terminal, $res);
                    $details[] = array(
                        'server' => $terminal,
                        'success' => $success,
                        'detail' => self::sanitize_varnish_string(
                            self::maybe_translate_sprintf(
                                'URL %1$d/%2$d · %3$s',
                                $url_index + 1,
                                count($prepared['urls']),
                                (string) ($res['detail'] ?? '')
                            )
                        ),
                    );
                }
            }
        }

        $accounting = self::finalize_varnish_url_results($url_results);
        $all_ok = (int) $accounting['fullyInvalidatedUrlCount'] === (int) $prepared['uniqueCount'];
        $detail_count = count($details);
        $details_truncated = $detail_count > 100;
        if ($details_truncated) {
            $details = array_slice($details, 0, 100);
        }

        if ($all_ok) {
            $message = self::maybe_translate_sprintf(
                'Varnish %1$s invalidated %2$d unique URL(s) with %3$d request(s).',
                $effective_method,
                (int) $prepared['uniqueCount'],
                $request_count
            );
        } elseif ((int) $accounting['partiallyInvalidatedUrlCount'] > 0 || (int) $accounting['fullyInvalidatedUrlCount'] > 0) {
            $message = self::maybe_translate_sprintf(
                'Varnish %1$s fully invalidated %2$d URL(s), partially invalidated %3$d, and failed %4$d.',
                $effective_method,
                (int) $accounting['fullyInvalidatedUrlCount'],
                (int) $accounting['partiallyInvalidatedUrlCount'],
                (int) $accounting['failedUrlCount']
            );
        } else {
            $message = self::maybe_translate_sprintf('Varnish %s failed on every requested URL.', $effective_method);
        }

        $result = array_merge(array(
            'success' => $all_ok,
            'partial' => !$all_ok && ((int) $accounting['partiallyInvalidatedUrlCount'] > 0 || (int) $accounting['fullyInvalidatedUrlCount'] > 0),
            'message' => $message,
            'time' => time(),
            'mode' => $mode,
            'method' => $method,
            'effectiveMethod' => $effective_method,
            'invalidationStrategy' => $effective_strategy,
            'endpointCount' => count($targets),
            'configuredEndpointCount' => count((array) $settings['servers']),
            'adminModeUsed' => ('admin' === $mode),
            'httpEndpointModeUsed' => ('http' === $mode),
            'secretConfigured' => !empty($settings['key']),
            'scope' => sanitize_key((string) $scope),
            'operationType' => 'batch-invalidation',
            'receivedUrlCount' => (int) $prepared['receivedCount'],
            'validUrlCount' => (int) $prepared['validCount'],
            'uniqueUrlCount' => (int) $prepared['uniqueCount'],
            'duplicateUrlCount' => (int) $prepared['duplicateCount'],
            'rejectedUrlCount' => (int) $prepared['rejectedCount'],
            'hostCount' => count($host_lookup),
            'batchCount' => $batch_count,
            'batchBanVerified' => $batch_ban_verified,
            'batchBanProbeAuthorized' => $batch_probe_authorized,
            'batchBanUsed' => $batch_ban_allowed,
            'exactBanPerUrlFallbackUsed' => ('admin' === $mode || 'BAN' === $method)
                && count($prepared['urls']) > 1
                && !$batch_ban_allowed,
            'requestCount' => $request_count,
            'successfulEndpointRequestCount' => $successful_endpoint_requests,
            'failedEndpointRequestCount' => $failed_endpoint_requests,
            'requestedEndpointTargets' => $requested_targets_supplied ? array_values($requested_targets) : array(),
            'attemptedEndpointTargets' => array_values($targets),
            'rejections' => $prepared['rejections'],
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'detailCount' => $detail_count,
            'detailsTruncated' => $details_truncated,
            'details' => $details,
        ), $accounting);

        $result = self::finalize_varnish_runtime_result($result, $runtime_plan, true);
        $persisted_result = $result;
        unset($persisted_result['urlResults']);
        self::set_varnish_last_result($persisted_result);
        return $result;
    }
}
