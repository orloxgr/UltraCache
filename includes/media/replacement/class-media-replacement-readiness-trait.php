<?php
/**
 * UltraCache Media Library replacement readiness inventory and start guards.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Readiness_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache reads private custom Media Library replacement and media queue tables with validated table identifiers.

    private function get_media_replacement_readiness_defaults()
    {
        return array(
            'version'                  => 1,
            'generation'               => '',
            'status'                   => 'idle',
            'target_format'            => 'webp',
            'fallback_format'          => 'original',
            'cursor_attachment_id'     => 0,
            'candidate_attachments'    => 0,
            'scanned_attachments'      => 0,
            'eligible_attachments'     => 0,
            'ready_attachments'        => 0,
            'blocked_attachments'      => 0,
            'required_variants'        => 0,
            'ready_variants'           => 0,
            'missing_variants'         => 0,
            'stale_variants'           => 0,
            'failed_variants'          => 0,
            'pending_variants'         => 0,
            'unsupported_variants'     => 0,
            'library_changed'          => false,
            'candidate_count_at_finish'=> 0,
            'blocker_samples'          => array(),
            'created_at'               => '',
            'updated_at'               => '',
            'completed_at'             => '',
            'last_error'               => '',
        );
    }

    private function normalize_media_replacement_readiness_state($state)
    {
        $state = is_array($state) ? $state : array();
        $state = array_merge($this->get_media_replacement_readiness_defaults(), $state);
        $state['version']               = 1;
        $state['generation']            = sanitize_key((string) $state['generation']);
        $state['status']                = in_array((string) $state['status'], array('idle', 'scanning', 'paused', 'completed', 'failed'), true) ? (string) $state['status'] : 'idle';
        $state['target_format']         = in_array((string) $state['target_format'], array('avif', 'webp'), true) ? (string) $state['target_format'] : 'webp';
        $state['fallback_format']       = ('avif' === $state['target_format'] && 'webp' === (string) $state['fallback_format']) ? 'webp' : 'original';
        $state['cursor_attachment_id']  = max(0, absint($state['cursor_attachment_id']));
        $state['library_changed']       = !empty($state['library_changed']);
        $state['last_error']            = sanitize_text_field((string) $state['last_error']);
        foreach (array('created_at', 'updated_at', 'completed_at') as $timestamp_key) {
            $state[$timestamp_key] = sanitize_text_field((string) $state[$timestamp_key]);
        }
        foreach (array(
            'candidate_attachments',
            'scanned_attachments',
            'eligible_attachments',
            'ready_attachments',
            'blocked_attachments',
            'required_variants',
            'ready_variants',
            'missing_variants',
            'stale_variants',
            'failed_variants',
            'pending_variants',
            'unsupported_variants',
            'candidate_count_at_finish',
        ) as $count_key) {
            $state[$count_key] = max(0, (int) $state[$count_key]);
        }

        $samples = array();
        foreach ((array) $state['blocker_samples'] as $sample) {
            if (!is_array($sample) || count($samples) >= 25) {
                continue;
            }
            $samples[] = array(
                'attachmentId' => absint($sample['attachmentId'] ?? 0),
                'scope'        => in_array((string) ($sample['scope'] ?? ''), array('main', 'intermediate'), true) ? (string) $sample['scope'] : 'main',
                'sizeName'     => substr(sanitize_key((string) ($sample['sizeName'] ?? '')), 0, 64),
                'status'       => in_array((string) ($sample['status'] ?? ''), array('missing', 'stale', 'failed', 'pending', 'unsupported'), true) ? (string) $sample['status'] : 'failed',
                'source'       => sanitize_text_field((string) ($sample['source'] ?? '')),
                'reasonCode'   => sanitize_key((string) ($sample['reasonCode'] ?? '')),
                'reason'       => sanitize_text_field((string) ($sample['reason'] ?? '')),
            );
        }
        $state['blocker_samples'] = $samples;

        return $state;
    }

    private function get_media_replacement_readiness_state()
    {
        return $this->normalize_media_replacement_readiness_state(get_option($this->get_media_replacement_readiness_option_name(), array()));
    }

    private function update_media_replacement_readiness_state(array $state)
    {
        $state = $this->normalize_media_replacement_readiness_state($state);
        update_option($this->get_media_replacement_readiness_option_name(), $state, false);
        return $state;
    }

    private function reset_media_replacement_readiness_state($target_format, $fallback_format)
    {
        $generation = sanitize_key('readiness-' . gmdate('Ymd-His') . '-' . strtolower((string) wp_generate_password(5, false, false)));
        return $this->update_media_replacement_readiness_state(array_merge(
            $this->get_media_replacement_readiness_defaults(),
            array(
                'generation'            => $generation,
                'status'                => 'scanning',
                'target_format'         => $target_format,
                'fallback_format'       => $fallback_format,
                'candidate_attachments' => $this->get_media_replacement_candidate_attachment_count(),
                'created_at'            => current_time('mysql', true),
                'updated_at'            => current_time('mysql', true),
            )
        ));
    }

    private function get_media_replacement_readiness_queue_status_map(array $attachment_ids, $target_format)
    {
        global $wpdb;

        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if (empty($attachment_ids) || !($wpdb instanceof wpdb) || !method_exists($this, 'media_queue_table_exists') || !$this->media_queue_table_exists()) {
            return array();
        }

        $table = method_exists($this, 'get_media_queue_table_name') ? $this->get_media_queue_table_name() : '';
        if ('' === $table) {
            return array();
        }

        $target_format = sanitize_key((string) $target_format);
        $rows = array();
        $attachment_id_chunks = array_chunk($attachment_ids, 20);
        foreach ($attachment_id_chunks as $attachment_id_chunk) {
            $last_attachment_id = (int) end($attachment_id_chunk);
            while (count($attachment_id_chunk) < 20) {
                $attachment_id_chunk[] = $last_attachment_id;
            }

            $chunk_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT attachment_id, status FROM %i WHERE attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) AND format IN (%s, %s, %s)',
                    $table,
                    $attachment_id_chunk[0],
                    $attachment_id_chunk[1],
                    $attachment_id_chunk[2],
                    $attachment_id_chunk[3],
                    $attachment_id_chunk[4],
                    $attachment_id_chunk[5],
                    $attachment_id_chunk[6],
                    $attachment_id_chunk[7],
                    $attachment_id_chunk[8],
                    $attachment_id_chunk[9],
                    $attachment_id_chunk[10],
                    $attachment_id_chunk[11],
                    $attachment_id_chunk[12],
                    $attachment_id_chunk[13],
                    $attachment_id_chunk[14],
                    $attachment_id_chunk[15],
                    $attachment_id_chunk[16],
                    $attachment_id_chunk[17],
                    $attachment_id_chunk[18],
                    $attachment_id_chunk[19],
                    $target_format,
                    'best',
                    'both'
                ),
                ARRAY_A
            );
            if (!empty($chunk_rows)) {
                $rows = array_merge($rows, $chunk_rows);
            }
        }

        $priority = array('processing' => 5, 'pending' => 4, 'failed' => 3, 'done' => 2, 'skipped' => 1);
        $map = array();
        foreach ((array) $rows as $row) {
            $attachment_id = absint($row['attachment_id'] ?? 0);
            $status = sanitize_key((string) ($row['status'] ?? ''));
            if ($attachment_id <= 0 || !isset($priority[$status])) {
                continue;
            }
            $existing = isset($map[$attachment_id]) ? (string) $map[$attachment_id] : '';
            if ('' === $existing || $priority[$status] > ($priority[$existing] ?? 0)) {
                $map[$attachment_id] = $status;
            }
        }
        return $map;
    }

    private function get_media_replacement_required_source_variants($attachment_id)
    {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return array();
        }

        $main_file = get_attached_file($attachment_id);
        $main_file = is_string($main_file) ? wp_normalize_path($main_file) : '';
        $metadata = wp_get_attachment_metadata($attachment_id);
        $metadata = is_array($metadata) ? $metadata : array();
        $variants = array();
        $seen_paths = array();

        $append = static function ($scope, $size_name, $source_file) use (&$variants, &$seen_paths) {
            $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
            $dedupe_key = strtolower($source_file);
            if ('' !== $dedupe_key && isset($seen_paths[$dedupe_key])) {
                return;
            }
            if ('' !== $dedupe_key) {
                $seen_paths[$dedupe_key] = true;
            }
            $variants[] = array(
                'scope'       => $scope,
                'size_name'   => $size_name,
                'source_file' => $source_file,
            );
        };

        $append('main', '', $main_file);
        if ('' !== $main_file && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($main_file);
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (!is_array($size_data) || empty($size_data['file'])) {
                    continue;
                }
                $size_file = ltrim(str_replace('\\', '/', (string) $size_data['file']), '/');
                if ('' === $size_file || !preg_match('/\.(?:jpe?g|png)$/i', $size_file)) {
                    continue;
                }
                $append(
                    'intermediate',
                    substr(sanitize_key((string) $size_name), 0, 64),
                    trailingslashit($base_dir) . $size_file
                );
            }
        }

        return $variants;
    }

    private function get_media_replacement_readiness_source_relative_path($source_file)
    {
        $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
        if ('' === $source_file) {
            return '';
        }

        $existing_relative = $this->get_uploads_relative_path_from_source($source_file);
        if ($existing_relative) {
            return (string) $existing_relative;
        }

        $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
        $uploads_root = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ('' === $uploads_root || !$this->path_is_within_root($source_file, $uploads_root)) {
            return '';
        }

        $relative = ltrim(substr($source_file, strlen(rtrim($uploads_root, '/'))), '/');
        if ('' === $relative) {
            return '';
        }
        foreach (explode('/', str_replace('\\', '/', $relative)) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        return $relative;
    }

    private function inspect_media_replacement_readiness_variant($attachment_id, array $variant, $target_format, $queue_status)
    {
        $source_file = isset($variant['source_file']) ? wp_normalize_path((string) $variant['source_file']) : '';
        $scope = isset($variant['scope']) && 'intermediate' === (string) $variant['scope'] ? 'intermediate' : 'main';
        $size_name = 'intermediate' === $scope ? substr(sanitize_key((string) ($variant['size_name'] ?? '')), 0, 64) : '';
        $relative_path = '' !== $source_file ? $this->get_media_replacement_readiness_source_relative_path($source_file) : '';
        $source_label = '' !== (string) $relative_path ? (string) $relative_path : basename($source_file);
        $base = array(
            'attachmentId' => absint($attachment_id),
            'scope'        => $scope,
            'sizeName'     => $size_name,
            'source'       => sanitize_text_field((string) $source_label),
        );

        if ('' === $source_file || !preg_match('/\.(?:jpe?g|png)$/i', $source_file) || '' === (string) $relative_path) {
            return array_merge($base, array('status' => 'unsupported', 'reasonCode' => 'unsupported_source', 'sourceEligible' => false, 'reason' => __('The source variant is not a JPG/PNG file inside the WordPress uploads directory.', 'ultracache')));
        }

        if (!$this->optimized_storage_readable_source_exists($source_file)) {
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'source_missing', 'sourceEligible' => false, 'reason' => __('The source variant is missing or unreadable.', 'ultracache')));
        }

        $generated_path = $this->get_media_replacement_generated_path_for_source($source_file, $target_format);
        if (!$generated_path) {
            return array_merge($base, array('status' => 'unsupported', 'reasonCode' => 'target_mapping_unavailable', 'sourceEligible' => false, 'reason' => __('UltraCache could not map this source variant to the selected replacement format.', 'ultracache')));
        }

        $target_exists = $this->optimized_storage_path_exists($generated_path, true);
        $queue_pending = in_array((string) $queue_status, array('pending', 'processing'), true);
        $queue_failed = 'failed' === (string) $queue_status;

        if (!$target_exists) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_missing_queue_pending', 'sourceEligible' => true, 'reason' => __('The target replacement is still pending in the media conversion queue.', 'ultracache')));
            }
            if ($queue_failed) {
                return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_missing_queue_failed', 'sourceEligible' => true, 'reason' => __('The target replacement is missing and the media conversion queue reports a failure.', 'ultracache')));
            }
            return array_merge($base, array('status' => 'missing', 'reasonCode' => 'target_missing', 'sourceEligible' => true, 'reason' => __('The target replacement file does not exist.', 'ultracache')));
        }

        if (!$this->is_valid_generated_media_file($generated_path, $target_format, 'media_replacement_readiness_validate')) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_invalid_queue_pending', 'sourceEligible' => true, 'reason' => __('The existing target replacement is invalid, but regeneration is pending.', 'ultracache')));
            }
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_invalid', 'sourceEligible' => true, 'reason' => __('The target replacement exists but failed image validation.', 'ultracache')));
        }

        $source_mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($source_file, 'media_replacement_readiness_source_mtime') : 0;
        $target_mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($generated_path, 'media_replacement_readiness_target_mtime') : 0;
        if ($source_mtime <= 0 || $target_mtime <= 0) {
            return array_merge($base, array('status' => 'failed', 'reasonCode' => 'mtime_unavailable', 'sourceEligible' => true, 'reason' => __('UltraCache could not verify source and target modification times.', 'ultracache')));
        }
        if ($target_mtime < $source_mtime) {
            if ($queue_pending) {
                return array_merge($base, array('status' => 'pending', 'reasonCode' => 'target_stale_queue_pending', 'sourceEligible' => true, 'reason' => __('The target replacement is stale and regeneration is pending.', 'ultracache')));
            }
            if ($queue_failed) {
                return array_merge($base, array('status' => 'failed', 'reasonCode' => 'target_stale_queue_failed', 'sourceEligible' => true, 'reason' => __('The target replacement is stale and the media conversion queue reports a failure.', 'ultracache')));
            }
            return array_merge($base, array('status' => 'stale', 'reasonCode' => 'target_stale', 'sourceEligible' => true, 'reason' => __('The target replacement is older than its source variant.', 'ultracache')));
        }

        return array_merge($base, array('status' => 'ready', 'reasonCode' => 'ready', 'sourceEligible' => true, 'reason' => ''));
    }

    private function add_media_replacement_readiness_blocker_sample(array &$state, array $result)
    {
        if (count((array) $state['blocker_samples']) >= 25 || 'ready' === (string) ($result['status'] ?? '')) {
            return;
        }
        $state['blocker_samples'][] = $result;
    }

    private function build_media_replacement_readiness_response(array $state, $has_more = false, $batch_scanned = 0)
    {
        $state = $this->normalize_media_replacement_readiness_state($state);
        $blocker_variants = (int) $state['missing_variants']
            + (int) $state['stale_variants']
            + (int) $state['failed_variants']
            + (int) $state['pending_variants']
            + (int) $state['unsupported_variants'];
        $inventory_complete = 'completed' === $state['status'] && !$has_more;
        $ready = $inventory_complete
            && !$state['library_changed']
            && $state['candidate_attachments'] > 0
            && $state['eligible_attachments'] === $state['candidate_attachments']
            && $state['required_variants'] > 0
            && $state['ready_variants'] === $state['required_variants']
            && 0 === $blocker_variants;
        $progress = $state['candidate_attachments'] > 0
            ? round((min($state['scanned_attachments'], $state['candidate_attachments']) / max(1, $state['candidate_attachments'])) * 100, 1)
            : ($inventory_complete ? 100 : 0);

        if ('failed' === $state['status']) {
            $message = $state['last_error'] ?: __('Replacement readiness inventory failed.', 'ultracache');
        } elseif ('paused' === $state['status']) {
            $message = __('Replacement readiness inventory is paused and can be resumed.', 'ultracache');
        } elseif ($has_more || 'scanning' === $state['status']) {
            $message = sprintf(
                /* translators: 1: scanned attachments, 2: total candidate attachments. */
                __('Replacement readiness inventory scanned %1$d of %2$d candidate attachments.', 'ultracache'),
                (int) $state['scanned_attachments'],
                (int) $state['candidate_attachments']
            );
        } elseif ($ready) {
            $message = sprintf(
                /* translators: 1: replacement variants, 2: target image format. */
                __('Replacement readiness complete. All %1$d required %2$s variants are valid and current.', 'ultracache'),
                (int) $state['required_variants'],
                strtoupper((string) $state['target_format'])
            );
        } else {
            $message = __('Replacement readiness complete, but blockers remain. Media Library replacement must stay locked.', 'ultracache');
        }

        return array(
            'success'                 => 'failed' !== $state['status'],
            'status'                  => $state['status'],
            'message'                 => $message,
            'generation'              => $state['generation'],
            'targetFormat'            => $state['target_format'],
            'fallbackFormat'          => $state['fallback_format'],
            'hasMore'                 => (bool) $has_more,
            'batchScanned'            => max(0, (int) $batch_scanned),
            'nextCursor'              => (int) $state['cursor_attachment_id'],
            'progressPercent'         => $progress,
            'inventoryComplete'       => $inventory_complete,
            'readyForReplacement'     => $ready,
            'libraryChanged'          => (bool) $state['library_changed'],
            'candidateAttachments'    => (int) $state['candidate_attachments'],
            'candidateCountAtFinish'  => (int) $state['candidate_count_at_finish'],
            'scannedAttachments'      => (int) $state['scanned_attachments'],
            'eligibleAttachments'     => (int) $state['eligible_attachments'],
            'readyAttachments'        => (int) $state['ready_attachments'],
            'blockedAttachments'      => (int) $state['blocked_attachments'],
            'requiredVariants'        => (int) $state['required_variants'],
            'readyVariants'           => (int) $state['ready_variants'],
            'missingVariants'         => (int) $state['missing_variants'],
            'staleVariants'           => (int) $state['stale_variants'],
            'failedVariants'          => (int) $state['failed_variants'],
            'pendingVariants'         => (int) $state['pending_variants'],
            'unsupportedVariants'     => (int) $state['unsupported_variants'],
            'blockerVariants'         => $blocker_variants,
            'blockerSamples'          => array_values((array) $state['blocker_samples']),
            'createdAt'               => $state['created_at'],
            'updatedAt'               => $state['updated_at'],
            'completedAt'             => $state['completed_at'],
            'lastError'               => $state['last_error'],
        );
    }

    private function add_media_replacement_start_guard_blocker(array &$blockers, $code, $message, $count = 0)
    {
        $code = sanitize_key((string) $code);
        if ('' === $code) {
            return;
        }

        foreach ($blockers as $blocker) {
            if (isset($blocker['code']) && $code === (string) $blocker['code']) {
                return;
            }
        }

        $blockers[] = array(
            'code'    => $code,
            'message' => sanitize_text_field((string) $message),
            'count'   => max(0, (int) $count),
        );
    }

    public function get_media_library_replacement_start_guard($args = array())
    {
        $args = is_array($args) ? $args : array();
        $requested_generation = sanitize_key((string) ($args['generation'] ?? $args['readiness_generation'] ?? ''));
        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        $state = $this->get_media_replacement_readiness_state();
        $readiness = $this->get_media_library_replacement_readiness_status();
        $blockers = array();

        $inventory_complete = 'completed' === $state['status'] && !empty($readiness['inventoryComplete']) && empty($readiness['hasMore']);
        $current_candidate_count = $inventory_complete
            ? $this->get_media_replacement_candidate_attachment_count()
            : (int) $state['candidate_attachments'];
        if (empty($state['generation']) || 'idle' === $state['status']) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_not_scanned',
                __('Run the complete replacement readiness inventory before starting Media Library replacement.', 'ultracache')
            );
        } elseif ('failed' === $state['status']) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_failed',
                $state['last_error'] ?: __('The replacement readiness inventory failed.', 'ultracache')
            );
        } elseif (!$inventory_complete) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_incomplete',
                __('The replacement readiness inventory is not complete.', 'ultracache')
            );
        }

        if ($state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'output_policy_changed',
                __('The image output policy changed after the readiness inventory. Run the inventory again.', 'ultracache')
            );
        }

        if ('' !== $requested_generation && $requested_generation !== $state['generation']) {
            $this->add_media_replacement_start_guard_blocker(
                $blockers,
                'readiness_generation_mismatch',
                __('The requested readiness generation is no longer current. Run the readiness inventory again.', 'ultracache')
            );
        }

        if ($inventory_complete) {
            $candidate_set_changed = !empty($state['library_changed'])
                || $state['candidate_attachments'] !== $state['candidate_count_at_finish']
                || $state['candidate_attachments'] !== $current_candidate_count;
            if ($candidate_set_changed) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'candidate_set_changed',
                    __('The Media Library candidate set changed after the readiness inventory. Run the inventory again.', 'ultracache'),
                    abs($current_candidate_count - (int) $state['candidate_attachments'])
                );
            }

            if ($state['candidate_attachments'] <= 0) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'no_candidate_attachments',
                    __('No JPG/PNG Media Library attachments are available for replacement.', 'ultracache')
                );
            }

            if ($state['scanned_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'candidate_scan_incomplete',
                    __('Not every candidate attachment was scanned by the readiness inventory.', 'ultracache'),
                    max(0, (int) $state['candidate_attachments'] - (int) $state['scanned_attachments'])
                );
            }

            if ($state['eligible_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'ineligible_attachments',
                    __('One or more candidate attachments do not have a complete eligible JPG/PNG source set.', 'ultracache'),
                    max(0, (int) $state['candidate_attachments'] - (int) $state['eligible_attachments'])
                );
            }

            if ($state['required_variants'] <= 0) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'no_required_variants',
                    __('The readiness inventory did not find any source variants to replace.', 'ultracache')
                );
            }

            $variant_blockers = array(
                'missing_variants'     => array('missing_target_variants', __('Required target-format files are missing.', 'ultracache')),
                'stale_variants'       => array('stale_target_variants', __('Required target-format files are older than their source files.', 'ultracache')),
                'failed_variants'      => array('failed_target_variants', __('Required target-format files failed validation or conversion.', 'ultracache')),
                'pending_variants'     => array('pending_target_variants', __('Required target-format files are still pending or processing.', 'ultracache')),
                'unsupported_variants' => array('unsupported_source_variants', __('One or more source variants are unsupported for replacement.', 'ultracache')),
            );
            foreach ($variant_blockers as $state_key => $definition) {
                $count = max(0, (int) $state[$state_key]);
                if ($count > 0) {
                    $this->add_media_replacement_start_guard_blocker($blockers, $definition[0], $definition[1], $count);
                }
            }

            if ($state['ready_variants'] !== $state['required_variants']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'variant_count_mismatch',
                    __('Not every required target-format variant is ready.', 'ultracache'),
                    max(0, (int) $state['required_variants'] - (int) $state['ready_variants'])
                );
            }

            if ($state['blocked_attachments'] > 0 || $state['ready_attachments'] !== $state['candidate_attachments']) {
                $this->add_media_replacement_start_guard_blocker(
                    $blockers,
                    'blocked_attachments',
                    __('One or more Media Library attachments are not fully ready for replacement.', 'ultracache'),
                    max((int) $state['blocked_attachments'], max(0, (int) $state['candidate_attachments'] - (int) $state['ready_attachments']))
                );
            }

        }

        $allowed = empty($blockers);
        $message = $allowed
            ? sprintf(
                /* translators: 1: ready target variants, 2: target image format. */
                __('Replacement start guard passed. All %1$d required %2$s variants are valid and current.', 'ultracache'),
                (int) $state['ready_variants'],
                strtoupper($target_format)
            )
            : __('Media Library replacement cannot start until every required target-format file is ready.', 'ultracache');

        return array(
            'success'                     => true,
            'allowed'                     => $allowed,
            'blocked'                     => !$allowed,
            'status'                      => $allowed ? 'replacement_start_ready' : 'replacement_start_blocked',
            'message'                     => $message,
            'generation'                  => $state['generation'],
            'requestedGeneration'         => $requested_generation,
            'targetFormat'                => $target_format,
            'fallbackFormat'              => $fallback_format,
            'currentCandidateAttachments' => $current_candidate_count,
            'blockers'                    => $blockers,
            'readiness'                   => $readiness,
        );
    }

    public function get_media_library_replacement_readiness_status()
    {
        $state = $this->get_media_replacement_readiness_state();
        $has_more = in_array($state['status'], array('scanning', 'paused'), true) && !empty($this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], 1));
        return $this->build_media_replacement_readiness_response($state, $has_more, 0);
    }

    public function scan_media_library_replacement_readiness_inventory($args = array())
    {
        $args = is_array($args) ? $args : array();
        list($target_format, $fallback_format) = $this->get_media_replacement_current_output_policy();
        $limit = isset($args['limit']) && absint($args['limit']) > 0 ? absint($args['limit']) : 50;
        $limit = max(1, min(250, $limit));
        $time_budget = isset($args['time_budget']) && (float) $args['time_budget'] > 0 ? (float) $args['time_budget'] : 15.0;
        $time_budget = max(1.0, min(30.0, $time_budget));
        $lock_token = $this->acquire_media_replacement_readiness_lock($target_format, (int) ceil($time_budget) + 15);
        if ('' === $lock_token) {
            $locked = $this->get_media_library_replacement_readiness_status();
            $locked['success'] = false;
            $locked['blocked'] = true;
            $locked['status'] = 'readiness_locked';
            $locked['message'] = __('Another Media Library replacement readiness chunk is already running.', 'ultracache');
            return $locked;
        }

        $state = $this->get_media_replacement_readiness_state();
        $batch_scanned = 0;
        try {
            $reset = !empty($args['reset']);
            $policy_changed = $state['target_format'] !== $target_format || $state['fallback_format'] !== $fallback_format;
            if ($reset || $policy_changed || 'idle' === $state['status'] || empty($state['generation'])) {
                $state = $this->reset_media_replacement_readiness_state($target_format, $fallback_format);
            } elseif ('completed' === $state['status']) {
                return $this->build_media_replacement_readiness_response($state, false, 0);
            } else {
                $state['status'] = 'scanning';
                $state['last_error'] = '';
                $state['updated_at'] = current_time('mysql', true);
                $state = $this->update_media_replacement_readiness_state($state);
            }

            $deadline = microtime(true) + $time_budget;
            $attachment_ids = $this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], $limit);
            $queue_statuses = $this->get_media_replacement_readiness_queue_status_map($attachment_ids, $target_format);
            foreach ($attachment_ids as $attachment_id) {
                if ($batch_scanned > 0 && microtime(true) >= $deadline) {
                    break;
                }

                $variants = $this->get_media_replacement_required_source_variants($attachment_id);
                $attachment_ready = true;
                $attachment_eligible = false;
                $queue_status = isset($queue_statuses[$attachment_id]) ? (string) $queue_statuses[$attachment_id] : '';
                foreach ($variants as $variant) {
                    $result = $this->inspect_media_replacement_readiness_variant($attachment_id, $variant, $target_format, $queue_status);
                    $status = (string) ($result['status'] ?? 'failed');
                    $state['required_variants']++;
                    if ('ready' === $status) {
                        $state['ready_variants']++;
                        $attachment_eligible = true;
                    } else {
                        $attachment_ready = false;
                        if ('missing' === $status) {
                            $state['missing_variants']++;
                            $attachment_eligible = true;
                        } elseif ('stale' === $status) {
                            $state['stale_variants']++;
                            $attachment_eligible = true;
                        } elseif ('pending' === $status) {
                            $state['pending_variants']++;
                            $attachment_eligible = true;
                        } elseif ('unsupported' === $status) {
                            $state['unsupported_variants']++;
                        } else {
                            $state['failed_variants']++;
                            if (!empty($result['sourceEligible'])) {
                                $attachment_eligible = true;
                            }
                        }
                        $this->add_media_replacement_readiness_blocker_sample($state, $result);
                    }
                }

                $state['scanned_attachments']++;
                if ($attachment_eligible) {
                    $state['eligible_attachments']++;
                }
                if ($attachment_ready && !empty($variants)) {
                    $state['ready_attachments']++;
                } else {
                    $state['blocked_attachments']++;
                }
                $state['cursor_attachment_id'] = max((int) $state['cursor_attachment_id'], absint($attachment_id));
                $batch_scanned++;
            }

            $has_more = !empty($this->get_media_replacement_candidate_attachment_ids_batch($state['cursor_attachment_id'], 1));
            $state['status'] = $has_more ? 'scanning' : 'completed';
            $state['updated_at'] = current_time('mysql', true);
            if (!$has_more) {
                $state['candidate_count_at_finish'] = $this->get_media_replacement_candidate_attachment_count();
                $state['library_changed'] = $state['candidate_count_at_finish'] !== $state['candidate_attachments'];
                $state['completed_at'] = current_time('mysql', true);
            }
            $state = $this->update_media_replacement_readiness_state($state);
            return $this->build_media_replacement_readiness_response($state, $has_more, $batch_scanned);
        } catch (Throwable $error) {
            $state['status'] = 'failed';
            $state['last_error'] = sanitize_text_field((string) $error->getMessage());
            $state['updated_at'] = current_time('mysql', true);
            $state = $this->update_media_replacement_readiness_state($state);
            return $this->build_media_replacement_readiness_response($state, true, $batch_scanned);
        } finally {
            $this->release_media_replacement_readiness_lock($lock_token);
        }
    }


}
