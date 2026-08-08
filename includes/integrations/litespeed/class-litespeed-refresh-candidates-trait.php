<?php
/**
 * Bounded multi-source candidate registry for LiteSpeed refresh-ahead.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Refresh_Candidates_Trait
{
    private static function get_litespeed_refresh_candidate_option_key()
    {
        return 'ultracache_litespeed_refresh_candidates_v1';
    }

    private static function get_litespeed_refresh_candidate_cap()
    {
        $cap = (int) apply_filters('ultracache_litespeed_refresh_candidate_cap', 150);
        return max(50, min(500, $cap));
    }

    private static function get_litespeed_refresh_candidate_retention()
    {
        $retention = (int) apply_filters('ultracache_litespeed_refresh_candidate_retention_seconds', 30 * DAY_IN_SECONDS);
        return max(7 * DAY_IN_SECONDS, min(180 * DAY_IN_SECONDS, $retention));
    }

    private static function get_litespeed_refresh_candidate_source_weight($source)
    {
        $weights = array(
            'pinned'        => 1000,
            'home'          => 950,
            'posts-page'    => 900,
            'shop-page'     => 900,
            'menu'          => 800,
            'analytics'     => 600,
            'sitemap'       => 300,
        );
        $source = sanitize_key((string) $source);
        return isset($weights[$source]) ? (int) $weights[$source] : 100;
    }

    private static function normalize_litespeed_refresh_candidate_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }
        if ('/' === substr($url, 0, 1)) {
            $url = home_url($url);
        }
        if (method_exists(static::class, 'normalize_litespeed_purge_url')) {
            return self::normalize_litespeed_purge_url($url);
        }

        $url = preg_replace('/[?#].*$/', '', $url);
        $url = esc_url_raw((string) $url, array('http', 'https'));
        if ('' === $url) {
            return '';
        }

        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return '' !== $home_host && hash_equals($home_host, $url_host) ? $url : '';
    }

    private static function get_default_litespeed_refresh_candidate_registry()
    {
        return array(
            'version' => 1,
            'updatedAt' => 0,
            'cursor' => array(
                'providerIndex' => 0,
                'subtypeIndex' => 0,
                'page' => 1,
                'offset' => 0,
                'cycle' => 0,
            ),
            'candidates' => array(),
        );
    }

    private static function sanitize_litespeed_refresh_candidate_registry($value)
    {
        $defaults = self::get_default_litespeed_refresh_candidate_registry();
        if (!is_array($value)) {
            return $defaults;
        }

        $registry = $defaults;
        $registry['updatedAt'] = max(0, (int) ($value['updatedAt'] ?? 0));
        $cursor = is_array($value['cursor'] ?? null) ? $value['cursor'] : array();
        foreach (array('providerIndex', 'subtypeIndex', 'offset', 'cycle') as $key) {
            $registry['cursor'][$key] = max(0, (int) ($cursor[$key] ?? 0));
        }
        $registry['cursor']['page'] = max(1, (int) ($cursor['page'] ?? 1));

        $now = time();
        $retention = self::get_litespeed_refresh_candidate_retention();
        $candidates = array();
        foreach ((array) ($value['candidates'] ?? array()) as $hash => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $url = self::normalize_litespeed_refresh_candidate_url($candidate['url'] ?? '');
            if ('' === $url) {
                continue;
            }
            $last_seen = max(0, (int) ($candidate['lastSeen'] ?? 0));
            if ($last_seen > 0 && ($now - $last_seen) > $retention) {
                continue;
            }

            $source_weights = array();
            foreach ((array) ($candidate['sourceWeights'] ?? array()) as $source => $weight) {
                $source = sanitize_key((string) $source);
                if ('' !== $source) {
                    $source_weights[$source] = max(1, min(2000, (int) $weight));
                }
            }
            if (empty($source_weights)) {
                continue;
            }

            $key = preg_match('/\A[a-f0-9]{40}\z/', (string) $hash) ? (string) $hash : sha1($url);
            $last_probe_bucket = in_array((string) ($candidate['lastProbeBucket'] ?? ''), array('orig', 'webp', 'avif'), true)
                ? (string) $candidate['lastProbeBucket']
                : '';
            $last_result = sanitize_key((string) ($candidate['lastResult'] ?? ''));
            $candidates[$key] = array(
                'url' => $url,
                'sourceWeights' => array_slice($source_weights, 0, 10, true),
                'observations' => max(0, min(PHP_INT_MAX, (int) ($candidate['observations'] ?? 0))),
                'firstSeen' => max(0, (int) ($candidate['firstSeen'] ?? $last_seen)),
                'lastSeen' => $last_seen,
                'lastProbedAt' => max(0, (int) ($candidate['lastProbedAt'] ?? 0)),
                'nextProbeAt' => max(0, (int) ($candidate['nextProbeAt'] ?? 0)),
                'probeCount' => max(0, min(PHP_INT_MAX, (int) ($candidate['probeCount'] ?? 0))),
                'lastProbeBucket' => $last_probe_bucket,
                'lastResult' => $last_result,
                'lastProbeAge' => isset($candidate['lastProbeAge']) && null !== $candidate['lastProbeAge']
                    ? max(0, (int) $candidate['lastProbeAge'])
                    : null,
            );
        }

        uasort($candidates, static function ($left, $right) {
            $left_weights = array_map('intval', (array) ($left['sourceWeights'] ?? array()));
            $right_weights = array_map('intval', (array) ($right['sourceWeights'] ?? array()));
            $left_weight = empty($left_weights) ? 0 : max($left_weights);
            $right_weight = empty($right_weights) ? 0 : max($right_weights);
            if ($left_weight === $right_weight) {
                return (int) ($right['lastSeen'] ?? 0) <=> (int) ($left['lastSeen'] ?? 0);
            }
            return $right_weight <=> $left_weight;
        });
        $registry['candidates'] = array_slice($candidates, 0, self::get_litespeed_refresh_candidate_cap(), true);
        return $registry;
    }

    private static function get_litespeed_refresh_candidate_registry()
    {
        return self::sanitize_litespeed_refresh_candidate_registry(
            get_option(self::get_litespeed_refresh_candidate_option_key(), array())
        );
    }

    private static function save_litespeed_refresh_candidate_registry(array $registry)
    {
        $registry['updatedAt'] = time();
        update_option(
            self::get_litespeed_refresh_candidate_option_key(),
            self::sanitize_litespeed_refresh_candidate_registry($registry),
            false
        );
    }

    private static function merge_litespeed_refresh_candidate(array &$registry, $url, $source, $weight = 0, $observations = 0, $seen_at = 0)
    {
        $url = self::normalize_litespeed_refresh_candidate_url($url);
        $source = sanitize_key((string) $source);
        if ('' === $url || '' === $source) {
            return false;
        }

        $hash = sha1($url);
        $now = $seen_at > 0 ? min(time(), (int) $seen_at) : time();
        $current = is_array($registry['candidates'][$hash] ?? null) ? $registry['candidates'][$hash] : array();
        $source_weights = is_array($current['sourceWeights'] ?? null) ? $current['sourceWeights'] : array();
        $weight = $weight > 0 ? (int) $weight : self::get_litespeed_refresh_candidate_source_weight($source);
        $source_weights[$source] = max((int) ($source_weights[$source] ?? 0), max(1, min(2000, $weight)));

        $registry['candidates'][$hash] = array(
            'url' => $url,
            'sourceWeights' => array_slice($source_weights, 0, 10, true),
            'observations' => max((int) ($current['observations'] ?? 0), max(0, (int) $observations)),
            'firstSeen' => !empty($current['firstSeen']) ? (int) $current['firstSeen'] : $now,
            'lastSeen' => max((int) ($current['lastSeen'] ?? 0), $now),
            'lastProbedAt' => max(0, (int) ($current['lastProbedAt'] ?? 0)),
            'nextProbeAt' => max(0, (int) ($current['nextProbeAt'] ?? 0)),
            'probeCount' => max(0, (int) ($current['probeCount'] ?? 0)),
            'lastProbeBucket' => in_array((string) ($current['lastProbeBucket'] ?? ''), array('orig', 'webp', 'avif'), true)
                ? (string) $current['lastProbeBucket']
                : '',
            'lastResult' => sanitize_key((string) ($current['lastResult'] ?? '')),
            'lastProbeAge' => isset($current['lastProbeAge']) && null !== $current['lastProbeAge']
                ? max(0, (int) $current['lastProbeAge'])
                : null,
        );
        return true;
    }

    private static function get_litespeed_refresh_pinned_urls(array $settings = array())
    {
        if (empty($settings)) {
            $settings = self::get_dashboard_settings();
        }
        $lines = preg_split('/[\r\n,]+/', (string) ($settings['liteSpeedRefreshAheadPinnedUrls'] ?? ''));
        $filtered = apply_filters('ultracache_litespeed_refresh_ahead_pinned_urls', (array) $lines, $settings);
        $urls = array();
        foreach ((array) $filtered as $line) {
            $url = self::normalize_litespeed_refresh_candidate_url($line);
            if ('' !== $url) {
                $urls[$url] = $url;
            }
            if (count($urls) >= 25) {
                break;
            }
        }
        return array_values($urls);
    }

    private static function seed_litespeed_refresh_static_candidates(array &$registry, array $settings = array())
    {
        $sources = array();
        $sources['home'] = array(home_url('/'));

        $posts_page_id = absint(get_option('page_for_posts'));
        if ($posts_page_id > 0) {
            $posts_url = get_permalink($posts_page_id);
            if (is_string($posts_url) && '' !== $posts_url) {
                $sources['posts-page'] = array($posts_url);
            }
        }

        $shop_page_id = absint(get_option('woocommerce_shop_page_id'));
        if ($shop_page_id > 0) {
            $shop_url = get_permalink($shop_page_id);
            if (is_string($shop_url) && '' !== $shop_url) {
                $sources['shop-page'] = array($shop_url);
            }
        }

        $menu_urls = array();
        if (function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items')) {
            foreach ((array) wp_get_nav_menus() as $menu) {
                $term_id = absint($menu->term_id ?? 0);
                if ($term_id < 1) {
                    continue;
                }
                $items = wp_get_nav_menu_items($term_id, array('update_post_term_cache' => false));
                foreach ((array) $items as $item) {
                    $url = self::normalize_litespeed_refresh_candidate_url($item->url ?? '');
                    if ('' !== $url) {
                        $menu_urls[$url] = $url;
                    }
                    if (count($menu_urls) >= 40) {
                        break 2;
                    }
                }
            }
        }
        if (!empty($menu_urls)) {
            $sources['menu'] = array_values($menu_urls);
        }

        $pinned_urls = self::get_litespeed_refresh_pinned_urls($settings);
        if (!empty($pinned_urls)) {
            $sources['pinned'] = $pinned_urls;
        }

        foreach ($sources as $source => $urls) {
            foreach ($urls as $url) {
                self::merge_litespeed_refresh_candidate($registry, $url, $source);
            }
        }
    }

    private static function import_litespeed_refresh_analytics_candidates(array &$registry)
    {
        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_hot_page_candidates')) {
            return;
        }
        foreach ((array) Ultra_Cache_Engine::get_hot_page_candidates(50) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $score = max(0, (float) ($candidate['score'] ?? 0));
            $weight = self::get_litespeed_refresh_candidate_source_weight('analytics') + min(250, (int) round($score * 10));
            self::merge_litespeed_refresh_candidate(
                $registry,
                $candidate['url'] ?? '',
                'analytics',
                $weight,
                max(0, (int) ($candidate['observations'] ?? 0)),
                max(0, (int) ($candidate['lastSeen'] ?? 0))
            );
        }
    }

    private static function advance_litespeed_sitemap_cursor(array &$cursor, $provider_count, $subtype_count, $max_pages)
    {
        ++$cursor['page'];
        $cursor['offset'] = 0;
        if ($cursor['page'] <= max(1, $max_pages)) {
            return;
        }

        $cursor['page'] = 1;
        ++$cursor['subtypeIndex'];
        if ($cursor['subtypeIndex'] < max(1, $subtype_count)) {
            return;
        }

        $cursor['subtypeIndex'] = 0;
        ++$cursor['providerIndex'];
        if ($cursor['providerIndex'] < max(1, $provider_count)) {
            return;
        }

        $cursor['providerIndex'] = 0;
        ++$cursor['cycle'];
    }

    private static function discover_litespeed_sitemap_candidates(array &$registry, $limit = 10)
    {
        $limit = max(1, min(25, absint($limit)));
        if (!function_exists('wp_sitemaps_get_server')) {
            return 0;
        }

        $server = wp_sitemaps_get_server();
        if (!is_object($server) || !isset($server->registry) || !is_object($server->registry) || !method_exists($server->registry, 'get_providers')) {
            return 0;
        }

        $providers = (array) $server->registry->get_providers();
        if (empty($providers)) {
            return 0;
        }
        ksort($providers);
        $provider_keys = array_keys($providers);
        $cursor = is_array($registry['cursor'] ?? null) ? $registry['cursor'] : self::get_default_litespeed_refresh_candidate_registry()['cursor'];
        $added = 0;
        $start_cycle = max(0, (int) ($cursor['cycle'] ?? 0));
        $attempts = 0;
        $max_attempts = max(20, count($provider_keys) * 20);

        while ($added < $limit && $attempts < $max_attempts && (int) ($cursor['cycle'] ?? 0) === $start_cycle) {
            ++$attempts;
            $provider_index = (int) $cursor['providerIndex'] % count($provider_keys);
            $provider = $providers[$provider_keys[$provider_index]] ?? null;
            if (!is_object($provider) || !method_exists($provider, 'get_object_subtypes') || !method_exists($provider, 'get_url_list') || !method_exists($provider, 'get_max_num_pages')) {
                self::advance_litespeed_sitemap_cursor($cursor, count($provider_keys), 1, 1);
                continue;
            }

            try {
                $subtypes_raw = (array) $provider->get_object_subtypes();
                $subtypes = !empty($subtypes_raw) ? array_keys($subtypes_raw) : array('');
                $subtype_index = (int) $cursor['subtypeIndex'] % count($subtypes);
                $subtype = (string) $subtypes[$subtype_index];
                $max_pages = max(1, (int) $provider->get_max_num_pages($subtype));
                $page = max(1, min($max_pages, (int) $cursor['page']));
                $url_list = (array) $provider->get_url_list($page, $subtype);
                $offset = max(0, (int) $cursor['offset']);

                if ($offset >= count($url_list)) {
                    self::advance_litespeed_sitemap_cursor($cursor, count($provider_keys), count($subtypes), $max_pages);
                    continue;
                }

                for ($index = $offset; $index < count($url_list) && $added < $limit; ++$index) {
                    $entry = is_array($url_list[$index] ?? null) ? $url_list[$index] : array();
                    if (self::merge_litespeed_refresh_candidate($registry, $entry['loc'] ?? '', 'sitemap')) {
                        ++$added;
                    }
                    $cursor['offset'] = $index + 1;
                }

                if ((int) $cursor['offset'] >= count($url_list)) {
                    self::advance_litespeed_sitemap_cursor($cursor, count($provider_keys), count($subtypes), $max_pages);
                }
            } catch (Throwable $throwable) {
                self::advance_litespeed_sitemap_cursor($cursor, count($provider_keys), 1, 1);
            }
        }

        $registry['cursor'] = $cursor;
        return $added;
    }

    private static function score_litespeed_refresh_candidate(array $candidate)
    {
        $source_weights = (array) ($candidate['sourceWeights'] ?? array());
        $base = empty($source_weights) ? 0 : max(array_map('intval', $source_weights));
        $source_bonus = max(0, count($source_weights) - 1) * 25;
        $observations = max(0, (int) ($candidate['observations'] ?? 0));
        $observation_bonus = min(150, (int) round(log($observations + 1, 2) * 20));
        $last_seen = max(0, (int) ($candidate['lastSeen'] ?? 0));
        $age = $last_seen > 0 ? max(0, time() - $last_seen) : self::get_litespeed_refresh_candidate_retention();
        $recency_bonus = max(0, 100 - (int) floor(100 * min(1, $age / self::get_litespeed_refresh_candidate_retention())));
        return $base + $source_bonus + $observation_bonus + $recency_bonus;
    }

    /**
     * Add bounded scheduling fairness without allowing high-priority candidates to starve the registry.
     *
     * @param array $candidate Candidate registry row.
     * @param int   $now       Current timestamp.
     * @return int
     */
    private static function score_litespeed_refresh_candidate_schedule(array $candidate, $now)
    {
        $priority = self::score_litespeed_refresh_candidate($candidate);
        $last_probed = max(0, (int) ($candidate['lastProbedAt'] ?? 0));
        if ($last_probed < 1) {
            return $priority + 10000;
        }

        $next_probe = max($last_probed, (int) ($candidate['nextProbeAt'] ?? 0));
        $overdue = max(0, (int) $now - $next_probe);
        $scan_interval = method_exists(static::class, 'get_litespeed_refresh_ahead_scan_interval')
            ? max(MINUTE_IN_SECONDS, (int) self::get_litespeed_refresh_ahead_scan_interval())
            : 5 * MINUTE_IN_SECONDS;
        $fairness_bonus = min(8000, (int) floor($overdue / $scan_interval) * 100);
        return $priority + $fairness_bonus;
    }

    /**
     * Persist bounded probe scheduling state in one non-autoloaded candidate-registry update.
     *
     * @param array $updates Probe updates keyed by URL or supplied as rows containing a URL.
     * @return int
     */
    private static function record_litespeed_refresh_candidate_probe_updates(array $updates)
    {
        if (empty($updates)) {
            return 0;
        }

        $registry = self::get_litespeed_refresh_candidate_registry();
        $updated = 0;
        foreach ($updates as $key => $update) {
            if (!is_array($update)) {
                continue;
            }
            $url = self::normalize_litespeed_refresh_candidate_url($update['url'] ?? (is_string($key) ? $key : ''));
            if ('' === $url) {
                continue;
            }
            $hash = sha1($url);
            if (!isset($registry['candidates'][$hash]) || !is_array($registry['candidates'][$hash])) {
                continue;
            }

            $candidate = $registry['candidates'][$hash];
            $increment_probe = !empty($update['incrementProbe']);
            if ($increment_probe) {
                $candidate['lastProbedAt'] = max(0, (int) ($update['lastProbedAt'] ?? time()));
                $candidate['probeCount'] = max(0, (int) ($candidate['probeCount'] ?? 0)) + 1;
            }
            $candidate['nextProbeAt'] = max(0, (int) ($update['nextProbeAt'] ?? ($candidate['nextProbeAt'] ?? 0)));
            $bucket = (string) ($update['lastProbeBucket'] ?? ($candidate['lastProbeBucket'] ?? ''));
            $candidate['lastProbeBucket'] = in_array($bucket, array('orig', 'webp', 'avif'), true) ? $bucket : '';
            $candidate['lastResult'] = sanitize_key((string) ($update['lastResult'] ?? ($candidate['lastResult'] ?? '')));
            if (array_key_exists('lastProbeAge', $update)) {
                $candidate['lastProbeAge'] = null !== $update['lastProbeAge']
                    ? max(0, (int) $update['lastProbeAge'])
                    : null;
            }
            $registry['candidates'][$hash] = $candidate;
            ++$updated;
        }

        if ($updated > 0) {
            self::save_litespeed_refresh_candidate_registry($registry);
        }
        return $updated;
    }

    /**
     * Return the most recently assigned probe bucket for one candidate URL.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    private static function get_litespeed_refresh_candidate_probe_bucket($url)
    {
        $url = self::normalize_litespeed_refresh_candidate_url($url);
        if ('' === $url) {
            return 'orig';
        }
        $registry = self::get_litespeed_refresh_candidate_registry();
        $candidate = is_array($registry['candidates'][sha1($url)] ?? null) ? $registry['candidates'][sha1($url)] : array();
        $bucket = (string) ($candidate['lastProbeBucket'] ?? '');
        return in_array($bucket, array('orig', 'webp', 'avif'), true) ? $bucket : 'orig';
    }

    private static function get_litespeed_refresh_candidate_summary()
    {
        $registry = self::get_litespeed_refresh_candidate_registry();
        $sources = array();
        foreach ((array) ($registry['candidates'] ?? array()) as $candidate) {
            foreach (array_keys((array) ($candidate['sourceWeights'] ?? array())) as $source) {
                $source = sanitize_key((string) $source);
                if ('' !== $source) {
                    $sources[$source] = max(0, (int) ($sources[$source] ?? 0)) + 1;
                }
            }
        }
        ksort($sources);
        return array(
            'count' => count((array) ($registry['candidates'] ?? array())),
            'sources' => $sources,
            'updatedAt' => max(0, (int) ($registry['updatedAt'] ?? 0)),
            'sitemapCursor' => is_array($registry['cursor'] ?? null) ? $registry['cursor'] : array(),
        );
    }

    private static function get_litespeed_refresh_ahead_candidates($limit = 20, $due_only = true)
    {
        if (
            !method_exists(static::class, 'is_litespeed_refresh_ahead_runtime_active')
            || !self::is_litespeed_refresh_ahead_runtime_active()
        ) {
            return array();
        }

        $limit = max(1, min(50, absint($limit)));
        $due_only = (bool) $due_only;
        $registry = self::get_litespeed_refresh_candidate_registry();
        $settings = self::get_dashboard_settings();
        self::seed_litespeed_refresh_static_candidates($registry, $settings);
        self::import_litespeed_refresh_analytics_candidates($registry);
        self::discover_litespeed_sitemap_candidates($registry, max(5, min(15, $limit * 2)));

        $now = time();
        $rows = array();
        foreach ((array) ($registry['candidates'] ?? array()) as $hash => $candidate) {
            if ($due_only && max(0, (int) ($candidate['nextProbeAt'] ?? 0)) > $now) {
                continue;
            }
            $candidate['priorityScore'] = self::score_litespeed_refresh_candidate($candidate);
            $candidate['scheduleScore'] = $due_only
                ? self::score_litespeed_refresh_candidate_schedule($candidate, $now)
                : $candidate['priorityScore'];
            $candidate['sources'] = array_keys((array) ($candidate['sourceWeights'] ?? array()));
            $candidate['hash'] = (string) $hash;
            $rows[] = $candidate;
        }
        usort($rows, static function ($left, $right) use ($due_only) {
            $score_compare = (int) ($right['scheduleScore'] ?? 0) <=> (int) ($left['scheduleScore'] ?? 0);
            if (0 !== $score_compare) {
                return $score_compare;
            }
            if ($due_only) {
                $next_compare = (int) ($left['nextProbeAt'] ?? 0) <=> (int) ($right['nextProbeAt'] ?? 0);
                if (0 !== $next_compare) {
                    return $next_compare;
                }
                $probe_compare = (int) ($left['lastProbedAt'] ?? 0) <=> (int) ($right['lastProbedAt'] ?? 0);
                if (0 !== $probe_compare) {
                    return $probe_compare;
                }
            }
            $priority_compare = (int) ($right['priorityScore'] ?? 0) <=> (int) ($left['priorityScore'] ?? 0);
            if (0 !== $priority_compare) {
                return $priority_compare;
            }
            return (int) ($right['lastSeen'] ?? 0) <=> (int) ($left['lastSeen'] ?? 0);
        });

        $all_rows = array();
        foreach ((array) ($registry['candidates'] ?? array()) as $hash => $candidate) {
            $candidate['priorityScore'] = self::score_litespeed_refresh_candidate($candidate);
            $candidate['hash'] = (string) $hash;
            $all_rows[] = $candidate;
        }
        usort($all_rows, static function ($left, $right) {
            $score_compare = (int) ($right['priorityScore'] ?? 0) <=> (int) ($left['priorityScore'] ?? 0);
            if (0 !== $score_compare) {
                return $score_compare;
            }
            return (int) ($right['lastSeen'] ?? 0) <=> (int) ($left['lastSeen'] ?? 0);
        });

        $registry['candidates'] = array();
        foreach (array_slice($all_rows, 0, self::get_litespeed_refresh_candidate_cap()) as $row) {
            $hash = (string) ($row['hash'] ?? sha1((string) ($row['url'] ?? '')));
            unset($row['priorityScore'], $row['scheduleScore'], $row['sources'], $row['hash']);
            $registry['candidates'][$hash] = $row;
        }
        self::save_litespeed_refresh_candidate_registry($registry);

        return array_slice($rows, 0, $limit);
    }


}
