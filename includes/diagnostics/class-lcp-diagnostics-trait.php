<?php
/**
 * Browser-observed LCP diagnostics exposed to the dashboard.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LCP_Diagnostics_Trait
{

    /**
     * Return a compact, URL-grouped LCP diagnostics page.
     *
     * @param array<string,mixed> $query List query.
     * @return array<string,mixed>
     */
    public static function get_lcp_observation_diagnostics_list($query = array())
    {
        $fallback = array(
            'available' => false,
            'message'   => __('LCP observation diagnostics are unavailable.', 'ultracache'),
            'summary'   => array(
                'learnedPages'          => 0,
                'confirmedMappings'     => 0,
                'learningMappings'      => 0,
                'pendingRefreshes'      => 0,
                'failedRefreshes'       => 0,
                'staleMappings'         => 0,
                'lastObservationAt'     => 0,
                'lastSuccessfulRefresh' => array(),
            ),
            'urls'       => array(),
            'query'      => is_array($query) ? $query : array(),
            'pagination' => array('perPage' => 10, 'returned' => 0, 'hasMore' => false, 'nextCursor' => ''),
        );

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return $fallback;
        }

        try {
            $engine = Ultra_Cache_Engine::get_instance();
            if (!$engine || !method_exists($engine, 'get_lcp_observation_diagnostics_list_snapshot')) {
                return $fallback;
            }

            $snapshot = $engine->get_lcp_observation_diagnostics_list_snapshot(is_array($query) ? $query : array());
            return is_array($snapshot) ? array_merge($fallback, $snapshot) : $fallback;
        } catch (Throwable $e) {
            return $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }

    /**
     * Return full LCP mapping details for one stored page hash.
     *
     * @param string $page_hash Stored SHA-256 page hash.
     * @return array<string,mixed>
     */
    public static function get_lcp_observation_diagnostics_detail($page_hash)
    {
        $fallback = array(
            'available' => false,
            'message'   => __('LCP observation details are unavailable.', 'ultracache'),
            'pageHash'       => '',
            'pageUrl'        => '',
            'manualSelector' => '',
            'mappings'       => array(),
        );

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return $fallback;
        }

        try {
            $engine = Ultra_Cache_Engine::get_instance();
            if (!$engine || !method_exists($engine, 'get_lcp_observation_diagnostics_detail_snapshot')) {
                return $fallback;
            }

            $snapshot = $engine->get_lcp_observation_diagnostics_detail_snapshot($page_hash);
            return is_array($snapshot) ? array_merge($fallback, $snapshot) : $fallback;
        } catch (Throwable $e) {
            return $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }

    /**
     * Return the current browser-observed LCP mapping summary and recent rows.
     *
     * @return array<string,mixed>
     */
    public static function get_lcp_observation_diagnostics($query = array())
    {
        $fallback = array(
            'available' => false,
            'message'   => __('LCP observation diagnostics are unavailable.', 'ultracache'),
            'summary'   => array(
                'learnedPages'          => 0,
                'allMappings'           => 0,
                'attentionMappings'     => 0,
                'confirmedMappings'     => 0,
                'pendingObservations'   => 0,
                'pendingRefreshes'      => 0,
                'failedRefreshes'       => 0,
                'staleMappings'         => 0,
                'lastObservationAt'     => 0,
                'lastSuccessfulRefresh' => array(),
            ),
            'records'   => array(),
            'query'     => is_array($query) ? $query : array(),
            'pagination'=> array('perPage' => 20, 'returned' => 0, 'totalFiltered' => 0, 'hasMore' => false, 'nextCursor' => ''),
        );

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return $fallback;
        }

        try {
            $engine = Ultra_Cache_Engine::get_instance();
            if (!$engine || !method_exists($engine, 'get_lcp_observation_diagnostics_snapshot')) {
                return $fallback;
            }

            $snapshot = $engine->get_lcp_observation_diagnostics_snapshot(is_array($query) ? $query : array());
            if (!is_array($snapshot)) {
                return $fallback;
            }

            return array_merge($fallback, $snapshot);
        } catch (Throwable $e) {
            return $fallback;
        } catch (Exception $e) {
            return $fallback;
        }
    }
}
