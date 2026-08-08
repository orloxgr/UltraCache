/* UltraCache Admin - Profiles, settings normalization, import/export, and visible-list helpers */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function' || typeof admin.get !== 'function') {
		throw new Error('UltraCache admin namespace was not loaded.');
	}

	const core = admin.get('core');
	if (!core) {
		throw new Error('UltraCache admin core module was not loaded before settings.js.');
	}

	const { __ } = core;
	const runtime = {
		version: '',
		siteOrigin: (window.location && window.location.origin) ? window.location.origin : '',
		woocommercePublicPath: '',
	};

	function configure(nextRuntime) {
		if (!nextRuntime || typeof nextRuntime !== 'object') {
			return;
		}
		if (Object.prototype.hasOwnProperty.call(nextRuntime, 'version')) {
			runtime.version = String(nextRuntime.version || '');
		}
		if (Object.prototype.hasOwnProperty.call(nextRuntime, 'siteOrigin')) {
			runtime.siteOrigin = String(nextRuntime.siteOrigin || '');
		}
		if (Object.prototype.hasOwnProperty.call(nextRuntime, 'woocommercePublicPath')) {
			runtime.woocommercePublicPath = String(nextRuntime.woocommercePublicPath || '').toLowerCase();
		}
	}

	const IMPORT_EXPORT_SETTING_KEYS = [
		'pageCacheEnabled',
		'purgeAfterCoreUpdatesEnabled',
		'purgeAfterPluginUpdatesEnabled',
		'purgeAfterThemeUpdatesEnabled',
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend',
		'redisHost',
		'redisPort',
		'redisUsername',
		'redisDatabase',
		'redisPrefix',
		'redisUseTls',
		'redisPersistent',
		'redisConnectTimeoutMs',
		'redisReadTimeoutMs',
		'brotliEnabled',
		'gzipEnabled',
		'mediaOptimizationEnabled',
		'mediaGenerateOnUploadEnabled',
		'mediaGenerateOnDemandEnabled',
		'mediaUploadConversionEnabled',
		'mediaStaleWorkerThreshold',
		'imageUploadMaxSide',
		'mediaIgnoreColorProfilePreservation',
		'mediaUploadFormat',
		'mediaOutputMode',
		'mediaFallbackFormat',
		'mediaReplacementFormat',
		'mediaQuality',
		'javascriptStrategy',
		'deferJsEnabled',
		'delayAllJsEnabled',
		'firstPartyJsParallelExecutionEnabled',
		'thirdPartyJsParallelExecutionEnabled',
		'delayedLocalJsAutoStart',
		'delayedLocalJsAutoStartSeconds',
		'delayedJsAutostartAfterLoadEnabled',
		'delayedJsAutostartMousemoveEnabled',
		'delayedJsAutostartScrollEnabled',
		'delayedJsAutostartClickEnabled',
		'delayedJsAutostartTouchPointerEnabled',
		'delayedJsAutostartKeyboardEnabled',
		'deferJsForceList',
		'deferJsExcludeList',
		'delaySafeThirdPartyJsEnabled',
		'delayAllThirdPartyJsEnabled',
		'lazyMailerliteNonceEnabled',
		'delaySafeThirdPartyJsPatterns',
		'delayFunctionalThirdPartyJsEnabled',
		'delayFunctionalThirdPartyJsPatterns',
		'asyncExternalScriptsEnabled',
		'homepageCssBundleEnabled',
		'homepageCssBundleInlineEnabled',
		'leftoverCssBundleEnabled',
		'fontMixCssBundleEnabled',
		'fontMixCssBundleAsyncEnabled',
		'homepageCssBundleExcludeList',
		'homepageCssBundleMode',
		'cssBundleScope',
		'pageCssBundleOnEntryEnabled',
		'pageAsyncBundleOnEntryEnabled',
		'sliderSafeModeEnabled',
		'clsDimensionsEnabled',
		'asyncCssEnabled',
		'asyncExternalCssEnabled',
		'asyncCssExcludeList',
		'asyncExternalCssExcludeList',
		'aggressiveAsyncCssEnabled',
		'delayNonCriticalJsEnabled',
		'delayNonCriticalJsExcludeList',
		'lcpImagePriorityEnabled',
		'lcpFrontendDiscoveryEnabled',
		'lcpFrontendDiscoveryAdminsOnly',
		'lcpFrontendDiscoveryDuration',
		'lazyLoadImagesEnabled',
		'lazyLoadThirdPartyIframesEnabled',
		'lcpBoundaryDeferEnabled',
		'manualLcpHeroSelector',
		'mainThreadReliefEnabled',
		'criticalRequestChainReliefEnabled',
		'criticalResourcePreloadList',
		'criticalRequestChainDelayList',
		'assetChainCleanupEnabled',
		'assetCleanupWooProductAssetsEnabled',
		'assetCleanupProductFilterAssetsEnabled',
		'assetCleanupWooBlocksCssEnabled',
		'woocommerceCartFragmentsSuppressEmptyEnabled',
		'woocommerceCartFragmentsDelayEnabled',
		'woocommerceCartFragmentsDelayTiming',
		'assetCleanupExcludeList',
		'googleFontsSwapEnabled',
		'googleFontsLocalOptimizationEnabled',
		'googleFontsAdditionalScanUrls',
		'selfHostedFontCssOptimizationEnabled',
		'selfHostedFontRuntimeRewriteEnabled',
		'speculationRulesEnabled',
		'browserCacheRulesEnabled',
		'apacheStaticHtmlDeliveryEnabled',
		'liteSpeedCacheEnabled',
		'liteSpeedRefillAfterTargetedInvalidation',
		'liteSpeedWarmDuringSiteWarmup',
		'liteSpeedStalePurgeEnabled',
		'liteSpeedRefreshAheadEnabled',
		'liteSpeedRefreshAheadThresholdPercent',
		'liteSpeedRefreshAheadMaxPages',
		'liteSpeedRefreshAheadPinnedUrls',
		'varnishCliEnabled',
		'varnishConnectionConfigured',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishInvalidationsPerMinute',
		'varnishCliMethod',
		'varnishInvalidationStrategy',
		'varnishFlushScope',
		'preRenderOnSave',
		'woocommerceSafeModeEnabled',
		'cacheCleanupEnabled',
		'apcuFlushOnScheduledCleanup',
		'flushAllIncludeOpcache',
		'flushAllIncludeApcu',
		'flushAllIncludeLiteSpeed',
		'flushAllIncludeNginx',
		'flushAllIncludeVarnish',
		'flushAllIncludeElementor',
		'warmUncachedUrlsOnFirstVisit',
		'cronWarmPagesPerMinute',
		'scheduledWarmLimit',
		'warmMenuLocation',
		'warmMenuDepth',
		'warmFullSiteSources',
		'staleWhileRevalidateEnabled',
		'cacheCleanupIntervalHours',
		'cacheFreshTtlMinutes',
		'cacheMaxStaleMinutes',
		'cacheExceptionPaths',
		'cacheExceptionQueryArgs',
		'cacheQueryStringsEnabled',
		'cacheQueryStringAllowlist',
		'cacheSafeTrackingCookiesEnabled',
		'safeTrackingCookieList',
		'unsafeCacheCookieList',
	];


	const CRITICAL_SETTING_KEYS = [
		'pageCacheEnabled',
		'purgeAfterCoreUpdatesEnabled',
		'purgeAfterPluginUpdatesEnabled',
		'purgeAfterThemeUpdatesEnabled',
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend',
		'gzipEnabled',
		'brotliEnabled',
		'homepageCssBundleEnabled',
		'homepageCssBundleMode',
		'homepageCssBundleInlineEnabled',
		'leftoverCssBundleEnabled',
		'fontMixCssBundleEnabled',
		'fontMixCssBundleAsyncEnabled',
		'cssBundleScope',
		'pageCssBundleOnEntryEnabled',
		'pageAsyncBundleOnEntryEnabled',
		'javascriptStrategy',
		'deferJsEnabled',
		'delayAllJsEnabled',
		'firstPartyJsParallelExecutionEnabled',
		'thirdPartyJsParallelExecutionEnabled',
		'delaySafeThirdPartyJsEnabled',
		'delayAllThirdPartyJsEnabled',
		'lazyMailerliteNonceEnabled',
		'delayFunctionalThirdPartyJsEnabled',
		'delayNonCriticalJsEnabled',
		'criticalRequestChainReliefEnabled',
		'lcpBoundaryDeferEnabled',
		'manualLcpHeroSelector',
		'sliderSafeModeEnabled',
		'woocommerceSafeModeEnabled',
		'cacheQueryStringsEnabled',
		'cacheSafeTrackingCookiesEnabled',
		'googleFontsLocalOptimizationEnabled',
		'selfHostedFontCssOptimizationEnabled',
		'selfHostedFontRuntimeRewriteEnabled',
		'apacheStaticHtmlDeliveryEnabled',
		'liteSpeedCacheEnabled',
	];
	const PROFILE_OBJECT_CACHE_SETTING_KEYS = [
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend'
	];
	const PERFORMANCE_PROFILE_ORDER = ['off', 'safe', 'balanced', 'aggressive'];
	const PERFORMANCE_PROFILE_PRESERVED_SETTING_KEYS = [
		// Profiles must not change stats counters, scheduling/automation, Varnish infrastructure,
		// Redis connection infrastructure, or user-maintained visible lists/textareas.
		'cacheStatsEnabled',
		'liteSpeedCacheEnabled',
		'liteSpeedRefillAfterTargetedInvalidation',
		'liteSpeedWarmDuringSiteWarmup',
		'liteSpeedStalePurgeEnabled',
		'liteSpeedRefreshAheadEnabled',
		'liteSpeedRefreshAheadThresholdPercent',
		'liteSpeedRefreshAheadMaxPages',
		'liteSpeedRefreshAheadPinnedUrls',
		'varnishCliEnabled',
		'varnishConnectionConfigured',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishInvalidationsPerMinute',
		'varnishCliMethod',
		'varnishInvalidationStrategy',
		'varnishFlushScope',
		'varnishCliKeyConfigured',
		'cacheCleanupEnabled',
		'apcuFlushOnScheduledCleanup',
		'flushAllIncludeOpcache',
		'flushAllIncludeApcu',
		'flushAllIncludeLiteSpeed',
		'flushAllIncludeNginx',
		'flushAllIncludeVarnish',
		'flushAllIncludeElementor',
		'cronWarmStartAfterCleanup',
		'cronWarmStartAfterManualPurge',
		'warmUncachedUrlsOnFirstVisit',
		'cronWarmPagesPerMinute',
		'scheduledWarmLimit',
		'cacheCleanupIntervalHours',
		'cacheFreshTtlMinutes',
		'cacheMaxStaleMinutes',
		'cssBundleCleanupGraceHours',
		'cssBundleCleanupDeleteLimit',
		'redisHost',
		'redisPort',
		'redisUsername',
		'redisDatabase',
		'redisPrefix',
		'redisUseTls',
		'redisPersistent',
		'redisConnectTimeoutMs',
		'redisReadTimeoutMs',
		'deferJsForceList',
		'deferJsExcludeList',
		'delaySafeThirdPartyJsPatterns',
		'delayFunctionalThirdPartyJsPatterns',
		'delayNonCriticalJsExcludeList',
		'homepageCssBundleExcludeList',
		'asyncCssExcludeList',
		'asyncExternalCssExcludeList',
		'criticalResourcePreloadList',
		'criticalRequestChainDelayList',
		'assetCleanupExcludeList',
		'googleFontsAdditionalScanUrls',
		'cacheExceptionPaths',
		'cacheExceptionQueryArgs',
		'cacheQueryStringAllowlist',
		'manualLcpHeroSelector',
	];
	const PERFORMANCE_PROFILE_DISPLAY_ORDER = ['custom', 'off', 'safe', 'balanced', 'aggressive'];
	const PERFORMANCE_PROFILE_CUSTOM = {
		label: __("Custom", 'ultracache'),
		description: __("Turns on automatically when current settings no longer match a known preset. It preserves your manual choices.", 'ultracache'),
	};
	const PERFORMANCE_PROFILES = {
		off: { label: __("All Off", 'ultracache'), description: __("Disable optimization modules managed by profiles. Diagnostic counters, Automation & Scheduling, and Varnish settings are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: false, purgeAfterCoreUpdatesEnabled: true, purgeAfterPluginUpdatesEnabled: true, purgeAfterThemeUpdatesEnabled: true, objectCacheEnabled: false, brotliEnabled: false, gzipEnabled: false, cacheStatsEnabled: false, debugHeadersEnabled: false, mediaOptimizationEnabled: false, mediaGenerateOnUploadEnabled: false, mediaGenerateOnDemandEnabled: false, mediaUploadConversionEnabled: false, mediaIgnoreColorProfilePreservation: false,
			javascriptStrategy: 'off', deferJsEnabled: false, delayAllJsEnabled: false, firstPartyJsParallelExecutionEnabled: false, thirdPartyJsParallelExecutionEnabled: false, delayedLocalJsAutoStart: 'custom', delayedLocalJsAutoStartSeconds: 0.05, delayedJsAutostartAfterLoadEnabled: false, delayedJsAutostartMousemoveEnabled: false, delayedJsAutostartScrollEnabled: false, delayedJsAutostartClickEnabled: false, delayedJsAutostartTouchPointerEnabled: false, delayedJsAutostartKeyboardEnabled: false, delaySafeThirdPartyJsEnabled: false, delayAllThirdPartyJsEnabled: false, lazyMailerliteNonceEnabled: false, delayFunctionalThirdPartyJsEnabled: false, asyncExternalScriptsEnabled: false, homepageCssBundleEnabled: false, homepageCssBundleInlineEnabled: false, leftoverCssBundleEnabled: false, fontMixCssBundleEnabled: false, fontMixCssBundleAsyncEnabled: false, pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false, sliderSafeModeEnabled: false, clsDimensionsEnabled: false, asyncCssEnabled: false, asyncExternalCssEnabled: false, aggressiveAsyncCssEnabled: false, delayNonCriticalJsEnabled: false, lcpImagePriorityEnabled: false, lcpFrontendDiscoveryEnabled: false, lcpFrontendDiscoveryAdminsOnly: false, lcpFrontendDiscoveryDuration: 'indefinitely', lazyLoadImagesEnabled: false, lazyLoadThirdPartyIframesEnabled: false, lcpBoundaryDeferEnabled: false, manualLcpHeroSelector: '', mainThreadReliefEnabled: false, criticalRequestChainReliefEnabled: false,
			assetChainCleanupEnabled: false, assetCleanupWooProductAssetsEnabled: false, assetCleanupProductFilterAssetsEnabled: false, assetCleanupWooBlocksCssEnabled: false, woocommerceCartFragmentsSuppressEmptyEnabled: false, woocommerceCartFragmentsDelayEnabled: false, woocommerceCartFragmentsDelayTiming: 'delayed-js', googleFontsSwapEnabled: false, googleFontsLocalOptimizationEnabled: false, selfHostedFontCssOptimizationEnabled: false, selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: false, browserCacheRulesEnabled: false, apacheStaticHtmlDeliveryEnabled: false, liteSpeedCacheEnabled: false, liteSpeedRefillAfterTargetedInvalidation: false, liteSpeedWarmDuringSiteWarmup: false, liteSpeedStalePurgeEnabled: false, liteSpeedRefreshAheadEnabled: false, liteSpeedRefreshAheadThresholdPercent: 85, liteSpeedRefreshAheadMaxPages: 5, liteSpeedRefreshAheadPinnedUrls: '', preRenderOnSave: false, woocommerceSafeModeEnabled: false, cacheCleanupEnabled: false, apcuFlushOnScheduledCleanup: false, cronWarmStartAfterCleanup: false, cronWarmStartAfterManualPurge: false, warmUncachedUrlsOnFirstVisit: false, staleWhileRevalidateEnabled: false, cacheQueryStringsEnabled: false, cacheSafeTrackingCookiesEnabled: false,
			homepageCssBundleMode: 'safe', delayIconFontsEnabled: false, delayIconFontsAutoDetectEnabled: false, cssBundleScope: 'homepage', mediaOutputMode: 'webp', mediaFallbackFormat: 'original', mediaQuality: 'balanced',
		} },
		safe: { label: __("Safe", 'ultracache'), description: __("Safe baseline with frontend JavaScript manipulation disabled. Object Cache is enabled automatically with Redis/APCu/SQLite/Disk detection. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
			purgeAfterCoreUpdatesEnabled: true,
			purgeAfterPluginUpdatesEnabled: true,
			purgeAfterThemeUpdatesEnabled: true,
			objectCacheEnabled: true,
			redisHost: "127.0.0.1",
			redisPort: 6379,
			redisUsername: "",
			redisDatabase: 0,
			redisPrefix: "",
			redisUseTls: false,
			redisPersistent: false,
			redisConnectTimeoutMs: 200,
			redisReadTimeoutMs: 200,
			brotliEnabled: false,
			gzipEnabled: false,
			cacheStatsEnabled: false,
			mediaOptimizationEnabled: false,
			mediaGenerateOnUploadEnabled: false,
			mediaGenerateOnDemandEnabled: false,
			mediaUploadConversionEnabled: false,
			mediaIgnoreColorProfilePreservation: false,
			mediaOutputMode: "webp",
			mediaFallbackFormat: "original",
			mediaQuality: "balanced",
			javascriptStrategy: 'off',
			deferJsEnabled: false,
			delayAllJsEnabled: false,
			firstPartyJsParallelExecutionEnabled: false,
			thirdPartyJsParallelExecutionEnabled: false,
			delayedLocalJsAutoStart: 'custom',
			delayedLocalJsAutoStartSeconds: 0.05, delayedJsAutostartAfterLoadEnabled: false, delayedJsAutostartMousemoveEnabled: false, delayedJsAutostartScrollEnabled: false, delayedJsAutostartClickEnabled: false, delayedJsAutostartTouchPointerEnabled: false, delayedJsAutostartKeyboardEnabled: false,
			deferJsForceList: "",
			deferJsExcludeList: "",
			delaySafeThirdPartyJsEnabled: false,
			delayAllThirdPartyJsEnabled: false,
			lazyMailerliteNonceEnabled: false,
			// These are matching fragments for existing site scripts only; they do not load or contact third-party providers.
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtag(\ndataLayer\ngtm.start\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: false,
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: false,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: false,
			fontMixCssBundleEnabled: false,
			fontMixCssBundleAsyncEnabled: false,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "safe",
			cssBundleScope: "homepage",
			pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: false,
			clsDimensionsEnabled: true,
			asyncCssEnabled: false,
			asyncExternalCssEnabled: false,
			asyncCssExcludeList: "",
			asyncExternalCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: false,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: false,
			lcpFrontendDiscoveryEnabled: false,
			lcpFrontendDiscoveryAdminsOnly: false,
			lcpFrontendDiscoveryDuration: 'indefinitely',
			lazyLoadImagesEnabled: false,
			lazyLoadThirdPartyIframesEnabled: false,
			lcpBoundaryDeferEnabled: false,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: false,
			criticalRequestChainReliefEnabled: false,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: false,
			assetCleanupWooProductAssetsEnabled: false,
			assetCleanupProductFilterAssetsEnabled: false,
			assetCleanupWooBlocksCssEnabled: false,
			woocommerceCartFragmentsSuppressEmptyEnabled: false,
			woocommerceCartFragmentsDelayEnabled: false,
			woocommerceCartFragmentsDelayTiming: 'delayed-js',
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			apacheStaticHtmlDeliveryEnabled: false,
			liteSpeedCacheEnabled: false, liteSpeedRefillAfterTargetedInvalidation: false, liteSpeedWarmDuringSiteWarmup: false, liteSpeedStalePurgeEnabled: false, liteSpeedRefreshAheadEnabled: false, liteSpeedRefreshAheadThresholdPercent: 85, liteSpeedRefreshAheadMaxPages: 5, liteSpeedRefreshAheadPinnedUrls: '',
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishInvalidationsPerMinute: 10,
			varnishCliMethod: "BAN",
			varnishInvalidationStrategy: "auto",
			varnishFlushScope: "auto",
			preRenderOnSave: false,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			flushAllIncludeElementor: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			warmUncachedUrlsOnFirstVisit: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 1440,
			cacheMaxStaleMinutes: 2880,
			cacheExceptionQueryArgs: "preview\ncustomize_changeset_uuid\ncustomize_autosaved\nelementor-preview\nvc_editable\net_fb\nadd-to-cart\nwc-ajax\nremove_item\nundo_item\napply_coupon\nremove_coupon\norder_again\n_wpnonce\n_ajax_nonce\nnonce\nsecurity\ntoken\nauth\nauth_token\naccess_token\nkey\norder_key\npassword\npass\npwd\nredirect_to\ncustomer-logout\nlogout\npay_for_order\ncancel_order\ndownload_file",
			cacheQueryStringsEnabled: false,
			cacheSafeTrackingCookiesEnabled: true,
		} },
		balanced: { label: __("Balanced", 'ultracache'), description: __("Balanced profile enables selected delayed JavaScript features without Delay all JS, uses aggressive CSS bundling for better speed, and auto-detects Object Cache with Redis/APCu/SQLite/Disk. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
			purgeAfterCoreUpdatesEnabled: true,
			purgeAfterPluginUpdatesEnabled: true,
			purgeAfterThemeUpdatesEnabled: true,
			objectCacheEnabled: true,
			redisHost: "127.0.0.1",
			redisPort: 6379,
			redisUsername: "",
			redisDatabase: 0,
			redisPrefix: "",
			redisUseTls: false,
			redisPersistent: false,
			redisConnectTimeoutMs: 200,
			redisReadTimeoutMs: 200,
			brotliEnabled: false,
			gzipEnabled: false,
			cacheStatsEnabled: false,
			mediaOptimizationEnabled: true,
			mediaGenerateOnUploadEnabled: true,
			mediaGenerateOnDemandEnabled: false,
			mediaUploadConversionEnabled: false,
			mediaIgnoreColorProfilePreservation: false,
			mediaOutputMode: "webp",
			mediaFallbackFormat: "original",
			mediaQuality: "balanced",
			javascriptStrategy: 'off',
			deferJsEnabled: false,
			delayAllJsEnabled: false,
			firstPartyJsParallelExecutionEnabled: false,
			thirdPartyJsParallelExecutionEnabled: false,
			delayedLocalJsAutoStart: 'custom',
			delayedLocalJsAutoStartSeconds: 0.05, delayedJsAutostartAfterLoadEnabled: false, delayedJsAutostartMousemoveEnabled: false, delayedJsAutostartScrollEnabled: false, delayedJsAutostartClickEnabled: false, delayedJsAutostartTouchPointerEnabled: false, delayedJsAutostartKeyboardEnabled: false,
			deferJsForceList: "",
			deferJsExcludeList: "",
			delaySafeThirdPartyJsEnabled: true,
			delayAllThirdPartyJsEnabled: true,
			lazyMailerliteNonceEnabled: false,
			// These are matching fragments for existing site scripts only; they do not load or contact third-party providers.
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtag(\ndataLayer\ngtm.start\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: true,
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: true,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: true,
			fontMixCssBundleEnabled: false,
			fontMixCssBundleAsyncEnabled: false,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "aggressive",
			cssBundleScope: "shared",
			pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: true,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: false,
			clsDimensionsEnabled: true,
			asyncCssEnabled: false,
			asyncExternalCssEnabled: false,
			asyncCssExcludeList: "",
			asyncExternalCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: true,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: true,
			lazyLoadImagesEnabled: true,
			lazyLoadThirdPartyIframesEnabled: false,
			lcpBoundaryDeferEnabled: false,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: false,
			criticalRequestChainReliefEnabled: false,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: false,
			assetCleanupWooProductAssetsEnabled: false,
			assetCleanupProductFilterAssetsEnabled: false,
			assetCleanupWooBlocksCssEnabled: false,
			woocommerceCartFragmentsSuppressEmptyEnabled: false,
			woocommerceCartFragmentsDelayEnabled: false,
			woocommerceCartFragmentsDelayTiming: 'delayed-js',
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			apacheStaticHtmlDeliveryEnabled: false,
			liteSpeedCacheEnabled: false, liteSpeedRefillAfterTargetedInvalidation: false, liteSpeedWarmDuringSiteWarmup: false, liteSpeedStalePurgeEnabled: false, liteSpeedRefreshAheadEnabled: false, liteSpeedRefreshAheadThresholdPercent: 85, liteSpeedRefreshAheadMaxPages: 5, liteSpeedRefreshAheadPinnedUrls: '',
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishInvalidationsPerMinute: 10,
			varnishCliMethod: "BAN",
			varnishInvalidationStrategy: "auto",
			varnishFlushScope: "auto",
			preRenderOnSave: false,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			flushAllIncludeElementor: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			warmUncachedUrlsOnFirstVisit: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 1440,
			cacheMaxStaleMinutes: 2880,
			cacheExceptionQueryArgs: "preview\ncustomize_changeset_uuid\ncustomize_autosaved\nelementor-preview\nvc_editable\net_fb\nadd-to-cart\nwc-ajax\nremove_item\nundo_item\napply_coupon\nremove_coupon\norder_again\n_wpnonce\n_ajax_nonce\nnonce\nsecurity\ntoken\nauth\nauth_token\naccess_token\nkey\norder_key\npassword\npass\npwd\nredirect_to\ncustomer-logout\nlogout\npay_for_order\ncancel_order\ndownload_file",
			cacheQueryStringsEnabled: false,
			cacheSafeTrackingCookiesEnabled: true,
		} },
		aggressive: { label: __("Aggressive", 'ultracache'), description: __("Aggressive profile enables Balanced JavaScript with native Defer as the base strategy, targeted delay modules, aggressive CSS bundling, Warm affected pages after save, Apache Static HTML Delivery, Mouse move and Scroll delayed-JS triggers, a 2 second fallback, and Ignore color profile preservation for broader AVIF/WebP conversion coverage. Disable that media option when strict embedded-profile color fidelity is required. Scan Browser Runtime Errors is recommended to build speed-first Defer Instead fixes before compatibility exclusions. Object Cache is auto-detected. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
			purgeAfterCoreUpdatesEnabled: true,
			purgeAfterPluginUpdatesEnabled: true,
			purgeAfterThemeUpdatesEnabled: true,
			objectCacheEnabled: true,
			redisHost: "127.0.0.1",
			redisPort: 6379,
			redisUsername: "",
			redisDatabase: 0,
			redisPrefix: "",
			redisUseTls: false,
			redisPersistent: false,
			redisConnectTimeoutMs: 200,
			redisReadTimeoutMs: 200,
			brotliEnabled: false,
			gzipEnabled: false,
			cacheStatsEnabled: false,
			mediaOptimizationEnabled: true,
			mediaGenerateOnUploadEnabled: true,
			mediaGenerateOnDemandEnabled: true,
			mediaUploadConversionEnabled: false,
			mediaIgnoreColorProfilePreservation: true,
			mediaOutputMode: "webp",
			mediaFallbackFormat: "original",
			mediaQuality: "balanced",
			javascriptStrategy: 'defer',
			deferJsEnabled: true,
			delayAllJsEnabled: false,
			firstPartyJsParallelExecutionEnabled: false,
			thirdPartyJsParallelExecutionEnabled: false,
			delayedLocalJsAutoStart: 'custom',
			delayedLocalJsAutoStartSeconds: 2, delayedJsAutostartAfterLoadEnabled: false, delayedJsAutostartMousemoveEnabled: true, delayedJsAutostartScrollEnabled: true, delayedJsAutostartClickEnabled: false, delayedJsAutostartTouchPointerEnabled: false, delayedJsAutostartKeyboardEnabled: false,
			deferJsForceList: "",
			delaySafeThirdPartyJsEnabled: true,
			delayAllThirdPartyJsEnabled: true,
			lazyMailerliteNonceEnabled: false,
			// These are matching fragments for existing site scripts only; they do not load or contact third-party providers.
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtag(\ndataLayer\ngtm.start\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: true,
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: true,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: true,
			fontMixCssBundleEnabled: false,
			fontMixCssBundleAsyncEnabled: false,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "aggressive",
			cssBundleScope: "per-page",
			pageCssBundleOnEntryEnabled: true, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: true,
			clsDimensionsEnabled: true,
			asyncCssEnabled: false,
			asyncExternalCssEnabled: false,
			asyncCssExcludeList: "",
			asyncExternalCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: false,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: true,
			lazyLoadImagesEnabled: true,
			lazyLoadThirdPartyIframesEnabled: false,
			lcpBoundaryDeferEnabled: false,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: false,
			criticalRequestChainReliefEnabled: false,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: true,
			assetCleanupWooProductAssetsEnabled: true,
			assetCleanupProductFilterAssetsEnabled: true,
			assetCleanupWooBlocksCssEnabled: true,
			woocommerceCartFragmentsSuppressEmptyEnabled: false,
			woocommerceCartFragmentsDelayEnabled: false,
			woocommerceCartFragmentsDelayTiming: 'delayed-js',
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			apacheStaticHtmlDeliveryEnabled: true,
			liteSpeedCacheEnabled: false, liteSpeedRefillAfterTargetedInvalidation: false, liteSpeedWarmDuringSiteWarmup: false, liteSpeedStalePurgeEnabled: false, liteSpeedRefreshAheadEnabled: false, liteSpeedRefreshAheadThresholdPercent: 85, liteSpeedRefreshAheadMaxPages: 5, liteSpeedRefreshAheadPinnedUrls: '',
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishInvalidationsPerMinute: 10,
			varnishCliMethod: "BAN",
			varnishInvalidationStrategy: "auto",
			varnishFlushScope: "auto",
			preRenderOnSave: true,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			flushAllIncludeElementor: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			warmUncachedUrlsOnFirstVisit: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 1440,
			cacheMaxStaleMinutes: 2880,
			cacheExceptionQueryArgs: "preview\ncustomize_changeset_uuid\ncustomize_autosaved\nelementor-preview\nvc_editable\net_fb\nadd-to-cart\nwc-ajax\nremove_item\nundo_item\napply_coupon\nremove_coupon\norder_again\n_wpnonce\n_ajax_nonce\nnonce\nsecurity\ntoken\nauth\nauth_token\naccess_token\nkey\norder_key\npassword\npass\npwd\nredirect_to\ncustomer-logout\nlogout\npay_for_order\ncancel_order\ndownload_file",
			cacheQueryStringsEnabled: true,
			cacheSafeTrackingCookiesEnabled: true,
		} },
	};


	function stripPerformanceProfilePreservedSettings(patch) {
		const next = Object.assign({}, patch || {});
		PERFORMANCE_PROFILE_PRESERVED_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(next, key)) {
				delete next[key];
			}
		});
		return next;
	}

	function getPerformanceProfilePatch(profileKey) {
		const profile = PERFORMANCE_PROFILES[profileKey];
		if (!profile || !profile.patch) {
			return {};
		}
		const merged = profileKey === 'off'
			? Object.assign({}, profile.patch)
			: Object.assign({}, PERFORMANCE_PROFILES.off.patch, profile.patch);
		return stripPerformanceProfilePreservedSettings(merged);
	}

	function splitProfileObjectCachePatch(patch) {
		const mainPatch = Object.assign({}, patch || {});
		const objectPatch = {};
		PROFILE_OBJECT_CACHE_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(mainPatch, key)) {
				objectPatch[key] = mainPatch[key];
				delete mainPatch[key];
			}
		});
		return { mainPatch, objectPatch };
	}

	function settingValueMatchesProfileValue(currentValue, profileValue) {
		if (typeof profileValue === 'boolean') { return !!currentValue === profileValue; }
		if (typeof profileValue === 'number') { return Number(currentValue) === profileValue; }
		return String(currentValue || '') === String(profileValue || '');
	}

	function normalizeJavascriptStrategy(value) {
		const normalized = String(value || '').toLowerCase();
		return ['off', 'defer', 'delay'].indexOf(normalized) !== -1 ? normalized : 'off';
	}

	function getJavascriptStrategyValue(sourceSettings) {
		const current = sourceSettings && typeof sourceSettings === 'object' ? sourceSettings : {};
		return normalizeJavascriptStrategy(current.javascriptStrategy);
	}

	function getJavascriptStrategyPatch(value) {
		const strategy = normalizeJavascriptStrategy(value);
		return {
			javascriptStrategy: strategy,
			deferJsEnabled: strategy === 'defer',
			delayAllJsEnabled: strategy === 'delay',
		};
	}

	function getJavascriptStrategyDescription(strategy) {
		strategy = normalizeJavascriptStrategy(strategy);
		if (strategy === 'defer') {
			return __('Defer: faster, but more prone to errors. Use the scanners in exclusions to detect and fix problematic scripts.', 'ultracache');
		}
		if (strategy === 'delay') {
			return __('Delay: safer for dependency order because UltraCache runs eligible scripts through the ordered delayed loader. Exclusions still win.', 'ultracache');
		}
		return __('Off: disables only the base Defer JS and Delay all JS modes. The independent third-party, local, and LCP delay switches below can still be used.', 'ultracache');
	}

	function normalizeWooCommerceFrontendStrategy(value) {
		const normalized = String(value || '').toLowerCase();
		return ['off', 'safe', 'balanced', 'aggressive', 'custom'].indexOf(normalized) !== -1 ? normalized : 'custom';
	}

	function getWooCommerceCartFragmentsBehaviorValue(sourceSettings) {
		const current = sourceSettings && typeof sourceSettings === 'object' ? sourceSettings : {};
		if (!!current.woocommerceCartFragmentsSuppressEmptyEnabled) {
			return 'suppress-empty';
		}
		if (!!current.woocommerceCartFragmentsDelayEnabled) {
			return 'delay';
		}
		return 'off';
	}

	function getWooCommerceCartFragmentsBehaviorPatch(value) {
		const behavior = String(value || 'off').toLowerCase();
		if (behavior === 'suppress-empty') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: true,
				woocommerceCartFragmentsDelayEnabled: false,
			};
		}
		if (behavior === 'delay') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: false,
				woocommerceCartFragmentsDelayEnabled: true,
			};
		}
		return {
			woocommerceCartFragmentsSuppressEmptyEnabled: false,
			woocommerceCartFragmentsDelayEnabled: false,
		};
	}

	function getWooCommerceFrontendStrategyValue(sourceSettings) {
		const current = sourceSettings && typeof sourceSettings === 'object' ? sourceSettings : {};
		const suppressEmptyCartFragments = !!current.woocommerceCartFragmentsSuppressEmptyEnabled;
		const delayCartFragments = !!current.woocommerceCartFragmentsDelayEnabled;
		const cleanupEnabled = !!current.assetChainCleanupEnabled;
		const cleanupProductAssets = !!current.assetCleanupWooProductAssetsEnabled;
		const cleanupFilterAssets = !!current.assetCleanupProductFilterAssetsEnabled;
		const cleanupBlocksCss = !!current.assetCleanupWooBlocksCssEnabled;

		if (!suppressEmptyCartFragments && !delayCartFragments && !cleanupEnabled && !cleanupProductAssets && !cleanupFilterAssets && !cleanupBlocksCss) {
			return 'off';
		}

		if (!suppressEmptyCartFragments && delayCartFragments && !cleanupEnabled && !cleanupProductAssets && !cleanupFilterAssets && !cleanupBlocksCss) {
			return 'safe';
		}

		if (!suppressEmptyCartFragments && delayCartFragments && cleanupEnabled && !cleanupProductAssets && !cleanupFilterAssets && cleanupBlocksCss) {
			return 'balanced';
		}

		if (!suppressEmptyCartFragments && delayCartFragments && cleanupEnabled && cleanupProductAssets && cleanupFilterAssets && cleanupBlocksCss) {
			return 'aggressive';
		}

		return 'custom';
	}

	function getWooCommerceFrontendStrategyPatch(value) {
		const strategy = normalizeWooCommerceFrontendStrategy(value);
		if (strategy === 'safe') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: false,
				woocommerceCartFragmentsDelayEnabled: true,
				assetChainCleanupEnabled: false,
				assetCleanupWooProductAssetsEnabled: false,
				assetCleanupProductFilterAssetsEnabled: false,
				assetCleanupWooBlocksCssEnabled: false,
			};
		}
		if (strategy === 'balanced') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: false,
				woocommerceCartFragmentsDelayEnabled: true,
				assetChainCleanupEnabled: true,
				assetCleanupWooProductAssetsEnabled: false,
				assetCleanupProductFilterAssetsEnabled: false,
				assetCleanupWooBlocksCssEnabled: true,
			};
		}
		if (strategy === 'aggressive') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: false,
				woocommerceCartFragmentsDelayEnabled: true,
				assetChainCleanupEnabled: true,
				assetCleanupWooProductAssetsEnabled: true,
				assetCleanupProductFilterAssetsEnabled: true,
				assetCleanupWooBlocksCssEnabled: true,
			};
		}
		if (strategy === 'off') {
			return {
				woocommerceCartFragmentsSuppressEmptyEnabled: false,
				woocommerceCartFragmentsDelayEnabled: false,
				assetChainCleanupEnabled: false,
				assetCleanupWooProductAssetsEnabled: false,
				assetCleanupProductFilterAssetsEnabled: false,
				assetCleanupWooBlocksCssEnabled: false,
			};
		}
		return {};
	}


	function normalizeHtmlCompressionDelivery(value) {
		const normalized = String(value || '').toLowerCase();
		return ['off', 'gzip', 'brotli'].indexOf(normalized) !== -1 ? normalized : 'off';
	}

	function getHtmlCompressionDeliveryValue(sourceSettings) {
		const current = sourceSettings && typeof sourceSettings === 'object' ? sourceSettings : {};
		if (current.brotliEnabled) {
			return 'brotli';
		}
		if (current.gzipEnabled) {
			return 'gzip';
		}
		return 'off';
	}

	function getHtmlCompressionDeliveryPatch(value) {
		const mode = normalizeHtmlCompressionDelivery(value);
		return {
			gzipEnabled: mode === 'gzip',
			brotliEnabled: mode === 'brotli',
		};
	}

	function getHtmlCompressionDeliveryDescription(browserProbe) {
		if (browserProbe && browserProbe.message) {
			return browserProbe.message;
		}
		return __('Choose whether UltraCache should serve compressed HTML cache files. If your server already serves gzip or Brotli, UltraCache keeps this off.', 'ultracache');
	}

	function normalizeLineListItems(value) {
		const seen = {};
		const items = [];
		String(value || '').split(/\r?\n/).forEach((line) => {
			const item = String(line || '').trim();
			const key = item.toLowerCase();
			if (!item || seen[key]) {
				return;
			}
			seen[key] = true;
			items.push(item);
		});
		return items;
	}

	function mergeLineListAppendOnly(currentValue, appendItems) {
		const currentItems = normalizeLineListItems(currentValue);
		const seen = {};
		currentItems.forEach((item) => { seen[item.toLowerCase()] = true; });
		(Array.isArray(appendItems) ? appendItems : normalizeLineListItems(appendItems)).forEach((item) => {
			item = String(item || '').trim();
			const key = item.toLowerCase();
			if (!item || seen[key]) {
				return;
			}
			seen[key] = true;
			currentItems.push(item);
		});
		return currentItems.join('\n');
	}

	function getActivePerformanceProfile(settings) {
		const current = settings && typeof settings === 'object' ? settings : {};
		for (const key of PERFORMANCE_PROFILE_ORDER) {
			const profile = PERFORMANCE_PROFILES[key];
			const patch = getPerformanceProfilePatch(key);
			if (Object.keys(patch).every((settingKey) => settingValueMatchesProfileValue(current[settingKey], patch[settingKey]))) {
				return key;
			}
		}
		return 'custom';
	}

	function pickTransferableSettings(source) {
		const picked = {};
		const input = source && typeof source === 'object' ? source : {};

		IMPORT_EXPORT_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(input, key)) {
				picked[key] = input[key];
			}
		});

		return picked;
	}

	function buildSettingsExportPayload(source) {
		return {
			format: 'ultracache-settings-v1',
			plugin: 'UltraCache',
			version: runtime.version || '',
			exportedAt: new Date().toISOString(),
			site: runtime.siteOrigin || '',
			settings: pickTransferableSettings(source),
		};
	}

	function getTransferableSettingsFromImport(rawValue) {
		if (!rawValue || typeof rawValue !== 'object' || Array.isArray(rawValue)) {
			throw new Error('Invalid import file. Expected a JSON object.');
		}

		const candidate = rawValue.settings && typeof rawValue.settings === 'object' && !Array.isArray(rawValue.settings)
			? rawValue.settings
			: rawValue;
		const picked = pickTransferableSettings(candidate);
		if (Object.prototype.hasOwnProperty.call(candidate, 'mediaOutputMode')) {
			const legacyMediaFormat = String(candidate.mediaOutputMode || '').toLowerCase() === 'avif' ? 'avif' : 'webp';
			if (!Object.prototype.hasOwnProperty.call(candidate, 'mediaUploadFormat')) {
				picked.mediaUploadFormat = legacyMediaFormat;
			}
			if (!Object.prototype.hasOwnProperty.call(candidate, 'mediaReplacementFormat')) {
				picked.mediaReplacementFormat = legacyMediaFormat;
			}
		}

		if (!Object.keys(picked).length) {
			throw new Error('No supported UltraCache settings were found in this file.');
		}

		return picked;
	}

	function triggerFileDownload(filename, content, mimeType) {
		const blob = new Blob([content], { type: mimeType || 'application/json' });
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
	}

	function normalizeSettingListLines(value) {
		return String(value || '')
			.split(/\r?\n/)
			.map((line) => String(line || '').trim())
			.filter((line) => line.length > 0);
	}

	function isGenericRootJsExclusionLine(line) {
		const value = String(line || '').trim().toLowerCase();
		return [
			'woocommerce',
			'wordpress',
			'frontend',
			'main',
			'plugin',
			'plugins',
			'script',
			'scripts',
			'data',
			'params',
			'cart',
			'checkout',
			'account'
		].indexOf(value) !== -1;
	}

	function genericRootJsExclusionCoversTarget(line, target) {
		const value = String(line || '').trim().toLowerCase();
		const candidate = String(target || '').trim().toLowerCase();
		if (!value || !candidate) {
			return false;
		}
		if (candidate === value) {
			return true;
		}
		if (value === 'woocommerce') {
			return (runtime.woocommercePublicPath && candidate.indexOf(runtime.woocommercePublicPath) !== -1)
				|| candidate.indexOf('/woocommerce/assets/') !== -1;
		}
		return false;
	}

	function jsExclusionLineCoversTarget(existingLine, targetLine) {
		const existing = String(existingLine || '').trim().toLowerCase();
		const target = String(targetLine || '').trim().toLowerCase();
		if (!existing || !target) {
			return false;
		}
		if (isGenericRootJsExclusionLine(existing)) {
			return genericRootJsExclusionCoversTarget(existing, target);
		}
		return existing === target || target.indexOf(existing) !== -1;
	}

	function mergeUniqueSettingLines(currentValue, additions) {
		const currentLines = normalizeSettingListLines(currentValue);
		const normalizedExisting = currentLines.map((line) => line.toLowerCase());
		const addedLines = [];
		normalizeSettingListLines(Array.isArray(additions) ? additions.join('\n') : additions).forEach((line) => {
			const normalized = line.toLowerCase();
			const duplicate = normalizedExisting.some((existing) => jsExclusionLineCoversTarget(existing, normalized));
			if (!duplicate) {
				normalizedExisting.push(normalized);
				currentLines.push(line);
				addedLines.push(line);
			}
		});
		return {
			value: currentLines.join('\n'),
			addedLines,
			added: addedLines.length,
		};
	}

	function settingLinesOverlap(leftLine, rightLine) {
		const left = String(leftLine || '').trim().toLowerCase();
		const right = String(rightLine || '').trim().toLowerCase();
		if (!left || !right) {
			return false;
		}
		return jsExclusionLineCoversTarget(left, right) || jsExclusionLineCoversTarget(right, left);
	}

	function removeOverlappingSettingLines(currentValue, removals) {
		const removalLines = normalizeSettingListLines(Array.isArray(removals) ? removals.join('\n') : removals);
		if (!removalLines.length) {
			return {
				value: normalizeSettingListLines(currentValue).join('\n'),
				removedLines: [],
				removed: 0,
			};
		}
		const keptLines = [];
		const removedLines = [];
		normalizeSettingListLines(currentValue).forEach((line) => {
			const overlaps = removalLines.some((removalLine) => settingLinesOverlap(line, removalLine));
			if (overlaps) {
				removedLines.push(line);
			} else {
				keptLines.push(line);
			}
		});
		return {
			value: keptLines.join('\n'),
			removedLines,
			removed: removedLines.length,
		};
	}


	admin.define('settings', {
		configure,
		CRITICAL_SETTING_KEYS,
		PERFORMANCE_PROFILE_ORDER,
		PERFORMANCE_PROFILE_CUSTOM,
		PERFORMANCE_PROFILES,
		stripPerformanceProfilePreservedSettings,
		getPerformanceProfilePatch,
		splitProfileObjectCachePatch,
		settingValueMatchesProfileValue,
		normalizeJavascriptStrategy,
		getJavascriptStrategyValue,
		getJavascriptStrategyPatch,
		getJavascriptStrategyDescription,
		normalizeWooCommerceFrontendStrategy,
		getWooCommerceCartFragmentsBehaviorValue,
		getWooCommerceCartFragmentsBehaviorPatch,
		getWooCommerceFrontendStrategyValue,
		getWooCommerceFrontendStrategyPatch,
		normalizeHtmlCompressionDelivery,
		getHtmlCompressionDeliveryValue,
		getHtmlCompressionDeliveryPatch,
		getHtmlCompressionDeliveryDescription,
		normalizeLineListItems,
		mergeLineListAppendOnly,
		getActivePerformanceProfile,
		buildSettingsExportPayload,
		getTransferableSettingsFromImport,
		triggerFileDownload,
		normalizeSettingListLines,
		jsExclusionLineCoversTarget,
		mergeUniqueSettingLines,
		settingLinesOverlap,
		removeOverlappingSettingLines,
	});
})(window);
