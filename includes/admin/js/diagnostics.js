/* UltraCache Admin - JavaScript/CSS diagnostics and Runtime Scan helpers */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function' || typeof admin.get !== 'function') {
		throw new Error('UltraCache admin namespace was not loaded.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	const settings = admin.get('settings');
	const help = admin.get('help');
	const ui = admin.get('ui');
	if (!core || !api || !settings || !help || !ui) {
		throw new Error('UltraCache diagnostics dependencies were not loaded before diagnostics.js.');
	}

	const {
		h,
		useEffect,
		useState,
		__,
		joinPublicPath,
		formatBytes,
		formatNumber,
	} = core;
	const { apiRequest } = api;
	const {
		normalizeSettingListLines,
		jsExclusionLineCoversTarget,
		mergeUniqueSettingLines,
		settingLinesOverlap,
		removeOverlappingSettingLines,
	} = settings;
	const { renderLabelWithHelp } = help;
	const { Button, DetailRow } = ui;

	let ultracache = {};
	let pluginsPublicPath = '';

	function configure(nextRuntime) {
		const runtime = nextRuntime && typeof nextRuntime === 'object' ? nextRuntime : {};
		if (Object.prototype.hasOwnProperty.call(runtime, 'ultracache')) {
			ultracache = runtime.ultracache && typeof runtime.ultracache === 'object' ? runtime.ultracache : {};
		}
		if (Object.prototype.hasOwnProperty.call(runtime, 'pluginsPublicPath')) {
			pluginsPublicPath = String(runtime.pluginsPublicPath || '');
		}
	}

function getJsDelaySafetySuggestions(scan) {
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		return suggestions
			.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && item.appendable !== false)
			.map((item) => String(item.suggestedExclusion).trim())
			.filter((line) => line.length > 0);
	}

function getJsDelayReviewSuggestions(scan) {
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		return suggestions
			.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && item.appendable === false)
			.map((item) => String(item.suggestedExclusion).trim())
			.filter((line) => line.length > 0);
	}

function isSuggestionPresentInDraft(draftValue, suggestion) {
		const lines = normalizeSettingListLines(draftValue).map((line) => line.toLowerCase());
		const target = String(suggestion || '').trim().toLowerCase();
		if (!target) {
			return false;
		}
		return lines.some((line) => jsExclusionLineCoversTarget(line, target));
	}

