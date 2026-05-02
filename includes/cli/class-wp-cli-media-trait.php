<?php
/**
 * WP-CLI command group for UltraCache.
 */

defined('ABSPATH') || exit;

if (!trait_exists('UCWP_CLI_Media_Trait')) {
    trait UCWP_CLI_Media_Trait
    {
        public function media($args, $assoc_args)
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'generate_attachment_formats')) {
                WP_CLI::error('Media converter is not available.');
            }

            $action = !empty($args[0]) ? strtolower((string) $args[0]) : 'process';
            $format = !empty($assoc_args['media-format']) ? strtolower((string) $assoc_args['media-format']) : 'best';
            if (!in_array($format, array('best', 'avif', 'webp', 'both'), true)) {
                WP_CLI::error('Invalid --media-format. Use best, avif, webp, or both.');
            }

            $output_format = !empty($assoc_args['format']) ? strtolower((string) $assoc_args['format']) : 'table';
            if (!in_array($output_format, array('table', 'json', 'yaml'), true)) {
                WP_CLI::error('Invalid --format. Use table, json, or yaml.');
            }

            $only_missing = array_key_exists('only-missing', $assoc_args) ? (bool) $assoc_args['only-missing'] : true;
            $limit = isset($assoc_args['limit']) ? max(0, absint($assoc_args['limit'])) : 0;

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
                if ('rebuild' === $action) {
                    $result = $media->rebuild_media_conversion_queue($format, $only_missing, $limit);
                    $print_result($result);
                    if ('json' !== $output_format) {
                        WP_CLI::success(sprintf('Media queue rebuilt. Queued: %d. Pending: %d. Failed: %d.', (int) ($result['queued'] ?? 0), (int) ($result['pending'] ?? 0), (int) ($result['failed'] ?? 0)));
                    }
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

                if ('process' !== $action) {
                    WP_CLI::error('Invalid media action. Use status, rebuild, process, retry-failed, or clear-completed.');
                }

                $batch_limit = $limit > 0 ? $limit : 25;
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
                    WP_CLI::success(sprintf('Media queue processed: %d attachment(s). Generated %d AVIF and %d WebP. Failed this run: %d. Already optimized this run: %d. Remaining: %d.', (int) ($result['processed'] ?? 0), (int) ($result['avif'] ?? 0), (int) ($result['webp'] ?? 0), (int) ($result['failedThisRun'] ?? 0), (int) ($result['skippedThisRun'] ?? 0), (int) ($result['remaining'] ?? 0)));
                }
                return;
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


        /**
         * Show UltraCache status.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : Output format: table or json. Default: table.
         *
         * [--section=<section>]
         * : One of summary, settings, diagnostics, storage, stats, all. Default: summary.
         */

    }
}
