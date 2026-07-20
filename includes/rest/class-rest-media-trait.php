<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Rest_Media_Trait
{
    private function media_library_replacement_runner_unavailable_response($extra = array())
    {
        $extra = is_array($extra) ? $extra : array();
        return new WP_REST_Response(array_merge(array(
            'success'              => false,
            'blocked'              => true,
            'status'               => 'replacement_runner_unavailable',
            'orchestrationVersion' => 2,
            'message'              => __('The legacy Media Library replacement mutation endpoints are disabled while the shared resumable runner is connected.', 'ultracache'),
        ), $extra), 409);
    }

    public function get_media_ids(WP_REST_Request $request)
    {
        $media = $this->get_media();
        $offset = max(0, absint($request->get_param('offset')));
        $limit = max(1, min(500, absint($request->get_param('limit')) ?: 100));

        if (!$media) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media converter not available.', 'ultracache')), 503);
        }

        if (method_exists($media, 'get_media_queue_batch')) {
            return new WP_REST_Response($media->get_media_queue_batch($offset, $limit, 'best', true), 200);
        }

        if (method_exists($media, 'get_media_ids_batch')) {
            return new WP_REST_Response($media->get_media_ids_batch($offset, $limit), 200);
        }

        return new WP_REST_Response(array('success' => false, 'message' => __('Media queue is not available.', 'ultracache')), 503);
    }

    public function optimize_id(WP_REST_Request $request)
    {
        $attachment_id = absint($request->get_param('id'));
        if ($attachment_id <= 0) {
            return new WP_REST_Response(array('success' => false, 'message' => __('No valid media ID.', 'ultracache')), 400);
        }

        $media = $this->get_media();
        if (!$media) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media converter not available.', 'ultracache')), 500);
        }

        if (method_exists($media, 'process_queued_attachment')) {
            $result = $media->process_queued_attachment(
                $attachment_id,
                'best',
                true,
                '',
                sanitize_text_field((string) $request->get_param('manual_token')),
                rest_sanitize_boolean($request->get_param('force_regenerate'))
            );
            return new WP_REST_Response($result, $this->get_media_queue_result_http_status($result));
        }

        if (!method_exists($media, 'to_avif_by_id')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media converter not available.', 'ultracache')), 500);
        }

        $converted = (bool) $media->to_avif_by_id($attachment_id);
        return new WP_REST_Response(array('success' => true, 'converted' => $converted), 200);
    }

    public function optimize_media()
    {
        $media = $this->get_media();
        if (!$media) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media converter not available.', 'ultracache')), 500);
        }

        if (method_exists($media, 'process_media_queue_batch')) {
            $result = $media->process_media_queue_batch(
                array(
                    'limit'        => 1,
                    'format'       => 'best',
                    'only_missing' => true,
                    'time_budget'  => 8,
                )
            );
            return new WP_REST_Response($result, $this->get_media_queue_result_http_status($result));
        }

        return new WP_REST_Response(array('success' => false, 'message' => __('Media queue is not available.', 'ultracache')), 503);
    }

    public function check_media_background_worker_permission(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'verify_background_generation_worker_request')) {
            return new WP_Error(
                'ultracache_media_worker_unavailable',
                __('Media background worker is unavailable.', 'ultracache'),
                array('status' => 503)
            );
        }

        $allowed = $media->verify_background_generation_worker_request(
            (string) $request->get_param('token'),
            absint($request->get_param('expires')),
            (string) $request->get_param('signature')
        );

        if ($allowed) {
            return true;
        }

        return new WP_Error(
            'ultracache_media_worker_forbidden',
            __('Invalid or expired media background worker request.', 'ultracache'),
            array('status' => 403)
        );
    }

    public function media_background_worker(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'handle_background_generation_worker')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'reason'  => 'worker_unavailable',
                ),
                503
            );
        }

        $result = $media->handle_background_generation_worker((string) $request->get_param('token'));
        $status = !empty($result['success']) ? 200 : 409;
        if ('disabled' === (string) ($result['reason'] ?? '')) {
            $status = 503;
        }

        return new WP_REST_Response($result, $status);
    }

    private function get_media_queue_result_http_status(array $result)
    {
        if (!empty($result['success'])) {
            return 200;
        }

        $reason = (string) ($result['reason'] ?? $result['pauseReason'] ?? '');
        if (in_array($reason, array('locked', 'already_claimed', 'background_paused', 'manual_session_active', 'manual_session_lost'), true)) {
            return 409;
        }
        if (
            in_array($reason, array('conversion_failed', 'retry_limit'), true)
            || 'failed' === (string) ($result['queueStatus'] ?? '')
            || (int) ($result['failedThisRun'] ?? 0) > 0
        ) {
            return 422;
        }

        return 500;
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
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue is not available.', 'ultracache')), 500);
        }

        $recount_files = rest_sanitize_boolean($request->get_param('recount_files'));
        $status = $media->get_media_queue_status($this->get_media_queue_format_from_request($request));
        if ($recount_files && method_exists($media, 'recount_media_files')) {
            $status['mediaFileCounts'] = $media->recount_media_files();
        } elseif (method_exists($media, 'get_media_file_counts')) {
            $status['mediaFileCounts'] = $media->get_media_file_counts();
        }

        return new WP_REST_Response($status, 200);
    }

    public function media_queue_rebuild(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'rebuild_media_conversion_queue')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue rebuild is not available.', 'ultracache')), 500);
        }

        $limit = max(0, absint($request->get_param('limit')));
        $time_budget = max(0, min(120, absint($request->get_param('time_budget')) ?: 20));
        $reset = rest_sanitize_boolean($request->get_param('reset'));
        $generation = sanitize_text_field((string) $request->get_param('generation'));
        $result = $media->rebuild_media_conversion_queue(
            $this->get_media_queue_format_from_request($request),
            true,
            $limit,
            array(
                'reset' => $reset,
                'time_budget' => $time_budget,
                'generation' => $generation,
            )
        );

        $status = 200;
        if (!empty($result['locked']) || !empty($result['staleGeneration'])) {
            $status = 409;
        } elseif (empty($result['success'])) {
            $status = 500;
        }

        return new WP_REST_Response($result, $status);
    }

    public function media_queue_process(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'process_media_queue_batch')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue processing is not available.', 'ultracache')), 500);
        }

        $limit = 1;
        $time_budget = max(1, min(15, absint($request->get_param('time_budget')) ?: 8));
        $result = $media->process_media_queue_batch(array(
            'limit' => $limit,
            'format' => $this->get_media_queue_format_from_request($request),
            'only_missing' => true,
            'time_budget' => $time_budget,
        ));
        return new WP_REST_Response($result, $this->get_media_queue_result_http_status($result));
    }

    public function media_manual_session_control(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'begin_manual_media_conversion_session')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Dashboard media-session control is not available.', 'ultracache')), 500);
        }

        $action = sanitize_key((string) $request->get_param('session_action'));
        $token = sanitize_text_field((string) $request->get_param('token'));
        if ('start' === $action) {
            $result = $media->begin_manual_media_conversion_session($token);
        } elseif ('renew' === $action) {
            $result = $media->renew_manual_media_conversion_session($token);
        } elseif ('stop' === $action) {
            $result = $media->end_manual_media_conversion_session($token);
        } else {
            return new WP_REST_Response(array('success' => false, 'message' => __('Invalid dashboard media-session action.', 'ultracache')), 400);
        }

        if (method_exists($media, 'get_media_queue_status')) {
            $result = array_merge(
                $media->get_media_queue_status($this->get_media_queue_format_from_request($request)),
                $result
            );
        }

        $status = !empty($result['success']) ? 200 : 409;
        if ('stop' === $action && 'manual_session_lost' === (string) ($result['reason'] ?? '')) {
            $status = 200;
        }
        return new WP_REST_Response($result, $status);
    }

    public function media_background_work_control(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'set_media_background_work_paused')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media background control is not available.', 'ultracache')), 500);
        }

        $result = $media->set_media_background_work_paused(
            rest_sanitize_boolean($request->get_param('paused'))
        );
        if (method_exists($media, 'get_media_queue_status')) {
            $result = array_merge(
                $media->get_media_queue_status($this->get_media_queue_format_from_request($request)),
                $result
            );
        }

        return new WP_REST_Response($result, 200);
    }


    public function media_conversion_test_latest(WP_REST_Request $request = null)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_conversion_test_report')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Image conversion test is not available.', 'ultracache')), 500);
        }

        return new WP_REST_Response($media->get_media_library_conversion_test_report(), 200);
    }

    public function media_conversion_test_run(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'run_media_library_conversion_test')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Image conversion test is not available.', 'ultracache')), 500);
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'reset_settings_cache')) {
            Ultra_Cache_WP::reset_settings_cache();
        }

        $result = $media->run_media_library_conversion_test();
        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 404);
    }

    public function media_library_replacement_status(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_workflow_status')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement status is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_workflow_status(array(
            'job_id' => (string) $request->get_param('jobId'),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }



    public function media_library_replacement_session(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'manage_media_library_replacement_session')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement session management is not available.', 'ultracache')), 500);
        }

        $result = $media->manage_media_library_replacement_session(
            (string) $request->get_param('action'),
            (string) $request->get_param('token'),
            (string) $request->get_param('activeStep')
        );
        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 410);
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_restart(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'restart_media_library_replacement_workflow')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement restart is not available.', 'ultracache')), 500);
        }

        $result = $media->restart_media_library_replacement_workflow();
        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_readiness_status(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_readiness_status')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement readiness is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_readiness_status();
        if (method_exists($media, 'get_media_library_replacement_start_guard')) {
            $result['startGuard'] = $media->get_media_library_replacement_start_guard();
        }
        return new WP_REST_Response($result, 200);
    }

    public function media_library_replacement_readiness_scan(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'scan_media_library_replacement_readiness_inventory')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement readiness is not available.', 'ultracache')), 500);
        }

        $result = $media->scan_media_library_replacement_readiness_inventory(array(
            'reset'       => rest_sanitize_boolean($request->get_param('reset')),
            'limit'       => absint($request->get_param('limit')),
            'time_budget' => (float) $request->get_param('time_budget'),
        ));

        if (method_exists($media, 'get_media_library_replacement_start_guard')) {
            $result['startGuard'] = $media->get_media_library_replacement_start_guard();
        }
        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }

    public function media_library_replacement_workflow_stage(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'set_media_library_replacement_workflow_stage')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement workflow stage is not available.', 'ultracache')), 500);
        }

        $result = $media->set_media_library_replacement_workflow_stage(array(
            'job_id'  => (string) $request->get_param('jobId'),
            'stage'   => (string) $request->get_param('stage'),
            'message' => (string) $request->get_param('message'),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function media_library_replacement_prepare(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'run_media_library_replacement_prepare_chunk')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement Prepare runner is not available.', 'ultracache')), 500);
        }

        $result = $media->run_media_library_replacement_prepare_chunk(array(
            'reset'                => rest_sanitize_boolean($request->get_param('reset')),
            'readiness_generation' => (string) $request->get_param('readinessGeneration'),
            'session_token'        => (string) $request->get_param('sessionToken'),
            'limit'                => absint($request->get_param('limit')),
            'time_budget'          => (float) $request->get_param('time_budget'),
        ));

        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }



    public function media_library_replacement_do(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'run_media_library_replacement_do_chunk')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement Do runner is not available.', 'ultracache')), 500);
        }

        $result = $media->run_media_library_replacement_do_chunk(array(
            'session_token' => (string) $request->get_param('sessionToken'),
            'limit'         => absint($request->get_param('limit')),
            'time_budget'   => (float) $request->get_param('time_budget'),
        ));

        $status = !empty($result['success'])
            ? 200
            : (isset($result['httpStatus']) ? max(400, min(599, absint($result['httpStatus']))) : (!empty($result['blocked']) ? 409 : 500));
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_verify(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'run_media_library_replacement_verify_chunk')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement Verify runner is not available.', 'ultracache')), 500);
        }

        $result = $media->run_media_library_replacement_verify_chunk(array(
            'session_token' => (string) $request->get_param('sessionToken'),
            'limit'         => absint($request->get_param('limit')),
            'time_budget'   => (float) $request->get_param('time_budget'),
        ));

        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_delete_confirm(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'confirm_media_library_replacement_delete')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement Delete Originals confirmation is not available.', 'ultracache')), 500);
        }

        $result = $media->confirm_media_library_replacement_delete(array(
            'generation' => (string) $request->get_param('generation'),
        ));
        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_delete(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'run_media_library_replacement_delete_chunk')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement Delete Originals runner is not available.', 'ultracache')), 500);
        }

        $result = $media->run_media_library_replacement_delete_chunk(array(
            'session_token'     => (string) $request->get_param('sessionToken'),
            'generation'        => (string) $request->get_param('generation'),
            'limit'             => absint($request->get_param('limit')),
            'time_budget'       => (float) $request->get_param('time_budget'),
            'confirmationToken' => (string) $request->get_param('confirmationToken'),
        ));

        $status = !empty($result['success']) ? 200 : (!empty($result['blocked']) ? 409 : 500);
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_preview(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_mapping_preview')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement preview is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_mapping_preview(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
            'offset' => absint($request->get_param('offset')),
        ));

        $status = (!empty($result['success']) || array_key_exists('hasPreview', (array) $result)) ? 200 : 404;
        return new WP_REST_Response($result, $status);
    }


    public function media_library_replacement_copy(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'copy_media_library_replacement_files')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement file copy is not available.', 'ultracache')), 500);
        }

        $result = $media->copy_media_library_replacement_files(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_metadata_prepare(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'prepare_media_library_replacement_metadata_updates')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement metadata preparation is not available.', 'ultracache')), 500);
        }

        $result = $media->prepare_media_library_replacement_metadata_updates(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }



    public function media_library_replacement_metadata_apply(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'apply_media_library_replacement_metadata_updates')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement metadata switch is not available.', 'ultracache')), 500);
        }

        $result = $media->apply_media_library_replacement_metadata_updates(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_metadata_rollback(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'rollback_media_library_replacement_metadata_updates')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement metadata rollback is not available.', 'ultracache')), 500);
        }

        $result = $media->rollback_media_library_replacement_metadata_updates(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_references_scan(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'scan_media_library_replacement_database_references')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement reference scan is not available.', 'ultracache')), 500);
        }

        $result = $media->scan_media_library_replacement_database_references(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }




    public function media_library_replacement_references_match(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'match_media_library_replacement_database_references')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement reference matching is not available.', 'ultracache')), 500);
        }

        $result = $media->match_media_library_replacement_database_references(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_theme_css_scan(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'scan_media_library_replacement_theme_css_references')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement theme CSS reference scan is not available.', 'ultracache')), 500);
        }

        $result = $media->scan_media_library_replacement_theme_css_references(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
            'start'  => (bool) $request->get_param('start'),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }

    public function media_library_replacement_theme_css_preview(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_theme_css_replacement_preview')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement theme CSS preview is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_theme_css_replacement_preview(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
            'offset' => absint($request->get_param('offset')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 404);
    }

    public function media_library_replacement_theme_css_apply(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'apply_media_library_replacement_theme_css_replacements')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement theme CSS apply is not available.', 'ultracache')), 500);
        }

        $result = $media->apply_media_library_replacement_theme_css_replacements(array(
            'job_id'            => (string) $request->get_param('jobId'),
            'limit'             => absint($request->get_param('limit')),
            'confirmationToken' => (string) $request->get_param('confirmationToken'),
        ));

        return new WP_REST_Response($result, (!empty($result['success']) || !empty($result['blocked'])) ? 200 : 500);
    }

    public function media_library_replacement_theme_css_verify(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'verify_media_library_replacement_theme_css_replacements')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement theme CSS verification is not available.', 'ultracache')), 500);
        }

        $result = $media->verify_media_library_replacement_theme_css_replacements(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_database_preview(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_database_replacement_preview')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement database replacement preview is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_database_replacement_preview(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
            'offset' => absint($request->get_param('offset')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 404);
    }


    public function media_library_replacement_database_apply(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'apply_media_library_replacement_database_replacements')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement database replacement apply is not available.', 'ultracache')), 500);
        }

        $result = $media->apply_media_library_replacement_database_replacements(array(
            'job_id'            => (string) $request->get_param('jobId'),
            'limit'             => absint($request->get_param('limit')),
            'confirmationToken' => (string) $request->get_param('confirmationToken'),
        ));

        return new WP_REST_Response($result, (!empty($result['success']) || !empty($result['blocked'])) ? 200 : 500);
    }



    public function media_library_replacement_database_verify(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'verify_media_library_replacement_database_replacements')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement database replacement verification is not available.', 'ultracache')), 500);
        }

        $result = $media->verify_media_library_replacement_database_replacements(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }


    public function media_library_replacement_database_rollback(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'rollback_media_library_replacement_database_replacements')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement database rollback is not available.', 'ultracache')), 500);
        }

        $result = $media->rollback_media_library_replacement_database_replacements(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
    }



    public function media_library_replacement_cleanup_preview(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_cleanup_preview')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement cleanup preview is not available.', 'ultracache')), 500);
        }

        $result = $media->get_media_library_replacement_cleanup_preview(array(
            'job_id' => (string) $request->get_param('jobId'),
            'limit'  => absint($request->get_param('limit')),
            'offset' => absint($request->get_param('offset')),
        ));

        return new WP_REST_Response($result, !empty($result['success']) ? 200 : 404);
    }


    public function media_library_replacement_cleanup_apply(WP_REST_Request $request)
    {
        return $this->media_library_replacement_runner_unavailable_response();

        $media = $this->get_media();
        if (!$media || !method_exists($media, 'apply_media_library_replacement_cleanup')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media Library replacement cleanup apply is not available.', 'ultracache')), 500);
        }

        $result = $media->apply_media_library_replacement_cleanup(array(
            'job_id'            => (string) $request->get_param('jobId'),
            'limit'             => absint($request->get_param('limit')),
            'confirmationToken' => (string) $request->get_param('confirmationToken'),
        ));

        return new WP_REST_Response($result, (!empty($result['success']) || !empty($result['blocked'])) ? 200 : 500);
    }

    public function media_queue_retry_failed(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'retry_failed_media_queue_items')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue retry is not available.', 'ultracache')), 500);
        }

        return new WP_REST_Response($media->retry_failed_media_queue_items($this->get_media_queue_format_from_request($request)), 200);
    }

    public function media_queue_requeue_completed_for_regeneration(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'requeue_completed_media_queue_items_for_regeneration')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue maintenance is not available.', 'ultracache')), 500);
        }

        return new WP_REST_Response($media->requeue_completed_media_queue_items_for_regeneration($this->get_media_queue_format_from_request($request)), 200);
    }

    public function media_queue_clear_completed(WP_REST_Request $request)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'clear_completed_media_queue_items')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Media queue cleanup is not available.', 'ultracache')), 500);
        }

        return new WP_REST_Response($media->clear_completed_media_queue_items($this->get_media_queue_format_from_request($request)), 200);
    }

}