function DeferDelayExclusionsField({ value, onSave, forceDeferValue, onForceDeferSave, onSaveBoth, disabled, placeholder, forceDeferPlaceholder, onPopulateDefaults, onScan, onRuntimeScan, onAppendDelayPattern }) {
		const defaultScanUrl = (typeof ultracache !== "undefined" && ultracache && ultracache.frontendProbeUrl) ? String(ultracache.frontendProbeUrl || "") : "";
		const [draft, setDraft] = useState(value || "");
		const [forceDraft, setForceDraft] = useState(forceDeferValue || "");
		const [scanUrl, setScanUrl] = useState(defaultScanUrl);
		const [scan, setScan] = useState(null);
		const [populateBusy, setPopulateBusy] = useState(false);
		const [scanBusy, setScanBusy] = useState(false);
		const [scanStatus, setScanStatus] = useState('');
		const [scanProgress, setScanProgress] = useState(null);
		const [runtimeScanBusy, setRuntimeScanBusy] = useState(false);
		const [runtimeScanStatus, setRuntimeScanStatus] = useState('');
		const [runtimeScanContext, setRuntimeScanContext] = useState('anonymous');
		const [consoleErrorInput, setConsoleErrorInput] = useState('');
		const [consoleErrorSuggestions, setConsoleErrorSuggestions] = useState([]);
		const [consoleErrorScan, setConsoleErrorScan] = useState(null);
		const [consoleErrorStatus, setConsoleErrorStatus] = useState('');
		const [consoleErrorBusy, setConsoleErrorBusy] = useState(false);
		const [jsDiagnosticQueue, setJsDiagnosticQueue] = useState(null);
		const [jsDiagnosticQueueBusy, setJsDiagnosticQueueBusy] = useState(false);
		const [selectedSuggestionActions, setSelectedSuggestionActions] = useState({});
		const [lastEditedSafeguardList, setLastEditedSafeguardList] = useState('');

		useEffect(() => {
			setDraft(value || '');
		}, [value]);

		useEffect(() => {
			setForceDraft(forceDeferValue || '');
		}, [forceDeferValue]);

		useEffect(() => {
			let cancelled = false;
			if (disabled || typeof onScan !== 'function' || !defaultScanUrl) {
				return undefined;
			}
			(async function resumeHtmlDependencyScan() {
				try {
					const result = await onScan(defaultScanUrl, function(progress, message) {
						if (cancelled) {
							return;
						}
						setScanBusy(true);
						setScanProgress((previous) => Object.assign({}, previous && typeof previous === 'object' ? previous : {}, progress && typeof progress === 'object' ? progress : {}));
						setScanStatus(String(message || 'Resuming HTML JS dependency analysis…'));
					}, { resumeOnly: true });
					if (!cancelled && result && typeof result === 'object') {
						setScan(result);
						if (result.scannedUrl) {
							setScanUrl(String(result.scannedUrl));
						}
						setScanStatus('HTML JS dependency analysis completed.');
					}
				} finally {
					if (!cancelled) {
						setScanBusy(false);
					}
				}
			})();
			return function() {
				cancelled = true;
			};
		}, []);


		const currentValue = String(value || '');
		const draftValue = String(draft || '');
		const currentForceValue = String(forceDeferValue || '');
		const forceDraftValue = String(forceDraft || '');
		const hasChanges = draftValue !== currentValue;
		const forceHasChanges = forceDraftValue !== currentForceValue;
		const safeguardListsOverlap = normalizeSettingListLines(forceDraftValue).some((forceLine) => normalizeSettingListLines(draftValue).some((excludeLine) => settingLinesOverlap(forceLine, excludeLine)));
		function suggestionLine(item) {
			return String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim();
		}
		function suggestionInFallback(item) {
			const line = suggestionLine(item);
			return !!line && (!!(item && item.alreadyExcluded) || isSuggestionPresentInDraft(draftValue, line));
		}
		function suggestionInForce(item) {
			const line = suggestionLine(item);
			return !!line && (!!(item && item.alreadyForceDeferred) || isSuggestionPresentInDraft(forceDraftValue, line));
		}
		function suggestionPrefersExclusion(item) {
			const target = item && item.preferredTarget ? String(item.preferredTarget).toLowerCase() : '';
			return target === 'exclusion' || !!(item && item.fallbackRecommended && !item.alreadyExcluded);
		}
		const suggestions = scan && Array.isArray(scan.suggestions) ? scan.suggestions : [];
		const isStrongHtmlScan = !!(scan && String(scan.source || '') === 'html-strong-dependency-analysis');
		const actionableSuggestions = suggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored);
		const persistentListedFailureSuggestions = actionableSuggestions.filter((item) => !!(item && item.stillFailingWhileListed));
		const alreadyListedSuggestions = actionableSuggestions.filter((item) => suggestionInFallback(item) && !(item && item.stillFailingWhileListed));
		const appendableSuggestions = actionableSuggestions.filter((item) => item.appendable !== false && !item.alreadyExcluded);
		const reviewOnlySuggestions = actionableSuggestions.filter((item) => item.appendable === false && !item.alreadyExcluded);
		const missingAppendableSuggestions = appendableSuggestions.filter((item) => !suggestionPrefersExclusion(item) && !suggestionInFallback(item) && !suggestionInForce(item));
		const fallbackAppendableSuggestions = isStrongHtmlScan
			? appendableSuggestions.filter((item) => !suggestionInFallback(item) && (suggestionPrefersExclusion(item) || suggestionInForce(item)))
			: appendableSuggestions.filter((item) => !suggestionInFallback(item));
		const fallbackEscalationSuggestions = fallbackAppendableSuggestions.filter((item) => suggestionInForce(item) || suggestionPrefersExclusion(item));
		const alreadyListedAppendableSuggestions = alreadyListedSuggestions;
		const missingReviewOnlySuggestions = reviewOnlySuggestions.filter((item) => !suggestionInFallback(item) && !suggestionInForce(item));
		const totalDetected = scan && typeof scan.suggestionCount !== 'undefined' ? Number(scan.suggestionCount || 0) : suggestions.length;
		const liveMissingCount = missingAppendableSuggestions.length;
		const fallbackMissingCount = fallbackAppendableSuggestions.length;
		const fallbackEscalationCount = fallbackEscalationSuggestions.length;
		const confirmedRuntimeErrorCount = Number(scan && scan.runtimeErrorCount ? scan.runtimeErrorCount : 0) || (scan && Array.isArray(scan.errors) ? scan.errors.length : 0);
		const hasConfirmedRuntimeErrors = !!(scan && (scan.source === 'browser-runtime' || confirmedRuntimeErrorCount > 0) && confirmedRuntimeErrorCount > 0);
		const confirmedErrorMissingCount = hasConfirmedRuntimeErrors ? missingAppendableSuggestions.length : 0;
		const suggestionMissingCount = hasConfirmedRuntimeErrors ? 0 : liveMissingCount;
		const liveAlreadyListedCount = alreadyListedAppendableSuggestions.length;
		const persistentListedFailureCount = persistentListedFailureSuggestions.length;
		const reviewOnlyCount = reviewOnlySuggestions.length;
		const runtimeErrors = scan && Array.isArray(scan.errors) ? scan.errors : [];
		const resourceErrors = scan && Array.isArray(scan.resourceErrors) ? scan.resourceErrors : (scan && Array.isArray(scan.blockedResources) ? scan.blockedResources : []);
		const resourceErrorCount = scan && typeof scan.resourceErrorCount !== 'undefined' ? Number(scan.resourceErrorCount || 0) : resourceErrors.length;
		const blockedResourceCount = scan && typeof scan.blockedResourceCount !== 'undefined' ? Number(scan.blockedResourceCount || 0) : resourceErrors.filter((item) => item && item.likelyClientBlocked).length;
		const missingConsoleErrorSuggestions = consoleErrorSuggestions.filter((line) => !isSuggestionPresentInDraft(draftValue, line) && !isSuggestionPresentInDraft(forceDraftValue, line));
		const consoleSuggestions = consoleErrorScan && Array.isArray(consoleErrorScan.suggestions) ? consoleErrorScan.suggestions : [];
		const consoleActionableSuggestions = consoleSuggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored);
		const consolePersistentFailures = consoleActionableSuggestions.filter((item) => !!(item && item.stillFailingWhileListed));
		const consoleDependencyRisks = consoleActionableSuggestions.filter((item) => item && item.source === 'page-dependency-analysis');
		const consoleAppendableSuggestions = consoleActionableSuggestions.filter((item) => item.appendable !== false && !item.alreadyExcluded);
		const consoleReviewOnlySuggestions = consoleActionableSuggestions.filter((item) => item.appendable === false && !item.alreadyExcluded && !item.stillFailingWhileListed);
		const consoleFallbackSuggestions = consoleAppendableSuggestions
			.map((item) => suggestionLine(item))
			.filter((line, index, lines) => line && !isSuggestionPresentInDraft(draftValue, line) && lines.indexOf(line) === index);
		const missingConsoleReviewOnlySuggestions = consoleReviewOnlySuggestions.filter((item) => !suggestionInFallback(item) && !suggestionInForce(item));
		const jsDiagnosticQueueResult = jsDiagnosticQueue && jsDiagnosticQueue.result && typeof jsDiagnosticQueue.result === 'object' ? jsDiagnosticQueue.result : null;
		const jsDiagnosticQueueBucketCounts = jsDiagnosticQueueResult && jsDiagnosticQueueResult.bucketCounts ? jsDiagnosticQueueResult.bucketCounts : {};
		const jsDiagnosticQueueBuckets = jsDiagnosticQueueResult && jsDiagnosticQueueResult.buckets && typeof jsDiagnosticQueueResult.buckets === 'object' ? jsDiagnosticQueueResult.buckets : {};
		const jsDiagnosticQueueProgressTotal = jsDiagnosticQueue ? Math.max(1, Number(jsDiagnosticQueue.progressTotal || 100)) : 100;
		const jsDiagnosticQueueProgressCurrent = jsDiagnosticQueue ? Math.max(0, Math.min(jsDiagnosticQueueProgressTotal, Number(jsDiagnosticQueue.progressCurrent || 0))) : 0;
		const jsDiagnosticQueueProgressPercent = jsDiagnosticQueue ? Math.round((jsDiagnosticQueueProgressCurrent / jsDiagnosticQueueProgressTotal) * 100) : 0;
		const htmlDependencyProgress = scanProgress && typeof scanProgress === 'object' ? scanProgress : {};
		const htmlDependencyProgressPercent = Math.max(0, Math.min(100, Number(htmlDependencyProgress.progressPercent || 0)));
		const htmlDependencyTotalScripts = Math.max(0, Number(htmlDependencyProgress.totalScripts || 0));
		const htmlDependencyTotalFiles = Math.max(0, Number(htmlDependencyProgress.totalFiles || 0));
		const htmlDependencyProcessedFiles = Math.max(0, Number(htmlDependencyProgress.processedFiles || 0));
		const htmlDependencyCacheHits = Math.max(0, Number(htmlDependencyProgress.cacheHits || 0));
		const htmlDependencyFreshFiles = Math.max(0, Number(htmlDependencyProgress.freshlyAnalyzedFiles || 0));



		function normalizeSuggestionActionPattern(pattern) {
			return String(pattern || '').trim().replace(/^\/+/, '');
		}

		function getSuggestionSourcePath(item) {
			const source = item && (item.definingScriptUrl || item.sourceUrl || item.url) ? String(item.definingScriptUrl || item.sourceUrl || item.url) : '';
			if (!source) {
				return '';
			}
			try {
				const parsed = new URL(source, window.location.origin);
				return decodeURIComponent(String(parsed.pathname || '')).replace(/\\/g, '/').replace(/\/+/g, '/');
			} catch (error) {
				return String(source).split(/[?#]/)[0].replace(/\\/g, '/').replace(/\/+/g, '/');
			}
		}

		function getSuggestionActionPatterns(item) {
			const suggested = normalizeSuggestionActionPattern(item && item.suggestedExclusion ? item.suggestedExclusion : '');
			const sourcePath = getSuggestionSourcePath(item).replace(/^\/+/, '');
			let ownerSlug = '';

			const sourceOwnerMatch = sourcePath.match(/(?:^|\/)(?:plugins|themes)\/([^/]+)\/(.+)$/i);
			const suggestedOwnerMatch = suggested.match(/(?:^|\/)(?:plugins|themes)\/([^/]+)\/(.+)$/i);
			const ownerMatch = sourceOwnerMatch || suggestedOwnerMatch;
			if (ownerMatch) {
				ownerSlug = String(ownerMatch[1] || '').trim();
			}

			if (!ownerSlug && sourcePath) {
				const sourcePluginThemeMatch = sourcePath.match(/(?:^|\/)(?:plugins|themes)\/([^/]+)(?:\/|$)/i);
				if (sourcePluginThemeMatch) {
					ownerSlug = String(sourcePluginThemeMatch[1] || '').trim();
				}
			}

			if (!ownerSlug && suggested) {
				const suggestedParts = suggested.split('/').filter(Boolean);
				if (suggestedParts.length > 1 && ['wp-includes', 'wp-admin', 'wp-content'].indexOf(suggestedParts[0]) === -1) {
					ownerSlug = suggestedParts[0];
				}
			}

			return {
				exact: suggested,
				chain: ownerSlug ? normalizeSuggestionActionPattern(ownerSlug + '/') : '',
			};
		}

		function getSuggestionActionKey(item, keyPrefix, index) {
			return [
				String(keyPrefix || 'suggestion'),
				String(index || 0),
				String(item && item.suggestedExclusion ? item.suggestedExclusion : ''),
				String(item && item.definingScriptUrl ? item.definingScriptUrl : ''),
				String(item && item.symbol ? item.symbol : ''),
			].join('|');
		}

		function applySuggestionAction(actionKey, actionId, target, pattern) {
			const line = normalizeSuggestionActionPattern(pattern);
			if (!line) {
				return;
			}
			if (target === 'force') {
				appendToForceDraft(line);
			} else if (target === 'exclusion') {
				appendToExclusionDraft(line);
			} else {
				if (typeof onAppendDelayPattern !== 'function') {
					return;
				}
				onAppendDelayPattern(line);
			}
			setSelectedSuggestionActions((current) => Object.assign({}, current || {}, { [actionKey]: actionId }));
		}

		function renderSuggestionActionButtons(item, keyPrefix, index, allowAppend) {
			if (!allowAppend || !item) {
				return null;
			}
			const patterns = getSuggestionActionPatterns(item);
			const actionKey = getSuggestionActionKey(item, keyPrefix, index);
			const selected = String(selectedSuggestionActions[actionKey] || '');
			const actions = [
				{ id: 'force-exact', target: 'force', pattern: patterns.exact, label: __('Defer Instead', 'ultracache') },
				{ id: 'force-chain', target: 'force', pattern: patterns.chain, label: __('Defer Chain', 'ultracache') },
				{ id: 'exclude-exact', target: 'exclusion', pattern: patterns.exact, label: __('Add to exclusions', 'ultracache') },
				{ id: 'exclude-chain', target: 'exclusion', pattern: patterns.chain, label: __('Exclude Chain', 'ultracache') },
			];
			const visibleActions = selected ? actions.filter((action) => action.id === selected) : actions;

			return h('span', { className: 'inline-flex flex-wrap items-center' }, visibleActions.map((action) => h('button', {
				type: 'button',
				key: actionKey + '-' + action.id,
				className: 'uc-btn text-[11px] px-2 py-1',
				style: { margin: '5px' },
				disabled: !!disabled || !action.pattern || !!selected,
				title: action.pattern ? String(action.pattern) : __('Dependency chain unavailable for this finding', 'ultracache'),
				onClick: () => applySuggestionAction(actionKey, action.id, action.target, action.pattern),
			}, action.label)));
		}

		function appendToForceDraft(lines) {
			const normalizedLines = normalizeSettingListLines(Array.isArray(lines) ? lines.join('\n') : lines);
			if (!normalizedLines.length) {
				return { added: 0, removed: 0 };
			}
			const merged = mergeUniqueSettingLines(forceDraftValue, normalizedLines);
			const cleanedExclusions = removeOverlappingSettingLines(draftValue, normalizedLines);
			setForceDraft(merged.value);
			if (cleanedExclusions.value !== draftValue) {
				setDraft(cleanedExclusions.value);
			}
			setLastEditedSafeguardList('force');
			return { added: merged.added, removed: cleanedExclusions.removed };
		}

		function appendToExclusionDraft(lines) {
			const normalizedLines = normalizeSettingListLines(Array.isArray(lines) ? lines.join('\n') : lines);
			if (!normalizedLines.length) {
				return { added: 0, removed: 0 };
			}
			const merged = mergeUniqueSettingLines(draftValue, normalizedLines);
			const cleanedForce = removeOverlappingSettingLines(forceDraftValue, normalizedLines);
			setDraft(merged.value);
			if (cleanedForce.value !== forceDraftValue) {
				setForceDraft(cleanedForce.value);
			}
			setLastEditedSafeguardList('exclusion');
			return { added: merged.added, removed: cleanedForce.removed };
		}


		function renderSuggestionItem(item, keyPrefix, index) {
			const line = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
			const fallbackPresent = suggestionInFallback(item);
			const forcePresent = suggestionInForce(item);
			const reviewOnly = item && item.appendable === false;
			const statusText = reviewOnly ? ((fallbackPresent || forcePresent) ? 'already listed · not fixable' : 'not fixable') : (fallbackPresent ? 'in "Do Not Defer or Delay"' : (forcePresent ? 'in Defer Instead · can append to "Do Not Defer or Delay"' : 'missing'));
			const statusClass = reviewOnly ? 'text-sky-300' : (fallbackPresent ? 'text-emerald-400' : 'text-amber-300');
			const metaRows = [
				['Status', statusText, statusClass],
				['Confidence', item && item.confidence ? String(item.confidence) : '—', 'text-zinc-300'],
				['Category', item && item.categoryLabel ? String(item.categoryLabel) : (reviewOnly ? 'Not fixable candidates' : 'Detected recommendation'), reviewOnly ? 'text-sky-300' : 'text-violet-300'],
			];
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-3 space-y-2', key: keyPrefix + '-' + index + '-' + line }, [
				h('div', { className: 'space-y-1' }, [
					h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, __("Suggested exclusion", 'ultracache')),
					h('div', { className: 'flex flex-wrap items-center gap-2' }, [
						h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1.5' }, line || 'unknown'),
						renderSuggestionActionButtons(item, keyPrefix, index, !!line),
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


		function renderJsDiagnosticQueueItem(item, keyPrefix, index, options) {
			const line = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
			const readOnly = !!(options && options.readOnly);
			const fallbackPresent = suggestionInFallback(item);
			const forcePresent = suggestionInForce(item);
			const canAppend = !readOnly && item && item.appendable !== false && line && !fallbackPresent;
			const status = readOnly ? ((fallbackPresent || forcePresent) ? 'already listed' : 'read only') : (fallbackPresent ? 'in "Do Not Defer or Delay"' : (forcePresent ? 'in Defer Instead · can append to "Do Not Defer or Delay"' : 'ready to append'));
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-3 space-y-2', key: keyPrefix + '-' + index + '-' + line }, [
				h('div', { className: 'flex flex-wrap items-center gap-2' }, [
					h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1.5' }, line || 'unknown'),
					renderSuggestionActionButtons(item, keyPrefix, index, canAppend),
				]),
				h('div', { className: 'grid grid-cols-1 sm:grid-cols-3 gap-2' }, [
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Status'), h('div', { className: fallbackPresent ? 'text-[11px] font-semibold text-emerald-300' : (forcePresent ? 'text-[11px] font-semibold text-amber-300' : 'text-[11px] font-semibold text-zinc-300') }, status)]),
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Confidence'), h('div', { className: 'text-[11px] font-semibold text-zinc-300' }, item && item.confidence ? String(item.confidence) : '—')]),
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Type'), h('div', { className: 'text-[11px] font-semibold text-violet-300' }, item && item.categoryLabel ? String(item.categoryLabel) : (options && options.title ? String(options.title) : 'Diagnostic result'))]),
				]),
				item && item.reason ? h('div', { className: 'text-zinc-400 leading-relaxed pt-1' }, item.reason) : null,
				item && item.sample ? h('div', { className: 'text-zinc-500 leading-relaxed break-all bg-black/15 rounded px-2 py-1.5' }, [h('span', { className: 'text-zinc-400 font-semibold' }, __("Sample: ", 'ultracache')), String(item.sample)]) : null,
			]);
		}

		function renderJsDiagnosticQueueCategory(title, count, items, emptyText, keyPrefix, options) {
			const list = Array.isArray(items) ? items : [];
			if (!count && !list.length) {
				return null;
			}
			return h('div', { className: 'rounded-xl bg-black/15 px-3 py-3 space-y-2', key: keyPrefix }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2' }, [
					h('div', null, [
						h('div', { className: 'text-zinc-200 font-semibold' }, title),
						options && options.help ? h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, options.help) : null,
					]),
					h('div', { className: 'font-mono text-[12px] text-zinc-300' }, String(count || list.length || 0)),
				]),
				list.length ? h('div', { className: 'space-y-2' }, list.map((item, index) => renderJsDiagnosticQueueItem(item, keyPrefix + '-item', index, Object.assign({}, options || {}, { title: title })))) : h('div', { className: 'text-[11px] text-zinc-500' }, emptyText),
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
				return { key: 'tracking-ads', title: __("Tracking / ads", 'ultracache'), reason: 'Tracking or ads scripts were detected. These are not-fixable because delaying them often improves performance but may affect tracking timing.' };
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
			const missingCount = items.filter((item) => !suggestionPrefersExclusion(item) && !suggestionInFallback(item) && !suggestionInForce(item)).length;
			const fallbackCount = items.filter((item) => !suggestionInFallback(item) && (suggestionInForce(item) || suggestionPrefersExclusion(item))).length;
			const reviewOnly = items.some((item) => item && item.appendable === false);
			const lines = items.map((item) => String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim()).filter(Boolean);
			const summaryStatus = reviewOnly ? 'not fixable' : (fallbackCount ? (fallbackCount + ' Do Not Defer or Delay') : (missingCount ? (missingCount + ' missing') : 'covered'));
			return h('details', { className: 'rounded-lg bg-black/20 px-3 py-2', key: keyPrefix + '-group-' + index + '-' + group.key, open: !collapsed }, [
				h('summary', { className: 'cursor-pointer list-none flex flex-wrap items-center justify-between gap-2' }, [
					h('span', { className: 'text-zinc-200 font-semibold' }, group.title || 'Detected group'),
					h('span', { className: reviewOnly ? 'text-sky-300 font-mono text-[11px]' : (missingCount ? 'text-amber-300 font-mono text-[11px]' : 'text-emerald-300 font-mono text-[11px]') }, summaryStatus + ' · ' + items.length + ' line(s)'),
				]),
				group.reason ? h('div', { className: 'text-zinc-500 mt-2' }, group.reason) : null,
				lines.length ? h('div', { className: 'mt-2 flex flex-wrap gap-1' }, lines.map((line, lineIndex) => h('code', { className: 'font-mono text-[11px] text-emerald-300 bg-black/25 rounded px-2 py-1 break-all', key: keyPrefix + '-line-' + index + '-' + lineIndex }, line))) : null,
				reviewOnly ? h('div', { className: 'mt-2 text-[11px] text-zinc-500' }, __("Not-fixable items are informational and are not added to exclusions.", 'ultracache')) : null,
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
				h('div', { className: 'text-[11px] text-zinc-500 mt-2 mb-2' }, __("These are network/resource load failures. If Chrome shows ERR_BLOCKED_BY_CLIENT, this is usually a browser extension/privacy blocker and is not counted as a missing JS safeguard.", 'ultracache')),
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


		function applyJsDiagnosticQueueJob(job) {
			if (!job || typeof job !== 'object') {
				return null;
			}
			setJsDiagnosticQueue(job);
			const result = job.result && typeof job.result === 'object' ? job.result : null;
			const dashboardScan = result && result.dashboardScan && typeof result.dashboardScan === 'object' ? result.dashboardScan : null;
			if (dashboardScan) {
				if (job.scanType === 'console') {
					setConsoleErrorScan(dashboardScan);
					setConsoleErrorSuggestions(getJsDelaySafetySuggestions(dashboardScan));
				} else {
					setScan(dashboardScan);
				}
			}
			return job;
		}

		function applyJsDiagnosticQueueResponse(response) {
			const job = response && response.jsDiagnosticQueue ? response.jsDiagnosticQueue : null;
			return applyJsDiagnosticQueueJob(job);
		}

		async function refreshJsDiagnosticQueue(jobId) {
			setJsDiagnosticQueueBusy(true);
			try {
				const response = await apiRequest('runtime_js_diagnostic_queue_status', jobId ? { jobId } : {});
				const job = applyJsDiagnosticQueueResponse(response);
				if (job && job.result && job.result.dashboardScan) {
					pushToast({ type: 'success', text: 'Loaded stored JS diagnostic queue result.' });
				} else if (job) {
					pushToast({ type: 'info', text: 'Loaded JS diagnostic queue status: ' + String(job.status || 'unknown') + '.' });
				}
				return job;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Could not load JS diagnostic queue status.' });
				return null;
			} finally {
				setJsDiagnosticQueueBusy(false);
			}
		}

		async function transitionJsDiagnosticQueue(action) {
			const jobId = jsDiagnosticQueue && jsDiagnosticQueue.id ? String(jsDiagnosticQueue.id) : '';
			if (!jobId) {
				pushToast({ type: 'warning', text: 'No JS diagnostic queue job is selected.' });
				return;
			}
			setJsDiagnosticQueueBusy(true);
			try {
				const response = await apiRequest('runtime_js_diagnostic_queue_' + action, { jobId });
				const job = applyJsDiagnosticQueueResponse(response);
				pushToast({ type: 'success', text: 'JS diagnostic queue ' + action + ' completed.' });
				return job;
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'JS diagnostic queue action failed.' });
				return null;
			} finally {
				setJsDiagnosticQueueBusy(false);
			}
		}

		async function handleExtractConsoleErrors() {
			const input = String(consoleErrorInput || '');
			if (!input.trim()) {
				setConsoleErrorSuggestions([]);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('Paste one or more browser console errors first.');
				return;
			}
			setSelectedSuggestionActions({});
			setConsoleErrorBusy(true);
			setConsoleErrorStatus('Parsing console errors with the DB-backed Runtime Scan suggestion engine…');
			try {
				const response = await apiRequest('runtime_js_diagnostic_queue_start', { scanType: 'console', text: input, url: scanUrl || defaultScanUrl, scanContext: runtimeScanContext });
				const job = applyJsDiagnosticQueueResponse(response);
				const scan = job && job.result && job.result.dashboardScan ? job.result.dashboardScan : null;
				const extracted = getJsDelaySafetySuggestions(scan);
				const reviewOnly = getJsDelayReviewSuggestions(scan);
				const fallbackMissing = extracted.filter((line) => !isSuggestionPresentInDraft(draftValue, line));
				const speedMissing = fallbackMissing.filter((line) => !isSuggestionPresentInDraft(forceDraftValue, line));
				const escalationCount = fallbackMissing.length - speedMissing.length;
				setConsoleErrorSuggestions(extracted);
				setConsoleErrorScan(scan || null);
				const persistentCount = scan && typeof scan.persistentListedFailureCount !== 'undefined' ? Number(scan.persistentListedFailureCount || 0) : 0;
				const dependencyRiskCount = scan && typeof scan.dependencyRiskCount !== 'undefined' ? Number(scan.dependencyRiskCount || 0) : 0;
				if (!extracted.length && !reviewOnly.length && !persistentCount && !dependencyRiskCount) {
					setConsoleErrorStatus('No Runtime Scan suggestions were detected. UltraCache only reports exact paths/handles resolved from the error, the page dependency graph, or scanned local JS sources.');
				} else {
					setConsoleErrorStatus('Detected ' + extracted.length + ' appendable Runtime Scan suggestion(s)' + (dependencyRiskCount ? (', ' + dependencyRiskCount + ' page/file dependency risk(s)') : '') + (persistentCount ? (', and ' + persistentCount + ' already-listed script(s) where the runtime error still persists') : '') + (reviewOnly.length ? (', plus ' + reviewOnly.length + ' not-fixable candidate(s)') : '') + (escalationCount ? ('; ' + escalationCount + ' already in Defer Instead can still be appended to "Do Not Defer or Delay".') : '') + '. Review the stored result below, append the fixes you want, then save and purge cache.');
				}
			} catch (error) {
				setConsoleErrorSuggestions([]);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('Runtime Scan parser failed. Safe Defer diagnostics now require the REST/runtime scan parser; fix the parser error and run extraction again. ' + (error && error.message ? String(error.message) : ''));
			} finally {
				setConsoleErrorBusy(false);
			}
		}

		function handleAppendConsoleErrors() {
			const lines = missingConsoleErrorSuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'All extracted console-error fixes are already in Defer Instead or "Do Not Defer or Delay". Use Append to "Do Not Defer or Delay" if the error still persists.' : 'Extract console error suggestions before appending.');
				return;
			}
			const moved = appendToForceDraft(lines);
			setConsoleErrorStatus(moved.added || moved.removed ? ('Appended ' + moved.added + ' console-error fix(es) to Defer Instead of Delay' + (moved.removed ? (' and removed ' + moved.removed + ' overlap(s) from Do Not Defer or Delay') : '') + '.') : 'All extracted console-error fixes are already listed.');
		}

		function handleAppendConsoleFallbacks() {
			const lines = consoleFallbackSuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'All extracted console-error fixes are already in "Do Not Defer or Delay".' : 'Extract console error suggestions before appending to "Do Not Defer or Delay".');
				return;
			}
			const moved = appendToExclusionDraft(lines);
			setConsoleErrorStatus(moved.added || moved.removed ? ('Appended ' + moved.added + ' console-error item(s) to "Do Not Defer or Delay"' + (moved.removed ? (' and removed ' + moved.removed + ' overlap(s) from Defer Instead') : '') + '.') : 'All extracted console-error fixes are already in "Do Not Defer or Delay".');
		}

		function handleClearConsoleErrors() {
			setConsoleErrorInput('');
			setConsoleErrorSuggestions([]);
			setConsoleErrorScan(null);
			setConsoleErrorStatus('');
			setSelectedSuggestionActions({});
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
					const cleanedForce = removeOverlappingSettingLines(forceDraftValue, next);
					if (cleanedForce.value !== forceDraftValue) {
						setForceDraft(cleanedForce.value);
					}
					setLastEditedSafeguardList('exclusion');
				}
			} finally {
				setPopulateBusy(false);
			}
		}

		async function handleScan() {
			if (disabled || scanBusy || typeof onScan !== 'function') {
				return;
			}
			setSelectedSuggestionActions({});
			setScanBusy(true);
			setScanStatus('Preparing HTML JS dependency analysis…');
			setScanProgress({ phase: 'prepare', progressPercent: 1 });
			try {
				const result = await onScan(scanUrl, function(progress, message) {
					setScanProgress((previous) => Object.assign({}, previous && typeof previous === 'object' ? previous : {}, progress && typeof progress === 'object' ? progress : {}));
					setScanStatus(String(message || 'Analyzing HTML JS dependencies…'));
				});
				if (result && typeof result === 'object') {
					setScan(result);
					setScanStatus('HTML JS dependency analysis completed.');
				}
			} finally {
				setScanBusy(false);
			}
		}


		async function handleRuntimeScan() {
			if (disabled || runtimeScanBusy || typeof onRuntimeScan !== 'function') {
				return;
			}
			setSelectedSuggestionActions({});
			setRuntimeScanBusy(true);
			setRuntimeScanStatus('Creating DB-backed JS diagnostic queue job…');
			try {
				const startResponse = await apiRequest('runtime_js_diagnostic_queue_start', { scanType: 'runtime', url: scanUrl || defaultScanUrl, scanContext: runtimeScanContext });
				const queueJob = applyJsDiagnosticQueueResponse(startResponse);
				const queueJobId = queueJob && queueJob.id ? String(queueJob.id) : '';
				const result = await onRuntimeScan(scanUrl, function(statusText) {
					setRuntimeScanStatus(String(statusText || ''));
				}, { context: runtimeScanContext, queueJobId: queueJobId });
				if (result && typeof result === 'object') {
					setScan(result);
				}
				if (queueJobId) {
					await refreshJsDiagnosticQueue(queueJobId);
				}
			} finally {
				setRuntimeScanBusy(false);
			}
		}

		function handleAppendConfirmedErrorFixes() {
			if (!hasConfirmedRuntimeErrors) {
				return;
			}
			const lines = missingAppendableSuggestions
				.map((item) => String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim())
				.filter(Boolean);
			if (!lines.length) {
				return;
			}
			appendToForceDraft(lines);
		}

		function handleAppendSuggestions() {
			if (hasConfirmedRuntimeErrors) {
				return;
			}
			const lines = missingAppendableSuggestions
				.map((item) => String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim())
				.filter(Boolean);
			if (!lines.length) {
				return;
			}
			appendToForceDraft(lines);
		}

		function handleAppendFallbackSuggestions() {
			const lines = fallbackAppendableSuggestions
				.map((item) => String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim())
				.filter(Boolean);
			if (!lines.length) {
				return;
			}
			appendToExclusionDraft(lines);
		}

		function handleSaveBoth() {
			let nextForceValue = forceDraftValue;
			let nextExclusionValue = draftValue;
			const lastList = String(lastEditedSafeguardList || '');
			if ('force' === lastList || (forceHasChanges && !hasChanges)) {
				nextExclusionValue = removeOverlappingSettingLines(nextExclusionValue, nextForceValue).value;
			} else if ('exclusion' === lastList || (hasChanges && !forceHasChanges)) {
				nextForceValue = removeOverlappingSettingLines(nextForceValue, nextExclusionValue).value;
			} else {
				nextForceValue = removeOverlappingSettingLines(nextForceValue, nextExclusionValue).value;
			}
			if (nextForceValue !== forceDraftValue) {
				setForceDraft(nextForceValue);
			}
			if (nextExclusionValue !== draftValue) {
				setDraft(nextExclusionValue);
			}
			if (typeof onSaveBoth === 'function') {
				if (nextForceValue !== currentForceValue || nextExclusionValue !== currentValue) {
					onSaveBoth(nextExclusionValue, nextForceValue);
				}
				return;
			}
			if (typeof onForceDeferSave === 'function' && nextForceValue !== currentForceValue) {
				onForceDeferSave(nextForceValue);
			}
			if (typeof onSave === 'function' && nextExclusionValue !== currentValue) {
				onSave(nextExclusionValue);
			}
		}

		const runtimeStatusText = String(runtimeScanStatus || '');
		const runtimeStatusMatch = runtimeStatusText.match(/(\d+)\s*\/\s*(\d+)/);
		const runtimeStatusCurrent = runtimeStatusMatch ? Number(runtimeStatusMatch[1] || 0) : (runtimeScanBusy ? 1 : 0);
		const runtimeStatusTotal = runtimeStatusMatch ? Math.max(1, Number(runtimeStatusMatch[2] || 1)) : (runtimeScanBusy ? 100 : 100);
		const runtimeStatusPercent = runtimeScanBusy ? Math.max(5, Math.min(100, Math.round((runtimeStatusCurrent / runtimeStatusTotal) * 100))) : 0;
		const queueStatusText = jsDiagnosticQueue ? String(jsDiagnosticQueue.status || 'unknown') : 'idle';
		const queueStatusClass = queueStatusText === 'done'
			? 'text-emerald-300 font-mono text-[11px]'
			: (queueStatusText === 'running' ? 'text-sky-300 font-mono text-[11px]' : (queueStatusText === 'paused' ? 'text-amber-300 font-mono text-[11px]' : 'text-zinc-400 font-mono text-[11px]'));

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' } }, [
			h('div', {
				key: 'js-strategy-safeguard-pair',
				style: {
					display: 'grid',
					gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)',
					gap: '16px',
					alignItems: 'stretch',
				},
			}, [
				h('div', { className: 'uc-field-wrap', key: 'force-defer-box', style: { minWidth: 0 } }, [
					h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Defer Instead of Delay', 'ultracache'), __("What it does: moves matching scripts out of Delay and lets the browser run them with normal defer timing.\n\nWhy it helps: this is usually faster than a full exclusion because the script can still wait until the HTML is parsed.\n\nWatch for: use this when Delay made a needed library, jQuery plugin, theme helper, or WordPress global arrive too late, but normal defer still works.", 'ultracache'))),
					h('div', { className: 'text-xs text-zinc-500 mb-2' }, __('Speed-first compatibility list. Matching frontend scripts are never delayed by UltraCache; they are forced to native defer so browser order can remain optimized. Scanner and Console Handler fixes are appended here first.', 'ultracache')),
					h('textarea', {
						className: 'uc-field-input uc-field-textarea',
						value: forceDraft,
						disabled: !!disabled,
						placeholder: forceDeferPlaceholder || '',
						onChange: (e) => {
							setForceDraft(e.target.value);
							setLastEditedSafeguardList('force');
						},
					}),
				]),
				h('div', { className: 'uc-field-wrap', key: 'exclude-box', style: { minWidth: 0 } }, [
					h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Do Not Defer or Delay', 'ultracache'), __("What it does: keeps matching scripts exactly where WordPress, the theme, or another plugin printed them.\n\nWhy it helps: this is the strongest compatibility fix when both Delay and Defer are still too late.\n\nWatch for: it gives up more speed than Defer Instead. When a script is added here, UltraCache removes overlapping entries from the Defer Instead list so the two boxes do not fight.", 'ultracache'))),
					h('div', { className: 'text-xs text-zinc-500 mb-2' }, __('Compatibility exclusion list. Matching scripts stay in the normal browser execution flow and are respected by Defer JS, Delay all JS, third-party delay, non-critical/local delay, LCP Boundary Delay, and Main Thread Relief where applicable.', 'ultracache')),
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => {
					setDraft(e.target.value);
					setLastEditedSafeguardList('exclusion');
				},
			}),
				]),
			]),
			h('div', { className: 'flex flex-wrap items-center', style: { marginTop: '10px', gap: '12px' } }, [
				h(Button, { key: 'defaults', onClick: handlePopulateDefaults, disabled: !!disabled || populateBusy }, populateBusy ? 'Appending…' : 'Append Broad WP Dependency Preset'),
				h(Button, { key: 'scan', onClick: handleScan, disabled: !!disabled || scanBusy }, scanBusy ? 'Analyzing…' : 'Analyze HTML JS Dependencies'),
				h(Button, { key: 'append-suggestions', onClick: handleAppendSuggestions, disabled: !!disabled || !suggestionMissingCount }, 'Append to Defer Instead' + (suggestionMissingCount ? ' (' + suggestionMissingCount + ')' : '')),
				h(Button, { key: 'append-fallbacks', onClick: handleAppendFallbackSuggestions, disabled: !!disabled || !fallbackMissingCount }, 'Append to "Do Not Defer or Delay"' + (fallbackMissingCount ? ' (' + fallbackMissingCount + ')' : '')),
				h(Button, { key: 'save', onClick: handleSaveBoth, disabled: !!disabled || (!hasChanges && !forceHasChanges && !safeguardListsOverlap), variant: 'primary' }, __('Save Both Lists', 'ultracache')),
			]),
			scanStatus ? h('div', { className: 'rounded-lg bg-sky-500/10 px-3 py-2', style: { marginTop: '10px' } }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
					h('span', { className: 'text-sky-200 font-semibold text-[12px]' }, scanStatus),
					h('span', { className: 'text-sky-300 font-mono text-[11px]' }, String(Math.round(htmlDependencyProgressPercent)) + '%'),
				]),
				h('div', { className: 'w-full h-2 rounded bg-black/30 overflow-hidden' }, [
					h('div', { className: 'h-2 rounded bg-sky-500/80', style: { width: String(htmlDependencyProgressPercent) + '%' } }),
				]),
				(htmlDependencyTotalScripts || htmlDependencyTotalFiles) ? h('div', { className: 'text-[11px] text-zinc-400 mt-2 font-mono' }, [
					'Page inventory: ' + String(htmlDependencyTotalScripts) + ' scripts',
					htmlDependencyTotalFiles ? ' · Local JS: ' + String(htmlDependencyTotalFiles) : '',
					htmlDependencyTotalFiles ? ' · Processed: ' + String(Math.min(htmlDependencyProcessedFiles, htmlDependencyTotalFiles)) + '/' + String(htmlDependencyTotalFiles) : '',
					' · Cached: ' + String(htmlDependencyCacheHits),
					' · Parsed now: ' + String(htmlDependencyFreshFiles),
				]) : null,
			]) : null,
			h('div', { className: 'mt-5 mb-4', style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: '16px', alignItems: 'start' } }, [
				h('div', { key: 'browser-scanner-panel', className: 'uc-field-wrap', style: { minWidth: 0 } }, [
					h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
						h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Browser Scanner', 'ultracache'), __("What it does: checks a real frontend page. HTML analysis reads the final markup, while Runtime Scan opens the page like a browser and watches console/runtime errors.\n\nWhy it helps: UltraCache can see which scripts were actually printed on that page instead of guessing from a generic list.\n\nWatch for: it never changes settings by itself. It only prepares suggestions that you can append to Defer Instead of Delay or Do Not Defer or Delay.", 'ultracache'))),
						runtimeScanBusy ? h('span', { className: 'text-sky-300 font-mono text-[11px]' }, 'running') : null,
					]),
					h('div', { className: 'text-xs text-zinc-500 mb-3 leading-relaxed' }, 'Scan a same-site page. Analyze reads the final HTML and shows dependency/order candidates. Runtime Scan opens the frontend in a browser and captures real console/runtime errors. Scan buttons never change either list automatically. After applying suggestions, purge cache and repeat the scan; some dependency errors only appear after earlier missing dependencies are fixed.'),
					h('label', { className: 'uc-field-label', style: { fontSize: '12px', color: '#6f7b8f' } }, renderLabelWithHelp(__('Page URL to scan', 'ultracache'), __("What it does: tells the scanner which exact frontend page to inspect.\n\nWhy it helps: homepage, product pages, categories, cart, checkout, and account pages often load different scripts.\n\nWatch for: paste the page where the error actually happens, or the scanner may suggest the wrong file.", 'ultracache'))),
					h('input', {
						type: 'url',
						className: 'uc-field-input',
						value: scanUrl,
						disabled: !!disabled || scanBusy || runtimeScanBusy,
						placeholder: defaultScanUrl || 'https://example.com/page/',
						onChange: (e) => setScanUrl(e.target.value),
					}),
					h('div', { className: 'flex flex-wrap items-center text-[11px] text-zinc-500', style: { marginTop: '10px' } }, [
						h('span', { className: 'text-zinc-400', style: { marginRight: '10px' } }, __('Runtime Scan context', 'ultracache')),
						h('select', {
							className: 'uc-field-input uc-field-select',
							style: { maxWidth: '260px', marginRight: '8px', paddingLeft: '8px', paddingRight: '34px', paddingTop: '7px', paddingBottom: '7px' },
							value: runtimeScanContext,
							disabled: !!disabled || runtimeScanBusy,
							onChange: (e) => setRuntimeScanContext(e && e.target ? String(e.target.value || 'anonymous') : 'anonymous'),
						}, [
							h('option', { value: 'anonymous' }, __('Anonymous frontend', 'ultracache')),
							h('option', { value: 'logged-in' }, __('Logged-in/admin frontend', 'ultracache')),
						]),
					]),
					h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, runtimeScanContext === 'anonymous' ? 'Recommended for public cache debugging. Admin cookies are ignored while rendering the scan page.' : 'Useful only for admin-bar/editor/frontend issues.'),
					h('div', { className: 'flex flex-wrap', style: { marginTop: '10px', gap: '12px' } }, [
						h(Button, { key: 'runtime-scan', onClick: handleRuntimeScan, disabled: !!disabled || runtimeScanBusy }, runtimeScanBusy ? 'Runtime scanning…' : 'Scan Browser Runtime Errors'),
						h(Button, { key: 'append-confirmed-errors', onClick: handleAppendConfirmedErrorFixes, disabled: !!disabled || !confirmedErrorMissingCount }, 'Append Errors to Defer Instead' + (confirmedErrorMissingCount ? ' (' + confirmedErrorMissingCount + ')' : '')),
						h(Button, { key: 'browser-save', onClick: handleSaveBoth, disabled: !!disabled || (!hasChanges && !forceHasChanges && !safeguardListsOverlap), variant: 'primary' }, __('Save Both Lists', 'ultracache')),
					]),
					runtimeScanStatus ? h('div', { className: 'rounded-lg bg-emerald-500/10 px-3 py-2', style: { marginTop: '10px' } }, [
						h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
							h('span', { className: 'text-emerald-200 font-semibold text-[12px]' }, runtimeScanStatus),
							h('span', { className: 'text-emerald-300 font-mono text-[11px]' }, runtimeScanBusy ? String(runtimeStatusPercent) + '%' : ''),
						]),
						h('div', { className: 'w-full h-2 rounded bg-black/30 overflow-hidden' }, [
							h('div', { className: 'h-2 rounded bg-emerald-500/80', style: { width: String(runtimeScanBusy ? runtimeStatusPercent : 100) + '%' } }),
						]),
					]) : null,
				]),
				h('div', { key: 'console-handler-panel', className: 'uc-field-wrap', style: { minWidth: 0 } }, [
					h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
						h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Console Error Handler', 'ultracache'), __("What it does: reads pasted browser console errors and looks for missing globals, missing jQuery plugin methods, stack-trace URLs, and dependency clues.\n\nWhy it helps: it can propose the script that should move earlier instead of blindly excluding the script that shouted first.\n\nWatch for: it only proposes visible fixes. It does not create hidden exceptions.", 'ultracache'))),
						(consoleErrorSuggestions.length || consolePersistentFailures.length) ? h('span', { className: (missingConsoleErrorSuggestions.length || consoleFallbackSuggestions.length || consolePersistentFailures.length) ? 'text-amber-300 font-mono text-[11px]' : 'text-emerald-300 font-mono text-[11px]' }, String(missingConsoleErrorSuggestions.length) + ' Defer Instead / ' + String(consoleFallbackSuggestions.length) + ' Do Not Defer or Delay / ' + String(consolePersistentFailures.length) + ' persistent / ' + String(consoleDependencyRisks.length) + ' dependency') : null,
					]),
					h('div', { className: 'text-xs text-zinc-500 mb-3 leading-relaxed' }, "Paste browser console errors here. UltraCache also scans the selected page's actual WordPress script dependency graph and readable local JS files for provider/consumer and lifecycle-order risks. An already-listed script is kept visible when the same runtime error persists; it is not treated as solved just because the exclusion exists. Nothing changes until you append a proposed fix."),
					h('label', { className: 'uc-field-label', style: { fontSize: '12px', color: '#6f7b8f' } }, renderLabelWithHelp(__('Console errors to analyze', 'ultracache'), __("What it does: gives the handler the raw error text to study.\n\nWhy it helps: error lines, stack traces, and script URLs help UltraCache tell the difference between the script that failed and the missing script that caused the failure.\n\nWatch for: after applying one fix, test again. One missing dependency can hide the next error.", 'ultracache'))),
					h('textarea', {
						className: 'uc-field-input uc-field-textarea',
						style: { minHeight: '142px' },
						value: consoleErrorInput,
						disabled: !!disabled,
						placeholder: `Paste console errors, e.g. "complianz is not defined" or stack lines containing ${joinPublicPath(pluginsPublicPath, 'example/js/file.min.js')}`,
						onChange: (e) => setConsoleErrorInput(e.target.value),
					}),
					h('div', { className: 'flex flex-wrap', style: { marginTop: '10px', gap: '12px' } }, [
						h(Button, { key: 'extract-console-errors', onClick: handleExtractConsoleErrors, disabled: !!disabled || consoleErrorBusy }, consoleErrorBusy ? 'Extracting…' : 'Extract Console Error Suggestions'),
						h(Button, { key: 'append-console-errors', onClick: handleAppendConsoleErrors, disabled: !!disabled || !missingConsoleErrorSuggestions.length }, 'Append to Defer Instead' + (missingConsoleErrorSuggestions.length ? ' (' + missingConsoleErrorSuggestions.length + ')' : '')),
						h(Button, { key: 'append-console-fallbacks', onClick: handleAppendConsoleFallbacks, disabled: !!disabled || !consoleFallbackSuggestions.length }, 'Append to "Do Not Defer or Delay"' + (consoleFallbackSuggestions.length ? ' (' + consoleFallbackSuggestions.length + ')' : '')),
						h(Button, { key: 'clear-console-errors', onClick: handleClearConsoleErrors, disabled: !!disabled || (!consoleErrorInput && !consoleErrorSuggestions.length) }, 'Clear Console Input'),
						h(Button, { key: 'console-save', onClick: handleSaveBoth, disabled: !!disabled || (!hasChanges && !forceHasChanges && !safeguardListsOverlap), variant: 'primary' }, __('Save Both Lists', 'ultracache')),
					]),
					consoleErrorStatus ? h('div', { className: 'mt-2 text-[11px] text-sky-300' }, consoleErrorStatus) : null,
				])
			]),
			jsDiagnosticQueue ? h('div', { className: 'mt-3 mb-3 rounded-xl bg-black/20 px-3 py-3' }, [
				h('div', { className: 'flex flex-wrap items-start justify-between gap-3 mb-2' }, [
					h('div', null, [
						h('div', { className: 'text-zinc-200 font-semibold' }, 'JS Diagnostic Queue Status'),
						h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, 'DB-backed JS Diagnostic Queue · latest stored diagnostic job'),
						h('div', { className: 'text-[11px] text-zinc-500 font-mono break-all mt-1' }, String(jsDiagnosticQueue.id || '') + ' · ' + String(jsDiagnosticQueue.scanType || 'runtime') + ' · ' + String(jsDiagnosticQueue.status || 'unknown')),
					]),
					h('div', { className: 'text-right' }, [
						h('div', { className: 'font-mono text-emerald-300' }, String(jsDiagnosticQueueProgressPercent) + '%'),
						h('div', { className: queueStatusClass }, queueStatusText),
						h('div', { className: 'text-[11px] text-zinc-500' }, String(jsDiagnosticQueue.message || '')),
					]),
				]),
				h('div', { className: 'w-full h-2 rounded bg-black/30 overflow-hidden mb-3' }, [
					h('div', { className: 'h-2 rounded bg-emerald-500/80', style: { width: String(jsDiagnosticQueueProgressPercent) + '%' } }),
				]),
				h('div', { className: 'grid grid-cols-2 md:grid-cols-6 gap-2 mb-3' }, [
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Appendable Fixes'), h('div', { className: 'font-mono text-amber-300' }, String(jsDiagnosticQueueBucketCounts.confirmedErrorFixes || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Additional Matches'), h('div', { className: 'font-mono text-zinc-200' }, String(jsDiagnosticQueueBucketCounts.suggestions || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Persistent Errors'), h('div', { className: 'font-mono text-amber-300' }, String(jsDiagnosticQueueBucketCounts.persistentFailures || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Not Fixable'), h('div', { className: 'font-mono text-sky-300' }, String(jsDiagnosticQueueBucketCounts.reviewOnly || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Already Listed'), h('div', { className: 'font-mono text-emerald-300' }, String(jsDiagnosticQueueBucketCounts.alreadyListed || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Ignored'), h('div', { className: 'font-mono text-zinc-400' }, String(jsDiagnosticQueueBucketCounts.ignored || 0))]),
				]),
				h('div', { className: 'space-y-2 mb-3' }, [
					renderJsDiagnosticQueueCategory('Appendable Fixes', jsDiagnosticQueueBucketCounts.confirmedErrorFixes || 0, jsDiagnosticQueueBuckets.confirmedErrorFixes || [], 'No confirmed fixes in this stored result.', 'jsdq-confirmed', { help: 'Ready-to-append fixes detected from confirmed runtime/console errors.' }),
					renderJsDiagnosticQueueCategory('Additional Matches', jsDiagnosticQueueBucketCounts.suggestions || 0, jsDiagnosticQueueBuckets.suggestions || [], 'No dependency/file-scan suggestions in this stored result.', 'jsdq-suggestions', { help: 'Page-scoped dependency risks found from WordPress registered dependencies and readable local JS lifecycle relationships.' }),
					renderJsDiagnosticQueueCategory('Persistent Errors After Exclusion', jsDiagnosticQueueBucketCounts.persistentFailures || 0, jsDiagnosticQueueBuckets.persistentFailures || [], 'No already-listed script still reports the same runtime error.', 'jsdq-persistent', { readOnly: true, help: 'The script is already covered by Do Not Defer or Delay, but the same error still originates from it. Check whether the exclusion is effective on final HTML, then inspect the dependency suggestions instead of hiding this result.' }),
					renderJsDiagnosticQueueCategory('Not Fixable', jsDiagnosticQueueBucketCounts.reviewOnly || 0, jsDiagnosticQueueBuckets.reviewOnly || [], 'No not-fixable items in this stored result.', 'jsdq-not-fixable', { readOnly: true, help: 'Information only. These findings are not fixable by a JS exclusion.' }),
					renderJsDiagnosticQueueCategory('Already Listed', jsDiagnosticQueueBucketCounts.alreadyListed || 0, jsDiagnosticQueueBuckets.alreadyListed || [], 'No already listed items in this stored result.', 'jsdq-already-listed', { readOnly: true, help: 'These items are already covered by Defer Instead of Delay or Do Not Defer or Delay.' }),
					renderJsDiagnosticQueueCategory('Ignored', jsDiagnosticQueueBucketCounts.ignored || 0, jsDiagnosticQueueBuckets.ignored || [], 'No ignored items in this stored result.', 'jsdq-ignored', { readOnly: true, help: 'Ignored findings do not require action.' }),
				]),
				h('div', { className: 'flex flex-wrap gap-2' }, [
					h(Button, { onClick: () => refreshJsDiagnosticQueue(jsDiagnosticQueue && jsDiagnosticQueue.id), disabled: !!disabled || jsDiagnosticQueueBusy }, jsDiagnosticQueueBusy ? 'Refreshing…' : 'Refresh Stored Results'),
					h(Button, { onClick: () => transitionJsDiagnosticQueue('pause'), disabled: !!disabled || jsDiagnosticQueueBusy || !jsDiagnosticQueue || jsDiagnosticQueue.status !== 'running' }, 'Pause'),
					h(Button, { onClick: () => transitionJsDiagnosticQueue('resume'), disabled: !!disabled || jsDiagnosticQueueBusy || !jsDiagnosticQueue || jsDiagnosticQueue.status !== 'paused' }, 'Resume'),
					h(Button, { onClick: () => transitionJsDiagnosticQueue('cancel'), disabled: !!disabled || jsDiagnosticQueueBusy || !jsDiagnosticQueue || ['done', 'failed', 'cancelled'].indexOf(String(jsDiagnosticQueue.status || '')) !== -1 }, 'Cancel'),
				]),
			]) : h('div', { className: 'mt-3 mb-3 rounded-xl bg-black/15 px-3 py-3 text-[11px] text-zinc-500' }, [
				h('div', { className: 'text-zinc-300 font-semibold mb-1' }, 'JS Diagnostic Queue Status'),
				h('div', null, 'Runtime and console diagnostics create DB-backed queue jobs. Stored results appear here after Extract Console Error Suggestions or Scan Browser Runtime Errors.'),
				h(Button, { onClick: () => refreshJsDiagnosticQueue(''), disabled: !!disabled || jsDiagnosticQueueBusy, className: 'mt-2' }, jsDiagnosticQueueBusy ? 'Loading…' : 'Load Latest Stored Results'),
			]),
			scan ? h('div', { className: 'mt-3 mb-2 text-xs bg-black/20 rounded-xl px-3 py-3', style: { padding: '5px' } }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-3 mb-2' }, [
					h('span', { className: 'text-zinc-300 font-bold' }, isStrongHtmlScan ? __('Strong JS Dependency Suggestions', 'ultracache') : __('JS Safeguard Safety Scan', 'ultracache')),
					h('span', { className: 'text-zinc-500 font-mono break-all' }, [(scan.scanContext || runtimeScanContext) ? ('Context: ' + (String(scan.scanContext || runtimeScanContext) === 'logged-in' ? 'Logged-in/admin frontend' : 'Anonymous frontend') + ' · ') : '', (scan.scannedUrl || scan.profileUrl || scan.url) ? String(scan.scannedUrl || scan.profileUrl || scan.url) : '']),
				]),
				h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-2 mb-3' }, [
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Detected', 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(totalDetected || suggestions.length || 0))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Recommended', 'ultracache')), h('div', { className: 'font-mono text-zinc-200' }, String(appendableSuggestions.length))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Missing', 'ultracache')), h('div', { className: liveMissingCount ? 'font-mono text-amber-300' : 'font-mono text-emerald-300' }, String(liveMissingCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Do Not Defer or Delay', 'ultracache')), h('div', { className: fallbackMissingCount ? 'font-mono text-amber-300' : 'font-mono text-emerald-300' }, String(fallbackMissingCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Already listed', 'ultracache')), h('div', { className: 'font-mono text-emerald-300' }, String(liveAlreadyListedCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Persistent after exclusion', 'ultracache')), h('div', { className: persistentListedFailureCount ? 'font-mono text-amber-300' : 'font-mono text-zinc-300' }, String(persistentListedFailureCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Not fixable', 'ultracache')), h('div', { className: missingReviewOnlySuggestions.length ? 'font-mono text-sky-300' : 'font-mono text-zinc-300' }, String(reviewOnlyCount))]),
					h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __('Blocked resources', 'ultracache')), h('div', { className: resourceErrorCount ? 'font-mono text-sky-300' : 'font-mono text-zinc-300' }, String(resourceErrorCount || 0))]),
				]),
				renderResourceErrorsSection(resourceErrors),
				renderRuntimeErrorsSection(runtimeErrors),
				renderSuggestionSection(isStrongHtmlScan ? 'Strong suggestions' : 'Missing recommended', liveMissingCount, missingAppendableSuggestions, isStrongHtmlScan ? 'No missing high-confidence silent dependency conflicts were found.' : 'No missing speed-first fixes. Defer Instead already covers these appendable scan results or they are in Do Not Defer or Delay.', 'missing-recommended', isStrongHtmlScan ? 'These are high-confidence execution-order conflicts found from the actual page script inventory and readable local JavaScript. Append only the fixes you want, then purge and rescan.' : 'Append to Defer Instead adds these lines to Defer Instead of Delay.'),
				renderSuggestionSection('Do Not Defer or Delay candidates', fallbackEscalationCount, fallbackEscalationSuggestions, 'No scan findings need "Do Not Defer or Delay" for this scan.', 'fallback-candidates', 'These lines either already failed after Defer Instead or the scanner found an exclusion-first pattern such as a same-URL reload loop. Append to "Do Not Defer or Delay" moves them to the exclude list for the next test.', { grouped: true, collapsed: false }),
				renderSuggestionSection('Persistent errors after exclusion', persistentListedFailureCount, persistentListedFailureSuggestions, 'No already-listed script still reports the same runtime error.', 'persistent-listed-failures', 'These scripts are already in Do Not Defer or Delay but the same runtime error still originates from them. If the scanned strategy is still Delay/Defer, purge and rescan. If it is blocking, inspect the dependency/file-scan recommendations instead of treating the script as solved.', { grouped: true, collapsed: false }),
				renderSuggestionSection('Already listed recommended', liveAlreadyListedCount, alreadyListedAppendableSuggestions, 'No recommended fixes are already listed yet.', 'already-listed-recommended', 'Grouped and collapsed by default. These scan matches are already covered by your paired safeguard lists, including broad fragments that cover variant paths.', { grouped: true, collapsed: true }),
				renderSuggestionSection('Not fixable detected', reviewOnlyCount, reviewOnlySuggestions, 'No not-fixable candidates were detected.', 'not-fixable-detected', 'Grouped and collapsed by default. Items listed here are informational and are not fixable by adding a JS safeguard.', { grouped: true, collapsed: true }),
			]) : h('div', { className: 'mt-2 mb-2 text-[11px] text-zinc-500', style: { padding: '5px' } }, __('Enter a same-site URL. Analyze HTML JS Dependencies looks for high-confidence silent execution-order conflicts, including lifecycle events that can be missed without a console error. Scan Browser Runtime Errors opens the page in your browser and captures console/runtime errors. Scan buttons do not change either list automatically.', 'ultracache')),
		]);

	}

