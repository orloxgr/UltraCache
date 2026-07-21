/* UltraCache Admin - Warm-up policy and dashboard orchestration */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before warmup.js.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	if (!core || !api) {
		throw new Error('UltraCache admin core/api modules are required before warmup.js.');
	}

	const { __, sleep } = core;
	const { apiRequest, normalizeBatchResponse } = api;

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
	const manualWarmSeenUrls = new Map();
	let maxWarmItemRetries = 2;

	function configure(config) {
		const source = config && typeof config === 'object' ? config : {};
		if (Number(source.maxItemRetries) >= 0) {
			maxWarmItemRetries = Math.max(0, Number(source.maxItemRetries));
		}
	}

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

	function getCanonicalWarmUrl(url) {
		try {
			const parsed = new URL(String(url || ''), window.location.origin);
			parsed.hash = '';
			['ultracache_revalidate', 'ultracache_rt', 'ultracache_rv', 'ultracache_bucket', 'ultracache_frontpage_css_scan', 'ultracache_css_v'].forEach((key) => {
				parsed.searchParams.delete(key);
			});
			parsed.searchParams.sort();
			return parsed.toString().replace(/\/+(\?|$)/, '$1');
		} catch (error) {
			return String(url || '').split('#')[0];
		}
	}

	function isWarmUrlSeen(type, url, manualSessionToken) {
		const sessionKey = String(manualSessionToken || '') || ('no-token:' + String(type || 'warm'));
		const canonicalUrl = getCanonicalWarmUrl(url);
		const seen = manualWarmSeenUrls.get(sessionKey);
		return !!(seen && seen.has(canonicalUrl));
	}

	function markWarmUrlSeen(type, url, manualSessionToken) {
		const sessionKey = String(manualSessionToken || '') || ('no-token:' + String(type || 'warm'));
		const canonicalUrl = getCanonicalWarmUrl(url);
		if (!manualWarmSeenUrls.has(sessionKey)) {
			manualWarmSeenUrls.set(sessionKey, new Set());
		}
		manualWarmSeenUrls.get(sessionKey).add(canonicalUrl);
	}

	function clonePipelineState(value) {
		if (!value || typeof value !== 'object') {
			return null;
		}
		try {
			return JSON.parse(JSON.stringify(value));
		} catch (error) {
			return null;
		}
	}

	function getManualWarmStageLabel(stage, bucket) {
		if ('html' === stage) {
			return 'HTML';
		}
		if ('css' === stage) {
			return 'CSS';
		}
		if ('varnish' === stage) {
			return 'Varnish ' + String(bucket || 'orig').toUpperCase();
		}
		return 'Finalizing';
	}

	async function requestManualWarmStage(payload) {
		let attempt = 0;
		let lastError = null;
		while (attempt <= maxWarmItemRetries) {
			try {
				return await apiRequest('manual_warm_page_stage', payload);
			} catch (error) {
				lastError = error;
				if (attempt >= maxWarmItemRetries) {
					break;
				}
				await sleep((attempt + 1) * 500);
			}
			attempt += 1;
		}
		return {
			success: false,
			skipped: false,
			requestFailed: true,
			message: lastError && lastError.message ? String(lastError.message) : 'Request failed.',
		};
	}

	async function processJobItem(type, item, shouldCancel, manualSessionToken, state, checkpointItem) {
		const canonicalItem = getCanonicalWarmUrl(item);
		const currentPipeline = state && getCanonicalWarmUrl(state.currentItem || '') === canonicalItem
			? clonePipelineState(state.currentPipeline)
			: null;
		if (!currentPipeline && isWarmUrlSeen(type, item, manualSessionToken)) {
			return {
				line: 'Skipped duplicate: ' + item,
				progressIncrement: 1,
				skippedIncrement: 1,
			};
		}

		const buildCssBundle = shouldBuildCssBundleForWarmJob(type);
		let pipeline = currentPipeline || {
			url: canonicalItem,
			nextStage: 'html',
			buildCssBundle,
			buckets: [],
			bucketIndex: 0,
			refilledBuckets: [],
			varnishEnabled: false,
			html: null,
			css: null,
		};
		pipeline.buildCssBundle = buildCssBundle;

		const checkpoint = (stage, bucket) => {
			if (typeof checkpointItem !== 'function') {
				return;
			}
			checkpointItem({
				currentItem: canonicalItem,
				currentStageLabel: getManualWarmStageLabel(stage, bucket),
				currentPipeline: clonePipelineState(pipeline),
			});
		};
		const clearCheckpoint = () => {
			if (typeof checkpointItem === 'function') {
				checkpointItem({ currentItem: '', currentStageLabel: '', currentPipeline: null });
			}
		};
		const pauseIfRequested = (stage, bucket) => {
			if (!shouldCancel()) {
				return null;
			}
			checkpoint(stage, bucket);
			return {
				pauseItem: true,
				line: 'Paused: ' + item + ' — resume from ' + getManualWarmStageLabel(stage, bucket) + '.',
			};
		};
		const failItem = (message) => {
			markWarmUrlSeen(type, item, manualSessionToken);
			clearCheckpoint();
			return {
				line: 'Failed: ' + item + (message ? ' — ' + message : ''),
				progressIncrement: 1,
				failedIncrement: 1,
			};
		};

		if ('html' === pipeline.nextStage) {
			checkpoint('html');
			const htmlResult = await requestManualWarmStage({
				url: item,
				stage: 'html',
				buildCssBundle,
				manualToken: String(manualSessionToken || ''),
			});
			if (htmlResult && htmlResult.skipped) {
				markWarmUrlSeen(type, item, manualSessionToken);
				clearCheckpoint();
				return {
					line: 'Skipped: ' + item + (htmlResult.message ? ' — ' + String(htmlResult.message) : ''),
					progressIncrement: 1,
					skippedIncrement: 1,
				};
			}
			if (!htmlResult || !htmlResult.success) {
				return failItem(htmlResult && htmlResult.message ? String(htmlResult.message) : 'HTML warm failed.');
			}

			const plan = htmlResult.varnishPlan && typeof htmlResult.varnishPlan === 'object' ? htmlResult.varnishPlan : {};
			pipeline.html = {
				success: true,
				message: String(htmlResult.message || ''),
				forceRefreshReachedOrigin: !!htmlResult.forceRefreshReachedOrigin,
			};
			pipeline.varnishEnabled = !!plan.enabled;
			pipeline.buckets = Array.isArray(plan.buckets) && plan.buckets.length
				? plan.buckets.filter((bucket) => ['orig', 'webp', 'avif'].indexOf(String(bucket)) !== -1)
				: [];
			pipeline.bucketIndex = Math.max(0, Number(pipeline.bucketIndex || 0));
			pipeline.nextStage = buildCssBundle ? 'css' : (pipeline.varnishEnabled && pipeline.buckets.length ? 'varnish' : 'done');
			checkpoint(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
			const paused = pauseIfRequested(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
			if (paused) {
				return paused;
			}
		}

		if ('css' === pipeline.nextStage) {
			checkpoint('css');
			const cssResult = await requestManualWarmStage({
				url: item,
				stage: 'css',
				buildCssBundle: true,
				manualToken: String(manualSessionToken || ''),
			});
			if (!cssResult || (!cssResult.success && !cssResult.skipped)) {
				return failItem(cssResult && cssResult.message ? String(cssResult.message) : 'CSS warm failed.');
			}
			pipeline.css = {
				success: !!cssResult.success,
				skipped: !!cssResult.skipped,
				message: String(cssResult.message || ''),
			};
			pipeline.nextStage = pipeline.varnishEnabled && pipeline.buckets.length ? 'varnish' : 'done';
			checkpoint(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
			const paused = pauseIfRequested(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
			if (paused) {
				return paused;
			}
		}

		if ('varnish' === pipeline.nextStage) {
			while (pipeline.bucketIndex < pipeline.buckets.length) {
				const bucket = String(pipeline.buckets[pipeline.bucketIndex] || 'orig');
				checkpoint('varnish', bucket);
				const varnishResult = await requestManualWarmStage({
					url: item,
					stage: 'varnish',
					bucket,
					manualToken: String(manualSessionToken || ''),
				});
				if (!varnishResult || (!varnishResult.success && !varnishResult.skipped)) {
					return failItem(varnishResult && varnishResult.message ? String(varnishResult.message) : ('Varnish ' + bucket.toUpperCase() + ' refill failed.'));
				}
				if (!varnishResult.skipped && pipeline.refilledBuckets.indexOf(bucket) === -1) {
					pipeline.refilledBuckets.push(bucket);
				}
				pipeline.bucketIndex += 1;
				pipeline.nextStage = pipeline.bucketIndex < pipeline.buckets.length
					? 'varnish'
					: 'done';
				checkpoint(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
				const paused = pauseIfRequested(pipeline.nextStage, pipeline.buckets[pipeline.bucketIndex]);
				if (paused) {
					return paused;
				}
			}
		}

		markWarmUrlSeen(type, item, manualSessionToken);
		clearCheckpoint();
		const varnishWarmed = pipeline.refilledBuckets.length > 0;
		const stageSummary = [];
		stageSummary.push('HTML ✓');
		if (buildCssBundle) {
			stageSummary.push('CSS ✓');
		}
		pipeline.refilledBuckets.forEach((bucket) => stageSummary.push('Varnish ' + String(bucket).toUpperCase() + ' ✓'));

		await sleep(40);
		return {
			line: 'Cached: ' + item + ' — ' + stageSummary.join(' · '),
			progressIncrement: 1,
			successIncrement: 1,
			varnishWarmedIncrement: varnishWarmed ? 1 : 0,
		};
	}

	async function fetchJobBatch(type, cursor, limit, scope) {
		const response = await apiRequest('get_crawl_urls', {
			cursor: cursor || '',
			limit,
			scope: scope || getWarmScopeForType(type),
		});
		return normalizeBatchResponse(response, cursor, limit);
	}

	function createController(config) {
		const source = config && typeof config === 'object' ? config : {};
		const getSettings = typeof source.getSettings === 'function' ? source.getSettings : function () { return {}; };
		const syncQueuedSettingsBeforeAction = typeof source.syncQueuedSettingsBeforeAction === 'function' ? source.syncQueuedSettingsBeforeAction : async function () {};
		const pushToast = typeof source.pushToast === 'function' ? source.pushToast : function () {};
		const queueDashboardAction = typeof source.queueDashboardAction === 'function' ? source.queueDashboardAction : async function () { return null; };
		const setHomepageHtmlBusy = typeof source.setHomepageHtmlBusy === 'function' ? source.setHomepageHtmlBusy : function () {};
		const setHomepageHtmlCssBusy = typeof source.setHomepageHtmlCssBusy === 'function' ? source.setHomepageHtmlCssBusy : function () {};
		const setAllUrlsCssBusy = typeof source.setAllUrlsCssBusy === 'function' ? source.setAllUrlsCssBusy : function () {};
		const setMenuUrlsCssBusy = typeof source.setMenuUrlsCssBusy === 'function' ? source.setMenuUrlsCssBusy : function () {};
		const getJobControls = typeof source.getJobControls === 'function' ? source.getJobControls : function () { return { canResume: false }; };
		const getSavedJob = typeof source.getSavedJob === 'function' ? source.getSavedJob : function () { return null; };
		const isBusy = typeof source.isBusy === 'function' ? source.isBusy : function () { return false; };
		const runJob = typeof source.runJob === 'function' ? source.runJob : async function () {};
		const getWarmupGeneration = typeof source.getWarmupGeneration === 'function' ? source.getWarmupGeneration : function () { return 0; };
		const hasFullSiteWarmScope = typeof source.hasFullSiteWarmScope === 'function' ? source.hasFullSiteWarmScope : function () { return false; };
		const hasMenuWarmScope = typeof source.hasMenuWarmScope === 'function' ? source.hasMenuWarmScope : function () { return false; };
		const beginManualWarmPriority = typeof source.beginManualWarmPriority === 'function' ? source.beginManualWarmPriority : async function () { return ''; };
		const endManualWarmPriority = typeof source.endManualWarmPriority === 'function' ? source.endManualWarmPriority : async function () { return true; };
		const defaultBatchSize = Math.max(1, Number(source.defaultBatchSize || 100));

		function getCurrentCssBundleScope() {
			const settings = getSettings();
			return normalizeCssBundleScopeValue(settings && settings.cssBundleScope);
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
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
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
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
				return;
			}
			if (!(settings && settings.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable CSS Bundling before using CSS bundle warm actions.', 'ultracache') });
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

		async function startWarmingAllWithFrontpageCss(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
				return;
			}
			if (!(settings && settings.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable CSS Bundling before using CSS bundle warm actions.', 'ultracache') });
				return;
			}
			if (!hasFullSiteWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select at least one full-site warm source first.', 'ultracache') });
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('full', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			const controls = getJobControls(jobType);
			if (!forceRestart && controls.canResume) {
				await runJob(getSavedJob(), false);
				return;
			}
			if (isBusy()) {
				return;
			}

			setAllUrlsCssBusy(true);
			let manualPriorityToken = '';
			let manualPriorityHandedOff = false;
			try {
				if ('per-page' !== cssScope) {
					const saved = getSavedJob();
					manualPriorityToken = await beginManualWarmPriority(jobType, saved && saved.manualSessionToken ? saved.manualSessionToken : '');
					const prepared = await buildHomepageCssBundleBeforeWarm(cssScope, 'warm_full_prepare_' + cssScope + '_css');
					if (!prepared) {
						await endManualWarmPriority(manualPriorityToken);
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
					batchSize: defaultBatchSize,
					warmupGeneration: Number(getWarmupGeneration() || 0),
					manualSessionToken: manualPriorityToken,
				}, forceRestart, manualPriorityToken);
				manualPriorityHandedOff = true;
			} catch (error) {
				if (manualPriorityToken && !manualPriorityHandedOff) {
					try {
						await endManualWarmPriority(manualPriorityToken);
					} catch (releaseError) {}
				}
				throw error;
			} finally {
				setAllUrlsCssBusy(false);
			}
		}

		async function startMenuWarming(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
				return;
			}
			if (!hasMenuWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select a frontend menu and depth first.', 'ultracache') });
				return;
			}
			const controls = getJobControls('warm_menu');
			if (!forceRestart && controls.canResume) {
				await runJob(getSavedJob(), false);
				return;
			}
			if (isBusy()) {
				return;
			}
			await runJob({
				type: 'warm_menu',
				scope: 'menu',
				label: __('Warming Menu HTML Cache', 'ultracache'),
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting menu URL crawler…'],
				startTime: Date.now(),
				batchSize: defaultBatchSize,
				warmupGeneration: Number(getWarmupGeneration() || 0),
			}, forceRestart);
		}

		async function startMenuWarmingWithFrontpageCss(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
				return;
			}
			if (!(settings && settings.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable CSS Bundling before using CSS bundle warm actions.', 'ultracache') });
				return;
			}
			if (!hasMenuWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select a frontend menu and depth first.', 'ultracache') });
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('menu', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			const controls = getJobControls(jobType);
			if (!forceRestart && controls.canResume) {
				await runJob(getSavedJob(), false);
				return;
			}
			if (isBusy()) {
				return;
			}

			setMenuUrlsCssBusy(true);
			let manualPriorityToken = '';
			let manualPriorityHandedOff = false;
			try {
				if ('per-page' !== cssScope) {
					const saved = getSavedJob();
					manualPriorityToken = await beginManualWarmPriority(jobType, saved && saved.manualSessionToken ? saved.manualSessionToken : '');
					const prepared = await buildHomepageCssBundleBeforeWarm(cssScope, 'warm_menu_prepare_' + cssScope + '_css');
					if (!prepared) {
						await endManualWarmPriority(manualPriorityToken);
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
					batchSize: defaultBatchSize,
					warmupGeneration: Number(getWarmupGeneration() || 0),
					manualSessionToken: manualPriorityToken,
				}, forceRestart, manualPriorityToken);
				manualPriorityHandedOff = true;
			} catch (error) {
				if (manualPriorityToken && !manualPriorityHandedOff) {
					try {
						await endManualWarmPriority(manualPriorityToken);
					} catch (releaseError) {}
				}
				throw error;
			} finally {
				setMenuUrlsCssBusy(false);
			}
		}

		async function startWarming(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching first or select a profile before warming cache.', 'ultracache') });
				return;
			}
			if (!hasFullSiteWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select at least one full-site warm source first.', 'ultracache') });
				return;
			}
			const controls = getJobControls('warm');
			if (!forceRestart && controls.canResume) {
				await runJob(getSavedJob(), false);
				return;
			}
			if (isBusy()) {
				return;
			}
			await runJob({
				type: 'warm',
				label: __('Warming Full Site HTML Cache', 'ultracache'),
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting full site crawler…'],
				startTime: Date.now(),
				batchSize: defaultBatchSize,
				warmupGeneration: Number(getWarmupGeneration() || 0),
			}, forceRestart);
		}

		return {
			warmFrontpageHtml,
			warmFrontpageHtmlCss,
			startWarmingAllWithFrontpageCss,
			startMenuWarming,
			startMenuWarmingWithFrontpageCss,
			startWarming,
		};
	}

	admin.define('warmup', {
		configure,
		normalizeCssBundleScopeValue,
		getFirstVisitCssBundleHandling,
		getFirstVisitCssBundlePatch,
		getCssWarmJobType,
		getCssWarmBundleLabel,
		isWarmJobType,
		isWarmCssJobType,
		getWarmScopeForType,
		processJobItem,
		fetchJobBatch,
		createController,
	});
})(window);
