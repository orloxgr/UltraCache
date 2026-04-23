<?php
/** Hotfix Bundle Version: 2.55.02 */
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

            foreach ($this->get_legacy_namespaces() as $namespace) {
                foreach ($definitions as $route => $handlers) {
                    register_rest_route($namespace, $route, $handlers);
                }
            }
        }

        private function get_canonical_namespace()
        {
            return 'ultracache/v1';
        }

        private function get_legacy_namespaces()
        {
            $namespaces = array('ucwp/v1', 'ultra-cache-wp/v1');
            $namespaces = apply_filters('ucwp_rest_legacy_namespaces', $namespaces);
            $clean = array();

            foreach ((array) $namespaces as $namespace) {
                $namespace = trim((string) $namespace, " /");
                if ('' === $namespace || $namespace === $this->get_canonical_namespace()) {
                    continue;
                }
                $clean[$namespace] = $namespace;
            }

            return array_values($clean);
        }

        public function sanitize_object_cache_backend_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('disk', 'redis'), true) ? $value : 'disk';
        }

        public function validate_object_cache_backend_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('disk', 'redis'), true);
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
                'avifConversionEnabled'                => array('type' => 'boolean', 'required' => false),
                'deferJsEnabled'                       => array('type' => 'boolean', 'required' => false),
                'delayThirdPartyJsEnabled'             => array('type' => 'boolean', 'required' => false),
                'asyncExternalScriptsEnabled'          => array('type' => 'boolean', 'required' => false),
                'clsDimensionsEnabled'                 => array('type' => 'boolean', 'required' => false),
                'asyncCssEnabled'                      => array('type' => 'boolean', 'required' => false),
                'lcpImagePriorityEnabled'              => array('type' => 'boolean', 'required' => false),
                'googleFontsSwapEnabled'               => array('type' => 'boolean', 'required' => false),
                'googleFontsLocalOptimizationEnabled'  => array('type' => 'boolean', 'required' => false),
                'selfHostedFontCssOptimizationEnabled' => array('type' => 'boolean', 'required' => false),
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
                'cronWarmEnabled'                      => array('type' => 'boolean', 'required' => false),
                'cronWarmStartAfterCleanup'            => array('type' => 'boolean', 'required' => false),
                'warmAfterScheduledCleanup'            => array('type' => 'boolean', 'required' => false),
                'cacheCleanupIntervalHours'            => array('type' => 'integer', 'required' => false),
                'cronWarmPagesPerMinute'               => array('type' => 'integer', 'required' => false),
                'scheduledWarmLimit'                   => array('type' => 'integer', 'required' => false),
                'staleWhileRevalidateEnabled'          => array('type' => 'boolean', 'required' => false),
                'cacheFreshTtlMinutes'                 => array('type' => 'integer', 'required' => false),
                'cacheMaxStaleMinutes'                 => array('type' => 'integer', 'required' => false),
                'cacheExceptionPaths'                  => array('type' => 'string', 'required' => false),
                'cacheExceptionQueryArgs'              => array('type' => 'string', 'required' => false),
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
            );
        }

        public function dispatch_legacy_route(WP_REST_Request $request)
        {
            $path = '/' . ltrim((string) $request->get_param('ucwp_legacy_path'), '/');
            $definitions = $this->get_route_definitions();
            if (empty($definitions[$path])) {
                return new WP_Error(
                    'rest_no_route',
                    __('No route was found matching the URL and request method.', 'ultracache'),
                    array('status' => 404)
                );
            }

            $handler = $this->get_matching_legacy_handler($definitions[$path], $request->get_method());
            if (empty($handler)) {
                return new WP_Error(
                    'rest_no_route',
                    __('No route was found matching the URL and request method.', 'ultracache'),
                    array('status' => 404)
                );
            }

            $permission = $handler['permission_callback'] ?? null;
            if (is_callable($permission)) {
                $permission_result = call_user_func($permission, $request);
                if (is_wp_error($permission_result)) {
                    return $permission_result;
                }
                if (false === $permission_result || null === $permission_result) {
                    return new WP_Error(
                        'rest_forbidden',
                        __('Sorry, you are not allowed to do that.', 'ultracache'),
                        array('status' => function_exists('rest_authorization_required_code') ? rest_authorization_required_code() : 403)
                    );
                }
            }

            $prepared = $this->prepare_legacy_request($request, $handler['args'] ?? array());
            if (is_wp_error($prepared)) {
                return $prepared;
            }

            $callback = $handler['callback'] ?? null;
            if (!is_callable($callback)) {
                return new WP_Error(
                    'rest_invalid_handler',
                    __('The REST route handler is invalid.', 'ultracache'),
                    array('status' => 500)
                );
            }

            return call_user_func($callback, $request);
        }

        private function get_matching_legacy_handler($handlers, $method)
        {
            $method = strtoupper((string) $method);
            if ('HEAD' === $method) {
                $method = 'GET';
            }
            foreach ((array) $handlers as $handler) {
                $supported_methods = array_filter(array_map('trim', explode(',', strtoupper((string) ($handler['methods'] ?? '')))));
                if (in_array($method, $supported_methods, true)) {
                    return $handler;
                }
            }

            return array();
        }

        private function prepare_legacy_request(WP_REST_Request $request, array $args)
        {
            foreach ($args as $name => $schema) {
                $required = !empty($schema['required']);
                $has_value = $request->has_param($name);
                if (!$has_value) {
                    if ($required) {
                        return new WP_Error(
                            'rest_missing_callback_param',
                            sprintf(
                                /* translators: %s: REST parameter name. */
                                __('Missing parameter(s): %s', 'ultracache'),
                                $name
                            ),
                            array('status' => 400)
                        );
                    }
                    continue;
                }

                $value = $request->get_param($name);

                if (!empty($schema['sanitize_callback']) && is_callable($schema['sanitize_callback'])) {
                    $value = call_user_func($schema['sanitize_callback'], $value, $request, $name);
                } elseif (function_exists('rest_sanitize_value_from_schema') && !empty($schema['type'])) {
                    $value = rest_sanitize_value_from_schema($value, $schema, $name);
                }

                if (!empty($schema['validate_callback']) && is_callable($schema['validate_callback'])) {
                    $valid = call_user_func($schema['validate_callback'], $value, $request, $name);
                    if (is_wp_error($valid)) {
                        return $valid;
                    }
                    if (false === $valid) {
                        return new WP_Error(
                            'rest_invalid_param',
                            sprintf(
                                /* translators: %s: REST parameter name. */
                                __('Invalid parameter: %s', 'ultracache'),
                                $name
                            ),
                            array('status' => 400)
                        );
                    }
                } elseif (function_exists('rest_validate_value_from_schema') && !empty($schema['type'])) {
                    $valid = rest_validate_value_from_schema($value, $schema, $name);
                    if (is_wp_error($valid)) {
                        return $valid;
                    }
                }

                $request->set_param($name, $value);
            }

            return true;
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
            if (null !== $request->get_param('cronWarmStartAfterCleanup') && null === $request->get_param('warmAfterScheduledCleanup')) {
                $current['warmAfterScheduledCleanup'] = $request->get_param('cronWarmStartAfterCleanup');
            } elseif (null !== $request->get_param('warmAfterScheduledCleanup') && null === $request->get_param('cronWarmStartAfterCleanup')) {
                $current['cronWarmStartAfterCleanup'] = $request->get_param('warmAfterScheduledCleanup');
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

            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_url') || !method_exists($engine, 'is_cacheable_local_url')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            if (!$engine->is_cacheable_local_url($url)) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Only local site URLs can be crawled.'), 400);
            }

            $result = $engine->warm_url($url);
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
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'build_frontpage_css_bundle')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->build_frontpage_css_bundle();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : (!empty($result['skipped']) ? 200 : 500));
        }

        public function warm_frontpage_html()
        {
            $engine = $this->get_engine();
            if (!$engine || !method_exists($engine, 'warm_frontpage_html')) {
                return new WP_REST_Response(array('success' => false, 'message' => 'Cache engine not available.'), 500);
            }

            $result = $engine->warm_frontpage_html();
            return new WP_REST_Response($result, !empty($result['success']) ? 200 : 500);
        }

        public function warm_frontpage_html_css()
        {
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

        private function resolve_engine_stats()
        {
            $stats = array();
            $engine = $this->get_engine();
            if ($engine && method_exists($engine, 'get_stats')) {
                $stats = $engine::get_stats();
                $stats = is_array($stats) ? $stats : array();
            }

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_stats')) {
                $object_stats = Ultra_Cache_Object_Cache_Manager::get_stats();
                if (is_array($object_stats)) {
                    $stats = array_merge($stats, $object_stats);
                    $stats['cacheSizeBytes'] = (int) ($stats['cacheSizeBytes'] ?? 0) + (int) ($object_stats['objectCacheSizeBytes'] ?? 0);
                    if (function_exists('size_format')) {
                        $stats['cacheSizeHuman'] = size_format((int) $stats['cacheSizeBytes'], 2);
                    }
                }
            }

            return $stats;
        }
    }
}

if (!class_exists('Ultra_Cache_REST_API') && class_exists('Ultra_Cache_Rest_API')) {
    class_alias('Ultra_Cache_Rest_API', 'Ultra_Cache_REST_API');
}

if (!class_exists('UltraCache_V246_Rest_API') && class_exists('Ultra_Cache_Rest_API')) {
    class_alias('Ultra_Cache_Rest_API', 'UltraCache_V246_Rest_API');
}

if (!class_exists('UltraCache_V246_REST_API') && class_exists('Ultra_Cache_Rest_API')) {
    class_alias('Ultra_Cache_Rest_API', 'UltraCache_V246_REST_API');
}
