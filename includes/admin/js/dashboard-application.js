/* UltraCache Admin - Dashboard application */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before dashboard-application.js.');
	}

	function bootstrap() {
	const adminModules = window.UltraCacheAdmin;
	const core = adminModules && typeof adminModules.get === 'function' ? adminModules.get('core') : null;
	const api = adminModules && typeof adminModules.get === 'function' ? adminModules.get('api') : null;
	const settingsModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('settings') : null;
	const dashboardSettingsActionsModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('dashboardSettingsActions') : null;
	const dashboardSectionsModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('dashboardSections') : null;
	const dashboardDiagnosticsUiModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('dashboardDiagnosticsUi') : null;
	const help = adminModules && typeof adminModules.get === 'function' ? adminModules.get('help') : null;
	const ui = adminModules && typeof adminModules.get === 'function' ? adminModules.get('ui') : null;
	const themeModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('theme') : null;
	const diagnosticsModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('diagnostics') : null;
	const jobsModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('jobs') : null;
	const warmupModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('warmup') : null;
	const mediaModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('media') : null;
	const mediaReplacementModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('mediaReplacement') : null;
	const mediaReplacementUiModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('mediaReplacementUi') : null;
	const cacheModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('cache') : null;
	const lifecycleModule = adminModules && typeof adminModules.get === 'function' ? adminModules.get('lifecycle') : null;
	if (!core || !api || !settingsModule || !dashboardSettingsActionsModule || !dashboardSectionsModule || !dashboardDiagnosticsUiModule || !help || !ui || !themeModule || !diagnosticsModule || !jobsModule || !warmupModule || !mediaModule || !mediaReplacementModule || !mediaReplacementUiModule || !cacheModule || !lifecycleModule) {
		throw new Error('UltraCache admin core/api/settings/dashboard-settings-actions/dashboard-sections/dashboard-diagnostics-ui/help/ui/theme/diagnostics/jobs/warmup/media/media-replacement/media-replacement-ui/cache/lifecycle modules were not loaded.');
	}
	const {
		h,
		useState,
		useEffect,
		useRef,
		__,
		normalizePublicPath,
		joinPublicPath,
		classNames,
		isMobileViewport,
		formatNumber,
		formatCount,
		formatFileCount,
		formatDurationSeconds,
		formatEventTime,
		formatPercent,
		formatLooseTime,
		formatBytes,
		sleep,
		ignoreExpectedAdminFailure,
		reportNonFatalAdminError,
	} = core;
	const { configure: configureApi, apiRequest } = api;
	const {
		configure: configureSettingsModule,
		CRITICAL_SETTING_KEYS,
		PERFORMANCE_PROFILE_ORDER,
		PERFORMANCE_PROFILE_CUSTOM,
		PERFORMANCE_PROFILES,
		getPerformanceProfilePatch,
		splitProfileObjectCachePatch,
		normalizeJavascriptStrategy,
		getJavascriptStrategyValue,
		getJavascriptStrategyPatch,
		getJavascriptStrategyDescription,
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
		mergeUniqueSettingLines,
	} = settingsModule;
	const { createDashboardSettingsActions } = dashboardSettingsActionsModule;
	const { createDashboardSections } = dashboardSectionsModule;
	const { createDashboardDiagnosticsUi } = dashboardDiagnosticsUiModule;
	const { renderLabelWithHelp, getOptionHelpText } = help;
	const {
		configure: configureUi,
		ToastViewport,
		SupportInlineCard,
		SupportModal,
		VersionHelpModal,
		Card,
		SettingsAccordionCard,
		StatCard,
		Button,
		ToggleRow,
		SaveableTextAreaField,
		NumberRow,
		TextField,
		SelectField,
		CustomSelect,
		MultiSelectField,
		DetailRow,
		StatusPill,
		ProgressPanel,
	} = ui;
	const {
		THEME_NATIVE,
		THEME_ULTRACACHE,
		useAdminTheme,
		AdminThemeToggle,
	} = themeModule;
	const {
		configure: configureDiagnosticsModule,
		DeferDelayExclusionsField,
		CssBundleExclusionsDiagnosticsField,
		getNextMediaEtaCheckpoint,
		formatEtaDuration,
		PerformanceProfilerCard,
		sanitizeRuntimeJsScanDisplayUrl,
		buildRuntimeJsScanUrl,
		normalizeRuntimeJsScanResult,
		readPopupRuntimeJsScanSnapshot,
		readPopupRuntimeJsScanNavigationLossSnapshot,
	} = diagnosticsModule;
	const {
		configure: configureJobsModule,
		loadSavedJob,
		saveJob,
		clearSavedJob,
		getJobControls: getSavedJobControls,
		buildProcessState,
		createJobRunner,
	} = jobsModule;
	const {
		configure: configureWarmupModule,
		normalizeCssBundleScopeValue,
		getFirstVisitCssBundleHandling,
		getFirstVisitCssBundlePatch,
		getCssWarmJobType,
		getCssWarmBundleLabel,
		isWarmJobType,
		isWarmCssJobType,
		getWarmScopeForType,
		processJobItem: processWarmJobItem,
		fetchJobBatch: fetchWarmJobBatch,
		createController: createWarmupController,
	} = warmupModule;
	const {
		configure: configureMediaModule,
		processJobItem: processMediaJobItem,
		fetchJobBatch: fetchMediaJobBatch,
		beginManualSession: beginMediaManualSession,
		endManualSession: endMediaManualSession,
		createController: createMediaController,
	} = mediaModule;
	const { useMediaReplacementState, useMediaReplacementWorkflow } = mediaReplacementModule;
	const { createMediaReplacementUi } = mediaReplacementUiModule;
	const {
		getDefaultVarnishServersForMode,
		VarnishCard,
		OPcacheCard,
		APCuCard,
		getExternalCacheLayer,
		ExternalCacheCard,
		LiteSpeedCard,
		ExternalCacheFlushSettingsCard,
		RedisCard,
	} = cacheModule;
	const {
		useDashboardState,
		useDashboardLifecycle,
		useProcessEta,
		findDashboardRoot,
		mountDashboard,
	} = lifecycleModule;

	configureUi({
		getOptionHelpText: (...args) => getOptionHelpText(...args),
		renderLabelWithHelp: (...args) => renderLabelWithHelp(...args),
		mergeUniqueSettingLines: (...args) => mergeUniqueSettingLines(...args),
		splitWarmSourceList: (...args) => splitWarmSourceList(...args),
		joinWarmSourceList: (...args) => joinWarmSourceList(...args),
	});

	const rootEl = findDashboardRoot(document);
	if (!rootEl) {
		return;
	}

	const ultracache = window.ultracacheData || {};
	const canManageInfrastructure = !!ultracache.canManageInfrastructure;
	configureApi({
		restBase: ultracache.restBase,
		restNonce: ultracache.restNonce,
		fetch: (typeof window !== 'undefined' && window.fetch) ? window.fetch.bind(window) : null,
	});
	const initialSettings = ultracache.settings || {};
	const initialStats = ultracache.stats || {};
	const initialMediaFileCounts = ultracache.mediaFileCounts || { total: 0, avif: 0, webp: 0, initialized: false, needsRecount: true };
	const avifSupport = ultracache.avifSupport || { supported: false };
	const initialDiagnostics = ultracache.diagnostics || initialStats.diagnostics || {};
	const initialDefaults = ultracache.defaults || {};
	let crawlScopeSummary = ultracache.crawlScopeSummary || {};
	const initialWarmupGeneration = Math.max(0, Number(ultracache.warmupGeneration || 0));
	const frontendProbeUrl = ultracache.frontendProbeUrl || '/';
	const ultracachePublicPaths = (ultracache.publicPaths && typeof ultracache.publicPaths === 'object') ? ultracache.publicPaths : {};
	const pluginsPublicPath = normalizePublicPath(ultracachePublicPaths.plugins || '');
	const themesPublicPaths = Array.isArray(ultracachePublicPaths.themes) ? ultracachePublicPaths.themes.map(normalizePublicPath).filter(Boolean) : [];
	const uploadsPublicPath = normalizePublicPath(ultracachePublicPaths.uploads || '');
	const generatedAssetsPublicPath = normalizePublicPath(ultracachePublicPaths.generatedAssets || '');
	const woocommercePublicPath = normalizePublicPath(ultracachePublicPaths.woocommerce || '').toLowerCase();
	const jqueryPublicPath = normalizePublicPath(ultracachePublicPaths.jquery || '');
	const wpUtilPublicPath = normalizePublicPath(ultracachePublicPaths.wpUtil || '');
	const apiFetchPublicPath = normalizePublicPath(ultracachePublicPaths.apiFetch || '');
	configureSettingsModule({
		version: ultracache.version || '',
		siteOrigin: (window.location && window.location.origin) ? window.location.origin : '',
		woocommercePublicPath,
	});
	configureDiagnosticsModule({
		ultracache,
		pluginsPublicPath,
	});

	const CLEAR_NOTICE_DELAY = 4200;
	const SYSTEM_NOTICE_DELAY = 7000;
	const SYSTEM_NOTICE_COOLDOWN = 24 * 60 * 60 * 1000;
	const STATS_REFRESH_INTERVAL = 60000;
	const ACTION_QUEUE_POLL_DELAY = 750;
	const ACTION_QUEUE_MAX_POLLS = 480;
	const JOB_STORAGE_KEY = 'ultracache-dashboard-job-state-v3';
	const DEFAULT_QUEUE_BATCH_SIZE = 100;
	const MAX_WARM_ITEM_RETRIES = 2;
	const MEDIA_UNIT_DELAY_MS = 250;

	configureJobsModule({
		storageKey: JOB_STORAGE_KEY,
		defaultBatchSize: DEFAULT_QUEUE_BATCH_SIZE,
		getNextEtaCheckpoint: getNextMediaEtaCheckpoint,
	});
	configureWarmupModule({
		maxItemRetries: MAX_WARM_ITEM_RETRIES,
	});
	configureMediaModule({
		unitDelayMs: MEDIA_UNIT_DELAY_MS,
	});

	const {
		CacheStatisticsPanel,
		ActivitySummaryCard,
	} = createDashboardSections({
		h,
		__,
		classNames,
		formatNumber,
		formatPercent,
		formatLooseTime,
		StatCard,
		StatusPill,
		hasDashboardStatsCounters,
		formatStatsNumber,
		formatStatsPercent,
		getStatsRefreshHint,
		formatObjectEntries,
		getObjectEntriesHint,
	});

	const {
		DiagnosticsCard,
		AdvancedDiagnosticsCard,
		LcpDiagnosticsCard,
		FontPipelineDiagnosticsPanel,
		SettingsTransparencyPanel,
		SecurityCorrectnessPanel,
	} = createDashboardDiagnosticsUi({
		h,
		useState,
		useEffect,
		__,
		formatNumber,
		formatBytes,
		formatFileCount,
		formatCount,
		formatLooseTime,
		formatDurationSeconds,
		formatEventTime,
		getCssCleanupGraceLabel,
		getCssCleanupDeleteLimitLabel,
		DetailRow,
		StatusPill,
		ToggleRow,
		SelectField,
	});


	async function requestHtmlCompressionCapabilities() {
		const response = await apiRequest('compression_capabilities', {});
		const source = response && response.capabilities && typeof response.capabilities === 'object'
			? response.capabilities
			: {};

		return {
			ready: true,
			probed: true,
			serverCompression: !!source.serverManaged,
			serverGzip: !!source.serverGzip,
			serverBrotli: !!source.serverBrotli,
			gzipAvailable: !!source.gzipAvailable,
			brotliAvailable: !!source.brotliAvailable,
			blocked: !!source.blocked,
			brokenGzip: !!source.brokenGzip,
			brokenBrotli: !!source.brokenBrotli,
			message: source.message ? String(source.message) : '',
		};
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
			typeof stats.cacheSizeHuman !== 'undefined'
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
			if (normalized === 'sqlite') return 'SQLite';
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
		if (stats && (typeof stats.objectCacheRedisEntries !== 'undefined' || typeof stats.objectCacheApcuEntries !== 'undefined' || typeof stats.objectCacheSqliteEntries !== 'undefined' || typeof stats.objectCacheDiskEntries !== 'undefined')) {
			parts.push('Redis ' + formatNumber(stats.objectCacheRedisEntries || 0));
			parts.push('APCu ' + formatNumber(stats.objectCacheApcuEntries || 0));
			parts.push('SQLite ' + formatNumber(stats.objectCacheSqliteEntries || 0));
			parts.push('Disk ' + formatNumber(stats.objectCacheDiskEntries || 0));
		}
		if (!parts.length) {
			parts.push('Object cache backend entries');
		}
		return parts.join(' · ');
	}



	async function processJobItem(type, item, shouldCancel, manualSessionToken = '') {
		if (isWarmJobType(type)) {
			return processWarmJobItem(type, item, shouldCancel, manualSessionToken);
		}
		return processMediaJobItem(type, item, shouldCancel, manualSessionToken);
	}

	async function fetchJobBatch(type, cursor, limit, scope) {
		if (isWarmJobType(type)) {
			return fetchWarmJobBatch(type, cursor, limit, scope);
		}
		return fetchMediaJobBatch(cursor, limit);
	}





			function DelayedJsAutostartEventsField({ label, description, settings, onChange, disabled, tooltip }) {
		const options = [
			{ key: 'delayedJsAutostartAfterLoadEnabled', label: __('After page load', 'ultracache') },
			{ key: 'delayedJsAutostartMousemoveEnabled', label: __('Mouse move', 'ultracache') },
			{ key: 'delayedJsAutostartScrollEnabled', label: __('Scroll', 'ultracache') },
			{ key: 'delayedJsAutostartKeyboardEnabled', label: __('Keyboard', 'ultracache') },
			{ key: 'delayedJsAutostartTouchPointerEnabled', label: __('Touch / pointer', 'ultracache') },
			{ key: 'delayedJsAutostartClickEnabled', label: __('Click', 'ultracache') },
		];
		const selected = options.filter((option) => !!settings[option.key]);
		const helpText = getOptionHelpText(label, description, tooltip);
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'block text-sm font-medium text-white' }, renderLabelWithHelp(label, helpText)) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mt-1 mb-2' }, description) : null,
			h('details', { className: classNames('uc-switch-dropdown', disabled ? 'opacity-60 pointer-events-none' : '') }, [
				h('summary', { className: 'uc-switch-dropdown-summary' }, [
					h('span', { key: 'label' }, selected.length ? (selected.length + ' trigger' + (selected.length === 1 ? '' : 's') + ' selected') : __('No event triggers selected', 'ultracache')),
					h('span', { key: 'icon', className: 'uc-select-icon', 'aria-hidden': 'true' }, '▾'),
				]),
				h('div', { className: 'uc-switch-dropdown-panel' }, options.map((option) => {
					const checked = !!settings[option.key];
					return h('label', { className: 'uc-switch-dropdown-row', key: option.key }, [
						h('span', { className: 'uc-switch-dropdown-text' }, option.label),
						h('span', { className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : '') }, [
							h('input', { type: 'checkbox', checked: checked, disabled: !!disabled, onChange: (event) => onChange(option.key, event.target.checked) }),
							h('span', { className: 'slider' }),
						]),
					]);
				})),
			]),
			h('div', { className: 'text-[11px] text-zinc-500 mt-2' }, selected.length ? ('Selected: ' + selected.length) : __('No event triggers selected. Delayed JS will release only by fallback timer.', 'ultracache')),
		]);
	}





	function App() {
		const {
			settings, setSettings,
			stats, setStats,
			mediaFileCounts, setMediaFileCounts,
			diagnostics, setDiagnostics,
			setCrawlScopeVersion,
			browserCompressionProbe, setBrowserCompressionProbe,
			compressionProbeBusy, setCompressionProbeBusy,
			busy, setBusy,
			mediaSupport, setMediaSupport,
			asyncActions, setAsyncActions,
			toasts,
			isMobile, setIsMobile,
			supportModalOpen, setSupportModalOpen,
			infoAccordionsOpen, setInfoAccordionsOpen,
			safeDelayAppendRequest, setSafeDelayAppendRequest,
			scannerAppendSequenceRef,
			advancedForm, setAdvancedForm,
			varnishForm, setVarnishForm,
			redisForm, setRedisForm,
			inspectUrl, setInspectUrl,
			inspectBusy, setInspectBusy,
			inspectResult, setInspectResult,
			performanceProfile, setPerformanceProfile,
			cssDiagnosticsUrl, setCssDiagnosticsUrl,
			cssDiagnosticsBusy, setCssDiagnosticsBusy,
			homepageHtmlBusy, setHomepageHtmlBusy,
			homepageHtmlCssBusy, setHomepageHtmlCssBusy,
			allUrlsCssBusy, setAllUrlsCssBusy,
			menuUrlsCssBusy, setMenuUrlsCssBusy,
			savedJob, setSavedJob,
			warmupGeneration, setWarmupGeneration,
			warmupGenerationRef,
			cancelRequestedRef,
			manualMediaSessionTokenRef,
			importFileInputRef,
			statsRefreshInFlightRef,
			settingsRef,
			committedSettingsRef,
			lastSettingsSavePromiseRef,
			googleFontsAutoRebuildQueuedRef,
			queuedActionKeysRef,
			uiActionQueueRef,
			uiActionQueueDepthRef,
			uiActionSequenceRef,
			queuedDashboardPayloadRef,
			suppressBeforeUnloadRef,
			uiActionQueueCount, setUiActionQueueCount,
			compressionSyncRef,
			manualObjectCacheTestRef,
			mediaQueueStatus, setMediaQueueStatus,
			mediaBackgroundControlBusy, setMediaBackgroundControlBusy,
			process, setProcess,
			dismissToast,
			pushToast,
		} = useDashboardState({
			initialSettings,
			initialStats,
			initialMediaFileCounts,
			initialDiagnostics,
			initialMediaSupport: avifSupport,
			initialWarmupGeneration,
			frontendProbeUrl: (typeof ultracache !== 'undefined' && ultracache && ultracache.frontendProbeUrl) ? String(ultracache.frontendProbeUrl || '') : '',
			clearNoticeDelay: CLEAR_NOTICE_DELAY,
			isMobileViewport,
			loadSavedJob,
			getDefaultScheduledWarmLimit,
			getDefaultVarnishServersForMode,
		});

		const {
			isUltraCacheTheme,
			saving: adminThemeSaving,
			setTheme: setAdminTheme,
		} = useAdminTheme({
			initialTheme: ultracache.adminTheme || THEME_NATIVE,
			apiRequest,
			rootElement: rootEl,
			documentObject: document,
			onError: (error) => pushToast({
				type: 'error',
				text: error && error.message ? error.message : __('The UltraCache admin theme could not be saved.', 'ultracache'),
			}),
		});

		const etaText = useProcessEta(process, formatEtaDuration);
		const [mediaConversionTestBusy, setMediaConversionTestBusy] = useState(false);
		const [mediaConversionTestResult, setMediaConversionTestResult] = useState(null);
		const [mediaConversionTestRevision, setMediaConversionTestRevision] = useState(0);
		const [mediaConversionTestModalOpen, setMediaConversionTestModalOpen] = useState(false);
		const [mediaConversionLightboxItem, setMediaConversionLightboxItem] = useState(null);
		const [mediaRegenerateExistingEnabled, setMediaRegenerateExistingEnabled] = useState(false);
		const [lcpDiagnosticsBusyKey, setLcpDiagnosticsBusyKey] = useState('');
		const [lcpDiagnosticsQueryBusy, setLcpDiagnosticsQueryBusy] = useState(false);
		const [versionHelpModalOpen, setVersionHelpModalOpen] = useState(false);

		useEffect(() => {
			const trigger = document.getElementById('ultracache-version-help-trigger');
			if (!trigger) {
				return undefined;
			}

			const openVersionHelp = () => {
				setSupportModalOpen(false);
				setVersionHelpModalOpen(true);
			};

			trigger.addEventListener('click', openVersionHelp);
			return () => trigger.removeEventListener('click', openVersionHelp);
		}, [setSupportModalOpen]);

		useEffect(() => {
			const trigger = document.getElementById('ultracache-version-help-trigger');
			if (trigger) {
				trigger.setAttribute('aria-expanded', versionHelpModalOpen ? 'true' : 'false');
			}
		}, [versionHelpModalOpen]);

		const mediaLibraryReplacementState = useMediaReplacementState(
			(ultracache && ultracache.mediaLibraryReplacementStatus) ? ultracache.mediaLibraryReplacementStatus : null
		);
		const {
			mediaLibraryReplacementBusy,
			setMediaLibraryReplacementBusy,
			mediaLibraryReplacementStatus,
			setMediaLibraryReplacementStatus,
			mediaLibraryReplacementPreview,
			setMediaLibraryReplacementPreview,
			mediaLibraryReplacementPreviewOpen,
			setMediaLibraryReplacementPreviewOpen,
			mediaLibraryReplacementDbPreview,
			setMediaLibraryReplacementDbPreview,
			mediaLibraryReplacementDbPreviewOpen,
			setMediaLibraryReplacementDbPreviewOpen,
			mediaLibraryReplacementBlockers,
			mediaLibraryReplacementBlockersOpen,
			setMediaLibraryReplacementBlockersOpen,
			mediaLibraryReplacementBlockerDecisions,
			setMediaLibraryReplacementBlockerDecisions,
			mediaLibraryReplacementCleanupPreview,
			setMediaLibraryReplacementCleanupPreview,
			mediaLibraryReplacementCleanupPreviewOpen,
			setMediaLibraryReplacementCleanupPreviewOpen,
			mediaLibraryReplacementWarningAction,
			mediaLibraryReplacementWarningConfirmation,
			setMediaLibraryReplacementWarningConfirmation,
		} = mediaLibraryReplacementState;

		const dashboardSettingsActions = createDashboardSettingsActions({
			CRITICAL_SETTING_KEYS,
			PERFORMANCE_PROFILES,
			getPerformanceProfilePatch,
			splitProfileObjectCachePatch,
			buildSettingsExportPayload,
			getTransferableSettingsFromImport,
			triggerFileDownload,
			apiRequest,
			applyDashboardPayload,
			settingsRef,
			committedSettingsRef,
			lastSettingsSavePromiseRef,
			setSettings,
			setAdvancedForm,
			setRedisForm,
			setInspectResult,
			setBusy,
			pushToast,
			sleep,
			enqueueUiOperation,
			getProfileQueryAllowlistPatch,
			getProfileObjectCachePatch,
			populateDeferDelayExclusionDefaults,
			advancedForm,
			settings,
			ultracache,
			busy,
			importFileInputRef,
			initialDefaults,
			__,
		});
		const {
			isCriticalSettingsPatch,
			getSettingsResponseKeysForPatch,
			applyServerSettings,
			applySettingsSaveResponse,
			saveSettingsPatch,
			waitForSettingsSaveToSettle,
			syncQueuedSettingsBeforeAction,
			queueSettingsPatch,
			applyPerformanceProfile,
			updateAdvancedField,
			saveAdvancedSettings,
			exportSettingsFile,
			openImportSettingsDialog,
			importSettingsFile,
			resetSettingsToDefaults,
		} = dashboardSettingsActions;

		const cacheController = cacheModule.createController({
			redisForm,
			varnishForm,
			settingsRef,
			busy,
			stats,
			setVarnishForm,
			setRedisForm,
			queueSettingsPatch,
			enqueueUiOperation,
			saveSettingsPatch,
			applyDashboardPayload,
			mergeObjectCacheTestResult,
			queueDashboardAction,
			refreshStats,
			pushToast,
			setBusy,
			setStats,
			setDiagnostics,
			mergeManualObjectCacheTestIntoDiagnostics,
			mergeVarnishTestResult,
			stageDashboardPayloadForQueue,
		});
		const {
			updateVarnishEnabled,
			updateVarnishField,
			updateRedisField,
			saveRedisSettings,
			testObjectCacheBackend,
			flushObjectCache,
			scrollToCacheConflictReview,
			recheckCacheConflicts,
			removeConflictingCacheDropins,
			runFullObjectCount,
			saveVarnishSettings,
			runVarnishDiscovery,
			runVarnishTest,
			runVarnishPerformanceSnapshot,
			runVarnishFlushAll,
			runVarnishFlushEntireHost,
			flushOpcache,
			flushApcu,
			runLiteSpeedTest,
			flushLiteSpeed,
			flushNginx,
			redetectExternalCaches,
			purgeCache,
		} = cacheController;

		useDashboardLifecycle({
			settings,
			diagnostics,
			process,
			busy,
			asyncActions,
			uiActionQueueCount,
			toasts,
			isMobile,
			settingsRef,
			suppressBeforeUnloadRef,
			statsRefreshInFlightRef,
			setStats,
			setIsMobile,
			setSupportModalOpen,
			setAdvancedForm,
			setVarnishForm,
			setRedisForm,
			pushToast,
			dismissToast,
			apiRequest,
			applyDashboardPayload,
			hasActiveQueuedDashboardAction,
			scrollToCacheConflictReview,
			refreshStats,
			getStatsDisabledClientPayload,
			isMobileViewport,
			getDefaultScheduledWarmLimit,
			getDefaultVarnishServersForMode,
			clearNoticeDelay: CLEAR_NOTICE_DELAY,
			systemNoticeDelay: SYSTEM_NOTICE_DELAY,
			systemNoticeCooldown: SYSTEM_NOTICE_COOLDOWN,
			statsRefreshInterval: STATS_REFRESH_INTERVAL,
		});


		async function queryLcpObservations(query) {
			if (lcpDiagnosticsQueryBusy) {
				return null;
			}

			setLcpDiagnosticsQueryBusy(true);
			try {
				return await apiRequest('lcp_observations_query', query && typeof query === 'object' ? query : {});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('The LCP observations could not be loaded.', 'ultracache') });
				return null;
			} finally {
				setLcpDiagnosticsQueryBusy(false);
			}
		}

		async function queryLcpObservationDetail(pageHash) {
			if (!pageHash) {
				return null;
			}

			try {
				return await apiRequest('lcp_observation_detail', { pageHash: String(pageHash) });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('The selected LCP URL details could not be loaded.', 'ultracache') });
				return null;
			}
		}

		async function saveLcpObservationManualSelector(pageHash, manualSelector) {
			const normalizedHash = String(pageHash || '');
			if (!normalizedHash || lcpDiagnosticsBusyKey) {
				return null;
			}

			setLcpDiagnosticsBusyKey(normalizedHash + ':manual-selector');
			try {
				const response = await apiRequest('lcp_observation_manual_selector', {
					pageHash: normalizedHash,
					manualSelector: String(manualSelector || ''),
				});
				pushToast({ type: response && response.refreshQueued === false ? 'warning' : 'success', text: response && response.message ? response.message : __('Manual LCP selector updated.', 'ultracache') });
				return response;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('The Manual LCP selector could not be saved.', 'ultracache') });
				return null;
			} finally {
				setLcpDiagnosticsBusyKey('');
			}
		}

		async function runLcpObservationAction(record, action) {
			const recordId = record && record.id ? Number(record.id) : 0;
			const normalizedAction = String(action || '');
			if (!recordId || ['forget', 'relearn', 'refresh'].indexOf(normalizedAction) === -1 || lcpDiagnosticsBusyKey) {
				return null;
			}

			const busyKey = String(recordId) + ':' + normalizedAction;
			setLcpDiagnosticsBusyKey(busyKey);
			try {
				const payload = {
					recordId,
					action: normalizedAction,
				};
				const response = await apiRequest('lcp_observation_action', payload);
				pushToast({ type: response && response.refreshQueued === false ? 'warning' : 'success', text: response && response.message ? response.message : __('LCP diagnostics updated.', 'ultracache') });
				return response;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('The LCP diagnostics action failed.', 'ultracache') });
				return null;
			} finally {
				setLcpDiagnosticsBusyKey('');
			}
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

		function hasActiveQueuedDashboardAction() {
			return uiActionQueueDepthRef.current > 0 || Object.keys(queuedActionKeysRef.current || {}).some((key) => !!queuedActionKeysRef.current[key]);
		}

		function hasDashboardWorkInProgress() {
			return hasActiveQueuedDashboardAction();
		}

		function mergeManualObjectCacheTestIntoDiagnostics(diagnosticsPayload) {
			const manual = manualObjectCacheTestRef.current;
			if (!manual || !diagnosticsPayload || typeof diagnosticsPayload !== 'object') {
				return diagnosticsPayload;
			}
			const next = Object.assign({}, diagnosticsPayload || {});
			const objectCache = Object.assign({}, next.objectCache || {});
			const backend = String(manual.backend || '').toLowerCase();
			if (['redis', 'apcu', 'sqlite', 'disk'].indexOf(backend) !== -1) {
				objectCache[backend] = Object.assign({}, objectCache[backend] || {}, manual.result || {});
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

		function stripSettingsFromDashboardPayload(payload) {
			if (!payload || typeof payload !== 'object') {
				return payload;
			}
			const next = Object.assign({}, payload);
			delete next.settings;
			if (next.result && typeof next.result === 'object') {
				next.result = stripSettingsFromDashboardPayload(next.result);
			}
			return next;
		}

		function applyDashboardPayloadNow(payload, options) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			const opts = options || {};

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
				ultracache.crawlScopeSummary = responseCrawlScopeSummary;
				setCrawlScopeVersion((version) => version + 1);
			}

			const responseWarmupGeneration = typeof payload.warmupGeneration !== 'undefined' ? payload.warmupGeneration : (payload.result && typeof payload.result.warmupGeneration !== 'undefined' ? payload.result.warmupGeneration : null);
			if (null !== responseWarmupGeneration) {
				setCurrentWarmupGeneration(responseWarmupGeneration);
			}

			const responseSettings = payload.settings || (payload.result && payload.result.settings);
			if (responseSettings && opts.fullReplaceSettings && !opts.skipSettings) {
				applyServerSettings(responseSettings, { fullReplace: true });
			}
		}

		function applyDashboardPayload(payload, options) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			const opts = options || {};
			const nextPayload = opts.skipSettings ? stripSettingsFromDashboardPayload(payload) : payload;
			if (uiActionQueueDepthRef.current > 0) {
				stageDashboardPayloadForQueue(nextPayload);
				return;
			}
			applyDashboardPayloadNow(nextPayload, opts);
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

		function mergeObjectCacheTestResult(result) {
			if (!result || typeof result !== 'object') {
				return;
			}

			const backend = String(result.backend || '').toLowerCase();
			const backendResult = Object.assign({}, result || {});
			delete backendResult.diagnostics;
			delete backendResult.settings;
			delete backendResult.stats;
			manualObjectCacheTestRef.current = {
				backend: backend,
				result: backendResult,
				payloadProbe: result && result.payloadProbe ? result.payloadProbe : null,
			};

			if (uiActionQueueDepthRef.current > 0) {
				return;
			}

			setDiagnostics((current) => {
				const next = Object.assign({}, current || {});
				const objectCache = Object.assign({}, next.objectCache || {});
				if (['redis', 'apcu', 'sqlite', 'disk'].indexOf(backend) !== -1) {
					objectCache[backend] = Object.assign({}, objectCache[backend] || {}, backendResult);
				}
				if (result && result.payloadProbe) {
					objectCache.manualPayloadProbe = result.payloadProbe;
				}
				objectCache.manualBackendTest = Object.assign({}, backendResult);
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
				const responseBasicTest = result.diagnostics && result.diagnostics.varnish && result.diagnostics.varnish.basicTest
					? result.diagnostics.varnish.basicTest
					: result;
				const basicTest = Object.assign({}, responseBasicTest || {});
				delete basicTest.diagnostics;
				delete basicTest.settings;
				delete basicTest.stats;
				varnish.basicTest = basicTest;
				next.varnish = varnish;
				return next;
			});
		}

		function applyMediaQueueStatus(payload) {
			if (!payload || typeof payload !== 'object') {
				return;
			}
			setMediaQueueStatus(payload);
			if (payload.mediaFileCounts && typeof payload.mediaFileCounts === 'object') {
				setMediaFileCounts(payload.mediaFileCounts);
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

		async function refreshMediaQueueStatus(recountFiles = false) {
			const response = await apiRequest('media_queue_status', { media_format: getSelectedMediaQueueFormat(), recount_files: !!recountFiles });
			applyMediaQueueStatus(response);
			return response;
		}
		function enqueueUiOperation(key, label, runner, options) {
			const actionKey = key || ('operation_' + Date.now());
			const readableLabel = label || formatDashboardActionLabel(actionKey);
			const sequence = ++uiActionSequenceRef.current;
			const toastId = 'ultracache-fifo-' + sequence + '-' + String(actionKey).replace(/[^a-z0-9_-]+/gi, '-');
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
				js_dependency_scan: 'HTML JS Dependency Scan',
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
					} catch (error) {
						reportNonFatalAdminError('dashboard.queued-action.stats-refresh', error, { severity: 'debug', dedupeKey: 'dashboard.queued-action.stats-refresh', dedupeWindowMs: 30000 });
					}
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
			const targetUrl = String(url || '').trim() || ((typeof ultracache !== 'undefined' && ultracache && ultracache.frontendProbeUrl) ? String(ultracache.frontendProbeUrl || '') : '');
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


		async function probeHtmlCompressionOptions() {
			setCompressionProbeBusy(true);
			setBrowserCompressionProbe({
				ready: false,
				probed: false,
				serverCompression: false,
				serverGzip: false,
				serverBrotli: false,
				gzipAvailable: false,
				brotliAvailable: false,
				blocked: false,
				brokenGzip: false,
				brokenBrotli: false,
				message: __('Please wait… Checking server compression support.', 'ultracache'),
			});

			try {
				const result = await requestHtmlCompressionCapabilities();
				setBrowserCompressionProbe(result);

				const currentMode = getHtmlCompressionDeliveryValue(settingsRef.current || {});
				const currentModeUnavailable =
					('gzip' === currentMode && !result.gzipAvailable)
					|| ('brotli' === currentMode && !result.brotliAvailable);
				if ((result.serverCompression || result.blocked || currentModeUnavailable) && 'off' !== currentMode) {
					await queueSettingsPatch(getHtmlCompressionDeliveryPatch('off'));
					pushToast({
						type: 'warning',
						text: result.message || __('The selected HTML compression mode is not available and was switched off.', 'ultracache'),
					});
				}

				return result;
			} catch (error) {
				const message = error && error.message ? error.message : __('Unable to check server compression support.', 'ultracache');
				const failed = {
					ready: true,
					probed: true,
					serverCompression: false,
					serverGzip: false,
					serverBrotli: false,
					gzipAvailable: false,
					brotliAvailable: false,
					blocked: true,
					brokenGzip: false,
					brokenBrotli: false,
					message,
				};
				setBrowserCompressionProbe(failed);
				pushToast({ type: 'error', text: message });
				return failed;
			} finally {
				setCompressionProbeBusy(false);
			}
		}

		async function updateHtmlCompressionDelivery(value) {
			const mode = normalizeHtmlCompressionDelivery(value);
			if ('off' === mode) {
				return queueSettingsPatch(getHtmlCompressionDeliveryPatch('off'));
			}

			let capabilities = browserCompressionProbe;
			if (!capabilities || !capabilities.probed) {
				capabilities = await probeHtmlCompressionOptions();
			}

			if (!capabilities || capabilities.serverCompression || capabilities.blocked) {
				return queueSettingsPatch(getHtmlCompressionDeliveryPatch('off'));
			}
			if ('gzip' === mode && !capabilities.gzipAvailable) {
				pushToast({ type: 'warning', text: __('Gzip compression is not available on this server.', 'ultracache') });
				return null;
			}
			if ('brotli' === mode && !capabilities.brotliAvailable) {
				pushToast({ type: 'warning', text: __('Brotli compression is not available on this server.', 'ultracache') });
				return null;
			}

			return queueSettingsPatch(getHtmlCompressionDeliveryPatch(mode));
		}


		function appendScannerPatternToSafeDelayDraft(value) {
			const pattern = String(value || '').trim();
			if (!pattern) {
				return;
			}
			scannerAppendSequenceRef.current += 1;
			setSafeDelayAppendRequest({ id: scannerAppendSequenceRef.current, value: pattern });
		}

		function updateSetting(key, value) {
			if (key === 'liteSpeedCacheEnabled') {
				queueSettingsPatch({
					liteSpeedCacheEnabled: !!value,
					apacheStaticHtmlDeliveryEnabled: value ? false : !!(settingsRef.current || {}).apacheStaticHtmlDeliveryEnabled,
				});
				return;
			}
			if (key === 'apacheStaticHtmlDeliveryEnabled') {
				queueSettingsPatch({
					apacheStaticHtmlDeliveryEnabled: !!value,
					liteSpeedCacheEnabled: value ? false : !!(settingsRef.current || {}).liteSpeedCacheEnabled,
				});
				return;
			}
			if (key === 'objectCacheEnabled') {
				const currentForm = redisForm || {};
				const currentSettings = settingsRef.current || {};
				const backend = String(currentForm.objectCacheBackend || currentSettings.objectCacheBackend || 'redis').toLowerCase();
				const fallback = String(currentForm.objectCacheFallbackBackend || currentSettings.objectCacheFallbackBackend || 'apcu').toLowerCase();
				queueSettingsPatch(Object.assign({
					objectCacheEnabled: !!value,
					objectCacheBackend: ['redis', 'apcu', 'sqlite', 'disk'].indexOf(backend) !== -1 ? backend : 'redis',
					objectCacheFallbackBackend: ['none', 'runtime'].indexOf(fallback) !== -1 ? 'none' : (['apcu', 'sqlite', 'disk'].indexOf(fallback) !== -1 ? fallback : 'apcu'),
				}, backend === 'apcu' ? { flushAllIncludeApcu: true } : {}));
				return;
			}
			if (key === 'mediaOutputMode') {
				const outputMode = String(value || 'webp').toLowerCase() === 'avif' ? 'avif' : 'webp';
				queueSettingsPatch({
					mediaOutputMode: outputMode,
					mediaFallbackFormat: outputMode === 'avif' ? 'webp' : 'original',
				});
				return;
			}
			if (key === 'mediaFallbackFormat') {
				const outputMode = String((settingsRef.current || {}).mediaOutputMode || 'webp').toLowerCase() === 'avif' ? 'avif' : 'webp';
				const fallbackFormat = outputMode === 'avif' && String(value || '').toLowerCase() === 'webp' ? 'webp' : 'original';
				queueSettingsPatch({ mediaFallbackFormat: fallbackFormat });
				return;
			}
			queueSettingsPatch({ [key]: value });
		}

		function scheduleGoogleFontsAutoRebuildAfterSave() {
			if (googleFontsAutoRebuildQueuedRef.current) {
				return;
			}
			googleFontsAutoRebuildQueuedRef.current = true;
			const toastId = 'ultracache-google-fonts-auto-rebuild';
			pushToast({
				id: toastId,
				type: 'info',
				title: 'Local Google Fonts',
				text: 'Google Fonts rebuild will start automatically after settings save.',
				persistent: true,
			});
			window.setTimeout(async () => {
				try {
					const saveSettled = await waitForSettingsSaveToSettle();
					if (!saveSettled) {
						throw new Error('Settings are still saving. Google Fonts rebuild was not queued yet.');
					}
					const queued = await apiRequest('queue_action', {
						action: 'google_fonts_rebuild_cache',
						params: { clear: false },
					});
					const job = queued && queued.job ? queued.job : null;
					if (!job || !job.id) {
						throw new Error('Google Fonts rebuild job was not created.');
					}
					if (job.direct && ['done', 'failed'].indexOf(job.status) !== -1) {
						if (job.result) {
							applyDashboardPayload(job.result);
						}
						if (job.status !== 'done') {
							throw new Error(job.message || 'Google Fonts rebuild failed.');
						}
						pushToast({
							id: toastId,
							type: 'success',
							title: 'Local Google Fonts',
							text: 'Google Fonts rebuild completed.',
						});
						return;
					}
					apiRequest('queue_run', { id: job.id }).catch((error) => {
						pushToast({
							id: toastId,
							type: 'error',
							title: 'Local Google Fonts',
							text: error && error.message ? error.message : 'Google Fonts background rebuild failed to start.',
						});
					});
					pushToast({
						id: toastId,
					type: 'success',
					title: 'Local Google Fonts',
					text: 'Google Fonts rebuild queued and started in the background.',
					});
				} catch (error) {
					pushToast({
						id: toastId,
						type: 'error',
						title: 'Local Google Fonts',
						text: error && error.message ? error.message : 'Google Fonts rebuild could not be queued.',
					});
				} finally {
					window.setTimeout(() => {
						googleFontsAutoRebuildQueuedRef.current = false;
					}, 10000);
				}
			}, 250);
		}

		function updateGoogleFontsLocalOptimization(value) {
			queueSettingsPatch({ googleFontsLocalOptimizationEnabled: !!value });
			if (!!value) {
				scheduleGoogleFontsAutoRebuildAfterSave();
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

		function updateJavascriptStrategy(value) {
			const strategy = normalizeJavascriptStrategy(value);
			queueSettingsPatch(getJavascriptStrategyPatch(strategy));
			if (strategy !== 'defer') {
				return;
			}

			window.setTimeout(async () => {
				const current = settingsRef.current && typeof settingsRef.current.deferJsExcludeList !== 'undefined' ? settingsRef.current.deferJsExcludeList : '';
				const merged = await populateDeferDelayExclusionDefaults(current);
				if (merged !== null && String(merged || '') !== String(current || '')) {
					queueSettingsPatch({ deferJsExcludeList: String(merged || '') });
				}
			}, 0);
		}

		async function updateDelayIconFonts(value) {
			const enabled = !!value;
			queueSettingsPatch({
				delayIconFontsEnabled: enabled,
				delayIconFontsAutoDetectEnabled: false,
			});
			if (!enabled) {
				return;
			}

			const response = await scanFrontpageFontPatterns();
			if (!response || !Array.isArray(response.delayIconFontsList) || !response.delayIconFontsList.length) {
				pushToast({ type: 'warning', text: __("Delay icon fonts is enabled, but no likely icon font patterns were detected on the front page.", 'ultracache') });
				return;
			}

			const currentDraft = (settingsRef.current && settingsRef.current.delayIconFontsList) || '';
			const merged = mergeUniqueSettingLines(currentDraft, response.delayIconFontsList.join('\n'));
			if (merged.added) {
				queueSettingsPatch({ delayIconFontsList: merged.value });
				pushToast({ type: 'success', text: 'Delay icon fonts added ' + merged.added + ' front-page icon font pattern(s).' });
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

		async function populateDeferDelayExclusionDefaults(currentDraft) {
			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			let defaults = ultracache && typeof ultracache.jsDelayDeferRecommendedExclusions !== 'undefined'
				? String(ultracache.jsDelayDeferRecommendedExclusions || '')
				: '';
			if (!defaults.trim() && Object.prototype.hasOwnProperty.call(defaultsPayload, 'deferJsExcludeList')) {
				defaults = String(defaultsPayload.deferJsExcludeList || '');
			}
			// 2.58.90.03.06: Append Broad WP Dependency Preset is a manual
			// compatibility preset, not a scanner/discovery result. Slider, theme, WooCommerce, Elementor,
			// tracking, popup, and form fragments are left to Scan/Runtime Scan
			// or manual user additions so defaults do not over-protect heavy JS.
			if (!defaults.trim()) {
				pushToast({ type: 'warning', text: __("No recommended JS Do Not Defer or Delay defaults are defined.", 'ultracache') });
				return String(currentDraft || '');
			}
			const merged = mergeUniqueSettingLines(currentDraft, defaults);
			pushToast({ type: merged.added ? 'success' : 'info', text: merged.added ? ('Added ' + merged.added + ' missing default Do Not Defer or Delay item(s).') : 'All recommended JS Do Not Defer or Delay defaults are already present.' });
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


																async function submitRuntimeJsScanSnapshot(snapshot) {
			if (!snapshot) {
				return null;
			}
			try {
				const response = await apiRequest('runtime_js_scan_submit', snapshot);
				return response && response.runtimeJsScan ? response.runtimeJsScan : null;
			} catch (error) {
				return null;
			}
		}

		async function submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, completed, queueJobId) {
			const snapshot = readPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, queueJobId);
			if (snapshot) {
				snapshot.completed = !!completed;
			}
			return submitRuntimeJsScanSnapshot(snapshot);
		}

		async function runBrowserRuntimeJsScanForUrl(url, onStatus, options) {
			const scanOptions = options && typeof options === 'object' ? options : {};
			const scanContext = scanOptions.context === 'logged-in' ? 'logged-in' : 'anonymous';
			function setRuntimeStatus(message) {
				if (typeof onStatus === 'function') {
					onStatus(message);
				}
			}
			let scanUrl = String(url || '').trim() || ((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/');
			const scanId = 'rt_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
			const runtimeUrl = buildRuntimeJsScanUrl(scanUrl, scanId, scanContext);
			setRuntimeStatus('Opening ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' diagnostic page…');
			const popup = window.open(runtimeUrl, 'ultracacheRuntimeJsScan', 'width=1280,height=900');
			if (!popup) {
				setRuntimeStatus('Popup was blocked. Allow popups for this admin page and try again. Diagnostic URL: ' + runtimeUrl);
				pushToast({ type: 'error', text: __("Browser blocked the runtime scan window. Allow popups for this admin page and try again.", 'ultracache') });
				return { available: false, suggestions: [], suggestionCount: 0, missingCount: 0, scanContext: scanContext, scannedUrl: sanitizeRuntimeJsScanDisplayUrl(scanUrl), debugUrl: runtimeUrl };
			}

			try { popup.focus(); } catch (error) {
				ignoreExpectedAdminFailure(error);
			}
			setRuntimeStatus('Diagnostic page opened in ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' mode. Waiting for browser errors…');
			pushToast({ type: 'info', text: 'Browser Runtime Scan opened the page in ' + (scanContext === 'anonymous' ? 'anonymous frontend' : 'logged-in/admin frontend') + ' mode. Keep it open for a few seconds.' });
			let latestReport = null;
			let navigationLossReported = false;
			const startedAt = Date.now();
			for (let i = 0; i < 18; i++) {
				setRuntimeStatus('Waiting for runtime scan report… ' + (i + 1) + '/18');
				await sleep(i < 2 ? 900 : 1200);
				const directReport = await submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, false, scanOptions.queueJobId || '');
				if (directReport && Number(directReport.errorCount || 0) > 0) {
					latestReport = directReport;
				}
				if (!directReport && !navigationLossReported) {
					const navigationLossSnapshot = readPopupRuntimeJsScanNavigationLossSnapshot(popup, scanId, scanUrl, runtimeUrl, scanContext, scanOptions.queueJobId || '', startedAt);
					if (navigationLossSnapshot) {
						navigationLossReported = true;
						setRuntimeStatus('Runtime scan detected navigation before the collector could report.');
						const navigationLossReport = await submitRuntimeJsScanSnapshot(navigationLossSnapshot);
						if (navigationLossReport) {
							latestReport = navigationLossReport;
							break;
						}
					}
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
				} catch (error) {
					reportNonFatalAdminError('dashboard.runtime-js-scan.poll', error, { severity: 'debug', dedupeKey: 'dashboard.runtime-js-scan.poll', dedupeWindowMs: 30000 });
				}
			}
			const finalDirectReport = await submitPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, true, scanOptions.queueJobId || '');
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


		async function runJsDelaySafetyScanForUrl(url, onProgress, scanOptions) {
			const options = scanOptions && typeof scanOptions === 'object' ? scanOptions : {};
			let scanUrl = String(url || '').trim() || ((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/');
			const sessionKey = 'ultracache_js_dependency_scan_active_v1';
			const reportProgress = typeof onProgress === 'function' ? onProgress : function() {};
			const progressLogRef = { lines: [], lastProgress: {} };
			const projectProgressPopup = function(progress, message, active, failed) {
				const incomingProgress = progress && typeof progress === 'object' ? progress : {};
				const data = Object.assign({}, progressLogRef.lastProgress || {}, incomingProgress);
				progressLogRef.lastProgress = data;
				const phase = String(data.phase || (active ? 'prepare' : (failed ? 'failed' : 'complete')));
				const totalFiles = Math.max(0, Number(data.totalFiles || 0));
				const processedFiles = totalFiles > 0
					? Math.min(totalFiles, Math.max(0, Number(data.processedFiles || 0)))
					: Math.max(0, Number(data.processedFiles || 0));
				const cleanMessage = String(message || '').trim();
				if (cleanMessage && progressLogRef.lines[progressLogRef.lines.length - 1] !== cleanMessage) {
					progressLogRef.lines = progressLogRef.lines.concat([cleanMessage]).slice(-10);
				}
				const stageLabel = phase === 'analyze'
					? 'Analyzing local JavaScript'
					: (phase === 'correlate' ? 'Correlating dependency evidence' : (phase === 'complete' ? 'Complete' : (phase === 'failed' ? 'Failed' : 'Preparing page inventory')));
				setProcess({
					type: 'js_dependency_scan',
					active: !!active,
					label: __('Analyzing HTML JS Dependencies', 'ultracache'),
					current: processedFiles,
					total: totalFiles,
					queueBuilding: phase === 'prepare',
					logs: progressLogRef.lines.slice(),
					startTime: Date.now(),
					cancellable: false,
					cancelRequested: false,
					showWhenInactive: !active,
					complete: !active && !failed,
					currentStageLabel: stageLabel,
					currentItem: '',
					jsTotalScripts: Math.max(0, Number(data.totalScripts || 0)),
					jsTotalFiles: totalFiles,
					jsProcessedFiles: processedFiles,
					jsCacheHits: Math.max(0, Number(data.cacheHits || 0)),
					jsFreshFiles: Math.max(0, Number(data.freshlyAnalyzedFiles || 0)),
					jsProgressPercent: Math.max(0, Math.min(100, Number(data.progressPercent || (active ? 0 : 100)))),
					failed: !!failed,
				});
			};
			const publishProgress = function(progress, message) {
				reportProgress(progress || {}, message || 'Analyzing HTML JS dependencies…');
				projectProgressPopup(progress || {}, message || 'Analyzing HTML JS dependencies…', true, false);
			};
			const readStoredJob = function() {
				try {
					const raw = window.sessionStorage ? window.sessionStorage.getItem(sessionKey) : '';
					const parsed = raw ? JSON.parse(raw) : null;
					return parsed && parsed.id ? parsed : null;
				} catch (error) {
					return null;
				}
			};
			const storeJob = function(job) {
				if (!job || !job.id) {
					return;
				}
				try {
					if (window.sessionStorage) {
						window.sessionStorage.setItem(sessionKey, JSON.stringify({ id: String(job.id), url: scanUrl }));
					}
				} catch (error) {
					// Browser storage is an optional resume hint; server-side URL deduplication remains authoritative.
				}
			};
			const clearStoredJob = function(jobId) {
				try {
					if (!window.sessionStorage) {
						return;
					}
					const stored = readStoredJob();
					if (!stored || !jobId || String(stored.id) === String(jobId)) {
						window.sessionStorage.removeItem(sessionKey);
					}
				} catch (error) {
					// Ignore optional browser-storage cleanup failures.
				}
			};

			let resumeHint = null;
			let resumeJob = null;
			if (options.resumeOnly) {
				resumeHint = readStoredJob();
				if (!resumeHint || !String(resumeHint.url || '').trim()) {
					return null;
				}
				scanUrl = String(resumeHint.url || '').trim();
				try {
					const statusResponse = await apiRequest('queue_status', { id: resumeHint.id });
					resumeJob = statusResponse && statusResponse.job ? statusResponse.job : null;
				} catch (error) {
					clearStoredJob(resumeHint.id);
					return null;
				}
				if (!resumeJob || resumeJob.action !== 'js_dependency_scan') {
					clearStoredJob(resumeHint.id);
					return null;
				}
			}

			const completed = await enqueueUiOperation('js_dependency_scan', 'HTML JS Dependency Scan', async (toastId) => {
				let job = null;
				if (options.resumeOnly) {
					job = resumeJob;
				} else {
					await syncQueuedSettingsBeforeAction();
					const queued = await apiRequest('queue_action', { action: 'js_dependency_scan', params: { url: scanUrl } });
					job = queued && queued.job ? queued.job : null;
					if (!job || !job.id) {
						throw new Error('HTML JS dependency analysis job was not created.');
					}
					storeJob(job);
				}

				publishProgress(job && job.progress ? job.progress : {}, job && job.message ? job.message : 'Preparing HTML JS dependency analysis…');

				for (let batch = 0; batch < 256 && job && ['done', 'failed'].indexOf(job.status) === -1; batch++) {
					const response = await apiRequest('queue_run', { id: job.id });
					job = response && response.job ? response.job : job;
					publishProgress(job && job.progress ? job.progress : {}, job && job.message ? job.message : 'Analyzing HTML JS dependencies…');
					if (job && job.status === 'running') {
						await sleep(500);
					} else if (job && job.status === 'queued') {
						await sleep(40);
					}
				}

				if (!job) {
					return null;
				}
				if (['done', 'failed'].indexOf(job.status) !== -1) {
					clearStoredJob(job.id);
				}
				if (['done', 'failed'].indexOf(job.status) === -1) {
					throw new Error('HTML JS dependency analysis did not reach a terminal state.');
				}
				if (job.status !== 'done') {
					throw new Error(job.message || 'HTML JS dependency analysis failed.');
				}

				const result = job.result || {};
				applyDashboardPayload(result);
				if (result && result.performanceProfile) {
					setPerformanceProfile(result.performanceProfile);
				}
				return job;
			}, {
				processingText: options.resumeOnly ? 'Resuming HTML JS dependency analysis…' : 'Analyzing HTML JS dependencies…',
				successText: 'HTML JS dependency analysis completed.',
				failedText: 'HTML JS dependency analysis failed.',
			});

			if (!completed) {
				projectProgressPopup({ phase: 'failed', progressPercent: 100 }, 'HTML JS dependency analysis failed.', false, true);
				return null;
			}
			projectProgressPopup(completed && completed.progress ? completed.progress : { phase: 'complete', progressPercent: 100 }, completed && completed.message ? completed.message : 'HTML JS dependency analysis completed.', false, false);
			const result = completed && completed.result ? completed.result : {};
			const profile = result && result.performanceProfile ? result.performanceProfile : null;
			const scan = profile && profile.jsDelaySafetyScan ? profile.jsDelaySafetyScan : null;
			if (!scan || !scan.available) {
				if (!options.resumeOnly) {
					pushToast({ type: 'warning', text: __('HTML JS dependency analysis was not available for this URL.', 'ultracache') });
				}
				return { available: false, source: 'html-strong-dependency-analysis', suggestions: [], suggestionCount: 0, missingCount: 0, scannedUrl: scanUrl };
			}
			const strongSuggestions = Array.isArray(scan.strongSuggestions) ? scan.strongSuggestions : [];
			const enrichedScan = Object.assign({}, scan, {
				available: true,
				source: 'html-strong-dependency-analysis',
				suggestions: strongSuggestions,
				suggestionCount: Number(scan.strongSuggestionCount || strongSuggestions.length || 0),
				missingCount: Number(scan.strongMissingCount || 0),
				alreadySafeguardedCount: Number(scan.strongAlreadySafeguardedCount || 0),
				otherHeuristicSuggestionCount: Number(scan.suggestionCount || 0),
				scannedUrl: (profile && (profile.profileUrl || profile.url)) ? (profile.profileUrl || profile.url) : scanUrl,
				scannedAt: profile && profile.scannedAt ? profile.scannedAt : '',
			});
			pushToast({
				type: enrichedScan.missingCount ? 'warning' : 'success',
				text: enrichedScan.missingCount
					? ('Found ' + enrichedScan.missingCount + ' strong JS dependency suggestion(s).')
					: 'No missing high-confidence silent JS dependency conflicts were found for this URL.'
			});
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
			});
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

		async function getProfileSqliteFallbackPatch(basePatch) {
			const sqliteResult = await probeObjectCacheBackendForProfile('sqlite', basePatch || {});
			assertProfileProbeDeterminate(sqliteResult);
			return sqliteResult.status === 'available' ? 'sqlite' : await getProfileDiskFallbackPatch();
		}

		async function getProfileObjectCachePatch(basePatch) {
			// Profile detection is part of the queued profile operation and must be
			// resolved in deterministic Redis > APCu > SQLite > Disk order. Do not run these
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
					objectCacheFallbackBackend: apcuFallbackResult.status === 'available' ? 'apcu' : await getProfileSqliteFallbackPatch(basePatch),
				};
			}

			const apcuResult = await probeObjectCacheBackendForProfile('apcu', basePatch);
			assertProfileProbeDeterminate(apcuResult);

			if (apcuResult.status === 'available') {
				return {
					objectCacheEnabled: true,
					objectCacheBackend: 'apcu',
					objectCacheFallbackBackend: await getProfileSqliteFallbackPatch(basePatch),
				};
			}

			const sqliteResult = await probeObjectCacheBackendForProfile('sqlite', basePatch);
			assertProfileProbeDeterminate(sqliteResult);
			if (sqliteResult.status === 'available') {
				return {
					objectCacheEnabled: true,
					objectCacheBackend: 'sqlite',
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

			throw new Error('Profile object-cache detection found no available persistent backend. Redis, APCu, SQLite and Disk probes were unavailable.');
		}

		async function getProfileQueryAllowlistPatch() {
			// Profiles must not overwrite or append to user-maintained visible lists.
			// Query-string allowlist population remains available from its dedicated UI action.
			return {};
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



		async function beginManualMediaSession(preferredToken = '') {
			return beginMediaManualSession({
				mediaFormat: getSelectedMediaQueueFormat(),
				preferredToken: String(preferredToken || manualMediaSessionTokenRef.current || ''),
				setToken: (token) => {
					manualMediaSessionTokenRef.current = token;
				},
			});
		}

		async function endManualMediaSession(token) {
			return endMediaManualSession({
				mediaFormat: getSelectedMediaQueueFormat(),
				token: String(token || ''),
				currentToken: String(manualMediaSessionTokenRef.current || ''),
				clearToken: (ownerToken) => {
					if (manualMediaSessionTokenRef.current === ownerToken) {
						manualMediaSessionTokenRef.current = '';
					}
				},
			});
		}


		async function beginManualWarmSession(state, preferredToken) {
			const result = await apiRequest('manual_warm_session', {
				action: 'begin',
				token: String(preferredToken || ''),
				jobType: String(state && state.type ? state.type : 'warm'),
			});
			if (!result || !result.success || !result.token) {
				throw new Error(result && result.message ? result.message : __('Could not acquire manual warm-up priority.', 'ultracache'));
			}
			return String(result.token);
		}

		async function pauseManualWarmSession(token) {
			const result = await apiRequest('manual_warm_session', {
				action: 'pause',
				token: String(token || ''),
			});
			return !!(result && result.success);
		}

		async function cancelManualWarmSession(token) {
			const result = await apiRequest('manual_warm_session', {
				action: 'cancel',
				token: String(token || ''),
			});
			return !!(result && result.success);
		}

		async function endManualWarmSession(token) {
			const result = await apiRequest('manual_warm_session', {
				action: 'end',
				token: String(token || ''),
			});
			return !!(result && result.success);
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
			ultracache.warmupGeneration = next;
			setWarmupGeneration(next);
		}


		function getJobControls(type) {
			return getSavedJobControls(savedJob, type, {
				isWarmJobType,
				warmupGeneration: Number(warmupGenerationRef.current || 0),
			});
		}



		function updateProcessState(state, overrides = {}) {
			setProcess(buildProcessState(state, overrides));
		}


		function requestCancel() {
			if (!process.active || cancelRequestedRef.current) {
				return;
			}
			cancelRequestedRef.current = true;
			setProcess((prev) => Object.assign({}, prev, { cancelRequested: true }));
		}


		async function runJob(job, forceRestart, existingManualSessionToken = '') {
			const runner = createJobRunner({
				isWarmJobType,
				getWarmScopeForType,
				getWarmupGeneration: () => Number(warmupGenerationRef.current || 0),
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => {
					cancelRequestedRef.current = false;
				},
				setBusy,
				updateProcessState,
				persistJobState,
				fetchBatch: fetchJobBatch,
				processItem: processJobItem,
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media' || isWarmJobType(type),
				beginExclusiveSession: (state, preferredToken) => isWarmJobType(state.type)
					? beginManualWarmSession(state, preferredToken)
					: beginManualMediaSession(preferredToken),
				endExclusiveSession: (state, token) => isWarmJobType(state.type)
					? endManualWarmSession(token)
					: endManualMediaSession(token),
				pauseExclusiveSession: (state, token) => isWarmJobType(state.type)
					? cancelManualWarmSession(token)
					: true,
				failExclusiveSession: (state, token) => isWarmJobType(state.type)
					? pauseManualWarmSession(token)
					: true,
				shouldReleaseExclusiveSessionOnExit: (state, completed) => state.type === 'media' || !!completed,
				getBatchStatePatch: (state, batch) => {
					const mediaQueueCompleted = state.type === 'media' ? Math.max(0, Number(batch.queueCompleted || 0)) : 0;
					return {
						total: Math.max(Number(state.total || 0), Number(batch.total || 0), mediaQueueCompleted),
						processed: state.type === 'media' ? Math.max(Number(state.processed || 0), mediaQueueCompleted) : Number(state.processed || 0),
						queueBuilding: state.type === 'media' ? !!batch.queueBuilding : false,
					};
				},
				shouldMeasureEta: (type) => type === 'media',
				decorateStateAfterItem: (state, result) => ({
					label: state.type === 'media' && (result.avifIncrement > 0 || result.webpIncrement > 0) ? 'Optimizing Media' : state.label,
				}),
				buildCompletionNotice: (state) => {
					const failedCount = Math.max(0, Number(state.failedCount || 0));
					const skippedCount = Math.max(0, Number(state.skippedCount || 0));
					const successCount = Math.max(0, Number(state.successCount || 0));
					const varnishWarmedCount = Math.max(0, Number(state.varnishWarmedCount || 0));
					const liteSpeedWarmedCount = Math.max(0, Number(state.liteSpeedWarmedCount || 0));
					const varnishSummary = varnishWarmedCount > 0
						? ' Varnish: ' + varnishWarmedCount + ' warmed.'
						: '';
					const liteSpeedSummary = liteSpeedWarmedCount > 0
						? ' LiteSpeed: ' + liteSpeedWarmedCount + ' warmed.'
						: '';
					let finalNotice = { type: 'success', text: isWarmJobType(state.type) ? 'Cache warming complete.' : (state.forceRegenerateExisting ? 'Media regeneration complete.' : 'Media optimization complete.') };
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
						if (varnishSummary) {
							finalNotice.text += varnishSummary;
						}
						if (liteSpeedSummary) {
							finalNotice.text += liteSpeedSummary;
						}
					} else if (failedCount > 0) {
						finalNotice = { type: 'warning', text: 'Media optimization completed with ' + failedCount + ' failed item' + (failedCount === 1 ? '' : 's') + '.' };
					}
					return finalNotice;
				},
				onCompleted: async (state) => {
					if (state.type === 'media') {
						await refreshMediaQueueStatus(false);
					}
					await refreshStats();
				},
				markProcessComplete: () => {
					setProcess((prev) => Object.assign({}, prev, { active: false, cancellable: false, cancelRequested: false }));
				},
				getFailureText: (type) => isWarmJobType(type) ? 'Cache warming failed.' : 'Media optimization failed.',
				onReleaseFailure: (state) => {
					pushToast({
						type: 'warning',
						text: isWarmJobType(state && state.type)
							? __('Manual warm-up finished, but its priority state could not be released immediately.', 'ultracache')
							: 'Media conversion finished, but exclusive background ownership could not be released immediately. It will expire automatically.',
					});
				},
				onPaused: (state) => {
					pushToast({
						type: 'success',
						text: isWarmJobType(state && state.type)
							? __("Warm-up cancelled. Background automation may continue.", 'ultracache')
							: __("Job paused. You can resume it later.", 'ultracache'),
					});
				},
			});
			return runner(job, forceRestart, existingManualSessionToken);
		}


		const warmupController = createWarmupController({
			getSettings: () => settingsRef.current || {},
			syncQueuedSettingsBeforeAction,
			pushToast,
			queueDashboardAction,
			setHomepageHtmlBusy,
			setHomepageHtmlCssBusy,
			setAllUrlsCssBusy,
			setMenuUrlsCssBusy,
			getJobControls,
			getSavedJob: () => savedJob,
			isBusy: () => busy,
			runJob,
			getWarmupGeneration: () => Number(warmupGenerationRef.current || 0),
			hasFullSiteWarmScope,
			hasMenuWarmScope,
			beginManualWarmPriority: (jobType, preferredToken) => beginManualWarmSession({ type: jobType }, preferredToken),
			endManualWarmPriority: (token) => endManualWarmSession(token),
			defaultBatchSize: DEFAULT_QUEUE_BATCH_SIZE,
		});
		const {
			warmFrontpageHtml,
			warmFrontpageHtmlCss,
			startWarmingAllWithFrontpageCss,
			startMenuWarming,
			startMenuWarmingWithFrontpageCss,
			startWarming,
		} = warmupController;

		const mediaController = createMediaController({
			getJobControls,
			getSavedJob: () => savedJob,
			isBusy: () => busy,
			runJob,
			beginManualSession: beginManualMediaSession,
			endManualSession: endManualMediaSession,
			getSelectedMediaQueueFormat,
			applyMediaQueueStatus,
			setProcess,
			persistJobState,
			refreshMediaQueueStatus,
			refreshStats,
			pushToast,
			setBusy,
			getCancelRequested: () => !!cancelRequestedRef.current,
			setCancelRequested: (value) => {
				cancelRequestedRef.current = !!value;
			},
			getMediaBackgroundControlBusy: () => mediaBackgroundControlBusy,
			setMediaBackgroundControlBusy,
			markMediaProcessCancelRequested: (value) => {
				setProcess((current) => current && current.type === 'media'
					? Object.assign({}, current, { cancelRequested: !!value })
					: current);
			},
			defaultBatchSize: DEFAULT_QUEUE_BATCH_SIZE,
		});
		const {
			startMediaOptimization,
			rebuildMediaQueue,
			toggleMediaBackgroundWork,
			recountOptimizedImageFiles,
			retryFailedMediaQueue,
			clearCompletedMediaQueue,
		} = mediaController;


		function hasSavedMediaRegenerationJob() {
			return !!(savedJob && savedJob.type === 'media' && savedJob.forceRegenerateExisting && getJobControls('media').canResume);
		}

		function getMediaStartButtonLabel() {
			if (mediaBackgroundPaused) {
				return 'Media Background Work Paused';
			}
			if (mediaBackgroundCoolingDown) {
				return 'Media Worker Cooldown';
			}
			if (busy) {
				return 'Engine Busy';
			}
			if (hasSavedMediaRegenerationJob()) {
				return 'Resume Media Regeneration';
			}
			if (getJobControls('media').canResume) {
				return 'Resume Media Conversion';
			}
			return 'Start / Resume Conversion';
		}

		function getMediaStartHelpText() {
			if (hasSavedMediaRegenerationJob()) {
				return __('An interrupted regeneration job is saved. Resume will continue overwriting existing AVIF/WebP files for that job.', 'ultracache');
			}
			return mediaRegenerateExistingEnabled
				? __('Start will requeue completed media and overwrite existing AVIF/WebP files. Original uploads are not changed.', 'ultracache')
				: __('Processes the next pending media items. Existing optimized files are checked and marked already optimized.', 'ultracache');
		}

		function startMediaOptimizationFromDashboard() {
			const resumeSavedRegeneration = hasSavedMediaRegenerationJob();
			const regenerateExisting = !!mediaRegenerateExistingEnabled;
			if (!resumeSavedRegeneration && regenerateExisting && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm('Regenerate existing optimized images? UltraCache will overwrite already generated AVIF/WebP files with the current media quality setting. Original uploads are not changed.')) {
					return;
				}
			}
			startMediaOptimization(false, regenerateExisting);
		}

		function getMediaConversionTestItems() {
			return mediaConversionTestResult && Array.isArray(mediaConversionTestResult.items) ? mediaConversionTestResult.items : [];
		}

		function getMediaConversionTestImageUrl(item) {
			if (!item || typeof item !== 'object') {
				return '';
			}
			return String(item.previewUrl || item.thumbnailUrl || item.originalUrl || '');
		}

		function getMediaConversionTestVariant(item, key) {
			if (!item || typeof item !== 'object') {
				return null;
			}
			if (key === 'original') {
				return item.original && typeof item.original === 'object'
					? item.original
					: {
						supported: true,
						status: 'source',
						label: 'Original',
						sizeHuman: item.originalSizeHuman || '0 B',
						url: item.originalUrl || item.previewUrl || item.thumbnailUrl || '',
					};
			}
			return item[key] && typeof item[key] === 'object' ? item[key] : null;
		}

		function getMediaConversionTestFormatText(item, key) {
			const format = getMediaConversionTestVariant(item, key);
			if (!format) {
				return '—';
			}
			if (format.sizeHuman) {
				return String(format.sizeHuman);
			}
			if (format.status === 'not_supported') {
				return 'Not supported';
			}
			if (format.status === 'failed') {
				return 'Failed';
			}
			return '—';
		}

		function getMediaConversionTestCacheBust() {
			return String(Date.now()) + '-' + String(Math.random()).slice(2, 9);
		}

		const {
			refreshMediaLibraryReplacementWorkflowStatus,
			persistMediaLibraryReplacementWorkflowStage,
			loadMediaLibraryReplacementPreviewPage,
			canRefreshMediaLibraryReplacementMappingPreview,
			canRefreshMediaLibraryReplacementDatabasePreview,
			getMediaLibraryReplacementNextActionKey,
			getMediaLibraryReplacementActionClass,
			getMediaLibraryReplacementWorkflowStage,
			getMediaLibraryReplacementStepInactiveReason,
			isMediaLibraryReplacementReadinessRunnerReady,
			getMediaLibraryReplacementReadinessStatus,
			manageMediaLibraryReplacementSession,
			runMediaLibraryReplacementReadiness,
			isMediaLibraryReplacementPrepareRunnerReady,
			getMediaLibraryReplacementPrepareStatus,
			runMediaLibraryReplacementPrepare,
			getMediaLibraryReplacementPrepareLabel,
			isMediaLibraryReplacementDoRunnerReady,
			getMediaLibraryReplacementDoStatus,
			runMediaLibraryReplacementDo,
			getMediaLibraryReplacementDoLabel,
			isMediaLibraryReplacementVerifyRunnerReady,
			getMediaLibraryReplacementVerifyStatus,
			runMediaLibraryReplacementVerify,
			getMediaLibraryReplacementVerifyLabel,
			isMediaLibraryReplacementDeleteRunnerReady,
			getMediaLibraryReplacementDeleteStatus,
			runMediaLibraryReplacementDelete,
			getMediaLibraryReplacementDeleteLabel,
			isMediaLibraryReplacementRunnerReady,
			getMediaLibraryReplacementRecoveryStatus,
			isMediaLibraryReplacementOwnedByAnotherDashboard,
			restartMediaLibraryReplacementWorkflow,
			recoverMediaLibraryReplacementWorkflow,
			closeMediaLibraryReplacementWarning,
			confirmMediaLibraryReplacementWarning,
			getMediaLibraryReplacementRunnerUnavailableMessage,
			showMediaLibraryReplacementRunnerUnavailable,
			getMediaLibraryReplacementWorkflowButtonState,
			getMediaLibraryReplacementWorkflowButtonClass,
			getMediaLibraryReplacementDeleteDisabledReason,
			prepareMediaLibraryReplacementWorkflow,
			doMediaLibraryReplacementWorkflow,
			verifyMediaLibraryReplacementWorkflow,
			deleteMediaLibraryReplacementOriginalsWorkflow,
			prepareMediaLibraryReplacementFoundation,
			openMediaLibraryReplacementPreviewModal,
			changeMediaLibraryReplacementPreviewPage,
			loadMediaLibraryReplacementDbPreviewPage,
			openMediaLibraryReplacementDbPreviewModal,
			changeMediaLibraryReplacementDbPreviewPage,
			loadMediaLibraryReplacementBlockersPage,
			openMediaLibraryReplacementBlockersModal,
			changeMediaLibraryReplacementBlockersPage,
			saveMediaLibraryReplacementBlockerDecisions,
			loadMediaLibraryReplacementCleanupPreviewPage,
			openMediaLibraryReplacementCleanupPreviewModal,
			changeMediaLibraryReplacementCleanupPreviewPage,
			closeMediaLibraryReplacementCleanupPreviewModal,
			copyMediaLibraryReplacementFiles,
			prepareMediaLibraryReplacementMetadataUpdates,
			applyMediaLibraryReplacementMetadataUpdates,
			rollbackMediaLibraryReplacementMetadataUpdates,
			scanMediaLibraryReplacementReferences,
			matchMediaLibraryReplacementReferences,
			scanMediaLibraryReplacementThemeCssReferences,
			previewMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementThemeCssReplacements,
			verifyMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementDatabaseReplacements,
			verifyMediaLibraryReplacementDatabaseReplacements,
			rollbackMediaLibraryReplacementDatabaseReplacements,
		} = useMediaReplacementWorkflow(Object.assign({}, mediaLibraryReplacementState, {
			busy,
			mediaConversionTestBusy,
			process,
			cancelRequestedRef,
			setBusy,
			setProcess,
			updateProcessState,
			pushToast,
			getMediaConversionTestCacheBust,
		}));

		async function runMediaConversionTest() {
			if (busy || mediaConversionTestBusy) {
				return;
			}
			setMediaConversionTestBusy(true);
			setMediaConversionLightboxItem(null);
			try {
				await syncQueuedSettingsBeforeAction();
				const response = await apiRequest('media_conversion_test_run', {
					cacheBust: getMediaConversionTestCacheBust(),
				});
				setMediaConversionTestResult(response || null);
				setMediaConversionTestRevision((revision) => revision + 1);
				pushToast({
					type: 'success',
					text: 'Image conversion test complete: ' + formatNumber(response && response.total ? response.total : 0) + ' image' + (Number(response && response.total ? response.total : 0) === 1 ? '' : 's') + '.',
				});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Image conversion test failed.' });
			} finally {
				setMediaConversionTestBusy(false);
			}
		}

		async function openMediaConversionTestModal() {
			if (mediaConversionTestBusy) {
				return;
			}
			setMediaConversionTestBusy(true);
			setMediaConversionLightboxItem(null);
			try {
				const response = await apiRequest('media_conversion_test_latest', { cacheBust: getMediaConversionTestCacheBust() });
				setMediaConversionTestResult(response || null);
				setMediaConversionTestRevision((revision) => revision + 1);
				setMediaConversionTestModalOpen(true);
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Could not load the image conversion test.' });
			} finally {
				setMediaConversionTestBusy(false);
			}
		}


		function closeMediaConversionTestModal() {
			setMediaConversionTestModalOpen(false);
			setMediaConversionLightboxItem(null);
		}

		function openMediaConversionLightbox(item) {
			const url = getMediaConversionTestImageUrl(item);
			if (!url) {
				return;
			}
			setMediaConversionLightboxItem({
				url,
				title: item && (item.title || item.filename) ? String(item.title || item.filename) : 'Image preview',
			});
		}

		function openMediaConversionVariantLightbox(item, key, label) {
			const format = getMediaConversionTestVariant(item, key);
			const url = format && format.url ? String(format.url) : '';
			if (!url) {
				return;
			}
			const title = item && (item.title || item.filename) ? String(item.title || item.filename) : 'Image preview';
			setMediaConversionLightboxItem({
				url,
				title: String(label || '') + (label ? ' · ' : '') + title,
			});
		}

		function renderMediaConversionTestModal() {
			if (!mediaConversionTestModalOpen) {
				return null;
			}

			const items = getMediaConversionTestItems();
			const hasReport = !!(mediaConversionTestResult && mediaConversionTestResult.hasReport);
			const createdAt = mediaConversionTestResult && mediaConversionTestResult.createdAt ? String(mediaConversionTestResult.createdAt) : '';
			const qualityProfile = mediaConversionTestResult && mediaConversionTestResult.qualityProfile ? String(mediaConversionTestResult.qualityProfile) : 'balanced';
			const qualityValues = mediaConversionTestResult && mediaConversionTestResult.qualityValues && typeof mediaConversionTestResult.qualityValues === 'object' ? mediaConversionTestResult.qualityValues : {};
			const qualitySummary = 'WebP ' + String(qualityValues.webp || '—') + ' / AVIF ' + String(qualityValues.avif || '—');
			const qualitySource = mediaConversionTestResult && mediaConversionTestResult.qualitySource ? String(mediaConversionTestResult.qualitySource) : '';
			const qualityDebug = qualitySource ? (' · source: ' + qualitySource) : '';
			const runKey = mediaConversionTestResult && mediaConversionTestResult.runKey ? String(mediaConversionTestResult.runKey) : String(mediaConversionTestRevision || 0);

			return h('div', {
				className: 'uc-media-test-modal',
				onClick: closeMediaConversionTestModal,
				role: 'presentation',
				key: 'media-conversion-test-modal-' + runKey,
			}, [
				h('div', {
					className: 'uc-media-test-modal__dialog',
					onClick: (event) => event.stopPropagation(),
					role: 'dialog',
					'aria-modal': 'true',
					'aria-labelledby': 'uc-media-test-modal-title',
					key: 'dialog',
				}, [
					h('button', {
						type: 'button',
						className: 'uc-media-test-modal__close',
						onClick: closeMediaConversionTestModal,
						'aria-label': __('Close image conversion test', 'ultracache'),
						key: 'close',
					}, '×'),
					h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('Media Library replacement', 'ultracache')),
					h('h3', { className: 'uc-support-modal__title', id: 'uc-media-test-modal-title', key: 'title' }, __('Image conversion test', 'ultracache')),
					h('p', { className: 'uc-support-modal__text', key: 'summary' }, hasReport
						? ('Last test: ' + formatNumber(items.length) + ' image' + (items.length === 1 ? '' : 's') + ' · quality profile: ' + qualityProfile + ' (' + qualitySummary + ')' + qualityDebug + (createdAt ? ' · ' + createdAt : ''))
						: __('No image conversion test has been run yet.', 'ultracache')
					),
					items.length ? h('div', { className: 'uc-media-test-grid', key: 'items-' + runKey }, items.map((item, index) => {
						const title = item && (item.title || item.filename) ? String(item.title || item.filename) : ('Image #' + (index + 1));
						const variants = [
							{ key: 'original', label: 'Original' },
							{ key: 'webp', label: 'WebP' },
							{ key: 'avif', label: 'AVIF' },
						];
						return h('div', { className: 'uc-media-test-row', key: 'media-test-item-' + runKey + '-' + (item.id || index) }, [
							h('div', { className: 'uc-media-test-row__meta', key: 'meta' }, [
								h('div', { className: 'uc-media-test-item__title', key: 'item-title' }, title),
								h('div', { className: 'uc-media-test-item__filename', key: 'filename' }, item.filename || ''),
							]),
							h('div', { className: 'uc-media-test-variants', key: 'variants' }, variants.map((variant) => {
								const format = getMediaConversionTestVariant(item, variant.key);
								const url = format && format.url ? String(format.url) : '';
								const status = format && format.status ? String(format.status) : '';
								const text = getMediaConversionTestFormatText(item, variant.key);
								const qualityText = format && format.status === 'generated' && format.quality && variant.key !== 'original' ? (' · q' + String(format.quality) + (format.encoder ? ' · ' + String(format.encoder) : '')) : '';
								return h('button', {
									type: 'button',
									className: 'uc-media-test-variant' + (url ? ' has-image' : '') + (status ? ' is-' + status : ''),
									onClick: () => openMediaConversionVariantLightbox(item, variant.key, variant.label),
									disabled: !url,
									key: runKey + '-' + variant.key,
								}, [
									h('span', { className: 'uc-media-test-variant__label', key: 'label' }, variant.label),
									url ? h('span', { className: 'uc-media-test-variant__image', key: 'image-' + runKey + '-' + variant.key }, h('img', { key: 'img-' + runKey + '-' + variant.key, src: url, alt: variant.label + ' · ' + title, loading: 'lazy' })) : h('span', { className: 'uc-media-test-variant__empty', key: 'empty' }, text),
									h('strong', { className: 'uc-media-test-variant__size', key: 'size' }, text + qualityText),
								]);
							})),
						]);
					})) : h('div', { className: 'uc-media-test-empty-state', key: 'empty' }, __('Run Image conversion test first, then open Check test again.', 'ultracache')),
				]),
				mediaConversionLightboxItem ? h('div', {
					className: 'uc-media-test-lightbox',
					onClick: (event) => {
						event.stopPropagation();
						setMediaConversionLightboxItem(null);
					},
					role: 'presentation',
					key: 'lightbox',
				}, [
					h('button', {
						type: 'button',
						className: 'uc-media-test-lightbox__close',
						onClick: (event) => {
							event.stopPropagation();
							setMediaConversionLightboxItem(null);
						},
						'aria-label': __('Close image preview', 'ultracache'),
						key: 'close',
					}, '×'),
					h('div', {
						className: 'uc-media-test-lightbox__frame',
						onClick: (event) => event.stopPropagation(),
						key: 'frame',
					}, h('img', { src: mediaConversionLightboxItem.url, alt: mediaConversionLightboxItem.title || 'Image preview' })),
				]) : null,
			]);
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
		'uploads/ultracache/images/avif',
		'uploads/ultracache/images/webp',
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




		function openSupportModal() {
			setSupportModalOpen(true);
		}

		function closeSupportModal() {
			setSupportModalOpen(false);
		}

		function handleHireClick() {
			setSupportModalOpen(false);
		}

		function closeVersionHelpModal() {
			setVersionHelpModalOpen(false);
			window.setTimeout(() => {
				const trigger = document.getElementById('ultracache-version-help-trigger');
				if (trigger && typeof trigger.focus === 'function') {
					trigger.focus();
				}
			}, 0);
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
		const mediaBackgroundPaused = !!effectiveMediaQueueStatus.backgroundPaused;
		const mediaBackgroundPauseMessage = effectiveMediaQueueStatus.backgroundPauseMessage
			? String(effectiveMediaQueueStatus.backgroundPauseMessage)
			: '';
		const mediaBackgroundCooldownRemaining = Math.max(0, Number(effectiveMediaQueueStatus.backgroundCooldownRemaining || 0));
		const mediaBackgroundCoolingDown = !mediaBackgroundPaused && mediaBackgroundCooldownRemaining > 0;
		const mediaBackgroundStaleCount = Math.max(0, Number(effectiveMediaQueueStatus.backgroundStaleCount || 0));
		const mediaBackgroundStaleThreshold = Math.max(1, Number(settings.mediaStaleWorkerThreshold || effectiveMediaQueueStatus.backgroundStaleThreshold || 3));
		const mediaBackgroundStaleWindowSeconds = Math.max(0, Number(effectiveMediaQueueStatus.backgroundStaleWindowSeconds || 0));
		const mediaBackgroundStaleItems = Array.isArray(effectiveMediaQueueStatus.backgroundStaleItems)
			? effectiveMediaQueueStatus.backgroundStaleItems
			: [];
		const mediaBackgroundLastStaleItem = mediaBackgroundStaleItems.length > 0
			? mediaBackgroundStaleItems[mediaBackgroundStaleItems.length - 1]
			: null;
		const mediaBackgroundLastStaleAttachmentId = mediaBackgroundLastStaleItem
			? Math.max(0, Number(mediaBackgroundLastStaleItem.attachmentId || 0))
			: 0;
		const mediaBackgroundLastStaleAt = mediaBackgroundLastStaleItem
			? Math.max(0, Number(mediaBackgroundLastStaleItem.at || 0))
			: 0;
		const mediaBackgroundLastStaleLabel = mediaBackgroundLastStaleItem
			? ((mediaBackgroundLastStaleAttachmentId > 0 ? ('Attachment #' + mediaBackgroundLastStaleAttachmentId) : 'Queue item')
				+ (mediaBackgroundLastStaleAt > 0 ? (' · ' + new Date(mediaBackgroundLastStaleAt * 1000).toLocaleString()) : ''))
			: 'None';
		const mediaBackgroundSafetyStatus = mediaBackgroundPaused
			? 'Paused'
			: (mediaBackgroundCoolingDown ? 'Cooldown' : 'Ready');
		const mediaBackgroundSafetyStatusClass = mediaBackgroundPaused
			? 'text-red-300'
			: (mediaBackgroundCoolingDown ? 'text-amber-300' : 'text-emerald-300');
		const mediaBackgroundAutomaticAction = mediaBackgroundPaused
			? 'Global breaker open · explicit resume required'
			: (mediaBackgroundCoolingDown ? 'Queue resumes automatically after cooldown' : 'Queue continues normally');
		const optimizedImagesTotal = Math.max(0, Number(mediaFileCounts.total || 0));
		const optimizedAvifTotal = Math.max(0, Number(mediaFileCounts.avif || 0));
		const optimizedWebpTotal = Math.max(0, Number(mediaFileCounts.webp || 0));
		const mediaQueueTotal = Math.max(0, Number(effectiveMediaQueueStatus.total || 0));
		const mediaQueuePending = Math.max(0, Number(effectiveMediaQueueStatus.pending || 0));
		const mediaQueueFailed = Math.max(0, Number(effectiveMediaQueueStatus.failed || 0));
		const mediaQueueRecoverableInterrupted = Math.max(0, Number(effectiveMediaQueueStatus.recoverableInterrupted || 0));
		const mediaQueueRetryable = Math.max(0, Number(effectiveMediaQueueStatus.retryable || (mediaQueueFailed + mediaQueueRecoverableInterrupted)));
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
			(settings && (settings.varnishCliEnabled || settings.varnishConnectionConfigured || settings.varnishCliServers || settings.flushAllIncludeVarnish)) ||
			(varnishForm && (varnishForm.varnishCliEnabled || varnishForm.varnishConnectionConfigured || varnishForm.varnishCliServers || varnishForm.varnishCliKeyConfigured)) ||
			(varnishDiagnostic && (varnishDiagnostic.enabled || varnishDiagnostic.available || varnishDiagnostic.servers || varnishDiagnostic.endpointCount))
		);
		const showVarnishCard = !!((varnishLayer && varnishLayer.detected) || reverseProxyLooksLikeVarnish || varnishConfigured);
		const liteSpeedLayer = getExternalCacheLayer(stats, 'litespeed');
		const liteSpeedDiagnostic = diagnostics && diagnostics.liteSpeed ? diagnostics.liteSpeed : {};
		const liteSpeedConfigured = !!(settings && (
			settings.liteSpeedCacheEnabled ||
			settings.liteSpeedRefillAfterTargetedInvalidation ||
			settings.liteSpeedWarmDuringSiteWarmup ||
			settings.liteSpeedStalePurgeEnabled ||
			settings.liteSpeedRefreshAheadEnabled ||
			settings.liteSpeedRefreshAheadPinnedUrls ||
			settings.flushAllIncludeLiteSpeed
		));
		const showLiteSpeedCard = !!(
			(liteSpeedLayer && liteSpeedLayer.detected) ||
			(liteSpeedDiagnostic && (liteSpeedDiagnostic.detected || liteSpeedDiagnostic.serverDetected || liteSpeedDiagnostic.nativeEnabled)) ||
			liteSpeedConfigured
		);
		const cronWarmStatus = diagnostics && diagnostics.cronWarm && typeof diagnostics.cronWarm === 'object'
			? diagnostics.cronWarm
			: {};
		const automationWorker = cronWarmStatus.workerHealth && typeof cronWarmStatus.workerHealth === 'object'
			? cronWarmStatus.workerHealth
			: { status: 'ready', message: __('No automation work is waiting.', 'ultracache'), pendingUrls: 0, processingUrls: 0, pendingStages: 0, processingStages: 0, nextScheduledAt: 0 };
		const automationWorkerLabels = {
			ready: __('Ready', 'ultracache'),
			'running-scheduled': __('Running scheduled warm-up', 'ultracache'),
			'running-targeted': __('Running targeted work', 'ultracache'),
			'running-ui': __('Running UI warm-up', 'ultracache'),
			'running-cli': __('Running WP-CLI warm-up', 'ultracache'),
			'scheduled-full-site': __('Scheduled full-site warm-up', 'ultracache'),
			'scheduled-targeted': __('Scheduled targeted work', 'ultracache'),
			'scheduled-varnish-invalidation': __('Scheduled Varnish invalidation', 'ultracache'),
			'waiting-retry': __('Waiting for retry', 'ultracache'),
			'paused-configuration': __('Paused by configuration', 'ultracache'),
			recovered: __('Recovered', 'ultracache'),
			attention: __('Attention required', 'ultracache'),
		};
		const automationWorkerStageLabels = {
			html: __('HTML', 'ultracache'),
			css_bundle: __('CSS bundle', 'ultracache'),
			lcp_refresh: __('LCP refresh', 'ultracache'),
			varnish: __('Varnish', 'ultracache'),
			litespeed: __('LiteSpeed', 'ultracache'),
		};
		const automationWorkerStatus = String(automationWorker.status || 'ready');
		const automationWorkerLabel = automationWorkerLabels[automationWorkerStatus] || __('Ready', 'ultracache');
		const automationWorkerAttention = automationWorkerStatus === 'paused-configuration' || automationWorkerStatus === 'attention';
		const automationWorkerPendingUrls = Math.max(0, Number(automationWorker.pendingUrls || 0));
		const automationWorkerProcessingUrls = Math.max(0, Number(automationWorker.processingUrls || 0));
		const automationWorkerPendingStages = Math.max(0, Number(automationWorker.pendingStages || 0));
		const automationWorkerProcessingStages = Math.max(0, Number(automationWorker.processingStages || 0));
		const automationWorkerOpenStages = automationWorkerPendingStages + automationWorkerProcessingStages;
		const automationWorkerNextAt = Math.max(0, Number(automationWorker.nextScheduledAt || cronWarmStatus.nextScheduledAt || 0));
		const automationWorkerNextRetryAt = Math.max(0, Number(automationWorker.nextRetryAt || 0));
		const automationWorkerQueueText = __('Queue', 'ultracache') + ': '
			+ formatNumber(automationWorkerPendingUrls) + ' ' + __('URLs pending', 'ultracache')
			+ ' · ' + formatNumber(automationWorkerProcessingUrls) + ' ' + __('processing', 'ultracache')
			+ ' · ' + formatNumber(automationWorkerOpenStages) + ' ' + __('stages open', 'ultracache');
		const automationWorkerNextText = automationWorkerNextRetryAt > 0 && automationWorkerStatus === 'waiting-retry'
			? __('Retry after', 'ultracache') + ': ' + formatLooseTime(automationWorkerNextRetryAt)
			: (automationWorkerNextAt > 0
				? __('Next run', 'ultracache') + ': ' + formatLooseTime(automationWorkerNextAt)
				: '');
		const automationWorkerOwner = String(automationWorker.ownerSource || '');
		const automationWorkerCurrentUrl = String(automationWorker.currentUrl || '');
		const automationWorkerCurrentStage = String(automationWorker.currentStage || '');
		const automationWorkerCurrentStageLabel = automationWorkerStageLabels[automationWorkerCurrentStage] || automationWorkerCurrentStage;
		const automationWorkerActivityParts = [];
		if (automationWorkerOwner) {
			automationWorkerActivityParts.push(__('Owner', 'ultracache') + ': ' + (automationWorkerOwner === 'cli' ? __('WP-CLI', 'ultracache') : (automationWorkerOwner === 'ui' ? __('UI', 'ultracache') : __('Cron', 'ultracache'))));
		}
		if (automationWorkerCurrentStageLabel) {
			automationWorkerActivityParts.push(__('Stage', 'ultracache') + ': ' + automationWorkerCurrentStageLabel);
		}
		if (automationWorkerCurrentUrl) {
			automationWorkerActivityParts.push(__('URL', 'ultracache') + ': ' + automationWorkerCurrentUrl);
		}
		const automationWorkerPausedSession = automationWorker.pausedForegroundSession && typeof automationWorker.pausedForegroundSession === 'object'
			? automationWorker.pausedForegroundSession
			: {};
		const automationWorkerPausedSessionText = automationWorkerPausedSession.paused
			? __('Paused UI session available to resume.', 'ultracache')
			: '';
		const automationWorkerFullSiteText = String(cronWarmStatus.workloadType || '') === 'full_site'
			? __('Full-site plan', 'ultracache') + ': '
				+ formatNumber(Math.max(0, Number(cronWarmStatus.fullSiteProcessed || 0))) + ' / '
				+ formatNumber(Math.max(0, Number(cronWarmStatus.fullSitePlanned || 0))) + ' ' + __('processed', 'ultracache')
				+ ' · ' + formatNumber(Math.max(0, Number(cronWarmStatus.fullSitePlanned || 0))) + ' / '
				+ formatNumber(Math.max(0, Number(cronWarmStatus.scheduledWarmLimit || 0))) + ' ' + __('selected', 'ultracache')
			: '';

		const {
			closeMediaLibraryReplacementPreviewModal,
			closeMediaLibraryReplacementDbPreviewModal,
			renderMediaLibraryReplacementWarningModal,
			renderMediaLibraryReplacementPreviewModal,
			renderMediaLibraryReplacementDbPreviewModal,
			renderMediaLibraryReplacementBlockersModal,
			renderMediaLibraryReplacementCleanupPreviewModal,
			renderMediaConversionTestControls,
			renderMediaLibraryReplacementControls,
		} = createMediaReplacementUi({
			renderLabelWithHelp,
			SelectField,
			settings,
			updateSetting,
			busy,
			mediaConversionTestBusy,
			mediaSupport,
			mediaLibraryReplacementBusy,
			mediaLibraryReplacementStatus,
			mediaLibraryReplacementPreview,
			mediaLibraryReplacementPreviewOpen,
			mediaLibraryReplacementDbPreview,
			mediaLibraryReplacementDbPreviewOpen,
			mediaLibraryReplacementBlockers,
			mediaLibraryReplacementBlockersOpen,
			mediaLibraryReplacementBlockerDecisions,
			setMediaLibraryReplacementBlockersOpen,
			setMediaLibraryReplacementBlockerDecisions,
			mediaLibraryReplacementCleanupPreview,
			mediaLibraryReplacementCleanupPreviewOpen,
			mediaLibraryReplacementWarningAction,
			mediaLibraryReplacementWarningConfirmation,
			setMediaLibraryReplacementWarningConfirmation,
			closeMediaLibraryReplacementWarning,
			confirmMediaLibraryReplacementWarning,
			setMediaLibraryReplacementPreviewOpen,
			setMediaLibraryReplacementDbPreviewOpen,
			closeMediaLibraryReplacementCleanupPreviewModal,
			changeMediaLibraryReplacementPreviewPage,
			changeMediaLibraryReplacementDbPreviewPage,
			changeMediaLibraryReplacementBlockersPage,
			openMediaLibraryReplacementBlockersModal,
			saveMediaLibraryReplacementBlockerDecisions,
			changeMediaLibraryReplacementCleanupPreviewPage,
			runMediaConversionTest,
			openMediaConversionTestModal,
			getMediaLibraryReplacementDeleteDisabledReason,
			getMediaLibraryReplacementRecoveryStatus,
			isMediaLibraryReplacementRunnerReady,
			isMediaLibraryReplacementOwnedByAnotherDashboard,
			getMediaLibraryReplacementWorkflowButtonState,
			getMediaLibraryReplacementWorkflowButtonClass,
			getMediaLibraryReplacementRunnerUnavailableMessage,
			getMediaLibraryReplacementActionClass,
			isMediaLibraryReplacementReadinessRunnerReady,
			getMediaLibraryReplacementPrepareLabel,
			getMediaLibraryReplacementDoLabel,
			getMediaLibraryReplacementVerifyLabel,
			getMediaLibraryReplacementDeleteLabel,
			prepareMediaLibraryReplacementWorkflow,
			doMediaLibraryReplacementWorkflow,
			verifyMediaLibraryReplacementWorkflow,
			deleteMediaLibraryReplacementOriginalsWorkflow,
			restartMediaLibraryReplacementWorkflow,
			recoverMediaLibraryReplacementWorkflow,
			prepareMediaLibraryReplacementFoundation,
			openMediaLibraryReplacementPreviewModal,
			openMediaLibraryReplacementDbPreviewModal,
			openMediaLibraryReplacementCleanupPreviewModal,
			copyMediaLibraryReplacementFiles,
			prepareMediaLibraryReplacementMetadataUpdates,
			applyMediaLibraryReplacementMetadataUpdates,
			scanMediaLibraryReplacementReferences,
			matchMediaLibraryReplacementReferences,
			applyMediaLibraryReplacementDatabaseReplacements,
			verifyMediaLibraryReplacementDatabaseReplacements,
			rollbackMediaLibraryReplacementDatabaseReplacements,
			scanMediaLibraryReplacementThemeCssReferences,
			previewMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementThemeCssReplacements,
			verifyMediaLibraryReplacementThemeCssReplacements,
			rollbackMediaLibraryReplacementMetadataUpdates,
		});
		return h('div', { className: 'max-w-6xl p-6 space-y-8' }, [
			h('header', { className: 'uc-dashboard-header flex flex-col gap-4 md:flex-row md:justify-between md:items-end', key: 'header' }, [
				h('div', { key: 'title' }, [
					h('h1', { className: 'text-3xl font-black tracking-tighter m-0 text-white' }, 'UltraCache'),
					h(
						'p',
						{ className: 'text-zinc-500 text-xs tracking-widest mt-2 mb-0' },
						__("Page cache, object cache, compression, warmups, fonts, and next-gen images", 'ultracache')
					),
				]),
				h('div', { className: 'uc-dashboard-header-actions flex flex-wrap gap-3', key: 'actions' }, [
					h(AdminThemeToggle, {
						checked: isUltraCacheTheme,
						disabled: adminThemeSaving,
						onChange: (enabled) => {
							setAdminTheme(enabled ? THEME_ULTRACACHE : THEME_NATIVE).catch(() => {});
						},
						key: 'admin-theme-toggle',
					}),
					h(Button, {
						onClick: purgeCache,
						disabled: busy || !!asyncActions.purge_all || (!canManageInfrastructure && !!settings.flushAllIncludeVarnish && !!settings.varnishCliEnabled && !!settings.varnishConnectionConfigured),
						title: (!canManageInfrastructure && !!settings.flushAllIncludeVarnish && !!settings.varnishCliEnabled && !!settings.varnishConnectionConfigured) ? 'Flush All Cache includes Varnish and requires plugin activation or network plugin management permission.' : '',
						variant: 'primary'
					}, asyncActions.purge_all ? 'Processing via dashboard…' : (busy ? 'Working…' : 'Flush All Cache')),
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
			h(VersionHelpModal, {
				open: versionHelpModalOpen,
				version: ultracache.version || '',
				onClose: closeVersionHelpModal,
				key: 'version-help-modal',
			}),

			renderMediaConversionTestModal(),
			renderMediaLibraryReplacementWarningModal(),
			renderMediaLibraryReplacementPreviewModal(),
			renderMediaLibraryReplacementDbPreviewModal(),
			renderMediaLibraryReplacementBlockersModal(),
			renderMediaLibraryReplacementCleanupPreviewModal(),

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
						title: __("Cache Engine", 'ultracache'),
						description: __("Core page cache behavior, compression variants, and safe prefetch hints.", 'ultracache'),
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
							label: __("Purge after WordPress core updates", 'ultracache'),
							description: __("Clear page cache and generated frontend assets after a successful WordPress version update. Object cache and optimized images are not cleared.", 'ultracache'),
							checked: !!settings.purgeAfterCoreUpdatesEnabled,
							onChange: (value) => updateSetting('purgeAfterCoreUpdatesEnabled', value),
							disabled: busy,
							key: 'purge-after-core-updates',
						}),
						h(ToggleRow, {
							label: __("Purge after active plugin updates", 'ultracache'),
							description: __("Clear page cache and generated frontend assets once after successful updates that include an active or network-active plugin. Inactive plugin updates are ignored.", 'ultracache'),
							checked: !!settings.purgeAfterPluginUpdatesEnabled,
							onChange: (value) => updateSetting('purgeAfterPluginUpdatesEnabled', value),
							disabled: busy,
							key: 'purge-after-plugin-updates',
						}),
						h(ToggleRow, {
							label: __("Purge after active theme updates", 'ultracache'),
							description: __("Clear page cache and generated frontend assets once after successful updates to the active child theme or its active parent theme. Inactive theme updates are ignored.", 'ultracache'),
							checked: !!settings.purgeAfterThemeUpdatesEnabled,
							onChange: (value) => updateSetting('purgeAfterThemeUpdatesEnabled', value),
							disabled: busy,
							key: 'purge-after-theme-updates',
						}),
h(ToggleRow, {
							label: __("Warm affected pages after save", 'ultracache'),
							description: __("Automatically rebuild affected public HTML pages after real content changes. The shared queue warms active HTML variants and configured CSS bundles; when Varnish is enabled, it also invalidates and refills the same URLs. Autosaves, revisions, and feeds are excluded from warm processing.", 'ultracache'),
							checked: settings.preRenderOnSave,
							onChange: (value) => updateSetting('preRenderOnSave', value),
							disabled: busy,
							key: 'preload',
						}),
h(ToggleRow, {
							label: __('Browser Cache Headers', 'ultracache') + ' (.htaccess)',
							description: __("Write browser cache headers for static assets, manifests, WASM, media, fonts, CSS, and JS on Apache-compatible hosts while keeping HTML, JSON, and XML revalidation-oriented.", 'ultracache'),
							checked: settings.browserCacheRulesEnabled,
							onChange: (value) => updateSetting('browserCacheRulesEnabled', value),
							disabled: busy,
							key: 'browser-cache-rules',
						}),
						h(ToggleRow, {
							label: __('Apache Static HTML Delivery', 'ultracache') + ' (.htaccess)',
							description: __("Serve already-built anonymous HTML cache aliases directly through Apache for safe queryless GET requests before WordPress/PHP starts.", 'ultracache'),
							checked: !!settings.apacheStaticHtmlDeliveryEnabled,
							onChange: (value) => updateSetting('apacheStaticHtmlDeliveryEnabled', value),
							disabled: busy || !settings.pageCacheEnabled || !!settings.liteSpeedCacheEnabled,
							disabledReason: settings.liteSpeedCacheEnabled
								? __('Apache Static HTML Delivery cannot be enabled while Native LiteSpeed HTML Cache is active. Disable Native LiteSpeed HTML Cache first.', 'ultracache')
								: '',
							tooltip: __("What it does: lets Apache serve a ready-made cached HTML file before WordPress and PHP start.\n\nWhy it helps: safe repeat visits can skip the WordPress kitchen completely and get the saved page from the shelf.\n\nWatch for: this only applies to safe anonymous queryless GET requests. It skips unsafe cookies, query strings, admin, login, REST, AJAX, WooCommerce dynamic paths, cart, checkout, account, and session-like visits. PHP debug headers and PHP hit counters do not run for these server-level hits.", 'ultracache'),
							key: 'apache-static-html-delivery',
						}),
							h('div', { className: 'py-4', key: 'html-compression-delivery' }, [
								h('div', { className: 'uc-field-wrap' }, [
									h('label', { className: 'block text-sm font-medium text-white' }, renderLabelWithHelp(
										__('HTML Compression', 'ultracache'),
										getHtmlCompressionDeliveryDescription(browserCompressionProbe)
									)),
									h('div', { className: 'text-xs text-zinc-500 mt-1 mb-2' }, getHtmlCompressionDeliveryDescription(browserCompressionProbe)),
									h(CustomSelect, {
										value: browserCompressionProbe.serverCompression ? 'off' : getHtmlCompressionDeliveryValue(settings),
										onChange: (value) => updateHtmlCompressionDelivery(value),
										disabled: busy,
										loading: compressionProbeBusy,
										loadingLabel: __('Please wait…', 'ultracache'),
										onBeforeOpen: probeHtmlCompressionOptions,
										options: [
											{
												value: 'off',
												label: browserCompressionProbe.serverCompression ? __('Server managed', 'ultracache') : __('Off', 'ultracache'),
											},
											{
												value: 'gzip',
												label: __('Gzip compression', 'ultracache'),
												disabled: !browserCompressionProbe.probed || browserCompressionProbe.serverCompression || browserCompressionProbe.blocked || !browserCompressionProbe.gzipAvailable,
											},
											{
												value: 'brotli',
												label: __('Brotli compression', 'ultracache'),
												disabled: !browserCompressionProbe.probed || browserCompressionProbe.serverCompression || browserCompressionProbe.blocked || !browserCompressionProbe.brotliAvailable,
											},
										],
									}),
								]),
							]),
							h(ToggleRow, {
								label: __("Speculation Rules Prefetch", 'ultracache'),
								description: __("Enable safe prefetch-only speculative loading for likely next-page internal navigations through WordPress Core. Logged-in users, query-string links, WooCommerce flows, admin-like paths, nofollow links, and visible excluded paths stay excluded.", 'ultracache'),
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
						title: __("Warm Cache", 'ultracache'),
						description: __("Crawl public URLs and prebuild static cache files.", 'ultracache'),
						key: 'warm',
					},
					[
						warmDisabledMessage ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'warm-disabled-message' }, warmDisabledMessage) : null,
						h(ToggleRow, {
							label: __("Warm uncached URLs after first visit", 'ultracache'),
							description: __("When an uncached public URL is first visited, UltraCache queues that URL for background warming through the shared warm pipeline. Processing follows the Background warm pages per minute limit.", 'ultracache'),
							checked: !!settings.warmUncachedUrlsOnFirstVisit,
							onChange: (value) => updateSetting('warmUncachedUrlsOnFirstVisit', value),
							disabled: busy || !pageCacheReady,
							key: 'warm-uncached-first-visit',
						}),
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

			]),

			SettingsAccordionCard({
						keyName: 'query-string-args-caching-box',
						title: __("Query-string args caching", 'ultracache'),
						description: __("Control which query-string URL variants are eligible for public HTML cache.", 'ultracache'),
						children: [
							h(ToggleRow, {
															label: __("Enable query-string args caching", 'ultracache'),
															description: __("Allow UltraCache to cache URL variants that include query-string args. Excluded query-string args always bypass cache. The whitelist below must contain every query key; an empty whitelist bypasses query-string caching.", 'ultracache'),
															checked: !!settings.cacheQueryStringsEnabled,
															onChange: (value) => updateSetting('cacheQueryStringsEnabled', value),
															disabled: busy,
															key: 'query-string-caching',
														}),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'query-string-fields' }, [
															h(SaveableTextAreaField, {
																											label: __("Query-string args whitelist", 'ultracache'),
																											description: __("Required when query-string caching is enabled. Add one query key per line. UltraCache caches a query-string URL only when every query arg is listed here; an empty whitelist bypasses query-string caching. Populate appends detected attributes, taxonomies, categories and tags without removing custom entries.", 'ultracache'),
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
														]),
						],
					}),
			h(ProgressPanel, {
				process,
				etaText,
				onCancel: requestCancel,
				showWhenInactive: !!process.showWhenInactive,
				key: 'job-progress-after-jobs',
			}),

			
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'settings' }, [

				h(
				Card,
				{
					title: __("Media Optimization", 'ultracache'),
					description: __("Controls frontend AVIF/WebP URL rewriting, missing-media queueing, and image frontend handling.", 'ultracache'),
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
						label: __("Rewrite on upload", 'ultracache'),
						description: __("When enabled, newly uploaded images and their registered thumbnail sizes are queued for next-gen conversion.", 'ultracache'),
						checked: !!settings.mediaGenerateOnUploadEnabled,
						onChange: (value) => updateSetting('mediaGenerateOnUploadEnabled', value),
						disabled: busy || !mediaOptimizationEnabled,
						key: 'media-generate-upload',
					}),
					h('div', { key: 'media-generate-demand-safety' }, [
						h(ToggleRow, {
							label: __("Queue Missing Media During Page Generation", 'ultracache'),
							description: __("When enabled, UltraCache queues missing AVIF/WebP variants while WordPress generates or regenerates page HTML, including uncached requests, warm-up, cron warm, and stale regeneration. Conversion runs separately in the background.", 'ultracache'),
							checked: !!settings.mediaGenerateOnDemandEnabled,
							onChange: (value) => updateSetting('mediaGenerateOnDemandEnabled', value),
							disabled: busy || !mediaOptimizationEnabled,
							key: 'media-generate-demand',
						}),
						h(NumberRow, {
							label: __("Pause after stale workers", 'ultracache'),
							description: __("Pause automatic media generation after this many stale worker incidents are detected within the rolling safety window.", 'ultracache'),
							value: Math.max(1, Number(settings.mediaStaleWorkerThreshold || 3)),
							onChange: (value) => updateSetting('mediaStaleWorkerThreshold', value),
							disabled: busy || !mediaOptimizationEnabled,
							min: 1,
							step: 1,
							key: 'media-stale-worker-threshold',
						}),
						h('div', { className: 'text-xs pb-4', key: 'media-worker-safety-summary' }, [
							h('div', { className: 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1', key: 'media-safety-status' }, [
								h('span', { className: 'text-zinc-500' }, __('Media worker safety', 'ultracache')),
								h('span', { className: 'font-bold ' + mediaBackgroundSafetyStatusClass }, mediaBackgroundSafetyStatus),
							]),
							h('div', { className: 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1', key: 'media-safety-incidents' }, [
								h('span', { className: 'text-zinc-500' }, __('Recent stale workers', 'ultracache')),
								h('span', { className: mediaBackgroundStaleCount > 0 ? 'font-semibold text-amber-300' : 'text-zinc-300' }, mediaBackgroundStaleCount + '/' + mediaBackgroundStaleThreshold + (mediaBackgroundStaleWindowSeconds > 0 ? (' in ' + formatDurationSeconds(mediaBackgroundStaleWindowSeconds)) : '')),
							]),
							h('div', { className: 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1', key: 'media-safety-cooldown' }, [
								h('span', { className: 'text-zinc-500' }, __('Cooldown', 'ultracache')),
								h('span', { className: mediaBackgroundCoolingDown ? 'font-semibold text-amber-300' : 'text-zinc-300' }, mediaBackgroundCoolingDown ? formatDurationSeconds(mediaBackgroundCooldownRemaining) + ' remaining' : 'None'),
							]),
							h('div', { className: 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1', key: 'media-safety-last-quarantine' }, [
								h('span', { className: 'text-zinc-500' }, __('Last quarantine', 'ultracache')),
								h('span', { className: mediaBackgroundLastStaleItem ? 'font-semibold text-amber-300 text-right' : 'text-zinc-300' }, mediaBackgroundLastStaleLabel),
							]),
							h('div', { className: 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1', key: 'media-safety-action' }, [
								h('span', { className: 'text-zinc-500' }, __('Automatic action', 'ultracache')),
								h('span', { className: mediaBackgroundPaused ? 'font-semibold text-red-300 text-right' : (mediaBackgroundCoolingDown ? 'font-semibold text-amber-300 text-right' : 'text-zinc-300 text-right') }, mediaBackgroundAutomaticAction),
							]),
						]),
					]),
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
						label: __("LCP Frontend Discovery", 'ultracache'),
						description: __("Adds a lightweight browser discovery layer on top of LCP Image Priority. It observes the actual browser LCP for more accurate page-specific priority. The frontend cost is very small, and discovery stops for each page and viewport after the same result is confirmed in two of the last three visits.", 'ultracache'),
						checked: !!settings.lcpFrontendDiscoveryEnabled,
						onChange: (value) => updateSetting('lcpFrontendDiscoveryEnabled', value),
						disabled: busy || !settings.lcpImagePriorityEnabled,
						key: 'media-lcp-frontend-discovery',
					}),
					h(ToggleRow, {
						label: __("Lazy load & async images", 'ultracache'),
						description: __("Adds native loading=\"lazy\" and decoding=\"async\" to eligible images. If LCP Image Priority is enabled, UltraCache only lazy-loads images printed after the detected LCP image.", 'ultracache'),
						checked: !!settings.lazyLoadImagesEnabled,
						onChange: (value) => updateSetting('lazyLoadImagesEnabled', value),
						disabled: busy,
						key: 'media-lazy-load-images',
					}),
					h(ToggleRow, {
						label: __("Lazy load third-party iframes", 'ultracache'),
						description: __("Delays eligible offscreen third-party embeds, such as Google Maps and videos, until they are within 400 pixels of the viewport. Payment, authentication, CAPTCHA, hidden or functional frames, and critical checkout/account pages remain unchanged.", 'ultracache'),
						checked: !!settings.lazyLoadThirdPartyIframesEnabled,
						onChange: (value) => updateSetting('lazyLoadThirdPartyIframesEnabled', value),
						disabled: busy,
						key: 'media-lazy-load-third-party-iframes',
					}),
					!mediaSupport.supported
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
					title: __("AVIF / WebP Batch Conversion", 'ultracache'),
					description: __("Queue-based conversion and maintenance for existing uploads.", 'ultracache'),
					key: 'batch-media-conversion',
				},
				[
					h('div', { className: 'text-xs text-zinc-500 mt-1', key: 'media-batch-support-summary' }, 'Conversion support: Imagick installed ' + (mediaSupport.imagick ? 'Yes' : 'No') + ' · Imagick AVIF test ' + (mediaSupport.imagick_avif ? 'Passed' : 'Failed') + ' · Imagick WebP available ' + (mediaSupport.imagick_webp ? 'Yes' : 'No') + ' · GD AVIF test ' + (mediaSupport.gd_avif ? 'Passed' : 'Failed') + ' · GD WebP available ' + (mediaSupport.gd_webp ? 'Yes' : 'No')),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-4 mt-4', key: 'media-batch-summary' }, [
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'optimized-files' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Optimized image files", 'ultracache')),
							h('div', { className: 'text-2xl font-black text-white mt-1' }, formatNumber(optimizedImagesTotal || 0)),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, formatNumber(optimizedAvifTotal || 0) + ' AVIF · ' + formatNumber(optimizedWebpTotal || 0) + ' WebP'),
						]),
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'queue-status' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Media status", 'ultracache')),
							h('div', { className: 'text-2xl font-black text-white mt-1' }, formatNumber(mediaQueueTotal)),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, formatNumber(mediaQueuePending) + ' pending · ' + formatNumber(mediaQueueAlreadyOptimized) + ' already optimized · ' + formatNumber(mediaQueueFailed) + ' failed' + (mediaQueueRecoverableInterrupted > 0 ? (' · ' + formatNumber(mediaQueueRecoverableInterrupted) + ' interrupted') : '')),
						]),
						h('div', { className: 'rounded-xl bg-white/5 px-4 py-3', key: 'queue-health' }, [
							h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Queue health", 'ultracache')),
							h('div', { className: mediaQueueNeedsRepair ? 'text-lg font-black text-amber-300 mt-1' : 'text-lg font-black text-emerald-300 mt-1' }, mediaQueueNeedsRepair ? 'Needs repair' : (mediaQueueIsComplete ? 'Complete' : 'Ready')),
							h('div', { className: 'text-xs text-zinc-500 mt-1' }, 'Target policy: ' + (('avif' === settings.mediaOutputMode) ? ('avif / fallback ' + (('webp' === settings.mediaFallbackFormat) ? 'webp' : 'original')) : 'webp / fallback original') + ' · queue format: best'),
						]),
					]),

					h('div', { className: 'mt-5 uc-media-batch-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' }, key: 'media-batch-actions' }, [
						h('div', { key: 'start' }, [
							h('div', { className: 'uc-media-start-row', key: 'start-row' }, [
								h('button', {
									className: 'uc-btn uc-btn--primary text-white py-3 font-bold uc-media-start-row__item',
									onClick: startMediaOptimizationFromDashboard,
									disabled: busy || mediaBackgroundPaused || mediaBackgroundCoolingDown || !mediaOptimizationEnabled || !mediaSupport.supported,
								}, getMediaStartButtonLabel()),
								h('div', { className: 'uc-media-regenerate-existing uc-media-start-row__item', key: 'regenerate-existing' }, [
									h('span', { className: 'uc-media-regenerate-existing__copy', key: 'regenerate-copy' }, [
										h('span', { className: 'uc-media-regenerate-existing__title' }, __('Regen. existing', 'ultracache')),
									]),
									h('label', { className: classNames('uc-toggle', 'uc-media-regenerate-existing__toggle', busy ? 'opacity-60 pointer-events-none' : ''), key: 'regenerate-toggle' }, [
										h('input', {
											type: 'checkbox',
											checked: !!mediaRegenerateExistingEnabled,
											disabled: busy,
											onChange: (event) => setMediaRegenerateExistingEnabled(event.target.checked),
										}),
										h('span', { className: 'slider' }),
									]),
								]),
							]),
							h('div', { className: (mediaRegenerateExistingEnabled || hasSavedMediaRegenerationJob()) ? 'text-xs text-amber-300 mt-2' : 'text-xs text-zinc-500 mt-2' }, getMediaStartHelpText()),
						]),
						h('div', { key: 'rebuild' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: () => rebuildMediaQueue(false),
								disabled: busy || !mediaOptimizationEnabled || !mediaSupport.supported,
							}, busy ? 'Engine Busy' : (getJobControls('media_rebuild').canResume ? 'Resume Scan Media Library & Repair Status' : 'Scan Media Library & Repair Status')),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Scans the Media Library, adds missing queue rows, and requeues attachments whose required optimized files are missing or older than their source images.", 'ultracache')),
						]),
						getJobControls('media_rebuild').canRestart ? h('div', { key: 'rebuild-restart' }, [
								h('button', {
									className: 'uc-btn w-full text-white py-3 font-bold',
									onClick: () => rebuildMediaQueue(true),
									disabled: busy || !mediaOptimizationEnabled || !mediaSupport.supported,
								}, busy ? 'Engine Busy' : 'Restart Scan Media Library & Repair Status'),
								h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Starts the rebuild and repair scan from the beginning instead of resuming the saved offset.", 'ultracache')),
							]) : null,
							h('div', { key: 'recount-files' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: recountOptimizedImageFiles,
								disabled: busy,
							}, busy ? 'Engine Busy' : __("Refresh Optimized File Counts", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Scans uploads/ultracache/images and corrects the AVIF/WebP file counter.", 'ultracache')),
						]),
						h('div', { key: 'retry' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: retryFailedMediaQueue,
								disabled: busy || !mediaOptimizationEnabled || !mediaSupport.supported || mediaQueueRetryable <= 0,
							}, busy ? 'Engine Busy' : 'Retry Failed'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Recovers interrupted queue rows and moves failed rows back to pending so image conversion can continue.", 'ultracache')),
						]),
						h('div', { key: 'clear-completed' }, [
							h('button', {
								className: 'uc-btn !bg-zinc-800 !text-white !border-white/10 w-full text-white py-3 font-bold',
								onClick: clearCompletedMediaQueue,
								disabled: busy || !mediaOptimizationEnabled || mediaQueueAlreadyOptimized <= 0,
							}, busy ? 'Engine Busy' : 'Clear Completed Queue History'),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, __("Removes completed queue history only. It does not delete AVIF/WebP files.", 'ultracache')),
						]),
						h('div', { key: 'background-control' }, [
							h('button', {
								className: 'uc-btn w-full text-white py-3 font-bold',
								onClick: () => toggleMediaBackgroundWork(!mediaBackgroundPaused),
								disabled: mediaBackgroundControlBusy,
							}, mediaBackgroundControlBusy
								? 'Updating Media Background State…'
								: (mediaBackgroundPaused ? 'Resume All Media Background Work' : 'Stop All Media Background Work')),
							h('div', { className: (mediaBackgroundPaused || mediaBackgroundCoolingDown) ? 'text-xs text-amber-300 mt-2' : 'text-xs text-zinc-500 mt-2' }, mediaBackgroundPaused
								? (mediaBackgroundPauseMessage || __("Media generation is stopped. Pending queue rows and existing optimized files are preserved.", 'ultracache'))
								: (mediaBackgroundCoolingDown
									? __("Automatic media work is temporarily waiting for the worker safety cooldown.", 'ultracache')
									: __("Stops upload, page-generation, cron, immediate-worker, and dashboard media conversion after the current physical file finishes.", 'ultracache'))),
						]),
					]),
				]
			),
				h(LcpDiagnosticsCard, {
					settings,
					busy,
					onSettingChange: updateSetting,
					onQuery: queryLcpObservations,
					onDetail: queryLcpObservationDetail,
					queryBusy: lcpDiagnosticsQueryBusy,
					onSaveManualSelector: saveLcpObservationManualSelector,
					onAction: runLcpObservationAction,
					busyKey: lcpDiagnosticsBusyKey,
					key: 'lcp-diagnostics-card',
				}),
				h('div', { className: 'uc-media-settings-replacement-card', style: { gridColumn: '1 / -1' }, key: 'media-settings-library-replacement-wrap' }, [
				SettingsAccordionCard({
					keyName: 'media-settings-library-replacement-box',
					title: __("Media settings & Media Library replacement", 'ultracache'),
					description: __("Upload format, new-upload conversion, and Media Library replacement controls.", 'ultracache'),
					children: [
						h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'media-settings-replacement-grid' }, [
							h('div', { className: 'grid grid-cols-1 gap-4', key: 'media-settings-left' }, [
								h(ToggleRow, {
									label: __("Convert new uploads", 'ultracache'),
									description: __("Convert the actual uploaded image file to the selected Upload image format during WordPress upload.", 'ultracache'),
									checked: !!settings.mediaUploadConversionEnabled,
									onChange: (value) => updateSetting('mediaUploadConversionEnabled', value),
									disabled: busy,
									key: 'media-upload-conversion-enabled',
								}),
								h(NumberRow, {
									label: __("Maximum upload image side", 'ultracache'),
									description: __("Maximum width or height for newly converted uploads. Smaller images keep their original dimensions and are only converted to the selected upload format.", 'ultracache'),
									value: Math.max(1, Number(settings.imageUploadMaxSide || 1920)),
									onChange: (value) => updateSetting('imageUploadMaxSide', value),
									disabled: busy,
									min: 1,
									max: 8192,
									step: 1,
									key: 'media-upload-max-side',
								}),
								h('div', { className: 'uc-media-upload-format-field', key: 'media-upload-format-wrap' }, h(SelectField, {
									label: __("Upload image format", 'ultracache'),
									description: __("Choose the format used for the actual uploaded attachment file when Convert new uploads is enabled.", 'ultracache'),
									value: ('avif' === settings.mediaUploadFormat) ? 'avif' : 'webp',
									onChange: (value) => updateSetting('mediaUploadFormat', value),
									disabled: busy,
									options: [
										{ value: 'avif', label: (mediaSupport.imagick_avif || mediaSupport.gd_avif) ? __("AVIF Format", 'ultracache') : __("AVIF Format (Self-Test Failed)", 'ultracache') },
										{ value: 'webp', label: __("WebP Format", 'ultracache') },
									],
								})),
								h(ToggleRow, {
									label: __("Ignore color profile preservation", 'ultracache'),
									description: __("Allow AVIF/WebP conversion when an embedded ICC/ICM profile cannot be verified or preserved. Converted colors may differ slightly; Convert new uploads and Media Library Replacement still change attachment files when their workflows run.", 'ultracache'),
									checked: !!settings.mediaIgnoreColorProfilePreservation,
									onChange: (value) => updateSetting('mediaIgnoreColorProfilePreservation', value),
									disabled: busy,
									key: 'media-ignore-color-profile-preservation',
								}),
								h('div', { className: 'uc-media-output-mode-field', key: 'media-output-mode-wrap' }, h(SelectField, {
									label: __("Image Rewrite Format", 'ultracache'),
									description: __("Choose the primary format used for generated variants and frontend rewrites. Older Automatic settings are migrated to WebP.", 'ultracache'),
									value: ('avif' === settings.mediaOutputMode) ? 'avif' : 'webp',
									onChange: (value) => updateSetting('mediaOutputMode', value),
									disabled: busy || !mediaOptimizationEnabled,
									options: [
										{ value: 'avif', label: (mediaSupport.imagick_avif || mediaSupport.gd_avif) ? __("AVIF Format", 'ultracache') : __("AVIF Format (Self-Test Failed)", 'ultracache') },
										{ value: 'webp', label: __("WebP Format", 'ultracache') },
									],
								})),
								h('div', { className: 'uc-media-fallback-format-field', key: 'media-fallback-format-wrap' }, h(SelectField, {
									label: __("Image Rewrite Fallback Format", 'ultracache'),
									description: ('avif' === settings.mediaOutputMode)
										? __("Choose what UltraCache should use when AVIF is unavailable or not accepted by the browser.", 'ultracache')
										: __("WebP output falls back to the original attachment file.", 'ultracache'),
									value: ('avif' === settings.mediaOutputMode && 'webp' === settings.mediaFallbackFormat) ? 'webp' : 'original',
									onChange: (value) => updateSetting('mediaFallbackFormat', value),
									disabled: busy || !mediaOptimizationEnabled || 'avif' !== settings.mediaOutputMode,
									options: ('avif' === settings.mediaOutputMode) ? [
										{ value: 'webp', label: __("WebP fallback", 'ultracache') },
										{ value: 'original', label: __("Original file fallback", 'ultracache') },
									] : [
										{ value: 'original', label: __("Original file fallback", 'ultracache') },
									],
								})),
								h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'media-output-mode-description' },
									('avif' === settings.mediaOutputMode)
										? (('webp' === settings.mediaFallbackFormat)
											? __("Generate and prefer AVIF variants, with WebP as the optimized fallback.", 'ultracache')
											: __("Generate and prefer AVIF variants, with the original attachment file as fallback.", 'ultracache'))
										: __("Generate and prefer WebP variants, with the original attachment file as fallback.", 'ultracache')
								),
								h('div', { className: 'uc-media-quality-field', key: 'media-quality-wrap' }, h(SelectField, {
									label: __("Image compression level", 'ultracache'),
									description: __("Choose the shared quality/file-size target used for generated AVIF/WebP variants, converted uploads, and Media Library Replacement files.", 'ultracache'),
									value: settings.mediaQuality || 'balanced',
									id: 'ultracache-media-quality-select',
									name: 'ultracache-media-quality-select',
									dataSettingKey: 'mediaQuality',
									onChange: (value) => updateSetting('mediaQuality', value),
									disabled: busy,
									options: [
										{ value: 'original', label: __("Original-like quality — minimal loss, largest files", 'ultracache') },
										{ value: 'high', label: __("High quality — slight loss, smaller files", 'ultracache') },
										{ value: 'balanced', label: __("Balanced — default quality and file-size tradeoff", 'ultracache') },
										{ value: 'compact', label: __("Compact — visible compression, smaller files", 'ultracache') },
										{ value: 'smallest', label: __("Smallest files — strongest compression loss", 'ultracache') },
									],
								})),
								h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'media-quality-description' },
									('original' === settings.mediaQuality)
										? __("Closest visual match to the source image; expect the largest generated files.", 'ultracache')
										: ('high' === settings.mediaQuality)
											? __("Small visual loss with better file savings than original-like quality.", 'ultracache')
											: ('compact' === settings.mediaQuality)
												? __("Smaller files with visible compression on detailed images.", 'ultracache')
												: ('smallest' === settings.mediaQuality)
													? __("Maximum size reduction; use only when file size matters more than image fidelity.", 'ultracache')
													: __("Balanced keeps the previous UltraCache defaults: AVIF 60 and WebP 82.", 'ultracache')
								),
								renderMediaConversionTestControls(),
							]),
							renderMediaLibraryReplacementControls(),
						]),
					],
				}),
			]),
				h(
					Card,
					{
						title: __("Frontend Javascript manipulation", 'ultracache'),
						description: __("Control frontend JavaScript defer/delay behavior and the unified delayed JS release timing.", 'ultracache'),
						key: 'frontend-js-request-chains-card',
					},
					[
						h('div', { className: 'py-4', key: 'javascript-strategy' }, [
							h(SelectField, {
								label: __("JavaScript Strategy", 'ultracache'),
								description: __("Choose the base JavaScript mode. This only changes Defer JS and Delay all JS; third-party, local, and LCP delay controls below stay independent.", 'ultracache'),
								value: getJavascriptStrategyValue(settings),
								onChange: (value) => updateJavascriptStrategy(value),
								disabled: busy,
								options: [
									{ value: 'off', label: __('Off', 'ultracache') },
									{ value: 'defer', label: __('Defer', 'ultracache') },
									{ value: 'delay', label: __('Delay', 'ultracache') },
								],
							}),
							h('div', { className: 'text-xs text-zinc-500 mt-2' }, getJavascriptStrategyDescription(getJavascriptStrategyValue(settings))),
						]),

h(ToggleRow, {
									label: __("First-party parallel execution", 'ultracache'),
									description: __("Applies parallel execution to eligible same-origin frontend JavaScript in Defer and Delay modes.", 'ultracache'),
									checked: !!settings.firstPartyJsParallelExecutionEnabled,
									onChange: (value) => updateSetting('firstPartyJsParallelExecutionEnabled', value),
									disabled: busy,
									key: 'first-party-js-parallel-execution',
								}),
	h(ToggleRow, {
									label: __("Third-party parallel execution", 'ultracache'),
									description: __("Applies parallel execution to eligible third-party frontend JavaScript in Defer and Delay modes.", 'ultracache'),
									checked: !!settings.thirdPartyJsParallelExecutionEnabled,
									onChange: (value) => updateSetting('thirdPartyJsParallelExecutionEnabled', value),
									disabled: busy,
									key: 'third-party-js-parallel-execution',
								}),

h(ToggleRow, {
									label: __("LCP Boundary Delay", 'ultracache'),
									description: __("Uses the LCP image detected by LCP Image Priority as a visual boundary. Eligible local scripts printed after that image in the HTML are delayed through the UltraCache delayed loader.", 'ultracache'),
									checked: !!settings.lcpBoundaryDeferEnabled,
									onChange: (value) => updateSetting('lcpBoundaryDeferEnabled', value),
									disabled: busy || !settings.lcpImagePriorityEnabled,
									key: 'lcp-boundary-defer',
								}),

h(ToggleRow, {
									label: __("Delay safe third-party JS", 'ultracache'),
									description: __("Delay analytics, pixels, ads, tracking, and marketing scripts according to the unified Delayed JS auto-start controls, keeping them out of the initial PageSpeed/LCP/TBT critical window. Examples: Google Analytics, gtag, GTM, Google Site Kit event providers, Meta Pixel, TikTok Pixel, LinkedIn Insight, Pinterest Tag, Bing UET, Hotjar, Clarity, DoubleClick, Google Ads, Taboola, Outbrain, and Yahoo tracking.", 'ultracache'),
									checked: settings.delaySafeThirdPartyJsEnabled,
									onChange: (value) => updateSetting('delaySafeThirdPartyJsEnabled', value),
									disabled: busy,
									key: 'delay-safe-third-party-js',
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
									label: __("Delay known functional third-party JS", 'ultracache'),
									description: __("Delay matched third-party scripts that provide visible functionality, such as cookie banners, captcha, maps, chat widgets, booking widgets, embedded forms, opt-in popups, newsletter widgets, and review widgets. Matching is based on the visible include/exclude patterns below. If a form, map, captcha, checkout, or cookie banner misbehaves, add its script keyword to exclusions.", 'ultracache'),
									checked: !!settings.delayFunctionalThirdPartyJsEnabled,
									onChange: (value) => updateSetting('delayFunctionalThirdPartyJsEnabled', value),
									disabled: busy,
									key: 'delay-functional-third-party-js',
								}),
	h(ToggleRow, {
										label: __("Delay all third-party JS", 'ultracache'),
										description: __("Delays external scripts loaded from third-party domains according to the unified Delayed JS auto-start controls. Defer Instead of Delay and Do Not Defer or Delay are respected; use Do Not Defer or Delay for captcha, payments, consent, login, booking, or critical form scripts that must run immediately.", 'ultracache'),
										checked: !!settings.delayAllThirdPartyJsEnabled,
										onChange: (value) => updateSetting('delayAllThirdPartyJsEnabled', value),
										disabled: busy,
										key: 'delay-all-third-party-js',
									}),

h('div', { className: 'mt-4 pt-4 border-t border-white/5', key: 'delayed-js-auto-start-controls' }, [
										h('div', { className: 'text-sm font-medium text-white' }, renderLabelWithHelp(__('Delayed JS auto-start', 'ultracache'), __("What it does: controls when all delayed JavaScript queues are allowed to run.\n\nWhy it helps: delayed scripts stay out of the first loading rush, then release by visitor interaction or by the fallback timer.\n\nWatch for: event triggers are useful when a widget must wake as soon as the visitor interacts. Leaving events off gives a cleaner timer-only test.", 'ultracache'))),
										h('div', { className: 'text-xs text-zinc-500 mt-1 mb-3' }, __('Controls when all delayed JavaScript queues are released. Applies to Delay all JS, Delay non-critical/local JS, LCP Boundary Delay, known functional third-party delay, and all third-party delay.', 'ultracache')),
										h(DelayedJsAutostartEventsField, {
											label: __('Event triggers', 'ultracache'),
											description: __('Optional. Keep these off for pure timer-based release. If enabled, these events can request the delayed JS queue release.', 'ultracache'),
											settings: settings,
											onChange: (key, value) => updateSetting(key, value),
											disabled: busy,
											key: 'delayed-js-autostart-events-dropdown',
										}),
										h(SelectField, {
											label: __('If no event happens, autostart JS after', 'ultracache'),
											description: __('Fallback timer for all delayed JavaScript queues.', 'ultracache'),
											value: String(typeof settings.delayedLocalJsAutoStartSeconds !== 'undefined' ? settings.delayedLocalJsAutoStartSeconds : 0.05),
											onChange: (value) => queueSettingsPatch({ delayedLocalJsAutoStart: 'custom', delayedLocalJsAutoStartSeconds: Number(value) }),
											disabled: busy,
											options: [
												{ value: '0.05', label: __('0.05 seconds', 'ultracache') },
												{ value: '0.1', label: __('0.1 seconds', 'ultracache') },
												{ value: '0.5', label: __('0.5 seconds', 'ultracache') },
												{ value: '1', label: __('1 second', 'ultracache') },
												{ value: '2', label: __('2 seconds', 'ultracache') },
												{ value: '3', label: __('3 seconds', 'ultracache') },
												{ value: '4', label: __('4 seconds', 'ultracache') },
												{ value: '5', label: __('5 seconds', 'ultracache') },
											],
											key: 'delayed-js-auto-start-fallback',
										}),
									])

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
h(ToggleRow, {
							label: __("Async external CSS", 'ultracache'),
							description: __("Converts stylesheet links from other domains to non-render-blocking async CSS. Same-site stylesheets continue through the normal CSS optimization/bundling flow.", 'ultracache'),
							checked: !!settings.asyncExternalCssEnabled,
							onChange: (value) => updateSetting('asyncExternalCssEnabled', value),
							disabled: busy,
							key: 'async-external-css',
						}),
h(ToggleRow, {
											label: __("Aggressive Async CSS", 'ultracache'),
											description: __("Optional advanced mode. Rewrite almost all remaining local stylesheet links, including late footer output, to non-blocking print+onload loading with a noscript fallback. Use the exclude list for styles that must stay blocking.", 'ultracache'),
											checked: settings.aggressiveAsyncCssEnabled,
											onChange: (value) => updateSetting('aggressiveAsyncCssEnabled', value),
											disabled: busy,
											key: 'aggressive-async-css',
										}),
					]
				),
			h('div', { className: 'uc-media-settings-replacement-card', style: { gridColumn: '1 / -1' }, key: 'js-delay-defer-safeguards-diagnostics-wrap' }, [
SettingsAccordionCard({
						keyName: 'js-delay-defer-exclusions-diagnostics-box',
						title: __("JS Defer / Delay Safeguards & Diagnostics", 'ultracache'),
						description: __("Speed-first defer-instead safeguards, compatibility exclusions, third-party delay patterns, and runtime diagnostics.", 'ultracache'),
						children: [
							h(DeferDelayExclusionsField, {
																		value: settings.deferJsExcludeList || '',
																		onSave: (value) => updateSetting('deferJsExcludeList', value),
																		forceDeferValue: settings.deferJsForceList || '',
																		onForceDeferSave: (value) => updateSetting('deferJsForceList', value),
																		onSaveBoth: (excludeValue, forceValue) => queueSettingsPatch({ deferJsExcludeList: excludeValue, deferJsForceList: forceValue }),
																		disabled: busy,
																		placeholder: [jqueryPublicPath, wpUtilPublicPath, apiFetchPublicPath].filter(Boolean).join('\n'),
																		forceDeferPlaceholder: 'my-theme-script\n/custom-plugin/assets/app.js',
																		onPopulateDefaults: populateDeferDelayExclusionDefaults,
																		onScan: runJsDelaySafetyScanForUrl,
																		onRuntimeScan: runBrowserRuntimeJsScanForUrl,
																		onAppendDelayPattern: (value) => appendScannerPatternToSafeDelayDraft(value),
																		key: 'defer-stages-exclude-list-final',
																	}),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'js-exclusions-grid' }, [
															h(SaveableTextAreaField, {
																											label: __("Safe Third-Party Delay Patterns", 'ultracache'),
																											description: __("User-editable matching fragments for scripts already printed by the site, theme, or another plugin.", 'ultracache'),
																											value: settings.delaySafeThirdPartyJsPatterns || '',
																											onSave: (value) => updateSetting('delaySafeThirdPartyJsPatterns', value),
																											disabled: busy || !settings.delaySafeThirdPartyJsEnabled,
																											placeholder: 'googletagmanager.com\ngoogle-analytics.com\nconnect.facebook.net\nclarity.ms',
																											saveLabel: 'Save Safe Third-Party Patterns',
																											appendRequest: safeDelayAppendRequest,
																											populateLabel: __("Populate Defaults", 'ultracache'),
																											populateWarning: 'Your current safe third-party delay patterns will be replaced with the recommended defaults.',
																											onPopulate: () => populateDefaultSettingList('delaySafeThirdPartyJsPatterns', 'safe third-party delay patterns'),
																											key: 'delay-safe-third-party-patterns',
																										}),
															h(SaveableTextAreaField, {
																											label: __("Known Functional Third-Party Delay Patterns", 'ultracache'),
																											description: __("User-editable matching fragments for scripts already printed by the site, theme, or another plugin. Matching consent, captcha, maps, chat, booking, embedded form, opt-in popup, newsletter, and widget scripts are delayed unless excluded.", 'ultracache'),
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
														]),
						],
					}),
			]),
			h('div', { className: 'uc-media-settings-replacement-card', style: { gridColumn: '1 / -1' }, key: 'css-bundle-exclusions-diagnostics-wrap' }, [
SettingsAccordionCard({
						keyName: 'css-bundle-exclusions-diagnostics-box',
						title: __("CSS Bundle Exclusions & Diagnostics", 'ultracache'),
						description: __("CSS bundle, async CSS, and delayed font CSS safeguards plus diagnostics.", 'ultracache'),
						children: [
							h(CssBundleExclusionsDiagnosticsField, {
																		value: settings.homepageCssBundleExcludeList || '',
																		onSave: (value) => updateSetting('homepageCssBundleExcludeList', value),
																		disabled: busy || !settings.homepageCssBundleEnabled,
																		placeholder: `${joinPublicPath(pluginsPublicPath, 'plugin-name/assets/style.css')}\n${joinPublicPath(themesPublicPaths[0] || '', 'theme-name/assets/critical.css')}`,
																		onPopulateDefaults: populateCssBundleExclusionDefaults,
																		onRunDiagnostics: runCssDiagnosticsForUrl,
																		onDownloadJson: downloadCssDiagnosticsJson,
																		onClearResult: clearCssDiagnosticsResult,
																		profile: performanceProfile,
																		onCopyCssExclusion: copyCssBundleExclusionSuggestion,
																		key: 'homepage-css-bundle-exclude-diagnostics-field',
																	}),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'css-exclusions-grid' }, [
															h(SaveableTextAreaField, {
																											label: __("Async CSS Exclude List", 'ultracache'),
																											description: __("Optional newline-separated handle or URL fragments. Matching stylesheets stay in the normal blocking flow for both Enable Async CSS and Aggressive Async CSS. This is the single visible/editable Async CSS safeguard list.", 'ultracache'),
																											value: settings.asyncCssExcludeList || '',
																											onSave: (value) => updateSetting('asyncCssExcludeList', value),
																											disabled: busy,
																											placeholder: '/post-21.css\n/base/elementor.css',
																											saveLabel: 'Save Exclude List',
																											key: 'async-css-exclude-list',
																										}),
															h(SaveableTextAreaField, {
																										label: __("Delay These Fonts / Icon Font", 'ultracache'),
																										description: __("Newline-separated font-family, filename, or URL fragments. Matching @font-face blocks from bundled or standalone CSS are moved into the delayed font stylesheet. Commonly used for icon fonts, but it can match any font you choose to delay.", 'ultracache'),
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
																		label: __("Never async these external CSS URLs / patterns", 'ultracache'),
																		description: __("One URL, domain, filename, or pattern per line. Matching external stylesheets stay render-blocking and are also allowed to continue through the normal CSS flow.", 'ultracache'),
																		value: settings.asyncExternalCssExcludeList || '',
																		onSave: (value) => updateSetting('asyncExternalCssExcludeList', value),
																		disabled: busy,
																		placeholder: 'cdn.example.com\nexternal-library.css\n/css/vendor/',
																		saveLabel: 'Save External CSS Exclusions',
																		key: 'async-external-css-exclude-list',
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
														]),
						],
					}),
			]),
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
							label: __("Bundle Generated Font-Mix CSS", 'ultracache'),
							description: __("Consolidate UltraCache-generated optimized-css/css-font-mix stylesheets into one ordered blocking CSS file. This reduces render-blocking request count without asyncing layout-risk CSS.", 'ultracache'),
							checked: !!settings.fontMixCssBundleEnabled,
							onChange: (value) => queueSettingsPatch({ fontMixCssBundleEnabled: value, fontMixCssBundleAsyncEnabled: value ? !!settings.fontMixCssBundleAsyncEnabled : false }),
							disabled: busy,
							tooltip: __("What it does: combines UltraCache-generated font-mix CSS files into one ordered stylesheet.\n\nWhy it helps: the browser has one font CSS request to handle instead of many small blocking requests.\n\nWatch for: this bundle stays blocking on purpose because font CSS can change text size, icon display, and first-view layout.", 'ultracache'),
							key: 'font-mix-css-bundle',
						}),
