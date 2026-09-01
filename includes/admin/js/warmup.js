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

	const { __, sleep, reportNonFatalAdminError } = core;
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

	function getManualWarmOperation(type) {
		return String(type || '').indexOf('warm_menu') === 0 ? 'menu' : 'full_site';
	}

	function getWarmRequestUrl(url) {
		try {
			const parsed = new URL(String(url || ''), window.location.origin);
			parsed.hash = '';
			['ultracache_revalidate', 'ultracache_rt', 'ultracache_rv', 'ultracache_bucket', 'ultracache_frontpage_css_scan', 'ultracache_css_v'].forEach((key) => {
				parsed.searchParams.delete(key);
			});
			parsed.searchParams.sort();
			return parsed.toString();
		} catch (error) {
			return String(url || '').split('#')[0];
		}
	}

	function getCanonicalWarmUrl(url) {
		return getWarmRequestUrl(url).replace(/\/+(\?|$)/, '$1');
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

	function getPipelineStageLabel(stage) {
		if ('html' === stage) {
			return 'HTML';
		}
		if ('css' === stage) {
			return 'CSS';
		}
		if ('lcp' === stage) {
			return 'LCP';
		}
		if ('varnish' === stage) {
			return 'Varnish';
		}
		if ('litespeed' === stage) {
			return 'LiteSpeed';
		}
		return String(stage || 'Pipeline');
	}

	function getPipelineStageStatus(result, stage) {
		const pipeline = result && result.pipeline && typeof result.pipeline === 'object' ? result.pipeline : {};
		const stages = pipeline.stages && typeof pipeline.stages === 'object' ? pipeline.stages : {};
		const stageResult = stages[stage] && typeof stages[stage] === 'object' ? stages[stage] : {};
		return String(stageResult.status || '');
	}

	function buildExternalCacheVariantSummary(stageResult) {
		const details = stageResult && stageResult.details && typeof stageResult.details === 'object' ? stageResult.details : {};
		const rows = Array.isArray(details.refillDetails) ? details.refillDetails : [];
		const variants = rows.map((row) => {
			if (!row || typeof row !== 'object') {
				return '';
			}
			const bucket = String(row.bucket || '').toLowerCase();
			if (['orig', 'webp', 'avif'].indexOf(bucket) === -1) {
				return '';
			}
			if (row.success) {
				const cacheStatus = String(row.cacheStatus || row.refillStatus || '').toUpperCase();
				return bucket + (cacheStatus === 'INCONCLUSIVE' ? ' ?' : ' ✓');
			}
			return bucket + ' ✗';
		}).filter(Boolean);
		return variants.join(' / ');
	}

	function buildWarmPipelineSummary(result) {
		const pipeline = result && result.pipeline && typeof result.pipeline === 'object' ? result.pipeline : {};
		const stages = pipeline.stages && typeof pipeline.stages === 'object' ? pipeline.stages : {};
		const parts = [];
		['html', 'css', 'lcp', 'varnish', 'litespeed'].forEach((stage) => {
			const stageResult = stages[stage] && typeof stages[stage] === 'object' ? stages[stage] : null;
			if (!stageResult) {
				return;
			}
			const status = String(stageResult.status || '');
			if ('disabled' === status || 'planned' === status) {
				return;
			}
			const label = getPipelineStageLabel(stage);
			if ('completed' === status || 'warning' === status) {
				const variantSummary = ('varnish' === stage || 'litespeed' === stage)
					? buildExternalCacheVariantSummary(stageResult)
					: '';
				if (variantSummary) {
					parts.push(label + ' ' + variantSummary + ('warning' === status ? ' warning' : ''));
				} else {
					parts.push(label + ('warning' === status ? ' warning' : ' ✓'));
				}
				return;
			}
			if ('skipped' === status) {
				parts.push(label + ' skipped');
				return;
			}
			if ('failed' === status) {
				const variantSummary = ('varnish' === stage || 'litespeed' === stage)
					? buildExternalCacheVariantSummary(stageResult)
					: '';
				parts.push(label + (variantSummary ? ' ' + variantSummary : ' failed'));
			}
		});
		return parts.length ? parts.join(' · ') : String(result && result.message ? result.message : 'Page pipeline completed.');
	}

	function buildQueuedWarmPipelineSuccess(completed, fallbackText) {
		const result = completed && completed.result && typeof completed.result === 'object' ? completed.result : {};
		const pipeline = result.pipeline && typeof result.pipeline === 'object' ? result.pipeline : {};
		const warmUrl = String(pipeline.url || result.url || '');
		if (!warmUrl) {
			return String(fallbackText || result.message || 'Homepage warm completed.');
		}

		if (result && result.skipped) {
			return 'Skipped: ' + getWarmRequestUrl(warmUrl) + (result.message ? ' — ' + String(result.message) : ' — ' + buildWarmPipelineSummary(result));
		}
		if (!result || !result.success) {
			return 'Failed: ' + getWarmRequestUrl(warmUrl) + (result && result.message ? ' — ' + String(result.message) : ' — Page pipeline failed.');
		}

		return 'Cached: ' + getWarmRequestUrl(warmUrl) + ' — ' + buildWarmPipelineSummary(result);
	}

	async function requestManualWarmPage(payload) {
		let attempt = 0;
		let lastError = null;
		while (attempt <= maxWarmItemRetries) {
			try {
				const response = await apiRequest('crawl_page', payload);
				if (!(response && response.coalesced)) {
					return response;
				}
				if (attempt >= maxWarmItemRetries) {
					return response;
				}
				await sleep((attempt + 1) * 750);
			} catch (error) {
				lastError = error;
				const errorData = error && error.data && typeof error.data === 'object' ? error.data : null;
				if (errorData && errorData.coalesced) {
					if (attempt >= maxWarmItemRetries) {
						return errorData;
					}
					await sleep((attempt + 1) * 750);
				} else if (error && error.rest && Number(error.rest.status || 0) === 409) {
					return {
						success: false,
						skipped: false,
						ownershipLost: true,
						message: error && error.message ? String(error.message) : 'Foreground warm-up ownership was transferred.',
					};
				} else {
					if (attempt >= maxWarmItemRetries) {
						break;
					}
					await sleep((attempt + 1) * 500);
				}
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

	async function processJobItem(type, item, shouldCancel, manualSessionToken) {
		if (isWarmUrlSeen(type, item, manualSessionToken)) {
			return {
				line: 'Skipped duplicate: ' + item,
				progressIncrement: 1,
				skippedIncrement: 1,
			};
		}

		if (shouldCancel()) {
			return {
				pauseItem: true,
				line: 'Paused before page pipeline: ' + item + '.',
			};
		}

		const result = await requestManualWarmPage({
			url: getWarmRequestUrl(item),
			buildCssBundle: shouldBuildCssBundleForWarmJob(type),
			manualToken: String(manualSessionToken || ''),
		});

		if (result && result.ownershipLost) {
			return {
				pauseItem: true,
				line: 'Foreground ownership transferred: ' + item + '.',
			};
		}
		if (result && result.coalesced) {
			return {
				pauseItem: true,
				line: 'Paused because another request still owns this URL: ' + item + '. Resume to retry the complete page pipeline.',
			};
		}

		markWarmUrlSeen(type, item, manualSessionToken);

		if (result && result.skipped) {
			return {
				line: 'Skipped: ' + item + (result.message ? ' — ' + String(result.message) : ''),
				progressIncrement: 1,
				skippedIncrement: 1,
			};
		}

		if (!result || !result.success) {
			return {
				line: 'Failed: ' + item + (result && result.message ? ' — ' + String(result.message) : ' — Page pipeline failed.'),
				progressIncrement: 1,
				failedIncrement: 1,
			};
		}

		const canonicalUrl = result && result.pipeline && result.pipeline.url
			? getWarmRequestUrl(result.pipeline.url)
			: getWarmRequestUrl(item);
		const summary = buildWarmPipelineSummary(result);
		const canonicalNote = canonicalUrl !== getWarmRequestUrl(item) ? ' · Canonical URL: ' + canonicalUrl : '';
		await sleep(40);
		return {
			line: 'Cached: ' + item + ' — ' + summary + canonicalNote,
			progressIncrement: 1,
			successIncrement: 1,
			varnishWarmedIncrement: 'completed' === getPipelineStageStatus(result, 'varnish') ? 1 : 0,
			liteSpeedWarmedIncrement: 'completed' === getPipelineStageStatus(result, 'litespeed') ? 1 : 0,
		};
	}

	async function fetchJobBatch(type, cursor, limit, scope, state) {
		const response = await apiRequest('get_crawl_urls', {
			cursor: cursor || '',
			limit,
			scope: scope || getWarmScopeForType(type),
			operation: getManualWarmOperation(type),
			language: state && state.language ? String(state.language) : '',
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
		const getManualWarmLanguages = typeof source.getManualWarmLanguages === 'function' ? source.getManualWarmLanguages : function () { return ['']; };
		const defaultBatchSize = Math.max(1, Number(source.defaultBatchSize || 100));

		function getCurrentCssBundleScope() {
			const settings = getSettings();
			return normalizeCssBundleScopeValue(settings && settings.cssBundleScope);
		}

		function getOperationLanguageSequence(operation) {
			const raw = getManualWarmLanguages(String(operation || ''));
			const seen = {};
			return (Array.isArray(raw) ? raw : ['']).map((language) => String(language || '').trim()).filter((language) => {
				const key = language || '__default__';
				if (seen[key]) {
					return false;
				}
				seen[key] = true;
				return true;
			});
		}

		function getLanguageActionSuffix(language, index, total) {
			if (!language || total <= 1) {
				return '';
			}
			return ' [' + language + ' ' + String(index + 1) + '/' + String(total) + ']';
		}

		async function buildHomepageCssBundleBeforeWarm(scope, actionKey, language, operation, index, total) {
			scope = normalizeCssBundleScopeValue(scope);
			if ('per-page' === scope) {
				return true;
			}

			const label = getCssWarmBundleLabel(scope, false);
			const suffix = getLanguageActionSuffix(language, Number(index || 0), Number(total || 1));
			const params = {};
			if (language) {
				params.language = language;
			}
			if (['menu', 'full_site'].indexOf(String(operation || '')) !== -1) {
				params.operation = String(operation);
			}
			const completed = await queueDashboardAction('warm_frontpage_html_css', params, {
				queued: label + ' build processing via dashboard' + suffix + '…',
				success: (job) => buildQueuedWarmPipelineSuccess(job, label + ' built and homepage HTML warmed' + suffix + '.'),
				failed: label + ' build failed' + suffix + '.',
				runningLabel: 'Building CSS' + suffix + '…',
			}, (actionKey || ('prepare_' + scope + '_css_bundle')) + (language ? '_' + language : ''));

			return !!(completed && completed.status === 'done');
		}

		async function runCrawlerAcrossLanguages(config, forceRestart) {
			const sourceConfig = config && typeof config === 'object' ? config : {};
			const type = String(sourceConfig.type || 'warm');
			const operation = String(sourceConfig.operation || getManualWarmOperation(type));
			const languages = getOperationLanguageSequence(operation);
			if (!languages.length) {
				pushToast({ type: 'warning', text: __('No multilingual language is enabled for this warm operation.', 'ultracache') });
				return false;
			}

			const controls = getJobControls(type);
			let startIndex = 0;
			let resumeJob = null;
			if (!forceRestart && controls.canResume) {
				resumeJob = getSavedJob();
				const savedSequence = resumeJob && Array.isArray(resumeJob.languageSequence)
					? resumeJob.languageSequence.map((language) => String(language || ''))
					: languages;
				const savedLanguage = String(resumeJob && resumeJob.language || '');
				const savedIndex = Math.max(0, Number(resumeJob && resumeJob.languageIndex || 0));
				startIndex = savedIndex < savedSequence.length ? savedIndex : Math.max(0, savedSequence.indexOf(savedLanguage));
				if (savedSequence.length) {
					languages.splice(0, languages.length, ...savedSequence);
				}
			}

			for (let index = startIndex; index < languages.length; index += 1) {
				const language = String(languages[index] || '');
				if (typeof sourceConfig.prepare === 'function' && !(resumeJob && index === startIndex)) {
					const prepared = await sourceConfig.prepare(language, index, languages.length);
					if (!prepared) {
						return false;
					}
				}

				const job = resumeJob && index === startIndex
					? Object.assign({}, resumeJob, {
						language,
						languageSequence: languages.slice(),
						languageIndex: index,
					})
					: {
						type,
						scope: sourceConfig.scope || getWarmScopeForType(type),
						label: String(sourceConfig.label || 'Cache warming') + getLanguageActionSuffix(language, index, languages.length),
						cursor: '',
						nextCursor: '',
						processed: 0,
						total: 0,
						pendingItems: [],
						hasMore: true,
						logs: [String(sourceConfig.startLog || 'Starting cache crawler…') + getLanguageActionSuffix(language, index, languages.length)],
						startTime: Date.now(),
						batchSize: defaultBatchSize,
						warmupGeneration: Number(getWarmupGeneration() || 0),
						language,
						languageSequence: languages.slice(),
						languageIndex: index,
					};

				const outcome = await runJob(job, !!forceRestart && index === startIndex);
				resumeJob = null;
				if (!(outcome && outcome.completed)) {
					return false;
				}
			}
			return true;
		}

		async function warmFrontpageHtml() {
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
				return false;
			}
			const languages = getOperationLanguageSequence('homepage');
			if (!languages.length) {
				pushToast({ type: 'warning', text: __('No multilingual language is enabled for Warm Homepage.', 'ultracache') });
				return false;
			}
			let succeeded = true;
			setHomepageHtmlBusy(true);
			try {
				for (let index = 0; index < languages.length; index += 1) {
					const language = String(languages[index] || '');
					const suffix = getLanguageActionSuffix(language, index, languages.length);
					const completed = await queueDashboardAction('warm_frontpage_html', language ? { language } : {}, {
						queued: 'Frontpage HTML warm processing via dashboard' + suffix + '…',
						success: (job) => buildQueuedWarmPipelineSuccess(job, 'Frontpage HTML warm completed' + suffix + '.'),
						failed: 'Frontpage HTML warm failed' + suffix + '.',
						runningLabel: 'Warming' + suffix + '…',
					}, 'warm_frontpage_html' + (language ? '_' + language : ''));
					if (!(completed && completed.status === 'done')) {
						succeeded = false;
						break;
					}
				}
			} finally {
				setHomepageHtmlBusy(false);
			}
			return succeeded;
		}

		async function warmFrontpageHtmlCss() {
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
				return false;
			}
			if (!(settings && settings.homepageCssBundleEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable CSS Bundling before using CSS bundle warm actions.', 'ultracache') });
				return false;
			}
			const languages = getOperationLanguageSequence('homepage');
			if (!languages.length) {
				pushToast({ type: 'warning', text: __('No multilingual language is enabled for Warm Homepage.', 'ultracache') });
				return false;
			}
			let succeeded = true;
			setHomepageHtmlCssBusy(true);
			try {
				for (let index = 0; index < languages.length; index += 1) {
					const language = String(languages[index] || '');
					const suffix = getLanguageActionSuffix(language, index, languages.length);
					const completed = await queueDashboardAction('warm_frontpage_html_css', language ? { language } : {}, {
						queued: 'Homepage HTML + CSS bundle warm processing via dashboard' + suffix + '…',
						success: (job) => buildQueuedWarmPipelineSuccess(job, 'Homepage HTML + CSS bundle warm completed' + suffix + '.'),
						failed: 'Homepage HTML + CSS bundle warm failed' + suffix + '.',
						runningLabel: 'Warming' + suffix + '…',
					}, 'warm_frontpage_html_css' + (language ? '_' + language : ''));
					if (!(completed && completed.status === 'done')) {
						succeeded = false;
						break;
					}
				}
			} finally {
				setHomepageHtmlCssBusy(false);
			}
			return succeeded;
		}

		async function startWarmingAllWithFrontpageCss(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
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
			if (isBusy()) {
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('full', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			setAllUrlsCssBusy(true);
			try {
				await runCrawlerAcrossLanguages({
					type: jobType,
					operation: 'full_site',
					scope: 'full',
					label: 'Warming Full Site HTML Cache + ' + bundleLabel,
					startLog: 'Starting full site crawler + ' + bundleLabel + '…',
					prepare: 'per-page' === cssScope ? null : (language, index, total) => buildHomepageCssBundleBeforeWarm(cssScope, 'warm_full_prepare_' + cssScope + '_css', language, 'full_site', index, total),
				}, forceRestart);
			} finally {
				setAllUrlsCssBusy(false);
			}
		}

		async function startMenuWarming(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
				return;
			}
			if (!hasMenuWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select a frontend menu and depth first.', 'ultracache') });
				return;
			}
			if (isBusy()) {
				return;
			}
			await runCrawlerAcrossLanguages({
				type: 'warm_menu',
				operation: 'menu',
				scope: 'menu',
				label: __('Warming Menu HTML Cache', 'ultracache'),
				startLog: 'Starting menu URL crawler…',
			}, forceRestart);
		}

		async function startMenuWarmingWithFrontpageCss(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
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
			if (isBusy()) {
				return;
			}

			const cssScope = getCurrentCssBundleScope();
			const jobType = getCssWarmJobType('menu', cssScope);
			const bundleLabel = getCssWarmBundleLabel(cssScope, true);
			setMenuUrlsCssBusy(true);
			try {
				await runCrawlerAcrossLanguages({
					type: jobType,
					operation: 'menu',
					scope: 'menu',
					label: 'Warming Menu HTML Cache + ' + bundleLabel,
					startLog: 'Starting menu URL crawler + ' + bundleLabel + '…',
					prepare: 'per-page' === cssScope ? null : (language, index, total) => buildHomepageCssBundleBeforeWarm(cssScope, 'warm_menu_prepare_' + cssScope + '_css', language, 'menu', index, total),
				}, forceRestart);
			} finally {
				setMenuUrlsCssBusy(false);
			}
		}

		async function startWarming(forceRestart) {
			forceRestart = !!forceRestart;
			await syncQueuedSettingsBeforeAction();
			const settings = getSettings();
			if (!(settings && settings.pageCacheEnabled)) {
				pushToast({ type: 'warning', text: __('Please enable Page Caching before warming cache.', 'ultracache') });
				return;
			}
			if (!hasFullSiteWarmScope(settings)) {
				pushToast({ type: 'warning', text: __('Select at least one full-site warm source first.', 'ultracache') });
				return;
			}
			if (isBusy()) {
				return;
			}
			await runCrawlerAcrossLanguages({
				type: 'warm',
				operation: 'full_site',
				scope: 'full',
				label: __('Warming Full Site HTML Cache', 'ultracache'),
				startLog: 'Starting full site crawler…',
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
		getManualWarmOperation,
		processJobItem,
		fetchJobBatch,
		createController,
	});
})(window);
