<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!trait_exists('Ultra_Cache_Rest_Schemas_Trait')) {
    trait Ultra_Cache_Rest_Schemas_Trait
    {
        public function sanitize_object_cache_backend_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('redis', 'apcu', 'disk'), true) ? $value : 'redis';
        }

        public function validate_object_cache_backend_param($value)
        {
            return in_array(strtolower(trim((string) $value)), array('redis', 'apcu', 'disk'), true);
        }

        public function sanitize_object_cache_fallback_backend_param($value)
        {
            $value = strtolower(trim((string) $value));
            if ('none' === $value || 'runtime' === $value || '' === $value) {
                return 'none';
            }
            return in_array($value, array('apcu', 'disk'), true) ? $value : 'apcu';
        }

        public function validate_object_cache_fallback_backend_param($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('none', 'runtime', 'apcu', 'disk', ''), true);
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

        public function sanitize_media_queue_format_param($value)
        {
            return sanitize_key((string) $value);
        }

        public function validate_media_queue_format_param($value)
        {
            return in_array(sanitize_key((string) $value), array('best', 'avif', 'webp', 'both'), true);
        }

        public function sanitize_media_queue_limit_param($value)
        {
            return absint($value);
        }

        public function validate_media_queue_limit_param($value)
        {
            $value = absint($value);
            return $value >= 0 && $value <= 500;
        }

        public function sanitize_media_queue_time_budget_param($value)
        {
            return absint($value);
        }

        public function validate_media_queue_time_budget_param($value)
        {
            $value = absint($value);
            return $value >= 0 && $value <= 120;
        }

        private function get_media_queue_format_arg_schema()
        {
            return array(
                'type'              => 'string',
                'required'          => false,
                'default'           => 'best',
                'sanitize_callback' => array($this, 'sanitize_media_queue_format_param'),
                'validate_callback' => array($this, 'validate_media_queue_format_param'),
            );
        }

        private function get_media_queue_common_args()
        {
            return array(
                'media_format' => $this->get_media_queue_format_arg_schema(),
            );
        }

        private function get_media_queue_rebuild_args()
        {
            return array_merge($this->get_media_queue_common_args(), array(
                'limit' => array(
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 0,
                    'sanitize_callback' => array($this, 'sanitize_media_queue_limit_param'),
                    'validate_callback' => array($this, 'validate_media_queue_limit_param'),
                ),
            ));
        }

        private function get_media_queue_process_args()
        {
            return array_merge($this->get_media_queue_common_args(), array(
                'limit' => array(
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 5,
                    'sanitize_callback' => array($this, 'sanitize_media_queue_limit_param'),
                    'validate_callback' => array($this, 'validate_media_queue_limit_param'),
                ),
                'time_budget' => array(
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 20,
                    'sanitize_callback' => array($this, 'sanitize_media_queue_time_budget_param'),
                    'validate_callback' => array($this, 'validate_media_queue_time_budget_param'),
                ),
            ));
        }

        private function get_settings_update_args()
        {
            return array(
                'pageCacheEnabled'                     => array('type' => 'boolean', 'required' => false),
                'objectCacheEnabled'                   => array('type' => 'boolean', 'required' => false),
                'objectCacheBackend'                   => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_object_cache_backend_param'), 'validate_callback' => array($this, 'validate_object_cache_backend_param')),
                'objectCacheFallbackBackend'           => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_object_cache_fallback_backend_param'), 'validate_callback' => array($this, 'validate_object_cache_fallback_backend_param')),
                'redisHost'                            => array('type' => 'string', 'required' => false),
                'redisPort'                            => array('type' => 'integer', 'required' => false),
                'redisUsername'                        => array('type' => 'string', 'required' => false),
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
                'preRenderOnSave'                      => array('type' => 'boolean', 'required' => false),
                'woocommerceSafeModeEnabled'           => array('type' => 'boolean', 'required' => false),
                'cacheCleanupEnabled'                  => array('type' => 'boolean', 'required' => false),
                'apcuFlushOnScheduledCleanup'          => array('type' => 'boolean', 'required' => false),
                'cronWarmEnabled'                      => array('type' => 'boolean', 'required' => false),
                'cronWarmStartAfterCleanup'            => array('type' => 'boolean', 'required' => false),
                'cronWarmStartAfterManualPurge'        => array('type' => 'boolean', 'required' => false),
                'cacheCleanupIntervalHours'            => array('type' => 'integer', 'required' => false),
                'cssBundleCleanupGraceHours'       => array('type' => 'integer', 'required' => false),
                'cssBundleCleanupDeleteLimit'      => array('type' => 'integer', 'required' => false),
                'cronWarmPagesPerMinute'               => array('type' => 'integer', 'required' => false),
                'scheduledWarmLimit'                   => array('type' => 'integer', 'required' => false),
                'warmMenuLocation'                    => array('type' => 'string', 'required' => false),
                'warmMenuDepth'                       => array('type' => 'string', 'required' => false),
                'warmFullSiteSources'                 => array('type' => 'string', 'required' => false),
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
                'redisUsername'         => array('type' => 'string', 'required' => false),
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

    }
}
