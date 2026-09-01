/* UltraCache Admin - Optimal settings recipe, settings normalization, import/export, and visible-list helpers */
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
		'delayedJsMinimumReleaseSeconds',
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
		'asyncConsentCssEnabled',
		'asyncConsentCssAutoEnabled',
		'asyncCssExcludeList',
		'asyncExternalCssExcludeList',
		'aggressiveAsyncCssEnabled',
		'delayNonCriticalJsEnabled',
		'protectWpBakeryAnimationsEnabled',
		'protectElementorCompatibilityEnabled',
		'realCookieBannerCompatibilityEnabled',
		'complianzCompatibilityEnabled',
		'woocommerceVariableProductCompatibilityEnabled',
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
		'alsoWarmTranslationPagesEnabled',
		'multilingualWarmPolicyV1',
		'openBrowserScannerInNewWindowEnabled',
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
		'warmCssBundlesEnabled',
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
		'cacheQueryCombinationLevel',
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
		'warmCssBundlesEnabled',
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
		'protectWpBakeryAnimationsEnabled',
		'protectElementorCompatibilityEnabled',
		'realCookieBannerCompatibilityEnabled',
		'complianzCompatibilityEnabled',
		'woocommerceVariableProductCompatibilityEnabled',
		'criticalRequestChainReliefEnabled',
		'lcpBoundaryDeferEnabled',
		'manualLcpHeroSelector',
		'sliderSafeModeEnabled',
		'woocommerceSafeModeEnabled',
		'cacheQueryStringsEnabled',
		'cacheQueryCombinationLevel',
		'cacheSafeTrackingCookiesEnabled',
		'googleFontsLocalOptimizationEnabled',
		'selfHostedFontCssOptimizationEnabled',
		'selfHostedFontRuntimeRewriteEnabled',
		'apacheStaticHtmlDeliveryEnabled',
		'liteSpeedCacheEnabled',
	];
	const OPTIMAL_OBJECT_CACHE_SETTING_KEYS = [
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend'
	];
	const OPTIMAL_COMPRESSION_SETTING_KEYS = [
		'gzipEnabled',
		'brotliEnabled'
	];
	const OPTIMAL_SETTINGS_PRESERVED_SETTING_KEYS = [
		// Optimal Settings must not change stats counters, scheduling/automation, Varnish infrastructure,
		// Redis connection infrastructure, or user-maintained visible lists/textareas.
		'cacheStatsEnabled',
		'liteSpeedCacheEnabled',
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
		'cacheQueryCombinationLevel',
		'manualLcpHeroSelector',
	];
	const OPTIMAL_SETTINGS_RECIPE = {
		label: __("Start Wizard", 'ultracache'),
		description: __("Apply UltraCache's recommended performance configuration. If you have manually added credentials, schedules, exclusions, safeguards, and other user-maintained lists the wizard will preserve them.", 'ultracache'),
		patch: {
		  "pageCacheEnabled": true,
		  "purgeAfterCoreUpdatesEnabled": true,
		  "purgeAfterPluginUpdatesEnabled": true,
		  "purgeAfterThemeUpdatesEnabled": true,
		  "objectCacheEnabled": true,
		  "brotliEnabled": false,
		  "gzipEnabled": false,
		  "debugHeadersEnabled": false,
		  "mediaOptimizationEnabled": true,
		  "mediaGenerateOnUploadEnabled": true,
		  "mediaGenerateOnDemandEnabled": true,
		  "mediaUploadConversionEnabled": false,
		  "imageUploadMaxSide": 1920,
		  "mediaIgnoreColorProfilePreservation": true,
		  "javascriptStrategy": "defer",
		  "deferJsEnabled": true,
		  "delayAllJsEnabled": false,
		  "firstPartyJsParallelExecutionEnabled": false,
		  "thirdPartyJsParallelExecutionEnabled": false,
		  "delayedLocalJsAutoStart": "custom",
		  "delayedLocalJsAutoStartSeconds": 2,
		  "delayedJsMinimumReleaseSeconds": 0,
		  "delayedJsAutostartAfterLoadEnabled": false,
		  "delayedJsAutostartMousemoveEnabled": true,
		  "delayedJsAutostartScrollEnabled": false,
		  "delayedJsAutostartClickEnabled": true,
		  "delayedJsAutostartTouchPointerEnabled": true,
		  "delayedJsAutostartKeyboardEnabled": true,
		  "delaySafeThirdPartyJsEnabled": true,
		  "delayAllThirdPartyJsEnabled": true,
		  "lazyMailerliteNonceEnabled": false,
		  "delayFunctionalThirdPartyJsEnabled": true,
		  "asyncExternalScriptsEnabled": false,
		  "homepageCssBundleEnabled": true,
		  "warmCssBundlesEnabled": true,
		  "homepageCssBundleInlineEnabled": false,
		  "leftoverCssBundleEnabled": true,
		  "fontMixCssBundleEnabled": true,
		  "fontMixCssBundleAsyncEnabled": false,
		  "pageCssBundleOnEntryEnabled": true,
		  "pageAsyncBundleOnEntryEnabled": false,
		  "frontendSafeModeEnabled": false,
		  "sliderSafeModeEnabled": true,
		  "clsDimensionsEnabled": true,
		  "asyncCssEnabled": false,
		  "asyncExternalCssEnabled": true,
		  "aggressiveAsyncCssEnabled": false,
		  "delayNonCriticalJsEnabled": true,
		  "lcpImagePriorityEnabled": true,
		  "lcpFrontendDiscoveryEnabled": true,
		  "lcpFrontendDiscoveryAdminsOnly": false,
		  "lcpFrontendDiscoveryDuration": "indefinitely",
		  "lazyLoadImagesEnabled": true,
		  "lazyLoadThirdPartyIframesEnabled": true,
		  "lcpBoundaryDeferEnabled": false,
		  "mainThreadReliefEnabled": false,
		  "criticalRequestChainReliefEnabled": false,
		  "assetChainCleanupEnabled": true,
		  "assetCleanupWooProductAssetsEnabled": true,
		  "assetCleanupProductFilterAssetsEnabled": true,
		  "assetCleanupWooBlocksCssEnabled": true,
		  "woocommerceCartFragmentsSuppressEmptyEnabled": false,
		  "woocommerceCartFragmentsDelayEnabled": false,
		  "woocommerceCartFragmentsDelayTiming": "delayed-js",
		  "googleFontsSwapEnabled": true,
		  "googleFontsLocalOptimizationEnabled": true,
		  "selfHostedFontCssOptimizationEnabled": true,
		  "selfHostedFontRuntimeRewriteEnabled": false,
		  "speculationRulesEnabled": true,
		  "browserCacheRulesEnabled": true,
		  "preRenderOnSave": true,
		  "alsoWarmTranslationPagesEnabled": true,
		  "woocommerceSafeModeEnabled": true,
		  "woocommerceVariableProductCompatibilityEnabled": false,
		  "staleWhileRevalidateEnabled": true,
		  "cacheQueryStringsEnabled": true,
		  "cacheSafeTrackingCookiesEnabled": true,
		  "homepageCssBundleMode": "aggressive",
		  "delayIconFontsEnabled": true,
		  "delayIconFontsAutoDetectEnabled": false,
		  "cssBundleScope": "per-page",
		  "mediaOutputMode": "webp",
		  "mediaFallbackFormat": "original",
		  "mediaQuality": "compact"
	},
	};

	function stripOptimalSettingsPreservedSettings(patch) {
		const next = Object.assign({}, patch || {});
		OPTIMAL_SETTINGS_PRESERVED_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(next, key)) {
				delete next[key];
			}
		});
		return next;
	}

	function getOptimalSettingsPatch() {
		return stripOptimalSettingsPreservedSettings(OPTIMAL_SETTINGS_RECIPE.patch);
	}

	function splitOptimalObjectCachePatch(patch) {
		const mainPatch = Object.assign({}, patch || {});
		const objectPatch = {};
		OPTIMAL_OBJECT_CACHE_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(mainPatch, key)) {
				objectPatch[key] = mainPatch[key];
				delete mainPatch[key];
			}
		});
		return { mainPatch, objectPatch };
	}

	function splitOptimalCompressionPatch(patch) {
		const mainPatch = Object.assign({}, patch || {});
		const compressionPatch = {};
		OPTIMAL_COMPRESSION_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(mainPatch, key)) {
				compressionPatch[key] = mainPatch[key];
				delete mainPatch[key];
			}
		});
		return { mainPatch, compressionPatch };
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
		OPTIMAL_SETTINGS_RECIPE,
		stripOptimalSettingsPreservedSettings,
		getOptimalSettingsPatch,
		splitOptimalObjectCachePatch,
		splitOptimalCompressionPatch,
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
