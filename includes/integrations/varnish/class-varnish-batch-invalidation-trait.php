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
            if ('' === $host || '' === $path) {
                continue;
            }
            if (!isset($by_host[$host])) {
                $by_host[$host] = array();
            }
            $by_host[$host][] = $path;
        }

        $batches = array();
        $path_limit = self::get_varnish_ban_batch_path_limit();
        $expression_limit = self::get_varnish_ban_batch_expression_limit();

        foreach ($by_host as $host => $paths) {
            $current = array();
            foreach ($paths as $path) {
                $candidate = array_merge($current, array($path));
                $candidate_expression = self::build_varnish_batched_ban_expression($host, $candidate);
                $would_exceed_count = count($candidate) > $path_limit;
                $would_exceed_length = strlen($candidate_expression) > $expression_limit;

                if (!empty($current) && ($would_exceed_count || $would_exceed_length)) {
                    $expression = self::build_varnish_batched_ban_expression($host, $current);
                    $batches[] = array(
                        'host' => $host,
                        'paths' => $current,
                        'expression' => $expression,
                    );
                    $current = array($path);
                    continue;
                }

                $current = $candidate;
            }

            if (!empty($current)) {
                $batches[] = array(
                    'host' => $host,
                    'paths' => $current,
                    'expression' => self::build_varnish_batched_ban_expression($host, $current),
                );
            }
        }

        return $batches;
    }

    /**
     * Send a normalized invalidation set using bounded BAN batches or exact PURGE.
     *
     * @param array  $urls  Candidate URLs.
     * @param string $scope Invalidation scope label.
     * @return array
     */
    private static function varnish_flush_url_batch(array $urls, $scope = 'batch', $strategy_override = '')
    {
        $settings = self::get_varnish_cli_settings();
        $mode = (string) ($settings['mode'] ?? 'http');
        $method = (string) ($settings['method'] ?? 'BAN');
        $strategy_status = self::get_varnish_invalidation_strategy_status($settings, $strategy_override);
        $effective_strategy = (string) ($strategy_status['effective'] ?? 'ban');
        $method = 'purge' === $effective_strategy || 'soft' === $effective_strategy ? 'PURGE' : 'BAN';
        $effective_method = 'soft' === $effective_strategy ? 'soft PURGE' : (('admin' === $mode) ? 'admin BAN' : $method);
        $support = is_array($settings['support'] ?? null) ? $settings['support'] : array();

        if (empty($support['available'])) {
            $result = array(
                'success' => false,
                'message' => (string) ($support['message'] ?? self::maybe_translate('Varnish integration is unavailable.')),
                'time' => time(),
                'scope' => sanitize_key((string) $scope),
            );
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
            );
            self::set_varnish_last_result($result);
            return $result;
        }

        if ('soft' === $effective_strategy) {
            return self::send_varnish_soft_purge_prepared_urls($prepared, $scope);
        }

        $details = array();
        $all_ok = true;
        $request_count = 0;
        $batch_count = 0;
        $host_lookup = array();
        foreach ($prepared['urls'] as $item) {
            $host_lookup[(string) $item['host']] = true;
        }

        if ('admin' === $mode || 'BAN' === $method) {
            $batches = self::build_varnish_ban_batches($prepared['urls']);
            $batch_count = count($batches);
            foreach ($batches as $batch_index => $batch) {
                foreach ($settings['servers'] as $server) {
                    $res = self::varnish_command_for_expr($server, $settings['key'], $settings['timeout'], (string) $batch['expression'], 'BAN');
                    ++$request_count;
                    $success = !empty($res['ok']);
                    $all_ok = $all_ok && $success;
                    $details[] = array(
                        'server' => $server,
                        'success' => $success,
                        'detail' => self::sanitize_varnish_string(
                            self::maybe_translate_sprintf(
                                'Batch %1$d/%2$d · %3$d URL(s) · %4$s',
                                $batch_index + 1,
                                $batch_count,
                                count($batch['paths']),
                                (string) ($res['detail'] ?? '')
                            )
                        ),
                    );
                }
            }
        } else {
            /*
             * HTTP PURGE remains exact-path. Combining multiple URLs would assume
             * custom VCL support that the current capability test does not prove.
             */
            $batch_count = 0;
            foreach ($prepared['urls'] as $url_index => $item) {
                $expr = self::build_varnish_ban_expression((string) $item['host'], (string) $item['path'], false);
                foreach ($settings['servers'] as $terminal) {
                    $endpoint_check = self::validate_varnish_http_endpoint($terminal);
                    if (empty($endpoint_check['valid'])) {
                        ++$request_count;
                        $all_ok = false;
                        $failure_detail = self::sanitize_varnish_string((string) ($endpoint_check['message'] ?? 'Invalid or blocked Varnish HTTP endpoint.'));
                        self::record_varnish_endpoint_result($terminal, 'http', false, 0, $failure_detail);
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
                        $all_ok = false;
                        $failure_detail = self::sanitize_varnish_string('Invalid or blocked Varnish HTTP endpoint.');
                        self::record_varnish_endpoint_result($terminal, 'http', false, 0, $failure_detail);
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
                    $all_ok = $all_ok && $success;
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

        $detail_count = count($details);
        $details_truncated = $detail_count > 100;
        if ($details_truncated) {
            $details = array_slice($details, 0, 100);
        }

        $message = $all_ok
            ? self::maybe_translate_sprintf(
                'Varnish %1$s invalidated %2$d unique URL(s) with %3$d request(s).',
                $effective_method,
                (int) $prepared['uniqueCount'],
                $request_count
            )
            : self::maybe_translate_sprintf('Varnish %s failed on one or more invalidation requests.', $effective_method);

        $result = array(
            'success' => $all_ok,
            'message' => $message,
            'time' => time(),
            'mode' => $mode,
            'method' => $method,
            'effectiveMethod' => $effective_method,
            'invalidationStrategy' => $effective_strategy,
            'endpointCount' => count($settings['servers']),
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
            'requestCount' => $request_count,
            'rejections' => $prepared['rejections'],
            'rejectionsTruncated' => !empty($prepared['rejectionsTruncated']),
            'detailCount' => $detail_count,
            'detailsTruncated' => $details_truncated,
            'details' => $details,
        );

        self::set_varnish_last_result($result);
        return $result;
    }
}
