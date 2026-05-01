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
            return in_array($value, array('safe', 'aggressive', 'full'), true) ? $value : 'safe';
        }

        public function validate_homepage_css_bundle_mode_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('safe', 'aggressive', 'full'), true);
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
                'deferAllJsEnabled'                    => array('type' => 'boolean', 'required' => false),
                'deferJsForceList'                   => array('type' => 'string', 'required' => false),
                'deferJsExcludeList'                   => array('type' => 'string', 'required' => false),
                'delaySafeThirdPartyJsEnabled'             => array('type' => 'boolean', 'required' => false),
                'delaySafeThirdPartyJsPatterns'       => array('type' => 'string', 'required' => false),
                'delayFunctionalThirdPartyJsEnabled'  => array('type' => 'boolean', 'required' => false),
                'delayFunctionalThirdPartyJsPatterns' => array('type' => 'string', 'required' => false),
                'delayThirdPartyJsExcludeList'        => array('type' => 'string', 'required' => false),
                'asyncExternalScriptsEnabled'          => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleEnabled'             => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleInlineEnabled'       => array('type' => 'boolean', 'required' => false),
                'leftoverCssBundleEnabled'           => array('type' => 'boolean', 'required' => false),
                'homepageCssBundleExcludeList'         => array('type' => 'string', 'required' => false),
                'homepageCssBundleMode'                => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_homepage_css_bundle_mode_param'), 'validate_callback' => array($this, 'validate_homepage_css_bundle_mode_param')),
                'delayIconFontsEnabled'                => array('type' => 'boolean', 'required' => false),
                'delayIconFontsAutoDetectEnabled'      => array('type' => 'boolean', 'required' => false),
                'delayIconFontsList'                   => array('type' => 'string', 'required' => false),
                'delayIconFontsExcludeList'            => array('type' => 'string', 'required' => false),
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
                'manualLcpHeroSelector'                => array('type' => 'string', 'required' => false),
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
                'googleFontsAdditionalScanUrls'       => array('type' => 'string', 'required' => false),
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
                'varnish_test',
                'varnish_flush_all',
                'opcache_flush',
                'apcu_flush',
                'redis_test',
                'google_fonts_rebuild_cache',
                'performance_profile',
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
                'varnish_flush_all',
                'google_fonts_rebuild_cache',
                'performance_profile',
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
                    case 'google_fonts_rebuild_cache':
                        $engine = $this->get_engine();
                        if (!$engine || !method_exists($engine, 'rebuild_google_fonts_cache_from_scan_urls')) {
                            return array('success' => false, 'message' => 'Google Fonts rebuild helper is not available.');
                        }
                        $result = $engine->rebuild_google_fonts_cache_from_scan_urls(array(), !empty($params['clear']), 'dashboard');
                        if (is_array($result) && class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_diagnostics')) {
                            $result['diagnostics'] = Ultra_Cache_WP::get_dashboard_diagnostics();
                        }
                        return $result;
                    case 'performance_profile':
                        return $this->run_performance_profile_job($params);
                }
            } catch (Throwable $error) {
                return array('success' => false, 'message' => $error->getMessage());
            } catch (Exception $error) {
                return array('success' => false, 'message' => $error->getMessage());
            }

            return array('success' => false, 'message' => 'Unsupported dashboard processing action.');
        }

        private function normalize_performance_profile_mode($mode)
        {
            $mode = sanitize_key((string) $mode);
            return in_array($mode, array('compact', 'verbose', 'callback'), true) ? $mode : 'compact';
        }

        private function normalize_performance_profile_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                $url = home_url('/');
            }

            if (0 === strpos($url, '/')) {
                $url = home_url($url);
            }

            $url = esc_url_raw($url);
            $parts = wp_parse_url($url);
            $home_parts = wp_parse_url(home_url('/'));
            $home_host = isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '';
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

            if ('' === $home_host || '' === $host || $host !== $home_host) {
                return new WP_Error('ucwp_profile_url_not_allowed', 'Only same-site URLs can be scanned.');
            }

            $scheme = isset($parts['scheme']) && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true) ? strtolower((string) $parts['scheme']) : '';
            if ('' === $scheme) {
                $scheme = isset($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https';
            }

            $path = isset($parts['path']) ? (string) $parts['path'] : '/';
            if ('' === $path) {
                $path = '/';
            }

            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $query = isset($parts['query']) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

            return $scheme . '://' . $host . $port . $path . $query;
        }

        private function summarize_performance_profile($profile)
        {
            if (!is_array($profile) || empty($profile)) {
                return array();
            }

            $request_profile = isset($profile['request_profile']) && is_array($profile['request_profile']) ? $profile['request_profile'] : array();
            $checkpoints = isset($request_profile['checkpoints']) && is_array($request_profile['checkpoints']) ? $request_profile['checkpoints'] : array();
            $callbacks = isset($request_profile['callback_timing_summary']) && is_array($request_profile['callback_timing_summary']) ? $request_profile['callback_timing_summary'] : array();
            $callback_timings = isset($request_profile['callback_timings']) && is_array($request_profile['callback_timings']) ? $request_profile['callback_timings'] : array();
            $slow_checkpoints = array();
            foreach ($checkpoints as $checkpoint) {
                if (!is_array($checkpoint)) {
                    continue;
                }
                $delta = isset($checkpoint['since_previous_ms']) ? (int) $checkpoint['since_previous_ms'] : 0;
                if ($delta < 200) {
                    continue;
                }
                $slow_checkpoints[] = array(
                    'stage'    => isset($checkpoint['stage']) ? (string) $checkpoint['stage'] : '',
                    'deltaMs'  => $delta,
                    'atMs'     => isset($checkpoint['at_ms']) ? (int) $checkpoint['at_ms'] : 0,
                    'hook'     => isset($checkpoint['hook']) ? (string) $checkpoint['hook'] : '',
                    'reason'   => isset($checkpoint['reason']) ? (string) $checkpoint['reason'] : '',
                    'source'   => isset($checkpoint['source']) ? (string) $checkpoint['source'] : '',
                    'callback' => isset($checkpoint['callback_label']) ? (string) $checkpoint['callback_label'] : (isset($checkpoint['callback']) ? (string) $checkpoint['callback'] : ''),
                    'origin'   => isset($checkpoint['origin_name']) ? (string) $checkpoint['origin_name'] : '',
                );
            }
            usort($slow_checkpoints, function ($a, $b) {
                return (int) ($b['deltaMs'] ?? 0) <=> (int) ($a['deltaMs'] ?? 0);
            });

            $callback_top = array();
            $origin_totals = array();
            foreach ($callbacks as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $origin_type = isset($entry['origin_type']) ? (string) $entry['origin_type'] : 'unknown';
                $origin_name = isset($entry['origin_name']) ? (string) $entry['origin_name'] : 'unknown';
                $origin_key = trim($origin_type . ':' . $origin_name, ':');
                if ('' === $origin_key) {
                    $origin_key = 'unknown:unknown';
                    $origin_type = 'unknown';
                    $origin_name = 'unknown';
                }

                $total_ms = isset($entry['total_ms']) ? (int) $entry['total_ms'] : 0;
                $max_ms = isset($entry['max_ms']) ? (int) $entry['max_ms'] : 0;
                $count = isset($entry['count']) ? (int) $entry['count'] : 0;
                $callback_label = isset($entry['callback_label']) ? (string) $entry['callback_label'] : '';

                if (!isset($origin_totals[$origin_key])) {
                    $origin_totals[$origin_key] = array(
                        'origin'        => $origin_key,
                        'originType'    => $origin_type,
                        'originName'    => $origin_name,
                        'totalMs'       => 0,
                        'maxMs'         => 0,
                        'count'         => 0,
                        'callbackCount' => 0,
                        'topCallback'   => '',
                        'topCallbackMs' => 0,
                    );
                }

                $origin_totals[$origin_key]['totalMs'] += $total_ms;
                $origin_totals[$origin_key]['count'] += $count;
                $origin_totals[$origin_key]['callbackCount']++;
                if ($max_ms > $origin_totals[$origin_key]['maxMs']) {
                    $origin_totals[$origin_key]['maxMs'] = $max_ms;
                }
                if ($total_ms > $origin_totals[$origin_key]['topCallbackMs']) {
                    $origin_totals[$origin_key]['topCallbackMs'] = $total_ms;
                    $origin_totals[$origin_key]['topCallback'] = $callback_label;
                }
            }

            usort($origin_totals, function ($a, $b) {
                return (int) ($b['totalMs'] ?? 0) <=> (int) ($a['totalMs'] ?? 0);
            });

            foreach (array_slice($callbacks, 0, 12) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $callback_top[] = array(
                    'hook'      => isset($entry['hook']) ? (string) $entry['hook'] : '',
                    'priority'  => isset($entry['priority']) ? (string) $entry['priority'] : '',
                    'callback'  => isset($entry['callback_label']) ? (string) $entry['callback_label'] : '',
                    'origin'    => trim((isset($entry['origin_type']) ? (string) $entry['origin_type'] : '') . ':' . (isset($entry['origin_name']) ? (string) $entry['origin_name'] : ''), ':'),
                    'file'      => isset($entry['origin_file']) ? (string) $entry['origin_file'] : '',
                    'count'     => isset($entry['count']) ? (int) $entry['count'] : 0,
                    'totalMs'   => isset($entry['total_ms']) ? (int) $entry['total_ms'] : 0,
                    'maxMs'     => isset($entry['max_ms']) ? (int) $entry['max_ms'] : 0,
                    'avgMs'     => isset($entry['avg_ms']) ? (int) $entry['avg_ms'] : 0,
                );
            }
            $raw_request_mode = isset($request_profile['mode']) ? sanitize_key((string) $request_profile['mode']) : 'compact';
            if (!in_array($raw_request_mode, array('compact', 'verbose', 'callback'), true)) {
                $raw_request_mode = 'compact';
            }
            $display_mode = count($callbacks) > 0 ? 'callback' : $raw_request_mode;


            $slowest = isset($profile['slowest_stage']) && is_array($profile['slowest_stage']) ? $profile['slowest_stage'] : array();
            $largest = isset($profile['largest_positive_delta']) && is_array($profile['largest_positive_delta']) ? $profile['largest_positive_delta'] : array();
            $css_context = isset($profile['css_bundle_context_after']) && is_array($profile['css_bundle_context_after']) ? $profile['css_bundle_context_after'] : array();
            $critical_request_chain = isset($profile['critical_request_chain']) && is_array($profile['critical_request_chain']) ? $profile['critical_request_chain'] : array();
            $js_delay_safety_scan = isset($profile['js_delay_safety_scan']) && is_array($profile['js_delay_safety_scan']) ? $profile['js_delay_safety_scan'] : array();
            $leftover_css_bundle = isset($profile['leftover_css_bundle']) && is_array($profile['leftover_css_bundle']) ? $profile['leftover_css_bundle'] : array();            $async_css_diagnostics = isset($profile['async_css_diagnostics']) && is_array($profile['async_css_diagnostics']) ? $profile['async_css_diagnostics'] : array();
            $final_stage = array();
            if (isset($profile['stages']) && is_array($profile['stages'])) {
                foreach ($profile['stages'] as $stage_entry) {
                    if (is_array($stage_entry)) {
                        $final_stage = $stage_entry;
                    }
                }
            }

            return array(
                'available'                     => true,
                'version'                       => isset($profile['version']) ? (string) $profile['version'] : (defined('UCWP_VERSION') ? UCWP_VERSION : ''),
                'requestId'                     => isset($profile['request_id']) ? (string) $profile['request_id'] : '',
                'url'                           => isset($profile['url']) ? (string) $profile['url'] : '',
                'status'                        => isset($profile['status']) ? (string) $profile['status'] : '',
                'reason'                        => isset($profile['reason']) ? (string) $profile['reason'] : '',
                'mode'                          => $display_mode,
                'requestMode'                   => $display_mode,
                'rawRequestMode'                => $raw_request_mode,
                'finishedAtUtc'                 => isset($profile['finished_at_utc']) ? (string) $profile['finished_at_utc'] : '',
                'totalRequestDurationMs'        => isset($profile['total_request_duration_ms']) ? (int) $profile['total_request_duration_ms'] : 0,
                'storeProfileDurationMs'        => isset($profile['total_duration_ms']) ? (int) $profile['total_duration_ms'] : 0,
                'shutdownTotalDurationMs'       => isset($profile['shutdown_total_duration_ms']) ? (int) $profile['shutdown_total_duration_ms'] : 0,
                'unmeasuredBeforeStoreProfileMs'=> isset($request_profile['unmeasured_before_store_profile_ms']) ? (int) $request_profile['unmeasured_before_store_profile_ms'] : 0,
                'checkpointCount'               => count($checkpoints),
                'callbackTimingSummaryCount'    => count($callbacks),
                'callbackTimingsCount'          => count($callback_timings),
                'slowestStage'                  => array(
                    'stage'      => isset($slowest['stage']) ? (string) $slowest['stage'] : '',
                    'durationMs' => isset($slowest['duration_ms']) ? (int) $slowest['duration_ms'] : 0,
                ),
                'largestPositiveDelta'          => array(
                    'stage'      => isset($largest['stage']) ? (string) $largest['stage'] : '',
                    'deltaBytes' => isset($largest['delta_bytes']) ? (int) $largest['delta_bytes'] : 0,
                ),
                'criticalRequestChain'          => array(
                    'available'                   => !empty($critical_request_chain['available']),
                    'renderBlockingStyleCount'    => isset($critical_request_chain['render_blocking_style_count']) ? (int) $critical_request_chain['render_blocking_style_count'] : 0,
                    'renderBlockingScriptCount'   => isset($critical_request_chain['render_blocking_script_count']) ? (int) $critical_request_chain['render_blocking_script_count'] : 0,
                    'delayedScriptCount'          => isset($critical_request_chain['delayed_script_count']) ? (int) $critical_request_chain['delayed_script_count'] : 0,
                    'protectedScriptCount'        => isset($critical_request_chain['protected_script_count']) ? (int) $critical_request_chain['protected_script_count'] : 0,
                    'protectedStyleCount'         => isset($critical_request_chain['protected_style_count']) ? (int) $critical_request_chain['protected_style_count'] : 0,
                    'styleCandidates'             => isset($critical_request_chain['style_candidates']) && is_array($critical_request_chain['style_candidates']) ? array_slice($critical_request_chain['style_candidates'], 0, 40) : array(),
                    'scriptCandidates'            => isset($critical_request_chain['script_candidates']) && is_array($critical_request_chain['script_candidates']) ? array_slice($critical_request_chain['script_candidates'], 0, 60) : array(),
                ),
                'jsDelaySafetyScan'            => array(
                    'available'              => !empty($js_delay_safety_scan['available']),
                    'suggestionCount'        => isset($js_delay_safety_scan['suggestion_count']) ? (int) $js_delay_safety_scan['suggestion_count'] : 0,
                    'missingCount'           => isset($js_delay_safety_scan['missing_count']) ? (int) $js_delay_safety_scan['missing_count'] : 0,
                    'alreadyExcludedCount'   => isset($js_delay_safety_scan['already_excluded_count']) ? (int) $js_delay_safety_scan['already_excluded_count'] : 0,
                    'suggestions'            => isset($js_delay_safety_scan['suggestions']) && is_array($js_delay_safety_scan['suggestions']) ? array_slice($js_delay_safety_scan['suggestions'], 0, 80) : array(),
                ),
                'cssBundle'                     => array(
                    'fileExists'                  => !empty($css_context['bundle_file_exists']),
                    'fileBytes'                   => isset($css_context['bundle_file_bytes']) ? (int) $css_context['bundle_file_bytes'] : 0,
                    'sourceUrlCount'              => isset($css_context['source_url_count']) ? (int) $css_context['source_url_count'] : 0,
                    'sourceBytesTotal'            => isset($css_context['source_bytes_total']) ? (int) $css_context['source_bytes_total'] : 0,
                    'largestSourceBytes'          => isset($css_context['largest_source_bytes']) ? (int) $css_context['largest_source_bytes'] : 0,
                    'largestSourceUrl'            => isset($css_context['largest_source_url']) ? (string) $css_context['largest_source_url'] : '',
                    'sourceTop'                   => isset($css_context['source_top']) && is_array($css_context['source_top']) ? array_slice($css_context['source_top'], 0, 12) : array(),
                    'mode'                        => isset($css_context['mode']) ? (string) $css_context['mode'] : '',
                    'largeBundleWarning'          => !empty($css_context['large_bundle_warning']),
                    'veryLargeBundleWarning'      => !empty($css_context['very_large_bundle_warning']),
                    'sourceControlReady'          => !empty($css_context['source_control_ready']),
                    'leftoverCssBundle'          => array(
                        'enabled'               => !empty($leftover_css_bundle['enabled']),
                        'success'               => !empty($leftover_css_bundle['success']),
                        'candidateCount'        => isset($leftover_css_bundle['candidate_count']) ? (int) $leftover_css_bundle['candidate_count'] : 0,
                        'replacedLinkCount'     => isset($leftover_css_bundle['replaced_link_count']) ? (int) $leftover_css_bundle['replaced_link_count'] : 0,
                        'skippedProtectedCount' => isset($leftover_css_bundle['skipped_protected_count']) ? (int) $leftover_css_bundle['skipped_protected_count'] : 0,
                        'skippedReason'         => isset($leftover_css_bundle['skipped_reason']) ? (string) $leftover_css_bundle['skipped_reason'] : '',
                        'bundleUrl'             => isset($leftover_css_bundle['bundle_url']) ? (string) $leftover_css_bundle['bundle_url'] : '',
                        'bundleBytes'           => isset($leftover_css_bundle['bundle_bytes']) ? (int) $leftover_css_bundle['bundle_bytes'] : 0,
                        'sourceBytesTotal'      => isset($leftover_css_bundle['source_bytes_total']) ? (int) $leftover_css_bundle['source_bytes_total'] : 0,
                    ),
                    'finalHtmlBytes'              => isset($final_stage['bytes_out']) ? (int) $final_stage['bytes_out'] : 0,
                    'stylesheetLinks'             => isset($final_stage['stylesheet_links']) ? (int) $final_stage['stylesheet_links'] : 0,
                    'renderBlockingStylesheets'   => isset($final_stage['render_blocking_stylesheet_links']) ? (int) $final_stage['render_blocking_stylesheet_links'] : 0,
                    'renderBlockingBundleLinks'   => isset($final_stage['render_blocking_css_bundle_links']) ? (int) $final_stage['render_blocking_css_bundle_links'] : 0,
                    'renderBlockingNonBundleLinks'=> isset($final_stage['render_blocking_non_bundle_stylesheet_links']) ? (int) $final_stage['render_blocking_non_bundle_stylesheet_links'] : 0,
                    'renderBlockingHrefs'         => isset($final_stage['render_blocking_stylesheet_hrefs']) && is_array($final_stage['render_blocking_stylesheet_hrefs']) ? array_slice($final_stage['render_blocking_stylesheet_hrefs'], 0, 20) : array(),
                    'renderBlockingNonBundleHrefs'=> isset($final_stage['render_blocking_non_bundle_stylesheet_hrefs']) && is_array($final_stage['render_blocking_non_bundle_stylesheet_hrefs']) ? array_slice($final_stage['render_blocking_non_bundle_stylesheet_hrefs'], 0, 20) : array(),
                    'noscriptTags'                => isset($final_stage['noscript_tags']) ? (int) $final_stage['noscript_tags'] : 0,
                    'externalLinks'               => isset($final_stage['page_css_bundle_external_links']) ? (int) $final_stage['page_css_bundle_external_links'] : 0,
                    'inlineStyleTags'             => isset($final_stage['page_css_bundle_inline_style_tags']) ? (int) $final_stage['page_css_bundle_inline_style_tags'] : 0,
                    'inlineStyleBytes'            => isset($final_stage['page_css_bundle_inline_style_bytes']) ? (int) $final_stage['page_css_bundle_inline_style_bytes'] : 0,
                    'fallbackMarkers'             => isset($final_stage['page_css_bundle_fallback_markers']) ? (int) $final_stage['page_css_bundle_fallback_markers'] : 0,
                    'fallbackBlocks'              => isset($final_stage['page_css_bundle_fallback_blocks']) ? (int) $final_stage['page_css_bundle_fallback_blocks'] : 0,
                    'fallbackLinks'               => isset($final_stage['page_css_bundle_fallback_links']) ? (int) $final_stage['page_css_bundle_fallback_links'] : 0,
                    'leftoverBundleRefs'        => isset($final_stage['leftover_css_bundle_refs']) ? (int) $final_stage['leftover_css_bundle_refs'] : 0,
                    'leftoverBundleMarkers'     => isset($final_stage['leftover_css_bundle_markers']) ? (int) $final_stage['leftover_css_bundle_markers'] : 0,                    'asyncCssDiagnostics'        => array(
                        'available'          => !empty($async_css_diagnostics['available']),
                        'enabled'            => !empty($async_css_diagnostics['enabled']),
                        'aggressiveEnabled'  => !empty($async_css_diagnostics['aggressive_enabled']),
                        'safe'               => !isset($async_css_diagnostics['safe']) || !empty($async_css_diagnostics['safe']),
                        'scanned'            => isset($async_css_diagnostics['scanned']) ? (int) $async_css_diagnostics['scanned'] : 0,
                        'rewritten'          => isset($async_css_diagnostics['rewritten']) ? (int) $async_css_diagnostics['rewritten'] : 0,
                        'skipped'            => isset($async_css_diagnostics['skipped']) ? (int) $async_css_diagnostics['skipped'] : 0,
                        'unresolved'         => isset($async_css_diagnostics['unresolved']) ? (int) $async_css_diagnostics['unresolved'] : 0,
                        'reasonCounts'       => isset($async_css_diagnostics['reason_counts']) && is_array($async_css_diagnostics['reason_counts']) ? $async_css_diagnostics['reason_counts'] : array(),
                        'items'              => isset($async_css_diagnostics['items']) && is_array($async_css_diagnostics['items']) ? array_slice($async_css_diagnostics['items'], 0, 80) : array(),
                    ),
                ),
                'slowCheckpoints'               => array_slice($slow_checkpoints, 0, 12),
                'callbackTop'                   => $callback_top,
                'originTop'                     => array_slice($origin_totals, 0, 12),
            );
        }

        public function get_performance_profile_last()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'STORE profiler helper is not available.'), 500);
            }

            $profile = $engine->get_last_store_profile();
            if (!is_array($profile) || empty($profile)) {
                return new WP_REST_Response(array('success' => true, 'message' => 'No STORE profiler report found yet.', 'performanceProfile' => array(), 'profile' => null), 200);
            }

            return new WP_REST_Response(array(
                'success'            => true,
                'message'            => 'Last STORE profiler report loaded.',
                'performanceProfile' => $this->summarize_performance_profile($profile),
                'profile'            => $profile,
            ), 200);
        }

        public function clear_performance_profile_last()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'clear_last_store_profile')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'STORE profiler helper is not available.'), 500);
            }

            $ok = (bool) $engine->clear_last_store_profile();
            return new WP_REST_Response(array(
                'success' => true,
                'cleared' => $ok,
                'message' => $ok ? 'Last STORE profiler report cleared.' : 'No STORE profiler report was present.',
            ), 200);
        }

        private function run_performance_profile_job(array $params)
        {
            $mode = $this->normalize_performance_profile_mode($params['mode'] ?? 'compact');
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'get_last_store_profile')) {
                return array('success' => false, 'message' => 'STORE profiler helper is not available.');
            }

            if (method_exists($engine, 'clear_last_store_profile')) {
                $engine->clear_last_store_profile();
            }

            $headers = array(
                'X-UltraCache-Store-Profile'  => '1',
                'X-UltraCache-Debug'          => '1',
                'X-UltraCache-Profile-Bypass' => '1',
                'X-UltraCache-Token'          => wp_hash('ucwp-revalidate-v1'),
            );
            if ('verbose' === $mode) {
                $headers['X-UltraCache-Store-Profile-Verbose'] = '1';
            }
            if ('callback' === $mode) {
                $headers['X-UltraCache-Callback-Profile'] = '1';
            }

            $url = $this->normalize_performance_profile_url($params['url'] ?? home_url('/'));
            if (is_wp_error($url)) {
                return array(
                    'success' => false,
                    'message' => $url->get_error_message(),
                    'performanceProfile' => array('available' => false, 'mode' => $mode),
                );
            }

            $started = microtime(true);
            $response = wp_remote_get($url, array(
                'timeout'     => 90,
                'redirection' => 3,
                'headers'     => $headers,
                'user-agent'  => 'UltraCache Dashboard Profiler/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown') . '; ' . home_url('/'),
            ));
            $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Profiler request failed: ' . $response->get_error_message(),
                    'performanceProfile' => array('available' => false, 'mode' => $mode),
                );
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $cache_status = (string) wp_remote_retrieve_header($response, 'x-ultra-cache');
            $cache_source = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-source');
            $profile_header = (string) wp_remote_retrieve_header($response, 'x-ultra-cache-store-profile');
            $body = wp_remote_retrieve_body($response);

            $profile = $engine->get_last_store_profile();
            if (!is_array($profile) || empty($profile)) {
                return array(
                    'success' => false,
                    'message' => 'Profiler request completed but no STORE profile report was saved. Cache status: ' . ($cache_status ?: 'unknown') . '.',
                    'performanceProfile' => array(
                        'available' => false,
                        'mode' => $mode,
                        'responseCode' => $code,
                        'requestMs' => $elapsed_ms,
                        'cacheStatus' => $cache_status,
                        'cacheSource' => $cache_source,
                        'profileHeader' => $profile_header,
                        'bodyBytes' => is_string($body) ? strlen($body) : 0,
                    ),
                );
            }

            $summary = $this->summarize_performance_profile($profile);
            $summary['mode'] = $mode;
            $summary['profileUrl'] = $url;
            $summary['scannedAt'] = function_exists('current_time') ? current_time('mysql') : gmdate('c');
            $summary['responseCode'] = $code;
            $summary['requestMs'] = $elapsed_ms;
            $summary['cacheStatus'] = $cache_status;
            $summary['cacheSource'] = $cache_source;
            $summary['profileHeader'] = $profile_header;
            $summary['bodyBytes'] = is_string($body) ? strlen($body) : 0;
            $summary['cacheBypassedForDiagnostic'] = true;

            return array(
                'success' => true,
                'message' => strtoupper($mode) . ' performance profile completed.',
                'performanceProfile' => $summary,
            );
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

