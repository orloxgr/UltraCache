<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Rest_Helpers_Trait
{
    private function unwrap_rest_payload($response)
    {
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            $status = (int) $response->get_status();
            $payload = is_array($data) ? $data : array('data' => $data);
            if (!array_key_exists('success', $payload)) {
                $payload['success'] = $status >= 200 && $status < 300;
            }
            return $payload;
        }

        return is_array($response) ? $response : array('success' => (bool) $response);
    }

    private function get_engine()
    {
        foreach (array('Ultra_Cache_Engine') as $class) {
            if (class_exists($class) && method_exists($class, 'get_instance')) {
                return call_user_func(array($class, 'get_instance'));
            }
        }

        return null;
    }

    private function get_media()
    {
        foreach (array('Ultra_Cache_Media_Converter') as $class) {
            if (class_exists($class) && method_exists($class, 'get_instance')) {
                return call_user_func(array($class, 'get_instance'));
            }
        }

        return null;
    }


    /**
     * Paginate one complete in-memory item set.
     *
     * Callers must pass the full global set, never an already paginated page.
     * The total is derived here so page contents and pagination metadata cannot
     * describe different datasets.
     *
     * @param array $items  Complete global item set.
     * @param int   $offset Requested global offset.
     * @param int   $limit  Requested page size.
     * @return array{items: array, total: int, offset: int, limit: int, nextOffset: int, hasMore: bool}
     */
    private function paginate_complete_item_set(array $items, $offset, $limit)
    {
        $items = array_values($items);
        $total = count($items);
        $offset = min($total, max(0, (int) $offset));
        $limit = max(1, min(500, (int) $limit));
        $sliced = array_slice($items, $offset, $limit);
        $next_offset = min($total, $offset + count($sliced));

        return array(
            'items'      => $sliced,
            'total'      => $total,
            'offset'     => $offset,
            'limit'      => $limit,
            'nextOffset' => $next_offset,
            'hasMore'    => $next_offset < $total,
        );
    }

    private function resolve_engine_stats($full_object_count = false)
    {
        $stats = array();
        $engine = $this->get_engine();
        if ($engine && method_exists($engine, 'get_stats')) {
            $stats = $engine::get_stats();
            $stats = is_array($stats) ? $stats : array();
        }

        if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
            $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats((bool) $full_object_count);
            if (is_array($object_stats)) {
                $stats = array_merge($stats, $object_stats);
                $stats['cacheSizeBytes'] = (int) ($stats['cacheSizeBytes'] ?? 0) + (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                if (function_exists('size_format')) {
                    $stats['cacheSizeHuman'] = size_format((int) $stats['cacheSizeBytes'], 2);
                }
            }
        }

        if (class_exists('Ultra_Cache_WP')) {
            if (method_exists('Ultra_Cache_WP', 'get_opcache_status_summary')) {
                $stats['opcache'] = Ultra_Cache_WP::get_opcache_status_summary();
            }
            if (method_exists('Ultra_Cache_WP', 'get_apcu_status_summary')) {
                $stats['apcu'] = Ultra_Cache_WP::get_apcu_status_summary();
            }
            if (method_exists('Ultra_Cache_WP', 'get_external_cache_detection')) {
                $stats['externalCaches'] = Ultra_Cache_WP::get_external_cache_detection(false);
            }
        }

        return $stats;
    }
}
