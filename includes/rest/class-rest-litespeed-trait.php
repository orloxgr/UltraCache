<?php
/**
 * Signed internal LiteSpeed control REST endpoint.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Rest_LiteSpeed_Trait
{
    /**
     * Verify the unauthenticated same-site LiteSpeed control request.
     *
     * @param WP_REST_Request $request REST request.
     * @return true|WP_Error
     */
    public function check_litespeed_control_permission(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'verify_litespeed_control_request')) {
            return new WP_Error(
                'ultracache_litespeed_control_unavailable',
                __('LiteSpeed control endpoint is unavailable.', 'ultracache'),
                array('status' => 503)
            );
        }

        $urls = $request->get_param('urls');
        $urls = is_array($urls) ? $urls : array();
        $allowed = Ultra_Cache_WP::verify_litespeed_control_request(
            (string) $request->get_param('operation'),
            $urls,
            (string) $request->get_param('requestId'),
            absint($request->get_param('expires')),
            (string) $request->get_param('signature')
        );

        if ($allowed) {
            return true;
        }

        return new WP_Error(
            'ultracache_litespeed_control_forbidden',
            __('Invalid, expired, or replayed LiteSpeed control request.', 'ultracache'),
            array('status' => 403)
        );
    }

    /**
     * Return the response header consumed by the LiteSpeed cache engine.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function litespeed_control(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_litespeed_control_response')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('LiteSpeed control endpoint is unavailable.', 'ultracache'),
            ), 503);
        }

        $urls = $request->get_param('urls');
        $urls = is_array($urls) ? $urls : array();
        $result = Ultra_Cache_WP::get_litespeed_control_response(
            (string) $request->get_param('operation'),
            $urls
        );
        $response = new WP_REST_Response($result, !empty($result['success']) ? 200 : 400);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');

        if (!empty($result['success']) && !empty($result['purgeHeader'])) {
            $response->header('X-LiteSpeed-Purge', (string) $result['purgeHeader']);
            $response->header('X-UltraCache-LiteSpeed-Purge', '1');
            $response->header('X-UltraCache-LiteSpeed-Operation', sanitize_key((string) ($result['operation'] ?? '')));
        }

        return $response;
    }

    /**
     * Run the canonical LiteSpeed public cache behavior test.
     *
     * @return WP_REST_Response
     */
    public function litespeed_behavior_test()
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_litespeed_behavior_test')) {
            return new WP_REST_Response(array(
                'success' => false,
                'status' => 'runner-unavailable',
                'message' => __('LiteSpeed behavior test helper is unavailable.', 'ultracache'),
            ), 500);
        }

        $result = Ultra_Cache_WP::run_litespeed_behavior_test();
        if (method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
            $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
        }
        if (method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
            $result['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
        }
        if (method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
            $result['stats'] = Ultra_Cache_WP::get_engine_stats();
        }
        if (method_exists('Ultra_Cache_WP', 'get_external_cache_detection')) {
            $result['externalCaches'] = Ultra_Cache_WP::get_external_cache_detection(true);
        }

        return new WP_REST_Response($result, 200);
    }
}
