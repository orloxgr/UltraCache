<?php
/**
 * UltraCache Media Library replacement blocker discovery and decisions.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Replacement_Blockers_Trait
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache owns and validates its replacement registry table.

    private function get_media_replacement_scan_queue_blocker($attachment_id, $relative_path, $target_format, $default_code, $default_detail)
    {
        $diagnostics = $this->get_media_replacement_readiness_queue_status_map(array(absint($attachment_id)), $target_format);
        $context = isset($diagnostics[$attachment_id]) && is_array($diagnostics[$attachment_id]) ? $diagnostics[$attachment_id] : array();
        $unit = $this->get_media_replacement_readiness_unit_diagnostic($context, $relative_path);
        $unit_status = sanitize_key((string) ($unit['status'] ?? ''));
        $code = sanitize_key((string) ($unit['skippedReason'] ?? ''));
        $detail = sanitize_text_field((string) ($unit['skipDetail'] ?? ''));
        if ('' === $code) {
            $code = sanitize_key((string) ($unit['failureCode'] ?? ''));
            $detail = sanitize_text_field((string) ($unit['failureDetail'] ?? $detail));
        }
        if ('' === $code && in_array($unit_status, array('pending', 'processing'), true)) {
            $code = 'conversion_pending';
            $detail = __('The target replacement is still pending in the media conversion queue.', 'ultracache');
        }
        if ('' === $code) {
            $code = sanitize_key((string) $default_code);
        }
        if ('' === $detail) {
            $detail = sanitize_text_field((string) $default_detail);
        }
        return array('code' => $code, 'detail' => $detail, 'unitStatus' => $unit_status);
    }

    private function get_media_replacement_scan_destination_plan($old_relative, $target_format, $generated_file)
    {
        $old_relative = ltrim(str_replace('\\', '/', (string) $old_relative), '/');
        $target_format = sanitize_key((string) $target_format);
        $generated_file = wp_normalize_path((string) $generated_file);
        $destination = $this->build_media_replacement_planned_destination($old_relative, $target_format);
        $relative = ltrim(str_replace('\\', '/', (string) ($destination['relativePath'] ?? '')), '/');
        $target_file = $this->build_media_replacement_destination_file_path($relative);
        $url = esc_url_raw((string) ($destination['url'] ?? ''));
        $result = array(
            'new_relative_path' => $relative,
            'new_url' => $url,
            'new_file_path' => $target_file,
            'destination_existed' => 0,
            'destination_previous_size' => 0,
            'destination_previous_hash' => '',
            'blocker_code' => '',
            'blocker_detail' => '',
        );
        if ('' === $relative || '' === $target_file || '' === $url || '' === $generated_file) {
            return $result;
        }
        $filesystem = $this->optimized_storage_filesystem();
        if (!$filesystem || !method_exists($filesystem, 'exists') || !$filesystem->exists($target_file)) {
            return $result;
        }
        $result['destination_existed'] = 1;
        if ($this->optimized_storage_path_exists($target_file, true)
            && $this->is_valid_generated_media_file($target_file, $target_format, 'media_replacement_prepare_existing_destination_validate')
            && $this->media_replacement_files_are_identical($generated_file, $target_file)
        ) {
            return $result;
        }
        $fingerprint = $this->get_media_replacement_file_fingerprint($target_file);
        $result['destination_previous_size'] = max(0, (int) ($fingerprint['size'] ?? 0));
        $result['destination_previous_hash'] = strtolower((string) ($fingerprint['hash'] ?? ''));
        $result['blocker_code'] = 'existing_destination_collision';
        $result['blocker_detail'] = __('A different AVIF/WebP file already exists at the planned Media Library destination.', 'ultracache');
        return $result;
    }

    private function get_media_replacement_blocker_actions($blocker_code)
    {
        $blocker_code = sanitize_key((string) $blocker_code);
        if ('existing_destination_collision' === $blocker_code) {
            return array('overwrite_with_backup', 'exclude_attachment');
        }

        return array('exclude_attachment');
    }

    private function get_media_replacement_blocker_title($blocker_code)
    {
        $titles = array(
            'existing_destination_collision' => __('Existing destination files', 'ultracache'),
            'color_profile_unreadable'        => __('Unreadable embedded color profiles', 'ultracache'),
            'source_missing'                  => __('Missing or unreadable source files', 'ultracache'),
            'source_outside_uploads'          => __('Sources outside the uploads directory', 'ultracache'),
            'original_fingerprint_unavailable'=> __('Original file fingerprints unavailable', 'ultracache'),
            'generated_target_missing'        => __('Missing generated replacement files', 'ultracache'),
            'conversion_pending'              => __('Replacement conversion still pending', 'ultracache'),
            'target_invalid'                  => __('Invalid generated replacement files', 'ultracache'),
        );
        $blocker_code = sanitize_key((string) $blocker_code);
        return isset($titles[$blocker_code]) ? $titles[$blocker_code] : ucwords(str_replace('_', ' ', $blocker_code));
    }

    private function get_media_replacement_blocker_summary()
    {
        global $wpdb;

        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array('groups' => array(), 'groupCount' => 0, 'unresolvedGroups' => 0, 'affectedAttachments' => 0, 'affectedVariants' => 0);
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT blocker_code,
                        COUNT(*) AS variant_count,
                        COUNT(DISTINCT attachment_id) AS attachment_count,
                        SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS unresolved_count,
                        COUNT(DISTINCT NULLIF(decision, '')) AS decision_count,
                        MAX(decision) AS decision,
                        MAX(blocker_detail) AS blocker_detail
                 FROM %i
                 WHERE blocker_code <> ''
                 GROUP BY blocker_code
                 ORDER BY blocker_code ASC",
                $table
            ),
            ARRAY_A
        );

        $workflow = $this->get_media_replacement_workflow_state();
        $group_decisions = is_array($workflow['blocker_group_decisions'] ?? null)
            ? $workflow['blocker_group_decisions']
            : array();
        $groups = array();
        $affected_variants = 0;
        $unresolved_groups = 0;
        foreach ((array) $rows as $row) {
            $code = sanitize_key((string) ($row['blocker_code'] ?? ''));
            if ('' === $code) {
                continue;
            }
            $unresolved = max(0, (int) ($row['unresolved_count'] ?? 0));
            if ($unresolved > 0) {
                $unresolved_groups++;
            }
            $variant_count = max(0, (int) ($row['variant_count'] ?? 0));
            $attachment_count = max(0, (int) ($row['attachment_count'] ?? 0));
            $affected_variants += $variant_count;
            $groups[] = array(
                'code'               => $code,
                'title'              => $this->get_media_replacement_blocker_title($code),
                'detail'             => sanitize_text_field((string) ($row['blocker_detail'] ?? '')),
                'attachmentCount'    => $attachment_count,
                'variantCount'       => $variant_count,
                'unresolvedVariants' => $unresolved,
                'resolved'           => 0 === $unresolved,
                'decision'           => isset($group_decisions[$code])
                    ? sanitize_key((string) $group_decisions[$code])
                    : (1 === (int) ($row['decision_count'] ?? 0) ? sanitize_key((string) ($row['decision'] ?? '')) : ''),
                'actions'            => $this->get_media_replacement_blocker_actions($code),
            );
        }

        $affected_attachments = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(DISTINCT attachment_id) FROM %i WHERE blocker_code <> ''", $table)
        );

        return array(
            'groups'              => $groups,
            'groupCount'          => count($groups),
            'unresolvedGroups'    => $unresolved_groups,
            'affectedAttachments' => max(0, $affected_attachments),
            'affectedVariants'    => $affected_variants,
        );
    }

    public function get_media_library_replacement_blockers($args = array())
    {
        global $wpdb;

        $args = is_array($args) ? $args : array();
        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb) || !$this->media_replacement_items_table_exists() || !$this->media_replacement_has_registry_rows()) {
            return array('success' => false, 'message' => __('Run Prepare Replacement before reviewing blockers.', 'ultracache'));
        }

        $summary = $this->get_media_replacement_blocker_summary();
        $requested_code = sanitize_key((string) ($args['blocker_code'] ?? $args['blockerCode'] ?? ''));
        if ('' === $requested_code && !empty($summary['groups'][0]['code'])) {
            $requested_code = (string) $summary['groups'][0]['code'];
        }
        $limit = max(1, min(200, absint($args['limit'] ?? 100)));
        $offset = max(0, absint($args['offset'] ?? 0));
        $items = array();
        $total = 0;

        if ('' !== $requested_code) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE blocker_code = %s', $table, $requested_code));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, attachment_id, item_scope, size_name, target_format, old_relative_path, generated_file_path, new_relative_path, new_file_path, status, blocker_code, blocker_detail, decision, error_message FROM %i WHERE blocker_code = %s ORDER BY attachment_id ASC, id ASC LIMIT %d OFFSET %d',
                    $table,
                    $requested_code,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $items[] = array(
                    'id'              => absint($row['id'] ?? 0),
                    'attachmentId'    => absint($row['attachment_id'] ?? 0),
                    'scope'           => 'intermediate' === (string) ($row['item_scope'] ?? '') ? 'intermediate' : 'main',
                    'sizeName'        => sanitize_key((string) ($row['size_name'] ?? '')),
                    'targetFormat'    => sanitize_key((string) ($row['target_format'] ?? '')),
                    'source'          => sanitize_text_field((string) ($row['old_relative_path'] ?? '')),
                    'generatedFile'   => wp_normalize_path((string) ($row['generated_file_path'] ?? '')),
                    'destination'     => sanitize_text_field((string) ($row['new_relative_path'] ?? '')),
                    'destinationFile' => wp_normalize_path((string) ($row['new_file_path'] ?? '')),
                    'status'          => sanitize_key((string) ($row['status'] ?? '')),
                    'blockerCode'     => sanitize_key((string) ($row['blocker_code'] ?? '')),
                    'detail'          => sanitize_text_field((string) ($row['blocker_detail'] ?: $row['error_message'] ?? '')),
                    'decision'        => sanitize_key((string) ($row['decision'] ?? '')),
                    'actions'         => $this->get_media_replacement_blocker_actions((string) ($row['blocker_code'] ?? '')),
                );
            }
        }

        return array(
            'success'             => true,
            'message'             => empty($summary['groupCount']) ? __('Prepare found no blocker decisions.', 'ultracache') : __('Prepare blocker decisions loaded.', 'ultracache'),
            'groups'              => $summary['groups'],
            'groupCount'          => $summary['groupCount'],
            'unresolvedGroups'    => $summary['unresolvedGroups'],
            'affectedAttachments' => $summary['affectedAttachments'],
            'affectedVariants'    => $summary['affectedVariants'],
            'decisionsComplete'   => 0 === (int) $summary['unresolvedGroups'],
            'activeBlockerCode'   => $requested_code,
            'items'               => $items,
            'returned'            => count($items),
            'total'               => max(0, $total),
            'limit'               => $limit,
            'offset'              => $offset,
            'hasMore'             => ($offset + count($items)) < $total,
            'previousOffset'      => max(0, $offset - $limit),
            'nextOffset'          => $offset + count($items),
        );
    }

    private function persist_media_replacement_blocker_group_decision($blocker_code, $decision, $user_id, $decided_at)
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        $blocker_code = sanitize_key((string) $blocker_code);
        $decision = sanitize_key((string) $decision);
        if ('' === $table || '' === $blocker_code || '' === $decision || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET decision = %s, decided_by = %d, decided_at = %s, updated_at = %s WHERE blocker_code = %s',
                $table,
                $decision,
                $user_id,
                $decided_at,
                $decided_at,
                $blocker_code
            )
        );
    }

    private function persist_media_replacement_blocker_item_decision($item_id, $decision, $user_id, $decided_at)
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        $item_id = absint($item_id);
        $decision = sanitize_key((string) $decision);
        if ('' === $table || $item_id <= 0 || '' === $decision || !($wpdb instanceof wpdb)) {
            return false;
        }
        return false !== $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET decision = %s, decided_by = %d, decided_at = %s, updated_at = %s WHERE id = %d',
                $table,
                $decision,
                $user_id,
                $decided_at,
                $decided_at,
                $item_id
            )
        );
    }

    private function exclude_media_replacement_attachments(array $attachment_ids, $decided_at)
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if ('' === $table || empty($attachment_ids) || !($wpdb instanceof wpdb)) {
            return 0;
        }
        $updated = 0;
        foreach (array_chunk($attachment_ids, 20) as $chunk) {
            $chunk = array_pad($chunk, 20, 0);
            $result = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %s WHERE attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                    $table,
                    'excluded',
                    $decided_at,
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                )
            );
            if (false === $result) {
                return false;
            }
            $updated += (int) $result;
        }
        return $updated;
    }

    private function defer_media_replacement_blocked_attachments()
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }
        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT attachment_id FROM %i WHERE status = %s AND blocker_code <> %s',
            $table,
            'blocked',
            ''
        ));
        if (!is_array($attachment_ids)) {
            return false;
        }
        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if (empty($attachment_ids)) {
            return 0;
        }
        $updated = 0;
        foreach (array_chunk($attachment_ids, 20) as $chunk) {
            $chunk = array_pad($chunk, 20, 0);
            $result = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %s WHERE status = %s AND attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                    $table,
                    'blocked_dependency',
                    current_time('mysql', true),
                    'matched',
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                )
            );
            if (false === $result) {
                return false;
            }
            $updated += (int) $result;
        }
        return $updated;
    }

    private function get_media_replacement_overwrite_attachment_ids()
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return array();
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT selected.attachment_id
             FROM %i selected
             WHERE selected.decision = %s
               AND NOT EXISTS (
                   SELECT 1 FROM %i excluded
                   WHERE excluded.attachment_id = selected.attachment_id
                     AND excluded.decision = %s
               )",
            $table,
            'overwrite_with_backup',
            $table,
            'exclude_attachment'
        ));
        return is_array($rows) ? array_values(array_filter(array_unique(array_map('absint', $rows)))) : array();
    }

    private function activate_media_replacement_overwrite_attachments(array $attachment_ids, $updated_at)
    {
        global $wpdb;
        $table = $this->get_media_replacement_items_table_name();
        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if ('' === $table || empty($attachment_ids) || !($wpdb instanceof wpdb)) {
            return 0;
        }
        $updated = 0;
        foreach (array_chunk($attachment_ids, 20) as $chunk) {
            $chunk = array_pad($chunk, 20, 0);
            $result = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %s WHERE status IN (%s, %s) AND attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                    $table,
                    'matched',
                    $updated_at,
                    'blocked',
                    'blocked_dependency',
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                )
            );
            if (false === $result) {
                return false;
            }
            $updated += (int) $result;
        }
        return $updated;
    }

    private function reset_media_replacement_reference_matches_for_attachments(array $attachment_ids)
    {
        global $wpdb;
        $items_table = $this->get_media_replacement_items_table_name();
        $refs_table = $this->get_media_replacement_refs_table_name();
        $index_table = $this->get_media_replacement_ref_index_table_name();
        $attachment_ids = array_values(array_filter(array_unique(array_map('absint', $attachment_ids))));
        if ('' === $items_table || '' === $refs_table || '' === $index_table || empty($attachment_ids) || !($wpdb instanceof wpdb)) {
            return true;
        }

        $item_ids = array();
        $path_hashes = array();
        foreach (array_chunk($attachment_ids, 20) as $chunk) {
            $chunk = array_pad($chunk, 20, 0);
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, old_path_hash FROM %i WHERE attachment_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                    $items_table,
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                ),
                ARRAY_A
            );
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                $item_id = absint($row['id'] ?? 0);
                $hash = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($row['old_path_hash'] ?? '')));
                if ($item_id > 0) {
                    $item_ids[$item_id] = $item_id;
                }
                if (32 === strlen($hash)) {
                    $path_hashes[$hash] = $hash;
                }
            }
        }

        foreach (array_chunk(array_values($item_ids), 20) as $chunk) {
            if (empty($chunk)) {
                continue;
            }
            $chunk = array_pad($chunk, 20, 0);
            if (false === $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE item_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                    $refs_table,
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                )
            )) {
                return false;
            }
        }

        foreach (array_chunk(array_values($path_hashes), 20) as $chunk) {
            if (empty($chunk)) {
                continue;
            }
            $chunk = array_pad($chunk, 20, '!');
            if (false === $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET matched_item_id = 0, status = %s, error_message = NULL, updated_at = %s WHERE status = %s AND url_path_hash IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                    $index_table,
                    'indexed',
                    current_time('mysql', true),
                    'unmatched',
                    $chunk[0],
                    $chunk[1],
                    $chunk[2],
                    $chunk[3],
                    $chunk[4],
                    $chunk[5],
                    $chunk[6],
                    $chunk[7],
                    $chunk[8],
                    $chunk[9],
                    $chunk[10],
                    $chunk[11],
                    $chunk[12],
                    $chunk[13],
                    $chunk[14],
                    $chunk[15],
                    $chunk[16],
                    $chunk[17],
                    $chunk[18],
                    $chunk[19]
                )
            )) {
                return false;
            }
        }
        return true;
    }

    public function save_media_library_replacement_blocker_decisions($args = array())
    {
        global $wpdb;

        $args = is_array($args) ? $args : array();
        $decisions = isset($args['decisions']) && is_array($args['decisions']) ? $args['decisions'] : array();
        $item_decisions = isset($args['item_decisions']) && is_array($args['item_decisions']) ? $args['item_decisions'] : (isset($args['itemDecisions']) && is_array($args['itemDecisions']) ? $args['itemDecisions'] : array());
        $state = $this->get_media_replacement_workflow_state();
        $table = $this->get_media_replacement_items_table_name();
        if ('' === $table || !($wpdb instanceof wpdb) || !$this->media_replacement_has_registry_rows()) {
            return array('success' => false, 'blocked' => true, 'message' => __('Run Prepare Replacement before saving blocker decisions.', 'ultracache'));
        }
        if (!in_array((string) $state['active_step'], array('decisions_required', 'prepare_blocked'), true) && (int) $state['unresolved_blocker_groups'] <= 0) {
            return array('success' => false, 'blocked' => true, 'message' => __('The singleton replacement workflow is not awaiting blocker decisions.', 'ultracache'));
        }

        $current_summary = $this->get_media_replacement_blocker_summary();
        $current_groups = array();
        foreach ((array) ($current_summary['groups'] ?? array()) as $group) {
            $code = sanitize_key((string) ($group['code'] ?? ''));
            if ('' !== $code) {
                $current_groups[$code] = $group;
            }
        }
        if (empty($current_groups)) {
            return array('success' => false, 'blocked' => true, 'message' => __('The prepared replacement plan no longer contains blocker groups.', 'ultracache'));
        }

        $decision_map = array();
        foreach ($decisions as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $code = sanitize_key((string) ($entry['blockerCode'] ?? $entry['code'] ?? ''));
            $decision = sanitize_key((string) ($entry['decision'] ?? ''));
            if ('' === $code || !isset($current_groups[$code]) || !in_array($decision, $this->get_media_replacement_blocker_actions($code), true)) {
                return array('success' => false, 'blocked' => true, 'message' => __('One or more group decisions are invalid or no longer current.', 'ultracache'));
            }
            $decision_map[$code] = $decision;
        }
        foreach ($current_groups as $code => $group) {
            if (!isset($decision_map[$code])) {
                return array('success' => false, 'blocked' => true, 'message' => __('Choose a group decision for every blocker group before saving.', 'ultracache'));
            }
        }

        $item_decision_map = array();
        foreach ($item_decisions as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $item_id = absint($entry['itemId'] ?? $entry['id'] ?? 0);
            $decision = sanitize_key((string) ($entry['decision'] ?? ''));
            if ($item_id <= 0 || '' === $decision) {
                return array('success' => false, 'blocked' => true, 'message' => __('One or more individual file decisions are invalid.', 'ultracache'));
            }
            if (isset($item_decision_map[$item_id]) && $item_decision_map[$item_id] !== $decision) {
                return array('success' => false, 'blocked' => true, 'message' => __('The same blocker item was submitted with conflicting decisions.', 'ultracache'));
            }
            $item_decision_map[$item_id] = $decision;
        }

        if (!empty($item_decision_map)) {
            $item_rows = array();
            foreach (array_chunk(array_keys($item_decision_map), 20) as $chunk) {
                $chunk = array_pad($chunk, 20, 0);
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT id, blocker_code FROM %i WHERE id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)',
                        $table,
                        $chunk[0],
                        $chunk[1],
                        $chunk[2],
                        $chunk[3],
                        $chunk[4],
                        $chunk[5],
                        $chunk[6],
                        $chunk[7],
                        $chunk[8],
                        $chunk[9],
                        $chunk[10],
                        $chunk[11],
                        $chunk[12],
                        $chunk[13],
                        $chunk[14],
                        $chunk[15],
                        $chunk[16],
                        $chunk[17],
                        $chunk[18],
                        $chunk[19]
                    ),
                    ARRAY_A
                );
                foreach ((array) $rows as $row) {
                    $item_rows[absint($row['id'] ?? 0)] = sanitize_key((string) ($row['blocker_code'] ?? ''));
                }
            }
            if (count($item_rows) !== count($item_decision_map)) {
                return array('success' => false, 'blocked' => true, 'message' => __('One or more individual blocker items are no longer part of the current prepared plan.', 'ultracache'));
            }
            foreach ($item_decision_map as $item_id => $decision) {
                $code = (string) ($item_rows[$item_id] ?? '');
                if ('' === $code || !isset($current_groups[$code]) || !in_array($decision, $this->get_media_replacement_blocker_actions($code), true)) {
                    return array('success' => false, 'blocked' => true, 'message' => __('One or more individual file decisions are not valid for their blocker group.', 'ultracache'));
                }
            }
        }

        $user_id = get_current_user_id();
        $now = current_time('mysql', true);
        $overwrite_attachment_ids = array();
        $activated_rows = 0;
        $excluded_attachments = 0;
        $active_rows = 0;
        $summary = array();
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($decision_map as $code => $decision) {
                if (!$this->persist_media_replacement_blocker_group_decision($code, $decision, $user_id, $now)) {
                    throw new RuntimeException('decision_persist_failed');
                }
            }
            foreach ($item_decision_map as $item_id => $decision) {
                if (!$this->persist_media_replacement_blocker_item_decision($item_id, $decision, $user_id, $now)) {
                    throw new RuntimeException('item_decision_persist_failed');
                }
            }

            $attachment_ids = $wpdb->get_col($wpdb->prepare(
                'SELECT DISTINCT attachment_id FROM %i WHERE blocker_code <> %s AND decision = %s',
                $table,
                '',
                'exclude_attachment'
            ));
            if (!is_array($attachment_ids) || false === $this->exclude_media_replacement_attachments($attachment_ids, $now)) {
                throw new RuntimeException('attachment_exclusion_failed');
            }

            $overwrite_attachment_ids = $this->get_media_replacement_overwrite_attachment_ids();
            $activated_rows = $this->activate_media_replacement_overwrite_attachments($overwrite_attachment_ids, $now);
            if (false === $activated_rows) {
                throw new RuntimeException('collision_decision_failed');
            }

            $summary = $this->get_media_replacement_blocker_summary();
            if ((int) $summary['unresolvedGroups'] > 0) {
                throw new RuntimeException('unresolved_groups_remain');
            }

            $excluded_attachments = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(DISTINCT attachment_id) FROM %i WHERE status = %s',
                $table,
                'excluded'
            ));
            $active_rows = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status <> %s',
                $table,
                'excluded'
            ));
            if ('' !== (string) $wpdb->last_error) {
                throw new RuntimeException('decision_summary_failed');
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            $message = __('Blocker decisions could not be saved atomically. Reload the decision plan and try again.', 'ultracache');
            if ('unresolved_groups_remain' === $exception->getMessage()) {
                $message = __('Choose a decision for every blocker group before continuing Prepare.', 'ultracache');
            }
            return array('success' => false, 'blocked' => true, 'message' => $message);
        }

        if (!empty($overwrite_attachment_ids)) {
            if (!$this->reset_media_replacement_reference_matches_for_attachments($overwrite_attachment_ids)
                || !$this->reset_media_replacement_theme_css_refs()
            ) {
                $state['status'] = 'failed';
                $state['run_status'] = 'failed';
                $state['active_step'] = 'prepare_failed';
                $state['last_error'] = __('Blocker decisions were saved, but the affected database or Theme CSS plans could not be reset. Use Restart Replacement Plan.', 'ultracache');
                $state['updated_at'] = $now;
                $this->update_media_replacement_workflow_state($state);
                return array('success' => false, 'blocked' => true, 'message' => $state['last_error']);
            }
        }

        $state['blocker_groups'] = max(0, (int) ($summary['groupCount'] ?? 0));
        $state['blocker_items'] = max(0, (int) ($summary['affectedVariants'] ?? 0));
        $state['unresolved_blocker_groups'] = 0;
        $state['excluded_attachments'] = max(0, $excluded_attachments);
        $state['blocker_group_decisions'] = $decision_map;
        $state['blocker_item_overrides'] = count($item_decision_map);
        $state['decisions_completed_at'] = $now;
        $state['workflow_updated_at'] = $now;
        $state['updated_at'] = $now;
        $state['heartbeat_at'] = $now;
        $state['last_error'] = '';

        if ($active_rows <= 0) {
            $state['status'] = 'completed';
            $state['run_status'] = 'completed';
            $state['active_step'] = 'delete_complete';
            $state['workflow_stage'] = 'complete';
            $state['workflow_message'] = __('All prepared blocker groups were resolved by keeping their affected attachments as originals. No replacement work remains.', 'ultracache');
            $state['prepare_completed_at'] = $now;
            $state['pre_do_guard_completed_at'] = $now;
            $state['pre_do_plan_fingerprint'] = hash('sha256', wp_json_encode(array(
                'result' => 'all_attachments_excluded',
                'excluded_attachments' => max(0, $excluded_attachments),
                'prepared_at' => (string) $state['prepare_started_at'],
            )));
            $state['completed_at'] = $now;
            $this->update_media_replacement_workflow_state($state);
            return array_merge(array(
                'success' => true,
                'message' => __('All affected attachments will remain original. No replacement work remains for this prepared plan.', 'ultracache'),
                'resumePrepare' => false,
                'applyStarted' => false,
                'noReplacementWork' => true,
                'individualOverrides' => count($item_decision_map),
            ), $summary);
        }

        $resume_step = !empty($overwrite_attachment_ids) ? 'copy' : 'pre_do_validate';
        $state['status'] = 'copy' === $resume_step ? 'copying' : 'validating_pre_do';
        $state['run_status'] = 'paused';
        $state['active_step'] = $resume_step;
        $state['workflow_stage'] = 'prepare';
        if (!empty($overwrite_attachment_ids)) {
            $state['confirmation_tokens'] = array();
        }
        $state['pre_do_validation_cursor_item_id'] = 0;
        $state['pre_do_validated_items'] = 0;
        $state['pre_do_validation_failed'] = 0;
        $state['pre_do_guard_completed_at'] = '';
        $state['pre_do_plan_fingerprint'] = '';
        $state['prepare_completed_at'] = '';
        $state['completed_at'] = '';
        $state['workflow_message'] = !empty($overwrite_attachment_ids)
            ? __('Blocker decisions are saved. Prepare is finalizing the affected file, database, and Theme CSS plans; Apply remains a separate user action.', 'ultracache')
            : __('Blocker decisions are saved. Prepare is running the final pre-Do guard; Apply remains a separate user action.', 'ultracache');
        $this->update_media_replacement_workflow_state($state);

        return array_merge(array(
            'success' => true,
            'message' => __('All blocker decisions were saved. Prepare will finish, but Apply will not start automatically.', 'ultracache'),
            'resumePrepare' => true,
            'resumeStep' => $resume_step,
            'activatedAttachments' => count($overwrite_attachment_ids),
            'activatedRows' => max(0, (int) $activated_rows),
            'applyStarted' => false,
            'noReplacementWork' => false,
            'individualOverrides' => count($item_decision_map),
        ), $summary);
    }

}
