<?php
/**
 * Warm URL discovery.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Warm_URL_Discovery_Trait
{
        /** Provider-native language currently selected for URL-only discovery. */
        private $warm_multilingual_context_code = '';

        /** Return frontend languages for one warm/discovery operation, or one neutral context. */
        private function get_warm_language_codes($operation = '')
        {
            $operation = sanitize_key((string) $operation);
            if ('' !== $operation && function_exists('ultracache_multilingual_get_warm_languages')) {
                if (function_exists('ultracache_multilingual_is_active') && ultracache_multilingual_is_active()) {
                    return array_values(ultracache_multilingual_get_warm_languages($operation));
                }
            }

            if (function_exists('ultracache_multilingual_get_active_language_codes')) {
                $languages = ultracache_multilingual_get_active_language_codes();
                if (!empty($languages)) {
                    if (function_exists('ultracache_should_warm_translation_pages')
                        && !ultracache_should_warm_translation_pages()
                    ) {
                        $default_language = function_exists('ultracache_multilingual_get_default_language')
                            ? (string) ultracache_multilingual_get_default_language()
                            : '';
                        if ('' !== $default_language && in_array($default_language, $languages, true)) {
                            return array($default_language);
                        }

                        return array((string) reset($languages));
                    }

                    return array_values($languages);
                }
            }

            return array('');
        }

        /** Return the provider-native language selected for this discovery callback. */
        private function get_current_warm_language_code()
        {
            if ('' !== (string) $this->warm_multilingual_context_code) {
                return (string) $this->warm_multilingual_context_code;
            }

            return function_exists('ultracache_multilingual_get_current_language')
                ? (string) ultracache_multilingual_get_current_language()
                : '';
        }

        /** Translate one generated public URL for the current discovery language. */
        private function translate_warm_url_for_context($url)
        {
            $url = (string) $url;
            $language_code = $this->get_current_warm_language_code();
            if ('' === $url || '' === $language_code) {
                return $url;
            }

            if (
                function_exists('ultracache_multilingual_get_provider')
                && 'wpml' === ultracache_multilingual_get_provider()
                && function_exists('ultracache_wpml_translate_generated_url')
            ) {
                return ultracache_wpml_translate_generated_url($url, $language_code);
            }

            if (!function_exists('ultracache_multilingual_translate_url')) {
                return $url;
            }

            return ultracache_multilingual_translate_url($url, $language_code);
        }

        /** Execute one discovery callback in a provider-supported language context. */
        private function run_warm_language_context($language_code, callable $callback)
        {
            $language_code = function_exists('ultracache_multilingual_normalize_language_code')
                ? ultracache_multilingual_normalize_language_code($language_code)
                : '';
            $previous = (string) $this->warm_multilingual_context_code;
            $this->warm_multilingual_context_code = $language_code;

            try {
                if ('' !== $language_code && function_exists('ultracache_multilingual_run_in_language')) {
                    return ultracache_multilingual_run_in_language($language_code, $callback);
                }

                return call_user_func($callback);
            } finally {
                $this->warm_multilingual_context_code = $previous;
            }
        }

        /** Return the exact public home for the current multilingual language. */
        private function get_current_warm_home_url()
        {
            $language_code = $this->get_current_warm_language_code();
            if ('' !== $language_code && function_exists('ultracache_multilingual_get_language_home_urls')) {
                $homes = ultracache_multilingual_get_language_home_urls();
                if (!empty($homes[$language_code])) {
                    return (string) $homes[$language_code];
                }
            }

            return home_url('/');
        }

        /** Return the language assigned to one post through WPML's public API. */
        private function get_wpml_post_language_code($post_id)
        {
            $post_id = absint($post_id);
            $post_type = $post_id > 0 ? get_post_type($post_id) : '';
            if ($post_id < 1 || '' === (string) $post_type || !function_exists('ultracache_wpml_get_element_language_code')) {
                return '';
            }

            return ultracache_wpml_get_element_language_code($post_id, $post_type);
        }

        /** Return the language assigned to one taxonomy term through WPML's public API. */
        private function get_wpml_term_language_code($term_id, $taxonomy)
        {
            if (!function_exists('ultracache_wpml_get_element_language_code')) {
                return '';
            }

            return ultracache_wpml_get_element_language_code(absint($term_id), sanitize_key((string) $taxonomy));
        }

        /** Build a provider-aware post affected-URL plan. */
        private function get_affected_url_plan_for_post($post_id, $language_code = '')
        {
            $post_id = absint($post_id);
            $provider = function_exists('ultracache_multilingual_get_provider')
                ? ultracache_multilingual_get_provider()
                : 'none';

            // WPML keeps translated-object semantics: one translation change is
            // resolved only in that translation's public language context.
            if ('wpml' === $provider) {
                if ('' === (string) $language_code) {
                    $language_code = $this->get_wpml_post_language_code($post_id);
                }

                $plan = $this->run_warm_language_context($language_code, function () use ($post_id) {
                    return $this->build_affected_url_plan(
                        $this->get_related_urls_for_post(
                            $post_id,
                            array(
                                'includeFeeds'            => true,
                                'includePagination'       => true,
                                'includeAuthorArchive'    => true,
                                'includeDateArchives'     => true,
                                'includePostCommentsFeed' => true,
                                'includeSiteFront'        => true,
                            )
                        )
                    );
                });

                return $this->filter_wpml_affected_plan_warm_by_policy($plan, $language_code);
            }

            $plan = $this->build_affected_url_plan(
                $this->get_related_urls_for_post(
                    $post_id,
                    array(
                        'includeFeeds'            => true,
                        'includePagination'       => true,
                        'includeAuthorArchive'    => true,
                        'includeDateArchives'     => true,
                        'includePostCommentsFeed' => true,
                        'includeSiteFront'        => true,
                    )
                )
            );

            // TranslatePress and future shared-object renderers expose one
            // WordPress object through multiple public language URLs. Expand
            // only those URLs; unrelated objects remain outside the plan.
            return $this->expand_affected_url_plan_for_shared_object_renderings($plan);
        }

        /** Build a provider-aware taxonomy-term affected-URL plan. */
        private function get_affected_url_plan_for_term($term_id, $taxonomy, $language_code = '')
        {
            $term_id = absint($term_id);
            $taxonomy = sanitize_key((string) $taxonomy);
            $provider = function_exists('ultracache_multilingual_get_provider')
                ? ultracache_multilingual_get_provider()
                : 'none';

            if ('wpml' === $provider) {
                if ('' === (string) $language_code) {
                    $language_code = $this->get_wpml_term_language_code($term_id, $taxonomy);
                }

                $plan = $this->run_warm_language_context($language_code, function () use ($term_id, $taxonomy) {
                    return $this->build_affected_url_plan(
                        $this->get_related_urls_for_term(
                            $term_id,
                            $taxonomy,
                            array(
                                'includeFeeds'      => true,
                                'includePagination' => true,
                                'includeSiteFront'  => true,
                            )
                        )
                    );
                });

                return $this->filter_wpml_affected_plan_warm_by_policy($plan, $language_code);
            }

            $plan = $this->build_affected_url_plan(
                $this->get_related_urls_for_term(
                    $term_id,
                    $taxonomy,
                    array(
                        'includeFeeds'      => true,
                        'includePagination' => true,
                        'includeSiteFront'  => true,
                    )
                )
            );

            return $this->expand_affected_url_plan_for_shared_object_renderings($plan);
        }
        /** Expand one affected plan only for shared-object multilingual renderers. */
        private function expand_affected_url_plan_for_shared_object_renderings(array $plan)
        {
            if (!function_exists('ultracache_multilingual_expand_shared_object_public_urls')) {
                return $plan;
            }

            $purge_urls = ultracache_multilingual_expand_shared_object_public_urls(
                (array) ($plan['purgeUrls'] ?? array())
            );
            $warm_urls = function_exists('ultracache_multilingual_expand_shared_object_warm_urls')
                ? ultracache_multilingual_expand_shared_object_warm_urls(
                    (array) ($plan['warmUrls'] ?? array()),
                    'affected_save'
                )
                : ultracache_multilingual_expand_shared_object_public_urls(
                    (array) ($plan['warmUrls'] ?? array())
                );

            $purge_plan = $this->build_affected_url_plan($purge_urls);
            $warm_plan = $this->build_affected_url_plan($warm_urls);

            return array(
                'purgeUrls' => (array) ($purge_plan['purgeUrls'] ?? array()),
                'warmUrls'  => (array) ($warm_plan['warmUrls'] ?? array()),
            );
        }

        /** Keep WPML invalidation intact while gating proactive affected-save warm by language policy. */
        private function filter_wpml_affected_plan_warm_by_policy(array $plan, $language_code)
        {
            $language_code = function_exists('ultracache_multilingual_normalize_language_code')
                ? ultracache_multilingual_normalize_language_code($language_code)
                : trim((string) $language_code);
            if ('' === $language_code || !function_exists('ultracache_multilingual_get_warm_languages')) {
                return $plan;
            }

            $eligible = ultracache_multilingual_get_warm_languages('affected_save');
            if (!in_array($language_code, $eligible, true)) {
                $plan['warmUrls'] = array();
            }

            return $plan;
        }

        private function get_affected_url_plan_for_term_assignment($post_id, $taxonomy, array $terms = array(), array $tt_ids = array(), array $old_tt_ids = array())
        {
            $plans = array($this->get_affected_url_plan_for_post($post_id));
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
                $plans[] = $this->get_affected_url_plan_for_term($term_id, $taxonomy);
            }

            return $this->merge_affected_url_plans($plans);
        }
        private function build_affected_url_plan(array $urls)
        {
            $purge_urls = array();
            $warm_urls = array();

            foreach ($urls as $url) {
                $normalized_url = $this->normalize_url($url);
                if ('' === $normalized_url || !$this->is_cacheable_local_url($normalized_url)) {
                    continue;
                }

                $purge_urls[$normalized_url] = $normalized_url;
                if (!$this->is_feed_url($normalized_url)) {
                    $warm_urls[$normalized_url] = $normalized_url;
                }
            }

            return array(
                'purgeUrls' => array_values($purge_urls),
                'warmUrls'  => array_values($warm_urls),
            );
        }
        private function merge_affected_url_plans(array $plans)
        {
            $purge_urls = array();
            $warm_urls = array();

            foreach ($plans as $plan) {
                if (!is_array($plan)) {
                    continue;
                }

                foreach ((array) ($plan['purgeUrls'] ?? array()) as $url) {
                    $normalized_url = $this->normalize_url($url);
                    if ('' !== $normalized_url && $this->is_cacheable_local_url($normalized_url)) {
                        $purge_urls[$normalized_url] = $normalized_url;
                    }
                }

                foreach ((array) ($plan['warmUrls'] ?? array()) as $url) {
                    $normalized_url = $this->normalize_url($url);
                    if ('' !== $normalized_url && !$this->is_feed_url($normalized_url) && $this->is_cacheable_local_url($normalized_url)) {
                        $warm_urls[$normalized_url] = $normalized_url;
                    }
                }
            }

            return array(
                'purgeUrls' => array_values($purge_urls),
                'warmUrls'  => array_values($warm_urls),
            );
        }
        private function is_feed_url($url)
        {
            $path = wp_parse_url((string) $url, PHP_URL_PATH);
            $path = is_string($path) ? trailingslashit(strtolower($path)) : '';
            if ('' !== $path && false !== strpos($path, '/feed/')) {
                return true;
            }

            $query = wp_parse_url((string) $url, PHP_URL_QUERY);
            if (is_string($query) && '' !== $query) {
                wp_parse_str($query, $parsed_query);
                if (!empty($parsed_query['feed'])) {
                    return true;
                }
            }

            return false;
        }
        private function get_related_urls_for_post($post_id, array $args = array())
        {
            $post_id = (int) $post_id;
            $defaults = array(
                'includeFeeds'            => true,
                'includePagination'       => true,
                'includeAuthorArchive'    => true,
                'includeDateArchives'     => true,
                'includePostCommentsFeed' => true,
                'includeSiteFront'        => true,
            );
            $args = wp_parse_args($args, $defaults);

            $urls = array();
            if (!empty($args['includeSiteFront'])) {
                foreach ($this->get_site_front_urls(false) as $seed_url) {
                    $this->append_related_url($urls, $seed_url);
                }
            }

            if ($post_id <= 0) {
                return array_values($urls);
            }

            $post = get_post($post_id);
            if (!$post) {
                return array_values($urls);
            }

            $permalink = get_permalink($post_id);
            if ($permalink) {
                $this->append_related_url($urls, $permalink);
                if (!empty($args['includePostCommentsFeed'])) {
                    $comments_feed = get_post_comments_feed_link($post_id);
                    if ($comments_feed) {
                        $this->append_related_url($urls, $comments_feed);
                    }
                }
            }

            if ('post' === $post->post_type) {
                $blog_base_url = $this->get_posts_index_url();
                if ($blog_base_url) {
                    $this->append_related_url($urls, $blog_base_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($blog_base_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $blog_base_url, $this->get_post_listing_page_number($post));
                    }
                }
            }

            $post_type_object = get_post_type_object($post->post_type);
            if ($post_type_object && !empty($post_type_object->has_archive)) {
                $archive_url = get_post_type_archive_link($post->post_type);
                if ($archive_url) {
                    $this->append_related_url($urls, $archive_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $archive_url, $this->get_post_type_archive_page_number($post));
                    }
                }
            }

            if ('product' === $post->post_type && function_exists('wc_get_page_permalink')) {
                $shop_url = wc_get_page_permalink('shop');
                if ($shop_url) {
                    $this->append_related_url($urls, $shop_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($shop_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $shop_url, $this->get_post_type_archive_page_number($post));
                    }
                }
            }

            if (!empty($args['includeAuthorArchive']) && 'post' === $post->post_type && !empty($post->post_author)) {
                $author_url = get_author_posts_url((int) $post->post_author);
                if ($author_url) {
                    $this->append_related_url($urls, $author_url);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($author_url));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $author_url, $this->get_author_archive_page_number($post));
                    }
                }
            }

            if (!empty($args['includeDateArchives']) && 'post' === $post->post_type) {
                $this->append_date_archive_urls($urls, $post, $args);
            }

            $taxonomies = get_object_taxonomies($post->post_type, 'names');
            foreach ((array) $taxonomies as $taxonomy) {
                $taxonomy_object = get_taxonomy($taxonomy);
                if (!$taxonomy_object || empty($taxonomy_object->public)) {
                    continue;
                }

                $terms = get_the_terms($post_id, $taxonomy);
                if (empty($terms) || is_wp_error($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    $term_link = get_term_link($term);
                    if (is_wp_error($term_link) || !$term_link) {
                        continue;
                    }

                    $this->append_related_url($urls, $term_link);
                    if (!empty($args['includeFeeds'])) {
                        $this->append_related_url($urls, $this->build_archive_feed_url($term_link));
                    }
                    if (!empty($args['includePagination'])) {
                        $this->append_paged_related_url($urls, $term_link, $this->get_term_archive_page_number($post, $taxonomy, (int) $term->term_id));
                    }
                }
            }

            return array_values($urls);
        }
        private function get_related_urls_for_term($term_id, $taxonomy, array $args = array())
        {
            $term_id = (int) $term_id;
            $taxonomy = is_string($taxonomy) ? $taxonomy : '';
            $defaults = array(
                'includeFeeds'      => true,
                'includePagination' => true,
                'includeSiteFront'  => true,
            );
            $args = wp_parse_args($args, $defaults);

            $urls = array();
            if (!empty($args['includeSiteFront'])) {
                foreach ($this->get_site_front_urls(false) as $seed_url) {
                    $this->append_related_url($urls, $seed_url);
                }
            }

            if ($term_id <= 0 || '' === $taxonomy) {
                return array_values($urls);
            }

            $term = get_term($term_id, $taxonomy);
            if (!$term || is_wp_error($term)) {
                return array_values($urls);
            }

            $term_link = get_term_link($term);
            if (!is_wp_error($term_link) && $term_link) {
                $this->append_related_url($urls, $term_link);
                if (!empty($args['includeFeeds'])) {
                    $this->append_related_url($urls, $this->build_archive_feed_url($term_link));
                }
                if (!empty($args['includePagination']) && (int) $term->count > $this->get_archive_posts_per_page($this->get_primary_post_type_for_taxonomy($taxonomy))) {
                    $this->append_paged_related_url($urls, $term_link, 2);
                }
            }

            $taxonomy_object = get_taxonomy($taxonomy);
            if ($taxonomy_object && !empty($taxonomy_object->object_type) && is_array($taxonomy_object->object_type)) {
                foreach ($taxonomy_object->object_type as $object_type) {
                    $post_type_object = get_post_type_object($object_type);
                    if ($post_type_object && !empty($post_type_object->has_archive)) {
                        $archive_url = get_post_type_archive_link($object_type);
                        if ($archive_url) {
                            $this->append_related_url($urls, $archive_url);
                        }
                    }

                    if ('product' === $object_type && function_exists('wc_get_page_permalink')) {
                        $shop_url = wc_get_page_permalink('shop');
                        if ($shop_url) {
                            $this->append_related_url($urls, $shop_url);
                        }
                    }
                }
            }

            return array_values($urls);
        }
        private function get_site_front_urls($include_archives = false)
        {
            $urls = array();
            $this->append_related_url($urls, $this->get_current_warm_home_url());

            if (function_exists('get_feed_link')) {
                $this->append_related_url($urls, get_feed_link());
            }

            $posts_page_url = $this->get_posts_index_url();
            if ($posts_page_url && $this->get_current_warm_home_url() !== $posts_page_url) {
                $this->append_related_url($urls, $posts_page_url);
                $this->append_related_url($urls, $this->build_archive_feed_url($posts_page_url));
            }

            if ($include_archives) {
                foreach ($this->get_public_crawl_post_types() as $post_type) {
                    $post_type_object = get_post_type_object($post_type);
                    if (!$post_type_object || empty($post_type_object->has_archive)) {
                        continue;
                    }

                    $archive_url = get_post_type_archive_link($post_type);
                    if ($archive_url) {
                        $this->append_related_url($urls, $archive_url);
                        $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                    }
                }

                if (function_exists('wc_get_page_permalink')) {
                    $shop_url = wc_get_page_permalink('shop');
                    if ($shop_url) {
                        $this->append_related_url($urls, $shop_url);
                        $this->append_related_url($urls, $this->build_archive_feed_url($shop_url));
                    }
                }
            }

            return array_values($urls);
        }
        /** Return site-front/archive URLs across every active multilingual language. */
        private function get_all_language_site_front_urls($include_archives = false)
        {
            $urls = array();
            foreach ($this->get_warm_language_codes() as $language_code) {
                $language_urls = $this->run_warm_language_context(
                    $language_code,
                    function () use ($include_archives) {
                        return $this->get_site_front_urls($include_archives);
                    }
                );
                foreach ((array) $language_urls as $url) {
                    $url = is_string($url) ? trim($url) : '';
                    if ('' === $url || !$this->is_cacheable_local_url($url)) {
                        continue;
                    }
                    $urls[$url] = $url;
                }
            }

            return array_values($urls);
        }

        private function get_posts_index_url()
        {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page_url = get_permalink($posts_page_id);
                if ($posts_page_url) {
                    return $posts_page_url;
                }
            }

            return $this->get_current_warm_home_url();
        }
        private function append_related_url(array &$urls, $url)
        {
            $url = $this->translate_warm_url_for_context((string) $url);

            $normalized_url = $this->normalize_url($url);
            if ('' === $normalized_url || !$this->is_cacheable_local_url($normalized_url)) {
                return false;
            }

            $urls[$normalized_url] = $normalized_url;
            return true;
        }
        private function append_paged_related_url(array &$urls, $base_url, $page_number)
        {
            $page_number = (int) $page_number;
            if ($page_number <= 1) {
                return false;
            }

            return $this->append_related_url($urls, $this->build_paged_archive_url($base_url, $page_number));
        }
        private function build_paged_archive_url($base_url, $page_number)
        {
            $page_number = max(1, (int) $page_number);
            $base_url = trailingslashit((string) $base_url);
            if ($page_number <= 1 || '' === $base_url) {
                return $base_url;
            }

            return $base_url . ltrim(user_trailingslashit('page/' . $page_number, 'paged'), '/');
        }
        private function build_archive_feed_url($base_url)
        {
            $base_url = trailingslashit((string) $base_url);
            if ('' === $base_url) {
                return '';
            }

            return $base_url . ltrim(user_trailingslashit('feed', 'feed'), '/');
        }
        private function append_date_archive_urls(array &$urls, $post, array $args)
        {
            $post = get_post($post);
            if (!$post) {
                return;
            }

            $year = (int) mysql2date('Y', $post->post_date);
            $month = (int) mysql2date('m', $post->post_date);
            $day = (int) mysql2date('d', $post->post_date);

            $year_url = get_year_link($year);
            $month_url = get_month_link($year, $month);
            $day_url = get_day_link($year, $month, $day);

            foreach (array(
                'year'  => $year_url,
                'month' => $month_url,
                'day'   => $day_url,
            ) as $period => $archive_url) {
                if (!$archive_url) {
                    continue;
                }

                $this->append_related_url($urls, $archive_url);
                if (!empty($args['includeFeeds'])) {
                    $this->append_related_url($urls, $this->build_archive_feed_url($archive_url));
                }
                if (!empty($args['includePagination'])) {
                    $this->append_paged_related_url($urls, $archive_url, $this->get_date_archive_page_number($post, $period));
                }
            }
        }
        private function get_archive_posts_per_page($post_type = 'post')
        {
            $per_page = (int) get_option('posts_per_page', 10);
            if ('product' === $post_type) {
                $per_page = (int) apply_filters('loop_shop_per_page', $per_page); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce owns this documented third-party filter name.
            }

            return max(1, $per_page);
        }
        private function get_primary_post_type_for_taxonomy($taxonomy)
        {
            $taxonomy_object = get_taxonomy($taxonomy);
            if ($taxonomy_object && !empty($taxonomy_object->object_type) && is_array($taxonomy_object->object_type)) {
                foreach ($taxonomy_object->object_type as $object_type) {
                    if (post_type_exists($object_type)) {
                        return (string) $object_type;
                    }
                }
            }

            return 'post';
        }
        private function get_descending_position_for_post($post, $post_type = '', $scope = 'post_type', array $scope_args = array())
        {
            global $wpdb;

            $post = get_post($post);
            if (!$post) {
                return 0;
            }

            $post_type = $post_type ? sanitize_key((string) $post_type) : sanitize_key((string) $post->post_type);
            $post_date = (string) $post->post_date;
            $scope = sanitize_key((string) $scope);
            if ('' === $post_type || '' === $post_date) {
                return 0;
            }

            $cache_key = 'ultracache_desc_pos_' . md5((string) wp_json_encode(array(
                'postId'   => (int) $post->ID,
                'postType' => $post_type,
                'postDate' => $post_date,
                'scope'    => $scope,
                'args'     => $scope_args,
            )));
            $cached = wp_cache_get($cache_key, 'ultracache');
            if (false !== $cached) {
                return max(1, (int) $cached);
            }

            switch ($scope) {
                case 'author':
                    $author_id = absint($scope_args['authorId'] ?? 0);
                    if ($author_id < 1) {
                        return 1;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded archive-position lookup against the WordPress posts table; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p WHERE p.post_status = %s AND p.post_type = %s AND p.post_author = %d AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        'publish',
                        $post_type,
                        $author_id,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;

                case 'year':
                    $year = absint($scope_args['year'] ?? 0);
                    if ($year < 1) {
                        return 1;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded archive-position lookup against the WordPress posts table; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p WHERE p.post_status = %s AND p.post_type = %s AND YEAR(p.post_date) = %d AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        'publish',
                        $post_type,
                        $year,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;

                case 'month':
                    $year = absint($scope_args['year'] ?? 0);
                    $month = absint($scope_args['month'] ?? 0);
                    if ($year < 1 || $month < 1 || $month > 12) {
                        return 1;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded archive-position lookup against the WordPress posts table; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p WHERE p.post_status = %s AND p.post_type = %s AND YEAR(p.post_date) = %d AND MONTH(p.post_date) = %d AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        'publish',
                        $post_type,
                        $year,
                        $month,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;

                case 'day':
                    $year = absint($scope_args['year'] ?? 0);
                    $month = absint($scope_args['month'] ?? 0);
                    $day = absint($scope_args['day'] ?? 0);
                    if ($year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
                        return 1;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded archive-position lookup against the WordPress posts table; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p WHERE p.post_status = %s AND p.post_type = %s AND YEAR(p.post_date) = %d AND MONTH(p.post_date) = %d AND DAY(p.post_date) = %d AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        'publish',
                        $post_type,
                        $year,
                        $month,
                        $day,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;

                case 'term':
                    $taxonomy = sanitize_key((string) ($scope_args['taxonomy'] ?? ''));
                    $term_id = absint($scope_args['termId'] ?? 0);
                    if ('' === $taxonomy || $term_id < 1) {
                        return 1;
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded taxonomy archive-position lookup against WordPress core tables; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p INNER JOIN %i tr ON tr.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE p.post_status = %s AND p.post_type = %s AND tt.taxonomy = %s AND tt.term_id = %d AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        $wpdb->term_relationships,
                        $wpdb->term_taxonomy,
                        'publish',
                        $post_type,
                        $taxonomy,
                        $term_id,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;

                default:
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded archive-position lookup against the WordPress posts table; result is cached below.
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(DISTINCT p.ID) FROM %i p WHERE p.post_status = %s AND p.post_type = %s AND (p.post_date > %s OR (p.post_date = %s AND p.ID >= %d))',
                        $wpdb->posts,
                        'publish',
                        $post_type,
                        $post_date,
                        $post_date,
                        (int) $post->ID
                    ));
                    break;
            }

            wp_cache_set($cache_key, $count, 'ultracache', HOUR_IN_SECONDS);

            return max(1, $count);
        }
        private function get_post_listing_page_number($post)
        {
            $post = get_post($post);
            if (!$post || 'post' !== $post->post_type) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, 'post');
            return (int) ceil($position / $this->get_archive_posts_per_page('post'));
        }
        private function get_post_type_archive_page_number($post)
        {
            $post = get_post($post);
            if (!$post) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type);
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }
        private function get_author_archive_page_number($post)
        {
            $post = get_post($post);
            if (!$post || empty($post->post_author)) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type, 'author', array('authorId' => (int) $post->post_author));
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }
        private function get_date_archive_page_number($post, $period = 'month')
        {
            $post = get_post($post);
            if (!$post) {
                return 1;
            }

            $year = (int) mysql2date('Y', $post->post_date);
            $month = (int) mysql2date('m', $post->post_date);
            $day = (int) mysql2date('d', $post->post_date);
            $scope = in_array($period, array('year', 'month', 'day'), true) ? $period : 'month';
            $position = $this->get_descending_position_for_post($post, $post->post_type, $scope, array(
                'year'  => $year,
                'month' => $month,
                'day'   => $day,
            ));
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }
        private function get_term_archive_page_number($post, $taxonomy, $term_id)
        {
            $post = get_post($post);
            $taxonomy = is_string($taxonomy) ? sanitize_key($taxonomy) : '';
            $term_id = absint($term_id);
            if (!$post || '' === $taxonomy || $term_id <= 0) {
                return 1;
            }

            $position = $this->get_descending_position_for_post($post, $post->post_type, 'term', array(
                'taxonomy' => $taxonomy,
                'termId'   => $term_id,
            ));
            return (int) ceil($position / $this->get_archive_posts_per_page($post->post_type));
        }
        public function get_crawl_scope_summary($scope_settings_override = null)
        {
            $scope_settings = is_array($scope_settings_override) ? $this->normalize_warm_scope_settings_array($scope_settings_override) : $this->get_warm_scope_settings();
            $max_urls = (int) apply_filters('ultracache_max_crawl_urls', 5000);
            if ($max_urls <= 0) {
                $max_urls = 5000;
            }

            $source_summary = $this->get_full_site_warm_source_breakdown($max_urls, $scope_settings);
            $menu_options = $this->get_warm_nav_menu_options();
            $source_counts = isset($source_summary['sourceCounts']) && is_array($source_summary['sourceCounts']) ? $source_summary['sourceCounts'] : array();
            $content_url_count = 0;
            foreach (array('pages', 'posts', 'categories', 'tags', 'woocommerce_products', 'woocommerce_product_taxonomies', 'custom_post_types', 'custom_taxonomies') as $content_key) {
                if (isset($source_counts[$content_key])) {
                    $content_url_count += (int) $source_counts[$content_key];
                }
            }

            return array(
                'baseUrlCount' => isset($source_counts['homepage']) ? max(0, (int) $source_counts['homepage']) : 0,
                'menuUrlCount' => isset($source_counts['menus']) ? max(0, (int) $source_counts['menus']) : 0,
                'seedUrlCount' => max(0, (int) (($source_counts['homepage'] ?? 0) + ($source_counts['menus'] ?? 0))),
                'postUrlCount' => max(0, (int) (($source_counts['pages'] ?? 0) + ($source_counts['posts'] ?? 0) + ($source_counts['woocommerce_products'] ?? 0) + ($source_counts['custom_post_types'] ?? 0))),
                'termUrlCount' => max(0, (int) (($source_counts['categories'] ?? 0) + ($source_counts['tags'] ?? 0) + ($source_counts['woocommerce_product_taxonomies'] ?? 0) + ($source_counts['custom_taxonomies'] ?? 0))),
                'contentUrlCount' => max(0, (int) $content_url_count),
                'sourceCounts' => $source_counts,
                'sourceBreakdown' => isset($source_summary['breakdown']) && is_array($source_summary['breakdown']) ? $source_summary['breakdown'] : array(),
                'sourceOrder' => $this->get_full_site_warm_source_order(),
                'generatedAt' => time(),
                'storedAt' => 0,
                'discoveredTotal' => max(0, (int) ($source_summary['discoveredTotal'] ?? 0)),
                'estimatedTotal' => max(0, (int) ($source_summary['estimatedTotal'] ?? 0)),
                'maxUrls' => $max_urls,
                'defaultScheduledWarmLimit' => 9,
                'suggestedScheduledWarmLimit' => max(0, (int) ($source_summary['defaultScheduledWarmLimit'] ?? 0)),
                'scheduledWarmLimitDerived' => false,
                'scheduledWarmLimitSource' => 'user_cap',
                'menuOptions' => $menu_options,
                'menuDepthOptions' => array(
                    array('value' => '', 'label' => __('Select depth', 'ultracache')),
                    array('value' => '1', 'label' => __('Depth 1', 'ultracache')),
                    array('value' => '2', 'label' => __('Depth 2', 'ultracache')),
                    array('value' => '3', 'label' => __('Depth 3', 'ultracache')),
                    array('value' => 'all', 'label' => __('All depths', 'ultracache')),
                ),
                'fullSiteSourceOptions' => $this->get_full_site_warm_source_options(),
                'selectedMenuLocation' => (string) $scope_settings['menuLocation'],
                'selectedMenuDepth' => (string) $scope_settings['menuDepth'],
                'selectedFullSiteSources' => array_values((array) $scope_settings['sources']),
            );
        }
        public function get_crawl_scope_summary_for_settings(array $settings)
        {
            return $this->get_crawl_scope_summary($settings);
        }
        private function get_full_site_warm_source_order()
        {
            return array('homepage', 'menus', 'pages', 'posts', 'categories', 'tags', 'woocommerce_products', 'woocommerce_product_taxonomies', 'custom_post_types', 'custom_taxonomies');
        }
        private function get_full_site_warm_source_label($source)
        {
            $labels = array(
                'homepage' => 'Homepage / blog index',
                'menus' => 'Menu URLs',
                'pages' => 'Pages',
                'posts' => 'Posts',
                'categories' => 'Categories',
                'tags' => 'Tags',
                'woocommerce_products' => 'WooCommerce products',
                'woocommerce_product_taxonomies' => 'WooCommerce product categories/tags',
                'custom_post_types' => 'Custom post types',
                'custom_taxonomies' => 'Custom taxonomies',
            );
            return isset($labels[$source]) ? $labels[$source] : (string) $source;
        }
        private function add_full_site_warm_source_urls(array &$seen, array &$breakdown, $source, array $urls, $max_urls)
        {
            $added = 0;
            $raw_count = 0;
            foreach ($urls as $url) {
                if (count($seen) >= $max_urls) {
                    break;
                }
                $url = is_string($url) ? trim($url) : '';
                if ('' === $url || !$this->is_cacheable_local_url($url)) {
                    continue;
                }
                $raw_count++;
                $key = $url;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = $url;
                $added++;
            }

            $breakdown[] = array(
                'key'      => (string) $source,
                'label'    => $this->get_full_site_warm_source_label($source),
                'count'    => $added,
                'rawCount' => $raw_count,
            );
        }
        private function get_public_post_type_urls_for_source($post_type, $max_urls)
        {
            $post_type = sanitize_key((string) $post_type);
            if ('' === $post_type || $max_urls <= 0) {
                return array();
            }

            $ids = get_posts(
                array(
                    'post_type'              => $post_type,
                    'post_status'            => 'publish',
                    'posts_per_page'         => max(1, min(5000, (int) $max_urls)),
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'suppress_filters'       => false,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                )
            );

            $urls = array();
            foreach ((array) $ids as $post_id) {
                $link = $this->safe_get_permalink((int) $post_id);
                if ($link) {
                    $urls[] = $link;
                }
            }

            return $urls;
        }
        private function get_public_taxonomy_urls_for_source($taxonomy, $max_urls)
        {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ('' === $taxonomy || $max_urls <= 0) {
                return array();
            }

            $term_ids = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                    'number'     => max(1, min(5000, (int) $max_urls)),
                    'orderby'    => 'term_id',
                    'order'      => 'ASC',
                )
            );

            if (is_wp_error($term_ids) || empty($term_ids)) {
                return array();
            }

            $urls = array();
            foreach ((array) $term_ids as $term_id) {
                $term_link = $this->safe_get_term_link((int) $term_id, $taxonomy);
                if ($term_link) {
                    $urls[] = $term_link;
                }
            }

            return $urls;
        }
        private function get_custom_public_post_types_for_warm()
        {
            $post_types = get_post_types(array('public' => true), 'objects');
            if (!is_array($post_types)) {
                return array();
            }

            $selected = array();
            foreach ($post_types as $post_type => $object) {
                $post_type = sanitize_key((string) $post_type);
                if ('' !== $post_type && !in_array($post_type, array('post', 'page', 'attachment', 'product'), true)) {
                    $selected[] = $post_type;
                }
            }

            return array_values(array_unique($selected));
        }
        private function get_custom_public_taxonomies_for_warm()
        {
            $taxonomies = get_taxonomies(array('public' => true), 'objects');
            if (!is_array($taxonomies)) {
                return array();
            }

            $selected = array();
            foreach ($taxonomies as $taxonomy => $object) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if ('' !== $taxonomy && !in_array($taxonomy, array('category', 'post_tag', 'product_cat', 'product_tag'), true)) {
                    $selected[] = $taxonomy;
                }
            }

            return array_values(array_unique($selected));
        }
        private function get_full_site_warm_source_urls_for_current_language($source, $remaining, $scope_settings = null)
        {
            $source = sanitize_key((string) $source);
            $remaining = max(0, (int) $remaining);
            if ($remaining <= 0) {
                return array();
            }

            if ('homepage' === $source) {
                $urls = array($this->get_current_warm_home_url());
                $posts_page_id = (int) get_option('page_for_posts');
                if ($posts_page_id > 0) {
                    $posts_page_url = $this->safe_get_permalink($posts_page_id);
                    if ($posts_page_url) {
                        $urls[] = $posts_page_url;
                    }
                }
                return $urls;
            }

            if ('menus' === $source) {
                $scope_settings = is_array($scope_settings) ? $this->normalize_warm_scope_settings_array($scope_settings) : $this->get_warm_scope_settings();
                return $this->get_safe_nav_menu_urls($scope_settings['menuLocation'] ?? '', $scope_settings['menuDepth'] ?? '');
            }

            if ('pages' === $source) {
                return $this->get_public_post_type_urls_for_source('page', $remaining);
            }

            if ('posts' === $source) {
                return $this->get_public_post_type_urls_for_source('post', $remaining);
            }

            if ('categories' === $source) {
                return $this->get_public_taxonomy_urls_for_source('category', $remaining);
            }

            if ('tags' === $source) {
                return $this->get_public_taxonomy_urls_for_source('post_tag', $remaining);
            }

            if ('woocommerce_products' === $source && post_type_exists('product')) {
                return $this->get_public_post_type_urls_for_source('product', $remaining);
            }

            if ('woocommerce_product_taxonomies' === $source) {
                $urls = array();
                foreach (array('product_cat', 'product_tag') as $taxonomy) {
                    if (!taxonomy_exists($taxonomy)) {
                        continue;
                    }
                    $urls = array_merge($urls, $this->get_public_taxonomy_urls_for_source($taxonomy, max(1, $remaining - count($urls))));
                    if (count($urls) >= $remaining) {
                        break;
                    }
                }
                return $urls;
            }

            if ('custom_post_types' === $source) {
                $urls = array();
                foreach ($this->get_custom_public_post_types_for_warm() as $post_type) {
                    $urls = array_merge($urls, $this->get_public_post_type_urls_for_source($post_type, max(1, $remaining - count($urls))));
                    if (count($urls) >= $remaining) {
                        break;
                    }
                }
                return $urls;
            }

            if ('custom_taxonomies' === $source) {
                $urls = array();
                foreach ($this->get_custom_public_taxonomies_for_warm() as $taxonomy) {
                    $urls = array_merge($urls, $this->get_public_taxonomy_urls_for_source($taxonomy, max(1, $remaining - count($urls))));
                    if (count($urls) >= $remaining) {
                        break;
                    }
                }
                return $urls;
            }

            return array();
        }
        /** Expand one selected full-site source across every active multilingual language. */
        private function get_full_site_warm_source_urls($source, $remaining, $scope_settings = null)
        {
            $remaining = max(0, (int) $remaining);
            if ($remaining <= 0) {
                return array();
            }

            $urls = array();
            $seen = array();
            foreach ($this->get_warm_language_codes() as $language_code) {
                if (count($urls) >= $remaining) {
                    break;
                }

                $language_urls = $this->run_warm_language_context(
                    $language_code,
                    function () use ($source, $remaining, $scope_settings, &$urls) {
                        return $this->get_full_site_warm_source_urls_for_current_language(
                            $source,
                            max(1, $remaining - count($urls)),
                            $scope_settings
                        );
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
                    if (count($urls) >= $remaining) {
                        break;
                    }
                }
            }

            return array_values($urls);
        }

        private function get_full_site_warm_source_breakdown($max_urls = 5000, $scope_settings = null)
        {
            $max_urls = max(1, (int) $max_urls);
            $selected = $this->get_full_site_warm_sources_lookup($scope_settings);
            $seen = array();
            $breakdown = array();
            $source_counts = array();

            foreach ($this->get_full_site_warm_source_order() as $source) {
                if (!isset($selected[$source])) {
                    continue;
                }
                $before = count($seen);
                $remaining = max(0, $max_urls - $before);
                $urls = $remaining > 0 ? $this->get_full_site_warm_source_urls($source, $remaining, $scope_settings) : array();
                $this->add_full_site_warm_source_urls($seen, $breakdown, $source, $urls, $max_urls);
                $source_counts[$source] = max(0, count($seen) - $before);
                if (count($seen) >= $max_urls) {
                    break;
                }
            }

            $discovered_total = count($seen);

            return array(
                'breakdown' => $breakdown,
                'sourceCounts' => $source_counts,
                'discoveredTotal' => $discovered_total,
                'estimatedTotal' => min($max_urls, $discovered_total),
                'defaultScheduledWarmLimit' => min($max_urls, $discovered_total),
            );
        }
        private function get_crawl_seed_urls($scope = 'full')
        {
            $scope = $this->normalize_crawl_scope($scope);
            $urls = array();
            $sources = $this->get_full_site_warm_sources_lookup();

            if ('menu' !== $scope && isset($sources['homepage'])) {
                $urls[] = $this->get_current_warm_home_url();

                $posts_page_id = (int) get_option('page_for_posts');
                if ($posts_page_id > 0) {
                    $posts_page_url = $this->safe_get_permalink($posts_page_id);
                    if ($posts_page_url) {
                        $urls[] = $posts_page_url;
                    }
                }
            }

            if ('menu' === $scope || isset($sources['menus'])) {
                foreach ($this->get_safe_nav_menu_urls() as $menu_url) {
                    if ($this->is_cacheable_local_url($menu_url)) {
                        $urls[] = $menu_url;
                    }
                }
            }

            $urls = array_values(array_unique(array_filter($urls)));

            return apply_filters('ultracache_crawl_seed_urls', $urls, $scope);
        }
        private function normalize_crawl_scope($scope)
        {
            $scope = is_string($scope) ? strtolower(trim($scope)) : 'full';
            return 'menu' === $scope ? 'menu' : 'full';
        }
        private function safe_get_permalink($post_id)
        {
            $post_id = absint($post_id);
            if ($post_id < 1) {
                return '';
            }

            $language_code = $this->get_current_warm_language_code();
            if (
                '' !== $language_code
                && function_exists('ultracache_multilingual_get_provider')
                && 'wpml' === ultracache_multilingual_get_provider()
                && function_exists('ultracache_wpml_get_translated_object_id')
            ) {
                $post_type = get_post_type($post_id);
                $translated_id = '' !== (string) $post_type
                    ? ultracache_wpml_get_translated_object_id($post_id, (string) $post_type, $language_code)
                    : 0;
                if ($translated_id < 1) {
                    return '';
                }
                $post_id = $translated_id;
            }

            try {
                $permalink = get_permalink($post_id);
            } catch (Throwable $e) {
                return '';
            }

            $permalink = is_string($permalink) ? $permalink : '';
            return '' !== $permalink ? $this->translate_warm_url_for_context($permalink) : '';
        }

        /** Return one taxonomy permalink in the current warm language. */
        private function safe_get_term_link($term_id, $taxonomy)
        {
            $term_id = absint($term_id);
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($term_id < 1 || '' === $taxonomy) {
                return '';
            }

            $language_code = $this->get_current_warm_language_code();
            if (
                '' !== $language_code
                && function_exists('ultracache_multilingual_get_provider')
                && 'wpml' === ultracache_multilingual_get_provider()
                && function_exists('ultracache_wpml_get_translated_object_id')
            ) {
                $translated_id = ultracache_wpml_get_translated_object_id($term_id, $taxonomy, $language_code);
                if ($translated_id < 1) {
                    return '';
                }
                $term_id = $translated_id;
            }

            $term_link = get_term_link($term_id, $taxonomy);
            if (is_wp_error($term_link) || !$term_link) {
                return '';
            }

            return $this->translate_warm_url_for_context((string) $term_link);
        }
        private function get_warm_scope_settings($raw = null)
        {
            /*
             * Deliberately read the saved dashboard option directly here by default. This helper is
             * used while dashboard defaults are being built, so calling get_settings() or
             * get_dashboard_settings() from here would recurse back into crawl summary
             * generation. Warm scope selection must stay a light option read.
             */
            if (null === $raw) {
                $raw = get_option(ULTRACACHE_SETTINGS_KEY, array());
            }

            return $this->normalize_warm_scope_settings_array(is_array($raw) ? $raw : array());
        }
        private function normalize_warm_scope_settings_array($raw)
        {
            $raw = is_array($raw) ? $raw : array();
            $sources = array();
            $source_value = '';

            if (isset($raw['warmFullSiteSources'])) {
                $source_value = $raw['warmFullSiteSources'];
            } elseif (isset($raw['sources'])) {
                $source_value = $raw['sources'];
            }

            $allowed = $this->get_allowed_full_site_warm_source_keys();
            $requested = array();
            $source_items = is_array($source_value) ? $source_value : preg_split('/[\r\n,]+/', (string) $source_value);
            foreach ((array) $source_items as $source) {
                $source = sanitize_key((string) $source);
                if ('' !== $source && in_array($source, $allowed, true)) {
                    $requested[$source] = true;
                }
            }
            foreach ($allowed as $source) {
                if (isset($requested[$source])) {
                    $sources[] = $source;
                }
            }

            $menu_location = isset($raw['warmMenuLocation']) ? $raw['warmMenuLocation'] : (isset($raw['menuLocation']) ? $raw['menuLocation'] : '');
            $menu_depth = isset($raw['warmMenuDepth']) ? $raw['warmMenuDepth'] : (isset($raw['menuDepth']) ? $raw['menuDepth'] : '');

            return array(
                'menuLocation' => sanitize_key((string) $menu_location),
                'menuDepth'    => $this->normalize_warm_menu_depth((string) $menu_depth),
                'sources'      => $sources,
            );
        }
        private function normalize_warm_menu_depth($depth)
        {
            $depth = strtolower(trim((string) $depth));
            return in_array($depth, array('1', '2', '3', 'all'), true) ? $depth : '';
        }
        private function get_allowed_full_site_warm_source_keys()
        {
            return $this->get_full_site_warm_source_order();
        }
        private function get_full_site_warm_sources_lookup($scope_settings = null)
        {
            $scope = is_array($scope_settings) ? $this->normalize_warm_scope_settings_array($scope_settings) : $this->get_warm_scope_settings();
            $sources = array_values((array) ($scope['sources'] ?? array()));

            // 3.04.42: resolve full-site source policy inside the selected
            // language context, before seed/post/term discovery starts. This
            // prevents low-traffic languages from querying global source
            // classes only to discard their URLs later.
            $language_code = (string) $this->warm_multilingual_context_code;
            if ('' !== $language_code
                && function_exists('ultracache_multilingual_get_language_warm_policy')
            ) {
                $policy = ultracache_multilingual_get_language_warm_policy($language_code);
                if (!empty($policy) && empty($policy['useGlobalFullSiteSources'])) {
                    $allowed = $this->get_allowed_full_site_warm_source_keys();
                    $requested = isset($policy['fullSiteSources']) && is_array($policy['fullSiteSources'])
                        ? $policy['fullSiteSources']
                        : array();
                    $requested_lookup = array_fill_keys(array_map('sanitize_key', $requested), true);
                    $sources = array_values(array_filter(
                        $allowed,
                        static function ($source) use ($requested_lookup) {
                            return isset($requested_lookup[$source]);
                        }
                    ));
                }
            }

            $lookup = array();
            foreach ($sources as $source) {
                $lookup[(string) $source] = true;
            }
            return $lookup;
        }
        private function is_full_site_source_enabled($source)
        {
            $lookup = $this->get_full_site_warm_sources_lookup();
            return isset($lookup[(string) $source]);
        }
        private function get_full_site_warm_source_options()
        {
            $options = array(
                array('value' => 'homepage', 'label' => __('Homepage / blog index', 'ultracache')),
                array('value' => 'menus', 'label' => __('Selected menu URLs', 'ultracache')),
                array('value' => 'pages', 'label' => __('Pages', 'ultracache')),
                array('value' => 'posts', 'label' => __('Posts', 'ultracache')),
                array('value' => 'categories', 'label' => __('Categories', 'ultracache')),
                array('value' => 'tags', 'label' => __('Tags', 'ultracache')),
            );

            $post_types = get_post_types(array('public' => true), 'objects');
            $custom_post_types = array();
            if (is_array($post_types)) {
                foreach ($post_types as $post_type => $object) {
                    $post_type = sanitize_key((string) $post_type);
                    if ('' !== $post_type && !in_array($post_type, array('post', 'page', 'attachment', 'product'), true)) {
                        $custom_post_types[] = !empty($object->labels->name) ? (string) $object->labels->name : $post_type;
                    }
                }
            }
            if (!empty($custom_post_types)) {
                $options[] = array('value' => 'custom_post_types', 'label' => sprintf(
				/* translators: %s: comma-separated custom post type labels. */
				__('Detected custom post types: %s', 'ultracache'),
				implode(', ', array_slice($custom_post_types, 0, 5))
			));
            }

            $taxonomies = get_taxonomies(array('public' => true), 'objects');
            $custom_taxonomies = array();
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $object) {
                    $taxonomy = sanitize_key((string) $taxonomy);
                    if ('' !== $taxonomy && !in_array($taxonomy, array('category', 'post_tag', 'product_cat', 'product_tag'), true)) {
                        $custom_taxonomies[] = !empty($object->labels->name) ? (string) $object->labels->name : $taxonomy;
                    }
                }
            }
            if (!empty($custom_taxonomies)) {
                $options[] = array('value' => 'custom_taxonomies', 'label' => sprintf(
				/* translators: %s: comma-separated custom taxonomy labels. */
				__('Detected custom taxonomies: %s', 'ultracache'),
				implode(', ', array_slice($custom_taxonomies, 0, 5))
			));
            }

            if (post_type_exists('product')) {
                $options[] = array('value' => 'woocommerce_products', 'label' => __('WooCommerce products', 'ultracache'));
            }
            if (taxonomy_exists('product_cat') || taxonomy_exists('product_tag')) {
                $options[] = array('value' => 'woocommerce_product_taxonomies', 'label' => __('WooCommerce product categories/tags', 'ultracache'));
            }

            return $options;
        }
        private function get_warm_nav_menu_options()
        {
            $options = array();
            $used_menu_ids = array();
            $locations = function_exists('get_nav_menu_locations') ? (array) get_nav_menu_locations() : array();
            $registered = function_exists('get_registered_nav_menus') ? (array) get_registered_nav_menus() : array();

            /*
             * Show assigned/front-end menus first because they are the safest default
             * warm-up targets. Still expose every saved WordPress menu below them so
             * the user can deliberately warm an unassigned/custom menu without the
             * plugin automatically scanning all stored/demo menus.
             */
            foreach ($locations as $location => $menu_id) {
                $menu_id = (int) $menu_id;
                if ($menu_id <= 0) {
                    continue;
                }

                $menu = wp_get_nav_menu_object($menu_id);
                if (!$menu || empty($menu->term_id)) {
                    continue;
                }

                $location_key = sanitize_key((string) $location);
                if ('' === $location_key) {
                    continue;
                }

                $location_label = isset($registered[$location]) && '' !== (string) $registered[$location] ? (string) $registered[$location] : (string) $location;
                $menu_name = !empty($menu->name) ? (string) $menu->name : ('Menu #' . $menu_id);
                $used_menu_ids[$menu_id] = true;

                $options[] = array(
                    'value'    => $location_key,
                    'label'    => sprintf(
						/* translators: 1: menu location label, 2: menu name. */
						__('Assigned / frontend: %1$s — %2$s', 'ultracache'),
						$location_label,
						$menu_name
					),
                    'menuId'   => $menu_id,
                    'location' => $location_key,
                    'source'   => 'assigned',
                    'count'    => 0,
                );
            }

            try {
                $menus = function_exists('wp_get_nav_menus') ? wp_get_nav_menus() : array();
            } catch (Throwable $e) {
                $menus = array();
            }

            if (is_array($menus)) {
                foreach ($menus as $menu) {
                    $menu_id = is_object($menu) && !empty($menu->term_id) ? (int) $menu->term_id : 0;
                    if ($menu_id <= 0 || isset($used_menu_ids[$menu_id])) {
                        continue;
                    }

                    $menu_name = !empty($menu->name) ? (string) $menu->name : ('Menu #' . $menu_id);
                    $options[] = array(
                        'value'    => 'menu-' . $menu_id,
                        'label'    => sprintf(
						/* translators: %s: menu name. */
						__('Other saved menu: %s', 'ultracache'),
						$menu_name
					),
                        'menuId'   => $menu_id,
                        'location' => '',
                        'source'   => 'saved',
                        'count'    => 0,
                    );
                }
            }

            return $options;
        }
        private function get_menu_id_for_warm_location($location)
        {
            $location = sanitize_key((string) $location);
            if ('' === $location) {
                return 0;
            }

            if (preg_match('/^menu-([0-9]+)$/', $location, $matches)) {
                $menu_id = (int) $matches[1];
                if ($menu_id > 0) {
                    $menu = wp_get_nav_menu_object($menu_id);
                    return $menu && !empty($menu->term_id) ? $menu_id : 0;
                }
            }

            foreach ($this->get_warm_nav_menu_options() as $option) {
                if (!empty($option['value']) && $location === (string) $option['value']) {
                    return (int) $option['menuId'];
                }
            }

            return 0;
        }
        private function get_nav_menu_item_depth_map(array $items)
        {
            $parent_map = array();
            foreach ($items as $item) {
                $id = is_object($item) && !empty($item->ID) ? (int) $item->ID : 0;
                if ($id <= 0) {
                    continue;
                }
                $parent_id = is_object($item) && !empty($item->menu_item_parent) ? (int) $item->menu_item_parent : 0;
                $parent_map[$id] = $parent_id;
            }

            $depth_map = array();
            foreach ($parent_map as $id => $parent_id) {
                $depth = 1;
                $seen = array($id => true);
                while ($parent_id > 0 && isset($parent_map[$parent_id]) && empty($seen[$parent_id])) {
                    $seen[$parent_id] = true;
                    $depth++;
                    $parent_id = (int) $parent_map[$parent_id];
                    if ($depth > 25) {
                        break;
                    }
                }
                $depth_map[$id] = max(1, $depth);
            }

            return $depth_map;
        }
        private function normalize_menu_warm_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url || '#' === $url || 0 === strpos($url, '#')) {
                return '';
            }

            $lower = strtolower($url);
            foreach (array('mailto:', 'tel:', 'sms:', 'javascript:') as $blocked_scheme) {
                if (0 === strpos($lower, $blocked_scheme)) {
                    return '';
                }
            }

            if (0 === strpos($url, '/')) {
                $url = home_url($url);
            }

            return esc_url_raw($url);
        }
        private function get_safe_nav_menu_urls($menu_location = null, $menu_depth = null)
        {
            $scope = $this->get_warm_scope_settings();
            $menu_location = null === $menu_location ? (string) $scope['menuLocation'] : sanitize_key((string) $menu_location);
            $menu_depth = null === $menu_depth ? (string) $scope['menuDepth'] : $this->normalize_warm_menu_depth($menu_depth);
            if ('' === $menu_location || '' === $menu_depth) {
                return array();
            }

            $menu_id = $this->get_menu_id_for_warm_location($menu_location);
            if ($menu_id <= 0) {
                return array();
            }

            try {
                $items = wp_get_nav_menu_items($menu_id);
            } catch (Throwable $e) {
                $items = array();
            }

            if (empty($items) || !is_array($items)) {
                return array();
            }

            $depth_limit = 'all' === $menu_depth ? 0 : max(1, (int) $menu_depth);
            $depth_map = $this->get_nav_menu_item_depth_map($items);
            $urls = array();

            foreach ($items as $item) {
                $item_id = is_object($item) && !empty($item->ID) ? (int) $item->ID : 0;
                $item_depth = $item_id > 0 && isset($depth_map[$item_id]) ? (int) $depth_map[$item_id] : 1;
                if ($depth_limit > 0 && $item_depth > $depth_limit) {
                    continue;
                }

                $url = '';
                if (is_object($item) && !empty($item->url) && is_string($item->url)) {
                    $url = $item->url;
                } elseif (is_array($item) && !empty($item['url']) && is_string($item['url'])) {
                    $url = $item['url'];
                }

                $url = $this->normalize_menu_warm_url($url);
                if ('' !== $url) {
                    $url = $this->translate_warm_url_for_context($url);
                }
                if ('' !== $url && $this->is_cacheable_local_url($url)) {
                    $urls[] = $url;
                }
            }

            return array_values(array_unique(array_filter($urls)));
        }
        private function get_public_crawl_post_types()
        {
            $sources = $this->get_full_site_warm_sources_lookup();
            if (empty($sources)) {
                return array();
            }

            $ordered = array();
            if (isset($sources['pages'])) {
                $ordered[] = 'page';
            }
            if (isset($sources['posts'])) {
                $ordered[] = 'post';
            }
            if (isset($sources['woocommerce_products']) && post_type_exists('product')) {
                $ordered[] = 'product';
            }
            if (isset($sources['custom_post_types'])) {
                $ordered = array_merge($ordered, $this->get_custom_public_post_types_for_warm());
            }

            return array_values(array_unique(array_filter(array_map('sanitize_key', $ordered))));
        }
        private function get_public_crawl_taxonomies()
        {
            $sources = $this->get_full_site_warm_sources_lookup();
            if (empty($sources)) {
                return array();
            }

            $ordered = array();
            if (isset($sources['categories']) && taxonomy_exists('category')) {
                $ordered[] = 'category';
            }
            if (isset($sources['tags']) && taxonomy_exists('post_tag')) {
                $ordered[] = 'post_tag';
            }
            if (isset($sources['woocommerce_product_taxonomies'])) {
                if (taxonomy_exists('product_cat')) {
                    $ordered[] = 'product_cat';
                }
                if (taxonomy_exists('product_tag')) {
                    $ordered[] = 'product_tag';
                }
            }
            if (isset($sources['custom_taxonomies'])) {
                $ordered = array_merge($ordered, $this->get_custom_public_taxonomies_for_warm());
            }

            return array_values(array_unique(array_filter(array_map('sanitize_key', $ordered))));
        }
}
