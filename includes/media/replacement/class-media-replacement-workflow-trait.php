<?php
/**
 * UltraCache Media Library replacement workflow status, confirmation, preview, Prepare, Do, Verify, and Delete Originals runners.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Workflow_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache uses private custom Media Library replacement registry tables with validated table identifiers.

    private function get_media_replacement_workflow_state()
    {
        $saved = get_option($this->get_media_replacement_workflow_state_option_name(), array());
        return $this->normalize_media_replacement_workflow_state(is_array($saved) ? $saved : array());
    }

    private function get_media_replacement_workflow_section($section)
    {
        $section = sanitize_key((string) $section);
        $state = $this->get_media_replacement_workflow_state();
        return isset($state[$section]) && is_array($state[$section]) ? $state[$section] : array();
    }

    private function update_media_replacement_workflow_section($section, array $value)
    {
        $section = sanitize_key((string) $section);
        if ('' === $section) {
            return array();
        }
        $state = $this->get_media_replacement_workflow_state();
        $state[$section] = $value;
        $this->update_media_replacement_workflow_state($state);
        return $value;
    }

    private function clear_media_replacement_workflow_section($section)
    {
        return $this->update_media_replacement_workflow_section($section, array());
    }

    private function persist_media_replacement_workflow_state($stage, $message = '')
    {
        $stage = in_array((string) $stage, array('prepare', 'do', 'verify', 'delete', 'complete'), true) ? (string) $stage : 'prepare';
        $saved = $this->get_media_replacement_workflow_state();
        $saved['workflow_stage']      = $stage;
        $saved['workflow_message']    = sanitize_text_field((string) $message);
        $saved['workflow_updated_at'] = current_time('mysql', true);
        return $this->update_media_replacement_workflow_state($saved);
    }


    private function normalize_media_replacement_confirmation_tokens($tokens)
    {
        $tokens = is_array($tokens) ? $tokens : array();
        $normalized = array();
        foreach ($tokens as $action => $data) {
            $action = sanitize_key((string) $action);
            if (!in_array($action, array('database_apply', 'theme_css_apply', 'cleanup_apply'), true) || !is_array($data)) {
                continue;
            }
            $created_at = sanitize_text_field((string) ($data['created_at'] ?? ''));
            $created_ts = max(0, (int) ($data['created_ts'] ?? 0));
            if ($created_ts <= 0 && '' !== $created_at) {
                $parsed = strtotime($created_at . ' UTC');
                $created_ts = false === $parsed ? 0 : max(0, (int) $parsed);
            }
            $normalized[$action] = array(
                'token'            => sanitize_text_field((string) ($data['token'] ?? '')),
                'plan_fingerprint' => sanitize_text_field((string) ($data['plan_fingerprint'] ?? '')),
                'created_at'       => $created_at,
                'created_ts'       => $created_ts,
            );
        }
        return $normalized;
    }

    private function normalize_media_replacement_destructive_authorizations($authorizations)
    {
        $authorizations = is_array($authorizations) ? $authorizations : array();
        $normalized = array();
        foreach ($authorizations as $action => $data) {
            $action = sanitize_key((string) $action);
            if (!in_array($action, array('database_apply', 'theme_css_apply'), true) || !is_array($data)) {
                continue;
            }
            $normalized[$action] = array(
                'plan_fingerprint' => sanitize_text_field((string) ($data['plan_fingerprint'] ?? '')),
                'authorized_at'    => sanitize_text_field((string) ($data['authorized_at'] ?? '')),
                'authorized_ts'    => max(0, (int) ($data['authorized_ts'] ?? 0)),
            );
        }
        return $normalized;
    }

    private function get_media_replacement_confirmation_token_public_key($action)
    {
        $action = sanitize_key((string) $action);
        $map = array(
            'database_apply'  => 'databaseApply',
            'theme_css_apply' => 'themeCssApply',
            'cleanup_apply'   => 'cleanupApply',
        );
        return isset($map[$action]) ? $map[$action] : '';
    }

    private function get_media_replacement_confirmation_fingerprint($action, array $state)
    {
        $action = sanitize_key((string) $action);
        $state = $this->normalize_media_replacement_workflow_state($state);
        if ('cleanup_apply' === $action) {
            return (string) ($state['verified_plan_fingerprint'] ?: $state['pre_do_plan_fingerprint']);
        }
        return (string) $state['pre_do_plan_fingerprint'];
    }

    private function issue_media_replacement_confirmation_token($action)
    {
        $action = sanitize_key((string) $action);
        $public_key = $this->get_media_replacement_confirmation_token_public_key($action);
        if ('' === $public_key) {
            return array();
        }

        $state = $this->get_media_replacement_workflow_state();
        $fingerprint = $this->get_media_replacement_confirmation_fingerprint($action, $state);
        if ('' === $fingerprint) {
            return array();
        }

        $token = wp_generate_uuid4();
        $state['confirmation_tokens'][$action] = array(
            'token'            => $token,
            'plan_fingerprint' => $fingerprint,
            'created_at'       => current_time('mysql', true),
            'created_ts'       => time(),
        );
        $this->update_media_replacement_workflow_state($state);
        return array($public_key => $token);
    }

    /**
     * Attach a fresh start token only when an orchestrated Do phase has not
     * already established its durable plan authorization.
     *
     * The token is issued and consumed inside the same authenticated Do
     * request. It is never a persisted Prepare invariant, so a prepared workflow
     * remains resumable after the short confirmation TTL has elapsed.
     */
    private function get_media_replacement_do_destructive_action_args($action, array $args)
    {
        $action = sanitize_key((string) $action);
        $args['confirmationToken'] = $this->get_media_replacement_confirmation_token_from_args($args);
        if ('' === $args['confirmationToken']) {
            $issued = $this->issue_media_replacement_confirmation_token($action);
            $public_key = $this->get_media_replacement_confirmation_token_public_key($action);
            $args['confirmationToken'] = (string) ($issued[$public_key] ?? '');
        }
        return $args;
    }

    private function get_media_replacement_confirmation_token_from_args(array $args)
    {
        foreach (array('confirmation_token', 'confirmationToken', 'token') as $key) {
            if (isset($args[$key]) && '' !== (string) $args[$key]) {
                return sanitize_text_field((string) $args[$key]);
            }
        }
        return '';
    }

    private function get_media_replacement_confirmation_token_error($status = 'confirmation_token_required', $message = '')
    {
        $status = sanitize_key((string) $status);
        $message = '' !== (string) $message ? (string) $message : __('Create a fresh confirmation for this prepared replacement plan and retry.', 'ultracache');
        return array(
            'success'    => false,
            'blocked'    => true,
            'httpStatus' => 409,
            'status'     => $status,
            'message'    => $message,
        );
    }

    private function is_media_replacement_confirmation_retry_status($status)
    {
        $status = sanitize_key((string) $status);
        return 0 === strpos($status, 'confirmation_token_')
            || 'destructive_authorization_user_mismatch' === $status;
    }

    private function validate_media_replacement_confirmation_token($action, array $args)
    {
        $action = sanitize_key((string) $action);
        if (!in_array($action, array('database_apply', 'theme_css_apply', 'cleanup_apply'), true)) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_invalid_action', __('The requested destructive Media Library replacement action is invalid.', 'ultracache'));
        }

        $token = $this->get_media_replacement_confirmation_token_from_args($args);
        if ('' === $token) {
            return $this->get_media_replacement_confirmation_token_error();
        }

        $state = $this->get_media_replacement_workflow_state();
        $stored = isset($state['confirmation_tokens'][$action]) && is_array($state['confirmation_tokens'][$action])
            ? $state['confirmation_tokens'][$action]
            : array();
        $stored_token = (string) ($stored['token'] ?? '');
        $expected_fingerprint = $this->get_media_replacement_confirmation_fingerprint($action, $state);
        $stored_fingerprint = (string) ($stored['plan_fingerprint'] ?? '');
        $created_ts = max(0, (int) ($stored['created_ts'] ?? 0));

        if ('' === $stored_token || !hash_equals($stored_token, $token)) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_mismatch', __('The confirmation does not match the current prepared replacement plan. Create a fresh confirmation and retry.', 'ultracache'));
        }
        if ('' === $expected_fingerprint || '' === $stored_fingerprint || !hash_equals($expected_fingerprint, $stored_fingerprint)) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_state_changed', __('The prepared replacement plan changed before the destructive action started. Create a fresh confirmation and retry.', 'ultracache'));
        }
        if ($created_ts <= 0 || (time() - $created_ts) > self::MEDIA_REPLACEMENT_CONFIRMATION_TTL) {
            unset($state['confirmation_tokens'][$action]);
            $this->update_media_replacement_workflow_state($state);
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_expired', __('The start confirmation expired. Create a fresh confirmation and retry. The persisted replacement plan remains available.', 'ultracache'));
        }

        return array(
            'success'          => true,
            'token'            => $token,
            'planFingerprint'  => $expected_fingerprint,
            'state'            => $state,
        );
    }

    private function authorize_media_replacement_destructive_action($action, array $args)
    {
        $action = sanitize_key((string) $action);
        $state = $this->get_media_replacement_workflow_state();
        $fingerprint = $this->get_media_replacement_confirmation_fingerprint($action, $state);
        $existing = isset($state['destructive_authorizations'][$action]) && is_array($state['destructive_authorizations'][$action])
            ? $state['destructive_authorizations'][$action]
            : array();

        if ('' !== $fingerprint && '' !== (string) ($existing['plan_fingerprint'] ?? '')
            && hash_equals($fingerprint, (string) $existing['plan_fingerprint'])) {
            return array(
                'success'       => true,
                'continuation'  => true,
                'authorization' => $existing,
                'state'         => $state,
            );
        }

        $validation = $this->validate_media_replacement_confirmation_token($action, $args);
        if (empty($validation['success'])) {
            return $validation;
        }

        $latest = $this->get_media_replacement_workflow_state();
        $latest_fingerprint = $this->get_media_replacement_confirmation_fingerprint($action, $latest);
        if ('' === $latest_fingerprint || !hash_equals((string) $validation['planFingerprint'], $latest_fingerprint)) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_state_changed', __('The replacement plan changed before the destructive action could start. Create a fresh confirmation and retry.', 'ultracache'));
        }

        unset($latest['confirmation_tokens'][$action]);
        $latest['destructive_authorizations'][$action] = array(
            'plan_fingerprint' => $latest_fingerprint,
            'authorized_at'    => current_time('mysql', true),
            'authorized_ts'    => time(),
        );
        $latest = $this->update_media_replacement_workflow_state($latest);

        return array(
            'success'       => true,
            'continuation'  => false,
            'authorization' => $latest['destructive_authorizations'][$action],
            'state'         => $latest,
        );
    }

    private function clear_media_replacement_destructive_authorization($action)
    {
        $action = sanitize_key((string) $action);
        if (!in_array($action, array('database_apply', 'theme_css_apply'), true)) {
            return;
        }
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!isset($saved['destructive_authorizations'][$action])) {
            return;
        }
        unset($saved['destructive_authorizations'][$action]);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function consume_media_replacement_delete_confirmation(array $state, $confirmation_token)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        $validation = $this->validate_media_replacement_confirmation_token('cleanup_apply', array(
            'confirmationToken' => $confirmation_token,
        ));
        if (empty($validation['success'])) {
            return $validation;
        }

        $latest = $this->get_media_replacement_workflow_state();
        if ('verify_complete' !== (string) $latest['active_step']) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_state_changed', __('The verified replacement state changed before Delete Originals could start. Reload the workflow and create a fresh confirmation.', 'ultracache'));
        }
        $fingerprint = $this->get_media_replacement_confirmation_fingerprint('cleanup_apply', $latest);
        if ('' === $fingerprint || !hash_equals((string) $validation['planFingerprint'], $fingerprint)) {
            return $this->get_media_replacement_confirmation_token_error('confirmation_token_state_changed', __('The verified replacement plan changed before Delete Originals could start. Create a fresh confirmation and retry.', 'ultracache'));
        }

        unset($latest['confirmation_tokens']['cleanup_apply']);
        $latest['delete_authorized_at'] = current_time('mysql', true);
        $latest['delete_authorized_fingerprint'] = $fingerprint;
        $latest = $this->update_media_replacement_workflow_state($latest);
        return array('success' => true, 'state' => $latest);
    }

    private function is_media_replacement_delete_authorized(array $state)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        $fingerprint = $this->get_media_replacement_confirmation_fingerprint('cleanup_apply', $state);
        return '' !== (string) $state['delete_authorized_at']
            && '' !== $fingerprint
            && '' !== (string) $state['delete_authorized_fingerprint']
            && hash_equals($fingerprint, (string) $state['delete_authorized_fingerprint']);
    }

    private function get_media_replacement_confirmation_tokens_for_response()
    {
        $saved = $this->get_media_replacement_workflow_state();
        $tokens = array();
        foreach ((array) $saved['confirmation_tokens'] as $action => $data) {
            if (!is_array($data) || '' === (string) ($data['token'] ?? '')) {
                continue;
            }
            $validation = $this->validate_media_replacement_confirmation_token($action, array(
                'confirmationToken' => (string) $data['token'],
            ));
            if (empty($validation['success'])) {
                continue;
            }
            $public_key = $this->get_media_replacement_confirmation_token_public_key($action);
            if ('' !== $public_key) {
                $tokens[$public_key] = sanitize_text_field((string) $data['token']);
            }
        }
        return $tokens;
    }

    private function add_media_replacement_confirmation_token_to_response(array $response, $action, $should_issue = true)
    {
        if (empty($response['success']) || !$should_issue) {
            return $response;
        }
        $token = $this->issue_media_replacement_confirmation_token($action);
        if (!empty($token)) {
            $response['confirmationTokens'] = isset($response['confirmationTokens']) && is_array($response['confirmationTokens'])
                ? array_merge($response['confirmationTokens'], $token)
                : $token;
        }
        return $response;
    }


    public function set_media_library_replacement_workflow_stage($args = array())
    {
        $args = is_array($args) ? $args : array();
        $tables_ready = $this->ensure_media_replacement_tables();
        $stage = isset($args['stage']) ? sanitize_key((string) $args['stage']) : '';
        if (!in_array($stage, array('prepare', 'do', 'verify', 'delete', 'complete'), true)) {
            return array(
                'success' => false,
                'message' => __('Invalid Media Library replacement workflow stage.', 'ultracache'),
            );
        }

        if (!$tables_ready || !$this->media_replacement_has_registry_rows()) {
            return array(
                'success' => false,
                'message' => __('Run Media Library replacement before changing workflow stage.', 'ultracache'),
            );
        }

        $message = isset($args['message']) ? sanitize_text_field((string) $args['message']) : '';
        if ('' === $message) {
            if ('do' === $stage) {
                $message = __('Prepare is complete. Run Do to switch metadata and apply matched replacements.', 'ultracache');
            } elseif ('verify' === $stage) {
                $message = __('Do is complete. Run Verify before deleting originals.', 'ultracache');
            } elseif ('delete' === $stage) {
                $message = __('Verification is clean. Delete Originals is ready.', 'ultracache');
            } elseif ('complete' === $stage) {
                $message = __('Media Library replacement is complete.', 'ultracache');
            } else {
                $message = __('Prepare builds the replacement plan and previews all matched references.', 'ultracache');
            }
        }

        $this->persist_media_replacement_workflow_state($stage, $message);

        return $this->get_media_library_replacement_workflow_status(array(
            'respect_saved_stage' => true,
        ));
    }

    public function get_media_library_replacement_workflow_status($args = array())
    {
        global $wpdb;

        $args = is_array($args) ? $args : array();
        $tables_ready = $this->ensure_media_replacement_tables();
        $this->reconcile_media_replacement_recovery_state();
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $respect_saved_stage = array_key_exists('respect_saved_stage', $args) ? !empty($args['respect_saved_stage']) : true;
        $saved_stage = isset($saved['workflow_stage']) ? (string) $saved['workflow_stage'] : 'prepare';
        list($configured_target_format, $configured_fallback_format) = $this->get_media_replacement_current_output_policy();
        $format_lock = $this->get_media_replacement_format_lock_state($saved);
        $active_target_format = $this->media_replacement_workflow_exists($saved) ? $saved['target_format'] : '';
        $replacement_policy_changed = '' !== $active_target_format
            && ($active_target_format !== $configured_target_format || $saved['fallback_format'] !== $configured_fallback_format);

        $response = array(
            'success'             => true,
            'status'              => 'workflow_reset',
            'orchestrationVersion'=> self::MEDIA_REPLACEMENT_ORCHESTRATION_VERSION,
            'runnerReady'         => true,
            'readinessRunnerReady'=> true,
            'prepareRunnerReady'  => true,
            'doRunnerReady'       => true,
            'verifyRunnerReady'   => true,
            'deleteRunnerReady'   => true,
            'replacementSession'  => $this->get_media_library_replacement_session_status(),
            'runStatus'           => isset($saved['run_status']) ? (string) $saved['run_status'] : 'idle',
            'activeStep'          => isset($saved['active_step']) ? (string) $saved['active_step'] : '',
            'targetFormat'        => $configured_target_format,
            'activeTargetFormat'  => $active_target_format,
            'collisionPolicy'     => isset($saved['collision_policy']) ? (string) $saved['collision_policy'] : 'block',
            'activeCollisionPolicy' => isset($saved['collision_policy']) ? (string) $saved['collision_policy'] : 'block',
            'replacementPolicyChanged' => $replacement_policy_changed,
            'formatLocked'        => !empty($format_lock['locked']),
            'formatLockTarget'    => (string) ($format_lock['targetFormat'] ?? ''),
            'formatLockMessage'   => (string) ($format_lock['message'] ?? ''),
            'workflowStage'       => 'prepare',
            'message'             => __('Replacement readiness, Prepare, Do, Verify, and Delete Originals use the shared resumable dashboard runner.', 'ultracache'),
            'workflowMessage'     => __('Complete readiness and Prepare, run Do, then run Verify before deleting original JPG/PNG files.', 'ultracache'),
            'workflowUpdatedAt'   => isset($saved['workflow_updated_at']) ? (string) $saved['workflow_updated_at'] : '',
            'workflowVerifyCompleted' => !empty($saved['workflow_verified_at']),
            'workflowVerifiedAt'   => isset($saved['workflow_verified_at']) ? (string) $saved['workflow_verified_at'] : '',
            'cleanupReady'        => false,
            'cleanupCandidates'   => 0,
            'cleanupBlockedItems' => 0,
            'summary'             => array(),
            'readiness'           => $this->get_media_library_replacement_readiness_status(),
            'startGuard'          => $this->get_media_library_replacement_start_guard(),
            'preDoGuard'          => $this->get_media_library_replacement_pre_do_guard(),
            'prepare'             => $this->get_media_library_replacement_prepare_status(),
            'do'                  => $this->get_media_library_replacement_do_status(),
            'verify'              => $this->get_media_library_replacement_verify_status(),
            'delete'              => $this->get_media_library_replacement_delete_status(),
            'recovery'            => $this->get_media_replacement_recovery_status($saved),
        );

        if (!$tables_ready || !$this->media_replacement_has_registry_rows()) {
            $this->persist_media_replacement_workflow_state('prepare', $response['workflowMessage']);
            return $response;
        }

        $prepare_status  = $this->get_media_library_replacement_prepare_status();
        $do_status       = $this->get_media_library_replacement_do_status();
        $verify_status   = $this->get_media_library_replacement_verify_status();
        $mapping_summary = $this->get_media_replacement_preview_summary();
        $db_summary      = $this->get_media_replacement_database_preview_summary();
        $theme_summary   = $this->get_media_replacement_theme_css_summary();

        $total_items      = isset($mapping_summary['total']) ? max(0, (int) $mapping_summary['total']) : 0;
        $matched_items    = isset($mapping_summary['matched']) ? max(0, (int) $mapping_summary['matched']) : 0;
        $copied_items     = isset($mapping_summary['copied']) ? max(0, (int) $mapping_summary['copied']) : 0;
        $metadata_ready   = isset($mapping_summary['metadata_ready']) ? max(0, (int) $mapping_summary['metadata_ready']) : 0;
        $metadata_updated = isset($mapping_summary['metadata_updated']) ? max(0, (int) $mapping_summary['metadata_updated']) : 0;
        $refs_scanned     = isset($mapping_summary['refs_scanned']) ? max(0, (int) $mapping_summary['refs_scanned']) : 0;
        $failed_items     = isset($mapping_summary['failed']) ? max(0, (int) $mapping_summary['failed']) : 0;

        $db_total         = isset($db_summary['totalRefs']) ? max(0, (int) $db_summary['totalRefs']) : 0;
        $db_pending       = isset($db_summary['pendingRefs']) ? max(0, (int) $db_summary['pendingRefs']) : 0;
        $db_replaced      = isset($db_summary['replacedRefs']) ? max(0, (int) $db_summary['replacedRefs']) : 0;
        $db_verified      = isset($db_summary['verifiedRefs']) ? max(0, (int) $db_summary['verifiedRefs']) : 0;
        $db_failed        = isset($db_summary['failedRefs']) ? max(0, (int) $db_summary['failedRefs']) : 0;
        $db_verify_failed = isset($db_summary['verifyFailedRefs']) ? max(0, (int) $db_summary['verifyFailedRefs']) : 0;

        $theme_total       = isset($theme_summary['total']) ? max(0, (int) $theme_summary['total']) : 0;
        $theme_pending     = isset($theme_summary['pending']) ? max(0, (int) $theme_summary['pending']) : 0;
        $theme_applied     = isset($theme_summary['applied']) ? max(0, (int) $theme_summary['applied']) : 0;
        $theme_verified    = isset($theme_summary['verified']) ? max(0, (int) $theme_summary['verified']) : 0;
        $theme_failed      = isset($theme_summary['failed']) ? max(0, (int) $theme_summary['failed']) : 0;
        $theme_verify_fail = isset($theme_summary['verifyFailed']) ? max(0, (int) $theme_summary['verifyFailed']) : 0;

        $cleanup_candidates = max(0, $metadata_updated + $refs_scanned);
        $cleanup_deleted    = 0;
        $cleanup_failed     = 0;
        $cleanup_blocked    = 0;
        $items_table        = $this->get_media_replacement_items_table_name();
        if ('' !== $items_table && ($wpdb instanceof wpdb) && $this->media_replacement_items_table_exists()) {
            $cleanup_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT status, COUNT(*) AS item_count FROM %i GROUP BY status',
                    $items_table
                ),
                ARRAY_A
            );
            foreach ((array) $cleanup_rows as $cleanup_row) {
                $cleanup_status = isset($cleanup_row['status']) ? sanitize_key((string) $cleanup_row['status']) : '';
                $cleanup_count  = isset($cleanup_row['item_count']) ? max(0, (int) $cleanup_row['item_count']) : 0;
                if ('cleanup_deleted' === $cleanup_status) {
                    $cleanup_deleted += $cleanup_count;
                } elseif ('cleanup_failed' === $cleanup_status) {
                    $cleanup_failed += $cleanup_count;
                    $cleanup_blocked += $cleanup_count;
                } elseif (!in_array($cleanup_status, array('metadata_updated', 'refs_scanned', 'excluded'), true) && $cleanup_count > 0) {
                    $cleanup_blocked += $cleanup_count;
                }
            }
        }

        $ref_index_state = $this->get_media_replacement_ref_index_state();
        $db_index_completed = isset($ref_index_state['status']) && 'completed' === (string) $ref_index_state['status'];

        $theme_scan_state = $this->get_media_replacement_theme_css_scan_state();
        $theme_scan_completed = isset($theme_scan_state['status']) && 'completed' === (string) $theme_scan_state['status'];

        $do_ready = $total_items > 0
            && 0 === $failed_items
            && 0 === $matched_items
            && 0 === $copied_items
            && 0 === $metadata_ready
            && ($metadata_updated > 0 || $refs_scanned > 0)
            && !empty($db_index_completed)
            && ($db_total >= 0)
            && !($theme_scan_state && empty($theme_scan_completed));

        $db_done = 0 === $db_total || ($db_total > 0 && $db_replaced >= $db_total && 0 === $db_pending && 0 === $db_failed);
        $theme_done = 0 === $theme_total || ($theme_applied + $theme_verified >= $theme_total && 0 === $theme_pending && 0 === $theme_failed);
        $verify_ready = ($db_total > 0 && $db_replaced > $db_verified) || $db_verify_failed > 0 || $theme_applied > $theme_verified || $theme_verify_fail > 0;
        $verified_ready = ($db_total <= 0 || ($db_verified >= $db_total && 0 === $db_verify_failed && 0 === $db_failed))
            && ($theme_total <= 0 || ($theme_verified >= $theme_total && 0 === $theme_verify_fail && 0 === $theme_failed));
        $cleanup_ready = $total_items > 0 && $verified_ready && 0 === $cleanup_blocked && 0 === $cleanup_failed;
        $cleanup_complete = $cleanup_ready && $cleanup_deleted > 0 && 0 === $cleanup_candidates;

        $prepare_complete = !empty($prepare_status['prepareComplete']);
        $prepare_blocked_by_missing_generated = $total_items > 0
            && !$prepare_complete
            && 'blocker_decisions' !== (string) ($saved['active_step'] ?? '')
            && ((isset($saved['missingGenerated']) && (int) $saved['missingGenerated'] > 0) || (isset($saved['skipped']) && (int) $saved['skipped'] > 0) || (isset($saved['failed']) && (int) $saved['failed'] > 0));
        $do_complete = !empty($do_status['doComplete']);

        $workflow_verify_completed = !empty($saved['workflow_verified_at']);

        if ($prepare_blocked_by_missing_generated) {
            $stage = 'prepare';
            $message = __('Run AVIF / WebP Batch Conversion successfully first. Prepare found missing generated replacement files and cannot continue to Do.', 'ultracache');
        } elseif ($cleanup_complete || ($cleanup_deleted > 0 && 0 === $cleanup_candidates && 0 === $cleanup_failed && $cleanup_ready)) {
            $stage = 'complete';
            $message = __('Media Library replacement is complete.', 'ultracache');
        } elseif ($respect_saved_stage && 'delete' === $saved_stage) {
            if ($workflow_verify_completed && $cleanup_ready && $cleanup_candidates > 0 && 0 === $cleanup_blocked && $verified_ready) {
                $stage = 'delete';
                $message = __('Verification is clean. Delete Originals is ready.', 'ultracache');
            } elseif (!empty($do_complete)) {
                $stage = 'verify';
                $message = __('Run Verify to validate database, theme CSS, metadata, and cleanup readiness.', 'ultracache');
            } elseif (!empty($prepare_complete)) {
                $stage = 'do';
                $message = __('Prepare is complete. Run Do to switch metadata and apply matched replacements.', 'ultracache');
            } else {
                $stage = 'prepare';
                $message = __('Prepare builds the replacement plan and previews all matched references.', 'ultracache');
            }
        } elseif ($respect_saved_stage && 'verify' === $saved_stage) {
            if (!empty($do_complete)) {
                $stage = 'verify';
                $message = __('Run Verify to validate database, theme CSS, metadata, and cleanup readiness.', 'ultracache');
            } elseif (!empty($prepare_complete)) {
                $stage = 'do';
                $message = __('Prepare is complete. Run Do to switch metadata and apply matched replacements.', 'ultracache');
            } else {
                $stage = 'prepare';
                $message = __('Prepare builds the replacement plan and previews all matched references.', 'ultracache');
            }
        } elseif ($respect_saved_stage && 'do' === $saved_stage) {
            if (!empty($do_complete)) {
                $stage = 'verify';
                $message = __('Do is complete. Run Verify before deleting originals.', 'ultracache');
            } elseif (!empty($prepare_complete) || $do_ready || $metadata_ready > 0 || $db_pending > 0 || $theme_pending > 0) {
                $stage = 'do';
                $message = __('Prepare is complete. Run Do to switch metadata and apply matched replacements.', 'ultracache');
            } else {
                $stage = 'prepare';
                $message = __('Prepare builds the replacement plan and previews all matched references.', 'ultracache');
            }
        } elseif ($workflow_verify_completed && $cleanup_ready && $cleanup_candidates > 0 && 0 === $cleanup_blocked && $verified_ready && 'delete' === $saved_stage) {
            $stage = 'delete';
            $message = __('Verification is clean. Delete Originals is ready.', 'ultracache');
        } elseif (!empty($do_complete) || $verify_ready || ($db_done && $theme_done && !$cleanup_ready)) {
            $stage = 'verify';
            $message = __('Run Verify to validate database, theme CSS, metadata, and cleanup readiness.', 'ultracache');
        } elseif (!empty($prepare_complete) || $do_ready || $metadata_ready > 0 || $db_pending > 0 || $theme_pending > 0) {
            $stage = 'do';
            $message = __('Prepare is complete. Run Do to switch metadata and apply matched replacements.', 'ultracache');
        } else {
            $stage = 'prepare';
            $message = __('Prepare builds the replacement plan and previews all matched references.', 'ultracache');
        }

        $active_step = isset($saved['active_step']) ? sanitize_key((string) $saved['active_step']) : '';
        if (in_array($active_step, array('delete_originals', 'delete_failed'), true)) {
            $stage = 'delete';
            $message = 'delete_failed' === $active_step
                ? __('Delete Originals stopped on failed rows. Retry after reviewing the reported blocker.', 'ultracache')
                : ('paused' === (string) ($saved['run_status'] ?? '')
                    ? __('Delete Originals is paused and can resume from the remaining verified rows.', 'ultracache')
                    : __('Delete Originals is processing the remaining verified rows.', 'ultracache'));
        } elseif ('delete_complete' === $active_step) {
            $stage = 'complete';
            $message = __('Media Library replacement is complete.', 'ultracache');
        }

        if (empty($prepare_status['prepareComplete']) && !in_array($active_step, array('delete_originals', 'delete_failed', 'delete_complete'), true)) {
            $stage = 'prepare';
            $message = !empty($prepare_status['prepareFailed'])
                ? ((string) ($prepare_status['message'] ?? __('Prepare failed.', 'ultracache')))
                : ((string) ($prepare_status['message'] ?? __('Prepare is still running.', 'ultracache')));
        } elseif ('prepare' === $stage) {
            $stage = 'do';
            $message = __('Prepare and the hard pre-Do guard are complete. Run or resume Do.', 'ultracache');
        }

        $this->persist_media_replacement_workflow_state($stage, $message);

        $response['status']              = 'workflow_reset';
        $response['orchestrationVersion']= self::MEDIA_REPLACEMENT_ORCHESTRATION_VERSION;
        $response['runnerReady']         = true;
        $response['readinessRunnerReady']= true;
        $response['prepareRunnerReady']  = true;
        $response['doRunnerReady']       = true;
        $response['verifyRunnerReady']   = true;
        $response['deleteRunnerReady']   = true;
        $response['replacementSession']  = $this->get_media_library_replacement_session_status();
        $response['readiness']           = $this->get_media_library_replacement_readiness_status();
        $response['startGuard']          = $this->get_media_library_replacement_start_guard();
        $response['preDoGuard']          = isset($do_status['preDoGuard']) && is_array($do_status['preDoGuard']) ? $do_status['preDoGuard'] : array();
        $response['prepare']             = $prepare_status;
        $response['do']                  = $do_status;
        $response['verify']              = $verify_status;
        $response['delete']              = $this->get_media_library_replacement_delete_status();
        $response['recovery']            = $this->get_media_replacement_recovery_status($saved);
        $response['runStatus']           = isset($saved['run_status']) ? (string) $saved['run_status'] : 'idle';
        $response['activeStep']          = isset($saved['active_step']) ? (string) $saved['active_step'] : '';
        $response['workflowStage']       = $stage;
        $response['message']             = __('Replacement readiness, Prepare, Do, Verify, and Delete Originals use the shared resumable dashboard runner.', 'ultracache');
        $response['workflowMessage']     = __('Complete readiness, Prepare, Do, and Verify, then run or resume Delete Originals for the verified JPG/PNG rows.', 'ultracache');
        $response['workflowUpdatedAt']   = current_time('mysql', true);
        $response['workflowVerifyCompleted'] = !empty($saved['workflow_verified_at']);
        $response['workflowVerifiedAt']   = isset($saved['workflow_verified_at']) ? (string) $saved['workflow_verified_at'] : '';
        $response['cleanupReady']        = $cleanup_ready;
        $response['cleanupCandidates']   = $cleanup_candidates;
        $response['cleanupBlockedItems'] = $cleanup_blocked;
        $response['cleanupDeleted']      = $cleanup_deleted;
        $response['cleanupFailed']       = $cleanup_failed;
        $response['metadataUpdated']     = $metadata_updated;
        $response['refsScanned']         = $refs_scanned;
        $response['missingGenerated']    = isset($saved['missingGenerated']) ? max(0, (int) $saved['missingGenerated']) : 0;
        $response['skipped']             = isset($saved['skipped']) ? max(0, (int) $saved['skipped']) : 0;
        $response['failed']              = isset($saved['failed']) ? max(0, (int) $saved['failed']) : 0;
        $response['referencesFound']     = isset($mapping_summary['refsFound']) ? max(0, (int) $mapping_summary['refsFound']) : 0;
        $response['matchedRefs']         = $db_total;
        $response['replacedRefs']        = $db_replaced;
        $response['verifiedRefs']        = $db_verified;
        $response['pendingRefs']         = $db_pending;
        $response['failedRefs']          = $db_failed;
        $response['verifyFailedRefs']    = $db_verify_failed;
        $response['themeCssRefs']        = $theme_total;
        $response['themeCssPendingRefs'] = $theme_pending;
        $response['themeCssAppliedRefs'] = $theme_applied;
        $response['themeCssVerifiedRefs'] = $theme_verified;
        $response['themeCssFailedRefs']  = $theme_failed;
        $response['themeCssVerifyFailedRefs'] = $theme_verify_fail;
        $response['summary']             = array_merge($db_summary, array(
            'registryRows' => $total_items,
            'metadataUpdated' => $metadata_updated,
            'refsScanned' => $refs_scanned,
            'cleanupCandidates' => $cleanup_candidates,
            'cleanupBlockedItems' => $cleanup_blocked,
            'cleanupDeleted' => $cleanup_deleted,
            'cleanupFailed' => $cleanup_failed,
        ));

        $response['confirmationTokens'] = $this->get_media_replacement_confirmation_tokens_for_response();
        return $response;
    }

    private function get_media_replacement_registry_row_count()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb) || !$this->media_replacement_items_table_exists()) {
            return 0;
        }

        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i',
            $items_table
        )));
    }

    private function media_replacement_has_registry_rows()
    {
        return $this->get_media_replacement_registry_row_count() > 0;
    }

    private function build_media_replacement_empty_registry_response($message = '')
    {
        $converted_count = $this->get_media_replacement_converted_attachment_count();
        $message = '' !== (string) $message ? (string) $message : ($converted_count > 0
            ? __('The Media Library replacement workflow has no registry rows. Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache')
            : __('The Media Library replacement workflow has no registry rows. Run Restart Replacement Plan before continuing.', 'ultracache'));

        return array(
            'success'             => true,
            'message'             => $message,
            'status'              => 'empty_registry',
            'blocked'             => true,
            'emptyRegistry'       => true,
            'hasMore'             => false,
            'progressPercent'     => 100,
            'scanned'             => 0,
            'matched'             => 0,
            'convertedCandidates' => $converted_count,
            'nextStep'            => __('Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'),
        );
    }

    private function get_empty_media_replacement_planned_destination()
    {
        return array(
            'relativePath'                    => '',
            'intendedPath'                    => '',
            'url'                             => '',
            'filename'                        => '',
            'intendedFilename'                => '',
            'collision'                       => false,
            'existingUploadReplacement'       => false,
            'existingUploadReplacementValid'  => false,
        );
    }

    private function build_media_replacement_planned_destination($old_relative_path, $target_format)
    {
        $target_format = in_array((string) $target_format, array('avif', 'webp'), true) ? (string) $target_format : 'webp';
        $uploads       = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);

        if (empty($uploads['basedir'])) {
            return $this->get_empty_media_replacement_planned_destination();
        }

        $old_relative_path = ltrim(str_replace('\\', '/', (string) $old_relative_path), '/');
        if ('' === $old_relative_path || false !== strpos($old_relative_path, "\0")) {
            return $this->get_empty_media_replacement_planned_destination();
        }

        $directory = wp_normalize_path(dirname($old_relative_path));
        $directory = ('.' === $directory || '/' === $directory) ? '' : trim($directory, '/');
        $basename  = basename($old_relative_path);
        $stem      = (string) pathinfo($basename, PATHINFO_FILENAME);
        $filename  = sanitize_file_name($stem . '.' . $target_format);
        if ('' === $filename || '.' . $target_format === $filename) {
            $filename = sanitize_file_name('ultracache-media-' . md5($old_relative_path) . '.' . $target_format);
        }

        $relative_path = ('' !== $directory ? trailingslashit($directory) : '') . $filename;
        $target_file   = $this->build_media_replacement_destination_file_path($relative_path);
        $exists        = '' !== $target_file && $this->optimized_storage_path_exists($target_file, true);
        $valid         = $exists && $this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_existing_destination_validate');

        return array(
            'relativePath'                    => $relative_path,
            'intendedPath'                    => $relative_path,
            'url'                             => $this->build_media_replacement_public_url($relative_path),
            'filename'                        => $filename,
            'intendedFilename'                => $filename,
            'collision'                       => false,
            'existingUploadReplacement'       => $exists,
            'existingUploadReplacementValid'  => $valid,
        );
    }

    private function get_media_replacement_preview_summary()
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS item_count, SUM(old_size) AS old_total, SUM(new_size) AS new_total FROM %i GROUP BY status',
                $items_table
            ),
            ARRAY_A
        );

        $summary = array(
            'total'           => 0,
            'matched'         => 0,
            'copied'          => 0,
            'metadata_ready'  => 0,
            'metadata_updated'=> 0,
            'refs_scanned'    => 0,
            'metadata_restored' => 0,
            'metadata_rollback_failed' => 0,
            'metadata_failed' => 0,
            'cleanup_deleted' => 0,
            'cleanup_failed'  => 0,
            'skipped'         => 0,
            'failed'          => 0,
            'pending'         => 0,
            'oldTotalSize'    => 0,
            'targetTotalSize' => 0,
            'refsFound'       => 0,
            'serializedRefs'  => 0,
            'jsonRefs'        => 0,
        );

        foreach ((array) $rows as $row) {
            $status = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $count  = isset($row['item_count']) ? (int) $row['item_count'] : 0;
            if ('excluded' !== $status) {
                $summary['total'] += max(0, $count);
            }
            if (array_key_exists($status, $summary)) {
                $summary[$status] += max(0, $count);
            }
            if (in_array($status, array('matched', 'copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true)) {
                $summary['oldTotalSize']    += isset($row['old_total']) ? max(0, (int) $row['old_total']) : 0;
                $summary['targetTotalSize'] += isset($row['new_total']) ? max(0, (int) $row['new_total']) : 0;
            }
        }

        $refs_table = $this->get_media_replacement_refs_table_name();
        if ('' !== $refs_table && $this->media_replacement_refs_table_exists()) {
            $ref_row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT COUNT(*) AS refs_found, SUM(serialized) AS serialized_refs, SUM(json_detected) AS json_refs FROM %i',
                    $refs_table
                ),
                ARRAY_A
            );
            if (is_array($ref_row)) {
                $summary['refsFound']      = isset($ref_row['refs_found']) ? max(0, (int) $ref_row['refs_found']) : 0;
                $summary['serializedRefs'] = isset($ref_row['serialized_refs']) ? max(0, (int) $ref_row['serialized_refs']) : 0;
                $summary['jsonRefs']       = isset($ref_row['json_refs']) ? max(0, (int) $ref_row['json_refs']) : 0;
            }
        }

        return $summary;
    }

    private function get_media_replacement_preview_rows($limit = 200, $offset = 0)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $limit       = max(1, min(500, absint($limit)));
        $offset      = max(0, absint($offset));

        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, source_format, target_format, fallback_format, old_relative_path, old_url, generated_file_path, new_relative_path, new_url, new_file_path, old_mime, new_mime, old_size, new_size, destination_existed, destination_overwritten, destination_previous_size, destination_previous_hash, destination_backup_path, destination_backup_size, destination_backup_hash, destination_published_size, destination_published_hash, status, error_message FROM %i ORDER BY id ASC LIMIT %d OFFSET %d',
                $items_table,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
            $target_format = isset($row['target_format']) ? sanitize_key((string) $row['target_format']) : '';
            $status        = isset($row['status']) ? sanitize_key((string) $row['status']) : '';
            $old_relative  = isset($row['old_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['old_relative_path']), '/') : '';
            $generated     = isset($row['generated_file_path']) ? wp_normalize_path((string) $row['generated_file_path']) : '';
            $generated_rel = '';

            if ('' !== $generated && method_exists($this, 'get_uploads_relative_path_from_source')) {
                $maybe_generated_rel = $this->get_uploads_relative_path_from_source($generated);
                $generated_rel       = is_string($maybe_generated_rel) ? $maybe_generated_rel : '';
            }

            $stored_new_relative = isset($row['new_relative_path']) ? ltrim(str_replace('\\', '/', (string) $row['new_relative_path']), '/') : '';
            $stored_new_url      = isset($row['new_url']) ? esc_url_raw((string) $row['new_url']) : '';
            $destination = $this->get_empty_media_replacement_planned_destination();

            if ('' !== $stored_new_relative) {
                $destination = array(
                    'relativePath'                    => $stored_new_relative,
                    'intendedPath'                    => $stored_new_relative,
                    'url'                             => '' !== $stored_new_url ? $stored_new_url : $this->build_media_replacement_public_url($stored_new_relative),
                    'filename'                        => basename($stored_new_relative),
                    'intendedFilename'                => basename($stored_new_relative),
                    'collision'                       => false,
                    'existingUploadReplacement'       => false,
                    'existingUploadReplacementValid'  => false,
                );
            } elseif (in_array($status, array('matched', 'copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true) && '' !== $old_relative) {
                $destination = $this->build_media_replacement_planned_destination($old_relative, $target_format);
            }

            $planned_file = $this->build_media_replacement_destination_file_path((string) $destination['relativePath']);
            $existing_destination = '' !== $planned_file && $this->optimized_storage_path_exists($planned_file, true);
            $existing_destination_valid = $existing_destination
                && $this->is_valid_generated_media_file($planned_file, $target_format, 'media_replacement_preview_existing_destination_validate');
            $existing_destination_identical = $existing_destination_valid
                && '' !== $generated
                && $this->media_replacement_files_are_identical($generated, $planned_file);
            $destination['collision'] = $existing_destination && !$existing_destination_identical;
            $destination['existingUploadReplacement'] = $existing_destination;
            $destination['existingUploadReplacementValid'] = $existing_destination_identical;

            $title = $attachment_id > 0 ? get_the_title($attachment_id) : '';
            $title = is_string($title) && '' !== $title ? $title : sprintf(
                /* translators: %d: attachment ID. */
                __('Attachment #%d', 'ultracache'),
                $attachment_id
            );

            $old_size    = isset($row['old_size']) ? max(0, (int) $row['old_size']) : 0;
            $target_size = isset($row['new_size']) ? max(0, (int) $row['new_size']) : 0;
            $saving      = in_array($status, array('matched', 'copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true) ? max(0, $old_size - $target_size) : 0;

            $items[] = array(
                'id'                 => isset($row['id']) ? absint($row['id']) : 0,
                'attachmentId'       => $attachment_id,
                'itemScope'          => isset($row['item_scope']) ? sanitize_key((string) $row['item_scope']) : 'main',
                'sizeName'           => isset($row['size_name']) ? sanitize_key((string) $row['size_name']) : '',
                'title'              => $title,
                'sourceFormat'       => isset($row['source_format']) ? sanitize_key((string) $row['source_format']) : '',
                'targetFormat'       => $target_format,
                'fallbackFormat'     => isset($row['fallback_format']) ? sanitize_key((string) $row['fallback_format']) : '',
                'oldRelativePath'    => $old_relative,
                'oldUrl'             => isset($row['old_url']) ? esc_url_raw((string) $row['old_url']) : '',
                'oldMime'            => isset($row['old_mime']) ? sanitize_mime_type((string) $row['old_mime']) : '',
                'targetMime'         => isset($row['new_mime']) ? sanitize_mime_type((string) $row['new_mime']) : '',
                'generatedPath'      => $generated_rel,
                'plannedRelativePath'=> (string) $destination['relativePath'],
                'plannedUrl'         => esc_url_raw((string) $destination['url']),
                'plannedFilename'    => (string) $destination['filename'],
                'newFilePath'        => isset($row['new_file_path']) ? wp_normalize_path((string) $row['new_file_path']) : '',
                'intendedFilename'   => (string) $destination['intendedFilename'],
                'hasCollision'       => !empty($destination['collision']),
                'existingUploadReplacement'      => !empty($destination['existingUploadReplacement']),
                'existingUploadReplacementValid' => !empty($destination['existingUploadReplacementValid']),
                'destinationOverwritten' => !empty($row['destination_overwritten']),
                'destinationBackupPath' => isset($row['destination_backup_path']) ? wp_normalize_path((string) $row['destination_backup_path']) : '',
                'oldSize'            => $old_size,
                'targetSize'         => $target_size,
                'savingBytes'        => $saving,
                'savingPercent'      => ($old_size > 0 && in_array($status, array('matched', 'copied', 'metadata_ready', 'metadata_updated', 'refs_scanned', 'metadata_restored'), true)) ? round(($saving / $old_size) * 100, 1) : 0,
                'status'             => $status,
                'errorMessage'       => isset($row['error_message']) ? wp_strip_all_tags((string) $row['error_message']) : '',
            );
        }

        return $items;
    }

    public function get_media_library_replacement_mapping_preview($args = array())
    {
        if (!$this->ensure_media_replacement_tables()) {
            return array(
                'success' => false,
                'message' => __('Media Library replacement registry tables are not available.', 'ultracache'),
            );
        }

        $args = is_array($args) ? $args : array();
        if (!$this->media_replacement_has_registry_rows()) {
            return array(
                'success' => false,
                'message' => __('Run Prepare Library Replacement before opening the mapping preview.', 'ultracache'),
                'hasPreview' => false,
            );
        }

        $limit  = isset($args['limit']) ? absint($args['limit']) : 200;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $summary = $this->get_media_replacement_preview_summary();
        if (empty($summary) || empty($summary['total'])) {
            return array(
                'success' => true,
                'message' => __('No Media Library replacement registry rows were found for the current workflow. Run Prepare / Resume Library Replacement after restoring a JPG/PNG Media Library state, or roll back attachment metadata before rebuilding the plan.', 'ultracache'),
                'hasPreview' => false,
                'summary' => array(),
                'items' => array(),
                'limit' => $limit,
                'offset' => $offset,
                'returned' => 0,
                'hasMore' => false,
                'nextOffset' => $offset,
                'previousOffset' => max(0, $offset - $limit),
                'previewOnly' => true,
                'nextStep' => __('Restore the database backup or roll back attachment metadata, then run Restart Replacement Plan again.', 'ultracache'),
            );
        }

        $items = $this->get_media_replacement_preview_rows($limit, $offset);
        $first_item = !empty($items[0]) && is_array($items[0]) ? $items[0] : array();
        $target_format = !empty($first_item['targetFormat']) ? (string) $first_item['targetFormat'] : '';
        $fallback_format = !empty($first_item['fallbackFormat']) ? (string) $first_item['fallbackFormat'] : '';
        $next_step = __('Next step: copy the matched converted files into the normal WordPress uploads folders.', 'ultracache');
        if (!empty($summary['metadata_restored'])) {
            $next_step = __('Attachment metadata rollback is complete for restored rows. Copied replacement files still remain until cleanup tools are added.', 'ultracache');
        } elseif (!empty($summary['refs_scanned'])) {
            $next_step = __('Next step: preview database replacements before applying serialized-aware changes. Site content has not been replaced yet.', 'ultracache');
        } elseif (!empty($summary['metadata_updated'])) {
            $next_step = __('Next step: scan database references for the old image URLs and paths. Site content references have not been replaced yet.', 'ultracache');
        } elseif (!empty($summary['metadata_ready'])) {
            $next_step = __('Next step: switch attachment metadata to the copied replacement files. Site content references have not been replaced yet.', 'ultracache');
        } elseif (!empty($summary['copied'])) {
            $next_step = __('Next step: prepare attachment metadata updates. Site content references have not been replaced yet.', 'ultracache');
        }

        return array(
            'success'        => true,
            'message'        => __('Media Library replacement mapping preview is ready. Files are copied only after you run the copy step; attachment metadata and database content are not changed here.', 'ultracache'),
            'hasPreview'     => true,
            'targetFormat'   => $target_format,
            'fallbackFormat' => $fallback_format,
            'summary'        => $summary,
            'items'          => $items,
            'limit'          => $limit,
            'offset'         => $offset,
            'returned'       => count($items),
            'hasMore'        => ($offset + count($items)) < (int) $summary['total'],
            'nextOffset'     => ($offset + count($items)) < (int) $summary['total'] ? $offset + count($items) : $offset,
            'previousOffset' => max(0, $offset - $limit),
            'previewOnly'    => true,
            'nextStep'       => $next_step,
        );
    }


    private function get_media_replacement_pre_do_validation_rows($after_item_id = 0, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $after_item_id = max(0, absint($after_item_id));
        $limit = max(1, min(250, absint($limit)));
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, target_format, generated_file_path, new_relative_path, new_file_path FROM %i WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d',
                $items_table,
                'metadata_ready',
                $after_item_id,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function build_media_replacement_pre_do_plan_fingerprint(array $state, array $copy_summary, array $metadata_summary, array $ref_state, array $match_summary, array $database_summary, array $theme_state, array $theme_summary)
    {
        $payload = array(
            'orchestration_version' => self::MEDIA_REPLACEMENT_ORCHESTRATION_VERSION,
            'target_format'         => (string) $state['target_format'],
            'fallback_format'       => (string) $state['fallback_format'],
            'total_candidates'      => (int) $state['total_candidates'],
            'registry_total'        => max(0, (int) ($copy_summary['total'] ?? 0)),
            'metadata_ready'        => max(0, (int) ($metadata_summary['metadataReady'] ?? 0)),
            'db_index_status'       => sanitize_key((string) ($ref_state['status'] ?? '')),
            'db_index_total_specs'  => max(0, (int) ($ref_state['total_specs'] ?? 0)),
            'db_indexed_total'      => max(0, (int) ($match_summary['indexedTotal'] ?? 0)),
            'db_planned_refs'       => max(0, (int) ($match_summary['plannedRefs'] ?? 0)),
            'db_pending_refs'       => max(0, (int) ($database_summary['pendingRefs'] ?? 0)),
            'db_serialized_refs'    => max(0, (int) ($database_summary['serializedRefs'] ?? 0)),
            'db_json_refs'          => max(0, (int) ($database_summary['jsonRefs'] ?? 0)),
            'theme_scan_status'     => sanitize_key((string) ($theme_state['status'] ?? '')),
            'theme_total_files'     => max(0, (int) ($theme_state['total_files'] ?? 0)),
            'theme_total_refs'      => max(0, (int) ($theme_summary['total'] ?? 0)),
            'theme_pending_refs'    => max(0, (int) ($theme_summary['pending'] ?? 0)),
        );

        return hash('sha256', wp_json_encode($payload));
    }

    public function get_media_library_replacement_pre_do_guard($args = array())
    {
        $args = is_array($args) ? $args : array();
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $blockers = array();

        if (!$this->ensure_media_replacement_tables()) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'replacement_tables_unavailable', __('Media Library replacement registry tables are not available.', 'ultracache'));
        }
        if (!$this->media_replacement_has_registry_rows()) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'replacement_plan_missing', __('Prepare the Media Library replacement workflow before running Do.', 'ultracache'));
        }

        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        if ($state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'output_policy_changed', __('The image replacement policy changed after Prepare. Restart readiness and Prepare.', 'ultracache'));
        }

        $start_guard = $this->get_media_library_replacement_start_guard();
        if (empty($start_guard['allowed'])) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'readiness_guard_changed', __('The accepted readiness state is no longer valid. Restart readiness and Prepare.', 'ultracache'), count((array) ($start_guard['blockers'] ?? array())));
        }

        $prepare_complete = 'prepare_complete' === $state['active_step']
            && 'completed' === $state['run_status']
            && '' !== $state['prepare_completed_at'];
        if (!$prepare_complete) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'prepare_not_complete', __('Prepare and its final destination validation must complete before Do.', 'ultracache'));
        }
        if ('' === $state['pre_do_guard_completed_at'] || '' === $state['pre_do_plan_fingerprint']) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'pre_do_validation_incomplete', __('The final pre-Do destination validation has not completed.', 'ultracache'));
        }
        if ('do' !== $state['workflow_stage']) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'workflow_stage_not_do', __('The replacement workflow has not reached the Do stage.', 'ultracache'));
        }

        $copy_summary = $this->get_media_replacement_copy_summary();
        $metadata_summary = $this->get_media_replacement_metadata_summary();
        $ref_state = $this->get_media_replacement_ref_index_state();
        $match_summary = $this->get_media_replacement_ref_match_summary();
        $database_summary = $this->get_media_replacement_database_preview_summary();
        $theme_state = $this->get_media_replacement_theme_css_scan_state();
        $theme_summary = $this->get_media_replacement_theme_css_summary();

        $registry_total = max(0, (int) ($copy_summary['total'] ?? 0));
        $metadata_ready = max(0, (int) ($metadata_summary['metadataReady'] ?? 0));
        if ($registry_total <= 0) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'empty_replacement_registry', __('The prepared replacement registry is empty.', 'ultracache'));
        }
        if (0 !== max(0, (int) ($copy_summary['remainingToCopy'] ?? 0))) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'replacement_files_pending', __('One or more replacement files still need to be copied.', 'ultracache'), (int) ($copy_summary['remainingToCopy'] ?? 0));
        }
        $file_failures = max(0, (int) ($copy_summary['failed'] ?? 0))
            + max(0, (int) ($copy_summary['skipped'] ?? 0))
            + max(0, (int) ($metadata_summary['metadataFailed'] ?? 0))
            + max(0, (int) ($metadata_summary['failed'] ?? 0));
        if ($file_failures > 0) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'replacement_file_failures', __('One or more replacement registry rows failed before Do.', 'ultracache'), $file_failures);
        }
        if (0 !== max(0, (int) ($metadata_summary['remainingToPrepare'] ?? 0)) || $metadata_ready !== $registry_total) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'metadata_plan_incomplete', __('Attachment metadata planning does not cover every replacement registry row.', 'ultracache'), max(0, $registry_total - $metadata_ready));
        }
        if (max(0, (int) ($metadata_summary['metadataUpdated'] ?? 0)) > 0) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'metadata_already_changed', __('Attachment metadata was already changed before the new resumable Do runner started.', 'ultracache'), (int) ($metadata_summary['metadataUpdated'] ?? 0));
        }
        if ($state['pre_do_validation_failed'] > 0 || $state['pre_do_validated_items'] !== $registry_total) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'destination_validation_incomplete', __('The final destination-file validation did not cover every replacement registry row.', 'ultracache'), max((int) $state['pre_do_validation_failed'], max(0, $registry_total - (int) $state['pre_do_validated_items'])));
        }

        $db_index_complete = isset($ref_state['status']) && 'completed' === (string) $ref_state['status'];
        if (!$db_index_complete) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'database_index_incomplete', __('The database reference index is not complete for this workflow.', 'ultracache'));
        }
        if (max(0, (int) ($match_summary['indexedPending'] ?? 0)) > 0 || max(0, (int) ($match_summary['failedIndexed'] ?? 0)) > 0) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'database_match_incomplete', __('Database reference matching still has pending or failed indexed rows.', 'ultracache'), max(0, (int) ($match_summary['indexedPending'] ?? 0)) + max(0, (int) ($match_summary['failedIndexed'] ?? 0)));
        }
        $theme_scan_complete = isset($theme_state['status']) && 'completed' === (string) $theme_state['status'];
        if (!$theme_scan_complete) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'theme_css_scan_incomplete', __('The Theme CSS reference scan is not complete for this workflow.', 'ultracache'));
        }
        if (max(0, (int) ($theme_summary['failed'] ?? 0)) > 0) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'theme_css_plan_failed', __('One or more Theme CSS replacement rows failed during Prepare.', 'ultracache'), (int) ($theme_summary['failed'] ?? 0));
        }
        $current_fingerprint = $this->build_media_replacement_pre_do_plan_fingerprint($state, $copy_summary, $metadata_summary, $ref_state, $match_summary, $database_summary, $theme_state, $theme_summary);
        if ('' !== $state['pre_do_plan_fingerprint'] && !hash_equals((string) $state['pre_do_plan_fingerprint'], (string) $current_fingerprint)) {
            $this->add_media_replacement_start_guard_blocker($blockers, 'prepared_plan_changed', __('The prepared metadata, database, or Theme CSS plan changed after final validation. Restart Prepare.', 'ultracache'));
        }

        $allowed = empty($blockers);
        return array(
            'success'            => true,
            'allowed'            => $allowed,
            'blocked'            => !$allowed,
            'status'             => $allowed ? 'pre_do_ready' : 'pre_do_blocked',
            'message'            => $allowed
                /* translators: %d: total ready destination replacement file count. */
                ? sprintf(__('Pre-Do guard passed. All %d destination replacement files and prepared plans are ready.', 'ultracache'), $registry_total)
                : __('Do is blocked until the final destination validation and every prepared plan remain consistent.', 'ultracache'),
            'completedAt'        => (string) $state['pre_do_guard_completed_at'],
            'validatedFiles'     => (int) $state['pre_do_validated_items'],
            'registryFiles'      => $registry_total,
            'planFingerprint'    => (string) $state['pre_do_plan_fingerprint'],
            'currentFingerprint' => $current_fingerprint,
            'blockers'           => $blockers,
            'startGuard'         => $start_guard,
        );
    }

    private function validate_media_library_replacement_pre_do_files($args = array())
    {
        $args = is_array($args) ? $args : array();
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_has_registry_rows()) {
            return array('success' => false, 'blocked' => true, 'message' => __('The prepared replacement workflow is empty.', 'ultracache'));
        }

        $limit = max(1, min(250, absint($args['limit'] ?? 50)));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $deadline = microtime(true) + $time_budget;
        $rows = $this->get_media_replacement_pre_do_validation_rows($state['pre_do_validation_cursor_item_id'], $limit);
        $validated = 0;
        $failed = 0;
        $last_id = $state['pre_do_validation_cursor_item_id'];

        foreach ($rows as $row) {
            if (($validated + $failed) > 0 && microtime(true) >= $deadline) {
                break;
            }
            $item_id = absint($row['id'] ?? 0);
            $result = $this->validate_media_replacement_destination_row($row);
            if (empty($result['valid'])) {
                $failed++;
                $this->update_media_replacement_item_copy_result($item_id, array(
                    'status'        => 'failed',
                    'error_message' => wp_strip_all_tags((string) ($result['message'] ?? __('Pre-Do destination validation failed.', 'ultracache'))),
                ));
            } else {
                $validated++;
            }
            $last_id = max($last_id, $item_id);
        }

        $state['pre_do_validation_cursor_item_id'] = $last_id;
        $state['pre_do_validated_items'] += $validated;
        $state['pre_do_validation_failed'] += $failed;
        $state['heartbeat_at'] = current_time('mysql', true);
        $state['updated_at'] = current_time('mysql', true);
        $has_more = !empty($this->get_media_replacement_pre_do_validation_rows($last_id, 1));

        if ($state['pre_do_validation_failed'] > 0) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'prepare_failed';
            $state['last_error'] = __('Prepare stopped because one or more destination files failed the final pre-Do validation.', 'ultracache');
            $has_more = false;
        } elseif ($has_more) {
            $state['status'] = 'validating_pre_do';
            $state['run_status'] = 'running';
            $state['active_step'] = 'pre_do_validate';
        } else {
            $copy_summary = $this->get_media_replacement_copy_summary();
            $metadata_summary = $this->get_media_replacement_metadata_summary();
            $ref_state = $this->get_media_replacement_ref_index_state();
            $match_summary = $this->get_media_replacement_ref_match_summary();
            $database_summary = $this->get_media_replacement_database_preview_summary();
            $theme_state = $this->get_media_replacement_theme_css_scan_state();
            $theme_summary = $this->get_media_replacement_theme_css_summary();
            $start_guard = $this->get_media_library_replacement_start_guard();
            $registry_total = max(0, (int) ($copy_summary['total'] ?? 0));
            $final_valid = !empty($start_guard['allowed'])
                && $registry_total > 0
                && $state['pre_do_validated_items'] === $registry_total
                && 0 === (int) $state['pre_do_validation_failed']
                && 0 === (int) ($copy_summary['remainingToCopy'] ?? 0)
                && 0 === (int) ($copy_summary['failed'] ?? 0)
                && 0 === (int) ($copy_summary['skipped'] ?? 0)
                && 0 === (int) ($metadata_summary['remainingToPrepare'] ?? 0)
                && 0 === (int) ($metadata_summary['metadataFailed'] ?? 0)
                && 0 === (int) ($metadata_summary['failed'] ?? 0)
                && $registry_total === (int) ($metadata_summary['metadataReady'] ?? 0)
                && 0 === (int) ($metadata_summary['metadataUpdated'] ?? 0)
                && isset($ref_state['status'])
                && 'completed' === (string) $ref_state['status']
                && 0 === (int) ($match_summary['indexedPending'] ?? 0)
                && 0 === (int) ($match_summary['failedIndexed'] ?? 0)
                && isset($theme_state['status'])
                && 'completed' === (string) $theme_state['status'];

            if (!$final_valid) {
                $state['status'] = 'failed';
                $state['run_status'] = 'failed';
                $state['active_step'] = 'prepare_failed';
                $state['last_error'] = __('The hard pre-Do guard failed. Restart Prepare to rebuild and revalidate every destination file and prepared plan.', 'ultracache');
            } else {
                $now = current_time('mysql', true);
                $state['pre_do_plan_fingerprint'] = $this->build_media_replacement_pre_do_plan_fingerprint($state, $copy_summary, $metadata_summary, $ref_state, $match_summary, $database_summary, $theme_state, $theme_summary);
                $state['pre_do_guard_completed_at'] = $now;
                $state['status'] = 'completed';
                $state['run_status'] = 'completed';
                $state['active_step'] = 'prepare_complete';
                $state['prepare_completed_at'] = $now;
                $state['completed_at'] = $now;
                $state['workflow_stage'] = 'do';
                $state['workflow_message'] = __('Prepare and the hard pre-Do guard are complete. Every destination file and prepared plan is ready for Do.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['last_error'] = '';
            }
        }

        $state = $this->update_media_replacement_workflow_state($state);
        $registry_total = max(0, (int) ($this->get_media_replacement_copy_summary()['total'] ?? 0));
        return array(
            'success'             => 'failed' !== $state['run_status'],
            'blocked'             => 'failed' === $state['run_status'],
            'message'             => 'failed' === $state['run_status']
                ? $state['last_error']
                : ($has_more
                    /* translators: %1$d: checked destination count; %2$d: total destination count. */
                    ? sprintf(__('Pre-Do validation checked %1$d of %2$d destination files.', 'ultracache'), (int) $state['pre_do_validated_items'], $registry_total)
                    /* translators: %d: total ready destination file count. */
                    : sprintf(__('Pre-Do guard complete. All %d destination files and prepared plans are ready.', 'ultracache'), $registry_total)),
            'status'              => $state['status'],
            'activeStep'          => $state['active_step'],
            'hasMore'             => $has_more,
            'batchPreDoValidated' => $validated,
            'batchPreDoFailed'    => $failed,
            'preDoValidated'      => (int) $state['pre_do_validated_items'],
            'preDoValidationFailed' => (int) $state['pre_do_validation_failed'],
            'totalToValidate'     => $registry_total,
            'preDoGuard'          => $this->get_media_library_replacement_pre_do_guard(),
        );
    }


    private function get_media_replacement_prepare_step_rank($step)
    {
        $steps = array(
            'registry_scan',
            'copy',
            'validate',
            'metadata_plan',
            'database_scan',
            'database_match',
            'database_preview',
            'theme_css_scan',
            'theme_css_preview',
            'blocker_decisions',
            'pre_do_validate',
            'prepare_complete',
        );
        $rank = array_search(sanitize_key((string) $step), $steps, true);
        return false === $rank ? -1 : (int) $rank;
    }

    public function get_media_library_replacement_prepare_status()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $copy_summary = $this->media_replacement_has_registry_rows()
            ? $this->get_media_replacement_copy_summary()
            : array(
                'total' => 0,
                'matched' => 0,
                'copied' => 0,
                'failed' => 0,
                'copyProgressItems' => 0,
                'copyProgressTotal' => 0,
            );
        $metadata_summary = $this->get_media_replacement_metadata_summary();
        $ref_state = $this->get_media_replacement_ref_index_state();
        $match_summary = $this->get_media_replacement_ref_match_summary();
        $theme_state = $this->get_media_replacement_theme_css_scan_state();
        $theme_summary = $this->get_media_replacement_theme_css_summary();

        $total_candidates = max(0, (int) $state['total_candidates']);
        $registry_processed = min($total_candidates, max(0, (int) $state['scanned']));
        $readiness = $this->get_media_library_replacement_readiness_status();
        $readiness_variants = max(0, (int) ($readiness['requiredVariants'] ?? 0));
        $decisions_completed = '' !== (string) ($state['decisions_completed_at'] ?? '');
        $variant_total = $decisions_completed
            ? max(0, (int) ($copy_summary['copyProgressTotal'] ?? 0))
            : max($readiness_variants, max(0, (int) ($copy_summary['copyProgressTotal'] ?? 0)));
        $copy_processed = min($variant_total, max(0, (int) ($copy_summary['copyProgressItems'] ?? 0)));
        $validation_total = $decisions_completed
            ? max(0, (int) ($copy_summary['total'] ?? 0))
            : max($readiness_variants, max(0, (int) ($copy_summary['copied'] ?? 0)));
        $validation_processed = min($validation_total, max(0, (int) $state['validated_items']));
        $metadata_total = max(0, (int) ($metadata_summary['metadataProgressTotal'] ?? 0));
        $metadata_processed = min($metadata_total, max(0, (int) ($metadata_summary['metadataProgressItems'] ?? 0)));
        $db_scan_total = max(0, (int) ($ref_state['total_specs'] ?? 0));
        $db_scan_processed = min($db_scan_total, max(0, (int) ($ref_state['cursor_spec_index'] ?? 0)));
        $db_index_complete_for_progress = isset($ref_state['status']) && 'completed' === (string) $ref_state['status'];
        $db_match_total = $db_index_complete_for_progress ? max(0, (int) ($match_summary['indexedTotal'] ?? 0)) : 0;
        $db_match_processed = min($db_match_total, max(0, $db_match_total - (int) ($match_summary['indexedPending'] ?? 0)));
        $theme_file_total = max(0, (int) ($theme_state['total_files'] ?? 0));
        $theme_total = $theme_file_total * 2;
        $theme_processed = min(
            $theme_total,
            max(0, (int) ($theme_state['scanned_files'] ?? 0)) + max(0, (int) ($theme_state['validated_files'] ?? 0))
        );
        $rank = $this->get_media_replacement_prepare_step_rank($state['active_step']);
        $db_preview_processed = $rank > $this->get_media_replacement_prepare_step_rank('database_preview') ? 1 : 0;
        $theme_preview_processed = $rank > $this->get_media_replacement_prepare_step_rank('theme_css_preview') ? 1 : 0;
        $preview_total = $this->media_replacement_has_registry_rows() ? 2 : 0;
        $pre_do_total = max(0, (int) ($copy_summary['total'] ?? 0));
        $pre_do_processed = min($pre_do_total, max(0, (int) $state['pre_do_validated_items']));

        $total = $total_candidates + $variant_total + $validation_total + $metadata_total + $db_scan_total + $db_match_total + $theme_total + $preview_total + $pre_do_total;
        $processed = $registry_processed + $copy_processed + $validation_processed + $metadata_processed + $db_scan_processed + $db_match_processed + $theme_processed + $db_preview_processed + $theme_preview_processed + $pre_do_processed;
        // Prepare completion is a durable milestone. Do changes active_step/run_status,
        // but must not make the completed Prepare phase appear pending or failed again.
        $complete = '' !== $state['prepare_completed_at']
            && '' !== $state['pre_do_guard_completed_at']
            && '' !== $state['pre_do_plan_fingerprint'];
        $failed = 'prepare_failed' === $state['active_step']
            || ('failed' === $state['run_status'] && 'prepare' === $state['workflow_stage']);
        $decisions_required = 'blocker_decisions' === $state['active_step'] || 'decisions_required' === $state['status'];
        $has_more = $this->media_replacement_workflow_exists($state) && !$complete && !$failed && !$decisions_required;
        if ($complete || $decisions_required) {
            $processed = $total;
        }

        $message = __('Prepare has not started.', 'ultracache');
        if ($failed) {
            $message = $state['last_error'] ?: __('Prepare failed.', 'ultracache');
        } elseif ($complete) {
            $message = __('Prepare is complete. File, metadata, database, and Theme CSS plans are ready.', 'ultracache');
        } elseif ('registry_scan' === $state['active_step']) {
            $message = __('Prepare is building the main and intermediate replacement registry.', 'ultracache');
        } elseif ('blocker_decisions' === $state['active_step']) {
            $message = $state['workflow_message'] ?: __('Prepare completed discovery and planning. Resolve the recorded blocker groups to finalize the plan.', 'ultracache');
        } elseif ('copy' === $state['active_step']) {
            $message = __('Prepare is copying or reusing replacement files in WordPress uploads.', 'ultracache');
        } elseif ('validate' === $state['active_step']) {
            $message = __('Prepare is validating every destination replacement file.', 'ultracache');
        } elseif ('metadata_plan' === $state['active_step']) {
            $message = __('Prepare is building attachment metadata plans without changing the Media Library.', 'ultracache');
        } elseif ('database_scan' === $state['active_step']) {
            $message = __('Prepare is building the resumable database-wide JPG/PNG reference index.', 'ultracache');
        } elseif ('database_match' === $state['active_step']) {
            $message = __('Prepare is matching indexed database references to replacement registry rows.', 'ultracache');
        } elseif ('database_preview' === $state['active_step']) {
            $message = __('Prepare is finalizing the database replacement preview and confirmation state.', 'ultracache');
        } elseif ('theme_css_scan' === $state['active_step']) {
            $theme_phase = sanitize_key((string) ($theme_state['phase'] ?? ''));
            if ('discover' === $theme_phase) {
                $message = sprintf(
                    /* translators: %d: CSS files discovered so far. */
                    __('Prepare is discovering current and parent theme CSS files from a saved directory cursor. %d CSS files have been found so far.', 'ultracache'),
                    max(0, (int) ($theme_state['discovered_files'] ?? 0))
                );
            } elseif ('validate_file_set' === $theme_phase) {
                $message = __('Prepare is validating the persisted Theme CSS inventory through a second resumable directory traversal.', 'ultracache');
            } else {
                $message = __('Prepare is scanning the persisted Theme CSS inventory for matching references.', 'ultracache');
            }
        } elseif ('theme_css_preview' === $state['active_step']) {
            $message = __('Prepare is finalizing the Theme CSS replacement preview and confirmation state.', 'ultracache');
        } elseif ('pre_do_validate' === $state['active_step'] || ('prepare_complete' === $state['active_step'] && !$complete)) {
            $message = __('Prepare is running the hard pre-Do guard across every destination replacement file and prepared plan.', 'ultracache');
        }

        return array(
            'success'             => true,
            'status'              => $state['status'],
            'runStatus'           => $state['run_status'],
            'activeStep'          => $state['active_step'],
            'message'             => $message,
            'hasMore'             => $has_more,
            'prepareComplete'     => $complete,
            'prepareFailed'       => $failed,
            'decisionsRequired'    => $decisions_required,
            'blockerGroups'        => max(0, (int) $state['blocker_groups']),
            'blockerItems'         => max(0, (int) $state['blocker_items']),
            'unresolvedBlockerGroups' => max(0, (int) $state['unresolved_blocker_groups']),
            'excludedAttachments'  => max(0, (int) $state['excluded_attachments']),
            'processed'           => $processed,
            'total'               => $total,
            'progressPercent'     => $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : ($complete ? 100 : 0),
            'totalCandidates'     => $total_candidates,
            'scannedAttachments'  => (int) $state['scanned'],
            'registryRows'        => max(0, (int) ($copy_summary['total'] ?? 0)),
            'remainingToCopy'     => max(0, (int) ($copy_summary['remainingToCopy'] ?? 0)),
            'copied'              => max(0, (int) ($copy_summary['copied'] ?? 0)),
            'validated'           => (int) $state['validated_items'],
            'metadataPrepared'    => max(0, (int) ($metadata_summary['metadataReady'] ?? 0)),
            'metadataRemaining'   => max(0, (int) ($metadata_summary['remainingToPrepare'] ?? 0)),
            'databaseColumnsScanned' => $db_scan_processed,
            'databaseColumnsTotal'   => $db_scan_total,
            'databaseRowsScanned'    => max(0, (int) ($ref_state['scanned_rows'] ?? 0)),
            'databaseScanTable'      => sanitize_text_field((string) ($ref_state['current_table'] ?? '')),
            'databaseScanColumn'     => sanitize_text_field((string) ($ref_state['current_column'] ?? '')),
            'databaseScanPagination' => sanitize_key((string) ($ref_state['current_pagination'] ?? '')),
            'databaseScanCursorPrimary' => sanitize_text_field((string) ($ref_state['cursor_primary_value'] ?? '')),
            'databaseScanCursorOffset' => max(0, (int) ($ref_state['cursor_offset'] ?? 0)),
            'databaseScanQueryMs'    => max(0, (int) ($ref_state['last_query_ms'] ?? 0)),
            'databaseScanLastBatchRows' => max(0, (int) ($ref_state['last_batch_rows'] ?? 0)),
            'databaseScanLastBatchRefs' => max(0, (int) ($ref_state['last_batch_refs'] ?? 0)),
            'databaseReferencesIndexed' => max(0, (int) ($match_summary['indexedTotal'] ?? 0)),
            'databaseReferencesMatched' => max(0, (int) ($match_summary['plannedRefs'] ?? 0)),
            'databaseReferencesUnmatched' => max(0, (int) ($match_summary['unmatchedIndexed'] ?? 0)),
            'databaseReferencesIgnored' => max(0, (int) ($match_summary['unmatchedIgnored'] ?? 0)),
            'databaseReferencesRelevantUnmatched' => max(0, (int) ($match_summary['unmatchedRelevant'] ?? 0)),
            'databaseReferenceFailures' => max(0, (int) ($match_summary['failedIndexed'] ?? 0)),
            'themeCssPhase'        => sanitize_key((string) ($theme_state['phase'] ?? '')),
            'themeCssFilesDiscovered' => max(0, (int) ($theme_state['discovered_files'] ?? 0)),
            'themeCssFilesScanned' => max(0, (int) ($theme_state['scanned_files'] ?? 0)),
            'themeCssFilesValidated' => max(0, (int) ($theme_state['validated_files'] ?? 0)),
            'themeCssFilesTotal'   => $theme_file_total,
            'themeCssRefs'         => max(0, (int) ($theme_summary['total'] ?? 0)),
            'preDoValidated'       => (int) $state['pre_do_validated_items'],
            'preDoValidationFailed'=> (int) $state['pre_do_validation_failed'],
            'preDoGuardCompletedAt'=> (string) $state['pre_do_guard_completed_at'],
            'failed'               => max(
                (int) $state['failed'],
                max(0, (int) ($copy_summary['failed'] ?? 0)),
                max(0, (int) ($metadata_summary['metadataFailed'] ?? 0)),
                max(0, (int) ($match_summary['failedIndexed'] ?? 0)),
                max(0, (int) ($theme_summary['failed'] ?? 0))
            ),
            'prepareStartedAt'     => $state['prepare_started_at'],
            'prepareCompletedAt'   => $state['prepare_completed_at'],
            'confirmationTokens'   => $this->get_media_replacement_confirmation_tokens_for_response(),
        );
    }

    public function run_media_library_replacement_prepare_chunk($args = array())
    {
        $args = is_array($args) ? $args : array();
        $session_check = $this->validate_media_replacement_session_token((string) ($args['session_token'] ?? $args['sessionToken'] ?? ''), 'prepare');
        if (empty($session_check['success'])) {
            return $session_check;
        }

        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $chunk_lock = $this->acquire_media_replacement_prepare_chunk_lock((int) ceil($time_budget) + 15);
        if ('' === $chunk_lock) {
            return array(
                'success' => false,
                'blocked' => true,
                'status'  => 'prepare_chunk_locked',
                'message' => __('Another Media Library replacement Prepare chunk is still running.', 'ultracache'),
            );
        }

        try {
            $reset = !empty($args['reset']);
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            $active_step = $state['active_step'];
            if ('prepare_complete' === $active_step && ('' === $state['pre_do_guard_completed_at'] || '' === $state['pre_do_plan_fingerprint'])) {
                $state['status'] = 'validating_pre_do';
                $state['run_status'] = 'running';
                $state['active_step'] = 'pre_do_validate';
                $state['completed_at'] = '';
                $state['prepare_completed_at'] = '';
                $state['pre_do_validation_cursor_item_id'] = 0;
                $state['pre_do_validated_items'] = 0;
                $state['pre_do_validation_failed'] = 0;
                $state['pre_do_guard_completed_at'] = '';
                $state['pre_do_plan_fingerprint'] = '';
                $state['workflow_stage'] = 'prepare';
                $state['workflow_message'] = __('Prepare is running the hard pre-Do guard.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_workflow_state($state);
                $active_step = 'pre_do_validate';
            }
            if ($reset || !$this->media_replacement_has_registry_rows() || empty($active_step)) {
                $active_step = 'registry_scan';
                $step_result = $this->scan_media_library_replacement_eligible_items(array(
                    'reset'                => true,
                    'limit'                => absint($args['limit'] ?? 50),
                    'time_budget'          => $time_budget,
                    'collision_policy'     => sanitize_key((string) ($args['collision_policy'] ?? $args['collisionPolicy'] ?? 'block')),
                ));
            } elseif ('registry_scan' === $active_step) {
                $step_result = $this->scan_media_library_replacement_eligible_items(array(
                    'limit'       => absint($args['limit'] ?? 50),
                    'time_budget' => $time_budget,
                ));
            } elseif ('copy' === $active_step) {
                $step_result = $this->copy_media_library_replacement_files(array(
                    'limit'       => absint($args['limit'] ?? 50),
                    'time_budget' => $time_budget,
                ));
            } elseif ('validate' === $active_step) {
                $step_result = $this->validate_media_library_replacement_destination_files(array(
                    'limit'       => absint($args['limit'] ?? 50),
                    'time_budget' => $time_budget,
                ));
            } elseif ('metadata_plan' === $active_step) {
                $step_result = $this->prepare_media_library_replacement_metadata_updates(array(
                    'limit'       => absint($args['limit'] ?? 50),
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_scan' === $active_step) {
                $step_result = $this->scan_media_library_replacement_database_references(array(
                    'limit'       => max(1000, absint($args['limit'] ?? 1000)),
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_match' === $active_step) {
                $step_result = $this->match_media_library_replacement_database_references(array(
                    'limit'       => max(50, absint($args['limit'] ?? 250)),
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_preview' === $active_step) {
                $step_result = $this->get_media_library_replacement_database_replacement_preview(array(
                    'limit'                   => 1,
                    'offset'                  => 0,
                    'issue_confirmation_token' => false,
                ));
            } elseif ('theme_css_scan' === $active_step) {
                $step_result = $this->scan_media_library_replacement_theme_css_references(array(
                    'limit'       => min(50, max(1, absint($args['limit'] ?? 20))),
                    'time_budget' => $time_budget,
                ));
            } elseif ('theme_css_preview' === $active_step) {
                $step_result = $this->get_media_library_replacement_theme_css_replacement_preview(array(
                    'limit'                   => 1,
                    'offset'                  => 0,
                    'issue_confirmation_token' => false,
                ));
            } elseif ('pre_do_validate' === $active_step) {
                $step_result = $this->validate_media_library_replacement_pre_do_files(array(
                    'limit'       => absint($args['limit'] ?? 50),
                    'time_budget' => $time_budget,
                ));
            } elseif ('prepare_complete' === $active_step) {
                $step_result = array('success' => true, 'message' => __('Prepare is already complete.', 'ultracache'), 'batchProcessed' => 0);
            } else {
                $step_result = array(
                    'success' => false,
                    'blocked' => true,
                    'status'  => 'prepare_failed',
                    'message' => $state['last_error'] ?: __('Prepare is in an unsupported state. Restart Prepare.', 'ultracache'),
                );
            }

            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if (empty($step_result['success'])) {
                if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
                    $state['status'] = 'failed';
                    $state['run_status'] = 'failed';
                    $state['active_step'] = 'prepare_failed';
                    $state['last_error'] = sanitize_text_field((string) ($step_result['message'] ?? __('Prepare failed.', 'ultracache')));
                    $state['updated_at'] = current_time('mysql', true);
                    $this->update_media_replacement_workflow_state($state);
                }
            } elseif ('metadata_plan' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'scanning_database';
                $state['run_status'] = 'running';
                $state['active_step'] = 'database_scan';
                $state['workflow_message'] = __('Metadata plans are ready. Prepare is indexing database JPG/PNG references.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_scan' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'matching_database';
                $state['run_status'] = 'running';
                $state['active_step'] = 'database_match';
                $state['workflow_message'] = __('Database reference index is complete. Prepare is matching references to registry rows.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_match' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'planning_database';
                $state['run_status'] = 'running';
                $state['active_step'] = 'database_preview';
                $state['workflow_message'] = __('Database reference matching is complete. Prepare is finalizing the database preview.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_preview' === $active_step) {
                $state['status'] = 'scanning_theme';
                $state['run_status'] = 'running';
                $state['active_step'] = 'theme_css_scan';
                $state['workflow_message'] = __('Database preview is ready. Prepare is scanning theme CSS references.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('theme_css_scan' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'planning_theme';
                $state['run_status'] = 'running';
                $state['active_step'] = 'theme_css_preview';
                $state['workflow_message'] = __('Theme CSS scan is complete. Prepare is finalizing the Theme CSS preview.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('theme_css_preview' === $active_step) {
                $metadata_summary = $this->get_media_replacement_metadata_summary();
                $ref_state = $this->get_media_replacement_ref_index_state();
                $match_summary = $this->get_media_replacement_ref_match_summary();
                $theme_state = $this->get_media_replacement_theme_css_scan_state();
                $copy_summary = $this->get_media_replacement_copy_summary();
                $final_guard = $this->get_media_library_replacement_start_guard();
                $planning_valid = !empty($final_guard['allowed'])
                    && 0 === (int) ($copy_summary['failed'] ?? 0)
                    && 0 === (int) ($copy_summary['remainingToCopy'] ?? 0)
                    && 0 === (int) ($metadata_summary['remainingToPrepare'] ?? 0)
                    && 0 === (int) ($metadata_summary['metadataFailed'] ?? 0)
                    && isset($ref_state['status'])
                    && 'completed' === (string) $ref_state['status']
                    && 0 === (int) ($match_summary['indexedPending'] ?? 0)
                    && 0 === (int) ($match_summary['failedIndexed'] ?? 0)
                    && isset($theme_state['status'])
                    && 'completed' === (string) $theme_state['status'];

                if (!$planning_valid) {
                    $state['status'] = 'failed';
                    $state['run_status'] = 'failed';
                    $state['active_step'] = 'prepare_failed';
                    $state['last_error'] = __('Prepare planning validation failed. Restart Prepare to rebuild a consistent metadata, database, and Theme CSS plan.', 'ultracache');
                } else {
                    $blocker_summary = $this->get_media_replacement_blocker_summary();
                    $unresolved_blocker_groups = max(0, (int) ($blocker_summary['unresolvedGroups'] ?? 0));
                    $state['blocker_groups'] = max(0, (int) ($blocker_summary['groupCount'] ?? 0));
                    $state['blocker_items'] = max(0, (int) ($blocker_summary['affectedVariants'] ?? 0));
                    $state['unresolved_blocker_groups'] = $unresolved_blocker_groups;
                    $state['completed_at'] = '';
                    $state['prepare_completed_at'] = '';
                    $state['pre_do_validation_cursor_item_id'] = 0;
                    $state['pre_do_validated_items'] = 0;
                    $state['pre_do_validation_failed'] = 0;
                    $state['pre_do_guard_completed_at'] = '';
                    $state['pre_do_plan_fingerprint'] = '';
                    $state['workflow_stage'] = 'prepare';
                    $state['workflow_updated_at'] = current_time('mysql', true);
                    $state['last_error'] = '';

                    if ($unresolved_blocker_groups > 0) {
                        $state['status'] = 'decisions_required';
                        $state['run_status'] = 'paused';
                        $state['active_step'] = 'blocker_decisions';
                        $state['workflow_message'] = sprintf(
                            /* translators: 1: blocker groups, 2: affected attachments. */
                            __('Prepare completed discovery and planning with %1$d blocker group(s) affecting %2$d attachment(s). Open Decide Blockers to finalize the plan.', 'ultracache'),
                            $unresolved_blocker_groups,
                            max(0, (int) ($blocker_summary['affectedAttachments'] ?? 0))
                        );
                    } else {
                        $state['status'] = 'validating_pre_do';
                        $state['run_status'] = 'running';
                        $state['active_step'] = 'pre_do_validate';
                        $state['workflow_message'] = __('Metadata, database, and Theme CSS plans are ready. Prepare is running the hard pre-Do destination validation.', 'ultracache');
                    }
                }
                $this->update_media_replacement_workflow_state($state);
            }

            $prepare = $this->get_media_library_replacement_prepare_status();
            $batch_processed = max(0, (int) ($step_result['batchScanned'] ?? 0))
                + max(0, (int) ($step_result['batchCopied'] ?? 0))
                + max(0, (int) ($step_result['batchValidated'] ?? 0))
                + max(0, (int) ($step_result['batchPrepared'] ?? 0))
                + max(0, (int) ($step_result['batchScannedRows'] ?? 0))
                + max(0, (int) ($step_result['batchColumnsDone'] ?? 0))
                + max(0, (int) ($step_result['batchProcessedRefs'] ?? 0))
                + max(0, (int) ($step_result['batchScannedFiles'] ?? 0))
                + max(0, (int) ($step_result['batchPreDoValidated'] ?? 0));
            return array_merge($prepare, array(
                'success'        => !empty($step_result['success']) && empty($prepare['prepareFailed']),
                'blocked'        => !empty($step_result['blocked']),
                'message'        => (string) ($step_result['message'] ?? $prepare['message']),
                'batchProcessed' => $batch_processed,
                'step'           => (array) $step_result,
                'confirmationTokens' => $this->get_media_replacement_confirmation_tokens_for_response(),
            ));
        } catch (Throwable $error) {
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
                $state['status'] = 'failed';
                $state['run_status'] = 'failed';
                $state['active_step'] = 'prepare_failed';
                $state['last_error'] = sanitize_text_field((string) $error->getMessage());
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            }
            return array(
                'success' => false,
                'status'  => 'prepare_failed',
                'message' => sanitize_text_field((string) $error->getMessage()),
                'prepare' => $this->get_media_library_replacement_prepare_status(),
            );
        } finally {
            $this->release_media_replacement_prepare_chunk_lock($chunk_lock);
        }
    }



    private function media_replacement_do_file_mutation_permission_allowed()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return current_user_can('manage_options') && current_user_can('activate_plugins');
    }

    private function media_replacement_do_requires_file_mutation_permission()
    {
        $summary = $this->get_media_replacement_theme_css_summary();
        return max(0, (int) ($summary['total'] ?? 0)) > 0;
    }

    private function get_media_replacement_do_file_mutation_permission_blocked_response()
    {
        return array_merge($this->get_media_library_replacement_do_status(), array(
            'success'            => false,
            'blocked'            => true,
            'reason'             => 'theme_css_file_mutation_forbidden',
            'httpStatus'         => 403,
            'permissionRequired' => true,
            'message'            => __('Applying prepared Theme CSS replacements requires manage_options and activate_plugins permissions.', 'ultracache'),
        ));
    }


    private function flush_all_after_media_library_replacement_do()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ('' !== $state['do_flush_all_completed_at']) {
            return array(
                'success' => true,
                'message' => $state['do_flush_all_message'] ?: __('Flush All completed after Media Library replacement.', 'ultracache'),
            );
        }

        if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
            return array('success' => false, 'message' => __('Flush All could not start because the cache engine is unavailable.', 'ultracache'));
        }

        $engine = Ultra_Cache_Engine::get_instance();
        if (!$engine || !method_exists($engine, 'purge_all')) {
            return array('success' => false, 'message' => __('Flush All could not start because the cache engine is unavailable.', 'ultracache'));
        }

        $success = (bool) $engine->purge_all(array(
            'reason' => 'media_library_replacement',
            'source' => 'media_library_replacement',
        ));
        if (!$success) {
            return array('success' => false, 'message' => __('Flush All did not complete. Continue replacement to retry the clean-slate cache reset.', 'ultracache'));
        }

        $now = current_time('mysql', true);
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $state['do_flush_all_completed_at'] = $now;
        $state['do_flush_all_message'] = __('Flush All completed and cache state was reset from a clean slate.', 'ultracache');
        $state['updated_at'] = $now;
        $this->update_media_replacement_workflow_state($state);

        return array('success' => true, 'message' => $state['do_flush_all_message']);
    }

    public function recover_media_library_replacement_do($mode = 'continue')
    {
        $mode = sanitize_key((string) $mode);
        $mode = in_array($mode, array('continue', 'restart_database'), true) ? $mode : 'continue';

        if (!$this->ensure_media_replacement_tables()) {
            return array('success' => false, 'message' => __('Media Library replacement registry tables are not available.', 'ultracache'));
        }

        $session = $this->get_media_library_replacement_session_status();
        if (!empty($session['active'])) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('Pause the active Media Library replacement dashboard session before recovery.', 'ultracache'),
            ));
        }

        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($state) && !$this->media_replacement_has_registry_rows()) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('There is no Media Library replacement workflow to recover.', 'ultracache'),
            ));
        }

        $failed = 'do_failed' === $state['active_step']
            || ('failed' === $state['run_status'] && 'do' === $state['workflow_stage']);
        if (!$failed) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => true,
                'message' => __('The Media Library replacement workflow is already resumable from its current state.', 'ultracache'),
                'nextStage' => $state['workflow_stage'],
            ));
        }

        $failed_step = sanitize_key((string) ($state['do_failed_step'] ?? ''));
        $database_before = $this->get_media_replacement_database_apply_summary();
        $database_failed_before = max(0, (int) ($database_before['failedRefs'] ?? 0));
        $database_recovery_steps = array('database_recovery_scan', 'database_recovery_match', 'database_recovery_preview');
        $can_restart_database = $database_failed_before > 0
            || 'database_apply' === $failed_step
            || in_array($failed_step, $database_recovery_steps, true);
        $can_continue = '' === $failed_step
            || in_array($failed_step, array('metadata_apply', 'database_apply', 'theme_css_apply'), true)
            || in_array($failed_step, $database_recovery_steps, true);

        if ('continue' === $mode && !$can_continue) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('This failure occurred before a resumable Do phase. Restart the replacement plan to rebuild it.', 'ultracache'),
            ));
        }

        if ('restart_database' === $mode && !$can_restart_database) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('Restart Database Replacement is available only when the database replacement phase stopped.', 'ultracache'),
            ));
        }

        $excluded = $this->exclude_media_replacement_internal_database_references();
        $now = current_time('mysql', true);

        if ('restart_database' === $mode) {
            if (!$this->reset_media_replacement_database_plan_for_restart()) {
                return array_merge($this->get_media_library_replacement_workflow_status(), array(
                    'success' => false,
                    'message' => __('The database replacement step could not be reset.', 'ultracache'),
                ));
            }

            unset(
                $state['confirmation_tokens']['database_apply'],
                $state['destructive_authorizations']['database_apply'],
                $state['confirmation_tokens']['theme_css_apply'],
                $state['destructive_authorizations']['theme_css_apply']
            );
            $state['status'] = 'scanning_database';
            $state['run_status'] = 'paused';
            $state['active_step'] = 'database_recovery_scan';
            $state['do_failed_step'] = '';
            $state['workflow_stage'] = 'do';
            $state['completed_at'] = '';
            $state['do_completed_at'] = '';
            $state['do_flush_all_completed_at'] = '';
            $state['do_flush_all_message'] = '';
            $state['last_error'] = '';
            $state['paused_at'] = $now;
            $state['workflow_message'] = __('Database replacement restart is ready. Completed metadata and database changes were preserved; Continue rebuilds only the unresolved database plan from the current database state.', 'ultracache');
            $state['workflow_updated_at'] = $now;
            $state['updated_at'] = $now;
            $this->update_media_replacement_workflow_state($state);

            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => true,
                'recoveryMode' => 'restart_database',
                'nextStage' => 'do',
                'excludedInternalRefs' => $excluded,
                'message' => $state['workflow_message'],
            ));
        }

        $retried_metadata = 0;
        $retried_database = 0;
        $retried_theme_css = 0;
        $metadata = $this->get_media_replacement_metadata_apply_summary();
        $metadata_failed = max(0, (int) ($metadata['metadataFailed'] ?? 0));
        $database = $this->get_media_replacement_database_apply_summary();
        $database_failed = max(0, (int) ($database['failedRefs'] ?? 0));
        $theme = $this->get_media_replacement_theme_css_summary();
        $theme_failed = max(0, (int) ($theme['failed'] ?? 0));

        if ($metadata_failed > 0 || 'metadata_apply' === $failed_step) {
            $retried_metadata = $this->retry_media_replacement_failed_metadata_updates();
            $next_step = 'metadata_apply';
            $next_status = 'applying_metadata';
            $workflow_message = __('Replacement recovery is ready. Continue retries only unresolved attachment metadata rows and preserves completed changes.', 'ultracache');
        } elseif ($database_failed > 0 || 'database_apply' === $failed_step) {
            $retried_database = $this->retry_media_replacement_failed_database_references();
            $next_step = 'database_apply';
            $next_status = 'applying_database';
            $workflow_message = __('Replacement recovery is ready. Continue retries only unresolved database rows and preserves completed changes.', 'ultracache');
        } elseif (in_array($failed_step, $database_recovery_steps, true)) {
            $next_step = $failed_step;
            $next_status = 'scanning_database';
            $workflow_message = __('Database replacement restart recovery is ready. Continue resumes the saved database rebuild phase.', 'ultracache');
        } else {
            if ($theme_failed > 0) {
                $retried_theme_css = $this->retry_media_replacement_failed_theme_css_references();
            }
            $next_step = 'theme_css_apply';
            $next_status = 'applying_theme';
            $workflow_message = $theme_failed > 0 || 'theme_css_apply' === $failed_step
                ? __('Replacement recovery is ready. Continue retries only unresolved Theme CSS rows and preserves completed changes.', 'ultracache')
                : __('Replacement recovery is ready. Continue resumes completion and retries Flush All when required.', 'ultracache');
        }

        unset(
            $state['confirmation_tokens']['database_apply'],
            $state['destructive_authorizations']['database_apply'],
            $state['confirmation_tokens']['theme_css_apply'],
            $state['destructive_authorizations']['theme_css_apply']
        );
        $state['status'] = $next_status;
        $state['run_status'] = 'paused';
        $state['active_step'] = $next_step;
        $state['do_failed_step'] = '';
        $state['workflow_stage'] = 'do';
        $state['completed_at'] = '';
        $state['do_completed_at'] = '';
        $state['do_flush_all_completed_at'] = '';
        $state['do_flush_all_message'] = '';
        $state['last_error'] = '';
        $state['paused_at'] = $now;
        $state['workflow_message'] = $workflow_message;
        $state['workflow_updated_at'] = $now;
        $state['updated_at'] = $now;
        $this->update_media_replacement_workflow_state($state);

        return array_merge($this->get_media_library_replacement_workflow_status(), array(
            'success' => true,
            'recoveryMode' => 'continue',
            'nextStage' => 'do',
            'excludedInternalRefs' => $excluded,
            'retriedMetadataRows' => $retried_metadata,
            'retriedDatabaseRefs' => $retried_database,
            'retriedThemeCssRefs' => $retried_theme_css,
            'message' => $state['workflow_message'],
        ));
    }


    public function get_media_library_replacement_do_status()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $metadata = $this->get_media_replacement_metadata_apply_summary();
        $database = $this->get_media_replacement_database_apply_summary();
        $theme = $this->get_media_replacement_theme_css_summary();

        $metadata_total = max(0, (int) ($metadata['metadataApplyTotal'] ?? 0));
        $metadata_updated = max(0, (int) ($metadata['metadataUpdated'] ?? 0));
        $metadata_failed = max(0, (int) ($metadata['metadataFailed'] ?? 0)) + max(0, (int) ($metadata['failed'] ?? 0));
        $database_total = max(0, (int) ($database['totalRefs'] ?? 0));
        $database_pending = max(0, (int) ($database['pendingRefs'] ?? 0));
        $database_failed = max(0, (int) ($database['failedRefs'] ?? 0));
        $database_excluded = max(0, (int) ($database['excludedRefs'] ?? 0));
        $theme_total = max(0, (int) ($theme['total'] ?? 0));
        $theme_pending = max(0, (int) ($theme['pending'] ?? 0));
        $theme_failed = max(0, (int) ($theme['failed'] ?? 0));

        $total = $metadata_total + $database_total + $theme_total;
        $processed = min($metadata_total, $metadata_updated + $metadata_failed)
            + min($database_total, max(0, $database_total - $database_pending))
            + min($theme_total, max(0, $theme_total - $theme_pending));
        // Do completion is a durable milestone. Verify changes active_step/run_status,
        // but must not make a completed Do workflow appear incomplete again.
        $complete = '' !== $state['do_completed_at'] && 'do_failed' !== $state['active_step'];
        $failed = 'do_failed' === $state['active_step']
            || ('failed' === $state['run_status'] && 'do' === $state['workflow_stage']);
        $failed_step = sanitize_key((string) ($state['do_failed_step'] ?? ''));
        if ($failed && '' === $failed_step) {
            if ($metadata_failed > 0) {
                $failed_step = 'metadata_apply';
            } elseif ($database_failed > 0) {
                $failed_step = 'database_apply';
            } elseif ($theme_failed > 0) {
                $failed_step = 'theme_css_apply';
            }
        }
        $database_failure_steps = array('database_apply', 'database_recovery_scan', 'database_recovery_match', 'database_recovery_preview');
        $can_continue = $failed && ('' === $failed_step || in_array($failed_step, array('metadata_apply', 'theme_css_apply'), true) || in_array($failed_step, $database_failure_steps, true));
        $can_restart_database = $failed && ($database_failed > 0 || in_array($failed_step, $database_failure_steps, true));
        $active_do = in_array($state['active_step'], array('metadata_apply', 'database_recovery_scan', 'database_recovery_match', 'database_recovery_preview', 'database_apply', 'theme_css_apply'), true);
        $file_mutation_permission_required = !$complete && $theme_total > 0;
        $file_mutation_permission_allowed = !$file_mutation_permission_required
            || $this->media_replacement_do_file_mutation_permission_allowed();
        if ('prepare_complete' === $state['active_step']) {
            $pre_do_guard = $this->get_media_library_replacement_pre_do_guard();
        } elseif ($active_do || $complete) {
            $pre_do_guard = array(
                'success'     => true,
                'allowed'     => true,
                'blocked'     => false,
                'status'      => 'pre_do_passed',
                'message'     => __('The hard pre-Do guard passed before destructive work started.', 'ultracache'),
                'completedAt' => $state['pre_do_guard_completed_at'],
                'blockers'    => array(),
            );
        } else {
            $pre_do_guard = array('allowed' => false, 'blocked' => true, 'blockers' => array());
        }
        $can_start = 'prepare_complete' === $state['active_step']
            && 'completed' === $state['run_status']
            && !empty($pre_do_guard['allowed'])
            && $file_mutation_permission_allowed;
        $has_more = $this->media_replacement_has_registry_rows()
            && !$complete
            && !$failed
            && $file_mutation_permission_allowed
            && ($can_start || $active_do);

        $message = __('Do has not started.', 'ultracache');
        if ($failed) {
            $message = $state['last_error'] ?: __('Do failed.', 'ultracache');
        } elseif ($complete) {
            $message = $state['workflow_message'] ?: __('Media Library replacement and Flush All completed. Run Verify before deleting original JPG/PNG files.', 'ultracache');
        } elseif (!$file_mutation_permission_allowed) {
            $message = __('Applying prepared Theme CSS replacements requires manage_options and activate_plugins permissions.', 'ultracache');
        } elseif ('metadata_apply' === $state['active_step']) {
            $message = __('Do is switching attachment metadata in resumable chunks.', 'ultracache');
        } elseif ('database_recovery_scan' === $state['active_step']) {
            $message = __('Database replacement restart is scanning the current database for unresolved JPG/PNG references.', 'ultracache');
        } elseif ('database_recovery_match' === $state['active_step']) {
            $message = __('Database replacement restart is matching the rebuilt reference index to the existing replacement registry.', 'ultracache');
        } elseif ('database_recovery_preview' === $state['active_step']) {
            $message = __('Database replacement restart is finalizing the rebuilt unresolved replacement plan.', 'ultracache');
        } elseif ('database_apply' === $state['active_step']) {
            $message = __('Do is applying matched database replacements in resumable chunks.', 'ultracache');
        } elseif ('theme_css_apply' === $state['active_step']) {
            $message = __('Do is applying matched Theme CSS replacements in resumable chunks.', 'ultracache');
        } elseif ($can_start) {
            $message = __('The hard pre-Do guard passed. Do is ready to start.', 'ultracache');
        } elseif ($this->media_replacement_has_registry_rows() && empty($pre_do_guard['allowed'])) {
            $message = (string) ($pre_do_guard['message'] ?? __('Do is blocked by the hard pre-Do guard.', 'ultracache'));
        }

        return array(
            'success'            => true,
            'status'             => $state['status'],
            'runStatus'          => $state['run_status'],
            'activeStep'         => $state['active_step'],
            'message'            => $message,
            'hasMore'            => $has_more,
            'doReady'            => ($can_start || $active_do) && $file_mutation_permission_allowed,
            'doComplete'         => $complete,
            'fileMutationPermissionRequired' => $file_mutation_permission_required,
            'fileMutationPermissionAllowed'  => $file_mutation_permission_allowed,
            'doFailed'           => $failed,
            'processed'          => $processed,
            'total'              => $total,
            'progressPercent'    => $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : ($complete ? 100 : 0),
            'metadataTotal'      => $metadata_total,
            'metadataUpdated'    => $metadata_updated,
            'metadataFailed'     => $metadata_failed,
            'databaseTotal'      => $database_total,
            'databasePending'    => $database_pending,
            'databaseReplaced'   => max(0, (int) ($database['replacedRefs'] ?? 0)),
            'databaseFailed'     => $database_failed,
            'databaseExcluded'   => $database_excluded,
            'failedStep'         => $failed_step,
            'canContinue'        => $can_continue,
            'canRestartDatabase' => $can_restart_database,
            'flushAllCompleted'  => '' !== $state['do_flush_all_completed_at'],
            'flushAllCompletedAt'=> $state['do_flush_all_completed_at'],
            'flushAllMessage'    => $state['do_flush_all_message'],
            'themeCssTotal'      => $theme_total,
            'themeCssPending'    => $theme_pending,
            'themeCssApplied'    => max(0, (int) ($theme['applied'] ?? 0)),
            'themeCssFailed'     => $theme_failed,
            'doStartedAt'        => $state['do_started_at'],
            'doCompletedAt'      => $state['do_completed_at'],
            'preDoGuard'         => $pre_do_guard,
        );
    }

    private function fail_media_library_replacement_do($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            if ('do_failed' !== $state['active_step']) {
                $state['do_failed_step'] = sanitize_key((string) $state['active_step']);
            }
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'do_failed';
            $state['workflow_stage'] = 'do';
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_do_status(), array(
            'success' => false,
            'blocked' => true,
            'message' => sanitize_text_field((string) $message),
        ));
    }

    private function pause_media_library_replacement_do_for_retry($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            $state['run_status'] = 'paused';
            $state['paused_at'] = current_time('mysql', true);
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_stage'] = 'do';
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_do_status(), array(
            'success'       => false,
            'blocked'       => true,
            'retryRequired' => true,
            'message'       => sanitize_text_field((string) $message),
        ));
    }

    public function run_media_library_replacement_do_chunk($args = array())
    {
        $args = is_array($args) ? $args : array();
        $session_token = $this->normalize_media_replacement_manual_session_token((string) ($args['session_token'] ?? $args['sessionToken'] ?? ''));
        $session = $this->validate_media_replacement_session_token($session_token, 'do');
        if (empty($session['success'])) {
            return $session;
        }

        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $chunk_lock = $this->acquire_media_replacement_do_chunk_lock((int) ceil($time_budget) + 15);
        if ('' === $chunk_lock) {
            return array(
                'success' => false,
                'blocked' => true,
                'status'  => 'do_chunk_locked',
                'message' => __('Another Media Library replacement Do chunk is still running. Retry after it finishes.', 'ultracache'),
            );
        }

        try {
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if (!$this->media_replacement_has_registry_rows()) {
                return $this->fail_media_library_replacement_do(__('Prepare a complete Media Library replacement plan before running Do.', 'ultracache'));
            }

            if ('do_complete' === $state['active_step'] && 'completed' === $state['run_status']) {
                return array_merge($this->get_media_library_replacement_do_status(), array(
                    'success'        => true,
                    'message'        => __('Do is already complete.', 'ultracache'),
                    'batchProcessed' => 0,
                ));
            }
            if ('do_failed' === $state['active_step']) {
                return $this->fail_media_library_replacement_do($state['last_error'] ?: __('Do failed. Use the recovery controls or restart the replacement plan.', 'ultracache'));
            }

            if (
                $this->media_replacement_do_requires_file_mutation_permission()
                && !$this->media_replacement_do_file_mutation_permission_allowed()
            ) {
                return $this->get_media_replacement_do_file_mutation_permission_blocked_response();
            }

            if ('prepare_complete' === $state['active_step']) {
                $guard = $this->get_media_library_replacement_pre_do_guard();
                if (empty($guard['allowed'])) {
                    return $this->fail_media_library_replacement_do((string) ($guard['message'] ?? __('The hard pre-Do guard no longer passes. Restart Prepare.', 'ultracache')));
                }
                list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
                if ($target_format !== $state['target_format'] || $fallback_format !== $state['fallback_format']) {
                    return $this->fail_media_library_replacement_do(__('The Media Library replacement policy changed after Prepare. Restart Prepare before running Do.', 'ultracache'));
                }

                $now = current_time('mysql', true);
                $state['status'] = 'applying_metadata';
                $state['run_status'] = 'running';
                $state['active_step'] = 'metadata_apply';
                $state['workflow_stage'] = 'do';
                $state['do_started_at'] = $now;
                $state['do_completed_at'] = '';
                $state['do_failed_step'] = '';
                $state['completed_at'] = '';
                $state['heartbeat_at'] = $now;
                $state['paused_at'] = '';
                $state['last_error'] = '';
                $state['workflow_message'] = __('Do started. Attachment metadata is being switched in resumable chunks.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $state = $this->update_media_replacement_workflow_state($state);
            } elseif (!in_array($state['active_step'], array('metadata_apply', 'database_recovery_scan', 'database_recovery_match', 'database_recovery_preview', 'database_apply', 'theme_css_apply'), true)) {
                return $this->fail_media_library_replacement_do(__('Do is in an unsupported state. Restart Prepare to build a clean replacement plan.', 'ultracache'));
            } else {
                $state['run_status'] = 'running';
                $state['workflow_stage'] = 'do';
                $state['heartbeat_at'] = current_time('mysql', true);
                $state['paused_at'] = '';
                $state['updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_workflow_state($state);
            }

            $active_step = $state['active_step'];
            $limit = max(1, min(250, absint($args['limit'] ?? 50)));
            $step_result = array('success' => true, 'hasMore' => false, 'message' => '');

            if ('metadata_apply' === $active_step) {
                $step_result = $this->apply_media_library_replacement_metadata_updates(array(
                    'limit'       => $limit,
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_recovery_scan' === $active_step) {
                $step_result = $this->scan_media_library_replacement_database_references(array(
                    'limit'       => max(50, $limit),
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_recovery_match' === $active_step) {
                $step_result = $this->match_media_library_replacement_database_references(array(
                    'limit'       => max(50, $limit),
                    'time_budget' => $time_budget,
                ));
            } elseif ('database_recovery_preview' === $active_step) {
                $step_result = $this->get_media_library_replacement_database_replacement_preview(array(
                    'limit'                   => 1,
                    'offset'                  => 0,
                    'issue_confirmation_token' => false,
                ));
            } elseif ('database_apply' === $active_step) {
                $database_summary = $this->get_media_replacement_database_apply_summary();
                $pending = max(0, (int) ($database_summary['pendingRefs'] ?? 0));
                if ($pending > 0) {
                    $database_args = $this->get_media_replacement_do_destructive_action_args(
                        'database_apply',
                        array(
                            'limit'       => $limit,
                            'time_budget' => $time_budget,
                        )
                    );
                    $step_result = $this->apply_media_library_replacement_database_replacements($database_args);
                } else {
                    $step_result = array('success' => true, 'hasMore' => false, 'message' => __('No pending database replacements remain.', 'ultracache'), 'batchProcessedRefs' => 0);
                }
            } elseif ('theme_css_apply' === $active_step) {
                if (!$this->media_replacement_do_file_mutation_permission_allowed()) {
                    return $this->get_media_replacement_do_file_mutation_permission_blocked_response();
                }

                $theme_summary = $this->get_media_replacement_theme_css_summary();
                $pending = max(0, (int) ($theme_summary['pending'] ?? 0));
                if ($pending > 0) {
                    $theme_css_args = $this->get_media_replacement_do_destructive_action_args(
                        'theme_css_apply',
                        array(
                            'limit'       => min(100, $limit),
                            'time_budget' => $time_budget,
                        )
                    );
                    $step_result = $this->apply_media_library_replacement_theme_css_replacements($theme_css_args);
                } else {
                    $step_result = array('success' => true, 'hasMore' => false, 'message' => __('No pending Theme CSS replacements remain.', 'ultracache'), 'batchProcessedThemeCssRefs' => 0);
                }
            }

            if (empty($step_result['success'])) {
                $message = (string) ($step_result['message'] ?? __('Do chunk failed.', 'ultracache'));
                if (!empty($step_result['retryRequired'])
                    || $this->is_media_replacement_confirmation_retry_status((string) ($step_result['status'] ?? ''))) {
                    return $this->pause_media_library_replacement_do_for_retry($message);
                }
                return $this->fail_media_library_replacement_do($message);
            }

            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if ('database_recovery_scan' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'matching_database';
                $state['active_step'] = 'database_recovery_match';
                $state['workflow_message'] = __('The rebuilt database reference index is complete. Recovery is matching unresolved references.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_recovery_match' === $active_step && empty($step_result['hasMore'])) {
                $state['status'] = 'planning_database';
                $state['active_step'] = 'database_recovery_preview';
                $state['workflow_message'] = __('Unresolved database reference matching is complete. Recovery is finalizing the replacement plan.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_recovery_preview' === $active_step) {
                $database_summary = $this->get_media_replacement_database_apply_summary();
                $database_pending = max(0, (int) ($database_summary['pendingRefs'] ?? 0));
                $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
                $state['status'] = $database_pending > 0 ? 'applying_database' : 'applying_theme';
                $state['active_step'] = $database_pending > 0 ? 'database_apply' : 'theme_css_apply';
                $state['workflow_message'] = $database_pending > 0
                    ? __('The unresolved database replacement plan was rebuilt. Do is applying only the remaining content references.', 'ultracache')
                    : __('The rebuilt database plan contains no unresolved content references. Do is resuming the remaining replacement phases.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('metadata_apply' === $active_step && empty($step_result['hasMore'])) {
                $summary = $this->get_media_replacement_metadata_apply_summary();
                $metadata_failures = max(0, (int) ($summary['metadataFailed'] ?? 0)) + max(0, (int) ($summary['failed'] ?? 0));
                if ($metadata_failures > 0) {
                    /* translators: %d: failed attachment metadata plan count. */
                    return $this->fail_media_library_replacement_do(sprintf(__('Do stopped because %d attachment metadata plans failed.', 'ultracache'), $metadata_failures));
                }
                $state['status'] = 'applying_database';
                $state['active_step'] = 'database_apply';
                $state['workflow_message'] = __('Attachment metadata is switched. Do is applying matched database replacements.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('database_apply' === $active_step && empty($step_result['hasMore'])) {
                $this->clear_media_replacement_destructive_authorization('database_apply');
                $summary = $this->get_media_replacement_database_apply_summary();
                $database_failures = max(0, (int) ($summary['failedRefs'] ?? 0));
                if ($database_failures > 0) {
                    /* translators: %d: failed database replacement row count. */
                    return $this->fail_media_library_replacement_do(sprintf(__('Do stopped because %d database replacement rows failed.', 'ultracache'), $database_failures));
                }
                $state['status'] = 'applying_theme';
                $state['active_step'] = 'theme_css_apply';
                $state['workflow_message'] = __('Database replacements are applied. Do is applying matched Theme CSS replacements.', 'ultracache');
                $state['workflow_updated_at'] = current_time('mysql', true);
                $state['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($state);
            } elseif ('theme_css_apply' === $active_step && empty($step_result['hasMore'])) {
                $this->clear_media_replacement_destructive_authorization('theme_css_apply');
                $summary = $this->get_media_replacement_theme_css_summary();
                $theme_failures = max(0, (int) ($summary['failed'] ?? 0));
                if ($theme_failures > 0) {
                    /* translators: %d: failed Theme CSS replacement row count. */
                    return $this->fail_media_library_replacement_do(sprintf(__('Do stopped because %d Theme CSS replacement rows failed.', 'ultracache'), $theme_failures));
                }
                $flush_all = $this->flush_all_after_media_library_replacement_do();
                if (empty($flush_all['success'])) {
                    return $this->fail_media_library_replacement_do(
                        isset($flush_all['message']) ? (string) $flush_all['message'] : __('Flush All failed after Media Library replacement.', 'ultracache')
                    );
                }

                $now = current_time('mysql', true);
                $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
                $database_summary = $this->get_media_replacement_database_apply_summary();
                $state['status'] = 'completed';
                $state['run_status'] = 'completed';
                $state['active_step'] = 'do_complete';
                $state['workflow_stage'] = 'verify';
                $state['do_completed_at'] = $now;
                $state['do_failed_step'] = '';
                $state['completed_at'] = $now;
                $state['heartbeat_at'] = $now;
                $state['last_error'] = '';
                $state['workflow_message'] = sprintf(
                    /* translators: 1: attachment metadata rows, 2: site-content database references, 3: excluded internal references. */
                    __('Media Library replacement completed: %1$d attachment metadata records and %2$d site-content references updated; %3$d UltraCache internal references excluded. Flush All completed. Run Verify before deleting original JPG/PNG files.', 'ultracache'),
                    max(0, (int) ($this->get_media_replacement_metadata_apply_summary()['metadataUpdated'] ?? 0)),
                    max(0, (int) ($database_summary['replacedRefs'] ?? 0)),
                    max(0, (int) ($database_summary['excludedRefs'] ?? 0))
                );
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $this->update_media_replacement_workflow_state($state);
            }

            $do = $this->get_media_library_replacement_do_status();
            $batch_processed = max(0, (int) ($step_result['batchUpdated'] ?? 0))
                + max(0, (int) ($step_result['batchFailed'] ?? 0))
                + max(0, (int) ($step_result['batchScannedRows'] ?? 0))
                + max(0, (int) ($step_result['batchProcessedRefs'] ?? 0))
                + max(0, (int) ($step_result['batchProcessedThemeCssRefs'] ?? 0));
            return array_merge($do, array(
                'success'        => true,
                'message'        => (string) ($step_result['message'] ?? $do['message']),
                'batchProcessed' => $batch_processed,
                'step'           => (array) $step_result,
            ));
        } catch (Throwable $error) {
            return $this->pause_media_library_replacement_do_for_retry(sanitize_text_field((string) $error->getMessage()));
        } finally {
            $this->release_media_replacement_do_chunk_lock($chunk_lock);
        }
    }


    private function get_media_replacement_verify_item_rows($after_item_id = 0, $limit = 50)
    {
        global $wpdb;

        $items_table = $this->get_media_replacement_items_table_name();
        $after_item_id = max(0, absint($after_item_id));
        $limit = max(1, min(250, absint($limit)));
        if ('' === $items_table || !($wpdb instanceof wpdb)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, item_scope, size_name, target_format, generated_file_path, new_relative_path, new_file_path, new_mime, new_metadata_json, status FROM %i WHERE status IN (%s, %s) AND id > %d ORDER BY id ASC LIMIT %d',
                $items_table,
                'metadata_updated',
                'refs_scanned',
                $after_item_id,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private function verify_media_replacement_metadata_row(array $row)
    {
        $attachment_id = absint($row['attachment_id'] ?? 0);
        $item_scope = sanitize_key((string) ($row['item_scope'] ?? 'main'));
        $size_name = substr(sanitize_key((string) ($row['size_name'] ?? '')), 0, 64);
        $new_relative = ltrim(str_replace('\\', '/', (string) ($row['new_relative_path'] ?? '')), '/');
        $new_mime = sanitize_mime_type((string) ($row['new_mime'] ?? ''));
        $plan = $this->decode_media_replacement_json_array((string) ($row['new_metadata_json'] ?? ''));

        if ($attachment_id <= 0 || '' === $new_relative || '' === $new_mime || empty($plan)) {
            return array('verified' => false, 'message' => __('The prepared attachment metadata verification plan is incomplete.', 'ultracache'));
        }

        if ('intermediate' === $item_scope) {
            if ('' === $size_name) {
                return array('verified' => false, 'message' => __('The intermediate attachment metadata size name is missing.', 'ultracache'));
            }
            $verified = $this->verify_media_replacement_intermediate_metadata_switch($attachment_id, $size_name, $new_relative, $new_mime);
        } else {
            $new_attached_file = ltrim(str_replace('\\', '/', (string) ($plan['new_attached_file'] ?? $new_relative)), '/');
            $planned_metadata = isset($plan['planned_metadata']) && is_array($plan['planned_metadata']) ? $plan['planned_metadata'] : array();
            $verified = '' !== $new_attached_file
                && !empty($planned_metadata)
                && $this->verify_media_replacement_attachment_metadata_switch($attachment_id, $new_attached_file, $new_mime, $planned_metadata);
        }

        return $verified
            ? array('verified' => true, 'message' => '')
            : array('verified' => false, 'message' => __('Attachment metadata no longer matches the prepared replacement state.', 'ultracache'));
    }

    private function get_media_replacement_verify_cleanup_facts()
    {
        $mapping = $this->get_media_replacement_preview_summary();
        $database = $this->get_media_replacement_database_verify_summary();
        $theme = $this->get_media_replacement_theme_css_summary();
        $ref_state = $this->get_media_replacement_ref_index_state();
        $ref_index = $this->get_media_replacement_ref_match_summary();
        $theme_state = $this->get_media_replacement_theme_css_scan_state();

        $total_items = max(0, (int) ($mapping['total'] ?? 0));
        $candidate_items = max(0, (int) ($mapping['metadata_updated'] ?? 0) + (int) ($mapping['refs_scanned'] ?? 0));
        $blocked_items = max(0, $total_items - $candidate_items);
        $metadata_ready = $total_items > 0 && $candidate_items === $total_items;

        $db_total = max(0, (int) ($database['totalRefs'] ?? 0));
        $db_verified = max(0, (int) ($database['verifiedRefs'] ?? 0));
        $db_pending = max(0, (int) ($database['pendingVerify'] ?? 0)) + max(0, (int) ($database['pendingRefs'] ?? 0));
        $db_failed = max(0, (int) ($database['verifyFailedRefs'] ?? 0)) + max(0, (int) ($database['failedRefs'] ?? 0));
        $db_index_completed = isset($ref_state['status'])
            && 'completed' === (string) $ref_state['status'];
        $db_index_pending = max(0, (int) ($ref_index['indexedPending'] ?? 0))
            + max(0, (int) ($ref_index['unmatchedRelevant'] ?? 0))
            + max(0, (int) ($ref_index['failedIndexed'] ?? 0));
        $database_ready = $db_index_completed
            && 0 === $db_index_pending
            && 0 === $db_pending
            && 0 === $db_failed
            && (0 === $db_total || $db_verified === $db_total);

        $theme_total = max(0, (int) ($theme['total'] ?? 0));
        $theme_verified = max(0, (int) ($theme['verified'] ?? 0));
        $theme_pending = max(0, (int) ($theme['pending'] ?? 0)) + max(0, (int) ($theme['applied'] ?? 0));
        $theme_failed = max(0, (int) ($theme['failed'] ?? 0)) + max(0, (int) ($theme['verifyFailed'] ?? 0));
        $theme_scan_completed = isset($theme_state['status'])
            && 'completed' === (string) $theme_state['status'];
        $theme_ready = $theme_scan_completed
            && 0 === $theme_pending
            && 0 === $theme_failed
            && (0 === $theme_total || $theme_verified === $theme_total);

        $summary = array(
            'totalItems'             => $total_items,
            'candidateItems'         => $candidate_items,
            'blockedItems'           => $blocked_items,
            'metadataReadyByStatus'  => $metadata_ready,
            'databaseIndexCompleted' => $db_index_completed,
            'databaseIndexedPending' => $db_index_pending,
            'databaseIndexedIgnored' => max(0, (int) ($ref_index['unmatchedIgnored'] ?? 0)),
            'databaseRefs'           => $db_total,
            'databaseVerifiedRefs'   => $db_verified,
            'databasePendingRefs'    => $db_pending,
            'databaseFailedRefs'     => $db_failed,
            'databaseReady'          => $database_ready,
            'themeCssRefs'           => $theme_total,
            'themeCssVerifiedRefs'   => $theme_verified,
            'themeCssPendingRefs'    => $theme_pending,
            'themeCssFailedRefs'     => $theme_failed,
            'themeCssReady'          => $theme_ready,
            'cleanupFailedItems'     => max(0, (int) ($mapping['cleanup_failed'] ?? 0)),
            'potentialFreeBytes'     => 0,
        );
        $summary['cleanupReady'] = $metadata_ready && $database_ready && $theme_ready && 0 === $summary['cleanupFailedItems'] && 0 === $blocked_items;
        return $summary;
    }

    private function get_media_replacement_verify_cleanup_blockers(array $summary)
    {
        $blockers = array();
        $add = static function (&$items, $code, $message, $count = 0) {
            $items[] = array(
                'code'    => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
                'count'   => max(0, (int) $count),
            );
        };

        if (empty($summary['metadataReadyByStatus'])) {
            $add($blockers, 'metadata_not_ready', __('Attachment metadata rows are not all in a cleanup-ready state.', 'ultracache'), (int) ($summary['blockedItems'] ?? 0));
        }
        if (empty($summary['databaseIndexCompleted'])) {
            $add($blockers, 'database_index_incomplete', __('The database reference index is not complete for this workflow.', 'ultracache'));
        }
        if (!empty($summary['databaseIndexedPending'])) {
            $add($blockers, 'database_index_pending', __('Indexed database references remain unmatched or pending.', 'ultracache'), (int) $summary['databaseIndexedPending']);
        }
        if (empty($summary['databaseReady'])) {
            $count = (int) ($summary['databasePendingRefs'] ?? 0) + (int) ($summary['databaseFailedRefs'] ?? 0) + (int) ($summary['databaseRollbackFailedRefs'] ?? 0);
            $add($blockers, 'database_verification_incomplete', __('Database replacements are not fully verified.', 'ultracache'), $count);
        }
        if (empty($summary['themeCssReady'])) {
            $count = (int) ($summary['themeCssPendingRefs'] ?? 0) + (int) ($summary['themeCssFailedRefs'] ?? 0);
            $add($blockers, 'theme_css_verification_incomplete', __('Theme CSS replacements are not fully verified.', 'ultracache'), $count);
        }
        if (!empty($summary['cleanupFailedItems'])) {
            $add($blockers, 'cleanup_failed_rows', __('Some replacement rows are already marked as cleanup failures.', 'ultracache'), (int) $summary['cleanupFailedItems']);
        }
        if (!empty($summary['blockedItems'])) {
            $add($blockers, 'cleanup_blocked_rows', __('Some replacement rows are not eligible for original-file cleanup.', 'ultracache'), (int) $summary['blockedItems']);
        }

        return $blockers;
    }

    public function get_media_library_replacement_verify_status()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $mapping = $this->get_media_replacement_preview_summary();
        $database = $this->get_media_replacement_database_verify_summary();
        $theme = $this->get_media_replacement_theme_css_summary();
        $cleanup = array(
            'cleanupReady'      => !empty($state['verify_cleanup_ready']),
            'candidateItems'    => (int) $state['verify_cleanup_candidates'],
            'blockedItems'      => (int) $state['verify_cleanup_blocked_items'],
            'potentialFreeBytes'=> (int) $state['verify_cleanup_potential_free_bytes'],
        );

        $item_total = max(0, (int) ($mapping['metadata_updated'] ?? 0) + (int) ($mapping['refs_scanned'] ?? 0));
        $db_total = max(0, (int) ($database['totalRefs'] ?? 0));
        $db_verified = max(0, (int) ($database['verifiedRefs'] ?? 0));
        $db_failed = max(0, (int) ($database['verifyFailedRefs'] ?? 0)) + max(0, (int) ($database['failedRefs'] ?? 0)) + max(0, (int) ($database['pendingRefs'] ?? 0));
        $theme_total = max(0, (int) ($theme['total'] ?? 0));
        $theme_verified = max(0, (int) ($theme['verified'] ?? 0));
        $theme_failed = max(0, (int) ($theme['verifyFailed'] ?? 0)) + max(0, (int) ($theme['failed'] ?? 0)) + max(0, (int) ($theme['pending'] ?? 0)) + max(0, (int) ($theme['applied'] ?? 0));
        $cleanup_ready = !empty($cleanup['cleanupReady']);
        $cleanup_blockers = (array) $state['verify_cleanup_blockers'];

        $complete = 'verify_complete' === $state['active_step']
            && 'completed' === $state['run_status']
            && '' !== $state['verify_completed_at']
            && '' !== $state['workflow_verified_at'];
        $failed = 'verify_failed' === $state['active_step']
            || ('failed' === $state['run_status'] && 'verify' === $state['workflow_stage']);
        $active = in_array($state['active_step'], array('destination_verify', 'metadata_verify', 'database_verify', 'theme_css_verify', 'cleanup_preview'), true);
        $can_start = 'do_complete' === $state['active_step'] && 'completed' === $state['run_status'] && '' !== $state['do_completed_at'];
        $has_more = $this->media_replacement_has_registry_rows() && !$complete && ($can_start || $active || $failed);

        $total = ($item_total * 2) + $db_total + $theme_total + 1;
        $processed = min($item_total, (int) $state['verify_destination_checked'])
            + min($item_total, (int) $state['verify_metadata_checked'])
            + min($db_total, $db_verified + max(0, (int) ($database['verifyFailedRefs'] ?? 0)))
            + min($theme_total, $theme_verified + max(0, (int) ($theme['verifyFailed'] ?? 0)))
            + ($complete || 'cleanup_preview' === $state['active_step'] ? 1 : 0);

        $message = __('Verify has not started.', 'ultracache');
        if ($failed) {
            $message = $state['last_error'] ?: __('Verify found blockers.', 'ultracache');
        } elseif ($complete) {
            $message = __('Verify is complete. Metadata, destination files, database references, and Theme CSS are consistent.', 'ultracache');
        } elseif ('destination_verify' === $state['active_step']) {
            $message = __('Verify is checking every destination replacement file.', 'ultracache');
        } elseif ('metadata_verify' === $state['active_step']) {
            $message = __('Verify is checking attachment metadata in resumable chunks.', 'ultracache');
        } elseif ('database_verify' === $state['active_step']) {
            $message = __('Verify is checking applied database replacements in resumable chunks.', 'ultracache');
        } elseif ('theme_css_verify' === $state['active_step']) {
            $message = __('Verify is checking applied Theme CSS replacements in resumable chunks.', 'ultracache');
        } elseif ('cleanup_preview' === $state['active_step']) {
            $message = __('Verify is calculating authoritative cleanup readiness.', 'ultracache');
        } elseif ($can_start) {
            $message = __('Do is complete. Verify is ready to start.', 'ultracache');
        }

        return array(
            'success'                 => true,
            'status'                  => $state['status'],
            'runStatus'               => $state['run_status'],
            'activeStep'              => $state['active_step'],
            'message'                 => $message,
            'hasMore'                 => $has_more,
            'verifyReady'             => $can_start || $active || $failed,
            'verifyComplete'          => $complete,
            'verifyFailed'            => $failed,
            'processed'               => $processed,
            'total'                   => $total,
            'progressPercent'         => $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : ($complete ? 100 : 0),
            'destinationTotal'        => $item_total,
            'destinationChecked'      => (int) $state['verify_destination_checked'],
            'destinationFailed'       => (int) $state['verify_destination_failed'],
            'metadataTotal'           => $item_total,
            'metadataChecked'         => (int) $state['verify_metadata_checked'],
            'metadataFailed'          => (int) $state['verify_metadata_failed'],
            'databaseTotal'           => $db_total,
            'databaseVerified'        => $db_verified,
            'databaseFailed'          => $db_failed,
            'themeCssTotal'           => $theme_total,
            'themeCssVerified'        => $theme_verified,
            'themeCssFailed'          => $theme_failed,
            'cleanupReady'            => $cleanup_ready,
            'cleanupCandidates'       => max(0, (int) ($cleanup['candidateItems'] ?? 0)),
            'cleanupBlockedItems'     => max(0, (int) ($cleanup['blockedItems'] ?? 0)),
            'cleanupPotentialFreeBytes' => max(0, (int) ($cleanup['potentialFreeBytes'] ?? 0)),
            'cleanupBlockers'         => $cleanup_blockers,
            'verifyStartedAt'         => $state['verify_started_at'],
            'verifyCompletedAt'       => $state['verify_completed_at'],
        );
    }

    private function fail_media_library_replacement_verify($message, array $blockers = array())
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'verify_failed';
            $state['workflow_stage'] = 'verify';
            $state['workflow_verified_at'] = '';
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_verify_status(), array(
            'success'  => false,
            'blocked'  => true,
            'message'  => sanitize_text_field((string) $message),
            'blockers' => $blockers,
        ));
    }

    private function pause_media_library_replacement_verify_for_retry($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            $state['run_status'] = 'paused';
            $state['paused_at'] = current_time('mysql', true);
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_stage'] = 'verify';
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_verify_status(), array(
            'success'       => false,
            'blocked'       => true,
            'retryRequired' => true,
            'message'       => sanitize_text_field((string) $message),
        ));
    }

    public function run_media_library_replacement_verify_chunk($args = array())
    {
        $args = is_array($args) ? $args : array();
        $session_token = $this->normalize_media_replacement_manual_session_token((string) ($args['session_token'] ?? $args['sessionToken'] ?? ''));
        $session = $this->validate_media_replacement_session_token($session_token, 'verify');
        if (empty($session['success'])) {
            return $session;
        }

        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $limit = max(1, min(250, absint($args['limit'] ?? 50)));
        $chunk_lock = $this->acquire_media_replacement_verify_chunk_lock((int) ceil($time_budget) + 15);
        if ('' === $chunk_lock) {
            return array(
                'success' => false,
                'blocked' => true,
                'status'  => 'verify_chunk_locked',
                'message' => __('Another Media Library replacement Verify chunk is still running. Retry after it finishes.', 'ultracache'),
            );
        }

        try {
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if (!$this->media_replacement_has_registry_rows()) {
                return $this->fail_media_library_replacement_verify(__('Run a complete Prepare and Do workflow before Verify.', 'ultracache'));
            }

            if ('verify_complete' === $state['active_step'] && 'completed' === $state['run_status']) {
                return array_merge($this->get_media_library_replacement_verify_status(), array(
                    'success'        => true,
                    'message'        => __('Verify is already complete.', 'ultracache'),
                    'batchProcessed' => 0,
                ));
            }

            if ('do_complete' === $state['active_step'] || 'verify_failed' === $state['active_step']) {
                if ('' === $state['do_completed_at']) {
                    return $this->fail_media_library_replacement_verify(__('Do must be complete before Verify can start.', 'ultracache'));
                }
                list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
                if ($target_format !== $state['target_format'] || $fallback_format !== $state['fallback_format']) {
                    return $this->fail_media_library_replacement_verify(__('The Media Library replacement policy changed after Do. Restart the replacement plan.', 'ultracache'));
                }

                $now = current_time('mysql', true);
                $state['status'] = 'verifying_files';
                $state['run_status'] = 'running';
                $state['active_step'] = 'destination_verify';
                $state['workflow_stage'] = 'verify';
                $state['workflow_verified_at'] = '';
                $state['verify_started_at'] = $now;
                $state['verify_completed_at'] = '';
                $state['verify_destination_cursor_item_id'] = 0;
                $state['verify_destination_checked'] = 0;
                $state['verify_destination_failed'] = 0;
                $state['verify_metadata_cursor_item_id'] = 0;
                $state['verify_metadata_checked'] = 0;
                $state['verify_metadata_failed'] = 0;
                $state['verify_cleanup_ready'] = false;
                $state['verify_cleanup_candidates'] = 0;
                $state['verify_cleanup_blocked_items'] = 0;
                $state['verify_cleanup_potential_free_bytes'] = 0;
                $state['verify_cleanup_blockers'] = array();
                $state['heartbeat_at'] = $now;
                $state['paused_at'] = '';
                $state['last_error'] = '';
                $state['workflow_message'] = __('Verify started. Destination replacement files are being checked.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $state = $this->update_media_replacement_workflow_state($state);
            } elseif (!in_array($state['active_step'], array('destination_verify', 'metadata_verify', 'database_verify', 'theme_css_verify', 'cleanup_preview'), true)) {
                return $this->fail_media_library_replacement_verify(__('Verify is in an unsupported state. Restart the replacement plan.', 'ultracache'));
            } else {
                $state['run_status'] = 'running';
                $state['workflow_stage'] = 'verify';
                $state['heartbeat_at'] = current_time('mysql', true);
                $state['paused_at'] = '';
                $state['updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_workflow_state($state);
            }

            $deadline = microtime(true) + $time_budget;
            $batch_processed = 0;
            $step_result = array();

            if ('destination_verify' === $state['active_step']) {
                $rows = $this->get_media_replacement_verify_item_rows($state['verify_destination_cursor_item_id'], $limit);
                $last_id = $state['verify_destination_cursor_item_id'];
                $checked = 0;
                $failed = 0;
                foreach ($rows as $row) {
                    if ($checked > 0 && microtime(true) >= $deadline) {
                        break;
                    }
                    $result = $this->validate_media_replacement_destination_row($row);
                    $checked++;
                    if (empty($result['valid'])) {
                        $failed++;
                    }
                    $last_id = max($last_id, absint($row['id'] ?? 0));
                }
                $state['verify_destination_cursor_item_id'] = $last_id;
                $state['verify_destination_checked'] += $checked;
                $state['verify_destination_failed'] += $failed;
                $batch_processed = $checked;
                $has_more = !empty($this->get_media_replacement_verify_item_rows($last_id, 1));
                if ($state['verify_destination_failed'] > 0) {
                    $state = $this->update_media_replacement_workflow_state($state);
                    return $this->fail_media_library_replacement_verify(
                        /* translators: %d: failed destination replacement file count. */
                        sprintf(__('Verify stopped because %d destination replacement files are missing, invalid, or no longer match the rewrite output.', 'ultracache'), (int) $state['verify_destination_failed']),
                        array(array('code' => 'destination_verification_failed', 'message' => __('Destination replacement file verification failed.', 'ultracache'), 'count' => (int) $state['verify_destination_failed']))
                    );
                }
                if (!$has_more) {
                    $state['status'] = 'verifying_metadata';
                    $state['active_step'] = 'metadata_verify';
                    $state['workflow_message'] = __('Destination files are verified. Attachment metadata verification is next.', 'ultracache');
                }
                $step_result = array('message' => $has_more ? __('Destination replacement file verification is in progress.', 'ultracache') : __('All destination replacement files are verified.', 'ultracache'));
            } elseif ('metadata_verify' === $state['active_step']) {
                $rows = $this->get_media_replacement_verify_item_rows($state['verify_metadata_cursor_item_id'], $limit);
                $last_id = $state['verify_metadata_cursor_item_id'];
                $checked = 0;
                $failed = 0;
                foreach ($rows as $row) {
                    if ($checked > 0 && microtime(true) >= $deadline) {
                        break;
                    }
                    $result = $this->verify_media_replacement_metadata_row($row);
                    $checked++;
                    if (empty($result['verified'])) {
                        $failed++;
                    }
                    $last_id = max($last_id, absint($row['id'] ?? 0));
                }
                $state['verify_metadata_cursor_item_id'] = $last_id;
                $state['verify_metadata_checked'] += $checked;
                $state['verify_metadata_failed'] += $failed;
                $batch_processed = $checked;
                $has_more = !empty($this->get_media_replacement_verify_item_rows($last_id, 1));
                if ($state['verify_metadata_failed'] > 0) {
                    $state = $this->update_media_replacement_workflow_state($state);
                    return $this->fail_media_library_replacement_verify(
                        /* translators: %d: failed attachment metadata verification row count. */
                        sprintf(__('Verify stopped because %d attachment metadata rows no longer match the prepared replacement state.', 'ultracache'), (int) $state['verify_metadata_failed']),
                        array(array('code' => 'metadata_verification_failed', 'message' => __('Attachment metadata verification failed.', 'ultracache'), 'count' => (int) $state['verify_metadata_failed']))
                    );
                }
                if (!$has_more) {
                    $state['status'] = 'verifying_database';
                    $state['active_step'] = 'database_verify';
                    $state['workflow_message'] = __('Attachment metadata is verified. Database replacement verification is next.', 'ultracache');
                }
                $step_result = array('message' => $has_more ? __('Attachment metadata verification is in progress.', 'ultracache') : __('All attachment metadata rows are verified.', 'ultracache'));
            } elseif ('database_verify' === $state['active_step']) {
                $step_result = $this->verify_media_library_replacement_database_replacements(array('limit' => $limit));
                $batch_processed = max(0, (int) ($step_result['batchProcessedRefs'] ?? 0));
                if (empty($step_result['success'])) {
                    return $this->pause_media_library_replacement_verify_for_retry((string) ($step_result['message'] ?? __('Database verification could not continue.', 'ultracache')));
                }
                $db_summary = $this->get_media_replacement_database_verify_summary();
                $db_failures = max(0, (int) ($db_summary['verifyFailedRefs'] ?? 0)) + max(0, (int) ($db_summary['failedRefs'] ?? 0)) + max(0, (int) ($db_summary['pendingRefs'] ?? 0));
                if ($db_failures > 0) {
                    return $this->fail_media_library_replacement_verify(
                        /* translators: %d: failed or unapplied database reference count. */
                        sprintf(__('Verify stopped because %d database replacement references failed or remain unapplied.', 'ultracache'), $db_failures),
                        array(array('code' => 'database_verification_failed', 'message' => __('Database replacement verification failed.', 'ultracache'), 'count' => $db_failures))
                    );
                }
                if (empty($step_result['hasMore'])) {
                    $state['status'] = 'verifying_theme';
                    $state['active_step'] = 'theme_css_verify';
                    $state['workflow_message'] = __('Database replacements are verified. Theme CSS verification is next.', 'ultracache');
                }
            } elseif ('theme_css_verify' === $state['active_step']) {
                $step_result = $this->verify_media_library_replacement_theme_css_replacements(array('limit' => $limit));
                $batch_processed = max(0, (int) ($step_result['batchProcessedThemeCssRefs'] ?? 0));
                if (empty($step_result['success'])) {
                    return $this->pause_media_library_replacement_verify_for_retry((string) ($step_result['message'] ?? __('Theme CSS verification could not continue.', 'ultracache')));
                }
                $theme_summary = $this->get_media_replacement_theme_css_summary();
                $theme_failures = max(0, (int) ($theme_summary['verifyFailed'] ?? 0)) + max(0, (int) ($theme_summary['failed'] ?? 0)) + max(0, (int) ($theme_summary['pending'] ?? 0));
                if ($theme_failures > 0) {
                    return $this->fail_media_library_replacement_verify(
                        /* translators: %d: failed or unapplied Theme CSS reference count. */
                        sprintf(__('Verify stopped because %d Theme CSS references failed or remain unapplied.', 'ultracache'), $theme_failures),
                        array(array('code' => 'theme_css_verification_failed', 'message' => __('Theme CSS replacement verification failed.', 'ultracache'), 'count' => $theme_failures))
                    );
                }
                if (empty($step_result['hasMore'])) {
                    $state['status'] = 'planning_cleanup';
                    $state['active_step'] = 'cleanup_preview';
                    $state['workflow_message'] = __('Theme CSS replacements are verified. Cleanup readiness is being calculated.', 'ultracache');
                }
            } elseif ('cleanup_preview' === $state['active_step']) {
                $summary = $this->get_media_replacement_verify_cleanup_facts();
                $batch_processed = 1;
                $blockers = $this->get_media_replacement_verify_cleanup_blockers($summary);
                $state['verify_cleanup_ready'] = !empty($summary['cleanupReady']);
                $state['verify_cleanup_candidates'] = max(0, (int) ($summary['candidateItems'] ?? 0));
                $state['verify_cleanup_blocked_items'] = max(0, (int) ($summary['blockedItems'] ?? 0));
                $state['verify_cleanup_potential_free_bytes'] = max(0, (int) ($summary['potentialFreeBytes'] ?? 0));
                $state['verify_cleanup_blockers'] = $blockers;
                $state = $this->update_media_replacement_workflow_state($state);
                if (empty($summary['cleanupReady']) || !empty($blockers)) {
                    return $this->fail_media_library_replacement_verify(__('Verify completed its checks but cleanup remains blocked.', 'ultracache'), $blockers);
                }

                $now = current_time('mysql', true);
                $state['status'] = 'completed';
                $state['run_status'] = 'completed';
                $state['active_step'] = 'verify_complete';
                $state['workflow_stage'] = !empty($summary['candidateItems']) ? 'delete' : 'complete';
                $state['workflow_verified_at'] = $now;
                $state['verify_completed_at'] = $now;
                $state['verified_plan_fingerprint'] = (string) $state['pre_do_plan_fingerprint'];
                $state['delete_started_at'] = '';
                $state['delete_completed_at'] = '';
                $state['delete_authorized_at'] = '';
                $state['delete_authorized_user_id'] = 0;
                unset($state['confirmation_tokens']['cleanup_apply']);
                $state['completed_at'] = $now;
                $state['heartbeat_at'] = $now;
                $state['paused_at'] = '';
                $state['last_error'] = '';
                $state['workflow_message'] = !empty($summary['candidateItems'])
                    ? __('Verify is complete. Delete Originals is unlocked for the verified cleanup candidates.', 'ultracache')
                    : __('Verify is complete. No original cleanup candidates remain.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $state = $this->update_media_replacement_workflow_state($state);
                $confirmation_tokens = array();
                if (!empty($summary['candidateItems'])) {
                    $confirmation_tokens = $this->issue_media_replacement_confirmation_token('cleanup_apply');
                }
                $cleanup_preview = array(
                    'success'             => true,
                            'hasCleanupPreview'   => true,
                    'summary'             => $summary,
                    'items'               => array(),
                    'cleanupReady'        => true,
                    'cleanupCandidates'   => (int) $summary['candidateItems'],
                    'cleanupBlockedItems' => (int) $summary['blockedItems'],
                    'cleanupPotentialFreeBytes' => (int) $summary['potentialFreeBytes'],
                    'status'              => 'cleanup_ready',
                    'message'             => $state['workflow_message'],
                    'confirmationTokens'  => $confirmation_tokens,
                );
                return array_merge($this->get_media_library_replacement_verify_status(), array(
                    'success'            => true,
                    'message'            => $state['workflow_message'],
                    'batchProcessed'     => 1,
                    'confirmationTokens' => $this->get_media_replacement_confirmation_tokens_for_response(),
                    'cleanupPreview'     => $cleanup_preview,
                ));
            }

            $state['heartbeat_at'] = current_time('mysql', true);
            $state['workflow_stage'] = 'verify';
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_workflow_state($state);
            $verify = $this->get_media_library_replacement_verify_status();
            return array_merge($verify, array(
                'success'        => true,
                'message'        => (string) ($step_result['message'] ?? $verify['message']),
                'batchProcessed' => $batch_processed,
                'step'           => (array) $step_result,
            ));
        } catch (Throwable $error) {
            return $this->pause_media_library_replacement_verify_for_retry(sanitize_text_field((string) $error->getMessage()));
        } finally {
            $this->release_media_replacement_verify_chunk_lock($chunk_lock);
        }
    }


    private function get_media_replacement_delete_facts()
    {
        $mapping = $this->get_media_replacement_preview_summary();
        $database = $this->get_media_replacement_database_verify_summary();
        $theme = $this->get_media_replacement_theme_css_summary();
        $ref_state = $this->get_media_replacement_ref_index_state();
        $ref_index = $this->get_media_replacement_ref_match_summary();
        $theme_state = $this->get_media_replacement_theme_css_scan_state();

        $total = max(0, (int) ($mapping['total'] ?? 0));
        $remaining = max(0, (int) ($mapping['metadata_updated'] ?? 0) + (int) ($mapping['refs_scanned'] ?? 0));
        $deleted = max(0, (int) ($mapping['cleanup_deleted'] ?? 0));
        $failed = max(0, (int) ($mapping['cleanup_failed'] ?? 0));
        $blocked = max(0, $total - $remaining - $deleted - $failed);

        $db_total = max(0, (int) ($database['totalRefs'] ?? 0));
        $db_verified = max(0, (int) ($database['verifiedRefs'] ?? 0));
        $db_pending = max(0, (int) ($database['pendingVerify'] ?? 0)) + max(0, (int) ($database['pendingRefs'] ?? 0));
        $db_failed = max(0, (int) ($database['verifyFailedRefs'] ?? 0)) + max(0, (int) ($database['failedRefs'] ?? 0));
        $db_index_completed = isset($ref_state['status'])
            && 'completed' === (string) $ref_state['status'];
        $db_index_pending = max(0, (int) ($ref_index['indexedPending'] ?? 0))
            + max(0, (int) ($ref_index['unmatchedRelevant'] ?? 0))
            + max(0, (int) ($ref_index['failedIndexed'] ?? 0));
        $database_ready = $db_index_completed
            && 0 === $db_index_pending
            && 0 === $db_pending
            && 0 === $db_failed
            && (0 === $db_total || $db_verified === $db_total);

        $theme_total = max(0, (int) ($theme['total'] ?? 0));
        $theme_verified = max(0, (int) ($theme['verified'] ?? 0));
        $theme_pending = max(0, (int) ($theme['pending'] ?? 0)) + max(0, (int) ($theme['applied'] ?? 0));
        $theme_failed = max(0, (int) ($theme['failed'] ?? 0)) + max(0, (int) ($theme['verifyFailed'] ?? 0));
        $theme_scan_completed = isset($theme_state['status'])
            && 'completed' === (string) $theme_state['status'];
        $theme_ready = $theme_scan_completed
            && 0 === $theme_pending
            && 0 === $theme_failed
            && (0 === $theme_total || $theme_verified === $theme_total);

        return array(
            'totalItems'             => $total,
            'remainingItems'         => $remaining,
            'deletedItems'           => $deleted,
            'failedItems'            => $failed,
            'blockedItems'           => $blocked,
            'databaseReady'          => $database_ready,
            'databaseIndexCompleted' => $db_index_completed,
            'databaseIndexedPending' => $db_index_pending,
            'databaseIndexedIgnored' => max(0, (int) ($ref_index['unmatchedIgnored'] ?? 0)),
            'databaseTotal'          => $db_total,
            'databaseVerified'       => $db_verified,
            'databasePending'        => $db_pending,
            'databaseFailed'         => $db_failed,
            'themeCssReady'          => $theme_ready,
            'themeCssScanCompleted'  => $theme_scan_completed,
            'themeCssTotal'          => $theme_total,
            'themeCssVerified'       => $theme_verified,
            'themeCssPending'        => $theme_pending,
            'themeCssFailed'         => $theme_failed,
            'cleanupReady'           => $total > 0 && 0 === $failed && 0 === $blocked && $database_ready && $theme_ready,
            'cleanupComplete'        => $total > 0 && 0 === $remaining && 0 === $failed && $deleted === $total && $database_ready && $theme_ready,
        );
    }

    private function get_media_replacement_delete_guard()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $blockers = array();
        $add = static function (&$items, $code, $message, $count = 0) {
            $items[] = array(
                'code'    => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
                'count'   => max(0, (int) $count),
            );
        };

        if (!$this->media_replacement_has_registry_rows()) {
            $add($blockers, 'replacement_plan_missing', __('The current replacement plan is empty. Run Prepare before deleting originals.', 'ultracache'));
        }

        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        if ($state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format) {
            $add($blockers, 'policy_changed', __('The Media Library replacement policy changed after Verify.', 'ultracache'));
        }
        if ('' === $state['workflow_verified_at'] || '' === $state['verify_completed_at'] || empty($state['verify_cleanup_ready'])) {
            $add($blockers, 'verify_incomplete', __('Run Verify successfully before deleting original files.', 'ultracache'));
        }
        if ('' === $state['verified_plan_fingerprint']
            || '' === $state['pre_do_plan_fingerprint']
            || !hash_equals((string) $state['pre_do_plan_fingerprint'], (string) $state['verified_plan_fingerprint'])) {
            $add($blockers, 'verified_plan_changed', __('The prepared replacement plan no longer matches the plan verified for cleanup.', 'ultracache'));
        }
        if (!empty($state['verify_cleanup_blockers'])) {
            foreach ((array) $state['verify_cleanup_blockers'] as $blocker) {
                if (is_array($blocker)) {
                    $blockers[] = $blocker;
                }
            }
        }
        if (!in_array($state['active_step'], array('verify_complete', 'delete_originals', 'delete_failed', 'delete_complete'), true)) {
            $add($blockers, 'delete_state_invalid', __('Delete Originals is not available from the current replacement phase.', 'ultracache'));
        }

        $summary = $this->get_media_replacement_delete_facts();
        $total = max(0, (int) ($summary['totalItems'] ?? 0));
        $remaining = max(0, (int) ($summary['remainingItems'] ?? 0));
        $deleted = max(0, (int) ($summary['deletedItems'] ?? 0));
        $failed = max(0, (int) ($summary['failedItems'] ?? 0));
        $blocked = max(0, (int) ($summary['blockedItems'] ?? 0));
        $expected = max(0, (int) $state['verify_cleanup_candidates']);
        $hard_blocked = $this->get_media_replacement_cleanup_hard_blocked_status_count();

        if ($expected <= 0 || $total !== $expected || ($remaining + $deleted + $failed) !== $total) {
            $add($blockers, 'cleanup_set_changed', __('The verified cleanup candidate set changed after Verify.', 'ultracache'), abs($expected - $total));
        }
        if (empty($summary['databaseReady'])) {
            $add($blockers, 'database_not_verified', __('Database replacements are no longer fully verified.', 'ultracache'));
        }
        if (empty($summary['themeCssReady'])) {
            $add($blockers, 'theme_css_not_verified', __('Theme CSS replacements are no longer fully verified.', 'ultracache'));
        }
        if ($blocked > 0 || $hard_blocked > 0) {
            $add($blockers, 'cleanup_rows_blocked', __('Some registry rows are no longer eligible for original-file cleanup.', 'ultracache'), max($blocked, $hard_blocked));
        }
        if ('verify_complete' === $state['active_step'] && (empty($summary['cleanupReady']) || $failed > 0)) {
            $add($blockers, 'cleanup_not_ready', __('The verified cleanup set is no longer ready to start.', 'ultracache'), $failed);
        }

        return array(
            'allowed'          => empty($blockers),
            'message'          => empty($blockers)
                ? __('Delete Originals guard passed for the verified cleanup plan.', 'ultracache')
                : (string) ($blockers[0]['message'] ?? __('Delete Originals is blocked.', 'ultracache')),
            'blockers'         => $blockers,
            'totalItems'       => $total,
            'remainingItems'   => $remaining,
            'deletedItems'     => $deleted,
            'failedItems'      => $failed,
            'blockedItems'     => $blocked,
            'cleanupComplete'  => $total > 0 && 0 === $remaining && 0 === $failed && $deleted === $total,
        );
    }

    public function get_media_library_replacement_delete_status()
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        $summary = $this->get_media_replacement_delete_facts();
        $remaining = max(0, (int) ($summary['remainingItems'] ?? 0));
        $deleted = max(0, (int) ($summary['deletedItems'] ?? 0));
        $failed = max(0, (int) ($summary['failedItems'] ?? 0));
        $total = max((int) $state['verify_cleanup_candidates'], $remaining + $deleted + $failed);
        $processed = min($total, $deleted + $failed);
        $complete = 'delete_complete' === $state['active_step'] || ($total > 0 && 0 === $remaining && 0 === $failed && $deleted === $total);
        $delete_failed = 'delete_failed' === $state['active_step'] || $failed > 0;
        $active = 'delete_originals' === $state['active_step'] && in_array($state['run_status'], array('running', 'paused'), true);
        $guard = $this->get_media_replacement_delete_guard();
        $ready = !$complete && !empty($guard['allowed']) && ($remaining > 0 || $delete_failed);

        if ($complete) {
            $message = __('Delete Originals is complete. Every verified original JPG/PNG row is marked deleted.', 'ultracache');
        } elseif ($delete_failed) {
            $message = $state['last_error'] ?: __('Delete Originals stopped with failed rows. Retry rechecks failed rows before deleting anything else.', 'ultracache');
        } elseif ('paused' === $state['run_status'] && 'delete_originals' === $state['active_step']) {
            $message = __('Delete Originals is paused and can resume from the remaining server rows.', 'ultracache');
        } elseif ($active) {
            $message = __('Delete Originals is processing verified original JPG/PNG rows.', 'ultracache');
        } elseif ($ready) {
            $message = __('Verify is complete. Delete Originals is ready for the verified cleanup rows.', 'ultracache');
        } else {
            $message = (string) ($guard['message'] ?? __('Delete Originals is blocked until Verify completes cleanly.', 'ultracache'));
        }

        return array(
            'success'          => true,
            'status'           => $state['status'],
            'runStatus'        => $state['run_status'],
            'activeStep'       => $state['active_step'],
            'message'          => $message,
            'deleteReady'      => $ready,
            'deleteActive'     => $active,
            'deleteFailed'     => $delete_failed,
            'deleteComplete'   => $complete,
            'hasMore'          => !$complete && $remaining > 0,
            'total'            => $total,
            'processed'        => $processed,
            'remaining'        => $remaining,
            'deleted'          => $deleted,
            'failed'           => $failed,
            'progressPercent'  => $total > 0 ? round(($processed / max(1, $total)) * 100, 1) : ($complete ? 100 : 0),
            'guard'            => $guard,
            'deleteStartedAt'  => $state['delete_started_at'],
            'deleteCompletedAt'=> $state['delete_completed_at'],
            'confirmationTokens' => $this->get_media_replacement_confirmation_tokens_for_response(),
        );
    }

    public function confirm_media_library_replacement_delete($args = array())
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ('verify_complete' !== $state['active_step']) {
            return array_merge($this->get_media_library_replacement_delete_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('A fresh Delete Originals confirmation can be issued only before the first destructive chunk. Resume and Retry use the persisted cleanup authorization together with the current replacement lease.', 'ultracache'),
            ));
        }
        $guard = $this->get_media_replacement_delete_guard();
        if (empty($guard['allowed']) || !empty($guard['cleanupComplete']) || empty($guard['remainingItems'])) {
            return array_merge($this->get_media_library_replacement_delete_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => !empty($guard['cleanupComplete'])
                    ? __('Delete Originals is already complete.', 'ultracache')
                    : (string) ($guard['message'] ?? __('Delete Originals confirmation is blocked.', 'ultracache')),
            ));
        }

        $tokens = $this->issue_media_replacement_confirmation_token('cleanup_apply');
        if (empty($tokens)) {
            return array_merge($this->get_media_library_replacement_delete_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('UltraCache could not issue a fresh Delete Originals confirmation token.', 'ultracache'),
            ));
        }

        return array_merge($this->get_media_library_replacement_delete_status(), array(
            'success'            => true,
            'message'            => __('Fresh Delete Originals confirmation is ready for the current verified cleanup plan.', 'ultracache'),
            'confirmationTokens' => $tokens,
        ));
    }

    private function fail_media_library_replacement_delete($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            $state['status'] = 'failed';
            $state['run_status'] = 'failed';
            $state['active_step'] = 'delete_failed';
            $state['workflow_stage'] = 'delete';
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_delete_status(), array(
            'success' => false,
            'blocked' => true,
            'message' => sanitize_text_field((string) $message),
        ));
    }

    private function block_media_library_replacement_delete_start($message, $retry_required = false)
    {
        return array_merge($this->get_media_library_replacement_delete_status(), array(
            'success'       => false,
            'blocked'       => true,
            'retryRequired' => !empty($retry_required),
            'message'       => sanitize_text_field((string) $message),
        ));
    }

    private function pause_media_library_replacement_delete_for_retry($message)
    {
        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if ($this->media_replacement_workflow_exists($state) || $this->media_replacement_has_registry_rows()) {
            $state['status'] = 'deleting_originals';
            $state['run_status'] = 'paused';
            $state['active_step'] = 'delete_originals';
            $state['paused_at'] = current_time('mysql', true);
            $state['last_error'] = sanitize_text_field((string) $message);
            $state['workflow_stage'] = 'delete';
            $state['workflow_message'] = $state['last_error'];
            $state['workflow_updated_at'] = current_time('mysql', true);
            $state['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_workflow_state($state);
        }
        return array_merge($this->get_media_library_replacement_delete_status(), array(
            'success'       => false,
            'blocked'       => true,
            'retryRequired' => true,
            'message'       => sanitize_text_field((string) $message),
        ));
    }

    private function apply_media_library_replacement_delete_rows($limit = 50, $time_budget = 15.0, $retry_failed = false)
    {
        $limit = max(1, min(100, absint($limit)));
        $time_budget = max(1.0, min(25.0, (float) $time_budget));
        if (!$this->media_replacement_has_registry_rows()) {
            return array('success' => false, 'blocked' => true, 'message' => __('No replacement registry rows are available for Delete Originals.', 'ultracache'));
        }

        $recovered = $retry_failed ? $this->recover_media_replacement_cleanup_failed_rows($limit) : array();
        $facts = $this->get_media_replacement_delete_facts();
        if (empty($facts['databaseReady']) || empty($facts['themeCssReady']) || !empty($facts['blockedItems'])) {
            return array(
                'success' => false,
                'blocked' => true,
                'message' => __('Delete Originals stopped because database, Theme CSS, or registry verification is no longer clean.', 'ultracache'),
                'facts'   => $facts,
            );
        }
        if (empty($facts['remainingItems'])) {
            return array(
                'success'        => empty($facts['failedItems']),
                'blocked'        => !empty($facts['failedItems']),
                'message'        => empty($facts['failedItems'])
                    ? __('Delete Originals is complete. No verified original rows remain.', 'ultracache')
                    : __('Delete Originals has failed rows that could not be recovered automatically.', 'ultracache'),
                'hasMore'        => false,
                'processed'      => 0,
                'deleted'        => 0,
                'alreadyMissing' => 0,
                'failed'         => (int) $facts['failedItems'],
                'cleanupDeleted' => (int) $facts['deletedItems'],
                'cleanupFailed'  => (int) $facts['failedItems'],
                'facts'          => $facts,
                'recoveredCleanupRows' => $recovered,
            );
        }

        $rows = $this->get_media_replacement_cleanup_candidate_rows($limit);
        $started_at = microtime(true);
        $processed = 0;
        $deleted = 0;
        $already_missing = 0;
        $failed = 0;
        $deleted_paths = array();

        foreach ($rows as $row) {
            if ($processed > 0 && (microtime(true) - $started_at) >= $time_budget) {
                break;
            }

            $item_id = absint($row['id'] ?? 0);
            $old_file = wp_normalize_path((string) ($row['old_file_path'] ?? ''));
            if ($item_id <= 0) {
                continue;
            }
            $processed++;

            $evaluation = $this->evaluate_media_replacement_cleanup_row($row);
            $old_file = (string) $evaluation['oldFile'];
            if (empty($evaluation['processable'])) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', (string) $evaluation['message']);
                continue;
            }
            if (!empty($evaluation['alreadyMissing'])) {
                $backup_cleanup = $this->finalize_media_replacement_destination_backup_cleanup($row);
                if (empty($backup_cleanup['cleaned'])) {
                    $failed++;
                    $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', (string) ($backup_cleanup['message'] ?? __('Overwrite backup cleanup failed.', 'ultracache')));
                    continue;
                }
                $already_missing++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', __('Original file was already missing; row marked complete.', 'ultracache'));
                continue;
            }

            if (!isset($deleted_paths[$old_file])) {
                if (!function_exists('ultracache_safe_unlink') || !ultracache_safe_unlink($old_file, 'media_library_replacement_cleanup_original')) {
                    $failed++;
                    $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Original file could not be deleted.', 'ultracache'));
                    continue;
                }
                $deleted_paths[$old_file] = true;
                $deleted++;
            }

            if ($this->optimized_storage_path_exists($old_file, true)) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', __('Original file still exists after the delete attempt.', 'ultracache'));
                continue;
            }

            $backup_cleanup = $this->finalize_media_replacement_destination_backup_cleanup($row);
            if (empty($backup_cleanup['cleaned'])) {
                $failed++;
                $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_failed', (string) ($backup_cleanup['message'] ?? __('Overwrite backup cleanup failed.', 'ultracache')));
                continue;
            }

            $this->update_media_replacement_cleanup_item_status($item_id, 'cleanup_deleted', '');
        }

        $facts = $this->get_media_replacement_delete_facts();
        return array(
            'success'        => true,
            'message'        => !empty($facts['remainingItems'])
                /* translators: %1$d: registry rows processed in this chunk; %2$d: remaining registry rows. */
                ? sprintf(__('Delete Originals processed %1$d registry rows in this chunk. %2$d rows remain.', 'ultracache'), $processed, (int) $facts['remainingItems'])
                /* translators: %1$d: final processed registry row count; %2$d: failed cleanup row count. */
                : sprintf(__('Delete Originals processed the final %1$d registry rows. Failed rows: %2$d.', 'ultracache'), $processed, (int) $facts['failedItems']),
            'hasMore'        => !empty($facts['remainingItems']),
            'processed'      => $processed,
            'deleted'        => $deleted,
            'alreadyMissing' => $already_missing,
            'failed'         => $failed,
            'cleanupDeleted' => (int) $facts['deletedItems'],
            'cleanupFailed'  => (int) $facts['failedItems'],
            'facts'          => $facts,
            'recoveredCleanupRows' => $recovered,
        );
    }

    public function run_media_library_replacement_delete_chunk($args = array())
    {
        $args = is_array($args) ? $args : array();
        $session_token = $this->normalize_media_replacement_manual_session_token((string) ($args['session_token'] ?? $args['sessionToken'] ?? ''));
        $session = $this->validate_media_replacement_session_token($session_token, 'delete');
        if (empty($session['success'])) {
            return $session;
        }

        $limit = max(1, min(100, absint($args['limit'] ?? 50)));
        $time_budget = isset($args['time_budget']) ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(25.0, $time_budget));
        $confirmation_token = $this->get_media_replacement_confirmation_token_from_args($args);
        $chunk_lock = $this->acquire_media_replacement_delete_chunk_lock((int) ceil($time_budget) + 20);
        if ('' === $chunk_lock) {
            $lock_state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if ('verify_complete' === $lock_state['active_step']) {
                return $this->block_media_library_replacement_delete_start(__('Another Delete Originals chunk lock is still active. Retry after it expires; no original files were deleted by this request.', 'ultracache'), true);
            }
            return $this->pause_media_library_replacement_delete_for_retry(__('Another Delete Originals chunk is still running. Resume after it finishes or its lock expires.', 'ultracache'));
        }

        try {
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            if (!$this->media_replacement_has_registry_rows()) {
                return $this->fail_media_library_replacement_delete(__('Run Prepare, Do, and Verify before Delete Originals.', 'ultracache'));
            }
            if ('delete_complete' === $state['active_step']) {
                return $this->get_media_library_replacement_delete_status();
            }

            $guard = $this->get_media_replacement_delete_guard();
            if (empty($guard['allowed'])) {
                $message = (string) ($guard['message'] ?? __('Delete Originals guard failed.', 'ultracache'));
                return 'verify_complete' === $state['active_step']
                    ? $this->block_media_library_replacement_delete_start($message)
                    : $this->fail_media_library_replacement_delete($message);
            }

            if ('verify_complete' === $state['active_step']) {
                $authorization = $this->consume_media_replacement_delete_confirmation($state, $confirmation_token);
                if (empty($authorization['success'])) {
                    return $this->block_media_library_replacement_delete_start(
                        (string) ($authorization['message'] ?? __('A fresh Delete Originals confirmation token is required.', 'ultracache'))
                    );
                }
                $state = isset($authorization['state']) && is_array($authorization['state'])
                    ? $authorization['state']
                    : $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            } elseif (!$this->is_media_replacement_delete_authorized($state)) {
                return $this->fail_media_library_replacement_delete(
                    __('Delete Originals has no valid start authorization for the current verified cleanup plan. Run Verify again before resuming deletion.', 'ultracache')
                );
            }

            $retry_failed_rows = 'delete_failed' === $state['active_step'];
            if ('verify_complete' === $state['active_step'] || $retry_failed_rows) {
                $now = current_time('mysql', true);
                $state['status'] = 'deleting_originals';
                $state['run_status'] = 'running';
                $state['active_step'] = 'delete_originals';
                $state['workflow_stage'] = 'delete';
                $state['delete_started_at'] = '' !== $state['delete_started_at'] ? $state['delete_started_at'] : $now;
                $state['delete_completed_at'] = '';
                $state['completed_at'] = '';
                $state['heartbeat_at'] = $now;
                $state['paused_at'] = '';
                $state['last_error'] = '';
                $state['workflow_message'] = __('Delete Originals is processing only the verified cleanup rows.', 'ultracache');
                $state['workflow_updated_at'] = $now;
                $state['updated_at'] = $now;
                $state = $this->update_media_replacement_workflow_state($state);
            } elseif ('delete_originals' !== $state['active_step']) {
                return $this->fail_media_library_replacement_delete(__('Delete Originals is in an unsupported state. Run Verify again.', 'ultracache'));
            }

            $result = $this->apply_media_library_replacement_delete_rows(
                $limit,
                $time_budget,
                $retry_failed_rows
            );
            if (empty($result['success'])) {
                if (!empty($result['retryRequired'])) {
                    return $this->pause_media_library_replacement_delete_for_retry((string) ($result['message'] ?? __('Delete Originals could not continue.', 'ultracache')));
                }
                return $this->fail_media_library_replacement_delete((string) ($result['message'] ?? __('Delete Originals failed.', 'ultracache')));
            }

            $failed = max(0, (int) ($result['cleanupFailed'] ?? $result['failed'] ?? 0));
            if ($failed > 0) {
                /* translators: %d: failed cleanup validation or deletion row count. */
                return $this->fail_media_library_replacement_delete(sprintf(__('Delete Originals stopped because %d registry rows failed cleanup validation or deletion.', 'ultracache'), $failed));
            }

            $has_more = !empty($result['hasMore']);
            $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
            $now = current_time('mysql', true);
            $state['heartbeat_at'] = $now;
            $state['updated_at'] = $now;
            if ($has_more) {
                $state['status'] = 'deleting_originals';
                $state['run_status'] = 'running';
                $state['active_step'] = 'delete_originals';
                $state['workflow_stage'] = 'delete';
                $state['workflow_message'] = __('Delete Originals is continuing from the remaining verified rows.', 'ultracache');
                $state['workflow_updated_at'] = $now;
            } else {
                $state['status'] = 'completed';
                $state['run_status'] = 'completed';
                $state['active_step'] = 'delete_complete';
                $state['workflow_stage'] = 'complete';
                $state['delete_completed_at'] = $now;
                $state['completed_at'] = $now;
                $state['paused_at'] = '';
                $state['last_error'] = '';
                $state['workflow_message'] = __('Media Library replacement is complete. Verified original JPG/PNG files have been deleted.', 'ultracache');
                $state['workflow_updated_at'] = $now;
            }
            $this->update_media_replacement_workflow_state($state);

            return array_merge($this->get_media_library_replacement_delete_status(), array(
                'success'        => true,
                'message'        => (string) ($result['message'] ?? $state['workflow_message']),
                'batchProcessed' => max(0, (int) ($result['processed'] ?? 0)),
                'batchDeleted'   => max(0, (int) ($result['deleted'] ?? 0)),
                'batchAlreadyMissing' => max(0, (int) ($result['alreadyMissing'] ?? 0)),
                'step'           => $result,
            ));
        } catch (Throwable $error) {
            return $this->pause_media_library_replacement_delete_for_retry(sanitize_text_field((string) $error->getMessage()));
        } finally {
            $this->release_media_replacement_delete_chunk_lock($chunk_lock);
        }
    }

}
