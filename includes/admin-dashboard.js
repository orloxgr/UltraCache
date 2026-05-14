/* UltraCache - Dashboard JS (No-Build Version) */
(function () {
	const elementApi = (window.wp && window.wp.element) ? window.wp.element : null;
	const ReactApi = elementApi || window.React;
	const ReactDOMApi = elementApi || window.ReactDOM;
	const { createElement: h, useCallback, useEffect, useMemo, useRef, useState } = ReactApi;
	const ucwpI18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {};
	const __ = typeof ucwpI18n.__ === 'function' ? ucwpI18n.__ : function (text) { return text; };
	const sprintf = typeof ucwpI18n.sprintf === 'function' ? ucwpI18n.sprintf : function (text) { return text; };

	const rootEl =
		document.getElementById('uc-dashboard') ||
		document.getElementById('ucwp-admin-root') ||
		document.getElementById('ucwp-root') ||
		document.getElementById('ultracache-root');
	if (!rootEl) {
		return;
	}

	const ucwp = window.ucwpData || {};
	const ucwpRestBase = String(ucwp.restBase || '');
	const ucwpRestNonce = String(ucwp.restNonce || '');
	const ucwpFetch = (typeof window !== 'undefined' && window.fetch) ? window.fetch.bind(window) : null;
	const initialSettings = ucwp.settings || {};
	const initialStats = ucwp.stats || {};
	const avifSupport = ucwp.avifSupport || { supported: false };
	const initialDiagnostics = ucwp.diagnostics || initialStats.diagnostics || {};
	const initialDefaults = ucwp.defaults || {};
	let crawlScopeSummary = ucwp.crawlScopeSummary || {};
	const initialWarmupGeneration = Math.max(0, Number(ucwp.warmupGeneration || 0));
	const frontendProbeUrl = ucwp.frontendProbeUrl || '/';

	const CLEAR_NOTICE_DELAY = 4200;
	const SYSTEM_NOTICE_DELAY = 7000;
	const SYSTEM_NOTICE_COOLDOWN = 24 * 60 * 60 * 1000;
	const STATS_REFRESH_INTERVAL = 60000;
	const SETTINGS_SAVE_DEBOUNCE_MS = 700;
	const ACTION_QUEUE_POLL_DELAY = 750;
	const ACTION_QUEUE_MAX_POLLS = 480;
	const JOB_STORAGE_KEY = 'ucwp-dashboard-job-state-v3';
	const DEFAULT_QUEUE_BATCH_SIZE = 100;
	const MAX_ITEM_RETRIES = 2;
	const SUPPORT_LINKS = {
		coffee: 'https://www.paypal.com/ncp/payment/LDBFB3RRB3E9J',
		beer: 'https://www.paypal.com/ncp/payment/G5RNTC3UF58VU',
		meal: 'https://www.paypal.com/ncp/payment/4NP9RNUYRFRFA',
		hire: 'mailto:byron@iniotakis.com?subject=Hire%20me%20for%20WordPress%20work',
		feature: 'mailto:byron@iniotakis.com?subject=UltraCache%20feature%20request',
		bug: 'mailto:byron@iniotakis.com?subject=UltraCache%20bug%20report',
	};
	const IMPORT_EXPORT_SETTING_KEYS = [
		'pageCacheEnabled',
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
		'mediaOutputMode',
		'deferJsEnabled',
		'deferAllJsEnabled',
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
		'homepageCssBundleExcludeList',
		'homepageCssBundleMode',
		'cssBundleScope',
		'pageCssBundleOnEntryEnabled',
		'pageAsyncBundleOnEntryEnabled',
		'sliderSafeModeEnabled',
		'clsDimensionsEnabled',
		'asyncCssEnabled',
		'asyncCssExcludeList',
		'aggressiveAsyncCssEnabled',
		'delayNonCriticalJsEnabled',
		'delayNonCriticalJsExcludeList',
		'lcpImagePriorityEnabled',
		'lazyLoadImagesEnabled',
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
		'assetCleanupExcludeList',
		'googleFontsSwapEnabled',
		'googleFontsLocalOptimizationEnabled',
		'googleFontsAdditionalScanUrls',
		'selfHostedFontCssOptimizationEnabled',
		'selfHostedFontRuntimeRewriteEnabled',
		'speculationRulesEnabled',
		'browserCacheRulesEnabled',
		'varnishCliEnabled',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishCliMethod',
		'preRenderOnSave',
		'woocommerceSafeModeEnabled',
		'cacheCleanupEnabled',
		'apcuFlushOnScheduledCleanup',
		'flushAllIncludeOpcache',
		'flushAllIncludeApcu',
		'flushAllIncludeLiteSpeed',
		'flushAllIncludeNginx',
		'flushAllIncludeVarnish',
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
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend',
		'gzipEnabled',
		'brotliEnabled',
		'homepageCssBundleEnabled',
		'homepageCssBundleMode',
		'homepageCssBundleInlineEnabled',
		'leftoverCssBundleEnabled',
		'cssBundleScope',
		'pageCssBundleOnEntryEnabled',
		'pageAsyncBundleOnEntryEnabled',
		'deferJsEnabled',
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
		'selfHostedFontRuntimeRewriteEnabled'
	];
	const PROFILE_OBJECT_CACHE_SETTING_KEYS = [
		'objectCacheEnabled',
		'objectCacheBackend',
		'objectCacheFallbackBackend'
	];
	const PERFORMANCE_PROFILE_ORDER = ['off', 'safe', 'balanced', 'aggressive'];
	const PERFORMANCE_PROFILE_PRESERVED_SETTING_KEYS = [
		// Profiles must not change diagnostics, scheduling/automation, Varnish infrastructure,
		// Redis connection infrastructure, or user-maintained visible lists/textareas.
		'cacheStatsEnabled',
		'varnishCliEnabled',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishCliMethod',
		'varnishCliKey',
		'varnishCliKeyConfigured',
		'preRenderOnSave',
		'cacheCleanupEnabled',
		'apcuFlushOnScheduledCleanup',
		'flushAllIncludeOpcache',
		'flushAllIncludeApcu',
		'flushAllIncludeLiteSpeed',
		'flushAllIncludeNginx',
		'flushAllIncludeVarnish',
		'cronWarmEnabled',
		'cronWarmStartAfterCleanup',
		'cronWarmStartAfterManualPurge',
		'cronWarmPagesPerMinute',
		'scheduledWarmLimit',
		'cacheCleanupIntervalHours',
		'staleWhileRevalidateEnabled',
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
			pageCacheEnabled: false, objectCacheEnabled: false, brotliEnabled: false, gzipEnabled: false, cacheStatsEnabled: false, mediaOptimizationEnabled: false, mediaGenerateOnUploadEnabled: false, mediaGenerateOnDemandEnabled: false,
			deferJsEnabled: false, deferAllJsEnabled: false, delaySafeThirdPartyJsEnabled: false, delayAllThirdPartyJsEnabled: false, lazyMailerliteNonceEnabled: false, delayFunctionalThirdPartyJsEnabled: false, asyncExternalScriptsEnabled: false, homepageCssBundleEnabled: false, homepageCssBundleInlineEnabled: false, leftoverCssBundleEnabled: false, pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false, sliderSafeModeEnabled: false, clsDimensionsEnabled: false, asyncCssEnabled: false, aggressiveAsyncCssEnabled: false, delayNonCriticalJsEnabled: false, lcpImagePriorityEnabled: false, lazyLoadImagesEnabled: false, lcpBoundaryDeferEnabled: false, manualLcpHeroSelector: '', mainThreadReliefEnabled: false, criticalRequestChainReliefEnabled: false,
			assetChainCleanupEnabled: false, assetCleanupWooProductAssetsEnabled: false, assetCleanupProductFilterAssetsEnabled: false, assetCleanupWooBlocksCssEnabled: false, googleFontsSwapEnabled: false, googleFontsLocalOptimizationEnabled: false, selfHostedFontCssOptimizationEnabled: false, selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: false, browserCacheRulesEnabled: false, preRenderOnSave: false, woocommerceSafeModeEnabled: false, cacheCleanupEnabled: false, apcuFlushOnScheduledCleanup: false, cronWarmEnabled: false, cronWarmStartAfterCleanup: false, cronWarmStartAfterManualPurge: false, staleWhileRevalidateEnabled: false, cacheQueryStringsEnabled: false, cacheSafeTrackingCookiesEnabled: false, varnishCliEnabled: false,
			homepageCssBundleMode: 'safe', delayIconFontsEnabled: false, delayIconFontsAutoDetectEnabled: false, cssBundleScope: 'homepage', mediaOutputMode: 'auto',
		} },
		safe: { label: __("Safe", 'ultracache'), description: __("Public-safe profile based on the exported Safe settings. Object Cache is enabled automatically with Redis/APCu/Disk detection. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
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
			mediaOutputMode: "auto",
			deferJsEnabled: true,
			deferAllJsEnabled: false,
			deferJsForceList: "",
			deferJsExcludeList: "",
			delaySafeThirdPartyJsEnabled: true,
			delayAllThirdPartyJsEnabled: false,
			lazyMailerliteNonceEnabled: false,
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: false,
			delayFunctionalThirdPartyJsPatterns: "recaptcha\nhcaptcha\ngoogle.com/recaptcha\ngstatic.com/recaptcha\nmaps.googleapis.com\nmaps.gstatic.com\ncomplianz\ncmplz\ncookieyes\ncky-\nintercom\ncrisp.chat\ntawk.to\nzendesk\ncalendly\ntypeform\njotform",
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: false,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: false,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "safe",
			cssBundleScope: "homepage",
			pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: false,
			clsDimensionsEnabled: true,
			asyncCssEnabled: false,
			asyncCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: false,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: false,
			lazyLoadImagesEnabled: false,
			lcpBoundaryDeferEnabled: false,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: true,
			criticalRequestChainReliefEnabled: false,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: false,
			assetCleanupWooProductAssetsEnabled: false,
			assetCleanupProductFilterAssetsEnabled: false,
			assetCleanupWooBlocksCssEnabled: false,
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			varnishCliEnabled: false,
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishCliMethod: "BAN",
			preRenderOnSave: true,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			cronWarmEnabled: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 60,
			cacheMaxStaleMinutes: 1440,
			cacheExceptionPaths: "/cart/\n/checkout/\n/my-account/\n/wp-admin/\n/wp-login.php\n/wc-api/\n/wp-json/",
			cacheExceptionQueryArgs: "preview\ncustomize_changeset_uuid\ncustomize_autosaved\nelementor-preview\nvc_editable\net_fb\nadd-to-cart\nwc-ajax\nremove_item\nundo_item\napply_coupon\nremove_coupon\norder_again\n_wpnonce\n_ajax_nonce\nnonce\nsecurity\ntoken\nauth\nauth_token\naccess_token\nkey\norder_key\npassword\npass\npwd\nredirect_to\ncustomer-logout\nlogout\npay_for_order\ncancel_order\ndownload_file",
			cacheQueryStringsEnabled: false,
			cacheSafeTrackingCookiesEnabled: true,
		} },
		balanced: { label: __("Balanced", 'ultracache'), description: __("Balanced profile based on the exported Balanced settings. Object Cache is enabled automatically with Redis/APCu/Disk detection. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
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
			mediaOutputMode: "auto",
			deferJsEnabled: true,
			deferAllJsEnabled: false,
			deferJsForceList: "",
			deferJsExcludeList: "",
			delaySafeThirdPartyJsEnabled: true,
			delayAllThirdPartyJsEnabled: false,
			lazyMailerliteNonceEnabled: false,
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: true,
			delayFunctionalThirdPartyJsPatterns: "recaptcha\nhcaptcha\ngoogle.com/recaptcha\ngstatic.com/recaptcha\nmaps.googleapis.com\nmaps.gstatic.com\ncomplianz\ncmplz\ncookieyes\ncky-\nintercom\ncrisp.chat\ntawk.to\nzendesk\ncalendly\ntypeform\njotform",
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: true,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: true,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "safe",
			cssBundleScope: "shared",
			pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: true,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: false,
			clsDimensionsEnabled: true,
			asyncCssEnabled: false,
			asyncCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: true,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: true,
			lazyLoadImagesEnabled: true,
			lcpBoundaryDeferEnabled: false,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: true,
			criticalRequestChainReliefEnabled: false,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: false,
			assetCleanupWooProductAssetsEnabled: false,
			assetCleanupProductFilterAssetsEnabled: false,
			assetCleanupWooBlocksCssEnabled: false,
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: true,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			varnishCliEnabled: true,
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishCliMethod: "BAN",
			preRenderOnSave: true,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			cronWarmEnabled: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 60,
			cacheMaxStaleMinutes: 1440,
			cacheExceptionPaths: "/cart/\n/checkout/\n/my-account/\n/wp-admin/\n/wp-login.php\n/wc-api/\n/wp-json/",
			cacheExceptionQueryArgs: "preview\ncustomize_changeset_uuid\ncustomize_autosaved\nelementor-preview\nvc_editable\net_fb\nadd-to-cart\nwc-ajax\nremove_item\nundo_item\napply_coupon\nremove_coupon\norder_again\n_wpnonce\n_ajax_nonce\nnonce\nsecurity\ntoken\nauth\nauth_token\naccess_token\nkey\norder_key\npassword\npass\npwd\nredirect_to\ncustomer-logout\nlogout\npay_for_order\ncancel_order\ndownload_file",
			cacheQueryStringsEnabled: false,
			cacheSafeTrackingCookiesEnabled: true,
		} },
		aggressive: { label: __("Aggressive", 'ultracache'), description: __("Aggressive profile based on the exported Aggressive settings. Enables Defer all JS; Runtime Scan is recommended to detect broken JS dependencies and build JS Delay / Defer Exclusions. Object Cache is auto-detected. User-maintained exclusions and visible lists are preserved.", 'ultracache'), patch: {
			pageCacheEnabled: true,
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
			mediaOutputMode: "auto",
			deferJsEnabled: true,
			deferAllJsEnabled: true,
			deferJsForceList: "",
			deferJsExcludeList: "wp-i18n\nwp-hooks\njquery\npage-builder.js\nindex.js\ncontact-form-7-js-translations",
			delaySafeThirdPartyJsEnabled: true,
			delayAllThirdPartyJsEnabled: true,
			lazyMailerliteNonceEnabled: false,
			delaySafeThirdPartyJsPatterns: "googletagmanager.com\ngoogle-analytics.com\ngtag/js\ngtm.js\ngooglesitekit-events-provider\ngoogle-site-kit/dist/assets/js\nconnect.facebook.net\nfbevents.js\nfbq\nanalytics.tiktok.com\nsnap.licdn.com\ninsight.min.js\nbat.bing.com\nclarity.ms\nstatic.hotjar.com\nscript.hotjar.com\ns.pinimg.com\npintrk\ndoubleclick.net\ngoogleadservices.com\ntaboola\noutbrain\nyahoo\nyimg.com",
			delayFunctionalThirdPartyJsEnabled: true,
			delayFunctionalThirdPartyJsPatterns: "recaptcha\nhcaptcha\ngoogle.com/recaptcha\ngstatic.com/recaptcha\nmaps.googleapis.com\nmaps.gstatic.com\ncomplianz\ncmplz\ncookieyes\ncky-\nintercom\ncrisp.chat\ntawk.to\nzendesk\ncalendly\ntypeform\njotform",
			asyncExternalScriptsEnabled: false,
			homepageCssBundleEnabled: true,
			homepageCssBundleInlineEnabled: false,
			leftoverCssBundleEnabled: true,
			homepageCssBundleExcludeList: "",
			homepageCssBundleMode: "safe",
			cssBundleScope: "per-page",
			pageCssBundleOnEntryEnabled: true, pageAsyncBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false,
			sliderSafeModeEnabled: true,
			clsDimensionsEnabled: true,
			asyncCssEnabled: true,
			asyncCssExcludeList: "",
			aggressiveAsyncCssEnabled: false,
			delayNonCriticalJsEnabled: true,
			delayNonCriticalJsExcludeList: "",
			lcpImagePriorityEnabled: true,
			lazyLoadImagesEnabled: true,
			lcpBoundaryDeferEnabled: true,
			manualLcpHeroSelector: "",
			mainThreadReliefEnabled: true,
			criticalRequestChainReliefEnabled: true,
			criticalResourcePreloadList: "",
			criticalRequestChainDelayList: "",
			assetChainCleanupEnabled: true,
			assetCleanupWooProductAssetsEnabled: true,
			assetCleanupProductFilterAssetsEnabled: true,
			assetCleanupWooBlocksCssEnabled: true,
			assetCleanupExcludeList: "elementor\nbricks\noxygen\nwpbakery\nvc_\nrevslider\nsr7\najaxsearch\nfibosearch\n.dgwt-wcas\naws-container\ncart\ncheckout\naccount",
			googleFontsSwapEnabled: true,
			googleFontsLocalOptimizationEnabled: false,
			googleFontsAdditionalScanUrls: "",
			selfHostedFontCssOptimizationEnabled: true,
			selfHostedFontRuntimeRewriteEnabled: true,
			speculationRulesEnabled: true,
			browserCacheRulesEnabled: true,
			varnishCliEnabled: true,
			varnishCliMode: "admin",
			varnishCliServers: "127.0.0.1:6082",
			varnishCliTimeoutSeconds: 2,
			varnishCliMethod: "BAN",
			preRenderOnSave: true,
			woocommerceSafeModeEnabled: true,
			cacheCleanupEnabled: false,
			apcuFlushOnScheduledCleanup: false,
			flushAllIncludeOpcache: false,
			flushAllIncludeApcu: false,
			flushAllIncludeLiteSpeed: false,
			flushAllIncludeNginx: false,
			flushAllIncludeVarnish: false,
			cronWarmEnabled: false,
			cronWarmStartAfterCleanup: false,
			cronWarmStartAfterManualPurge: false,
			cronWarmPagesPerMinute: 2,
			scheduledWarmLimit: 9,
			staleWhileRevalidateEnabled: true,
			cacheCleanupIntervalHours: 24,
			cacheFreshTtlMinutes: 60,
			cacheMaxStaleMinutes: 1440,
			cacheExceptionPaths: "/cart/\n/checkout/\n/my-account/\n/wp-admin/\n/wp-login.php\n/wc-api/\n/wp-json/",
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

	function classNames() {
		return Array.prototype.slice.call(arguments).filter(Boolean).join(' ');
	}

	function normalizeContentEncoding(headerValue) {
		return String(headerValue || '').toLowerCase();
	}

	function responseHasEncoding(headerValue, token) {
		return normalizeContentEncoding(headerValue).split(',').map((part) => part.trim()).includes(String(token || '').toLowerCase());
	}

	async function probeFrontendCompressionViaBrowser() {
		const probeUrl = new URL(frontendProbeUrl || '/', window.location.origin);
		probeUrl.searchParams.set('ucwp_probe_browser', String(Date.now()));

		const result = {
			ready: true,
			serverCompression: false,
			gzip: false,
			brotli: false,
			brokenGzip: false,
			brokenBrotli: false,
			message: '',
		};

		const response = await window.fetch(probeUrl.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
				'X-UltraCache-Compression-Probe': 'browser',
			},
		});

		const contentEncoding = response.headers.get('content-encoding') || '';
		const ultraCacheEncoding = String(response.headers.get('x-ultracache-encoding') || '').toLowerCase().trim();

		if (!ultraCacheEncoding) {
			if (responseHasEncoding(contentEncoding, 'br')) {
				result.serverCompression = true;
				result.brotli = true;
			}
			if (responseHasEncoding(contentEncoding, 'gzip')) {
				result.serverCompression = true;
				result.gzip = true;
			}
			if (result.serverCompression) {
				result.gzip = true;
				result.brotli = true;
				result.message = 'Your server or proxy is already using frontend compression by default. UltraCache compression has been disabled to avoid conflicts.';
			}
			return result;
		}

		if ('gzip' === ultraCacheEncoding && !responseHasEncoding(contentEncoding, 'gzip')) {
			result.brokenGzip = true;
			result.message = 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.';
			return result;
		}

		if ('brotli' === ultraCacheEncoding && !responseHasEncoding(contentEncoding, 'br')) {
			result.brokenBrotli = true;
			result.message = 'UltraCache detected Brotli-compressed output without a matching Content-Encoding header. Brotli has been disabled as a safety measure.';
			return result;
		}

		return result;
	}

	function getLocalStorageSafe() {
		try {
			if (typeof window !== 'undefined' && window.localStorage) {
				return window.localStorage;
			}
		} catch (error) {}
		return null;
	}

	function getSystemNoticeStorageKey(id) {
		return 'ucwp-system-notice:' + String(id || 'notice');
	}

	function shouldShowSystemNotice(id, cooldownMs) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return true;
		}
		const raw = storage.getItem(getSystemNoticeStorageKey(id));
		const lastShown = raw ? Number(raw) : 0;
		if (!lastShown || Number.isNaN(lastShown)) {
			return true;
		}
		return (Date.now() - lastShown) >= Math.max(1000, Number(cooldownMs) || SYSTEM_NOTICE_COOLDOWN);
	}

	function markSystemNoticeShown(id) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return;
		}
		try {
			storage.setItem(getSystemNoticeStorageKey(id), String(Date.now()));
		} catch (error) {}
	}


	function getPersistentDismissalStorageKey(id) {
		return 'ucwp-dismissed-notice:' + String(id || 'notice');
	}

	function isPersistentNoticeDismissed(id) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return false;
		}
		return storage.getItem(getPersistentDismissalStorageKey(id)) === '1';
	}

	function dismissPersistentNotice(id) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return;
		}
		try {
			storage.setItem(getPersistentDismissalStorageKey(id), '1');
		} catch (error) {}
	}

	function isMobileViewport() {
		if (typeof window === 'undefined') {
			return false;
		}
		return window.innerWidth <= 782;
	}

	function formatNumber(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toLocaleString() : '0';
	}

	function hasDashboardStatsCounters(stats) {
		if (!stats || typeof stats !== 'object') {
			return false;
		}
		return (
			typeof stats.pageCacheHits !== 'undefined' ||
			typeof stats.pageCacheMisses !== 'undefined' ||
			typeof stats.pageCacheFiles !== 'undefined' ||
			typeof stats.objectCacheHits !== 'undefined' ||
			typeof stats.objectCacheEntries !== 'undefined' ||
			typeof stats.cacheSizeBytes !== 'undefined' ||
			typeof stats.cacheSizeHuman !== 'undefined' ||
			typeof stats.optimizedImages !== 'undefined' ||
			typeof stats.avifFiles !== 'undefined' ||
			typeof stats.webpFiles !== 'undefined'
		);
	}

	function formatStatsNumber(stats, value) {
		return hasDashboardStatsCounters(stats) ? formatNumber(value) : 'Refreshing…';
	}

	function formatStatsPercent(stats, value) {
		return hasDashboardStatsCounters(stats) ? formatPercent(value) : 'Refreshing…';
	}

	function getStatsRefreshHint(stats, fallback) {
		return hasDashboardStatsCounters(stats) ? fallback : 'Fetching live REST counters…';
	}

	function formatCount(value, isMinimum) {
		return formatNumber(value) + (isMinimum ? '+' : '');
	}

	function formatFileCount(value, isMinimum) {
		return formatCount(value, isMinimum) + ' files';
	}

	function formatDurationSeconds(value) {
		const seconds = Number(value || 0);
		if (!Number.isFinite(seconds) || seconds <= 0) {
			return '0 seconds';
		}
		if (seconds % 86400 === 0) {
			const days = seconds / 86400;
			return formatNumber(days) + ' day' + (days === 1 ? '' : 's');
		}
		if (seconds % 3600 === 0) {
			const hours = seconds / 3600;
			return formatNumber(hours) + ' hour' + (hours === 1 ? '' : 's');
		}
		if (seconds % 60 === 0) {
			const minutes = seconds / 60;
			return formatNumber(minutes) + ' minute' + (minutes === 1 ? '' : 's');
		}
		return formatNumber(seconds) + ' seconds';
	}

	function getCssCleanupGraceLabel(storageCssBundles) {
		return storageCssBundles.graceSecondsLabel || formatDurationSeconds(storageCssBundles.graceSeconds || 0);
	}

	function getCssCleanupDeleteLimitLabel(storageCssBundles) {
		return storageCssBundles.cleanupDeleteLimitLabel || (formatNumber(storageCssBundles.cleanupDeleteLimit || 0) + ' files per cleanup run');
	}

	function getDefaultScheduledWarmLimit() {
		const value = Number(crawlScopeSummary.defaultScheduledWarmLimit || 9);
		return Number.isFinite(value) ? Math.max(1, value) : 9;
	}

	function getScheduledWarmLimitSummary(formValues, currentSettings) {
		formValues = formValues || {};
		currentSettings = currentSettings || {};
		const breakdown = Array.isArray(crawlScopeSummary.sourceBreakdown) ? crawlScopeSummary.sourceBreakdown : [];
		const selectedCap = Number(typeof formValues.scheduledWarmLimit !== 'undefined' ? formValues.scheduledWarmLimit : currentSettings.scheduledWarmLimit || 1);
		const maxUrls = Number(crawlScopeSummary.maxUrls || 0);
		const selectedSourceKeys = splitWarmSourceList(
			typeof currentSettings.warmFullSiteSources !== 'undefined'
				? currentSettings.warmFullSiteSources
				: (Array.isArray(crawlScopeSummary.selectedFullSiteSources) ? crawlScopeSummary.selectedFullSiteSources.join(',') : '')
		);
		const optionLabelMap = {};
		getFullSiteSourceOptions().forEach((option) => {
			if (option && option.value) {
				optionLabelMap[String(option.value)] = String(option.label || option.value);
			}
		});
		const countMap = {};
		breakdown.forEach((item) => {
			const key = String((item && item.key) || '').trim();
			if (key) {
				countMap[key] = Math.max(0, Number(item && item.count ? item.count : 0));
			}
		});
		const sourceCounts = crawlScopeSummary && crawlScopeSummary.sourceCounts && typeof crawlScopeSummary.sourceCounts === 'object' ? crawlScopeSummary.sourceCounts : {};
		Object.keys(sourceCounts).forEach((key) => {
			if (typeof countMap[key] === 'undefined') {
				countMap[key] = Math.max(0, Number(sourceCounts[key] || 0));
			}
		});
		if (typeof countMap.homepage === 'undefined' && Number(crawlScopeSummary.baseUrlCount || 0) > 0) {
			countMap.homepage = Math.max(0, Number(crawlScopeSummary.baseUrlCount || 0));
		}
		if (typeof countMap.menus === 'undefined' && Number(crawlScopeSummary.menuUrlCount || 0) > 0) {
			countMap.menus = Math.max(0, Number(crawlScopeSummary.menuUrlCount || 0));
		}
		const labelMap = {
			homepage: 'homepage',
			menus: 'menu',
			pages: 'pages',
			posts: 'posts',
			categories: 'categories',
			tags: 'tags',
			woocommerce_products: 'WooCommerce products',
			woocommerce_product_taxonomies: 'WooCommerce product categories/tags',
			custom_post_types: 'custom post types',
			custom_taxonomies: 'custom taxonomies',
		};
		const selectedItems = selectedSourceKeys
			.map((key) => {
				const cleanKey = String(key || '').trim();
				const rawLabel = labelMap[cleanKey] || optionLabelMap[cleanKey] || cleanKey || 'source';
				const count = Math.max(0, Number(countMap[cleanKey] || 0));
				return { key: cleanKey, label: rawLabel, count };
			})
			.filter((item) => item.key && item.count > 0);
		const discoveredTotal = selectedItems.reduce((total, item) => total + item.count, 0);
		let sourceText = '';
		let totalText = '';
		if (selectedItems.length) {
			sourceText = selectedItems.map((item) => item.label + ' ' + formatNumber(item.count) + ' URL' + (item.count === 1 ? '' : 's')).join(' | ');
			totalText = ' Total available from selected sources: ' + formatNumber(discoveredTotal) + ' unique URL' + (discoveredTotal === 1 ? '' : 's') + '.';
		} else if (selectedSourceKeys.length) {
			sourceText = 'selected sources are saved; counts are being refreshed or no cacheable URLs were found for them yet';
		} else {
			sourceText = 'no full-site warm-up sources selected yet';
		}
		const capText = ' Current cap: ' + formatNumber(Number.isFinite(selectedCap) ? Math.max(0, selectedCap) : 0) + '.';
		const globalCapText = maxUrls > 0 ? ' Global crawl cap: ' + formatNumber(maxUrls) + '.' : '';

		return 'Cap for scheduled / cron warm-up. The sources are chosen from the "Full-site warm-up sources" setting in the Warm Cache box. Selected sources are: ' + sourceText + '. URLs are processed in the above priority order.' + totalText + capText + globalCapText;
	}

	function splitWarmSourceList(value) {
		const seen = {};
		const items = [];
		String(value || '').split(/[\r\n,]+/).forEach((item) => {
			item = String(item || '').trim();
			if (!item || seen[item]) { return; }
			seen[item] = true;
			items.push(item);
		});
		return items;
	}

	function joinWarmSourceList(items) {
		return (Array.isArray(items) ? items : []).map((item) => String(item || '').trim()).filter(Boolean).join(',');
	}

	function getWarmMenuOptions() {
		const options = Array.isArray(crawlScopeSummary.menuOptions) ? crawlScopeSummary.menuOptions : [];
		return [{ value: '', label: __("Select menu", 'ultracache') }].concat(options.map((option) => ({ value: String(option.value || option.location || ''), label: String(option.label || option.value || option.location || 'Menu') })).filter((option) => option.value || option.label));
	}

	function getWarmMenuDepthOptions() {
		const options = Array.isArray(crawlScopeSummary.menuDepthOptions) ? crawlScopeSummary.menuDepthOptions : [];
		return options.length ? options.map((option) => ({ value: String(option.value || ''), label: String(option.label || option.value || '') })) : [
			{ value: '', label: __("Select depth", 'ultracache') },
			{ value: '1', label: __("Depth 1", 'ultracache') },
			{ value: '2', label: __("Depth 2", 'ultracache') },
			{ value: '3', label: __("Depth 3", 'ultracache') },
			{ value: 'all', label: __("All depths", 'ultracache') },
		];
	}

	function getFullSiteSourceOptions() {
		const options = Array.isArray(crawlScopeSummary.fullSiteSourceOptions) ? crawlScopeSummary.fullSiteSourceOptions : [];
		return options.map((option) => ({ value: String(option.value || ''), label: String(option.label || option.value || '') })).filter((option) => option.value);
	}

	function hasMenuWarmScope(settingsValue) {
		const value = settingsValue || settingsRef.current || settings || {};
		return !!String(value.warmMenuLocation || '').trim() && ['1', '2', '3', 'all'].indexOf(String(value.warmMenuDepth || '').trim()) !== -1;
	}

	function hasFullSiteWarmScope(settingsValue) {
		const value = settingsValue || settingsRef.current || settings || {};
		return splitWarmSourceList(value.warmFullSiteSources).length > 0;
	}

	function formatEventTime(event) {
		if (!event || typeof event !== 'object') {
			return 'No frontend cache event yet';
		}

		const numericTime = Number(event.time);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}

		const mysqlTime = event.time_mysql || (typeof event.time === 'string' ? event.time : '');
		if (mysqlTime) {
			const normalized = String(mysqlTime).replace(' ', 'T');
			const parsed = Date.parse(normalized);
			if (!Number.isNaN(parsed)) {
				return new Date(parsed).toLocaleString();
			}
		}

		return 'No frontend cache event yet';
	}

	function formatPercent(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toFixed(num % 1 === 0 ? 0 : 1) + '%' : '0%';
	}

	function formatLooseTime(value) {
		if (!value) {
			return '—';
		}
		if (typeof value === 'object') {
			return formatEventTime(value);
		}
		const numericTime = Number(value);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}
		const parsed = Date.parse(String(value).replace(' ', 'T'));
		return Number.isNaN(parsed) ? String(value) : new Date(parsed).toLocaleString();
	}


	function formatObjectEntries(stats) {
		if (stats && (stats.dashboardStatsDisabled || stats.disabled)) {
			return 'Disabled';
		}
		if (!hasDashboardStatsCounters(stats)) {
			return 'Refreshing…';
		}
		const value = formatNumber(stats && typeof stats.objectCacheEntries !== 'undefined' ? stats.objectCacheEntries : 0);
		return stats && stats.objectCacheStatsPartial ? value + '+' : value;
	}

	function getObjectEntriesHint(stats) {
		const parts = [];
		const backendLabel = (value) => {
			const normalized = String(value || '').toLowerCase();
			if (normalized === 'redis') return 'Redis';
			if (normalized === 'apcu') return 'APCu';
			if (normalized === 'disk') return 'Disk';
			if (normalized === 'runtime') return 'Runtime-only';
			return value ? String(value).toUpperCase() : 'Unavailable';
		};
		const activeBackend = stats && (stats.objectCacheActiveBackend || stats.objectCacheBackend || stats.objectCacheStatsSource);
		const selectedBackend = stats && stats.objectCacheSelectedBackend;
		const fallbackBackend = stats && (stats.objectCacheFallbackBackend || (stats.objectCacheFallbackActive ? activeBackend : ''));
		if (activeBackend) {
			parts.push('Active: ' + backendLabel(activeBackend));
		}
		if (selectedBackend && activeBackend && String(selectedBackend) !== String(activeBackend)) {
			parts.push('Configured: ' + backendLabel(selectedBackend));
		}
		if (stats && stats.objectCacheFallbackActive) {
			parts.push(backendLabel(fallbackBackend) + ' fallback active');
		}
		if (stats && stats.objectCacheSizeHuman) {
			parts.push(stats.objectCacheSizeHuman);
		}
		if (stats && stats.objectCacheStatsPartial) {
			parts.push(stats.objectCacheStatsPartialReason || ('sampled, backend scan capped at ' + formatNumber(stats.objectCacheStatsLimit || 5000) + ' keys'));
		}
		if (stats && (typeof stats.objectCacheRedisEntries !== 'undefined' || typeof stats.objectCacheApcuEntries !== 'undefined' || typeof stats.objectCacheDiskEntries !== 'undefined')) {
			parts.push('Redis ' + formatNumber(stats.objectCacheRedisEntries || 0));
			parts.push('APCu ' + formatNumber(stats.objectCacheApcuEntries || 0));
			parts.push('Disk ' + formatNumber(stats.objectCacheDiskEntries || 0));
		}
		if (!parts.length) {
			parts.push('Object cache backend entries');
		}
		return parts.join(' · ');
	}

	function CacheStatisticsPanel({ settings, stats, diagnostics, busy, asyncActions, onToggleStats, onFullObjectCount }) {
		const enabled = !!(settings && settings.cacheStatsEnabled);
		return h('div', { className: 'uc-card uc-cache-stats-panel', key: 'cache-statistics-panel' }, [
			h('div', { className: 'flex flex-col md:flex-row md:items-start md:justify-between gap-4', key: 'head' }, [
				h('div', { key: 'copy' }, [
					h('div', { className: 'text-xs tracking-widest text-zinc-500 mb-1', key: 'eyebrow' }, __("Cache Statistics", 'ultracache')),
					h('h3', { className: 'text-lg font-black tracking-tight text-white m-0', key: 'title' }, __("Count Cache stats", 'ultracache')),
					h('p', { className: 'text-xs text-zinc-500 mt-2 mb-0 max-w-3xl', key: 'desc' }, __("Track cache hits, misses, bypasses and object-cache counters. Disabling this avoids counter writes during frontend requests.", 'ultracache')),
				]),
				h('label', {
					className: classNames('uc-toggle', busy ? 'opacity-60 pointer-events-none' : ''),
					key: 'toggle',
				}, [
					h('input', {
						type: 'checkbox',
						checked: enabled,
						disabled: !!busy,
						onChange: (event) => onToggleStats(event.target.checked),
					}),
					h('span', { className: 'slider' }),
				]),
			]),
			enabled
				? h('div', { className: 'uc-warning-box mt-4', key: 'warning' }, __("Enabling cache statistics may add a small performance overhead because UltraCache needs to update counters during requests. If you use Varnish, nginx full-page cache, Cloudflare, or another reverse proxy, stats may under-report requests served before WordPress.", 'ultracache'))
				: h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'disabled-copy' }, __("Stats are disabled. The dashboard will not refresh or write live request counters until this switch is enabled.", 'ultracache')),
			enabled && !hasDashboardStatsCounters(stats)
				? h('div', { className: 'text-xs text-sky-300 bg-sky-500/10 rounded-xl px-3 py-2 mt-3', key: 'stats-refreshing-copy' }, __("Stats are ON. Waiting for the authenticated REST refresh; placeholder values are not shown as zeros.", 'ultracache'))
				: null,
			enabled
				? h('details', { className: 'uc-accordion uc-accordion--card mt-4', open: true, key: 'details' }, [
					h('summary', { key: 'summary' }, __("Show detailed stats", 'ultracache')),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: __("Cached Pages", 'ultracache'),
							value: formatStatsNumber(stats, typeof stats.pagesCached !== 'undefined' ? stats.pagesCached : stats.pageCacheFiles),
							hint: getStatsRefreshHint(stats, 'Stored HTML cache files'),
							key: 'pages',
						}),
						h(StatCard, {
							label: __("Optimized Images", 'ultracache'),
							value: formatStatsNumber(stats, typeof stats.imagesOptimized !== 'undefined' ? stats.imagesOptimized : stats.optimizedImages),
							hint: hasDashboardStatsCounters(stats) ? (formatNumber(typeof stats.avifImagesOptimized !== 'undefined' ? stats.avifImagesOptimized : stats.avifFiles) + ' AVIF · ' + formatNumber(typeof stats.webpImagesOptimized !== 'undefined' ? stats.webpImagesOptimized : stats.webpFiles) + ' WebP') : 'Fetching live REST counters…',
							key: 'images',
						}),
						h(StatCard, {
							label: __("Object Entries", 'ultracache'),
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: __("Run full object-cache count", 'ultracache'),
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: __("Cache Size", 'ultracache'), value: hasDashboardStatsCounters(stats) ? (stats.cacheSizeHuman || '0 B') : 'Refreshing…', hint: getStatsRefreshHint(stats, 'Total cache footprint'), key: 'size' }),
						h(StatCard, { key: 'hits', label: __("Cache Hits", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheHits), hint: getStatsRefreshHint(stats, diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache') }),
						h(StatCard, { key: 'misses', label: __("Render Misses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheMisses), hint: getStatsRefreshHint(stats, 'Reached WordPress render path') }),
						h(StatCard, { key: 'bypasses', label: __("Bypasses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheBypasses), hint: getStatsRefreshHint(stats, 'Skipped before buffering') }),
						h(StatCard, { key: 'ratio', label: __("Hit Ratio", 'ultracache'), value: formatStatsPercent(stats, stats.pageCacheHitRatio), hint: getStatsRefreshHint(stats, 'Hits ÷ (hits + misses)') }),
					]),
				])
				: h('details', { className: 'uc-accordion uc-accordion--card mt-4', key: 'manual-details' }, [
					h('summary', { key: 'summary' }, __("Manual object-cache diagnostics", 'ultracache')),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: __("Object Entries", 'ultracache'),
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: __("Run full object-cache count", 'ultracache'),
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: __("Cache Size", 'ultracache'), value: stats.cacheSizeHuman || '0 B', hint: __("Static cache footprint", 'ultracache'), key: 'size' }),
					]),
				]),
		]);
	}

	function formatBytes(value) {
		const num = Number(value || 0);
		if (!Number.isFinite(num) || num <= 0) {
			return '0 B';
		}
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let size = num;
		let index = 0;
		while (size >= 1024 && index < units.length - 1) {
			size /= 1024;
			index += 1;
		}
		const fixed = size >= 100 || 0 === index ? 0 : 1;
		return size.toFixed(fixed) + ' ' + units[index];
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
			version: ucwp.version || '',
			exportedAt: new Date().toISOString(),
			site: window.location.origin || '',
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

	function mergeUniqueSettingLines(currentValue, additions) {
		const currentLines = normalizeSettingListLines(currentValue);
		const normalizedExisting = currentLines.map((line) => line.toLowerCase());
		const addedLines = [];
		normalizeSettingListLines(Array.isArray(additions) ? additions.join('\n') : additions).forEach((line) => {
			const normalized = line.toLowerCase();
			const duplicate = normalizedExisting.some((existing) => existing === normalized || normalized.indexOf(existing) !== -1);
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

	function getJsDelaySafetySuggestions(scan) {
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		return suggestions
			.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && item.appendable !== false && item.category !== 'review-only')
			.map((item) => String(item.suggestedExclusion).trim())
			.filter((line) => line.length > 0);
	}

	function getJsDelayReviewSuggestions(scan) {
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		return suggestions
			.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && (item.appendable === false || item.category === 'review-only'))
			.map((item) => String(item.suggestedExclusion).trim())
			.filter((line) => line.length > 0);
	}

	function isSuggestionPresentInDraft(draftValue, suggestion) {
		const lines = normalizeSettingListLines(draftValue).map((line) => line.toLowerCase());
		const target = String(suggestion || '').trim().toLowerCase();
		if (!target) {
			return false;
		}
		return lines.some((line) => line === target || target.indexOf(line) !== -1);
	}


	function cleanConsoleErrorExclusionToken(token) {
		let value = String(token || '').trim();
		if (!value) {
			return '';
		}
		value = value.replace(/[\u2026]/g, '');
		value = value.replace(/^['"`(<\[]+|['"`)>\],.;:]+$/g, '');
		value = value.replace(/\?.*$/, '');
		value = value.replace(/#.*$/, '');
		value = value.replace(/:\d+(?::\d+)?$/g, '');
		value = value.replace(/^.*\/wp-content\/plugins\/([^\s'"()<>]+).*$/i, function(match, rest) {
			const parts = String(rest || '').split('/').filter(Boolean);
			return parts.length ? parts[0] : match;
		});
		value = value.trim();
		if (!value || value.length < 3 || value.length > 160) {
			return '';
		}
		const lower = value.toLowerCase();
		if (/^(at|e|t|l|anonymous|undefined|null|true|false|object|array|function|functions|return|var|let|const|new|this|window|document|html(document)?|promise|uncaught|referenceerror|typeerror|syntaxerror|error|warning|jquery\.deferred|deferred|exception|woocommerce|wordpress|main|params|data)$/.test(lower)) {
			return '';
		}
		if (/^(https?:)?\/\/$/.test(value) || /^\d+$/.test(value)) {
			return '';
		}
		if (/^(jquery(?:-migrate)?(?:\.min)?|main|functions?|scripts?|script|custom|app|index|site|frontend|public|plugin)(?:\.min)?\.js$/i.test(value)) {
			return '';
		}
		return value;
	}

	function addConsoleErrorSuggestionToken(suggestions, seen, token) {
		const cleaned = cleanConsoleErrorExclusionToken(token);
		if (!cleaned) {
			return;
		}
		const lower = cleaned.toLowerCase();
		if (seen[lower]) {
			return;
		}
		seen[lower] = true;
		suggestions.push(cleaned);
	}

	function addConsoleErrorUrlSuggestions(suggestions, seen, url) {
		let raw = String(url || '').trim();
		if (!raw) {
			return;
		}
		raw = raw.replace(/[\u2026]/g, '');
		raw = raw.replace(/[),.;]+$/g, '');
		let parsed = null;
		try {
			parsed = new URL(raw, (typeof window !== 'undefined' && window.location ? window.location.href : 'https://example.com/'));
		} catch (error) {
			parsed = null;
		}
		const pathname = parsed && parsed.pathname ? String(parsed.pathname || '') : raw.split('?')[0];
		const lowerPath = pathname.toLowerCase();
		const pluginMatch = pathname.match(/\/wp-content\/plugins\/([^\/\s'"()<>]+)/i);
		if (pluginMatch && pluginMatch[1]) {
			addConsoleErrorSuggestionToken(suggestions, seen, pluginMatch[1]);
		}
		const fileMatch = pathname.match(/([^\/\s'"()<>]+\.(?:min\.)?(?:js|mjs))(?:$|[?#:])/i) || pathname.match(/([^\/\s'"()<>]+\.(?:min\.)?(?:js|mjs))$/i);
		if (fileMatch && fileMatch[1]) {
			const file = fileMatch[1];
			addConsoleErrorSuggestionToken(suggestions, seen, file);
			const base = file.replace(/\.min\.js$/i, '').replace(/\.js$/i, '');
			if (!/^(main|index|frontend|script|scripts|app|site|jquery|jquery\.min)$/i.test(base)) {
				addConsoleErrorSuggestionToken(suggestions, seen, base);
			}
		}
		if (lowerPath.indexOf('woocommerce-coupon-box') !== -1) {
			addConsoleErrorSuggestionToken(suggestions, seen, 'woocommerce-coupon-box');
		}
		if (lowerPath.indexOf('complianz') !== -1 || lowerPath.indexOf('cmplz') !== -1) {
			addConsoleErrorSuggestionToken(suggestions, seen, 'complianz');
		}
	}

	function getConsoleErrorRelevantText(rawInput) {
		const lines = String(rawInput || '').replace(/\r\n?/g, '\n').split('\n');
		const blocks = [];
		let current = [];
		let inError = false;
		const errorLineRegex = /(?:Uncaught\s+)?(?:ReferenceError|TypeError|SyntaxError|RangeError|EvalError|URIError|Error):|jQuery\.Deferred exception|\bis not defined\b|\bis not a function\b|Cannot read properties|window\[[^\]]+\]\s+is\s+not\s+a\s+function/i;
		const stackLineRegex = /^\s*at\s+|\.(?:m?js)(?:\?[^\s)]*)?(?::\d+(?::\d+)?)?/i;
		const harmlessRegex = /^\s*(?:JQMIGRATE:\s*Migrate is installed|opt-in|Understand this (?:error|warning))\s*$/i;

		lines.forEach((line) => {
			const text = String(line || '').trim();
			if (!text || harmlessRegex.test(text)) {
				if (!text && current.length) {
					blocks.push(current.join('\n'));
					current = [];
					inError = false;
				}
				return;
			}

			if (errorLineRegex.test(text)) {
				if (current.length) {
					blocks.push(current.join('\n'));
				}
				current = [text];
				inError = true;
				return;
			}

			if (inError && stackLineRegex.test(text)) {
				current.push(text);
				return;
			}

			if (current.length) {
				blocks.push(current.join('\n'));
				current = [];
			}
			inError = false;
		});

		if (current.length) {
			blocks.push(current.join('\n'));
		}

		return blocks.join('\n');
	}

	function extractConsoleErrorExclusions(rawInput) {
		const text = getConsoleErrorRelevantText(rawInput);
		const suggestions = [];
		const seen = {};
		let match;

		const urlRegex = /https?:\/\/[^\s'"<>),]+/gi;
		while ((match = urlRegex.exec(text)) !== null) {
			addConsoleErrorUrlSuggestions(suggestions, seen, match[0]);
		}

		const sourceRegex = /(^|\s|\()([A-Za-z0-9_./@%+-]+\.(?:min\.)?(?:js|mjs))(?:\?[^\s:)]*)?(?::\d+(?::\d+)?)?/g;
		while ((match = sourceRegex.exec(text)) !== null) {
			addConsoleErrorUrlSuggestions(suggestions, seen, match[2]);
		}

		const notDefinedRegex = /(?:ReferenceError:\s*)?([A-Za-z_$][A-Za-z0-9_$.-]{2,})\s+is\s+not\s+defined/gi;
		while ((match = notDefinedRegex.exec(text)) !== null) {
			addConsoleErrorSuggestionToken(suggestions, seen, match[1]);
		}

		const wpInlineRegex = /([A-Za-z0-9_-]+-js-(?:after|before|extra|translations))/gi;
		while ((match = wpInlineRegex.exec(text)) !== null) {
			addConsoleErrorSuggestionToken(suggestions, seen, match[1]);
			addConsoleErrorSuggestionToken(suggestions, seen, match[1].replace(/-js-(?:after|before|extra|translations)$/i, ''));
		}

		const knownNeedles = [
			'woocommerce-google',
			'woocommerce-coupon-box',
			'wcb_params',
			'complianz',
			'cmplz',
			'contact-form-7',
			'wp-api-fetch',
			'api-fetch.min.js'
		];
		const lowerText = text.toLowerCase();
		knownNeedles.forEach((needle) => {
			if (lowerText.indexOf(String(needle).toLowerCase()) !== -1) {
				addConsoleErrorSuggestionToken(suggestions, seen, needle);
			}
		});

		return suggestions.slice(0, 60);
	}

	function sleep(ms) {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	function getRestErrorMessage(subAction, route, requestUrl, response, data, fallbackMessage) {
		const method = route && route.method ? String(route.method) : 'GET';
		const path = route && route.path ? '/' + String(route.path).replace(/^\/+/, '') : String(subAction || 'unknown');
		const status = response && typeof response.status !== 'undefined' ? Number(response.status || 0) : 0;
		const code = data && data.code ? String(data.code) : '';
		const message = fallbackMessage ||
			(data && data.message ? String(data.message) : '') ||
			(data && data.data && data.data.message ? String(data.data.message) : '') ||
			(status ? ('HTTP ' + status) : 'Request failed.');
		return 'UltraCache REST failed: ' + method + ' ' + path + (status ? (' returned HTTP ' + status) : '') + (code ? (' (' + code + ')') : '') + '. ' + message;
	}

	async function apiRequest(subAction, params = {}) {
		const routes = {
			stats: { path: 'stats', method: 'GET' },
			purge_all: { path: 'purge-all', method: 'POST' },
			storage_diagnostics_refresh: { path: 'diagnostics/storage/refresh', method: 'POST' },
			get_crawl_urls: { path: 'crawl-urls', method: 'GET' },
			inspect_url: { path: 'inspect-url', method: 'POST' },
			crawl_page: { path: 'crawl-page', method: 'POST' },
			build_frontpage_css: { path: 'build-frontpage-css', method: 'POST' },
			warm_frontpage_html: { path: 'warm-frontpage-html', method: 'POST' },
			warm_frontpage_html_css: { path: 'warm-frontpage-html-css', method: 'POST' },
			get_media_ids: { path: 'media-ids', method: 'GET' },
			optimize_id: { path: 'optimize-id', method: 'POST' },
			optimize_media: { path: 'optimize-media', method: 'POST' },
			media_queue_status: { path: 'media-queue/status', method: 'GET' },
			media_queue_rebuild: { path: 'media-queue/rebuild', method: 'POST' },
			media_queue_process: { path: 'media-queue/process', method: 'POST' },
			media_queue_repair: { path: 'media-queue/repair', method: 'POST' },
			media_queue_retry_failed: { path: 'media-queue/retry-failed', method: 'POST' },
			media_queue_clear_completed: { path: 'media-queue/clear-completed', method: 'POST' },
			varnish_test: { path: 'varnish/test', method: 'POST' },
			varnish_flush_all: { path: 'varnish/flush-all', method: 'POST' },
			opcache_flush: { path: 'opcache/flush', method: 'POST' },
			apcu_flush: { path: 'apcu/flush', method: 'POST' },
			litespeed_flush: { path: 'litespeed/flush', method: 'POST' },
			nginx_flush: { path: 'nginx/flush', method: 'POST' },
			external_caches_redetect: { path: 'external-caches/redetect', method: 'POST' },
			redis_test: { path: 'object-cache/redis-test', method: 'POST' },
			object_cache_test: { path: 'object-cache/backend-test', method: 'POST' },
			object_cache_flush: { path: 'object-cache/flush', method: 'POST' },
			remove_conflicting_cache_dropins: { path: 'cache-conflicts/remove-dropins', method: 'POST' },
			performance_profile_last: { path: 'performance-profile/last', method: 'GET' },
			performance_profile_clear: { path: 'performance-profile/clear', method: 'POST' },
			runtime_js_scan_report: { path: 'runtime-js-scan/report', method: 'GET' },
			runtime_js_scan_submit: { path: 'runtime-js-scan/report', method: 'POST' },
			runtime_js_scan_parse_console: { path: 'runtime-js-scan/parse-console', method: 'POST' },
			cron_warm_start: { path: 'cron-warm/start', method: 'POST' },
			cron_warm_stop: { path: 'cron-warm/stop', method: 'POST' },
			cron_warm_tick: { path: 'cron-warm/tick', method: 'POST' },
			settings: { path: 'settings', method: 'POST' },
			save_settings: { path: 'settings', method: 'POST' },
			queue_action: { path: 'action-queue', method: 'POST' },
			queue_status: { path: 'action-queue/{id}', method: 'GET' },
			queue_run: { path: 'action-queue/{id}/run', method: 'POST' },
			delete_all_data: { path: 'delete-all-data', method: 'POST' },
			populate_query_allowlist: { path: 'query-string-allowlist/populate', method: 'POST' },
			font_patterns_scan: { path: 'font-patterns/scan-frontpage', method: 'POST' },
			safe_defer_init_scan: { path: 'safe-defer/init-scan', method: 'POST' },
		};

		const route = routes[subAction];
		if (!route || !ucwpRestBase) {
			throw new Error('REST route not available for action: ' + subAction);
		}
		if (!ucwpFetch) {
			throw new Error('Browser fetch API is not available for UltraCache REST action: ' + subAction);
		}

		let payload = params;
		let requestUrl = ucwpRestBase + route.path;
		if ((subAction === 'queue_status' || subAction === 'queue_run') && params && params.id) {
			requestUrl = ucwpRestBase + route.path.replace('{id}', encodeURIComponent(String(params.id)));
		}

		if (route.method === 'GET' && params && typeof params === 'object') {
			const query = new URLSearchParams();
			Object.keys(params).forEach((key) => {
				if ((subAction === 'queue_status' || subAction === 'queue_run') && key === 'id') {
					return;
				}
				if (typeof params[key] === 'undefined' || params[key] === null || params[key] === '') {
					return;
				}
				query.append(key, String(params[key]));
			});
			const queryString = query.toString();
			if (queryString) {
				requestUrl += '?' + queryString;
			}
		}

		if (subAction === 'settings') {
			let normalizedValue = params.value;
			if (params.value === '1' || params.value === true) {
				normalizedValue = true;
			} else if (params.value === '0' || params.value === false) {
				normalizedValue = false;
			}
			payload = {
				[params.key]: normalizedValue,
			};
		} else if (subAction === 'save_settings') {
			payload = params.settings_json ? JSON.parse(params.settings_json) : {};
		} else if (subAction === 'queue_action') {
			payload = {
				action: params.action || '',
				params: params.params || {},
			};
		}

		let response = null;
		let data = null;
		try {
			response = await ucwpFetch(requestUrl, {
				method: route.method,
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'X-WP-Nonce': ucwpRestNonce || '',
					'Cache-Control': 'no-cache, no-store, max-age=0',
					'Pragma': 'no-cache',
					...(route.method !== 'GET' ? { 'Content-Type': 'application/json' } : {}),
				},
				...(route.method !== 'GET' ? { body: JSON.stringify(payload) } : {}),
			});
		} catch (error) {
			const wrapped = new Error(getRestErrorMessage(subAction, route, requestUrl, { status: 0 }, null, error && error.message ? error.message : 'Network request failed.'));
			wrapped.data = null;
			wrapped.rest = { action: subAction, method: route.method, path: route.path, url: requestUrl, status: 0, code: 'network_error' };
			throw wrapped;
		}

		try {
			data = await response.json();
		} catch (error) {}

		if (!response.ok) {
			const message = getRestErrorMessage(subAction, route, requestUrl, response, data, '');
			const error = new Error(message);
			error.data = data;
			error.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: data && data.code ? String(data.code) : '',
				message: data && data.message ? String(data.message) : '',
			};
			throw error;
		}

		if (data && data.success === false) {
			const responseMessage =
				(data.data && data.data.message) ||
				data.message ||
				'Request failed.';
			const error = new Error('UltraCache request failed: ' + route.method + ' /' + route.path + '. ' + responseMessage);
			error.data = data;
			error.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: data && data.code ? String(data.code) : '',
				message: responseMessage,
			};
			throw error;
		}

		return data;
	}

	function normalizeBatchResponse(data, cursor, limit) {
		const normalizedCursor = typeof cursor === 'string' ? cursor : '';
		const normalizedLimit = Math.max(1, Number(limit || DEFAULT_QUEUE_BATCH_SIZE));

		if (Array.isArray(data)) {
			const total = data.length;
			const items = data.slice(0, normalizedLimit);
			return {
				items,
				total,
				cursor: normalizedCursor,
				limit: normalizedLimit,
				nextCursor: '',
				nextOffset: items.length,
				processed: items.length,
				hasMore: items.length < total,
			};
		}

		const items = Array.isArray(data && data.items) ? data.items : [];
		const queue = data && data.queue && typeof data.queue === 'object' ? data.queue : null;
		const total = Math.max(items.length, Number((data && data.total) || 0));
		const queueCompleted = queue
			? Math.max(0, Number(queue.done || 0)) + Math.max(0, Number(queue.skipped || 0)) + Math.max(0, Number(queue.failed || 0))
			: 0;
		const processed = typeof (data && data.processed) !== 'undefined'
			? Math.max(0, Number(data.processed || 0))
			: Math.max(0, Number(data && data.nextOffset ? data.nextOffset : items.length));
		const nextCursor = typeof (data && data.nextCursor) === 'string' ? data.nextCursor : '';
		const queueBuilding = queue ? !queue.buildComplete : false;

		return {
			items,
			total,
			workTotal: typeof (data && data.workTotal) !== 'undefined' ? Math.max(0, Number(data.workTotal || 0)) : total,
			attachmentTotal: typeof (data && data.attachmentTotal) !== 'undefined' ? Math.max(0, Number(data.attachmentTotal || total)) : total,
			cursor: typeof (data && data.cursor) === 'string' ? data.cursor : normalizedCursor,
			limit: typeof (data && data.limit) !== 'undefined' ? Number(data.limit || normalizedLimit) : normalizedLimit,
			nextCursor: nextCursor,
			nextOffset: typeof (data && data.nextOffset) !== 'undefined' ? Number(data.nextOffset || processed) : processed,
			processed: processed,
			queueCompleted: queueCompleted,
			queueBuilding: queueBuilding,
			queuePending: queue ? Math.max(0, Number(queue.pending || 0)) : 0,
			queueFailed: queue ? Math.max(0, Number(queue.failed || 0)) : 0,
			queueSkipped: queue ? Math.max(0, Number(queue.skipped || 0)) : 0,
			queueAlreadyOptimized: queue ? Math.max(0, Number(queue.alreadyOptimized || queue.skipped || 0)) : 0,
			queueIsComplete: queue ? !!queue.isComplete : !!(data && data.complete),
			needsRepair: queue ? !!queue.needsRepair : false,
			repair: data && data.repair && typeof data.repair === 'object' ? data.repair : null,
			message: data && data.message ? String(data.message) : '',
			hasMore: typeof (data && data.hasMore) !== 'undefined' ? !!data.hasMore : !!nextCursor,
		};
	}

	const WARM_HTML_JOB_TYPES = ['warm', 'warm_menu'];
	const WARM_CSS_JOB_TYPES = [
		'warm_css',
		'warm_menu_css',
		'warm_css_homepage',
		'warm_menu_css_homepage',
		'warm_css_shared',
		'warm_menu_css_shared',
		'warm_css_per_page',
		'warm_menu_css_per_page',
	];
	const WARM_PER_PAGE_CSS_JOB_TYPES = ['warm_css', 'warm_menu_css', 'warm_css_per_page', 'warm_menu_css_per_page'];

	function normalizeCssBundleScopeValue(scope) {
		scope = String(scope || 'homepage').toLowerCase();
		return ['homepage', 'shared', 'per-page'].indexOf(scope) !== -1 ? scope : 'homepage';
	}

	function getFirstVisitCssBundleHandling(settings) {
		settings = settings || {};
		if (!!settings.pageAsyncBundleOnEntryEnabled) {
			return 'async';
		}
		if (!!settings.pageCssBundleOnEntryEnabled) {
			return 'on_entry';
		}
		return 'none';
	}

	function getFirstVisitCssBundlePatch(value) {
		value = String(value || 'none').toLowerCase();
		if ('async' === value) {
			return { pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: true };
		}
		if ('on_entry' === value) {
			return { pageCssBundleOnEntryEnabled: true, pageAsyncBundleOnEntryEnabled: false };
		}
		return { pageCssBundleOnEntryEnabled: false, pageAsyncBundleOnEntryEnabled: false };
	}

	function getCssWarmJobType(baseScope, cssBundleScope) {
		const prefix = baseScope === 'menu' ? 'warm_menu_css' : 'warm_css';
		const scope = normalizeCssBundleScopeValue(cssBundleScope);
		if ('shared' === scope) {
			return prefix + '_shared';
		}
		if ('per-page' === scope) {
			return prefix + '_per_page';
		}
		return prefix + '_homepage';
	}

	function getCssWarmBundleLabel(scope, plural) {
		scope = normalizeCssBundleScopeValue(scope);
		if ('shared' === scope) {
			return plural ? 'Shared CSS Bundles' : 'Shared CSS Bundle';
		}
		if ('per-page' === scope) {
			return plural ? 'Separate CSS Bundles' : 'Separate CSS Bundle';
		}
		return plural ? 'Homepage CSS Bundles' : 'Homepage CSS Bundle';
	}

	function isWarmJobType(type) {
		return WARM_HTML_JOB_TYPES.indexOf(type) !== -1 || WARM_CSS_JOB_TYPES.indexOf(type) !== -1;
	}

	function isWarmCssJobType(type) {
		return WARM_CSS_JOB_TYPES.indexOf(type) !== -1;
	}

	function shouldBuildCssBundleForWarmJob(type) {
		return WARM_PER_PAGE_CSS_JOB_TYPES.indexOf(type) !== -1;
	}

	function getWarmScopeForType(type) {
		return String(type || '').indexOf('warm_menu') === 0 ? 'menu' : 'full';
	}

	async function processJobItem(type, item) {
		let attempt = 0;
		let lastError = null;

		while (attempt <= MAX_ITEM_RETRIES) {
			try {
				if (isWarmJobType(type)) {
					const result = await apiRequest('crawl_page', { url: item, buildCssBundle: shouldBuildCssBundleForWarmJob(type) });
					const detail = result && result.message ? ' — ' + result.message : '';
					const skipped = !!(result && (result.cached === false || result.skipped));
					const failed = !!(result && result.success === false && !skipped);
					await sleep(40);
					return {
						line: (failed ? 'Failed: ' : (skipped ? 'Skipped: ' : 'Cached: ')) + item + detail,
						progressIncrement: 1,
						successIncrement: failed || skipped ? 0 : 1,
						skippedIncrement: skipped ? 1 : 0,
						failedIncrement: failed ? 1 : 0,
					};
				}

				const response = await apiRequest('optimize_id', { id: item });
				await sleep(20);
				const workCompleted = Math.max(1, Number(response && response.workCompleted ? response.workCompleted : response && response.workTotal ? response.workTotal : 1));
				const avifCount = Math.max(0, Number(response && response.avif ? response.avif : 0));
				const webpCount = Math.max(0, Number(response && response.webp ? response.webp : 0));
				const queueStatus = response && response.queueStatus ? String(response.queueStatus) : '';
				const skippedReason = response && response.skippedReason ? String(response.skippedReason) : '';
				const failed = !!(response && response.success === false) || queueStatus === 'failed';
				const alreadyOptimized = !failed && (skippedReason === 'already_optimized' || !!(response && response.alreadyOptimized));
				const skipped = !failed && queueStatus === 'skipped';
				const verb = failed ? 'Failed attachment #' : (response && response.converted ? 'Processed attachment #' : (alreadyOptimized ? 'Already optimized attachment #' : (skipped ? 'Checked attachment #' : 'Checked attachment #')));
				const statusSuffix = alreadyOptimized ? ' · up to date' : (queueStatus ? ' · ' + queueStatus : '');
				return {
					line: verb + item + ' · ' + workCompleted + ' unit' + (workCompleted === 1 ? '' : 's') + ' checked · AVIF ' + avifCount + ' · WebP ' + webpCount + statusSuffix,
					progressIncrement: 1,
					attachmentIncrement: 1,
					unitIncrement: workCompleted,
					avifIncrement: avifCount,
					webpIncrement: webpCount,
					successIncrement: failed || skipped ? 0 : 1,
					skippedIncrement: skipped ? 1 : 0,
					failedIncrement: failed ? 1 : 0,
				};
			} catch (error) {
				lastError = error;
				if (attempt >= MAX_ITEM_RETRIES) {
					break;
				}
				await sleep((attempt + 1) * 500);
			}
			attempt += 1;
		}

		return {
			line: (isWarmJobType(type) ? 'Failed: ' + item : 'Failed attachment #' + item) + ' — ' + (lastError && lastError.message ? lastError.message : 'Unknown error'),
			progressIncrement: 1,
			failedIncrement: 1,
		};
	}

	async function fetchJobBatch(type, cursor, limit, scope) {
		const action = isWarmJobType(type) ? 'get_crawl_urls' : 'get_media_ids';
		const params = isWarmJobType(type)
			? { cursor: cursor || '', limit, scope: scope || getWarmScopeForType(type) }
			: { offset: Math.max(0, Number(cursor || 0)), limit };
		const response = await apiRequest(action, params);
		return normalizeBatchResponse(response, cursor, limit);
	}

	function ToastViewport({ toasts, onDismiss }) {
		if (!Array.isArray(toasts) || !toasts.length) {
			return null;
		}

		return h('div', { className: 'uc-toast-viewport' },
			toasts.map((toast) => {
				const tone = toast && toast.type ? toast.type : 'info';
				const actions = toast && Array.isArray(toast.actions) ? toast.actions : [];
				return h('div', { className: classNames('uc-toast', 'uc-toast--' + tone), key: toast.id || toast.text }, [
					h('div', { className: 'uc-toast__body', key: 'body' }, [
						toast.title ? h('div', { className: 'uc-toast__title', key: 'title' }, toast.title) : null,
						h('div', { className: 'uc-toast__text', key: 'text' }, toast.text || ''),
						actions.length ? h('div', { className: 'uc-toast__actions', key: 'actions' }, actions.map((action, index) => h('button', {
							type: 'button',
							className: classNames('uc-toast__action', action && action.variant === 'danger' ? 'uc-toast__action--danger' : ''),
							onClick: () => {
								if (action && typeof action.onClick === 'function') {
									action.onClick(toast);
								}
							},
							key: 'action-' + index,
						}, action && action.label ? action.label : 'Action'))) : null,
					]),
					h('button', {
						type: 'button',
						className: 'uc-toast__close',
						onClick: () => onDismiss(toast.id),
						'aria-label': __("Dismiss notification", 'ultracache'),
						key: 'close',
					}, '×'),
				]);
			})
		);
	}



	function SupportActionLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'hire', label: __("Hire me", 'ultracache'), href: SUPPORT_LINKS.hire, onClick: onHireClick },
			{ key: 'feature', label: __("Feature request", 'ultracache'), href: SUPPORT_LINKS.feature },
			{ key: 'bug', label: __("Bug report", 'ultracache'), href: SUPPORT_LINKS.bug },
		];

		return h('div', { className: classNames('uc-support-links', 'uc-support-links--actions', compact ? 'uc-support-links--compact' : '') },
			items.map((item) => h('a', {
				key: item.key,
				className: classNames('uc-support-link', 'uc-support-link--hire'),
				href: item.href,
				onClick: item.key === 'hire' && typeof item.onClick === 'function' ? item.onClick : undefined,
			}, [
				h('span', { className: 'uc-support-link__label', key: 'label' }, item.label),
				h('span', { className: 'uc-support-link__amount', key: 'amount' }, __("Email", 'ultracache')),
			]))
		);
	}

	function SupportLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'coffee', label: __("Buy me a coffee", 'ultracache'), amount: '€5', href: SUPPORT_LINKS.coffee, kind: 'paypal' },
			{ key: 'beer', label: __("Buy me a beer", 'ultracache'), amount: '€10', href: SUPPORT_LINKS.beer, kind: 'paypal' },
			{ key: 'meal', label: __("Buy me a meal", 'ultracache'), amount: '€15', href: SUPPORT_LINKS.meal, kind: 'paypal' },
		];

		return h('div', { className: classNames('uc-support-links', compact ? 'uc-support-links--compact' : '') },
			items.map((item) => h(
				'a',
				{
					key: item.key,
					className: classNames('uc-support-link', 'uc-support-link--paypal'),
					href: item.href,
					target: item.kind === 'paypal' ? '_blank' : undefined,
					rel: item.kind === 'paypal' ? 'noopener noreferrer' : undefined,
					onClick: item.kind === 'hire' && typeof onHireClick === 'function' ? onHireClick : undefined,
				},
				[
					h('span', { className: 'uc-support-link__label', key: 'label' }, item.label),
					h('span', { className: 'uc-support-link__amount', key: 'amount' }, item.amount),
				]
			))
		);
	}

	function SupportInlineCard({ isMobile, onMobileTrigger, onHireClick }) {
		const triggerProps = isMobile
			? {
				role: 'button',
				tabIndex: 0,
				onClick: onMobileTrigger,
				onKeyDown: (event) => {
					if ('Enter' === event.key || ' ' === event.key) {
						event.preventDefault();
						onMobileTrigger();
					}
				},
			}
			: {};

		return h('div', { className: classNames('uc-support-inline', isMobile ? 'uc-support-inline--mobile' : '' ) }, [
			h('div', { className: 'uc-support-inline__copy', key: 'copy' }, [
				h('div', Object.assign({ className: 'uc-support-inline__title' }, triggerProps), __("Support this plugin", 'ultracache')),
				!isMobile ? h('p', { className: 'uc-support-inline__text' }, __("If UltraCache saves you time, you can support future development or reach out for help.", 'ultracache')) : null,
			]),
			h('div', { className: 'uc-support-inline__actions', key: 'actions' }, [
				h('div', { className: 'uc-support-inline__support-group', key: 'support-group' }, [
					h('div', { className: 'uc-support-inline__group-label' }, __("Support this plugin", 'ultracache')),
					h(SupportLinks, { compact: isMobile, key: 'paypal-links' }),
					h('div', { className: 'uc-support-inline__need-support', key: 'need-support' }, [
						h('div', { className: 'uc-support-inline__group-label', key: 'need-label' }, __("Need Support?", 'ultracache')),
						h(SupportActionLinks, { compact: isMobile, key: 'support-actions', onHireClick }),
				]),
			]),
		]),
		]);
	}


	function SupportModal({ open, isMobile, onClose, onHireClick }) {
		useEffect(() => {
			if (!open) {
				return undefined;
			}
			const handleKeyDown = (event) => {
				if ('Escape' === event.key) {
					onClose();
				}
			};
			window.addEventListener('keydown', handleKeyDown);
			return () => window.removeEventListener('keydown', handleKeyDown);
		}, [open, onClose]);

		if (!open) {
			return null;
		}

		const titleId = 'uc-support-modal-title';
		const descriptionId = 'uc-support-modal-description';

		return h('div', {
			className: classNames('uc-support-modal', isMobile ? 'uc-support-modal--mobile' : 'uc-support-modal--desktop'),
			onClick: onClose,
			role: 'presentation',
		}, [
			h('div', {
				className: 'uc-support-modal__dialog',
				onClick: (event) => event.stopPropagation(),
				role: 'dialog',
				'aria-modal': 'true',
				'aria-labelledby': titleId,
				'aria-describedby': descriptionId,
				key: 'dialog',
			}, [
				h('button', {
					type: 'button',
					className: 'uc-support-modal__close',
					onClick: onClose,
					'aria-label': __("Close support modal", 'ultracache'),
					key: 'close',
				}, '×'),
				h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __("Support this plugin", 'ultracache')),
				h('h3', { className: 'uc-support-modal__title', id: titleId, key: 'title' }, __("Support this plugin", 'ultracache')),
				h('p', { className: 'uc-support-modal__text', id: descriptionId, key: 'text' }, __("If UltraCache saves you time, you can support future updates or contact Byron directly for paid help.", 'ultracache')),
				h('div', { className: 'uc-support-modal__section-label', key: 'support-label' }, __("Support this plugin", 'ultracache')),
				h(SupportLinks, { compact: isMobile, onHireClick, key: 'links' }),
					h('div', { className: 'uc-support-modal__need-support', key: 'need-support' }, [
						h('div', { className: 'uc-support-modal__section-label', key: 'need-label' }, __("Need Support?", 'ultracache')),
						h(SupportActionLinks, { compact: isMobile, onHireClick, key: 'support-actions' }),
					]),
				]),
		]);
	}

	function Card({ title, description, children, footer }) {
		return h('div', { className: 'uc-card' }, [
			h('div', { className: 'mb-5', key: 'head' }, [
				h('h3', { className: 'text-lg font-bold m-0 text-white', key: 'title' }, title),
				description
					? h(
							'p',
							{ className: 'uc-stat-label mt-1 mb-0', key: 'desc' },
							description
					 )
					: null,
			]),
			h('div', { key: 'body' }, children),
			footer
				? h('div', { className: 'mt-5 pt-4 border-t border-white/5', key: 'footer' }, footer)
				: null,
		]);
	}

	function StatCard({ label, value, hint, action }) {
		return h('div', { className: 'uc-card relative' }, [
			h('div', { className: 'text-xs tracking-widest text-zinc-500 mb-2 pr-8', key: 'label' }, label),
			h('div', { className: 'ucwp-stat-card-value text-3xl font-black tracking-tight text-white pr-8', key: 'value' }, value),
			h('div', { className: 'text-xs text-zinc-500 mt-2 pr-8', key: 'hint' }, hint || '\u00A0'),
			action
				? h('button', {
					type: 'button',
					className: 'uc-stat-card-action',
					title: action.title || '',
					onClick: action.onClick,
					disabled: !!action.disabled,
					key: 'action',
				}, action.label || '+')
				: null,
		]);
	}

	function Button({ onClick, disabled, children, variant }) {
		const styleClass =
			variant === 'primary'
				? 'uc-btn--primary text-white'
				: variant === 'light'
				? 'uc-btn--primary text-white'
				: variant === 'danger'
				? 'uc-btn--danger'
				: '';

		return h(
			'button',
			{
				className: classNames('uc-btn', styleClass, 'disabled:opacity-50 disabled:cursor-not-allowed'),
				onClick,
				disabled,
			},
			children
		);
	}

	function ToggleRow({ label, description, checked, onChange, disabled }) {
		return h('div', { className: 'flex items-center justify-between py-4' }, [
			h('div', { key: 'left' }, [
				h('div', { className: 'text-sm font-medium text-white' }, label),
				h('div', { className: 'text-xs text-zinc-500' }, description),
			]),
			h(
				'label',
				{
					className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
					key: 'right',
				},
				[
					h('input', {
						type: 'checkbox',
						checked: !!checked,
						disabled: !!disabled,
						onChange: (e) => onChange(e.target.checked),
					}),
					h('span', { className: 'slider' }),
				]
			),
		]);
	}

	function ToggleField({ label, description, checked, onChange, disabled }) {
		return h('div', { className: 'uc-field-wrap' }, [
			h('div', { className: 'flex items-center justify-between gap-4 px-1 py-1' }, [
				h('div', { key: 'left', className: 'min-w-0 flex-1' }, [
					label ? h('div', { className: 'uc-field-label mb-0' }, label) : null,
					description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
				]),
				h(
					'label',
					{
						className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
						key: 'right',
					},
					[
						h('input', {
							type: 'checkbox',
							checked: !!checked,
							disabled: !!disabled,
							onChange: (e) => onChange(e.target.checked),
						}),
						h('span', { className: 'slider' }),
					]
				),
			]),
		]);
	}

	function TextAreaField({ label, description, value, onChange, disabled, placeholder }) {
		return h('div', { className: 'uc-field-wrap' }, [
			h('label', { className: 'uc-field-label' }, label),
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function SaveableTextAreaField({ label, description, value, onSave, disabled, placeholder, saveLabel, populateLabel, populateBusyLabel, onPopulate, populateWarning }) {
		const [draft, setDraft] = useState(value || '');
		const [populateBusy, setPopulateBusy] = useState(false);

		useEffect(() => {
			setDraft(value || '');
		}, [value]);

		const currentValue = String(value || '');
		const draftValue = String(draft || '');
		const hasChanges = draftValue !== currentValue;
		const hasPopulate = typeof onPopulate === 'function';
		const hasDraftContent = draftValue.trim().length > 0;

		async function handlePopulateClick() {
			if (!hasPopulate || populateBusy || disabled) {
				return;
			}

			if (hasDraftContent && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm(populateWarning || 'Your current whitelist will be replaced.')) {
					return;
				}
			}

			setPopulateBusy(true);
			try {
				const populatedValue = await onPopulate(draftValue);
				if (typeof populatedValue === 'string') {
					setDraft(populatedValue);
				}
			} finally {
				setPopulateBusy(false);
			}
		}

		return h('div', { className: 'uc-field-wrap' }, [
			h('label', { className: 'uc-field-label' }, label),
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => setDraft(e.target.value),
			}),
			hasPopulate && hasDraftContent ? h('div', { className: 'mt-2 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, populateWarning || 'Your current whitelist will be replaced.') : null,
			h('div', { className: 'mt-3 flex items-center justify-between gap-3' }, [
				hasPopulate ? h(Button, {
					onClick: handlePopulateClick,
					disabled: !!disabled || populateBusy,
				}, populateBusy ? (populateBusyLabel || 'Populating…') : (populateLabel || 'Populate')) : h('span', { 'aria-hidden': 'true' }, ''),
				h(Button, {
					onClick: () => onSave(draftValue),
					disabled: !!disabled || !hasChanges,
					variant: 'primary',
				}, saveLabel || 'Save'),
			]),
		]);
	}

	function DeferDelayExclusionsField({ value, onSave, disabled, placeholder, onPopulateDefaults, onSafeInitScan, onScan, onRuntimeScan, onLoadLatestProfileScan }) {
		const defaultScanUrl = (typeof ucwp !== "undefined" && ucwp && ucwp.frontendProbeUrl) ? String(ucwp.frontendProbeUrl || "") : "";
		const [draft, setDraft] = useState(value || "");
		const [scanUrl, setScanUrl] = useState(defaultScanUrl);
		const [scan, setScan] = useState(null);
		const [populateBusy, setPopulateBusy] = useState(false);
		const [scanBusy, setScanBusy] = useState(false);
		const [initScanBusy, setInitScanBusy] = useState(false);
		const [debugScanBusy, setDebugScanBusy] = useState(false);
		const [runtimeScanBusy, setRuntimeScanBusy] = useState(false);
		const [runtimeScanStatus, setRuntimeScanStatus] = useState('');
		const [runtimeScanContext, setRuntimeScanContext] = useState('anonymous');
		const [consoleErrorInput, setConsoleErrorInput] = useState('');
		const [consoleErrorSuggestions, setConsoleErrorSuggestions] = useState([]);
		const [consoleErrorScan, setConsoleErrorScan] = useState(null);
		const [consoleErrorStatus, setConsoleErrorStatus] = useState('');
		const [consoleErrorBusy, setConsoleErrorBusy] = useState(false);

		useEffect(() => {
			setDraft(value || '');
		}, [value]);

		const currentValue = String(value || '');
		const draftValue = String(draft || '');
		const hasChanges = draftValue !== currentValue;
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		const actionableSuggestions = suggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored);
		const appendableSuggestions = actionableSuggestions.filter((item) => item.appendable !== false && item.category !== 'review-only');
		const reviewOnlySuggestions = actionableSuggestions.filter((item) => item.appendable === false || item.category === 'review-only');
		const missingAppendableSuggestions = appendableSuggestions.filter((item) => !isSuggestionPresentInDraft(draftValue, item.suggestedExclusion));
		const alreadyListedAppendableSuggestions = appendableSuggestions.filter((item) => isSuggestionPresentInDraft(draftValue, item.suggestedExclusion));
		const missingReviewOnlySuggestions = reviewOnlySuggestions.filter((item) => !isSuggestionPresentInDraft(draftValue, item.suggestedExclusion));
		const totalDetected = scan && typeof scan.suggestionCount !== 'undefined' ? Number(scan.suggestionCount || 0) : suggestions.length;
		const liveMissingCount = missingAppendableSuggestions.length;
		const liveAlreadyListedCount = alreadyListedAppendableSuggestions.length;
		const reviewOnlyCount = reviewOnlySuggestions.length;
		const runtimeErrors = scan && Array.isArray(scan.errors) ? scan.errors : [];
		const resourceErrors = scan && Array.isArray(scan.resourceErrors) ? scan.resourceErrors : (scan && Array.isArray(scan.blockedResources) ? scan.blockedResources : []);
		const resourceErrorCount = scan && typeof scan.resourceErrorCount !== 'undefined' ? Number(scan.resourceErrorCount || 0) : resourceErrors.length;
		const blockedResourceCount = scan && typeof scan.blockedResourceCount !== 'undefined' ? Number(scan.blockedResourceCount || 0) : resourceErrors.filter((item) => item && item.likelyClientBlocked).length;
		const missingConsoleErrorSuggestions = consoleErrorSuggestions.filter((line) => !isSuggestionPresentInDraft(draftValue, line));
		const consoleSuggestions = consoleErrorScan && Array.isArray(consoleErrorScan.suggestions) ? consoleErrorScan.suggestions : [];
		const consoleActionableSuggestions = consoleSuggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored);
		const consoleAppendableSuggestions = consoleActionableSuggestions.filter((item) => item.appendable !== false && item.category !== 'review-only');
		const consoleReviewOnlySuggestions = consoleActionableSuggestions.filter((item) => item.appendable === false || item.category === 'review-only');
		const missingConsoleReviewOnlySuggestions = consoleReviewOnlySuggestions.filter((item) => !isSuggestionPresentInDraft(draftValue, item.suggestedExclusion));

		function appendJsExclusionLine(line) {
			const suggestion = String(line || '').trim();
			if (!suggestion) {
				return;
			}
			const merged = mergeUniqueSettingLines(draftValue, suggestion);
			setDraft(merged.value);
		}


		function renderSuggestionItem(item, keyPrefix, index) {
			const line = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
			const present = isSuggestionPresentInDraft(draftValue, line);
			const reviewOnly = item && (item.appendable === false || item.category === 'review-only');
			const statusText = reviewOnly ? (present ? 'already listed · review only' : 'review only') : (present ? 'already listed' : 'missing');
			const statusClass = reviewOnly ? 'text-sky-300' : (present ? 'text-emerald-400' : 'text-amber-300');
			const metaRows = [
				['Status', statusText, statusClass],
				['Confidence', item && item.confidence ? String(item.confidence) : '—', 'text-zinc-300'],
				['Category', item && item.categoryLabel ? String(item.categoryLabel) : (reviewOnly ? 'Review-only candidates' : 'Detected recommendation'), reviewOnly ? 'text-sky-300' : 'text-violet-300'],
			];
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-3 space-y-2 border border-white/5', key: keyPrefix + '-' + index + '-' + line }, [
				h('div', { className: 'space-y-1' }, [
					h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Suggested exclusion", 'ultracache')),
					h('div', { className: 'flex flex-wrap items-center gap-2' }, [
						h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1.5' }, line || 'unknown'),
						line ? h('button', { type: 'button', className: 'uc-btn text-[11px] px-2 py-1', disabled: !!disabled || present, onClick: () => appendJsExclusionLine(line) }, reviewOnly ? (present ? 'Already in exclusions' : 'Append reviewed') : (present ? 'Already in exclusions' : 'Append')) : null,
					]),
				]),
				h('div', { className: 'grid grid-cols-1 sm:grid-cols-3 gap-2' }, metaRows.map((row, rowIndex) => h('div', { className: 'rounded bg-black/15 px-2 py-1', key: keyPrefix + '-meta-' + index + '-' + rowIndex }, [
					h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, row[0]),
					h('div', { className: 'text-[11px] font-semibold ' + row[2] }, row[1]),
				]))),
				item.reason ? h('div', { className: 'text-zinc-400 leading-relaxed pt-1' }, item.reason) : null,
				item.sample ? h('div', { className: 'text-zinc-500 leading-relaxed break-all bg-black/15 rounded px-2 py-1.5' }, [
					h('span', { className: 'text-zinc-400 font-semibold' }, __("Sample: ", 'ultracache')),
					String(item.sample),
				]) : null,
			]);
		}

		function getSuggestionGroupInfo(item) {
			const line = item && item.suggestedExclusion ? String(item.suggestedExclusion).toLowerCase() : '';
			const reason = item && item.reason ? String(item.reason) : '';
			const text = line + ' ' + reason.toLowerCase();
			if (/revslider|sr7|tptools|tp-tools|rs6|rs-module|slider revolution/.test(text)) {
				return { key: 'slider-revolution-sr7', title: __("Slider Revolution / SR7", 'ultracache'), reason: 'Slider Revolution / SR7 assets or markup were detected on this page. Keep slider runtime assets protected unless visually tested.' };
			}
			if (/swiper|swiper-bundle/.test(text)) {
				return { key: 'swiper', title: 'Swiper', reason: 'Swiper slider/carousel assets or markup were detected on this page.' };
			}
			if (/slick/.test(text)) {
				return { key: 'slick', title: __("Slick carousel", 'ultracache'), reason: 'Slick carousel assets or markup were detected on this page.' };
			}
			if (/splide|owl\.carousel|smartslider|n2-ss|layerslider|masterslider|metaslider|soliloquy|royalslider|flickity|glide/.test(text)) {
				return { key: 'other-slider-carousel', title: __("Other slider / carousel", 'ultracache'), reason: 'Slider or carousel assets were detected on this page.' };
			}
			if (/react|react-dom|wp-element|notes-app-initiator/.test(text)) {
				return { key: 'react-wp-element', title: 'React / wp-element runtime', reason: 'A browser runtime error points to the WordPress React dependency chain or a dependent script that executed too early.' };
			}
			if (/wp-api-fetch|api-fetch|wp-hooks|wp-api-fetch-js-after/.test(text)) {
				return { key: 'wp-api-fetch', title: __("WordPress apiFetch runtime", 'ultracache'), reason: 'A WordPress inline-after block or apiFetch configuration ran before its dependency chain was available.' };
			}
			if (/elementor|elementormodules|frontend-modules|webpack\.runtime/.test(text)) {
				return { key: 'elementor', title: __("Elementor runtime", 'ultracache'), reason: 'Elementor assets or widgets were detected on this page. Keep core runtime dependencies protected unless dependency-safe testing passes.' };
			}
			if (/divi|et-core|et-builder/.test(text)) {
				return { key: 'divi', title: __("Divi / Elegant Themes", 'ultracache'), reason: 'Divi builder assets were detected on this page.' };
			}
			if (/wpbakery|vc_|bricks|oxygen|beaver-builder|fl-builder|fusion-builder|avada|thrive|seedprod|siteorigin|spectra|uagb|kadence|generateblocks/.test(text)) {
				return { key: 'builder-runtime', title: __("Builder runtime", 'ultracache'), reason: 'Builder/runtime assets were detected on this page.' };
			}
			if (/complianz|cmplz/.test(text)) {
				return { key: 'complianz', title: __("Complianz consent scripts", 'ultracache'), reason: 'Complianz consent assets were detected. Consent/cookie scripts are safer outside Delay JS.' };
			}
			if (/cookieyes|cookielawinfo|cky-|cookiebot|iubenda|onetrust|optanon/.test(text)) {
				return { key: 'consent-management', title: __("Cookie / consent management", 'ultracache'), reason: 'Cookie/consent-management assets were detected. Consent scripts are safer outside Delay JS.' };
			}
			if (/mailerlite|validation-messages|mailchimp|mc4wp|klaviyo|hubspot|contact-form-7|wpforms|gform|gravityforms|formidable|ninja-forms|fluentform|forminator|recaptcha|hcaptcha|turnstile/.test(text)) {
				return { key: 'forms-validation', title: __("Forms / validation / newsletter", 'ultracache'), reason: 'Form, validation, newsletter, or CRM assets were detected on this page.' };
			}
			if (/woocommerce|wc-|cart|checkout|account|add-to-cart|wc-cart-fragments|stripe|paypal|braintree|klarna|afterpay|square/.test(text)) {
				return { key: 'ecommerce-checkout', title: __("WooCommerce / ecommerce", 'ultracache'), reason: 'Commerce or checkout-related markers were detected. Review before excluding broadly.' };
			}
			if (/gtag|gtm|datalayer|adsbygoogle|stats\.wp\.com|_stq|facebook\.net|fbevents|hotjar|clarity|googletagmanager|google-analytics/.test(text)) {
				return { key: 'tracking-ads', title: __("Tracking / ads", 'ultracache'), reason: 'Tracking or ads scripts were detected. These are review-only because delaying them often improves performance but may affect tracking timing.' };
			}
			return { key: item && item.category ? String(item.category) : 'other', title: item && item.categoryLabel ? String(item.categoryLabel) : 'Other detected recommendation', reason: reason };
		}

		function groupSuggestionItems(items) {
			const groups = [];
			const byKey = {};
			(items || []).forEach((item) => {
				const info = getSuggestionGroupInfo(item);
				if (!byKey[info.key]) {
					byKey[info.key] = { key: info.key, title: info.title, reason: info.reason, items: [] };
					groups.push(byKey[info.key]);
				}
				byKey[info.key].items.push(item);
			});
			return groups;
		}

		function renderSuggestionGroup(group, keyPrefix, index, collapsed) {
			const items = group && Array.isArray(group.items) ? group.items : [];
			const missingCount = items.filter((item) => !isSuggestionPresentInDraft(draftValue, item && item.suggestedExclusion)).length;
			const reviewOnly = items.some((item) => item && (item.appendable === false || item.category === 'review-only'));
			const lines = items.map((item) => String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim()).filter(Boolean);
			const summaryStatus = reviewOnly ? 'review only' : (missingCount ? (missingCount + ' missing') : 'covered');
			return h('details', { className: 'rounded-lg bg-black/20 px-3 py-2', key: keyPrefix + '-group-' + index + '-' + group.key, open: !collapsed }, [
				h('summary', { className: 'cursor-pointer list-none flex flex-wrap items-center justify-between gap-2' }, [
					h('span', { className: 'text-zinc-200 font-semibold' }, group.title || 'Detected group'),
					h('span', { className: reviewOnly ? 'text-sky-300 font-mono text-[11px]' : (missingCount ? 'text-amber-300 font-mono text-[11px]' : 'text-emerald-300 font-mono text-[11px]') }, summaryStatus + ' · ' + items.length + ' line(s)'),
				]),
				group.reason ? h('div', { className: 'text-zinc-500 mt-2' }, group.reason) : null,
				lines.length ? h('div', { className: 'mt-2 flex flex-wrap gap-1' }, lines.map((line, lineIndex) => h('code', { className: 'font-mono text-[11px] text-emerald-300 bg-black/25 rounded px-2 py-1 break-all', key: keyPrefix + '-line-' + index + '-' + lineIndex }, line))) : null,
				reviewOnly ? h('div', { className: 'mt-2 text-[11px] text-zinc-500' }, __("Review-only candidates are shown for manual judgement and can be appended one by one. They are never appended automatically.", 'ultracache')) : null,
				h('div', { className: 'mt-2 space-y-2' }, items.map((item, itemIndex) => renderSuggestionItem(item, keyPrefix + '-detail-' + index, itemIndex))),
			]);
		}

		function renderRuntimeErrorItem(error, index) {
			const message = String(error && error.message ? error.message : 'Unknown browser runtime error');
			const source = String(error && error.source ? error.source : '');
			const detail = String(error && error.detail ? error.detail : '');
			const line = Number(error && error.line ? error.line : 0);
			const column = Number(error && error.column ? error.column : 0);
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2 text-[11px] text-zinc-300 space-y-1', key: 'runtime-error-' + index }, [
				h('div', { className: 'text-amber-300 font-semibold break-all' }, message),
				source ? h('div', { className: 'text-zinc-400 font-mono break-all' }, source + (line ? ':' + line + (column ? ':' + column : '') : '')) : null,
				detail ? h('pre', { className: 'text-zinc-500 whitespace-pre-wrap break-all bg-black/15 rounded px-2 py-1 max-h-24 overflow-y-auto' }, detail.slice(0, 1200)) : null,
			]);
		}

		function renderRuntimeErrorsSection(errors) {
			if (!errors || !errors.length) {
				return null;
			}
			return h('details', { className: 'mt-3 rounded-lg bg-black/20 px-3 py-3', open: false, key: 'runtime-errors-captured' }, [
				h('summary', { className: 'cursor-pointer list-none flex flex-wrap items-center justify-between gap-2' }, [
					h('span', { className: 'text-zinc-200 font-semibold' }, __("Captured browser runtime errors", 'ultracache')),
					h('span', { className: 'text-amber-300 font-mono text-[11px]' }, String(errors.length) + ' error(s)'),
				]),
				h('div', { className: 'text-[11px] text-zinc-500 mt-2 mb-2' }, __("Raw captured errors are shown for debugging even when no confident exclusion can be suggested.", 'ultracache')),
				h('div', { className: 'space-y-2' }, errors.slice(0, 20).map((error, index) => renderRuntimeErrorItem(error, index))),
			]);
		}


		function renderResourceErrorsSection(items) {
			if (!items || !items.length) {
				return null;
			}
			return h('details', { className: 'mt-3 rounded-lg bg-black/20 px-3 py-3', open: true, key: 'runtime-resource-errors-captured' }, [
				h('summary', { className: 'cursor-pointer list-none flex flex-wrap items-center justify-between gap-2' }, [
					h('span', { className: 'text-zinc-200 font-semibold' }, __("Blocked / failed resources", 'ultracache')),
					h('span', { className: 'text-sky-300 font-mono text-[11px]' }, String(items.length) + ' resource(s)'),
				]),
				h('div', { className: 'text-[11px] text-zinc-500 mt-2 mb-2' }, __("These are network/resource load failures. If Chrome shows ERR_BLOCKED_BY_CLIENT, this is usually a browser extension/privacy blocker and is not counted as a missing JS Delay / Defer exclusion.", 'ultracache')),
				h('div', { className: 'space-y-2' }, items.slice(0, 20).map((item, index) => {
					const source = String(item && item.source ? item.source : '');
					const detail = String(item && item.detail ? item.detail : '');
					const likely = !!(item && item.likelyClientBlocked);
					return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2 text-[11px] text-zinc-300 space-y-1', key: 'runtime-resource-error-' + index }, [
						h('div', { className: likely ? 'text-sky-300 font-semibold' : 'text-zinc-300 font-semibold' }, likely ? 'Likely blocked by client / extension' : 'Resource failed to load'),
						source ? h('div', { className: 'text-zinc-400 font-mono break-all' }, source) : null,
						detail ? h('div', { className: 'text-zinc-500 break-all' }, detail) : null,
					]);
				})),
			]);
		}

		function renderSuggestionSection(title, count, items, emptyText, keyPrefix, note, options) {
			const opts = options || {};
			const grouped = !!opts.grouped;
			const collapsed = !!opts.collapsed;
			const groups = grouped ? groupSuggestionItems(items) : [];
			return h('div', { className: 'mt-3', key: keyPrefix }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
					h('span', { className: 'text-zinc-300 font-semibold' }, title),
					h('span', { className: count ? 'text-amber-300 font-mono' : 'text-emerald-300 font-mono' }, String(count || 0)),
				]),
				note ? h('div', { className: 'text-[11px] text-zinc-500 mb-2' }, note) : null,
				items.length ? (grouped ? h('div', { className: 'space-y-2' }, groups.map((group, index) => renderSuggestionGroup(group, keyPrefix, index, collapsed))) : h('div', { className: 'space-y-2' }, items.map((item, index) => renderSuggestionItem(item, keyPrefix, index)))) : h('div', { className: 'text-zinc-500' }, emptyText),
			]);
		}

		async function handleExtractConsoleErrors() {
			const input = String(consoleErrorInput || '');
			if (!input.trim()) {
				setConsoleErrorSuggestions([]);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('Paste one or more browser console errors first.');
				return;
			}
			setConsoleErrorBusy(true);
			setConsoleErrorStatus('Parsing console errors with the Runtime Scan suggestion engine…');
			try {
				const response = await apiRequest('runtime_js_scan_parse_console', { text: input, url: scanUrl || defaultScanUrl });
				const scan = response && response.consoleErrorScan ? response.consoleErrorScan : (response && response.jsDelaySafetyScan ? response.jsDelaySafetyScan : null);
				const extracted = getJsDelaySafetySuggestions(scan);
				const reviewOnly = getJsDelayReviewSuggestions(scan);
				setConsoleErrorSuggestions(extracted);
				setConsoleErrorScan(scan || null);
				if (!extracted.length && !reviewOnly.length) {
					setConsoleErrorStatus('No Runtime Scan suggestions were detected. Broad/generic sources such as jquery.min.js and same-origin domains are ignored unless the scanned HTML inventory can resolve an exact path or inline handle.');
				} else if (!extracted.length) {
					setConsoleErrorStatus('Detected ' + reviewOnly.length + ' review-only Runtime Scan candidate(s). They are shown below for manual review and can be appended one by one.');
				} else {
					setConsoleErrorStatus('Detected ' + extracted.length + ' appendable Runtime Scan suggestion(s)' + (reviewOnly.length ? (' and ' + reviewOnly.length + ' review-only candidate(s)') : '') + '. Review the preview, then append missing recommended tokens.');
				}
			} catch (error) {
				const fallback = extractConsoleErrorExclusions(input);
				setConsoleErrorSuggestions(fallback);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('Runtime Scan parser failed, so UltraCache used the local fallback parser. ' + (error && error.message ? String(error.message) : ''));
			} finally {
				setConsoleErrorBusy(false);
			}
		}

		function handleAppendConsoleErrors() {
			const lines = missingConsoleErrorSuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'All extracted console-error exclusions are already listed.' : 'Extract suggestions before appending.');
				return;
			}
			const merged = mergeUniqueSettingLines(draftValue, lines);
			setDraft(merged.value);
			setConsoleErrorStatus(merged.added ? ('Appended ' + merged.added + ' console-error exclusion(s).') : 'All extracted console-error exclusions are already listed.');
		}

		async function handlePopulateDefaults() {
			if (disabled || populateBusy || typeof onPopulateDefaults !== 'function') {
				return;
			}
			setPopulateBusy(true);
			try {
				const next = await onPopulateDefaults(draftValue);
				if (typeof next === 'string') {
					setDraft(next);
				}
			} finally {
				setPopulateBusy(false);
			}
		}

		async function handleSafeInitScan() {
			if (disabled || initScanBusy || typeof onSafeInitScan !== 'function') {
				return;
			}
			setInitScanBusy(true);
			try {
				const next = await onSafeInitScan(draftValue);
				if (typeof next === 'string') {
					setDraft(next);
				}
			} finally {
				setInitScanBusy(false);
			}
		}

		async function handleScan() {
			if (disabled || scanBusy || typeof onScan !== 'function') {
				return;
			}
			setScanBusy(true);
			try {
				const result = await onScan(scanUrl);
				if (result && typeof result === 'object') {
					setScan(result);
				}
			} finally {
				setScanBusy(false);
			}
		}


		async function handleRuntimeScan() {
			if (disabled || runtimeScanBusy || typeof onRuntimeScan !== 'function') {
				return;
			}
			setRuntimeScanBusy(true);
			setRuntimeScanStatus('Opening diagnostic page…');
			try {
				const result = await onRuntimeScan(scanUrl, function(statusText) {
					setRuntimeScanStatus(String(statusText || ''));
				});
				if (result && typeof result === 'object') {
					setScan(result);
				}
			} finally {
				setRuntimeScanBusy(false);
			}
		}

		async function handleDebugLoadLatestProfileScan() {
			if (disabled || debugScanBusy || typeof onLoadLatestProfileScan !== 'function') {
				return;
			}
			setDebugScanBusy(true);
			try {
				const result = await onLoadLatestProfileScan();
				if (result && typeof result === 'object') {
					setScan(result);
				}
			} finally {
				setDebugScanBusy(false);
			}
		}

		function handleAppendSuggestions() {
			const lines = getJsDelaySafetySuggestions(scan).filter((line) => !isSuggestionPresentInDraft(draftValue, line));
			if (!lines.length) {
				return;
			}
			const merged = mergeUniqueSettingLines(draftValue, lines);
			setDraft(merged.value);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' } }, [
			h('label', { className: 'uc-field-label' }, __("JS Delay / Defer Exclusions", 'ultracache')),
			h('div', { className: 'text-xs text-zinc-500 mb-2' }, __("Optional newline-separated handle or URL fragments. UltraCache uses this visible/editable safeguard list for Safe Defer JS, Defer all JS, Delay safe/known functional/all third-party JS, Delay non-critical/local JS, LCP Boundary Defer, and Main Thread Relief where applicable. Populate Defaults adds only the hard dependency-safety floor here; broad slider/theme/WooCommerce/Elementor/tracking protections are not added by default. UltraCache does not silently apply recommended defaults when this box is empty or user-edited. Scan suggestions are appended only if missing; existing custom lines are preserved.", 'ultracache')),
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => setDraft(e.target.value),
			}),
			h('div', { className: 'mt-3 mb-2' }, [
				h('label', { className: 'uc-field-label' }, __("Page URL to scan", 'ultracache')),
				h('input', {
					type: 'url',
					className: 'uc-field-input',
					value: scanUrl,
					disabled: !!disabled || scanBusy,
					placeholder: defaultScanUrl || 'https://example.com/page/',
					onChange: (e) => setScanUrl(e.target.value),
				}),
				h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, 'Scan a same-site page. UltraCache profiles the final HTML and shows missing visible exclusions, including inline dependency blocks such as jquery-js-after, wp-i18n-js-after, wp-api-fetch-js-after, and *-js-translations. Nothing is applied automatically by the scan button; Safe Defer JS can append detected init scripts here only when you enable that switch.'),
			]),
			h('div', { className: 'mt-2 mb-2 flex flex-wrap items-center text-[11px] text-zinc-500' }, [
				h('span', { className: 'text-zinc-400', style: { marginRight: '10px' } }, __("Runtime Scan context", 'ultracache')),
				h('select', {
					className: 'uc-field-input uc-field-select',
					style: { maxWidth: '260px', marginRight: '8px', paddingLeft: '8px', paddingRight: '34px', paddingTop: '7px', paddingBottom: '7px' },
					value: runtimeScanContext,
					disabled: !!disabled || runtimeScanBusy,
					onChange: (e) => setRuntimeScanContext(e && e.target ? String(e.target.value || 'anonymous') : 'anonymous'),
				}, [
					h('option', { value: 'anonymous' }, __("Anonymous frontend", 'ultracache')),
					h('option', { value: 'logged-in' }, __("Logged-in/admin frontend", 'ultracache')),
				]),
				h('span', null, runtimeScanContext === 'anonymous' ? 'Recommended for public cache debugging. Admin cookies are ignored while rendering the scan page.' : 'Useful only for admin-bar/editor/frontend issues.'),
			]),
			h('div', { className: 'uc-js-scan-actions mt-3 mb-3' }, [
				h(Button, { key: 'defaults', onClick: handlePopulateDefaults, disabled: !!disabled || populateBusy }, populateBusy ? 'Populating…' : 'Populate Defaults'),
				h(Button, { key: 'safe-init-scan', onClick: handleSafeInitScan, disabled: !!disabled || initScanBusy }, initScanBusy ? 'Scanning init scripts…' : 'Scan & append detected init scripts'),
				h(Button, { key: 'scan', onClick: handleScan, disabled: !!disabled || scanBusy }, scanBusy ? 'Scanning…' : 'JS Delay / Defer Scan'),
				h(Button, { key: 'runtime-scan', onClick: handleRuntimeScan, disabled: !!disabled || runtimeScanBusy }, runtimeScanBusy ? 'Runtime scanning…' : 'Runtime Scan'),
				h(Button, { key: 'debug-scan', onClick: handleDebugLoadLatestProfileScan, disabled: !!disabled || debugScanBusy }, debugScanBusy ? 'Loading…' : 'Debug Profile'),
				h(Button, { key: 'append', onClick: handleAppendSuggestions, disabled: !!disabled || !liveMissingCount }, 'Append Missing' + (liveMissingCount ? ' (' + liveMissingCount + ')' : '')),
				h(Button, { key: 'save', onClick: () => onSave(draftValue), disabled: !!disabled || !hasChanges, variant: 'primary' }, __("Save", 'ultracache')),
			]),
			h('div', { className: 'mt-3 mb-3 rounded-xl bg-black/20 px-3 py-3 border border-white/5' }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
					h('span', { className: 'text-zinc-200 font-semibold' }, __("Console Error Handler", 'ultracache')),
					consoleErrorSuggestions.length ? h('span', { className: missingConsoleErrorSuggestions.length ? 'text-amber-300 font-mono text-[11px]' : 'text-emerald-300 font-mono text-[11px]' }, String(missingConsoleErrorSuggestions.length) + ' missing / ' + String(consoleErrorSuggestions.length) + ' detected') : null,
				]),
				h('div', { className: 'text-[11px] text-zinc-500 mb-2' }, 'Paste browser console errors here. UltraCache uses the selected Scan URL/front page to read the same-site JS files loaded by that HTML, resolve missing jQuery plugin methods/globals to their provider scripts where possible, and append clean deduplicated tokens to JS Delay / Defer Exclusions. Broad sources such as jquery.min.js, main.js, functions.js, and same-origin domains are ignored.'),
				h('textarea', {
					className: 'uc-field-input uc-field-textarea',
					style: { minHeight: '105px' },
					value: consoleErrorInput,
					disabled: !!disabled,
					placeholder: 'Paste console errors, e.g. "complianz is not defined" or stack lines containing /wp-content/plugins/example/js/file.min.js',
					onChange: (e) => setConsoleErrorInput(e.target.value),
				}),
				h('div', { className: 'mt-3 flex flex-wrap items-center gap-2' }, [
					h(Button, { key: 'extract-console-errors', onClick: handleExtractConsoleErrors, disabled: !!disabled || consoleErrorBusy }, consoleErrorBusy ? 'Extracting…' : 'Extract suggestions'),
					h(Button, { key: 'append-console-errors', onClick: handleAppendConsoleErrors, disabled: !!disabled || !missingConsoleErrorSuggestions.length }, 'Append to exclusions' + (missingConsoleErrorSuggestions.length ? ' (' + missingConsoleErrorSuggestions.length + ')' : '')),
					h(Button, { key: 'clear-console-errors', onClick: () => { setConsoleErrorInput(''); setConsoleErrorSuggestions([]); setConsoleErrorScan(null); setConsoleErrorStatus(''); }, disabled: !!disabled || (!consoleErrorInput && !consoleErrorSuggestions.length && !consoleErrorScan) }, __("Clear", 'ultracache')),
				]),
				consoleErrorStatus ? h('div', { className: 'mt-2 text-[11px] text-sky-300' }, consoleErrorStatus) : null,
				consoleErrorSuggestions.length ? h('div', { className: 'mt-3' }, [
					h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500 mb-2' }, __("Extracted appendable exclusion preview", 'ultracache')),
					h('div', { className: 'flex flex-wrap gap-1' }, consoleErrorSuggestions.map((line, index) => h('code', { className: isSuggestionPresentInDraft(draftValue, line) ? 'font-mono text-[11px] text-emerald-300 bg-black/25 rounded px-2 py-1 break-all' : 'font-mono text-[11px] text-amber-300 bg-black/25 rounded px-2 py-1 break-all', key: 'console-error-suggestion-' + index + '-' + line }, line))),
				]) : null,
				consoleErrorScan ? h('div', { className: 'mt-3 text-xs bg-black/20 rounded-xl px-3 py-3', key: 'console-error-scan-preview' }, [
					h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
						h('span', { className: 'text-zinc-300 font-bold' }, __("Console Extract Suggestions", 'ultracache')),
						h('span', { className: 'text-zinc-500 font-mono break-all' }, consoleErrorScan.scannedUrl ? String(consoleErrorScan.scannedUrl) : ''),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-2 mb-3' }, [
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Appendable", 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(consoleAppendableSuggestions.length))]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Review-only", 'ultracache')), h('div', { className: missingConsoleReviewOnlySuggestions.length ? 'font-mono text-sky-300' : 'font-mono text-zinc-300' }, String(consoleReviewOnlySuggestions.length))]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Script inventory", 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(consoleErrorScan.scriptInventoryCount || 0))]),
					]),
					consoleErrorScan.scriptInventorySummary ? h('div', { className: 'text-[11px] text-zinc-500 mb-3 font-mono break-all' }, 'Inventory detail: external ' + String(consoleErrorScan.scriptInventorySummary.external || 0) + ' · inline ' + String(consoleErrorScan.scriptInventorySummary.inline || 0) + ' · delayed ' + String(consoleErrorScan.scriptInventorySummary.delayed || 0) + ' · sourceURL ' + String(consoleErrorScan.scriptInventorySummary.sourceUrl || 0)) : null,
					renderRuntimeErrorsSection(consoleErrorScan.errors || []),
					renderSuggestionSection('Console review-only candidates', consoleReviewOnlySuggestions.length, consoleReviewOnlySuggestions, 'No review-only candidates were detected from this console paste.', 'console-review-only', 'These are exact stack-frame/script-inventory ids or paths. Review them and append only the candidates that match the affected feature.', { grouped: true, collapsed: false }),
				]) : null,
			]),
			runtimeScanStatus ? h('div', { className: 'uc-js-runtime-scan-status mt-1 mb-2 text-[11px] text-sky-300' }, runtimeScanStatus) : null,
			scan ? h('div', { className: 'mt-3 mb-2 text-xs bg-black/20 rounded-xl px-3 py-3', style: { padding: '5px' } }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-3 mb-2' }, [
					h('span', { className: 'text-zinc-300 font-bold' }, __("JS Delay / Defer Safety Scan", 'ultracache')),
					h('span', { className: 'text-zinc-500 font-mono break-all' }, [(scan.scanContext || runtimeScanContext) ? ('Context: ' + (String(scan.scanContext || runtimeScanContext) === 'logged-in' ? 'Logged-in/admin frontend' : 'Anonymous frontend') + ' · ') : '', (scan.scannedUrl || scan.profileUrl || scan.url) ? String(scan.scannedUrl || scan.profileUrl || scan.url) : '']),
				]),
				h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-2 mb-3' }, [
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Detected", 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(totalDetected || suggestions.length || 0))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Recommended", 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(appendableSuggestions.length))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Missing", 'ultracache')), h('div', { className: liveMissingCount ? 'font-mono text-amber-300' : 'font-mono text-emerald-300' }, String(liveMissingCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Already listed", 'ultracache')), h('div', { className: 'font-mono text-emerald-300' }, String(liveAlreadyListedCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Review-only", 'ultracache')), h('div', { className: missingReviewOnlySuggestions.length ? 'font-mono text-sky-300' : 'font-mono text-zinc-300' }, String(reviewOnlyCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Blocked resources", 'ultracache')), h('div', { className: resourceErrorCount ? 'font-mono text-sky-300' : 'font-mono text-zinc-300' }, String(resourceErrorCount || 0))]),
				]),
				renderResourceErrorsSection(resourceErrors),
				renderRuntimeErrorsSection(runtimeErrors),
				renderSuggestionSection('Missing recommended', liveMissingCount, missingAppendableSuggestions, 'No missing recommended exclusions. The visible JS Delay / Defer Exclusions list already covers the appendable scan results.', 'missing-recommended', 'These are the only lines Append Missing Recommended will add.'),
				renderSuggestionSection('Already listed recommended', liveAlreadyListedCount, alreadyListedAppendableSuggestions, 'No recommended exclusions are already listed yet.', 'already-listed-recommended', 'Grouped and collapsed by default. These scan matches are already covered by your textarea, including broad fragments that cover variant paths.', { grouped: true, collapsed: true }),
				renderSuggestionSection('Review-only detected', reviewOnlyCount, reviewOnlySuggestions, 'No review-only candidates were detected.', 'review-only-detected', 'Grouped and collapsed by default. Review-only items can be appended one by one after manual review.', { grouped: true, collapsed: true }),
			]) : h('div', { className: 'mt-2 mb-2 text-[11px] text-zinc-500', style: { padding: '5px' } }, __("Enter a same-site URL. JS Delay / Defer Scan reads final HTML; Runtime Scan opens the page in your browser, defaults to anonymous frontend mode, captures console/runtime errors, and turns them into suggested exclusions. Debug Profile loads the last WP-CLI/advanced profile only.", 'ultracache')),
		]);
	}


	function CssBundleExclusionsDiagnosticsField({ value, onSave, disabled, placeholder, onPopulateDefaults, onRunDiagnostics, onDownloadJson, onClearResult, profile, onCopyCssExclusion }) {
		const defaultScanUrl = (typeof ucwp !== "undefined" && ucwp && ucwp.frontendProbeUrl) ? String(ucwp.frontendProbeUrl || "") : "";
		const [draft, setDraft] = useState(value || '');
		const [scanUrl, setScanUrl] = useState(defaultScanUrl);
		const [populateBusy, setPopulateBusy] = useState(false);
		const [scanBusy, setScanBusy] = useState(false);
		const [sourceTopOpen, setSourceTopOpen] = useState(false);

		useEffect(() => {
			setDraft(value || '');
		}, [value]);

		const currentValue = String(value || '');
		const draftValue = String(draft || '');
		const hasChanges = draftValue !== currentValue;
		const current = profile && profile.available ? profile : null;
		const cssBundle = current && current.cssBundle ? current.cssBundle : {};
		const leftoverCssBundle = cssBundle && cssBundle.leftoverCssBundle ? cssBundle.leftoverCssBundle : {};
		const asyncCssDiagnostics = cssBundle && cssBundle.asyncCssDiagnostics ? cssBundle.asyncCssDiagnostics : {};
		const criticalChain = current && current.criticalRequestChain ? current.criticalRequestChain : {};
		const sourceTop = cssBundle && Array.isArray(cssBundle.sourceTop) ? cssBundle.sourceTop : [];
		const protectedStyles = criticalChain && Array.isArray(criticalChain.styleCandidates) ? criticalChain.styleCandidates.filter((item) => item && item.protected) : [];
		const renderBlockingHrefs = cssBundle && Array.isArray(cssBundle.renderBlockingHrefs) ? cssBundle.renderBlockingHrefs : [];

		async function handlePopulateDefaults() {
			if (disabled || populateBusy || typeof onPopulateDefaults !== 'function') {
				return;
			}
			setPopulateBusy(true);
			try {
				const next = await onPopulateDefaults(draftValue);
				if (typeof next === 'string') {
					setDraft(next);
				}
			} finally {
				setPopulateBusy(false);
			}
		}

		async function handleRunDiagnostics() {
			if (disabled || scanBusy || typeof onRunDiagnostics !== 'function') {
				return;
			}
			setScanBusy(true);
			try {
				await onRunDiagnostics(scanUrl || defaultScanUrl);
				setSourceTopOpen(true);
			} finally {
				setScanBusy(false);
			}
		}

		function appendCssExclusionLine(line) {
			const suggestion = String(line || '').trim();
			if (!suggestion) {
				return;
			}
			const merged = mergeUniqueSettingLines(draftValue, suggestion);
			setDraft(merged.value);
		}

		function isCssExclusionCovered(line) {
			const suggestion = String(line || '').trim().toLowerCase();
			if (!suggestion) {
				return true;
			}
			return normalizeSettingListLines(draftValue).map((item) => String(item || '').trim().toLowerCase()).some((existing) => existing === suggestion || existing.indexOf(suggestion) !== -1 || suggestion.indexOf(existing) !== -1);
		}

		function renderMetric(label, value, hint, tone) {
			const valueClass = tone === 'warning' ? 'text-amber-300' : (tone === 'success' ? 'text-emerald-300' : 'text-zinc-200');
			return h('div', { className: 'bg-black/20 rounded-xl px-3 py-3' }, [
				h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, label),
				h('div', { className: 'font-mono font-bold mt-1 ' + valueClass }, value),
				hint ? h('div', { className: 'text-zinc-500 mt-1' }, hint) : null,
			]);
		}

		function renderDiagnosticsResult() {
			if (!current) {
				return h('div', { className: 'mt-3 text-[11px] text-zinc-500 bg-black/15 rounded-xl px-3 py-3' }, __("No CSS diagnostics result loaded yet. Enter a same-site URL and click Run CSS Diagnostics.", 'ultracache'));
			}

			return h('div', { className: 'mt-4 text-xs bg-black/20 rounded-xl px-3 py-3 space-y-4' }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-3' }, [
					h('div', { className: 'text-zinc-300 font-bold' }, __("CSS Critical Path / Render Blocking Diagnostics", 'ultracache')),
					h('div', { className: 'text-zinc-500 font-mono break-all text-right' }, current.profileUrl || current.url || scanUrl || ''),
				]),
				h('div', { className: 'grid grid-cols-1 md:grid-cols-6 gap-2' }, [
					renderMetric('Main bundle', cssBundle.fileExists ? formatBytes(cssBundle.fileBytes || 0) : 'Not built', formatNumber(cssBundle.sourceUrlCount || 0) + ' source stylesheet(s)', (cssBundle.fileBytes || 0) > 153600 ? 'warning' : 'success'),
					renderMetric('Leftover bundle', leftoverCssBundle.enabled ? (leftoverCssBundle.success ? 'Active' : 'Skipped') : 'Disabled', leftoverCssBundle.enabled ? (formatNumber(leftoverCssBundle.replacedLinkCount || 0) + ' replaced · ' + formatBytes(leftoverCssBundle.bundleBytes || 0)) : 'Consolidate Remaining CSS is off', leftoverCssBundle.enabled && leftoverCssBundle.success ? 'success' : 'warning'),
					renderMetric('Async CSS', asyncCssDiagnostics.available ? formatNumber(asyncCssDiagnostics.rewritten || 0) + ' applied' : 'No scan', asyncCssDiagnostics.available ? (formatNumber(asyncCssDiagnostics.scanned || 0) + ' scanned · ' + formatNumber(asyncCssDiagnostics.skipped || 0) + ' skipped') : 'Run CSS Diagnostics', (asyncCssDiagnostics.rewritten || 0) > 0 ? 'success' : 'warning'),
					renderMetric('Final CSS links', formatNumber(cssBundle.stylesheetLinks || 0), formatNumber(cssBundle.renderBlockingBundleLinks || 0) + ' bundle · ' + formatNumber(cssBundle.renderBlockingNonBundleLinks || 0) + ' outside bundle', (cssBundle.stylesheetLinks || 0) > 8 ? 'warning' : 'success'),
					renderMetric('Render-blocking CSS', formatNumber(cssBundle.renderBlockingStylesheets || 0), 'Final render-blocking stylesheet link(s)', (cssBundle.renderBlockingStylesheets || 0) > 0 ? 'warning' : 'success'),
					renderMetric('Protected CSS', formatNumber(criticalChain.protectedStyleCount || protectedStyles.length || 0), 'Slider/hero/safety protected', 'neutral'),
				]),
				h('div', { className: 'text-[11px] text-zinc-400 leading-relaxed' }, [
					h('strong', { className: 'text-zinc-300' }, __("Recommendation: ", 'ultracache')),
					(leftoverCssBundle.enabled && leftoverCssBundle.success)
						? 'Leftover CSS consolidation is active. The remaining candidate is the main render-blocking CSS bundle: review critical CSS split or async non-critical bundle mode.'
						: 'Run/test Consolidate Remaining CSS first if visual output is safe, then review whether the main bundle needs a critical CSS split.',
				]),
				sourceTop.length ? h('details', { className: 'rounded-xl bg-black/15 px-3 py-2', open: sourceTopOpen }, [
					h('summary', { className: 'cursor-pointer text-zinc-300 font-semibold' }, __("Top CSS bundle sources by bytes", 'ultracache')),
					h('div', { className: 'mt-3 space-y-2' }, sourceTop.slice(0, 8).map((item, index) => {
						const suggestion = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
						return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'cssdiag-source-' + index }, [
							h('div', { className: 'flex items-center justify-between gap-4' }, [
								h('span', { className: 'break-all text-zinc-300' }, item.url || 'unknown stylesheet'),
								h('span', { className: (item.largeSourceWarning ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0') }, formatBytes(item.bytes || 0)),
							]),
							suggestion ? h('div', { className: 'mt-2 flex flex-wrap items-center gap-2' }, [
								h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1' }, suggestion),
								h('button', { type: 'button', className: 'uc-btn text-[11px] px-2 py-1', disabled: !!disabled || isCssExclusionCovered(suggestion), onClick: () => appendCssExclusionLine(suggestion) }, isCssExclusionCovered(suggestion) ? 'Already in exclusions' : 'Append exclusion line'),
							]) : null,
						]);
					})),
				]) : null,
				asyncCssDiagnostics.available ? h('details', { className: 'rounded-xl bg-black/15 px-3 py-2' }, [
					h('summary', { className: 'cursor-pointer text-zinc-300 font-semibold' }, __("Async Remaining CSS decisions", 'ultracache')),
					h('div', { className: 'mt-3 text-[11px] text-zinc-400 leading-relaxed' }, __("CSS Bundle Exclusions do not disable Async CSS. UltraCache-generated CSS is now classified before async: main/page/frontpage bundles and preserved optimized-css stay blocking because they can affect layout, while leftover and delayed-font CSS can load async when classified as non-critical. Aggressive Async CSS still uses the visible Async CSS Exclude List plus hard admin-only exclusions.", 'ultracache')),
					asyncCssDiagnostics.reasonCounts ? h('div', { className: 'mt-3 flex flex-wrap gap-2' }, Object.keys(asyncCssDiagnostics.reasonCounts).slice(0, 12).map((key) => h('span', { className: 'font-mono text-[11px] bg-black/25 rounded px-2 py-1', key: 'async-reason-' + key }, key + ': ' + formatNumber(asyncCssDiagnostics.reasonCounts[key] || 0)))) : null,
					Array.isArray(asyncCssDiagnostics.items) && asyncCssDiagnostics.items.length ? h('div', { className: 'mt-3 space-y-1' }, asyncCssDiagnostics.items.slice(0, 16).map((item, index) => h('div', { className: 'text-[11px] bg-black/20 rounded px-2 py-1', key: 'async-item-' + index }, [
						h('div', { className: item.status === 'applied' ? 'text-emerald-300 font-bold' : (item.status === 'unresolved' ? 'text-amber-300 font-bold' : 'text-zinc-300 font-bold') }, (item.status || 'unknown') + ' · ' + (item.reason || 'unknown')),
						item.detail ? h('div', { className: 'font-mono text-[10px] text-sky-300 mt-1' }, item.detail) : null,
						h('code', { className: 'block font-mono text-zinc-400 break-all mt-1' }, item.url || item.path || 'unknown stylesheet'),
					]))) : null,
				]) : null,				renderBlockingHrefs.length ? h('details', { className: 'rounded-xl bg-black/15 px-3 py-2' }, [
					h('summary', { className: 'cursor-pointer text-zinc-300 font-semibold' }, __("Remaining render-blocking stylesheet URLs", 'ultracache')),
					h('div', { className: 'mt-3 space-y-1' }, renderBlockingHrefs.slice(0, 12).map((url, index) => h('code', { className: 'block font-mono text-[11px] text-zinc-300 break-all bg-black/20 rounded px-2 py-1', key: 'cssdiag-rb-' + index }, url))),
				]) : null,
			]);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' } }, [
			h('label', { className: 'uc-field-label' }, __("CSS Bundle Exclusions", 'ultracache')),
			h('div', { className: 'text-xs text-zinc-500 mb-2' }, __("Optional newline-separated URL fragments. Matching stylesheets stay outside generated CSS bundles and load normally as their original stylesheet links. Use exclusions only when a stylesheet breaks inside the bundle or tested slower when bundled.", 'ultracache')),
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => setDraft(e.target.value),
			}),
			h('div', { className: 'mt-3 mb-2' }, [
				h('label', { className: 'uc-field-label' }, __("Page URL to diagnose", 'ultracache')),
				h('input', {
					type: 'url',
					className: 'uc-field-input',
					value: scanUrl,
					disabled: !!disabled || scanBusy,
					placeholder: defaultScanUrl || 'https://example.com/page/',
					onChange: (e) => setScanUrl(e.target.value),
				}),
				h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, __("Run a profile-bypass diagnostic for this same-site URL. Nothing is changed automatically.", 'ultracache')),
			]),
			h('div', { className: 'mt-3 mb-3 flex flex-wrap items-center gap-2', style: { justifyContent: 'space-evenly', padding: '5px 0' } }, [
				h(Button, { key: 'defaults', onClick: handlePopulateDefaults, disabled: !!disabled || populateBusy }, populateBusy ? 'Populating…' : 'Populate Defaults'),
				h(Button, { key: 'run-css', onClick: handleRunDiagnostics, disabled: !!disabled || scanBusy }, scanBusy ? 'Running…' : 'Run CSS Diagnostics'),
				h(Button, { key: 'download-css', onClick: onDownloadJson, disabled: !!disabled || !current }, __("Download CSS JSON", 'ultracache')),
				h(Button, { key: 'clear-css', onClick: onClearResult, disabled: !!disabled || !current }, __("Clear CSS Result", 'ultracache')),
				h(Button, { key: 'save-css', onClick: () => onSave(draftValue), disabled: !!disabled || !hasChanges, variant: 'primary' }, __("Save Exclusions", 'ultracache')),
			]),
			renderDiagnosticsResult(),
		]);
	}


	function NumberField({ label, description, value, onChange, disabled, min, step }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: 'number',
				className: 'uc-field-input',
				value: value,
				disabled: !!disabled,
				min: typeof min === 'number' ? min : 0,
				step: typeof step === 'number' ? step : 1,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}


	function NumberRow({ label, description, value, onChange, disabled, min, max, step, className }) {
		return h('div', { className: classNames('uc-number-row flex items-center justify-between gap-4 py-4', className || '') }, [
			h('div', { key: 'left', className: 'min-w-0 pr-4' }, [
				label ? h('div', { className: 'text-sm font-medium text-white' }, label) : null,
				description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
			]),
			h('input', {
				key: 'right',
				type: 'number',
				className: 'uc-field-input uc-number-row-input',
				value: value,
				disabled: !!disabled,
				min: typeof min === 'number' ? min : 0,
				max: typeof max === 'number' ? max : undefined,
				step: typeof step === 'number' ? step : 1,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}


	function TextRow({ label, description, value, onChange, disabled, placeholder, type, className, autoComplete, inputMode }) {
		return h('div', { className: classNames('uc-number-row flex items-center justify-between gap-4 py-4', className || '') }, [
			h('div', { key: 'left', className: 'min-w-0 pr-4' }, [
				label ? h('div', { className: 'text-sm font-medium text-white' }, label) : null,
				description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
			]),
			h('input', {
				key: 'right',
				type: type || 'text',
				className: 'uc-field-input uc-number-row-input',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				autoComplete: autoComplete || undefined,
				inputMode: inputMode || undefined,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function TextField({ label, description, value, onChange, disabled, placeholder, onKeyDown, type }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: type || 'text',
				className: 'uc-field-input',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
				onKeyDown: onKeyDown,
			}),
		]);
	}


	function SelectField({ label, description, value, onChange, disabled, options }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('div', { className: 'uc-select-wrap' }, [
				h('select', {
					className: 'uc-field-input uc-field-select',
					value: value || '',
					disabled: !!disabled,
					onChange: (e) => onChange(e.target.value),
				}, (options || []).map((option) => h('option', { value: option.value, key: option.value }, option.label))),
				h('span', { className: 'uc-select-icon', 'aria-hidden': 'true' }, '▾'),
			]),
		]);
	}

	function CustomSelect({ value, options, onChange, disabled, className }) {
		const [open, setOpen] = useState(false);
		const wrapRef = useRef(null);
		const selected = (options || []).find((option) => option.value === value) || (options || [])[0] || { value: '', label: '' };

		useEffect(() => {
			function handleOutsideClick(event) {
				if (!wrapRef.current || wrapRef.current.contains(event.target)) {
					return;
				}
				setOpen(false);
			}

			document.addEventListener('mousedown', handleOutsideClick);
			return () => document.removeEventListener('mousedown', handleOutsideClick);
		}, []);

		function selectOption(nextValue) {
			if (disabled) {
				return;
			}
			setOpen(false);
			if (nextValue !== value) {
				onChange(nextValue);
			}
		}

		return h('div', { ref: wrapRef, className: classNames('uc-custom-select', className || '', disabled ? 'opacity-60 pointer-events-none' : '') }, [
			h('button', {
				type: 'button',
				className: 'uc-field-input uc-custom-select-button',
				disabled: !!disabled,
				onClick: () => setOpen(!open),
			}, [
				h('span', { key: 'label' }, selected.label),
				h('span', { key: 'icon', className: 'uc-custom-select-icon', 'aria-hidden': 'true' }, '▾'),
			]),
			open ? h('div', { className: 'uc-custom-select-menu', role: 'listbox' }, (options || []).map((option) => h('button', {
				type: 'button',
				key: option.value,
				className: classNames('uc-custom-select-option', option.value === value ? 'is-selected' : ''),
				onClick: () => selectOption(option.value),
			}, option.label))) : null,
		]);
	}

	function MultiSelectField({ label, description, value, onChange, disabled, options }) {
		const selected = splitWarmSourceList(value);
		const availableOptions = Array.isArray(options) ? options : [];
		const selectedMap = {};
		selected.forEach((item) => { selectedMap[item] = true; });

		function toggleValue(nextValue, checked) {
			const cleanValue = String(nextValue || '').trim();
			if (!cleanValue || disabled) {
				return;
			}

			const nextSelected = selected.filter((item) => item !== cleanValue);
			if (checked) {
				nextSelected.push(cleanValue);
			}
			onChange(joinWarmSourceList(nextSelected));
		}

		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('details', { className: classNames('uc-switch-dropdown', disabled ? 'opacity-60 pointer-events-none' : '') }, [
				h('summary', { className: 'uc-switch-dropdown-summary' }, [
					h('span', { key: 'label' }, selected.length ? (selected.length + ' source' + (selected.length === 1 ? '' : 's') + ' selected') : 'Select full-site sources'),
					h('span', { key: 'icon', className: 'uc-select-icon', 'aria-hidden': 'true' }, '▾'),
				]),
				h('div', { className: 'uc-switch-dropdown-panel' }, availableOptions.length ? availableOptions.map((option) => {
					const optionValue = String(option.value || '');
					const checked = !!selectedMap[optionValue];
					return h('label', { className: 'uc-switch-dropdown-row', key: optionValue }, [
						h('span', { className: 'uc-switch-dropdown-text' }, option.label),
						h('span', { className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : '') }, [
							h('input', {
							type: 'checkbox',
							checked: checked,
							disabled: !!disabled,
							onChange: (event) => toggleValue(optionValue, event.target.checked),
						}),
							h('span', { className: 'slider' }),
						]),
					]);
				}) : h('div', { className: 'text-xs text-zinc-500 px-3 py-3' }, __("No sources detected.", 'ultracache'))),
			]),
			h('div', { className: 'text-[11px] text-zinc-500 mt-2' }, selected.length ? ('Selected: ' + selected.length) : 'No sources selected. Full-site warm buttons stay off.'),
		]);
	}

	function DetailRow({ label, value, mono }) {
		if (value === null || typeof value === 'undefined' || value === '') {
			return null;
		}

		return h('div', { className: 'flex flex-col gap-1 py-2' }, [
			h('div', { className: 'text-[11px] tracking-widest text-zinc-500' }, label),
			h('div', { className: classNames('text-sm text-white break-all', mono ? 'font-mono text-[12px]' : '') }, String(value)),
		]);
	}

	function AnalyticsSummaryCard({ stats, diagnostics }) {
		const reasons = stats && stats.topBypassReasons && typeof stats.topBypassReasons === 'object' ? stats.topBypassReasons : {};
		const bucketHits = stats && stats.pageCacheBucketHits && typeof stats.pageCacheBucketHits === 'object' ? stats.pageCacheBucketHits : {};
		const encodingHits = stats && stats.pageCacheEncodingHits && typeof stats.pageCacheEncodingHits === 'object' ? stats.pageCacheEncodingHits : {};
		const reasonEntries = Object.entries(reasons);
		const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : {};
		const proxyNotice = reverseProxy && reverseProxy.detected
			? (reverseProxy.message || 'External reverse proxy cache detected. UltraCache page-hit counters reflect only requests that reach PHP/advanced-cache.')
			: '';

		return h(Card, {
			title: __("Cache Analytics", 'ultracache'),
			description: __("Request counters for cache delivery and cache-generation activity. Warm/preload creates misses and writes, not hits.", 'ultracache'),
		}, [
			h('div', { className: 'grid grid-cols-2 xl:grid-cols-4 gap-3', key: 'metrics' }, [
				h(StatCard, { key: 'hits', label: __("Cache Hits", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheHits), hint: getStatsRefreshHint(stats, diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache') }),
				h(StatCard, { key: 'misses', label: __("Render Misses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheMisses), hint: getStatsRefreshHint(stats, 'Reached WordPress render path') }),
				h(StatCard, { key: 'bypasses', label: __("Bypasses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheBypasses), hint: getStatsRefreshHint(stats, 'Skipped before buffering') }),
				h(StatCard, { key: 'ratio', label: __("Hit Ratio", 'ultracache'), value: formatStatsPercent(stats, stats.pageCacheHitRatio), hint: getStatsRefreshHint(stats, diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'PHP-level ratio only; reverse proxy hits excluded' : 'Hits ÷ (hits + misses)') }),
			]),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-6 mt-5', key: 'detail-grid' }, [
				h('div', { key: 'buckets' }, [
					h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, __("Served cache buckets", 'ultracache')),
					h(DetailRow, { label: __("Original HTML", 'ultracache'), value: formatNumber(bucketHits.orig || 0) }),
					h(DetailRow, { label: __("WebP HTML", 'ultracache'), value: formatNumber(bucketHits.webp || 0) }),
					h(DetailRow, { label: __("AVIF HTML", 'ultracache'), value: formatNumber(bucketHits.avif || 0) }),
					h(DetailRow, { label: __("Identity encoding", 'ultracache'), value: formatNumber(encodingHits.identity || 0) }),
					h(DetailRow, { label: __("Gzip encoding", 'ultracache'), value: formatNumber(encodingHits.gzip || 0) }),
					h(DetailRow, { label: __("Brotli encoding", 'ultracache'), value: formatNumber(encodingHits.brotli || 0) }),
					h(DetailRow, { label: __("Cache writes", 'ultracache'), value: formatNumber(stats.pageCacheStores || 0) }),
					h(DetailRow, { label: __("Write skips", 'ultracache'), value: formatNumber(stats.pageCacheStoreSkips || 0) }),
					h(DetailRow, { label: __("Stale hits", 'ultracache'), value: formatNumber(stats.pageCacheStaleHits || 0) }),
					h(DetailRow, { label: __("Background refreshes", 'ultracache'), value: formatNumber(stats.pageCacheBackgroundRevalidations || 0) }),
				]),
				h('div', { key: 'reasons' }, [
					h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, __("Top bypass reasons", 'ultracache')),
					reasonEntries.length
						? h('div', { className: 'space-y-2' }, reasonEntries.map(([reason, count]) =>
							h('div', { className: 'flex items-center justify-between gap-4 py-2', key: reason }, [
								h('div', { className: 'text-sm text-white break-all' }, reason),
								h('div', { className: 'text-xs font-mono text-zinc-400' }, formatNumber(count)),
							])
						))
						: h('div', { className: 'text-xs text-zinc-500 pt-2' }, __("No bypasses recorded yet.", 'ultracache')),
				]),
			]),
		]);
	}

	function ActivitySummaryCard({ stats, cssBundleDiagnostics, open, onToggle }) {
		const lastPurge = stats && stats.lastPurge ? stats.lastPurge : {};
		const lastWarm = stats && stats.lastWarm ? stats.lastWarm : {};
		const counterRows = [
			['Warm successes', false, formatNumber(stats.warmSuccessCount || 0)],
			['Warm failures', false, formatNumber(stats.warmFailureCount || 0)],
			['Last warm files', false, typeof lastWarm.files !== 'undefined' ? formatNumber(lastWarm.files) : '0'],
			['Object cache hits', false, formatNumber(stats.objectCacheHits || 0)],
			['Object cache misses', false, formatNumber(stats.objectCacheMisses || 0)],
			['Object cache hit ratio', false, formatPercent(stats.objectCacheHitRatio || 0)],
		];
		const statusRows = [
			['Last warm result', false, typeof lastWarm.success === 'boolean' ? (lastWarm.success ? 'Success' : 'Failed') : '—'],
			['Last purge scope', false, lastPurge.scope || '—'],
		];
		const timelineRows = [
			['Last purge time', formatLooseTime(lastPurge)],
			['Last warm URL', lastWarm.url],
			['Last purge URL', lastPurge.url],
			['Last warm message', lastWarm.message],
			['Last warm time', formatLooseTime(lastWarm)],
		];

		const cssSummary = cssBundleDiagnostics || {};
		const cssFiles = cssSummary.files || {};
		const cssManifest = cssSummary.manifest || {};
		const cssSummaryRows = [
			['Bundles built', false, formatNumber(cssSummary.bundlesBuilt || 0)],
			['Styles bundled/scanned', false, formatNumber(cssSummary.stylesBundled || 0) + ' / ' + formatNumber(cssSummary.stylesScanned || 0)],
			['Skipped/unresolved', false, formatNumber(cssSummary.stylesSkipped || 0) + ' / ' + formatNumber(cssSummary.stylesUnresolved || 0)],
			['Last CSS bundle warm', false, formatLooseTime(cssSummary.lastWarm || null)],
			['Bundle files / delayed fonts', false, formatNumber(cssFiles.bundleFiles || 0) + ' / ' + formatNumber(cssFiles.delayedFontFiles || 0)],
			['Manifest entries / sources', false, formatNumber(cssManifest.entries || 0) + ' / ' + formatNumber(cssManifest.sourceUrls || 0)],
			['Missing main/delayed files', false, formatNumber(cssManifest.missingBundleFiles || 0) + ' / ' + formatNumber(cssManifest.missingDelayedFontFiles || 0)],
		];

		function renderSummaryRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: false, text: row[2], tone: 'neutral' }),
			])));
		}

		function renderStackRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.filter((row) => row[1] !== null && typeof row[1] !== 'undefined' && row[1] !== '').map((row) => h('div', { className: 'py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white mb-1' }, row[0]),
				h('div', { className: 'text-xs text-zinc-300 break-all whitespace-pre-wrap' }, String(row[1])),
			])));
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'activity-summary', open: !!open }, [
				h('summary', { className: 'uc-accordion__summary', onClick: function(event) { event.preventDefault(); if (onToggle) { onToggle(); } } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Activity Summary", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Recent operational events and warm/object-cache counters.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'activity-counters' }, [
				h('div', { className: 'uc-section-title' }, __("Activity counters", 'ultracache')),
				renderSummaryRows(counterRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-status' }, [
				h('div', { className: 'uc-section-title' }, __("Recent status", 'ultracache')),
				renderSummaryRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-css-bundle-summary' }, [
				h('div', { className: 'uc-section-title' }, __("CSS Bundle Summary", 'ultracache')),
				renderSummaryRows(cssSummaryRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-timeline' }, [
				h('div', { className: 'uc-section-title' }, __("Recent activity", 'ultracache')),
				renderStackRows(timelineRows),
			]),
				]),
			]),
		]);
	}

	function loadSavedJob() {
		try {
			const raw = window.localStorage.getItem(JOB_STORAGE_KEY);
			return raw ? JSON.parse(raw) : null;
		} catch (error) {
			return null;
		}
	}

	function saveJob(job) {
		try {
			window.localStorage.setItem(JOB_STORAGE_KEY, JSON.stringify(job));
		} catch (error) {}
	}

	function clearSavedJob() {
		try {
			window.localStorage.removeItem(JOB_STORAGE_KEY);
		} catch (error) {}
	}

	function StatusPill({ ok, text, tone }) {
		const variant = tone || (ok ? 'success' : 'neutral');
		const toneClass = 'success' === variant
			? 'text-emerald-400'
			: ('warning' === variant ? 'text-cyan-400' : 'text-zinc-300');
		if ('plain' === variant) {
			return h('span', { className: 'inline-flex items-center justify-end text-right text-xs font-normal tracking-normal text-zinc-400 break-words max-w-xl' }, text);
		}
		return h('span', {
			className: classNames('inline-flex items-center justify-end text-right text-xs font-normal tracking-normal break-words max-w-xl', toneClass),
		}, text);
	}


		function DiagnosticsCard({ diagnostics, stats, open, onToggle, onRefreshStorageDiagnostics, busy }) {
		const compressionStatus = diagnostics.compression || {};
		const pathDiagnostics = diagnostics.paths || {};
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const environmentDiag = diagnostics.environment || {};
		const mediaRuntimeDiag = diagnostics.mediaRuntime || {};
		const analyticsBackend = diagnostics.analytics || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const analyticsProbeText = analyticsBackend && analyticsBackend.enabled ? ((analyticsBackend.readWrite === false) ? ' · probe failed' : (analyticsBackend.readWrite ? ' · probe passed' : '')) : '';
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const cacheStorageDiag = diagnostics.cacheStorage || {};
		const storageTotal = cacheStorageDiag.total || {};
		const storagePageCache = cacheStorageDiag.pageCache || {};
		const storageCssBundles = cacheStorageDiag.cssBundles || {};
		const storageObjectDisk = cacheStorageDiag.objectCacheDisk || {};
		const storageMediaCache = cacheStorageDiag.mediaCache || {};
		const storageWarnings = Array.isArray(cacheStorageDiag.warnings) ? cacheStorageDiag.warnings : [];
		const storageWarningLevel = cacheStorageDiag.warningLevel || 'ok';
		const avifDirDiag = pathDiagnostics.avifDir || {};
		const webpDirDiag = pathDiagnostics.webpDir || {};
		const browserCacheRulesDiag = pathDiagnostics.browserCacheRules || {};
		const proxyProviderText = reverseProxy && reverseProxy.provider ? reverseProxy.provider : (reverseProxy && reverseProxy.detected ? 'Detected' : 'Not detected');
		const reverseProxyText = reverseProxy.detected
			? (proxyProviderText + (reverseProxy.x_cache ? ' · ' + reverseProxy.x_cache : '') + (reverseProxy.x_litespeed_cache ? ' · ' + reverseProxy.x_litespeed_cache : '') + (reverseProxy.x_cache_status ? ' · ' + reverseProxy.x_cache_status : ''))
			: 'Not detected';
		const loadedRuntime = runtimeConfigDiag.loaded || {};
		const allowlist = Array.isArray(loadedRuntime.cache_query_allowlist) ? loadedRuntime.cache_query_allowlist : [];
		const queryStringCachingText = loadedRuntime.cache_query_strings
			? ('Enabled' + (allowlist.length ? ' - Whitelist: ' + allowlist.join(', ') : ' - Whitelist empty: caching all non-excluded query variants'))
			: 'Disabled';
		const safeTrackingCookieCacheText = loadedRuntime.cache_safe_tracking_cookies === false
			? 'Strict mode: any Set-Cookie skips cache'
			: 'Enabled: safe tracking Set-Cookie can store public HTML';
		const objectCacheStatus = diagnostics.objectCache || {};
		const pageCacheStatus = diagnostics.pageCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || (objectCacheStatus.fallbackActive ? activeObjectBackend : 'apcu');
		const objectRuntimeOnly = activeObjectBackend === 'runtime' || !!objectCacheStatus.activeBackendRuntimeOnly;
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend && activeObjectBackend && selectedObjectBackend !== activeObjectBackend);
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = objectRuntimeOnly ? 'Runtime only' : (activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable');
		const fallbackObjectBackendText = fallbackObjectBackend ? (String(fallbackObjectBackend).toLowerCase() === 'runtime' ? 'Runtime only' : String(fallbackObjectBackend).toUpperCase()) : 'Unavailable';
		const objectCacheActiveTone = !objectCacheStatus.enabled ? 'neutral' : (!objectCacheStatus.active ? 'warning' : (objectFallbackActive || objectRuntimeOnly ? 'warning' : 'success'));
		const objectCacheFallbackTone = objectFallbackActive ? 'warning' : 'neutral';

		function describeDropIn(diag) {
			if (!diag || !diag.exists) {
				return 'Missing';
			}
			const parts = ['Present', diag.managed ? 'Managed by UltraCache' : 'Not managed'];
			if (diag.dropInBuild) {
				parts.push('Build ' + diag.dropInBuild);
			}
			return parts.join(' · ');
		}

		const statusRows = [
			['Page cache switch', !!pageCacheStatus.enabled, pageCacheStatus.enabled ? 'Enabled' : 'Disabled'],
			['Page cache drop-in active', !!pageCacheStatus.active, pageCacheStatus.active ? 'Active' : (pageCacheStatus.enabled ? 'Configured · drop-in inactive' : 'Disabled')],
			['Object cache switch', !!objectCacheStatus.enabled, objectCacheStatus.enabled ? 'Enabled' : 'Disabled'],
			['Selected object cache backend', !!objectCacheStatus.enabled, objectCacheStatus.enabled ? selectedObjectBackendText : 'Disabled'],
			['Active object cache backend', !!objectCacheStatus.active, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled'), objectCacheActiveTone],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby'), objectCacheFallbackTone],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : ('Disabled' + (analyticsBackend.message ? ' · ' + analyticsBackend.message : ''))],
			['Cron Warm Up', diagnostics.cronWarm && diagnostics.cronWarm.active, diagnostics.cronWarm && diagnostics.cronWarm.active ? ('Running · ' + formatNumber(diagnostics.cronWarm.processed || 0) + '/' + formatNumber(diagnostics.cronWarm.total || 0)) : diagnostics.cronWarm && diagnostics.cronWarm.enabled ? ((diagnostics.cronWarm.completed ? 'Completed' : 'Enabled') + ' · ' + formatNumber(diagnostics.cronWarm.pagesPerMinute || 0) + '/min') : 'Disabled'],
			['Varnish', diagnostics.varnish && diagnostics.varnish.enabled, diagnostics.varnish && diagnostics.varnish.enabled ? ('Varnish mode: ' + ((diagnostics.varnish.configuredMode || diagnostics.varnish.mode || 'http') === 'admin' ? 'admin-secret' : 'HTTP endpoint') + ' · ' + (diagnostics.varnish.effectiveMethod || (((diagnostics.varnish.mode || 'http') === 'admin') ? 'admin BAN' : (diagnostics.varnish.method || 'BAN'))) + ' · ' + ((diagnostics.varnish.endpointCount || 0) ? (diagnostics.varnish.endpointCount + ' endpoint(s)') : ((diagnostics.varnish.servers || '').trim() || 'No endpoints'))) : 'Disabled'],
			['Reverse Proxy', !!reverseProxy.detected, reverseProxyText],
			['Brotli Compression', compressionStatus.brotli && compressionStatus.brotli.available, compressionStatus.brotli && compressionStatus.brotli.available ? (compressionStatus.preferred === 'brotli' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['Gzip Compression', compressionStatus.gzip && compressionStatus.gzip.available, compressionStatus.gzip && compressionStatus.gzip.available ? (compressionStatus.preferred === 'gzip' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['AVIF Capability', diagnostics.formats && diagnostics.formats.avif, diagnostics.formats && diagnostics.formats.avif ? 'Available' : 'Unavailable'],
			['WebP Capability', diagnostics.formats && diagnostics.formats.webp, diagnostics.formats && diagnostics.formats.webp ? 'Available' : 'Unavailable'],
			['Query-string args caching', !!loadedRuntime.cache_query_strings, queryStringCachingText],
			['Safe tracking cookie cache', loadedRuntime.cache_safe_tracking_cookies !== false, safeTrackingCookieCacheText],
		];
		const runtimeRows = [
			['Cache directory', !!cacheDirDiag.exists, cacheDirDiag.exists ? (cacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['Advanced cache drop-in', !!advancedCacheDiag.exists, describeDropIn(advancedCacheDiag)],
			['Advanced-cache generated version', !!advancedCacheDiag.dropInBuild, advancedCacheDiag.dropInBuild ? ('Build ' + advancedCacheDiag.dropInBuild) : 'Unavailable'],
			['Object cache drop-in', !!objectCacheDiag.exists, describeDropIn(objectCacheDiag)],
			['Object-cache generated version', !!objectCacheDiag.dropInBuild, objectCacheDiag.dropInBuild ? ('Build ' + objectCacheDiag.dropInBuild) : 'Unavailable'],
			['Object-cache storage format', !!objectCacheDiag.storageFormat, objectCacheDiag.storageFormat || 'Unavailable'],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : ('Disabled' + (analyticsBackend.message ? ' · ' + analyticsBackend.message : ''))],
			['Runtime config', !!runtimeConfigDiag.exists && !!runtimeConfigDiag.valid, runtimeConfigDiag.exists ? (runtimeConfigDiag.valid ? 'Present · Valid' : 'Present · Invalid') : 'Missing'],
			['Analytics storage', !!analyticsDiag.exists && !!analyticsDiag.valid, analyticsDiag.exists ? ('DB table · ' + formatNumber(analyticsDiag.rows || 0) + ' rows') : 'Missing DB table'],
			['Browser cache rules', !!browserCacheRulesDiag.exists && !!browserCacheRulesDiag.managed, browserCacheRulesDiag.exists ? (browserCacheRulesDiag.managed ? 'Present · Managed block found' : 'Present · No UltraCache block') : 'Missing'],
			['Object cache directory', !!objectCacheDirDiag.exists, objectCacheDirDiag.exists ? (objectCacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['AVIF optimized media directory', !!avifDirDiag.exists, avifDirDiag.exists ? (avifDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['WebP optimized media directory', !!webpDirDiag.exists, webpDirDiag.exists ? (webpDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
		];
		const storageRows = [
			['Total UltraCache storage', storageWarningLevel === 'ok', formatBytes(storageTotal.bytes || 0) + ' · ' + formatFileCount(storageTotal.files || 0, !!storageTotal.truncated) + (storageTotal.truncated ? ' · capped scan' : '')],
			['HTML/page cache', false, formatBytes(storagePageCache.bytes || 0) + ' · ' + formatFileCount(storagePageCache.files || 0, !!storagePageCache.truncated) + (storagePageCache.truncated ? ' · capped scan' : '')],
			['CSS bundle storage', storageCssBundles.warningLevel === 'ok', formatBytes(storageCssBundles.bytes || 0) + ' · ' + formatFileCount(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || storageCssBundles.files || 0, false)],
			['CSS recent / old files', false, formatNumber(storageCssBundles.recentFiles || 0) + ' / ' + formatNumber(storageCssBundles.oldFiles || 0)],
			['CSS orphan-like files', storageCssBundles.oldOrphanLikeFiles === 0, formatNumber(storageCssBundles.orphanLikeFiles || 0) + ' total · ' + formatNumber(storageCssBundles.oldOrphanLikeFiles || 0) + ' eligible · ' + formatNumber(storageCssBundles.recentOrphanLikeFiles || 0) + ' protected by grace · ' + formatNumber(storageCssBundles.protectedByCachedHtmlRefs || 0) + ' protected by cached HTML'],
			['Cleanup grace / delete limit', false, getCssCleanupGraceLabel(storageCssBundles) + ' · ' + getCssCleanupDeleteLimitLabel(storageCssBundles)],
			['Generated AVIF/WebP storage', false, formatBytes(storageMediaCache.bytes || 0) + ' · ' + formatFileCount(storageMediaCache.files || 0, !!storageMediaCache.truncated) + ' · AVIF ' + formatCount(storageMediaCache.avifFiles || 0, !!storageMediaCache.avifTruncated) + ' / WebP ' + formatCount(storageMediaCache.webpFiles || 0, !!storageMediaCache.webpTruncated)],
		];

		const runtimeConfigRows = runtimeConfigDiag.valid ? [
			['Fresh TTL', false, formatNumber(loadedRuntime.cache_fresh_ttl_minutes || 0) + ' min'],
			['Max stale window', false, formatNumber(loadedRuntime.cache_max_stale_minutes || 0) + ' min'],
			['Stale-while-revalidate', !!loadedRuntime.stale_while_revalidate_enabled, loadedRuntime.stale_while_revalidate_enabled ? 'Enabled' : 'Disabled'],
			['Query allowlist', false, allowlist.length ? (formatNumber(allowlist.length) + ' key' + (allowlist.length === 1 ? '' : 's') + ' · ' + allowlist.join(', ')) : 'None'],
			['Woo safe mode', !!loadedRuntime.woo_safe_mode, loadedRuntime.woo_safe_mode ? 'Enabled' : 'Disabled'],
			['Excluded paths', false, formatNumber((loadedRuntime.excluded_paths || []).length) + ' path' + (((loadedRuntime.excluded_paths || []).length === 1) ? '' : 's')],
			['Excluded query-string args', false, formatNumber((loadedRuntime.excluded_query_args || []).length) + ' arg' + (((loadedRuntime.excluded_query_args || []).length === 1) ? '' : 's')],
			['Sync state', !!runtimeConfigDiag.inSync, runtimeConfigDiag.inSync ? 'In sync with dashboard settings' : 'Out of sync with dashboard settings'],
		] : [];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: row[3] || tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'diagnostics', open: !!open }, [
				h('summary', { className: 'uc-accordion__summary', onClick: function(event) { event.preventDefault(); if (onToggle) { onToggle(); } } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Diagnostics", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Live cache status", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'status-group' }, [
				h('div', { className: 'uc-section-title' }, __("Runtime status", 'ultracache')),
				renderRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'runtime-group' }, [
				h('div', { className: 'uc-section-title' }, __("Runtime files, drop-ins & versions", 'ultracache')),
				renderRows(runtimeRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'cache-storage-summary-group' }, [
				h('div', { className: 'uc-section-title' }, __("Cache storage diagnostics", 'ultracache')),
				renderRows(storageRows, 'plain'),
				h('button', {
					className: 'uc-btn mt-3 text-white py-2 px-3 font-bold',
					onClick: onRefreshStorageDiagnostics,
					disabled: busy || !onRefreshStorageDiagnostics,
				}, busy ? 'Engine Busy' : 'Refresh storage diagnostics'),
				cacheStorageDiag.message ? h('div', { className: 'mt-2 text-xs text-zinc-500' }, cacheStorageDiag.message) : null,
				storageWarnings.length ? h('div', { className: 'mt-3 text-xs text-cyan-300 space-y-1' }, storageWarnings.map(function(message, index) {
					return h('div', { key: 'diagnostics-storage-warning-' + index }, message);
				})) : null,
			]),
			runtimeConfigRows.length ? h('div', { className: 'uc-diagnostic-group', key: 'runtime-config-group' }, [
				h('div', { className: 'uc-section-title' }, __("Runtime config in use", 'ultracache')),
				renderRows(runtimeConfigRows),
			]) : null,
				]),
			]),
		]);
	}

	function AdvancedDiagnosticsCard({ diagnostics, stats, onRefreshStorageDiagnostics, busy }) {
		const last = diagnostics.lastEvent || {};
		const objectCacheStatus = diagnostics.objectCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || (objectCacheStatus.fallbackActive ? activeObjectBackend : 'apcu');
		const objectRuntimeOnly = activeObjectBackend === 'runtime' || !!objectCacheStatus.activeBackendRuntimeOnly;
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend && activeObjectBackend && selectedObjectBackend !== activeObjectBackend);
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = objectRuntimeOnly ? 'Runtime only' : (activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable');
		const fallbackObjectBackendText = fallbackObjectBackend ? (String(fallbackObjectBackend).toLowerCase() === 'runtime' ? 'Runtime only' : String(fallbackObjectBackend).toUpperCase()) : 'Unavailable';
		const objectCacheActiveTone = !objectCacheStatus.enabled ? 'neutral' : (!objectCacheStatus.active ? 'warning' : (objectFallbackActive || objectRuntimeOnly ? 'warning' : 'success'));
		const objectCacheFallbackTone = objectFallbackActive ? 'warning' : 'neutral';
		const compressionStatus = diagnostics.compression || {};
		const wpCacheStatus = diagnostics.wpCache || {};
		const pathDiagnostics = diagnostics.paths || {};
		const lastCacheWrite = diagnostics.lastCacheWrite || {};
		const lastStatus = last.status || last.type || '—';
		const lastSeen = formatEventTime(last);
		const statsDisabled = !!(stats && stats.disabled);
		const statsCountText = (value) => statsDisabled ? 'Stats disabled' : formatNumber(value || 0);
		const bucketHits = stats && stats.pageCacheBucketHits && typeof stats.pageCacheBucketHits === 'object' ? stats.pageCacheBucketHits : {};
		const encodingHits = stats && stats.pageCacheEncodingHits && typeof stats.pageCacheEncodingHits === 'object' ? stats.pageCacheEncodingHits : {};
		const reasons = stats && stats.topBypassReasons && typeof stats.topBypassReasons === 'object' ? stats.topBypassReasons : {};
		const reasonEntries = Object.entries(reasons).slice(0, 8);
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const environmentDiag = diagnostics.environment || {};
		const mediaRuntimeDiag = diagnostics.mediaRuntime || {};
		const analyticsBackend = diagnostics.analytics || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const analyticsProbeText = analyticsBackend && analyticsBackend.enabled ? ((analyticsBackend.readWrite === false) ? ' · probe failed' : (analyticsBackend.readWrite ? ' · probe passed' : '')) : '';
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const cacheStorageDiag = diagnostics.cacheStorage || {};
		const storageTotal = cacheStorageDiag.total || {};
		const storagePageCache = cacheStorageDiag.pageCache || {};
		const storageCssBundles = cacheStorageDiag.cssBundles || {};
		const storageObjectDisk = cacheStorageDiag.objectCacheDisk || {};
		const storageMediaCache = cacheStorageDiag.mediaCache || {};
		const storageWarnings = Array.isArray(cacheStorageDiag.warnings) ? cacheStorageDiag.warnings : [];
		const storageWarningLevel = cacheStorageDiag.warningLevel || 'ok';
		const cacheDetailRows = [
			['Original HTML', false, statsCountText(bucketHits.orig)],
			['WebP HTML', false, statsCountText(bucketHits.webp)],
			['AVIF HTML', false, statsCountText(bucketHits.avif)],
			['Identity encoding', false, statsCountText(encodingHits.identity)],
			['Gzip encoding', false, statsCountText(encodingHits.gzip)],
			['Brotli encoding', false, statsCountText(encodingHits.brotli)],
			['Cache writes', false, statsCountText(stats && stats.pageCacheStores)],
			['Write skips', false, statsCountText(stats && stats.pageCacheStoreSkips)],
			['Stale hits', false, statsCountText(stats && stats.pageCacheStaleHits)],
			['Background refreshes', false, statsCountText(stats && stats.pageCacheBackgroundRevalidations)],
			['Selected object cache backend', false, selectedObjectBackendText],
			['Active object cache backend', !!objectCacheStatus.active, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled'), objectCacheActiveTone],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby'), objectCacheFallbackTone],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : 'Disabled'],
			['CLS images scanned', false, statsCountText(stats && stats.clsImagesScanned)],
			['CLS dimensions injected', false, statsCountText(stats && stats.clsDimensionsInjected)],
			['CLS skipped', false, statsCountText(stats && stats.clsImagesSkipped)],
			['CLS unresolved', false, statsCountText(stats && stats.clsImagesUnresolved)],
		];

		const environmentRows = [
			['Server Hostname', false, environmentDiag.serverHostname || 'Unavailable'],
			['Origin IP:Port', false, environmentDiag.originIpPort || 'Unavailable'],
			['Server Document Root', false, environmentDiag.serverDocumentRoot || 'Unavailable'],
			['PHP Version', false, environmentDiag.phpVersion || 'Unavailable'],
			['PHP SAPI', false, environmentDiag.phpSapi || 'Unavailable'],
			['PHP Max Script Execute Time', false, environmentDiag.phpMaxExecutionTime ? (String(environmentDiag.phpMaxExecutionTime) + 's') : 'Unavailable'],
			['PHP Memory Limit', false, environmentDiag.phpMemoryLimit || 'Unavailable'],
			['PHP Max Upload Size', false, environmentDiag.phpMaxUploadSize || 'Unavailable'],
			['Max Post Size', false, environmentDiag.phpMaxPostSize || 'Unavailable'],
			['Max Input Vars', false, environmentDiag.phpMaxInputVars || 'Unavailable'],
			['WordPress Memory Limit', false, environmentDiag.wpMemoryLimit || 'Unavailable'],
			['MYSQL Query Cache Size', false, environmentDiag.mysqlQueryCacheSize || 'Unavailable'],
			['MYSQL Maximum Packet Size', false, environmentDiag.mysqlMaxAllowedPacket || 'Unavailable'],
		];

		const storageRows = [
			['Total UltraCache storage', storageWarningLevel === 'ok', formatBytes(storageTotal.bytes || 0) + ' · ' + formatFileCount(storageTotal.files || 0, !!storageTotal.truncated) + (storageTotal.truncated ? ' · capped scan' : '')],
			['HTML/page cache', false, formatBytes(storagePageCache.bytes || 0) + ' · ' + formatFileCount(storagePageCache.files || 0, !!storagePageCache.truncated) + (storagePageCache.truncated ? ' · capped scan' : '')],
			['CSS bundle storage', storageCssBundles.warningLevel === 'ok', formatBytes(storageCssBundles.bytes || 0) + ' · ' + formatFileCount(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || storageCssBundles.files || 0, false)],
			['Disk object cache storage', false, storageObjectDisk.exists ? (formatBytes(storageObjectDisk.bytes || 0) + ' · ' + formatFileCount(storageObjectDisk.files || 0, !!storageObjectDisk.truncated) + (storageObjectDisk.truncated ? ' · capped scan' : '')) : 'Directory not present'],
			['Generated AVIF/WebP storage', false, formatBytes(storageMediaCache.bytes || 0) + ' · ' + formatFileCount(storageMediaCache.files || 0, !!storageMediaCache.truncated) + ' · AVIF ' + formatCount(storageMediaCache.avifFiles || 0, !!storageMediaCache.avifTruncated) + ' / WebP ' + formatCount(storageMediaCache.webpFiles || 0, !!storageMediaCache.webpTruncated)],
		];

		const cssStorageRows = [
			['Recognized / directory CSS files', false, formatNumber(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || 0) + ' / ' + formatNumber(storageCssBundles.allDirectoryFiles || storageCssBundles.totalFiles || 0)],
			['Main / delayed CSS files', false, formatNumber(storageCssBundles.mainBundleFiles || 0) + ' / ' + formatNumber(storageCssBundles.delayedFontFiles || 0)],
			['Safe / leftover / aggressive / full', false, formatNumber(storageCssBundles.safeFiles || 0) + ' / ' + formatNumber(storageCssBundles.leftoverFiles || 0) + ' / ' + formatNumber(storageCssBundles.aggressiveFiles || 0) + ' / ' + formatNumber(storageCssBundles.fullFiles || 0)],
			['Recent / old CSS files', false, formatNumber(storageCssBundles.recentFiles || 0) + ' / ' + formatNumber(storageCssBundles.oldFiles || 0)],
			['Manifest-active CSS files', false, formatNumber(storageCssBundles.activeManifestFiles || 0)],
			['Orphan-like CSS files', storageCssBundles.oldOrphanLikeFiles === 0, formatNumber(storageCssBundles.orphanLikeFiles || 0) + ' total · ' + formatNumber(storageCssBundles.oldOrphanLikeFiles || 0) + ' eligible · ' + formatNumber(storageCssBundles.recentOrphanLikeFiles || 0) + ' protected by grace · ' + formatNumber(storageCssBundles.protectedByCachedHtmlRefs || 0) + ' protected by cached HTML'],
			['Cached HTML CSS refs', false, formatNumber(storageCssBundles.cachedHtmlRefFiles || 0) + ' refs · ' + formatNumber(storageCssBundles.cachedHtmlRefFilesWithRefs || 0) + '/' + formatNumber(storageCssBundles.cachedHtmlRefFilesScanned || 0) + ' HTML files' + (storageCssBundles.cachedHtmlRefScanTimedOut ? ' · time-capped scan' : (storageCssBundles.cachedHtmlRefScanTruncated ? ' · capped scan' : ''))],
			['Cleanup grace / delete limit', false, getCssCleanupGraceLabel(storageCssBundles) + ' · ' + getCssCleanupDeleteLimitLabel(storageCssBundles)],
			['Cleanup policy source', false, (storageCssBundles.cleanupPolicySource || 'dashboard/filter') + ' · ' + (storageCssBundles.cleanupGraceFilter || 'ucwp_css_bundle_cleanup_grace_seconds') + ' · ' + (storageCssBundles.cleanupDeleteLimitFilter || 'ucwp_css_bundle_cleanup_max_deletes_per_run')],
			['Cleanup bounds', false, 'Grace ' + (storageCssBundles.cleanupGraceMinLabel || formatDurationSeconds(storageCssBundles.cleanupGraceMinSeconds || 3600)) + '–' + (storageCssBundles.cleanupGraceMaxLabel || formatDurationSeconds(storageCssBundles.cleanupGraceMaxSeconds || 604800)) + ' · delete limit ' + formatNumber(storageCssBundles.cleanupDeleteLimitMin || 5) + '–' + formatNumber(storageCssBundles.cleanupDeleteLimitMax || 500)],
		];

		const mediaQueueDiag = mediaRuntimeDiag.queue || {};
		const mediaRuntimeRows = [
			['Preferred image editor', false, mediaRuntimeDiag.preferredEditor || 'Unavailable'],
			['Last image editor class', false, mediaRuntimeDiag.lastImageEditorClass || 'Unavailable'],
			['Imagick AVIF', !!mediaRuntimeDiag.imagickAvif, mediaRuntimeDiag.imagickAvif ? 'Yes' : 'No'],
			['Imagick WebP', !!mediaRuntimeDiag.imagickWebp, mediaRuntimeDiag.imagickWebp ? 'Yes' : 'No'],
			['GD AVIF', !!mediaRuntimeDiag.gdAvif, mediaRuntimeDiag.gdAvif ? 'Yes' : 'No'],
			['GD WebP', !!mediaRuntimeDiag.gdWebp, mediaRuntimeDiag.gdWebp ? 'Yes' : 'No'],
			['Media conversion queue', !!mediaQueueDiag.enabled, mediaQueueDiag.enabled ? (formatNumber(mediaQueueDiag.pending || 0) + ' pending · ' + formatNumber(mediaQueueDiag.done || 0) + ' done · ' + formatNumber(mediaQueueDiag.failed || 0) + ' failed · ' + formatNumber(mediaQueueDiag.alreadyOptimized || mediaQueueDiag.skipped || 0) + ' already optimized' + (mediaQueueDiag.needsRepair ? ' · repair needed' : '')) : 'Unavailable'],
			['Last AVIF encode engine', false, mediaRuntimeDiag.lastAvifEncodeEngine || 'Unavailable'],
			['Last AVIF encode error', false, mediaRuntimeDiag.lastAvifEncodeError || 'None'],
			['Last AVIF encode file', false, mediaRuntimeDiag.lastAvifEncodeFile || 'Unavailable'],
			['Last AVIF encode at', false, mediaRuntimeDiag.lastAvifEncodeAt ? formatLooseTime(mediaRuntimeDiag.lastAvifEncodeAt) : 'Unavailable'],
		];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: row[3] || tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		function renderPathDetails(label, diag, extraRows) {
			if (!diag || !diag.path) {
				return null;
			}
			return h('div', { className: 'rounded bg-black/10 p-4', key: 'path-' + label }, [
				h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, label),
				h('div', { className: 'space-y-3' }, [
					h(DetailRow, { label: __("Path", 'ultracache'), value: diag.path, mono: true }),
					h(DetailRow, { label: __("Exists", 'ultracache'), value: diag.exists ? 'Yes' : 'No' }),
					h(DetailRow, { label: __("Readable", 'ultracache'), value: diag.readable ? 'Yes' : 'No' }),
					h(DetailRow, { label: __("Writable", 'ultracache'), value: diag.writable ? 'Yes' : 'No' }),
					!diag.exists ? h(DetailRow, { label: __("Parent writable", 'ultracache'), value: diag.parentWritable ? 'Yes' : 'No' }) : null,
					diag.managed ? h(DetailRow, { label: __("Managed by UltraCache", 'ultracache'), value: 'Yes' }) : null,
					diag.dropInBuild ? h(DetailRow, { label: __("Generated build", 'ultracache'), value: diag.dropInBuild }) : null,
					diag.storageFormat ? h(DetailRow, { label: __("Storage format", 'ultracache'), value: diag.storageFormat }) : null,
					diag.size ? h(DetailRow, { label: __("Size", 'ultracache'), value: formatBytes(diag.size) }) : null,
					diag.modified ? h(DetailRow, { label: __("Modified", 'ultracache'), value: formatLooseTime(diag.modified) }) : null,
					diag.valid ? h(DetailRow, { label: __("Valid", 'ultracache'), value: 'Yes' }) : null,
					diag.validJson ? h(DetailRow, { label: __("Valid JSON", 'ultracache'), value: 'Yes' }) : null,
					diag.readError ? h(DetailRow, { label: __("Read error", 'ultracache'), value: diag.readError }) : null,
					extraRows || null,
				]),
			]);
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'advanced-diagnostics' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Advanced Diagnostics", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Expanded server, PHP, media, proxy, cache-write, and bypass inspection.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h(SettingsTransparencyPanel, { diagnostics: diagnostics, key: 'advanced-diagnostics-settings-transparency' }),
					h(SecurityCorrectnessPanel, { diagnostics: diagnostics, key: 'advanced-diagnostics-security-correctness' }),
					h('div', { className: 'uc-diagnostic-group', key: 'last-cache-write' }, [
						h('div', { className: 'uc-section-title' }, __("Last page cache write", 'ultracache')),
						(lastCacheWrite && (lastCacheWrite.path || lastCacheWrite.pageFiles)) ? h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: __("Page cache files", 'ultracache'), value: formatNumber(lastCacheWrite.pageFiles || 0) }),
							h(DetailRow, { label: __("Last modified", 'ultracache'), value: formatLooseTime(lastCacheWrite.modified || 0) }),
							h(DetailRow, { label: __("Last file size", 'ultracache'), value: formatBytes(lastCacheWrite.size || 0) }),
							h(DetailRow, { label: __("Last file path", 'ultracache'), value: lastCacheWrite.path || '', mono: true }),
							lastCacheWrite.error ? h(DetailRow, { label: __("Scan error", 'ultracache'), value: lastCacheWrite.error }) : null,
						]) : h('div', { className: 'text-xs text-zinc-500 pt-2' }, __("No page cache files have been detected yet.", 'ultracache')),
					]),
					(runtimeConfigDiag.path || analyticsDiag.path || advancedCacheDiag.path || objectCacheDiag.path || cacheDirDiag.path || objectCacheDirDiag.path)
						? h('div', { className: 'uc-diagnostic-group', key: 'path-grid' }, [
							h('div', { className: 'uc-section-title' }, __("Path details", 'ultracache')),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
								renderPathDetails('advanced-cache.php', advancedCacheDiag),
								renderPathDetails('object-cache.php', objectCacheDiag),
								renderPathDetails('runtime-config.php', runtimeConfigDiag, runtimeConfigDiag.keys && runtimeConfigDiag.keys.length ? h(DetailRow, { label: __("Keys", 'ultracache'), value: runtimeConfigDiag.keys.join(', ') }) : null),
								analyticsDiag.table ? renderPathDetails('Analytics DB table', Object.assign({}, analyticsDiag, { path: analyticsDiag.table, readable: analyticsDiag.exists, writable: analyticsDiag.exists }), analyticsDiag.keys && analyticsDiag.keys.length ? h(DetailRow, { label: __("Top keys", 'ultracache'), value: analyticsDiag.keys.join(', ') }) : null) : null,
								renderPathDetails('Cache directory', cacheDirDiag),
								renderPathDetails('Object cache directory', objectCacheDirDiag),
							]),
						])
						: null,
					h('div', { className: 'uc-diagnostic-group', key: 'cache-storage-diagnostics' }, [
						h('div', { className: 'uc-section-title' }, __("Cache storage diagnostics", 'ultracache')),
						renderRows(storageRows, 'plain'),
						h('button', {
							className: 'uc-btn mt-3 text-white py-2 px-3 font-bold',
							onClick: onRefreshStorageDiagnostics,
							disabled: busy || !onRefreshStorageDiagnostics,
						}, busy ? 'Engine Busy' : 'Refresh storage diagnostics'),
						cacheStorageDiag.message ? h('div', { className: 'mt-2 text-xs text-zinc-500' }, cacheStorageDiag.message) : null,
						storageWarnings.length ? h('div', { className: 'mt-3 text-xs text-cyan-300 space-y-1' }, storageWarnings.map(function(message, index) {
							return h('div', { key: 'storage-warning-' + index }, message);
						})) : null,
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'css-bundle-storage-diagnostics' }, [
						h('div', { className: 'uc-section-title' }, __("CSS bundle storage", 'ultracache')),
						renderRows(cssStorageRows, 'plain'),
						storageCssBundles.message ? h('div', { className: storageCssBundles.warningLevel === 'ok' ? 'mt-3 text-xs text-zinc-500' : 'mt-3 text-xs text-cyan-300' }, storageCssBundles.message) : null,
						storageCssBundles.cleanupPolicyMessage ? h('div', { className: 'mt-2 text-xs text-zinc-400' }, storageCssBundles.cleanupPolicyMessage) : null,
						storageCssBundles.recentProtectedMessage ? h('div', { className: 'mt-2 text-xs text-cyan-300' }, storageCssBundles.recentProtectedMessage) : null,
						storageCssBundles.oldEligibleMessage ? h('div', { className: 'mt-2 text-xs text-amber-300' }, storageCssBundles.oldEligibleMessage) : null,
						Array.isArray(storageCssBundles.largestFiles) && storageCssBundles.largestFiles.length ? h('div', { className: 'mt-4 rounded bg-black/10 p-4 space-y-3' }, [
							h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400' }, __("Largest CSS bundle files", 'ultracache')),
							storageCssBundles.largestFiles.map(function(file) {
								return h(DetailRow, { key: file.name, label: file.name, value: formatBytes(file.bytes || 0) + ' · ' + formatLooseTime(file.modified || 0), mono: true });
							}),
						]) : null,
					]),
					reverseProxy.detected ? h('div', { className: 'uc-diagnostic-group', key: 'proxy-box' }, [
						h('div', { className: 'uc-section-title' }, __("Reverse Proxy Details", 'ultracache')),
						h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: __("Provider", 'ultracache'), value: reverseProxy.provider || 'Detected' }),
							h(DetailRow, { label: __("Server", 'ultracache'), value: reverseProxy.server || '' }),
							h(DetailRow, { label: 'Via', value: reverseProxy.via || '' }),
							h(DetailRow, { label: 'X-Cache', value: reverseProxy.x_cache || '' }),
							h(DetailRow, { label: 'X-Cache-Status', value: reverseProxy.x_cache_status || '' }),
							h(DetailRow, { label: 'X-Proxy-Cache', value: reverseProxy.x_proxy_cache || '' }),
							h(DetailRow, { label: 'X-FastCGI-Cache', value: reverseProxy.x_fastcgi_cache || '' }),
							h(DetailRow, { label: 'X-LiteSpeed-Cache', value: reverseProxy.x_litespeed_cache || '' }),
							h(DetailRow, { label: 'X-QC-Cache', value: reverseProxy.x_qc_cache || '' }),
							h(DetailRow, { label: 'CF-Cache-Status', value: reverseProxy.cf_cache_status || '' }),
							h(DetailRow, { label: __("Age", 'ultracache'), value: reverseProxy.age || '' }),
						]),
					]) : null,
					h('div', { className: 'uc-diagnostic-group', key: 'environment-group' }, [
						h('div', { className: 'uc-section-title' }, __("Server & PHP environment", 'ultracache')),
						renderRows(environmentRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'media-runtime-group' }, [
						h('div', { className: 'uc-section-title' }, __("Media runtime diagnostics", 'ultracache')),
						renderRows(mediaRuntimeRows, 'neutral'),
					]),
					h(FontPipelineDiagnosticsPanel, { diagnostics, key: 'font-pipeline-diagnostics-panel' }),
					h('div', { className: 'uc-diagnostic-group', key: 'cache-group' }, [
						h('div', { className: 'uc-section-title' }, __("Cache diagnostics", 'ultracache')),
						renderRows(cacheDetailRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'reasons-group' }, [
						h('div', { className: 'uc-section-title' }, __("Top bypass reasons", 'ultracache')),
						reasonEntries.length
							? renderRows(reasonEntries.map(([reason, count]) => [reason, false, statsDisabled ? 'Stats disabled' : formatNumber(count)]), 'neutral')
							: h('div', { className: 'text-xs text-zinc-500 pt-2' }, statsDisabled ? 'Cache Statistics are OFF, so bypass counters are not collected.' : 'No bypasses recorded yet.'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500 space-y-1', key: 'last' }, [
						h('div', { key: 'status' }, 'Last cache event: ' + lastStatus + (last.reason ? ' · ' + last.reason : '')),
						h('div', { key: 'bucket' }, 'Last bucket: ' + (last.bucket || '—')),
						h('div', { key: 'time' }, 'Last seen: ' + lastSeen),
						compressionStatus.message ? h('div', { key: 'compression-note' }, 'Compression: ' + compressionStatus.message) : null,
						compressionStatus.serverDefault && compressionStatus.serverDefault.message ? h('div', { key: 'compression-server-default-note' }, 'Frontend compression: ' + compressionStatus.serverDefault.message) : null,
						diagnostics && diagnostics.loopbackSsl && diagnostics.loopbackSsl.fallbackUsed && diagnostics.loopbackSsl.message ? h('div', { key: 'loopback-ssl-note' }, 'Loopback SSL: ' + diagnostics.loopbackSsl.message) : null,
						wpCacheStatus.message ? h('div', { key: 'wp-cache-note' }, 'WP_CACHE: ' + wpCacheStatus.message) : null,
						analyticsBackend.message ? h('div', { key: 'analytics-backend-note' }, 'Analytics hits: ' + analyticsBackend.message) : null,
						!objectCacheStatus.available && objectCacheStatus.message ? h('div', { key: 'object-cache-note' }, 'File object cache: ' + objectCacheStatus.message) : null,
						reverseProxy.message ? h('div', { key: 'reverse-proxy-note' }, 'Reverse proxy: ' + reverseProxy.message) : null,
					]),
				]),
			]),
		]);
	}

	function ProgressPanel({ process, etaText, onCancel, showWhenInactive }) {
		if (!process.active && !showWhenInactive) {
			return null;
		}

		const safeTotal = Math.max(0, Number(process.total || 0));
		const safeCurrent = safeTotal > 0 ? Math.min(Math.max(0, Number(process.current || 0)), safeTotal) : Math.max(0, Number(process.current || 0));
		const percent = safeTotal > 0 ? Math.min(100, Math.round((safeCurrent / safeTotal) * 100)) : 0;
		const progressText = safeTotal > 0
			? safeCurrent + ' / ' + safeTotal + (process.queueBuilding ? '+ (building queue)' : '') + ' (' + percent + '%)'
			: (process.queueBuilding ? 'Building queue…' : 'Preparing…');
		const unitText = process.type === 'media' && Number(process.unitCount || 0) > 0
			? ('Image units checked: ' + Math.max(0, Number(process.unitCount || 0)))
			: '';
		const avifCount = Math.max(0, Number(process.avifCount || 0));
		const webpCount = Math.max(0, Number(process.webpCount || 0));
		const successCount = Math.max(0, Number(process.successCount || 0));
		const skippedCount = Math.max(0, Number(process.skippedCount || 0));
		const failedCount = Math.max(0, Number(process.failedCount || 0));
		const hasCounters = successCount > 0 || skippedCount > 0 || failedCount > 0 || avifCount > 0 || webpCount > 0 || Number(process.unitCount || 0) > 0;

		return h('div', { className: 'bg-emerald-500/5  p-6 rounded' }, [
			h('div', { className: 'flex justify-between items-center mb-3 gap-3', key: 'head' }, [
				h(
					'h4',
					{ className: 'text-sm font-bold tracking-widest text-emerald-400 m-0' },
					process.label || 'Working'
				),
				h('div', { className: 'flex items-center gap-3', key: 'controls' }, [
					h('span', { className: 'text-[10px] text-zinc-500', key: 'eta' }, etaText || ''),
					process.cancellable && typeof onCancel === 'function'
						? h(
							'button',
							{
								className: 'uc-btn !bg-zinc-800 !text-white !border-white/10 !py-2 !px-3 text-xs font-bold',
								onClick: onCancel,
								disabled: !!process.cancelRequested,
								key: 'cancel',
							},
							process.cancelRequested ? 'Stopping…' : 'Cancel'
						 )
						: null,
				]),
			]),
			h('div', { className: 'flex justify-between text-xs mb-2', key: 'meta' }, [
				h('span', { className: 'text-zinc-400' }, __("Overall Progress", 'ultracache')),
				h(
					'span',
					{ className: 'text-emerald-400 font-mono' },
					progressText
				),
			]),
			h('div', { className: 'w-full bg-zinc-800 h-1 rounded-full overflow-hidden', key: 'bar-wrap' }, [
				h('div', {
					className: 'bg-emerald-500 h-full transition-all duration-300',
					style: { width: percent + '%' },
				}),
			]),
			unitText ? h('div', { className: 'mt-2 text-[11px] text-zinc-500', key: 'unit-count' }, unitText) : null,
			hasCounters
				? h('div', {
					className: 'uc-media-operation-counters mt-3 text-[11px]',
					key: 'job-counters',
					style: { display: 'flex', flexWrap: 'wrap', gap: '4px 14px', alignItems: 'center' },
				}, process.type === 'media'
					? [
						h('span', { className: 'text-emerald-400 font-bold', key: 'checked' }, 'Attachments checked: ' + safeCurrent + (safeTotal > 0 ? ' / ' + safeTotal : '')),
						h('span', { className: 'text-zinc-400 font-bold', key: 'units' }, 'Image units checked: ' + Math.max(0, Number(process.unitCount || 0))),
						h('span', { className: avifCount > 0 ? 'text-emerald-400 font-bold' : 'text-zinc-500 font-bold', key: 'avif' }, 'AVIF generated: ' + avifCount),
						h('span', { className: webpCount > 0 ? 'text-emerald-400 font-bold' : 'text-zinc-500 font-bold', key: 'webp' }, 'WebP generated: ' + webpCount),
						h('span', { className: 'text-zinc-400 font-bold', key: 'already' }, 'Already optimized: ' + skippedCount),
						h('span', { className: failedCount > 0 ? 'text-amber-400 font-bold' : 'text-zinc-500 font-bold', key: 'failed' }, 'Failed: ' + failedCount),
					]
					: [
						h('span', { className: 'text-emerald-400 font-bold', key: 'cached' }, 'Cached: ' + successCount),
						h('span', { className: 'text-zinc-400 font-bold', key: 'skipped' }, 'Skipped: ' + skippedCount),
						h('span', { className: failedCount > 0 ? 'text-amber-400 font-bold' : 'text-zinc-500 font-bold', key: 'failed' }, 'Failed: ' + failedCount),
					])
				: null,
			process.logs && process.logs.length
				? h(
						'div',
						{
							className:
								'mt-4 max-h-40 overflow-y-auto text-[11px] font-mono text-zinc-400 bg-black/20 p-3 rounded space-y-1',
							key: 'logs',
						},
						process.logs.slice(-10).map((log, index) =>
							h('div', { key: 'log-' + index }, '> ' + log)
						)
				 )
				: null,
		]);
	}


	function PerformanceProfilerCard({ profile, busy, onRun, onDownload, onClear, onCopyCssExclusion }) {
		const current = profile && profile.available ? profile : null;
		const slowCheckpoints = current && Array.isArray(current.slowCheckpoints) ? current.slowCheckpoints : [];
		const callbackTop = current && Array.isArray(current.callbackTop) ? current.callbackTop : [];
		const originTop = current && Array.isArray(current.originTop) ? current.originTop : [];
		const modeLabel = current ? (current.mode || current.requestMode || 'compact') : 'none';
		const cssBundle = current && current.cssBundle ? current.cssBundle : {};
		const criticalChain = current && current.criticalRequestChain ? current.criticalRequestChain : {};
		const jsDelaySafetyScan = current && current.jsDelaySafetyScan ? current.jsDelaySafetyScan : {};
		const criticalStyleCandidates = criticalChain && Array.isArray(criticalChain.styleCandidates) ? criticalChain.styleCandidates : [];
		const criticalScriptCandidates = criticalChain && Array.isArray(criticalChain.scriptCandidates) ? criticalChain.scriptCandidates : [];
		const inlineCssWarning = !!(cssBundle && ((cssBundle.inlineStyleBytes || 0) > 524288 || (cssBundle.finalHtmlBytes || 0) > 1048576));
		const cssBundleCriticalWarning = !!(cssBundle && ((cssBundle.fileBytes || 0) > 153600 || (cssBundle.veryLargeBundleWarning || false)));
		const cssBundleSourceTop = cssBundle && Array.isArray(cssBundle.sourceTop) ? cssBundle.sourceTop : [];
		const leftoverCssBundle = cssBundle && cssBundle.leftoverCssBundle ? cssBundle.leftoverCssBundle : {};
		const overheadProbe = current && current.ultraCacheOverheadProbe ? current.ultraCacheOverheadProbe : {};
		const overheadProbeItems = overheadProbe && Array.isArray(overheadProbe.slowItems) ? overheadProbe.slowItems : [];
		const overheadProbeDeltas = overheadProbe && Array.isArray(overheadProbe.topCheckpointDeltas) ? overheadProbe.topCheckpointDeltas : [];
		const frontendRewriteBreakdown = current && current.frontendRewriteBreakdown ? current.frontendRewriteBreakdown : {};
		const frontendRewriteItems = frontendRewriteBreakdown && Array.isArray(frontendRewriteBreakdown.items) ? frontendRewriteBreakdown.items : [];
		const cssLinkDuplication = current && current.cssLinkDuplication ? current.cssLinkDuplication : {};
		const cssDuplicateItems = cssLinkDuplication && Array.isArray(cssLinkDuplication.items) ? cssLinkDuplication.items : [];
		const copyCssExclusion = typeof onCopyCssExclusion === 'function' ? onCopyCssExclusion : function () {};

		const summaryRows = current ? [
			['Mode', modeLabel],
			['Status', (current.status || '—') + (current.cacheStatus ? ' / ' + current.cacheStatus : '')],
			['Total request', formatNumber(current.totalRequestDurationMs || current.requestMs || 0) + ' ms'],
			['STORE processing', formatNumber(current.storeProfileDurationMs || 0) + ' ms'],
			['Shutdown total', formatNumber(current.shutdownTotalDurationMs || 0) + ' ms'],
			['Slowest rewrite stage', current.slowestStage && current.slowestStage.stage ? current.slowestStage.stage + ' · ' + formatNumber(current.slowestStage.durationMs || 0) + ' ms' : '—'],
			['Checkpoints', formatNumber(current.checkpointCount || 0)],
			['Callback rows', formatNumber(current.callbackTimingSummaryCount || 0)],
			['CSS bundle', cssBundle.fileExists ? (formatBytes(cssBundle.fileBytes || 0) + ' · ' + formatNumber(cssBundle.sourceUrlCount || 0) + ' sources' + (cssBundle.mode ? ' · ' + cssBundle.mode : '')) : 'Not built in this run'],
			['CSS source bytes', cssBundle.sourceBytesTotal ? formatBytes(cssBundle.sourceBytesTotal || 0) : '—'],
			['Largest CSS source', cssBundle.largestSourceUrl ? (formatBytes(cssBundle.largestSourceBytes || 0) + ' · ' + cssBundle.largestSourceUrl) : '—'],
			['Render-blocking CSS', formatNumber(cssBundle.renderBlockingStylesheets || 0) + ' links · ' + formatNumber(cssBundle.renderBlockingBundleLinks || 0) + ' bundle · ' + formatNumber(cssBundle.renderBlockingNonBundleLinks || 0) + ' outside bundle'],
			['Leftover CSS bundle', cssBundle.leftoverCssBundle && cssBundle.leftoverCssBundle.enabled ? ((cssBundle.leftoverCssBundle.success ? 'Built · ' : 'Skipped · ') + formatNumber(cssBundle.leftoverCssBundle.replacedLinkCount || 0) + ' links · ' + formatBytes(cssBundle.leftoverCssBundle.bundleBytes || 0) + (cssBundle.leftoverCssBundle.skippedReason ? ' · ' + cssBundle.leftoverCssBundle.skippedReason : '')) : 'Disabled'],
			['Critical request chain', criticalChain.available ? (formatNumber(criticalChain.renderBlockingStyleCount || 0) + ' blocking CSS · ' + formatNumber(criticalChain.renderBlockingScriptCount || 0) + ' blocking JS · ' + formatNumber(criticalChain.delayedScriptCount || 0) + ' delayed JS') : '—'],
			['JS delay safety scan', jsDelaySafetyScan.available ? (formatNumber(jsDelaySafetyScan.suggestionCount || 0) + ' suggestion(s) · ' + formatNumber(jsDelaySafetyScan.missingCount || 0) + ' missing') : '—'],
			['UltraCache overhead probe', overheadProbe && overheadProbe.available ? ('buffering ' + formatNumber(overheadProbe.maybeStartBufferingMs || 0) + ' ms · bypass ' + formatNumber(overheadProbe.shouldBypassMs || 0) + ' ms') : '—'],
			['Frontend rewrite stages', frontendRewriteBreakdown && frontendRewriteBreakdown.available ? (formatNumber(frontendRewriteBreakdown.frontendTotalMs || 0) + ' ms total · ' + formatNumber(frontendRewriteItems.length || 0) + ' measured step(s)') : '—'],
			['CSS duplicate/mixed links', cssLinkDuplication && cssLinkDuplication.available ? (formatNumber(cssLinkDuplication.duplicateCount || 0) + ' duplicate · ' + formatNumber(cssLinkDuplication.mixedStatusCount || 0) + ' mixed status') : '—'],
			['Final HTML size', cssBundle.finalHtmlBytes ? formatBytes(cssBundle.finalHtmlBytes || 0) : '—'],
			['Inline CSS bytes', cssBundle.inlineStyleBytes ? (formatBytes(cssBundle.inlineStyleBytes || 0) + ' · ' + formatNumber(cssBundle.inlineStyleTags || 0) + ' style tag(s)') : '0 B'],
			['CSS bundle fallbacks', formatNumber(cssBundle.fallbackLinks || 0) + ' links · ' + formatNumber(cssBundle.fallbackMarkers || 0) + ' markers · ' + formatNumber(cssBundle.noscriptTags || 0) + ' noscript tags'],
		] : [];

		return h('details', { className: 'uc-card uc-accordion uc-performance-profiler', key: 'performance-profiler' }, [
			h('summary', { className: 'uc-accordion__summary uc-performance-profiler__summary', key: 'summary' }, [
				h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
					h('div', { className: 'uc-accordion__title', key: 'title' }, __("Speed Diagnostics", 'ultracache')),
					h('div', { className: 'uc-accordion__description', key: 'description' }, __("Find what slows down the first uncached page build.", 'ultracache')),
				]),
				h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
			]),
			h('div', { className: 'uc-accordion__body uc-performance-profiler__body', key: 'body' }, [
				h('div', { className: 'uc-card-warning mb-4', key: 'warning' }, [
					h('strong', { key: 'title' }, __("Use this when the first visit after purge feels slow. ", 'ultracache')),
					__("Quick Speed Check shows the main timing breakdown. Full Speed Breakdown adds deeper details. Analyze WordPress Hooks shows which plugin, theme, or WordPress core area costs time.", 'ultracache'),
				]),
				inlineCssWarning ? h('div', { className: 'uc-card-warning mb-4', key: 'inline-css-warning' }, [
					h('strong', { key: 'title' }, __("Inline CSS Bundling generated large cached HTML. ", 'ultracache')),
					'Last profile: inline CSS ' + formatBytes(cssBundle.inlineStyleBytes || 0) + ', final HTML ' + formatBytes(cssBundle.finalHtmlBytes || 0) + '. This setting is still respected; UltraCache will not silently switch it to external CSS. Disable Inline CSS Bundling if this size is too high for the site/server.'
				]) : null,
				cssBundleCriticalWarning ? h('div', { className: 'uc-card-warning mb-4', key: 'css-bundle-critical-warning' }, [
					h('strong', { key: 'title' }, __("Large render-blocking CSS bundle detected. ", 'ultracache')),
					'Last profile: bundle ' + formatBytes(cssBundle.fileBytes || 0) + ' from ' + formatNumber(cssBundle.sourceUrlCount || 0) + ' source stylesheet(s). This is diagnostic only; UltraCache is not changing CSS loading automatically.'
				]) : null,
				h('div', { className: 'uc-profiler-actions mb-4', key: 'actions' }, [
					h(Button, { key: 'compact', variant: 'primary', disabled: !!busy, onClick: () => onRun('compact') }, busy ? 'Analyzing…' : 'Quick Speed Check'),
					h(Button, { key: 'verbose', disabled: !!busy, onClick: () => onRun('verbose') }, __("Full Speed Breakdown", 'ultracache')),
					h(Button, { key: 'callback', disabled: !!busy, onClick: () => onRun('callback') }, __("Analyze WordPress Hooks", 'ultracache')),
					h(Button, { key: 'download', disabled: !!busy || !current, onClick: onDownload }, __("Download Diagnostic Data", 'ultracache')),
					h(Button, { key: 'clear', variant: 'danger', disabled: !!busy || !current, onClick: onClear }, __("Clear Last Speed Report", 'ultracache')),
				]),
				current ? h('div', { className: 'uc-detail-list mb-4', key: 'summary-list' }, summaryRows.map((row) => h(DetailRow, { key: row[0], label: row[0], value: row[1] }))) : h('div', { className: 'text-sm text-zinc-500', key: 'empty' }, __("No speed report loaded yet.", 'ultracache')),
				current && overheadProbe && overheadProbe.available ? h('div', { className: 'mt-4 mb-4 bg-black/20 rounded-2xl px-4 py-4', key: 'ultracache-overhead-probe' }, [
					h('div', { className: 'flex items-center justify-between gap-4 mb-3', key: 'heading' }, [
						h('div', { key: 'copy' }, [
							h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase', key: 'label' }, __("UltraCache Overhead Probe", 'ultracache')),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'hint' }, __("Breaks down UltraCache request-path work such as cacheability checks, early HIT lookup, CSS ref validation, and cache output processing.", 'ultracache')),
						]),
						h('span', { className: (overheadProbe.maybeStartBufferingMs || 0) > 100 ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-emerald-300 shrink-0', key: 'status' }, 'buffering ' + formatNumber(overheadProbe.maybeStartBufferingMs || 0) + ' ms'),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-3 text-xs mb-3', key: 'cards' }, [
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'maybe' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Buffering entry", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(overheadProbe.maybeStartBufferingMs || 0) + ' ms'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, __("template_redirect → buffer/bypass/HIT", 'ultracache')),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'bypass' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Cacheability checks", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(overheadProbe.shouldBypassMs || 0) + ' ms'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, __("should_bypass_cache breakdown", 'ultracache')),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'output' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Output callback", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(overheadProbe.cacheOutputCallbackMs || 0) + ' ms'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, __("HTML rewrites + cache write", 'ultracache')),
						]),
					]),
					overheadProbeItems.length ? h('div', { className: 'space-y-2', key: 'items' }, overheadProbeItems.slice(0, 10).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'uc-overhead-' + index }, [
						h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
							h('span', { key: 'label' }, item.label || item.endStage || 'overhead step'),
							h('span', { className: (item.durationMs || 0) > 50 ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0', key: 'duration' }, formatNumber(item.durationMs || 0) + ' ms'),
						]),
						item.description ? h('div', { className: 'text-zinc-500 mt-1', key: 'desc' }, item.description) : null,
					]))) : null,
					overheadProbeDeltas.length ? h('details', { className: 'mt-3', key: 'deltas' }, [
						h('summary', { className: 'text-[11px] text-zinc-500 cursor-pointer', key: 'summary' }, __("Show checkpoint deltas", 'ultracache')),
						h('div', { className: 'space-y-1 mt-2', key: 'delta-items' }, overheadProbeDeltas.slice(0, 12).map((item, index) => h('div', { className: 'text-[11px] text-zinc-400 flex items-center justify-between gap-3', key: 'uc-delta-' + index }, [
							h('span', { className: 'break-all', key: 'stage' }, item.stage || 'checkpoint'),
							h('span', { className: 'font-mono shrink-0', key: 'delta' }, formatNumber(item.deltaMs || 0) + ' ms'),
						]))),
					]) : null,
				]) : null,
				current && frontendRewriteBreakdown && frontendRewriteBreakdown.available ? h('div', { className: 'mt-4 mb-4 bg-black/20 rounded-2xl px-4 py-4', key: 'frontend-rewrite-breakdown' }, [
					h('div', { className: 'flex items-center justify-between gap-4 mb-3', key: 'heading' }, [
						h('div', { key: 'copy' }, [
							h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase', key: 'label' }, __("Frontend Rewrite Stage Breakdown", 'ultracache')),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'hint' }, __("Breaks down the HTML optimization work inside the STORE output callback. Diagnostic only; no loading behavior is changed.", 'ultracache')),
						]),
						h('span', { className: (frontendRewriteBreakdown.frontendTotalMs || 0) > 500 ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0', key: 'status' }, formatNumber(frontendRewriteBreakdown.frontendTotalMs || 0) + ' ms total'),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-3 text-xs mb-3', key: 'cards' }, [
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'parent' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Parent rewrite time", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(frontendRewriteBreakdown.frontendTotalMs || 0) + ' ms'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, 'frontend_performance_optimizations_total'),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'visible' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Measured sub-steps", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(frontendRewriteItems.length || 0)),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, __("sorted by duration", 'ultracache')),
						]),
					]),
					frontendRewriteItems.length ? h('div', { className: 'space-y-2', key: 'items' }, frontendRewriteItems.slice(0, 14).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'frontend-stage-' + index }, [
						h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
							h('span', { key: 'label' }, item.label || item.stage || 'rewrite stage'),
							h('span', { className: (item.durationMs || 0) > 100 ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0', key: 'duration' }, formatNumber(item.durationMs || 0) + ' ms'),
						]),
						h('div', { className: 'text-zinc-500 mt-1 break-all', key: 'meta' }, (item.stage || '') + ' · Δ ' + formatBytes(Math.abs(item.deltaBytes || 0))),
					]))) : null,
					frontendRewriteBreakdown.note ? h('div', { className: 'text-[11px] text-zinc-500 mt-3', key: 'note' }, frontendRewriteBreakdown.note) : null,
				]) : null,				current && cssLinkDuplication && cssLinkDuplication.available && cssDuplicateItems.length ? h('div', { className: 'mt-4 mb-4 bg-black/20 rounded-2xl px-4 py-4', key: 'css-link-duplication' }, [
					h('div', { className: 'flex items-center justify-between gap-4 mb-3', key: 'heading' }, [
						h('div', { key: 'copy' }, [
							h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase', key: 'label' }, __("CSS Duplicate / Mixed-Status Links", 'ultracache')),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'hint' }, __("Highlights stylesheet URLs that appear more than once or appear both blocking and non-blocking. Diagnostic only.", 'ultracache')),
						]),
						h('span', { className: cssLinkDuplication.mixedStatusCount ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0', key: 'status' }, formatNumber(cssLinkDuplication.duplicateCount || 0) + ' duplicate'),
					]),
					h('div', { className: 'space-y-2', key: 'items' }, cssDuplicateItems.slice(0, 8).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'css-dup-' + index }, [
						h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
							h('span', { className: 'break-all', key: 'url' }, item.url || 'unknown stylesheet'),
							h('span', { className: item.mixedBlockingStatus ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0', key: 'count' }, formatNumber(item.count || 0) + 'x'),
						]),
						h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, formatNumber(item.renderBlockingCount || 0) + ' blocking · ' + formatNumber(item.nonBlockingCount || 0) + ' non-blocking' + (item.statuses && item.statuses.length ? ' · ' + item.statuses.join(', ') : '')),
						item.suggestedAction ? h('div', { className: 'text-emerald-300 mt-1', key: 'suggestion' }, item.suggestedAction) : null,
					]))),
				]) : null,
				current ? h('div', { className: 'mt-4 mb-4 bg-black/20 rounded-2xl px-4 py-4', key: 'css-critical-path-diagnostics' }, [
					h('div', { className: 'flex items-center justify-between gap-4 mb-3', key: 'heading' }, [
						h('div', { key: 'copy' }, [
							h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase', key: 'label' }, __("CSS Critical Path / Render Blocking Diagnostics", 'ultracache')),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'hint' }, __("Summary of the CSS calls left in the first render path. Diagnostic only; no CSS loading behavior is changed automatically.", 'ultracache')),
						]),
						h('span', { className: ((cssBundle.renderBlockingStylesheets || 0) > 0 ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-emerald-300 shrink-0'), key: 'status' }, formatNumber(cssBundle.renderBlockingStylesheets || 0) + ' blocking CSS'),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3 text-xs', key: 'cards' }, [
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'main' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Main bundle", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, cssBundle.fileExists ? formatBytes(cssBundle.fileBytes || 0) : 'Not built'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, formatNumber(cssBundle.sourceUrlCount || 0) + ' source stylesheet(s)' + (cssBundle.mode ? ' · ' + cssBundle.mode : '')),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'leftover' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Leftover bundle", 'ultracache')),
							h('div', { className: leftoverCssBundle.enabled && leftoverCssBundle.success ? 'text-emerald-300 font-bold mt-1' : 'text-zinc-200 font-bold mt-1', key: 'value' }, leftoverCssBundle.enabled ? (leftoverCssBundle.success ? 'Built' : 'Skipped') : 'Disabled'),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, formatNumber(leftoverCssBundle.replacedLinkCount || 0) + ' replaced · ' + formatBytes(leftoverCssBundle.bundleBytes || 0)),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'links' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Final CSS links", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(cssBundle.stylesheetLinks || 0)),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, formatNumber(cssBundle.renderBlockingBundleLinks || 0) + ' bundle · ' + formatNumber(cssBundle.renderBlockingNonBundleLinks || 0) + ' outside bundle'),
						]),
						h('div', { className: 'bg-black/20 rounded-xl px-3 py-3', key: 'protected' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, __("Protected CSS", 'ultracache')),
							h('div', { className: 'text-zinc-200 font-bold mt-1', key: 'value' }, formatNumber(criticalChain.protectedStyleCount || 0)),
							h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, __("Slider/hero or safety protected", 'ultracache')),
						]),
					]),
					(cssBundle.renderBlockingStylesheets || 0) > 0 ? h('div', { className: 'mt-3 text-[11px] text-zinc-400 leading-relaxed', key: 'recommendation' }, [
						h('strong', { className: 'text-zinc-300', key: 'title' }, __("Recommended next check: ", 'ultracache')),
						(leftoverCssBundle.enabled && leftoverCssBundle.success) ? 'Leftover CSS consolidation is active. The remaining larger issue is the main render-blocking CSS bundle, so the next optimization candidate is critical CSS split / async non-critical bundle mode.' : 'Test Consolidate Remaining CSS first if visual output is safe, then review whether the main bundle needs a critical CSS split.',
					]) : null,
				]) : null,
					current && (criticalStyleCandidates.length || criticalScriptCandidates.length) ? h('div', { className: 'mt-4', key: 'critical-chain' }, [
						h('div', { className: 'flex items-center justify-between gap-4 mb-2', key: 'heading' }, [
							h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase', key: 'label' }, __("Critical Request Chain Candidates", 'ultracache')),
							h('div', { className: 'text-[11px] text-zinc-500 text-right', key: 'hint' }, __("Diagnostic only: shows why CSS/JS remains blocking, delayed, or protected.", 'ultracache')),
						]),
						criticalStyleCandidates.length ? h('div', { className: 'mb-3', key: 'styles' }, [
							h('div', { className: 'text-[11px] text-zinc-500 uppercase tracking-wider mb-2', key: 'styles-label' }, __("Styles", 'ultracache')),
							h('div', { className: 'space-y-2', key: 'style-items' }, criticalStyleCandidates.slice(0, 10).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'critical-style-' + index }, [
								h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
									h('span', { className: 'break-all', key: 'url' }, item.url || item.path || 'unknown stylesheet'),
									h('span', { className: item.renderBlocking ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-emerald-300 shrink-0', key: 'status' }, item.status || 'unknown'),
								]),
								h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, (item.origin || 'local') + ' · ' + (item.location || 'head') + (item.bytes ? ' · ' + formatBytes(item.bytes || 0) : '') + (item.protected ? ' · protected' : '')),
								item.reason ? h('div', { className: 'text-zinc-400 mt-1', key: 'reason' }, item.reason) : null,
								item.suggestedAction ? h('div', { className: 'text-emerald-300 mt-1', key: 'suggestion' }, item.suggestedAction) : null,
							]))),
						]) : null,
						criticalScriptCandidates.length ? h('div', { key: 'scripts' }, [
							h('div', { className: 'text-[11px] text-zinc-500 uppercase tracking-wider mb-2', key: 'scripts-label' }, __("Scripts", 'ultracache')),
							h('div', { className: 'space-y-2', key: 'script-items' }, criticalScriptCandidates.slice(0, 12).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'critical-script-' + index }, [
								h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
									h('span', { className: 'break-all', key: 'url' }, item.url || item.path || 'unknown script'),
									h('span', { className: item.renderBlocking ? 'font-mono text-amber-300 shrink-0' : (item.delayed ? 'font-mono text-emerald-300 shrink-0' : 'font-mono text-zinc-300 shrink-0'), key: 'status' }, item.status || 'unknown'),
								]),
								h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, (item.origin || 'local') + ' · ' + (item.location || 'head') + (item.handle ? ' · ' + item.handle : '') + (item.bytes ? ' · ' + formatBytes(item.bytes || 0) : '') + (item.protected ? ' · protected' : '')),
								item.reason ? h('div', { className: 'text-zinc-400 mt-1', key: 'reason' }, item.reason) : null,
								item.suggestedAction ? h('div', { className: 'text-emerald-300 mt-1', key: 'suggestion' }, item.suggestedAction) : null,
							]))),
						]) : null,
					]) : null,
				current ? h('div', { className: 'mt-4', key: 'origin-summary' }, [
					h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase mb-2', key: 'label' }, __("Plugin / Theme Time Summary", 'ultracache')),
					originTop.length ? h('div', { className: 'space-y-2', key: 'items' }, originTop.slice(0, 12).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'origin-' + index }, [
						h('div', { className: 'flex items-center justify-between gap-4', key: 'main' }, [
							h('span', { key: 'name' }, (item.originName || 'unknown') + ' · ' + (item.originType || 'origin')),
							h('span', { className: 'font-mono text-amber-300', key: 'ms' }, formatNumber(item.totalMs || 0) + 'ms'),
						]),
						h('div', { className: 'text-zinc-500 mt-1', key: 'meta' }, formatNumber(item.callbackCount || 0) + ' callback groups' + (item.topCallback ? ' · slowest: ' + item.topCallback + ' (' + formatNumber(item.topCallbackMs || 0) + 'ms)' : '')),
					]))) : h('div', { className: 'text-xs text-zinc-500 bg-black/20 rounded-xl px-3 py-2', key: 'empty' }, __("Analyze WordPress Hooks to see total delay grouped by plugin, theme, and WordPress core.", 'ultracache')),
				]) : null,
				current && slowCheckpoints.length ? h('div', { className: 'mt-4', key: 'slow' }, [
					h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase mb-2', key: 'label' }, __("Slow checkpoints", 'ultracache')),
					h('div', { className: 'space-y-2', key: 'items' }, slowCheckpoints.slice(0, 6).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'slow-' + index }, [
						h('span', { className: 'font-mono text-amber-300', key: 'ms' }, formatNumber(item.deltaMs || 0) + 'ms '),
						h('span', { key: 'stage' }, item.stage || 'unknown'),
						item.hook ? h('span', { className: 'text-zinc-500', key: 'hook' }, ' · ' + item.hook) : null,
						item.callback ? h('span', { className: 'text-zinc-500', key: 'cb' }, ' · ' + item.callback) : null,
					]))),
				]) : null,
				current && callbackTop.length ? h('div', { className: 'mt-4', key: 'callbacks' }, [
					h('div', { className: 'text-xs tracking-widest text-zinc-500 uppercase mb-2', key: 'label' }, __("Top slow callbacks", 'ultracache')),
					h('div', { className: 'space-y-2', key: 'items' }, callbackTop.slice(0, 8).map((item, index) => h('div', { className: 'text-xs text-zinc-300 bg-black/20 rounded-xl px-3 py-2', key: 'cb-' + index }, [
						h('span', { className: 'font-mono text-amber-300', key: 'ms' }, formatNumber(item.totalMs || 0) + 'ms '),
						h('span', { key: 'callback' }, item.callback || 'unknown callback'),
						h('span', { className: 'text-zinc-500', key: 'meta' }, ' · ' + (item.origin || 'unknown') + ' · ' + (item.hook || 'hook') + ':' + (item.priority || '')),
					]))),
				]) : null,
			]),
		]);
	}


	function CacheHelperConflictNotice({ diagnostics, busy, onRemove, onRecheck }) {
		const legacyConflicts = diagnostics && diagnostics.legacyCacheConflicts ? diagnostics.legacyCacheConflicts : {};
		const dropins = Array.isArray(legacyConflicts.dropins) ? legacyConflicts.dropins.filter((item) => item && item.exists && !item.managed) : [];
		const removableDropins = dropins.filter((item) => item && item.removable);

		if (!dropins.length) {
			return null;
		}

		return h('div', { id: 'ucwp-cache-conflict-review', className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-3' }, [
			h('div', { className: 'font-bold text-amber-200 mb-2', key: 'title' }, __("Conflicting WordPress cache helpers detected", 'ultracache')),
			h('div', { className: 'space-y-1 mb-2', key: 'dropins' }, dropins.map((item) => h('div', { key: 'dropin-' + item.file }, [
				h('span', { className: 'font-mono text-amber-100' }, item.file || 'drop-in'),
				h('span', {}, ' — owner: ' + (item.owner || 'Unknown') + (item.removable ? ' · removable' : '')),
			]))),
			h('div', { className: 'text-amber-100/90', key: 'message' }, __("UltraCache can back up and remove these conflicting WordPress drop-ins. It will not delete plugin folders or settings from other plugins.", 'ultracache')),
			h('div', { className: 'flex flex-wrap gap-3 mt-3', key: 'actions' }, [
				removableDropins.length ? h(Button, { onClick: onRemove, disabled: busy, variant: 'danger', key: 'remove' }, busy ? 'Working…' : 'Remove conflicting cache helpers') : null,
				h(Button, { onClick: onRecheck, disabled: busy, variant: 'light', key: 'recheck' }, busy ? 'Working…' : 'Re-check'),
			]),
		]);
	}


	function getDefaultVarnishServersForMode(mode) {
		return String(mode || 'http') === 'admin' ? '127.0.0.1:6082' : '127.0.0.1:82';
	}

	function isDefaultVarnishServersValue(value) {
		const normalized = String(value || '').trim();
		return !normalized || normalized === '127.0.0.1:82' || normalized === '127.0.0.1:6082';
	}

	function VarnishCard({ form, diagnostics, busy, onFieldChange, onSave, onTest, onFlushAll, onRemoveConflictingDropins, onRecheckConflicts }) {
		const varnish = diagnostics.varnish || {};
		const last = varnish.last || {};
		const supportMessage = varnish.message || '';
		const endpointDiagnostics = varnish.endpointDiagnostics || {};
		const endpointWarningMessages = Array.isArray(endpointDiagnostics.messages) ? endpointDiagnostics.messages : (varnish.unsafeEndpointMessage ? [varnish.unsafeEndpointMessage] : []);
		const hasUnsafeEndpoints = !!varnish.hasUnsafeEndpoints || !!endpointDiagnostics.unsafe;
		const legacyConflicts = diagnostics.legacyCacheConflicts || {};
		const detailLines = Array.isArray(last.details) ? last.details.map((item) => {
			return (item.server || 'server') + ': ' + (item.success ? 'OK' : 'FAIL') + (item.detail ? ' · ' + item.detail : '');
		}).join('\n') : '';
		const mode = form.varnishCliMode || 'http';
		const isAdminMode = mode === 'admin';
		const formServers = String(form.varnishCliServers || '');
		const currentFrontendHost = (typeof window !== 'undefined' && window.location && window.location.hostname ? String(window.location.hostname).replace(/^www\./i, '') : '');
		const frontendHostPattern = currentFrontendHost ? new RegExp('(^|\\s)(?:https?:\\/\\/)?(?:www\\.)?' + currentFrontendHost.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?::|\\s|$)', 'i') : null;
		const formHasUnsafeEndpoint = !isAdminMode && /:(80|443|8443)(\s|$)/.test(formServers) && !!frontendHostPattern && frontendHostPattern.test(formServers);
		const actionsBlocked = hasUnsafeEndpoints || formHasUnsafeEndpoint;
		const effectiveMethod = varnish.effectiveMethod || (isAdminMode ? 'admin BAN' : (form.varnishCliMethod || 'BAN'));
		const endpointCount = typeof varnish.endpointCount !== 'undefined' ? varnish.endpointCount : (formServers.trim() ? formServers.trim().split(/\s+/).length : 0);
		const secretConfigured = !!(varnish.secretConfigured || form.varnishCliKeyConfigured);
		const modeLabel = isAdminMode ? 'Admin secret' : 'HTTP frontend';

		return h(Card, {
			title: __("Varnish Cache", 'ultracache'),
			description: __("Varnish integration supports two purge methods: HTTP frontend endpoint mode and admin-secret mode. Use the method your host exposes; HTTP mode is optional and is not required when admin-secret mode is configured.", 'ultracache'),
		}, [
			h(ToggleRow, {
				label: isAdminMode ? 'Enable Varnish admin-secret purge' : 'Enable Varnish HTTP endpoint purge',
				description: varnish.available ? (isAdminMode ? 'Saves immediately. Uses the Varnish admin socket and shared secret. HTTP endpoint tests are not used in this mode.' : 'Saves immediately. Sends BAN/PURGE requests to configured Varnish HTTP listener endpoints, including external infrastructure hosts when intentionally configured.') : (supportMessage || 'Unavailable on this server.'),
				checked: !!form.varnishCliEnabled,
				onChange: (value) => onFieldChange('varnishCliEnabled', value),
				disabled: busy || !varnish.available,
			}),
			
			!isAdminMode && endpointWarningMessages.length ? h('div', { className: 'space-y-2 mt-4' }, endpointWarningMessages.map((message, index) => h('div', { className: 'text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2', key: 'varnish-endpoint-warning-' + index }, message))) : null,
			formHasUnsafeEndpoint ? h('div', { className: 'mt-4 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2' }, __("This endpoint appears to point at the public WordPress frontend instead of a Varnish listener. External Varnish infrastructure and custom ports are allowed when intentionally configured.", 'ultracache')) : null,
			h(CacheHelperConflictNotice, { diagnostics, busy, onRemove: onRemoveConflictingDropins, onRecheck: onRecheckConflicts }),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(SelectField, {
					label: __("Purge mode", 'ultracache'),
					description: __("Choose HTTP only when your host exposes a local Varnish HTTP purge listener. Choose Admin when your host provides the Varnish admin secret/socket.", 'ultracache'),
					value: form.varnishCliMode || 'http',
					onChange: (value) => onFieldChange('varnishCliMode', value),
					disabled: busy,
					options: [
						{ value: 'http', label: __("HTTP frontend endpoint", 'ultracache') },
						{ value: 'admin', label: __("Admin secret", 'ultracache') },
					],
					key: 'mode',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin endpoints' : 'HTTP endpoints',
					description: isAdminMode ? 'Space-separated Varnish admin endpoints in host:port format. Example: 127.0.0.1:6082' : 'Space-separated Varnish HTTP listener endpoints in host:port format. Examples: 127.0.0.1:82, varnish.internal:82, or varnish.example.com:8080. Public frontend endpoints such as domain.com:443 are blocked.',
					value: form.varnishCliServers || '',
					onChange: (value) => onFieldChange('varnishCliServers', value),
					disabled: busy,
					placeholder: isAdminMode ? '127.0.0.1:6082' : '127.0.0.1:82',
					key: 'servers',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin secret' : 'HTTP token / control key',
					description: isAdminMode ? (secretConfigured ? 'A saved Varnish admin secret exists. Leave blank to keep it, or enter a new one to replace it. The secret is never displayed.' : 'Shared secret used to authenticate against the Varnish admin interface. The secret is never displayed after saving.') : (form.varnishCliKeyConfigured ? 'A saved HTTP token exists. Leave blank to keep it, or enter a new one to replace it.' : 'Optional token sent as the X-UltraCache-Token header with Varnish HTTP requests.'),
					value: form.varnishCliKey || '',
					onChange: (value) => onFieldChange('varnishCliKey', value),
					disabled: busy,
					placeholder: isAdminMode ? 'varnish-secret' : 'your-secret-key',
					key: 'key',
				}),
				h(SelectField, {
					label: __("Command type", 'ultracache'),
					description: isAdminMode ? 'Admin mode uses the Varnish admin interface. BAN is the effective action even if you change this selector.' : 'BAN is safer across most builds. PURGE sends PURGE only; choose BAN if your Varnish setup does not explicitly support PURGE.',
					value: form.varnishCliMethod || 'BAN',
					onChange: (value) => onFieldChange('varnishCliMethod', value),
					disabled: busy,
					options: isAdminMode ? [{ value: 'BAN', label: 'BAN' }] : [
						{ value: 'BAN', label: 'BAN' },
						{ value: 'PURGE', label: 'PURGE' },
					],
					key: 'method',
				}),
				h(NumberRow, {
					label: __("Timeout (seconds)", 'ultracache'),
					description: isAdminMode ? 'Connection and read timeout for each Varnish admin endpoint. Maximum: 15 seconds.' : 'Connection and read timeout for each Varnish HTTP endpoint. Maximum: 15 seconds.',
					value: form.varnishCliTimeoutSeconds || 2,
					onChange: (value) => onFieldChange('varnishCliTimeoutSeconds', value),
					disabled: busy,
					min: 1,
					max: 15,
					step: 1,
					key: 'timeout',
				}),
			]),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: onTest, disabled: busy || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : 'Test Selected Varnish Mode'),
				h(Button, { onClick: onFlushAll, disabled: busy || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : 'Flush Varnish for This Site'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Support", 'ultracache')),
						h(StatusPill, { ok: !!varnish.available, text: varnish.available ? 'Available' : 'Unavailable', tone: varnish.available ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Configured mode", 'ultracache')),
						h(StatusPill, { ok: true, text: modeLabel, tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Effective purge method", 'ultracache')),
						h(StatusPill, { ok: true, text: effectiveMethod, tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Configured endpoints", 'ultracache')),
						h(StatusPill, { ok: endpointCount > 0, text: endpointCount > 0 ? (endpointCount + ' endpoint(s)') : '—', tone: endpointCount > 0 ? 'neutral' : 'warning' }),
					]),
					isAdminMode ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Admin secret", 'ultracache')),
						h(StatusPill, { ok: secretConfigured, text: secretConfigured ? 'Configured' : 'Missing', tone: secretConfigured ? 'success' : 'warning' }),
					]) : h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Admin-secret mode", 'ultracache')),
						h(StatusPill, { ok: false, text: __("Not used", 'ultracache'), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("HTTP endpoint mode", 'ultracache')),
						h(StatusPill, { ok: !isAdminMode, text: isAdminMode ? 'Not used' : 'Used', tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last result", 'ultracache')),
						h(StatusPill, { ok: !!last.success, text: last.message || 'No Varnish action yet', tone: last.message ? (!!last.success ? 'success' : 'warning') : 'neutral' }),
					]),
				]),
				supportMessage ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, supportMessage) : null,
				last.time ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, 'Last run: ' + formatLooseTime(last.time)) : null,
				detailLines ? h('div', { className: 'text-xs text-zinc-400 mt-3 whitespace-pre-wrap break-all' }, detailLines) : null,
			]),
			h('div', { className: 'mt-5 space-y-2' }, [
				isAdminMode ? h('p', { className: 'uc-stat-label mt-1 mb-0' }, __("Security warning: Varnish admin mode uses a plain TCP socket. Local/private endpoints are safest, but external infrastructure endpoints are allowed when intentionally configured and firewalled. The saved secret is never shown in diagnostics or REST settings.", 'ultracache')) : null,
				h('p', { className: 'uc-stat-label mt-1 mb-0' }, isAdminMode ? 'Current mode: admin-secret. HTTP endpoint tests are not used, but HTTP mode remains available for other servers that expose a local Varnish frontend purge listener.' : 'Current mode: HTTP endpoint. External Varnish infrastructure and custom ports are supported when intentionally configured. Admin-secret mode remains available for hosts that expose the Varnish admin socket and shared secret.'),
			]),
		]);
	}



	function OPcacheCard({ stats, busy, onFlush }) {
		const opcache = stats && stats.opcache ? stats.opcache : {};
		const isAvailable = !!opcache.available;
		const isEnabled = !!opcache.enabled;
		if (!isAvailable || !isEnabled) {
			return null;
		}
		const statusText = !isAvailable ? 'Unavailable' : (isEnabled ? 'Enabled' : 'Disabled');
		const rows = [
			['Status', statusText],
			['Used memory', opcache.memoryUsedHuman || '—'],
			['Free memory', opcache.memoryFreeHuman || '—'],
			['Wasted memory', opcache.memoryWastedHuman || '—'],
			['Interned strings used', opcache.internedUsedHuman || '—'],
			['Interned strings free', opcache.internedFreeHuman || '—'],
			['Cached scripts', typeof opcache.cachedScripts !== 'undefined' ? formatNumber(opcache.cachedScripts) : '—'],
			['Cached keys', typeof opcache.cachedKeys !== 'undefined' ? (formatNumber(opcache.cachedKeys) + (opcache.maxCachedKeys ? ' / ' + formatNumber(opcache.maxCachedKeys) : '')) : '—'],
			['Hit rate', typeof opcache.hitRate !== 'undefined' ? formatPercent(opcache.hitRate) : '—'],
			['Last restart', opcache.lastRestartTimeHuman || 'Never'],
			['Last flush', opcache.lastFlushTimeHuman || 'Never'],
		];

		return h(Card, {
			title: 'OPcache',
			description: __("Production-safe visibility into PHP OPcache memory usage, hit rate, and restart state, with a manual flush control for post-deployment opcode invalidation when application code changes.", 'ultracache'),
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush OPcache'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				(opcache.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, opcache.message) : null),
			]),
		]);
	}

	function APCuCard({ stats, settings, busy, onFlush, onToggleScheduledCleanup }) {
		const apcu = stats && stats.apcu ? stats.apcu : {};
		const isAvailable = !!apcu.available;
		const isEnabled = !!apcu.enabled;
		if (!isAvailable || !isEnabled) {
			return null;
		}
		const statusText = !isAvailable ? 'Unavailable' : (isEnabled ? 'Enabled' : 'Disabled');
		const objectCacheStatus = stats && stats.objectCache ? stats.objectCache : {};
		const diagnosticsObjectCacheStatus = stats && stats.diagnostics && stats.diagnostics.objectCache ? stats.diagnostics.objectCache : {};
		const normalizeBackendValue = (value) => {
			value = String(value || '').toLowerCase().trim();
			return ['redis', 'apcu', 'disk', 'runtime'].indexOf(value) !== -1 ? value : '';
		};
		const firstBackendValue = (values) => {
			for (let i = 0; i < values.length; i++) {
				const normalized = normalizeBackendValue(values[i]);
				if (normalized) {
					return normalized;
				}
			}
			return '';
		};
		const activeObjectBackend = firstBackendValue([
			objectCacheStatus.activeBackend,
			diagnosticsObjectCacheStatus.activeBackend,
			stats && stats.objectCacheActiveBackend,
			stats && stats.objectCacheStatsSource,
			objectCacheStatus.activeFallbackBackend,
			diagnosticsObjectCacheStatus.activeFallbackBackend,
			stats && stats.objectCacheBackend,
		]);
		const fallbackObjectBackend = firstBackendValue([
			objectCacheStatus.activeFallbackBackend,
			diagnosticsObjectCacheStatus.activeFallbackBackend,
			objectCacheStatus.fallbackBackend,
			diagnosticsObjectCacheStatus.fallbackBackend,
		]);
		const selectedObjectBackend = firstBackendValue([
			objectCacheStatus.selectedBackend,
			diagnosticsObjectCacheStatus.selectedBackend,
			stats && stats.objectCacheBackend,
			settings && settings.objectCacheBackend,
		]);
		const fallbackActive = !!(
			objectCacheStatus.fallbackActive ||
			diagnosticsObjectCacheStatus.fallbackActive ||
			(stats && stats.objectCacheFallbackActive)
		);
		const apcuIsActiveObjectBackend = activeObjectBackend === 'apcu' || (fallbackActive && fallbackObjectBackend === 'apcu') || (!activeObjectBackend && selectedObjectBackend === 'apcu' && settings && settings.objectCacheEnabled);
		const memoryUsage = typeof apcu.memoryUsagePercent !== 'undefined' ? Number(apcu.memoryUsagePercent) : 0;
		const apcuWarnings = [];
		if (memoryUsage >= 85) {
			apcuWarnings.push(apcuIsActiveObjectBackend
				? 'APCu memory usage is above 85%. Consider increasing apc.shm_size if object-cache hit rate drops or expunges increase.'
				: 'APCu memory usage is above 85%. APCu is not the active object-cache backend right now, so treat this as an analytics/shared-memory advisory.');
		}
		if (Number(apcu.expunges || 0) > 0) {
			apcuWarnings.push(apcuIsActiveObjectBackend
				? 'APCu has expunges. This means entries were cleared to make room for new object-cache writes.'
				: 'APCu has expunges. Since APCu is not the active object-cache backend, this may affect analytics/shared-memory entries rather than persistent object caching.');
		}
		const usageText = typeof apcu.memoryUsagePercent !== 'undefined' ? formatPercent(apcu.memoryUsagePercent) : '—';
		const rows = [
			['Status', statusText],
			['Used memory', apcu.memoryUsedHuman || '—'],
			['Free memory', apcu.memoryFreeHuman || '—'],
			['Total memory', apcu.memoryTotalHuman || '—'],
			['Memory usage', usageText],
			['Cached entries', typeof apcu.cachedEntries !== 'undefined' ? formatNumber(apcu.cachedEntries) : '—'],
			['Hit rate', typeof apcu.hitRate !== 'undefined' ? formatPercent(apcu.hitRate) : '—'],
			['Hits / Misses', (typeof apcu.hits !== 'undefined' ? formatNumber(apcu.hits) : '—') + ' / ' + (typeof apcu.misses !== 'undefined' ? formatNumber(apcu.misses) : '—')],
			['Inserts', typeof apcu.inserts !== 'undefined' ? formatNumber(apcu.inserts) : '—'],
			['Expunges', typeof apcu.expunges !== 'undefined' ? formatNumber(apcu.expunges) : '—'],
			['Started', apcu.startTimeHuman || '—'],
		];

		return h(Card, {
			title: 'APCu',
			description: __("Local shared-memory cache used for lightweight hit analytics and as the safe local object-cache fallback when Redis is unavailable.", 'ultracache'),
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush APCu Cache'),
			]),
			h('div', { className: 'mt-4' }, [
				h(ToggleField, {
					label: __("Include APCu Flush on Scheduled Cache Cleanup", 'ultracache'),
					description: __("Warning: this clears the whole APCu user cache for this PHP runtime, including entries used by other plugins/apps in the same PHP-FPM context.", 'ultracache'),
					checked: !!(settings && settings.apcuFlushOnScheduledCleanup),
					onChange: onToggleScheduledCleanup,
					disabled: busy,
				}),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				(apcu.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, apcu.message) : null),
				apcuWarnings.length ? h('div', { className: 'space-y-2 mt-3' }, apcuWarnings.map((warning, index) => h('div', { className: 'text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'apcu-warning-' + index }, warning))) : null,
			]),
		]);
	}


	function getExternalCacheLayer(stats, key) {
		const detection = stats && stats.externalCaches ? stats.externalCaches : {};
		const layers = detection && detection.layers ? detection.layers : {};
		return layers && layers[key] ? layers[key] : {};
	}

	function ExternalCacheCard({ title, description, layer, busy, onFlush }) {
		layer = layer || {};
		if (!layer.detected || !layer.flushable) {
			return null;
		}
		const rows = [
			['Status', layer.enabled ? 'Detected' : 'Detected'],
			['Flushable', layer.flushable ? 'Yes' : 'No'],
			['Method', layer.method || '—'],
		];
		return h(Card, { title: title, description: description }, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !layer.flushable, variant: 'primary' }, busy ? 'Working…' : ('Flush ' + title)),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Detection", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				layer.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, layer.message) : null,
			]),
		]);
	}

	function ExternalCacheFlushSettingsCard({ stats, diagnostics, settings, busy, onRedetect, onToggle }) {
		const detection = stats && stats.externalCaches ? stats.externalCaches : {};
		const layers = detection && detection.layers ? detection.layers : {};
		const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : {};
		const varnishDiagnostic = diagnostics && diagnostics.varnish ? diagnostics.varnish : {};
		const varnishLayer = layers && layers.varnish ? layers.varnish : {};
		const reverseProxyTextForVarnish = [
			reverseProxy.provider,
			reverseProxy.server,
			reverseProxy.via,
			reverseProxy.x_cache,
			reverseProxy.x_cache_status,
			reverseProxy.message,
		].join(' ').toLowerCase();
		const reverseProxyLooksLikeVarnish = !!(reverseProxy && reverseProxy.detected && reverseProxyTextForVarnish.indexOf('varnish') !== -1);
		const varnishConfigured = !!(
			(settings && (settings.varnishCliEnabled || settings.varnishCliServers || settings.flushAllIncludeVarnish)) ||
			(varnishDiagnostic && (varnishDiagnostic.enabled || varnishDiagnostic.available || varnishDiagnostic.servers || varnishDiagnostic.endpointCount || varnishDiagnostic.secretConfigured))
		);
		const showVarnishCandidate = !!((varnishLayer && varnishLayer.detected) || reverseProxyLooksLikeVarnish || varnishConfigured);
		const candidates = [
			{ key: 'opcache', setting: 'flushAllIncludeOpcache', label: 'OPcache', description: __("Also reset PHP OPcache when Flush All Cache runs.", 'ultracache') },
			{ key: 'apcu', setting: 'flushAllIncludeApcu', label: 'APCu', description: __("Also clear the APCu user cache when Flush All Cache runs.", 'ultracache') },
			{ key: 'litespeed', setting: 'flushAllIncludeLiteSpeed', label: __("LiteSpeed Cache", 'ultracache'), description: __("Also purge LiteSpeed Cache when Flush All Cache runs. Uses the LiteSpeed plugin API when present, otherwise the server-level X-LiteSpeed-Purge response header.", 'ultracache') },
			{ key: 'nginx', setting: 'flushAllIncludeNginx', label: __("Nginx Cache", 'ultracache'), description: __("Also trigger the detected Nginx Helper purge hook when Flush All Cache runs.", 'ultracache') },
			{ key: 'varnish', setting: 'flushAllIncludeVarnish', label: __("Varnish Cache", 'ultracache'), description: __("Also flush the configured UltraCache Varnish endpoint when Flush All Cache runs.", 'ultracache') },
		];
		const visible = candidates.filter((item) => {
			const layer = layers[item.key] || {};
			if (item.key === 'varnish') {
				return showVarnishCandidate;
			}
			return !!(layer.detected && layer.flushable);
		});
		const renderCandidate = (item) => {
			const layer = layers[item.key] || {};
			const isVarnish = item.key === 'varnish';
			const flushable = !!layer.flushable;
			const disabled = !!busy || (isVarnish && !flushable);
			let description = item.description;
			if (isVarnish && !flushable) {
				description = 'Varnish is detected or configured, but UltraCache Varnish purge is currently disabled or not flushable. Enable and test the Varnish purge integration before including it in Flush All Cache.';
			}
			return h(ToggleRow, {
				label: 'Also flush ' + item.label,
				description: description,
				checked: !!(settings && settings[item.setting]),
				onChange: (value) => onToggle(item.setting, value),
				disabled: disabled,
				key: item.setting,
			});
		};
		return h(Card, {
			title: __("External Cache Flush", 'ultracache'),
			description: __("Also empty detected external/server cache layers with each Flush All Cache. Detected or configured Varnish is shown even when purge integration still needs to be enabled/tested.", 'ultracache'),
		}, [
			h('div', { className: 'flex flex-wrap items-center justify-between gap-3 mt-2' }, [
				h('div', { className: 'text-xs text-zinc-500', key: 'detected-at' }, detection.detectedAtHuman ? ('Last detected: ' + detection.detectedAtHuman) : 'No cache detection result saved yet.'),
				h(Button, { onClick: onRedetect, disabled: busy, variant: 'light', key: 'redetect' }, busy ? 'Working…' : 'Redetect Caches'),
			]),
			visible.length ? h('div', { className: 'mt-4 divide-y divide-white/5' }, visible.map(renderCandidate)) : h('div', { className: 'text-xs text-zinc-500 mt-4' }, __("No external/server cache layer with a safe flush mechanism is detected. Use Redetect Caches after enabling OPcache/APCu, Varnish settings, Nginx Helper, or after confirming a LiteSpeed/OpenLiteSpeed cache layer.", 'ultracache')),
		]);
	}

	function RedisCard({ form, diagnostics, busy, objectCacheEnabled, onObjectCacheEnabledChange, onFieldChange, onSave, onTest, onFlush, onRemoveConflictingDropins, onRecheckConflicts }) {
		const objectCache = diagnostics.objectCache || {};
		const redis = objectCache.redis || {};
		const legacyConflicts = diagnostics.legacyCacheConflicts || {};
		const normalizeBackendChoice = (value) => {
			value = String(value || '').toLowerCase();
			return ['redis', 'apcu', 'disk'].indexOf(value) !== -1 ? value : 'redis';
		};
		const backend = normalizeBackendChoice(form.objectCacheBackend || objectCache.selectedBackend || 'redis');
		const fallbackPolicy = form.objectCacheFallbackBackend || objectCache.configuredFallbackBackend || 'apcu';
		const selectedBackend = backend;
		const activeBackend = objectCache.activeBackend || selectedBackend;
		const fallbackActive = !!objectCache.fallbackActive;
		const fallbackBackend = objectCache.fallbackBackend || (fallbackActive ? activeBackend : ('none' === fallbackPolicy ? 'runtime' : fallbackPolicy));
		const apcu = objectCache.apcu || {};
		const backendLabel = (value) => value === 'redis' ? 'Redis' : (value === 'apcu' ? 'APCu' : (value === 'disk' ? 'Disk' : (value === 'runtime' ? 'Runtime-only' : String(value || 'Unavailable'))));
		const rawRedisDropinError = redis.dropinError || (objectCache.backendStatus && objectCache.backendStatus.redis && objectCache.backendStatus.redis.error) || '';
		const isRedisPayloadGuardMessage = (message) => /^Redis payload (rejected|skipped):/i.test(String(message || ''));
		const redisPayloadSkipReason = redis.payloadSkipReason || (objectCache.backendStatus && objectCache.backendStatus.redis && objectCache.backendStatus.redis.payloadSkipReason) || (isRedisPayloadGuardMessage(rawRedisDropinError) ? rawRedisDropinError.replace(/^Redis payload rejected:/i, 'Redis payload skipped:') : '');
		const redisDropinError = isRedisPayloadGuardMessage(rawRedisDropinError) ? '' : rawRedisDropinError;
		const fallbackMessage = objectCache.fallbackMessage || (fallbackActive ? (backendLabel(selectedBackend) + ' selected, ' + backendLabel(fallbackBackend) + ' fallback active.' + (redisDropinError ? ' Reason: ' + redisDropinError : '')) : '');
		const redisSupportText = redis.available ? 'PHP Redis extension detected on this server.' : (redis.message || 'PHP Redis extension not detected. APCu will be used when available; otherwise object cache is runtime-only.');
		const redisManualTestKnown = typeof redis.connected !== 'undefined' || typeof redis.readWrite !== 'undefined' || typeof redis.success !== 'undefined';
		const redisManualTestText = redisManualTestKnown
			? (redis.readWrite ? 'Read/write probe passed' : (redis.connected ? 'Connected; read/write not confirmed' : (redis.message || 'Test failed')))
			: '';
		const manualPayloadProbe = objectCache.manualPayloadProbe || redis.payloadProbe || {};
		const manualPayloadProbeKnown = !!manualPayloadProbe && typeof manualPayloadProbe === 'object' && (typeof manualPayloadProbe.success !== 'undefined' || !!manualPayloadProbe.message);
		const manualPayloadProbeText = manualPayloadProbeKnown
			? (manualPayloadProbe.success ? ('Passed' + (manualPayloadProbe.safeProbeBytes && manualPayloadProbe.payloadLimitBytes ? (' · tested ' + formatBytes(manualPayloadProbe.safeProbeBytes) + ' / limit ' + formatBytes(manualPayloadProbe.payloadLimitBytes)) : '')) : (manualPayloadProbe.message || 'Failed'))
			: '';
		const showApcuSupport = backend === 'apcu' || (fallbackActive && activeBackend === 'apcu');
		const apcuFallbackUnavailable = !fallbackActive && 'apcu' === fallbackPolicy && apcu && apcu.available === false;
		const dropinInstallable = typeof objectCache.dropinInstallable === 'undefined' ? !!objectCache.available : !!objectCache.dropinInstallable;
		const selectedBackendSupported = typeof objectCache.selectedBackendSupported === 'undefined'
			? (selectedBackend === 'redis' ? !!redis.available : (selectedBackend === 'apcu' ? !!apcu.available : true))
			: !!objectCache.selectedBackendSupported;
		const fallbackStatusText = fallbackActive
			? (backendLabel(fallbackBackend) + ' active')
			: (apcuFallbackUnavailable ? 'APCu unavailable · runtime-only final fallback' : ('none' === fallbackPolicy ? 'None / runtime-only' : backendLabel(fallbackPolicy) + ' standby'));
		const testButtonLabel = 'redis' === backend ? 'Test Redis Connection' : ('apcu' === backend ? 'Test APCu Connection' : 'Test Disk Object Cache');
		const flushButtonLabel = 'redis' === backend ? 'Flush Redis Object Cache' : ('apcu' === backend ? 'Flush APCu Object Cache' : 'Flush Disk Object Cache');
		const transportText = [form.redisUseTls ? 'TLS' : 'TCP', form.redisPersistent ? 'Persistent connections ON' : 'Persistent connections OFF'].join(' · ');
		const statusText = objectCache.active
			? ('Active backend: ' + backendLabel(activeBackend) + (fallbackActive ? ' fallback' : ''))
			: (objectCache.enabled ? ('Configured backend: ' + backendLabel(selectedBackend)) : 'Object cache is disabled.');
		const backendChoices = [
			{ value: 'redis', label: 'Redis', description: __("Recommended production backend. Fallback behavior is controlled by the Object Cache Fallback dropdown below.", 'ultracache') },
			{ value: 'apcu', label: 'APCu', description: __("Local memory backend for single-server sites. APCu is cleared on PHP-FPM restart.", 'ultracache') },
			{ value: 'disk', label: 'Disk', description: __("Advanced/debug only. Not recommended for production because it can create many small files.", 'ultracache') },
		];
		const renderBackendChoice = (choice) => {
			const selected = backend === choice.value;
			return h('div', { className: 'uc-object-cache-backend-choice', key: 'backend-column-' + choice.value }, [
				h('button', {
					type: 'button',
					className: classNames(
						'uc-btn uc-object-cache-backend-button w-full py-3 font-bold text-white',
						selected ? 'uc-btn--primary' : '',
						busy ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'
					),
					disabled: !!busy,
					'aria-pressed': selected ? 'true' : 'false',
					onClick: () => {
						if (!busy && !selected) {
							onFieldChange('objectCacheBackend', choice.value);
						}
					},
					key: 'backend-button-' + choice.value,
				}, choice.label),
				h('p', { className: 'uc-object-cache-backend-description m-0 text-xs text-zinc-500 leading-relaxed', key: 'backend-description-' + choice.value }, choice.description),
			]);
		};
				return h(Card, {
			title: __("Object Cache", 'ultracache'),
			description: 'Enable the WordPress object-cache.php drop-in. The selected backend and the active runtime backend are shown separately so Redis/APCu/runtime fallbacks are visible.',
		}, [
			h(ToggleField, {
				label: __("Enable Object Cache", 'ultracache'),
				description: 'Enable the WordPress object-cache.php drop-in. Configure the primary backend and fallback policy below.',
				checked: !!objectCacheEnabled,
				onChange: onObjectCacheEnabledChange,
				disabled: busy,
				key: 'object-cache-enabled',
			}),
			h(CacheHelperConflictNotice, { diagnostics, busy, onRemove: onRemoveConflictingDropins, onRecheck: onRecheckConflicts }),
			h('div', { className: 'objectcache uc-object-cache-backend-grid mt-4', role: 'group', 'aria-label': __("Object Cache backend selector", 'ultracache') }, backendChoices.map(renderBackendChoice)),
			backend === 'apcu' ? h('div', { className: 'mt-4 text-xs text-zinc-500' }, __("APCu has no connection credentials; use the test button to verify that the PHP APCu extension is available for the frontend runtime.", 'ultracache')) : null,
			backend === 'disk' ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Disk object cache is advanced/debug only and may add filesystem I/O, but it will be used if configured or if fallback activates.", 'ultracache')) : null,
			h('div', { className: 'mt-4 flex items-center justify-between gap-4 py-4' }, [
				h('div', { className: 'min-w-0 pr-4' }, [
					h('div', { className: 'uc-field-label' }, __("Object Cache Fallback", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-500 mt-1' }, __("Used only when the selected backend cannot connect or is unavailable. Runtime-only cache is always the final emergency fallback.", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-400 mt-1' }, 'Fallback policy: ' + ('none' === fallbackPolicy ? 'None / runtime-only' : backendLabel(fallbackPolicy)) + '. Fallback status: ' + fallbackStatusText + '.'),
					'disk' === fallbackPolicy ? h('div', { className: 'text-xs text-amber-300 mt-1' }, __("Disk fallback is advanced/debug only and may add filesystem I/O.", 'ultracache')) : null,
				]),
				h('div', { className: 'uc-select-wrap shrink-0 w-56 max-w-full' }, [
					h('select', {
						className: 'uc-field-input uc-field-select',
						value: fallbackPolicy,
						disabled: !!busy,
						onChange: (e) => onFieldChange('objectCacheFallbackBackend', e.target.value),
					}, [
						h('option', { value: 'none', key: 'none' }, __("None / runtime-only", 'ultracache')),
						h('option', { value: 'apcu', key: 'apcu' }, 'APCu'),
						h('option', { value: 'disk', key: 'disk' }, __("Disk (advanced/debug)", 'ultracache')),
					]),
					h('span', { className: 'uc-select-icon', 'aria-hidden': true }, '▾'),
				]),
			]),
			fallbackActive ? h('div', { className: 'mt-4 text-xs text-zinc-500' }, fallbackMessage) : null,
			backend === 'redis' ? h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(TextRow, {
					label: __("Redis host", 'ultracache'),
					description: __("Common default: 127.0.0.1. External Redis hosts are supported when intentionally configured.", 'ultracache'),
					value: form.redisHost || '127.0.0.1',
					onChange: (value) => onFieldChange('redisHost', value),
					disabled: busy,
					placeholder: '127.0.0.1',
					key: 'redis-host',
				}),
				h(NumberRow, {
					label: __("Redis port", 'ultracache'),
					description: __("Common default: 6379. Custom Redis ports are supported.", 'ultracache'),
					value: form.redisPort || 6379,
					onChange: (value) => onFieldChange('redisPort', value),
					disabled: busy,
					min: 1,
					step: 1,
					key: 'redis-port',
				}),
				h(TextRow, {
					label: __("Redis username", 'ultracache'),
					description: __("Optional. Required only for Redis ACL users. Leave empty for password-only Redis.", 'ultracache'),
					value: form.redisUsername || '',
					onChange: (value) => onFieldChange('redisUsername', value),
					disabled: busy,
					placeholder: 'optional',
					autoComplete: 'off',
					key: 'redis-username',
				}),
				h(TextRow, {
					label: __("Redis password", 'ultracache'),
					description: form.redisPasswordConfigured ? 'A saved Redis password already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Leave empty when the server does not require auth.',
					value: form.redisPassword || '',
					onChange: (value) => onFieldChange('redisPassword', value),
					disabled: busy,
					placeholder: 'optional',
					type: 'password',
					autoComplete: 'new-password',
					key: 'redis-password',
				}),
				h(NumberRow, {
					label: __("Redis database", 'ultracache'),
					description: __("Usually 0. Typical range: 0-15.", 'ultracache'),
					value: typeof form.redisDatabase === 'undefined' ? 0 : form.redisDatabase,
					onChange: (value) => onFieldChange('redisDatabase', value),
					disabled: busy,
					min: 0,
					step: 1,
					key: 'redis-db',
				}),
				h(TextRow, {
					label: __("Redis prefix / namespace", 'ultracache'),
					description: __("Optional. Leave blank for automatic site-specific prefix.", 'ultracache'),
					value: form.redisPrefix || '',
					onChange: (value) => onFieldChange('redisPrefix', value),
					disabled: busy,
					placeholder: __("leave blank for auto", 'ultracache'),
					key: 'redis-prefix',
				}),
				h(NumberRow, {
					label: __("Connect timeout (ms)", 'ultracache'),
					description: __("Advanced. Default: 200ms. Maximum: 15000ms.", 'ultracache'),
					value: typeof form.redisConnectTimeoutMs === 'undefined' ? 200 : form.redisConnectTimeoutMs,
					onChange: (value) => onFieldChange('redisConnectTimeoutMs', value),
					disabled: busy,
					min: 50,
					max: 15000,
					step: 50,
					key: 'redis-connect-timeout',
				}),
				h(NumberRow, {
					label: __("Read timeout (ms)", 'ultracache'),
					description: __("Advanced. Default: 200ms. Maximum: 15000ms.", 'ultracache'),
					value: typeof form.redisReadTimeoutMs === 'undefined' ? 200 : form.redisReadTimeoutMs,
					onChange: (value) => onFieldChange('redisReadTimeoutMs', value),
					disabled: busy,
					min: 50,
					max: 15000,
					step: 50,
					key: 'redis-read-timeout',
				}),
				h(ToggleField, {
					label: __("Persistent connection", 'ultracache'),
					description: __("Advanced. Saves immediately. Reuse the Redis connection across PHP worker requests when supported.", 'ultracache'),
					checked: !!form.redisPersistent,
					onChange: (value) => onFieldChange('redisPersistent', value),
					disabled: busy,
					key: 'redis-persistent',
				}),
				h(ToggleField, {
					label: __("Use TLS", 'ultracache'),
					description: __("Saves immediately. Enable for managed Redis providers that require TLS/SSL transport.", 'ultracache'),
					checked: !!form.redisUseTls,
					onChange: (value) => onFieldChange('redisUseTls', value),
					disabled: busy,
					key: 'redis-use-tls',
				}),
			]) : null,
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				backend === 'redis' ? h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Redis Settings') : null,
				h(Button, { onClick: onTest, disabled: busy, variant: 'primary' }, busy ? 'Working…' : testButtonLabel),
				h(Button, { onClick: onFlush, disabled: busy, variant: 'primary' }, busy ? 'Working…' : flushButtonLabel),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, [
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Drop-in installable", 'ultracache')),
							h(StatusPill, { ok: dropinInstallable, text: dropinInstallable ? 'Yes' : 'No', tone: dropinInstallable ? 'success' : 'warning' }),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Configured backend", 'ultracache')),
							h(StatusPill, {
								ok: selectedBackendSupported,
								text: backendLabel(selectedBackend),
								tone: selectedBackend === 'disk' ? 'warning' : (selectedBackendSupported ? 'success' : 'warning')
							}),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Active backend", 'ultracache')),
							h(StatusPill, { ok: !!objectCache.active, text: objectCache.active ? backendLabel(activeBackend) : (objectCache.enabled ? 'Drop-in inactive' : 'Disabled'), tone: fallbackActive ? 'warning' : (objectCache.active ? 'success' : 'neutral') }),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Fallback status", 'ultracache')),
							h(StatusPill, { ok: !fallbackActive, text: fallbackStatusText, tone: fallbackActive ? 'warning' : 'neutral' }),
						]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Redis support", 'ultracache')),
						h(StatusPill, { ok: !!redis.available, text: redis.available ? 'Available' : 'Unavailable', tone: redis.available ? 'success' : 'warning' }),
					]),
						showApcuSupport ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("APCu support", 'ultracache')),
						h(StatusPill, { ok: !!apcu.available, text: apcu.available ? 'Available' : 'Unavailable in this runtime', tone: apcu.available ? 'success' : 'neutral' }),
					]) : null,
						backend === 'redis' && redisManualTestKnown ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Redis manual test", 'ultracache')),
							h(StatusPill, { ok: !!redis.readWrite || !!redis.connected || !!redis.success, text: redisManualTestText, tone: (!!redis.readWrite || !!redis.success) ? 'success' : 'warning' }),
						]) : null,
						manualPayloadProbeKnown ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Object payload probe", 'ultracache')),
							h(StatusPill, { ok: !!manualPayloadProbe.success, text: manualPayloadProbeText, tone: manualPayloadProbe.success ? 'success' : 'warning' }),
						]) : null,
						redisDropinError ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, __("Redis drop-in error", 'ultracache')),
							h('div', { className: 'text-xs text-amber-300 text-right break-all max-w-xl' }, redisDropinError),
						]) : null,
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Runtime status", 'ultracache')),
						h(StatusPill, { ok: !!objectCache.active, text: statusText, tone: objectCache.active ? 'success' : 'neutral' }),
					]),
					backend === 'redis' ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Redis prefix", 'ultracache')),
						h('code', { className: 'text-xs text-zinc-300 break-all' }, redis.prefix || 'auto'),
					]) : null,
				]),
				backend === 'redis' ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, redisSupportText) : null,
			]),
		]);
	}


	function FontPipelineDiagnosticsPanel({ diagnostics }) {
		const fontDiag = diagnostics && diagnostics.fontPipeline ? diagnostics.fontPipeline : {};
		const fontCss = fontDiag.fontCss || {};
		const bundles = fontDiag.cssBundles || {};
		const optimizedCss = fontDiag.optimizedCss || {};
		const google = fontDiag.googleFontsLocal || {};
		const cfg = fontDiag.settings || {};
		const hasMissing = (bundles.missingBundleFiles || 0) > 0 || (bundles.missingDelayedFontFiles || 0) > 0;
		const settingLine = [
			cfg.selfHostedFontCssOptimizationEnabled ? 'Self-hosted CSS ON' : 'Self-hosted CSS OFF',
			cfg.selfHostedFontRuntimeRewriteEnabled ? 'Runtime rewrite ON' : 'Runtime rewrite OFF',
			cfg.delayIconFontsEnabled ? 'Icon delay ON' : 'Icon delay OFF',
			cfg.cssBundlingEnabled ? ('CSS bundle ' + (cfg.cssBundleScope || 'on')) : 'CSS bundle OFF',
		].join(' · ');

		return h('div', { className: 'uc-diagnostic-group', key: 'font-pipeline-diagnostics-lite' }, [
			h('div', { className: 'uc-section-title', key: 'title' }, __("Font Pipeline Diagnostics", 'ultracache')),
			h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'description' }, __("Read-only summary for local font CSS, delayed icon-font CSS, and bundle font metadata.", 'ultracache')),
			h('div', { className: 'space-y-3', key: 'rows' }, [
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'status' }, [
					h('div', { className: 'text-sm text-white' }, __("Status", 'ultracache')),
					h(StatusPill, { ok: !hasMissing, text: hasMissing ? 'Needs attention' : 'OK', tone: hasMissing ? 'warning' : 'success' }),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'settings' }, [
					h('div', { className: 'text-sm text-white' }, __("Settings", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right break-all' }, settingLine),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'font-css' }, [
					h('div', { className: 'text-sm text-white' }, 'font-css'),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(fontCss.files || 0) + ' file(s) · ' + formatBytes(fontCss.bytes || 0) + ' · Delayed: ' + String(fontCss.delayedFiles || 0) + ' · ' + formatBytes(fontCss.delayedBytes || 0)),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'bundle-fonts' }, [
					h('div', { className: 'text-sm text-white' }, __("Bundle font metadata", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(bundles.entriesWithDelayedFonts || 0) + '/' + String(bundles.manifestEntries || 0) + ' delayed entries · Font-face blocks: ' + String(bundles.delayedFontFaceBlocks || 0)),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'google-imports' }, [
					h('div', { className: 'text-sm text-white' }, __("Google @import rewrites", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(optimizedCss.localGoogleImportRules || 0) + ' local · ' + String(optimizedCss.remoteGoogleImportRules || 0) + ' remote · ' + String(optimizedCss.filesWithGoogleImportRules || 0) + ' file(s)'),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'google-local' }, [
					h('div', { className: 'text-sm text-white' }, __("Local Google Fonts", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(google.cssFiles || 0) + ' CSS · ' + String(google.fontFilesOrAssets || 0) + ' asset(s) · ' + formatBytes((google.cssBytes || 0) + (google.fontBytesOrAssetBytes || 0))),
				]),
			]),
			hasMissing ? h('div', { className: 'text-xs text-amber-300 pt-1', key: 'missing' }, 'Missing bundle refs detected: main=' + String(bundles.missingBundleFiles || 0) + ', delayed-font=' + String(bundles.missingDelayedFontFiles || 0) + '.') : null,
			bundles.delayedFontFamilies && bundles.delayedFontFamilies.length ? h('div', { className: 'mt-2 text-xs text-zinc-500 break-all', key: 'families' }, bundles.delayedFontFamilies.join(' · ')) : null,
		]);
	}


	function SettingsTransparencyPanel({ diagnostics }) {
		const diag = diagnostics && diagnostics.settingsTransparency ? diagnostics.settingsTransparency : {};
		const summary = diag.summary || {};
		const visibleLists = Array.isArray(diag.visibleLists) ? diag.visibleLists : [];
		const engineOnly = Array.isArray(diag.engineOnlySafeguards) ? diag.engineOnlySafeguards : [];
		const legacyLists = Array.isArray(diag.legacyLists) ? diag.legacyLists : [];
		const visibleCount = summary.visibleEditableLists || visibleLists.length || 0;
		const defaultCount = summary.listsWithDefaults || visibleLists.filter((item) => item && item.populateDefaultAvailable).length || 0;
		const engineCount = summary.engineOnlySafeguards || engineOnly.length || 0;
		const legacyCount = summary.legacyLists || legacyLists.length || 0;

		function renderMiniStat(label, value, tone) {
			const cls = 'rounded-lg bg-black/25 px-3 py-2';
			return h('div', { className: cls, key: label }, [
				h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, label),
				h('div', { className: tone === 'warning' ? 'font-mono text-amber-300' : 'font-mono text-zinc-200', key: 'value' }, String(value || 0)),
			]);
		}

		function renderVisibleListRow(item, index) {
			const currentCount = item && typeof item.currentCount !== 'undefined' ? item.currentCount : 0;
			const defaultCount = item && typeof item.defaultCount !== 'undefined' ? item.defaultCount : 0;
			const badge = item && item.shared ? 'shared override' : (item && item.populateDefaultAvailable ? 'has defaults' : 'manual/empty default');
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'visible-' + index + '-' + (item && item.key ? item.key : index) }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2', key: 'top' }, [
					h('span', { className: 'text-zinc-200 font-semibold', key: 'label' }, item && item.label ? item.label : 'Visible list'),
					h('span', { className: item && item.populateDefaultAvailable ? 'text-emerald-300 font-mono text-[11px]' : 'text-zinc-500 font-mono text-[11px]', key: 'badge' }, badge),
				]),
				h('div', { className: 'text-[11px] text-zinc-500 mt-1 font-mono break-all', key: 'meta' }, [
					String(item && item.key ? item.key : ''),
					' · ',
					String(item && item.area ? item.area : 'General'),
					__(" · current ", 'ultracache'),
					String(currentCount),
					__(" · default ", 'ultracache'),
					String(defaultCount),
				]),
			]);
		}

		function renderEngineSafeguard(item, index) {
			const examples = Array.isArray(item && item.examples) ? item.examples : [];
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'engine-' + index }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2', key: 'top' }, [
					h('span', { className: 'text-zinc-200 font-semibold', key: 'label' }, item && item.label ? item.label : 'Engine-only safeguard'),
					h('span', { className: 'text-sky-300 font-mono text-[11px]', key: 'badge' }, 'engine-only'),
				]),
				item && item.reason ? h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'reason' }, item.reason) : null,
				examples.length ? h('div', { className: 'mt-2 flex flex-wrap gap-1', key: 'examples' }, examples.map((example, exampleIndex) => h('code', { className: 'font-mono text-[11px] text-sky-300 bg-black/25 rounded px-2 py-1', key: 'example-' + exampleIndex }, String(example)))) : null,
			]);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' }, key: 'settings-transparency-panel' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'details' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Settings Transparency", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Read-only map of visible safeguard lists, engine-only safety floors, and reset/default coverage.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'message' }, diag.message || 'User-editable safeguards are listed separately from engine-only safety floors.'),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3', key: 'stats' }, [
						renderMiniStat('Visible editable lists', visibleCount),
						renderMiniStat('Lists with defaults', defaultCount),
						renderMiniStat('Engine-only floors', engineCount, engineCount ? 'warning' : ''),
						renderMiniStat('Legacy mapped lists', legacyCount),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500', key: 'reset' }, summary.resetUsesDashboardDefaults ? 'Reset Settings uses the dashboard defaults payload, so recommended exclusion defaults are restored during full reset.' : 'Reset defaults status is unavailable.'),
					h('div', { className: 'mt-4', key: 'visible-section' }, [
						h('div', { className: 'uc-section-title' }, __("Visible / editable safeguard lists", 'ultracache')),
						visibleLists.length ? h('div', { className: 'space-y-2 mt-2' }, visibleLists.map(renderVisibleListRow)) : h('div', { className: 'text-xs text-zinc-500' }, __("No visible list diagnostics were reported.", 'ultracache')),
					]),
					h('div', { className: 'mt-4', key: 'engine-section' }, [
						h('div', { className: 'uc-section-title' }, __("Engine-only safety floors", 'ultracache')),
						engineOnly.length ? h('div', { className: 'space-y-2 mt-2' }, engineOnly.map(renderEngineSafeguard)) : h('div', { className: 'text-xs text-zinc-500' }, __("No engine-only safeguards were reported.", 'ultracache')),
					]),
					legacyLists.length ? h('div', { className: 'mt-4', key: 'legacy-section' }, [
						h('div', { className: 'uc-section-title' }, __("Legacy mapped lists", 'ultracache')),
						h('div', { className: 'space-y-2 mt-2' }, legacyLists.map((item, index) => h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'legacy-' + index }, [
							h('div', { className: 'text-zinc-200 font-semibold', key: 'label' }, item.label || item.key || 'Legacy list'),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'message' }, item.message || ''),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1 font-mono', key: 'map' }, String(item.key || '') + ' → ' + String(item.mappedTo || '')),
						]))),
					]) : null,
				]),
			]),
		]);
	}


	function SecurityCorrectnessPanel({ diagnostics }) {
		const diag = diagnostics && diagnostics.securityCorrectness ? diagnostics.securityCorrectness : {};
		const summary = diag.summary || {};
		const engineOnly = Array.isArray(diag.engineOnlySafeguards) ? diag.engineOnlySafeguards : [];
		const missing = Array.isArray(diag.hardSensitiveQueryArgsMissingFromVisibleList) ? diag.hardSensitiveQueryArgsMissingFromVisibleList : [];
		const runtime = diag.runtimeConfigProtection || {};
		const secretFiles = diag.secretFiles || {};
		const ok = !!summary.debugContextRedactionEnabled && !!summary.secretsRedactedFromClientSettings;

		function stat(label, value, tone) {
			return h('div', { className: 'rounded-lg bg-black/25 px-3 py-2', key: label }, [
				h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, label),
				h('div', { className: tone === 'warning' ? 'font-mono text-amber-300' : 'font-mono text-zinc-200', key: 'value' }, String(value)),
			]);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' }, key: 'security-correctness-panel' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'details' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Security / Cache Correctness", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Read-only audit of cache-poisoning safeguards, secret redaction, and runtime config protection.", 'ultracache')),
					]),
					h('span', { className: ok ? 'text-emerald-300 font-mono text-[11px]' : 'text-amber-300 font-mono text-[11px]', key: 'status' }, ok ? 'Guarded' : 'Review'),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'message' }, diag.message || 'Security diagnostics are read-only.'),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3', key: 'stats' }, [
						stat('Hard query args', summary.hardSensitiveQueryArgs || 0),
						stat('Visible list missing', summary.hardSensitiveQueryArgsMissingFromVisibleList || 0, missing.length ? 'warning' : ''),
						stat('Woo safe mode', summary.woocommerceSafeModeEnabled ? 'ON' : 'OFF', summary.woocommerceSafeModeEnabled ? '' : 'warning'),
						stat('Secret redaction', summary.debugContextRedactionEnabled ? 'ON' : 'OFF', summary.debugContextRedactionEnabled ? '' : 'warning'),
					]),
					missing.length ? h('div', { className: 'mt-3 rounded-lg bg-amber-500/10 text-amber-200 text-xs px-3 py-2', key: 'missing' }, 'These sensitive query args are enforced by the engine but are not in the visible exclusion textarea: ' + missing.join(', ')) : null,
					h('div', { className: 'mt-4', key: 'engine' }, [
						h('div', { className: 'uc-section-title' }, __("Engine safety floors", 'ultracache')),
						engineOnly.length ? h('div', { className: 'space-y-2 mt-2' }, engineOnly.map((item, index) => h('div', { className: 'flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2', key: 'guard-' + index }, [
							h('span', { className: 'text-sm text-zinc-200' }, item.label || 'Safety floor'),
							h('span', { className: 'font-mono text-[11px] text-sky-300' }, item.status || 'reported'),
						]))) : h('div', { className: 'text-xs text-zinc-500' }, __("No security safeguards were reported.", 'ultracache')),
					]),
					h('div', { className: 'mt-4 grid grid-cols-1 md:grid-cols-2 gap-3', key: 'files' }, [
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'runtime' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Runtime config protection", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'runtime-config.php: ' + (runtime.runtimeConfigExists ? 'exists' : 'missing')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, '.htaccess: ' + (runtime.htaccessProtectionFile ? 'present' : 'missing') + ' · web.config: ' + (runtime.webConfigProtectionFile ? 'present' : 'missing')),
						]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'secrets' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Secret sidecar files", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'Runtime secret: ' + (secretFiles.runtimeSecret && secretFiles.runtimeSecret.exists ? 'exists' : 'missing')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'Redis secret: ' + (secretFiles.objectCacheRedisSecret && secretFiles.objectCacheRedisSecret.exists ? 'exists' : 'missing')),
						]),
					]),
				]),
			]),
		]);
	}
	function App() {
		const [settings, setSettings] = useState(initialSettings);
		const [stats, setStats] = useState(initialStats);
		const [diagnostics, setDiagnostics] = useState(initialDiagnostics);
		const [, setCrawlScopeVersion] = useState(0);
		const [browserCompressionProbe, setBrowserCompressionProbe] = useState({ ready: true, serverCompression: false, gzip: false, brotli: false, brokenGzip: false, brokenBrotli: false, message: 'Browser compression probe is manual-only and does not run on dashboard load.' });
		const [busy, setBusy] = useState(false);
		const [asyncActions, setAsyncActions] = useState({});
		const [toasts, setToasts] = useState([]);
		const [isMobile, setIsMobile] = useState(isMobileViewport());
		const [supportModalOpen, setSupportModalOpen] = useState(false);
		const [infoAccordionsOpen, setInfoAccordionsOpen] = useState(false);
		const [advancedForm, setAdvancedForm] = useState({
			cacheExceptionPaths: initialSettings.cacheExceptionPaths || '',
			cacheExceptionQueryArgs: initialSettings.cacheExceptionQueryArgs || '',
			cacheQueryStringAllowlist: initialSettings.cacheQueryStringAllowlist || '',
			cacheCleanupIntervalHours: initialSettings.cacheCleanupIntervalHours || 24,
			cssBundleCleanupGraceHours: typeof initialSettings.cssBundleCleanupGraceHours === 'undefined' ? 48 : initialSettings.cssBundleCleanupGraceHours,
			cssBundleCleanupDeleteLimit: typeof initialSettings.cssBundleCleanupDeleteLimit === 'undefined' ? 60 : initialSettings.cssBundleCleanupDeleteLimit,
			cronWarmPagesPerMinute: typeof initialSettings.cronWarmPagesPerMinute === 'undefined' ? 2 : initialSettings.cronWarmPagesPerMinute,
			scheduledWarmLimit: typeof initialSettings.scheduledWarmLimit === 'undefined' ? getDefaultScheduledWarmLimit() : initialSettings.scheduledWarmLimit,
			cacheFreshTtlMinutes: initialSettings.cacheFreshTtlMinutes || 15,
			cacheMaxStaleMinutes: initialSettings.cacheMaxStaleMinutes || 720,
		});
		const [varnishForm, setVarnishForm] = useState({
			varnishCliEnabled: !!initialSettings.varnishCliEnabled,
			varnishCliMode: initialSettings.varnishCliMode || 'http',
			varnishCliServers: initialSettings.varnishCliServers || getDefaultVarnishServersForMode(initialSettings.varnishCliMode || 'http'),
			varnishCliKey: initialSettings.varnishCliKey || '',
			varnishCliTimeoutSeconds: initialSettings.varnishCliTimeoutSeconds || 2,
			varnishCliMethod: initialSettings.varnishCliMethod || 'BAN',
			varnishCliKeyConfigured: !!initialSettings.varnishCliKeyConfigured,
		});
		const [redisForm, setRedisForm] = useState({
			objectCacheBackend: initialSettings.objectCacheBackend || 'redis',
			objectCacheFallbackBackend: initialSettings.objectCacheFallbackBackend || 'apcu',
			redisHost: initialSettings.redisHost || '127.0.0.1',
			redisPort: initialSettings.redisPort || 6379,
			redisUsername: initialSettings.redisUsername || '',
			redisPassword: initialSettings.redisPassword || '',
			redisDatabase: typeof initialSettings.redisDatabase === 'undefined' ? 0 : initialSettings.redisDatabase,
			redisPrefix: initialSettings.redisPrefix || '',
			redisUseTls: !!initialSettings.redisUseTls,
			redisPersistent: !!initialSettings.redisPersistent,
			redisConnectTimeoutMs: typeof initialSettings.redisConnectTimeoutMs === 'undefined' ? 200 : initialSettings.redisConnectTimeoutMs,
			redisReadTimeoutMs: typeof initialSettings.redisReadTimeoutMs === 'undefined' ? 200 : initialSettings.redisReadTimeoutMs,
			redisPasswordConfigured: !!initialSettings.redisPasswordConfigured,
		});
		const [inspectUrl, setInspectUrl] = useState('');
		const [inspectBusy, setInspectBusy] = useState(false);
		const [inspectResult, setInspectResult] = useState(null);
		const [performanceProfile, setPerformanceProfile] = useState(null);
		const [cssDiagnosticsUrl, setCssDiagnosticsUrl] = useState((typeof ucwp !== 'undefined' && ucwp && ucwp.frontendProbeUrl) ? String(ucwp.frontendProbeUrl || '') : '');
		const [cssDiagnosticsBusy, setCssDiagnosticsBusy] = useState(false);
		const [homepageHtmlBusy, setHomepageHtmlBusy] = useState(false);
		const [homepageHtmlCssBusy, setHomepageHtmlCssBusy] = useState(false);
		const [allUrlsCssBusy, setAllUrlsCssBusy] = useState(false);
		const [menuUrlsCssBusy, setMenuUrlsCssBusy] = useState(false);
		const [savedJob, setSavedJob] = useState(loadSavedJob());
		const [warmupGeneration, setWarmupGeneration] = useState(initialWarmupGeneration);
		const warmupGenerationRef = useRef(initialWarmupGeneration);
		const cancelRequestedRef = useRef(false);
		const importFileInputRef = useRef(null);
		const statsRefreshInFlightRef = useRef(false);
		const settingsRef = useRef(initialSettings);
		const committedSettingsRef = useRef(initialSettings);
		const pendingSettingsPatchRef = useRef({});
		const settingsSaveTimerRef = useRef(null);
		const settingsSaveInFlightRef = useRef(false);
		const queuedActionKeysRef = useRef({});
		const uiActionQueueRef = useRef(Promise.resolve());
		const uiActionQueueDepthRef = useRef(0);
		const uiActionSequenceRef = useRef(0);
		const queuedDashboardPayloadRef = useRef(null);
		const suppressBeforeUnloadRef = useRef(false);
		const [uiActionQueueCount, setUiActionQueueCount] = useState(0);
		const compressionSyncRef = useRef('');
		const manualObjectCacheTestRef = useRef(null);
		const compressionLocks = useMemo(() => getCompressionLockState(diagnostics, browserCompressionProbe), [diagnostics, browserCompressionProbe]);
		const initialMediaQueue = initialDiagnostics && initialDiagnostics.mediaRuntime && initialDiagnostics.mediaRuntime.queue
			? initialDiagnostics.mediaRuntime.queue
			: null;
		const [mediaQueueStatus, setMediaQueueStatus] = useState(initialMediaQueue);
		const [process, setProcess] = useState({
			active: false,
			label: '',
			current: 0,
			total: 0,
			logs: [],
			startTime: 0,
			cancellable: false,
			cancelRequested: false,
		});

		const dismissToast = useCallback((id) => {
			if (!id) {
				return;
			}
			setToasts((current) => current.filter((toast) => toast.id !== id));
		}, []);

		const pushToast = useCallback((toast) => {
			if (!toast || !toast.text) {
				return null;
			}

			const nextToast = Object.assign({
				id: toast.id || ('toast-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
				type: 'info',
				persistent: false,
				duration: CLEAR_NOTICE_DELAY,
			}, toast || {});

			setToasts((current) => {
				const filtered = current.filter((item) => item.id !== nextToast.id);
				return filtered.concat([nextToast]).slice(-50);
			});

			return nextToast.id;
		}, []);


		useEffect(() => {
			settingsRef.current = settings;
		}, [settings]);

		useEffect(() => {
			return () => {
				if (settingsSaveTimerRef.current) {
					window.clearTimeout(settingsSaveTimerRef.current);
					settingsSaveTimerRef.current = null;
				}
			};
		}, []);

		useEffect(() => {
			const handleBeforeUnload = (event) => {
				if (suppressBeforeUnloadRef.current) {
					return undefined;
				}

				const processActive = !!(process && process.active);
				const actionActive = hasActiveQueuedDashboardAction();
				const saveActive = !!(settingsSaveTimerRef.current || settingsSaveInFlightRef.current || hasPendingSettingsPatch());

				if (!saveActive && !actionActive && !processActive && !busy) {
					return undefined;
				}

				event.preventDefault();
				event.returnValue = '';
				return '';
			};

			window.addEventListener('beforeunload', handleBeforeUnload);
			return () => window.removeEventListener('beforeunload', handleBeforeUnload);
		}, [busy, process && process.active, asyncActions, uiActionQueueCount]);
		useEffect(() => {
			if (!Array.isArray(toasts) || !toasts.length) {
				return undefined;
			}

			const timers = toasts
				.filter((toast) => toast && !toast.persistent)
				.map((toast) => setTimeout(() => dismissToast(toast.id), typeof toast.duration === 'number' ? toast.duration : CLEAR_NOTICE_DELAY));

			return () => timers.forEach((timer) => clearTimeout(timer));
		}, [toasts, dismissToast]);

		useEffect(() => {
			const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : null;
			if (reverseProxy && reverseProxy.detected) {
				if (shouldShowSystemNotice('reverse-proxy', SYSTEM_NOTICE_COOLDOWN)) {
					markSystemNoticeShown('reverse-proxy');
					pushToast({
						id: 'system-reverse-proxy',
						type: 'warning',
						title: __("Reverse proxy detected", 'ultracache'),
						text: reverseProxy.message || 'UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
						persistent: false,
						duration: SYSTEM_NOTICE_DELAY,
					});
				}
			} else {
				dismissToast('system-reverse-proxy');
			}
		}, [diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.detected : false, diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.message : '', pushToast, dismissToast]);

		useEffect(() => {
			const conflicts = diagnostics && diagnostics.legacyCacheConflicts ? diagnostics.legacyCacheConflicts : {};
			const activePlugins = Array.isArray(conflicts.activeCachePlugins) ? conflicts.activeCachePlugins : [];
			if (!activePlugins.length) {
				dismissToast('cache-plugin-conflict');
				return;
			}

			const slugs = activePlugins.map((item) => item && item.slug ? String(item.slug) : '').filter(Boolean).sort();
			const noticeKey = 'cache-plugin-conflict:' + slugs.join(',');
			if (isPersistentNoticeDismissed(noticeKey)) {
				return;
			}

			const pluginNames = activePlugins.map((item) => item && item.name ? item.name : (item && item.slug ? item.slug : 'Unknown')).join(', ');
			pushToast({
				id: 'cache-plugin-conflict',
				type: 'warning',
				title: __("Potential cache plugin conflict", 'ultracache'),
				text: (conflicts.message || 'Another cache/performance plugin is active and may conflict with UltraCache.') + ' Detected: ' + pluginNames + '.',
				persistent: true,
				actions: [
					{ label: __("Review", 'ultracache'), onClick: () => scrollToCacheConflictReview() },
					{ label: __("Dismiss", 'ultracache'), onClick: () => { markSystemNoticeShown(noticeKey); dismissToast('cache-plugin-conflict'); } },
					{ label: __("Don’t show again", 'ultracache'), onClick: () => { dismissPersistentNotice(noticeKey); dismissToast('cache-plugin-conflict'); } },
				],
			});
		}, [diagnostics && diagnostics.legacyCacheConflicts ? JSON.stringify(diagnostics.legacyCacheConflicts.activeCachePlugins || []) : '', pushToast, dismissToast]);

		useEffect(() => {
			const handleResize = () => setIsMobile(isMobileViewport());
			handleResize();
			window.addEventListener('resize', handleResize);
			return () => window.removeEventListener('resize', handleResize);
		}, []);

		useEffect(() => {
			if (isMobile) {
				setSupportModalOpen(false);
			}
		}, [isMobile]);

		// Browser/frontend probes are intentionally manual-only. Dashboard load and stats refresh must stay passive.

		useEffect(() => {
			if (!settings.cacheStatsEnabled) {
				statsRefreshInFlightRef.current = false;
				setStats(function(current) { return Object.assign({}, current || {}, getStatsDisabledClientPayload()); });
				return undefined;
			}

			let interval = null;

			const runRefresh = async () => {
				if (document.hidden || statsRefreshInFlightRef.current) {
					return;
				}
				statsRefreshInFlightRef.current = true;
				try {
					await refreshStats({ force: true });
				} catch (error) {}
				finally {
					statsRefreshInFlightRef.current = false;
				}
			};

			const startInterval = () => {
				if (interval) {
					return;
				}
				interval = window.setInterval(runRefresh, STATS_REFRESH_INTERVAL);
			};

			const stopInterval = () => {
				if (!interval) {
					return;
				}
				window.clearInterval(interval);
				interval = null;
			};

			const handleVisibilityChange = () => {
				if (document.hidden) {
					stopInterval();
					return;
				}
				runRefresh();
				startInterval();
			};

			runRefresh();
			startInterval();
			document.addEventListener('visibilitychange', handleVisibilityChange);
			return () => {
				stopInterval();
				document.removeEventListener('visibilitychange', handleVisibilityChange);
			};
		}, [settings.cacheStatsEnabled]);

		useEffect(() => {
			setAdvancedForm({
				cacheExceptionPaths: settings.cacheExceptionPaths || '',
				cacheExceptionQueryArgs: settings.cacheExceptionQueryArgs || '',
				cacheQueryStringAllowlist: settings.cacheQueryStringAllowlist || '',
				cacheCleanupIntervalHours: settings.cacheCleanupIntervalHours || 24,
				cssBundleCleanupGraceHours: typeof settings.cssBundleCleanupGraceHours === 'undefined' ? 48 : settings.cssBundleCleanupGraceHours,
				cssBundleCleanupDeleteLimit: typeof settings.cssBundleCleanupDeleteLimit === 'undefined' ? 60 : settings.cssBundleCleanupDeleteLimit,
				cronWarmPagesPerMinute: typeof settings.cronWarmPagesPerMinute === 'undefined' ? 2 : settings.cronWarmPagesPerMinute,
				scheduledWarmLimit: typeof settings.scheduledWarmLimit === 'undefined' ? getDefaultScheduledWarmLimit() : settings.scheduledWarmLimit,
				cacheFreshTtlMinutes: settings.cacheFreshTtlMinutes || 15,
				cacheMaxStaleMinutes: settings.cacheMaxStaleMinutes || 720,
			});
		}, [
			settings.cacheExceptionPaths,
			settings.cacheExceptionQueryArgs,
			settings.cacheQueryStringAllowlist,
			settings.cacheCleanupIntervalHours,
			settings.cssBundleCleanupGraceHours,
			settings.cssBundleCleanupDeleteLimit,
			settings.cronWarmPagesPerMinute,
			settings.scheduledWarmLimit,
			settings.cacheFreshTtlMinutes,
			settings.cacheMaxStaleMinutes,
		]);


		useEffect(() => {
			setVarnishForm({
				varnishCliEnabled: !!settings.varnishCliEnabled,
				varnishCliMode: settings.varnishCliMode || 'http',
				varnishCliServers: settings.varnishCliServers || getDefaultVarnishServersForMode(settings.varnishCliMode || 'http'),
				varnishCliKey: settings.varnishCliKey || '',
				varnishCliTimeoutSeconds: settings.varnishCliTimeoutSeconds || 2,
				varnishCliMethod: settings.varnishCliMethod || 'BAN',
				varnishCliKeyConfigured: !!settings.varnishCliKeyConfigured,
			});
		}, [
			settings.varnishCliEnabled,
			settings.varnishCliMode,
			settings.varnishCliServers,
			settings.varnishCliKey,
			settings.varnishCliTimeoutSeconds,
			settings.varnishCliMethod,
			settings.varnishCliKeyConfigured,
		]);

		useEffect(() => {
			const pendingObjectCachePatch = pendingSettingsPatchRef.current && (
				Object.prototype.hasOwnProperty.call(pendingSettingsPatchRef.current, 'objectCacheBackend') ||
				Object.prototype.hasOwnProperty.call(pendingSettingsPatchRef.current, 'objectCacheFallbackBackend')
			);

			setRedisForm((current) => {
				const next = {
					objectCacheBackend: pendingObjectCachePatch ? (current.objectCacheBackend || settings.objectCacheBackend || 'redis') : (settings.objectCacheBackend || 'redis'),
					objectCacheFallbackBackend: pendingObjectCachePatch ? (current.objectCacheFallbackBackend || settings.objectCacheFallbackBackend || 'apcu') : (settings.objectCacheFallbackBackend || 'apcu'),
					redisHost: settings.redisHost || '127.0.0.1',
					redisPort: settings.redisPort || 6379,
					redisUsername: settings.redisUsername || '',
					redisPassword: settings.redisPassword || '',
					redisDatabase: typeof settings.redisDatabase === 'undefined' ? 0 : settings.redisDatabase,
					redisPrefix: settings.redisPrefix || '',
					redisUseTls: !!settings.redisUseTls,
					redisPersistent: !!settings.redisPersistent,
					redisConnectTimeoutMs: typeof settings.redisConnectTimeoutMs === 'undefined' ? 200 : settings.redisConnectTimeoutMs,
					redisReadTimeoutMs: typeof settings.redisReadTimeoutMs === 'undefined' ? 200 : settings.redisReadTimeoutMs,
					redisPasswordConfigured: !!settings.redisPasswordConfigured,
				};
				return next;
			});
		}, [
			settings.objectCacheBackend,
			settings.objectCacheFallbackBackend,
			settings.redisHost,
			settings.redisPort,
			settings.redisUsername,
			settings.redisPassword,
			settings.redisDatabase,
			settings.redisPrefix,
			settings.redisUseTls,
			settings.redisPersistent,
			settings.redisConnectTimeoutMs,
			settings.redisReadTimeoutMs,
			settings.redisPasswordConfigured,
		]);

		useEffect(() => {
			if (browserCompressionProbe && browserCompressionProbe.ready === false) {
				return;
			}

			const patch = {};

			if (compressionLocks.gzipLocked && settings.gzipEnabled) {
				patch.gzipEnabled = false;
			}
			if (compressionLocks.brotliLocked && settings.brotliEnabled) {
				patch.brotliEnabled = false;
			}

			const patchKeys = Object.keys(patch).sort();
			if (!patchKeys.length) {
				compressionSyncRef.current = '';
				return;
			}

			const signature = patchKeys.join('|');
			if (busy || compressionSyncRef.current === signature) {
				return;
			}

			compressionSyncRef.current = signature;
			setSettings((current) => Object.assign({}, current, patch));

			(async () => {
				setBusy(true);
				try {
					const response = await apiRequest('save_settings', { settings_json: JSON.stringify(patch) });
					if (response && response.settings) {
						applyServerSettings(response.settings);
					}
					if (response && response.stats) {
						setStats(response.stats);
					}
					if (response && response.diagnostics) {
						setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
					}
					pushToast({ type: 'warning', text: __("UltraCache automatically turned off compression that is already handled by your server or proxy.", 'ultracache') });
				} catch (error) {
					compressionSyncRef.current = '';
					pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to synchronize compression safety settings.' });
				} finally {
					setBusy(false);
				}
			})();
		}, [busy, browserCompressionProbe.ready, compressionLocks.gzipLocked, compressionLocks.brotliLocked, settings.gzipEnabled, settings.brotliEnabled, pushToast]);


		const etaText = useMemo(() => {
			if (process.active && process.queueBuilding) {
				return 'Queue still building';
			}
			if (!process.active || !process.current || !process.total || !process.startTime) {
				return '';
			}

			const elapsed = Date.now() - process.startTime;
			const perItem = elapsed / process.current;
			const remaining = Math.max(0, (process.total - process.current) * perItem);
			const seconds = Math.round(remaining / 1000);

			if (seconds < 60) {
				return seconds + 's remaining';
			}

			return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's remaining';
		}, [process]);


		function updateVarnishField(key, value) {
			setVarnishForm((current) => {
				const next = Object.assign({}, current || {}, { [key]: value });
				if (key === 'varnishCliMode' && isDefaultVarnishServersValue(current && current.varnishCliServers)) {
					next.varnishCliServers = getDefaultVarnishServersForMode(value);
				}
				return next;
			});

			if (key === 'varnishCliEnabled') {
				queueSettingsPatch({ [key]: !!value });
			}
		}

		function updateRedisField(key, value) {
			const normalizedBackend = (candidate) => {
				candidate = String(candidate || '').toLowerCase();
				return ['redis', 'apcu', 'disk'].indexOf(candidate) !== -1 ? candidate : 'redis';
			};
			const normalizedFallback = (candidate) => {
				candidate = String(candidate || '').toLowerCase();
				return ['none', 'runtime'].indexOf(candidate) !== -1 ? 'none' : (['apcu', 'disk'].indexOf(candidate) !== -1 ? candidate : 'apcu');
			};
			const currentForm = redisForm || {};
			const currentSettings = settingsRef.current || {};
			const currentEnabled = !!currentSettings.objectCacheEnabled;
			const currentBackend = normalizedBackend(currentForm.objectCacheBackend || currentSettings.objectCacheBackend || 'redis');
			const currentFallback = normalizedFallback(currentForm.objectCacheFallbackBackend || currentSettings.objectCacheFallbackBackend || 'apcu');

			if (key === 'objectCacheBackend') {
				const nextBackend = normalizedBackend(value);
				setRedisForm((current) => Object.assign({}, current, { objectCacheBackend: nextBackend }));
				queueSettingsPatch({
					objectCacheEnabled: currentEnabled,
					objectCacheBackend: nextBackend,
					objectCacheFallbackBackend: currentFallback,
				});
				return;
			}

			if (key === 'objectCacheFallbackBackend') {
				const nextFallback = normalizedFallback(value);
				setRedisForm((current) => Object.assign({}, current, { objectCacheFallbackBackend: nextFallback }));
				queueSettingsPatch({
					objectCacheEnabled: currentEnabled,
					objectCacheBackend: currentBackend,
					objectCacheFallbackBackend: nextFallback,
				});
				return;
			}

			setRedisForm((current) => Object.assign({}, current, { [key]: value }));

			if (key === 'redisPersistent' || key === 'redisUseTls') {
				queueSettingsPatch({ [key]: !!value });
			}
		}

		function getCompressionLockState(sourceDiagnostics, browserProbe) {
			const serverDefault = sourceDiagnostics && sourceDiagnostics.compression && sourceDiagnostics.compression.serverDefault
				? sourceDiagnostics.compression.serverDefault
				: {};
			const browser = browserProbe || {};

			if (browser.serverCompression) {
				return {
					gzipLocked: true,
					brotliLocked: true,
					gzipDescription: browser.message || 'Your server or proxy is already using frontend compression by default. UltraCache compression has been disabled to avoid conflicts.',
					brotliDescription: browser.message || 'Your server or proxy is already using frontend compression by default. UltraCache compression has been disabled to avoid conflicts.',
				};
			}

			const gzipLocked = !!(browser.brokenGzip || serverDefault.brokenGzip || serverDefault.gzip);
			const brotliLocked = !!(browser.brokenBrotli || serverDefault.brokenBrotli || serverDefault.brotli);
			const gzipDescription = browser.brokenGzip
				? (browser.message || 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.')
				: (serverDefault.brokenGzip
					? 'UltraCache detected gzip-compressed output without a matching Content-Encoding header. Gzip has been disabled as a safety measure.'
					: (serverDefault.gzip ? 'Your server is already using gzip compression by default.' : 'Serve gzip sidecar cache files when supported.'));
			const brotliDescription = browser.brokenBrotli
				? (browser.message || 'UltraCache detected Brotli-compressed output without a matching Content-Encoding header. Brotli has been disabled as a safety measure.')
				: (serverDefault.brokenBrotli
					? 'UltraCache detected Brotli-compressed output without a matching Content-Encoding header. Brotli has been disabled as a safety measure.'
					: (serverDefault.brotli ? 'Your server is already using Brotli compression by default.' : 'Serve Brotli sidecar cache files when available.'));

			return {
				gzipLocked,
				brotliLocked,
				gzipDescription,
				brotliDescription,
			};
		}

		async function saveRedisSettings() {
			return enqueueUiOperation('object_cache_settings_save', 'Save object-cache settings', async () => {
				const form = Object.assign({}, redisForm || {});
				const patch = {
					objectCacheBackend: form.objectCacheBackend || 'redis',
					objectCacheFallbackBackend: form.objectCacheFallbackBackend || 'apcu',
					redisHost: form.redisHost || '127.0.0.1',
					redisPort: form.redisPort,
					redisUsername: form.redisUsername || '',
					redisDatabase: form.redisDatabase,
					redisPrefix: form.redisPrefix || '',
					redisUseTls: !!form.redisUseTls,
					redisPersistent: !!form.redisPersistent,
					redisConnectTimeoutMs: form.redisConnectTimeoutMs,
					redisReadTimeoutMs: form.redisReadTimeoutMs,
				};
				if (String(form.redisPassword || '').trim()) {
					patch.redisPassword = String(form.redisPassword || '');
				}
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(patch) });
				applyDashboardPayload(response || {});
				return response;
			}, { processingText: 'Processing object-cache settings save…', successText: 'Object cache backend settings saved.', failedText: 'Failed to save object cache settings.' });
		}

		async function testObjectCacheBackend() {
			return enqueueUiOperation('object_cache_test', 'Test object cache backend', async () => {
				const selectedBackend = (redisForm && redisForm.objectCacheBackend) || (settingsRef.current || {}).objectCacheBackend || 'redis';
				const payload = Object.assign({}, redisForm || {}, {
					backend: selectedBackend,
					redisPasswordConfigured: !!((settingsRef.current || {}).redisPasswordConfigured || (redisForm || {}).redisPasswordConfigured),
				});
				const passwordValue = String(payload.redisPassword || '').trim();
				if (!passwordValue) {
					delete payload.redisPassword;
				}
				const response = await apiRequest('object_cache_test', payload);
				applyDashboardPayload(response || {});
				if (selectedBackend === 'redis') {
					mergeRedisTestResult(response || {});
				}
				return response;
			}, { processingText: 'Processing object-cache backend test…', successText: 'Object cache backend test finished.', failedText: 'Object cache backend test failed.' });
		}

		async function flushObjectCache() {
			const selectedBackend = (redisForm && redisForm.objectCacheBackend) || (settingsRef.current || {}).objectCacheBackend || 'active';
			await queueDashboardAction('object_cache_flush', { backend: selectedBackend }, {
				queued: 'Object cache flush processing via dashboard…',
				success: 'Object cache flush finished.',
				failed: 'Object cache flush failed.',
			}, 'object_cache_flush');
		}

		function scrollToCacheConflictReview() {
			try {
				const element = document.getElementById('ucwp-cache-conflict-review');
				if (element && typeof element.scrollIntoView === 'function') {
					element.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			} catch (error) {}
		}

		async function recheckCacheConflicts() {
			try {
				await refreshStats();
				pushToast({ type: 'info', text: __("Cache conflict diagnostics refreshed.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Cache conflict re-check failed.' });
			}
		}

		async function removeConflictingCacheDropins() {
			if (busy) {
				return;
			}

			const confirmed = window.confirm('UltraCache will back up and remove the detected advanced-cache.php/object-cache.php files that are not managed by UltraCache. Plugin folders and settings from other plugins will not be deleted. Continue?');
			if (!confirmed) {
				pushToast({ type: 'info', text: __("Cache helper removal cancelled.", 'ultracache') });
				return;
			}

			setBusy(true);
			try {
				const response = await apiRequest('remove_conflicting_cache_dropins', {});
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
				}
				await refreshStats();
				pushToast({ type: 'success', text: response && response.message ? response.message : 'Conflicting cache helpers removed.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to remove conflicting cache helpers.' });
			} finally {
				setBusy(false);
			}
		}

		async function runFullObjectCount() {
			await queueDashboardAction('object_cache_full_count', {}, {
				queued: 'Full object-cache count processing via dashboard…',
				success: 'Full object-cache count finished.',
				failed: 'Full object-cache count failed.',
				runningLabel: 'Counting…',
			}, 'object_cache_full_count');
		}

		async function saveVarnishSettings() {
			return enqueueUiOperation('varnish_settings_save', 'Save Varnish settings', async () => {
				const form = Object.assign({}, varnishForm || {});
				const submittedSecret = String(form.varnishCliKey || '').trim();
				const patch = {
					varnishCliEnabled: !!form.varnishCliEnabled,
					varnishCliMode: form.varnishCliMode || 'http',
					varnishCliServers: form.varnishCliServers || '',
					varnishCliTimeoutSeconds: form.varnishCliTimeoutSeconds,
					varnishCliMethod: form.varnishCliMethod || 'BAN',
				};
				if (submittedSecret) {
					patch.varnishCliKey = submittedSecret;
				}
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(patch) });
				if (submittedSecret) {
					setVarnishForm((current) => Object.assign({}, current || {}, { varnishCliKey: '', varnishCliKeyConfigured: true }));
				}
				applyDashboardPayload(response || {});
				return response;
			}, { processingText: 'Processing Varnish settings save…', successText: 'Varnish settings saved.', failedText: 'Failed to save Varnish settings.' });
		}

		async function runVarnishTest() {
			return enqueueUiOperation('varnish_test', 'Test Varnish', async () => {
				const response = await apiRequest('varnish_test', {});
				applyDashboardPayload(response || {});
				mergeVarnishTestResult(response || {});
				return response;
			}, { processingText: 'Processing Varnish test…', successText: 'Varnish test completed.', failedText: 'Varnish test failed.' });
		}

		async function runVarnishFlushAll() {
			await queueDashboardAction('varnish_flush_all', {}, {
				queued: 'Varnish flush processing via dashboard…',
				success: 'Varnish flush finished.',
				failed: 'Varnish flush failed.',
			}, 'varnish_flush_all');
		}


		async function flushOpcache() {
			return enqueueUiOperation('opcache_flush', 'Flush OPcache', async () => {
				const response = await apiRequest('opcache_flush', {});
				applyDashboardPayload(response || {});
				if (response && response.opcache) {
					stageDashboardPayloadForQueue({ stats: Object.assign({}, stats || {}, { opcache: response.opcache }) });
				}
				try {
					await refreshStats();
				} catch (error) {}
				return response;
			}, { processingText: 'Processing OPcache flush…', successText: 'OPcache flush finished.', failedText: 'OPcache flush failed.' });
		}

		async function flushApcu() {
			await queueDashboardAction('apcu_flush', {}, {
				queued: 'APCu flush processing via dashboard…',
				success: 'APCu flush finished.',
				failed: 'APCu flush failed.',
			}, 'apcu_flush');
		}


		async function flushLiteSpeed() {
			return enqueueUiOperation('litespeed_flush', 'Flush LiteSpeed Cache', async () => {
				const response = await apiRequest('litespeed_flush', {});
				applyDashboardPayload(response || {});
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Processing LiteSpeed Cache flush…', successText: 'LiteSpeed Cache flush finished.', failedText: 'LiteSpeed Cache flush failed.' });
		}

		async function flushNginx() {
			return enqueueUiOperation('nginx_flush', 'Flush Nginx Cache', async () => {
				const response = await apiRequest('nginx_flush', {});
				applyDashboardPayload(response || {});
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Processing Nginx Cache flush…', successText: 'Nginx Cache flush finished.', failedText: 'Nginx Cache flush failed.' });
		}

		async function redetectExternalCaches() {
			return enqueueUiOperation('external_caches_redetect', 'Redetect caches', async () => {
				const response = await apiRequest('external_caches_redetect', {});
				applyDashboardPayload(response || {});
				if (response && response.layers) {
					stageDashboardPayloadForQueue({ stats: Object.assign({}, stats || {}, { externalCaches: response }) });
				}
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Detecting external caches…', successText: 'Cache detection refreshed.', failedText: 'Cache detection failed.' });
		}

		function normalizeStatsResponse(payload) {
			if (payload && typeof payload === 'object' && payload.stats && typeof payload.stats === 'object') {
				return payload.stats;
			}

			return payload && typeof payload === 'object' ? payload : {};
		}

		function getStatsDisabledClientPayload() {
			return {
				success: true,
				enabled: false,
				disabled: true,
				cacheStatsEnabled: false,
				message: 'Cache stats are disabled.',
				impact: 'off',
				timestamp: Math.floor(Date.now() / 1000),
				dashboardStatsDisabled: true,
				dashboardStatsDisabledReason: 'Cache stats are disabled.',
				dashboardStatsPollingDisabled: true,
			};
		}

		function areStatsEnabledInClient() {
			const currentSettings = settingsRef.current || settings || {};
			return !!(currentSettings && currentSettings.cacheStatsEnabled);
		}

		async function refreshStats(options) {
			const force = !!(options && options.force);
			if (!areStatsEnabledInClient()) {
				const disabledStats = getStatsDisabledClientPayload();
				setStats(function(current) { return Object.assign({}, current || {}, disabledStats); });
				return disabledStats;
			}

			if (!force && typeof isDashboardQuietModeActive === 'function' && isDashboardQuietModeActive()) {
				return null;
			}

			const response = await apiRequest('stats');
			const freshStats = normalizeStatsResponse(response);
			const hasMeaningfulStats = freshStats && typeof freshStats === 'object' && (
				typeof freshStats.pageCacheFiles !== 'undefined' ||
				typeof freshStats.pageCacheHits !== 'undefined' ||
				typeof freshStats.objectCacheEntries !== 'undefined' ||
				typeof freshStats.cacheSizeBytes !== 'undefined' ||
				typeof freshStats.cacheSizeHuman !== 'undefined' ||
				typeof freshStats.optimizedImages !== 'undefined' ||
				typeof freshStats.imagesOptimized !== 'undefined' ||
				typeof freshStats.opcache !== 'undefined' ||
				typeof freshStats.apcu !== 'undefined'
			);

			if (hasMeaningfulStats) {
				const responseHasDiagnostics = !!(freshStats && freshStats.diagnostics && typeof freshStats.diagnostics === 'object');
				if (uiActionQueueDepthRef.current > 0) {
					const queuedPayload = { stats: freshStats };
					if (responseHasDiagnostics) {
						queuedPayload.diagnostics = freshStats.diagnostics;
					}
					stageDashboardPayloadForQueue(queuedPayload);
					return freshStats;
				}
				setStats(freshStats);
				if (responseHasDiagnostics) {
					const nextDiagnostics = freshStats.diagnostics || {};
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(nextDiagnostics));
					if (nextDiagnostics && nextDiagnostics.mediaRuntime && nextDiagnostics.mediaRuntime.queue) {
						setMediaQueueStatus(nextDiagnostics.mediaRuntime.queue);
					}
				}
			}
		}

		function hasPendingSettingsPatch() {
			return !!Object.keys(pendingSettingsPatchRef.current || {}).length;
		}

		function hasActiveQueuedDashboardAction() {
			return uiActionQueueDepthRef.current > 0 || Object.keys(queuedActionKeysRef.current || {}).some((key) => !!queuedActionKeysRef.current[key]);
		}

		function hasDashboardWorkInProgress() {
			return !!(
				settingsSaveTimerRef.current ||
				settingsSaveInFlightRef.current ||
				hasPendingSettingsPatch() ||
				hasActiveQueuedDashboardAction()
			);
		}

		function isCriticalSettingsPatch(patch) {
			if (!patch || typeof patch !== 'object') {
				return false;
			}
			return Object.keys(patch).some((key) => CRITICAL_SETTING_KEYS.indexOf(key) !== -1);
		}

		function applyServerSettings(responseSettings) {
			if (!responseSettings || typeof responseSettings !== 'object') {
				return;
			}

			const committed = Object.assign({}, responseSettings);
			committedSettingsRef.current = committed;

			const pendingPatch = Object.assign({}, pendingSettingsPatchRef.current || {});
			const nextSettings = Object.keys(pendingPatch).length ? Object.assign({}, committed, pendingPatch) : committed;
			settingsRef.current = nextSettings;
			setSettings(nextSettings);
		}

		function applyEffectiveSettingsFromCommitted() {
			const committed = committedSettingsRef.current || initialSettings || {};
			const pendingPatch = Object.assign({}, pendingSettingsPatchRef.current || {});
			const nextSettings = Object.keys(pendingPatch).length ? Object.assign({}, committed, pendingPatch) : committed;
			settingsRef.current = nextSettings;
			setSettings(nextSettings);
		}


		function mergeManualObjectCacheTestIntoDiagnostics(diagnosticsPayload) {
			const manual = manualObjectCacheTestRef.current;
			if (!manual || !diagnosticsPayload || typeof diagnosticsPayload !== 'object') {
				return diagnosticsPayload;
			}
			const next = Object.assign({}, diagnosticsPayload || {});
			const objectCache = Object.assign({}, next.objectCache || {});
			const redis = Object.assign({}, objectCache.redis || {});
			if (manual.backend === 'redis') {
				Object.assign(redis, manual.result || {});
				objectCache.redis = redis;
			}
			if (manual.payloadProbe) {
				objectCache.manualPayloadProbe = manual.payloadProbe;
			}
			objectCache.manualBackendTest = manual.result || {};
			next.objectCache = objectCache;
			return next;
		}

		function mergeDashboardPayloadForDeferredApply(current, payload) {
			if (!payload || typeof payload !== 'object') {
				return current || null;
			}
			const next = Object.assign({}, current || {});
			['stats', 'diagnostics', 'settings', 'performanceProfile', 'opcache', 'apcu', 'externalCaches', 'mediaRuntime', 'crawlScopeSummary', 'warmupGeneration'].forEach((key) => {
				if (Object.prototype.hasOwnProperty.call(payload, key)) {
					next[key] = payload[key];
				}
			});
			if (payload.result && typeof payload.result === 'object') {
				next.result = mergeDashboardPayloadForDeferredApply(next.result || {}, payload.result);
			}
			return next;
		}

		function stageDashboardPayloadForQueue(payload) {
			queuedDashboardPayloadRef.current = mergeDashboardPayloadForDeferredApply(queuedDashboardPayloadRef.current, payload);
		}

		function applyDashboardPayloadNow(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}

			const responseStats = payload.stats || (payload.result && payload.result.stats);
			if (responseStats) {
				setStats(normalizeStatsResponse(responseStats));
			}

			const responseExternalCaches = payload.externalCaches || (payload.result && payload.result.externalCaches);
			if (responseExternalCaches) {
				setStats((current) => Object.assign({}, current || {}, { externalCaches: responseExternalCaches }));
			}

			const responseDiagnostics = payload.diagnostics || (payload.result && payload.result.diagnostics);
			if (responseDiagnostics) {
				setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(responseDiagnostics));
			}

			const responseCrawlScopeSummary = payload.crawlScopeSummary || (payload.result && payload.result.crawlScopeSummary);
			if (responseCrawlScopeSummary && typeof responseCrawlScopeSummary === 'object') {
				crawlScopeSummary = responseCrawlScopeSummary;
				ucwp.crawlScopeSummary = responseCrawlScopeSummary;
				setCrawlScopeVersion((version) => version + 1);
			}

			const responseWarmupGeneration = typeof payload.warmupGeneration !== 'undefined' ? payload.warmupGeneration : (payload.result && typeof payload.result.warmupGeneration !== 'undefined' ? payload.result.warmupGeneration : null);
			if (null !== responseWarmupGeneration) {
				setCurrentWarmupGeneration(responseWarmupGeneration);
			}

			const responseSettings = payload.settings || (payload.result && payload.result.settings);
			if (responseSettings) {
				applyServerSettings(responseSettings);
			}
		}

		function applyDashboardPayload(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			if (uiActionQueueDepthRef.current > 0) {
				stageDashboardPayloadForQueue(payload);
				return;
			}
			applyDashboardPayloadNow(payload);
		}

		async function flushDeferredDashboardPayloadAfterQueue() {
			if (uiActionQueueDepthRef.current > 0) {
				return;
			}
			const payload = queuedDashboardPayloadRef.current;
			queuedDashboardPayloadRef.current = null;
			if (payload) {
				applyDashboardPayloadNow(payload);
			}
		}

		function mergeRedisTestResult(result) {
			if (!result || typeof result !== 'object') {
				return;
			}

			manualObjectCacheTestRef.current = {
				backend: 'redis',
				result: Object.assign({}, result || {}),
				payloadProbe: result && result.payloadProbe ? result.payloadProbe : null,
			};

			if (uiActionQueueDepthRef.current > 0) {
				return;
			}

			setDiagnostics((current) => {
				const next = Object.assign({}, current || {});
				const objectCache = Object.assign({}, next.objectCache || {});
				objectCache.redis = Object.assign({}, objectCache.redis || {}, result || {});
				if (result && result.payloadProbe) {
					objectCache.manualPayloadProbe = result.payloadProbe;
				}
				objectCache.manualBackendTest = Object.assign({}, result || {});
				next.objectCache = objectCache;
				return next;
			});
		}

		function mergeVarnishTestResult(result) {
			if (!result || typeof result !== 'object') {
				return;
			}

			if (uiActionQueueDepthRef.current > 0) {
				return;
			}

			setDiagnostics((current) => {
				const next = Object.assign({}, current || {});
				const varnish = Object.assign({}, next.varnish || {});
				varnish.last = Object.assign({}, varnish.last || {}, result || {});
				next.varnish = varnish;
				return next;
			});
		}

		function applyMediaQueueStatus(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			setMediaQueueStatus(payload);
			if (payload.storageStats && typeof payload.storageStats === 'object') {
				setStats((current) => Object.assign({}, current || {}, payload.storageStats));
			}
			setDiagnostics((current) => {
				const next = Object.assign({}, current || {});
				const mediaRuntime = Object.assign({}, next.mediaRuntime || {});
				mediaRuntime.queue = payload;
				next.mediaRuntime = mediaRuntime;
				return next;
			});
		}

		function getSelectedMediaQueueFormat() {
			return 'best';
		}

		async function refreshMediaQueueStatus(refreshStorage = false) {
			const response = await apiRequest('media_queue_status', { media_format: getSelectedMediaQueueFormat(), refresh_storage: !!refreshStorage });
			applyMediaQueueStatus(response);
			return response;
		}
		function enqueueUiOperation(key, label, runner, options) {
			const actionKey = key || ('operation_' + Date.now());
			const readableLabel = label || formatDashboardActionLabel(actionKey);
			const sequence = ++uiActionSequenceRef.current;
			const toastId = 'ucwp-fifo-' + sequence + '-' + String(actionKey).replace(/[^a-z0-9_-]+/gi, '-');
			const opts = options || {};
			uiActionQueueDepthRef.current += 1;
			setUiActionQueueCount((count) => count + 1);
			pushToast({
				id: toastId,
				type: 'info',
				title: readableLabel,
				text: opts.processingText || ('Processing ' + readableLabel + '…'),
				persistent: true,
			});

			const run = async () => {
				try {
					const result = await runner(toastId);
					const successText = typeof opts.successText === 'function' ? opts.successText(result) : opts.successText;
					pushToast({
						id: toastId,
						type: 'success',
						title: readableLabel,
						text: successText || (readableLabel + ' completed.'),
					});
					return result;
				} catch (error) {
					pushToast({
						id: toastId,
						type: 'error',
						title: readableLabel,
						text: error && error.message ? error.message : (opts.failedText || (readableLabel + ' failed.')),
					});
					return null;
				} finally {
					uiActionQueueDepthRef.current = Math.max(0, uiActionQueueDepthRef.current - 1);
					setUiActionQueueCount((count) => Math.max(0, count - 1));
					if (uiActionQueueDepthRef.current === 0) {
						await flushDeferredDashboardPayloadAfterQueue();
					}
				}
			};

			const queued = (uiActionQueueRef.current || Promise.resolve()).catch(() => null).then(run);
			uiActionQueueRef.current = queued;
			return queued;
		}

		function setAsyncActionState(key, active, label) {
			if (!key) {
				return;
			}
			queuedActionKeysRef.current[key] = !!active;
			setAsyncActions((current) => {
				const next = Object.assign({}, current || {});
				if (active) {
					next[key] = label || true;
				} else {
					delete next[key];
				}
				return next;
			});
		}

		function formatDashboardActionLabel(action) {
			const labels = {
				purge_all: 'Flush All Cache',
				object_cache_flush: 'Flush Object Cache',
				object_cache_full_count: 'Count Object Cache',
				warm_frontpage_html: 'Warm Homepage HTML Cache',
				warm_frontpage_html_css: 'Warm Homepage HTML Cache + CSS Bundle',
				varnish_flush_all: 'Flush Varnish',
				google_fonts_rebuild_cache: 'Rebuild Google Fonts Cache',
				performance_profile: 'Performance Profile',
			};
			return labels[action] || String(action || 'Dashboard action').replace(/_/g, ' ');
		}

		async function pollQueuedAction(jobId, toastId, labels) {
			let job = null;
			for (let attempt = 0; attempt < ACTION_QUEUE_MAX_POLLS; attempt++) {
				const response = await apiRequest('queue_status', { id: jobId });
				job = response && response.job ? response.job : null;
				if (job && ['done', 'failed'].indexOf(job.status) !== -1) {
					return job;
				}

				if (job && toastId && attempt % 8 === 0) {
					const actionName = formatDashboardActionLabel(job.action);
					const age = Number(job.age || 0);
					const suffix = age > 0 ? ' · running for ' + age + 's' : '';
					pushToast({
						id: toastId,
						type: 'info',
						text: actionName + ' is still running' + suffix + '. This notice will stay open until it finishes.',
						persistent: true,
					});
				}

				await sleep(ACTION_QUEUE_POLL_DELAY);
			}
			throw new Error('Dashboard processing action is still running. This notice will stay open; try the action again after it finishes or the safety timeout clears it.');
		}

		async function waitForSettingsSaveToSettle(maxWaitMs = 15000) {
			const startedAt = Date.now();
			while (settingsSaveInFlightRef.current && (Date.now() - startedAt) < maxWaitMs) {
				await sleep(50);
			}
			return !settingsSaveInFlightRef.current;
		}

		async function syncQueuedSettingsBeforeAction() {
			if (settingsSaveTimerRef.current || hasPendingSettingsPatch()) {
				pushToast({ id: 'ucwp-settings-queue', type: 'info', text: __("Saving queued settings before running action…", 'ultracache'), persistent: true });
				await flushQueuedSettings();
			}

			if (!(await waitForSettingsSaveToSettle())) {
				throw new Error('Settings are still saving. Please wait for the save to finish before running this action.');
			}

			if (hasPendingSettingsPatch()) {
				pushToast({ id: 'ucwp-settings-queue', type: 'info', text: __("Saving queued settings before running action…", 'ultracache'), persistent: true });
				await flushQueuedSettings();
				if (!(await waitForSettingsSaveToSettle())) {
					throw new Error('Settings are still saving. Please wait for the save to finish before running this action.');
				}
			}
		}

		async function queueDashboardAction(action, params, labels, key, afterResult) {
			const actionKey = key || action;
			const readable = formatDashboardActionLabel(action);
			return enqueueUiOperation(actionKey, readable, async (toastId) => {
				await syncQueuedSettingsBeforeAction();
				const queued = await apiRequest('queue_action', { action, params: params || {} });
				const job = queued && queued.job ? queued.job : null;
				if (!job || !job.id) {
					throw new Error('Dashboard processing action was not created.');
				}

				const isDirectTerminal = !!(job && job.direct && ['done', 'failed'].indexOf(job.status) !== -1);
				if (!isDirectTerminal) {
					apiRequest('queue_run', { id: job.id }).catch((error) => {
						pushToast({
							id: toastId,
							type: 'error',
							title: readable,
							text: error && error.message ? error.message : 'Dashboard action runner failed to start.',
						});
					});
				}

				const completed = isDirectTerminal ? job : await pollQueuedAction(job.id, toastId, labels);
				const result = completed && completed.result ? completed.result : {};
				applyDashboardPayload(result);
				if (typeof afterResult === 'function') {
					afterResult(result, completed);
				}
				if (!result.stats && !result.diagnostics && ['purge_all', 'object_cache_flush', 'object_cache_full_count', 'warm_frontpage_html', 'warm_frontpage_html_css', 'opcache_flush', 'apcu_flush', 'varnish_flush_all', 'google_fonts_rebuild_cache'].indexOf(action) !== -1) {
					try {
						await refreshStats();
					} catch (error) {}
				}
				const ok = completed && completed.status === 'done';
				if (!ok) {
					throw new Error((completed && completed.message) || ((labels && labels.failed) || 'Action failed.'));
				}
				return completed;
			}, {
				processingText: (labels && labels.queued) || (readable + ' processing…'),
				successText: (labels && labels.success) || (readable + ' completed.'),
				failedText: (labels && labels.failed) || (readable + ' failed.'),
			});
		}

		async function runPerformanceProfile(mode) {
			const normalizedMode = ['compact', 'verbose', 'callback'].indexOf(mode) !== -1 ? mode : 'compact';
			const actionKey = 'performance_profile_' + normalizedMode;
			await queueDashboardAction('performance_profile', { mode: normalizedMode }, {
				queued: 'Performance profiler processing via dashboard…',
				success: 'Performance profile completed.',
				failed: 'Performance profile failed.',
				runningLabel: 'Analyzing…',
			}, actionKey, (result) => {
				if (result && result.performanceProfile) {
					setPerformanceProfile(result.performanceProfile);
				}
			});
		}

		async function downloadPerformanceProfileJson() {
			try {
				const response = await apiRequest('performance_profile_last', {});
				if (!response || !response.profile) {
					throw new Error('No profiler JSON is available yet.');
				}
				if (response.performanceProfile) {
					setPerformanceProfile(response.performanceProfile);
				}
				const id = (response.performanceProfile && response.performanceProfile.requestId) ? response.performanceProfile.requestId : Date.now();
				triggerFileDownload('ultracache-performance-profile-' + String(id).slice(0, 32) + '.json', JSON.stringify(response.profile, null, 2), 'application/json');
				pushToast({ type: 'success', text: __("Profiler JSON prepared.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to download profiler JSON.' });
			}
		}

		async function clearPerformanceProfile() {
			try {
				await apiRequest('performance_profile_clear', {});
				setPerformanceProfile(null);
				pushToast({ type: 'success', text: __("Last performance profile cleared.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to clear performance profile.' });
			}
		}


		async function runCssDiagnosticsForUrl(url) {
			const targetUrl = String(url || '').trim() || ((typeof ucwp !== 'undefined' && ucwp && ucwp.frontendProbeUrl) ? String(ucwp.frontendProbeUrl || '') : '');
			if (!targetUrl) {
				pushToast({ type: 'warning', text: __("Enter a same-site URL to diagnose.", 'ultracache') });
				return null;
			}
			setCssDiagnosticsBusy(true);
			try {
				return await queueDashboardAction('performance_profile', { mode: 'compact', url: targetUrl }, {
					queued: 'CSS diagnostics profile processing via dashboard…',
					success: 'CSS diagnostics completed.',
					failed: 'CSS diagnostics failed.',
					runningLabel: 'Diagnosing CSS…',
				}, 'css_diagnostics_ui', (result) => {
					if (result && result.performanceProfile) {
						setPerformanceProfile(result.performanceProfile);
					}
				});
			} finally {
				setCssDiagnosticsBusy(false);
			}
		}

		async function downloadCssDiagnosticsJson() {
			try {
				const response = await apiRequest('performance_profile_last', {});
				if (!response || !response.profile) {
					throw new Error('No CSS diagnostics JSON is available yet. Run CSS Diagnostics first.');
				}
				if (response.performanceProfile) {
					setPerformanceProfile(response.performanceProfile);
				}
				const profile = response.performanceProfile || {};
				const id = profile.requestId || Date.now();
				triggerFileDownload('ultracache-css-diagnostics-' + String(id).slice(0, 32) + '.json', JSON.stringify(response.profile, null, 2), 'application/json');
				pushToast({ type: 'success', text: __("CSS diagnostics JSON prepared.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to download CSS diagnostics JSON.' });
			}
		}

		async function clearCssDiagnosticsResult() {
			await clearPerformanceProfile();
		}

		async function copyCssBundleExclusionSuggestion(value) {
			const text = String(value || '').trim();
			if (!text) {
				pushToast({ type: 'warning', text: __("No CSS exclusion suggestion is available for this source.", 'ultracache') });
				return;
			}
			try {
				if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
					await window.navigator.clipboard.writeText(text);
					pushToast({ type: 'success', text: __("CSS bundle exclusion line copied.", 'ultracache') });
					return;
				}
			} catch (error) {
				// Fall through to the manual prompt fallback below.
			}
			if (window.prompt) {
				window.prompt('Copy this line into CSS Bundle Exclusions after visual testing:', text);
			}
		}

		async function flushQueuedSettings() {
			if (settingsSaveTimerRef.current) {
				window.clearTimeout(settingsSaveTimerRef.current);
				settingsSaveTimerRef.current = null;
			}
			const patch = Object.assign({}, pendingSettingsPatchRef.current || {});
			pendingSettingsPatchRef.current = {};
			if (Object.keys(patch).length) {
				queueSettingsPatch(patch);
			}
		}

		function queueSettingsPatch(patch) {
			if (!patch || typeof patch !== 'object') {
				return;
			}
			const queuedPatch = Object.assign({}, patch || {});
			const next = Object.assign({}, settingsRef.current || {}, queuedPatch);
			settingsRef.current = next;
			setSettings(next);
			const criticalPatch = isCriticalSettingsPatch(queuedPatch);
			enqueueUiOperation('settings_save', criticalPatch ? 'Save critical settings' : 'Save settings', async () => {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(queuedPatch) });
				if (response && response.settings) {
					committedSettingsRef.current = Object.assign({}, response.settings);
				}
				applyDashboardPayload(response || {});
				return response;
			}, {
				processingText: criticalPatch ? 'Processing critical settings save…' : 'Processing settings save…',
				successText: criticalPatch ? 'Critical cache settings saved.' : 'Settings saved.',
				failedText: 'Settings save failed.',
			});
		}

		function getCurrentCssBundleScope() {
			return normalizeCssBundleScopeValue(settingsRef.current && settingsRef.current.cssBundleScope);
		}

		async function buildHomepageCssBundleBeforeWarm(scope, actionKey) {
			scope = normalizeCssBundleScopeValue(scope);
			if ('per-page' === scope) {
				return true;
			}

			const label = getCssWarmBundleLabel(scope, false);
			const completed = await queueDashboardAction('warm_frontpage_html_css', {}, {
				queued: label + ' build processing via dashboard…',
				success: label + ' built and homepage HTML warmed.',
				failed: label + ' build failed.',
				runningLabel: 'Building CSS…',
			}, actionKey || ('prepare_' + scope + '_css_bundle'));

			return !!(completed && completed.status === 'done');
		}

		async function warmFrontpageHtml() {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			setHomepageHtmlBusy(true);
			await queueDashboardAction('warm_frontpage_html', {}, {
				queued: 'Frontpage HTML warm processing via dashboard…',
				success: 'Frontpage HTML warm completed.',
				failed: 'Frontpage HTML warm failed.',
				runningLabel: 'Warming…',
			}, 'warm_frontpage_html');
			setHomepageHtmlBusy(false);
		}

		async function warmFrontpageHtmlCss() {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable CSS Bundling before using CSS bundle warm actions.", 'ultracache') });
				return;
			}
			setHomepageHtmlCssBusy(true);
			await queueDashboardAction('warm_frontpage_html_css', {}, {
				queued: 'Homepage HTML + CSS bundle warm processing via dashboard…',
				success: 'Homepage HTML + CSS bundle warm completed.',
				failed: 'Homepage HTML + CSS bundle warm failed.',
				runningLabel: 'Warming…',
			}, 'warm_frontpage_html_css');
			setHomepageHtmlCssBusy(false);
		}

		async function startWarmingAllWithFrontpageCss(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable CSS Bundling before using CSS bundle warm actions.", 'ultracache') });
				return;
			}
			if (!hasFullSiteWarmScope(settingsRef.current)) {
				pushToast({ type: 'warning', text: __("Select at least one full-site warm source first.", 'ultracache') });
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('full', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			const controls = getJobControls(jobType);
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			setAllUrlsCssBusy(true);
			try {
				if ('per-page' !== cssScope) {
					const prepared = await buildHomepageCssBundleBeforeWarm(cssScope, 'warm_full_prepare_' + cssScope + '_css');
					if (!prepared) {
						return;
					}
				}

				await runJob({
					type: jobType,
					label: 'Warming Full Site HTML Cache + ' + bundleLabel,
					cursor: '',
					nextCursor: '',
					processed: 0,
					total: 0,
					pendingItems: [],
					hasMore: true,
					logs: ['Starting full site crawler + ' + bundleLabel + '…'],
					startTime: Date.now(),
					batchSize: DEFAULT_QUEUE_BATCH_SIZE,
					warmupGeneration: Number(warmupGenerationRef.current || 0),
				}, !!forceRestart);
			} finally {
				setAllUrlsCssBusy(false);
			}
		}

		async function startMenuWarming(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			if (!hasMenuWarmScope(settingsRef.current)) {
				pushToast({ type: 'warning', text: __("Select a frontend menu and depth first.", 'ultracache') });
				return;
			}
			const controls = getJobControls('warm_menu');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm_menu',
				scope: 'menu',
				label: __("Warming Menu HTML Cache", 'ultracache'),
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting menu URL crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
				warmupGeneration: Number(warmupGenerationRef.current || 0),
			}, !!forceRestart);
		}

		async function startMenuWarmingWithFrontpageCss(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable CSS Bundling before using CSS bundle warm actions.", 'ultracache') });
				return;
			}
			if (!hasMenuWarmScope(settingsRef.current)) {
				pushToast({ type: 'warning', text: __("Select a frontend menu and depth first.", 'ultracache') });
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('menu', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			const controls = getJobControls(jobType);
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			setMenuUrlsCssBusy(true);
			try {
				if ('per-page' !== cssScope) {
					const prepared = await buildHomepageCssBundleBeforeWarm(cssScope, 'warm_menu_prepare_' + cssScope + '_css');
					if (!prepared) {
						return;
					}
				}

				await runJob({
					type: jobType,
					scope: 'menu',
					label: 'Warming Menu HTML Cache + ' + bundleLabel,
					cursor: '',
					nextCursor: '',
					processed: 0,
					total: 0,
					pendingItems: [],
					hasMore: true,
					logs: ['Starting menu URL crawler + ' + bundleLabel + '…'],
					startTime: Date.now(),
					batchSize: DEFAULT_QUEUE_BATCH_SIZE,
					warmupGeneration: Number(warmupGenerationRef.current || 0),
				}, !!forceRestart);
			} finally {
				setMenuUrlsCssBusy(false);
			}
		}

		function updateSetting(key, value) {
			if (key === 'objectCacheEnabled') {
				const currentForm = redisForm || {};
				const currentSettings = settingsRef.current || {};
				const backend = String(currentForm.objectCacheBackend || currentSettings.objectCacheBackend || 'redis').toLowerCase();
				const fallback = String(currentForm.objectCacheFallbackBackend || currentSettings.objectCacheFallbackBackend || 'apcu').toLowerCase();
				queueSettingsPatch({
					objectCacheEnabled: !!value,
					objectCacheBackend: ['redis', 'apcu', 'disk'].indexOf(backend) !== -1 ? backend : 'redis',
					objectCacheFallbackBackend: ['none', 'runtime'].indexOf(fallback) !== -1 ? 'none' : (['apcu', 'disk'].indexOf(fallback) !== -1 ? fallback : 'apcu'),
				});
				return;
			}
			queueSettingsPatch({ [key]: value });
		}

		function updateGoogleFontsLocalOptimization(value) {
			queueSettingsPatch({ googleFontsLocalOptimizationEnabled: !!value });
			if (!!value) {
				window.setTimeout(() => {
					queueDashboardAction('google_fonts_rebuild_cache', { clear: false }, {
						queued: 'Google Fonts homepage scan started…',
						runningLabel: 'Scanning Google Fonts…',
						success: 'Google Fonts homepage scan finished.',
						failed: 'Google Fonts homepage scan failed.',
						alreadyQueued: 'Google Fonts scan is already processing.',
					}, 'google_fonts_rebuild_cache');
				}, 0);
			}
		}

		async function saveGoogleFontsAdditionalScanUrls(value) {
			queueSettingsPatch({ googleFontsAdditionalScanUrls: value });
			if (settingsRef.current && settingsRef.current.googleFontsLocalOptimizationEnabled) {
				await queueDashboardAction('google_fonts_rebuild_cache', { clear: false }, {
					queued: 'Google Fonts URL scan started…',
					runningLabel: 'Scanning Google Fonts URLs…',
					success: 'Google Fonts URL scan finished.',
					failed: 'Google Fonts URL scan failed.',
					alreadyQueued: 'Google Fonts scan is already processing.',
				}, 'google_fonts_rebuild_cache');
			}
		}

		function updateSafeDeferJs(value) {
			const enabled = !!value;
			queueSettingsPatch({ deferJsEnabled: enabled });
			if (!enabled) {
				return;
			}
			window.setTimeout(() => {
				const current = settingsRef.current && typeof settingsRef.current.deferJsExcludeList !== 'undefined' ? settingsRef.current.deferJsExcludeList : '';
				scanSafeDeferInitScriptsAndMerge(current, true);
			}, 0);
		}

		async function updateDelayIconFontsAutoDetect(value) {
			const enabled = !!value;
			queueSettingsPatch({ delayIconFontsAutoDetectEnabled: enabled });
			if (!enabled) {
				return;
			}

			const response = await scanFrontpageFontPatterns();
			if (!response || !Array.isArray(response.delayIconFontsList) || !response.delayIconFontsList.length) {
				pushToast({ type: 'warning', text: __("Auto-detect is enabled, but no likely icon fonts were detected on the front page.", 'ultracache') });
				return;
			}

			const currentDraft = (settingsRef.current && settingsRef.current.delayIconFontsList) || '';
			const merged = mergeUniqueSettingLines(currentDraft, response.delayIconFontsList.join('\n'));
			if (merged.added) {
				queueSettingsPatch({ delayIconFontsList: merged.value });
				pushToast({ type: 'success', text: 'Auto-detect added ' + merged.added + ' front-page icon font pattern(s).' });
			} else {
				pushToast({ type: 'info', text: __("Detected icon font patterns are already listed.", 'ultracache') });
			}
		}

		async function rebuildGoogleFontsCacheFromSettings(currentDraft) {
			await queueDashboardAction('google_fonts_rebuild_cache', { clear: true }, {
				queued: 'Google Fonts cache rebuild started…',
				runningLabel: 'Rebuilding Google Fonts cache…',
				success: 'Google Fonts cache rebuilt.',
				failed: 'Google Fonts cache rebuild failed.',
				alreadyQueued: 'Google Fonts rebuild is already processing.',
			}, 'google_fonts_rebuild_cache');
			return String(currentDraft || '');
		}

		async function populateQueryStringAllowlist(currentDraft) {
			try {
				const response = await apiRequest('populate_query_allowlist', {});
				const items = Array.isArray(response && response.items) ? response.items : [];
				if (!items.length) {
					pushToast({ type: 'warning', text: __("No taxonomy/attribute query-string keys were detected.", 'ultracache') });
					return String(currentDraft || '');
				}
				const merged = mergeLineListAppendOnly(currentDraft, items);
				const addedCount = Math.max(0, normalizeLineListItems(merged).length - normalizeLineListItems(currentDraft).length);
				pushToast({ type: 'success', text: addedCount ? ('Appended ' + addedCount + ' detected taxonomy/attribute query-string key(s).') : 'Query-string whitelist already contains all detected taxonomy/attribute keys.' });
				return merged;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Query-string populate failed.' });
				return null;
			}
		}

		async function populateDefaultSettingList(settingKey, label) {
			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			const value = Object.prototype.hasOwnProperty.call(defaultsPayload, settingKey)
				? String(defaultsPayload[settingKey] || '')
				: '';
			if (!value.trim()) {
				pushToast({ type: 'warning', text: 'No recommended defaults are defined for ' + label + '.' });
				return '';
			}
			pushToast({ type: 'success', text: 'Populated ' + label + ' with recommended defaults.' });
			return value;
		}

		async function scanSafeDeferInitScriptsAndMerge(currentDraft, saveImmediately) {
			try {
				const response = await apiRequest('safe_defer_init_scan', {});
				const items = Array.isArray(response && response.items) ? response.items : [];
				if (!items.length) {
					pushToast({ type: 'info', text: response && response.message ? response.message : 'No likely theme/page-builder init scripts were detected.' });
					return String(currentDraft || '');
				}
				const merged = mergeUniqueSettingLines(currentDraft, items.join('\n'));
				if (saveImmediately && merged.added) {
					queueSettingsPatch({ deferJsExcludeList: merged.value });
				}
				pushToast({
					type: merged.added ? 'success' : 'info',
					text: merged.added ? ('Added ' + merged.added + ' detected theme/page-builder init exclusion(s).') : 'Detected init scripts are already listed in JS Delay / Defer Exclusions.',
				});
				return merged.value;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Safe Defer JS init-script scan failed.' });
				return null;
			}
		}

		async function populateDeferDelayExclusionDefaults(currentDraft) {
			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			let defaults = ucwp && typeof ucwp.jsDelayDeferRecommendedExclusions !== 'undefined'
				? String(ucwp.jsDelayDeferRecommendedExclusions || '')
				: '';
			if (!defaults.trim() && Object.prototype.hasOwnProperty.call(defaultsPayload, 'deferJsExcludeList')) {
				defaults = String(defaultsPayload.deferJsExcludeList || '');
			}
			// 2.57.96: Populate Defaults is intentionally limited to the
			// hard dependency floor. Slider, theme, WooCommerce, Elementor,
			// tracking, popup, and form fragments are left to Scan/Runtime Scan
			// or manual user additions so defaults do not over-protect heavy JS.
			if (!defaults.trim()) {
				pushToast({ type: 'warning', text: __("No recommended JS Delay / Defer defaults are defined.", 'ultracache') });
				return String(currentDraft || '');
			}
			const merged = mergeUniqueSettingLines(currentDraft, defaults);
			pushToast({ type: merged.added ? 'success' : 'info', text: merged.added ? ('Added ' + merged.added + ' missing default exclusion(s).') : 'All recommended JS Delay / Defer defaults are already present.' });
			return merged.value;
		}


		async function populateCssBundleExclusionDefaults(currentDraft) {
			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			const defaults = Object.prototype.hasOwnProperty.call(defaultsPayload, 'homepageCssBundleExcludeList')
				? String(defaultsPayload.homepageCssBundleExcludeList || '')
				: '';
			if (!defaults.trim()) {
				pushToast({ type: 'warning', text: __("No recommended CSS Bundle Exclusion defaults are defined.", 'ultracache') });
				return String(currentDraft || '');
			}
			const merged = mergeUniqueSettingLines(currentDraft, defaults);
			pushToast({ type: merged.added ? 'success' : 'info', text: merged.added ? ('Added ' + merged.added + ' missing CSS bundle exclusion default(s).') : 'All recommended CSS bundle defaults are already present.' });
			return merged.value;
		}

			async function scanFrontpageFontPatterns() {
				try {
					const response = await apiRequest('font_patterns_scan', {});
					if (response && response.message) {
						pushToast({ type: 'info', text: response.message });
					}
					return response || {};
				} catch (error) {
					pushToast({ type: 'error', text: error && error.message ? error.message : 'Front page font scan failed.' });
					return null;
				}
			}

			async function populateDelayIconFontsDefaults(currentDraft) {
				const response = await scanFrontpageFontPatterns();
				if (!response) {
					return null;
				}
				const items = Array.isArray(response.delayIconFontsList) ? response.delayIconFontsList : [];
				if (!items.length) {
					pushToast({ type: 'warning', text: __("No likely icon font patterns were detected on the front page.", 'ultracache') });
					return String(currentDraft || '');
				}
				const merged = mergeUniqueSettingLines(currentDraft, items.join('\n'));
				pushToast({ type: merged.added ? 'success' : 'info', text: merged.added ? ('Added ' + merged.added + ' detected icon font pattern(s).') : 'All detected icon font patterns are already present.' });
				return merged.value;
			}

			async function populateDelayIconFontExclusionDefaults(currentDraft) {
				const response = await scanFrontpageFontPatterns();
				if (!response) {
					return null;
				}
				const items = Array.isArray(response.delayIconFontsExcludeList) ? response.delayIconFontsExcludeList : [];
				if (!items.length) {
					pushToast({ type: 'warning', text: __("No non-icon front-page font patterns were detected.", 'ultracache') });
					return String(currentDraft || '');
				}
				const merged = mergeUniqueSettingLines(currentDraft, items.join('\n'));
				pushToast({ type: merged.added ? 'success' : 'info', text: merged.added ? ('Added ' + merged.added + ' detected non-icon font pattern(s).') : 'All detected non-icon font patterns are already present.' });
				return merged.value;
			}


		async function loadLatestJsDelaySafetyScan() {
			try {
				const response = await apiRequest('performance_profile_last', {});
				const profile = response && response.performanceProfile ? response.performanceProfile : null;
				if (profile) {
					setPerformanceProfile(profile);
				}
				const scan = profile && profile.jsDelaySafetyScan ? profile.jsDelaySafetyScan : null;
				if (!scan || !scan.available) {
					pushToast({ type: 'warning', text: __("No JS Delay Safety Scan is available. Run a Speed Diagnostics check for the page first.", 'ultracache') });
					return { available: false, suggestions: [], suggestionCount: 0, missingCount: 0 };
				}
				pushToast({ type: scan.missingCount ? 'warning' : 'success', text: scan.missingCount ? ('Found ' + scan.missingCount + ' missing suggested Defer/Delay exclusion(s).') : 'No missing JS delay exclusions found in the latest profile.' });
				return scan;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to load JS Delay Safety Scan.' });
				return { available: false, suggestions: [], suggestionCount: 0, missingCount: 0 };
			}
		}


		function sanitizeRuntimeJsScanDisplayUrl(url) {
			let value = String(url || '').trim();
			if (!value) {
				return '';
			}
			try {
				const parsed = new URL(value, window.location.origin);
				['ucwp_runtime_js_scan', 'ucwp_runtime_js_scan_id', 'ucwp_runtime_js_scan_nonce', 'ucwp_runtime_js_scan_context', 'ucwp_rt', 'ucwp_profile_bypass', 'ucwp_store_profile', 'ucwp_callback_profile', 'ucwp_store_profile_verbose', 'ucwp_store_profile_verbose_settings', 'ucwp_profile_run', 'ucwp_revalidate'].forEach((key) => parsed.searchParams.delete(key));
				return parsed.toString();
			} catch (error) {
				return value.replace(/([?&])ucwp_(runtime_js_scan(?:_id|_nonce|_context)?|rt|profile_bypass|store_profile(?:_verbose(?:_settings)?)?|callback_profile|profile_run|revalidate)=[^&#]*/g, '$1').replace(/[?&]$/, '');
			}
		}

		function buildRuntimeJsScanUrl(url, scanId, context) {
			let target = String(url || '').trim() || ((ucwp && ucwp.frontendProbeUrl) ? ucwp.frontendProbeUrl : '/');
			let parsed;
			try {
				parsed = new URL(target, window.location.origin);
			} catch (error) {
				parsed = new URL((ucwp && ucwp.frontendProbeUrl) ? ucwp.frontendProbeUrl : '/', window.location.origin);
			}
			const scanContext = context === 'logged-in' ? 'logged-in' : 'anonymous';
			parsed.searchParams.set('ucwp_runtime_js_scan', '1');
			parsed.searchParams.set('ucwp_runtime_js_scan_id', scanId);
			parsed.searchParams.set('ucwp_runtime_js_scan_nonce', ucwp.runtimeJsScanNonce || '');
			parsed.searchParams.set('ucwp_runtime_js_scan_context', scanContext);
			parsed.searchParams.set('ucwp_rt', String(Date.now()));
			return parsed.toString();
		}

		function normalizeRuntimeJsScanResult(report, scanUrl) {
			const scan = report && report.jsDelaySafetyScan ? report.jsDelaySafetyScan : null;
			if (!scan || !scan.available) {
				return {
					available: false,
					source: 'browser-runtime',
					suggestions: [],
					suggestionCount: 0,
					missingCount: 0,
					runtimeErrorCount: report && report.errorCount ? report.errorCount : 0,
					scanContext: (report && report.scanContext) ? String(report.scanContext) : 'anonymous',
					scannedUrl: sanitizeRuntimeJsScanDisplayUrl(scanUrl),
				};
			}
			return Object.assign({}, scan, {
				available: true,
				source: 'browser-runtime',
				scannedUrl: sanitizeRuntimeJsScanDisplayUrl(scan.scannedUrl || (report && report.url) || scanUrl),
				scannedAt: new Date().toISOString(),
			});
		}


		function readPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl) {
			try {
				if (!popup || popup.closed || !popup.__ucwpRuntimeJsScan) {
					return null;
				}
				const state = popup.__ucwpRuntimeJsScan;
				const errors = Array.isArray(state.errors) ? state.errors.slice(0, 120) : [];
				return {
					scanId,
					url: sanitizeRuntimeJsScanDisplayUrl(String((popup.location && popup.location.href) || scanUrl || '')),
					completed: false,
					scanContext: (popup.__ucwpRuntimeJsScan && popup.__ucwpRuntimeJsScan.context) ? String(popup.__ucwpRuntimeJsScan.context) : 'anonymous',
					errors,
					userAgent: String((popup.navigator && popup.navigator.userAgent) || ''),
					elapsedMs: state.injectedAt ? Math.max(0, Date.now() - Number(state.injectedAt || 0)) : 0,
					debug: Object.assign({}, state.debug || {}, { directHarvest: true, sentCount: state.sentCount || 0 }),
				};
			} catch (error) {
				return null;
			}
		}

		async function submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, completed) {
			const snapshot = readPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl);
			if (!snapshot) {
				return null;
			}
			snapshot.completed = !!completed;
			try {
				const response = await apiRequest('runtime_js_scan_submit', snapshot);
				return response && response.runtimeJsScan ? response.runtimeJsScan : null;
			} catch (error) {
				return null;
			}
		}

		async function runBrowserRuntimeJsScanForUrl(url, onStatus, options) {
			const scanOptions = options && typeof options === 'object' ? options : {};
			const scanContext = scanOptions.context === 'logged-in' ? 'logged-in' : 'anonymous';
			function setRuntimeStatus(message) {
				if (typeof onStatus === 'function') {
					onStatus(message);
				}
			}
			const scanUrl = String(url || '').trim() || ((ucwp && ucwp.frontendProbeUrl) ? ucwp.frontendProbeUrl : '/');
			const scanId = 'rt_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
			const runtimeUrl = buildRuntimeJsScanUrl(scanUrl, scanId, scanContext);
			setRuntimeStatus('Opening ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' diagnostic page…');
			const popup = window.open(runtimeUrl, 'ucwpRuntimeJsScan', 'width=1280,height=900');
			if (!popup) {
				setRuntimeStatus('Popup was blocked. Allow popups for this admin page and try again. Diagnostic URL: ' + runtimeUrl);
				pushToast({ type: 'error', text: __("Browser blocked the runtime scan window. Allow popups for this admin page and try again.", 'ultracache') });
				return { available: false, suggestions: [], suggestionCount: 0, missingCount: 0, scanContext: scanContext, scannedUrl: sanitizeRuntimeJsScanDisplayUrl(scanUrl), debugUrl: runtimeUrl };
			}

			try { popup.focus(); } catch (error) {}
			setRuntimeStatus('Diagnostic page opened in ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' mode. Waiting for browser errors…');
			pushToast({ type: 'info', text: 'Runtime Scan opened the page in ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' mode. Keep it open for a few seconds.' });
			let latestReport = null;
			for (let i = 0; i < 18; i++) {
				setRuntimeStatus('Waiting for runtime scan report… ' + (i + 1) + '/18');
				await sleep(i < 2 ? 900 : 1200);
				const directReport = await submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, false);
				if (directReport && Number(directReport.errorCount || 0) > 0) {
					latestReport = directReport;
				}
				try {
					const response = await apiRequest('runtime_js_scan_report', { scanId });
					const fetchedReport = response && response.runtimeJsScan ? response.runtimeJsScan : null;
					if (fetchedReport) {
						if (!latestReport || Number(fetchedReport.errorCount || 0) >= Number(latestReport.errorCount || 0)) {
							latestReport = fetchedReport;
						}
						if (latestReport && latestReport.completed && Number(latestReport.errorCount || 0) > 0) {
							break;
						}
					}
				} catch (error) {}
			}
			const finalDirectReport = await submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, true);
			if (finalDirectReport && (!latestReport || Number(finalDirectReport.errorCount || 0) >= Number(latestReport.errorCount || 0))) {
				latestReport = finalDirectReport;
			}

			if (!latestReport) {
				setRuntimeStatus('No runtime report returned. The diagnostic page may have been served from cache or blocked.');
				pushToast({ type: 'warning', text: __("Runtime scan did not return a report. Check that the diagnostic page opened and that cache bypass is active.", 'ultracache') });
				return { available: false, source: 'browser-runtime', suggestions: [], suggestionCount: 0, missingCount: 0, scanContext: scanContext, scannedUrl: sanitizeRuntimeJsScanDisplayUrl(scanUrl) };
			}

			const result = normalizeRuntimeJsScanResult(latestReport, scanUrl);
			const missingCount = Number(result.missingCount || 0);
			const runtimeErrorCount = Number(result.runtimeErrorCount || latestReport.errorCount || 0);
			if (runtimeErrorCount > 0) {
				setRuntimeStatus('Runtime scan captured ' + runtimeErrorCount + ' browser error(s).');
				pushToast({ type: missingCount ? 'warning' : 'info', text: 'Runtime scan captured ' + runtimeErrorCount + ' browser error(s)' + (missingCount ? ' and found ' + missingCount + ' missing exclusion suggestion(s).' : '.') });
			} else {
				setRuntimeStatus('Runtime scan completed with no browser JS errors captured.');
				pushToast({ type: 'success', text: __("Runtime scan completed with no browser JS errors captured.", 'ultracache') });
			}
			return result;
		}


		async function runJsDelaySafetyScanForUrl(url) {
			const scanUrl = String(url || '').trim() || ((ucwp && ucwp.frontendProbeUrl) ? ucwp.frontendProbeUrl : '/');
			const completed = await queueDashboardAction('performance_profile', { mode: 'compact', url: scanUrl }, {
				queued: 'JS Delay Safety Scan queued…',
				success: 'JS Delay Safety Scan completed.',
				failed: 'JS Delay Safety Scan failed.',
				runningLabel: 'Scanning JS delay issues…',
			}, 'performance_profile_js_delay_scan', (result) => {
				if (result && result.performanceProfile) {
					setPerformanceProfile(result.performanceProfile);
				}
			});
			const result = completed && completed.result ? completed.result : {};
			const profile = result && result.performanceProfile ? result.performanceProfile : null;
			const scan = profile && profile.jsDelaySafetyScan ? profile.jsDelaySafetyScan : null;
			if (!scan || !scan.available) {
				pushToast({ type: 'warning', text: __("No JS delay dependency suggestions were found for this URL.", 'ultracache') });
				return { available: false, suggestions: [], suggestionCount: 0, missingCount: 0, scannedUrl: scanUrl };
			}
			const enrichedScan = Object.assign({}, scan, {
				scannedUrl: (profile && (profile.profileUrl || profile.url)) ? (profile.profileUrl || profile.url) : scanUrl,
				scannedAt: profile && profile.scannedAt ? profile.scannedAt : '',
			});
			pushToast({ type: enrichedScan.missingCount ? 'warning' : 'success', text: enrichedScan.missingCount ? ('Found ' + enrichedScan.missingCount + ' missing suggested Defer/Delay exclusion(s).') : 'No missing JS delay exclusions found for this URL.' });
			return enrichedScan;
		}
		function updateMediaOptimizationSetting(value) {

			queueSettingsPatch({
				mediaOptimizationEnabled: value,
			});
		}


		function updateSliderSafeModeSetting(value) {
			queueSettingsPatch({
				sliderSafeModeEnabled: value,
			});
		}

		function normalizeProfileBackendProbeResult(backend, response, error) {
			const source = response && typeof response === 'object' ? response : (error && error.data && typeof error.data === 'object' ? error.data : null);
			const message = source && source.message ? String(source.message) : (error && error.message ? String(error.message) : '');
			if (source && source.success) {
				return { backend: backend, status: 'available', success: true, message: message || (backend.toUpperCase() + ' backend probe passed.'), response: source };
			}
			if (source) {
				return { backend: backend, status: 'unavailable', success: false, message: message || (backend.toUpperCase() + ' backend is unavailable.'), response: source };
			}
			return { backend: backend, status: 'indeterminate', success: false, message: message || ('Could not verify ' + backend.toUpperCase() + ' backend availability.'), response: null };
		}

		async function probeObjectCacheBackendForProfile(backend, basePatch) {
			const currentSettings = settingsRef.current || {};
			const currentRedisForm = redisForm || {};
			const payload = Object.assign({}, currentSettings, currentRedisForm, basePatch || {}, {
				backend: backend,
				profileProbe: true,
				skipPayloadProbe: true,
				redisPasswordConfigured: !!(currentSettings.redisPasswordConfigured || currentRedisForm.redisPasswordConfigured),
			});
			if (!String(payload.redisPassword || '').trim()) {
				delete payload.redisPassword;
			}
			try {
				const response = await apiRequest('object_cache_test', payload);
				return normalizeProfileBackendProbeResult(backend, response, null);
			} catch (error) {
				return normalizeProfileBackendProbeResult(backend, null, error);
			}
		}

		function assertProfileProbeDeterminate(result) {
			if (result && result.status !== 'indeterminate') {
				return;
			}
			const backend = result && result.backend ? result.backend.toUpperCase() : 'object cache';
			const message = result && result.message ? result.message : 'The backend availability probe did not complete.';
			throw new Error('Profile object-cache detection stopped: ' + backend + ' availability is indeterminate. ' + message);
		}

		async function getProfileDiskFallbackPatch() {
			const diskResult = await probeObjectCacheBackendForProfile('disk', {});
			assertProfileProbeDeterminate(diskResult);
			return diskResult.status === 'available' ? 'disk' : 'none';
		}

		async function getProfileObjectCachePatch(basePatch) {
			// Profile detection is part of the queued profile operation and must be
			// resolved in deterministic Redis > APCu > Disk order. Do not run these
			// probes in parallel and do not fall back to Disk on indeterminate REST
			// results, otherwise fast follow-up clicks can save the wrong backend.
			const redisResult = await probeObjectCacheBackendForProfile('redis', basePatch);
			assertProfileProbeDeterminate(redisResult);

			if (redisResult.status === 'available') {
				const apcuFallbackResult = await probeObjectCacheBackendForProfile('apcu', basePatch);
				assertProfileProbeDeterminate(apcuFallbackResult);
				return {
					objectCacheEnabled: true,
					objectCacheBackend: 'redis',
					objectCacheFallbackBackend: apcuFallbackResult.status === 'available' ? 'apcu' : await getProfileDiskFallbackPatch(),
				};
			}

			const apcuResult = await probeObjectCacheBackendForProfile('apcu', basePatch);
			assertProfileProbeDeterminate(apcuResult);

			if (apcuResult.status === 'available') {
				return {
					objectCacheEnabled: true,
					objectCacheBackend: 'apcu',
					objectCacheFallbackBackend: await getProfileDiskFallbackPatch(),
				};
			}

			const diskResult = await probeObjectCacheBackendForProfile('disk', basePatch);
			assertProfileProbeDeterminate(diskResult);
			if (diskResult.status === 'available') {
				return {
					objectCacheEnabled: true,
					objectCacheBackend: 'disk',
					objectCacheFallbackBackend: 'none',
				};
			}

			throw new Error('Profile object-cache detection found no available persistent backend. Redis, APCu and Disk probes were unavailable.');
		}

		async function getProfileQueryAllowlistPatch() {
			// Profiles must not overwrite or append to user-maintained visible lists.
			// Query-string allowlist population remains available from its dedicated UI action.
			return {};
		}

		async function applyPerformanceProfile(profileKey) {
			const profile = PERFORMANCE_PROFILES[profileKey];
			if (!profile || !profile.patch) {
				return;
			}
			return enqueueUiOperation('apply_profile_' + profileKey, 'Apply ' + profile.label + ' profile', async (toastId) => {
				const basePatch = getPerformanceProfilePatch(profileKey);
				const splitPatch = splitProfileObjectCachePatch(basePatch);
				const queryAllowlistPatch = profileKey === 'off' ? {} : await getProfileQueryAllowlistPatch();
				const mainPatch = Object.assign({}, splitPatch.mainPatch, queryAllowlistPatch);
				const firstPassOptimistic = Object.assign({}, settingsRef.current || {}, mainPatch);
				settingsRef.current = firstPassOptimistic;
				setSettings(firstPassOptimistic);
				setAdvancedForm((prev) => Object.assign({}, prev, {
					cacheFreshTtlMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheFreshTtlMinutes') ? mainPatch.cacheFreshTtlMinutes : prev.cacheFreshTtlMinutes,
					cacheMaxStaleMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheMaxStaleMinutes') ? mainPatch.cacheMaxStaleMinutes : prev.cacheMaxStaleMinutes,
				}));

				pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Saving profile settings…", 'ultracache'), persistent: true });
				const firstResponse = await apiRequest('save_settings', { settings_json: JSON.stringify(mainPatch) });
				applyDashboardPayload(firstResponse || {});
				pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: __("Profile settings saved.", 'ultracache'), persistent: true });

				let objectCachePatch = {};
				let objectCacheWarning = '';
				pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Setting up Object Cache…", 'ultracache'), persistent: true });
				if (profileKey === 'off') {
					objectCachePatch = Object.assign({}, splitPatch.objectPatch, { objectCacheEnabled: false });
				} else if (Object.prototype.hasOwnProperty.call(splitPatch.objectPatch, 'objectCacheEnabled') && !splitPatch.objectPatch.objectCacheEnabled) {
					objectCachePatch = Object.assign({}, splitPatch.objectPatch, { objectCacheEnabled: false });
				} else {
					try {
						objectCachePatch = await getProfileObjectCachePatch(Object.assign({}, settingsRef.current || {}, basePatch));
					} catch (error) {
						objectCacheWarning = error && error.message ? error.message : 'Object Cache backend detection failed.';
					}
				}

				if (objectCachePatch && Object.keys(objectCachePatch).length) {
					const objectOptimistic = Object.assign({}, settingsRef.current || {}, objectCachePatch);
					settingsRef.current = objectOptimistic;
					setSettings(objectOptimistic);
					setRedisForm((prev) => Object.assign({}, prev, objectCachePatch));
					const objectResponse = await apiRequest('save_settings', { settings_json: JSON.stringify(objectCachePatch) });
					applyDashboardPayload(objectResponse || {});
					pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: objectCachePatch.objectCacheEnabled === false ? 'Object Cache disabled.' : 'Object Cache set up.', persistent: true });
				} else if (objectCacheWarning) {
					pushToast({ type: 'warning', title: __("Object Cache setup skipped", 'ultracache'), text: profile.label + ' profile settings were saved, but Object Cache was not changed. ' + objectCacheWarning });
				} else {
					pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: __("Object Cache unchanged.", 'ultracache'), persistent: true });
				}

				if (!!mainPatch.deferJsEnabled) {
					pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Running Safe Defer JS follow-up scan…", 'ultracache'), persistent: true });
					const currentExclusions = settingsRef.current && typeof settingsRef.current.deferJsExcludeList !== 'undefined' ? settingsRef.current.deferJsExcludeList : '';
					const scannedExclusions = await scanSafeDeferInitScriptsAndMerge(currentExclusions, false);
					if (scannedExclusions !== null) {
						const scanPatch = { deferJsExcludeList: String(scannedExclusions || '') };
						const scanOptimistic = Object.assign({}, settingsRef.current || {}, scanPatch);
						settingsRef.current = scanOptimistic;
						setSettings(scanOptimistic);
						const scanResponse = await apiRequest('save_settings', { settings_json: JSON.stringify(scanPatch) });
						applyDashboardPayload(scanResponse || {});
						pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: String(scanPatch.deferJsExcludeList || '') === String(currentExclusions || '') ? 'Safe Defer JS scan finalized; no new init exclusions were needed.' : 'Safe Defer JS scan exclusions saved.', persistent: true });
					}
				}

				return { firstResponse: firstResponse, objectCacheWarning: objectCacheWarning };
			}, { processingText: 'Preparing ' + profile.label + ' profile…', successText: (result) => result && result.objectCacheWarning ? (profile.label + ' profile settings saved. Object Cache was not changed.') : (profile.label + ' profile applied.'), failedText: profile.label + ' profile failed.' });
		}

		function updateAdvancedField(key, value) {
			setAdvancedForm((prev) => Object.assign({}, prev, { [key]: value }));
		}

		async function saveAdvancedSettings() {
			return enqueueUiOperation('advanced_settings_save', 'Save advanced settings', async () => {
				const formSnapshot = Object.assign({}, advancedForm || {});
				const response = await apiRequest('save_settings', {
					settings_json: JSON.stringify({
						cacheCleanupIntervalHours: Number(formSnapshot.cacheCleanupIntervalHours || 24),
						cssBundleCleanupGraceHours: Number(formSnapshot.cssBundleCleanupGraceHours || 48),
						cssBundleCleanupDeleteLimit: Number(formSnapshot.cssBundleCleanupDeleteLimit || 60),
						cronWarmPagesPerMinute: Number(formSnapshot.cronWarmPagesPerMinute || 0),
						scheduledWarmLimit: Number(formSnapshot.scheduledWarmLimit || 1),
						cacheFreshTtlMinutes: Number(formSnapshot.cacheFreshTtlMinutes || 15),
						cacheMaxStaleMinutes: Number(formSnapshot.cacheMaxStaleMinutes || 720),
					}),
				});
				applyDashboardPayload(response || {});
				if (response && response.settings) {
					setAdvancedForm((prev) => Object.assign({}, prev, {
						cacheCleanupIntervalHours: response.settings.cacheCleanupIntervalHours || prev.cacheCleanupIntervalHours,
						cssBundleCleanupGraceHours: typeof response.settings.cssBundleCleanupGraceHours === 'undefined' ? prev.cssBundleCleanupGraceHours : response.settings.cssBundleCleanupGraceHours,
						cssBundleCleanupDeleteLimit: typeof response.settings.cssBundleCleanupDeleteLimit === 'undefined' ? prev.cssBundleCleanupDeleteLimit : response.settings.cssBundleCleanupDeleteLimit,
						cronWarmPagesPerMinute: typeof response.settings.cronWarmPagesPerMinute === 'undefined' ? prev.cronWarmPagesPerMinute : response.settings.cronWarmPagesPerMinute,
						scheduledWarmLimit: typeof response.settings.scheduledWarmLimit === 'undefined' ? prev.scheduledWarmLimit : response.settings.scheduledWarmLimit,
						cacheFreshTtlMinutes: response.settings.cacheFreshTtlMinutes || prev.cacheFreshTtlMinutes,
						cacheMaxStaleMinutes: response.settings.cacheMaxStaleMinutes || prev.cacheMaxStaleMinutes,
					}));
				}
				return response;
			}, { processingText: 'Processing advanced settings save…', successText: 'Advanced settings saved.', failedText: 'Failed to save advanced settings.' });
		}

		async function inspectCacheDecision() {
			if (inspectBusy || !String(inspectUrl || '').trim()) {
				return;
			}

			setInspectBusy(true);
			try {
				const response = await apiRequest('inspect_url', { url: String(inspectUrl || '').trim() });
				setInspectResult(response || null);
			} catch (error) {
				setInspectResult(null);
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to inspect URL.',
				});
			} finally {
				setInspectBusy(false);
			}
		}


		function persistJobState(job) {
			if (job && job.type) {
				saveJob(job);
				setSavedJob(job);
			} else {
				clearSavedJob();
				setSavedJob(null);
			}
		}

		function setCurrentWarmupGeneration(value) {
			const next = Math.max(0, Number(value || 0));
			warmupGenerationRef.current = next;
			ucwp.warmupGeneration = next;
			setWarmupGeneration(next);
		}

		function getJobControls(type) {
			if (!savedJob || savedJob.type !== type) {
				return { canResume: false, canRestart: false, staleAfterFlush: false };
			}

			const processed = Math.max(0, Number(savedJob.processed || 0));
			const total = Math.max(0, Number(savedJob.total || 0));
			const hasPending = Array.isArray(savedJob.pendingItems) && savedJob.pendingItems.length > 0;
			const hasProgress = processed > 0 || total > 0 || hasPending || (Array.isArray(savedJob.logs) && savedJob.logs.length > 0);
			const incomplete = hasPending || !!savedJob.hasMore || total === 0 || processed < total;
			const staleAfterFlush = isWarmJobType(type) && typeof savedJob.warmupGeneration !== 'undefined' && Number(savedJob.warmupGeneration || 0) !== Number(warmupGenerationRef.current || 0);

			return {
				canResume: hasProgress && incomplete && !staleAfterFlush,
				canRestart: hasProgress && incomplete && !staleAfterFlush,
				staleAfterFlush,
			};
		}

		function updateProcessState(state, overrides = {}) {
			const total = Number(state.total || 0);
			const current = total > 0 ? Math.min(Number(state.processed || 0), total) : Number(state.processed || 0);
			setProcess({
				type: state.type || '',
				active: !!state.active,
				label: state.label || '',
				current: current,
				total: total,
				queueBuilding: !!state.queueBuilding,
				unitCount: Math.max(0, Number(state.unitCount || 0)),
				avifCount: Math.max(0, Number(state.avifCount || 0)),
				webpCount: Math.max(0, Number(state.webpCount || 0)),
				logs: Array.isArray(state.logs) ? state.logs : [],
				startTime: Number(state.startTime || 0),
				cancellable: !!state.active,
				cancelRequested: !!state.cancelRequested,
				showWhenInactive: !!state.showWhenInactive || state.type === 'media',
				...overrides,
			});
		}

		function requestCancel() {
			if (!process.active || cancelRequestedRef.current) {
				return;
			}
			cancelRequestedRef.current = true;
			setProcess((prev) => Object.assign({}, prev, { cancelRequested: true }));
		}

		async function runJob(job, forceRestart) {
			let state = Object.assign({}, job, {
				cursor: forceRestart ? '' : (typeof job.cursor === 'string' ? job.cursor : ''),
				nextCursor: forceRestart ? '' : (typeof job.nextCursor === 'string' ? job.nextCursor : ''),
				processed: forceRestart ? 0 : Number(job.processed || 0),
				total: Number(job.total || 0),
				pendingItems: forceRestart ? [] : (Array.isArray(job.pendingItems) ? job.pendingItems.slice(0, DEFAULT_QUEUE_BATCH_SIZE) : []),
				hasMore: forceRestart ? true : (typeof job.hasMore === 'boolean' ? job.hasMore : true),
				logs: Array.isArray(job.logs) ? job.logs.filter((line) => line !== 'Paused by user.').slice(-50) : [],
				unitCount: forceRestart ? 0 : Math.max(0, Number(job.unitCount || 0)),
				avifCount: forceRestart ? 0 : Math.max(0, Number(job.avifCount || 0)),
				webpCount: forceRestart ? 0 : Math.max(0, Number(job.webpCount || 0)),
				queueBuilding: forceRestart ? false : !!job.queueBuilding,
				active: true,
				cancelRequested: false,
				startTime: forceRestart ? Date.now() : (job.startTime || Date.now()),
				batchSize: Math.max(1, Number(job.batchSize || DEFAULT_QUEUE_BATCH_SIZE)),
				successCount: forceRestart ? 0 : Math.max(0, Number(job.successCount || 0)),
				skippedCount: forceRestart ? 0 : Math.max(0, Number(job.skippedCount || 0)),
				failedCount: forceRestart ? 0 : Math.max(0, Number(job.failedCount || 0)),
				warmupGeneration: isWarmJobType(job.type) ? Number(warmupGenerationRef.current || 0) : job.warmupGeneration,
			});
			let completed = false;
			cancelRequestedRef.current = false;
			setBusy(true);
			updateProcessState(state);
			persistJobState(state);

			try {
				while (true) {
					if (cancelRequestedRef.current) {
						state = Object.assign({}, state, {
							active: false,
							cancelRequested: true,
							logs: state.logs.concat(['Paused by user.']).slice(-50),
						});
						persistJobState(state);
						updateProcessState(state, { active: false, cancellable: false });
						pushToast({ type: 'success', text: __("Job paused. You can resume it later.", 'ultracache') });
						break;
					}

					let batchItems = Array.isArray(state.pendingItems) ? state.pendingItems.slice() : [];
					let batchNextCursor = state.nextCursor || state.cursor || '';
					let batchHasMore = typeof state.hasMore === 'boolean' ? state.hasMore : true;

					if (!batchItems.length) {
						const batch = await fetchJobBatch(state.type, state.cursor || '', state.batchSize, state.scope || getWarmScopeForType(state.type));
						batchItems = Array.isArray(batch.items) ? batch.items.slice() : [];
						batchNextCursor = batch.nextCursor || '';
						batchHasMore = !!batch.hasMore;
						const mediaQueueCompleted = state.type === 'media' ? Math.max(0, Number(batch.queueCompleted || 0)) : 0;
						state = Object.assign({}, state, {
							total: Math.max(Number(state.total || 0), Number(batch.total || 0), mediaQueueCompleted),
							processed: state.type === 'media' ? Math.max(Number(state.processed || 0), mediaQueueCompleted) : Number(state.processed || 0),
							queueBuilding: state.type === 'media' ? !!batch.queueBuilding : false,
							hasMore: batchHasMore,
							nextCursor: batchNextCursor,
							pendingItems: batchItems.slice(),
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (!batchItems.length) {
						completed = !batchHasMore;
						if (!completed) {
							state = Object.assign({}, state, {
								cursor: batchNextCursor,
								nextCursor: '',
								hasMore: batchHasMore,
							});
							persistJobState(state);
						}
						if (completed) {
							break;
						}
						continue;
					}

					for (let i = 0; i < batchItems.length; i++) {
						if (cancelRequestedRef.current) {
							break;
						}

						const item = batchItems[i];
						const itemResult = await processJobItem(state.type, item);
						const line = itemResult && typeof itemResult === 'object' ? itemResult.line : itemResult;
						const progressIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.progressIncrement || 0)) : 1;
						const attachmentIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.attachmentIncrement || 0)) : 0;
						const unitIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.unitIncrement || 0)) : 0;
						const successIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.successIncrement || 0)) : 1;
						const skippedIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.skippedIncrement || 0)) : 0;
						const failedIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.failedIncrement || 0)) : 0;
						const avifIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.avifIncrement || 0)) : 0;
						const webpIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.webpIncrement || 0)) : 0;
						state = Object.assign({}, state, {
							label: state.type === 'media' && (avifIncrement > 0 || webpIncrement > 0) ? 'Optimizing Media' : state.label,
							processed: Number(state.processed || 0) + progressIncrement,
							attachmentsProcessed: Number(state.attachmentsProcessed || 0) + attachmentIncrement,
							unitCount: Number(state.unitCount || 0) + unitIncrement,
							avifCount: Number(state.avifCount || 0) + avifIncrement,
							webpCount: Number(state.webpCount || 0) + webpIncrement,
							successCount: Number(state.successCount || 0) + successIncrement,
							skippedCount: Number(state.skippedCount || 0) + skippedIncrement,
							failedCount: Number(state.failedCount || 0) + failedIncrement,
							logs: state.logs.concat([line]).slice(-50),
							pendingItems: batchItems.slice(i + 1),
							nextCursor: batchNextCursor,
							hasMore: batchHasMore,
							cancelRequested: cancelRequestedRef.current,
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (cancelRequestedRef.current) {
						continue;
					}

					state = Object.assign({}, state, {
						cursor: batchNextCursor,
						nextCursor: '',
						pendingItems: [],
						hasMore: batchHasMore,
					});
					updateProcessState(state);
					persistJobState(state);

					if (!batchHasMore && !state.pendingItems.length) {
						completed = true;
						break;
					}
				}

				if (completed) {
					const failedCount = Math.max(0, Number(state.failedCount || 0));
					const skippedCount = Math.max(0, Number(state.skippedCount || 0));
					const successCount = Math.max(0, Number(state.successCount || 0));
					let finalNotice = { type: 'success', text: isWarmJobType(state.type) ? 'Cache warming complete.' : 'Media optimization complete.' };
					if (isWarmJobType(state.type)) {
						const subject = isWarmCssJobType(state.type) ? (getWarmScopeForType(state.type) === 'menu' ? 'Menu HTML + CSS bundle warm' : 'Full site HTML + CSS bundle warm') : 'Cache warming';
						if (failedCount > 0) {
							finalNotice = {
								type: 'warning',
								text: subject + ' completed with ' + failedCount + ' failed URL' + (failedCount === 1 ? '' : 's') + ', ' + successCount + ' cached, ' + skippedCount + ' skipped.',
							};
						} else if (skippedCount > 0) {
							finalNotice = {
								type: 'success',
								text: subject + ' complete: ' + successCount + ' cached, ' + skippedCount + ' skipped.',
							};
						} else {
							finalNotice = { type: 'success', text: subject + ' complete: ' + successCount + ' cached.' };
						}
					} else if (failedCount > 0) {
						finalNotice = { type: 'warning', text: 'Media optimization completed with ' + failedCount + ' failed item' + (failedCount === 1 ? '' : 's') + '.' };
					}
					state = Object.assign({}, state, { logs: state.logs.concat([finalNotice.text]).slice(-50) });
					await refreshStats();
					pushToast(finalNotice);
					setProcess((prev) => Object.assign({}, prev, { active: false, cancellable: false, cancelRequested: false }));
					persistJobState(null);
				}
			} catch (error) {
				state = Object.assign({}, state, { active: false, cancelRequested: false });
				persistJobState(state);
				updateProcessState(state, { active: false, cancellable: false, cancelRequested: false });
				pushToast({ type: 'error', text: error && error.message ? error.message : (isWarmJobType(state.type) ? 'Cache warming failed.' : 'Media optimization failed.') });
			} finally {
				cancelRequestedRef.current = false;
				setBusy(false);
			}
		}

		async function startWarming(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __("Please enable Page Caching first or select a profile before warming cache.", 'ultracache') });
				return;
			}
			if (!hasFullSiteWarmScope(settingsRef.current)) {
				pushToast({ type: 'warning', text: __("Select at least one full-site warm source first.", 'ultracache') });
				return;
			}
			const controls = getJobControls('warm');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm',
				label: __("Warming Full Site HTML Cache", 'ultracache'),
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting full site crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
				warmupGeneration: Number(warmupGenerationRef.current || 0),
			}, !!forceRestart);
		}

		async function startMediaOptimization(forceRestart = false) {
			const controls = getJobControls('media');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			try {
				const preflight = await fetchJobBatch('media', '', 1, 'media');
				if (preflight.queue) {
					applyMediaQueueStatus(preflight.queue);
				}
				const repaired = preflight.repair && preflight.repair.repaired;
				const requeued = preflight.repair ? Math.max(0, Number(preflight.repair.requeued || 0)) : 0;
				if (!repaired && preflight.queueIsComplete && !preflight.hasMore && !(preflight.items && preflight.items.length)) {
					const alreadyOptimized = Math.max(0, Number(preflight.queueAlreadyOptimized || 0));
					const completeText = alreadyOptimized > 0
						? 'Media conversion complete. ' + formatNumber(alreadyOptimized) + ' attachment' + (alreadyOptimized === 1 ? ' is' : 's are') + ' already optimized/up to date.'
						: 'Media conversion complete. No pending media items need optimization.';
					pushToast({ type: 'success', text: completeText });
					setProcess((prev) => Object.assign({}, prev, {
						type: 'media',
						active: false,
						showWhenInactive: true,
						cancellable: false,
						label: __("Media conversion complete", 'ultracache'),
						current: Math.max(0, Number(preflight.total || 0)),
						processed: Math.max(0, Number(preflight.total || 0)),
						total: Math.max(0, Number(preflight.total || 0)),
						logs: [completeText],
					}));
					persistJobState(null);
					await refreshStats();
					return;
				}

				const initialItems = Array.isArray(preflight.items) ? preflight.items.slice() : [];
				const initialLogs = repaired
					? ['Optimized image files were missing; requeued ' + formatNumber(requeued) + ' attachment' + (requeued === 1 ? '' : 's') + ' for repair.']
					: ['Checking queued media and converting only missing optimized files…'];

				await runJob({
					type: 'media',
					label: __("Checking Media", 'ultracache'),
					cursor: preflight.cursor || 0,
					nextCursor: preflight.nextCursor || 0,
					processed: 0,
					total: Math.max(0, Number(preflight.total || 0)),
					pendingItems: initialItems,
					hasMore: !!preflight.hasMore,
					queueBuilding: !!preflight.queueBuilding,
					logs: initialLogs,
					startTime: Date.now(),
					batchSize: DEFAULT_QUEUE_BATCH_SIZE,
				}, !!forceRestart);
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media optimization could not start.' });
			}
		}

		async function runMediaQueueRestAction(action, label, successText, extraParams) {
			if (busy) {
				return null;
			}
			setBusy(true);
			setProcess({
				type: 'media',
				active: true,
				showWhenInactive: true,
				label: label,
				current: 0,
				total: 0,
				logs: [label + '…'],
				startTime: Date.now(),
				cancellable: false,
				cancelRequested: false,
			});
			try {
				const params = Object.assign({ media_format: getSelectedMediaQueueFormat() }, extraParams || {});
				let response = null;
				let loops = 0;
				let changedTotal = 0;
				do {
					loops += 1;
					response = await apiRequest(action, params);
					applyMediaQueueStatus(response);
					changedTotal += Math.max(0, Number((response && (response.retried || response.cleared || (response.repair && response.repair.requeued))) || 0));
					if (response && response.hasMore) {
						setProcess((prev) => Object.assign({}, prev, {
							logs: (prev.logs || []).concat(['Processed ' + formatNumber(changedTotal) + ' row(s); continuing safely…']).slice(-50),
						}));
						await sleep(80);
					}
				} while (response && response.hasMore && loops < 5000);
				const message = response && response.message ? String(response.message) : successText;
				const statusText = 'Queue: ' + formatNumber(response && response.total ? response.total : 0) + ' attachment(s), ' + formatNumber(response && response.pending ? response.pending : 0) + ' pending, ' + formatNumber(response && response.alreadyOptimized ? response.alreadyOptimized : 0) + ' already optimized, ' + formatNumber(response && response.failed ? response.failed : 0) + ' failed.';
				setProcess({
					type: 'media',
					active: false,
					showWhenInactive: true,
					label: label + ' complete',
					current: Math.max(0, Number(response && response.total ? response.total : 0)),
					total: Math.max(0, Number(response && response.total ? response.total : 0)),
					logs: [message, statusText],
					startTime: Date.now(),
					cancellable: false,
					cancelRequested: false,
				});
				pushToast({ type: 'success', text: successText });
				await refreshStats();
				return response;
			} catch (error) {
				setProcess((prev) => Object.assign({}, prev, {
					type: 'media',
					active: false,
					showWhenInactive: true,
					cancellable: false,
					logs: (prev.logs || []).concat([error && error.message ? error.message : 'Media queue action failed.']).slice(-50),
				}));
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media queue action failed.' });
				return null;
			} finally {
				setBusy(false);
			}
		}

		async function rebuildMediaQueue(forceRestart = false) {
			const controls = getJobControls('media_rebuild');
			const isResume = !forceRestart && controls.canResume;
			if (!isResume && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm('Rebuild the full media queue? This scans the media library in safe chunks. Existing optimized image files are not deleted.')) {
					return;
				}
			}
			if (busy) {
				return;
			}
			setBusy(true);
			cancelRequestedRef.current = false;
			const startedAt = Date.now();
			let totalScanned = isResume ? Math.max(0, Number(savedJob && savedJob.scanned ? savedJob.scanned : 0)) : 0;
			let totalQueued = isResume ? Math.max(0, Number(savedJob && savedJob.queued ? savedJob.queued : 0)) : 0;
			setProcess({
				type: 'media_rebuild',
				active: true,
				showWhenInactive: true,
				label: isResume ? 'Resuming Media Queue Rebuild' : 'Rebuilding Media Queue',
				current: isResume ? Math.max(0, Number(savedJob && savedJob.processed ? savedJob.processed : 0)) : 0,
				total: isResume ? Math.max(0, Number(savedJob && savedJob.total ? savedJob.total : 0)) : 0,
				logs: isResume && savedJob && Array.isArray(savedJob.logs) ? savedJob.logs.concat(['Resuming chunked media queue rebuild…']).slice(-50) : ['Starting chunked media queue rebuild…'],
				startTime: startedAt,
				cancellable: true,
				cancelRequested: false,
			});
			try {
				let reset = forceRestart || !isResume;
				let loops = 0;
				let response = null;
				do {
					if (cancelRequestedRef.current) {
						const pausedJob = {
							type: 'media_rebuild',
							processed: response && response.buildOffset ? Number(response.buildOffset) : totalScanned,
							total: response && response.total ? Number(response.total) : 0,
							scanned: totalScanned,
							queued: totalQueued,
							hasMore: true,
							logs: ['Media queue rebuild paused by user.'],
							startTime: startedAt,
						};
						persistJobState(pausedJob);
						setProcess((prev) => Object.assign({}, prev, { active: false, cancellable: false, cancelRequested: true, logs: (prev.logs || []).concat(['Paused by user.']).slice(-50) }));
						pushToast({ type: 'success', text: __("Media queue rebuild paused. You can resume it later.", 'ultracache') });
						return;
					}
					loops += 1;
					response = await apiRequest('media_queue_rebuild', { media_format: getSelectedMediaQueueFormat(), limit: 0, reset: reset, time_budget: 20 });
					reset = false;
					totalScanned += Math.max(0, Number(response && response.scanned ? response.scanned : 0));
					totalQueued += Math.max(0, Number(response && response.queued ? response.queued : 0));
					applyMediaQueueStatus(response);
					const current = Math.max(0, Number(response && response.buildOffset ? response.buildOffset : totalScanned));
					const total = Math.max(0, Number(response && response.total ? response.total : 0));
					const logLine = 'Scanned ' + formatNumber(totalScanned) + ', queued ' + formatNumber(totalQueued) + '.';
					setProcess((prev) => Object.assign({}, prev, {
						current,
						total,
						cancellable: true,
						logs: (prev.logs || []).concat([logLine]).slice(-50),
					}));
					persistJobState({ type: 'media_rebuild', processed: current, total, scanned: totalScanned, queued: totalQueued, hasMore: !!(response && response.hasMore), logs: [logLine], startTime: startedAt });
					await sleep(80);
				} while (response && response.hasMore && loops < 5000);

				const statusText = 'Queue: ' + formatNumber(response && response.total ? response.total : 0) + ' attachment(s), ' + formatNumber(response && response.pending ? response.pending : 0) + ' pending.';
				setProcess((prev) => Object.assign({}, prev, {
					active: false,
					cancellable: false,
					cancelRequested: false,
					showWhenInactive: true,
					label: __("Media Queue Rebuild complete", 'ultracache'),
					logs: (prev.logs || []).concat([response && response.message ? String(response.message) : 'Media queue rebuild finished.', statusText]).slice(-50),
				}));
			persistJobState(null);
				pushToast({ type: 'success', text: __("Media queue rebuilt.", 'ultracache') });
				await refreshStats();
			} catch (error) {
				setProcess((prev) => Object.assign({}, prev, {
					active: false,
					cancellable: false,
					showWhenInactive: true,
					logs: (prev.logs || []).concat([error && error.message ? error.message : 'Media queue rebuild failed.']).slice(-50),
				}));
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media queue rebuild failed.' });
			} finally {
				cancelRequestedRef.current = false;
				setBusy(false);
			}
		}

		async function repairMediaQueue() {
			await runMediaQueueRestAction('media_queue_repair', 'Verifying / Repairing Media Queue', 'Media queue verification/repair finished.');
		}

		async function refreshMediaStorageStats() {
			if (busy) {
				return;
			}
			setBusy(true);
			try {
				const response = await refreshMediaQueueStatus(true);
				const storage = response && response.storageStats ? response.storageStats : {};
				const total = Math.max(0, Number(storage.optimizedImages || 0));
				pushToast({ type: 'success', text: 'Media storage stats refreshed. Found ' + formatNumber(total) + ' optimized file' + (total === 1 ? '' : 's') + '.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media storage stats refresh failed.' });
			} finally {
				setBusy(false);
			}
		}

		async function retryFailedMediaQueue() {
			await runMediaQueueRestAction('media_queue_retry_failed', 'Retrying Failed Media Items', 'Failed media items moved back to pending.');
		}

		async function clearCompletedMediaQueue() {
			if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm('Clear completed media queue rows? This does not delete optimized image files.')) {
					return;
				}
			}
			await runMediaQueueRestAction('media_queue_clear_completed', 'Clearing Completed Queue Rows', 'Completed media queue rows cleared.');
		}

		async function refreshStorageDiagnostics() {
			if (busy) {
				return;
			}
			setBusy(true);
			try {
				const response = await apiRequest('storage_diagnostics_refresh', {});
				if (response && response.diagnostics) {
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
				}
				pushToast({ type: 'success', text: __("Storage diagnostics refreshed.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Storage diagnostics refresh failed.' });
			} finally {
				setBusy(false);
			}
		}


		function exportSettingsFile() {
			try {
				const payload = buildSettingsExportPayload(settings);
				const stamp = new Date().toISOString().replace(/[:.]/g, '-');
				const filename = 'ultracache-settings-' + (ucwp.version || 'export') + '-' + stamp + '.json';
				triggerFileDownload(filename, JSON.stringify(payload, null, 2), 'application/json');
				pushToast({ type: 'success', text: __("Settings exported.", 'ultracache') });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to export settings.',
				});
			}
		}

		function openImportSettingsDialog() {
			if (busy || !importFileInputRef.current) {
				return;
			}

			importFileInputRef.current.value = '';
			importFileInputRef.current.click();
		}

		async function importSettingsFile(event) {
			const input = event && event.target ? event.target : null;
			const file = input && input.files && input.files[0] ? input.files[0] : null;
			if (!file || busy) {
				return;
			}

			setBusy(true);
			try {
				const rawText = await file.text();
				const parsed = JSON.parse(rawText);
				const importedSettings = getTransferableSettingsFromImport(parsed);
				const response = await apiRequest('save_settings', {
					settings_json: JSON.stringify(importedSettings),
				});

				if (response && response.settings) {
					applyServerSettings(response.settings);
				}

				if (response && response.stats) {
					setStats(response.stats);
				}

				if (response && response.diagnostics) {
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
				}

				pushToast({ type: 'success', text: 'Settings imported from ' + file.name + '.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to import settings.',
				});
			} finally {
				if (input) {
					input.value = '';
				}
				setBusy(false);
			}
		}

		async function resetSettingsToDefaults() {
			if (busy) {
				return;
			}

			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			if (!Object.keys(defaultsPayload).length) {
				pushToast({ type: 'error', text: __("Default settings are not available in this build.", 'ultracache') });
				return;
			}

			const confirmed = window.confirm('Reset all UltraCache settings to defaults? This also clears saved Redis and Varnish secrets.');
			if (!confirmed) {
				return;
			}

			setBusy(true);
			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(defaultsPayload) });
				if (response && response.settings) {
					applyServerSettings(response.settings);
				}
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
				}
				setInspectResult(null);
				pushToast({ type: 'success', text: __("UltraCache settings were reset to defaults, including visible safeguard lists.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to reset UltraCache settings.' });
			} finally {
				setBusy(false);
			}
		}

function normalizeUninstallCleanupPolicy(policy) {
	policy = String(policy || '').trim();
	return ['plugin_only', 'keep_settings', 'keep_settings_tables', 'delete_everything'].indexOf(policy) !== -1 ? policy : 'delete_everything';
}

function getUninstallCleanupPolicyLabel(policy) {
	policy = normalizeUninstallCleanupPolicy(policy);
	const labels = {
		plugin_only: 'Only deactivate/delete the plugin',
		keep_settings: 'Keep plugin settings',
		keep_settings_tables: 'Keep plugin settings and tables',
		delete_everything: 'Delete everything',
	};
	return labels[policy] || labels.delete_everything;
}

function getUninstallCleanupPolicyDescription(policy) {
	policy = normalizeUninstallCleanupPolicy(policy);
	const descriptions = {
		plugin_only: 'Keeps settings, custom tables, cache files, and generated media. Removes UltraCache drop-ins/scheduled hooks for safety.',
		keep_settings: 'Keeps dashboard settings/secrets. Removes custom tables, runtime/cache files, drop-ins, and scheduled hooks.',
		keep_settings_tables: 'Keeps dashboard settings/secrets and custom tables. Removes runtime/cache files, drop-ins, and scheduled hooks.',
		delete_everything: 'Removes settings, custom tables, runtime/cache files, drop-ins, scheduled hooks, and UltraCache options.',
	};
	return descriptions[policy] || descriptions.delete_everything;
}

async function deleteAllPluginDataAndDeactivate() {
	if (busy) {
		return;
	}

	const cleanupPolicy = normalizeUninstallCleanupPolicy((settingsRef.current || settings || {}).uninstallCleanupPolicy);
	const mediaFolders = [
		'wp-content/uploads/uc-images/avif',
		'wp-content/uploads/uc-images/webp',
	];
	const confirmed = window.confirm(
		'UltraCache delete/deactivate policy: ' + getUninstallCleanupPolicyLabel(cleanupPolicy) + '\n\n' +
		getUninstallCleanupPolicyDescription(cleanupPolicy) + '\n\n' +
		'Generated media folders are never deleted automatically. Delete them manually if needed:\n- ' + mediaFolders.join('\n- ')
	);
	if (!confirmed) {
		return;
	}

	const typed = window.prompt('Type DELETE to confirm this UltraCache delete/deactivate action.');
	if (String(typed || '').trim() !== 'DELETE') {
		pushToast({ type: 'info', text: __("UltraCache delete/deactivate cancelled.", 'ultracache') });
		return;
	}

	suppressBeforeUnloadRef.current = true;
	setBusy(true);
	try {
		await syncQueuedSettingsBeforeAction();
		const response = await apiRequest('delete_all_data', { confirmation: 'DELETE', cleanupPolicy });
		pushToast({ type: 'success', text: response && response.message ? response.message : 'UltraCache delete/deactivate action completed.' });
		window.setTimeout(() => {
			window.location.href = 'plugins.php?deactivate=true';
		}, 1200);
	} catch (error) {
		suppressBeforeUnloadRef.current = false;
		pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to delete/deactivate UltraCache.' });
		setBusy(false);
	}
}

		async function purgeCache() {
			await queueDashboardAction('purge_all', {}, {
				queued: 'Full cache purge processing via dashboard…',
				success: 'All cache files cleared.',
				failed: 'Failed to purge cache.',
			}, 'purge_all');
		}



		function openSupportModal() {
			setSupportModalOpen(true);
		}

		function closeSupportModal() {
			setSupportModalOpen(false);
		}

		function handleHireClick() {
			setSupportModalOpen(false);
		}

		const mediaOptimizationEnabled = !!settings.mediaOptimizationEnabled;
		const activePerformanceProfile = getActivePerformanceProfile(settings);
		const pageCacheReady = !!settings.pageCacheEnabled;
		const cssBundleReady = pageCacheReady && !!settings.homepageCssBundleEnabled;
		const cssWarmScope = normalizeCssBundleScopeValue(settings.cssBundleScope || 'homepage');
		const menuCssWarmJobType = getCssWarmJobType('menu', cssWarmScope);
		const fullCssWarmJobType = getCssWarmJobType('full', cssWarmScope);
		const homepageCssButtonLabel = 'Warm Up Homepage HTML Cache + Homepage CSS Bundle';
		const menuCssButtonLabel = 'Warm Up Menu HTML Cache + ' + getCssWarmBundleLabel(cssWarmScope, true);
		const fullCssButtonLabel = 'Warm Up Full Site HTML Cache + ' + getCssWarmBundleLabel(cssWarmScope, true);
		const cssBundleSummaryDiag = diagnostics && diagnostics.cssBundleSummary ? diagnostics.cssBundleSummary : {};
		const cssBundleSummaryLastWarm = cssBundleSummaryDiag && cssBundleSummaryDiag.lastWarm ? cssBundleSummaryDiag.lastWarm : null;
		const statsCssLastWarm = stats.lastFrontpageCssWarm || null;
		const cssBundleDiagnostics = {
			bundlesBuilt: typeof cssBundleSummaryDiag.bundlesBuilt !== 'undefined' ? cssBundleSummaryDiag.bundlesBuilt : (typeof stats.homepageCssBundlesBuilt !== 'undefined' ? stats.homepageCssBundlesBuilt : stats.frontpageCssBundlesBuilt),
			stylesBundled: typeof cssBundleSummaryDiag.stylesBundled !== 'undefined' ? cssBundleSummaryDiag.stylesBundled : (typeof stats.homepageCssStylesBundled !== 'undefined' ? stats.homepageCssStylesBundled : stats.frontpageCssStylesBundled),
			stylesScanned: typeof cssBundleSummaryDiag.stylesScanned !== 'undefined' ? cssBundleSummaryDiag.stylesScanned : (typeof stats.homepageCssStylesScanned !== 'undefined' ? stats.homepageCssStylesScanned : stats.frontpageCssStylesScanned),
			stylesSkipped: typeof cssBundleSummaryDiag.stylesSkipped !== 'undefined' ? cssBundleSummaryDiag.stylesSkipped : (typeof stats.homepageCssStylesSkipped !== 'undefined' ? stats.homepageCssStylesSkipped : stats.frontpageCssStylesSkipped),
			stylesUnresolved: typeof cssBundleSummaryDiag.stylesUnresolved !== 'undefined' ? cssBundleSummaryDiag.stylesUnresolved : (typeof stats.homepageCssStylesUnresolved !== 'undefined' ? stats.homepageCssStylesUnresolved : stats.frontpageCssStylesUnresolved),
			lastWarm: cssBundleSummaryLastWarm || statsCssLastWarm,
			files: cssBundleSummaryDiag.files || {},
			manifest: cssBundleSummaryDiag.manifest || {},
			integrityOk: typeof cssBundleSummaryDiag.integrityOk !== 'undefined' ? !!cssBundleSummaryDiag.integrityOk : true,
			summarySource: cssBundleSummaryDiag.summarySource || 'cache-stats',
			message: cssBundleSummaryDiag.message || '',
		};
		const googleFontsDiag = diagnostics && diagnostics.googleFonts ? diagnostics.googleFonts : {};
		const fontPipelineDiag = diagnostics && diagnostics.fontPipeline ? diagnostics.fontPipeline : {};
		const googleFontsStatusText = googleFontsDiag.message
			? String(googleFontsDiag.message)
			: (googleFontsDiag.built
				? ('Google Fonts cache: Built · Stylesheets: ' + formatNumber(googleFontsDiag.cssFiles || 0) + ' · Font files: ' + formatNumber(googleFontsDiag.fontFiles || 0))
				: 'Google Fonts cache: Not built yet — rebuild required.');
		const googleFontsLastScanText = googleFontsDiag.lastScanAt
			? ('Last scan: ' + formatNumber(googleFontsDiag.lastScanScannedUrls || 0) + ' URL(s) · ' + formatNumber(googleFontsDiag.lastScanGoogleFontsUrls || 0) + ' remote Google Fonts stylesheet(s) · ' + formatNumber(googleFontsDiag.lastScanBuilt || 0) + ' built · ' + formatNumber(googleFontsDiag.lastScanFailed || 0) + ' failed')
			: '';
		const warmBusy = busy || process.active || homepageHtmlBusy || homepageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy;
		const warmDisabledMessage = !pageCacheReady
			? 'Please enable Page Caching first or select a profile before warming cache.'
			: (!settings.homepageCssBundleEnabled ? 'CSS bundle warm buttons are disabled until CSS Bundling is enabled.' : '');
		const menuWarmScopeReady = hasMenuWarmScope(settings);
		const fullSiteWarmScopeReady = hasFullSiteWarmScope(settings);
		const menuWarmScopeMessage = menuWarmScopeReady ? '' : 'Select a frontend menu and depth before using menu warm-up.';
		const fullSiteWarmScopeMessage = fullSiteWarmScopeReady ? '' : 'Select at least one full-site warm source before using full-site warm-up.';
		const effectiveMediaQueueStatus = mediaQueueStatus || ((diagnostics && diagnostics.mediaRuntime && diagnostics.mediaRuntime.queue) ? diagnostics.mediaRuntime.queue : {});
		const optimizedImagesTotal = typeof stats.imagesOptimized !== 'undefined' ? stats.imagesOptimized : stats.optimizedImages;
		const optimizedAvifTotal = typeof stats.avifImagesOptimized !== 'undefined' ? stats.avifImagesOptimized : stats.avifFiles;
		const optimizedWebpTotal = typeof stats.webpImagesOptimized !== 'undefined' ? stats.webpImagesOptimized : stats.webpFiles;
		const mediaQueueTotal = Math.max(0, Number(effectiveMediaQueueStatus.total || 0));
		const mediaQueuePending = Math.max(0, Number(effectiveMediaQueueStatus.pending || 0));
		const mediaQueueFailed = Math.max(0, Number(effectiveMediaQueueStatus.failed || 0));
		const mediaQueueAlreadyOptimized = Math.max(0, Number(effectiveMediaQueueStatus.alreadyOptimized || effectiveMediaQueueStatus.skipped || 0));
		const mediaQueueNeedsRepair = !!effectiveMediaQueueStatus.needsRepair;
		const mediaQueueIsComplete = !!effectiveMediaQueueStatus.isComplete;
		const varnishLayer = getExternalCacheLayer(stats, 'varnish');
		const varnishDiagnostic = diagnostics && diagnostics.varnish ? diagnostics.varnish : {};
		const reverseProxyDiagnostic = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : {};
		const reverseProxyTextForVarnish = [
			reverseProxyDiagnostic.provider,
			reverseProxyDiagnostic.server,
			reverseProxyDiagnostic.via,
			reverseProxyDiagnostic.x_cache,
			reverseProxyDiagnostic.x_cache_status,
			reverseProxyDiagnostic.message,
		].join(' ').toLowerCase();
		const reverseProxyLooksLikeVarnish = !!(reverseProxyDiagnostic && reverseProxyDiagnostic.detected && reverseProxyTextForVarnish.indexOf('varnish') !== -1);
		const varnishConfigured = !!(
			(settings && (settings.varnishCliEnabled || settings.varnishCliServers || settings.flushAllIncludeVarnish)) ||
			(varnishForm && (varnishForm.varnishCliEnabled || varnishForm.varnishCliServers || varnishForm.varnishCliKeyConfigured)) ||
			(varnishDiagnostic && (varnishDiagnostic.enabled || varnishDiagnostic.available || varnishDiagnostic.servers || varnishDiagnostic.endpointCount))
		);
		const showVarnishCard = !!((varnishLayer && varnishLayer.detected) || reverseProxyLooksLikeVarnish || varnishConfigured);

		return h('div', { className: 'max-w-6xl p-6 space-y-8' }, [
			h('header', { className: 'flex flex-col gap-4 md:flex-row md:justify-between md:items-end', key: 'header' }, [
				h('div', { key: 'title' }, [
					h('h1', { className: 'text-3xl font-black tracking-tighter m-0 text-white' }, 'UltraCache'),
					h(
						'p',
						{ className: 'text-zinc-500 text-xs tracking-widest mt-2 mb-0' },
						__("Page cache, object cache, compression, warmups, fonts, and next-gen images", 'ultracache')
					),
				]),
				h('div', { className: 'flex flex-wrap gap-3', key: 'actions' }, [
					h(Button, { onClick: purgeCache, disabled: busy || !!asyncActions.purge_all, variant: 'primary' }, asyncActions.purge_all ? 'Processing via dashboard…' : (busy ? 'Working…' : 'Flush All Cache')),
				]),
			]),

			h(ToastViewport, { toasts, onDismiss: dismissToast, key: 'toast-viewport' }),
			h(SupportModal, {
				open: supportModalOpen,
				isMobile,
				onClose: closeSupportModal,
				onHireClick: handleHireClick,
				key: 'support-modal',
			}),


			h(CacheStatisticsPanel, {
				settings,
				stats,
				diagnostics,
				busy,
				asyncActions,
				onToggleStats: (value) => updateSetting('cacheStatsEnabled', value),
				onFullObjectCount: runFullObjectCount,
				key: 'cache-statistics-panel',
			}),

				h(
				Card,
				{
					title: __("Select profile", 'ultracache'),
					description: __("Select a known preset below. Profiles apply their settings immediately and can be adjusted manually afterwards.", 'ultracache'),
					key: 'performance-profile-card',
				},
				[
					h(ToggleRow, {
						label: PERFORMANCE_PROFILE_CUSTOM.label,
						description: PERFORMANCE_PROFILE_CUSTOM.description,
						checked: activePerformanceProfile === 'custom',
						onChange: (value) => {
							if (!value) { return; }
							pushToast({ type: 'info', text: __("Custom turns on automatically when settings do not match a preset.", 'ultracache') });
						},
						disabled: busy,
						key: 'performance-profile-custom',
					}),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4', key: 'performance-profile-presets-grid' },
						PERFORMANCE_PROFILE_ORDER.map((profileKey) => {
							const profile = PERFORMANCE_PROFILES[profileKey];
							return h(ToggleRow, {
								label: profile.label,
								description: profile.description,
								checked: activePerformanceProfile === profileKey,
								onChange: (value) => {
									if (!value) { return; }
									applyPerformanceProfile(profileKey);
								},
								disabled: busy,
								key: 'performance-profile-' + profileKey,
							});
						})
					),
				]
			),

			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'jobs' }, [
				h(
					Card,
					{
						title: __("Warm Cache", 'ultracache'),
						description: __("Crawl public URLs and prebuild static cache files.", 'ultracache'),
						key: 'warm',
					},
					[
						warmDisabledMessage ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'warm-disabled-message' }, warmDisabledMessage) : null,
						h('div', { className: 'mt-4 uc-warm-cache-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' }, key: 'warm-homepage-actions' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: warmFrontpageHtml,
									disabled: !pageCacheReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (warmBusy && !homepageHtmlBusy ? 'Engine Busy' : (homepageHtmlBusy ? 'Warming Homepage HTML…' : 'Warm Up Homepage HTML Cache'))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: warmFrontpageHtmlCss,
									disabled: !cssBundleReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!settings.homepageCssBundleEnabled ? 'Enable CSS Bundling First' : (warmBusy && !homepageHtmlCssBusy ? 'Engine Busy' : (homepageHtmlCssBusy ? 'Warming Homepage HTML + Homepage CSS Bundle…' : homepageCssButtonLabel)))
							),
						]),
						h('div', { className: 'mt-5 grid grid-cols-1 gap-4', key: 'warm-menu-scope-controls' }, [
							h(SelectField, {
								label: __("Menu warm-up", 'ultracache'),
								description: __("Choose any saved WordPress menu. Assigned/frontend menus are listed first; other saved menus run only when selected.", 'ultracache'),
								value: settings.warmMenuLocation || '',
								onChange: (value) => updateSetting('warmMenuLocation', value),
								disabled: warmBusy,
								options: getWarmMenuOptions(),
							}),
							h(SelectField, {
								label: __("Menu depth", 'ultracache'),
								description: __("Depth 1 = top-level only. All = every child item in the selected menu.", 'ultracache'),
								value: settings.warmMenuDepth || '',
								onChange: (value) => updateSetting('warmMenuDepth', value),
								disabled: warmBusy,
								options: getWarmMenuDepthOptions(),
							}),
						]),
						menuWarmScopeMessage ? h('div', { className: 'mt-2 text-xs text-zinc-500', key: 'warm-menu-scope-hint' }, menuWarmScopeMessage) : null,
						h('div', { className: 'mt-4 uc-warm-cache-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' }, key: 'warm-menu-actions' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarming(false),
									disabled: !pageCacheReady || warmBusy || !menuWarmScopeReady,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!menuWarmScopeReady ? 'Select Menu + Depth First' : (warmBusy ? 'Engine Busy' : (getJobControls('warm_menu').canResume ? 'Resume Warm Up Menu HTML Cache' : 'Warm Up Menu HTML Cache')))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarmingWithFrontpageCss(false),
									disabled: !cssBundleReady || warmBusy || !menuWarmScopeReady,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!settings.homepageCssBundleEnabled ? 'Enable CSS Bundling First' : (!menuWarmScopeReady ? 'Select Menu + Depth First' : (warmBusy && !menuUrlsCssBusy ? 'Engine Busy' : (menuUrlsCssBusy ? 'Warming Menu HTML + ' + getCssWarmBundleLabel(cssWarmScope, true) + '…' : (getJobControls(menuCssWarmJobType).canResume ? 'Resume ' + menuCssButtonLabel : menuCssButtonLabel)))))
							),
						]),
							(getJobControls('warm_menu').canRestart || getJobControls(menuCssWarmJobType).canRestart) ? h('button', {
								className: 'uc-btn flex-1 min-w-[220px] text-white py-3 font-bold',
								style: { marginTop: '12px' },
								onClick: () => (getJobControls(menuCssWarmJobType).canRestart ? startMenuWarmingWithFrontpageCss(true) : startMenuWarming(true)),
								disabled: !pageCacheReady || warmBusy || !menuWarmScopeReady,
							}, getJobControls(menuCssWarmJobType).canRestart ? 'Restart ' + menuCssButtonLabel : 'Restart Warm Up Menu HTML Cache') : null,

						h('div', { className: 'mt-5', key: 'warm-full-scope-controls' }, [
							h(MultiSelectField, {
								label: __("Full-site warm-up sources", 'ultracache'),
								description: __("Choose the URL sources for full-site and scheduled / cron warm-up. The counts below help you choose the Scheduled / Cron warm limit; the limit itself remains user-controlled.", 'ultracache'),
								value: settings.warmFullSiteSources || '',
								onChange: (value) => updateSetting('warmFullSiteSources', value),
								disabled: warmBusy,
								options: getFullSiteSourceOptions(),
							}),
						]),
						fullSiteWarmScopeMessage ? h('div', { className: 'mt-2 text-xs text-zinc-500', key: 'warm-full-scope-hint' }, fullSiteWarmScopeMessage) : null,
						h('div', { className: 'mt-4 uc-warm-cache-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' }, key: 'warm-full-actions' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarming(false),
									disabled: !pageCacheReady || warmBusy || !fullSiteWarmScopeReady,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!fullSiteWarmScopeReady ? 'Select Full-Site Sources First' : (warmBusy ? 'Engine Busy' : (getJobControls('warm').canResume ? 'Resume Warm Up Full Site HTML Cache' : 'Warm Up Full Site HTML Cache')))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarmingAllWithFrontpageCss(false),
									disabled: !cssBundleReady || warmBusy || !fullSiteWarmScopeReady,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!settings.homepageCssBundleEnabled ? 'Enable CSS Bundling First' : (!fullSiteWarmScopeReady ? 'Select Full-Site Sources First' : (warmBusy && !allUrlsCssBusy ? 'Engine Busy' : (allUrlsCssBusy ? 'Warming Full Site HTML + ' + getCssWarmBundleLabel(cssWarmScope, true) + '…' : (getJobControls(fullCssWarmJobType).canResume ? 'Resume ' + fullCssButtonLabel : fullCssButtonLabel)))))
							),
						]),
							(getJobControls('warm').canRestart || getJobControls(fullCssWarmJobType).canRestart) ? h('button', {
								className: 'uc-btn flex-1 min-w-[220px] text-white py-3 font-bold',
								style: { marginTop: '12px' },
								onClick: () => (getJobControls(fullCssWarmJobType).canRestart ? startWarmingAllWithFrontpageCss(true) : startWarming(true)),
								disabled: !pageCacheReady || warmBusy || !fullSiteWarmScopeReady,
							}, getJobControls(fullCssWarmJobType).canRestart ? 'Restart ' + fullCssButtonLabel : 'Restart Warm Up Full Site HTML Cache') : null,

					]
				),
			h(
				Card,
				{
					title: __("AVIF / WebP Batch Conversion", 'ultracache'),
					description: __("Queue-based conversion for existing uploads. This box is separate from cache warm-up and only shows media conversion operations.", 'ultracache'),
					key: 'batch-media-conversion',
				},
				[
					h('div', { className: 'text-xs text-zinc-500 mt-1', key: 'media-batch-support-summary' }, 'Conversion support: Imagick ' + (avifSupport.imagick ? 'Yes' : 'No') + ' · Imagick AVIF ' + (avifSupport.imagick_avif ? 'Yes' : 'No') + ' · Imagick WebP ' + (avifSupport.imagick_webp ? 'Yes' : 'No') + ' · GD AVIF ' + (avifSupport.gd_avif ? 'Yes' : 'No') + ' · GD WebP ' + (avifSupport.gd_webp ? 'Yes' : 'No')),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-4 mt-4', key: 'media-batch-summary' }, [
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'optimized-files' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Optimized image files", 'ultracache')),
							h('div', { className: 'text-2xl font-black text-white mt-1' }, formatNumber(optimizedImagesTotal || 0)),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, formatNumber(optimizedAvifTotal || 0) + ' AVIF · ' + formatNumber(optimizedWebpTotal || 0) + ' WebP'),
						]),
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'queue-status' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Media queue", 'ultracache')),
							h('div', { className: 'text-2xl font-black text-white mt-1' }, formatNumber(mediaQueueTotal)),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, formatNumber(mediaQueuePending) + ' pending · ' + formatNumber(mediaQueueAlreadyOptimized) + ' already optimized · ' + formatNumber(mediaQueueFailed) + ' failed'),
						]),
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'queue-health' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Queue health", 'ultracache')),
							h('div', { className: mediaQueueNeedsRepair ? 'text-lg font-black text-amber-300 mt-1' : 'text-lg font-black text-emerald-300 mt-1' }, mediaQueueNeedsRepair ? 'Needs repair' : (mediaQueueIsComplete ? 'Complete' : 'Ready')),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, 'Target policy: ' + (settings.mediaOutputMode || 'auto') + ' · queue format: best'),
						]),
					]),
					h('div', { className: 'mt-5 uc-media-batch-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' }, key: 'media-batch-actions' }, [
						h('div', { key: 'start' }, [
							h('button', {
								className: 'uc-btn uc-btn--primary w-full text-white py-3 font-bold',
								onClick: () => startMediaOptimization(false),
								disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
							}, busy ? 'Engine Busy' : (getJobControls('media').canResume ? 'Resume Media Conversion' : 'Start / Resume Conversion')),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Processes the next pending media items. Existing optimized files are checked and marked already optimized.", 'ultracache')),
						]),
						h('div', { key: 'rebuild' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: () => rebuildMediaQueue(false),
								disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
							}, busy ? 'Engine Busy' : (getJobControls('media_rebuild').canResume ? 'Resume Media Queue Rebuild' : 'Rebuild Media Queue')),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Scans the media library and rebuilds the attachment queue. Use after large imports or when the queue looks outdated.", 'ultracache')),
						]),
						getJobControls('media_rebuild').canRestart ? h('div', { key: 'rebuild-restart' }, [
								h('button', {
									className: 'uc-btn w-full text-white py-3 font-bold',
									onClick: () => rebuildMediaQueue(true),
									disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
								}, busy ? 'Engine Busy' : 'Restart Media Queue Rebuild'),
								h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Starts the rebuild from the beginning instead of resuming the saved offset.", 'ultracache')),
							]) : null,
							h('div', { key: 'refresh-storage' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: refreshMediaStorageStats,
								disabled: busy || !mediaOptimizationEnabled,
							}, busy ? 'Engine Busy' : 'Refresh Storage Stats'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Runs a capped manual scan of uploads/uc-images. Normal dashboard/status refreshes stay passive and do not crawl media directories.", 'ultracache')),
						]),
						h('div', { key: 'repair' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: repairMediaQueue,
								disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
							}, busy ? 'Engine Busy' : 'Verify / Repair Queue'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Checks whether optimized output storage is missing and re-queues completed items when repair is needed.", 'ultracache')),
						]),
						h('div', { key: 'retry' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: retryFailedMediaQueue,
								disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported || mediaQueueFailed <= 0,
							}, busy ? 'Engine Busy' : 'Retry Failed'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Moves failed queue rows back to pending so they can be processed again.", 'ultracache')),
						]),
						h('div', { key: 'clear-completed' }, [
							h('button', {
								className: 'uc-btn !bg-zinc-800 !text-white !border-white/10 w-full text-white py-3 font-bold',
								onClick: clearCompletedMediaQueue,
								disabled: busy || !mediaOptimizationEnabled || mediaQueueAlreadyOptimized <= 0,
							}, busy ? 'Engine Busy' : 'Clear Completed Queue Rows'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Removes completed queue rows only. It does not delete AVIF/WebP files.", 'ultracache')),
						]),
					]),
					h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'media-batch-note' }, __("Cache warm-up operations keep using the Warm Cache box above. This panel is used only for media queue actions.", 'ultracache')),
				]
			)
			]),

			h(ProgressPanel, {
				process,
				etaText,
				onCancel: requestCancel,
				showWhenInactive: process.type === 'media' && !!process.showWhenInactive,
				key: 'job-progress-after-jobs',
			}),

			
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'settings' }, [

								h(
					Card,
					{
						title: __("Cache Engine", 'ultracache'),
						description: __("Core page cache behavior, WooCommerce bypasses, compression variants, and safe prefetch hints.", 'ultracache'),
						key: 'cache-engine',
					},
					[
						h(ToggleRow, {
							label: __("Page Caching", 'ultracache'),
							description: __("Store public pages as static HTML files.", 'ultracache'),
							checked: settings.pageCacheEnabled,
							onChange: (value) => updateSetting('pageCacheEnabled', value),
							disabled: busy,
							key: 'page',
						}),
h(ToggleRow, {
							label: __("Pre-render on Save", 'ultracache'),
							description: __("Warm the updated page after content changes.", 'ultracache'),
							checked: settings.preRenderOnSave,
							onChange: (value) => updateSetting('preRenderOnSave', value),
							disabled: busy,
							key: 'preload',
						}),
h(ToggleRow, {
							label: __("WooCommerce Safe Mode", 'ultracache'),
							description: __("Bypass cart, checkout, account, order endpoints, and cart-changing requests.", 'ultracache'),
							checked: settings.woocommerceSafeModeEnabled,
							onChange: (value) => updateSetting('woocommerceSafeModeEnabled', value),
							disabled: busy,
							key: 'woo-safe',
						}),
h(ToggleRow, {
							label: __('Browser Cache Headers', 'ultracache') + ' (.htaccess)',
							description: __("Write long-lived browser cache headers for CSS, JS, fonts, static images, AVIF, and WebP on Apache-compatible hosts.", 'ultracache'),
							checked: settings.browserCacheRulesEnabled,
							onChange: (value) => updateSetting('browserCacheRulesEnabled', value),
							disabled: busy,
							key: 'browser-cache-rules',
						}),
							h(ToggleRow, {
								label: 'Gzip',
								description: compressionLocks.gzipDescription,
								checked: settings.gzipEnabled,
								onChange: (value) => updateSetting('gzipEnabled', value),
								disabled: busy || compressionLocks.gzipLocked,
								key: 'gzip',
							}),
							h(ToggleRow, {
								label: 'Brotli',
								description: compressionLocks.brotliDescription,
								checked: settings.brotliEnabled,
								onChange: (value) => updateSetting('brotliEnabled', value),
								disabled: busy || compressionLocks.brotliLocked,
								key: 'brotli',
							}),
							h(ToggleRow, {
								label: __("Speculation Rules Prefetch", 'ultracache'),
								description: __("Inject a safe prefetch-only speculationrules block for likely next-page internal navigations. Logged-in users, query-string links, WooCommerce flows, admin-like paths, nofollow links, and target/download links stay excluded.", 'ultracache'),
								checked: settings.speculationRulesEnabled,
								onChange: (value) => updateSetting('speculationRulesEnabled', value),
								disabled: busy,
								key: 'cache-engine-speculation-rules',
							}),
h(ToggleRow, {
								label: __("Cache Pages with Safe Tracking Cookies", 'ultracache'),
								description: __("Allow public HTML cache storage when the response sets only cookies from the Safe Tracking Cookies list. UltraCache still never stores or replays Set-Cookie headers. Disable for strict mode where any Set-Cookie must skip cache.", 'ultracache'),
								checked: !!settings.cacheSafeTrackingCookiesEnabled,
								onChange: (value) => updateSetting('cacheSafeTrackingCookiesEnabled', value),
								disabled: busy,
								key: 'cache-safe-tracking-cookies-enabled',
							}),

					]
				),

				h(
				Card,
				{
					title: __("Media Optimization", 'ultracache'),
					description: __("Controls frontend AVIF/WebP URL rewriting and the related upload, batch, and missing-media queue tools.", 'ultracache'),
					key: 'media-optimization',
				},
				[
					h(ToggleRow, {
						label: __("Enable Media Rewrite", 'ultracache'),
						description: __("Enable AVIF/WebP media URL rewriting according to the selected output policy. The actual media files must already exist or be generated through batch conversion, generate on upload, or queue missing media on demand.", 'ultracache'),
						checked: mediaOptimizationEnabled,
						onChange: (value) => updateMediaOptimizationSetting(value),
						disabled: busy,
						key: 'media-optimization-enabled',
					}),
					h(ToggleRow, {
						label: __("Automatic Format", 'ultracache'),
						description: __("AVIF is preferred first, with WebP kept as the compatibility fallback.", 'ultracache'),
						checked: !settings.mediaOutputMode || 'auto' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'auto'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-auto',
					}),
					h(ToggleRow, {
						label: __("AVIF Format", 'ultracache'),
						description: __("Generate and prefer AVIF variants only.", 'ultracache'),
						checked: 'avif' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'avif'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-avif',
					}),
					h(ToggleRow, {
						label: __("WebP Format", 'ultracache'),
						description: __("Generate and prefer WebP variants only.", 'ultracache'),
						checked: 'webp' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'webp'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-webp',
					}),
					h(ToggleRow, {
						label: __("Generate on Upload", 'ultracache'),
						description: __("When enabled, newly uploaded images and their registered thumbnail sizes are queued for next-gen conversion.", 'ultracache'),
						checked: !!settings.mediaGenerateOnUploadEnabled,
						onChange: (value) => updateSetting('mediaGenerateOnUploadEnabled', value),
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-generate-upload',
					}),
					h(ToggleRow, {
						label: __("Queue Missing Media on Demand", 'ultracache'),
						description: __("When enabled, UltraCache queues missing AVIF/WebP variants discovered during frontend, warm-up, cron warm, or stale rewrites. The current request never performs image conversion; the existing background media queue handles generation.", 'ultracache'),
						checked: !!settings.mediaGenerateOnDemandEnabled,
						onChange: (value) => updateSetting('mediaGenerateOnDemandEnabled', value),
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-generate-demand',
					}),
					h(ToggleRow, {
						label: __("Safe CLS Dimensions", 'ultracache'),
						description: __("Inject missing width and height on local images using attachment metadata first and local file dimensions as fallback.", 'ultracache'),
						checked: settings.clsDimensionsEnabled,
						onChange: (value) => updateSetting('clsDimensionsEnabled', value),
						disabled: busy,
						key: 'media-cls-dimensions',
					}),
					h(ToggleRow, {
						label: __("LCP Image Priority", 'ultracache'),
						description: __("Prioritize likely hero/LCP images. In normal mode UltraCache can mark the detected candidate and inject a preload; when Fix sliders / hero sections is active, it uses SR7/Revolution Slider first-slide discovery plus a lifecycle-safe runtime guard.", 'ultracache'),
						checked: settings.lcpImagePriorityEnabled,
						onChange: (value) => updateSetting('lcpImagePriorityEnabled', value),
						disabled: busy,
						key: 'media-lcp-priority',
					}),
					h(ToggleRow, {
						label: __("Lazy load & async images", 'ultracache'),
						description: __("Adds native loading=\"lazy\" and decoding=\"async\" to eligible images. If LCP Image Priority is enabled, UltraCache only lazy-loads images printed after the detected LCP image.", 'ultracache'),
						checked: !!settings.lazyLoadImagesEnabled,
						onChange: (value) => updateSetting('lazyLoadImagesEnabled', value),
						disabled: busy,
						key: 'media-lazy-load-images',
					}),
					!avifSupport.supported
						? h(
							'div',
							{
								className:
									'mt-4 p-3 bg-rose-500/10 border border-rose-500/20 rounded text-rose-400 text-xs',
							},
							__("This server cannot generate AVIF or WebP yet. Install Imagick with AVIF/WebP support or a GD build that includes imageavif()/imagewebp().", 'ultracache')
						 )
						: null,
				]
			),
				h(
					Card,
					{
						title: __("Frontend JS & Request Chains", 'ultracache'),
						description: __("Defer/delay safe scripts and prioritize known critical request chains.", 'ultracache'),
						key: 'frontend-js-request-chains-card',
					},
					[
						h(ToggleRow, {
									label: __("Safe Defer JS", 'ultracache'),
									description: __("Add native defer conservatively. When enabled, UltraCache scans the front page once and appends detected theme/page-builder init scripts to the visible JS Delay / Defer Exclusions list.", 'ultracache'),
									checked: settings.deferJsEnabled,
									onChange: (value) => updateSafeDeferJs(value),
									disabled: busy,
									key: 'defer-stage-one',
								}),
h(ToggleRow, {
									label: __("Defer all JS", 'ultracache'),
									description: __("Aggressive manual mode. When Safe Defer JS is on, UltraCache adds native defer to every eligible frontend script. If JS Delay / Defer Exclusions is empty, UltraCache really defers everything eligible. Populate Defaults is optional and only adds visible recommendations; scan suggestions help you build exclusions after testing.", 'ultracache'),
									checked: !!settings.deferAllJsEnabled,
									onChange: (value) => updateSetting('deferAllJsEnabled', value),
									disabled: busy || !settings.deferJsEnabled,
									key: 'defer-all-js',
								}),
h(ToggleRow, {
									label: __("Delay safe third-party JS", 'ultracache'),
									description: __("Delay analytics, pixels, ads, tracking, and marketing scripts until user interaction or a late safe fallback timeout, keeping them out of the initial PageSpeed/LCP/TBT critical window. Examples: Google Analytics, gtag, GTM, Google Site Kit event providers, Meta Pixel, TikTok Pixel, LinkedIn Insight, Pinterest Tag, Bing UET, Hotjar, Clarity, DoubleClick, Google Ads, Taboola, Outbrain, and Yahoo tracking.", 'ultracache'),
									checked: settings.delaySafeThirdPartyJsEnabled,
									onChange: (value) => updateSetting('delaySafeThirdPartyJsEnabled', value),
									disabled: busy,
									key: 'delay-safe-third-party-js',
								}),
h(ToggleRow, {
									label: __("Lazy MailerLite nonce refresh", 'ultracache'),
									description: 'Prevents MailerLite forms from calling wp-admin/admin-ajax.php on page load for ml_create_nonce. The nonce is refreshed on first form interaction or before submit, so cached pages avoid the load-time admin-ajax request.',
									checked: !!settings.lazyMailerliteNonceEnabled,
									onChange: (value) => updateSetting('lazyMailerliteNonceEnabled', value),
									disabled: busy,
									key: 'lazy-mailerlite-nonce-refresh',
								}),
	h(ToggleRow, {
									label: __("Delay known functional third-party JS", 'ultracache'),
									description: __("Delay matched third-party scripts that provide visible functionality, such as cookie banners, captcha, maps, chat widgets, booking widgets, embedded forms, opt-in popups, newsletter widgets, and review widgets. Matching is based on the visible include/exclude patterns below. If a form, map, captcha, checkout, or cookie banner misbehaves, add its script keyword to exclusions.", 'ultracache'),
									checked: !!settings.delayFunctionalThirdPartyJsEnabled,
									onChange: (value) => updateSetting('delayFunctionalThirdPartyJsEnabled', value),
									disabled: busy,
									key: 'delay-functional-third-party-js',
								}),
	h(ToggleRow, {
										label: __("Delay all third-party JS", 'ultracache'),
										description: __("Delays external scripts loaded from third-party domains until user interaction or a safe fallback timeout. JS Delay / Defer Exclusions is respected; use exclusions for captcha, payments, consent, login, booking, or critical form scripts that must run immediately.", 'ultracache'),
										checked: !!settings.delayAllThirdPartyJsEnabled,
										onChange: (value) => updateSetting('delayAllThirdPartyJsEnabled', value),
										disabled: busy,
										key: 'delay-all-third-party-js',
									}),
					h(ToggleRow, {
						label: __("Delay non-critical/local JS", 'ultracache'),
						description: __("Delay selected same-host enhancement scripts such as popups, sliders, filters, consent extras, marketing helpers, and other local footer scripts unless protected or excluded here.", 'ultracache'),
						checked: settings.delayNonCriticalJsEnabled,
						onChange: (value) => updateSetting('delayNonCriticalJsEnabled', value),
						disabled: busy,
						key: 'defer-stage-three',
					}),
h(ToggleRow, {
									label: __("Main Thread Relief", 'ultracache'),
									description: __("Load delayed scripts gradually during browser idle time instead of releasing the full delayed queue at once. Works with Stage two and Stage three delayed scripts.", 'ultracache'),
									checked: settings.mainThreadReliefEnabled,
									onChange: (value) => updateSetting('mainThreadReliefEnabled', value),
									disabled: busy,
									key: 'main-thread-relief',
								}),
h(ToggleRow, {
									label: __("Critical Request Chain Relief", 'ultracache'),
									description: __("Preload known critical requests and let selected non-critical chained assets be delayed so the browser has a shorter critical network chain.", 'ultracache'),
									checked: settings.criticalRequestChainReliefEnabled,
									onChange: (value) => updateSetting('criticalRequestChainReliefEnabled', value),
									disabled: busy,
									key: 'critical-request-chain-relief',
								}),
h(ToggleRow, {
									label: __("LCP Boundary Defer", 'ultracache'),
									description: __("Uses the LCP image detected by LCP Image Priority as a visual boundary. Eligible local scripts printed after that image in the HTML are delayed.", 'ultracache'),
									checked: !!settings.lcpBoundaryDeferEnabled,
									onChange: (value) => updateSetting('lcpBoundaryDeferEnabled', value),
										disabled: busy || !settings.lcpImagePriorityEnabled,
									key: 'lcp-boundary-defer',
								})
					]
				),
h(
					Card,
					{
						title: __("CSS Delivery", 'ultracache'),
						description: __("Bundle eligible CSS and async low-risk stylesheets without changing media-related controls.", 'ultracache'),
						key: 'css-delivery-lcp-card',
					},
					[
						h(ToggleRow, {
							label: __("CSS Bundling", 'ultracache'),
                                description: __("Create local UltraCache CSS bundles for eligible stylesheet links. Safe mode is the public default. Aggressive and Full CSS Bundle are experimental and should be enabled only after visual testing.", 'ultracache'),
							checked: settings.homepageCssBundleEnabled,
							onChange: (value) => updateSetting('homepageCssBundleEnabled', value),
							disabled: busy,
							key: 'homepage-css-bundle',
						}),
						h('div', { className: 'uc-css-bundle-scope-field', key: 'css-bundle-scope-wrap' }, h(SelectField, {
							label: __("CSS Bundling Scope", 'ultracache'),
                                description: __("Choose exactly one scope for generated CSS bundles. Homepage only is safest, shared reuses the homepage bundle where possible, and per-page creates separate bundles for cacheable pages.", 'ultracache'),
							value: settings.cssBundleScope || 'homepage',
							onChange: (value) => updateSetting('cssBundleScope', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							options: [
								{ value: 'homepage', label: __("Homepage only", 'ultracache') },
								{ value: 'shared', label: __("Shared site bundle", 'ultracache') },
								{ value: 'per-page', label: __("Per-page bundles", 'ultracache') },
							],
						})),
	h('div', { className: 'uc-css-bundle-mode-field', key: 'css-bundle-mode-wrap' }, h(SelectField, {
								label: __("CSS Bundle Mode", 'ultracache'),
								description: __("Choose how broadly UltraCache combines eligible local stylesheet links. Safe is recommended for public defaults; Aggressive and Full CSS Bundle are experimental and can increase blocking CSS or break layouts on some themes.", 'ultracache'),
								value: settings.homepageCssBundleMode || 'safe',
								onChange: (value) => updateSetting('homepageCssBundleMode', value),
								disabled: busy || !settings.homepageCssBundleEnabled,
								options: [
									{ value: 'safe', label: __("Safe", 'ultracache') },
									{ value: 'aggressive', label: __("Aggressive", 'ultracache') },
									{ value: 'full', label: __("Full CSS Bundle", 'ultracache') },
								],
							})),

h(ToggleRow, {
							label: __("Inline CSS Bundling", 'ultracache'),
							description: __("Inline the generated page CSS bundle directly into the document head. This is user-controlled and can greatly increase cached HTML size when the generated bundle is large; STORE profiler now shows final HTML size, inline CSS bytes, and fallback counts.", 'ultracache'),
							checked: settings.homepageCssBundleInlineEnabled,
							onChange: (value) => updateSetting('homepageCssBundleInlineEnabled', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'homepage-css-bundle-inline',
						}),
h(ToggleRow, {
							label: __("Consolidate Remaining CSS", 'ultracache'),
							description: __("After the main CSS bundle is injected, combine eligible leftover non-protected local stylesheet links into one extra CSS file. SR7/Revolution/Swiper/Slick hero CSS remains protected; this targets small leftover plugin/theme CSS calls that still block rendering.", 'ultracache'),
							checked: !!settings.leftoverCssBundleEnabled,
							onChange: (value) => updateSetting('leftoverCssBundleEnabled', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'leftover-css-bundle',
						}),
h('div', { className: 'uc-css-bundle-first-visit-field', key: 'css-bundle-first-visit-wrap' }, h(SelectField, {
							label: __("First Visit CSS Bundle Handling", 'ultracache'),
							description: __("Choose what happens when a visitor opens a page before its CSS bundle exists.", 'ultracache'),
							value: getFirstVisitCssBundleHandling(settings),
							onChange: (value) => queueSettingsPatch(getFirstVisitCssBundlePatch(value)),
							disabled: busy || !settings.homepageCssBundleEnabled,
							options: [
								{ value: 'none', label: __("Do nothing", 'ultracache') },
								{ value: 'on_entry', label: __("Build CSS bundle on entry", 'ultracache') },
								{ value: 'async', label: __("Build CSS bundle async", 'ultracache') },
							],
						})),
h(ToggleRow, {
							label: __("Async Remaining CSS", 'ultracache'),
							description: __("Rewrite low-risk local stylesheet links and UltraCache-generated external CSS bundles/optimized CSS to non-blocking print+onload loading with a noscript fallback. This complements CSS Bundling.", 'ultracache'),
							checked: settings.asyncCssEnabled,
							onChange: (value) => updateSetting('asyncCssEnabled', value),
							disabled: busy,
							key: 'async-css',
						}),
					]
				),
h(
					Card,
					{
						title: __("Fonts Optimization", 'ultracache'),
						description: __("Optimize remote Google Fonts, local @font-face display behavior, self-hosted font CSS delivery, and optional delayed icon-font loading.", 'ultracache'),
						key: 'fonts-optimization-card',
					},
					[
						h(ToggleRow, {
							label: __("Font Display Optimization", 'ultracache'),
							description: __("Adds font-display: swap to local @font-face declarations when missing and appends display=swap to remote Google Fonts requests. Uses the existing Google Fonts Swap setting internally for backward compatibility.", 'ultracache'),
							checked: settings.googleFontsSwapEnabled,
							onChange: (value) => updateSetting('googleFontsSwapEnabled', value),
							disabled: busy,
							key: 'fonts-swap',
						}),
h(ToggleRow, {
							label: __("Local Google Fonts Optimization", 'ultracache'),
							description: __("Opt-in feature. Download Google Fonts CSS and WOFF2 files into the UltraCache cache, rewrite frontend Google Fonts links and Google Fonts @import rules found in loaded same-origin CSS, and keep font-display: swap on localized CSS. This feature makes outbound requests to Google Fonts when building the local cache.", 'ultracache'),
							checked: settings.googleFontsLocalOptimizationEnabled,
							onChange: updateGoogleFontsLocalOptimization,
							disabled: busy,
							key: 'google-fonts-local',
						}),
h('div', { className: 'uc-muted mt-2 text-xs', key: 'google-fonts-cache-status' }, [
							googleFontsStatusText,
							googleFontsLastScanText ? h('div', { className: 'mt-1 text-[11px] text-zinc-500', key: 'google-fonts-last-scan' }, googleFontsLastScanText) : null,
						]),
h(ToggleRow, {
							label: __("Optimize Self-Hosted Font CSS", 'ultracache'),
							description: __("Rewrite local and inline @font-face CSS to add font-display: swap, prefer matching WOFF2 sources when available, normalize font URLs, and preload up to two likely first-paint WOFF2 files.", 'ultracache'),
							checked: settings.selfHostedFontCssOptimizationEnabled,
							onChange: (value) => updateSetting('selfHostedFontCssOptimizationEnabled', value),
							disabled: busy,
							key: 'self-hosted-fonts',
						}),
h(ToggleRow, {
							label: __("Delay icon font-face blocks", 'ultracache'),
							description: __("Detect matching icon-font @font-face blocks in bundled or standalone CSS and load them through a non-render-blocking delayed font stylesheet. This can reduce critical font loading, but icons may appear slightly later.", 'ultracache'),
							checked: !!settings.delayIconFontsEnabled,
							onChange: (value) => updateSetting('delayIconFontsEnabled', value),
							disabled: busy,
							key: 'delay-icon-fonts',
						}),
h(ToggleRow, {
							label: __("Auto-detect likely icon fonts", 'ultracache'),
							description: __("Use broad icon-font heuristics such as Font Awesome, eicons, dashicons, icomoon, flaticon, theme icon fonts, private unicode glyph usage, and /icons/ or /webfonts/ paths. The visible include/exclude lists below still win.", 'ultracache'),
							checked: !!settings.delayIconFontsAutoDetectEnabled,
							onChange: updateDelayIconFontsAutoDetect,
							disabled: busy || !settings.delayIconFontsEnabled,
							key: 'delay-icon-fonts-auto-detect',
						}),
h(ToggleRow, {
							label: __("Advanced Runtime Font CSS Rewrite", 'ultracache'),
							description: __("Advanced opt-in / experimental. Uses a MutationObserver to rewrite late-injected local font stylesheet links. Keep off unless a site specifically needs runtime font-link rewriting.", 'ultracache'),
							checked: settings.selfHostedFontRuntimeRewriteEnabled,
							onChange: (value) => updateSetting('selfHostedFontRuntimeRewriteEnabled', value),
							disabled: busy || !settings.selfHostedFontCssOptimizationEnabled,
							key: 'self-hosted-fonts-runtime-rewrite',
						}),
					]
				),
h(
					Card,
					{
						title: __("Safe Asset Cleanup", 'ultracache'),
						description: __("Optional cleanup for WooCommerce product/gallery/filter assets when they are not detected as needed.", 'ultracache'),
						key: 'asset-cleanup-section-card',
					},
					[
						h(ToggleRow, { label: __("Enable Asset Chain Cleanup", 'ultracache'), description: __("Cleans selected unnecessary frontend assets from cached HTML and late WordPress queues. Test homepage, shop, product, cart, checkout, and header search after enabling.", 'ultracache'), checked: settings.assetChainCleanupEnabled, onChange: (value) => updateSetting('assetChainCleanupEnabled', value), disabled: busy, key: 'asset-chain-cleanup-enabled' }),
h(ToggleRow, { label: __("Clean WooCommerce product/gallery assets outside product pages", 'ultracache'), description: __("Removes zoom, flexslider, PhotoSwipe, variation, and single-product assets when the cached HTML is not a single product page.", 'ultracache'), checked: settings.assetCleanupWooProductAssetsEnabled, onChange: (value) => updateSetting('assetCleanupWooProductAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-woo-product-assets' }),
h(ToggleRow, { label: __("Clean product filter assets when no filter is detected", 'ultracache'), description: __("Removes WOOF/filter scripts and styles when UltraCache cannot detect filter markup in the generated HTML.", 'ultracache'), checked: settings.assetCleanupProductFilterAssetsEnabled, onChange: (value) => updateSetting('assetCleanupProductFilterAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-product-filter-assets' }),
h(ToggleRow, { label: __("Clean WooCommerce Blocks CSS when no Woo blocks are detected", 'ultracache'), description: 'Removes wc-blocks.css from cached HTML when no WooCommerce block markup is present.', checked: settings.assetCleanupWooBlocksCssEnabled, onChange: (value) => updateSetting('assetCleanupWooBlocksCssEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-woo-blocks-css' })
					]
				),
			]),

			h('div', { className: 'uc-card', key: 'cache-engine-advanced-settings-exclusions-card' }, [
h('details', { className: 'uc-accordion uc-accordion--card', key: 'cache-engine-advanced-settings-exclusions' }, [
									h('summary', { className: 'uc-accordion__summary' }, [
										h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
											h('div', { className: 'uc-accordion__title' }, __("Advanced Settings & Exclusions", 'ultracache')),
											h('div', { className: 'uc-accordion__description' }, __("Manual include/exclude lists and higher-risk toggles for frontend delivery, CSS/JS handling, asset cleanup, and LCP image handling.", 'ultracache')),
										]),
										h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
									]),
									h('div', { className: 'uc-accordion__body space-y-4' }, [
										h(ToggleRow, {
											label: __("Fix sliders / hero sections", 'ultracache'),
											description: __("When Revolution Slider, SR7, Swiper, Slick, or similar hero/slider markup is detected, UltraCache protects slider/runtime assets, skips risky structural rewrites, and keeps SR7 first-slide LCP priority on the lifecycle-safe path when LCP Image Priority is enabled.", 'ultracache'),
											checked: !!settings.sliderSafeModeEnabled,
											onChange: (value) => updateSliderSafeModeSetting(value),
											disabled: busy,
											key: 'slider-safe-mode',
										}),
										h(ToggleRow, {
											label: __("Enable Debug", 'ultracache'),
											description: __("Allow request-triggered UltraCache debug/source headers such as X-Ultra-Cache-Source when X-UltraCache-Debug: 1 is sent. Keep OFF on production unless actively debugging.", 'ultracache'),
											checked: !!settings.debugHeadersEnabled,
											onChange: (value) => updateSetting('debugHeadersEnabled', value),
											disabled: busy,
											key: 'enable-debug-headers',
										}),
										h(ToggleRow, {
											label: __("Aggressive Async CSS", 'ultracache'),
											description: __("Optional advanced mode. Rewrite almost all remaining local stylesheet links, including late footer output, to non-blocking print+onload loading with a noscript fallback. Use the exclude list for styles that must stay blocking.", 'ultracache'),
											checked: settings.aggressiveAsyncCssEnabled,
											onChange: (value) => updateSetting('aggressiveAsyncCssEnabled', value),
											disabled: busy,
											key: 'aggressive-async-css',
										}),
										h(ToggleRow, {
								label: __("Enable query-string args caching", 'ultracache'),
								description: __("Allow UltraCache to cache URL variants that include query-string args. Excluded query-string args always bypass cache. If the whitelist below is empty, all non-excluded query-string variants can be cached.", 'ultracache'),
								checked: !!settings.cacheQueryStringsEnabled,
								onChange: (value) => updateSetting('cacheQueryStringsEnabled', value),
								disabled: busy,
								key: 'query-string-caching',
							}),
											h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'cache-engine-advanced-fields' }, [
											h(SaveableTextAreaField, {
												label: __("Exclude Paths From Caching", 'ultracache'),
												description: __("One path per line. This is the visible/editable cache path safeguard list. Reset restores defaults; Populate re-adds recommended defaults without resetting all settings.", 'ultracache'),
												value: settings.cacheExceptionPaths || '',
												onSave: (value) => updateSetting('cacheExceptionPaths', value),
												disabled: busy,
												placeholder: '/cart/\n/checkout/\n/my-account/',
												saveLabel: 'Save Excluded Paths',
												populateLabel: __("Populate Defaults", 'ultracache'),
												populateWarning: 'Your current excluded paths will be replaced with the recommended defaults.',
												onPopulate: () => populateDefaultSettingList('cacheExceptionPaths', 'excluded paths'),
												key: 'exclude-paths-from-caching',
											}),
											h(SaveableTextAreaField, {
												label: __("Excluded query-string args from Caching", 'ultracache'),
												description: __("One query key per line. This is the visible/editable unsafe query-arg safeguard list. Reset restores defaults; Populate re-adds recommended defaults without resetting all settings.", 'ultracache'),
												value: settings.cacheExceptionQueryArgs || '',
												onSave: (value) => updateSetting('cacheExceptionQueryArgs', value),
												disabled: busy,
												placeholder: 'preview\nadd-to-cart\nwc-ajax',
												saveLabel: 'Save Excluded Query-string Args',
												populateLabel: __("Populate Defaults", 'ultracache'),
												populateWarning: 'Your current excluded query-string args will be replaced with the recommended defaults.',
												onPopulate: () => populateDefaultSettingList('cacheExceptionQueryArgs', 'excluded query-string args'),
												key: 'excluded-query-string-args-from-caching',
											}),
											h(SaveableTextAreaField, {
												label: __("Query-string args whitelist", 'ultracache'),
												description: __("Optional. One query key per line. When query-string caching is enabled, UltraCache caches a query-string URL only when every query arg is listed here. Populate appends detected attributes, taxonomies, categories and tags without removing custom entries.", 'ultracache'),
												value: settings.cacheQueryStringAllowlist || '',
												onSave: (value) => updateSetting('cacheQueryStringAllowlist', value),
												disabled: busy,
												placeholder: 'product_cat\nproduct_tag\ncategory_name\ntag\nfilter_format\nquery_type_format\npa_format',
												saveLabel: 'Save Query-string Whitelist',
												populateLabel: __("Populate", 'ultracache'),
												populateWarning: 'Detected taxonomy/attribute query args will be appended. Existing custom entries are preserved.',
												onPopulate: populateQueryStringAllowlist,
												key: 'query-string-args-whitelist',
											}),
											h(SaveableTextAreaField, {
												label: __("Additional URLs for Google Fonts scanning", 'ultracache'),
												description: 'Optional local site URLs, one per line. When Local Google Fonts Optimization is enabled, UltraCache scans the homepage plus these URLs from admin/save or manual rebuild, downloads Google Fonts CSS/WOFF2 into wp-content/cache/ultracache/google-fonts, and never builds them on live frontend requests.',
												value: settings.googleFontsAdditionalScanUrls || '',
												onSave: saveGoogleFontsAdditionalScanUrls,
												disabled: busy || !settings.googleFontsLocalOptimizationEnabled,
												placeholder: '/shop/\n/category/books/\n/product/example-book/',
												saveLabel: 'Save Google Fonts URLs',
												populateLabel: __("Rebuild Google Fonts Cache", 'ultracache'),
												populateBusyLabel: __("Rebuilding…", 'ultracache'),
												populateWarning: 'This will rebuild the local Google Fonts cache from the homepage and the URLs listed here. Existing Google Fonts cache files will be replaced. Flush All Cache will not delete this font cache.',
												onPopulate: rebuildGoogleFontsCacheFromSettings,
												key: 'google-fonts-additional-scan-urls',
											}),
											h(SaveableTextAreaField, {
												label: __("Defer those scripts", 'ultracache'),
												description: __("Optional newline-separated handle or URL fragments. Matching frontend scripts are forced to native defer even when Defer JS is off or the script would normally be protected. Use carefully for scripts you explicitly want deferred.", 'ultracache'),
												value: settings.deferJsForceList || '',
												onSave: (value) => updateSetting('deferJsForceList', value),
												disabled: busy,
												placeholder: 'my-theme-script\n/custom-plugin/assets/app.js',
												saveLabel: 'Save Force Defer List',
												key: 'defer-those-scripts-list',
											}),
											null,
											h(SaveableTextAreaField, {
												label: __("Safe Third-Party Delay Patterns", 'ultracache'),
												description: __("Visible/default patterns used by Delay safe third-party JS. Matching analytics, pixels, ads, tracking, and marketing script tags are delayed unless excluded. Safe third-party scripts use a later automatic fallback than functional/local delayed scripts.", 'ultracache'),
												value: settings.delaySafeThirdPartyJsPatterns || '',
												onSave: (value) => updateSetting('delaySafeThirdPartyJsPatterns', value),
												disabled: busy || !settings.delaySafeThirdPartyJsEnabled,
												placeholder: 'googletagmanager.com\ngoogle-analytics.com\nconnect.facebook.net\nclarity.ms',
												saveLabel: 'Save Safe Third-Party Patterns',
												populateLabel: __("Populate Defaults", 'ultracache'),
												populateWarning: 'Your current safe third-party delay patterns will be replaced with the recommended defaults.',
												onPopulate: () => populateDefaultSettingList('delaySafeThirdPartyJsPatterns', 'safe third-party delay patterns'),
												key: 'delay-safe-third-party-patterns',
											}),
											h(SaveableTextAreaField, {
												label: __("Known Functional Third-Party Delay Patterns", 'ultracache'),
												description: __("Visible/default patterns used by Delay known functional third-party JS. Matching consent, captcha, maps, chat, booking, embedded form, opt-in popup, newsletter, and widget scripts are delayed unless excluded.", 'ultracache'),
												value: settings.delayFunctionalThirdPartyJsPatterns || '',
												onSave: (value) => updateSetting('delayFunctionalThirdPartyJsPatterns', value),
												disabled: busy || !settings.delayFunctionalThirdPartyJsEnabled,
												placeholder: 'recaptcha\nhcaptcha\nmaps.googleapis.com\ncomplianz\ncmplz',
												saveLabel: 'Save Known Functional Third-Party Patterns',
												populateLabel: __("Populate Defaults", 'ultracache'),
												populateWarning: 'Your current known functional third-party delay patterns will be replaced with the recommended defaults.',
												onPopulate: () => populateDefaultSettingList('delayFunctionalThirdPartyJsPatterns', 'known functional third-party delay patterns'),
												key: 'delay-functional-third-party-patterns',
											}),
											h(SaveableTextAreaField, {
												label: __("Priority Preloads", 'ultracache'),
												description: __("Optional newline-separated priority resources for early discovery. Prefix each line with image, style, script, font, or fetch. Use fetch for dynamic frontend requests, slider JSON/assets, or request chains that are not discovered early in the initial HTML. Image entries receive fetchpriority=high.", 'ultracache'),
												value: settings.criticalResourcePreloadList || '',
												onSave: (value) => updateSetting('criticalResourcePreloadList', value),
												disabled: busy || !settings.criticalRequestChainReliefEnabled,
												placeholder: 'image /wp-content/uploads/hero.webp\nstyle /wp-content/cache/ultracache/homepage-css/homepage.css\nfont /wp-content/uploads/fonts/manrope.woff2\nfetch /sliders/1?srengine=7',
												saveLabel: 'Save Priority Preloads',
												key: 'critical-resource-preload-list',
											}),
											h(SaveableTextAreaField, {
												label: __("Delay Non-Critical Request Chains", 'ultracache'),
												description: __("Optional newline-separated handle or URL fragments. Matching local scripts are delayed and matching stylesheets are converted to async print/onload loading.", 'ultracache'),
												value: settings.criticalRequestChainDelayList || '',
												onSave: (value) => updateSetting('criticalRequestChainDelayList', value),
												disabled: busy || !settings.criticalRequestChainReliefEnabled,
												placeholder: 'tooltipster\nplainoverlay\nion.rangeSlider\nsourcebuster',
												saveLabel: 'Save Chain Delay List',
												key: 'critical-request-chain-delay-list',
											}),
											h(SaveableTextAreaField, {
												label: __("Asset Cleanup Exclusions", 'ultracache'),
												description: __("Optional newline-separated handle, URL, or HTML fragments. This is the visible/editable Asset Cleanup safeguard list for builders, search, carts, checkout, and custom widgets.", 'ultracache'),
												value: settings.assetCleanupExcludeList || '',
												onSave: (value) => updateSetting('assetCleanupExcludeList', value),
												disabled: busy || !settings.assetChainCleanupEnabled,
												placeholder: 'elementor\nrevslider\nfibosearch\ncart\ncheckout',
												saveLabel: 'Save Asset Exclusions',
												populateLabel: __("Populate Defaults", 'ultracache'),
												populateWarning: 'Your current Asset Cleanup exclusions will be replaced with the recommended defaults.',
												onPopulate: () => populateDefaultSettingList('assetCleanupExcludeList', 'Asset Cleanup exclusions'),
												key: 'asset-cleanup-exclude-list',
											}),
											null,
											h(SaveableTextAreaField, {
												label: __("Async CSS Exclude List", 'ultracache'),
												description: __("Optional newline-separated handle or URL fragments. Matching stylesheets stay in the normal blocking flow for both Enable Async CSS and Aggressive Async CSS. This is the single visible/editable Async CSS safeguard list.", 'ultracache'),
												value: settings.asyncCssExcludeList || '',
												onSave: (value) => updateSetting('asyncCssExcludeList', value),
												disabled: busy || (!settings.asyncCssEnabled && !settings.aggressiveAsyncCssEnabled),
												placeholder: '/post-21.css\n/base/elementor.css',
												saveLabel: 'Save Exclude List',
												key: 'async-css-exclude-list',
											}),
										h(SaveableTextAreaField, {
											label: __("Manual LCP selector", 'ultracache'),
											description: __("Optional newline-separated CSS selectors, plain IDs, or image URL/fragments for the main above-the-fold hero/LCP target. CSS entries scope LCP discovery to that block; image entries become manual LCP preload targets.", 'ultracache'),
											value: settings.manualLcpHeroSelector || '',
											onSave: (value) => updateSetting('manualLcpHeroSelector', value),
											disabled: busy || !settings.lcpImagePriorityEnabled,
											placeholder: '#main-hero\n.hero-slider\n/wp-content/uploads/hero.webp\nhero-home.jpg',
											saveLabel: 'Save Manual LCP Selector',
											key: 'manual-lcp-hero-selector',
											}),
										]),
										h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'font-pattern-exclusions-grid' }, [
										h(SaveableTextAreaField, {
											label: __("Delay These Fonts / Patterns", 'ultracache'),
											description: __("Newline-separated font-family, filename, or URL fragments. Scan the front page or add manual patterns. Matching @font-face blocks from bundled or standalone CSS are moved into a delayed non-render-blocking font stylesheet. Use this mainly for icon fonts.", 'ultracache'),
											value: settings.delayIconFontsList || '',
											onSave: (value) => updateSetting('delayIconFontsList', value),
											disabled: busy,
											placeholder: __("Scan the front page to detect likely icon font families/files. You can also add manual fragments, one per line.", 'ultracache'),
											saveLabel: 'Save Delayed Font Patterns',
											populateLabel: __("Scan Front Page", 'ultracache'),
											populateBusyLabel: __("Scanning…", 'ultracache'),
											onPopulate: populateDelayIconFontsDefaults,
											populateWarning: 'Detected front-page icon font patterns will be appended to your current list.',
											key: 'delay-icon-fonts-list',
										}),
										h(SaveableTextAreaField, {
											label: __("Never Delay These Fonts / Patterns", 'ultracache'),
											description: __("Newline-separated font-family, filename, or URL fragments that must stay inside the normal CSS flow. Scan the front page to append detected non-icon text/brand fonts, then add manual safeguards if needed.", 'ultracache'),
											value: settings.delayIconFontsExcludeList || '',
											onSave: (value) => updateSetting('delayIconFontsExcludeList', value),
											disabled: busy,
											placeholder: __("Scan the front page to detect non-icon text/brand font families. You can also add manual fragments, one per line.", 'ultracache'),
											saveLabel: 'Save Font Exclusions',
											populateLabel: __("Scan Front Page", 'ultracache'),
											populateBusyLabel: __("Scanning…", 'ultracache'),
											onPopulate: populateDelayIconFontExclusionDefaults,
											populateWarning: 'Detected front-page non-icon fonts will be appended to your current list.',
											key: 'delay-icon-fonts-exclude-list',
										}),
										h(SaveableTextAreaField, {
											label: __("Safe Tracking Cookies", 'ultracache'),
											description: __("Cookie names or fragments that may be ignored for public cache eligibility and safe Set-Cookie storage decisions. Use this only for analytics/marketing identifiers that do not change the HTML. UltraCache never stores or replays Set-Cookie headers.", 'ultracache'),
											value: settings.safeTrackingCookieList || '',
											onSave: (value) => updateSetting('safeTrackingCookieList', value),
											disabled: busy,
											placeholder: '_fbp\n_fbc\n_ga\n_gid\n_clck\n_clsk',
											saveLabel: 'Save Safe Tracking Cookies',
											populateLabel: __("Populate Defaults", 'ultracache'),
											populateWarning: 'Your current safe tracking cookie list will be replaced with the recommended defaults.',
											onPopulate: () => populateDefaultSettingList('safeTrackingCookieList', 'safe tracking cookies'),
											key: 'safe-tracking-cookie-list',
										}),
										h(SaveableTextAreaField, {
											label: __("Never Cache When These Cookies Exist", 'ultracache'),
											description: __("Cookie names or fragments that can change visible HTML, cart/session/account state, prices, wishlist, compare, checkout, protected content, or comment forms. Matching requests bypass public HTML cache.", 'ultracache'),
											value: settings.unsafeCacheCookieList || '',
											onSave: (value) => updateSetting('unsafeCacheCookieList', value),
											disabled: busy,
											placeholder: 'wordpress_logged_in_\nwp_woocommerce_session_\nwoocommerce_items_in_cart\nwoocommerce_cart_hash',
											saveLabel: 'Save Unsafe Cookies',
											populateLabel: __("Populate Defaults", 'ultracache'),
											populateWarning: 'Your current unsafe cookie list will be replaced with the recommended defaults.',
											onPopulate: () => populateDefaultSettingList('unsafeCacheCookieList', 'unsafe cache cookies'),
											key: 'unsafe-cache-cookie-list',
										}),
										]),
										
										h(CssBundleExclusionsDiagnosticsField, {
											value: settings.homepageCssBundleExcludeList || '',
											onSave: (value) => updateSetting('homepageCssBundleExcludeList', value),
											disabled: busy || !settings.homepageCssBundleEnabled,
											placeholder: '/wp-content/plugins/plugin-name/assets/style.css\n/wp-content/themes/theme-name/assets/critical.css',
											onPopulateDefaults: populateCssBundleExclusionDefaults,
											onRunDiagnostics: runCssDiagnosticsForUrl,
											onDownloadJson: downloadCssDiagnosticsJson,
											onClearResult: clearCssDiagnosticsResult,
											profile: performanceProfile,
											onCopyCssExclusion: copyCssBundleExclusionSuggestion,
											key: 'homepage-css-bundle-exclude-diagnostics-field',
										}),

										h(DeferDelayExclusionsField, {
											value: settings.deferJsExcludeList || '',
											onSave: (value) => updateSetting('deferJsExcludeList', value),
											disabled: busy,
											placeholder: 'jquery\njquery-migrate\n/wp-includes/js/\nwp-util\nunderscore\ncart\ncheckout',
											onPopulateDefaults: populateDeferDelayExclusionDefaults,
											onSafeInitScan: (currentDraft) => scanSafeDeferInitScriptsAndMerge(currentDraft, false),
											onScan: runJsDelaySafetyScanForUrl,
											onRuntimeScan: runBrowserRuntimeJsScanForUrl,
											onLoadLatestProfileScan: loadLatestJsDelaySafetyScan,
											key: 'defer-stages-exclude-list-final',
										}),
									]),
								])
			]),

			h(RedisCard, { form: redisForm, diagnostics, busy: hasDashboardWorkInProgress(), objectCacheEnabled: settings.objectCacheEnabled, onObjectCacheEnabledChange: (value) => updateSetting('objectCacheEnabled', value), onFieldChange: updateRedisField, onSave: saveRedisSettings, onTest: testObjectCacheBackend, onFlush: flushObjectCache, onRemoveConflictingDropins: removeConflictingCacheDropins, onRecheckConflicts: recheckCacheConflicts, key: 'redis-card' }),

			h(
				Card,
				{
					title: __("Automation & Scheduling", 'ultracache'),
					description: __("Scheduled cache cleanup, background warmup queue, and stale cache timing controls.", 'ultracache'),
					key: 'automation-scheduling-reworked',
				},
				[
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
						h(ToggleRow, {
							label: __("Scheduled Cache Cleanup", 'ultracache'),
							description: __("Run an automatic full cache purge using the interval below.", 'ultracache'),
							checked: settings.cacheCleanupEnabled,
							onChange: (value) => updateSetting('cacheCleanupEnabled', value),
							disabled: busy,
							key: 'cleanup',
						}),
						h(ToggleRow, {
							label: __("Cron Warm Up", 'ultracache'),
							description: __("Enable the minute-by-minute background warm queue. Homepage is warmed first. If CSS Bundling and bundle-on-entry/warm are enabled, missing CSS bundles may be prepared before HTML is cached; otherwise the queue warms HTML only.", 'ultracache'),
							checked: settings.cronWarmEnabled,
							onChange: (value) => updateSetting('cronWarmEnabled', value),
							disabled: busy,
							key: 'cron-warm-enabled',
						}),
						h(ToggleRow, {
							label: __("Start Cron Warm Up after Scheduled Cleanup", 'ultracache'),
							description: __("Start the cron warm queue after the scheduled cleanup purge completes.", 'ultracache'),
							checked: settings.cronWarmStartAfterCleanup,
							onChange: (value) => updateSetting('cronWarmStartAfterCleanup', value),
							disabled: busy || !settings.cacheCleanupEnabled || !settings.cronWarmEnabled,
							key: 'cleanup-warm',
						}),
						h(ToggleRow, {
							label: __("Start Cron Warm Up after Flush All Cache", 'ultracache'),
							description: __("Start the cron warm queue after a manual full cache purge.", 'ultracache'),
							checked: !!settings.cronWarmStartAfterManualPurge,
							onChange: (value) => updateSetting('cronWarmStartAfterManualPurge', value),
							disabled: busy || !settings.cronWarmEnabled,
							key: 'manual-purge-warm',
						}),
						h(NumberRow, {
							label: __("Cleanup interval (hours)", 'ultracache'),
							description: __("Use 24 for daily, 168 for weekly, or any custom number of hours.", 'ultracache'),
							value: advancedForm.cacheCleanupIntervalHours,
							onChange: (value) => updateAdvancedField('cacheCleanupIntervalHours', value),
							disabled: busy,
							min: 1,
							key: 'cleanup-hours',
						}),
						h(NumberRow, {
							label: __("Cron warm pages per minute", 'ultracache'),
							description: __("How many HTML URLs to warm per minute in the cron warm-up queue. Homepage is always warmed first. If CSS Bundling is enabled, missing bundles may be prepared before HTML is cached. Lower values are safer on slower servers. Set 0 to pause queue processing.", 'ultracache'),
							value: advancedForm.cronWarmPagesPerMinute,
							onChange: (value) => updateAdvancedField('cronWarmPagesPerMinute', value),
							disabled: busy,
							min: 0,
							key: 'warm-limit',
						}),
						h(NumberRow, {
							label: __("Scheduled / Cron warm limit", 'ultracache'),
							description: getScheduledWarmLimitSummary(advancedForm, settings),
							value: advancedForm.scheduledWarmLimit,
							onChange: (value) => updateAdvancedField('scheduledWarmLimit', value),
							disabled: busy,
							min: 1,
							key: 'scheduled-warm-limit',
						}),
						h(ToggleRow, {
							label: __("Stale While Revalidate", 'ultracache'),
							description: __("Serve stale HTML only within the max stale window while UltraCache refreshes it in the background.", 'ultracache'),
							checked: settings.staleWhileRevalidateEnabled,
							onChange: (value) => updateSetting('staleWhileRevalidateEnabled', value),
							disabled: busy,
							key: 'swr-toggle',
						}),
						h(NumberRow, {
							label: __("Fresh TTL (minutes)", 'ultracache'),
							description: __("Serve a normal cache hit while the file age stays within this freshness window. Default: 15 minutes.", 'ultracache'),
							value: advancedForm.cacheFreshTtlMinutes,
							onChange: (value) => updateAdvancedField('cacheFreshTtlMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'fresh-ttl',
						}),
						h(NumberRow, {
							label: __("Max stale window (minutes)", 'ultracache'),
							description: __("After freshness expires, UltraCache may still serve the stale file until this limit while it refreshes in the background. Default: 720 minutes (12 hours).", 'ultracache'),
							value: advancedForm.cacheMaxStaleMinutes,
							onChange: (value) => updateAdvancedField('cacheMaxStaleMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'max-stale',
						}),
						h(NumberRow, {
							label: __("CSS bundle cleanup grace window (hours)", 'ultracache'),
							description: __("How long orphan-like CSS bundle files stay protected before cleanup may delete them. This helps stale HTML from Varnish, browser cache, or page cache keep valid CSS references. Default: 48 hours. Range: 1–168.", 'ultracache'),
							value: advancedForm.cssBundleCleanupGraceHours,
							onChange: (value) => updateAdvancedField('cssBundleCleanupGraceHours', value),
							disabled: busy,
							min: 1,
							max: 168,
							step: 1,
							key: 'css-cleanup-grace-hours',
						}),
						h(NumberRow, {
							label: __("CSS bundle cleanup delete limit", 'ultracache'),
							description: __("Maximum orphan-like CSS bundle files to delete per cleanup run. Lower values are safer on shared hosting; higher values clear test/build leftovers faster. Default: 60. Range: 5–500.", 'ultracache'),
							value: advancedForm.cssBundleCleanupDeleteLimit,
							onChange: (value) => updateAdvancedField('cssBundleCleanupDeleteLimit', value),
							disabled: busy,
							min: 5,
							max: 500,
							step: 1,
							key: 'css-cleanup-delete-limit',
						}),
					]),
					h('div', { className: 'mt-4 flex justify-end' }, [
						h(Button, { onClick: saveAdvancedSettings, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Automation & Scheduling'),
					]),
				]
			),

			showVarnishCard ? h(VarnishCard, { form: varnishForm, diagnostics, busy: false, onFieldChange: updateVarnishField, onSave: saveVarnishSettings, onTest: runVarnishTest, onFlushAll: runVarnishFlushAll, onRemoveConflictingDropins: removeConflictingCacheDropins, onRecheckConflicts: recheckCacheConflicts, key: 'varnish-card' }) : null,
			h('div', { className: 'uc-info-grid', key: 'php-cache-cards' }, [
			h(OPcacheCard, { stats, busy: false, onFlush: flushOpcache, key: 'opcache-card' }),
			h(APCuCard, { stats, settings, busy: false, onFlush: flushApcu, onToggleScheduledCleanup: (value) => updateSetting('apcuFlushOnScheduledCleanup', value), key: 'apcu-card' }),
			h(ExternalCacheCard, { title: __("LiteSpeed Cache", 'ultracache'), description: __("Detected LiteSpeed/OpenLiteSpeed cache integration. UltraCache uses the LiteSpeed plugin API when present, otherwise it requests server-level purge with the X-LiteSpeed-Purge response header.", 'ultracache'), layer: getExternalCacheLayer(stats, 'litespeed'), busy: false, onFlush: flushLiteSpeed, key: 'litespeed-cache-card' }),
			h(ExternalCacheCard, { title: __("Nginx Cache", 'ultracache'), description: __("Detected Nginx cache integration. UltraCache flushes Nginx only when a safe WordPress purge hook/integration is available.", 'ultracache'), layer: getExternalCacheLayer(stats, 'nginx'), busy: false, onFlush: flushNginx, key: 'nginx-cache-card' }),
			]),
			h(ExternalCacheFlushSettingsCard, { stats, diagnostics, settings, busy: false, onRedetect: redetectExternalCaches, onToggle: (key, value) => updateSetting(key, value), key: 'external-cache-flush-settings' }),

				h('div', { className: 'uc-info-grid', key: 'info-cards' }, [
					h(DiagnosticsCard, { diagnostics, stats, open: infoAccordionsOpen, onToggle: function() { setInfoAccordionsOpen(function(current) { return !current; }); }, busy, onRefreshStorageDiagnostics: refreshStorageDiagnostics, key: 'diagnostics' }),
					h(ActivitySummaryCard, { stats, cssBundleDiagnostics, open: infoAccordionsOpen, onToggle: function() { setInfoAccordionsOpen(function(current) { return !current; }); }, key: 'activity-summary' }),
				]),
				h(PerformanceProfilerCard, {
					profile: performanceProfile,
					busy: !!(asyncActions.performance_profile_compact || asyncActions.performance_profile_verbose || asyncActions.performance_profile_callback),
					onRun: runPerformanceProfile,
					onDownload: downloadPerformanceProfileJson,
					onClear: clearPerformanceProfile,
					onCopyCssExclusion: copyCssBundleExclusionSuggestion,
					key: 'performance-profiler-card'
				}),
				h(AdvancedDiagnosticsCard, { diagnostics, stats, busy, onRefreshStorageDiagnostics: refreshStorageDiagnostics, key: 'advanced-diagnostics-card' }),

			h(
				Card,
				{
					title: __("Cache Decision Tester", 'ultracache'),
					description: __("Inspect how UltraCache evaluates a frontend URL without using your current admin session cookies.", 'ultracache'),
					key: 'inspector',
				},
				[
					h(TextField, {
						label: __("URL or path", 'ultracache'),
						description: __("Paste a full local URL or just a path like /checkout/ or /product/widget/?add-to-cart=12.", 'ultracache'),
						value: inspectUrl,
						onChange: setInspectUrl,
						disabled: inspectBusy,
						placeholder: __("/sample-page/?utm_source=test", 'ultracache'),
						onKeyDown: (event) => {
							if ('Enter' === event.key) {
								event.preventDefault();
								inspectCacheDecision();
							}
						},
					}),
					h('div', { className: 'mt-4 flex flex-wrap gap-3 items-center' }, [
						h(Button, { onClick: inspectCacheDecision, disabled: inspectBusy || !String(inspectUrl || '').trim(), variant: 'primary' }, inspectBusy ? 'Inspecting…' : 'Inspect URL'),
						inspectResult
							? h(StatusPill, { ok: !!inspectResult.cacheable, text: inspectResult.cacheable ? 'Cacheable' : 'Bypassed' })
							: null,
					]),
					inspectResult
						? h('div', { className: 'mt-5 grid grid-cols-1 md:grid-cols-2 gap-6' }, [
							h('div', { className: 'space-y-0' }, [
								h(DetailRow, { label: __("Reason", 'ultracache'), value: inspectResult.reasonLabel || inspectResult.reason }),
								h(DetailRow, { label: __("Normalized URL", 'ultracache'), value: inspectResult.normalizedUrl || inspectResult.url, mono: true }),
								h(DetailRow, { label: __("Normalized path", 'ultracache'), value: inspectResult.normalizedPath || inspectResult.path, mono: true }),
								h(DetailRow, { label: __("Query string", 'ultracache'), value: inspectResult.query || '—', mono: true }),
								h(DetailRow, { label: __("Matched excluded path rule", 'ultracache'), value: inspectResult.matchedExcludedPathRule, mono: true }),
								h(DetailRow, { label: __("Matched excluded query arg", 'ultracache'), value: inspectResult.matchedExcludedQueryArg, mono: true }),
								h(DetailRow, { label: __("Non-allowlisted query arg", 'ultracache'), value: inspectResult.matchedNonAllowlistedQueryArg, mono: true }),
								h(DetailRow, { label: __("Matched WooCommerce rule", 'ultracache'), value: inspectResult.matchedWooRule ? ((inspectResult.matchedWooRuleType || 'rule') + ': ' + inspectResult.matchedWooRule) : '', mono: true }),
								h(DetailRow, { label: __("Query arg keys", 'ultracache'), value: Array.isArray(inspectResult.queryArgKeys) && inspectResult.queryArgKeys.length ? inspectResult.queryArgKeys.join(', ') : '' }),
								h(DetailRow, { label: __("Notes", 'ultracache'), value: inspectResult.simulationNote }),
							]),
							h('div', { className: 'space-y-0' }, [
								h(DetailRow, { label: __("Local URL", 'ultracache'), value: inspectResult.local ? 'Yes' : 'No' }),
								h(DetailRow, { label: __("Page cache enabled", 'ultracache'), value: inspectResult.pageCacheEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: __("WooCommerce safe mode", 'ultracache'), value: inspectResult.wooSafeModeEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: __("Cache query strings", 'ultracache'), value: inspectResult.cacheQueryStrings ? 'Yes' : 'No' }),
								inspectResult.cacheable && inspectResult.cachePaths
									? h('div', { className: 'pt-2' }, [
										h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, __("Expected cache files", 'ultracache')),
										h(DetailRow, { label: __("Original HTML", 'ultracache'), value: inspectResult.cachePaths.orig, mono: true }),
										h(DetailRow, { label: __("WebP HTML", 'ultracache'), value: inspectResult.cachePaths.webp, mono: true }),
										h(DetailRow, { label: __("AVIF HTML", 'ultracache'), value: inspectResult.cachePaths.avif, mono: true }),
									])
									: null,
							]),
						])
						: h('div', { className: 'mt-4 text-xs text-zinc-500' }, __("Enter a local URL or path to see the exact cache decision and matching bypass rule.", 'ultracache')),
				]
			),

			h(
				Card,
				{
					title: __("Export / Import Settings", 'ultracache'),
					description: __("Download a JSON backup of UltraCache dashboard settings or restore them on another site.", 'ultracache'),
					key: 'export-import',
				},
				[
					h('input', {
						type: 'file',
						accept: 'application/json,.json',
						ref: importFileInputRef,
						onChange: importSettingsFile,
						style: { display: 'none' },
						key: 'file-input',
					}),
					h('div', { className: 'space-y-3', key: 'copy' }, [
						h('div', { className: 'text-sm text-white' }, __("Export creates a portable JSON file from the current UltraCache dashboard settings.", 'ultracache')),
						h('div', { className: 'text-xs text-zinc-500' }, __("Import applies only supported dashboard options. Generated cache files, drop-ins, and runtime state are rebuilt by the existing save flow.", 'ultracache')),
					]),
					h('div', { className: 'mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 items-start', key: 'uninstall-policy' }, [
						h('div', { key: 'label' }, [
							h('div', { className: 'text-sm text-white' }, __("Delete / uninstall cleanup policy", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, getUninstallCleanupPolicyDescription((settings || {}).uninstallCleanupPolicy)),
							h('div', { className: 'text-xs text-amber-200/80 mt-2' }, __("Generated images under uploads/uc-images are never deleted automatically.", 'ultracache')),
						]),
						h(CustomSelect, {
							key: 'select',
							className: 'md:max-w-sm w-full md:ml-auto',
							value: normalizeUninstallCleanupPolicy((settings || {}).uninstallCleanupPolicy),
							disabled: busy,
							onChange: (nextValue) => updateSetting('uninstallCleanupPolicy', normalizeUninstallCleanupPolicy(nextValue)),
							options: [
								{ value: 'plugin_only', label: __("Only delete/deactivate plugin", 'ultracache') },
								{ value: 'keep_settings', label: __("Keep plugin settings", 'ultracache') },
								{ value: 'keep_settings_tables', label: __("Keep plugin settings and tables", 'ultracache') },
								{ value: 'delete_everything', label: __("Delete everything", 'ultracache') },
							],
						}),
					]),
					h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
						h(Button, { onClick: exportSettingsFile, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Export Settings'),
						h(Button, { onClick: openImportSettingsDialog, disabled: busy, variant: 'light' }, busy ? 'Working…' : 'Import Settings'),
					h(Button, { onClick: resetSettingsToDefaults, disabled: busy, variant: 'light' }, busy ? 'Working…' : 'Reset Settings'),
					h(Button, { onClick: deleteAllPluginDataAndDeactivate, disabled: busy, variant: 'danger' }, busy ? 'Working…' : 'Delete all plugin data and deactivate plugin'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500', key: 'hint' }, __("Recommended flow: export from the known-good site, then import into the target site and review Diagnostics once.", 'ultracache')),
					h('div', { className: 'mt-2 text-xs text-zinc-500', key: 'delete-hint' }, 'Delete/deactivate follows the selected cleanup policy. Generated media remains under wp-content/uploads/uc-images/ and must be removed manually if you want it deleted.'),
				]
			),

			h(SupportInlineCard, {
				isMobile,
				onMobileTrigger: openSupportModal,
				onHireClick: handleHireClick,
				key: 'support-inline-card',
			}),

			h(
				'div',
				{
					className:
						'uc-help-box bg-zinc-900/50 border border-zinc-800 p-6 rounded text-xs text-zinc-400 leading-relaxed',
					key: 'notes',
				},
				[
					h('p', { className: 'mb-2 font-bold text-zinc-300' }, __("Quick start & examples", 'ultracache')),
					h('div', { className: 'space-y-4' }, [
						h('p', { className: 'm-0' }, __("For most sites, begin with the Balanced profile. It enables the safest high-impact optimizations without pushing CSS or JavaScript too aggressively.", 'ultracache')),
						h('p', { className: 'm-0' }, __("After applying a profile, run Flush All Cache once and warm the homepage.", 'ultracache')),
						h('div', { className: 'space-y-1' }, [
							h('div', { className: 'text-zinc-300 font-semibold', key: 'css-title' }, __("For better CSS results", 'ultracache')),
							h('p', { className: 'm-0', key: 'css-1' }, __("Start with Safe CSS Bundling. If the frontend and PageSpeed remain stable, test Aggressive CSS Bundling.", 'ultracache')),
							h('p', { className: 'm-0', key: 'css-2' }, __("When testing Aggressive or Full CSS Bundling, review the CSS Bundle Summary. If the bundle becomes too large or performance gets worse, exclude the 2–3 largest or most problematic CSS files from bundling, then warm again.", 'ultracache')),
					]),
						h('div', { className: 'space-y-1' }, [
							h('div', { className: 'text-zinc-300 font-semibold', key: 'js-title' }, __("For better JavaScript results", 'ultracache')),
							h('p', { className: 'm-0', key: 'js-1' }, __("Enable defer/delay options gradually, then run Runtime JS Scan after JavaScript changes.", 'ultracache')),
							h('p', { className: 'm-0', key: 'js-2' }, __("If the scan reports dependency errors, add the affected scripts to the visible exclusions and test again. Pay extra attention to Elementor, WooCommerce cart/checkout, search/filter pages, mobile menu, sliders/hero sections, forms, and third-party scripts.", 'ultracache')),
					]),
						h('div', { className: 'space-y-1' }, [
							h('div', { className: 'text-zinc-300 font-semibold', key: 'scheduled-title' }, __("Scheduled warm-up", 'ultracache')),
							h('p', { className: 'm-0', key: 'scheduled-1' }, __("Scheduled / cron warm-up uses the selected Full-site warm-up sources. The Scheduled / Cron warm limit is a cap, not a target.", 'ultracache')),
							h('p', { className: 'm-0', key: 'scheduled-2' }, __("Priority order: homepage / blog index → menu URLs → pages → posts → categories → tags → other supported sources.", 'ultracache')),
					]),
						h('div', { className: 'space-y-2' }, [
							h('div', { className: 'text-zinc-300 font-semibold', key: 'cli-title' }, __("Media optimization with WP-CLI", 'ultracache')),
							h('p', { className: 'm-0', key: 'cli-copy' }, __("Use WP-CLI when you want to generate, repair, or complete AVIF/WebP files for the media library.", 'ultracache')),
							h('pre', { className: 'm-0 whitespace-pre-wrap rounded-xl bg-black/25 p-3 font-mono text-[11px] text-zinc-300', key: 'cli-code' }, 'wp ultracache media status\nwp ultracache media rebuild --only-missing --media-format=both\nwp ultracache media retry-failed\nwp ultracache media process\nwp ultracache --help'),
					]),
						h('p', { className: 'm-0' }, __("After major changes, test the homepage, key landing pages, product pages, cart, checkout, account pages, search/filter pages, forms, mobile menu, sliders, and hero sections.", 'ultracache')),
					]),

				]
			),
		]);
	}

	if (ReactDOMApi && typeof ReactDOMApi.createRoot === 'function') {
		ReactDOMApi.createRoot(rootEl).render(h(App));
	} else if (ReactDOMApi && typeof ReactDOMApi.render === 'function') {
		ReactDOMApi.render(h(App), rootEl);
	}
})();
