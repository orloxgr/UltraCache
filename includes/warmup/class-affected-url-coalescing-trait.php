<?php
/**
 * Request/import burst coalescing for affected-page invalidation and warm-up.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Affected_URL_Coalescing_Trait
{
    /** @var array<string,mixed> */
    private $affected_url_request_batch = array();

    /** @var string */
    private $affected_url_batch_lock_token = '';

    private function get_affected_url_batch_option_key()
    {
        return 'ultracache_affected_url_batch_v1';
    }

    private function get_affected_url_batch_cron_hook()
    {
        return 'ultracache_flush_affected_url_batch';
    }

    /** Normalize persisted object-language metadata only for translated-object providers. */
    private function normalize_affected_url_object_language($language_code)
    {
        $provider = function_exists('ultracache_multilingual_get_provider')
            ? ultracache_multilingual_get_provider()
            : 'none';
        if ('wpml' !== $provider || !function_exists('ultracache_wpml_normalize_language_code')) {
            return '';
        }

        return ultracache_wpml_normalize_language_code($language_code);
    }

    private function get_empty_affected_url_batch()
    {
        return array(
            'posts' => array(),
            'terms' => array(),
            'reasons' => array(),
            'activeImports' => array(),
            'firstSeenAt' => 0,
            'updatedAt' => 0,
        );
    }

    private function normalize_affected_url_batch($batch)
    {
        $normalized = $this->get_empty_affected_url_batch();
        if (!is_array($batch)) {
            return $normalized;
        }

        foreach ((array) ($batch['posts'] ?? array()) as $post_id => $entry) {
            $post_id = absint($post_id);
            if ($post_id < 1) {
                continue;
            }
            $entry = is_array($entry) ? $entry : array();
            $language = $this->normalize_affected_url_object_language($entry['language'] ?? '');
            $normalized['posts'][(string) $post_id] = array(
                'seenAt' => max(0, (int) ($entry['seenAt'] ?? 0)),
                'warm' => !empty($entry['warm']),
                'language' => $language,
            );
        }

        foreach ((array) ($batch['terms'] ?? array()) as $key => $entry) {
            $entry = is_array($entry) ? $entry : array();
            $taxonomy = sanitize_key((string) ($entry['taxonomy'] ?? ''));
            $term_id = absint($entry['termId'] ?? 0);
            if ('' === $taxonomy || $term_id < 1) {
                continue;
            }
            $term_key = $taxonomy . ':' . $term_id;
            $language = $this->normalize_affected_url_object_language($entry['language'] ?? '');
            $normalized['terms'][$term_key] = array(
                'taxonomy' => $taxonomy,
                'termId' => $term_id,
                'seenAt' => max(0, (int) ($entry['seenAt'] ?? 0)),
                'warm' => !empty($entry['warm']),
                'language' => $language,
            );
        }

        foreach ((array) ($batch['reasons'] ?? array()) as $reason => $count) {
            $reason = sanitize_key((string) $reason);
            $count = max(0, (int) $count);
            if ('' !== $reason && $count > 0) {
                $normalized['reasons'][$reason] = $count;
            }
        }

        foreach ((array) ($batch['activeImports'] ?? array()) as $import_id => $entry) {
            $import_id = absint($import_id);
            if ($import_id < 1) {
                continue;
            }
            $entry = is_array($entry) ? $entry : array();
            $normalized['activeImports'][(string) $import_id] = array(
                'startedAt' => max(0, (int) ($entry['startedAt'] ?? 0)),
                'updatedAt' => max(0, (int) ($entry['updatedAt'] ?? 0)),
            );
        }

        $normalized['firstSeenAt'] = max(0, (int) ($batch['firstSeenAt'] ?? 0));
        $normalized['updatedAt'] = max(0, (int) ($batch['updatedAt'] ?? 0));

        return $normalized;
    }

    private function merge_affected_url_batches(array $base, array $incoming)
    {
        $base = $this->normalize_affected_url_batch($base);
        $incoming = $this->normalize_affected_url_batch($incoming);

        foreach ($incoming['posts'] as $post_id => $entry) {
            $existing = isset($base['posts'][$post_id]) && is_array($base['posts'][$post_id])
                ? $base['posts'][$post_id]
                : array();
            $base['posts'][$post_id] = array(
                'seenAt' => max((int) ($existing['seenAt'] ?? 0), (int) ($entry['seenAt'] ?? 0)),
                'warm' => !empty($existing['warm']) || !empty($entry['warm']),
                'language' => '' !== (string) ($entry['language'] ?? '')
                    ? (string) $entry['language']
                    : (string) ($existing['language'] ?? ''),
            );
        }

        foreach ($incoming['terms'] as $term_key => $entry) {
            $existing = isset($base['terms'][$term_key]) && is_array($base['terms'][$term_key])
                ? $base['terms'][$term_key]
                : array();
            $base['terms'][$term_key] = array(
                'taxonomy' => (string) $entry['taxonomy'],
                'termId' => (int) $entry['termId'],
                'seenAt' => max((int) ($existing['seenAt'] ?? 0), (int) ($entry['seenAt'] ?? 0)),
                'warm' => !empty($existing['warm']) || !empty($entry['warm']),
                'language' => '' !== (string) ($entry['language'] ?? '')
                    ? (string) $entry['language']
                    : (string) ($existing['language'] ?? ''),
            );
        }

        foreach ($incoming['reasons'] as $reason => $count) {
            $base['reasons'][$reason] = max(0, (int) ($base['reasons'][$reason] ?? 0)) + max(0, (int) $count);
        }

        foreach ($incoming['activeImports'] as $import_id => $entry) {
            $existing = isset($base['activeImports'][$import_id]) && is_array($base['activeImports'][$import_id])
                ? $base['activeImports'][$import_id]
                : array();
            $base['activeImports'][$import_id] = array(
                'startedAt' => max(0, (int) ($existing['startedAt'] ?? $entry['startedAt'] ?? 0)),
                'updatedAt' => max((int) ($existing['updatedAt'] ?? 0), (int) ($entry['updatedAt'] ?? 0)),
            );
        }

        $first_seen = array_filter(array(
            (int) ($base['firstSeenAt'] ?? 0),
            (int) ($incoming['firstSeenAt'] ?? 0),
        ));
        $base['firstSeenAt'] = empty($first_seen) ? 0 : min($first_seen);
        $base['updatedAt'] = max((int) ($base['updatedAt'] ?? 0), (int) ($incoming['updatedAt'] ?? 0));

        return $base;
    }

    private function affected_url_batch_has_changes(array $batch)
    {
        return !empty($batch['posts']) || !empty($batch['terms']);
    }

    private function record_affected_batch_reason(array &$batch, $reason)
    {
        $reason = sanitize_key((string) $reason);
        if ('' === $reason) {
            $reason = 'update';
        }
        $batch['reasons'][$reason] = max(0, (int) ($batch['reasons'][$reason] ?? 0)) + 1;
    }

    private function record_affected_post_change($post_id, $reason, $warm = true, $language_code = '')
    {
        $post_id = absint($post_id);
        if ($post_id < 1) {
            return false;
        }

        $language_code = $this->normalize_affected_url_object_language($language_code);
        if (
            '' === $language_code
            && function_exists('ultracache_multilingual_get_provider')
            && 'wpml' === ultracache_multilingual_get_provider()
            && method_exists($this, 'get_wpml_post_language_code')
        ) {
            $language_code = $this->get_wpml_post_language_code($post_id);
        }

        $now = time();
        $batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        $key = (string) $post_id;
        $existing = isset($batch['posts'][$key]) && is_array($batch['posts'][$key])
            ? $batch['posts'][$key]
            : array();
        $batch['posts'][$key] = array(
            'seenAt' => $now,
            'warm' => !empty($existing['warm']) || (bool) $warm,
            'language' => '' !== $language_code ? $language_code : (string) ($existing['language'] ?? ''),
        );
        $this->record_affected_batch_reason($batch, $reason);
        $batch['firstSeenAt'] = $batch['firstSeenAt'] > 0 ? $batch['firstSeenAt'] : $now;
        $batch['updatedAt'] = $now;
        $this->affected_url_request_batch = $batch;

        return true;
    }

    private function record_affected_term_change($term_id, $taxonomy, $reason, $warm = true, $language_code = '')
    {
        $term_id = absint($term_id);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($term_id < 1 || '' === $taxonomy) {
            return false;
        }

        $language_code = $this->normalize_affected_url_object_language($language_code);
        if (
            '' === $language_code
            && function_exists('ultracache_multilingual_get_provider')
            && 'wpml' === ultracache_multilingual_get_provider()
            && method_exists($this, 'get_wpml_term_language_code')
        ) {
            $language_code = $this->get_wpml_term_language_code($term_id, $taxonomy);
        }

        $now = time();
        $batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        $key = $taxonomy . ':' . $term_id;
        $existing = isset($batch['terms'][$key]) && is_array($batch['terms'][$key])
            ? $batch['terms'][$key]
            : array();
        $batch['terms'][$key] = array(
            'taxonomy' => $taxonomy,
            'termId' => $term_id,
            'seenAt' => $now,
            'warm' => !empty($existing['warm']) || (bool) $warm,
            'language' => '' !== $language_code ? $language_code : (string) ($existing['language'] ?? ''),
        );
        $this->record_affected_batch_reason($batch, $reason);
        $batch['firstSeenAt'] = $batch['firstSeenAt'] > 0 ? $batch['firstSeenAt'] : $now;
        $batch['updatedAt'] = $now;
        $this->affected_url_request_batch = $batch;

        return true;
    }

    private function record_affected_term_assignment_change($object_id, $taxonomy, array $terms, array $tt_ids, array $old_tt_ids, $warm = true)
    {
        $object_id = absint($object_id);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($object_id < 1 || '' === $taxonomy) {
            return false;
        }

        $this->record_affected_post_change($object_id, 'term-assignment', $warm);
        $term_ids = array();

        foreach (array_merge($tt_ids, $old_tt_ids) as $term_taxonomy_id) {
            $term_taxonomy_id = absint($term_taxonomy_id);
            if ($term_taxonomy_id < 1) {
                continue;
            }
            $term = get_term_by('term_taxonomy_id', $term_taxonomy_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $term_ids[(int) $term->term_id] = (int) $term->term_id;
            }
        }

        foreach ($terms as $term_value) {
            $term_exists = term_exists($term_value, $taxonomy);
            if (is_array($term_exists) && !empty($term_exists['term_id'])) {
                $term_id = absint($term_exists['term_id']);
            } elseif (is_numeric($term_exists)) {
                $term_id = absint($term_exists);
            } else {
                $term_id = 0;
            }
            if ($term_id > 0) {
                $term_ids[$term_id] = $term_id;
            }
        }

        foreach ($term_ids as $term_id) {
            $this->record_affected_term_change($term_id, $taxonomy, 'term-assignment', $warm);
        }

        return true;
    }

    /** Build the semantic LiteSpeed dependency tags invalidated by one post change. */
    private function get_litespeed_semantic_invalidation_tags_for_post($post_id)
    {
        $post_id = absint($post_id);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status) {
            return array();
        }

        $tags = array();
        if (function_exists('ultracache_get_litespeed_post_tag')) {
            $tags[] = ultracache_get_litespeed_post_tag($post_id);
        }
        if (function_exists('ultracache_get_litespeed_post_type_archive_tag')) {
            $tags[] = ultracache_get_litespeed_post_type_archive_tag($post->post_type);
        }
        if (function_exists('ultracache_get_litespeed_front_tag')) {
            $tags[] = ultracache_get_litespeed_front_tag();
        }
        if ('post' === $post->post_type && function_exists('ultracache_get_litespeed_posts_index_tag')) {
            $tags[] = ultracache_get_litespeed_posts_index_tag();
        }
        if (!empty($post->post_author) && function_exists('ultracache_get_litespeed_author_tag')) {
            $tags[] = ultracache_get_litespeed_author_tag($post->post_author);
        }
        if (function_exists('ultracache_get_litespeed_date_archive_tag')) {
            $tags[] = ultracache_get_litespeed_date_archive_tag();
        }
        if ('product' === $post->post_type && function_exists('ultracache_get_litespeed_shop_tag')) {
            $tags[] = ultracache_get_litespeed_shop_tag();
        }

        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        foreach ((array) $taxonomies as $taxonomy => $taxonomy_object) {
            if (!is_object($taxonomy_object) || empty($taxonomy_object->public)) {
                continue;
            }
            $term_ids = wp_get_object_terms($post_id, (string) $taxonomy, array('fields' => 'ids'));
            if (is_wp_error($term_ids)) {
                continue;
            }
            foreach ((array) $term_ids as $term_id) {
                if (function_exists('ultracache_get_litespeed_term_tag')) {
                    $tags[] = ultracache_get_litespeed_term_tag($term_id);
                }
            }
        }

        $front_page_id = absint(get_option('page_on_front', 0));
        if ($front_page_id > 0 && $front_page_id === $post_id && function_exists('ultracache_get_litespeed_front_tag')) {
            $tags[] = ultracache_get_litespeed_front_tag();
        }
        $posts_page_id = absint(get_option('page_for_posts', 0));
        if ($posts_page_id > 0 && $posts_page_id === $post_id && function_exists('ultracache_get_litespeed_posts_index_tag')) {
            $tags[] = ultracache_get_litespeed_posts_index_tag();
        }

        $tags = apply_filters('ultracache_litespeed_post_invalidation_tags', $tags, $post_id, $post);
        return function_exists('ultracache_normalize_litespeed_cache_tags')
            ? ultracache_normalize_litespeed_cache_tags((array) $tags, 64)
            : array_values(array_unique(array_filter(array_map('strval', (array) $tags))));
    }

    /** Build the semantic LiteSpeed dependency tags invalidated by one term change. */
    private function get_litespeed_semantic_invalidation_tags_for_term($term_id, $taxonomy)
    {
        $term_id = absint($term_id);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($term_id < 1 || '' === $taxonomy) {
            return array();
        }
        $tags = array();
        if (function_exists('ultracache_get_litespeed_term_tag')) {
            $tags[] = ultracache_get_litespeed_term_tag($term_id);
        }
        if (function_exists('ultracache_get_litespeed_front_tag')) {
            $tags[] = ultracache_get_litespeed_front_tag();
        }
        $tags = apply_filters('ultracache_litespeed_term_invalidation_tags', $tags, $term_id, $taxonomy);
        return function_exists('ultracache_normalize_litespeed_cache_tags')
            ? ultracache_normalize_litespeed_cache_tags((array) $tags, 64)
            : array_values(array_unique(array_filter(array_map('strval', (array) $tags))));
    }

    /** Queue semantic tags using the same durable LiteSpeed invalidation worker. */
    private function queue_litespeed_semantic_invalidation_tags(array $tags, $reason)
    {
        if (empty($tags) || !class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'enqueue_litespeed_invalidation_tags')) {
            return array('success' => true, 'queued' => false, 'queuedTagCount' => 0);
        }
        $settings = $this->get_settings();
        $stale = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'is_litespeed_stale_invalidation_enabled')
            ? Ultra_Cache_WP::is_litespeed_stale_invalidation_enabled($settings)
            : !empty($settings['staleWhileRevalidateEnabled']);
        return Ultra_Cache_WP::enqueue_litespeed_invalidation_tags($tags, $stale, sanitize_key((string) $reason));
    }

    private function get_affected_url_batch_lock_name()
    {
        return 'ultracache_affected_url_batch';
    }

    private function acquire_affected_url_batch_lock($ttl = 60)
    {
        if (!function_exists('ultracache_acquire_lock')) {
            return false;
        }
        $token = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : hash('sha256', uniqid('ultracache-affected-', true));
        if (!ultracache_acquire_lock(
            $this->get_affected_url_batch_lock_name(),
            $token,
            max(10, (int) $ttl),
            array('component' => 'affected-url-coalescing')
        )) {
            return false;
        }
        $this->affected_url_batch_lock_token = $token;
        return true;
    }

    private function release_affected_url_batch_lock()
    {
        $token = (string) $this->affected_url_batch_lock_token;
        $this->affected_url_batch_lock_token = '';
        if ('' !== $token && function_exists('ultracache_release_lock')) {
            ultracache_release_lock($this->get_affected_url_batch_lock_name(), $token);
        }
    }

    private function read_persisted_affected_url_batch()
    {
        return $this->normalize_affected_url_batch(
            get_option($this->get_affected_url_batch_option_key(), array())
        );
    }

    private function save_persisted_affected_url_batch(array $batch)
    {
        $batch = $this->normalize_affected_url_batch($batch);
        if (!$this->affected_url_batch_has_changes($batch) && empty($batch['activeImports'])) {
            delete_option($this->get_affected_url_batch_option_key());
            return true;
        }

        return (bool) update_option($this->get_affected_url_batch_option_key(), $batch, false);
    }

    private function schedule_affected_url_batch_flush($delay = 30, $replace = true)
    {
        $hook = $this->get_affected_url_batch_cron_hook();
        $delay = max(1, (int) $delay);
        $next = wp_next_scheduled($hook);
        if ($replace) {
            while ($next) {
                wp_unschedule_event($next, $hook);
                $next = wp_next_scheduled($hook);
            }
        }
        if (!$next) {
            wp_schedule_single_event(time() + $delay, $hook);
        }
    }

    private function merge_request_batch_into_persistent_state($schedule_delay = 60)
    {
        $request_batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        if (!$this->affected_url_batch_has_changes($request_batch)) {
            return false;
        }

        if (!$this->acquire_affected_url_batch_lock(30)) {
            $this->schedule_affected_url_batch_flush(5, false);
            return false;
        }

        try {
            $state = $this->read_persisted_affected_url_batch();
            $state = $this->merge_affected_url_batches($state, $request_batch);
            $now = time();
            foreach ($state['activeImports'] as $import_id => $entry) {
                $state['activeImports'][$import_id]['updatedAt'] = $now;
            }
            $state['updatedAt'] = $now;
            $this->save_persisted_affected_url_batch($state);
            $this->affected_url_request_batch = $this->get_empty_affected_url_batch();
        } finally {
            $this->release_affected_url_batch_lock();
        }

        $this->schedule_affected_url_batch_flush($schedule_delay, true);
        return true;
    }

    public function handle_wp_all_import_start($import_id)
    {
        $import_id = absint($import_id);
        if ($import_id < 1) {
            return;
        }

        if (!$this->acquire_affected_url_batch_lock(30)) {
            return;
        }

        try {
            $state = $this->read_persisted_affected_url_batch();
            $now = time();
            $key = (string) $import_id;
            $existing = isset($state['activeImports'][$key]) && is_array($state['activeImports'][$key])
                ? $state['activeImports'][$key]
                : array();
            $state['activeImports'][$key] = array(
                'startedAt' => max(0, (int) ($existing['startedAt'] ?? $now)),
                'updatedAt' => $now,
            );
            $this->record_affected_batch_reason($state, 'wp-all-import');
            $state['updatedAt'] = $now;
            $this->save_persisted_affected_url_batch($state);
        } finally {
            $this->release_affected_url_batch_lock();
        }

        $this->schedule_affected_url_batch_flush(60, true);
    }

    public function handle_wp_all_import_record_saved($post_id = 0, $xml_node = null, $is_update = false)
    {
        unset($post_id, $xml_node, $is_update);
        $batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        $change_count = count($batch['posts']) + count($batch['terms']);
        if ($change_count >= 100) {
            $this->merge_request_batch_into_persistent_state(60);
            return;
        }

        static $last_heartbeat = 0;
        $now = time();
        if (($now - $last_heartbeat) < 15) {
            return;
        }
        $last_heartbeat = $now;

        if (!$this->acquire_affected_url_batch_lock(30)) {
            return;
        }
        try {
            $state = $this->read_persisted_affected_url_batch();
            if (empty($state['activeImports'])) {
                return;
            }
            foreach ($state['activeImports'] as $import_id => $entry) {
                $state['activeImports'][$import_id]['updatedAt'] = $now;
            }
            $state['updatedAt'] = $now;
            $this->save_persisted_affected_url_batch($state);
        } finally {
            $this->release_affected_url_batch_lock();
        }
        $this->schedule_affected_url_batch_flush(60, true);
    }

    public function handle_wp_all_import_complete($import_id, $import = null)
    {
        unset($import);
        $import_id = absint($import_id);
        $request_batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        if (!$this->acquire_affected_url_batch_lock(60)) {
            $this->schedule_affected_url_batch_flush(5, false);
            return;
        }

        try {
            $state = $this->read_persisted_affected_url_batch();
            if ($this->affected_url_batch_has_changes($request_batch)) {
                $state = $this->merge_affected_url_batches($state, $request_batch);
                $this->affected_url_request_batch = $this->get_empty_affected_url_batch();
            }
            if ($import_id > 0) {
                unset($state['activeImports'][(string) $import_id]);
            }
            $state['updatedAt'] = time();
            $this->save_persisted_affected_url_batch($state);
        } finally {
            $this->release_affected_url_batch_lock();
        }

        $this->schedule_affected_url_batch_flush(1, true);
    }

    private function is_recent_active_import_batch(array $state)
    {
        if (empty($state['activeImports'])) {
            return false;
        }

        $now = time();
        $latest = 0;
        foreach ($state['activeImports'] as $entry) {
            $latest = max($latest, (int) ($entry['updatedAt'] ?? 0));
        }

        return $latest > 0 && ($now - $latest) < 900;
    }

    public function flush_request_affected_url_batch()
    {
        $batch = $this->normalize_affected_url_batch($this->affected_url_request_batch);
        if (!$this->affected_url_batch_has_changes($batch)) {
            return;
        }

        $persisted = $this->read_persisted_affected_url_batch();
        $change_count = count($batch['posts']) + count($batch['terms']);
        if (!empty($persisted['activeImports'])) {
            $this->merge_request_batch_into_persistent_state(60);
            return;
        }
        if ($change_count > 250) {
            $this->merge_request_batch_into_persistent_state(1);
            return;
        }

        $this->affected_url_request_batch = $this->get_empty_affected_url_batch();
        try {
            $result = $this->apply_affected_url_batch($batch, 'request-burst');
        } catch (Throwable $throwable) {
            $result = array('success' => false);
            if (function_exists('ultracache_debug_log')) {
                ultracache_debug_log('request affected URL batch failed', array(
                    'message' => $throwable->getMessage(),
                ));
            }
        }
        if (empty($result['success'])) {
            $this->affected_url_request_batch = $batch;
            $this->merge_request_batch_into_persistent_state(30);
        }
    }

    private function slice_affected_url_batch(array $state, $post_limit = 250, $term_limit = 250)
    {
        $slice = $this->get_empty_affected_url_batch();
        $slice['posts'] = array_slice((array) $state['posts'], 0, max(1, (int) $post_limit), true);
        $slice['terms'] = array_slice((array) $state['terms'], 0, max(1, (int) $term_limit), true);
        $slice['reasons'] = (array) $state['reasons'];
        $slice['firstSeenAt'] = (int) $state['firstSeenAt'];
        $slice['updatedAt'] = (int) $state['updatedAt'];
        return $slice;
    }

    private function remove_processed_affected_url_batch_entries(array $state, array $processed)
    {
        foreach ($processed['posts'] as $post_id => $entry) {
            if (
                isset($state['posts'][$post_id])
                && (int) ($state['posts'][$post_id]['seenAt'] ?? 0) <= (int) ($entry['seenAt'] ?? 0)
            ) {
                unset($state['posts'][$post_id]);
            }
        }
        foreach ($processed['terms'] as $term_key => $entry) {
            if (
                isset($state['terms'][$term_key])
                && (int) ($state['terms'][$term_key]['seenAt'] ?? 0) <= (int) ($entry['seenAt'] ?? 0)
            ) {
                unset($state['terms'][$term_key]);
            }
        }
        if (empty($state['posts']) && empty($state['terms'])) {
            $state['reasons'] = array();
            $state['firstSeenAt'] = 0;
        }
        $state['updatedAt'] = time();
        return $state;
    }

    public function process_persisted_affected_url_batch()
    {
        if (!$this->acquire_affected_url_batch_lock(60)) {
            $this->schedule_affected_url_batch_flush(10, false);
            return;
        }

        try {
            $state = $this->read_persisted_affected_url_batch();
            if ($this->is_recent_active_import_batch($state)) {
                $this->schedule_affected_url_batch_flush(60, true);
                return;
            }
            if (!empty($state['activeImports'])) {
                $state['activeImports'] = array();
                $this->save_persisted_affected_url_batch($state);
            }
            $slice = $this->slice_affected_url_batch($state);
        } finally {
            $this->release_affected_url_batch_lock();
        }

        if (!$this->affected_url_batch_has_changes($slice)) {
            return;
        }

        $applied = false;
        try {
            $batch_reason = !empty($slice['reasons']['wp-all-import']) ? 'import-burst' : 'update-burst';
            $result = $this->apply_affected_url_batch($slice, $batch_reason);
            $applied = !empty($result['success']);
        } catch (Throwable $throwable) {
            if (function_exists('ultracache_debug_log')) {
                ultracache_debug_log('affected URL batch processing failed', array(
                    'message' => $throwable->getMessage(),
                ));
            }
        }

        if (!$applied) {
            $this->schedule_affected_url_batch_flush(30, true);
            return;
        }

        if (!$this->acquire_affected_url_batch_lock(60)) {
            $this->schedule_affected_url_batch_flush(10, false);
            return;
        }

        try {
            $state = $this->read_persisted_affected_url_batch();
            $state = $this->remove_processed_affected_url_batch_entries($state, $slice);
            $has_more = $this->affected_url_batch_has_changes($state);
            $this->save_persisted_affected_url_batch($state);
        } finally {
            $this->release_affected_url_batch_lock();
        }

        if ($has_more) {
            $this->schedule_affected_url_batch_flush(5, true);
        }
    }

    private function apply_affected_url_batch(array $batch, $reason)
    {
        $batch = $this->normalize_affected_url_batch($batch);
        $purge_plans = array();
        $warm_plans = array();
        $semantic_tags = array();
        $post_count = 0;
        $term_count = 0;

        foreach ($batch['posts'] as $post_id => $entry) {
            $post_id = absint($post_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (!$post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status) {
                continue;
            }
            $plan = $this->get_affected_url_plan_for_post($post_id, (string) ($entry['language'] ?? ''));
            $purge_plans[] = $plan;
            foreach ($this->get_litespeed_semantic_invalidation_tags_for_post($post_id) as $semantic_tag) {
                $semantic_tags[$semantic_tag] = $semantic_tag;
            }
            if (!empty($entry['warm'])) {
                $warm_plans[] = 'publish' === $post->post_status
                    ? $plan
                    : $this->exclude_post_permalink_from_affected_url_plan(
                        $plan,
                        $post_id,
                        (string) ($entry['language'] ?? '')
                    );
            }
            ++$post_count;
        }

        foreach ($batch['terms'] as $entry) {
            $term_id = absint($entry['termId'] ?? 0);
            $taxonomy = sanitize_key((string) ($entry['taxonomy'] ?? ''));
            if ($term_id < 1 || '' === $taxonomy) {
                continue;
            }
            $plan = $this->get_affected_url_plan_for_term($term_id, $taxonomy, (string) ($entry['language'] ?? ''));
            $purge_plans[] = $plan;
            foreach ($this->get_litespeed_semantic_invalidation_tags_for_term($term_id, $taxonomy) as $semantic_tag) {
                $semantic_tags[$semantic_tag] = $semantic_tag;
            }
            if (!empty($entry['warm'])) {
                $warm_plans[] = $plan;
            }
            ++$term_count;
        }

        $purge_plan = $this->merge_affected_url_plans($purge_plans);
        $warm_plan = $this->merge_affected_url_plans($warm_plans);
        $settings = $this->get_settings();
        $shared_warm_owns_litespeed_refill = !empty($warm_plan['warmUrls'])
            && !empty($settings['preload_on_save'])
            && class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'enqueue_targeted_warm_pipeline_urls');
        $purged = empty($purge_plan['purgeUrls'])
            ? false
            : $this->purge_urls(
                $purge_plan['purgeUrls'],
                'related-burst',
                array(
                    'post_count' => $post_count,
                    'term_count' => $term_count,
                    'reason' => sanitize_key((string) $reason),
                    'warm_url_count' => count($warm_plan['warmUrls']),
                    'litespeed_refill_managed_by_shared_warm' => $shared_warm_owns_litespeed_refill,
                )
            );

        $queue_result = $this->queue_affected_url_plan_rebuild(
            array(
                'purgeUrls' => $purge_plan['purgeUrls'],
                'warmUrls' => $warm_plan['warmUrls'],
            ),
            $reason
        );

        $litespeed_refill_fallback = array('success' => true, 'queued' => false, 'queuedUrlCount' => 0);
        if (
            $shared_warm_owns_litespeed_refill
            && (empty($queue_result['success']) || empty($queue_result['queued']) || max(0, (int) ($queue_result['queuedUrlCount'] ?? 0)) < 1)
            && class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'enqueue_litespeed_invalidation_urls')
        ) {
            $litespeed_refill_fallback = Ultra_Cache_WP::enqueue_litespeed_invalidation_urls(
                (array) $purge_plan['purgeUrls'],
                class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'is_litespeed_stale_invalidation_enabled')
                    ? Ultra_Cache_WP::is_litespeed_stale_invalidation_enabled($settings)
                    : !empty($settings['staleWhileRevalidateEnabled']),
                'affected-warm-fallback',
                true
            );
        }

        $semantic_queue = $this->queue_litespeed_semantic_invalidation_tags(array_values($semantic_tags), 'semantic-' . sanitize_key((string) $reason));
        $queue_success = (empty($warm_plan['warmUrls']) || !empty($queue_result['success']))
            && (empty($semantic_tags) || !empty($semantic_queue['success']));

        return array(
            'success' => $queue_success,
            'purged' => (bool) $purged,
            'postCount' => $post_count,
            'termCount' => $term_count,
            'purgeUrlCount' => count($purge_plan['purgeUrls']),
            'warmUrlCount' => count($warm_plan['warmUrls']),
            'semanticTagCount' => count($semantic_tags),
            'semanticQueue' => $semantic_queue,
            'queue' => $queue_result,
            'liteSpeedRefillFallback' => $litespeed_refill_fallback,
        );
    }
}