function CssBundleExclusionsDiagnosticsField({ value, onSave, disabled, placeholder, onPopulateDefaults, onRunDiagnostics, onDownloadJson, onClearResult, profile, onCopyCssExclusion }) {
		const defaultScanUrl = (typeof ultracache !== "undefined" && ultracache && ultracache.frontendProbeUrl) ? String(ultracache.frontendProbeUrl || "") : "";
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
					h('div', { className: 'mt-2 text-[11px] text-zinc-500 leading-relaxed' }, __("When UltraCache rewrites a stylesheet into a css-font-mix file, this list shows the original source. Bundle exclusions are appended against the original source while UltraCache resolves the generated replacement internally.", 'ultracache')),
					h('div', { className: 'mt-3 space-y-2' }, sourceTop.slice(0, 8).map((item, index) => {
						const suggestion = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
						const generatedUrl = item && item.generatedUrl ? String(item.generatedUrl) : '';
						return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'cssdiag-source-' + index }, [
							h('div', { className: 'flex items-center justify-between gap-4' }, [
								h('span', { className: 'break-all text-zinc-300' }, item.url || 'unknown stylesheet'),
								h('span', { className: (item.largeSourceWarning ? 'font-mono text-amber-300 shrink-0' : 'font-mono text-zinc-300 shrink-0') }, formatBytes(item.bytes || 0)),
							]),
							generatedUrl ? h('div', { className: 'mt-1 text-[10px] text-zinc-500 break-all' }, [
								h('span', { className: 'text-zinc-400' }, __("UltraCache replacement: ", 'ultracache')),
								h('code', { className: 'font-mono' }, generatedUrl),
								(item.generatedBytes ? h('span', null, ' · ' + formatBytes(item.generatedBytes || 0)) : null),
							]) : null,
							suggestion ? h('div', { className: 'mt-2 flex flex-wrap items-center gap-2' }, [
								h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1' }, suggestion),
								h('button', { type: 'button', className: 'uc-btn text-[11px] px-2 py-1', disabled: !!disabled || isCssExclusionCovered(suggestion), onClick: () => appendCssExclusionLine(suggestion) }, isCssExclusionCovered(suggestion) ? 'Already in exclusions' : 'Append exclusion line'),
							]) : null,
						]);
					})),
				]) : null,
				asyncCssDiagnostics.available ? h('details', { className: 'rounded-xl bg-black/15 px-3 py-2' }, [
					h('summary', { className: 'cursor-pointer text-zinc-300 font-semibold' }, __("Async Remaining CSS decisions", 'ultracache')),
					h('div', { className: 'mt-3 text-[11px] text-zinc-400 leading-relaxed' }, __("CSS Bundle Exclusions do not disable Async CSS. UltraCache-generated CSS is now classified before async: main/page/frontpage bundles and preserved optimized-css stay blocking because they can affect layout, while leftover and delayed-font CSS can load async when classified as non-critical. Aggressive Async CSS uses the visible Async CSS Exclude List.", 'ultracache')),
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
			h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__("CSS Bundle Exclusions", 'ultracache'), __("What it does: keeps matching stylesheets out of UltraCache CSS bundles.\n\nWhy it helps: a stylesheet that depends on exact order, timing, or its original URL can stay untouched while the rest of the CSS is optimized.\n\nWatch for: this box protects bundling only. If the same stylesheet must also stay render-blocking, add it to Async CSS Exclude List too.", 'ultracache'))),
			h('div', { className: 'text-xs text-zinc-500 mb-2' }, __("Optional newline-separated URL fragments. Matching stylesheets stay outside generated CSS bundles and load normally as their original stylesheet links. Use exclusions only when a stylesheet breaks inside the bundle or tested slower when bundled.", 'ultracache')),
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: draft,
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => setDraft(e.target.value),
			}),
			h('div', { className: 'mt-3 mb-2' }, [
				h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__("Page URL to diagnose", 'ultracache'), __("What it does: tells CSS diagnostics which frontend page to inspect.\n\nWhy it helps: UltraCache reads that page's generated or cached HTML, so bundle, async, font-mix, and render-blocking findings match the page you are testing.\n\nWatch for: use the page that actually has the visual issue or Lighthouse warning.", 'ultracache'))),
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
				h(Button, { key: 'run-css', onClick: handleRunDiagnostics, disabled: !!disabled || scanBusy }, scanBusy ? 'Running…' : 'Run CSS Diagnostics'),
				h(Button, { key: 'clear-css', onClick: onClearResult, disabled: !!disabled || !current }, __("Clear CSS Result", 'ultracache')),
				h(Button, { key: 'save-css', onClick: () => onSave(draftValue), disabled: !!disabled || !hasChanges, variant: 'primary' }, __("Save Exclusions", 'ultracache')),
			]),
			renderDiagnosticsResult(),
		]);
	}

