<?php
/**
 * WP-CLI command group for UltraCache media optimization.
 */

defined('ABSPATH') || exit;

if (!trait_exists('UCWP_CLI_Media_Trait')) {
    trait UCWP_CLI_Media_Trait
    {
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
            if (!$media || !method_exists($media, 'generate_attachment_formats')) {
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
                    WP_CLI::line(function_exists('wp_json_encode') ? wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
                $avif = 0;
                $webp = 0;
                $failed = 0;
                foreach ($ids as $attachment_id) {
                    $result = method_exists($media, 'process_queued_attachment')
                        ? $media->process_queued_attachment((int) $attachment_id, $format, $only_missing)
                        : $media->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
                    if (!empty($result['success'])) {
                        $attachments++;
                        $avif += (int) ($result['avif'] ?? 0);
                        $webp += (int) ($result['webp'] ?? 0);
                    } else {
                        $failed++;
                    }
                    $progress->tick();
                }
                $progress->finish();
                WP_CLI::success(sprintf('Processed %d attachments. Generated %d AVIF and %d WebP files. Failed: %d.', $attachments, $avif, $webp, $failed));
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
                    $result = $media->process_media_queue_batch(array(
                        'limit' => $batch_limit,
                        'format' => $format,
                        'only_missing' => $only_missing,
                        'time_budget' => $time_budget,
                    ));
                    $print_result($result);
                    if ('json' !== $output_format) {
                        WP_CLI::success(sprintf('Media queue batch processed: %d attachment(s). Generated %d AVIF and %d WebP. Failed this run: %d. Remaining: %d.', (int) ($result['processed'] ?? 0), (int) ($result['avif'] ?? 0), (int) ($result['webp'] ?? 0), (int) ($result['failedThisRun'] ?? 0), (int) ($result['remaining'] ?? 0)));
                    }
                    return;
                }

                WP_CLI::error('Invalid media action. Use help, status, rebuild, process, retry-failed, clear-completed, or process-batch.');
            }

            WP_CLI::warning('Persistent media queue is not available; falling back to direct media scan.');
            $ids = method_exists($media, 'get_all_media_ids') ? (array) $media->get_all_media_ids() : array();
            if ($limit > 0) {
                $ids = array_slice($ids, 0, $limit);
            }
            if (empty($ids)) {
                WP_CLI::warning('No attachments to process.');
                return;
            }
            $progress = \WP_CLI\Utils\make_progress_bar('Generating media variants', count($ids));
            $attachments = 0;
            $avif = 0;
            $webp = 0;
            foreach ($ids as $attachment_id) {
                $result = $media->generate_attachment_formats((int) $attachment_id, $format, $only_missing);
                if (!empty($result['success'])) {
                    $attachments++;
                    $avif += (int) ($result['avif'] ?? 0);
                    $webp += (int) ($result['webp'] ?? 0);
                }
                $progress->tick();
            }
            $progress->finish();
            WP_CLI::success(sprintf('Processed %d attachments. Generated %d AVIF and %d WebP files.', $attachments, $avif, $webp));
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

            $total_processed = 0;
            $total_avif = 0;
            $total_webp = 0;
            $total_failed = 0;
            $total_skipped = 0;
            $batches = 0;
            $last_result = array();
            $pause_reason = '';

            do {
                $batches++;
                $last_result = $media->process_media_queue_batch(array(
                    'limit' => $batch_limit,
                    'format' => $format,
                    'only_missing' => $only_missing,
                    'time_budget' => $time_budget,
                ));

                $processed = (int) ($last_result['processed'] ?? 0);
                $remaining = (int) ($last_result['remaining'] ?? 0);

                $total_processed += $processed;
                $total_avif += (int) ($last_result['avif'] ?? 0);
                $total_webp += (int) ($last_result['webp'] ?? 0);
                $total_failed += (int) ($last_result['failedThisRun'] ?? 0);
                $total_skipped += (int) ($last_result['skippedThisRun'] ?? 0) + (int) ($last_result['alreadyOptimizedThisRun'] ?? 0);

                if ('json' !== $output_format) {
                    WP_CLI::log(sprintf(
                        'Processed %d attachment(s). Generated %d AVIF / %d WebP. Remaining: %d.',
                        $processed,
                        (int) ($last_result['avif'] ?? 0),
                        (int) ($last_result['webp'] ?? 0),
                        $remaining
                    ));
                }

                if (!empty($last_result['isComplete']) || !empty($last_result['complete']) || $remaining <= 0) {
                    break;
                }

                if ($max_batches > 0 && $batches >= $max_batches) {
                    $pause_reason = 'max_batches';
                    break;
                }

                if ($processed <= 0 && $remaining > 0) {
                    $pause_reason = !empty($last_result['pauseReason']) ? (string) $last_result['pauseReason'] : 'no_progress';
                    break;
                }
            } while (true);

            $is_complete = !empty($last_result['isComplete']) || !empty($last_result['complete']) || (int) ($last_result['remaining'] ?? 0) <= 0;

            return array(
                'success' => true,
                'action' => $action,
                'mediaFormat' => $format,
                'onlyMissing' => (bool) $only_missing,
                'batchSize' => $batch_limit,
                'timeBudget' => $time_budget,
                'batches' => $batches,
                'processed' => $total_processed,
                'avif' => $total_avif,
                'webp' => $total_webp,
                'failedThisRun' => $total_failed,
                'skippedThisRun' => $total_skipped,
                'isComplete' => $is_complete,
                'remaining' => (int) ($last_result['remaining'] ?? 0),
                'pauseReason' => $pause_reason,
            );
        }

        private function print_media_cli_completion_message(array $summary, $output_format, $label)
        {
            if ('json' === $output_format) {
                return;
            }

            if (!empty($summary['isComplete'])) {
                WP_CLI::success(sprintf('%s complete. Processed %d attachment(s). Generated %d AVIF and %d WebP.', $label, (int) ($summary['processed'] ?? 0), (int) ($summary['avif'] ?? 0), (int) ($summary['webp'] ?? 0)));
                return;
            }

            WP_CLI::warning(sprintf('%s paused/incomplete. Processed %d attachment(s). Remaining: %d. Reason: %s.', $label, (int) ($summary['processed'] ?? 0), (int) ($summary['remaining'] ?? 0), (string) ($summary['pauseReason'] ?? 'unknown')));
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
            WP_CLI::line('      Show media queue and uploads/uc-images storage status.');
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
}
