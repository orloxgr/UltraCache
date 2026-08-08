<?php
/**
 * WP-CLI command group for UltraCache Media Library replacement.
 */

defined('ABSPATH') || exit;

trait ULTRACACHE_CLI_Media_Replacement_Trait
{
    /**
     * Run or inspect the server-backed Media Library replacement state machine.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : Action. One of help, status, run, resume, pause, restart. Default: help.
     *
     * [--stage=<stage>]
     * : Stage for `run`. One of readiness, prepare, do, verify, delete.
     *
     * [--batch-size=<number>]
     * : Maximum rows/attachments processed per server chunk. Default: 50.
     *
     * [--time-budget=<seconds>]
     * : Per-chunk server time budget. Default: 15.
     *
     * [--max-batches=<number>]
     * : Stop after N chunks and leave the workflow paused. Default: unlimited.
     *
     * [--reset]
     * : Restart the readiness inventory before scanning. Valid only with --stage=readiness.
     *
     * [--yes]
     * : Confirm destructive Do or Delete Originals execution.
     *
     * [--format=<format>]
     * : Output format. One of table, json, yaml. Default: table.
     */
    public function media_replace($args, $assoc_args)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'get_media_library_replacement_workflow_status')) {
            WP_CLI::error('Media Library replacement is not available.');
        }

        $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'help';
        $format = !empty($assoc_args['format']) ? strtolower((string) $assoc_args['format']) : 'table';
        if (!in_array($format, array('table', 'json', 'yaml'), true)) {
            WP_CLI::error('Invalid --format. Use table, json, or yaml.');
        }

        if ('help' === $action || 'commands' === $action) {
            $this->print_media_replacement_cli_help();
            return;
        }

        if ('status' === $action) {
            $this->print_media_replacement_cli_status($media->get_media_library_replacement_workflow_status(), $format);
            return;
        }

        if ('pause' === $action) {
            $this->request_media_replacement_cli_pause($media, $format);
            return;
        }

        if ('restart' === $action) {
            if (empty($assoc_args['yes'])) {
                WP_CLI::error('Restart clears the current non-destructive replacement plan. Re-run with --yes.');
            }
            if (!method_exists($media, 'restart_media_library_replacement_workflow')) {
                WP_CLI::error('Replacement restart is not available.');
            }
            $result = $media->restart_media_library_replacement_workflow();
            $this->print_media_replacement_cli_status($result, $format);
            if (empty($result['success'])) {
                WP_CLI::error((string) ($result['message'] ?? 'Replacement restart failed.'));
            }
            if ('json' !== $format && 'yaml' !== $format) {
                WP_CLI::success((string) ($result['message'] ?? 'Replacement plan restarted.'));
            }
            return;
        }

        if (!in_array($action, array('run', 'resume'), true)) {
            WP_CLI::error('Invalid media-replace action. Use help, status, run, resume, pause, or restart.');
        }

        $workflow = $media->get_media_library_replacement_workflow_status();
        $stage = '';
        if ('resume' === $action) {
            $stage = sanitize_key((string) ($workflow['recovery']['resumeStage'] ?? ''));
            if ('' === $stage) {
                WP_CLI::error('There is no paused Media Library replacement stage to resume.');
            }
        } else {
            $stage = sanitize_key((string) ($assoc_args['stage'] ?? ''));
            if (!in_array($stage, array('readiness', 'prepare', 'do', 'verify', 'delete'), true)) {
                WP_CLI::error('Run requires --stage=readiness|prepare|do|verify|delete.');
            }
        }

        if (in_array($stage, array('do', 'delete'), true) && empty($assoc_args['yes'])) {
            WP_CLI::error(sprintf('%s changes site data. Re-run with --yes.', 'do' === $stage ? 'Do' : 'Delete Originals'));
        }

        if (!empty($assoc_args['reset']) && 'readiness' !== $stage) {
            WP_CLI::error('--reset is valid only for --stage=readiness.');
        }

        if (!empty($workflow['recovery']['activeElsewhere'])) {
            $owner = sanitize_key((string) ($workflow['replacementSession']['owner'] ?? 'dashboard'));
            WP_CLI::error(sprintf(
                'A Media Library replacement %s session is already running%s.',
                $owner ?: 'dashboard',
                !empty($workflow['replacementSession']['expiresAt']) ? ' until lease expiry ' . gmdate('Y-m-d H:i:s', (int) $workflow['replacementSession']['expiresAt']) . ' UTC' : ''
            ));
        }

        $stage_blocker = $this->get_media_replacement_cli_stage_blocker($stage, $workflow);
        if ('' !== $stage_blocker) {
            WP_CLI::error($stage_blocker);
        }

        $summary = $this->run_media_replacement_cli_stage($media, $stage, $assoc_args, $format);
        $this->print_media_replacement_cli_status($summary['workflow'], $format);

        if (empty($summary['success'])) {
            WP_CLI::error((string) ($summary['message'] ?? 'Media Library replacement stage failed.'));
        }

        if (!empty($summary['complete'])) {
            if ('json' !== $format && 'yaml' !== $format) {
                WP_CLI::success(sprintf('Media Library replacement %s stage complete after %d chunk(s).', $stage, (int) $summary['batches']));
            }
            return;
        }

        if ('json' !== $format && 'yaml' !== $format) {
            WP_CLI::warning(sprintf(
                'Media Library replacement %s stage paused after %d chunk(s). Reason: %s.',
                $stage,
                (int) $summary['batches'],
                (string) ($summary['pauseReason'] ?? 'incomplete')
            ));
        }
    }

    private function get_media_replacement_cli_stage_blocker($stage, array $workflow)
    {
        if ('readiness' === $stage) {
            return '';
        }

        if ('prepare' === $stage) {
            $status = isset($workflow['prepare']) && is_array($workflow['prepare']) ? $workflow['prepare'] : array();
            if (!empty($status['prepareFailed'])) {
                return (string) ($status['message'] ?? 'Prepare failed. Restart the non-destructive replacement plan before running it again.');
            }
            if (!empty($status['prepareComplete']) || !empty($status['hasMore']) || !empty($workflow['startGuard']['allowed'])) {
                return '';
            }
            return (string) ($workflow['startGuard']['message'] ?? $status['message'] ?? 'Prepare is not ready. Complete replacement readiness first.');
        }

        if ('do' === $stage) {
            $status = isset($workflow['do']) && is_array($workflow['do']) ? $workflow['do'] : array();
            if (!empty($status['doComplete']) || !empty($status['doReady'])) {
                return '';
            }
            return (string) ($status['message'] ?? 'Do is not ready. Complete Prepare first.');
        }

        if ('verify' === $stage) {
            $status = isset($workflow['verify']) && is_array($workflow['verify']) ? $workflow['verify'] : array();
            if (!empty($status['verifyComplete']) || !empty($status['verifyReady'])) {
                return '';
            }
            return (string) ($status['message'] ?? 'Verify is not ready. Complete Do first.');
        }

        $status = isset($workflow['delete']) && is_array($workflow['delete']) ? $workflow['delete'] : array();
        if (!empty($status['deleteComplete']) || !empty($status['deleteReady']) || !empty($status['deleteActive']) || !empty($status['deleteFailed'])) {
            return '';
        }
        return (string) ($status['message'] ?? 'Delete Originals is not ready. Complete Verify first.');
    }

    private function run_media_replacement_cli_stage($media, $stage, array $assoc_args, $format)
    {
        if (!method_exists($media, 'manage_media_library_replacement_session')) {
            return array(
                'success' => false,
                'complete' => false,
                'batches' => 0,
                'message' => 'Replacement lease management is not available.',
                'workflow' => $media->get_media_library_replacement_workflow_status(),
            );
        }

        $batch_size = isset($assoc_args['batch-size']) ? max(1, min(250, absint($assoc_args['batch-size']))) : 50;
        $time_budget = isset($assoc_args['time-budget']) ? (float) $assoc_args['time-budget'] : 15.0;
        $time_budget = max(1.0, min(25.0, $time_budget));
        $max_batches = isset($assoc_args['max-batches']) ? max(0, absint($assoc_args['max-batches'])) : 0;
        $reset_readiness = 'readiness' === $stage && !empty($assoc_args['reset']);

        $this->clear_media_replacement_cli_pause_request();
        $session = $media->manage_media_library_replacement_session('begin', '', $stage, 'cli');
        if (empty($session['success']) || empty($session['token'])) {
            return array(
                'success' => false,
                'complete' => false,
                'batches' => 0,
                'message' => (string) ($session['message'] ?? 'Could not acquire the replacement CLI lease.'),
                'workflow' => $media->get_media_library_replacement_workflow_status(),
            );
        }

        $token = (string) $session['token'];
        $batches = 0;
        $complete = false;
        $success = true;
        $message = '';
        $pause_reason = '';
        $release_action = 'pause';
        $last_signature = '';
        $confirmation_token = '';

        try {
            if ('delete' === $stage) {
                $delete_status = method_exists($media, 'get_media_library_replacement_delete_status')
                    ? $media->get_media_library_replacement_delete_status()
                    : array();
                $starting_fresh = 'verify_complete' === (string) ($delete_status['activeStep'] ?? '');
                $confirmation_token = (string) ($delete_status['confirmationTokens']['cleanupApply'] ?? '');
                if ($starting_fresh && '' === $confirmation_token) {
                    if (!method_exists($media, 'confirm_media_library_replacement_delete')) {
                        throw new RuntimeException('Delete Originals confirmation is not available.');
                    }
$confirmation = $media->confirm_media_library_replacement_delete();
                    if (empty($confirmation['success'])) {
                        throw new RuntimeException((string) ($confirmation['message'] ?? 'Delete Originals confirmation failed.'));
                    }
                    $confirmation_token = (string) ($confirmation['confirmationTokens']['cleanupApply'] ?? '');
                }
                if ($starting_fresh && '' === $confirmation_token) {
                    throw new RuntimeException('Delete Originals start-confirmation token is missing.');
                }
            }

            do {
                if ($this->is_media_replacement_cli_pause_requested()) {
                    $pause_reason = 'pause_requested';
                    break;
                }

                $renewed = $media->manage_media_library_replacement_session('renew', $token, $stage, 'cli');
                if (empty($renewed['success'])) {
                    throw new RuntimeException((string) ($renewed['message'] ?? 'The replacement CLI lease was lost.'));
                }
                if ($this->is_media_replacement_cli_pause_requested()) {
                    $pause_reason = 'pause_requested';
                    break;
                }

                $before = $this->get_media_replacement_cli_stage_status($media, $stage);
                if ($this->is_media_replacement_cli_stage_complete($stage, $before)) {
                    $complete = true;
                    $release_action = 'end';
                    break;
                }
                if ($this->is_media_replacement_cli_stage_failed($stage, $before)) {
                    throw new RuntimeException((string) ($before['message'] ?? ucfirst($stage) . ' is in a failed state.'));
                }

                $batches++;
                $result = $this->run_media_replacement_cli_chunk(
                    $media,
                    $stage,
                    $token,
                    $batch_size,
                    $time_budget,
                    $reset_readiness && 1 === $batches,
                    $confirmation_token
                );

                if (empty($result['success'])) {
                    throw new RuntimeException((string) ($result['message'] ?? ucfirst($stage) . ' chunk failed.'));
                }

                $after = $this->get_media_replacement_cli_stage_status($media, $stage);
                $complete = $this->is_media_replacement_cli_stage_complete($stage, $after);
                $signature = $this->get_media_replacement_cli_progress_signature($stage, $after);

                if ('json' !== $format && 'yaml' !== $format) {
                    WP_CLI::log($this->format_media_replacement_cli_progress_line($stage, $after, $batches));
                }

                if ($complete) {
                    $release_action = 'end';
                    break;
                }

                if ($max_batches > 0 && $batches >= $max_batches) {
                    $pause_reason = 'max_batches';
                    break;
                }

                if ('' !== $last_signature && hash_equals($last_signature, $signature)) {
                    $pause_reason = 'no_progress';
                    break;
                }
                $last_signature = $signature;
            } while (true);
        } catch (Throwable $error) {
            $success = false;
            $message = sanitize_text_field((string) $error->getMessage());
            $pause_reason = 'error';
        } finally {
            $media->manage_media_library_replacement_session($release_action, $token, $stage, 'cli');
            $this->clear_media_replacement_cli_pause_request();
        }

        $workflow = $media->get_media_library_replacement_workflow_status();
        if ($success && !$complete && '' === $pause_reason) {
            $pause_reason = 'incomplete';
        }

        return array(
            'success' => $success,
            'complete' => $complete,
            'batches' => $batches,
            'pauseReason' => $pause_reason,
            'message' => $message,
            'workflow' => $workflow,
        );
    }

    private function run_media_replacement_cli_chunk($media, $stage, $token, $batch_size, $time_budget, $reset, $confirmation_token)
    {
        if ('readiness' === $stage) {
            return $media->scan_media_library_replacement_readiness_inventory(array(
                'reset' => $reset,
                'limit' => $batch_size,
                'time_budget' => $time_budget,
            ));
        }

        if ('prepare' === $stage) {
            return $media->run_media_library_replacement_prepare_chunk(array(
                'reset' => false,
                'session_token' => $token,
                'limit' => $batch_size,
                'time_budget' => $time_budget,
            ));
        }

        if ('do' === $stage) {
            return $media->run_media_library_replacement_do_chunk(array(
                'session_token' => $token,
                'limit' => $batch_size,
                'time_budget' => $time_budget,
            ));
        }

        if ('verify' === $stage) {
            return $media->run_media_library_replacement_verify_chunk(array(
                'session_token' => $token,
                'limit' => $batch_size,
                'time_budget' => $time_budget,
            ));
        }

        return $media->run_media_library_replacement_delete_chunk(array(
            'session_token' => $token,
            'limit' => min(100, $batch_size),
            'time_budget' => $time_budget,
            'confirmationToken' => $confirmation_token,
        ));
    }

    private function get_media_replacement_cli_stage_status($media, $stage)
    {
        if ('readiness' === $stage) {
            return $media->get_media_library_replacement_readiness_status();
        }
        if ('prepare' === $stage) {
            return $media->get_media_library_replacement_prepare_status();
        }
        if ('do' === $stage) {
            return $media->get_media_library_replacement_do_status();
        }
        if ('verify' === $stage) {
            return $media->get_media_library_replacement_verify_status();
        }
        return $media->get_media_library_replacement_delete_status();
    }

    private function is_media_replacement_cli_stage_complete($stage, array $status)
    {
        if ('readiness' === $stage) {
            return 'completed' === (string) ($status['status'] ?? '') && empty($status['hasMore']);
        }
        if ('prepare' === $stage) {
            return !empty($status['prepareComplete']);
        }
        if ('do' === $stage) {
            return !empty($status['doComplete']);
        }
        if ('verify' === $stage) {
            return !empty($status['verifyComplete']);
        }
        return !empty($status['deleteComplete']);
    }

    private function is_media_replacement_cli_stage_failed($stage, array $status)
    {
        // Readiness, Verify, and Delete expose server-side retry semantics.
        // Prepare requires an explicit plan restart and Do requires recovery/rollback.
        if ('prepare' === $stage) {
            return !empty($status['prepareFailed']);
        }
        if ('do' === $stage) {
            return !empty($status['doFailed']);
        }
        return false;
    }

    private function get_media_replacement_cli_progress_signature($stage, array $status)
    {
        return wp_json_encode(array(
            'stage' => $stage,
            'status' => (string) ($status['status'] ?? ''),
            'runStatus' => (string) ($status['runStatus'] ?? ''),
            'activeStep' => (string) ($status['activeStep'] ?? ''),
            'processed' => (int) ($status['processed'] ?? $status['scannedAttachments'] ?? 0),
            'total' => (int) ($status['total'] ?? $status['candidateAttachments'] ?? 0),
            'hasMore' => !empty($status['hasMore']),
        ));
    }

    private function format_media_replacement_cli_progress_line($stage, array $status, $batch)
    {
        $processed = (int) ($status['processed'] ?? $status['scannedAttachments'] ?? 0);
        $total = (int) ($status['total'] ?? $status['candidateAttachments'] ?? 0);
        $active_step = (string) ($status['activeStep'] ?? $status['status'] ?? $stage);
        return sprintf(
            '[%s #%d] %s · %d/%d · %s',
            strtoupper($stage),
            (int) $batch,
            $active_step,
            $processed,
            $total,
            (string) ($status['message'] ?? '')
        );
    }

    private function request_media_replacement_cli_pause($media, $format)
    {
        $workflow = $media->get_media_library_replacement_workflow_status();
        $session = isset($workflow['replacementSession']) && is_array($workflow['replacementSession'])
            ? $workflow['replacementSession']
            : array();

        if (!empty($session['active']) && 'cli' !== (string) ($session['owner'] ?? '')) {
            WP_CLI::error('The active replacement lease belongs to a dashboard session. Pause it from that dashboard.');
        }

        if (!empty($session['active'])) {
            update_option($this->get_media_replacement_cli_pause_option_name(), array(
                'requestedAt' => time(),
                'activeStep' => sanitize_key((string) ($session['activeStep'] ?? '')),
            ), false);
            if ('json' === $format) {
                WP_CLI::line((string) wp_json_encode(array('success' => true, 'pauseRequested' => true), JSON_PRETTY_PRINT));
            } elseif ('yaml' === $format) {
                WP_CLI::print_value(array('success' => true, 'pauseRequested' => true), array('format' => 'yaml'));
            } else {
                WP_CLI::success('Pause requested. The active CLI runner will stop after its current server chunk.');
            }
            return;
        }

        $stage = sanitize_key((string) ($workflow['recovery']['resumeStage'] ?? $workflow['workflowStage'] ?? 'readiness'));
        if (!in_array($stage, array('readiness', 'prepare', 'do', 'verify', 'delete'), true)) {
            $stage = 'readiness';
        }
        $result = $media->manage_media_library_replacement_session('pause', '', $stage, 'cli');
        $this->print_media_replacement_cli_status($media->get_media_library_replacement_workflow_status(), $format);
        if (empty($result['success'])) {
            WP_CLI::error((string) ($result['message'] ?? 'Could not mark the replacement workflow paused.'));
        }
        if ('json' !== $format && 'yaml' !== $format) {
            WP_CLI::success('No active CLI lease was running; the saved replacement stage is paused.');
        }
    }

    private function get_media_replacement_cli_pause_option_name()
    {
        return 'ultracache_media_replacement_cli_pause_request_v1';
    }

    private function clear_media_replacement_cli_pause_request()
    {
        delete_option($this->get_media_replacement_cli_pause_option_name());
    }

    private function is_media_replacement_cli_pause_requested()
    {
        return false !== get_option($this->get_media_replacement_cli_pause_option_name(), false);
    }

    private function redact_media_replacement_cli_status(array $workflow)
    {
        foreach ($workflow as $key => $value) {
            if (in_array((string) $key, array('confirmationTokens', 'confirmationToken', 'token'), true)) {
                $workflow[$key] = is_array($value) ? array_fill_keys(array_keys($value), '[redacted]') : '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $workflow[$key] = $this->redact_media_replacement_cli_status($value);
            }
        }
        return $workflow;
    }

    private function print_media_replacement_cli_status(array $workflow, $format)
    {
        $workflow = $this->redact_media_replacement_cli_status($workflow);
        if ('json' === $format) {
            WP_CLI::line((string) wp_json_encode($workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ('yaml' === $format) {
            WP_CLI::print_value($workflow, array('format' => 'yaml'));
            return;
        }

        $session = isset($workflow['replacementSession']) && is_array($workflow['replacementSession']) ? $workflow['replacementSession'] : array();
        $recovery = isset($workflow['recovery']) && is_array($workflow['recovery']) ? $workflow['recovery'] : array();
        $rows = array(
            array('key' => 'Workflow stage', 'value' => (string) ($workflow['workflowStage'] ?? '')),
            array('key' => 'Run status', 'value' => (string) ($workflow['runStatus'] ?? '')),
            array('key' => 'Active step', 'value' => (string) ($workflow['activeStep'] ?? '')),
            array('key' => 'Resume stage', 'value' => (string) ($recovery['resumeStage'] ?? '')),
            array('key' => 'Lease active', 'value' => !empty($session['active']) ? 'yes' : 'no'),
            array('key' => 'Lease owner', 'value' => (string) ($session['owner'] ?? '')),
            array('key' => 'Readiness', 'value' => $this->format_media_replacement_cli_stage_summary((array) ($workflow['readiness'] ?? array()), 'readiness')),
            array('key' => 'Prepare', 'value' => $this->format_media_replacement_cli_stage_summary((array) ($workflow['prepare'] ?? array()), 'prepare')),
            array('key' => 'Do', 'value' => $this->format_media_replacement_cli_stage_summary((array) ($workflow['do'] ?? array()), 'do')),
            array('key' => 'Verify', 'value' => $this->format_media_replacement_cli_stage_summary((array) ($workflow['verify'] ?? array()), 'verify')),
            array('key' => 'Delete', 'value' => $this->format_media_replacement_cli_stage_summary((array) ($workflow['delete'] ?? array()), 'delete')),
            array('key' => 'Message', 'value' => (string) ($workflow['workflowMessage'] ?? $workflow['message'] ?? '')),
        );
        \WP_CLI\Utils\format_items('table', $rows, array('key', 'value'));
    }

    private function format_media_replacement_cli_stage_summary(array $status, $stage)
    {
        $processed = (int) ($status['processed'] ?? $status['scannedAttachments'] ?? 0);
        $total = (int) ($status['total'] ?? $status['candidateAttachments'] ?? 0);
        $state = (string) ($status['activeStep'] ?? $status['status'] ?? 'idle');
        $complete_key = array(
            'prepare' => 'prepareComplete',
            'do' => 'doComplete',
            'verify' => 'verifyComplete',
            'delete' => 'deleteComplete',
        );
        $complete = 'readiness' === $stage
            ? 'completed' === (string) ($status['status'] ?? '') && empty($status['hasMore'])
            : !empty($status[$complete_key[$stage] ?? '']);
        return sprintf('%s · %d/%d%s', $state, $processed, $total, $complete ? ' · complete' : '');
    }

    private function print_media_replacement_cli_help()
    {
        WP_CLI::line('UltraCache Media Library replacement command reference');
        WP_CLI::line('');
        WP_CLI::line('  wp ultracache media-replace status [--format=table|json|yaml]');
        WP_CLI::line('  wp ultracache media-replace run --stage=readiness [--reset] [--batch-size=50] [--time-budget=15]');
        WP_CLI::line('  wp ultracache media-replace run --stage=prepare [--batch-size=50] [--time-budget=15]');
        WP_CLI::line('  wp ultracache media-replace run --stage=do --yes');
        WP_CLI::line('  wp ultracache media-replace run --stage=verify');
        WP_CLI::line('  wp ultracache media-replace run --stage=delete --yes');
        WP_CLI::line('  wp ultracache media-replace resume [--yes]');
        WP_CLI::line('  wp ultracache media-replace pause');
        WP_CLI::line('  wp ultracache media-replace restart --yes');
        WP_CLI::line('');
        WP_CLI::line('Each command runs the same server-backed stage and cursors used by the dashboard.');
        WP_CLI::line('Approval boundaries remain separate: readiness, Prepare, Do, Verify, and Delete Originals never auto-advance into the next stage.');
    }
}
