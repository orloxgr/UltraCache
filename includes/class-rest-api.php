<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Ultra_Cache_Rest_API')) {
    class Ultra_Cache_Rest_API
    {
        /** @var Ultra_Cache_Rest_API|null */
        private static $instance = null;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        public function register_routes()
        {
            $definitions = $this->get_route_definitions();
            $canonical_namespace = $this->get_canonical_namespace();
            foreach ($definitions as $route => $handlers) {
                register_rest_route($canonical_namespace, $route, $handlers);
            }
        }

        private function get_canonical_namespace()
        {
            return 'ultracache/v1';
        }

        public function sanitize_object_cache_backend_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('redis', 'apcu', 'disk'), true) ? $value : 'redis';
        }

        public function validate_object_cache_backend_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('redis', 'apcu', 'disk'), true);
        }

        public function sanitize_varnish_mode_param($value)
        {
            return ('admin' === strtolower(trim((string) $value))) ? 'admin' : 'http';
        }

        public function validate_varnish_mode_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('http', 'admin'), true);
        }

        public function sanitize_varnish_method_param($value)
        {
            return ('PURGE' === strtoupper(trim((string) $value))) ? 'PURGE' : 'BAN';
        }

        public function validate_varnish_method_param($value)
        {
            return in_array(strtoupper(trim((string) $value)), array('BAN', 'PURGE'), true);
        }

        public function sanitize_media_output_mode_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('auto', 'avif', 'webp'), true) ? $value : 'auto';
        }

        public function validate_media_output_mode_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('auto', 'avif', 'webp'), true);
        }

        public function sanitize_homepage_css_bundle_mode_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('safe', 'aggressive'), true) ? $value : 'safe';
        }

        public function validate_homepage_css_bundle_mode_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('safe', 'aggressive'), true);
        }

        public function sanitize_css_bundle_scope_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('homepage', 'shared', 'per-page'), true) ? $value : 'homepage';
        }

        public function validate_css_bundle_scope_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('homepage', 'shared', 'per-page'), true);
        }

        public function sanitize_crawl_scope_param($value)
        {
            return ('menu' === strtolower(trim((string) $value))) ? 'menu' : 'full';
        }

        public function validate_crawl_scope_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('full', 'menu'), true);
        }

        public function sanitize_url_param($value)
        {
            return esc_url_raw((string) $value);
        }

        public function validate_non_empty_url_param($value)
        {
            return '' !== trim((string) esc_url_raw((string) $value));
        }

        private function get_settings_update_args()
        {
            return array(
                'pageCacheEnabled'                     => array('type' => 'boolean', 'required' => false),
                'objectCacheEnabled'                   => array('type' => 'boolean', 'required' => false),
                'objectCacheBackend'                   => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_object_cache_backend_param'), 'validate_callback' => array($this, 'validate_object_cache_backend_param')),
                'redisHost'                            => array('type' => 'string', 'required' => false),
                'redisPort'                            => array('type' => 'integer', 'required' => false),
                'redisPassword'                        => array('type' => 'string', 'required' => false),
                'redisDatabase'                        => array('type' => 'integer', 'required' => false),
                'redisPrefix'                          => array('type' => 'string', 'required' => false),
                'redisUseTls'                          => array('type' => 'boolean', 'required' => false),
                'redisPersistent'                      => array('type' => 'boolean', 'required' => false),
                'redisConnectTimeoutMs'                => array('type' => 'integer', 'required' => false),
                'redisReadTimeoutMs'                   => array('type' => 'integer', 'required' => false),
                'brotliEnabled'                        => array('type' => 'boolean', 'required' => false),
                'gzipEnabled'                          => array('type' => 'boolean', 'required' => false),
                'cacheStatsEnabled'                    => array('type' => 'boolean', 'required' => false),
                'mediaOptimizationEnabled'           => array('type' => 'boolean', 'required' => false),
                'mediaGenerateOnUploadEnabled'        => array('type' => 'boolean', 'required' => false),
                'mediaGenerateOnDemandEnabled'        => array('type' => 'boolean', 'required' => false),
                'mediaOutputMode'                     => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_output_mode_param'), 'validate_callback' => array($this, 'validate_media_output_mode_param')),
                'deferJsEnabled'                       => array('type' => 'boolean', 'required' => false),
                'deferJsForceList'                   => array('type' => 'string', 'required' => false),
                'deferJsExcludeList'                   => array('type' => 'string', 'required' => false),
                'delayThirdPartyJsEnabled'             => array('type' => 'boolean', 'required' => false),
                'asyncExternalScriptsEnabled'          => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleEnabled'             => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleInlineEnabled'       => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleExcludeList'         => array('type' => 'string', 'required' => false),
                'homepageCssBundleMode'                => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_homepage_css_bundle_mode_param'), 'validate_callback' => array($this, 'validate_homepage_css_bundle_mode_param')),
                'cssBundleScope'                       => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_css_bundle_scope_param'), 'validate_callback' => array($this, 'validate_css_bundle_scope_param')),
                'pageCssBundleOnEntryEnabled'          => array('type' => 'boolean', 'required' => false),
                'frontendSafeModeEnabled'            => array('type' => 'boolean', 'required' => false),
                'sliderSafeModeEnabled'              => array('type' => 'boolean', 'required' => false),
                'clsDimensionsEnabled'                 => array('type' => 'boolean', 'required' => false),
                'asyncCssEnabled'                      => array('type' => 'boolean', 'required' => false),
                'asyncCssExcludeList'                  => array('type' => 'string', 'required' => false),
                'aggressiveAsyncCssEnabled'            => array('type' => 'boolean', 'required' => false),
                'aggressiveAsyncCssExcludeList'        => array('type' => 'string', 'required' => false),
                'delayNonCriticalJsEnabled'            => array('type' => 'boolean', 'required' => false),
                'delayNonCriticalJsExcludeList'        => array('type' => 'string', 'required' => false),
                'lcpImagePriorityEnabled'              => array('type' => 'boolean', 'required' => false),
                'lcpBoundaryDeferEnabled'              => array('type' => 'boolean', 'required' => false),
                'lcpImagePriorityOverride'             => array('type' => 'string', 'required' => false),
                'mainThreadReliefEnabled'              => array('type' => 'boolean', 'required' => false),
                'criticalRequestChainReliefEnabled'     => array('type' => 'boolean', 'required' => false),
                'criticalResourcePreloadList'           => array('type' => 'string', 'required' => false),
                'criticalFetchPreloadList'              => array('type' => 'string', 'required' => false),
                'criticalRequestChainDelayList'         => array('type' => 'string', 'required' => false),
                'assetChainCleanupEnabled'              => array('type' => 'boolean', 'required' => false),
                'assetCleanupWooProductAssetsEnabled'   => array('type' => 'boolean', 'required' => false),
                'assetCleanupProductFilterAssetsEnabled'=> array('type' => 'boolean', 'required' => false),
                'assetCleanupWooBlocksCssEnabled'       => array('type' => 'boolean', 'required' => false),
                'assetCleanupExcludeList'               => array('type' => 'string', 'required' => false),
                'googleFontsSwapEnabled'               => array('type' => 'boolean', 'required' => false),
                'googleFontsLocalOptimizationEnabled'  => array('type' => 'boolean', 'required' => false),
                'selfHostedFontCssOptimizationEnabled' => array('type' => 'boolean', 'required' => false),
                'selfHostedFontRuntimeRewriteEnabled'  => array('type' => 'boolean', 'required' => false),
                'speculationRulesEnabled'              => array('type' => 'boolean', 'required' => false),
                'browserCacheRulesEnabled'             => array('type' => 'boolean', 'required' => false),
                'varnishCliEnabled'                    => array('type' => 'boolean', 'required' => false),
                'varnishCliMode'                       => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_mode_param'), 'validate_callback' => array($this, 'validate_varnish_mode_param')),
                'varnishCliServers'                    => array('type' => 'string', 'required' => false),
                'varnishCliKey'                        => array('type' => 'string', 'required' => false),
                'varnishCliTimeoutSeconds'             => array('type' => 'integer', 'required' => false),
                'varnishCliMethod'                     => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_method_param'), 'validate_callback' => array($this, 'validate_varnish_method_param')),
                'varnishCliDebug'                      => array('type' => 'boolean', 'required' => false),
                'preRenderOnSave'                      => array('type' => 'boolean', 'required' => false),
                'woocommerceSafeModeEnabled'           => array('type' => 'boolean', 'required' => false),
                'cacheCleanupEnabled'                  => array('type' => 'boolean', 'required' => false),
                'apcuFlushOnScheduledCleanup'          => array('type' => 'boolean', 'required' => false),
                'cronWarmEnabled'                      => array('type' => 'boolean', 'required' => false),
                'cronWarmStartAfterCleanup'            => array('type' => 'boolean', 'required' => false),
                'cronWarmStartAfterManualPurge'        => array('type' => 'boolean', 'required' => false),
                'cacheCleanupIntervalHours'            => array('type' => 'integer', 'required' => false),
                'cronWarmPagesPerMinute'               => array('type' => 'integer', 'required' => false),
                'scheduledWarmLimit'                   => array('type' => 'integer', 'required' => false),
                'staleWhileRevalidateEnabled'          => array('type' => 'boolean', 'required' => false),
                'cacheFreshTtlMinutes'                 => array('type' => 'integer', 'required' => false),
                'cacheMaxStaleMinutes'                 => array('type' => 'integer', 'required' => false),
                'cacheExceptionPaths'                  => array('type' => 'string', 'required' => false),
                'cacheExceptionQueryArgs'              => array('type' => 'string', 'required' => false),
                'cacheQueryStringsEnabled'             => array('type' => 'boolean', 'required' => false),
                'cacheQueryStringAllowlist'            => array('type' => 'string', 'required' => false),
            );
        }

        private function get_redis_test_args()
        {
            return array(
                'redisHost'             => array('type' => 'string', 'required' => false),
                'redisPort'             => array('type' => 'integer', 'required' => false),
                'redisPassword'         => array('type' => 'string', 'required' => false),
                'redisPasswordConfigured' => array('type' => 'boolean', 'required' => false),
                'redisDatabase'         => array('type' => 'integer', 'required' => false),
                'redisPrefix'           => array('type' => 'string', 'required' => false),
                'redisUseTls'           => array('type' => 'boolean', 'required' => false),
                'redisPersistent'       => array('type' => 'boolean', 'required' => false),
                'redisConnectTimeoutMs' => array('type' => 'integer', 'required' => false),
                'redisReadTimeoutMs'    => array('type' => 'integer', 'required' => false),
            );
        }

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
                '/object-cache/flush' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'object_cache_flush'),
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
                '/warm-homepage-google-fonts' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'warm_homepage_google_fonts'),
                        'permission_callback' => array($this, 'check_permission'),
                    ),
                ),
                '/warm-menu-google-fonts' => array(
                    array(
                        'methods'             => WP_REST_Server::CREATABLE,
                        'callback'            => array($this, 'warm_menu_google_fonts'),
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

        private function get_query_string_allowlist_candidates()
        {
            $items = array();
            $sources = array();

            $common = array(
                'swoof'           => 'Common WooCommerce filter key',
                'pa_translations' => 'Common WooCommerce attribute/filter key',
                'product_author'  => 'Common product taxonomy/filter key',
                'product_cat'     => 'WooCommerce product category key',
                'product_tag'     => 'WooCommerce product tag key',
                'product_genre'   => 'Common product taxonomy/filter key',
                'pa_series'       => 'Common WooCommerce attribute/filter key',
                'group_by_series' => 'Common product grouping key',
                'pa_format'       => 'Common WooCommerce attribute/filter key',
            );

            foreach ($common as $key => $source) {
                $this->add_query_string_candidate($items, $sources, $key, $source);
            }

            if (taxonomy_exists('product_cat')) {
                $this->add_query_string_candidate($items, $sources, 'product_cat', 'WooCommerce product category taxonomy');
            }
            if (taxonomy_exists('product_tag')) {
                $this->add_query_string_candidate($items, $sources, 'product_tag', 'WooCommerce product tag taxonomy');
            }

            $product_taxonomies = get_object_taxonomies('product', 'objects');
            if (is_array($product_taxonomies)) {
                foreach ($product_taxonomies as $taxonomy => $taxonomy_object) {
                    if (!is_object($taxonomy_object)) {
                        continue;
                    }

                    $is_publicish = !empty($taxonomy_object->public) || !empty($taxonomy_object->publicly_queryable) || !empty($taxonomy_object->show_ui);
                    if (!$is_publicish) {
                        continue;
                    }

                    $this->add_query_string_candidate($items, $sources, $taxonomy, 'Product taxonomy');

                    if (isset($taxonomy_object->query_var) && is_string($taxonomy_object->query_var) && '' !== $taxonomy_object->query_var && $taxonomy_object->query_var !== $taxonomy) {
                        $this->add_query_string_candidate($items, $sources, $taxonomy_object->query_var, 'Product taxonomy query var');
                    }
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
                        $this->add_query_string_candidate($items, $sources, $taxonomy, 'WooCommerce product attribute taxonomy');
                        $this->add_query_string_candidate($items, $sources, 'filter_' . $attribute_name, 'WooCommerce layered nav filter key');
                        $this->add_query_string_candidate($items, $sources, 'query_type_' . $attribute_name, 'WooCommerce layered nav query type key');
                    }
                }
            }

            return array(
                'items'   => array_keys($items),
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
                'message'             => count($items) ? sprintf('Detected %d likely query-string keys.', count($items)) : 'No query-string keys were detected.',
            ), 200);
        }

        public function check_permission($request = null)
        {
            unset($request);

            return current_user_can('manage_options');
        }

        public function get_stats()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                return new WP_REST_Response(Ultra_Cache_WP::get_engine_stats(), 200);
            }

            return new WP_REST_Response($this->resolve_engine_stats(), 200);
        }

        public function purge_all()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'purge_all')) {
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'message' => 'Cache engine not available.',
                    ),
                    500
                );
            }

            $success = (bool) $engine->purge_all();
            $response = array('success' => $success);

            if ($success && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'maybe_start_cron_warmup_after_purge')) {
                $response['cronWarm'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_cron_warm_status') ? Ultra_Cache_WP::get_cron_warm_status() : array();
            }

            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats')) {
                $response['stats'] = Ultra_Cache_WP::get_engine_stats();
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                $response['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
            }

            return new WP_REST_Response($response, $success ? 200 : 500);
        }

        public function get_settings()
        {
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
                return new WP_REST_Response(Ultra_Cache_WP::get_dashboard_settings_for_client(), 200);
            }

            $settings = get_option(UCWP_SETTINGS_KEY, array());
            return new WP_REST_Response(is_array($settings) ? array_diff_key($settings, array_flip(array('redisPassword', 'varnishCliKey'))) : array(), 200);
        }

        public function update_settings(WP_REST_Request $request)
        {
            $allowed_keys = array_keys($this->get_settings_update_args());

            $current = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')
                ? Ultra_Cache_WP::get_dashboard_settings()
                : (array) get_option(UCWP_SETTINGS_KEY, array());

            foreach ($allowed_keys as $key) {
                if (null !== $request->get_param($key)) {
                    $current[$key] = $request->get_param($key);
                }
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'persist_dashboard_settings')) {
                $response = Ultra_Cache_WP::persist_dashboard_settings($current);
                if (is_wp_error($response)) {
                    return new WP_REST_Response(array('success' => false, 'message' => $response->get_error_message()), 500);
                }

                if (!empty($response['settings']) && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
                    $response['settings'] = Ultra_Cache_WP::get_dashboard_settings_for_client();
                }

                return new WP_REST_Response($response, 200);
            }

            update_option(UCWP_SETTINGS_KEY, $current);
            $client_settings = is_array($current) ? array_diff_key($current, array_flip(array('redisPassword', 'varnishCliKey'))) : array();
            return new WP_REST_Response(array('success' => true, 'settings' => $client_settings), 200);
        }


        public function delete_all_plugin_data(WP_REST_Request $request)
        {
            $confirmation = strtoupper(trim((string) $request->get_param('confirmation')));
            if ('DELETE' !== $confirmation) {
                return new WP_REST_Response(
                    array(
                        'success' => false,
                        'message' => 'Confirmation failed. Type DELETE to remove UltraCache data and deactivate the plugin.',
                    ),
                    400
                );
            }

            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'delete_all_plugin_data_and_deactivate')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cleanup helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::delete_all_plugin_data_and_deactivate();
            if (is_wp_error($result)) {
                return new WP_REST_Response(array('success' => false, 'message' => $result->get_error_message()), 500);
            }

            return new WP_REST_Response($result, 200);
        }

        public function cron_warm_start()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'start_cron_warmup_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::start_cron_warmup_queue('manual', false);
            if (!empty($result['state'])) {
                $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
                $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            }
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function cron_warm_stop()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'stop_cron_warmup_queue')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::stop_cron_warmup_queue('manual');
            $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
            $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function cron_warm_tick(WP_REST_Request $request)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'run_cron_warm_tick')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cron warm helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::run_cron_warm_tick(array(
                'invokedBy' => 'rest',
                'pagesPerMinute' => null !== $request->get_param('pagesPerMinute') ? absint($request->get_param('pagesPerMinute')) : null,
            ));
            $result['diagnostics'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics') ? Ultra_Cache_WP::get_dashboard_diagnostics() : array();
            $result['stats'] = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_engine_stats') ? Ultra_Cache_WP::get_engine_stats() : array();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }


        public function varnish_test()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'varnish_test_connection')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Varnish helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::varnish_test_connection();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function varnish_flush_all()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'varnish_flush_all_current_host')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Varnish helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::varnish_flush_all_current_host();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function opcache_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_opcache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'OPcache helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_opcache();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function apcu_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_apcu')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'APCu helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_apcu();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function redis_test(WP_REST_Request $request)
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'test_redis_connection')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Redis helper not available.'), 500);
            }

            $settings = array();
            foreach (array('redisHost', 'redisPort', 'redisPassword', 'redisDatabase', 'redisPrefix', 'redisUseTls', 'redisPersistent', 'redisConnectTimeoutMs', 'redisReadTimeoutMs') as $key) {
                if (null !== $request->get_param($key)) {
                    $settings[$key] = $request->get_param($key);
                }
            }

            if (array_key_exists('redisPassword', $settings) && '' === trim((string) $settings['redisPassword']) && !empty($request->get_param('redisPasswordConfigured'))) {
                unset($settings['redisPassword']);
            }

            $result = Ultra_Cache_WP::test_redis_connection($settings);
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function object_cache_flush()
        {
            if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'flush_object_cache')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Object cache helper not available.'), 500);
            }

            $result = Ultra_Cache_WP::flush_object_cache();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }


        public function object_cache_full_count()
        {
            $stats = $this->resolve_engine_stats(true);

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Full object-cache count completed.',
                'stats' => $stats,
            ), 200);
        }


        private function is_dashboard_setting_enabled($key)
        {
            $settings = defined('UCWP_SETTINGS_KEY') ? get_option(UCWP_SETTINGS_KEY, array()) : array();
            return is_array($settings) && !empty($settings[$key]);
        }

        private function guard_page_cache_enabled()
        {
            if (!$this->is_dashboard_setting_enabled('pageCacheEnabled')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Please enable Page Caching first or select a profile before warming cache.',
                ), 400);
            }

            return null;
        }

        private function guard_css_bundle_enabled()
        {
            if (!$this->is_dashboard_setting_enabled('homepageCssBundleEnabled')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Please enable CSS Bundling before using CSS bundle actions.',
                ), 400);
            }

            return null;
        }

        private function guard_google_fonts_local_enabled()
        {
            if (!$this->is_dashboard_setting_enabled('googleFontsLocalOptimizationEnabled')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Enable Local Google Fonts Optimization before warming Google Fonts.',
                ), 400);
            }

            return null;
        }

        public function get_urls(WP_REST_Request $request)
        {
            $engine = $this->get_engine();
            $offset = max(0, absint($request->get_param('offset')));
            $cursor = (string) $request->get_param('cursor');
            $limit = max(1, min(500, absint($request->get_param('limit')) ?: 100));
            $scope = sanitize_text_field((string) $request->get_param('scope'));

            if (!$engine || !method_exists($engine, 'get_crawl_urls')) {
                return new WP_REST_Response($this->format_batch_response(array(), 0, $offset, $limit), 200);
            }

            if (method_exists($engine, 'get_crawl_urls_cursor_batch')) {
                return new WP_REST_Response($engine->get_crawl_urls_cursor_batch($cursor, $limit, $scope), 200);
            }

            if (method_exists($engine, 'get_crawl_urls_batch')) {
                return new WP_REST_Response($engine->get_crawl_urls_batch($offset, $limit, $scope), 200);
            }

            $all_urls = (array) $engine->get_crawl_urls($scope);
            return new WP_REST_Response($this->format_batch_response($all_urls, count($all_urls), $offset, $limit), 200);
        }

        public function crawl_page(WP_REST_Request $request)
        {
            $url = esc_url_raw((string) $request->get_param('url'));
            if ('' === $url) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No URL provided.'), 400);
            }

            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            if ((bool) $request->get_param('buildCssBundle')) {
                $guard = $this->guard_css_bundle_enabled();
                if ($guard instanceof WP_REST_Response) {
                    return $guard;
                }
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_url') || !method_exists($engine, 'is_cacheable_local_url')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            if (!$engine->is_cacheable_local_url($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Only local site URLs can be crawled.'), 400);
            }

            $result = $engine->warm_url($url, array('build_css_bundle' => (bool) $request->get_param('buildCssBundle')));
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function inspect_url(WP_REST_Request $request)
        {
            $url = (string) $request->get_param('url');
            if ('' === trim($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No URL provided.'), 400);
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'inspect_url')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            return new WP_REST_Response($engine->inspect_url($url), 200);
        }

        public function build_frontpage_css()
        {
            $guard = $this->guard_css_bundle_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'build_frontpage_css_bundle')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->build_frontpage_css_bundle();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function warm_frontpage_html()
        {
            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_frontpage_html();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function warm_frontpage_html_css()
        {
            $guard = $this->guard_page_cache_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $guard = $this->guard_css_bundle_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html_with_css')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_frontpage_html_with_css();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function warm_homepage_google_fonts()
        {
            $guard = $this->guard_google_fonts_local_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_homepage_google_fonts')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_homepage_google_fonts();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function warm_menu_google_fonts()
        {
            $guard = $this->guard_google_fonts_local_enabled();
            if ($guard instanceof WP_REST_Response) {
                return $guard;
            }

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_menu_google_fonts')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_menu_google_fonts();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function get_media_ids(WP_REST_Request $request)
        {
            $media = $this->get_media();
            $offset = max(0, absint($request->get_param('offset')));
            $limit = max(1, min(500, absint($request->get_param('limit')) ?: 100));

            if (!$media || !method_exists($media, 'get_all_media_ids')) {
                return new WP_REST_Response($this->format_batch_response(array(), 0, $offset, $limit), 200);
            }

            if (method_exists($media, 'get_media_ids_batch')) {
                return new WP_REST_Response($media->get_media_ids_batch($offset, $limit), 200);
            }

            $all_ids = (array) $media->get_all_media_ids();
            return new WP_REST_Response($this->format_batch_response($all_ids, count($all_ids), $offset, $limit), 200);
        }

        public function optimize_id(WP_REST_Request $request)
        {
            $attachment_id = absint($request->get_param('id'));
            if ($attachment_id <= 0) {
                return new WP_REST_Response(array('success' => false, 'message' => 'No valid media ID.'), 400);
            }

            $media = $this->get_media();
            if (!$media || !method_exists($media, 'to_avif_by_id')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            $converted = (bool) $media->to_avif_by_id($attachment_id);
            return new WP_REST_Response(array('success' => true, 'converted' => $converted), 200);
        }

        public function optimize_media()
        {
            $media = $this->get_media();
            if (!$media || !method_exists($media, 'bulk_optimize')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Media converter not available.'), 500);
            }

            $media->bulk_optimize();
            return new WP_REST_Response(array('success' => true), 200);
        }

        private function get_action_queue_option_key()
        {
            return defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_action_jobs' : 'ucwp_settings_action_jobs';
        }

        private function get_allowed_action_queue_actions()
        {
            return array(
                'purge_all',
                'object_cache_flush',
                'object_cache_full_count',
                'warm_frontpage_html',
                'warm_frontpage_html_css',
                'warm_homepage_google_fonts',
                'warm_menu_google_fonts',
                'varnish_test',
                'varnish_flush_all',
                'opcache_flush',
                'apcu_flush',
                'redis_test',
            );
        }

        private function get_heavy_action_queue_actions()
        {
            return array(
                'purge_all',
                'object_cache_flush',
                'object_cache_full_count',
                'warm_frontpage_html',
                'warm_frontpage_html_css',
                'warm_homepage_google_fonts',
                'warm_menu_google_fonts',
                'varnish_flush_all',
            );
        }

        private function is_heavy_action_queue_action($action)
        {
            return in_array((string) $action, $this->get_heavy_action_queue_actions(), true);
        }

        private function get_action_queue_stale_seconds()
        {
            return 180;
        }

        private function get_action_queue_lock_option_key()
        {
            return defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY . '_action_queue_heavy_lock' : 'ucwp_settings_action_queue_heavy_lock';
        }

        private function normalize_action_jobs(array $jobs)
        {
            $now = time();
            $stale_after = $this->get_action_queue_stale_seconds();
            foreach ($jobs as $id => $job) {
                if (!is_array($job)) {
                    unset($jobs[$id]);
                    continue;
                }

                $status = (string) ($job['status'] ?? 'queued');
                $created = isset($job['createdAt']) ? (int) $job['createdAt'] : $now;
                $started = isset($job['startedAt']) ? (int) $job['startedAt'] : 0;
                $updated = isset($job['updatedAt']) ? (int) $job['updatedAt'] : 0;
                $finished = isset($job['finishedAt']) ? (int) $job['finishedAt'] : 0;
                $age_base = max($started, $updated, $created);

                if (in_array($status, array('queued', 'running'), true) && $age_base > 0 && ($now - $age_base) > $stale_after) {
                    $job['status'] = 'failed';
                    $job['message'] = 'Dashboard processing action was marked stale and stopped from blocking new work.';
                    $job['finishedAt'] = $now;
                    $job['updatedAt'] = $now;
                    $jobs[$id] = $job;
                    $status = 'failed';
                    $finished = $now;
                }

                $terminal = in_array($status, array('done', 'failed'), true);
                if (($terminal && $finished > 0 && ($now - $finished) > HOUR_IN_SECONDS) || (!$terminal && ($now - $created) > 6 * HOUR_IN_SECONDS)) {
                    unset($jobs[$id]);
                }
            }

            if (count($jobs) > 20) {
                uasort($jobs, static function ($a, $b) {
                    $a_time = is_array($a) ? (int) ($a['updatedAt'] ?? $a['createdAt'] ?? 0) : 0;
                    $b_time = is_array($b) ? (int) ($b['updatedAt'] ?? $b['createdAt'] ?? 0) : 0;
                    return $b_time <=> $a_time;
                });
                $jobs = array_slice($jobs, 0, 20, true);
            }

            return $jobs;
        }

        private function load_action_jobs()
        {
            $jobs = get_option($this->get_action_queue_option_key(), array());
            $jobs = is_array($jobs) ? $jobs : array();
            $normalized = $this->normalize_action_jobs($jobs);
            if ($normalized !== $jobs) {
                update_option($this->get_action_queue_option_key(), $normalized, false);
            }
            return $normalized;
        }

        private function save_action_jobs(array $jobs)
        {
            update_option($this->get_action_queue_option_key(), $this->normalize_action_jobs($jobs), false);
        }

        private function find_active_heavy_action_job(array $jobs, $exclude_id = '')
        {
            foreach ($jobs as $id => $job) {
                if ((string) $id === (string) $exclude_id || !is_array($job)) {
                    continue;
                }
                $status = (string) ($job['status'] ?? '');
                $action = (string) ($job['action'] ?? '');
                if ($this->is_heavy_action_queue_action($action) && in_array($status, array('queued', 'running'), true)) {
                    return $job;
                }
            }

            return array();
        }

        private function acquire_action_queue_heavy_lock($action, $job_id)
        {
            $action = sanitize_key((string) $action);
            $job_id = sanitize_text_field((string) $job_id);
            $now = time();
            $key = $this->get_action_queue_lock_option_key();
            $payload = array(
                'action' => $action,
                'jobId'  => $job_id,
                'time'   => $now,
            );

            if (add_option($key, $payload, '', false)) {
                return true;
            }

            $existing = get_option($key, array());
            $existing_time = is_array($existing) ? (int) ($existing['time'] ?? 0) : 0;
            if ($existing_time > 0 && ($now - $existing_time) > $this->get_action_queue_stale_seconds()) {
                delete_option($key);
                return add_option($key, $payload, '', false);
            }

            return false;
        }

        private function release_action_queue_heavy_lock($job_id)
        {
            $key = $this->get_action_queue_lock_option_key();
            $existing = get_option($key, array());
            if (is_array($existing) && (string) ($existing['jobId'] ?? '') === (string) $job_id) {
                delete_option($key);
            }
        }

        private function normalize_action_params($params)
        {
            if (!is_array($params)) {
                return array();
            }

            $normalized = array();
            foreach ($params as $key => $value) {
                $key = sanitize_key((string) $key);
                if ('' === $key) {
                    continue;
                }
                if (is_scalar($value) || null === $value) {
                    $normalized[$key] = is_string($value) ? sanitize_text_field($value) : $value;
                }
            }

            return $normalized;
        }

        public function enqueue_action_job(WP_REST_Request $request)
        {
            $action = sanitize_key((string) $request->get_param('action'));
            if (!in_array($action, $this->get_allowed_action_queue_actions(), true)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Unsupported dashboard processing action.'), 400);
            }

            $params = $this->normalize_action_params($request->get_param('params'));
            $id = 'ucwp_' . wp_generate_password(18, false, false);
            $now = time();
            $job = array(
                'id'        => $id,
                'action'    => $action,
                'params'    => $params,
                'status'    => 'queued',
                'message'   => 'Waiting for dashboard processing.',
                'createdAt' => $now,
                'updatedAt' => $now,
            );

            $jobs = $this->load_action_jobs();
            if ($this->is_heavy_action_queue_action($action)) {
                $active = $this->find_active_heavy_action_job($jobs);
                if (!empty($active)) {
                    $job['status'] = 'failed';
                    $job['message'] = 'Another heavy dashboard action is already running: ' . (string) ($active['action'] ?? 'unknown') . '.';
                    $job['finishedAt'] = $now;
                    $job['updatedAt'] = $now;
                    $jobs[$id] = $job;
                    $this->save_action_jobs($jobs);

                    return new WP_REST_Response(array('success' => true, 'job' => $job), 202);
                }
            }
            $jobs[$id] = $job;
            $this->save_action_jobs($jobs);

            return new WP_REST_Response(array('success' => true, 'job' => $job), 202);
        }

        public function get_action_job(WP_REST_Request $request)
        {
            $id = sanitize_text_field((string) $request->get_param('id'));
            $jobs = $this->load_action_jobs();
            if (empty($jobs[$id]) || !is_array($jobs[$id])) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Dashboard processing action not found.'), 404);
            }

            $job = $jobs[$id];
            $status = (string) ($job['status'] ?? 'queued');
            if ('queued' === $status) {
                $action = (string) ($job['action'] ?? '');
                if ($this->is_heavy_action_queue_action($action)) {
                    $active = $this->find_active_heavy_action_job($jobs, $id);
                    if (!empty($active)) {
                        $job['status'] = 'failed';
                        $job['message'] = 'Another heavy dashboard action is already running: ' . (string) ($active['action'] ?? 'unknown') . '.';
                        $job['finishedAt'] = time();
                        $job['updatedAt'] = time();
                        $jobs[$id] = $job;
                        $this->save_action_jobs($jobs);
                        return new WP_REST_Response(array('success' => true, 'job' => $job), 200);
                    }
                    if (!$this->acquire_action_queue_heavy_lock($action, $id)) {
                        $job['status'] = 'failed';
                        $job['message'] = 'Another heavy dashboard action lock is active. Try again shortly.';
                        $job['finishedAt'] = time();
                        $job['updatedAt'] = time();
                        $jobs[$id] = $job;
                        $this->save_action_jobs($jobs);
                        return new WP_REST_Response(array('success' => true, 'job' => $job), 200);
                    }
                }

                $job['status'] = 'running';
                $job['message'] = 'Processing via dashboard.';
                $job['startedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);

                try {
                    $result = $this->run_action_queue_job($action, is_array($job['params'] ?? null) ? $job['params'] : array());
                } finally {
                    if ($this->is_heavy_action_queue_action($action)) {
                        $this->release_action_queue_heavy_lock($id);
                    }
                }
                $ok = !empty($result['success']) || !empty($result['skipped']);
                $job['status'] = $ok ? 'done' : 'failed';
                $job['message'] = !empty($result['message']) ? (string) $result['message'] : ($ok ? 'Completed.' : 'Failed.');
                $job['result'] = $result;
                $job['finishedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);
            } elseif ('running' === $status && !empty($job['startedAt']) && (time() - (int) $job['startedAt']) > 300) {
                $job['status'] = 'failed';
                $job['message'] = 'Dashboard processing action timed out.';
                $job['finishedAt'] = time();
                $job['updatedAt'] = time();
                $jobs[$id] = $job;
                $this->save_action_jobs($jobs);
            }

            return new WP_REST_Response(array('success' => true, 'job' => $job), 200);
        }

        private function run_action_queue_job($action, array $params)
        {
            try {
                switch ($action) {
                    case 'purge_all':
                        return $this->unwrap_rest_payload($this->purge_all());
                    case 'object_cache_flush':
                        return $this->unwrap_rest_payload($this->object_cache_flush());
                    case 'object_cache_full_count':
                        return $this->unwrap_rest_payload($this->object_cache_full_count());
                    case 'warm_frontpage_html':
                        return $this->unwrap_rest_payload($this->warm_frontpage_html());
                    case 'warm_frontpage_html_css':
                        return $this->unwrap_rest_payload($this->warm_frontpage_html_css());
                    case 'warm_homepage_google_fonts':
                        return $this->unwrap_rest_payload($this->warm_homepage_google_fonts());
                    case 'warm_menu_google_fonts':
                        return $this->unwrap_rest_payload($this->warm_menu_google_fonts());
                    case 'varnish_test':
                        return $this->unwrap_rest_payload($this->varnish_test());
                    case 'varnish_flush_all':
                        return $this->unwrap_rest_payload($this->varnish_flush_all());
                    case 'opcache_flush':
                        return $this->unwrap_rest_payload($this->opcache_flush());
                    case 'apcu_flush':
                        return $this->unwrap_rest_payload($this->apcu_flush());
                    case 'redis_test':
                        $redis_request = new WP_REST_Request('POST', '/');
                        foreach ($params as $key => $value) {
                            $redis_request->set_param($key, $value);
                        }
                        return $this->unwrap_rest_payload($this->redis_test($redis_request));
                }
            } catch (Throwable $error) {
                return array('success' => false, 'message' => $error->getMessage());
            } catch (Exception $error) {
                return array('success' => false, 'message' => $error->getMessage());
            }

            return array('success' => false, 'message' => 'Unsupported dashboard processing action.');
        }

        private function unwrap_rest_payload($response)
        {
            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            if ($response instanceof WP_REST_Response) {
                $data = $response->get_data();
                $status = (int) $response->get_status();
                $payload = is_array($data) ? $data : array('data' => $data);
                if (!array_key_exists('success', $payload)) {
                    $payload['success'] = $status >= 200 && $status < 300;
                }
                return $payload;
            }

            return is_array($response) ? $response : array('success' => (bool) $response);
        }

        private function get_engine()
        {
            foreach (array('Ultra_Cache_Engine') as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return call_user_func(array($class, 'get_instance'));
                }
            }

            return null;
        }

        private function get_media()
        {
            foreach (array('Ultra_Cache_Media_Converter') as $class) {
                if (class_exists($class) && method_exists($class, 'get_instance')) {
                    return call_user_func(array($class, 'get_instance'));
                }
            }

            return null;
        }


        private function format_batch_response(array $items, $total, $offset, $limit)
        {
            $offset = max(0, (int) $offset);
            $limit = max(1, min(500, (int) $limit));
            $total = max(0, (int) $total);
            $sliced = array_values(array_slice($items, $offset, $limit));
            $next_offset = min($total, $offset + count($sliced));

            return array(
                'items'      => $sliced,
                'total'      => $total,
                'offset'     => $offset,
                'limit'      => $limit,
                'nextOffset' => $next_offset,
                'hasMore'    => $next_offset < $total,
            );
        }

        private function resolve_engine_stats($full_object_count = false)
        {
            $stats = array();
            $engine = $this->get_engine();
            if ($engine && method_exists($engine, 'get_stats')) {
                $stats = $engine::get_stats();
                $stats = is_array($stats) ? $stats : array();
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
                $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats((bool) $full_object_count);
                if (is_array($object_stats)) {
                    $stats = array_merge($stats, $object_stats);
                    $stats['cacheSizeBytes'] = (int) ($stats['cacheSizeBytes'] ?? 0) + (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                    if (function_exists('size_format')) {
                        $stats['cacheSizeHuman'] = size_format((int) $stats['cacheSizeBytes'], 2);
                    }
                }
            }

            if (class_exists('Ultra_Cache_WP')) {
                if (method_exists('Ultra_Cache_WP', 'get_opcache_status_summary')) {
                    $stats['opcache'] = Ultra_Cache_WP::get_opcache_status_summary();
                }
                if (method_exists('Ultra_Cache_WP', 'get_apcu_status_summary')) {
                    $stats['apcu'] = Ultra_Cache_WP::get_apcu_status_summary();
                }
            }

            return $stats;
        }
    }
}

if (!class_exists('Ultra_Cache_REST_API') && class_exists('Ultra_Cache_Rest_API')) {
    class_alias('Ultra_Cache_Rest_API', 'Ultra_Cache_REST_API');
}

