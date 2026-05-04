<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Routes_Trait')) {
    trait Ultra_Cache_Rest_Routes_Trait
    {
        private function get_route_definitions()
        {
            return array(
                '/stats' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_stats'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/purge-all' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'purge_all'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/varnish/test' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'varnish_test'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/varnish/flush-all' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'varnish_flush_all'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/opcache/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'opcache_flush'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/apcu/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'apcu_flush'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/object-cache/redis-test' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'redis_test'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_redis_test_args(),
                    ),
                ),
                '/object-cache/backend-test' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'object_cache_backend_test'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/object-cache/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'object_cache_flush'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/cache-conflicts/remove-dropins' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'remove_conflicting_cache_dropins'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/cron-warm/start' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'cron_warm_start'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/cron-warm/stop' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'cron_warm_stop'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/cron-warm/tick' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'cron_warm_tick'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'pagesPerMinute' => array(
                                'type'              => 'integer',
                                'required'          => false,
                                'sanitize_callback' => 'absint',
                            ),
                        ),
                    ),
                ),
                '/settings' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_settings'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'update_settings'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_settings_update_args(),
                    ),
                ),
                '/delete-all-data' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'delete_all_plugin_data'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'confirmation' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ),
                        ),
                    ),
                ),
                '/crawl-urls' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_urls'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'offset' => array(
                                'type'              => 'integer',
                                'required'          => false,
                                'sanitize_callback' => 'absint',
                            ),
                            'cursor' => array(
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'sanitize_text_field',
                            ),
                            'limit' => array(
                                'type'              => 'integer',
                                'required'          => false,
                                'sanitize_callback' => 'absint',
                            ),
                            'scope' => array(
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => array($this, 'sanitize_crawl_scope_param'),
                                'validate_callback' => array($this, 'validate_crawl_scope_param'),
                            ),
                        ),
                    ),
                ),
                '/crawl-page' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'crawl_page'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'url' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => array($this, 'sanitize_url_param'),
                                'validate_callback' => array($this, 'validate_non_empty_url_param'),
                            ),
                            'buildCssBundle' => array(
                                'type'     => 'boolean',
                                'required' => false,
                            ),
                        ),
                    ),
                ),
                '/build-frontpage-css' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'build_frontpage_css'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/warm-frontpage-html' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'warm_frontpage_html'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/warm-frontpage-html-css' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'warm_frontpage_html_css'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/inspect-url' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'inspect_url'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'url' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => array($this, 'sanitize_url_param'),
                                'validate_callback' => array($this, 'validate_non_empty_url_param'),
                            ),
                        ),
                    ),
                ),
                '/query-string-allowlist/populate' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'populate_query_string_allowlist'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/media-ids' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_media_ids'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'offset' => array(
                                'type'              => 'integer',
                                'required'          => false,
                                'sanitize_callback' => 'absint',
                            ),
                            'limit' => array(
                                'type'              => 'integer',
                                'required'          => false,
                                'sanitize_callback' => 'absint',
                            ),
                            'scope' => array(
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => array($this, 'sanitize_crawl_scope_param'),
                                'validate_callback' => array($this, 'validate_crawl_scope_param'),
                            ),
                        ),
                    ),
                ),
                '/optimize-id' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'optimize_id'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'id' => array(
                                'type'              => 'integer',
                                'required'          => true,
                                'sanitize_callback' => 'absint',
                            ),
                        ),
                    ),
                ),
                '/optimize-media' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'optimize_media'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/media-queue/status' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'media_queue_status'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_common_args(),
                    ),
                ),
                '/media-queue/rebuild' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'media_queue_rebuild'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_rebuild_args(),
                    ),
                ),
                '/media-queue/process' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'media_queue_process'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_process_args(),
                    ),
                ),
                '/media-queue/repair' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'media_queue_repair'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_common_args(),
                    ),
                ),
                '/media-queue/retry-failed' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'media_queue_retry_failed'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_common_args(),
                    ),
                ),
                '/media-queue/clear-completed' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'media_queue_clear_completed'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => $this->get_media_queue_common_args(),
                    ),
                ),
                '/performance-profile/last' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_performance_profile_last'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/performance-profile/clear' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'clear_performance_profile_last'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/runtime-js-scan/report' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'save_runtime_js_scan_report'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_runtime_js_scan_report'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'scanId' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_key',
                            ),
                        ),
                    ),
                ),
                '/action-queue' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'enqueue_action_job'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'action' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_key',
                            ),
                            'params' => array(
                                'type'     => 'object',
                                'required' => false,
                            ),
                        ),
                    ),
                ),
                '/action-queue/(?P<id>[A-Za-z0-9_\-]+)' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'get_action_job'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'id' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ),
                        ),
                    ),
                ),
                '/action-queue/(?P<id>[A-Za-z0-9_\-]+)/run' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'run_action_job_request'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'id' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ),
                        ),
                    ),
                ),
            );
        }

        private function add_query_string_candidate(&$items, &$sources, $key, $source)
        {
            $key = sanitize_key((string) $key);
            if ('' === $key) {
                return;
            }
            if (!isset($items[$key])) {
                $items[$key] = true;
                $sources[$key] = $source;
            }
        }

        private function taxonomy_has_query_string_terms($taxonomy)
        {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
                return false;
            }

            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'fields'     => 'ids',
                'number'     => 1,
            ));

            return !is_wp_error($terms) && !empty($terms);
        }

        private function add_taxonomy_query_string_candidates(&$items, &$sources, $taxonomy, $taxonomy_object, $source)
        {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ('' === $taxonomy || !is_object($taxonomy_object)) {
                return;
            }

            $has_query_var = isset($taxonomy_object->query_var) && false !== $taxonomy_object->query_var;
            if (!$has_query_var) {
                return;
            }

            if (!$this->taxonomy_has_query_string_terms($taxonomy)) {
                return;
            }

            $this->add_query_string_candidate($items, $sources, $taxonomy, $source);

            if (isset($taxonomy_object->query_var) && is_string($taxonomy_object->query_var) && '' !== $taxonomy_object->query_var && $taxonomy_object->query_var !== $taxonomy) {
                $this->add_query_string_candidate($items, $sources, $taxonomy_object->query_var, $source . ' query var');
            }
        }

        private function get_query_string_allowlist_candidates()
        {
            $items = array();
            $sources = array();

            $taxonomies = get_taxonomies(array(), 'objects');
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $taxonomy_object) {
                    if (!is_object($taxonomy_object)) {
                        continue;
                    }

                    $is_publicish = !empty($taxonomy_object->public) || !empty($taxonomy_object->publicly_queryable) || !empty($taxonomy_object->show_ui);
                    if (!$is_publicish) {
                        continue;
                    }

                    $this->add_taxonomy_query_string_candidates($items, $sources, $taxonomy, $taxonomy_object, 'Registered taxonomy');
                }
            }

            if (function_exists('wc_get_attribute_taxonomies')) {
                $attributes = wc_get_attribute_taxonomies();
                if (is_array($attributes)) {
                    foreach ($attributes as $attribute) {
                        $attribute_name = '';
                        if (is_object($attribute) && isset($attribute->attribute_name)) {
                            $attribute_name = (string) $attribute->attribute_name;
                        } elseif (is_array($attribute) && isset($attribute['attribute_name'])) {
                            $attribute_name = (string) $attribute['attribute_name'];
                        }

                        $attribute_name = sanitize_title($attribute_name);
                        if ('' === $attribute_name) {
                            continue;
                        }

                        $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($attribute_name) : ('pa_' . $attribute_name);
                        if (!taxonomy_exists($taxonomy) || !$this->taxonomy_has_query_string_terms($taxonomy)) {
                            continue;
                        }

                        $this->add_query_string_candidate($items, $sources, $taxonomy, 'WooCommerce product attribute taxonomy');
                        $this->add_query_string_candidate($items, $sources, 'filter_' . $attribute_name, 'WooCommerce layered nav attribute filter');
                        $this->add_query_string_candidate($items, $sources, 'query_type_' . $attribute_name, 'WooCommerce layered nav attribute query type');
                    }
                }
            }

            $items = array_keys($items);
            sort($items, SORT_NATURAL | SORT_FLAG_CASE);

            return array(
                'items'   => $items,
                'sources' => $sources,
            );
        }

        public function populate_query_string_allowlist($request = null)
        {
            unset($request);

            $candidates = $this->get_query_string_allowlist_candidates();
            $items = isset($candidates['items']) && is_array($candidates['items']) ? $candidates['items'] : array();
            $sources = isset($candidates['sources']) && is_array($candidates['sources']) ? $candidates['sources'] : array();

            return new WP_REST_Response(array(
                'items'               => $items,
                'sources'             => $sources,
                'count'               => count($items),
                'woocommerceDetected' => class_exists('WooCommerce') || function_exists('wc_get_attribute_taxonomies'),
                'message'             => count($items) ? sprintf('Detected %d taxonomy/attribute query-string keys.', count($items)) : 'No taxonomy/attribute query-string keys were detected.',
            ), 200);
        }

        public function check_permission($request = null)
        {
            unset($request);

            return current_user_can('manage_options');
        }

    }
}
