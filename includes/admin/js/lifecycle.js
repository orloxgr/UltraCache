/* UltraCache Admin - React state and dashboard lifecycle */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before lifecycle.js.');
	}

	const core = typeof admin.get === 'function' ? admin.get('core') : null;
	if (!core) {
		throw new Error('UltraCache admin core module is required before lifecycle.js.');
	}

	const {
		ReactDOMApi,
		h,
		useCallback,
		useEffect,
		useMemo,
		useRef,
		useState,
		__,
		getLocalStorageSafe,
		ignoreExpectedAdminFailure,
		reportNonFatalAdminError,
	} = core;

	function buildAdvancedForm(settings, getDefaultScheduledWarmLimit) {
		const source = settings && typeof settings === 'object' ? settings : {};
		return {
			cacheExceptionPaths: source.cacheExceptionPaths || '',
			cacheExceptionQueryArgs: source.cacheExceptionQueryArgs || '',
			cacheQueryStringAllowlist: source.cacheQueryStringAllowlist || '',
			cacheCleanupIntervalHours: source.cacheCleanupIntervalHours || 24,
			cssBundleCleanupGraceHours: typeof source.cssBundleCleanupGraceHours === 'undefined' ? 48 : source.cssBundleCleanupGraceHours,
			cssBundleCleanupDeleteLimit: typeof source.cssBundleCleanupDeleteLimit === 'undefined' ? 60 : source.cssBundleCleanupDeleteLimit,
			cronWarmPagesPerMinute: typeof source.cronWarmPagesPerMinute === 'undefined' ? 2 : source.cronWarmPagesPerMinute,
			scheduledWarmLimit: typeof source.scheduledWarmLimit === 'undefined'
				? (typeof getDefaultScheduledWarmLimit === 'function' ? getDefaultScheduledWarmLimit() : 0)
				: source.scheduledWarmLimit,
			cacheFreshTtlMinutes: source.cacheFreshTtlMinutes || 1440,
			cacheMaxStaleMinutes: source.cacheMaxStaleMinutes || 2880,
		};
	}

	function buildVarnishForm(settings, getDefaultVarnishServersForMode) {
		const source = settings && typeof settings === 'object' ? settings : {};
		const mode = source.varnishCliMode || 'http';
		return {
			varnishCliEnabled: !!source.varnishCliEnabled,
			varnishConnectionConfigured: !!source.varnishConnectionConfigured,
			varnishCliMode: mode,
			varnishCliServers: source.varnishCliServers || (typeof getDefaultVarnishServersForMode === 'function' ? getDefaultVarnishServersForMode(mode) : ''),
			varnishCliTimeoutSeconds: source.varnishCliTimeoutSeconds || 2,
			varnishInvalidationsPerMinute: typeof source.varnishInvalidationsPerMinute === 'undefined' ? 10 : source.varnishInvalidationsPerMinute,
			varnishCliMethod: source.varnishCliMethod || 'BAN',
			varnishInvalidationStrategy: source.varnishInvalidationStrategy || String(source.varnishCliMethod || 'BAN').toLowerCase(),
			varnishFlushScope: source.varnishFlushScope || 'auto',
			varnishCliKey: '',
			clearVarnishCliKey: false,
			varnishCliKeyConfigured: !!source.varnishCliKeyConfigured,
			varnishCliKeyManaged: !!source.varnishCliKeyManaged,
			varnishCliKeyExternal: !!source.varnishCliKeyExternal,
		};
	}

	function buildRedisForm(settings) {
		const source = settings && typeof settings === 'object' ? settings : {};
		return {
			objectCacheBackend: source.objectCacheBackend || 'redis',
			objectCacheFallbackBackend: source.objectCacheFallbackBackend || 'apcu',
			sqliteDatabaseSizeMb: [32, 64, 128, 256, 512, 1024, 2048].indexOf(Number(source.sqliteDatabaseSizeMb)) !== -1 ? Number(source.sqliteDatabaseSizeMb) : 256,
			redisHost: source.redisHost || '127.0.0.1',
			redisPort: source.redisPort || 6379,
			redisUsername: source.redisUsername || '',
			redisDatabase: typeof source.redisDatabase === 'undefined' ? 0 : source.redisDatabase,
			redisPrefix: source.redisPrefix || '',
			redisUseTls: !!source.redisUseTls,
			redisPersistent: !!source.redisPersistent,
			redisConnectTimeoutMs: typeof source.redisConnectTimeoutMs === 'undefined' ? 200 : source.redisConnectTimeoutMs,
			redisReadTimeoutMs: typeof source.redisReadTimeoutMs === 'undefined' ? 200 : source.redisReadTimeoutMs,
			redisPassword: '',
			clearRedisPassword: false,
			redisPasswordConfigured: !!source.redisPasswordConfigured,
			redisPasswordManaged: !!source.redisPasswordManaged,
			redisPasswordExternal: !!source.redisPasswordExternal,
		};
	}

	function buildInitialProcess() {
		return {
			active: false,
			label: '',
			current: 0,
			total: 0,
			logs: [],
			startTime: 0,
			cancellable: false,
			cancelRequested: false,
		};
	}

	function useDashboardState(options) {
		const config = options && typeof options === 'object' ? options : {};
		const initialSettings = config.initialSettings || {};
		const initialStats = config.initialStats || {};
		const initialMediaFileCounts = config.initialMediaFileCounts || {};
		const initialDiagnostics = config.initialDiagnostics || {};
		const initialMediaSupport = config.initialMediaSupport || {};
		const initialWarmupGeneration = Math.max(0, Number(config.initialWarmupGeneration || 0));
		const clearNoticeDelay = Number(config.clearNoticeDelay || 4200);
		const initialMediaQueue = initialDiagnostics && initialDiagnostics.mediaRuntime && initialDiagnostics.mediaRuntime.queue
			? initialDiagnostics.mediaRuntime.queue
			: null;
		const mobileViewport = typeof config.isMobileViewport === 'function' ? config.isMobileViewport : function () { return false; };
		const loadSavedJob = typeof config.loadSavedJob === 'function' ? config.loadSavedJob : function () { return null; };

		const [settings, setSettings] = useState(initialSettings);
		const [stats, setStats] = useState(initialStats);
		const [mediaFileCounts, setMediaFileCounts] = useState(initialMediaFileCounts);
		const [diagnostics, setDiagnostics] = useState(initialDiagnostics);
		const [, setCrawlScopeVersion] = useState(0);
		const [browserCompressionProbe, setBrowserCompressionProbe] = useState({ ready: false, probed: false, serverCompression: false, serverGzip: false, serverBrotli: false, gzipAvailable: false, brotliAvailable: false, blocked: false, brokenGzip: false, brokenBrotli: false, message: '' });
		const [compressionProbeBusy, setCompressionProbeBusy] = useState(false);
		const [busy, setBusy] = useState(false);
		const [mediaSupport, setMediaSupport] = useState(initialMediaSupport);
		const [asyncActions, setAsyncActions] = useState({});
		const [toasts, setToasts] = useState([]);
		const [isMobile, setIsMobile] = useState(mobileViewport());
		const [supportModalOpen, setSupportModalOpen] = useState(false);
		const [infoAccordionsOpen, setInfoAccordionsOpen] = useState(false);
		const [advancedForm, setAdvancedForm] = useState(buildAdvancedForm(initialSettings, config.getDefaultScheduledWarmLimit));
		const [varnishForm, setVarnishForm] = useState(buildVarnishForm(initialSettings, config.getDefaultVarnishServersForMode));
		const [redisForm, setRedisForm] = useState(buildRedisForm(initialSettings));
		const [inspectUrl, setInspectUrl] = useState('');
		const [inspectBusy, setInspectBusy] = useState(false);
		const [inspectResult, setInspectResult] = useState(null);
		const [performanceProfile, setPerformanceProfile] = useState(null);
		const [cssDiagnosticsUrl, setCssDiagnosticsUrl] = useState(String(config.frontendProbeUrl || ''));
		const [cssDiagnosticsBusy, setCssDiagnosticsBusy] = useState(false);
		const [homepageHtmlBusy, setHomepageHtmlBusy] = useState(false);
		const [homepageHtmlCssBusy, setHomepageHtmlCssBusy] = useState(false);
		const [allUrlsCssBusy, setAllUrlsCssBusy] = useState(false);
		const [menuUrlsCssBusy, setMenuUrlsCssBusy] = useState(false);
		const [savedJob, setSavedJob] = useState(loadSavedJob());
		const [warmupGeneration, setWarmupGeneration] = useState(initialWarmupGeneration);
		const warmupGenerationRef = useRef(initialWarmupGeneration);
		const cancelRequestedRef = useRef(false);
		const manualMediaSessionTokenRef = useRef('');
		const importFileInputRef = useRef(null);
		const statsRefreshInFlightRef = useRef(false);
		const settingsRef = useRef(initialSettings);
		const committedSettingsRef = useRef(initialSettings);
		const lastSettingsSavePromiseRef = useRef(Promise.resolve());
		const googleFontsAutoRebuildQueuedRef = useRef(false);
		const queuedActionKeysRef = useRef({});
		const uiActionQueueRef = useRef(Promise.resolve());
		const uiActionQueueDepthRef = useRef(0);
		const uiActionSequenceRef = useRef(0);
		const queuedDashboardPayloadRef = useRef(null);
		const suppressBeforeUnloadRef = useRef(false);
		const [uiActionQueueCount, setUiActionQueueCount] = useState(0);
		const compressionSyncRef = useRef('');
		const manualObjectCacheTestRef = useRef(null);
		const [mediaQueueStatus, setMediaQueueStatus] = useState(initialMediaQueue);
		const [mediaBackgroundControlBusy, setMediaBackgroundControlBusy] = useState(false);
		const [process, setProcess] = useState(buildInitialProcess());

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
				duration: clearNoticeDelay,
			}, toast || {});

			setToasts((current) => {
				const filtered = current.filter((item) => item.id !== nextToast.id);
				return filtered.concat([nextToast]).slice(-50);
			});

			return nextToast.id;
		}, [clearNoticeDelay]);

		return {
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
			toasts, setToasts,
			isMobile, setIsMobile,
			supportModalOpen, setSupportModalOpen,
			infoAccordionsOpen, setInfoAccordionsOpen,
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
		};
	}

	function getSystemNoticeStorageKey(id) {
		return 'ultracache-system-notice:' + String(id || 'notice');
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
		return (Date.now() - lastShown) >= Math.max(1000, Number(cooldownMs) || 0);
	}

	function markSystemNoticeShown(id) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return;
		}
		try {
			storage.setItem(getSystemNoticeStorageKey(id), String(Date.now()));
		} catch (error) {
			ignoreExpectedAdminFailure(error);
		}
	}

	function getPersistentDismissalStorageKey(id) {
		return 'ultracache-dismissed-notice:' + String(id || 'notice');
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
		} catch (error) {
			ignoreExpectedAdminFailure(error);
		}
	}

	function useDashboardLifecycle(options) {
		const config = options && typeof options === 'object' ? options : {};
		const settings = config.settings || {};
		const diagnostics = config.diagnostics || {};
		const process = config.process || {};
		const clearNoticeDelay = Number(config.clearNoticeDelay || 4200);
		const systemNoticeDelay = Number(config.systemNoticeDelay || 7000);
		const systemNoticeCooldown = Number(config.systemNoticeCooldown || (24 * 60 * 60 * 1000));
		const statsRefreshInterval = Number(config.statsRefreshInterval || 60000);

		useEffect(() => {
			let pending = '';
			try {
				pending = window.sessionStorage.getItem('ultracacheObjectCacheActivationProbe') || '';
				if (pending) {
					window.sessionStorage.removeItem('ultracacheObjectCacheActivationProbe');
				}
			} catch (error) {
				reportNonFatalAdminError('lifecycle.object-cache-activation-probe.read', error, { severity: 'warning', dedupeKey: 'lifecycle.object-cache-activation-probe.read' });
			}

			if (!pending) {
				return;
			}

			let cancelled = false;
			(async () => {
				try {
					const response = await config.apiRequest('object_cache_test', { backend: 'active' });
					if (cancelled) {
						return;
					}
					const nextResponse = Object.assign({}, response || {});
					const nextDiagnostics = Object.assign({}, nextResponse.diagnostics || {});
					const nextObjectCache = Object.assign({}, nextDiagnostics.objectCache || {});
					if (nextResponse.payloadProbe) {
						nextObjectCache.activationPayloadProbe = nextResponse.payloadProbe;
					}
					nextObjectCache.activationProbeBackend = String(nextResponse.backend || nextObjectCache.activeBackend || 'runtime').toLowerCase();
					nextDiagnostics.objectCache = nextObjectCache;
					nextResponse.diagnostics = nextDiagnostics;
					config.applyDashboardPayload(nextResponse);

					const activeBackend = String(nextObjectCache.activeBackend || nextResponse.backend || 'runtime').toLowerCase();
					const activeBackendLabel = 'redis' === activeBackend ? 'Redis' : ('apcu' === activeBackend ? 'APCu' : ('sqlite' === activeBackend ? 'SQLite' : ('disk' === activeBackend ? 'Disk' : 'Runtime-only')));
					if ('redis' === activeBackend && nextResponse.success) {
						config.pushToast({ type: 'success', text: 'Redis settings saved and activated. Active backend: Redis.' });
					} else {
						config.pushToast({ type: 'warning', text: 'Redis settings were saved, but the active backend is ' + activeBackendLabel + '. Review the Redis connection status below.' });
					}
				} catch (error) {
					if (!cancelled) {
						config.pushToast({ type: 'error', text: error && error.message ? error.message : 'Could not confirm the active object-cache backend after saving Redis settings.' });
					}
				}
			})();

			return () => {
				cancelled = true;
			};
		}, [config.pushToast]);

		useEffect(() => {
			config.settingsRef.current = settings;
		}, [settings]);

		useEffect(() => {
			const handleBeforeUnload = (event) => {
				if (config.suppressBeforeUnloadRef.current) {
					return undefined;
				}

				const processActive = !!(process && process.active);
				const actionActive = config.hasActiveQueuedDashboardAction();

				if (!actionActive && !processActive && !config.busy) {
					return undefined;
				}

				event.preventDefault();
				event.returnValue = '';
				return '';
			};

			window.addEventListener('beforeunload', handleBeforeUnload);
			return () => window.removeEventListener('beforeunload', handleBeforeUnload);
		}, [config.busy, process && process.active, config.asyncActions, config.uiActionQueueCount]);

		useEffect(() => {
			if (!Array.isArray(config.toasts) || !config.toasts.length) {
				return undefined;
			}

			const timers = config.toasts
				.filter((toast) => toast && !toast.persistent)
				.map((toast) => setTimeout(() => config.dismissToast(toast.id), typeof toast.duration === 'number' ? toast.duration : clearNoticeDelay));

			return () => timers.forEach((timer) => clearTimeout(timer));
		}, [config.toasts, config.dismissToast]);

		useEffect(() => {
			const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : null;
			if (reverseProxy && reverseProxy.detected) {
				if (shouldShowSystemNotice('reverse-proxy', systemNoticeCooldown)) {
					markSystemNoticeShown('reverse-proxy');
					config.pushToast({
						id: 'system-reverse-proxy',
						type: 'warning',
						title: __('Reverse proxy detected', 'ultracache'),
						text: reverseProxy.message || 'UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
						persistent: false,
						duration: systemNoticeDelay,
					});
				}
			} else {
				config.dismissToast('system-reverse-proxy');
			}
		}, [diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.detected : false, diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.message : '', config.pushToast, config.dismissToast]);

		useEffect(() => {
			const conflicts = diagnostics && diagnostics.legacyCacheConflicts ? diagnostics.legacyCacheConflicts : {};
			const activePlugins = Array.isArray(conflicts.activeCachePlugins) ? conflicts.activeCachePlugins : [];
			if (!activePlugins.length) {
				config.dismissToast('cache-plugin-conflict');
				return;
			}

			const slugs = activePlugins.map((item) => item && item.slug ? String(item.slug) : '').filter(Boolean).sort();
			const noticeKey = 'cache-plugin-conflict:' + slugs.join(',');
			if (isPersistentNoticeDismissed(noticeKey)) {
				return;
			}

			const pluginNames = activePlugins.map((item) => item && item.name ? item.name : (item && item.slug ? item.slug : 'Unknown')).join(', ');
			config.pushToast({
				id: 'cache-plugin-conflict',
				type: 'warning',
				title: __('Potential cache plugin conflict', 'ultracache'),
				text: (conflicts.message || 'Another cache/performance plugin is active and may conflict with UltraCache.') + ' Detected: ' + pluginNames + '.',
				persistent: true,
				actions: [
					{ label: __('Review', 'ultracache'), onClick: () => config.scrollToCacheConflictReview() },
					{ label: __('Dismiss', 'ultracache'), onClick: () => { markSystemNoticeShown(noticeKey); config.dismissToast('cache-plugin-conflict'); } },
					{ label: __('Don’t show again', 'ultracache'), onClick: () => { dismissPersistentNotice(noticeKey); config.dismissToast('cache-plugin-conflict'); } },
				],
			});
		}, [diagnostics && diagnostics.legacyCacheConflicts ? JSON.stringify(diagnostics.legacyCacheConflicts.activeCachePlugins || []) : '', config.pushToast, config.dismissToast]);

		useEffect(() => {
			const handleResize = () => config.setIsMobile(config.isMobileViewport());
			handleResize();
			window.addEventListener('resize', handleResize);
			return () => window.removeEventListener('resize', handleResize);
		}, []);

		useEffect(() => {
			if (config.isMobile) {
				config.setSupportModalOpen(false);
			}
		}, [config.isMobile]);

		// Browser/frontend probes are intentionally manual-only. Dashboard load and stats refresh must stay passive.
		useEffect(() => {
			if (!settings.cacheStatsEnabled) {
				config.statsRefreshInFlightRef.current = false;
				config.setStats(function (current) { return Object.assign({}, current || {}, config.getStatsDisabledClientPayload()); });
				return undefined;
			}

			let interval = null;

			const runRefresh = async () => {
				if (document.hidden || config.statsRefreshInFlightRef.current) {
					return;
				}
				config.statsRefreshInFlightRef.current = true;
				try {
					await config.refreshStats({ force: true });
				} catch (error) {
					reportNonFatalAdminError('lifecycle.stats-refresh', error, { severity: 'debug', dedupeKey: 'lifecycle.stats-refresh', dedupeWindowMs: 30000 });
				}
				finally {
					config.statsRefreshInFlightRef.current = false;
				}
			};

			const startInterval = () => {
				if (interval) {
					return;
				}
				interval = window.setInterval(runRefresh, statsRefreshInterval);
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
			config.setAdvancedForm(buildAdvancedForm(settings, config.getDefaultScheduledWarmLimit));
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
			config.setVarnishForm(buildVarnishForm(settings, config.getDefaultVarnishServersForMode));
		}, [
			settings.varnishCliEnabled,
			settings.varnishConnectionConfigured,
			settings.varnishCliMode,
			settings.varnishCliServers,
			settings.varnishCliTimeoutSeconds,
			settings.varnishInvalidationsPerMinute,
			settings.varnishCliMethod,
			settings.varnishInvalidationStrategy,
			settings.varnishFlushScope,
			settings.varnishCliKeyConfigured,
			settings.varnishCliKeyManaged,
			settings.varnishCliKeyExternal,
		]);

		useEffect(() => {
			config.setRedisForm(() => buildRedisForm(settings));
		}, [
			settings.objectCacheBackend,
			settings.objectCacheFallbackBackend,
			settings.redisHost,
			settings.redisPort,
			settings.redisUsername,
			settings.redisDatabase,
			settings.redisPrefix,
			settings.redisUseTls,
			settings.redisPersistent,
			settings.redisConnectTimeoutMs,
			settings.redisReadTimeoutMs,
			settings.redisPasswordConfigured,
			settings.redisPasswordManaged,
			settings.redisPasswordExternal,
		]);
	}

	function buildProcessEtaText(process, formatEtaDuration, nowMs) {
		const state = process && typeof process === 'object' ? process : {};
		const formatter = typeof formatEtaDuration === 'function' ? formatEtaDuration : function (value) { return String(value || 0); };
		if (!state.active || !state.total || state.current >= state.total) {
			return '';
		}

		if (state.type === 'media') {
			const sampleCount = Math.max(0, Number(state.etaSampleCount || 0));
			const perItemMs = Math.max(0, Number(state.etaPerItemMs || 0));
			if (sampleCount < 10 || perItemMs <= 0) {
				return 'Estimating after ' + (10 - sampleCount) + ' more attachment' + ((10 - sampleCount) === 1 ? '' : 's');
			}

			const remainingMs = Math.max(0, (Number(state.total || 0) - Number(state.current || 0)) * perItemMs);
			return 'About ' + formatter(remainingMs / 1000) + ' remaining · based on ' + sampleCount + ' attachments';
		}

		if (!state.current || !state.startTime) {
			return '';
		}

		const currentTime = typeof nowMs === 'number' ? nowMs : Date.now();
		const elapsed = currentTime - state.startTime;
		const perItem = elapsed / state.current;
		const remaining = Math.max(1000, (state.total - state.current) * perItem);
		return formatter(remaining / 1000) + ' remaining';
	}

	function useProcessEta(process, formatEtaDuration) {
		return useMemo(() => buildProcessEtaText(process, formatEtaDuration), [process]);
	}

	function findDashboardRoot(documentRef) {
		const doc = documentRef || document;
		return doc.getElementById('uc-dashboard') ||
			doc.getElementById('ultracache-admin-root') ||
			doc.getElementById('ultracache-root');
	}

	function mountDashboard(rootEl, App) {
		if (!rootEl || typeof App !== 'function') {
			return null;
		}
		if (ReactDOMApi && typeof ReactDOMApi.createRoot === 'function') {
			const root = ReactDOMApi.createRoot(rootEl);
			root.render(h(App));
			return root;
		}
		if (ReactDOMApi && typeof ReactDOMApi.render === 'function') {
			ReactDOMApi.render(h(App), rootEl);
			return ReactDOMApi;
		}
		return null;
	}

	admin.define('lifecycle', {
		useDashboardState,
		useDashboardLifecycle,
		useProcessEta,
		findDashboardRoot,
		mountDashboard,
	});
})(window);
