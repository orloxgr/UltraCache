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
                '/diagnostics/storage/refresh' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'refresh_storage_diagnostics'),
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
                '/external-caches/redetect' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'external_caches_redetect'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/litespeed/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'litespeed_flush'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/nginx/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'nginx_flush'),
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
                        'permission_callback' => array($this, 'check_file_mutation_permission'),
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
                        'permission_callback' => array($this, 'check_file_mutation_permission'),
                        'args'                => array(
                            'confirmation' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ),
                            'cleanupPolicy' => array(
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => array($this, 'sanitize_uninstall_cleanup_policy_param'),
                                'validate_callback' => array($this, 'validate_uninstall_cleanup_policy_param'),
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
                '/font-patterns/scan-frontpage' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'scan_frontpage_font_patterns'),
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
                '/runtime-js-scan/parse-console' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'parse_runtime_js_scan_console_errors'),
                        'permission_callback' => array($this, 'check_permission'),
                        'args'                => array(
                            'text' => array(
                                'type'              => 'string',
                                'required'          => true,
                                'sanitize_callback' => 'sanitize_textarea_field',
                            ),
                            'url'  => array(
                                'type'              => 'string',
                                'required'          => false,
                                'sanitize_callback' => 'esc_url_raw',
                            ),
                        ),
                    ),
                ),
                '/runtime-js-diagnostic-queue/start' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'runtime_js_diagnostic_queue_start'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/runtime-js-diagnostic-queue/status' => array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'runtime_js_diagnostic_queue_status'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/runtime-js-diagnostic-queue/pause' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'runtime_js_diagnostic_queue_pause'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/runtime-js-diagnostic-queue/resume' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'runtime_js_diagnostic_queue_resume'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/runtime-js-diagnostic-queue/cancel' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'runtime_js_diagnostic_queue_cancel'),
                        'permission_callback' => array($this, 'check_permission'),
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

        private function ultracache_font_scan_add_item(&$items, $value, $source = '')
        {
            $value = trim((string) $value);
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            $value = trim($value, " \t\r\n\0\x0B\"'");
            $value = preg_replace('/\s+/', ' ', $value);
            if ('' === $value) {
                return;
            }

            $lower = strtolower($value);
            $generic = array('serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial', 'unset', 'var');
            if (in_array($lower, $generic, true) || 1 === strlen($lower)) {
                return;
            }

            if (strlen($value) > 120 || $this->ultracache_font_scan_is_suspicious_family_candidate($value)) {
                return;
            }

            $items[$lower] = array(
                'value'  => $value,
                'source' => (string) $source,
            );
        }


        private function ultracache_font_scan_is_suspicious_family_candidate($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return true;
            }

            if (preg_match('/[{};()<>]/', $value)) {
                return true;
            }

            if (false !== stripos($value, 'url(') || false !== stripos($value, 'data:')) {
                return true;
            }

            $compact = preg_replace('/[\s_\-]+/', '', $value);
            if (is_string($compact) && strlen($compact) >= 48 && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
                return true;
            }

            if (strlen($value) >= 32 && preg_match('/^[A-Za-z0-9+\/=]{32,}$/', $value)) {
                return true;
            }

            return false;
        }

        private function ultracache_font_scan_add_font_family_list(&$items, $value, $source = '')
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return;
            }

            $value = preg_replace('/\b!important\b/i', '', $value);
            foreach (preg_split('/,/', $value) as $part) {
                $part = trim((string) $part);
                if ('' === $part || false !== strpos($part, 'var(')) {
                    continue;
                }
                $this->ultracache_font_scan_add_item($items, $part, $source);
            }
        }

        private function ultracache_font_scan_is_likely_icon_text($text)
        {
            $text = strtolower((string) $text);
            if ('' === $text) {
                return false;
            }

            $patterns = array(
                ' icon', '-icon', '_icon', 'icons', 'fontawesome', 'font awesome', 'font-awesome',
                'fa-solid', 'fa-regular', 'fa-brands', 'dashicons', 'eicons', 'icomoon', 'flaticon',
                'themify', 'simple-line-icons', 'linearicons', 'material-icons', 'materialicons',
                'ionicons', 'feather', 'glyphicons', 'dripicons', 'et-line', 'socicon', 'typicons',
                'woocommerce star', 'star.ttf', '/webfonts/', '/icons/', 'iconfont', 'porto-icon',
                'xstore-icons', 'woodmart-font', 'revicons', 'sr7icons', 'tinvwl-webfont'
            );

            foreach ($patterns as $pattern) {
                if (false !== strpos($text, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        private function ultracache_font_scan_basename_from_url($url)
        {
            $url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5);
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ('' === $path) {
                $path = $url;
            }
            $base = basename($path);
            $base = preg_replace('/\.(woff2?|ttf|otf|eot|svg)(?:\?.*)?$/i', '', (string) $base);
            return trim((string) $base);
        }

        private function ultracache_font_scan_parse_google_fonts_url($url, &$never_delay)
        {
            $url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5);
            if (false === stripos($url, 'fonts.googleapis.com') && false === stripos($url, 'fonts.gstatic.com') && false === stripos($url, 'google-fonts')) {
                return;
            }

            $query = (string) wp_parse_url($url, PHP_URL_QUERY);
            if ('' === $query) {
                return;
            }

            $params = array();
            parse_str($query, $params);
            if (empty($params['family'])) {
                return;
            }

            foreach ((array) $params['family'] as $family) {
                $family = preg_replace('/:.+$/', '', (string) $family);
                $family = str_replace('+', ' ', $family);
                $this->ultracache_font_scan_add_item($never_delay, $family, 'google-fonts-url');
            }
        }

        private function ultracache_font_scan_resolve_local_stylesheet_path($url)
        {
            $url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5);
            if ('' === trim($url)) {
                return '';
            }

            $absolute = wp_http_validate_url($url) ? $url : home_url('/' . ltrim($url, '/'));
            $url_host = strtolower((string) wp_parse_url($absolute, PHP_URL_HOST));
            $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ('' === $url_host || '' === $home_host || $url_host !== $home_host) {
                return '';
            }

            $candidate = function_exists('ultracache_local_path_from_public_url') ? ultracache_local_path_from_public_url($absolute, array('css')) : '';
            if ('' === $candidate || !is_readable($candidate) || !is_file($candidate)) {
                return '';
            }

            return $candidate;
        }

        private function ultracache_font_scan_collect_css_from_frontpage_html($html, $base_url, &$stylesheet_urls, &$never_delay)
        {
            $html = (string) $html;
            $css_blocks = array();
            $stylesheet_urls = array();

            if (class_exists('WP_HTML_Tag_Processor')) {
                try {
                    $style_processor = new WP_HTML_Tag_Processor($html);
                    while ($style_processor->next_tag('STYLE')) {
                        $type = strtolower($this->ultracache_html_processor_attribute($style_processor, 'type'));
                        if ('' !== $type && 'text/css' !== $type) {
                            continue;
                        }
                        $css = method_exists($style_processor, 'get_modifiable_text') ? trim((string) $style_processor->get_modifiable_text()) : '';
                        if ('' !== $css) {
                            $css_blocks[] = array('css' => $css, 'source' => 'inline-style');
                        }
                    }

                    $link_processor = new WP_HTML_Tag_Processor($html);
                    while ($link_processor->next_tag('LINK')) {
                        $rel = strtolower($this->ultracache_html_processor_attribute($link_processor, 'rel'));
                        $as = strtolower($this->ultracache_html_processor_attribute($link_processor, 'as'));
                        $href = $this->ultracache_html_processor_attribute($link_processor, 'href');
                        if ('' === $href || (false === strpos($rel, 'stylesheet') && !('preload' === $rel && 'style' === $as))) {
                            continue;
                        }

                        $absolute = wp_http_validate_url($href) ? $href : wp_make_link_relative($href);
                        if (!wp_http_validate_url($absolute)) {
                            $absolute = rtrim((string) $base_url, '/') . '/' . ltrim($href, '/');
                        }
                        $stylesheet_urls[] = $absolute;
                        $this->ultracache_font_scan_parse_google_fonts_url($absolute, $never_delay);
                    }
                } catch (\Throwable $e) {
                    // Without a valid HTML API scan, return only stylesheet CSS gathered from later local sources.
                }
            }

            $stylesheet_urls = array_values(array_unique(array_filter(array_map('strval', $stylesheet_urls))));
            foreach (array_slice($stylesheet_urls, 0, 80) as $url) {
                $path = $this->ultracache_font_scan_resolve_local_stylesheet_path($url);
                if ('' === $path) {
                    continue;
                }
                $css = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($path, 'font-css', 'font_pattern_frontpage_scan', true) : '';
                if (!is_string($css) || '' === trim($css)) {
                    continue;
                }
                $css_blocks[] = array('css' => $css, 'source' => $url);
            }

            return $css_blocks;
        }

        private function ultracache_font_scan_analyze_css($css, $source, &$delay_icons, &$never_delay)
        {
            $css = (string) $css;
            $source = (string) $source;
            if ('' === $css) {
                return;
            }

            if (preg_match_all('/font-family\s*:\s*([^;}]+)/i', $css, $families)) {
                foreach ((array) $families[1] as $family_list) {
                    $family_list_string = (string) $family_list;
                    if ($this->ultracache_font_scan_is_likely_icon_text($family_list_string)) {
                        $this->ultracache_font_scan_add_font_family_list($delay_icons, $family_list_string, $source . ' font-family');
                    } else {
                        $this->ultracache_font_scan_add_font_family_list($never_delay, $family_list_string, $source . ' font-family');
                    }
                }
            }

            if (!preg_match_all('/@font-face\s*\{.*?\}/is', $css, $blocks)) {
                return;
            }

            foreach ((array) $blocks[0] as $block) {
                $block = (string) $block;
                $family = '';
                if (preg_match('/font-family\s*:\s*([^;]+);/i', $block, $m)) {
                    $family = trim(trim((string) $m[1]), " \t\r\n\0\x0B\"'");
                }

                $src_basenames = array();
                if (preg_match_all('/url\(([^\)]+)\)/i', $block, $urls)) {
                    foreach ((array) $urls[1] as $font_url) {
                        $font_url = trim(trim((string) $font_url), " \t\r\n\0\x0B\"'");
                        $basename = $this->ultracache_font_scan_basename_from_url($font_url);
                        if ('' !== $basename) {
                            $src_basenames[] = $basename;
                        }
                    }
                }

                $combined = $source . "\n" . $block . "\n" . implode("\n", $src_basenames);
                $is_icon = $this->ultracache_font_scan_is_likely_icon_text($combined);
                if (!$is_icon && '' !== $family) {
                    $family_pattern = preg_quote($family, '/');
                    if (preg_match('/font-family\s*:\s*[^;]*' . $family_pattern . '[^;]*;[\s\S]{0,240}?content\s*:\s*[\'\"]\\\\[a-f0-9]{3,6}/i', $css)
                        || preg_match('/content\s*:\s*[\'\"]\\\\[a-f0-9]{3,6}[\s\S]{0,240}?font-family\s*:\s*[^;]*' . $family_pattern . '[^;]*;/i', $css)) {
                        $is_icon = true;
                    }
                }

                if ($is_icon) {
                    if ('' !== $family) {
                        $this->ultracache_font_scan_add_item($delay_icons, $family, $source . ' @font-face');
                    }
                    foreach ($src_basenames as $basename) {
                        $this->ultracache_font_scan_add_item($delay_icons, $basename, $source . ' font-file');
                    }
                } elseif ('' !== $family) {
                    $this->ultracache_font_scan_add_item($never_delay, $family, $source . ' @font-face');
                }
            }
        }

        private function ultracache_html_processor_attribute($processor, $attribute)
        {
            if (!$processor instanceof WP_HTML_Tag_Processor) {
                return '';
            }

            $value = $processor->get_attribute((string) $attribute);
            if (null === $value || false === $value) {
                return '';
            }
            if (true === $value) {
                return (string) $attribute;
            }

            return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        public function scan_frontpage_font_patterns($request = null)
        {
            unset($request);

            $url = home_url('/');
            $response = ultracache_safe_loopback_remote_request($url, array(
                'timeout'     => 10,
                'redirection' => 3,
                'headers'     => array(
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                    'User-Agent'    => 'UltraCache-FontPatternScanner/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : '1.0') . '; ' . home_url('/'),
                ),
            ), 'frontpage-font-pattern-scan');

            if (is_wp_error($response)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %s: WordPress HTTP API error message. */
                        __('Front page font scan failed: %s', 'ultracache'),
                        $response->get_error_message()
                    ),
                    'delayIconFontsList' => array(),
                    'delayIconFontsExcludeList' => array(),
                ), 500);
            }

            $html = (string) wp_remote_retrieve_body($response);
            if ('' === trim($html)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Front page font scan returned empty HTML.', 'ultracache'),
                    'delayIconFontsList' => array(),
                    'delayIconFontsExcludeList' => array(),
                ), 500);
            }

            $delay_icons = array();
            $never_delay = array();
            $stylesheet_urls = array();
            $css_blocks = $this->ultracache_font_scan_collect_css_from_frontpage_html($html, $url, $stylesheet_urls, $never_delay);
            foreach ($css_blocks as $entry) {
                $this->ultracache_font_scan_analyze_css((string) ($entry['css'] ?? ''), (string) ($entry['source'] ?? 'frontpage'), $delay_icons, $never_delay);
            }

            $delay_values = array_values(array_map(static function ($item) { return $item['value']; }, $delay_icons));
            $never_values = array_values(array_map(static function ($item) { return $item['value']; }, $never_delay));

            sort($delay_values, SORT_NATURAL | SORT_FLAG_CASE);
            sort($never_values, SORT_NATURAL | SORT_FLAG_CASE);
            $delay_values = array_slice(array_values(array_unique($delay_values)), 0, 80);
            $never_values = array_slice(array_values(array_unique($never_values)), 0, 80);

            return new WP_REST_Response(array(
                'success' => true,
                'url' => $url,
                'stylesheetsScanned' => count($stylesheet_urls),
                'cssBlocksScanned' => count($css_blocks),
                'delayIconFontsList' => $delay_values,
                'delayIconFontsExcludeList' => $never_values,
                'iconCount' => count($delay_values),
                'nonIconCount' => count($never_values),
                'message' => sprintf(
                    /* translators: 1: number of detected icon font patterns, 2: number of detected non-icon font patterns. */
                    __('Detected %1$d likely icon font pattern(s) and %2$d non-icon font pattern(s) on the front page.', 'ultracache'),
                    count($delay_values),
                    count($never_values)
                ),
            ), 200);
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
                'message'             => count($items) ? sprintf(
                    /* translators: %d: number of detected taxonomy or product attribute query-string keys. */
                    __('Detected %d taxonomy/attribute query-string keys.', 'ultracache'),
                    count($items)
                ) : __('No taxonomy/attribute query-string keys were detected.', 'ultracache'),
            ), 200);
        }

        public function check_permission($request = null)
        {
            unset($request);

            return current_user_can('manage_options');
        }

        public function check_file_mutation_permission($request = null)
        {
            unset($request);

            return current_user_can('manage_options') && current_user_can('activate_plugins');
        }

    }
}