function getNextMediaEtaCheckpoint(sampleCount) {
		const count = Math.max(0, Number(sampleCount || 0));
		if (count < 10) {
			return 10;
		}
		if (count < 100) {
			return 100;
		}
		return (Math.floor(count / 500) + 1) * 500;
	}

function formatEtaDuration(totalSeconds) {
		const seconds = Math.max(0, Math.round(Number(totalSeconds || 0)));
		if (seconds < 60) {
			return seconds + 's';
		}

		const minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return minutes + 'm ' + (seconds % 60) + 's';
		}

		const hours = Math.floor(minutes / 60);
		const remainingMinutes = minutes % 60;
		if (hours < 24) {
			return hours + 'h ' + remainingMinutes + 'm';
		}

		const days = Math.floor(hours / 24);
		return days + 'd ' + (hours % 24) + 'h';
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

function sanitizeRuntimeJsScanDisplayUrl(url) {
			let value = String(url || '').trim();
			if (!value) {
				return '';
			}
			try {
				const parsed = new URL(value, window.location.origin);
				['ultracache_runtime_js_scan', 'ultracache_runtime_js_scan_id', 'ultracache_runtime_js_scan_nonce', 'ultracache_runtime_js_scan_context', 'ultracache_rt', 'ultracache_rv', 'ultracache_bucket', 'ultracache_profile_bypass', 'ultracache_store_profile', 'ultracache_callback_profile', 'ultracache_store_profile_verbose', 'ultracache_store_profile_verbose_settings', 'ultracache_profile_run', 'ultracache_revalidate'].forEach((key) => parsed.searchParams.delete(key));
				return parsed.toString();
			} catch (error) {
				return value.replace(/([?&])ultracache_(runtime_js_scan(?:_id|_nonce|_context)?|rt|rv|bucket|profile_bypass|store_profile(?:_verbose(?:_settings)?)?|callback_profile|profile_run|revalidate)=[^&#]*/g, '$1').replace(/[?&]$/, '');
			}
		}

function buildRuntimeJsScanUrl(url, scanId, context) {
			let target = String(url || '').trim() || ((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/');
			let parsed;
			try {
				parsed = new URL(target, window.location.origin);
			} catch (error) {
				parsed = new URL((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/', window.location.origin);
			}
			const scanContext = context === 'logged-in' ? 'logged-in' : 'anonymous';
			parsed.searchParams.set('ultracache_runtime_js_scan', '1');
			parsed.searchParams.set('ultracache_runtime_js_scan_id', scanId);
			parsed.searchParams.set('ultracache_runtime_js_scan_nonce', ultracache.runtimeJsScanNonce || '');
			parsed.searchParams.set('ultracache_runtime_js_scan_context', scanContext);
			parsed.searchParams.set('ultracache_rt', String(Date.now()));
			return parsed.toString();
		}

function runtimeJsScanUrlHasScanId(url, scanId) {
			try {
				const parsed = new URL(String(url || ''), window.location.origin);
				return parsed.searchParams.get('ultracache_runtime_js_scan') === '1' && parsed.searchParams.get('ultracache_runtime_js_scan_id') === String(scanId || '');
			} catch (error) {
				return false;
			}
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

function collectPopupRuntimeJsScanScripts(popup) {
			try {
				if (!popup || popup.closed || !popup.document) {
					return [];
				}
				const scripts = Array.prototype.slice.call(popup.document.getElementsByTagName('script') || []);
				return scripts.slice(0, 240).map((script) => {
					const getAttr = (name) => {
						try {
							return script && script.getAttribute ? String(script.getAttribute(name) || '') : '';
						} catch (error) {
							return '';
						}
					};
					const src = String(script.src || getAttr('src') || getAttr('data-ultracache-src') || getAttr('data-ultracache-original-src') || '');
					const id = String(script.id || getAttr('id') || getAttr('data-ultracache-id') || getAttr('data-ultracache-handle') || '');
					const handle = String(getAttr('data-ultracache-handle') || '');
					const type = String(script.type || getAttr('type') || '');
					const delayed = type === 'text/ultracache-delayed-js' || !!(script.hasAttribute && (script.hasAttribute('data-ultracache-src') || script.hasAttribute('data-ultracache-inline') || script.hasAttribute('data-ultracache-delayed')));
					const text = (!src || delayed) && script.textContent ? String(script.textContent).slice(0, 60000) : '';
					return {
						id: id.slice(0, 160),
						handle: handle.slice(0, 160),
						src: src.slice(0, 1200),
						type: type.slice(0, 120),
						defer: !!script.defer,
						async: !!script.async,
						strategy: getAttr('data-wp-strategy').slice(0, 80),
						delayed,
						text,
					};
				});
			} catch (error) {
				return [];
			}
		}

function readPopupRuntimeJsScanSnapshot(popup, scanId, scanUrl, queueJobId) {
			try {
				if (!popup || popup.closed || !popup.__ultracacheRuntimeJsScan) {
					return null;
				}
				const state = popup.__ultracacheRuntimeJsScan;
				const errors = Array.isArray(state.errors) ? state.errors.slice(0, 120) : [];
				return {
					scanId,
					url: sanitizeRuntimeJsScanDisplayUrl(String((popup.location && popup.location.href) || scanUrl || '')),
					completed: false,
					scanContext: (popup.__ultracacheRuntimeJsScan && popup.__ultracacheRuntimeJsScan.context) ? String(popup.__ultracacheRuntimeJsScan.context) : 'anonymous',
					errors,
					scripts: collectPopupRuntimeJsScanScripts(popup),
					userAgent: String((popup.navigator && popup.navigator.userAgent) || ''),
					elapsedMs: state.injectedAt ? Math.max(0, Date.now() - Number(state.injectedAt || 0)) : 0,
					debug: Object.assign({}, state.debug || {}, { directHarvest: true, sentCount: state.sentCount || 0 }),
					queueJobId: queueJobId || '',
				};
			} catch (error) {
				return null;
			}
		}

function readPopupRuntimeJsScanNavigationLossSnapshot(popup, scanId, scanUrl, runtimeUrl, scanContext, queueJobId, startedAt) {
			try {
				if (!popup || popup.closed || popup.__ultracacheRuntimeJsScan) {
					return null;
				}
				const currentUrl = String((popup.location && popup.location.href) || '');
				if (!currentUrl || currentUrl === 'about:blank' || runtimeJsScanUrlHasScanId(currentUrl, scanId)) {
					return null;
				}
				const cleanCurrentUrl = sanitizeRuntimeJsScanDisplayUrl(currentUrl);
				if (!cleanCurrentUrl || cleanCurrentUrl === sanitizeRuntimeJsScanDisplayUrl(runtimeUrl)) {
					return null;
				}
				return {
					scanId,
					url: cleanCurrentUrl,
					completed: true,
					scanContext: scanContext === 'logged-in' ? 'logged-in' : 'anonymous',
					errors: [{
						kind: 'scan-navigation-before-collector',
						message: 'Browser Runtime Scan navigated away before the collector could report.',
						source: cleanCurrentUrl,
						line: 0,
						column: 0,
						detail: JSON.stringify({
							scanUrl: sanitizeRuntimeJsScanDisplayUrl(scanUrl),
							runtimeUrl: sanitizeRuntimeJsScanDisplayUrl(runtimeUrl),
							navigatedUrl: cleanCurrentUrl,
							reason: 'scan-query-lost-before-collector-report',
						}),
						atMs: Math.max(0, Date.now() - Number(startedAt || Date.now())),
					}],
					scripts: collectPopupRuntimeJsScanScripts(popup),
					userAgent: String((popup.navigator && popup.navigator.userAgent) || ''),
					elapsedMs: Math.max(0, Date.now() - Number(startedAt || Date.now())),
					debug: { directHarvest: true, navigationLostScanParams: true },
					queueJobId: queueJobId || '',
				};
			} catch (error) {
				return null;
			}
		}

	admin.define('diagnostics', {
		configure,
		getJsDelaySafetySuggestions,
		getJsDelayReviewSuggestions,
		isSuggestionPresentInDraft,
		DeferDelayExclusionsField,
		CssBundleExclusionsDiagnosticsField,
		getNextMediaEtaCheckpoint,
		formatEtaDuration,
		PerformanceProfilerCard,
		sanitizeRuntimeJsScanDisplayUrl,
		buildRuntimeJsScanUrl,
		runtimeJsScanUrlHasScanId,
		normalizeRuntimeJsScanResult,
		collectPopupRuntimeJsScanScripts,
		readPopupRuntimeJsScanSnapshot,
		readPopupRuntimeJsScanNavigationLossSnapshot,
	});
})(window);
