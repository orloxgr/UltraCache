/* UltraCache - Dashboard JS (No-Build Version) */
(function () {
	const elementApi = (window.wp && window.wp.element) ? window.wp.element : null;
	const ReactApi = elementApi || window.React;
	const ReactDOMApi = elementApi || window.ReactDOM;
	const { createElement: h, useCallback, useEffect, useMemo, useRef, useState } = ReactApi;

	const rootEl =
		document.getElementById('uc-dashboard') ||
		document.getElementById('ucwp-admin-root') ||
		document.getElementById('ucwp-root') ||
		document.getElementById('ultracache-root');
	if (!rootEl) {
		return;
	}

	const ucwp = window.ucwpData || {};
	const initialSettings = ucwp.settings || {};
	const initialStats = ucwp.stats || {};
	const avifSupport = ucwp.avifSupport || { supported: false };
	const initialDiagnostics = ucwp.diagnostics || initialStats.diagnostics || {};
	const initialDefaults = ucwp.defaults || {};
	const crawlScopeSummary = ucwp.crawlScopeSummary || {};
	const frontendProbeUrl = ucwp.frontendProbeUrl || '/';

	const CLEAR_NOTICE_DELAY = 4200;
	const SYSTEM_NOTICE_DELAY = 7000;
	const SYSTEM_NOTICE_COOLDOWN = 24 * 60 * 60 * 1000;
	const STATS_REFRESH_INTERVAL = 15000;
	const SETTINGS_SAVE_DEBOUNCE_MS = 700;
	const ACTION_QUEUE_POLL_DELAY = 750;
	const ACTION_QUEUE_MAX_POLLS = 160;
	const JOB_STORAGE_KEY = 'ucwp-dashboard-job-state-v3';
	const DEFAULT_QUEUE_BATCH_SIZE = 100;
	const MAX_ITEM_RETRIES = 2;
	const SUPPORT_LINKS = {
		coffee: 'https://www.paypal.com/ncp/payment/LDBFB3RRB3E9J',
		beer: 'https://www.paypal.com/ncp/payment/G5RNTC3UF58VU',
		meal: 'https://www.paypal.com/ncp/payment/4NP9RNUYRFRFA',
		hire: 'mailto:byron@iniotakis.com?subject=Hire%20me%20for%20WordPress%20work',
	};
	const IMPORT_EXPORT_SETTING_KEYS = [
		'pageCacheEnabled',
		'objectCacheEnabled',
		'objectCacheBackend',
		'redisHost',
		'redisPort',
		'redisDatabase',
		'redisPrefix',
		'redisUseTls',
		'redisPersistent',
		'redisConnectTimeoutMs',
		'redisReadTimeoutMs',
		'brotliEnabled',
		'gzipEnabled',
		'cacheStatsEnabled',
		'mediaOptimizationEnabled',
		'mediaGenerateOnUploadEnabled',
		'mediaGenerateOnDemandEnabled',
		'mediaOutputMode',
		'deferJsEnabled',
		'deferJsForceList',
		'deferJsExcludeList',
		'delayThirdPartyJsEnabled',
		'asyncExternalScriptsEnabled',
		'homepageCssBundleEnabled',
		'homepageCssBundleInlineEnabled',
		'homepageCssBundleExcludeList',
		'homepageCssBundleMode',
		'cssBundleScope',
		'pageCssBundleOnEntryEnabled',
		'frontendSafeModeEnabled',
		'sliderSafeModeEnabled',
		'clsDimensionsEnabled',
		'asyncCssEnabled',
		'asyncCssExcludeList',
		'aggressiveAsyncCssEnabled',
		'aggressiveAsyncCssExcludeList',
		'delayNonCriticalJsEnabled',
		'delayNonCriticalJsExcludeList',
		'lcpImagePriorityEnabled',
		'lcpBoundaryDeferEnabled',
		'lcpImagePriorityOverride',
		'mainThreadReliefEnabled',
		'criticalRequestChainReliefEnabled',
		'criticalResourcePreloadList',
		'criticalFetchPreloadList',
		'criticalRequestChainDelayList',
		'assetChainCleanupEnabled',
		'assetCleanupWooProductAssetsEnabled',
		'assetCleanupProductFilterAssetsEnabled',
		'assetCleanupWooBlocksCssEnabled',
		'assetCleanupExcludeList',
		'googleFontsSwapEnabled',
		'googleFontsLocalOptimizationEnabled',
		'selfHostedFontCssOptimizationEnabled',
		'selfHostedFontRuntimeRewriteEnabled',
		'speculationRulesEnabled',
		'browserCacheRulesEnabled',
		'varnishCliEnabled',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishCliMethod',
		'varnishCliDebug',
		'preRenderOnSave',
		'woocommerceSafeModeEnabled',
		'cacheCleanupEnabled',
		'apcuFlushOnScheduledCleanup',
		'cronWarmEnabled',
		'cronWarmStartAfterCleanup',
		'cronWarmStartAfterManualPurge',
		'cronWarmPagesPerMinute',
		'scheduledWarmLimit',
		'staleWhileRevalidateEnabled',
		'cacheCleanupIntervalHours',
		'cacheFreshTtlMinutes',
		'cacheMaxStaleMinutes',
		'cacheExceptionPaths',
		'cacheExceptionQueryArgs',
		'cacheQueryStringsEnabled',
		'cacheQueryStringAllowlist',
	];


	const PERFORMANCE_PROFILE_ORDER = ['off', 'safe', 'balanced', 'aggressive'];
	const PERFORMANCE_PROFILE_DISPLAY_ORDER = ['custom', 'off', 'safe', 'balanced', 'aggressive'];
	const PERFORMANCE_PROFILE_CUSTOM = {
		label: 'Custom',
		description: 'Turns on automatically when current settings no longer match a known preset. It preserves your manual choices.',
	};
	const PERFORMANCE_PROFILES = {
		off: { label: 'All Off', description: 'Disable page cache, object cache, media, CSS, JS, fonts, prefetch, warmup, and scheduled jobs. Best for first install or troubleshooting.', patch: {
			pageCacheEnabled: false, objectCacheEnabled: false, brotliEnabled: false, gzipEnabled: false, cacheStatsEnabled: false, mediaOptimizationEnabled: false, mediaGenerateOnUploadEnabled: false, mediaGenerateOnDemandEnabled: false,
			deferJsEnabled: false, delayThirdPartyJsEnabled: false, asyncExternalScriptsEnabled: false, homepageCssBundleEnabled: false, homepageCssBundleInlineEnabled: false, pageCssBundleOnEntryEnabled: false,
			frontendSafeModeEnabled: false, sliderSafeModeEnabled: false, clsDimensionsEnabled: false, asyncCssEnabled: false, aggressiveAsyncCssEnabled: false, delayNonCriticalJsEnabled: false, lcpImagePriorityEnabled: false, lcpBoundaryDeferEnabled: false, mainThreadReliefEnabled: false, criticalRequestChainReliefEnabled: false,
			assetChainCleanupEnabled: false, assetCleanupWooProductAssetsEnabled: false, assetCleanupProductFilterAssetsEnabled: false, assetCleanupWooBlocksCssEnabled: false, googleFontsSwapEnabled: false, googleFontsLocalOptimizationEnabled: false, selfHostedFontCssOptimizationEnabled: false, selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: false, browserCacheRulesEnabled: false, preRenderOnSave: false, woocommerceSafeModeEnabled: false, cacheCleanupEnabled: false, apcuFlushOnScheduledCleanup: false, cronWarmEnabled: false, cronWarmStartAfterCleanup: false, cronWarmStartAfterManualPurge: false, staleWhileRevalidateEnabled: false, cacheQueryStringsEnabled: false,
			homepageCssBundleMode: 'safe', cssBundleScope: 'homepage', mediaOutputMode: 'auto',
		} },
		safe: { label: 'Safe', description: 'Enables page caching, WooCommerce-safe bypasses, browser cache headers, save-time warmup, and stale protection. No JS/CSS/media rewrites.', patch: {
			pageCacheEnabled: true, objectCacheEnabled: false, cacheStatsEnabled: false, browserCacheRulesEnabled: true, preRenderOnSave: true, woocommerceSafeModeEnabled: true, staleWhileRevalidateEnabled: true, cacheFreshTtlMinutes: 60, cacheMaxStaleMinutes: 1440,
			cronWarmEnabled: false, cacheCleanupEnabled: false, mediaOptimizationEnabled: false, lcpImagePriorityEnabled: false, lcpBoundaryDeferEnabled: false, deferJsEnabled: false, delayThirdPartyJsEnabled: false, homepageCssBundleEnabled: false, asyncCssEnabled: false, speculationRulesEnabled: false, cacheQueryStringsEnabled: false, cssBundleScope: 'homepage',
		} },
		balanced: { label: 'Balanced', description: 'Adds object cache, media optimization, Safe CLS/LCP image hints, conservative JS defer, safe CSS bundling, and font-display improvements.', patch: {
			pageCacheEnabled: true, objectCacheEnabled: true, cacheStatsEnabled: false, browserCacheRulesEnabled: true, preRenderOnSave: true, woocommerceSafeModeEnabled: true, staleWhileRevalidateEnabled: true, cacheFreshTtlMinutes: 60, cacheMaxStaleMinutes: 1440,
			mediaOptimizationEnabled: true, mediaGenerateOnUploadEnabled: true, mediaGenerateOnDemandEnabled: true, mediaOutputMode: 'auto', clsDimensionsEnabled: true, lcpImagePriorityEnabled: true, deferJsEnabled: true, delayThirdPartyJsEnabled: false, mainThreadReliefEnabled: false, criticalRequestChainReliefEnabled: false,
			homepageCssBundleEnabled: true, homepageCssBundleInlineEnabled: false, homepageCssBundleMode: 'safe', cssBundleScope: 'homepage', pageCssBundleOnEntryEnabled: false, asyncCssEnabled: false, googleFontsSwapEnabled: true, googleFontsLocalOptimizationEnabled: false, selfHostedFontCssOptimizationEnabled: true, selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: false, cronWarmEnabled: false, cacheCleanupEnabled: false, cacheQueryStringsEnabled: false,
		} },
		aggressive: { label: 'Aggressive', description: 'Balanced plus delayed third-party JS, main-thread relief, aggressive CSS bundling, manual CSS bundle warm actions, speculation prefetch, cron HTML warmup, and scheduled cleanup.', patch: {
			pageCacheEnabled: true, objectCacheEnabled: true, cacheStatsEnabled: false, browserCacheRulesEnabled: true, preRenderOnSave: true, woocommerceSafeModeEnabled: true, staleWhileRevalidateEnabled: true, cacheFreshTtlMinutes: 60, cacheMaxStaleMinutes: 1440,
			mediaOptimizationEnabled: true, mediaGenerateOnUploadEnabled: true, mediaGenerateOnDemandEnabled: true, mediaOutputMode: 'auto', clsDimensionsEnabled: true, lcpImagePriorityEnabled: true, lcpBoundaryDeferEnabled: true, deferJsEnabled: true, delayThirdPartyJsEnabled: true, mainThreadReliefEnabled: true, criticalRequestChainReliefEnabled: true,
			homepageCssBundleEnabled: true, homepageCssBundleInlineEnabled: true, homepageCssBundleMode: 'aggressive', cssBundleScope: 'shared', pageCssBundleOnEntryEnabled: true, asyncCssEnabled: true, googleFontsSwapEnabled: true, googleFontsLocalOptimizationEnabled: false, selfHostedFontCssOptimizationEnabled: true, selfHostedFontRuntimeRewriteEnabled: false,
			speculationRulesEnabled: true, cronWarmEnabled: false, cronWarmStartAfterCleanup: false, cronWarmStartAfterManualPurge: false, cacheCleanupEnabled: true, cacheQueryStringsEnabled: false,
		} },
	};


	function getPerformanceProfilePatch(profileKey) {
		const profile = PERFORMANCE_PROFILES[profileKey];
		if (!profile || !profile.patch) {
			return {};
		}
		if (profileKey === 'off') {
			return Object.assign({}, profile.patch);
		}
		return Object.assign({}, PERFORMANCE_PROFILES.off.patch, profile.patch);
	}

	function settingValueMatchesProfileValue(currentValue, profileValue) {
		if (typeof profileValue === 'boolean') { return !!currentValue === profileValue; }
		if (typeof profileValue === 'number') { return Number(currentValue) === profileValue; }
		return String(currentValue || '') === String(profileValue || '');
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

	function getDefaultScheduledWarmLimit() {
		const value = Number(crawlScopeSummary.defaultScheduledWarmLimit || 0);
		if (Number.isFinite(value) && value > 0) {
			return value;
		}
		return 15;
	}

	function getScheduledWarmLimitSummary() {
		const menuCount = Number(crawlScopeSummary.menuUrlCount || 0);
		const baseCount = Number(crawlScopeSummary.baseUrlCount || 0);
		const contentCount = Number(crawlScopeSummary.contentUrlCount || 0);
		const totalCount = Number(crawlScopeSummary.estimatedTotal || 0);
		const defaultLimit = getDefaultScheduledWarmLimit();

		if (menuCount > 0 || contentCount > 0 || baseCount > 0 || totalCount > 0) {
			const discoveredTotal = Number(crawlScopeSummary.discoveredTotal || totalCount || 0);
			const cappedTotal = Number(crawlScopeSummary.estimatedTotal || 0);
			const maxUrls = Number(crawlScopeSummary.maxUrls || cappedTotal || 0);
			return 'Detected crawl scope: menu URLs ' + formatNumber(menuCount) + ' + content URLs ' + formatNumber(contentCount) + ' + base URLs ' + formatNumber(baseCount) + ' = ' + formatNumber(discoveredTotal) + ' discovered. Scheduled runs are capped at ' + formatNumber(cappedTotal || maxUrls) + '. Default scheduled limit is ' + formatNumber(defaultLimit) + '.';
		}

		return 'Set the maximum total URLs to process in one scheduled warm queue. Use 0 for unlimited.';
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
		const value = formatNumber(stats && typeof stats.objectCacheEntries !== 'undefined' ? stats.objectCacheEntries : 0);
		return stats && stats.objectCacheStatsPartial ? value + '+' : value;
	}

	function getObjectEntriesHint(stats) {
		const parts = [];
		const activeBackend = stats && (stats.objectCacheActiveBackend || stats.objectCacheBackend || stats.objectCacheStatsSource);
		const selectedBackend = stats && stats.objectCacheSelectedBackend;
		if (activeBackend) {
			parts.push('Active: ' + String(activeBackend).toUpperCase());
		}
		if (selectedBackend && activeBackend && String(selectedBackend) !== String(activeBackend)) {
			parts.push('Selected: ' + String(selectedBackend).toUpperCase());
		}
		if (stats && stats.objectCacheFallbackActive) {
			parts.push('APCu fallback active');
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
					h('div', { className: 'text-xs tracking-widest text-zinc-500 mb-1', key: 'eyebrow' }, 'Cache Statistics'),
					h('h3', { className: 'text-lg font-black tracking-tight text-white m-0', key: 'title' }, 'Count Cache stats'),
					h('p', { className: 'text-xs text-zinc-500 mt-2 mb-0 max-w-3xl', key: 'desc' }, 'Track cache hits, misses, bypasses and object-cache counters. Disabling this avoids counter writes during frontend requests.'),
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
				? h('div', { className: 'uc-warning-box mt-4', key: 'warning' }, 'Enabling cache statistics may add a small performance overhead because UltraCache needs to update counters during requests. If you use Varnish, nginx full-page cache, Cloudflare, or another reverse proxy, stats may under-report requests served before WordPress.')
				: h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'disabled-copy' }, 'Stats are disabled. The dashboard will not refresh or write live request counters until this switch is enabled.'),
			enabled
				? h('details', { className: 'uc-accordion uc-accordion--card mt-4', open: true, key: 'details' }, [
					h('summary', { key: 'summary' }, 'Show detailed stats'),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: 'Cached Pages',
							value: formatNumber(typeof stats.pagesCached !== 'undefined' ? stats.pagesCached : stats.pageCacheFiles),
							hint: 'Stored HTML cache files',
							key: 'pages',
						}),
						h(StatCard, {
							label: 'Optimized Images',
							value: formatNumber(typeof stats.imagesOptimized !== 'undefined' ? stats.imagesOptimized : stats.optimizedImages),
							hint: formatNumber(typeof stats.avifImagesOptimized !== 'undefined' ? stats.avifImagesOptimized : stats.avifFiles) + ' AVIF · ' + formatNumber(typeof stats.webpImagesOptimized !== 'undefined' ? stats.webpImagesOptimized : stats.webpFiles) + ' WebP',
							key: 'images',
						}),
						h(StatCard, {
							label: 'Object Entries',
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: 'Run full object-cache count',
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: 'Cache Size', value: stats.cacheSizeHuman || '0 B', hint: 'Total cache footprint', key: 'size' }),
						h(StatCard, { key: 'hits', label: 'Cache Hits', value: formatNumber(stats.pageCacheHits), hint: diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache' }),
						h(StatCard, { key: 'misses', label: 'Render Misses', value: formatNumber(stats.pageCacheMisses), hint: 'Reached WordPress render path' }),
						h(StatCard, { key: 'bypasses', label: 'Bypasses', value: formatNumber(stats.pageCacheBypasses), hint: 'Skipped before buffering' }),
						h(StatCard, { key: 'ratio', label: 'Hit Ratio', value: formatPercent(stats.pageCacheHitRatio), hint: 'Hits ÷ (hits + misses)' }),
					]),
				])
				: h('details', { className: 'uc-accordion uc-accordion--card mt-4', key: 'manual-details' }, [
					h('summary', { key: 'summary' }, 'Manual object-cache diagnostics'),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: 'Object Entries',
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: 'Run full object-cache count',
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: 'Cache Size', value: stats.cacheSizeHuman || '0 B', hint: 'Static cache footprint', key: 'size' }),
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

	function sleep(ms) {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	async function apiRequest(subAction, params = {}) {
		const routes = {
			stats: { path: 'stats', method: 'GET' },
			purge_all: { path: 'purge-all', method: 'POST' },
			get_crawl_urls: { path: 'crawl-urls', method: 'GET' },
			inspect_url: { path: 'inspect-url', method: 'POST' },
			crawl_page: { path: 'crawl-page', method: 'POST' },
			build_frontpage_css: { path: 'build-frontpage-css', method: 'POST' },
			warm_frontpage_html: { path: 'warm-frontpage-html', method: 'POST' },
			warm_frontpage_html_css: { path: 'warm-frontpage-html-css', method: 'POST' },
			get_media_ids: { path: 'media-ids', method: 'GET' },
			optimize_id: { path: 'optimize-id', method: 'POST' },
			optimize_media: { path: 'optimize-media', method: 'POST' },
			varnish_test: { path: 'varnish/test', method: 'POST' },
			varnish_flush_all: { path: 'varnish/flush-all', method: 'POST' },
			opcache_flush: { path: 'opcache/flush', method: 'POST' },
			apcu_flush: { path: 'apcu/flush', method: 'POST' },
			redis_test: { path: 'object-cache/redis-test', method: 'POST' },
			object_cache_flush: { path: 'object-cache/flush', method: 'POST' },
			cron_warm_start: { path: 'cron-warm/start', method: 'POST' },
			cron_warm_stop: { path: 'cron-warm/stop', method: 'POST' },
			cron_warm_tick: { path: 'cron-warm/tick', method: 'POST' },
			settings: { path: 'settings', method: 'POST' },
			save_settings: { path: 'settings', method: 'POST' },
			queue_action: { path: 'action-queue', method: 'POST' },
			queue_status: { path: 'action-queue/{id}', method: 'GET' },
			delete_all_data: { path: 'delete-all-data', method: 'POST' },
			populate_query_allowlist: { path: 'query-string-allowlist/populate', method: 'POST' },
		};

		const route = routes[subAction];
		if (!route || !ucwp.restBase) {
			throw new Error('REST route not available for action: ' + subAction);
		}

		let payload = params;
		let requestUrl = ucwp.restBase + route.path;
		if (subAction === 'queue_status' && params && params.id) {
			requestUrl = ucwp.restBase + route.path.replace('{id}', encodeURIComponent(String(params.id)));
		}

		if (route.method === 'GET' && params && typeof params === 'object') {
			const query = new URLSearchParams();
			Object.keys(params).forEach((key) => {
				if (subAction === 'queue_status' && key === 'id') {
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

		const response = await fetch(requestUrl, {
			method: route.method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': ucwp.restNonce || '',
				...(route.method !== 'GET' ? { 'Content-Type': 'application/json' } : {}),
			},
			...(route.method !== 'GET' ? { body: JSON.stringify(payload) } : {}),
		});

		let data = null;
		try {
			data = await response.json();
		} catch (error) {}

		if (!response.ok) {
			const message =
				(data && data.message) ||
				(data && data.data && data.data.message) ||
				('HTTP ' + response.status);
			throw new Error(message);
		}

		if (data && data.success === false) {
			const message =
				(data.data && data.data.message) ||
				data.message ||
				'Request failed.';
			throw new Error(message);
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
		const total = Math.max(items.length, Number((data && data.total) || 0));
		const processed = typeof (data && data.processed) !== 'undefined'
			? Math.max(0, Number(data.processed || 0))
			: Math.max(0, Number(data && data.nextOffset ? data.nextOffset : items.length));
		const nextCursor = typeof (data && data.nextCursor) === 'string' ? data.nextCursor : '';

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
				return {
					line: (response && response.converted ? 'Processed attachment #' : 'Scanned attachment #') + item + ' · ' + workCompleted + ' unit' + (workCompleted === 1 ? '' : 's') + ' · AVIF ' + avifCount + ' · WebP ' + webpCount,
					progressIncrement: workCompleted,
					attachmentIncrement: 1,
					successIncrement: 1,
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
				return h('div', { className: classNames('uc-toast', 'uc-toast--' + tone), key: toast.id || toast.text }, [
					h('div', { className: 'uc-toast__body', key: 'body' }, [
						toast.title ? h('div', { className: 'uc-toast__title', key: 'title' }, toast.title) : null,
						h('div', { className: 'uc-toast__text', key: 'text' }, toast.text || ''),
					]),
					h('button', {
						type: 'button',
						className: 'uc-toast__close',
						onClick: () => onDismiss(toast.id),
						'aria-label': 'Dismiss notification',
						key: 'close',
					}, '×'),
				]);
			})
		);
	}


	function SupportLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'coffee', label: 'Buy me a coffee', amount: '€5', href: SUPPORT_LINKS.coffee, kind: 'paypal' },
			{ key: 'beer', label: 'Buy me a beer', amount: '€10', href: SUPPORT_LINKS.beer, kind: 'paypal' },
			{ key: 'meal', label: 'Buy me a meal', amount: '€15', href: SUPPORT_LINKS.meal, kind: 'paypal' },
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
				h('div', Object.assign({ className: 'uc-support-inline__title' }, triggerProps), 'Support this plugin'),
				!isMobile ? h('p', { className: 'uc-support-inline__text' }, 'If UltraCache saves you time, you can support future development or reach out for help.') : null,
			]),
			isMobile
				? null
				: h('div', { className: 'uc-support-inline__actions', key: 'actions' }, [
					h('div', { className: 'uc-support-inline__support-group', key: 'support-group' }, [
						h('div', { className: 'uc-support-inline__group-label' }, 'Support this plugin'),
						h(SupportLinks, { key: 'paypal-links' }),
						h('div', { className: 'uc-support-inline__need-support', key: 'need-support' }, [
							h('div', { className: 'uc-support-inline__group-label', key: 'need-label' }, 'Need Support?'),
							h('a', {
							href: SUPPORT_LINKS.hire,
							className: 'uc-support-inline__hire-link',
							onClick: typeof onHireClick === 'function' ? onHireClick : undefined,
							key: 'hire-link',
						}, 'Hire me'),
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
					'aria-label': 'Close support modal',
					key: 'close',
				}, '×'),
				h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, 'Support this plugin'),
				h('h3', { className: 'uc-support-modal__title', id: titleId, key: 'title' }, 'Support this plugin'),
				h('p', { className: 'uc-support-modal__text', id: descriptionId, key: 'text' }, 'If UltraCache saves you time, you can support future updates or contact Byron directly for paid help.'),
				h('div', { className: 'uc-support-modal__section-label', key: 'support-label' }, 'Support this plugin'),
				h(SupportLinks, { compact: isMobile, onHireClick, key: 'links' }),
				h('div', { className: 'uc-support-modal__need-support', key: 'need-support' }, [
					h('div', { className: 'uc-support-modal__section-label', key: 'need-label' }, 'Need Support?'),
					h('a', {
					href: SUPPORT_LINKS.hire,
					className: 'uc-support-modal__hire-link',
					onClick: typeof onHireClick === 'function' ? onHireClick : undefined,
					key: 'hire-link',
				}, 'Hire me'),
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
			h('div', { className: 'text-3xl font-black tracking-tight text-white pr-8', key: 'value' }, value),
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

	function SaveableTextAreaField({ label, description, value, onSave, disabled, placeholder, saveLabel, populateLabel, onPopulate, populateWarning }) {
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
				}, populateBusy ? 'Populating…' : (populateLabel || 'Populate')) : h('span', { 'aria-hidden': 'true' }, ''),
				h(Button, {
					onClick: () => onSave(draftValue),
					disabled: !!disabled || !hasChanges,
					variant: 'primary',
				}, saveLabel || 'Save'),
			]),
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
			title: 'Cache Analytics',
			description: 'Request counters for cache delivery and cache-generation activity. Warm/preload creates misses and writes, not hits.',
		}, [
			h('div', { className: 'grid grid-cols-2 xl:grid-cols-4 gap-3', key: 'metrics' }, [
				h(StatCard, { key: 'hits', label: 'Cache Hits', value: formatNumber(stats.pageCacheHits), hint: diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache' }),
				h(StatCard, { key: 'misses', label: 'Render Misses', value: formatNumber(stats.pageCacheMisses), hint: 'Reached WordPress render path' }),
				h(StatCard, { key: 'bypasses', label: 'Bypasses', value: formatNumber(stats.pageCacheBypasses), hint: 'Skipped before buffering' }),
				h(StatCard, { key: 'ratio', label: 'Hit Ratio', value: formatPercent(stats.pageCacheHitRatio), hint: diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'PHP-level ratio only; reverse proxy hits excluded' : 'Hits ÷ (hits + misses)' }),
			]),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-6 mt-5', key: 'detail-grid' }, [
				h('div', { key: 'buckets' }, [
					h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, 'Served cache buckets'),
					h(DetailRow, { label: 'Original HTML', value: formatNumber(bucketHits.orig || 0) }),
					h(DetailRow, { label: 'WebP HTML', value: formatNumber(bucketHits.webp || 0) }),
					h(DetailRow, { label: 'AVIF HTML', value: formatNumber(bucketHits.avif || 0) }),
					h(DetailRow, { label: 'Identity encoding', value: formatNumber(encodingHits.identity || 0) }),
					h(DetailRow, { label: 'Gzip encoding', value: formatNumber(encodingHits.gzip || 0) }),
					h(DetailRow, { label: 'Brotli encoding', value: formatNumber(encodingHits.brotli || 0) }),
					h(DetailRow, { label: 'Cache writes', value: formatNumber(stats.pageCacheStores || 0) }),
					h(DetailRow, { label: 'Write skips', value: formatNumber(stats.pageCacheStoreSkips || 0) }),
					h(DetailRow, { label: 'Stale hits', value: formatNumber(stats.pageCacheStaleHits || 0) }),
					h(DetailRow, { label: 'Background refreshes', value: formatNumber(stats.pageCacheBackgroundRevalidations || 0) }),
				]),
				h('div', { key: 'reasons' }, [
					h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, 'Top bypass reasons'),
					reasonEntries.length
						? h('div', { className: 'space-y-2' }, reasonEntries.map(([reason, count]) =>
							h('div', { className: 'flex items-center justify-between gap-4 py-2', key: reason }, [
								h('div', { className: 'text-sm text-white break-all' }, reason),
								h('div', { className: 'text-xs font-mono text-zinc-400' }, formatNumber(count)),
							])
						))
						: h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No bypasses recorded yet.'),
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
		const cssSummaryRows = [
			['Bundles built', false, formatNumber(cssSummary.bundlesBuilt || 0)],
			['Styles bundled/scanned', false, formatNumber(cssSummary.stylesBundled || 0) + ' / ' + formatNumber(cssSummary.stylesScanned || 0)],
			['Skipped/unresolved', false, formatNumber(cssSummary.stylesSkipped || 0) + ' / ' + formatNumber(cssSummary.stylesUnresolved || 0)],
			['Last CSS bundle warm', false, formatLooseTime(cssSummary.lastWarm || null)],
		];
		const cssSummaryMessage = cssSummary.lastWarm && cssSummary.lastWarm.message ? cssSummary.lastWarm.message : '';

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
						h('div', { className: 'uc-accordion__title' }, 'Activity Summary'),
						h('div', { className: 'uc-accordion__description' }, 'Recent operational events and warm/object-cache counters.'),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'activity-counters' }, [
				h('div', { className: 'uc-section-title' }, 'Activity counters'),
				renderSummaryRows(counterRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-status' }, [
				h('div', { className: 'uc-section-title' }, 'Recent status'),
				renderSummaryRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-css-bundle-summary' }, [
				h('div', { className: 'uc-section-title' }, 'CSS Bundle Summary'),
				renderSummaryRows(cssSummaryRows),
				cssSummaryMessage ? h('div', { className: 'text-xs text-zinc-500 pt-1' }, cssSummaryMessage) : null,
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-timeline' }, [
				h('div', { className: 'uc-section-title' }, 'Recent activity'),
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
		return h('span', {
			className: classNames(
				'inline-flex items-center justify-end text-right px-2.5 py-1 rounded-full text-[11px] font-bold tracking-wider',
				'success' === variant
					? 'bg-emerald-500/15 text-emerald-400 '
					: ('warning' === variant
						? 'bg-cyan-500/5 text-cyan-400 '
						: 'bg-zinc-700/40 text-zinc-300 ')
			),
		}, text);
	}


		function DiagnosticsCard({ diagnostics, stats, open, onToggle }) {
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
		const objectCacheStatus = diagnostics.objectCache || {};
		const pageCacheStatus = diagnostics.pageCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || 'apcu';
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend === 'redis' && activeObjectBackend === 'apcu');
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable';
		const fallbackObjectBackendText = fallbackObjectBackend ? String(fallbackObjectBackend).toUpperCase() : 'Unavailable';

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
			['Active object cache backend', !!objectCacheStatus.active && !objectFallbackActive, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled')],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby')],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : ('Disabled' + (analyticsBackend.message ? ' · ' + analyticsBackend.message : ''))],
			['Cron Warm Up', diagnostics.cronWarm && diagnostics.cronWarm.active, diagnostics.cronWarm && diagnostics.cronWarm.active ? ('Running · ' + formatNumber(diagnostics.cronWarm.processed || 0) + '/' + formatNumber(diagnostics.cronWarm.total || 0)) : diagnostics.cronWarm && diagnostics.cronWarm.enabled ? ((diagnostics.cronWarm.completed ? 'Completed' : 'Enabled') + ' · ' + formatNumber(diagnostics.cronWarm.pagesPerMinute || 0) + '/min') : 'Disabled'],
			['Varnish', diagnostics.varnish && diagnostics.varnish.enabled, diagnostics.varnish && diagnostics.varnish.enabled ? ('Varnish mode: ' + (diagnostics.varnish.mode || 'http') + ' · ' + (((diagnostics.varnish.mode || 'http') === 'admin') ? 'BAN' : (diagnostics.varnish.method || 'BAN')) + ' · ' + ((diagnostics.varnish.servers || '').trim() || 'No endpoints')) : 'Disabled'],
			['Reverse Proxy', !!reverseProxy.detected, reverseProxyText],
			['Brotli Compression', compressionStatus.brotli && compressionStatus.brotli.available, compressionStatus.brotli && compressionStatus.brotli.available ? (compressionStatus.preferred === 'brotli' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['Gzip Compression', compressionStatus.gzip && compressionStatus.gzip.available, compressionStatus.gzip && compressionStatus.gzip.available ? (compressionStatus.preferred === 'gzip' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['AVIF Capability', diagnostics.formats && diagnostics.formats.avif, diagnostics.formats && diagnostics.formats.avif ? 'Available' : 'Unavailable'],
			['WebP Capability', diagnostics.formats && diagnostics.formats.webp, diagnostics.formats && diagnostics.formats.webp ? 'Available' : 'Unavailable'],
			['Query-string args caching', !!loadedRuntime.cache_query_strings, queryStringCachingText],
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
			['Analytics file', !!analyticsDiag.exists && !!analyticsDiag.validJson, analyticsDiag.exists ? (analyticsDiag.validJson ? 'Present · Valid JSON' : 'Present · Invalid JSON') : 'Missing'],
			['Browser cache rules', !!browserCacheRulesDiag.exists && !!browserCacheRulesDiag.managed, browserCacheRulesDiag.exists ? (browserCacheRulesDiag.managed ? 'Present · Managed block found' : 'Present · No UltraCache block') : 'Missing'],
			['Object cache directory', !!objectCacheDirDiag.exists, objectCacheDirDiag.exists ? (objectCacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['AVIF cache directory', !!avifDirDiag.exists, avifDirDiag.exists ? (avifDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['WebP cache directory', !!webpDirDiag.exists, webpDirDiag.exists ? (webpDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
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
				h(StatusPill, { ok: !!row[1], text: row[2], tone: tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'diagnostics', open: !!open }, [
				h('summary', { className: 'uc-accordion__summary', onClick: function(event) { event.preventDefault(); if (onToggle) { onToggle(); } } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, 'Diagnostics'),
						h('div', { className: 'uc-accordion__description' }, 'Live cache status'),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'status-group' }, [
				h('div', { className: 'uc-section-title' }, 'Runtime status'),
				renderRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'runtime-group' }, [
				h('div', { className: 'uc-section-title' }, 'Runtime files, drop-ins & versions'),
				renderRows(runtimeRows),
			]),
			runtimeConfigRows.length ? h('div', { className: 'uc-diagnostic-group', key: 'runtime-config-group' }, [
				h('div', { className: 'uc-section-title' }, 'Runtime config in use'),
				renderRows(runtimeConfigRows),
			]) : null,
				]),
			]),
		]);
	}

	function AdvancedDiagnosticsCard({ diagnostics, stats }) {
		const last = diagnostics.lastEvent || {};
		const objectCacheStatus = diagnostics.objectCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || 'apcu';
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend === 'redis' && activeObjectBackend === 'apcu');
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable';
		const fallbackObjectBackendText = fallbackObjectBackend ? String(fallbackObjectBackend).toUpperCase() : 'Unavailable';
		const compressionStatus = diagnostics.compression || {};
		const wpCacheStatus = diagnostics.wpCache || {};
		const pathDiagnostics = diagnostics.paths || {};
		const lastCacheWrite = diagnostics.lastCacheWrite || {};
		const lastStatus = last.status || last.type || '—';
		const lastSeen = formatEventTime(last);
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
		const cacheDetailRows = [
			['Original HTML', false, formatNumber(bucketHits.orig || 0)],
			['WebP HTML', false, formatNumber(bucketHits.webp || 0)],
			['AVIF HTML', false, formatNumber(bucketHits.avif || 0)],
			['Identity encoding', false, formatNumber(encodingHits.identity || 0)],
			['Gzip encoding', false, formatNumber(encodingHits.gzip || 0)],
			['Brotli encoding', false, formatNumber(encodingHits.brotli || 0)],
			['Cache writes', false, formatNumber(stats.pageCacheStores || 0)],
			['Write skips', false, formatNumber(stats.pageCacheStoreSkips || 0)],
			['Stale hits', false, formatNumber(stats.pageCacheStaleHits || 0)],
			['Background refreshes', false, formatNumber(stats.pageCacheBackgroundRevalidations || 0)],
			['Selected object cache backend', false, selectedObjectBackendText],
			['Active object cache backend', !!objectCacheStatus.active && !objectFallbackActive, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled')],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby')],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : 'Disabled'],
			['CLS images scanned', false, formatNumber(stats.clsImagesScanned || 0)],
			['CLS dimensions injected', false, formatNumber(stats.clsDimensionsInjected || 0)],
			['CLS skipped', false, formatNumber(stats.clsImagesSkipped || 0)],
			['CLS unresolved', false, formatNumber(stats.clsImagesUnresolved || 0)],
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

		const mediaRuntimeRows = [
			['Preferred image editor', false, mediaRuntimeDiag.preferredEditor || 'Unavailable'],
			['Last image editor class', false, mediaRuntimeDiag.lastImageEditorClass || 'Unavailable'],
			['Imagick AVIF', !!mediaRuntimeDiag.imagickAvif, mediaRuntimeDiag.imagickAvif ? 'Yes' : 'No'],
			['Imagick WebP', !!mediaRuntimeDiag.imagickWebp, mediaRuntimeDiag.imagickWebp ? 'Yes' : 'No'],
			['GD AVIF', !!mediaRuntimeDiag.gdAvif, mediaRuntimeDiag.gdAvif ? 'Yes' : 'No'],
			['GD WebP', !!mediaRuntimeDiag.gdWebp, mediaRuntimeDiag.gdWebp ? 'Yes' : 'No'],
			['Last AVIF encode engine', false, mediaRuntimeDiag.lastAvifEncodeEngine || 'Unavailable'],
			['Last AVIF encode error', false, mediaRuntimeDiag.lastAvifEncodeError || 'None'],
			['Last AVIF encode file', false, mediaRuntimeDiag.lastAvifEncodeFile || 'Unavailable'],
			['Last AVIF encode at', false, mediaRuntimeDiag.lastAvifEncodeAt ? formatLooseTime(mediaRuntimeDiag.lastAvifEncodeAt) : 'Unavailable'],
		];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		function renderPathDetails(label, diag, extraRows) {
			if (!diag || !diag.path) {
				return null;
			}
			return h('div', { className: 'rounded bg-black/10 p-4', key: 'path-' + label }, [
				h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, label),
				h('div', { className: 'space-y-3' }, [
					h(DetailRow, { label: 'Path', value: diag.path, mono: true }),
					h(DetailRow, { label: 'Exists', value: diag.exists ? 'Yes' : 'No' }),
					h(DetailRow, { label: 'Readable', value: diag.readable ? 'Yes' : 'No' }),
					h(DetailRow, { label: 'Writable', value: diag.writable ? 'Yes' : 'No' }),
					!diag.exists ? h(DetailRow, { label: 'Parent writable', value: diag.parentWritable ? 'Yes' : 'No' }) : null,
					diag.managed ? h(DetailRow, { label: 'Managed by UltraCache', value: 'Yes' }) : null,
					diag.dropInBuild ? h(DetailRow, { label: 'Generated build', value: diag.dropInBuild }) : null,
					diag.storageFormat ? h(DetailRow, { label: 'Storage format', value: diag.storageFormat }) : null,
					diag.size ? h(DetailRow, { label: 'Size', value: formatBytes(diag.size) }) : null,
					diag.modified ? h(DetailRow, { label: 'Modified', value: formatLooseTime(diag.modified) }) : null,
					diag.valid ? h(DetailRow, { label: 'Valid', value: 'Yes' }) : null,
					diag.validJson ? h(DetailRow, { label: 'Valid JSON', value: 'Yes' }) : null,
					diag.readError ? h(DetailRow, { label: 'Read error', value: diag.readError }) : null,
					extraRows || null,
				]),
			]);
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'advanced-diagnostics' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, 'Advanced Diagnostics'),
						h('div', { className: 'uc-accordion__description' }, 'Expanded server, PHP, media, proxy, cache-write, and bypass inspection.'),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'uc-diagnostic-group', key: 'last-cache-write' }, [
						h('div', { className: 'uc-section-title' }, 'Last page cache write'),
						(lastCacheWrite && (lastCacheWrite.path || lastCacheWrite.pageFiles)) ? h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: 'Page cache files', value: formatNumber(lastCacheWrite.pageFiles || 0) }),
							h(DetailRow, { label: 'Last modified', value: formatLooseTime(lastCacheWrite.modified || 0) }),
							h(DetailRow, { label: 'Last file size', value: formatBytes(lastCacheWrite.size || 0) }),
							h(DetailRow, { label: 'Last file path', value: lastCacheWrite.path || '', mono: true }),
							lastCacheWrite.error ? h(DetailRow, { label: 'Scan error', value: lastCacheWrite.error }) : null,
						]) : h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No page cache files have been detected yet.'),
					]),
					(runtimeConfigDiag.path || analyticsDiag.path || advancedCacheDiag.path || objectCacheDiag.path || cacheDirDiag.path || objectCacheDirDiag.path)
						? h('div', { className: 'uc-diagnostic-group', key: 'path-grid' }, [
							h('div', { className: 'uc-section-title' }, 'Path details'),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
								renderPathDetails('advanced-cache.php', advancedCacheDiag),
								renderPathDetails('object-cache.php', objectCacheDiag),
								renderPathDetails('runtime-config.json', runtimeConfigDiag, runtimeConfigDiag.keys && runtimeConfigDiag.keys.length ? h(DetailRow, { label: 'Keys', value: runtimeConfigDiag.keys.join(', ') }) : null),
								renderPathDetails('analytics.json', analyticsDiag, analyticsDiag.keys && analyticsDiag.keys.length ? h(DetailRow, { label: 'Top keys', value: analyticsDiag.keys.join(', ') }) : null),
								renderPathDetails('Cache directory', cacheDirDiag),
								renderPathDetails('Object cache directory', objectCacheDirDiag),
							]),
						])
						: null,
					reverseProxy.detected ? h('div', { className: 'uc-diagnostic-group', key: 'proxy-box' }, [
						h('div', { className: 'uc-section-title' }, 'Reverse Proxy Details'),
						h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: 'Provider', value: reverseProxy.provider || 'Detected' }),
							h(DetailRow, { label: 'Server', value: reverseProxy.server || '' }),
							h(DetailRow, { label: 'Via', value: reverseProxy.via || '' }),
							h(DetailRow, { label: 'X-Cache', value: reverseProxy.x_cache || '' }),
							h(DetailRow, { label: 'X-Cache-Status', value: reverseProxy.x_cache_status || '' }),
							h(DetailRow, { label: 'X-Proxy-Cache', value: reverseProxy.x_proxy_cache || '' }),
							h(DetailRow, { label: 'X-FastCGI-Cache', value: reverseProxy.x_fastcgi_cache || '' }),
							h(DetailRow, { label: 'X-LiteSpeed-Cache', value: reverseProxy.x_litespeed_cache || '' }),
							h(DetailRow, { label: 'X-QC-Cache', value: reverseProxy.x_qc_cache || '' }),
							h(DetailRow, { label: 'CF-Cache-Status', value: reverseProxy.cf_cache_status || '' }),
							h(DetailRow, { label: 'Age', value: reverseProxy.age || '' }),
						]),
					]) : null,
					h('div', { className: 'uc-diagnostic-group', key: 'environment-group' }, [
						h('div', { className: 'uc-section-title' }, 'Server & PHP environment'),
						renderRows(environmentRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'media-runtime-group' }, [
						h('div', { className: 'uc-section-title' }, 'Media runtime diagnostics'),
						renderRows(mediaRuntimeRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'cache-group' }, [
						h('div', { className: 'uc-section-title' }, 'Cache diagnostics'),
						renderRows(cacheDetailRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'reasons-group' }, [
						h('div', { className: 'uc-section-title' }, 'Top bypass reasons'),
						reasonEntries.length
							? renderRows(reasonEntries.map(([reason, count]) => [reason, false, formatNumber(count)]), 'neutral')
							: h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No bypasses recorded yet.'),
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

	function ProgressPanel({ process, etaText, onCancel }) {
		if (!process.active) {
			return null;
		}

		const percent =
			process.total > 0 ? Math.min(100, Math.round((process.current / process.total) * 100)) : 0;
		const attachmentText = process.attachmentsTotal > 0
			? ('Attachments ' + process.attachmentsProcessed + ' / ' + process.attachmentsTotal)
			: '';
		const successCount = Math.max(0, Number(process.successCount || 0));
		const skippedCount = Math.max(0, Number(process.skippedCount || 0));
		const failedCount = Math.max(0, Number(process.failedCount || 0));
		const hasWarmCounters = successCount > 0 || skippedCount > 0 || failedCount > 0;

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
				h('span', { className: 'text-zinc-400' }, 'Overall Progress'),
				h(
					'span',
					{ className: 'text-emerald-400 font-mono' },
					process.total > 0
						? process.current + ' / ' + process.total + ' (' + percent + '%)'
						: 'Preparing…'
				),
			]),
			h('div', { className: 'w-full bg-zinc-800 h-1 rounded-full overflow-hidden', key: 'bar-wrap' }, [
				h('div', {
					className: 'bg-emerald-500 h-full transition-all duration-300',
					style: { width: percent + '%' },
				}),
			]),
			hasWarmCounters
				? h('div', { className: 'flex flex-wrap gap-2 mt-3 text-[11px]', key: 'warm-counters' }, [
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


	function VarnishCard({ form, diagnostics, busy, onFieldChange, onSave, onTest, onFlushAll }) {
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
		const formHasUnsafeEndpoint = !isAdminMode && /:(80|443|8443)(\s|$)/.test(formServers);
		const actionsBlocked = hasUnsafeEndpoints || formHasUnsafeEndpoint;

		return h(Card, {
			title: 'Varnish',
			description: isAdminMode ? 'Send admin BAN commands to one or more Varnish admin endpoints using the shared secret.' : 'Send BAN or PURGE HTTP requests to a local Varnish listener. Use 127.0.0.1:82; do not use the public WordPress domain on :80 or :443.',
		}, [
			h(ToggleRow, {
				label: isAdminMode ? 'Enable Varnish admin purge' : 'Enable Varnish HTTP purge',
				description: varnish.available ? (isAdminMode ? 'Saves immediately. Propagate UltraCache purge-all and URL purge events through the Varnish admin interface.' : 'Saves immediately. Propagate UltraCache purge-all and URL purge events to your Varnish frontend endpoints.') : (supportMessage || 'Unavailable on this server.'),
				checked: !!form.varnishCliEnabled,
				onChange: (value) => onFieldChange('varnishCliEnabled', value),
				disabled: busy || !varnish.available,
			}),
			isAdminMode ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, 'Security warning: Varnish admin mode uses a plain TCP socket. Use localhost/private endpoints only, such as 127.0.0.1:6082, and never expose the Varnish admin port publicly.') : null,
			
			!isAdminMode && endpointWarningMessages.length ? h('div', { className: 'space-y-2 mt-4' }, endpointWarningMessages.map((message, index) => h('div', { className: 'text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2', key: 'varnish-endpoint-warning-' + index }, message))) : null,
			formHasUnsafeEndpoint ? h('div', { className: 'mt-4 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2' }, 'This unsaved HTTP endpoint is unsafe or unsupported. HTTP mode only allows local Varnish listener ports 82 or 6081. Do not use the public WordPress frontend on :80 or :443.') : null,
			legacyConflicts.detected ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, (legacyConflicts.message || 'Old W3 Total Cache / Varnish helper leftovers detected.') + ((legacyConflicts.options || []).length ? ' Options: ' + legacyConflicts.options.join(', ') + '.' : '') + ((legacyConflicts.plugins || []).length ? ' Plugins: ' + legacyConflicts.plugins.join(', ') + '.' : '')) : null,
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(SelectField, {
					label: 'Mode',
					description: 'Use HTTP for frontend BAN/PURGE endpoints, or Admin when you want to connect to the Varnish admin port with the shared secret.',
					value: form.varnishCliMode || 'http',
					onChange: (value) => onFieldChange('varnishCliMode', value),
					disabled: busy,
					options: [
						{ value: 'http', label: 'HTTP frontend' },
						{ value: 'admin', label: 'Admin (secret)' },
					],
					key: 'mode',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin endpoints' : 'HTTP endpoints',
					description: isAdminMode ? 'Space-separated Varnish admin endpoints in host:port format. Example: 127.0.0.1:6082' : 'Space-separated local Varnish HTTP listener endpoints in host:port format. Safe example: 127.0.0.1:82. Public frontend endpoints such as domain.com:443 are blocked.',
					value: form.varnishCliServers || '',
					onChange: (value) => onFieldChange('varnishCliServers', value),
					disabled: busy,
					placeholder: isAdminMode ? '127.0.0.1:6082' : '127.0.0.1:82',
					key: 'servers',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin secret' : 'Control key',
					description: isAdminMode ? (form.varnishCliKeyConfigured ? 'A saved Varnish admin secret already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Shared secret used to authenticate against the Varnish admin interface.') : (form.varnishCliKeyConfigured ? 'A saved token header already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Optional token sent as the X-UltraCache-Token header with Varnish HTTP requests.'),
					value: form.varnishCliKey || '',
					onChange: (value) => onFieldChange('varnishCliKey', value),
					disabled: busy,
					placeholder: isAdminMode ? 'varnish-secret' : 'your-secret-key',
					key: 'key',
				}),
				h(SelectField, {
					label: 'Command type',
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
				h(NumberField, {
					label: 'Timeout (seconds)',
					description: isAdminMode ? 'Connection and read timeout for each Varnish admin endpoint.' : 'Connection and read timeout for each Varnish HTTP endpoint.',
					value: form.varnishCliTimeoutSeconds || 2,
					onChange: (value) => onFieldChange('varnishCliTimeoutSeconds', value),
					disabled: busy,
					min: 1,
					step: 1,
					key: 'timeout',
				}),
			]),
			h(ToggleRow, {
				label: 'Debug log',
				description: 'Saves immediately. Write Varnish request details to wp-content/cache/ultracache/logs/varnish-cli.log',
				checked: !!form.varnishCliDebug,
				onChange: (value) => onFieldChange('varnishCliDebug', value),
				disabled: busy,
				key: 'debug',
			}),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: onTest, disabled: busy || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : 'Run Varnish Test'),
				h(Button, { onClick: onFlushAll, disabled: busy || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : 'Flush Varnish All'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Support'),
						h(StatusPill, { ok: !!varnish.available, text: varnish.available ? 'Available' : 'Unavailable', tone: varnish.available ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Mode'),
						h(StatusPill, { ok: true, text: (form.varnishCliMode || 'http'), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Configured endpoints'),
						h(StatusPill, { ok: false, text: form.varnishCliServers ? form.varnishCliServers : '—', tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Last result'),
						h(StatusPill, { ok: !!last.success, text: last.message || 'No Varnish action yet', tone: last.message ? (!!last.success ? 'success' : 'warning') : 'neutral' }),
					]),
				]),
				supportMessage ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, supportMessage) : null,
				last.time ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, 'Last run: ' + formatLooseTime(last.time)) : null,
				detailLines ? h('div', { className: 'text-xs text-zinc-400 mt-3 whitespace-pre-wrap break-all' }, detailLines) : null,
			]),
		]);
	}



	function OPcacheCard({ stats, busy, onFlush }) {
		const opcache = stats && stats.opcache ? stats.opcache : {};
		const isAvailable = !!opcache.available;
		const isEnabled = !!opcache.enabled;
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
		];

		return h(Card, {
			title: 'OPcache',
			description: 'Production-safe visibility into PHP OPcache memory usage, hit rate, and restart state, with a manual flush control for post-deployment opcode invalidation when application code changes.',
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush OPcache'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
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
		const statusText = !isAvailable ? 'Unavailable' : (isEnabled ? 'Enabled' : 'Disabled');
		const memoryUsage = typeof apcu.memoryUsagePercent !== 'undefined' ? Number(apcu.memoryUsagePercent) : 0;
		const apcuWarnings = [];
		if (memoryUsage >= 85) {
			apcuWarnings.push('APCu memory usage is above 85%. Consider increasing apc.shm_size if object-cache hit rate drops or expunges increase.');
		}
		if (Number(apcu.expunges || 0) > 0) {
			apcuWarnings.push('APCu has expunges. This means entries were cleared to make room for new writes.');
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
			description: 'Local shared-memory cache used for lightweight hit analytics and as the safe local object-cache fallback when Redis is unavailable.',
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush APCu Cache'),
			]),
			h('div', { className: 'mt-4' }, [
				h(ToggleField, {
					label: 'Include APCu Flush on Scheduled Cache Cleanup',
					description: 'Warning: this clears the whole APCu user cache for this PHP runtime, including entries used by other plugins/apps in the same PHP-FPM context.',
					checked: !!(settings && settings.apcuFlushOnScheduledCleanup),
					onChange: onToggleScheduledCleanup,
					disabled: busy,
				}),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				(apcu.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, apcu.message) : null),
				apcuWarnings.length ? h('div', { className: 'space-y-2 mt-3' }, apcuWarnings.map((warning, index) => h('div', { className: 'text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'apcu-warning-' + index }, warning))) : null,
			]),
		]);
	}

	function RedisCard({ form, diagnostics, busy, objectCacheEnabled, onObjectCacheEnabledChange, onFieldChange, onSave, onTest, onFlush }) {
		const objectCache = diagnostics.objectCache || {};
		const redis = objectCache.redis || {};
		const legacyConflicts = diagnostics.legacyCacheConflicts || {};
		const backend = form.objectCacheBackend || 'redis';
		const selectedBackend = objectCache.selectedBackend || backend;
		const activeBackend = objectCache.activeBackend || selectedBackend;
		const fallbackActive = !!objectCache.fallbackActive || (selectedBackend === 'redis' && activeBackend !== 'redis');
		const fallbackBackend = objectCache.fallbackBackend || (fallbackActive ? activeBackend : 'apcu');
		const apcu = objectCache.apcu || {};
		const backendLabel = (value) => value === 'redis' ? 'Redis' : (value === 'apcu' ? 'APCu' : (value === 'disk' ? 'Disk' : (value === 'runtime' ? 'Runtime-only' : String(value || 'Unavailable'))));
		const redisDropinError = redis.dropinError || (objectCache.backendStatus && objectCache.backendStatus.redis && objectCache.backendStatus.redis.error) || '';
		const fallbackMessage = objectCache.fallbackMessage || (fallbackActive ? ('Redis selected, ' + backendLabel(fallbackBackend) + ' fallback active.' + (redisDropinError ? ' Redis: ' + redisDropinError : '')) : '');
		const redisSupportText = redis.available ? 'PHP Redis extension detected on this server.' : (redis.message || 'PHP Redis extension not detected. APCu will be used when available; otherwise object cache is runtime-only.');
		const dropinInstallable = typeof objectCache.dropinInstallable === 'undefined' ? !!objectCache.available : !!objectCache.dropinInstallable;
		const selectedBackendSupported = typeof objectCache.selectedBackendSupported === 'undefined'
			? (selectedBackend === 'redis' ? !!redis.available : (selectedBackend === 'apcu' ? !!apcu.available : true))
			: !!objectCache.selectedBackendSupported;
		const fallbackStatusText = selectedBackend === 'redis'
			? (fallbackActive ? (backendLabel(fallbackBackend) + ' active') : 'Standby')
			: 'Not needed';
		const transportText = [form.redisUseTls ? 'TLS' : 'TCP', form.redisPersistent ? 'Persistent connections ON' : 'Persistent connections OFF'].join(' · ');
		const statusText = objectCache.active
			? ('Active backend: ' + backendLabel(activeBackend) + (fallbackActive ? ' fallback' : ''))
			: (objectCache.enabled ? ('Configured backend: ' + backendLabel(selectedBackend)) : 'Object cache is disabled.');
		const connectionText = redis.connected
			? 'Connected'
			: (redisDropinError || redis.message || 'Not tested yet');
		const payloadProbe = redis.payloadProbe || {};
		const payloadProbeKnown = typeof payloadProbe.success !== 'undefined';
		const payloadProbeText = payloadProbeKnown ? (payloadProbe.success ? 'String / array / object OK' : (payloadProbe.message || 'Payload probe failed')) : 'Not tested yet';
		return h(Card, {
			title: 'Object Cache',
			description: 'Enable the WordPress object-cache.php drop-in. The selected backend and the active runtime backend are shown separately so Redis/APCu/runtime fallbacks are visible.',
		}, [
			h(ToggleField, {
				label: 'Enable Object Cache',
				description: 'Enable the WordPress object-cache.php drop-in. Use Redis when possible; APCu is the safe local fallback.',
				checked: !!objectCacheEnabled,
				onChange: onObjectCacheEnabledChange,
				disabled: busy,
				key: 'object-cache-enabled',
			}),
			legacyConflicts.detected ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, (legacyConflicts.message || 'Old W3 Total Cache / Varnish helper leftovers detected.') + ((legacyConflicts.options || []).length ? ' Options: ' + legacyConflicts.options.join(', ') + '.' : '') + ((legacyConflicts.plugins || []).length ? ' Plugins: ' + legacyConflicts.plugins.join(', ') + '.' : '')) : null,
			h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-4 mt-4' }, [
				h(ToggleField, {
					label: 'Use Redis',
					description: 'Recommended production backend. This switch saves immediately. If Redis cannot connect, UltraCache falls back to APCu when available, then runtime-only.',
					checked: backend === 'redis',
					onChange: (value) => value ? onFieldChange('objectCacheBackend', 'redis') : null,
					disabled: busy,
					key: 'backend-redis',
				}),
				h(ToggleField, {
					label: 'Use APCu',
					description: 'Local memory backend for single-server sites. This switch saves immediately. APCu is cleared on PHP-FPM restart.',
					checked: backend === 'apcu',
					onChange: (value) => value ? onFieldChange('objectCacheBackend', 'apcu') : null,
					disabled: busy,
					key: 'backend-apcu',
				}),
				h(ToggleField, {
					label: 'Use Disk',
					description: 'Advanced/debug only. This switch saves immediately. Not recommended for production because it can create many small files.',
					checked: backend === 'disk',
					onChange: (value) => value ? onFieldChange('objectCacheBackend', 'disk') : null,
					disabled: busy,
					key: 'backend-disk',
				}),
			]),
			backend === 'disk' ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, 'Disk object cache is advanced/debug only and is not recommended for production. It can create many small files and may be slower than leaving persistent object cache disabled.') : null,
				fallbackActive ? h('div', { className: 'mt-4 text-xs text-cyan-300 bg-cyan-500/10 border border-cyan-500/20 rounded-xl px-3 py-2' }, fallbackMessage) : null,
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(TextField, {
					label: 'Redis host',
					description: 'Common default: 127.0.0.1',
					value: form.redisHost || '127.0.0.1',
					onChange: (value) => onFieldChange('redisHost', value),
					disabled: busy,
					placeholder: '127.0.0.1',
					key: 'redis-host',
				}),
				h(NumberField, {
					label: 'Redis port',
					description: 'Common default: 6379',
					value: form.redisPort || 6379,
					onChange: (value) => onFieldChange('redisPort', value),
					disabled: busy,
					min: 1,
					step: 1,
					key: 'redis-port',
				}),
				h(TextField, {
					label: 'Redis password',
					description: form.redisPasswordConfigured ? 'A saved Redis password already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Leave empty when the server does not require auth.',
					value: form.redisPassword || '',
					onChange: (value) => onFieldChange('redisPassword', value),
					disabled: busy,
					placeholder: 'optional',
					type: 'password',
					key: 'redis-password',
				}),
				h(NumberField, {
					label: 'Redis database',
					description: 'Usually 0. Typical range: 0-15.',
					value: typeof form.redisDatabase === 'undefined' ? 0 : form.redisDatabase,
					onChange: (value) => onFieldChange('redisDatabase', value),
					disabled: busy,
					min: 0,
					step: 1,
					key: 'redis-db',
				}),
				h(TextField, {
					label: 'Redis prefix / namespace',
					description: 'Optional. Leave blank for automatic site-specific prefix.',
					value: form.redisPrefix || '',
					onChange: (value) => onFieldChange('redisPrefix', value),
					disabled: busy,
					placeholder: 'leave blank for auto',
					key: 'redis-prefix',
				}),
				h(NumberField, {
					label: 'Connect timeout (ms)',
					description: 'Advanced. Default: 200ms.',
					value: typeof form.redisConnectTimeoutMs === 'undefined' ? 200 : form.redisConnectTimeoutMs,
					onChange: (value) => onFieldChange('redisConnectTimeoutMs', value),
					disabled: busy,
					min: 50,
					step: 50,
					key: 'redis-connect-timeout',
				}),
				h(NumberField, {
					label: 'Read timeout (ms)',
					description: 'Advanced. Default: 200ms.',
					value: typeof form.redisReadTimeoutMs === 'undefined' ? 200 : form.redisReadTimeoutMs,
					onChange: (value) => onFieldChange('redisReadTimeoutMs', value),
					disabled: busy,
					min: 50,
					step: 50,
					key: 'redis-read-timeout',
				}),
				h(ToggleField, {
					label: 'Persistent connection',
					description: 'Advanced. Saves immediately. Reuse the Redis connection across PHP worker requests when supported.',
					checked: !!form.redisPersistent,
					onChange: (value) => onFieldChange('redisPersistent', value),
					disabled: busy,
					key: 'redis-persistent',
				}),
				h(ToggleField, {
					label: 'Use TLS',
					description: 'Saves immediately. Enable for managed Redis providers that require TLS/SSL transport.',
					checked: !!form.redisUseTls,
					onChange: (value) => onFieldChange('redisUseTls', value),
					disabled: busy,
					key: 'redis-use-tls',
				}),
			]),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Object Cache Settings'),
				h(Button, { onClick: onTest, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Test Redis Connection'),
				h(Button, { onClick: onFlush, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Flush Object Cache'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
				h('div', { className: 'space-y-3' }, [
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Drop-in installable'),
							h(StatusPill, { ok: dropinInstallable, text: dropinInstallable ? 'Yes' : 'No', tone: dropinInstallable ? 'success' : 'warning' }),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Selected backend'),
							h(StatusPill, {
								ok: selectedBackendSupported,
								text: backendLabel(selectedBackend),
								tone: selectedBackend === 'disk' ? 'warning' : (selectedBackendSupported ? 'success' : 'warning')
							}),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Active backend'),
							h(StatusPill, { ok: !!objectCache.active, text: objectCache.active ? backendLabel(activeBackend) : (objectCache.enabled ? 'Drop-in inactive' : 'Disabled'), tone: fallbackActive ? 'warning' : (objectCache.active ? 'success' : 'neutral') }),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Fallback status'),
							h(StatusPill, { ok: !fallbackActive, text: fallbackStatusText, tone: fallbackActive ? 'warning' : 'neutral' }),
						]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Redis support'),
						h(StatusPill, { ok: !!redis.available, text: redis.available ? 'Available' : 'Unavailable', tone: redis.available ? 'success' : 'warning' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'APCu support'),
						h(StatusPill, { ok: !!apcu.available, text: apcu.available ? 'Available' : 'Unavailable', tone: apcu.available ? 'success' : 'warning' }),
					]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Redis connection'),
							h(StatusPill, { ok: !!redis.connected, text: connectionText, tone: redis.connected ? 'success' : (selectedBackend === 'redis' ? 'warning' : 'neutral') }),
						]),
						h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Object payload probe'),
							h(StatusPill, { ok: !!payloadProbe.success, text: payloadProbeText, tone: payloadProbeKnown ? (payloadProbe.success ? 'success' : 'warning') : 'neutral' }),
						]),
						redisDropinError ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
							h('div', { className: 'text-sm text-white' }, 'Redis drop-in error'),
							h('div', { className: 'text-xs text-amber-300 text-right break-all max-w-xl' }, redisDropinError),
						]) : null,
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Runtime status'),
						h(StatusPill, { ok: !!objectCache.active, text: statusText, tone: objectCache.active ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, 'Effective prefix'),
						h('code', { className: 'text-xs text-zinc-300 break-all' }, redis.prefix || 'auto'),
					]),
				]),
				h('div', { className: 'text-xs text-zinc-500 mt-4' }, redisSupportText),
			]),
		]);
	}

	function App() {
		const [settings, setSettings] = useState(initialSettings);
		const [stats, setStats] = useState(initialStats);
		const [diagnostics, setDiagnostics] = useState(initialDiagnostics);
		const [browserCompressionProbe, setBrowserCompressionProbe] = useState({ ready: false, serverCompression: false, gzip: false, brotli: false, brokenGzip: false, brokenBrotli: false, message: '' });
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
			cronWarmPagesPerMinute: typeof initialSettings.cronWarmPagesPerMinute === 'undefined' ? 2 : initialSettings.cronWarmPagesPerMinute,
			scheduledWarmLimit: typeof initialSettings.scheduledWarmLimit === 'undefined' ? getDefaultScheduledWarmLimit() : initialSettings.scheduledWarmLimit,
			cacheFreshTtlMinutes: initialSettings.cacheFreshTtlMinutes || 15,
			cacheMaxStaleMinutes: initialSettings.cacheMaxStaleMinutes || 720,
		});
		const [varnishForm, setVarnishForm] = useState({
			varnishCliEnabled: !!initialSettings.varnishCliEnabled,
			varnishCliMode: initialSettings.varnishCliMode || 'http',
			varnishCliServers: initialSettings.varnishCliServers || '127.0.0.1:80',
			varnishCliKey: initialSettings.varnishCliKey || '',
			varnishCliTimeoutSeconds: initialSettings.varnishCliTimeoutSeconds || 2,
			varnishCliMethod: initialSettings.varnishCliMethod || 'BAN',
			varnishCliDebug: !!initialSettings.varnishCliDebug,
			varnishCliKeyConfigured: !!initialSettings.varnishCliKeyConfigured,
		});
		const [redisForm, setRedisForm] = useState({
			objectCacheBackend: initialSettings.objectCacheBackend || 'redis',
			redisHost: initialSettings.redisHost || '127.0.0.1',
			redisPort: initialSettings.redisPort || 6379,
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
		const [homepageHtmlBusy, setHomepageHtmlBusy] = useState(false);
		const [homepageHtmlCssBusy, setHomepageHtmlCssBusy] = useState(false);
		const [allUrlsCssBusy, setAllUrlsCssBusy] = useState(false);
		const [menuUrlsCssBusy, setMenuUrlsCssBusy] = useState(false);
		const [savedJob, setSavedJob] = useState(loadSavedJob());
		const cancelRequestedRef = useRef(false);
		const importFileInputRef = useRef(null);
		const statsRefreshInFlightRef = useRef(false);
		const settingsRef = useRef(initialSettings);
		const committedSettingsRef = useRef(initialSettings);
		const pendingSettingsPatchRef = useRef({});
		const settingsSaveTimerRef = useRef(null);
		const settingsSaveInFlightRef = useRef(false);
		const queuedActionKeysRef = useRef({});
		const compressionSyncRef = useRef('');
		const compressionLocks = useMemo(() => getCompressionLockState(diagnostics, browserCompressionProbe), [diagnostics, browserCompressionProbe]);
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
				return filtered.concat([nextToast]).slice(-6);
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
						title: 'Reverse proxy detected',
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

		useEffect(() => {
			let cancelled = false;

			(async () => {
				try {
					const result = await probeFrontendCompressionViaBrowser();
					if (!cancelled) {
						setBrowserCompressionProbe(result);
					}
				} catch (error) {
					if (!cancelled) {
						setBrowserCompressionProbe({ ready: true, serverCompression: false, gzip: false, brotli: false, brokenGzip: false, brokenBrotli: false, message: '' });
					}
				}
			})();

			return () => {
				cancelled = true;
			};
		}, []);

		useEffect(() => {
			if (!settings.cacheStatsEnabled) {
				return undefined;
			}

			let interval = null;

			const runRefresh = async () => {
				if (document.hidden || statsRefreshInFlightRef.current) {
					return;
				}
				statsRefreshInFlightRef.current = true;
				try {
					await refreshStats();
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
			settings.cronWarmPagesPerMinute,
			settings.scheduledWarmLimit,
			settings.cacheFreshTtlMinutes,
			settings.cacheMaxStaleMinutes,
		]);


		useEffect(() => {
			setVarnishForm({
				varnishCliEnabled: !!settings.varnishCliEnabled,
				varnishCliMode: settings.varnishCliMode || 'http',
				varnishCliServers: settings.varnishCliServers || '127.0.0.1:80',
				varnishCliKey: settings.varnishCliKey || '',
				varnishCliTimeoutSeconds: settings.varnishCliTimeoutSeconds || 2,
				varnishCliMethod: settings.varnishCliMethod || 'BAN',
				varnishCliDebug: !!settings.varnishCliDebug,
				varnishCliKeyConfigured: !!settings.varnishCliKeyConfigured,
			});
		}, [
			settings.varnishCliEnabled,
			settings.varnishCliMode,
			settings.varnishCliServers,
			settings.varnishCliKey,
			settings.varnishCliTimeoutSeconds,
			settings.varnishCliMethod,
			settings.varnishCliDebug,
			settings.varnishCliKeyConfigured,
		]);

		useEffect(() => {
			setRedisForm({
				objectCacheBackend: settings.objectCacheBackend || 'redis',
				redisHost: settings.redisHost || '127.0.0.1',
				redisPort: settings.redisPort || 6379,
				redisPassword: settings.redisPassword || '',
				redisDatabase: typeof settings.redisDatabase === 'undefined' ? 0 : settings.redisDatabase,
				redisPrefix: settings.redisPrefix || '',
				redisUseTls: !!settings.redisUseTls,
				redisPersistent: !!settings.redisPersistent,
				redisConnectTimeoutMs: typeof settings.redisConnectTimeoutMs === 'undefined' ? 200 : settings.redisConnectTimeoutMs,
				redisReadTimeoutMs: typeof settings.redisReadTimeoutMs === 'undefined' ? 200 : settings.redisReadTimeoutMs,
				redisPasswordConfigured: !!settings.redisPasswordConfigured,
			});
		}, [
			settings.objectCacheBackend,
			settings.redisHost,
			settings.redisPort,
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
						setDiagnostics(response.diagnostics);
					}
					pushToast({ type: 'warning', text: 'UltraCache automatically turned off compression that is already handled by your server or proxy.' });
				} catch (error) {
					compressionSyncRef.current = '';
					pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to synchronize compression safety settings.' });
				} finally {
					setBusy(false);
				}
			})();
		}, [busy, browserCompressionProbe.ready, compressionLocks.gzipLocked, compressionLocks.brotliLocked, settings.gzipEnabled, settings.brotliEnabled, pushToast]);


		const etaText = useMemo(() => {
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
			setVarnishForm((current) => Object.assign({}, current, { [key]: value }));

			if (key === 'varnishCliEnabled' || key === 'varnishCliDebug') {
				queueSettingsPatch({ [key]: !!value });
			}
		}

		function updateRedisField(key, value) {
			setRedisForm((current) => Object.assign({}, current, { [key]: value }));

			if (key === 'objectCacheBackend') {
				queueSettingsPatch({ objectCacheBackend: value });
			}
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
			if (busy) {
				return;
			}

			const next = Object.assign({}, settings, redisForm || {});
			delete next.redisPasswordConfigured;
			if (!String((redisForm && redisForm.redisPassword) || '').trim()) {
				delete next.redisPassword;
			}
			setBusy(true);
			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(next) });
				if (response && response.settings) {
					applyServerSettings(response.settings);
				}
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}
				pushToast({ type: 'success', text: 'Object cache backend settings saved.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to save object cache settings.' });
			} finally {
				setBusy(false);
			}
		}

		async function testRedisConnection() {
			if (asyncActions.redis_test) {
				return;
			}

			const payload = Object.assign({}, redisForm || {});
			const passwordValue = String(payload.redisPassword || '').trim();
			if (!passwordValue) {
				delete payload.redisPassword;
			}

			await queueDashboardAction('redis_test', payload, {
				queued: 'Redis connection test processing via dashboard…',
				success: 'Redis connection test finished.',
				failed: 'Redis connection test failed.',
			}, 'redis_test', (result) => {
				setDiagnostics((current) => Object.assign({}, current || {}, {
					objectCache: Object.assign({}, (current && current.objectCache) || {}, {
						redis: Object.assign({}, (((current && current.objectCache) || {}).redis) || {}, result || {}),
					}),
				}));
			});
		}

		async function flushObjectCache() {
			await queueDashboardAction('object_cache_flush', {}, {
				queued: 'Object cache flush processing via dashboard…',
				success: 'Object cache flush finished.',
				failed: 'Object cache flush failed.',
			}, 'object_cache_flush');
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
			if (busy) {
				return;
			}

			const next = Object.assign({}, settings, varnishForm || {});
			delete next.varnishCliKeyConfigured;
			if (!String((varnishForm && varnishForm.varnishCliKey) || '').trim()) {
				delete next.varnishCliKey;
			}
			setBusy(true);
			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(next) });
				if (response && response.settings) {
					applyServerSettings(response.settings);
				}
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}
				pushToast({ type: 'success', text: 'Varnish settings saved.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to save Varnish settings.' });
			} finally {
				setBusy(false);
			}
		}
		async function runVarnishTest() {
			await queueDashboardAction('varnish_test', {}, {
				queued: 'Varnish test processing via dashboard…',
				success: 'Varnish test completed.',
				failed: 'Varnish test failed.',
			}, 'varnish_test');
		}

		async function runVarnishFlushAll() {
			await queueDashboardAction('varnish_flush_all', {}, {
				queued: 'Varnish flush processing via dashboard…',
				success: 'Varnish flush finished.',
				failed: 'Varnish flush failed.',
			}, 'varnish_flush_all');
		}


		async function flushOpcache() {
			await queueDashboardAction('opcache_flush', {}, {
				queued: 'OPcache flush processing via dashboard…',
				success: 'OPcache flush finished.',
				failed: 'OPcache flush failed.',
			}, 'opcache_flush');
		}

		async function flushApcu() {
			await queueDashboardAction('apcu_flush', {}, {
				queued: 'APCu flush processing via dashboard…',
				success: 'APCu flush finished.',
				failed: 'APCu flush failed.',
			}, 'apcu_flush');
		}

		function normalizeStatsResponse(payload) {
			if (payload && typeof payload === 'object' && payload.stats && typeof payload.stats === 'object') {
				return payload.stats;
			}

			return payload && typeof payload === 'object' ? payload : {};
		}

		async function refreshStats() {
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
				setStats(freshStats);
				setDiagnostics((freshStats && freshStats.diagnostics) || initialDiagnostics || {});
			}
		}

		function hasPendingSettingsPatch() {
			return !!Object.keys(pendingSettingsPatchRef.current || {}).length;
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

		function applyDashboardPayload(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}

			const responseStats = payload.stats || (payload.result && payload.result.stats);
			if (responseStats) {
				setStats(normalizeStatsResponse(responseStats));
			}

			const responseDiagnostics = payload.diagnostics || (payload.result && payload.result.diagnostics);
			if (responseDiagnostics) {
				setDiagnostics(responseDiagnostics);
			}

			const responseSettings = payload.settings || (payload.result && payload.result.settings);
			if (responseSettings) {
				applyServerSettings(responseSettings);
			}
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

		async function pollQueuedAction(jobId) {
			let job = null;
			for (let attempt = 0; attempt < ACTION_QUEUE_MAX_POLLS; attempt++) {
				const response = await apiRequest('queue_status', { id: jobId });
				job = response && response.job ? response.job : null;
				if (job && ['done', 'failed'].indexOf(job.status) !== -1) {
					return job;
				}
				await sleep(ACTION_QUEUE_POLL_DELAY);
			}
			throw new Error('Dashboard processing action timed out.');
		}

		async function waitForSettingsSaveToSettle(maxWaitMs = 6000) {
			const startedAt = Date.now();
			while (settingsSaveInFlightRef.current && (Date.now() - startedAt) < maxWaitMs) {
				await sleep(50);
			}
			return !settingsSaveInFlightRef.current;
		}

		async function syncQueuedSettingsBeforeAction() {
			if (settingsSaveTimerRef.current || hasPendingSettingsPatch()) {
				await flushQueuedSettings();
			}
			await waitForSettingsSaveToSettle();
			if (hasPendingSettingsPatch()) {
				await flushQueuedSettings();
				await waitForSettingsSaveToSettle();
			}
		}

		async function queueDashboardAction(action, params, labels, key, afterResult) {
			const actionKey = key || action;
			if (queuedActionKeysRef.current[actionKey]) {
				pushToast({ type: 'info', text: (labels && labels.alreadyQueued) || 'This dashboard action is already processing.' });
				return null;
			}

			await syncQueuedSettingsBeforeAction();

			setAsyncActionState(actionKey, true, (labels && labels.runningLabel) || 'Processing via dashboard…');
			pushToast({ id: 'ucwp-action-' + actionKey, type: 'info', text: (labels && labels.queued) || 'Processing via dashboard…', duration: 2200 });

			try {
				const queued = await apiRequest('queue_action', { action, params: params || {} });
				const job = queued && queued.job ? queued.job : null;
				if (!job || !job.id) {
					throw new Error('Dashboard processing action was not created.');
				}

				const completed = await pollQueuedAction(job.id);
				const result = completed && completed.result ? completed.result : {};
				applyDashboardPayload(result);
				if (typeof afterResult === 'function') {
					afterResult(result, completed);
				}
				if (!result.stats && !result.diagnostics && ['purge_all', 'object_cache_flush', 'object_cache_full_count', 'warm_frontpage_html', 'warm_frontpage_html_css', 'opcache_flush', 'apcu_flush', 'varnish_flush_all'].indexOf(action) !== -1) {
					try {
						await refreshStats();
					} catch (error) {}
				}
				const ok = completed && completed.status === 'done';
				pushToast({
					type: ok ? 'success' : 'error',
					text: (completed && completed.message) || (ok ? ((labels && labels.success) || 'Action completed.') : ((labels && labels.failed) || 'Action failed.')),
				});
				return completed;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : ((labels && labels.failed) || 'Dashboard processing action failed.') });
				return null;
			} finally {
				setAsyncActionState(actionKey, false);
			}
		}

		async function flushQueuedSettings() {
			if (settingsSaveTimerRef.current) {
				window.clearTimeout(settingsSaveTimerRef.current);
				settingsSaveTimerRef.current = null;
			}

			if (settingsSaveInFlightRef.current) {
				await waitForSettingsSaveToSettle();
				if (settingsSaveInFlightRef.current) {
					return;
				}
			}

			const patch = Object.assign({}, pendingSettingsPatchRef.current || {});
			pendingSettingsPatchRef.current = {};
			if (!Object.keys(patch).length) {
				return;
			}

			settingsSaveInFlightRef.current = true;
			pushToast({ id: 'ucwp-settings-queue', type: 'info', text: 'Saving queued settings…', persistent: true });

			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(patch) });
				if (response && response.settings) {
					applyServerSettings(response.settings);
				} else {
					const committed = Object.assign({}, committedSettingsRef.current || {}, patch);
					committedSettingsRef.current = committed;
					applyEffectiveSettingsFromCommitted();
				}
				applyDashboardPayload(response || {});
				pushToast({ id: 'ucwp-settings-queue', type: 'success', text: hasPendingSettingsPatch() ? 'Queued settings saved. More changes are pending…' : 'Queued settings saved.' });
			} catch (error) {
				applyEffectiveSettingsFromCommitted();
				pushToast({ id: 'ucwp-settings-queue', type: 'error', text: error && error.message ? error.message : 'Queued settings failed to save.' });
			} finally {
				settingsSaveInFlightRef.current = false;
				if (Object.keys(pendingSettingsPatchRef.current || {}).length) {
					settingsSaveTimerRef.current = window.setTimeout(flushQueuedSettings, 50);
				}
			}
		}
		function queueSettingsPatch(patch) {
			if (!patch || typeof patch !== 'object') {
				return;
			}
			pendingSettingsPatchRef.current = Object.assign({}, pendingSettingsPatchRef.current || {}, patch);
			const next = Object.assign({}, settingsRef.current || {}, patch);
			settingsRef.current = next;
			setSettings(next);
			if (settingsSaveInFlightRef.current) {
				pushToast({ id: 'ucwp-settings-queue', type: 'info', text: 'Saving queued settings…', persistent: true });
			} else {
				pushToast({ id: 'ucwp-settings-queue', type: 'info', text: 'Settings queued…', duration: 1400 });
			}

			if (settingsSaveTimerRef.current) {
				window.clearTimeout(settingsSaveTimerRef.current);
			}
			settingsSaveTimerRef.current = window.setTimeout(flushQueuedSettings, SETTINGS_SAVE_DEBOUNCE_MS);
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
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
				return;
			}
			if (process.active || asyncActions.warm_frontpage_html) {
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
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: 'Please enable CSS Bundling before using CSS bundle warm actions.' });
				return;
			}
			if (process.active || asyncActions.warm_frontpage_html_css) {
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
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: 'Please enable CSS Bundling before using CSS bundle warm actions.' });
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
				}, !!forceRestart);
			} finally {
				setAllUrlsCssBusy(false);
			}
		}

		async function startMenuWarming(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
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
				label: 'Warming Menu HTML Cache',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting menu URL crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}

		async function startMenuWarmingWithFrontpageCss(forceRestart = false) {
			await syncQueuedSettingsBeforeAction();
			if (!(settingsRef.current && settingsRef.current.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
				return;
			}
			if (!(settingsRef.current && settingsRef.current.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: 'Please enable CSS Bundling before using CSS bundle warm actions.' });
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
				}, !!forceRestart);
			} finally {
				setMenuUrlsCssBusy(false);
			}
		}

		function updateSetting(key, value) {
			queueSettingsPatch({ [key]: value });
		}

		async function populateQueryStringAllowlist() {
			try {
				const response = await apiRequest('populate_query_allowlist', {});
				const items = Array.isArray(response && response.items) ? response.items : [];
				if (!items.length) {
					pushToast({ type: 'warning', text: 'No query-string keys were detected.' });
					return null;
				}
				pushToast({ type: 'success', text: 'Populated query-string whitelist with ' + items.length + ' keys.' });
				return items.join('\n');
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Query-string populate failed.' });
				return null;
			}
		}

		function updateMediaOptimizationSetting(value) {

			queueSettingsPatch({
				mediaOptimizationEnabled: value,
			});
		}

		function updateFrontendSafeModeSetting(value) {
			queueSettingsPatch({
				frontendSafeModeEnabled: value,
				lcpBoundaryDeferEnabled: value ? false : !!settings.lcpBoundaryDeferEnabled,
			});
		}

		function updateSliderSafeModeSetting(value) {
			queueSettingsPatch({
				sliderSafeModeEnabled: value,
				lcpBoundaryDeferEnabled: value ? false : !!settings.lcpBoundaryDeferEnabled,
			});
		}

		function applyPerformanceProfile(profileKey) {
			const profile = PERFORMANCE_PROFILES[profileKey];
			if (!profile || !profile.patch) {
				return;
			}
			const patch = getPerformanceProfilePatch(profileKey);
			queueSettingsPatch(patch);
			setAdvancedForm((prev) => Object.assign({}, prev, {
				cacheFreshTtlMinutes: Object.prototype.hasOwnProperty.call(patch, 'cacheFreshTtlMinutes') ? patch.cacheFreshTtlMinutes : prev.cacheFreshTtlMinutes,
				cacheMaxStaleMinutes: Object.prototype.hasOwnProperty.call(patch, 'cacheMaxStaleMinutes') ? patch.cacheMaxStaleMinutes : prev.cacheMaxStaleMinutes,
			}));
			pushToast({ type: 'success', text: profile.label + ' profile queued.' });
		}

		function updateAdvancedField(key, value) {
			setAdvancedForm((prev) => Object.assign({}, prev, { [key]: value }));
		}

		async function saveAdvancedSettings() {
			if (busy) {
				return;
			}

			setBusy(true);
			try {
				const response = await apiRequest('save_settings', {
					settings_json: JSON.stringify({
						cacheCleanupIntervalHours: Number(advancedForm.cacheCleanupIntervalHours || 24),
						cronWarmPagesPerMinute: Number(advancedForm.cronWarmPagesPerMinute || 0),
						scheduledWarmLimit: Number(advancedForm.scheduledWarmLimit || advancedForm.cronWarmPagesPerMinute || 0),
						cacheFreshTtlMinutes: Number(advancedForm.cacheFreshTtlMinutes || 15),
						cacheMaxStaleMinutes: Number(advancedForm.cacheMaxStaleMinutes || 720),
					}),
				});

				if (response && response.settings) {
					applyServerSettings(response.settings);
				}

				if (response && response.stats) {
					setStats(response.stats);
				}

				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}

				pushToast({ type: 'success', text: 'Advanced settings saved.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to save advanced settings.',
				});
			} finally {
				setBusy(false);
			}
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

		function getJobControls(type) {
			if (!savedJob || savedJob.type !== type) {
				return { canResume: false, canRestart: false };
			}

			const processed = Math.max(0, Number(savedJob.processed || 0));
			const total = Math.max(0, Number(savedJob.total || 0));
			const hasPending = Array.isArray(savedJob.pendingItems) && savedJob.pendingItems.length > 0;
			const hasProgress = processed > 0 || total > 0 || hasPending || (Array.isArray(savedJob.logs) && savedJob.logs.length > 0);
			const incomplete = hasPending || !!savedJob.hasMore || total === 0 || processed < total;

			return {
				canResume: hasProgress && incomplete,
				canRestart: hasProgress,
			};
		}

		function updateProcessState(state, overrides = {}) {
			setProcess({
				active: !!state.active,
				label: state.label || '',
				current: Number(state.processed || 0),
				total: Number(state.total || 0),
				logs: Array.isArray(state.logs) ? state.logs : [],
				startTime: Number(state.startTime || 0),
				cancellable: !!state.active,
				cancelRequested: !!state.cancelRequested,
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
				logs: Array.isArray(job.logs) ? job.logs.slice(-50) : [],
				active: true,
				cancelRequested: false,
				startTime: forceRestart ? Date.now() : (job.startTime || Date.now()),
				batchSize: Math.max(1, Number(job.batchSize || DEFAULT_QUEUE_BATCH_SIZE)),
				successCount: forceRestart ? 0 : Math.max(0, Number(job.successCount || 0)),
				skippedCount: forceRestart ? 0 : Math.max(0, Number(job.skippedCount || 0)),
				failedCount: forceRestart ? 0 : Math.max(0, Number(job.failedCount || 0)),
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
						pushToast({ type: 'success', text: 'Job paused. You can resume it later.' });
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
						state = Object.assign({}, state, {
							total: Math.max(Number(state.total || 0), Number(batch.total || 0)),
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
						const successIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.successIncrement || 0)) : 1;
						const skippedIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.skippedIncrement || 0)) : 0;
						const failedIncrement = itemResult && typeof itemResult === 'object' ? Math.max(0, Number(itemResult.failedIncrement || 0)) : 0;
						state = Object.assign({}, state, {
							processed: Number(state.processed || 0) + progressIncrement,
							attachmentsProcessed: Number(state.attachmentsProcessed || 0) + attachmentIncrement,
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
				pushToast({ type: 'warning', text: 'Please enable Page Caching first or select a profile before warming cache.' });
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
				label: 'Warming Full Site HTML Cache',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting full site crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
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

			await runJob({
				type: 'media',
				label: 'Optimizing Media',
				cursor: 0,
				nextCursor: 0,
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting media conversion…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}


		function exportSettingsFile() {
			try {
				const payload = buildSettingsExportPayload(settings);
				const stamp = new Date().toISOString().replace(/[:.]/g, '-');
				const filename = 'ultracache-settings-' + (ucwp.version || 'export') + '-' + stamp + '.json';
				triggerFileDownload(filename, JSON.stringify(payload, null, 2), 'application/json');
				pushToast({ type: 'success', text: 'Settings exported.' });
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
					setDiagnostics(response.diagnostics);
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
				pushToast({ type: 'error', text: 'Default settings are not available in this build.' });
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
					setDiagnostics(response.diagnostics);
				}
				setInspectResult(null);
				pushToast({ type: 'success', text: 'UltraCache settings were reset to defaults.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to reset UltraCache settings.' });
			} finally {
				setBusy(false);
			}
		}

async function deleteAllPluginDataAndDeactivate() {
	if (busy) {
		return;
	}

	const mediaFolders = [
		'wp-content/cache/ultracache-avif',
		'wp-content/cache/ultracache-webp',
	];
	const confirmed = window.confirm(
		'Delete all UltraCache plugin data and deactivate the plugin?\n\n' +
		'This removes UltraCache settings, runtime files, drop-ins, Redis password files, object cache files, cache files, scheduled jobs, and managed .htaccess/WP_CACHE changes.\n\n' +
		'Converted media folders will NOT be deleted automatically. Delete them manually if needed:\n- ' + mediaFolders.join('\n- ')
	);
	if (!confirmed) {
		return;
	}

	const typed = window.prompt('Type DELETE to confirm. Converted media folders must be deleted manually if you want them removed.');
	if (String(typed || '').trim() !== 'DELETE') {
		pushToast({ type: 'info', text: 'Delete all plugin data cancelled.' });
		return;
	}

	setBusy(true);
	try {
		const response = await apiRequest('delete_all_data', { confirmation: 'DELETE' });
		pushToast({ type: 'success', text: response && response.message ? response.message : 'UltraCache data deleted and plugin deactivated.' });
		window.setTimeout(() => {
			window.location.href = 'plugins.php?deactivate=true';
		}, 1200);
	} catch (error) {
		pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to delete UltraCache data.' });
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
			const cssBundleDiagnostics = {
				bundlesBuilt: typeof stats.homepageCssBundlesBuilt !== 'undefined' ? stats.homepageCssBundlesBuilt : stats.frontpageCssBundlesBuilt,
				stylesBundled: typeof stats.homepageCssStylesBundled !== 'undefined' ? stats.homepageCssStylesBundled : stats.frontpageCssStylesBundled,
				stylesScanned: typeof stats.homepageCssStylesScanned !== 'undefined' ? stats.homepageCssStylesScanned : stats.frontpageCssStylesScanned,
				stylesSkipped: typeof stats.homepageCssStylesSkipped !== 'undefined' ? stats.homepageCssStylesSkipped : stats.frontpageCssStylesSkipped,
				stylesUnresolved: typeof stats.homepageCssStylesUnresolved !== 'undefined' ? stats.homepageCssStylesUnresolved : stats.frontpageCssStylesUnresolved,
				lastWarm: stats.lastFrontpageCssWarm || null,
			};
		const warmBusy = busy || process.active || homepageHtmlBusy || homepageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy;
		const warmDisabledMessage = !pageCacheReady
			? 'Please enable Page Caching first or select a profile before warming cache.'
			: (!settings.homepageCssBundleEnabled ? 'CSS bundle warm buttons are disabled until CSS Bundling is enabled.' : '');

		return h('div', { className: 'max-w-6xl p-6 space-y-8' }, [
			h('header', { className: 'flex flex-col gap-4 md:flex-row md:justify-between md:items-end', key: 'header' }, [
				h('div', { key: 'title' }, [
					h('h1', { className: 'text-3xl font-black tracking-tighter m-0 text-white' }, 'UltraCache'),
					h(
						'p',
						{ className: 'text-zinc-500 text-xs tracking-widest mt-2 mb-0' },
						'Page cache, object cache, compression, warmups, fonts, and next-gen images'
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

			h(ProgressPanel, { process, etaText, onCancel: requestCancel, key: 'progress' }),

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

			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'jobs' }, [
				h(
					Card,
					{
						title: 'Warm Cache',
						description: 'Crawl public URLs and prebuild static cache files.',
						key: 'warm',
					},
					[
						warmDisabledMessage ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'warm-disabled-message' }, warmDisabledMessage) : null,
						h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
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
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarming(false),
									disabled: !pageCacheReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (warmBusy ? 'Engine Busy' : (getJobControls('warm_menu').canResume ? 'Resume Warm Up Menu HTML Cache' : 'Warm Up Menu HTML Cache'))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarmingWithFrontpageCss(false),
									disabled: !cssBundleReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!settings.homepageCssBundleEnabled ? 'Enable CSS Bundling First' : (warmBusy && !menuUrlsCssBusy ? 'Engine Busy' : (menuUrlsCssBusy ? 'Warming Menu HTML + ' + getCssWarmBundleLabel(cssWarmScope, true) + '…' : (getJobControls(menuCssWarmJobType).canResume ? 'Resume ' + menuCssButtonLabel : menuCssButtonLabel))))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarming(false),
									disabled: !pageCacheReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (warmBusy ? 'Engine Busy' : (getJobControls('warm').canResume ? 'Resume Warm Up Full Site HTML Cache' : 'Warm Up Full Site HTML Cache'))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarmingAllWithFrontpageCss(false),
									disabled: !cssBundleReady || warmBusy,
								},
								!pageCacheReady ? 'Enable Page Cache First' : (!settings.homepageCssBundleEnabled ? 'Enable CSS Bundling First' : (warmBusy && !allUrlsCssBusy ? 'Engine Busy' : (allUrlsCssBusy ? 'Warming Full Site HTML + ' + getCssWarmBundleLabel(cssWarmScope, true) + '…' : (getJobControls(fullCssWarmJobType).canResume ? 'Resume ' + fullCssButtonLabel : fullCssButtonLabel))))
							)
						]),
						h('div', { className: 'uc-diagnostic-group mt-5', key: 'warm-css-bundle-diagnostics' }, [
							h('div', { className: 'uc-section-title' }, 'CSS Bundle Summary'),
							h('div', { key: 'fp-css-1' }, 'Bundles built: ' + formatNumber(cssBundleDiagnostics.bundlesBuilt || 0)),
							h('div', { key: 'fp-css-2' }, 'Styles bundled/scanned: ' + formatNumber(cssBundleDiagnostics.stylesBundled || 0) + ' / ' + formatNumber(cssBundleDiagnostics.stylesScanned || 0)),
							h('div', { key: 'fp-css-3' }, 'Skipped/unresolved: ' + formatNumber(cssBundleDiagnostics.stylesSkipped || 0) + ' / ' + formatNumber(cssBundleDiagnostics.stylesUnresolved || 0)),
							h('div', { key: 'fp-css-4' }, 'Last CSS bundle warm: ' + formatLooseTime(cssBundleDiagnostics.lastWarm || null)),
							(cssBundleDiagnostics.lastWarm && cssBundleDiagnostics.lastWarm.message)
								? h('div', { key: 'fp-css-5', className: 'text-zinc-500' }, cssBundleDiagnostics.lastWarm.message)
								: null,
						]),
					]
				),
			h(
				Card,
				{
					title: 'Media Optimization',
					description: 'Master switch for next-gen image generation, frontend rewriting, upload-time conversion, on-demand conversion, and batch conversion.',
					key: 'media-optimization',
				},
				[
					h(ToggleRow, {
						label: 'Enable Media Optimization',
						description: 'Enable AVIF/WebP generation and frontend image URL rewriting according to the selected output policy.',
						checked: mediaOptimizationEnabled,
						onChange: (value) => updateMediaOptimizationSetting(value),
						disabled: busy,
						key: 'media-optimization-enabled',
					}),
					h(ToggleRow, {
						label: 'Automatic Format',
						description: 'AVIF is preferred first, with WebP kept as the compatibility fallback.',
						checked: !settings.mediaOutputMode || 'auto' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'auto'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-auto',
					}),
					h(ToggleRow, {
						label: 'AVIF Format',
						description: 'Generate and prefer AVIF variants only.',
						checked: 'avif' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'avif'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-avif',
					}),
					h(ToggleRow, {
						label: 'WebP Format',
						description: 'Generate and prefer WebP variants only.',
						checked: 'webp' === settings.mediaOutputMode,
						onChange: (value) => { if (value) { updateSetting('mediaOutputMode', 'webp'); } },
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-output-webp',
					}),
					h(ToggleRow, {
						label: 'Generate on Upload',
						description: 'When enabled, newly uploaded images and their registered thumbnail sizes are queued for next-gen conversion.',
						checked: !!settings.mediaGenerateOnUploadEnabled,
						onChange: (value) => updateSetting('mediaGenerateOnUploadEnabled', value),
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-generate-upload',
					}),
					h(ToggleRow, {
						label: 'Generate on Demand',
						description: 'When enabled, UltraCache may create a missing next-gen image variant during safe frontend GET/HEAD requests, guarded by per-image locks, per-request limits, and a short time budget.',
						checked: !!settings.mediaGenerateOnDemandEnabled,
						onChange: (value) => updateSetting('mediaGenerateOnDemandEnabled', value),
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-generate-demand',
					}),
					h(ToggleRow, {
						label: 'Safe CLS Dimensions',
						description: 'Inject missing width and height on local images using attachment metadata first and local file dimensions as fallback.',
						checked: settings.clsDimensionsEnabled,
						onChange: (value) => updateSetting('clsDimensionsEnabled', value),
						disabled: busy || !mediaOptimizationEnabled || !!settings.frontendSafeModeEnabled,
						key: 'media-cls-dimensions',
					}),
					h(ToggleRow, {
						label: 'LCP Image Priority',
						description: 'Prioritize likely hero/LCP images. In normal mode UltraCache can mark the detected candidate and inject a preload; when Fix sliders / hero sections is active, it uses SR7/Revolution Slider first-slide discovery plus a lifecycle-safe runtime guard.',
						checked: settings.lcpImagePriorityEnabled,
						onChange: (value) => updateSetting('lcpImagePriorityEnabled', value),
						disabled: busy || !mediaOptimizationEnabled || !!settings.frontendSafeModeEnabled,
						key: 'media-lcp-priority',
					}),
					!avifSupport.supported
						? h(
							'div',
							{
								className:
									'mt-4 p-3 bg-rose-500/10 border border-rose-500/20 rounded text-rose-400 text-xs',
							},
							'This server cannot generate AVIF or WebP yet. Install Imagick with AVIF/WebP support or a GD build that includes imageavif()/imagewebp().'
						 )
						: null,
					h('div', { className: 'mt-5 pt-4 space-y-3', key: 'media-batch-section' }, [
						h('div', { className: 'text-sm font-medium text-white', key: 'media-batch-title' }, 'Batch conversion'),
						h('div', { className: 'text-xs text-zinc-500', key: 'media-batch-description' }, 'Convert existing uploads and generated image sizes using the selected output format policy.'),
						h('div', { className: 'flex gap-3 flex-wrap', style: { marginTop: '5px' }, key: 'media-batch-actions' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[180px] text-white py-3 font-bold',
									onClick: () => startMediaOptimization(false),
									disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
								},
								busy ? 'Engine Busy' : (getJobControls('media').canResume ? 'Resume Media Conversion' : 'Start Media Conversion')
							),
							getJobControls('media').canRestart
								? h(
									'button',
									{
										className: 'uc-btn uc-btn--primary flex-1 min-w-[180px] text-white py-3 font-bold',
										onClick: () => startMediaOptimization(true),
										disabled: busy || !mediaOptimizationEnabled || !avifSupport.supported,
									},
									busy ? 'Engine Busy' : 'Restart Media Conversion'
								)
								: null,
						]),
						h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'media support' }, [
							h('div', { key: 's1' }, 'Imagick: ' + (avifSupport.imagick ? 'Yes' : 'No')),
							h('div', { key: 's2' }, 'Imagick AVIF: ' + (avifSupport.imagick_avif ? 'Yes' : 'No')),
							h('div', { key: 's3' }, 'Imagick WebP: ' + (avifSupport.imagick_webp ? 'Yes' : 'No')),
							h('div', { key: 's4' }, 'GD AVIF: ' + (avifSupport.gd_avif ? 'Yes' : 'No')),
							h('div', { key: 's5' }, 'GD WebP: ' + (avifSupport.gd_webp ? 'Yes' : 'No')),
						]),
					]),
				]
			)
			]),

			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'settings' }, [

				h(
					Card,
					{
						title: 'Select profile',
						description: 'Select a known preset below. Profiles apply their settings immediately and can be adjusted manually afterwards.',
						key: 'performance-profile-card',
					},
					[
						h('div', { className: 'grid grid-cols-1 gap-4', key: 'performance-profile-grid' },
							PERFORMANCE_PROFILE_DISPLAY_ORDER.map((profileKey) => {
								const profile = profileKey === 'custom' ? PERFORMANCE_PROFILE_CUSTOM : PERFORMANCE_PROFILES[profileKey];
								return h(ToggleRow, {
									label: profile.label,
									description: profile.description,
									checked: activePerformanceProfile === profileKey,
									onChange: (value) => {
										if (!value) { return; }
										if (profileKey === 'custom') {
											pushToast({ type: 'info', text: 'Custom turns on automatically when settings do not match a preset.' });
											return;
										}
										applyPerformanceProfile(profileKey);
									},
									disabled: busy,
									key: 'performance-profile-' + profileKey,
								});
							})
						),
					]
				),
				h(
					Card,
					{
						title: 'Cache Engine',
						description: 'Core page cache behavior, WooCommerce bypasses, compression variants, and safe prefetch hints.',
						key: 'cache-engine',
					},
					[
						h(ToggleRow, {
							label: 'Page Caching',
							description: 'Store public pages as static HTML files.',
							checked: settings.pageCacheEnabled,
							onChange: (value) => updateSetting('pageCacheEnabled', value),
							disabled: busy,
							key: 'page',
						}),
h(ToggleRow, {
							label: 'Pre-render on Save',
							description: 'Warm the updated page after content changes.',
							checked: settings.preRenderOnSave,
							onChange: (value) => updateSetting('preRenderOnSave', value),
							disabled: busy,
							key: 'preload',
						}),
h(ToggleRow, {
							label: 'WooCommerce Safe Mode',
							description: 'Bypass cart, checkout, account, order endpoints, and cart-changing requests.',
							checked: settings.woocommerceSafeModeEnabled,
							onChange: (value) => updateSetting('woocommerceSafeModeEnabled', value),
							disabled: busy,
							key: 'woo-safe',
						}),
h(ToggleRow, {
							label: 'Browser Cache Headers (.htaccess)',
							description: 'Write long-lived browser cache headers for CSS, JS, fonts, static images, AVIF, and WebP on Apache-compatible hosts.',
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
								label: 'Speculation Rules Prefetch',
								description: 'Inject a safe prefetch-only speculationrules block for likely next-page internal navigations. Logged-in users, query-string links, WooCommerce flows, admin-like paths, nofollow links, and target/download links stay excluded.',
								checked: settings.speculationRulesEnabled,
								onChange: (value) => updateSetting('speculationRulesEnabled', value),
								disabled: busy,
								key: 'cache-engine-speculation-rules',
							}),

					]
				),
				h(
					Card,
					{
						title: 'Frontend JS & Request Chains',
						description: 'Defer/delay safe scripts and prioritize known critical request chains.',
						key: 'frontend-js-request-chains-card',
					},
					[
						h(ToggleRow, {
									label: 'Enable Defer JS',
									description: 'Add native defer to safe frontend scripts while keeping core, inline-dependent, WooCommerce, and protected scripts in normal blocking flow.',
									checked: settings.deferJsEnabled,
									onChange: (value) => updateSetting('deferJsEnabled', value),
									disabled: busy,
									key: 'defer-stage-one',
								}),
h(ToggleRow, {
									label: 'Delay third-party JS',
									description: 'Delay known third-party scripts until user interaction or the normal fallback timeout. Use the advanced exclusions accordion for sliders, menus, checkout, forms, and other fragile frontend scripts.',
									checked: settings.delayThirdPartyJsEnabled,
									onChange: (value) => updateSetting('delayThirdPartyJsEnabled', value),
									disabled: busy,
									key: 'defer-stage-two',
								}),
					h(ToggleRow, {
						label: 'Delay non-critical/local JS',
						description: 'Delay selected same-host enhancement scripts such as popups, sliders, filters, consent extras, marketing helpers, and other local footer scripts unless protected or excluded here.',
						checked: settings.delayNonCriticalJsEnabled,
						onChange: (value) => updateSetting('delayNonCriticalJsEnabled', value),
						disabled: busy,
						key: 'defer-stage-three',
					}),
h(ToggleRow, {
									label: 'Main Thread Relief',
									description: 'Load delayed scripts gradually during browser idle time instead of releasing the full delayed queue at once. Works with Stage two and Stage three delayed scripts.',
									checked: settings.mainThreadReliefEnabled,
									onChange: (value) => updateSetting('mainThreadReliefEnabled', value),
									disabled: busy,
									key: 'main-thread-relief',
								}),
h(ToggleRow, {
									label: 'Critical Request Chain Relief',
									description: 'Preload known critical requests and let selected non-critical chained assets be delayed so the browser has a shorter critical network chain.',
									checked: settings.criticalRequestChainReliefEnabled,
									onChange: (value) => updateSetting('criticalRequestChainReliefEnabled', value),
									disabled: busy,
									key: 'critical-request-chain-relief',
								}),
h(ToggleRow, {
									label: 'LCP Boundary Defer',
									description: 'Use the detected LCP image as a conservative visual boundary and delay eligible local enhancement scripts printed after that boundary. Disabled when Frontend Safe Mode or Fix sliders / hero sections is enabled, because those modes intentionally avoid this rewrite path.',
									checked: !!settings.lcpBoundaryDeferEnabled,
									onChange: (value) => updateSetting('lcpBoundaryDeferEnabled', value),
									disabled: busy || !settings.lcpImagePriorityEnabled || !!settings.frontendSafeModeEnabled || !!settings.sliderSafeModeEnabled,
									key: 'lcp-boundary-defer',
								})
					]
				),
h(
					Card,
					{
						title: 'CSS Delivery',
						description: 'Bundle eligible CSS and async low-risk stylesheets without changing media-related controls.',
						key: 'css-delivery-lcp-card',
					},
					[
						h(ToggleRow, {
							label: 'CSS Bundling',
							description: 'Create local UltraCache CSS bundles for eligible stylesheet links. Choose the scope below: homepage-only for safest cache reuse, shared for a common site bundle, or per-page for isolated landing/page optimization.',
							checked: settings.homepageCssBundleEnabled,
							onChange: (value) => updateSetting('homepageCssBundleEnabled', value),
							disabled: busy,
							key: 'homepage-css-bundle',
						}),
						h('div', { className: 'uc-css-bundle-scope-field', key: 'css-bundle-scope-wrap' }, h(SelectField, {
							label: 'CSS Bundling Scope',
							description: 'Choose exactly one scope for generated CSS bundles. Homepage only is safest, shared reuses the homepage bundle where possible, and per-page creates separate bundles for cacheable pages.',
							value: settings.cssBundleScope || 'homepage',
							onChange: (value) => updateSetting('cssBundleScope', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							options: [
								{ value: 'homepage', label: 'Homepage only' },
								{ value: 'shared', label: 'Shared site bundle' },
								{ value: 'per-page', label: 'Per-page bundles' },
							],
						})),
h(ToggleRow, {
							label: 'CSS Safe Bundling',
							description: 'Conservative CSS bundling. Injects the generated bundle without removing original stylesheet links. If Fix sliders / hero sections detects fragile slider markup, safe bundle replacement may be skipped on that page to protect the hero area.',
							checked: 'aggressive' !== settings.homepageCssBundleMode,
							onChange: (value) => updateSetting('homepageCssBundleMode', value ? 'safe' : 'aggressive'),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'homepage-css-bundle-stage-1',
						}),
h(ToggleRow, {
							label: 'CSS Aggressive Bundling',
							description: 'Includes the safe local CSS rules and additionally accepts local screen/all media stylesheets. It replaces matched stylesheet links with the generated bundle while still skipping external, async, disabled, unreadable, and excluded CSS.',
							checked: 'aggressive' === settings.homepageCssBundleMode,
							onChange: (value) => updateSetting('homepageCssBundleMode', value ? 'aggressive' : 'safe'),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'homepage-css-bundle-stage-2',
						}),
h(ToggleRow, {
							label: 'Inline CSS Bundling',
							description: 'Inline the generated page CSS bundle directly into the document head instead of loading it as a separate stylesheet request.',
							checked: settings.homepageCssBundleInlineEnabled,
							onChange: (value) => updateSetting('homepageCssBundleInlineEnabled', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'homepage-css-bundle-inline',
						}),
h(ToggleRow, {
							label: 'Create CSS Bundle on Entry / Warm',
							description: 'Build missing CSS bundles on entry or warmup according to the selected scope. Homepage and shared scopes build the homepage bundle; per-page scope can build a separate bundle for each warmed cacheable URL.',
							checked: settings.pageCssBundleOnEntryEnabled,
							onChange: (value) => updateSetting('pageCssBundleOnEntryEnabled', value),
							disabled: busy || !settings.homepageCssBundleEnabled,
							key: 'page-css-bundle-on-entry',
						}),
h(ToggleRow, {
							label: 'Async Remaining CSS',
							description: 'Rewrite only low-risk local stylesheet links to non-blocking print+onload loading with a noscript fallback. This complements CSS Bundling.',
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
						title: 'Fonts Optimization',
						description: 'Optimize Google Fonts and self-hosted font CSS delivery.',
						key: 'fonts-optimization-card',
					},
					[
						h(ToggleRow, {
							label: 'Google Fonts display=swap',
							description: 'Append display=swap to Google Fonts requests generated by themes and plugins.',
							checked: settings.googleFontsSwapEnabled,
							onChange: (value) => updateSetting('googleFontsSwapEnabled', value),
							disabled: busy,
							key: 'fonts-swap',
						}),
h(ToggleRow, {
							label: 'Local Google Fonts Optimization',
							description: 'Opt-in feature. Download Google Fonts CSS and WOFF2 files into the UltraCache cache, rewrite the frontend to serve local copies, and keep font-display: swap on the localized CSS. This feature makes outbound requests to Google Fonts when building the local cache.',
							checked: settings.googleFontsLocalOptimizationEnabled,
							onChange: (value) => updateSetting('googleFontsLocalOptimizationEnabled', value),
							disabled: busy,
							key: 'google-fonts-local',
						}),
h(ToggleRow, {
							label: 'Optimize Self-Hosted Font CSS',
							description: 'Rewrite local @font-face CSS to add font-display: swap, normalize font URLs, and preload up to two likely first-paint WOFF2 files.',
							checked: settings.selfHostedFontCssOptimizationEnabled,
							onChange: (value) => updateSetting('selfHostedFontCssOptimizationEnabled', value),
							disabled: busy,
							key: 'self-hosted-fonts',
						}),
h(ToggleRow, {
							label: 'Advanced Runtime Font CSS Rewrite',
							description: 'Advanced opt-in. Uses a MutationObserver to rewrite late-injected local font stylesheet links. Native DOM prototype monkey-patching has been removed from the default path.',
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
						title: 'Safe Asset Cleanup',
						description: 'Optional cleanup for WooCommerce product/gallery/filter assets when they are not detected as needed.',
						key: 'asset-cleanup-section-card',
					},
					[
						h(ToggleRow, { label: 'Enable Asset Chain Cleanup', description: 'Cleans selected unnecessary frontend assets from cached HTML and late WordPress queues. Test homepage, shop, product, cart, checkout, and header search after enabling.', checked: settings.assetChainCleanupEnabled, onChange: (value) => updateSetting('assetChainCleanupEnabled', value), disabled: busy, key: 'asset-chain-cleanup-enabled' }),
h(ToggleRow, { label: 'Clean WooCommerce product/gallery assets outside product pages', description: 'Removes zoom, flexslider, PhotoSwipe, variation, and single-product assets when the cached HTML is not a single product page.', checked: settings.assetCleanupWooProductAssetsEnabled, onChange: (value) => updateSetting('assetCleanupWooProductAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-woo-product-assets' }),
h(ToggleRow, { label: 'Clean product filter assets when no filter is detected', description: 'Removes WOOF/filter scripts and styles when UltraCache cannot detect filter markup in the generated HTML.', checked: settings.assetCleanupProductFilterAssetsEnabled, onChange: (value) => updateSetting('assetCleanupProductFilterAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-product-filter-assets' }),
h(ToggleRow, { label: 'Clean WooCommerce Blocks CSS when no Woo blocks are detected', description: 'Removes wc-blocks.css from cached HTML when no WooCommerce block markup is present.', checked: settings.assetCleanupWooBlocksCssEnabled, onChange: (value) => updateSetting('assetCleanupWooBlocksCssEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, key: 'asset-cleanup-woo-blocks-css' })
					]
				),
			]),

			h('div', { className: 'uc-card', key: 'cache-engine-advanced-settings-exclusions-card' }, [
h('details', { className: 'uc-accordion uc-accordion--card', key: 'cache-engine-advanced-settings-exclusions' }, [
									h('summary', { className: 'uc-accordion__summary' }, [
										h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
											h('div', { className: 'uc-accordion__title' }, 'Advanced Settings & Exclusions'),
											h('div', { className: 'uc-accordion__description' }, 'Manual include/exclude lists and higher-risk toggles for frontend delivery, CSS/JS handling, asset cleanup, and LCP image handling.'),
										]),
										h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
									]),
									h('div', { className: 'uc-accordion__body space-y-4' }, [
										h(ToggleRow, {
											label: 'Frontend Safe Mode',
											description: 'Force the broad safe frontend mode. When enabled, UltraCache skips structural frontend rewrites such as authoring-asset stripping, async CSS rewrites, self-hosted font runtime rewrites, LCP Boundary Defer, and safe HTML minification.',
											checked: settings.frontendSafeModeEnabled,
											onChange: (value) => updateFrontendSafeModeSetting(value),
											disabled: busy,
											key: 'frontend-safe-mode',
										}),
										h(ToggleRow, {
											label: 'Fix sliders / hero sections',
											description: 'When Revolution Slider, SR7, Swiper, Slick, or similar hero/slider markup is detected, UltraCache protects slider/runtime assets, skips risky structural rewrites, disables LCP Boundary Defer, and keeps SR7 first-slide LCP priority on the lifecycle-safe path when LCP Image Priority is enabled.',
											checked: !!settings.sliderSafeModeEnabled,
											onChange: (value) => updateSliderSafeModeSetting(value),
											disabled: busy,
											key: 'slider-safe-mode',
										}),
										h(ToggleRow, {
											label: 'Aggressive Async CSS',
											description: 'Optional advanced mode. Rewrite almost all remaining local stylesheet links, including late footer output, to non-blocking print+onload loading with a noscript fallback. Use the exclude list for styles that must stay blocking.',
											checked: settings.aggressiveAsyncCssEnabled,
											onChange: (value) => updateSetting('aggressiveAsyncCssEnabled', value),
											disabled: busy,
											key: 'aggressive-async-css',
										}),
										h(ToggleRow, {
								label: 'Enable query-string args caching',
								description: 'Allow UltraCache to cache URL variants that include query-string args. Excluded query-string args always bypass cache. If the whitelist below is empty, all non-excluded query-string variants can be cached.',
								checked: !!settings.cacheQueryStringsEnabled,
								onChange: (value) => updateSetting('cacheQueryStringsEnabled', value),
								disabled: busy,
								key: 'query-string-caching',
							}),
											h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'cache-engine-advanced-fields' }, [
											h(SaveableTextAreaField, {
												label: 'Exclude Paths From Caching',
												description: 'One path per line. Prefix matching is supported. Example: /members/ or /thank-you/.',
												value: settings.cacheExceptionPaths || '',
												onSave: (value) => updateSetting('cacheExceptionPaths', value),
												disabled: busy,
												placeholder: '/cart/\n/checkout/\n/my-account/',
												saveLabel: 'Save Excluded Paths',
												key: 'exclude-paths-from-caching',
											}),
											h(SaveableTextAreaField, {
												label: 'Excluded query-string args from Caching',
												description: 'One query key per line. URLs containing any of these query-string args always bypass cache. Use this for carts, previews, searches, dynamic filters, tracking actions, and other unsafe parameters.',
												value: settings.cacheExceptionQueryArgs || '',
												onSave: (value) => updateSetting('cacheExceptionQueryArgs', value),
												disabled: busy,
												placeholder: 'preview\nadd-to-cart\nwc-ajax',
												saveLabel: 'Save Excluded Query-string Args',
												key: 'excluded-query-string-args-from-caching',
											}),
											h(SaveableTextAreaField, {
												label: 'Query-string args whitelist',
												description: 'Optional. One query key per line. Leave empty to cache all non-excluded query-string variants. When filled, UltraCache caches a query-string URL only when every query arg is listed here. Click Populate to scan your website/WooCommerce setup for likely query strings.',
												value: settings.cacheQueryStringAllowlist || '',
												onSave: (value) => updateSetting('cacheQueryStringAllowlist', value),
												disabled: busy,
												placeholder: 'swoof\npa_translations\nproduct_author\nproduct_cat\nproduct_tag\nproduct_genre\npa_series\ngroup_by_series\npa_format',
												saveLabel: 'Save Query-string Whitelist',
												populateLabel: 'Populate',
												populateWarning: 'Your current whitelist will be replaced.',
												onPopulate: populateQueryStringAllowlist,
												key: 'query-string-args-whitelist',
											}),
											h(SaveableTextAreaField, {
												label: 'Defer those scripts',
												description: 'Optional newline-separated handle or URL fragments. Matching frontend scripts are forced to native defer even when Defer JS is off or the script would normally be protected. Use carefully for scripts you explicitly want deferred.',
												value: settings.deferJsForceList || '',
												onSave: (value) => updateSetting('deferJsForceList', value),
												disabled: busy,
												placeholder: 'my-theme-script\n/custom-plugin/assets/app.js',
												saveLabel: 'Save Force Defer List',
												key: 'defer-those-scripts-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Defer / Delay Exclusions',
												description: 'Optional newline-separated handle or URL fragments. Matching scripts are left untouched by Defer JS and Delay JS. Use this for sliders, menus, checkout, forms, videos, and scripts that must run during initial render.',
												value: settings.deferJsExcludeList || '',
												onSave: (value) => updateSetting('deferJsExcludeList', value),
												disabled: busy,
												placeholder: 'revslider\nsr7\ntptools\nelementor-frontend\nswiper\nslick\nsmartslider\nn2-ss',
												saveLabel: 'Save Exclusions',
												key: 'defer-stages-exclude-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Manual Priority Preloads',
												description: 'Optional newline-separated priority resources. Prefix each line with image, style, script, font, or fetch. Image entries receive fetchpriority=high.',
												value: settings.criticalResourcePreloadList || '',
												onSave: (value) => updateSetting('criticalResourcePreloadList', value),
												disabled: busy || !settings.criticalRequestChainReliefEnabled,
												placeholder: 'style /wp-content/cache/ultracache/homepage-css/homepage.css\nfont /wp-content/uploads/fonts/manrope.woff2',
												saveLabel: 'Save Priority Preloads',
												key: 'critical-resource-preload-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Additional Fetch URL Preloads',
												description: 'Optional newline-separated URLs for dynamic frontend fetches, slider JSON/assets, or request chains that are not discovered early in the initial HTML.',
												value: settings.criticalFetchPreloadList || '',
												onSave: (value) => updateSetting('criticalFetchPreloadList', value),
												disabled: busy || !settings.criticalRequestChainReliefEnabled,
												placeholder: '/sliders/1?srengine=7&slideid=2\n/sliders/1?srengine=7&slideid=3',
												saveLabel: 'Save Fetch URL Preloads',
												key: 'critical-fetch-preload-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Delay Non-Critical Request Chains',
												description: 'Optional newline-separated handle or URL fragments. Matching local scripts are delayed and matching stylesheets are converted to async print/onload loading.',
												value: settings.criticalRequestChainDelayList || '',
												onSave: (value) => updateSetting('criticalRequestChainDelayList', value),
												disabled: busy || !settings.criticalRequestChainReliefEnabled,
												placeholder: 'tooltipster\nplainoverlay\nion.rangeSlider\nsourcebuster',
												saveLabel: 'Save Chain Delay List',
												key: 'critical-request-chain-delay-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Asset Cleanup Exclusions',
												description: 'Optional newline-separated handle, URL, or HTML fragments. When a page/request matches one of these fragments, Asset Chain Cleanup is skipped to protect builders, search, carts, checkout, and custom widgets.',
												value: settings.assetCleanupExcludeList || '',
												onSave: (value) => updateSetting('assetCleanupExcludeList', value),
												disabled: busy || !settings.assetChainCleanupEnabled,
												placeholder: 'elementor\nrevslider\nfibosearch\ncart\ncheckout',
												saveLabel: 'Save Asset Exclusions',
												key: 'asset-cleanup-exclude-list',
											}),
											h(SaveableTextAreaField, {
												label: 'CSS Bundle Exclusions',
												description: 'Optional newline-separated URL fragments. Matching stylesheets stay outside generated CSS bundles.',
												value: settings.homepageCssBundleExcludeList || '',
												onSave: (value) => updateSetting('homepageCssBundleExcludeList', value),
												disabled: busy || !settings.homepageCssBundleEnabled,
												placeholder: '/widget-mega-menu.min.css\n/front.css',
												saveLabel: 'Save CSS Bundle Exclusions',
												key: 'homepage-css-bundle-exclude-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Async CSS Exclude List',
												description: 'Optional newline-separated URL fragments. Matching stylesheets will not be rewritten to async loading.',
												value: settings.asyncCssExcludeList || '',
												onSave: (value) => updateSetting('asyncCssExcludeList', value),
												disabled: busy || !settings.asyncCssEnabled,
												placeholder: '/post-21.css\n/base/elementor.css',
												saveLabel: 'Save Exclude List',
												key: 'async-css-exclude-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Aggressive Async CSS Exclude List',
												description: 'Optional newline-separated handle or URL fragments. Matching local stylesheets stay in normal blocking flow even when Aggressive Async CSS is enabled.',
												value: settings.aggressiveAsyncCssExcludeList || '',
												onSave: (value) => updateSetting('aggressiveAsyncCssExcludeList', value),
												disabled: busy || !settings.aggressiveAsyncCssEnabled,
												placeholder: '/fontawesome.css\n/elementor/css/post-21.css',
												saveLabel: 'Save Exclude List',
												key: 'aggressive-async-css-exclude-list',
											}),
											h(SaveableTextAreaField, {
												label: 'Single LCP Image URL',
												description: 'Optional single hero/LCP image URL or fragment. For complex sliders with multiple assets, prefer Manual Priority Preloads or the slider-safe LCP priority feature.',
												value: settings.lcpImagePriorityOverride || '',
												onSave: (value) => updateSetting('lcpImagePriorityOverride', value),
												disabled: busy || !!settings.frontendSafeModeEnabled || !settings.lcpImagePriorityEnabled,
												placeholder: '/wp-content/uploads/hero.webp\nhero-home.jpg',
												saveLabel: 'Save LCP Image URL',
												key: 'lcp-priority-override',
											}),
										]),
									]),
								])
			]),

			h(RedisCard, { form: redisForm, diagnostics, busy: busy || !!asyncActions.redis_test || !!asyncActions.object_cache_flush, objectCacheEnabled: settings.objectCacheEnabled, onObjectCacheEnabledChange: (value) => updateSetting('objectCacheEnabled', value), onFieldChange: updateRedisField, onSave: saveRedisSettings, onTest: testRedisConnection, onFlush: flushObjectCache, key: 'redis-card' }),

			h(
				Card,
				{
					title: 'Automation & Scheduling',
					description: 'Scheduled cache cleanup, background warmup queue, and stale cache timing controls.',
					key: 'automation-scheduling-reworked',
				},
				[
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
						h(ToggleRow, {
							label: 'Scheduled Cache Cleanup',
							description: 'Run an automatic full cache purge using the interval below.',
							checked: settings.cacheCleanupEnabled,
							onChange: (value) => updateSetting('cacheCleanupEnabled', value),
							disabled: busy,
							key: 'cleanup',
						}),
						h(ToggleRow, {
							label: 'Cron Warm Up',
							description: 'Enable the minute-by-minute background HTML warm queue. Homepage is warmed first; CSS bundle warm actions remain manual and are not run by this cron queue.',
							checked: settings.cronWarmEnabled,
							onChange: (value) => updateSetting('cronWarmEnabled', value),
							disabled: busy,
							key: 'cron-warm-enabled',
						}),
						h(ToggleRow, {
							label: 'Start after Scheduled Cleanup',
							description: 'Start the cron warm queue after the scheduled cleanup purge completes.',
							checked: settings.cronWarmStartAfterCleanup,
							onChange: (value) => updateSetting('cronWarmStartAfterCleanup', value),
							disabled: busy || !settings.cacheCleanupEnabled || !settings.cronWarmEnabled,
							key: 'cleanup-warm',
						}),
						h(ToggleRow, {
							label: 'Start after Flush All Cache',
							description: 'Start the cron warm queue after a manual full cache purge.',
							checked: !!settings.cronWarmStartAfterManualPurge,
							onChange: (value) => updateSetting('cronWarmStartAfterManualPurge', value),
							disabled: busy || !settings.cronWarmEnabled,
							key: 'manual-purge-warm',
						}),
						h(ToggleRow, {
							label: 'Stale While Revalidate',
							description: 'Serve stale HTML only within the max stale window while UltraCache refreshes it in the background.',
							checked: settings.staleWhileRevalidateEnabled,
							onChange: (value) => updateSetting('staleWhileRevalidateEnabled', value),
							disabled: busy,
							key: 'swr-toggle',
						}),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
						h(NumberField, {
							label: 'Cleanup interval (hours)',
							description: 'Use 24 for daily, 168 for weekly, or any custom number of hours.',
							value: advancedForm.cacheCleanupIntervalHours,
							onChange: (value) => updateAdvancedField('cacheCleanupIntervalHours', value),
							disabled: busy,
							min: 1,
							key: 'cleanup-hours',
						}),
						h(NumberField, {
							label: 'Cron warm pages per minute',
							description: 'How many HTML URLs to warm per minute in the cron warm-up queue. Homepage is always warmed first. CSS bundles are not rebuilt by cron. Lower values are safer on slower servers. Set 0 to pause queue processing.',
							value: advancedForm.cronWarmPagesPerMinute,
							onChange: (value) => updateAdvancedField('cronWarmPagesPerMinute', value),
							disabled: busy,
							min: 0,
							key: 'warm-limit',
						}),
						h(NumberField, {
							label: 'Scheduled warm limit',
							description: getScheduledWarmLimitSummary(),
							value: advancedForm.scheduledWarmLimit,
							onChange: (value) => updateAdvancedField('scheduledWarmLimit', value),
							disabled: busy || !settings.cronWarmEnabled,
							min: 0,
							key: 'scheduled-warm-limit',
						}),
						h(NumberField, {
							label: 'Fresh TTL (minutes)',
							description: 'Serve a normal cache hit while the file age stays within this freshness window. Default: 15 minutes.',
							value: advancedForm.cacheFreshTtlMinutes,
							onChange: (value) => updateAdvancedField('cacheFreshTtlMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'fresh-ttl',
						}),
						h(NumberField, {
							label: 'Max stale window (minutes)',
							description: 'After freshness expires, UltraCache may still serve the stale file until this limit while it refreshes in the background. Default: 720 minutes (12 hours).',
							value: advancedForm.cacheMaxStaleMinutes,
							onChange: (value) => updateAdvancedField('cacheMaxStaleMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'max-stale',
						}),
					]),
					h('div', { className: 'mt-4 flex justify-end' }, [
						h(Button, { onClick: saveAdvancedSettings, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Automation & Scheduling'),
					]),
				]
			),

			h(VarnishCard, { form: varnishForm, diagnostics, busy: busy || !!asyncActions.varnish_test || !!asyncActions.varnish_flush_all, onFieldChange: updateVarnishField, onSave: saveVarnishSettings, onTest: runVarnishTest, onFlushAll: runVarnishFlushAll, key: 'varnish-card' }),
			h('div', { className: 'uc-info-grid', key: 'php-cache-cards' }, [
			h(OPcacheCard, { stats, busy: busy || !!asyncActions.opcache_flush, onFlush: flushOpcache, key: 'opcache-card' }),
			h(APCuCard, { stats, settings, busy: busy || !!asyncActions.apcu_flush, onFlush: flushApcu, onToggleScheduledCleanup: (value) => updateSetting('apcuFlushOnScheduledCleanup', value), key: 'apcu-card' }),
			]),

				h('div', { className: 'uc-info-grid', key: 'info-cards' }, [
					h(DiagnosticsCard, { diagnostics, stats, open: infoAccordionsOpen, onToggle: function() { setInfoAccordionsOpen(function(current) { return !current; }); }, key: 'diagnostics' }),
					h(ActivitySummaryCard, { stats, cssBundleDiagnostics, open: infoAccordionsOpen, onToggle: function() { setInfoAccordionsOpen(function(current) { return !current; }); }, key: 'activity-summary' }),
				]),
				settings.cacheStatsEnabled ? h(AdvancedDiagnosticsCard, { diagnostics, stats, key: 'advanced-diagnostics-card' }) : null,

			h(
				Card,
				{
					title: 'Cache Decision Tester',
					description: 'Inspect how UltraCache evaluates a frontend URL without using your current admin session cookies.',
					key: 'inspector',
				},
				[
					h(TextField, {
						label: 'URL or path',
						description: 'Paste a full local URL or just a path like /checkout/ or /product/widget/?add-to-cart=12.',
						value: inspectUrl,
						onChange: setInspectUrl,
						disabled: inspectBusy,
						placeholder: '/sample-page/?utm_source=test',
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
								h(DetailRow, { label: 'Reason', value: inspectResult.reasonLabel || inspectResult.reason }),
								h(DetailRow, { label: 'Normalized URL', value: inspectResult.normalizedUrl || inspectResult.url, mono: true }),
								h(DetailRow, { label: 'Normalized path', value: inspectResult.normalizedPath || inspectResult.path, mono: true }),
								h(DetailRow, { label: 'Query string', value: inspectResult.query || '—', mono: true }),
								h(DetailRow, { label: 'Matched excluded path rule', value: inspectResult.matchedExcludedPathRule, mono: true }),
								h(DetailRow, { label: 'Matched excluded query arg', value: inspectResult.matchedExcludedQueryArg, mono: true }),
								h(DetailRow, { label: 'Non-allowlisted query arg', value: inspectResult.matchedNonAllowlistedQueryArg, mono: true }),
								h(DetailRow, { label: 'Matched WooCommerce rule', value: inspectResult.matchedWooRule ? ((inspectResult.matchedWooRuleType || 'rule') + ': ' + inspectResult.matchedWooRule) : '', mono: true }),
								h(DetailRow, { label: 'Query arg keys', value: Array.isArray(inspectResult.queryArgKeys) && inspectResult.queryArgKeys.length ? inspectResult.queryArgKeys.join(', ') : '' }),
								h(DetailRow, { label: 'Notes', value: inspectResult.simulationNote }),
							]),
							h('div', { className: 'space-y-0' }, [
								h(DetailRow, { label: 'Local URL', value: inspectResult.local ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'Page cache enabled', value: inspectResult.pageCacheEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'WooCommerce safe mode', value: inspectResult.wooSafeModeEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'Cache query strings', value: inspectResult.cacheQueryStrings ? 'Yes' : 'No' }),
								inspectResult.cacheable && inspectResult.cachePaths
									? h('div', { className: 'pt-2' }, [
										h('div', { className: 'text-[11px] tracking-widest text-zinc-500 mb-2' }, 'Expected cache files'),
										h(DetailRow, { label: 'Original HTML', value: inspectResult.cachePaths.orig, mono: true }),
										h(DetailRow, { label: 'WebP HTML', value: inspectResult.cachePaths.webp, mono: true }),
										h(DetailRow, { label: 'AVIF HTML', value: inspectResult.cachePaths.avif, mono: true }),
									])
									: null,
							]),
						])
						: h('div', { className: 'mt-4 text-xs text-zinc-500' }, 'Enter a local URL or path to see the exact cache decision and matching bypass rule.'),
				]
			),

			h(
				Card,
				{
					title: 'Export / Import Settings',
					description: 'Download a JSON backup of UltraCache dashboard settings or restore them on another site.',
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
						h('div', { className: 'text-sm text-white' }, 'Export creates a portable JSON file from the current UltraCache dashboard settings.'),
						h('div', { className: 'text-xs text-zinc-500' }, 'Import applies only supported dashboard options. Generated cache files, drop-ins, and runtime state are rebuilt by the existing save flow.'),
					]),
					h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
						h(Button, { onClick: exportSettingsFile, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Export Settings'),
						h(Button, { onClick: openImportSettingsDialog, disabled: busy, variant: 'light' }, busy ? 'Working…' : 'Import Settings'),
					h(Button, { onClick: resetSettingsToDefaults, disabled: busy, variant: 'light' }, busy ? 'Working…' : 'Reset Settings'),
					h(Button, { onClick: deleteAllPluginDataAndDeactivate, disabled: busy, variant: 'danger' }, busy ? 'Working…' : 'Delete all plugin Data and disable plugin'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500', key: 'hint' }, 'Recommended flow: export from the known-good site, then import into the target site and review Diagnostics once.'),
					h('div', { className: 'mt-2 text-xs text-zinc-500', key: 'delete-hint' }, 'Delete all plugin Data and disable plugin keeps converted media folders. Remove wp-content/cache/ultracache-avif and wp-content/cache/ultracache-webp manually if you want to delete converted media.'),
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
					h('p', { className: 'mb-2 font-bold text-zinc-300' }, 'Quick start & examples'),
					h('div', { className: 'space-y-3' }, [
						h('p', { className: 'm-0' }, 'Enable Page Caching, save settings, then run Flush All Cache once.'),
						h('div', { className: 'space-y-1' }, [
							h('div', { key: 'w1' }, 'Warm Up Homepage HTML Cache: homepage HTML only.'),
							h('div', { key: 'w2' }, 'Warm Up Homepage HTML Cache + Homepage CSS Bundle: homepage HTML plus the homepage CSS bundle.'),
							h('div', { key: 'w3' }, 'Warm Up Menu HTML Cache: URLs found in public menus, HTML only.'),
							h('div', { key: 'w4' }, 'Warm Up Menu HTML Cache + Homepage/Shared/Separate CSS Bundles: follows the selected CSS Bundling Scope.'),
							h('div', { key: 'w5' }, 'Warm Up Full Site HTML Cache: crawl all public crawlable URLs, HTML only.'),
							h('div', { key: 'w6' }, 'Warm Up Full Site HTML Cache + Homepage/Shared/Separate CSS Bundles: follows the selected CSS Bundling Scope.'),
						]),
						h('div', { className: 'space-y-1' }, [
							h('div', { className: 'text-zinc-300 font-semibold', key: 'cli-title' }, 'WP-CLI examples'),
							h('code', { className: 'block text-zinc-400', key: 'cli-1' }, 'wp ultracache --help'),
							h('code', { className: 'block text-zinc-400', key: 'cli-2' }, 'wp ultracache purge'),
							h('code', { className: 'block text-zinc-400', key: 'cli-3' }, 'wp ultracache warm_frontpage_html'),
							h('code', { className: 'block text-zinc-400', key: 'cli-4' }, 'wp ultracache warm_frontpage_html_css'),
							h('code', { className: 'block text-zinc-400', key: 'cli-5' }, 'wp ultracache warm_html_all --purge-first'),
							h('code', { className: 'block text-zinc-400', key: 'cli-6' }, 'wp ultracache warm_html_all_css --purge-first'),
							h('code', { className: 'block text-zinc-400', key: 'cli-7' }, 'wp ultracache cron_warm start'),
							h('code', { className: 'block text-zinc-400', key: 'cli-8' }, 'wp ultracache cron_warm tick'),
						]),
						h('p', { className: 'm-0' }, 'Recommended: keep WooCommerce Safe Mode enabled on shops. Enable Local Google Fonts Optimization only when you want UltraCache to fetch and serve Google Fonts locally. Enable Speculation Rules Prefetch only after cache behavior is stable. Review Cache Diagnostics after testing.'),
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
