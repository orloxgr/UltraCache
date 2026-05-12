<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Media_Trait')) {
    trait Ultra_Cache_Rest_Media_Trait
    {
        public function get_media_ids(WP_REST_Request $request)
        {
            $media = $this->get_media();
            $offset = max(0, absint($request->get_param('offset')));
            $limit = max(1, min(500, absint($request->get_param('limit')) ?: 100));

            if (!$media) {
                return new WP_REST_Response($this->format_batch_response(array(), 0, $offset, $limit), 200);
            }

            if (method_exists($media, 'get_media_queue_batch')) {
                return new WP_REST_Response($media->get_media_queue_batch($offset, $limit, 'best', true), 200);
            }

            if (!method_exists($media, 'get_all_media_ids')) {
                return new WP_REST_Response($this->format_batch_response(array(), 0, $offset, $limit), 200);
            }

            if (method_exists($media, 'get_media_ids_batch')) {
                return new WP_REST_Response($media->get_media_ids_batch($offset, $limit), 200);
            }

            $all_ids = (array) $media->get_all_media_ids();
            return new WP_REST_Response($this->format_batch_response($all_ids, count($all_ids), $offset, $limit), 200);
        }

        public function optimize_id(WP_REST_Request $request)
        {
            $attachment_id = absint($request->get_param('id'));
            if ($attachment_id <= 0) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No valid media ID.'), 400);
            }

            $media = $this->get_media();
            if (!$media) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            if (method_exists($media, 'process_queued_attachment')) {
                $result = $media->process_queued_attachment($attachment_id, 'best', true);
                return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
            }

            if (!method_exists($media, 'to_avif_by_id')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            $converted = (bool) $media->to_avif_by_id($attachment_id);
            return new WP_REST_Response(array('success' => true, 'converted' => $converted), 200);
        }

        public function optimize_media()
        {
            $media = $this->get_media();
            if (!$media) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            if (method_exists($media, 'process_media_queue_batch')) {
                return new WP_REST_Response($media->process_media_queue_batch(array('limit' => 5, 'format' => 'best', 'only_missing' => true, 'time_budget' => 20)), 200);
            }

            if (!method_exists($media, 'bulk_optimize')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            $media->bulk_optimize();
            return new WP_REST_Response(array('success' => true), 200);
        }

        private function get_media_queue_format_from_request(WP_REST_Request $request)
        {
            $format = sanitize_key((string) ($request->get_param('media_format') ?: 'best'));
            return in_array($format, array('best', 'avif', 'webp', 'both'), true) ? $format : 'best';
        }

        public function media_queue_status(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'get_media_queue_status')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue is not available.'), 500);
            }

            $refresh_storage = rest_sanitize_boolean($request->get_param('refresh_storage'));
            $status = $media->get_media_queue_status($this->get_media_queue_format_from_request($request), (bool) $refresh_storage);
            if ($refresh_storage && method_exists($media, 'get_stats')) {
                $status['storageStats'] = $media->get_stats(true);
            }

            return new WP_REST_Response($status, 200);
        }

        public function media_queue_rebuild(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'rebuild_media_conversion_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue rebuild is not available.'), 500);
            }

            $limit = max(0, absint($request->get_param('limit')));
            return new WP_REST_Response($media->rebuild_media_conversion_queue($this->get_media_queue_format_from_request($request), true, $limit), 200);
        }

        public function media_queue_process(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'process_media_queue_batch')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue processing is not available.'), 500);
            }

            $limit = max(1, min(100, absint($request->get_param('limit')) ?: 5));
            $time_budget = max(0, min(60, absint($request->get_param('time_budget')) ?: 20));
            return new WP_REST_Response($media->process_media_queue_batch(array(
                'limit' => $limit,
                'format' => $this->get_media_queue_format_from_request($request),
                'only_missing' => true,
                'time_budget' => $time_budget,
            )), 200);
        }

        public function media_queue_repair(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'repair_media_conversion_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue repair is not available.'), 500);
            }

            return new WP_REST_Response($media->repair_media_conversion_queue($this->get_media_queue_format_from_request($request)), 200);
        }

        public function media_queue_retry_failed(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'retry_failed_media_queue_items')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue retry is not available.'), 500);
            }

            return new WP_REST_Response($media->retry_failed_media_queue_items($this->get_media_queue_format_from_request($request)), 200);
        }

        public function media_queue_clear_completed(WP_REST_Request $request)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'clear_completed_media_queue_items')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media queue cleanup is not available.'), 500);
            }

            return new WP_REST_Response($media->clear_completed_media_queue_items($this->get_media_queue_format_from_request($request)), 200);
        }

    }
}
