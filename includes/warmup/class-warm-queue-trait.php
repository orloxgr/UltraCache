<?php
/**
 * Warm crawl batching and cursor queue.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_Queue_Trait
{
        public function get_crawl_urls($scope = 'full')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $max_urls = (int) apply_filters('ultracache_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            $urls = array();
            $seen = array();
            $language_codes = $this->get_warm_language_codes();
            $max_total_urls = $max_urls * max(1, count($language_codes));
            foreach ($language_codes as $language_code) {

                $language_urls = $this->run_warm_language_context(
                    $language_code,
                    function () use ($scope, $max_urls) {
                        return $this->get_crawl_urls_for_current_language($scope, $max_urls);
                    }
                );

                foreach ((array) $language_urls as $url) {
                    $url = is_string($url) ? trim($url) : '';
                    if ('' === $url || !$this->is_cacheable_local_url($url)) {
                        continue;
                    }
                    $key = $url;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $urls[] = $url;
                    if (count($urls) >= $max_total_urls) {
                        break;
                    }
                }

                if (count($urls) >= $max_total_urls) {
                    break;
                }
            }

            return apply_filters('ultracache_crawl_urls', array_values($urls), $scope);
        }

        /** Discover one crawl scope in the currently selected frontend language. */
        private function get_crawl_urls_for_current_language($scope, $max_urls)
        {
            $scope = $this->normalize_crawl_scope($scope);
            $max_urls = max(1, (int) $max_urls);

            if ('menu' === $scope) {
                $urls = array();
                foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                    if ($this->is_cacheable_local_url($menu_url)) {
                        $urls[] = $menu_url;
                    }
                }

                $urls = array_values(array_unique(array_filter($urls)));
                return count($urls) > $max_urls ? array_slice($urls, 0, $max_urls) : $urls;
            }

            $urls = array($this->get_current_warm_home_url());

            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page_url = $this->safe_get_permalink($posts_page_id);
                if ($posts_page_url) {
                    $urls[] = $posts_page_url;
                }
            }

            foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                if ($this->is_cacheable_local_url($menu_url)) {
                    $urls[] = $menu_url;
                }
            }

            foreach ($this->get_public_crawl_post_types() as $post_type) {
                $post_ids = get_posts(
                    array(
                        'post_type'              => $post_type,
                        'post_status'            => 'publish',
                        'posts_per_page'         => -1,
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                        'suppress_filters'       => false,
                    )
                );

                foreach ((array) $post_ids as $post_id) {
                    $link = $this->safe_get_permalink($post_id);
                    if ($link) {
                        $urls[] = $link;
                    }
                    if (count($urls) >= $max_urls) {
                        break;
                    }
                }

                if (count($urls) >= $max_urls) {
                    break;
                }
            }

            if (count($urls) < $max_urls) {
                foreach ($this->get_public_crawl_taxonomies() as $taxonomy) {
                    $term_ids = get_terms(
                        array(
                            'taxonomy'   => $taxonomy,
                            'hide_empty' => false,
                            'fields'     => 'ids',
                        )
                    );

                    if (is_wp_error($term_ids) || empty($term_ids)) {
                        continue;
                    }

                    foreach ($term_ids as $term_id) {
                        $term_link = $this->safe_get_term_link((int) $term_id, $taxonomy);
                        if ($term_link) {
                            $urls[] = $term_link;
                        }
                        if (count($urls) >= $max_urls) {
                            break;
                        }
                    }

                    if (count($urls) >= $max_urls) {
                        break;
                    }
                }
            }

            $urls = array_values(array_unique(array_filter($urls)));
            return count($urls) > $max_urls ? array_slice($urls, 0, $max_urls) : $urls;
        }
        public function get_crawl_urls_batch($offset = 0, $limit = 100, $scope = 'full')
        {
            $offset = max(0, (int) $offset);
            $limit = max(1, min(500, (int) $limit));
            $scope = $this->normalize_crawl_scope($scope);

            if ($offset <= 0) {
                return $this->get_crawl_urls_cursor_batch('', $limit, $scope);
            }

            $cursor = '';
            $remaining = $offset;
            $safety = 0;

            while ($remaining > 0 && $safety < 10000) {
                $step = min(500, max(1, $remaining));
                $batch = $this->get_crawl_urls_cursor_batch($cursor, $step, $scope);
                $count = isset($batch['items']) && is_array($batch['items']) ? count($batch['items']) : 0;

                if ($count <= 0 && empty($batch['hasMore'])) {
                    return $batch;
                }

                $remaining -= $count;
                $cursor = !empty($batch['nextCursor']) ? (string) $batch['nextCursor'] : '';

                if (empty($batch['hasMore'])) {
                    break;
                }

                $safety++;
            }

            return $this->get_crawl_urls_cursor_batch($cursor, $limit, $scope);
        }
        public function get_crawl_urls_cursor_batch($cursor = '', $limit = 100, $scope = 'full', $operation = '', $requested_language = '')
        {
            $limit = max(1, min(500, (int) $limit));
            $max_urls = (int) apply_filters('ultracache_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            $requested_language = function_exists('ultracache_multilingual_normalize_language_code')
                ? ultracache_multilingual_normalize_language_code($requested_language)
                : trim((string) $requested_language);
            $state = $this->decode_crawl_cursor_state($cursor, $scope);
            $scope = $this->normalize_crawl_scope(isset($state['scope']) ? $state['scope'] : $scope);
            $operation = sanitize_key((string) $operation);
            if (!in_array($operation, array('menu', 'full_site', 'scheduled', 'after_flush', 'after_cleanup'), true)) {
                $operation = '';
            }
            if ((string) ($state['language'] ?? '') !== $requested_language) {
                $state = $this->get_default_crawl_cursor_state($scope);
                $state['language'] = $requested_language;
            }
            $start_generated = (int) $state['generated'];
            $items = array();
            $batch_seen = array();

            $language_codes = $this->get_warm_language_codes($operation);
            if ('' !== $requested_language) {
                if (!in_array($requested_language, array_map('strval', (array) $language_codes), true)) {
                    return array(
                        'items'      => array(),
                        'total'      => 0,
                        'offset'     => $start_generated,
                        'limit'      => $limit,
                        'cursor'     => (string) $cursor,
                        'nextCursor' => '',
                        'nextOffset' => $start_generated,
                        'processed'  => $start_generated,
                        'hasMore'    => false,
                        'message'    => 'The requested language is not enabled for this warm operation.',
                    );
                }
                $language_codes = array($requested_language);
            }
            if (empty($language_codes)) {
                if ('' !== $operation && function_exists('ultracache_multilingual_is_active') && ultracache_multilingual_is_active()) {
                    return array(
                        'items'      => array(),
                        'total'      => 0,
                        'offset'     => $start_generated,
                        'limit'      => $limit,
                        'cursor'     => (string) $cursor,
                        'nextCursor' => '',
                        'nextOffset' => $start_generated,
                        'processed'  => $start_generated,
                        'hasMore'    => false,
                        'message'    => 'No multilingual language is enabled for this warm operation.',
                    );
                }
                $language_codes = array('');
            }
            $state['language_index'] = max(0, min(count($language_codes), (int) ($state['language_index'] ?? 0)));
            $max_total_urls = $max_urls * max(1, count($language_codes));

            while (count($items) < $limit) {
                if ((int) ($state['language_generated'] ?? 0) >= $max_urls) {
                    $state['stage'] = 'done';
                }

                if ((int) $state['language_index'] >= count($language_codes)) {
                    $state['stage'] = 'done';
                    break;
                }

                if ('done' === $state['stage']) {
                    ++$state['language_index'];
                    if ((int) $state['language_index'] >= count($language_codes)) {
                        break;
                    }
                    $state['stage'] = 'seed';
                    $state['seed_index'] = 0;
                    $state['post_type_index'] = 0;
                    $state['post_offset'] = 0;
                    $state['taxonomy_index'] = 0;
                    $state['term_offset'] = 0;
                    $state['language_generated'] = 0;
                }

                $language_code = (string) $language_codes[(int) $state['language_index']];
                $this->run_warm_language_context(
                    $language_code,
                    function () use (&$state, &$items, &$batch_seen, $limit, $max_urls, $scope) {
                    switch ($state['stage']) {
                        case 'seed':
                            $seed_urls = $this->get_crawl_seed_urls($scope);
                            $seed_total = count($seed_urls);

                            while (count($items) < $limit && (int) $state['seed_index'] < $seed_total && (int) ($state['language_generated'] ?? 0) < $max_urls) {
                                $url = $seed_urls[(int) $state['seed_index']];
                                $state['seed_index']++;
                                $this->append_crawl_batch_item($items, $batch_seen, $url, $state, $max_urls);
                            }

                            if ((int) $state['seed_index'] >= $seed_total) {
                                $state['stage'] = ('menu' === $scope) ? 'done' : 'posts';
                            }
                            break;

                        case 'posts':
                            $post_types = $this->get_public_crawl_post_types();
                            if ((int) $state['post_type_index'] >= count($post_types)) {
                                $state['stage'] = 'terms';
                                break;
                            }

                            $post_type = $post_types[(int) $state['post_type_index']];
                            $remaining = max(1, min(200, $limit - count($items)));
                            $post_ids = get_posts(
                                array(
                                    'post_type'              => $post_type,
                                    'post_status'            => 'publish',
                                    'posts_per_page'         => $remaining,
                                    'offset'                 => (int) $state['post_offset'],
                                    'orderby'                => 'ID',
                                    'order'                  => 'ASC',
                                    'fields'                 => 'ids',
                                    'no_found_rows'          => true,
                                    'update_post_meta_cache' => false,
                                    'update_post_term_cache' => false,
                                    'suppress_filters'       => false,
                                )
                            );

                            if (empty($post_ids)) {
                                $state['post_type_index']++;
                                $state['post_offset'] = 0;
                                break;
                            }

                            $state['post_offset'] += count($post_ids);

                            foreach ((array) $post_ids as $post_id) {
                                if (count($items) >= $limit || (int) ($state['language_generated'] ?? 0) >= $max_urls) {
                                    break;
                                }

                                $link = $this->safe_get_permalink($post_id);
                                if ($link) {
                                    $this->append_crawl_batch_item($items, $batch_seen, $link, $state, $max_urls);
                                }
                            }

                            if (count($post_ids) < $remaining) {
                                $state['post_type_index']++;
                                $state['post_offset'] = 0;
                            }
                            break;

                        case 'terms':
                            $taxonomies = $this->get_public_crawl_taxonomies();
                            if ((int) $state['taxonomy_index'] >= count($taxonomies)) {
                                $state['stage'] = 'done';
                                break;
                            }

                            $taxonomy = $taxonomies[(int) $state['taxonomy_index']];
                            $remaining = max(1, min(200, $limit - count($items)));
                            $term_ids = get_terms(
                                array(
                                    'taxonomy'   => $taxonomy,
                                    'hide_empty' => false,
                                    'fields'     => 'ids',
                                    'number'     => $remaining,
                                    'offset'     => (int) $state['term_offset'],
                                    'orderby'    => 'term_id',
                                    'order'      => 'ASC',
                                )
                            );

                            if (is_wp_error($term_ids) || empty($term_ids)) {
                                $state['taxonomy_index']++;
                                $state['term_offset'] = 0;
                                break;
                            }

                            $term_ids = array_values(array_map('intval', (array) $term_ids));
                            $state['term_offset'] += count($term_ids);

                            foreach ($term_ids as $term_id) {
                                if (count($items) >= $limit || (int) ($state['language_generated'] ?? 0) >= $max_urls) {
                                    break;
                                }

                                $term_link = $this->safe_get_term_link($term_id, $taxonomy);
                                if ($term_link) {
                                    $this->append_crawl_batch_item($items, $batch_seen, $term_link, $state, $max_urls);
                                }
                            }

                            if (count($term_ids) < $remaining) {
                                $state['taxonomy_index']++;
                                $state['term_offset'] = 0;
                            }
                            break;

                        default:
                            $state['stage'] = 'done';
                            break;
                    }

                    }
                );
            }

            $has_more = (int) $state['generated'] < $max_total_urls
                && ((int) ($state['language_index'] ?? 0) < count($language_codes))
                && !('done' === $state['stage'] && (int) ($state['language_index'] ?? 0) >= count($language_codes) - 1);
            $estimated_total = max($this->estimate_crawl_url_total($max_urls, $scope, $requested_language), (int) $state['generated']);

            return array(
                'items'      => array_values($items),
                'total'      => $estimated_total,
                'offset'     => $start_generated,
                'limit'      => $limit,
                'cursor'     => (string) $cursor,
                'nextCursor' => $has_more ? $this->encode_crawl_cursor_state($state) : '',
                'nextOffset' => (int) $state['generated'],
                'processed'  => (int) $state['generated'],
                'hasMore'    => $has_more,
            );
        }
        private function get_default_crawl_cursor_state($scope = 'full')
        {
            return array(
                'scope'           => $this->normalize_crawl_scope($scope),
                'language'        => '',
                'language_index'  => 0,
                'stage'           => 'seed',
                'seed_index'      => 0,
                'post_type_index' => 0,
                'post_offset'     => 0,
                'taxonomy_index'  => 0,
                'term_offset'     => 0,
                'generated'       => 0,
                'language_generated' => 0,
            );
        }
        private function encode_crawl_cursor_state($state)
        {
            if (!is_array($state)) {
                $state = $this->get_default_crawl_cursor_state();
            }

            $encoded = wp_json_encode($state);
            return $encoded ? base64_encode($encoded) : '';
        }
        private function decode_crawl_cursor_state($cursor, $scope = 'full')
        {
            $default = $this->get_default_crawl_cursor_state($scope);
            $cursor = is_string($cursor) ? trim($cursor) : '';

            if ('' === $cursor) {
                return $default;
            }

            $decoded = base64_decode($cursor, true);
            if (false === $decoded || '' === $decoded) {
                return $default;
            }

            $state = json_decode($decoded, true);
            if (!is_array($state)) {
                return $default;
            }

            $allowed_stages = array('seed', 'posts', 'terms', 'done');
            $state = array_merge($default, $state);
            $state['scope'] = $this->normalize_crawl_scope(isset($state['scope']) ? $state['scope'] : $default['scope']);
            $state['language'] = function_exists('ultracache_multilingual_normalize_language_code')
                ? ultracache_multilingual_normalize_language_code($state['language'] ?? '')
                : trim((string) ($state['language'] ?? ''));
            $state['language_index'] = max(0, (int) ($state['language_index'] ?? 0));
            $state['stage'] = in_array($state['stage'], $allowed_stages, true) ? $state['stage'] : $default['stage'];
            $state['seed_index'] = max(0, (int) $state['seed_index']);
            $state['post_type_index'] = max(0, (int) $state['post_type_index']);
            $state['post_offset'] = max(0, (int) $state['post_offset']);
            $state['taxonomy_index'] = max(0, (int) $state['taxonomy_index']);
            $state['term_offset'] = max(0, (int) $state['term_offset']);
            $state['generated'] = max(0, (int) $state['generated']);
            $state['language_generated'] = max(0, (int) ($state['language_generated'] ?? 0));

            return $state;
        }
        private function append_crawl_batch_item(array &$items, array &$batch_seen, $url, array &$state, $max_urls)
        {
            if ((int) ($state['language_generated'] ?? 0) >= (int) $max_urls) {
                $state['stage'] = 'done';
                return false;
            }

            $url = is_string($url) ? trim($url) : '';
            if ('' !== $url) {
                $url = $this->translate_warm_url_for_context($url);
            }
            if ('' === $url || !$this->is_cacheable_local_url($url)) {
                return false;
            }

            if (isset($batch_seen[$url])) {
                return false;
            }

            $batch_seen[$url] = true;
            $items[] = $url;
            $state['generated']++;
            $state['language_generated'] = max(0, (int) ($state['language_generated'] ?? 0)) + 1;

            return true;
        }
        private function estimate_crawl_url_total($max_urls, $scope = 'full', $requested_language = '')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $requested_language = function_exists('ultracache_multilingual_normalize_language_code')
                ? ultracache_multilingual_normalize_language_code($requested_language)
                : trim((string) $requested_language);
            $language_codes = '' !== $requested_language ? array($requested_language) : $this->get_warm_language_codes();
            $total = 0;
            foreach ($language_codes as $language_code) {
                $total += (int) $this->run_warm_language_context(
                    $language_code,
                    function () use ($scope) {
                        return count($this->get_crawl_seed_urls($scope));
                    }
                );
            }

            if ('menu' === $scope) {
                return min((int) $max_urls * max(1, count($language_codes)), max(0, (int) $total));
            }

            foreach ($this->get_public_crawl_post_types() as $post_type) {
                $counts = wp_count_posts($post_type);
                if ($counts && isset($counts->publish)) {
                    $total += (int) $counts->publish;
                }
            }

            foreach ($this->get_public_crawl_taxonomies() as $taxonomy) {
                $count = wp_count_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    )
                );

                if (!is_wp_error($count)) {
                    $total += (int) $count;
                }
            }

            return min((int) $max_urls * max(1, count($language_codes)), max(0, (int) $total));
        }
}
