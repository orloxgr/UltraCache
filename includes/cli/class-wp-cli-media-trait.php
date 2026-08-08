<?php
/**
 * WP-CLI command group for UltraCache media optimization.
 */

defined('ABSPATH') || exit;

trait ULTRACACHE_CLI_Media_Trait
{
    /** @var bool Whether SIGINT/SIGTERM requested a graceful stop. */
    private $media_cli_cancel_requested = false;

    /** @var array<int,mixed> Previous signal handlers restored after the command. */
    private $media_cli_previous_signal_handlers = array();

    /** @var bool|null Previous async-signal mode. */
    private $media_cli_previous_async_signals = null;

    /**
     * Manage UltraCache optimized AVIF/WebP media.
     *
     * Common user commands:
     * - `wp ultracache media rebuild` regenerates all optimized images until complete.
     * - `wp ultracache media process` processes the current queue until complete.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : Action. One of help, status, rebuild, regenerate, process, process-batch, retry-failed, clear-completed. Default: help.
     *
     * [--media-format=<format>]
     * : Output format target. One of both, best, avif, webp. Default: both.
     *
     * [--only-missing]
     * : Only generate missing variants; do not replace existing optimized files.
     *
     * [--batch-size=<number>]
     * : Internal batch size for full processing. Default: 25.
     *
     * [--time-budget=<seconds>]
     * : Internal per-batch time budget. Default: 20.
     *
     * [--max-batches=<number>]
     * : Stop after N internal chunks. Default: unlimited.
     *
     * [--ids=<ids>]
     * : Comma-separated attachment IDs to process directly.
     *
     * [--format=<format>]
     * : Output format. One of table, json, yaml. Default: table.
     */
    public function media($args, $assoc_args)
    {
        $media = $this->get_media();
        if (!$media || !method_exists($media, 'process_queued_attachment') || !method_exists($media, 'process_media_queue_batch')) {
            WP_CLI::error('Media converter is not available.');
        }

        $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'help';
        $format = !empty($assoc_args['media-format']) ? strtolower((string) $assoc_args['media-format']) : 'both';
        if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
            WP_CLI::error('Invalid --media-format. Use both, best, avif, or webp.');
        }

        $output_format = !empty($assoc_args['format']) ? strtolower((string) $assoc_args['format']) : 'table';
        if (!in_array($output_format, array('table', 'json', 'yaml'), true)) {
            WP_CLI::error('Invalid --format. Use table, json, or yaml.');
        }

        // 2.56.188: CLI commands now do what a user expects by default.
        // `wp ultracache media rebuild` means regenerate all optimized variants.
        // Add --only-missing when the desired operation is just repairing missing files.
        $only_missing = array_key_exists('only-missing', $assoc_args);
        $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;

        if ('help' === $action || 'commands' === $action) {
            $this->print_media_cli_help();
            return;
        }

        $flatten_result = static function($result) {
            if (!is_array($result)) {
                return array();
            }
            $row = array();
            foreach ($result as $key => $value) {
                if (is_bool($value)) {
                    $row[$key] = $value ? 'yes' : 'no';
                } elseif (is_scalar($value) || null === $value) {
                    $row[$key] = null === $value ? '' : $value;
                }
            }
            return $row;
        };

