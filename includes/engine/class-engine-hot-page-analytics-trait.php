<?php
/**
 * Bounded hot-page candidate tracking for Varnish refresh-ahead.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Hot_Page_Analytics_Trait
{
    /**
     * Maximum URL candidates retained inside the existing analytics summary.
     *
     * @return int
     */
    private static function get_hot_page_candidate_cap()
    {
        $cap = (int) apply_filters('ultracache_hot_page_candidate_cap', 100);
        return max(20, min(250, $cap));
    }

    /**
     * Normalize and bound hot-page candidate rows.
     *
     * These observations represent requests that reached UltraCache's WordPress
     * page-cache engine. Varnish-only HITs are intentionally not logged through
     * a frontend beacon because that would add one backend request per page view.
     *
     * @param mixed $candidates Candidate payload.
     * @return array
     */
    private static function normalize_hot_page_candidates($candidates)
    {
        if (!is_array($candidates)) {
            return array();
        }

        $normalized = array();
        foreach ($candidates as $hash => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = isset($candidate['url']) ? esc_url_raw((string) $candidate['url']) : '';
            if ('' === $url || !function_exists('ultracache_is_trusted_loopback_url') || !ultracache_is_trusted_loopback_url($url)) {
                continue;
            }

            $key = preg_match('/\A[a-f0-9]{40}\z/', (string) $hash) ? (string) $hash : sha1($url);
            $normalized[$key] = array(
                'url' => $url,
                'observations' => max(0, min(PHP_INT_MAX, (int) ($candidate['observations'] ?? 0))),
                'lastSeen' => max(0, (int) ($candidate['lastSeen'] ?? 0)),
                'firstSeen' => max(0, (int) ($candidate['firstSeen'] ?? 0)),
            );
        }

        uasort($normalized, static function ($left, $right) {
            $left_observations = (int) ($left['observations'] ?? 0);
            $right_observations = (int) ($right['observations'] ?? 0);
            if ($left_observations === $right_observations) {
                return (int) ($right['lastSeen'] ?? 0) <=> (int) ($left['lastSeen'] ?? 0);
            }
            return $right_observations <=> $left_observations;
        });

        return array_slice($normalized, 0, self::get_hot_page_candidate_cap(), true);
    }

    /**
     * Add one cacheable frontend observation to the bounded candidate payload.
     *
     * @param array  $analytics Analytics summary.
     * @param string $url       Public local URL.
     * @return array
     */
    private static function apply_hot_page_observation(array $analytics, $url)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url || !function_exists('ultracache_is_trusted_loopback_url') || !ultracache_is_trusted_loopback_url($url)) {
            return $analytics;
        }

        $candidates = self::normalize_hot_page_candidates($analytics['hotPages'] ?? array());
        $hash = sha1($url);
        $now = time();
        $existing = is_array($candidates[$hash] ?? null) ? $candidates[$hash] : array();
        $candidates[$hash] = array(
            'url' => $url,
            'observations' => max(0, (int) ($existing['observations'] ?? 0)) + 1,
            'lastSeen' => $now,
            'firstSeen' => !empty($existing['firstSeen']) ? (int) $existing['firstSeen'] : $now,
        );
        $analytics['hotPages'] = self::normalize_hot_page_candidates($candidates);

        return $analytics;
    }

    /**
     * Return recently observed local pages ordered by a recency-weighted score.
     *
     * @param int $limit Maximum rows.
     * @return array
     */
    public static function get_hot_page_candidates($limit = 20)
    {
        $limit = max(1, min(50, absint($limit)));
        if (!method_exists(static::class, 'read_analytics') || !method_exists(static::class, 'analytics_enabled') || !self::analytics_enabled()) {
            return array();
        }

        $analytics = self::read_analytics();
        $candidates = self::normalize_hot_page_candidates($analytics['hotPages'] ?? array());
        $now = time();
        $retention = (int) apply_filters('ultracache_hot_page_candidate_retention_seconds', 14 * DAY_IN_SECONDS);
        $retention = max(DAY_IN_SECONDS, min(90 * DAY_IN_SECONDS, $retention));
        $result = array();

        foreach ($candidates as $candidate) {
            $last_seen = max(0, (int) ($candidate['lastSeen'] ?? 0));
            if ($last_seen < 1 || ($now - $last_seen) > $retention) {
                continue;
            }

            $observations = max(1, (int) ($candidate['observations'] ?? 1));
            $age_ratio = min(1, max(0, ($now - $last_seen) / $retention));
            $score = round($observations * (1 - (0.75 * $age_ratio)), 4);
            $candidate['score'] = $score;
            $result[] = $candidate;
        }

        usort($result, static function ($left, $right) {
            $left_score = (float) ($left['score'] ?? 0);
            $right_score = (float) ($right['score'] ?? 0);
            if ($left_score === $right_score) {
                return (int) ($right['lastSeen'] ?? 0) <=> (int) ($left['lastSeen'] ?? 0);
            }
            return $right_score <=> $left_score;
        });

        return array_slice($result, 0, $limit);
    }
}
