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
			['ultracache_revalidate', 'ultracache_rt', 'ultracache_frontpage_css_scan', 'ultracache_css_v'].forEach((key) => {
				parsed.searchParams.delete(key);
			});
			parsed.searchParams.sort();
			return parsed.toString().replace(/\/+(\?|$)/, '$1');
		} catch (error) {
			return String(url || '').split('#')[0];
		}
	}

	function hasSeenWarmUrl(type, url, manualSessionToken) {
		const sessionKey = String(manualSessionToken || '') || ('no-token:' + String(type || 'warm'));
		const canonicalUrl = getCanonicalWarmUrl(url);
		if (!manualWarmSeenUrls.has(sessionKey)) {
			manualWarmSeenUrls.set(sessionKey, new Set());
		}
		const seen = manualWarmSeenUrls.get(sessionKey);
		if (seen.has(canonicalUrl)) {
			return true;
		}
		seen.add(canonicalUrl);
		return false;
	}

	async function processJobItem(type, item, shouldCancel, manualSessionToken) {
		if (hasSeenWarmUrl(type, item, manualSessionToken)) {
			return {
				line: 'Skipped duplicate: ' + item,
				progressIncrement: 1,
				skippedIncrement: 1,
			};
		}

		let attempt = 0;
		let lastError = null;

		while (attempt <= maxWarmItemRetries) {
			try {
				const result = await apiRequest('crawl_page', {
					url: item,
					buildCssBundle: shouldBuildCssBundleForWarmJob(type),
					manualToken: String(manualSessionToken || ''),
				});
				const skipped = !!(result && (result.cached === false || result.skipped));
				const failed = !!(result && result.success === false && !skipped);
				const detail = failed || skipped ? (result && result.message ? ' — ' + result.message : '') : '';
				const varnishRefill = result && result.varnishRefill && typeof result.varnishRefill === 'object' ? result.varnishRefill : null;
				const varnishWarmed = !!(varnishRefill && varnishRefill.success && !varnishRefill.skipped);
				const varnishFailed = !!(varnishRefill && varnishRefill.success === false);
				const varnishVerificationEnabled = !!(varnishRefill && varnishRefill.verificationEnabled);
				const varnishTwoStage = varnishRefill && varnishRefill.twoStageRefill && typeof varnishRefill.twoStageRefill === 'object' ? varnishRefill.twoStageRefill : null;
				const varnishTwoStageAvailable = !!(varnishTwoStage && varnishTwoStage.available);
				const varnishTwoStageFallback = !!(varnishTwoStage && !varnishTwoStage.available && varnishTwoStage.fallbackUsed);
				const varnishVerificationStatus = String(varnishRefill && varnishRefill.verificationStatus ? varnishRefill.verificationStatus : 'disabled').toLowerCase();
				const varnishVerified = varnishVerificationEnabled && varnishVerificationStatus === 'verified';
				const varnishBypassed = varnishVerificationEnabled && varnishVerificationStatus === 'bypassed';
				const varnishInconclusive = varnishVerificationEnabled && ['inconclusive', 'not-hit', 'error'].indexOf(varnishVerificationStatus) !== -1;
				let varnishDetail = '';
				if (varnishFailed) {
					varnishDetail = ' — Varnish refill failed' + (varnishRefill.message ? ': ' + String(varnishRefill.message) : '');
				} else if (varnishVerified) {
					varnishDetail = ' — Varnish HIT verified';
				} else if (varnishBypassed) {
					varnishDetail = ' — Varnish bypassed';
				} else if (varnishInconclusive) {
					varnishDetail = ' — Varnish verification ' + varnishVerificationStatus.replace(/-/g, ' ');
				}
				if (varnishWarmed && varnishTwoStageAvailable) {
					varnishDetail += ' — origin refresh verified';
				} else if (varnishWarmed && varnishTwoStageFallback) {
					varnishDetail += ' — one-stage fallback';
				}
				const successLabel = shouldBuildCssBundleForWarmJob(type)
					? (varnishWarmed ? 'Cached + CSS + Varnish: ' : 'Cached + CSS: ')
					: (varnishWarmed ? 'Cached + Varnish: ' : 'Cached: ');
				await sleep(40);
				return {
					line: (failed ? 'Failed: ' : (skipped ? 'Skipped: ' : successLabel)) + item + detail + varnishDetail,
					progressIncrement: 1,
					successIncrement: failed || skipped ? 0 : 1,
					skippedIncrement: skipped ? 1 : 0,
					failedIncrement: failed ? 1 : 0,
					varnishWarmedIncrement: varnishWarmed ? 1 : 0,
					varnishVerifiedIncrement: varnishVerified ? 1 : 0,
					varnishBypassedIncrement: varnishBypassed ? 1 : 0,
					varnishInconclusiveIncrement: varnishInconclusive ? 1 : 0,
				};
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
			line: 'Failed: ' + item + ' — Request failed.',
			progressIncrement: 1,
			failedIncrement: 1,
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