        $print_result = static function($result) use ($output_format, $flatten_result) {
            if ('json' === $output_format) {
                WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            $row = $flatten_result($result);
            if (empty($row)) {
                return;
            }
            if ('yaml' === $output_format) {
                WP_CLI::print_value($row, array('format' => 'yaml'));
                return;
            }
            WP_CLI\Utils\format_items('table', array($row), array_keys($row));
        };

        if (!empty($assoc_args['ids'])) {
            $ids = array_values(array_filter(array_map('absint', explode(',', (string) $assoc_args['ids']))));
            if ($limit > 0) {
                $ids = array_slice($ids, 0, $limit);
            }
            if (empty($ids)) {
                WP_CLI::warning('No attachments to process.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar('Generating media variants', count($ids));
            $attachments = 0;
            $unit_attempts = 0;
            $unit_failures = 0;
            $avif = 0;
            $webp = 0;
            $failed = 0;
            foreach ($ids as $attachment_id) {
                $attachment_failed = false;
                do {
                    $result = $media->process_queued_attachment((int) $attachment_id, $format, $only_missing);
                    $unit_attempts += max(0, (int) ($result['workCompletedThisRun'] ?? 0));
                    $avif += (int) ($result['avif'] ?? 0);
                    $webp += (int) ($result['webp'] ?? 0);
                    if (empty($result['success']) && (int) ($result['workCompletedThisRun'] ?? 0) > 0) {
                        $unit_failures += max(1, (int) ($result['workCompletedThisRun'] ?? 0));
                    }
                    $queue_status = (string) ($result['queueStatus'] ?? '');
                    if (empty($result['success']) || 'failed' === $queue_status) {
                        $attachment_failed = true;
                        break;
                    }
                    if (!empty($result['complete']) || in_array($queue_status, array('done', 'skipped'), true)) {
                        break;
                    }
                    if (!empty($result['paused']) || (int) ($result['workCompletedThisRun'] ?? 0) <= 0) {
                        $attachment_failed = true;
                        break;
                    }
                } while (true);

                if ($attachment_failed) {
                    $failed++;
                } else {
                    $attachments++;
                }
                $progress->tick();
            }
            $progress->finish();
            $message = sprintf('Processed %d attachment(s) across %d physical unit attempt(s). Generated %d AVIF and %d WebP files. Failed attachments: %d. Failed unit attempts: %d.', $attachments, $unit_attempts, $avif, $webp, $failed, $unit_failures);
            if ($failed > 0) {
                WP_CLI::warning($message);
            } else {
                WP_CLI::success($message);
            }
            return;
        }

        if (method_exists($media, 'get_media_queue_status')) {
            if ('status' === $action) {
                $print_result($media->get_media_queue_status($format));
                return;
            }

            if ('retry-failed' === $action) {
                $result = $media->retry_failed_media_queue_items($format);
                $print_result($result);
                if ('json' !== $output_format) {
                    WP_CLI::success(sprintf('Failed media queue rows reset to pending: %d.', (int) ($result['retried'] ?? 0)));
                }
                return;
            }

            if ('clear-completed' === $action) {
                $result = $media->clear_completed_media_queue_items($format);
                $print_result($result);
                if ('json' !== $output_format) {
                    WP_CLI::success(sprintf('Completed media queue rows cleared: %d.', (int) ($result['cleared'] ?? 0)));
                }
                return;
            }

            if ('rebuild' === $action || 'regenerate' === $action) {
                $queue_limit = isset($assoc_args['queue-limit']) ? max(0, absint($assoc_args['queue-limit'])) : 0;
                $rebuilt = $media->rebuild_media_conversion_queue($format, $only_missing, $queue_limit);
                if (empty($rebuilt['success'])) {
                    WP_CLI::error(!empty($rebuilt['message']) ? $rebuilt['message'] : 'Media queue could not be rebuilt.');
                }

                if ('json' !== $output_format) {
                    WP_CLI::log(sprintf(
                        'Media queue rebuilt. Queued: %d. Pending: %d. Failed: %d.',
                        (int) ($rebuilt['queued'] ?? 0),
                        (int) ($rebuilt['pending'] ?? 0),
                        (int) ($rebuilt['failed'] ?? 0)
                    ));
                }

                $summary = $this->run_media_cli_queue_to_completion($media, $format, $only_missing, $assoc_args, $output_format, 'rebuild');
                $summary['queueQueued'] = (int) ($rebuilt['queued'] ?? 0);
                $summary['queuePending'] = (int) ($rebuilt['pending'] ?? 0);
                $summary['queueFailed'] = (int) ($rebuilt['failed'] ?? 0);

                $print_result($summary);
                $this->print_media_cli_completion_message($summary, $output_format, 'Media rebuild');
                return;
            }

            if ('process' === $action) {
                $status = $media->get_media_queue_status($format);
                if (empty($status['total'])) {
                    $rebuilt = $media->rebuild_media_conversion_queue($format, $only_missing, 0);
                    if (empty($rebuilt['success'])) {
                        WP_CLI::error(!empty($rebuilt['message']) ? $rebuilt['message'] : 'Media queue could not be rebuilt.');
                    }
                    if ('json' !== $output_format) {
                        WP_CLI::log('Media queue was empty, so UltraCache rebuilt it before processing.');
                    }
                }

                $summary = $this->run_media_cli_queue_to_completion($media, $format, $only_missing, $assoc_args, $output_format, 'process');
                $print_result($summary);
                $this->print_media_cli_completion_message($summary, $output_format, 'Media process');
                return;
            }

            if ('process-batch' === $action) {
                $batch_limit = $this->get_media_cli_batch_limit($assoc_args);
                $time_budget = isset($assoc_args['time-budget']) ? max(0, absint($assoc_args['time-budget'])) : 20;
                $status = $media->get_media_queue_status($format);
                if (empty($status['total'])) {
                    $rebuilt = $media->rebuild_media_conversion_queue($format, $only_missing, 0);
                    if (empty($rebuilt['success'])) {
                        WP_CLI::error(!empty($rebuilt['message']) ? $rebuilt['message'] : 'Media queue could not be rebuilt.');
                    }
                }
                $this->begin_media_cli_interrupt_handling();
                try {
                    $result = $media->process_media_queue_batch(array(
                        'limit' => $batch_limit,
                        'format' => $format,
                        'only_missing' => $only_missing,
                        'time_budget' => $time_budget,
                        'should_cancel' => function() {
                            return $this->is_media_cli_interrupt_requested();
                        },
                    ));
                } finally {
                    $this->end_media_cli_interrupt_handling();
                }
                $print_result($result);
                if (!empty($result['cancelled']) && 'json' !== $output_format) {
                    WP_CLI::warning(sprintf('Media queue batch cancelled cleanly. Touched %d parent request(s), attempted %d physical unit(s), and completed %d attachment(s). Remaining attachments: %d.', (int) ($result['parentsTouchedThisRun'] ?? $result['processed'] ?? 0), (int) ($result['unitAttemptsThisRun'] ?? $result['unitsProcessed'] ?? 0), (int) ($result['attachmentsCompletedThisRun'] ?? 0), (int) ($result['remaining'] ?? 0)));
                } elseif ('json' !== $output_format) {
                    WP_CLI::success(sprintf('Media queue batch: touched %d parent request(s), attempted %d physical unit(s), completed %d attachment(s), generated %d AVIF and %d WebP. Unit failures this run: %d. Remaining attachments: %d.', (int) ($result['parentsTouchedThisRun'] ?? $result['processed'] ?? 0), (int) ($result['unitAttemptsThisRun'] ?? $result['unitsProcessed'] ?? 0), (int) ($result['attachmentsCompletedThisRun'] ?? 0), (int) ($result['avif'] ?? 0), (int) ($result['webp'] ?? 0), (int) ($result['unitFailuresThisRun'] ?? $result['failedThisRun'] ?? 0), (int) ($result['remaining'] ?? 0)));
                }
                return;
            }

            WP_CLI::error('Invalid media action. Use help, status, rebuild, process, retry-failed, clear-completed, or process-batch.');
        }

        WP_CLI::error('Persistent media queue is not available. UltraCache will not fall back to synchronous full-library processing.');
    }

    private function get_media_cli_batch_limit($assoc_args)
    {
        if (isset($assoc_args['batch-size'])) {
            return max(1, absint($assoc_args['batch-size']));
        }
        if (isset($assoc_args['limit'])) {
            return max(1, absint($assoc_args['limit']));
        }
        return 25;
    }

    private function run_media_cli_queue_to_completion($media, $format, $only_missing, $assoc_args, $output_format, $action)
    {
        $batch_limit = $this->get_media_cli_batch_limit($assoc_args);
        $time_budget = isset($assoc_args['time-budget']) ? max(0, absint($assoc_args['time-budget'])) : 20;
        $max_batches = isset($assoc_args['max-batches']) ? max(0, absint($assoc_args['max-batches'])) : 0;

        $total_parent_touches = 0;
        $total_attachment_touches = 0;
        $total_local_asset_touches = 0;
        $total_attachments_completed = 0;
        $total_attachments_failed = 0;
        $total_local_assets_completed = 0;
        $total_local_assets_failed = 0;
        $total_unit_attempts = 0;
        $total_units_resolved = 0;
        $total_units_skipped = 0;
        $total_units_already_optimized = 0;
        $total_unit_failures = 0;
        $total_terminal_unit_failures = 0;
        $total_unit_migration_parents = 0;
        $total_unit_migration_changed_parents = 0;
        $total_unit_migration_changed_units = 0;
        $total_avif = 0;
        $total_webp = 0;
        $total_skipped = 0;
        $batches = 0;
        $last_result = array();
        $pause_reason = '';
        $cancelled = false;
        $previous_persisted_signature = '';
        $unchanged_persisted_batches = 0;

        $this->begin_media_cli_interrupt_handling();
        try {
            do {
                if ($this->is_media_cli_interrupt_requested()) {
                    $cancelled = true;
                    $pause_reason = 'cancelled';
                    break;
                }

                $batches++;
                $last_result = $media->process_media_queue_batch(array(
                    'limit' => $batch_limit,
                    'format' => $format,
                    'only_missing' => $only_missing,
                    'time_budget' => $time_budget,
                    'should_cancel' => function() {
                        return $this->is_media_cli_interrupt_requested();
                    },
                ));

                $parent_touches = max(0, (int) ($last_result['parentsTouchedThisRun'] ?? $last_result['processed'] ?? 0));
                $attachment_touches = max(0, (int) ($last_result['attachmentsTouchedThisRun'] ?? 0));
                $local_asset_touches = max(0, (int) ($last_result['localAssetsTouchedThisRun'] ?? 0));
                $unit_attempts = max(0, (int) ($last_result['unitAttemptsThisRun'] ?? $last_result['unitsProcessed'] ?? 0));
                $unit_migration_parents = max(0, (int) ($last_result['unitMigrationParentsThisRun'] ?? 0));
                $remaining = max(0, (int) ($last_result['remaining'] ?? 0));

                $total_parent_touches += $parent_touches;
                $total_attachment_touches += $attachment_touches;
                $total_local_asset_touches += $local_asset_touches;
                $total_attachments_completed += max(0, (int) ($last_result['attachmentsCompletedThisRun'] ?? 0));
                $total_attachments_failed += max(0, (int) ($last_result['attachmentsFailedThisRun'] ?? 0));
                $total_local_assets_completed += max(0, (int) ($last_result['localAssetsCompletedThisRun'] ?? 0));
                $total_local_assets_failed += max(0, (int) ($last_result['localAssetsFailedThisRun'] ?? 0));
                $total_unit_attempts += $unit_attempts;
                $total_units_resolved += max(0, (int) ($last_result['unitsResolvedThisRun'] ?? 0));
                $total_units_skipped += max(0, (int) ($last_result['unitsSkippedThisRun'] ?? 0));
                $total_units_already_optimized += max(0, (int) ($last_result['unitsAlreadyOptimizedThisRun'] ?? 0));
                $total_unit_failures += max(0, (int) ($last_result['unitFailuresThisRun'] ?? 0));
                $total_terminal_unit_failures += max(0, (int) ($last_result['terminalUnitFailuresThisRun'] ?? 0));
                $total_unit_migration_parents += $unit_migration_parents;
                $total_unit_migration_changed_parents += max(0, (int) ($last_result['unitMigrationChangedParentsThisRun'] ?? 0));
                $total_unit_migration_changed_units += max(0, (int) ($last_result['unitMigrationChangedUnitsThisRun'] ?? 0));
                $total_avif += (int) ($last_result['avif'] ?? 0);
                $total_webp += (int) ($last_result['webp'] ?? 0);
                $total_skipped += (int) ($last_result['skippedThisRun'] ?? 0) + (int) ($last_result['alreadyOptimizedThisRun'] ?? 0);

                if ('json' !== $output_format) {
                    WP_CLI::log(sprintf(
                        'Touched %d parent request(s); attempted %d physical unit(s); materialized/reconciled %d parent inventory row(s); completed %d attachment(s); generated %d AVIF / %d WebP; remaining attachments: %d; remaining units: %d.',
                        $parent_touches,
                        $unit_attempts,
                        $unit_migration_parents,
                        (int) ($last_result['attachmentsCompletedThisRun'] ?? 0),
                        (int) ($last_result['avif'] ?? 0),
                        (int) ($last_result['webp'] ?? 0),
                        $remaining,
                        max(0, (int) ($last_result['unitRemaining'] ?? 0))
                    ));
                }

                if (!empty($last_result['cancelled']) || $this->is_media_cli_interrupt_requested()) {
                    $cancelled = true;
                    $pause_reason = 'cancelled';
                    break;
                }
                if (!empty($last_result['leaseLost'])) {
                    $pause_reason = 'lease_lost';
                    break;
                }

                $persisted_signature_values = array(
                    max(0, (int) ($last_result['remaining'] ?? 0)),
                    max(0, (int) ($last_result['unitOutstanding'] ?? 0)),
                    max(0, (int) ($last_result['unitPending'] ?? 0)),
                    max(0, (int) ($last_result['unitProcessing'] ?? 0)),
                    max(0, (int) ($last_result['unitDone'] ?? 0)),
                    max(0, (int) ($last_result['unitSkipped'] ?? 0)),
                    max(0, (int) ($last_result['unitFailed'] ?? 0)),
                    max(0, (int) ($last_result['unitUnmaterializedParents'] ?? 0)),
                    max(0, (int) ($last_result['attachmentPending'] ?? 0)),
                    max(0, (int) ($last_result['localAssetPending'] ?? 0)),
                );
                $persisted_signature = implode(':', array_map('strval', $persisted_signature_values));
                $signature_has_outstanding = $persisted_signature_values[0] > 0
                    || $persisted_signature_values[1] > 0
                    || $persisted_signature_values[7] > 0;
                if ($signature_has_outstanding && '' !== $previous_persisted_signature && hash_equals($previous_persisted_signature, $persisted_signature)) {
                    $unchanged_persisted_batches++;
                } else {
                    $unchanged_persisted_batches = 0;
                }
                $previous_persisted_signature = $persisted_signature;
                if ($signature_has_outstanding && $unchanged_persisted_batches >= 2) {
                    $pause_reason = 'no_persisted_progress';
                    break;
                }

                $queue_failed = max(0, (int) ($last_result['failed'] ?? 0));
                $unit_failed = max(0, (int) ($last_result['unitFailed'] ?? 0));
                if (!empty($last_result['isComplete']) || !empty($last_result['complete'])) {
                    break;
                }
                if ($remaining <= 0 && ($queue_failed > 0 || $unit_failed > 0)) {
                    $pause_reason = 'failed';
                    break;
                }
                if ($max_batches > 0 && $batches >= $max_batches) {
                    $pause_reason = 'max_batches';
                    break;
                }
                if ($parent_touches <= 0 && $unit_attempts <= 0 && (int) ($last_result['unitMigrationFailuresThisRun'] ?? 0) > 0) {
                    $pause_reason = 'unit_inventory_failed';
                    break;
                }
                if ($parent_touches <= 0 && $unit_attempts <= 0 && $unit_migration_parents <= 0 && ((int) ($last_result['unitOutstanding'] ?? 0) > 0 || empty($last_result['unitInventoryComplete']))) {
                    $pause_reason = !empty($last_result['pauseReason']) ? (string) $last_result['pauseReason'] : 'no_progress';
                    break;
                }
            } while (true);
        } finally {
            $this->end_media_cli_interrupt_handling();
        }

        $queue_failed = max(0, (int) ($last_result['failed'] ?? 0));
        $unit_failed = max(0, (int) ($last_result['unitFailed'] ?? 0));
        $is_complete = !$cancelled
            && !empty($last_result)
            && $queue_failed <= 0
            && $unit_failed <= 0
            && (!empty($last_result['isComplete']) || !empty($last_result['complete']));
        $attachments_processed = $total_attachments_completed + $total_attachments_failed;
        $local_assets_processed = $total_local_assets_completed + $total_local_assets_failed;

        return array(
            'success' => $is_complete,
            'action' => $action,
            'mediaFormat' => $format,
            'onlyMissing' => (bool) $only_missing,
            'batchSize' => $batch_limit,
            'timeBudget' => $time_budget,
            'batches' => $batches,
            'processed' => $attachments_processed,
            'attachmentsProcessed' => $attachments_processed,
            'attachmentsCompleted' => $total_attachments_completed,
            'attachmentsFailedThisRun' => $total_attachments_failed,
            'attachmentParentTouches' => $total_attachment_touches,
            'localAssetsProcessed' => $local_assets_processed,
            'localAssetsCompleted' => $total_local_assets_completed,
            'localAssetsFailedThisRun' => $total_local_assets_failed,
            'localAssetParentTouches' => $total_local_asset_touches,
            'parentTouches' => $total_parent_touches,
            'unitAttempts' => $total_unit_attempts,
            'unitsResolved' => $total_units_resolved,
            'unitsSkipped' => $total_units_skipped,
            'unitsAlreadyOptimized' => $total_units_already_optimized,
            'unitsGenerated' => $total_avif + $total_webp,
            'unitFailuresThisRun' => $total_unit_failures,
            'terminalUnitFailuresThisRun' => $total_terminal_unit_failures,
            'unitMigrationParents' => $total_unit_migration_parents,
            'unitMigrationChangedParents' => $total_unit_migration_changed_parents,
            'unitMigrationChangedUnits' => $total_unit_migration_changed_units,
            'avif' => $total_avif,
            'webp' => $total_webp,
            'failedThisRun' => $total_unit_failures,
            'skippedThisRun' => $total_skipped,
            'isComplete' => $is_complete,
            'remaining' => (int) ($last_result['remaining'] ?? 0),
            'failed' => $queue_failed,
            'unitRemaining' => (int) ($last_result['unitRemaining'] ?? 0),
            'unitFailed' => $unit_failed,
            'unitOutstanding' => (int) ($last_result['unitOutstanding'] ?? 0),
            'unitInventoryComplete' => !empty($last_result['unitInventoryComplete']),
            'pauseReason' => $pause_reason,
            'cancelled' => $cancelled,
        );
    }

    /**
     * Install temporary SIGINT/SIGTERM handlers for graceful queue cancellation.
     *
     * @return void
     */
    private function begin_media_cli_interrupt_handling()
    {
        $this->media_cli_cancel_requested = false;
        $this->media_cli_previous_signal_handlers = array();
        $this->media_cli_previous_async_signals = null;

        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            $this->media_cli_previous_async_signals = pcntl_async_signals();
            pcntl_async_signals(true);
        }

        foreach (array('SIGINT', 'SIGTERM') as $signal_name) {
            if (!defined($signal_name)) {
                continue;
            }
            $signal = constant($signal_name);
            if (function_exists('pcntl_signal_get_handler')) {
                $this->media_cli_previous_signal_handlers[$signal] = pcntl_signal_get_handler($signal);
            }
            pcntl_signal($signal, function($received_signal) {
                unset($received_signal);
                $this->media_cli_cancel_requested = true;
            });
        }
    }

    /**
     * Return whether an interrupt was requested, dispatching queued signals when needed.
     *
     * @return bool
     */
    private function is_media_cli_interrupt_requested()
    {
        if (function_exists('pcntl_signal_dispatch') && !function_exists('pcntl_async_signals')) {
            pcntl_signal_dispatch();
        }
        return (bool) $this->media_cli_cancel_requested;
    }

    /**
     * Restore signal handling after the media command finishes.
     *
     * @return void
     */
    private function end_media_cli_interrupt_handling()
    {
        if (function_exists('pcntl_signal')) {
            foreach ($this->media_cli_previous_signal_handlers as $signal => $handler) {
                pcntl_signal((int) $signal, $handler);
            }
        }
        if (null !== $this->media_cli_previous_async_signals && function_exists('pcntl_async_signals')) {
            pcntl_async_signals((bool) $this->media_cli_previous_async_signals);
        }
        $this->media_cli_previous_signal_handlers = array();
        $this->media_cli_previous_async_signals = null;
    }

    private function print_media_cli_completion_message(array $summary, $output_format, $label)
    {
        if ('json' === $output_format) {
            return;
        }

        if (!empty($summary['isComplete'])) {
            WP_CLI::success(sprintf('%s complete. Processed %d attachment(s) across %d physical unit attempt(s). Generated %d AVIF and %d WebP.', $label, (int) ($summary['attachmentsProcessed'] ?? $summary['processed'] ?? 0), (int) ($summary['unitAttempts'] ?? 0), (int) ($summary['avif'] ?? 0), (int) ($summary['webp'] ?? 0)));
            return;
        }

        WP_CLI::warning(sprintf('%s paused/incomplete. Processed %d attachment(s) across %d physical unit attempt(s). Remaining attachments: %d. Outstanding units: %d. Failed units: %d. Reason: %s.', $label, (int) ($summary['attachmentsProcessed'] ?? $summary['processed'] ?? 0), (int) ($summary['unitAttempts'] ?? 0), (int) ($summary['remaining'] ?? 0), (int) ($summary['unitOutstanding'] ?? 0), (int) ($summary['unitFailed'] ?? 0), (string) ($summary['pauseReason'] ?? 'unknown')));
    }

    private function print_media_cli_help()
    {
        WP_CLI::line('UltraCache media command reference');
        WP_CLI::line('');
        WP_CLI::line('Common commands:');
        WP_CLI::line('  wp ultracache media rebuild');
        WP_CLI::line('      Rebuild and regenerate all optimized AVIF/WebP images until complete. Default output target: both.');
        WP_CLI::line('  wp ultracache media rebuild --only-missing');
        WP_CLI::line('      Repair only missing optimized AVIF/WebP images. Default output target: both.');
        WP_CLI::line('  wp ultracache media process');
        WP_CLI::line('      Process the current media queue until complete. If the queue is empty, it is rebuilt first.');
        WP_CLI::line('  wp ultracache media status');
        WP_CLI::line('      Show media queue and uploads/ultracache/images storage status.');
        WP_CLI::line('');
        WP_CLI::line('Formats:');
        WP_CLI::line('  --media-format=both   Default. Generate/check both AVIF and WebP variants.');
        WP_CLI::line('  --media-format=best   Best single format for current settings/support, usually AVIF.');
        WP_CLI::line('  --media-format=avif   AVIF only.');
        WP_CLI::line('  --media-format=webp   WebP only.');
        WP_CLI::line('');
        WP_CLI::line('Advanced options:');
        WP_CLI::line('  --only-missing          Do not replace existing optimized variants.');
        WP_CLI::line('  --batch-size=<number>   Internal processing chunk size. Default: 25.');
        WP_CLI::line('  --time-budget=<seconds> Internal per-batch time budget. Default: 20.');
        WP_CLI::line('  --max-batches=<number>  Stop after N internal chunks. Default: unlimited.');
        WP_CLI::line('  Ctrl+C / SIGTERM          Stop cleanly after the current atomic media unit.');
        WP_CLI::line('  --format=table|json|yaml');
        WP_CLI::line('');
        WP_CLI::line('Other actions:');
        WP_CLI::line('  wp ultracache media retry-failed');
        WP_CLI::line('  wp ultracache media clear-completed');
        WP_CLI::line('  wp ultracache media process --ids=12,34,56');
        WP_CLI::line('  wp ultracache media process-batch');
        WP_CLI::line('      Advanced diagnostic action: process one internal chunk only.');
    }
}
