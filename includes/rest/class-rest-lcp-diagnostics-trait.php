<?php
/**
 * REST actions for browser-observed LCP diagnostics.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Rest_LCP_Diagnostics_Trait
{
    /**
     * Return one server-side filtered page of LCP observations.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function lcp_observations_query(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_lcp_observation_diagnostics_list')) {
            return new WP_Error('ultracache_lcp_diagnostics_unavailable', __('LCP observation diagnostics are unavailable.', 'ultracache'), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success'         => true,
            'lcpObservations' => Ultra_Cache_WP::get_lcp_observation_diagnostics_list($this->get_lcp_observation_query_args($request)),
        ));
    }

    /**
     * Return full mapping details only for one selected URL.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function lcp_observation_detail(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_lcp_observation_diagnostics_detail')) {
            return new WP_Error('ultracache_lcp_diagnostics_unavailable', __('LCP observation details are unavailable.', 'ultracache'), array('status' => 500));
        }

        $page_hash = strtolower(trim((string) $request->get_param('pageHash')));
        if (!preg_match('/^[a-f0-9]{64}$/', $page_hash)) {
            return new WP_Error('ultracache_invalid_lcp_page_hash', __('Invalid LCP page identifier.', 'ultracache'), array('status' => 400));
        }

        $detail = Ultra_Cache_WP::get_lcp_observation_diagnostics_detail($page_hash);
        if (empty($detail['available'])) {
            return new WP_Error('ultracache_lcp_page_missing', __('The selected LCP URL no longer exists.', 'ultracache'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'success'   => true,
            'lcpDetail' => $detail,
        ));
    }

    public function lcp_observation_action(WP_REST_Request $request)
    {
        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return new WP_Error('ultracache_lcp_engine_unavailable', __('The LCP engine is unavailable.', 'ultracache'), array('status' => 500));
        }

        $engine = Ultra_Cache_Engine::get_instance();
        if (!$engine || !method_exists($engine, 'perform_lcp_observation_admin_action')) {
            return new WP_Error('ultracache_lcp_action_unavailable', __('LCP diagnostics actions are unavailable.', 'ultracache'), array('status' => 500));
        }

        $action = sanitize_key((string) $request->get_param('action'));
        $result = $engine->perform_lcp_observation_admin_action(
            absint($request->get_param('recordId')),
            $action
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Extract the shared LCP diagnostics list arguments from a REST request.
     *
     * @param WP_REST_Request $request REST request.
     * @return array<string,mixed>
     */
    private function get_lcp_observation_query_args(WP_REST_Request $request)
    {
        return array(
            'search'         => sanitize_text_field((string) $request->get_param('search')),
            'cursor'         => sanitize_text_field((string) $request->get_param('cursor')),
            'includeSummary' => (bool) $request->get_param('includeSummary'),
        );
    }
}