h(ToggleRow, {
							label: __("Async Generated Font-Mix CSS Bundle", 'ultracache'),
							description: __("Load the consolidated bundle-font-mix stylesheet with non-blocking print+onload delivery. Individual css-font-mix source files are not async-loaded by this option.", 'ultracache'),
							checked: !!settings.fontMixCssBundleAsyncEnabled,
							onChange: (value) => updateSetting('fontMixCssBundleAsyncEnabled', value),
							disabled: busy || !settings.fontMixCssBundleEnabled,
							tooltip: __("What it does: async-loads only the single consolidated bundle-font-mix stylesheet.\n\nWhy it helps: a large font-mix bundle can leave the render-blocking path, so the page can paint sooner.\n\nWatch for: text or icons may appear with fallback styling first and settle later. Check the first viewport, product cards, menus, headers, and decorative fonts.", 'ultracache'),
							key: 'font-mix-css-bundle-async',
						}),
h(ToggleRow, {
							label: __("Delay icon fonts", 'ultracache'),
							description: __("Moves only matching entries from the visible font pattern list into a non-render-blocking delayed font stylesheet. Use the scanner to append detected icon-font patterns, then save the visible list.", 'ultracache'),
							checked: !!settings.delayIconFontsEnabled,
							onChange: updateDelayIconFonts,
							disabled: busy,
							key: 'delay-icon-fonts',
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
						title: __("WooCommerce Controls", 'ultracache'),
						description: __("Control WooCommerce cache safety, frontend request timing, and optional asset cleanup.", 'ultracache'),
						key: 'asset-cleanup-section-card',
					},
					[
						h(ToggleRow, {
							label: __("WooCommerce Safe Mode", 'ultracache'),
							description: __("Bypass cart, checkout, account, order endpoints, and cart-changing requests.", 'ultracache'),
							checked: settings.woocommerceSafeModeEnabled,
							onChange: (value) => updateSetting('woocommerceSafeModeEnabled', value),
							disabled: busy,
							hideDescription: true,
							key: 'woo-safe',
						}),
						h('div', { className: 'mt-3 pt-3 border-t border-white/5', key: 'woocommerce-strategy-divider' }),
						h(SelectField, {
							label: __("WooCommerce frontend strategy", 'ultracache'),
							description: __("Choose a preset for cart-fragments timing and WooCommerce asset cleanup. Custom appears when the individual controls no longer match a preset.", 'ultracache'),
							value: getWooCommerceFrontendStrategyValue(settings),
							onChange: (value) => {
								const patch = getWooCommerceFrontendStrategyPatch(value);
								if (Object.keys(patch).length) {
									queueSettingsPatch(patch);
								}
							},
							disabled: busy,
							hideDescription: true,
							tooltip: __("What it does: applies a WooCommerce preset. Off disables UltraCache Woo timing and cleanup. Safe delays cart fragments only. Balanced also enables general Woo asset cleanup and removes Woo Blocks CSS when no Woo blocks are found. Aggressive adds product/gallery cleanup outside product pages and product-filter cleanup when no filter is found. Custom means the small switches no longer match a preset.\n\nWhy it helps: WooCommerce often loads cart and shop helpers before the visitor needs them.\n\nWatch for: test homepage, shop, product, cart, checkout, account, mini-cart, add-to-cart, search, and filters after changing this.", 'ultracache'),
							options: [
								{ value: 'off', label: __("Off", 'ultracache') },
								{ value: 'safe', label: __("Safe", 'ultracache') },
								{ value: 'balanced', label: __("Balanced", 'ultracache') },
								{ value: 'aggressive', label: __("Aggressive", 'ultracache') },
								{ value: 'custom', label: __("Custom", 'ultracache') },
							],
							key: 'woocommerce-frontend-strategy',
						}),
						h(SelectField, {
							label: __("Cart fragments behavior", 'ultracache'),
							description: __("Choose whether WooCommerce cart fragments stay off, are delayed, or are suppressed on anonymous empty-cart cacheable pages. Cart, checkout, account, logged-in, and active cart/session-cookie contexts keep normal WooCommerce behavior.", 'ultracache'),
							value: getWooCommerceCartFragmentsBehaviorValue(settings),
							onChange: (value) => queueSettingsPatch(getWooCommerceCartFragmentsBehaviorPatch(value)),
							disabled: busy,
							hideDescription: true,
							options: [
								{ value: 'off', label: __("Off", 'ultracache') },
								{ value: 'delay', label: __("Delay request", 'ultracache') },
								{ value: 'suppress-empty', label: __("Suppress empty-cart execution", 'ultracache') },
							],
							key: 'woocommerce-cart-fragments-behavior',
						}),
						h(SelectField, {
							label: __("WooCommerce release timer", 'ultracache'),
							description: __("Use Delayed JS auto-start to mirror the JavaScript release timer and selected event triggers, or choose a WooCommerce-specific fallback timer.", 'ultracache'),
							value: settings.woocommerceCartFragmentsDelayTiming || 'delayed-js',
							onChange: (value) => updateSetting('woocommerceCartFragmentsDelayTiming', value),
							disabled: busy || !settings.woocommerceCartFragmentsDelayEnabled,
							hideDescription: true,
							options: [
								{ value: 'delayed-js', label: __("Use Delayed JS auto-start", 'ultracache') },
								{ value: '0.5', label: __("0.5 seconds", 'ultracache') },
								{ value: '1', label: __("1 second", 'ultracache') },
								{ value: '2', label: __("2 seconds", 'ultracache') },
								{ value: '3', label: __("3 seconds", 'ultracache') },
								{ value: '5', label: __("5 seconds", 'ultracache') },
							],
							key: 'woocommerce-cart-fragments-delay-timing',
						}),
						h('div', { className: 'mt-3 pt-3 border-t border-white/5', key: 'woocommerce-asset-cleanup-divider' }),
						h(ToggleRow, { label: __("Enable WooCommerce asset cleanup", 'ultracache'), description: __("Cleans selected unnecessary WooCommerce frontend assets from cached HTML and late WordPress queues. Test homepage, shop, product, cart, checkout, and header search after enabling.", 'ultracache'), checked: settings.assetChainCleanupEnabled, onChange: (value) => updateSetting('assetChainCleanupEnabled', value), disabled: busy, hideDescription: true, key: 'asset-chain-cleanup-enabled' }),
h(ToggleRow, { label: __("Clean WooCommerce product/gallery assets outside product pages", 'ultracache'), description: __("Removes zoom, flexslider, PhotoSwipe, variation, and single-product assets when the cached HTML is not a single product page.", 'ultracache'), checked: settings.assetCleanupWooProductAssetsEnabled, onChange: (value) => updateSetting('assetCleanupWooProductAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, hideDescription: true, key: 'asset-cleanup-woo-product-assets' }),
h(ToggleRow, { label: __("Clean product filter assets when no filter is detected", 'ultracache'), description: __("Removes WOOF/filter scripts and styles when UltraCache cannot detect filter markup in the generated HTML.", 'ultracache'), checked: settings.assetCleanupProductFilterAssetsEnabled, onChange: (value) => updateSetting('assetCleanupProductFilterAssetsEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, hideDescription: true, key: 'asset-cleanup-product-filter-assets' }),
h(ToggleRow, { label: __("Clean WooCommerce Blocks CSS when no Woo blocks are detected", 'ultracache'), description: __("Removes wc-blocks.css from cached HTML when no WooCommerce block markup is present.", 'ultracache'), checked: settings.assetCleanupWooBlocksCssEnabled, onChange: (value) => updateSetting('assetCleanupWooBlocksCssEnabled', value), disabled: busy || !settings.assetChainCleanupEnabled, hideDescription: true, key: 'asset-cleanup-woo-blocks-css' })
					]
				),
			]),

				SettingsAccordionCard({
						keyName: 'advanced-settings-box',
						title: __("Advanced settings", 'ultracache'),
						description: __("General advanced controls kept separate from exclusion lists.", 'ultracache'),
						children: [
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'advanced-settings-grid' }, [
															h(ToggleRow, {
																							label: __("Lazy MailerLite nonce refresh", 'ultracache'),
																							description: 'Prevents MailerLite forms from calling the WordPress Ajax endpoint on page load for ml_create_nonce. The nonce is refreshed on first form interaction or before submit, so cached pages avoid the load-time Ajax request.',
																							checked: !!settings.lazyMailerliteNonceEnabled,
																							onChange: (value) => updateSetting('lazyMailerliteNonceEnabled', value),
																							disabled: busy,
																							key: 'lazy-mailerlite-nonce-refresh',
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
															h(SaveableTextAreaField, {
																											label: __("Additional URLs for Google Fonts scanning", 'ultracache'),
																											description: 'Optional local site URLs, one per line. When Local Google Fonts Optimization is enabled, UltraCache scans the homepage plus these URLs from admin/save or manual rebuild, downloads Google Fonts CSS/WOFF2 into the UltraCache uploads/google-fonts folder, and never builds them on live frontend requests.',
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
														]),
						],
					}),
			SettingsAccordionCard({
						keyName: 'general-exclusions-box',
						title: __("General Exclusions", 'ultracache'),
						description: __("General cache, cookie, asset-cleanup, LCP, and request-chain safeguards.", 'ultracache'),
						children: [
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'general-exclusions-grid' }, [
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
														]),
						],
					}),
			SettingsAccordionCard({
						keyName: 'general-inclusions-box',
						title: __("General Inclusions", 'ultracache'),
						description: __("Manual LCP targets, priority preloads, and request-chain delay entries.", 'ultracache'),
						children: [
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-inclusions-grid', key: 'general-inclusions-grid' }, [
								h(SaveableTextAreaField, {
									label: __("Manual LCP selector", 'ultracache'),
									description: __("Optional newline-separated CSS selectors, plain IDs, or image URL/fragments for the main above-the-fold hero/LCP target. CSS entries scope LCP discovery to that block; image entries become manual LCP preload targets.", 'ultracache'),
									value: settings.manualLcpHeroSelector || '',
									onSave: (value) => updateSetting('manualLcpHeroSelector', value),
									disabled: busy || !settings.lcpImagePriorityEnabled,
									placeholder: `#main-hero\n.hero-slider\n${joinPublicPath(uploadsPublicPath, 'hero.webp')}\nhero-home.jpg`,
									saveLabel: 'Save Manual LCP Selector',
									key: 'manual-lcp-hero-selector',
								}),
								h(SaveableTextAreaField, {
									label: __("Priority Preloads", 'ultracache'),
									description: __("Optional newline-separated priority resources for early discovery. Prefix each line with image, style, script, font, or fetch. Use fetch for dynamic frontend requests, slider JSON/assets, or request chains that are not discovered early in the initial HTML. Image entries receive fetchpriority=high.", 'ultracache'),
									value: settings.criticalResourcePreloadList || '',
									onSave: (value) => updateSetting('criticalResourcePreloadList', value),
									disabled: busy,
									placeholder: `image ${joinPublicPath(uploadsPublicPath, 'hero.webp')}\nstyle ${joinPublicPath(generatedAssetsPublicPath, 'css-bundles/bundle.css')}\nfont ${joinPublicPath(uploadsPublicPath, 'fonts/manrope.woff2')}\nfetch /sliders/1?srengine=7`,
									saveLabel: 'Save Priority Preloads',
									key: 'critical-resource-preload-list',
								}),
								h(SaveableTextAreaField, {
									label: __("Delay Non-Critical Request Chains", 'ultracache'),
									description: __("Optional newline-separated handle or URL fragments. Matching local scripts are delayed and matching stylesheets are converted to async print/onload loading.", 'ultracache'),
									value: settings.criticalRequestChainDelayList || '',
									onSave: (value) => updateSetting('criticalRequestChainDelayList', value),
									disabled: busy,
									placeholder: 'tooltipster\nplainoverlay\nion.rangeSlider\nsourcebuster',
									saveLabel: 'Save Chain Delay List',
									key: 'critical-request-chain-delay-list',
								}),
							]),
						],
					}),
			h(RedisCard, { form: redisForm, diagnostics, busy: hasDashboardWorkInProgress(), canManageInfrastructure, objectCacheEnabled: settings.objectCacheEnabled, onObjectCacheEnabledChange: (value) => updateSetting('objectCacheEnabled', value), onFieldChange: updateRedisField, onSave: saveRedisSettings, onTest: testObjectCacheBackend, onFlush: flushObjectCache, onRemoveConflictingDropins: removeConflictingCacheDropins, onRecheckConflicts: recheckCacheConflicts, key: 'redis-card' }),

			h(
				Card,
				{
					title: __("Automation & Scheduling", 'ultracache'),
					description: __("Scheduled cache cleanup, background warmup queue, and stale cache timing controls.", 'ultracache'),
					key: 'automation-scheduling-reworked',
				},
				[
					h('div', { className: classNames('uc-inline-diagnostic', automationWorkerAttention ? 'is-disabled' : ''), key: 'automation-worker-status' }, [
						h('div', { className: 'uc-inline-diagnostic-row' }, [
							h('span', { className: 'uc-inline-diagnostic-label' }, __('AUTOMATION WORKER', 'ultracache')),
							h('span', { className: 'uc-inline-diagnostic-state' }, automationWorkerLabel),
						]),
						h('div', { className: 'uc-inline-diagnostic-copy' }, String(automationWorker.message || __('No automation work is waiting.', 'ultracache'))),
						h('div', { className: 'uc-inline-diagnostic-copy' }, automationWorkerQueueText),
						automationWorkerActivityParts.length > 0
							? h('div', { className: 'uc-inline-diagnostic-copy break-words' }, automationWorkerActivityParts.join(' · '))
							: null,
						automationWorkerFullSiteText
							? h('div', { className: 'uc-inline-diagnostic-copy' }, automationWorkerFullSiteText)
							: null,
						automationWorkerPausedSessionText
							? h('div', { className: 'uc-inline-diagnostic-copy text-amber-300' }, automationWorkerPausedSessionText)
							: null,
						automationWorkerNextText
							? h('div', { className: 'uc-inline-diagnostic-copy' }, automationWorkerNextText)
							: null,
					]),
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
							label: __("Stale While Revalidate", 'ultracache'),
							description: __("Serve stale HTML only within the max stale window while UltraCache refreshes it in the background.", 'ultracache'),
							checked: settings.staleWhileRevalidateEnabled,
							onChange: (value) => updateSetting('staleWhileRevalidateEnabled', value),
							disabled: busy,
							key: 'swr-toggle',
						}),
						h(ToggleRow, {
							label: __("Warm full site after Scheduled Cleanup", 'ultracache'),
							description: __("Start the background full-site warm-up after the scheduled cleanup purge completes.", 'ultracache'),
							checked: settings.cronWarmStartAfterCleanup,
							onChange: (value) => updateSetting('cronWarmStartAfterCleanup', value),
							disabled: busy || !settings.cacheCleanupEnabled,
							key: 'cleanup-warm',
						}),
						h(ToggleRow, {
							label: __("Warm full site after Flush All Cache", 'ultracache'),
							description: __("Start the background full-site warm-up after Flush All Cache completes.", 'ultracache'),
							checked: !!settings.cronWarmStartAfterManualPurge,
							onChange: (value) => updateSetting('cronWarmStartAfterManualPurge', value),
							disabled: busy,
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
							label: __("Background warm pages per minute", 'ultracache'),
							description: __("How many HTML URLs the shared background automation worker may process per minute. This rate applies to scheduled full-site warm-up and targeted work from updates, imports, LCP refreshes, media discovery, and external-cache refill. Lower values are gentler on the server. Set 0 to pause background page processing.", 'ultracache'),
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
						h(NumberRow, {
							label: __("Fresh TTL (minutes)", 'ultracache'),
							description: __("How long cached HTML remains fresh. When Varnish is enabled, this value is also used as the Varnish HTML TTL. Default: 1440 minutes (24 hours).", 'ultracache'),
							value: advancedForm.cacheFreshTtlMinutes,
							onChange: (value) => updateAdvancedField('cacheFreshTtlMinutes', value),
							disabled: busy,
							min: 1,
							max: 525600,
							key: 'fresh-ttl',
						}),
						h(NumberRow, {
							label: __("Max stale window (minutes)", 'ultracache'),
							description: __("Maximum total age at which expired HTML may still be served while UltraCache refreshes it. Stale serving starts after Fresh TTL expires. Default: 2880 minutes (48 hours).", 'ultracache'),
							value: advancedForm.cacheMaxStaleMinutes,
							onChange: (value) => updateAdvancedField('cacheMaxStaleMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							max: 525600,
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

			showVarnishCard ? h(VarnishCard, { form: varnishForm, savedSettings: settings, diagnostics, busy: false, canManageInfrastructure, onEnabledChange: updateVarnishEnabled, onFieldChange: updateVarnishField, onSave: saveVarnishSettings, onDetect: runVarnishDiscovery, onTest: runVarnishTest, onMeasurePerformance: runVarnishPerformanceSnapshot, onFlushAll: runVarnishFlushAll, onFlushEntireHost: runVarnishFlushEntireHost, onRemoveConflictingDropins: removeConflictingCacheDropins, onRecheckConflicts: recheckCacheConflicts, key: 'varnish-card' }) : null,
			showLiteSpeedCard ? h(LiteSpeedCard, { layer: liteSpeedLayer, diagnostics, settings, busy, onSettingChange: updateSetting, onRedetect: redetectExternalCaches, onFlush: flushLiteSpeed, onTest: runLiteSpeedTest, key: 'litespeed-cache-card' }) : null,
			h('div', { className: 'uc-info-grid', key: 'php-cache-cards' }, [
			h(OPcacheCard, { stats, busy: false, onFlush: flushOpcache, key: 'opcache-card' }),
			h(APCuCard, { stats, settings, busy: false, onFlush: flushApcu, onToggleScheduledCleanup: (value) => updateSetting('apcuFlushOnScheduledCleanup', value), key: 'apcu-card' }),
			h(ExternalCacheCard, { title: __("Nginx Cache", 'ultracache'), description: __("Detected Nginx cache integration. UltraCache flushes Nginx only when a safe WordPress purge hook/integration is available.", 'ultracache'), layer: getExternalCacheLayer(stats, 'nginx'), busy: false, onFlush: flushNginx, key: 'nginx-cache-card' }),
			]),
			h(ExternalCacheFlushSettingsCard, { stats, diagnostics, settings, busy: false, canManageInfrastructure, onRedetect: redetectExternalCaches, onToggle: (key, value) => updateSetting(key, value), key: 'external-cache-flush-settings' }),

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
						placeholder: "/sample-page/?utm_source=test",
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
							h('div', { className: 'text-xs text-amber-200/80 mt-2' }, __("Generated images under uploads/ultracache/images are never deleted automatically.", 'ultracache')),
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
					h('div', { className: 'mt-2 text-xs text-zinc-500', key: 'delete-hint' }, 'Delete/deactivate follows the selected cleanup policy. Generated media remains under the UltraCache uploads/images folder and must be removed manually if you want it deleted.'),
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
						h('p', { className: 'm-0' }, __("For most sites, begin with the Balanced profile. It enables high-impact optimizations, uses aggressive CSS bundling, and avoids the broadest JavaScript delay strategy.", 'ultracache')),
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


		mountDashboard(rootEl, App);
	}

	admin.define('dashboardApplication', {
		bootstrap,
	});
})(window);
