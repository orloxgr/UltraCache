<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Rest_Schemas_Trait
{
    public function sanitize_object_cache_backend_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('redis', 'apcu', 'sqlite', 'disk'), true) ? $value : 'redis';
    }

    public function validate_object_cache_backend_param($value)
    {
        return in_array(strtolower(trim((string) $value)), array('redis', 'apcu', 'sqlite', 'disk'), true);
    }

    public function sanitize_object_cache_fallback_backend_param($value)
    {
        $value = strtolower(trim((string) $value));
        if ('none' === $value || 'runtime' === $value || '' === $value) {
            return 'none';
        }
        return in_array($value, array('apcu', 'sqlite', 'disk'), true) ? $value : 'apcu';
    }

    public function validate_object_cache_fallback_backend_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('none', 'runtime', 'apcu', 'sqlite', 'disk', ''), true);
    }

    public function sanitize_sqlite_database_size_mb_param($value)
    {
        $value = absint($value);
        return in_array($value, array(32, 64, 128, 256, 512, 1024, 2048), true) ? $value : 256;
    }

    public function validate_sqlite_database_size_mb_param($value)
    {
        return in_array(absint($value), array(32, 64, 128, 256, 512, 1024, 2048), true);
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

    public function sanitize_varnish_invalidation_strategy_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('auto', 'ban', 'purge', 'soft'), true) ? $value : 'ban';
    }

    public function validate_varnish_invalidation_strategy_param($value)
    {
        return in_array(strtolower(trim((string) $value)), array('auto', 'ban', 'purge', 'soft'), true);
    }

    public function sanitize_varnish_flush_scope_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('auto', 'html', 'host'), true) ? $value : 'auto';
    }

    public function validate_varnish_flush_scope_param($value)
    {
        return in_array(strtolower(trim((string) $value)), array('auto', 'html', 'host'), true);
    }

    public function sanitize_varnish_flush_action_scope_param($value)
    {
        $value = sanitize_key((string) $value);
        return in_array($value, array('configured', 'entire-host'), true) ? $value : 'configured';
    }

    public function validate_varnish_flush_action_scope_param($value)
    {
        return in_array(sanitize_key((string) $value), array('configured', 'entire-host'), true);
    }

    public function sanitize_media_format_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('avif', 'webp'), true) ? $value : 'webp';
    }

    public function validate_media_format_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('avif', 'webp'), true);
    }

    public function sanitize_media_output_mode_param($value)
    {
        return $this->sanitize_media_format_param($value);
    }

    public function validate_media_output_mode_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('auto', 'avif', 'webp'), true);
    }

    public function sanitize_media_fallback_format_param($value)
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, array('jpeg/png', 'jpeg_png', 'jpeg-png', 'jpg/png', 'jpg_png', 'jpg-png', 'jpeg', 'jpg', 'png', 'original'), true)) {
            return 'original';
        }
        return ('webp' === $value) ? 'webp' : 'original';
    }

    public function validate_media_fallback_format_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('webp', 'original', 'jpeg/png', 'jpeg_png', 'jpeg-png', 'jpg/png', 'jpg_png', 'jpg-png', 'jpeg', 'jpg', 'png'), true);
    }

    public function sanitize_media_quality_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('original', 'high', 'balanced', 'compact', 'smallest'), true) ? $value : 'balanced';
    }

    public function validate_media_quality_param($value)
    {
        return in_array(strtolower(trim((string) $value)), array('original', 'high', 'balanced', 'compact', 'smallest'), true);
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

    public function sanitize_manual_warm_operation_param($value)
    {
        $value = sanitize_key((string) $value);
        return in_array($value, array('menu', 'full_site'), true) ? $value : '';
    }

    public function validate_manual_warm_operation_param($value)
    {
        $value = sanitize_key((string) $value);
        return in_array($value, array('', 'menu', 'full_site'), true);
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
            'recount_files' => array(
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
        );
    }

    private function get_media_queue_rebuild_args()
    {
        return array_merge($this->get_media_queue_common_args(), array(
            'reset' => array(
                'type'              => 'boolean',
                'required'          => false,
                'default'           => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'time_budget' => array(
                'type'              => 'integer',
                'required'          => false,
                'default'           => 20,
                'sanitize_callback' => array($this, 'sanitize_media_queue_time_budget_param'),
                'validate_callback' => array($this, 'validate_media_queue_time_budget_param'),
            ),
            'limit' => array(
                'type'              => 'integer',
                'required'          => false,
                'default'           => 0,
                'sanitize_callback' => array($this, 'sanitize_media_queue_limit_param'),
                'validate_callback' => array($this, 'validate_media_queue_limit_param'),
            ),
            'generation' => array(
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ));
    }

    private function get_media_queue_process_args()
    {
        return array_merge($this->get_media_queue_common_args(), array(
            'limit' => array(
                'type'              => 'integer',
                'required'          => false,
                'default'           => 1,
                'sanitize_callback' => array($this, 'sanitize_media_queue_limit_param'),
                'validate_callback' => array($this, 'validate_media_queue_limit_param'),
            ),
            'time_budget' => array(
                'type'              => 'integer',
                'required'          => false,
                'default'           => 8,
                'sanitize_callback' => array($this, 'sanitize_media_queue_time_budget_param'),
                'validate_callback' => array($this, 'validate_media_queue_time_budget_param'),
            ),
        ));
    }

    private function get_settings_update_args()
    {
        return array(
            'pageCacheEnabled'                     => array('type' => 'boolean', 'required' => false),
            'purgeAfterCoreUpdatesEnabled'         => array('type' => 'boolean', 'required' => false),
            'purgeAfterPluginUpdatesEnabled'       => array('type' => 'boolean', 'required' => false),
            'purgeAfterThemeUpdatesEnabled'        => array('type' => 'boolean', 'required' => false),
            'objectCacheEnabled'                   => array('type' => 'boolean', 'required' => false),
            'objectCacheBackend'                   => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_object_cache_backend_param'), 'validate_callback' => array($this, 'validate_object_cache_backend_param')),
            'objectCacheFallbackBackend'           => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_object_cache_fallback_backend_param'), 'validate_callback' => array($this, 'validate_object_cache_fallback_backend_param')),
            'preserveConfiguredInfrastructure'     => array('type' => 'boolean', 'required' => false),
            'runtimeJsScanDecision'                 => array('type' => 'string', 'required' => false),
            'sqliteDatabaseSizeMb'                   => array('type' => 'integer', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_sqlite_database_size_mb_param'), 'validate_callback' => array($this, 'validate_sqlite_database_size_mb_param')),
            'redisHost'                            => array('type' => 'string', 'required' => false),
            'redisPort'                            => array('type' => 'integer', 'required' => false),
            'redisUsername'                        => array('type' => 'string', 'required' => false),
            'redisPassword'                        => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_secret_constant_param')),
            'clearRedisPassword'                   => array('type' => 'boolean', 'required' => false),
            'validateRedisSettings'                => array('type' => 'boolean', 'required' => false),
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
            'mediaUploadConversionEnabled'        => array('type' => 'boolean', 'required' => false),
            'mediaIgnoreColorProfilePreservation' => array('type' => 'boolean', 'required' => false),
            'imageUploadMaxSide'                  => array(
                'type'              => 'integer',
                'required'          => false,
                'minimum'           => 1,
                'maximum'           => 8192,
                'sanitize_callback' => 'rest_sanitize_request_arg',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'mediaStaleWorkerThreshold'             => array(
                'type'              => 'integer',
                'required'          => false,
                'minimum'           => 1,
                'sanitize_callback' => 'rest_sanitize_request_arg',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'mediaUploadFormat'                  => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_format_param'), 'validate_callback' => array($this, 'validate_media_format_param')),
            'mediaOutputMode'                     => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_output_mode_param'), 'validate_callback' => array($this, 'validate_media_output_mode_param')),
            'mediaFallbackFormat'                 => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_fallback_format_param'), 'validate_callback' => array($this, 'validate_media_fallback_format_param')),
            'mediaReplacementFormat'             => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_format_param'), 'validate_callback' => array($this, 'validate_media_format_param')),
            'mediaQuality'                        => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_media_quality_param'), 'validate_callback' => array($this, 'validate_media_quality_param')),
            'javascriptStrategy'                   => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_javascript_strategy_param'), 'validate_callback' => array($this, 'validate_javascript_strategy_param')),
            'deferJsEnabled'                       => array('type' => 'boolean', 'required' => false),
            'delayAllJsEnabled'                    => array('type' => 'boolean', 'required' => false),
            'firstPartyJsParallelExecutionEnabled' => array('type' => 'boolean', 'required' => false),
            'thirdPartyJsParallelExecutionEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedLocalJsAutoStart'            => array('type' => 'string', 'required' => false),
            'delayedLocalJsAutoStartSeconds'     => array('type' => 'number', 'required' => false),
            'delayedJsMinimumReleaseSeconds'      => array('type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 4),
            'delayedJsAutostartAfterLoadEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedJsAutostartMousemoveEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedJsAutostartScrollEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedJsAutostartClickEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedJsAutostartTouchPointerEnabled' => array('type' => 'boolean', 'required' => false),
            'delayedJsAutostartKeyboardEnabled' => array('type' => 'boolean', 'required' => false),
            'deferJsForceList'                   => array('type' => 'string', 'required' => false),
            'deferJsExcludeList'                   => array('type' => 'string', 'required' => false),
            'delaySafeThirdPartyJsEnabled'             => array('type' => 'boolean', 'required' => false),
            'delayAllThirdPartyJsEnabled'              => array('type' => 'boolean', 'required' => false),
            'lazyMailerliteNonceEnabled'          => array('type' => 'boolean', 'required' => false),
            'delaySafeThirdPartyJsPatterns'       => array('type' => 'string', 'required' => false),
            'delayFunctionalThirdPartyJsEnabled'  => array('type' => 'boolean', 'required' => false),
            'delayFunctionalThirdPartyJsPatterns' => array('type' => 'string', 'required' => false),
            'asyncExternalScriptsEnabled'          => array('type' => 'boolean', 'required' => false),
            'homepageCssBundleEnabled'             => array('type' => 'boolean', 'required' => false),
            'homepageCssBundleInlineEnabled'       => array('type' => 'boolean', 'required' => false),
            'leftoverCssBundleEnabled'           => array('type' => 'boolean', 'required' => false),
            'fontMixCssBundleEnabled'            => array('type' => 'boolean', 'required' => false),
            'fontMixCssBundleAsyncEnabled'       => array('type' => 'boolean', 'required' => false),
            'homepageCssBundleExcludeList'         => array('type' => 'string', 'required' => false),
            'homepageCssBundleMode'                => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_homepage_css_bundle_mode_param'), 'validate_callback' => array($this, 'validate_homepage_css_bundle_mode_param')),
            'delayIconFontsEnabled'                => array('type' => 'boolean', 'required' => false),
            'delayIconFontsAutoDetectEnabled'      => array('type' => 'boolean', 'required' => false),
            'delayIconFontsList'                   => array('type' => 'string', 'required' => false),
            'delayIconFontsExcludeList'            => array('type' => 'string', 'required' => false),
            'cssBundleScope'                       => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_css_bundle_scope_param'), 'validate_callback' => array($this, 'validate_css_bundle_scope_param')),
            'pageCssBundleOnEntryEnabled'          => array('type' => 'boolean', 'required' => false),
            'pageAsyncBundleOnEntryEnabled'     => array('type' => 'boolean', 'required' => false),
            'sliderSafeModeEnabled'              => array('type' => 'boolean', 'required' => false),
            'clsDimensionsEnabled'                 => array('type' => 'boolean', 'required' => false),
            'asyncCssEnabled'                      => array('type' => 'boolean', 'required' => false),
            'asyncExternalCssEnabled'              => array('type' => 'boolean', 'required' => false),
            'asyncConsentCssEnabled'                => array('type' => 'boolean', 'required' => false),
            'asyncConsentCssAutoEnabled'            => array('type' => 'boolean', 'required' => false),
            'asyncCssExcludeList'                  => array('type' => 'string', 'required' => false),
            'asyncExternalCssExcludeList'          => array('type' => 'string', 'required' => false),
            'aggressiveAsyncCssEnabled'            => array('type' => 'boolean', 'required' => false),
            'delayNonCriticalJsEnabled'            => array('type' => 'boolean', 'required' => false),
            'protectWpBakeryAnimationsEnabled'      => array('type' => 'boolean', 'required' => false),
            'protectElementorCompatibilityEnabled'   => array('type' => 'boolean', 'required' => false),
            'realCookieBannerCompatibilityEnabled' => array('type' => 'boolean', 'required' => false),
            'complianzCompatibilityEnabled' => array('type' => 'boolean', 'required' => false),
            'woocommerceVariableProductCompatibilityEnabled' => array('type' => 'boolean', 'required' => false),
            'delayNonCriticalJsExcludeList'        => array('type' => 'string', 'required' => false),
            'lcpImagePriorityEnabled'              => array('type' => 'boolean', 'required' => false),
            'lcpFrontendDiscoveryEnabled'           => array('type' => 'boolean', 'required' => false),
            'lcpFrontendDiscoveryAdminsOnly'        => array('type' => 'boolean', 'required' => false),
            'lcpFrontendDiscoveryDuration'          => array(
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
                'enum'              => array('1_hour', '4_hours', '8_hours', '1_day', '3_days', '1_week', 'indefinitely'),
            ),
            'lazyLoadImagesEnabled'                => array('type' => 'boolean', 'required' => false),
            'lazyLoadThirdPartyIframesEnabled'     => array('type' => 'boolean', 'required' => false),
            'lcpBoundaryDeferEnabled'              => array('type' => 'boolean', 'required' => false),
            'manualLcpHeroSelector'                => array('type' => 'string', 'required' => false),
            'mainThreadReliefEnabled'              => array('type' => 'boolean', 'required' => false),
            'criticalRequestChainReliefEnabled'     => array('type' => 'boolean', 'required' => false),
            'criticalResourcePreloadList'           => array('type' => 'string', 'required' => false),
            'criticalRequestChainDelayList'         => array('type' => 'string', 'required' => false),
            'assetChainCleanupEnabled'              => array('type' => 'boolean', 'required' => false),
            'assetCleanupWooProductAssetsEnabled'   => array('type' => 'boolean', 'required' => false),
            'assetCleanupProductFilterAssetsEnabled'=> array('type' => 'boolean', 'required' => false),
            'assetCleanupWooBlocksCssEnabled'       => array('type' => 'boolean', 'required' => false),
            'woocommerceCartFragmentsSuppressEmptyEnabled' => array('type' => 'boolean', 'required' => false),
            'woocommerceCartFragmentsDelayEnabled'  => array('type' => 'boolean', 'required' => false),
            'woocommerceCartFragmentsDelayTiming'   => array('type' => 'string', 'required' => false),
            'assetCleanupExcludeList'               => array('type' => 'string', 'required' => false),
            'googleFontsSwapEnabled'               => array('type' => 'boolean', 'required' => false),
            'googleFontsLocalOptimizationEnabled'  => array('type' => 'boolean', 'required' => false),
            'googleFontsAdditionalScanUrls'       => array('type' => 'string', 'required' => false),
            'selfHostedFontCssOptimizationEnabled' => array('type' => 'boolean', 'required' => false),
            'selfHostedFontRuntimeRewriteEnabled'  => array('type' => 'boolean', 'required' => false),
            'speculationRulesEnabled'              => array('type' => 'boolean', 'required' => false),
            'browserCacheRulesEnabled'             => array('type' => 'boolean', 'required' => false),
            'apacheStaticHtmlDeliveryEnabled'      => array('type' => 'boolean', 'required' => false),
            'liteSpeedCacheEnabled'                => array('type' => 'boolean', 'required' => false),
            'varnishCliEnabled'                  => array('type' => 'boolean', 'required' => false),
            'configureVarnishConnection'          => array('type' => 'boolean', 'required' => false),
            'varnishCliMode'                       => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_mode_param'), 'validate_callback' => array($this, 'validate_varnish_mode_param')),
            'varnishCliServers'                    => array('type' => 'string', 'required' => false),
            'varnishCliKey'                        => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_secret_constant_param')),
            'clearVarnishCliKey'                   => array('type' => 'boolean', 'required' => false),
            'varnishCliTimeoutSeconds'             => array('type' => 'integer', 'required' => false),
            'varnishInvalidationsPerMinute'         => array('type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 600),
            'varnishCliMethod'                     => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_method_param'), 'validate_callback' => array($this, 'validate_varnish_method_param')),
            'varnishInvalidationStrategy'              => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_invalidation_strategy_param'), 'validate_callback' => array($this, 'validate_varnish_invalidation_strategy_param')),
            'varnishFlushScope'                    => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_varnish_flush_scope_param'), 'validate_callback' => array($this, 'validate_varnish_flush_scope_param')),
            'preRenderOnSave'                      => array('type' => 'boolean', 'required' => false),
            'alsoWarmTranslationPagesEnabled'      => array('type' => 'boolean', 'required' => false),
            'multilingualWarmPolicyV1'              => array('type' => 'object', 'required' => false),
            'woocommerceSafeModeEnabled'           => array('type' => 'boolean', 'required' => false),
            'cacheCleanupEnabled'                  => array('type' => 'boolean', 'required' => false),
            'apcuFlushOnScheduledCleanup'          => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeOpcache'             => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeApcu'                => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeLiteSpeed'           => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeNginx'               => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeVarnish'             => array('type' => 'boolean', 'required' => false),
            'flushAllIncludeElementor'           => array('type' => 'boolean', 'required' => false),
            // Legacy migration-only input; omitted from canonical settings output.
            'cronWarmEnabled'                      => array('type' => 'boolean', 'required' => false),
            'cronWarmStartAfterCleanup'            => array('type' => 'boolean', 'required' => false),
            'cronWarmStartAfterManualPurge'        => array('type' => 'boolean', 'required' => false),
            'warmUncachedUrlsOnFirstVisit'          => array('type' => 'boolean', 'required' => false),
            'warmCssBundlesEnabled'                    => array('type' => 'boolean', 'required' => false),
            'cacheCleanupIntervalHours'            => array('type' => 'integer', 'required' => false),
            'cssBundleCleanupGraceHours'       => array('type' => 'integer', 'required' => false),
            'cssBundleCleanupDeleteLimit'      => array('type' => 'integer', 'required' => false),
            'cronWarmPagesPerMinute'               => array('type' => 'integer', 'required' => false),
            'scheduledWarmLimit'                   => array('type' => 'integer', 'required' => false),
            'warmMenuLocation'                    => array('type' => 'string', 'required' => false),
            'warmMenuDepth'                       => array('type' => 'string', 'required' => false),
            'warmFullSiteSources'                 => array('type' => 'string', 'required' => false),
            'staleWhileRevalidateEnabled'          => array('type' => 'boolean', 'required' => false),
            'cacheFreshTtlMinutes'                 => array('type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 525600),
            'cacheMaxStaleMinutes'                 => array('type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 525600),
            'debugHeadersEnabled'                  => array('type' => 'boolean', 'required' => false),
            'openBrowserScannerInNewWindowEnabled' => array('type' => 'boolean', 'required' => false),
            'cacheExceptionPaths'                  => array('type' => 'string', 'required' => false),
            'cacheExceptionQueryArgs'              => array('type' => 'string', 'required' => false),
            'cacheQueryStringsEnabled'             => array('type' => 'boolean', 'required' => false),
            'cacheQueryStringAllowlist'            => array('type' => 'string', 'required' => false),
            'cacheQueryCombinationLevel'           => array('type' => 'string', 'required' => false, 'enum' => array('1', '2', '3', '4', 'all')),
            'cacheSafeTrackingCookiesEnabled'      => array('type' => 'boolean', 'required' => false),
            'safeTrackingCookieList'               => array('type' => 'string', 'required' => false),
            'unsafeCacheCookieList'                => array('type' => 'string', 'required' => false),
            'uninstallCleanupPolicy'             => array('type' => 'string', 'required' => false, 'sanitize_callback' => array($this, 'sanitize_uninstall_cleanup_policy_param'), 'validate_callback' => array($this, 'validate_uninstall_cleanup_policy_param')),
        );
    }

    public function sanitize_secret_constant_param($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        return str_replace("\0", '', (string) $value);
    }

    public function sanitize_javascript_strategy_param($value)
    {
        return class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'sanitize_javascript_strategy')
            ? Ultra_Cache_WP::sanitize_javascript_strategy($value)
            : 'off';
    }

    public function validate_javascript_strategy_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('off', 'defer', 'delay'), true);
    }

    public function sanitize_uninstall_cleanup_policy_param($value)
    {
        return class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'sanitize_uninstall_cleanup_policy')
            ? Ultra_Cache_WP::sanitize_uninstall_cleanup_policy($value)
            : 'delete_everything';
    }

    public function validate_uninstall_cleanup_policy_param($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('plugin_only', 'keep_settings', 'keep_settings_tables', 'delete_everything'), true);
    }


}
