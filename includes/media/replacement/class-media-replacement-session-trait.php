<?php
/**
 * UltraCache Media Library replacement workflow locks, sessions, and recovery state.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Session_Trait
{
    private function get_media_replacement_workflow_state_option_name()
    {
        return 'ultracache_media_replacement_workflow_state_v1';
    }

    private function get_media_replacement_current_output_policy()
    {
        $target_format = method_exists($this, 'get_media_replacement_format') ? $this->get_media_replacement_format() : 'webp';
        $target_format = in_array($target_format, array('avif', 'webp'), true) ? $target_format : 'webp';

        $fallback_format = 'original';
        $saved = $this->get_media_replacement_workflow_state();
        $format_lock = $this->get_media_replacement_format_lock_state($saved);
        if (!empty($format_lock['locked'])) {
            $target_format = (string) $format_lock['targetFormat'];
        }
        if ('' !== (string) ($saved['created_at'] ?? '') && $target_format === (string) ($saved['target_format'] ?? '')) {
            $fallback_format = ('avif' === $target_format && 'webp' === (string) ($saved['fallback_format'] ?? '')) ? 'webp' : 'original';
        }

        return array($target_format, $fallback_format);
    }

    private function acquire_media_replacement_readiness_lock($target_format, $ttl = 45)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = wp_generate_uuid4();
        $payload = array(
            'token'        => $token,
            'context'      => 'media_replacement_readiness',
            'targetFormat' => sanitize_key((string) $target_format),
            'startedAt'    => time(),
        );
        return ultracache_acquire_lock(self::MEDIA_REPLACEMENT_READINESS_LOCK, $token, max(15, (int) $ttl), $payload) ? $token : '';
    }

    private function release_media_replacement_readiness_lock($token)
    {
        if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock(self::MEDIA_REPLACEMENT_READINESS_LOCK, (string) $token);
        }
    }

    private function acquire_media_replacement_prepare_chunk_lock($ttl = 45)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = wp_generate_uuid4();
        $payload = array(
            'token'     => $token,
            'context'   => 'media_replacement_prepare_chunk',
            'startedAt' => time(),
        );
        return ultracache_acquire_lock('ultracache_media_replacement_prepare_chunk_v1', $token, max(15, (int) $ttl), $payload) ? $token : '';
    }

    private function release_media_replacement_prepare_chunk_lock($token)
    {
        if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_media_replacement_prepare_chunk_v1', (string) $token);
        }
    }

    private function acquire_media_replacement_do_chunk_lock($ttl = 45)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = wp_generate_uuid4();
        $payload = array(
            'token'     => $token,
            'context'   => 'media_replacement_do_chunk',
            'startedAt' => time(),
        );
        return ultracache_acquire_lock('ultracache_media_replacement_do_chunk_v1', $token, max(15, (int) $ttl), $payload) ? $token : '';
    }

    private function release_media_replacement_do_chunk_lock($token)
    {
        if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_media_replacement_do_chunk_v1', (string) $token);
        }
    }

    private function acquire_media_replacement_verify_chunk_lock($ttl = 45)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = wp_generate_uuid4();
        $payload = array(
            'token'     => $token,
            'context'   => 'media_replacement_verify_chunk',
            'startedAt' => time(),
        );
        return ultracache_acquire_lock('ultracache_media_replacement_verify_chunk_v1', $token, max(15, (int) $ttl), $payload) ? $token : '';
    }

    private function release_media_replacement_verify_chunk_lock($token)
    {
        if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_media_replacement_verify_chunk_v1', (string) $token);
        }
    }

    private function acquire_media_replacement_delete_chunk_lock($ttl = 45)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return '';
        }

        $token = wp_generate_uuid4();
        $payload = array(
            'token'     => $token,
            'context'   => 'media_replacement_delete_chunk',
            'startedAt' => time(),
        );
        return ultracache_acquire_lock('ultracache_media_replacement_delete_chunk_v1', $token, max(15, (int) $ttl), $payload) ? $token : '';
    }

    private function release_media_replacement_delete_chunk_lock($token)
    {
        if ('' !== (string) $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock('ultracache_media_replacement_delete_chunk_v1', (string) $token);
        }
    }

    private function normalize_media_replacement_manual_session_token($token)
    {
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
        return is_string($token) ? substr($token, 0, 128) : '';
    }

    public function get_media_library_replacement_session_status()
    {
        $lock = function_exists('ultracache_get_lock')
            ? ultracache_get_lock(self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK)
            : array();
        $active = !empty($lock['token']) && (int) ($lock['expiresAt'] ?? 0) > time();
        $payload = $active && isset($lock['payload']) && is_array($lock['payload']) ? $lock['payload'] : array();

        return array(
            'active'     => $active,
            'activeStep' => sanitize_key((string) ($payload['activeStep'] ?? '')),
            'userId'     => $active ? absint($payload['userId'] ?? 0) : 0,
            'expiresAt'  => $active ? max(0, (int) ($lock['expiresAt'] ?? 0)) : 0,
            'updatedAt'  => $active ? max(0, (int) ($payload['updatedAt'] ?? 0)) : 0,
            'owner'      => $active ? sanitize_key((string) ($payload['owner'] ?? 'dashboard')) : '',
        );
    }



    private function media_replacement_workflow_exists(array $state)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        if ($this->media_replacement_has_registry_rows()) {
            return true;
        }

        return '' !== (string) $state['active_step']
            || !in_array((string) $state['run_status'], array('', 'idle'), true)
            || '' !== (string) $state['prepare_started_at']
            || '' !== (string) $state['do_started_at']
            || '' !== (string) $state['verify_started_at']
            || '' !== (string) $state['delete_started_at'];
    }

    private function get_media_replacement_resume_stage(array $state, array $readiness = array())
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        $active_step = sanitize_key((string) $state['active_step']);

        if (!$this->media_replacement_workflow_exists($state)) {
            $readiness_status = sanitize_key((string) ($readiness['status'] ?? ''));
            return in_array($readiness_status, array('scanning', 'paused'), true) ? 'readiness' : '';
        }

        if (in_array($active_step, array(
            'registry_scan',
            'copy',
            'validate',
            'metadata_plan',
            'database_scan',
            'database_match',
            'database_preview',
            'theme_css_scan',
            'theme_css_preview',
            'pre_do_validate',
        ), true)) {
            return 'prepare';
        }
        if (in_array($active_step, array('metadata_apply', 'database_recovery_scan', 'database_recovery_match', 'database_recovery_preview', 'database_apply', 'theme_css_apply'), true)) {
            return 'do';
        }
        if (in_array($active_step, array('destination_verify', 'metadata_verify', 'database_verify', 'theme_css_verify', 'cleanup_preview', 'verify_failed'), true)) {
            return 'verify';
        }
        if (in_array($active_step, array('delete_originals', 'delete_failed'), true)) {
            return 'delete';
        }
        if (in_array($active_step, array('rollback_database', 'rollback_theme_css', 'rollback_metadata', 'rollback_files', 'rollback_failed'), true)) {
            return 'rollback';
        }

        return '';
    }

    private function media_replacement_has_destructive_progress(array $state)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        if ('rollback_complete' === (string) $state['active_step']) {
            return false;
        }
        if ('' !== $state['do_started_at'] || '' !== $state['do_completed_at'] || '' !== $state['verify_started_at'] || '' !== $state['delete_started_at']) {
            return true;
        }

        return in_array($state['active_step'], array(
            'metadata_apply',
            'database_recovery_scan',
            'database_recovery_match',
            'database_recovery_preview',
            'database_apply',
            'theme_css_apply',
            'do_complete',
            'do_failed',
            'destination_verify',
            'metadata_verify',
            'database_verify',
            'theme_css_verify',
            'cleanup_preview',
            'verify_complete',
            'verify_failed',
            'delete_originals',
            'delete_failed',
            'delete_complete',
            'rollback_database',
            'rollback_theme_css',
            'rollback_metadata',
            'rollback_files',
            'rollback_failed',
        ), true);
    }

    private function get_media_replacement_format_lock_state(array $state)
    {
        $state = $this->normalize_media_replacement_workflow_state($state);
        $completed = 'complete' === $state['workflow_stage'] || 'delete_complete' === $state['active_step'];
        $locked = $this->media_replacement_workflow_exists($state) && !$completed && $this->media_replacement_has_destructive_progress($state);

        return array(
            'locked'       => $locked,
            'targetFormat' => $locked ? $state['target_format'] : '',
            'message'      => $locked
                ? __('Image replacement format is locked because the destructive Do stage has started. Complete the current workflow or use the existing recovery path before changing it.', 'ultracache')
                : '',
        );
    }

    private function reconcile_media_replacement_recovery_state()
    {
        $session = $this->get_media_library_replacement_session_status();
        if (!empty($session['active'])) {
            return;
        }

        $readiness = $this->get_media_replacement_readiness_state();
        if ('scanning' === $readiness['status']) {
            $readiness['status'] = 'paused';
            $readiness['updated_at'] = current_time('mysql', true);
            $this->update_media_replacement_readiness_state($readiness);
        }

        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($state) || 'running' !== $state['run_status']) {
            return;
        }

        $resume_stage = $this->get_media_replacement_resume_stage($state, $readiness);
        if ('' === $resume_stage) {
            return;
        }

        $now = current_time('mysql', true);
        $state['run_status'] = 'paused';
        $state['paused_at'] = $now;
        $state['updated_at'] = $now;
        $state['workflow_message'] = sprintf(
            /* translators: %s is the replacement stage name. */
            __('The previous dashboard session ended. Resume %s from the saved server state.', 'ultracache'),
            ucfirst($resume_stage)
        );
        $state['workflow_updated_at'] = $now;
        $this->update_media_replacement_workflow_state($state);
    }

    private function get_media_replacement_recovery_status(array $state = array())
    {
        $state = empty($state)
            ? $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state())
            : $this->normalize_media_replacement_workflow_state($state);
        $readiness = $this->get_media_library_replacement_readiness_status();
        $session = $this->get_media_library_replacement_session_status();
        $resume_stage = $this->get_media_replacement_resume_stage($state, $readiness);
        $resumable = '' !== $resume_stage && empty($session['active']) && ('paused' === $state['run_status'] || ('readiness' === $resume_stage && 'paused' === (string) ($readiness['status'] ?? '')));
        $has_workflow = $this->media_replacement_workflow_exists($state);
        $destructive_progress = $this->media_replacement_has_destructive_progress($state);
        $can_restart = $has_workflow && !$destructive_progress && empty($session['active']);
        $restart_reason = '';
        if (!$has_workflow) {
            $restart_reason = __('There is no Media Library replacement workflow to restart.', 'ultracache');
        } elseif (!empty($session['active'])) {
            $restart_reason = __('Pause the active replacement workflow before restarting the plan.', 'ultracache');
        } elseif ($destructive_progress) {
            $restart_reason = __('Restart is blocked after Do has started. Resume the current workflow or use the explicit rollback/uninstall recovery path.', 'ultracache');
        }

        return array(
            'resumable'            => $resumable,
            'resumeStage'          => $resume_stage,
            'activeElsewhere'      => !empty($session['active']),
            'activeStep'           => sanitize_key((string) ($session['activeStep'] ?? $state['active_step'])),
            'leaseExpiresAt'       => max(0, (int) ($session['expiresAt'] ?? 0)),
            'canRestart'           => $can_restart,
            'restartBlockedReason' => $restart_reason,
        );
    }

    public function restart_media_library_replacement_workflow()
    {
        $this->reconcile_media_replacement_recovery_state();
        $session = $this->get_media_library_replacement_session_status();
        if (!empty($session['active'])) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('Pause the active Media Library replacement workflow before restarting the plan.', 'ultracache'),
            ));
        }

        $state = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($state)) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => true,
                'message' => __('There is no Media Library replacement workflow to restart.', 'ultracache'),
            ));
        }
        if ($this->media_replacement_has_destructive_progress($state)) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => __('Restart is blocked because Do has already started. Resume the current workflow or use the explicit rollback/uninstall recovery path.', 'ultracache'),
            ));
        }

        $reset = $this->reset_media_replacement_workflow_for_restart($state);
        if (empty($reset['success'])) {
            return array_merge($this->get_media_library_replacement_workflow_status(), array(
                'success' => false,
                'blocked' => true,
                'message' => (string) ($reset['message'] ?? __('The Media Library replacement workflow could not be restarted.', 'ultracache')),
            ));
        }

        return array_merge($this->get_media_library_replacement_workflow_status(), array(
            'success' => true,
            'restarted' => true,
            'message' => __('The Media Library replacement workflow was cleared. Start Prepare to build the singleton plan again from the beginning.', 'ultracache'),
        ));
    }

    private function update_media_replacement_readiness_run_status($run_status)
    {
        $state = $this->get_media_replacement_readiness_state();
        if ('paused' === $run_status && 'scanning' === $state['status']) {
            $state['status'] = 'paused';
        } elseif ('running' === $run_status && 'paused' === $state['status']) {
            $state['status'] = 'scanning';
        }
        $state['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_readiness_state($state);
    }

    private function update_media_replacement_prepare_run_status($run_status, $active_step = 'prepare')
    {
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($saved)) {
            return;
        }

        $prepare_steps = array(
            'registry_scan',
            'copy',
            'validate',
            'metadata_plan',
            'database_scan',
            'database_match',
            'database_preview',
            'theme_css_scan',
            'theme_css_preview',
            'pre_do_validate',
        );
        $run_status = in_array((string) $run_status, array('running', 'paused', 'failed', 'completed'), true) ? (string) $run_status : 'idle';
        if ('prepare_complete' === $saved['active_step'] && 'completed' === $saved['run_status'] && 'completed' !== $run_status) {
            return;
        }
        if ('prepare_failed' === $saved['active_step'] && 'failed' === $saved['run_status'] && 'running' !== $run_status) {
            return;
        }

        $saved['run_status'] = $run_status;
        if ('running' === $run_status) {
            if (!in_array($saved['active_step'], $prepare_steps, true)) {
                $saved['active_step'] = in_array($active_step, $prepare_steps, true) ? $active_step : 'registry_scan';
            }
            $saved['heartbeat_at'] = current_time('mysql', true);
            $saved['paused_at'] = '';
        } elseif ('paused' === $run_status) {
            $saved['paused_at'] = current_time('mysql', true);
        }
        $saved['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function update_media_replacement_do_run_status($run_status, $active_step = 'do')
    {
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($saved)) {
            return;
        }

        $do_steps = array('metadata_apply', 'database_recovery_scan', 'database_recovery_match', 'database_recovery_preview', 'database_apply', 'theme_css_apply');
        $run_status = in_array((string) $run_status, array('running', 'paused', 'failed', 'completed'), true) ? (string) $run_status : 'idle';

        // Acquiring a Do lease must never bypass the hard pre-Do guard by advancing
        // prepare_complete to metadata_apply. The first Do chunk performs that transition.
        if ('prepare_complete' === $saved['active_step']) {
            if ('running' === $run_status) {
                $saved['heartbeat_at'] = current_time('mysql', true);
                $saved['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($saved);
            }
            return;
        }

        if ('do_complete' === $saved['active_step'] || 'do_failed' === $saved['active_step']) {
            return;
        }

        if (!in_array($saved['active_step'], $do_steps, true)) {
            return;
        }

        $saved['run_status'] = $run_status;
        if ('running' === $run_status) {
            $saved['heartbeat_at'] = current_time('mysql', true);
            $saved['paused_at'] = '';
        } elseif ('paused' === $run_status) {
            $saved['paused_at'] = current_time('mysql', true);
        }
        $saved['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function update_media_replacement_verify_run_status($run_status)
    {
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($saved)) {
            return;
        }

        $verify_steps = array('destination_verify', 'metadata_verify', 'database_verify', 'theme_css_verify', 'cleanup_preview');
        $run_status = in_array((string) $run_status, array('running', 'paused', 'failed', 'completed'), true) ? (string) $run_status : 'idle';

        // Acquiring the Verify lease must not advance a completed Do phase before the
        // first Verify chunk validates the actual server state.
        if ('do_complete' === $saved['active_step']) {
            if ('running' === $run_status) {
                $saved['heartbeat_at'] = current_time('mysql', true);
                $saved['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($saved);
            }
            return;
        }

        if ('verify_complete' === $saved['active_step']) {
            return;
        }
        if ('verify_failed' === $saved['active_step'] && 'running' !== $run_status) {
            return;
        }
        if (!in_array($saved['active_step'], $verify_steps, true) && 'verify_failed' !== $saved['active_step']) {
            return;
        }

        $saved['run_status'] = $run_status;
        if ('running' === $run_status) {
            $saved['heartbeat_at'] = current_time('mysql', true);
            $saved['paused_at'] = '';
        } elseif ('paused' === $run_status) {
            $saved['paused_at'] = current_time('mysql', true);
        }
        $saved['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function update_media_replacement_delete_run_status($run_status)
    {
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($saved)) {
            return;
        }

        $run_status = in_array((string) $run_status, array('running', 'paused', 'failed', 'completed'), true) ? (string) $run_status : 'idle';

        // Acquiring the Delete lease must not advance a verified workflow before the
        // first destructive chunk revalidates policy, verification,
        // cleanup facts, and the fresh one-time start-confirmation token.
        if ('verify_complete' === $saved['active_step']) {
            if ('running' === $run_status) {
                $saved['heartbeat_at'] = current_time('mysql', true);
                $saved['updated_at'] = current_time('mysql', true);
                $this->update_media_replacement_workflow_state($saved);
            }
            return;
        }

        if ('delete_complete' === $saved['active_step']) {
            return;
        }
        if (!in_array($saved['active_step'], array('delete_originals', 'delete_failed'), true)) {
            return;
        }

        $saved['run_status'] = $run_status;
        if ('running' === $run_status) {
            $saved['heartbeat_at'] = current_time('mysql', true);
            $saved['paused_at'] = '';
        } elseif ('paused' === $run_status) {
            $saved['paused_at'] = current_time('mysql', true);
        }
        $saved['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function update_media_replacement_rollback_run_status($run_status)
    {
        $saved = $this->normalize_media_replacement_workflow_state($this->get_media_replacement_workflow_state());
        if (!$this->media_replacement_workflow_exists($saved)) {
            return;
        }

        $rollback_steps = array('rollback_database', 'rollback_theme_css', 'rollback_metadata', 'rollback_files', 'rollback_failed');
        if (!in_array($saved['active_step'], $rollback_steps, true) && 'rollback_complete' !== $saved['active_step']) {
            return;
        }
        if ('rollback_complete' === $saved['active_step']) {
            return;
        }

        $run_status = in_array((string) $run_status, array('running', 'paused', 'failed', 'completed'), true) ? (string) $run_status : 'idle';
        $saved['run_status'] = $run_status;
        if ('running' === $run_status) {
            $saved['heartbeat_at'] = current_time('mysql', true);
            $saved['paused_at'] = '';
        } elseif ('paused' === $run_status) {
            $saved['paused_at'] = current_time('mysql', true);
        }
        $saved['updated_at'] = current_time('mysql', true);
        $this->update_media_replacement_workflow_state($saved);
    }

    private function update_media_replacement_session_run_status($run_status, $active_step)
    {
        if ('readiness' === $active_step) {
            $this->update_media_replacement_readiness_run_status($run_status);
            return;
        }

        if ('prepare' === $active_step) {
            $this->update_media_replacement_prepare_run_status($run_status, 'prepare');
            return;
        }

        if ('do' === $active_step) {
            $this->update_media_replacement_do_run_status($run_status, 'do');
            return;
        }

        if ('verify' === $active_step) {
            $this->update_media_replacement_verify_run_status($run_status);
            return;
        }

        if ('delete' === $active_step) {
            $this->update_media_replacement_delete_run_status($run_status);
            return;
        }

        if ('rollback' === $active_step) {
            $this->update_media_replacement_rollback_run_status($run_status);
        }
    }

    private function validate_media_replacement_session_token($token, $active_step)
    {
        $token = $this->normalize_media_replacement_manual_session_token($token);
        $active_step = sanitize_key((string) $active_step);
        $lock = function_exists('ultracache_get_lock') ? ultracache_get_lock(self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK) : array();
        $payload = isset($lock['payload']) && is_array($lock['payload']) ? $lock['payload'] : array();
        $active_token = !empty($lock['token']) ? (string) $lock['token'] : '';
        $expires_at = max(0, (int) ($lock['expiresAt'] ?? 0));
        $locked_step = sanitize_key((string) ($payload['activeStep'] ?? ''));

        if ('' === $token || '' === $active_token || $expires_at <= time() || !hash_equals($active_token, $token) || $locked_step !== $active_step) {
            return array(
                'success' => false,
                'blocked' => true,
                'status'  => 'replacement_session_required',
                'message' => __('A current Media Library replacement lease is required for this operation.', 'ultracache'),
            );
        }

        return array('success' => true);
    }

    public function manage_media_library_replacement_session($action, $token = '', $active_step = 'readiness', $owner = 'dashboard')
    {
        $action = sanitize_key((string) $action);
        $token = $this->normalize_media_replacement_manual_session_token($token);
        $active_step = sanitize_key((string) $active_step);
        $owner = sanitize_key((string) $owner);
        if (!in_array($owner, array('dashboard', 'cli'), true)) {
            $owner = 'dashboard';
        }
        if (!in_array($active_step, array('readiness', 'prepare', 'do', 'verify', 'delete', 'rollback'), true)) {
            $active_step = 'readiness';
        }

        $state = $this->get_media_library_replacement_session_status();
        if ('begin' === $action) {
            if ('' !== $token && !empty($state['active']) && function_exists('ultracache_renew_lock')) {
                $lock = function_exists('ultracache_get_lock') ? ultracache_get_lock(self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK) : array();
                if (!empty($lock['token']) && hash_equals((string) $lock['token'], $token)) {
                    $renewed = ultracache_renew_lock(
                        self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK,
                        $token,
                        self::MEDIA_REPLACEMENT_MANUAL_SESSION_TTL,
                        array(
                            'activeStep' => $active_step,
                            'userId'     => get_current_user_id(),
                            'updatedAt'  => time(),
                            'owner'      => $owner,
                        )
                    );
                    if ($renewed) {
                        $this->update_media_replacement_session_run_status('running', $active_step);
                        return array_merge(array('success' => true, 'token' => $token), $this->get_media_library_replacement_session_status());
                    }
                }
            }

            if (!empty($state['active'])) {
                return array_merge(array(
                    'success' => false,
                    'blocked' => true,
                    'reason'  => 'replacement_session_active',
                    'message' => __('The Media Library replacement workflow is already running in another dashboard.', 'ultracache'),
                ), $state);
            }

            $token = wp_generate_uuid4();
            $acquired = function_exists('ultracache_acquire_lock') && ultracache_acquire_lock(
                self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK,
                $token,
                self::MEDIA_REPLACEMENT_MANUAL_SESSION_TTL,
                array(
                    'activeStep' => $active_step,
                    'userId'     => get_current_user_id(),
                    'updatedAt'  => time(),
                    'owner'      => $owner,
                )
            );
            if (!$acquired) {
                return array_merge(array(
                    'success' => false,
                    'blocked' => true,
                    'reason'  => 'replacement_session_active',
                    'message' => __('The Media Library replacement workflow acquired the dashboard lease elsewhere first.', 'ultracache'),
                ), $this->get_media_library_replacement_session_status());
            }
            $this->update_media_replacement_session_run_status('running', $active_step);
            return array_merge(array('success' => true, 'token' => $token), $this->get_media_library_replacement_session_status());
        }

        if ('renew' === $action) {
            $renewed = '' !== $token && function_exists('ultracache_renew_lock') && ultracache_renew_lock(
                self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK,
                $token,
                self::MEDIA_REPLACEMENT_MANUAL_SESSION_TTL,
                array(
                    'activeStep' => $active_step,
                    'userId'     => get_current_user_id(),
                    'updatedAt'  => time(),
                    'owner'      => $owner,
                )
            );
            if ($renewed) {
                $this->update_media_replacement_session_run_status('running', $active_step);
            }
            return array_merge(array(
                'success' => $renewed,
                'token'   => $renewed ? $token : '',
                'reason'  => $renewed ? '' : 'replacement_session_lost',
                'message' => $renewed ? '' : __('The Media Library replacement lease expired or changed owner.', 'ultracache'),
            ), $this->get_media_library_replacement_session_status());
        }

        if ('pause' === $action || 'end' === $action) {
            $lock = function_exists('ultracache_get_lock') ? ultracache_get_lock(self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK) : array();
            $active_token = !empty($lock['token']) ? (string) $lock['token'] : '';
            if ('' === $active_token) {
                if ('pause' === $action) {
                    $this->update_media_replacement_session_run_status('paused', $active_step);
                }
                return array_merge(array('success' => true, 'released' => false), $this->get_media_library_replacement_session_status());
            }
            if ('' === $token || !hash_equals($active_token, $token)) {
                return array_merge(array(
                    'success' => false,
                    'released'=> false,
                    'reason'  => 'replacement_session_lost',
                    'message' => __('The Media Library replacement lease is owned by another session.', 'ultracache'),
                ), $this->get_media_library_replacement_session_status());
            }
            $released = function_exists('ultracache_release_lock') && ultracache_release_lock(self::MEDIA_REPLACEMENT_MANUAL_SESSION_LOCK, $token);
            if ('pause' === $action) {
                $this->update_media_replacement_session_run_status('paused', $active_step);
            }
            return array_merge(array(
                'success'  => $released,
                'released' => $released,
                'reason'   => $released ? '' : 'replacement_session_lost',
            ), $this->get_media_library_replacement_session_status());
        }

        return array_merge(array(
            'success' => false,
            'message' => __('Unsupported Media Library replacement session action.', 'ultracache'),
        ), $state);
    }

}
