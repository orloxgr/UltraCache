<?php
/**
 * Bounded production LiteSpeed operation metrics and history.
 *
 * Diagnostic behavior tests are stored separately and never increment these
 * production counters.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Metrics_Trait
{
    /** @var array|null */
    private static $litespeed_metrics_state_cache = null;

    /** @var bool */
    private static $litespeed_metrics_state_dirty = false;

    /** @var bool */
    private static $litespeed_metrics_shutdown_registered = false;

    private static function get_litespeed_metrics_option_name()
    {
        return 'ultracache_litespeed_metrics_v1';
    }

    private static function get_default_litespeed_metrics_state()
    {
        return array(
            'version' => 1,
            'updatedAt' => 0,
            'operations' => array(
                'sitePurgeAttempts' => 0,
                'sitePurgeSuccesses' => 0,
                'sitePurgeFailures' => 0,
                'urlPurgeOperations' => 0,
                'urlPurgeSuccesses' => 0,
                'urlPurgeFailures' => 0,
                'staleUrlPurgeOperations' => 0,
                'staleUrlPurgeSuccesses' => 0,
                'staleUrlPurgeFailures' => 0,
                'staleInvalidatedUrls' => 0,
                'invalidatedUrls' => 0,
                'semanticTagPurgeOperations' => 0,
                'semanticTagPurgeSuccesses' => 0,
                'semanticTagPurgeFailures' => 0,
                'staleSemanticTagPurgeOperations' => 0,
                'staleSemanticTagPurgeSuccesses' => 0,
                'staleSemanticTagPurgeFailures' => 0,
                'invalidatedSemanticTags' => 0,
                'staleInvalidatedSemanticTags' => 0,
                'refillRequests' => 0,
                'refillSuccesses' => 0,
                'refillFailures' => 0,
                'observedHits' => 0,
                'observedMisses' => 0,
                'observedBypasses' => 0,
                'inconclusiveResponses' => 0,
            ),
            'history' => array(),
        );
    }

    private static function sanitize_litespeed_history_entry($entry)
    {
        if (!is_array($entry)) {
            return array();
        }

        $allowed_types = array('site-purge', 'url-purge', 'tag-purge', 'refill');
        $type = sanitize_key((string) ($entry['type'] ?? ''));
        if (!in_array($type, $allowed_types, true)) {
            return array();
        }

        $cache_status = strtoupper(sanitize_text_field((string) ($entry['cacheStatus'] ?? '')));
        if (!in_array($cache_status, array('', 'HIT', 'MISS', 'BYPASS', 'INCONCLUSIVE', 'ERROR', 'REDIRECT'), true)) {
            $cache_status = 'INCONCLUSIVE';
        }

        $message = sanitize_text_field((string) ($entry['message'] ?? ''));
        $path = sanitize_text_field((string) ($entry['path'] ?? ''));

        return array(
            'time' => max(0, (int) ($entry['time'] ?? 0)),
            'type' => $type,
            'success' => !empty($entry['success']),
            'context' => substr(sanitize_key((string) ($entry['context'] ?? '')), 0, 60),
            'targetCount' => max(0, (int) ($entry['targetCount'] ?? 0)),
            'processedCount' => max(0, (int) ($entry['processedCount'] ?? 0)),
            'bucket' => substr(sanitize_key((string) ($entry['bucket'] ?? '')), 0, 20),
            'cacheStatus' => $cache_status,
            'httpStatus' => max(0, min(599, (int) ($entry['httpStatus'] ?? 0))),
            'durationMs' => max(0, min(600000, (int) ($entry['durationMs'] ?? 0))),
            'path' => strlen($path) > 190 ? substr($path, 0, 190) : $path,
            'message' => strlen($message) > 240 ? substr($message, 0, 240) : $message,
        );
    }

    private static function sanitize_litespeed_metrics_state($value)
    {
        $defaults = self::get_default_litespeed_metrics_state();
        if (!is_array($value)) {
            return $defaults;
        }

        $state = $defaults;
        $state['updatedAt'] = max(0, (int) ($value['updatedAt'] ?? 0));
        $stored_operations = is_array($value['operations'] ?? null) ? $value['operations'] : array();
        foreach ($state['operations'] as $key => $default) {
            $state['operations'][$key] = max(0, (int) ($stored_operations[$key] ?? $default));
        }

        $history = is_array($value['history'] ?? null) ? $value['history'] : array();
        foreach (array_slice($history, -30) as $entry) {
            $entry = self::sanitize_litespeed_history_entry($entry);
            if (!empty($entry)) {
                $state['history'][] = $entry;
            }
        }

        return $state;
    }

    private static function get_litespeed_metrics_state()
    {
        if (is_array(self::$litespeed_metrics_state_cache)) {
            return self::$litespeed_metrics_state_cache;
        }

        self::$litespeed_metrics_state_cache = self::sanitize_litespeed_metrics_state(
            get_option(self::get_litespeed_metrics_option_name(), array())
        );

        return self::$litespeed_metrics_state_cache;
    }

    private static function set_litespeed_metrics_state(array $state)
    {
        self::$litespeed_metrics_state_cache = self::sanitize_litespeed_metrics_state($state);
        self::$litespeed_metrics_state_cache['updatedAt'] = time();
        self::$litespeed_metrics_state_dirty = true;

        if (!self::$litespeed_metrics_shutdown_registered) {
            self::$litespeed_metrics_shutdown_registered = true;
            register_shutdown_function(array(__CLASS__, 'flush_litespeed_metrics_state'));
        }
    }

    public static function flush_litespeed_metrics_state()
    {
        if (!self::$litespeed_metrics_state_dirty || !is_array(self::$litespeed_metrics_state_cache)) {
            return;
        }

        update_option(
            self::get_litespeed_metrics_option_name(),
            self::sanitize_litespeed_metrics_state(self::$litespeed_metrics_state_cache),
            false
        );
        self::$litespeed_metrics_state_dirty = false;
    }

    private static function litespeed_metrics_may_record()
    {
        return !(method_exists(static::class, 'is_litespeed_test_run_active') && self::is_litespeed_test_run_active());
    }

    private static function append_litespeed_operation_history(array &$state, array $entry)
    {
        $entry = self::sanitize_litespeed_history_entry($entry);
        if (empty($entry)) {
            return;
        }

        $state['history'][] = $entry;
        if (count($state['history']) > 30) {
            $state['history'] = array_slice($state['history'], -30);
        }
    }

    private static function get_litespeed_metrics_path($url)
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ('' === $path) {
            return '/';
        }

        $path = '/' . ltrim(rawurldecode($path), '/');
        return strlen($path) > 190 ? substr($path, 0, 190) : $path;
    }

    /**
     * Record one production site, exact-URL, or semantic-tag purge operation.
     *
     * @param string $operation    site, urls, or tags.
     * @param array  $result       Purge result.
     * @param int    $target_count Requested target count.
     * @param string $context      Operation source.
     * @return void
     */
    private static function record_litespeed_purge_result($operation, array $result, $target_count = 0, $context = '')
    {
        if (!self::litespeed_metrics_may_record() || !empty($result['skipped'])) {
            return;
        }

        $operation = sanitize_key((string) $operation);
        if (!in_array($operation, array('site', 'urls', 'tags'), true)) {
            return;
        }

        $state = self::get_litespeed_metrics_state();
        $operations = $state['operations'];
        $success = !empty($result['success']);
        $target_count = max(0, (int) $target_count);
        $processed_count = max(0, (int) ($result['processedCount'] ?? ($success ? ($result['targetCount'] ?? $target_count) : 0)));

        if ('site' === $operation) {
            $operations['sitePurgeAttempts']++;
            $operations[$success ? 'sitePurgeSuccesses' : 'sitePurgeFailures']++;
        } elseif ('tags' === $operation) {
            $operations['semanticTagPurgeOperations']++;
            $operations[$success ? 'semanticTagPurgeSuccesses' : 'semanticTagPurgeFailures']++;
            $operations['invalidatedSemanticTags'] += $processed_count;
            if (!empty($result['stale'])) {
                $operations['staleSemanticTagPurgeOperations']++;
                $operations[$success ? 'staleSemanticTagPurgeSuccesses' : 'staleSemanticTagPurgeFailures']++;
                $operations['staleInvalidatedSemanticTags'] += $processed_count;
            }
        } else {
            $operations['urlPurgeOperations']++;
            $operations[$success ? 'urlPurgeSuccesses' : 'urlPurgeFailures']++;
            $operations['invalidatedUrls'] += $processed_count;
            if (!empty($result['stale'])) {
                $operations['staleUrlPurgeOperations']++;
                $operations[$success ? 'staleUrlPurgeSuccesses' : 'staleUrlPurgeFailures']++;
                $operations['staleInvalidatedUrls'] += $processed_count;
            }
        }

        $state['operations'] = $operations;
        self::append_litespeed_operation_history($state, array(
            'time' => time(),
            'type' => 'site' === $operation ? 'site-purge' : ('tags' === $operation ? 'tag-purge' : 'url-purge'),
            'success' => $success,
            'context' => $context,
            'targetCount' => 'site' === $operation ? 1 : $target_count,
            'processedCount' => 'site' === $operation ? ($success ? 1 : 0) : $processed_count,
            'httpStatus' => (int) ($result['httpStatus'] ?? 0),
            'message' => (string) ($result['message'] ?? ''),
        ));
        self::set_litespeed_metrics_state($state);
    }

    /**
     * Record one production public refill response.
     *
     * @param string $url     Local public URL.
     * @param string $bucket  HTML bucket.
     * @param array  $summary Response summary.
     * @param string $context Warm source.
     * @return void
     */
    private static function record_litespeed_refill_result($url, $bucket, array $summary, $context = '')
    {
        if (!self::litespeed_metrics_may_record()) {
            return;
        }

        $state = self::get_litespeed_metrics_state();
        $operations = $state['operations'];
        $success = !empty($summary['success']);
        $cache_status = strtoupper((string) ($summary['cacheStatus'] ?? 'INCONCLUSIVE'));

        $operations['refillRequests']++;
        $operations[$success ? 'refillSuccesses' : 'refillFailures']++;
        if ('HIT' === $cache_status) {
            $operations['observedHits']++;
        } elseif ('MISS' === $cache_status) {
            $operations['observedMisses']++;
        } elseif ('BYPASS' === $cache_status) {
            $operations['observedBypasses']++;
        } elseif ('INCONCLUSIVE' === $cache_status) {
            $operations['inconclusiveResponses']++;
        }

        $state['operations'] = $operations;
        self::append_litespeed_operation_history($state, array(
            'time' => time(),
            'type' => 'refill',
            'success' => $success,
            'context' => $context,
            'targetCount' => 1,
            'processedCount' => $success ? 1 : 0,
            'bucket' => $bucket,
            'cacheStatus' => $cache_status,
            'httpStatus' => (int) ($summary['httpCode'] ?? 0),
            'durationMs' => (int) ($summary['durationMs'] ?? 0),
            'path' => self::get_litespeed_metrics_path($url),
            'message' => (string) ($summary['detail'] ?? ''),
        ));
        self::set_litespeed_metrics_state($state);
    }

    public static function get_litespeed_metrics_status()
    {
        $state = self::get_litespeed_metrics_state();
        $history = array();
        foreach (array_reverse($state['history']) as $entry) {
            $entry['timeHuman'] = !empty($entry['time']) ? gmdate('Y-m-d H:i:s', (int) $entry['time']) . ' UTC' : '';
            $history[] = $entry;
        }

        return array(
            'updatedAt' => max(0, (int) $state['updatedAt']),
            'updatedAtHuman' => !empty($state['updatedAt']) ? gmdate('Y-m-d H:i:s', (int) $state['updatedAt']) . ' UTC' : '',
            'operations' => $state['operations'],
            'history' => $history,
        );
    }
}
