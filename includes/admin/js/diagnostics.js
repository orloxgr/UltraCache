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
		useRef,
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
			.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && item.appendable === false && !item.stillFailingWhileListed && !item.alreadyExcluded)
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


function runtimeScanSuggestionLine(item) {
		return String(item && item.suggestedExclusion ? item.suggestedExclusion : '').trim();
	}

function runtimeScanSuggestionPreferredTarget(item) {
		const target = item && item.preferredTarget ? String(item.preferredTarget).toLowerCase() : '';
		if (item && item.fallbackRecommended && !item.alreadyExcluded) {
			return 'exclusion';
		}
		return target === 'force' || target === 'exclusion' ? target : '';
	}

function runtimeScanErrorsToConsoleText(result) {
		const errors = result && Array.isArray(result.errors) ? result.errors : [];
		return errors
			.filter((item) => item && String(item.kind || '').toLowerCase() !== 'resource-error')
			.map((item) => {
				const message = String(item.message || '').trim();
				const detail = String(item.detail || '').trim();
				const source = String(item.source || '').trim();
				const line = Number(item.line || 0);
				const column = Number(item.column || 0);
				const lines = [];
				if (message) {
					lines.push(message);
				}
				if (detail) {
					const detailLines = detail.split(/\r?\n/);
					const comparableMessage = message.replace(/^Uncaught\s+/i, '').trim().toLowerCase();
					if (detailLines.length && comparableMessage && String(detailLines[0] || '').trim().toLowerCase() === comparableMessage) {
						detailLines.shift();
					}
					if (detailLines.length) {
						lines.push(detailLines.join('\n'));
					}
				}
				if (source && !lines.some((value) => String(value || '').indexOf(source) !== -1)) {
					lines.push('at ' + source + (line ? ':' + line + (column ? ':' + column : '') : ''));
				}
				return lines.join('\n');
			})
			.filter(Boolean)
			.join('\n\n');
	}


function normalizeRuntimeScanErrorMessage(message) {
		return String(message || '')
			.replace(/^Uncaught(?:\s*\(in promise\))?\s*/i, '')
			.replace(/\s+/g, ' ')
			.trim()
			.toLowerCase();
	}

function runtimeScanErrorSourceIdentity(item) {
		let source = String(item && item.source ? item.source : '').trim();
		if (!source) {
			const detail = String(item && item.detail ? item.detail : '');
			const match = detail.match(/https?:\/\/[^\s)]+?\.js(?:\?[^\s):]+)?/i) || detail.match(/(?:^|[\s(])([^\s()]+\.js)(?::\d+(?::\d+)?)?/i);
			if (match) {
				source = String(match[1] || match[0] || '').trim();
			}
		}
		if (!source) {
			return '';
		}
		try {
			const parsed = new URL(source, window.location.origin);
			return (String(parsed.host || '').toLowerCase() + String(parsed.pathname || '').toLowerCase()).replace(/\/{2,}/g, '/');
		} catch (error) {
			return source
				.replace(/[?#].*$/, '')
				.replace(/:\d+(?::\d+)?$/, '')
				.trim()
				.toLowerCase();
		}
	}

function runtimeScanErrorSignature(item) {
		const kind = String(item && item.kind ? item.kind : 'runtime-error').trim().toLowerCase();
		const message = normalizeRuntimeScanErrorMessage(item && item.message ? item.message : '');
		const source = runtimeScanErrorSourceIdentity(item);
		return kind + '|' + message + '|' + source;
	}

function subtractRuntimeScanBaseline(optimizedResult, baselineResult) {
		const optimizedErrors = optimizedResult && Array.isArray(optimizedResult.errors) ? optimizedResult.errors : [];
		const baselineErrors = baselineResult && Array.isArray(baselineResult.errors) ? baselineResult.errors : [];
		const baselineCounts = {};
		baselineErrors.forEach((item) => {
			const signature = runtimeScanErrorSignature(item);
			baselineCounts[signature] = (baselineCounts[signature] || 0) + 1;
		});
		const differentialErrors = [];
		optimizedErrors.forEach((item) => {
			const signature = runtimeScanErrorSignature(item);
			if ((baselineCounts[signature] || 0) > 0) {
				baselineCounts[signature] -= 1;
				return;
			}
			differentialErrors.push(item);
		});
		return Object.assign({}, optimizedResult || {}, {
			errors: differentialErrors,
			runtimeErrorCount: differentialErrors.length,
			capturedRuntimeErrorCount: optimizedErrors.length,
			baselineRuntimeErrorCount: baselineErrors.length,
			baselineErrors: baselineErrors.slice(),
			capturedErrors: optimizedErrors.slice(),
			baselineComparison: true,
		});
	}

async function requestRuntimeJsConsoleFixer(input, targetUrl, scanContext, runtimeScripts) {
		const text = String(input || '');
		if (!text.trim()) {
			return null;
		}
		const fallbackUrl = ultracache && ultracache.frontendProbeUrl ? String(ultracache.frontendProbeUrl || '') : '';
		const runtimeInventory = Array.isArray(runtimeScripts) ? runtimeScripts.slice(0, 240).map((script) => {
			const item = script && typeof script === 'object' ? script : {};
			return {
				order: Math.max(0, Number(item.order || 0)),
				id: String(item.id || '').slice(0, 160),
				handle: String(item.handle || '').slice(0, 160),
				src: String(item.src || '').slice(0, 1200),
				type: String(item.type || '').slice(0, 120),
				defer: !!item.defer,
				async: !!item.async,
				strategy: String(item.strategy || '').slice(0, 80),
				delayed: !!item.delayed,
				deps: Array.isArray(item.deps) ? item.deps.slice(0, 40).map((value) => String(value || '').slice(0, 160)) : [],
				text: (!item.src || item.delayed) ? String(item.text || '').slice(0, 4000) : '',
			};
		}).filter((item) => item.src || item.id || item.handle || item.text) : [];
		return apiRequest('runtime_js_diagnostic_queue_start', {
			scanType: 'console',
			text,
			url: String(targetUrl || fallbackUrl || '').trim(),
			scanContext: scanContext === 'logged-in' ? 'logged-in' : 'anonymous',
			runtimeScripts: runtimeInventory,
		});
	}

function runtimeVisiblePolicyDescriptor(target) {
		const key = String(target || '').trim().toLowerCase();
		const map = {
			delay: { target: 'delay', lane: 'delay', settingKey: 'delaySafeThirdPartyJsPatterns', listLabel: 'Delay third-party JS Patterns' },
			force: { target: 'force', lane: 'defer', settingKey: 'deferJsForceList', listLabel: 'Defer Instead of Delay' },
			exclusion: { target: 'exclusion', lane: 'native', settingKey: 'deferJsExcludeList', listLabel: 'Do Not Defer or Delay' },
		};
		return Object.prototype.hasOwnProperty.call(map, key) ? Object.assign({ writeMode: 'visible-setting-only', hiddenOverride: false }, map[key]) : null;
	}

function runtimeAutomaticDecisionIsVisiblePolicyOnly(decision) {
		if (!decision || !decision.added) {
			return true;
		}
		const expected = runtimeVisiblePolicyDescriptor(decision.target);
		const actual = decision.policyWrite && typeof decision.policyWrite === 'object' ? decision.policyWrite : null;
		return !!expected && !!actual
			&& actual.writeMode === 'visible-setting-only'
			&& actual.hiddenOverride === false
			&& actual.target === expected.target
			&& actual.lane === expected.lane
			&& actual.settingKey === expected.settingKey
			&& actual.listLabel === expected.listLabel;
	}

function buildAutomaticRuntimeFixValues(scanResult, exclusionValue, forceValue, delayValue, options) {
		const opts = options && typeof options === 'object' ? options : {};
		const items = scanResult && Array.isArray(scanResult.suggestions) ? scanResult.suggestions : [];
		const delayLines = [];
		const forceLines = [];
		const exclusionLines = [];
		const seen = {};

		/*
		 * Repair escalation is intentionally performance-first:
		 * 1. If Runtime Scan proved a delayed provider / deferred consumer pair,
		 *    delay the proven consumer too.
		 * 2. If the error persists, promote the proven provider to Defer Instead.
		 * 3. Only then fall back to Do Not Defer or Delay.
		 *
		 * A tier is applied on its own cycle. We never mix a later/more invasive
		 * tier into the same save while an earlier deterministic action exists.
		 */
		let reversibleDelayRepair = false;
		const delayTrialSamples = [];
		if (opts.delayEnabled !== false) {
			items.forEach((item) => {
				if (!item || item.ignored || String(item.confidence || '').toLowerCase() !== 'recommended') {
					return;
				}
				const line = String(item.delaySuggestion || '').trim();
				const ownedStrongerSafeguard = !!(item.delayRepairAutoEligible || item.delaySuggestionScannerOwnedExclusion || item.delaySuggestionScannerOwnedForce);
				const coveredByExclusion = !!line && isSuggestionPresentInDraft(exclusionValue, line);
				const coveredByForce = !!line && isSuggestionPresentInDraft(forceValue, line);
				const reversible = !!(item.delayRepairRecommended && ownedStrongerSafeguard && (coveredByExclusion || coveredByForce));
				if (item.appendable === false && !reversible) {
					return;
				}
				if (!line || isSuggestionPresentInDraft(delayValue, line)) {
					return;
				}
				if ((coveredByExclusion || coveredByForce) && !reversible) {
					return;
				}
				const key = 'delay|' + line.toLowerCase();
				if (seen[key]) {
					return;
				}
				seen[key] = true;
				reversibleDelayRepair = reversibleDelayRepair || reversible;
				if (reversible && item.sample) {
					const sample = String(item.sample || '').replace(/\s+/g, ' ').trim().toLowerCase();
					if (sample && delayTrialSamples.indexOf(sample) === -1) {
						delayTrialSamples.push(sample);
					}
				}
				delayLines.push(line);
			});
		}

		if (delayLines.length) {
			const beforeState = {
				exclusionValue: String(exclusionValue || ''),
				forceValue: String(forceValue || ''),
				delayValue: String(delayValue || ''),
			};
			const mergedDelay = mergeUniqueSettingLines(delayValue, delayLines);
			const cleanedForce = removeOverlappingSettingLines(forceValue, delayLines);
			const cleanedExclusions = removeOverlappingSettingLines(exclusionValue, delayLines);
			const changedCount = mergedDelay.added + cleanedForce.removed + cleanedExclusions.removed;
			return {
				exclusionValue: cleanedExclusions.value,
				forceValue: cleanedForce.value,
				delayValue: mergedDelay.value,
				added: changedCount,
				target: 'delay',
				lines: delayLines.slice(),
				reversibleDelayRepair: reversibleDelayRepair,
				rollbackState: reversibleDelayRepair ? beforeState : null,
				trialSamples: reversibleDelayRepair ? delayTrialSamples.slice() : [],
				policyWrite: runtimeVisiblePolicyDescriptor('delay'),
			};
		}

		items.forEach((item) => {
			if (!item || item.appendable === false || item.alreadyExcluded || item.ignored || String(item.confidence || '').toLowerCase() !== 'recommended') {
				return;
			}
			const line = runtimeScanSuggestionLine(item);
			let target = runtimeScanSuggestionPreferredTarget(item);
			if (!line) {
				return;
			}
			if (target !== 'force' && target !== 'exclusion' && opts.preferDeferForAmbiguous) {
				target = isSuggestionPresentInDraft(forceValue, line) ? 'exclusion' : 'force';
			}
			if (target !== 'force' && target !== 'exclusion') {
				return;
			}
			const key = target + '|' + line.toLowerCase();
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			if (target === 'force') {
				if (!isSuggestionPresentInDraft(forceValue, line) && !isSuggestionPresentInDraft(exclusionValue, line)) {
					forceLines.push(line);
				}
			} else if (!isSuggestionPresentInDraft(exclusionValue, line)) {
				exclusionLines.push(line);
			}
		});

		if (forceLines.length) {
			const mergedForce = mergeUniqueSettingLines(forceValue, forceLines);
			return {
				exclusionValue: String(exclusionValue || ''),
				forceValue: mergedForce.value,
				delayValue: String(delayValue || ''),
				added: mergedForce.added,
				target: 'force',
				lines: forceLines.slice(),
				policyWrite: runtimeVisiblePolicyDescriptor('force'),
			};
		}

		if (exclusionLines.length) {
			const mergedExclusions = mergeUniqueSettingLines(exclusionValue, exclusionLines);
			const forceWithoutExclusions = removeOverlappingSettingLines(forceValue, exclusionLines);
			return {
				exclusionValue: mergedExclusions.value,
				forceValue: forceWithoutExclusions.value,
				delayValue: String(delayValue || ''),
				added: mergedExclusions.added,
				target: 'exclusion',
				lines: exclusionLines.slice(),
				policyWrite: runtimeVisiblePolicyDescriptor('exclusion'),
			};
		}

		return {
			exclusionValue: String(exclusionValue || ''),
			forceValue: String(forceValue || ''),
			delayValue: String(delayValue || ''),
			added: 0,
			target: '',
			lines: [],
			policyWrite: null,
		};
	}

function mergeRuntimeScanFixerRecords(records) {
		const list = Array.isArray(records) ? records : [];
		const merged = [];
		const byKey = {};
		let lastScan = null;

		list.forEach((record) => {
			const scan = record && record.scan && typeof record.scan === 'object' ? record.scan : null;
			if (!scan) {
				return;
			}
			lastScan = scan;
			const targetLabel = String(record.targetLabel || '').trim();
			const targetUrl = String(record.targetUrl || '').trim();
			(Array.isArray(scan.suggestions) ? scan.suggestions : []).forEach((item) => {
				if (!item || typeof item !== 'object') {
					return;
				}
				const key = [
					String(item.suggestedExclusion || '').trim().toLowerCase(),
					String(item.delaySuggestion || '').trim().toLowerCase(),
					String(item.preferredTarget || '').trim().toLowerCase(),
					String(item.symbol || '').trim().toLowerCase(),
				].join('|');
				if (!key.replace(/\|/g, '')) {
					return;
				}
				if (!byKey[key]) {
					const copy = Object.assign({}, item, {
						detectedOn: targetLabel ? [targetLabel] : [],
						detectedOnUrls: targetUrl ? [targetUrl] : [],
					});
					byKey[key] = copy;
					merged.push(copy);
					return;
				}
				const existing = byKey[key];
				if (targetLabel && existing.detectedOn.indexOf(targetLabel) === -1) {
					existing.detectedOn.push(targetLabel);
				}
				if (targetUrl && existing.detectedOnUrls.indexOf(targetUrl) === -1) {
					existing.detectedOnUrls.push(targetUrl);
				}
			});
		});

		if (!lastScan) {
			return null;
		}

		return Object.assign({}, lastScan, {
			source: 'browser-runtime-multi-target',
			suggestions: merged,
			suggestionCount: merged.length,
			multiTarget: true,
		});
	}


async function runRuntimeSiteScanAction(options) {
		const opts = options && typeof options === 'object' ? options : {};
		const manualScanUrl = String(opts.manualScanUrl || '').trim();
		const defaultScanUrl = String(opts.defaultScanUrl || '').trim();
		const scanContext = opts.scanContext === 'logged-in' ? 'logged-in' : 'anonymous';
		const status = (message) => {
			if (typeof opts.onStatus === 'function') {
				opts.onStatus(String(message || ''));
			}
		};
		const progress = (percent, phase) => {
			if (typeof opts.onProgress === 'function') {
				const normalizedPercent = Math.round(Math.max(0, Math.min(100, Number(percent || 0))) * 10) / 10;
				opts.onProgress({
					percent: normalizedPercent,
					phase: String(phase || ''),
				});
			}
		};
		progress(0, 'prepare');

		let configuredTargets = [];
		if (!manualScanUrl) {
			status('Discovering automatic Runtime Site Scan targets…');
			const targetResponse = typeof opts.discoverTargets === 'function'
				? await opts.discoverTargets()
				: await apiRequest('runtime_js_scan_targets', {});
			configuredTargets = targetResponse && Array.isArray(targetResponse.targets) ? targetResponse.targets : [];
		}

		const seenTargets = {};
		const automaticTargets = configuredTargets.reduce((targets, item) => {
			const target = item && typeof item === 'object' ? item : {};
			const url = String(target.url || '').trim();
			if (!url) {
				return targets;
			}
			const key = sanitizeRuntimeJsScanDisplayUrl(url).replace(/\/$/, '').toLowerCase();
			if (!key || seenTargets[key]) {
				return targets;
			}
			seenTargets[key] = true;
			targets.push({
				role: String(target.role || 'page'),
				label: String(target.label || 'Page'),
				url: url,
			});
			return targets;
		}, []);
		if (!automaticTargets.length && defaultScanUrl) {
			automaticTargets.push({ role: 'home', label: 'Front page', url: defaultScanUrl });
		}
		const targets = manualScanUrl
			? [{ role: 'manual', label: 'Manual URL', url: manualScanUrl }]
			: automaticTargets;

		if (!targets.length) {
			throw new Error('No valid frontend scan target is available.');
		}

		// Runtime Scan progress reserves one equal slot for every possible cycle on
		// every target. A target that resolves early consumes its unused cycle slots
		// immediately, so progress jumps forward instead of waiting on work that will
		// never run. The final slot is deliberately withheld from the last target so
		// 100% remains an explicit whole-scan completion state.
		const runtimeMaxScans = Math.max(1, Number(opts.maxScans || 10));
		const runtimeProgressSlots = Math.max(1, targets.length * runtimeMaxScans);
		const runtimeProgressStep = 100 / runtimeProgressSlots;
		const runtimeProgressFromSlots = (completedSlots) => {
			const raw = (Math.max(0, Math.min(runtimeProgressSlots, Number(completedSlots || 0))) / runtimeProgressSlots) * 100;
			const preCompleteCeiling = Math.max(0, 100 - runtimeProgressStep);
			return Math.round(Math.min(preCompleteCeiling, raw) * 10) / 10;
		};
		const runtimeTargetProgress = (targetIndex) => runtimeProgressFromSlots((Math.max(0, targetIndex) + 1) * runtimeMaxScans);
		const runtimeCycleProgress = (targetIndex, completedCycles) => runtimeProgressFromSlots((Math.max(0, targetIndex) * runtimeMaxScans) + Math.max(0, Math.min(runtimeMaxScans, Number(completedCycles || 0))));

		let runtimeExclusionValue = String(opts.exclusionValue || '');
		let runtimeForceValue = String(opts.forceValue || '');
		let runtimeDelayValue = String(opts.delayValue || '');
		let strategyPrepared = false;
		const fixerRecords = [];
		const targetResults = [];
		const targetOutcomes = [];

		for (let targetIndex = 0; targetIndex < targets.length; targetIndex++) {
			const target = targets[targetIndex];
			const targetMeta = {
				target: target,
				targetIndex: targetIndex,
				targetNumber: targetIndex + 1,
				targetCount: targets.length,
				targetPrefix: 'Target ' + String(targetIndex + 1) + '/' + String(targets.length) + ' · ' + String(target.label || 'Page'),
			};
			status(targetMeta.targetPrefix + ' · preparing…');
			if (typeof opts.onTargetStart === 'function') {
				opts.onTargetStart(targetMeta);
			}

			try {
				const outcome = await runRuntimeJsSelfHealingCycles({
					targetUrl: target.url,
					scanContext: scanContext,
					maxScans: runtimeMaxScans,
					preferDeferForAmbiguous: !!opts.preferDeferForAmbiguous,
					browserWindow: opts.browserWindow || null,
					exclusionValue: runtimeExclusionValue,
					forceValue: runtimeForceValue,
					delayValue: runtimeDelayValue,
					delayEnabled: opts.delayEnabled !== false,
					prepareStrategySafeguards: !strategyPrepared && typeof opts.prepareStrategySafeguards === 'function' ? opts.prepareStrategySafeguards : null,
					onStrategySafeguardsPrepared: function(strategyState) {
						strategyPrepared = true;
						if (strategyState && typeof strategyState === 'object') {
							runtimeExclusionValue = String(strategyState.exclusionValue || '');
							runtimeForceValue = String(strategyState.forceValue || '');
							runtimeDelayValue = String(strategyState.delayValue || '');
						}
						if (typeof opts.onStrategySafeguardsPrepared === 'function') {
							opts.onStrategySafeguardsPrepared(strategyState, targetMeta);
						}
					},
					prepare: opts.prepare,
					beginBaselineState: typeof opts.beginBaselineState === 'function' ? opts.beginBaselineState : null,
					restoreBaselineState: typeof opts.restoreBaselineState === 'function' ? opts.restoreBaselineState : null,
					afterBaselineCaptured: typeof opts.afterBaselineCaptured === 'function' ? async function(baselineResult) {
						return opts.afterBaselineCaptured(baselineResult, targetMeta);
					} : null,
					scan: opts.scan,
					runFixer: opts.runFixer,
					saveSafeguards: opts.saveSafeguards,
					onConsoleText: function(consoleText, result, pass) {
						if (typeof opts.onConsoleText === 'function') {
							opts.onConsoleText(consoleText, result, pass, targetMeta);
						}
					},
					onFixerScan: function(fixerScan) {
						if (fixerScan && typeof fixerScan === 'object') {
							fixerRecords.push({ scan: fixerScan, targetLabel: String(target.label || 'Page'), targetUrl: String(target.url || '') });
						}
						if (typeof opts.onFixerScan === 'function') {
							opts.onFixerScan(fixerScan, targetMeta);
						}
					},
					onSafeguardsApplied: function(nextState) {
						const state = nextState && typeof nextState === 'object' ? nextState : {};
						runtimeExclusionValue = String(state.exclusionValue || '');
						runtimeForceValue = String(state.forceValue || '');
						runtimeDelayValue = String(state.delayValue || '');
						if (typeof opts.onSafeguardsApplied === 'function') {
							opts.onSafeguardsApplied(state, targetMeta);
						}
					},
					onStatus: function(message) {
						status(targetMeta.targetPrefix + ' · ' + String(message || ''));
					},
					onCycleProgress: function(cycleState) {
						const state = cycleState && typeof cycleState === 'object' ? cycleState : {};
						progress(runtimeCycleProgress(targetIndex, state.pass), 'cycle-complete');
					},
				});

				strategyPrepared = true;
				runtimeExclusionValue = String(outcome && Object.prototype.hasOwnProperty.call(outcome, 'exclusionValue') ? outcome.exclusionValue : runtimeExclusionValue);
				runtimeForceValue = String(outcome && Object.prototype.hasOwnProperty.call(outcome, 'forceValue') ? outcome.forceValue : runtimeForceValue);
				runtimeDelayValue = String(outcome && Object.prototype.hasOwnProperty.call(outcome, 'delayValue') ? outcome.delayValue : runtimeDelayValue);

				const isolationWarnings = [];
				[outcome && outcome.baselineResult, outcome && outcome.result].forEach((scanResult) => {
					const warnings = scanResult && Array.isArray(scanResult.isolationWarnings) ? scanResult.isolationWarnings : [];
					warnings.forEach((warning) => {
						const integration = warning && warning.integration ? String(warning.integration) : 'consent-state';
						const reason = warning && warning.reason ? String(warning.reason) : 'unknown';
						const text = integration + ': ' + reason;
						if (isolationWarnings.indexOf(text) === -1) {
							isolationWarnings.push(text);
						}
					});
				});
				const resultRow = {
					label: String(target.label || 'Page'),
					url: String(target.url || ''),
					status: outcome && outcome.success ? 'complete' : 'unresolved',
					passes: Math.max(0, Number(outcome && outcome.passes || 0)),
					added: Math.max(0, Number(outcome && outcome.totalAdded || 0)),
					residual: Math.max(0, Number(outcome && outcome.residualRuntimeErrors || 0)),
					reason: outcome && outcome.reason ? String(outcome.reason) : '',
					measurementFailureReason: outcome && outcome.measurementFailureReason ? String(outcome.measurementFailureReason) : '',
					message: outcome && outcome.message ? String(outcome.message) : '',
					warning: isolationWarnings.length ? ('Consent-state reset warning: ' + isolationWarnings.join(', ') + '. Scan continued.') : '',
				};
				targetResults.push(resultRow);
				targetOutcomes.push({ target: target, outcome: outcome || null });
				if (typeof opts.onTargetResults === 'function') {
					opts.onTargetResults(targetResults.slice(), resultRow, targetMeta);
				}
				progress(runtimeTargetProgress(targetIndex), 'target-complete');
			} catch (targetError) {
				const resultRow = {
					label: String(target.label || 'Page'),
					url: String(target.url || ''),
					status: 'failed',
					passes: 0,
					added: 0,
					residual: 0,
					message: targetError && targetError.message ? String(targetError.message) : 'Runtime Scan target failed.',
				};
				targetResults.push(resultRow);
				targetOutcomes.push({ target: target, outcome: null, error: targetError || null });
				if (typeof opts.onTargetResults === 'function') {
					opts.onTargetResults(targetResults.slice(), resultRow, targetMeta);
				}
				progress(runtimeTargetProgress(targetIndex), 'target-failed');
				if (manualScanUrl) {
					throw targetError;
				}
			}
		}

		const mergedFixerScan = mergeRuntimeScanFixerRecords(fixerRecords);
		const baselineErrorMap = {};
		targetOutcomes.forEach((record) => {
			const target = record && record.target && typeof record.target === 'object' ? record.target : {};
			const outcome = record && record.outcome && typeof record.outcome === 'object' ? record.outcome : null;
			const baselineErrors = outcome && outcome.baselineResult && Array.isArray(outcome.baselineResult.errors) ? outcome.baselineResult.errors : [];
			baselineErrors.forEach((item) => {
				const signature = runtimeScanErrorSignature(item);
				if (!signature) {
					return;
				}
				if (!baselineErrorMap[signature]) {
					baselineErrorMap[signature] = Object.assign({}, item || {}, {
						preExistingBaseline: true,
						detectedOn: [],
					});
				}
				const label = String(target.label || 'Page');
				if (baselineErrorMap[signature].detectedOn.indexOf(label) === -1) {
					baselineErrorMap[signature].detectedOn.push(label);
				}
			});
		});
		const baselineErrors = Object.keys(baselineErrorMap).map((key) => baselineErrorMap[key]);
		const failedCount = targetResults.filter((item) => item.status === 'failed').length;
		const unresolvedCount = targetResults.filter((item) => item.status === 'unresolved').length;
		const measurementFailureCount = targetResults.filter((item) => item && (item.reason === 'baseline-measurement-unavailable' || item.reason === 'cycle-measurement-unavailable')).length;
		const completedCount = targetResults.length - failedCount;
		const totalAdded = targetResults.reduce((sum, item) => sum + Math.max(0, Number(item.added || 0)), 0);
		const passes = targetResults.reduce((sum, item) => sum + Math.max(0, Number(item.passes || 0)), 0);
		const residualRuntimeErrors = targetResults.reduce((sum, item) => sum + Math.max(0, Number(item.residual || 0)), 0);

		const issueMessages = targetResults
			.filter((item) => item && item.status !== 'complete')
			.map((item) => {
				const label = String(item.label || 'Page');
				const reason = String(item.measurementFailureReason || item.message || item.reason || item.status || 'Runtime Scan target needs attention.').trim();
				return label + ': ' + reason;
			});
		let summaryState = 'complete';
		let summaryMessage = '';
		if (failedCount) {
			summaryState = 'warning';
			summaryMessage = 'Runtime Site Scan completed ' + completedCount + '/' + targets.length + ' target(s); ' + failedCount + ' target(s) failed and ' + unresolvedCount + ' target(s) remain unresolved.';
		} else if (measurementFailureCount) {
			summaryState = 'warning';
			summaryMessage = 'Runtime Site Scan continued across all targets; ' + measurementFailureCount + ' target(s) could not produce a browser measurement.';
		} else if (unresolvedCount) {
			summaryState = 'warning';
			summaryMessage = 'Runtime Site Scan completed all ' + targets.length + ' target(s); ' + unresolvedCount + ' target(s) remain unresolved after automatic safeguards.';
		} else {
			summaryMessage = (manualScanUrl ? 'Runtime Scan' : 'Runtime Site Scan') + ' completed successfully across ' + targets.length + ' target(s).';
		}
		if (issueMessages.length) {
			summaryMessage += ' ' + issueMessages.slice(0, 3).join(' | ');
		}

		const result = {
			success: failedCount === 0 && unresolvedCount === 0,
			reason: failedCount ? 'target-failure' : (unresolvedCount ? 'unresolved-targets' : 'complete'),
			summaryState: summaryState,
			summaryMessage: summaryMessage,
			issueMessages: issueMessages.slice(),
			manual: !!manualScanUrl,
			targets: targets.slice(),
			targetResults: targetResults.slice(),
			targetOutcomes: targetOutcomes.slice(),
			mergedFixerScan: mergedFixerScan,
			baselineErrors: baselineErrors,
			baselineErrorCount: baselineErrors.length,
			failedCount: failedCount,
			unresolvedCount: unresolvedCount,
			measurementFailureCount: measurementFailureCount,
			completedCount: completedCount,
			passes: passes,
			totalAdded: totalAdded,
			residualRuntimeErrors: residualRuntimeErrors,
			exclusionValue: runtimeExclusionValue,
			forceValue: runtimeForceValue,
			delayValue: runtimeDelayValue,
		};

		progress(100, 'complete');
		if (typeof opts.onComplete === 'function') {
			opts.onComplete(result);
		}
		return result;
	}

function runtimeScanFixerReportsDelayRepairLine(scanResult, trial) {
		const record = trial && typeof trial === 'object' ? trial : {};
		const wanted = {};
		normalizeSettingListLines(Array.isArray(record.lines) ? record.lines.join('\n') : record.lines).forEach((line) => { wanted[String(line).toLowerCase()] = true; });
		if (!Object.keys(wanted).length) {
			return false;
		}
		const samples = (Array.isArray(record.samples) ? record.samples : []).map((sample) => String(sample || '').replace(/\s+/g, ' ').trim().toLowerCase()).filter(Boolean);
		const items = scanResult && Array.isArray(scanResult.suggestions) ? scanResult.suggestions : [];
		return items.some((item) => {
			if (!item) { return false; }
			const candidates = [item.suggestedExclusion, item.delaySuggestion].map((value) => String(value || '').trim().toLowerCase()).filter(Boolean);
			if (!candidates.some((candidate) => wanted[candidate])) {
				return false;
			}
			const sample = String(item.sample || '').replace(/\s+/g, ' ').trim().toLowerCase();
			if (samples.length) {
				return !!sample && samples.some((wantedSample) => sample === wantedSample || sample.indexOf(wantedSample) !== -1 || wantedSample.indexOf(sample) !== -1);
			}
			return !!item.stillFailingWhileListed || String(item.category || '').indexOf('listed-but-') === 0;
		});
	}

async function runRuntimeJsSelfHealingCycles(options) {
		const opts = options && typeof options === 'object' ? options : {};
		const maxScans = Math.max(1, Number(opts.maxScans || 10));
		const targetScanUrl = String(opts.targetUrl || '').trim();
		const scanContext = opts.scanContext === 'logged-in' ? 'logged-in' : 'anonymous';
		if (!targetScanUrl) {
			throw new Error('Page URL to scan is empty.');
		}
		if (typeof opts.prepare !== 'function' || typeof opts.scan !== 'function' || typeof opts.saveSafeguards !== 'function') {
			throw new Error('Runtime Scan cycle handlers are unavailable.');
		}
		const status = (message) => {
			if (typeof opts.onStatus === 'function') {
				opts.onStatus(String(message || ''));
			}
		};
		const cycleComplete = (pass, phase) => {
			if (typeof opts.onCycleProgress === 'function') {
				opts.onCycleProgress({
					pass: Math.max(0, Math.min(maxScans, Number(pass || 0))),
					maxScans: maxScans,
					phase: String(phase || 'cycle-complete'),
				});
			}
		};

		let strategySafeguardState = null;
		if (typeof opts.prepareStrategySafeguards === 'function') {
			status('Preparing JavaScript Strategy safeguards…');
			strategySafeguardState = await opts.prepareStrategySafeguards();
			if (typeof opts.onStrategySafeguardsPrepared === 'function') {
				opts.onStrategySafeguardsPrepared(strategySafeguardState);
			}
		}
		let cycleExclusions = String(strategySafeguardState && Object.prototype.hasOwnProperty.call(strategySafeguardState, 'exclusionValue') ? strategySafeguardState.exclusionValue : (opts.exclusionValue || ''));
		let cycleForce = String(strategySafeguardState && Object.prototype.hasOwnProperty.call(strategySafeguardState, 'forceValue') ? strategySafeguardState.forceValue : (opts.forceValue || ''));
		let cycleDelay = String(strategySafeguardState && Object.prototype.hasOwnProperty.call(strategySafeguardState, 'delayValue') ? strategySafeguardState.delayValue : (opts.delayValue || ''));
		let totalAdded = 0;
		let lastResult = null;
		let lastFixerScan = null;
		let pendingDelayRepairTrial = null;
		const sharedScanId = 'rt_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);

		let baselineStateSnapshot = null;
		let baselineStateActive = false;
		try {
			if (typeof opts.beginBaselineState === 'function') {
				status('Baseline: saving current JavaScript settings and disabling all JavaScript optimizations…');
				baselineStateSnapshot = await opts.beginBaselineState();
				baselineStateActive = true;
			}

		status('Baseline: clearing cache before the unoptimized Runtime Scan…');
		await opts.prepare(targetScanUrl, function(progress) {
			const message = progress && progress.message ? String(progress.message) : '';
			if (message) {
				status('Baseline: ' + message);
			}
		});
		status('Baseline: scanning with UltraCache JavaScript optimizations disabled…');
		const baselineResult = await opts.scan(targetScanUrl, function(statusText) {
			const message = String(statusText || '').trim();
			if (message) {
				status('Baseline: ' + message);
			}
		}, { context: scanContext, scanId: sharedScanId, browserWindow: opts.browserWindow || null });
		if (!baselineResult || !baselineResult.available) {
			const measurementReason = baselineResult && baselineResult.failureReason ? String(baselineResult.failureReason) : 'baseline-report-unavailable';
			const measurementMessage = baselineResult && baselineResult.message ? String(baselineResult.message) : 'No completed baseline browser error report was returned.';
			status('Baseline browser measurement unavailable (' + measurementReason + '). ' + measurementMessage);
			cycleComplete(0, 'measurement-unavailable');
			return {
				success: false,
				reason: 'baseline-measurement-unavailable',
				message: measurementMessage,
				measurementFailureReason: measurementReason,
				measurementFailure: baselineResult || null,
				passes: 0,
				totalAdded: totalAdded,
				residualRuntimeErrors: 0,
				result: null,
				baselineResult: baselineResult || null,
				fixerScan: lastFixerScan,
				exclusionValue: cycleExclusions,
				forceValue: cycleForce,
				delayValue: cycleDelay,
			};
		}
		status('Baseline: recorded ' + Math.max(0, Number(baselineResult.runtimeErrorCount || 0)) + ' pre-existing runtime error(s).');
		if (typeof opts.afterBaselineCaptured === 'function') {
			await opts.afterBaselineCaptured(baselineResult);
		}
		if (baselineStateActive && typeof opts.restoreBaselineState === 'function') {
			status('Baseline: restoring the saved JavaScript optimization settings…');
			await opts.restoreBaselineState(baselineStateSnapshot);
			baselineStateActive = false;
		}

		for (let pass = 1; pass <= maxScans; pass++) {
			status('Cycle ' + pass + '/' + maxScans + ': clearing cache and warming scan URL…');
			await opts.prepare(targetScanUrl, function(progress) {
				const message = progress && progress.message ? String(progress.message) : '';
				if (message) {
					status('Cycle ' + pass + '/' + maxScans + ': ' + message);
				}
			});

			status('Cycle ' + pass + '/' + maxScans + ': scanning browser runtime errors…');
			const optimizedResult = await opts.scan(targetScanUrl, function(statusText) {
				const message = String(statusText || '').trim();
				if (message) {
					status('Cycle ' + pass + '/' + maxScans + ': ' + message);
				}
			}, { context: scanContext, scanId: sharedScanId, browserWindow: opts.browserWindow || null });
			if (!optimizedResult || !optimizedResult.available) {
				const measurementReason = optimizedResult && optimizedResult.failureReason ? String(optimizedResult.failureReason) : 'browser-report-unavailable';
				const measurementMessage = optimizedResult && optimizedResult.message ? String(optimizedResult.message) : 'No completed browser error report was returned.';
				status('Cycle ' + pass + '/' + maxScans + ': browser measurement unavailable (' + measurementReason + '). ' + measurementMessage);
				cycleComplete(pass, 'measurement-unavailable');
				return {
					success: false,
					reason: 'cycle-measurement-unavailable',
					message: measurementMessage,
					measurementFailureReason: measurementReason,
					measurementFailure: optimizedResult || null,
					passes: pass,
					totalAdded: totalAdded,
					residualRuntimeErrors: 0,
					result: null,
					baselineResult: baselineResult,
					fixerScan: lastFixerScan,
					exclusionValue: cycleExclusions,
					forceValue: cycleForce,
					delayValue: cycleDelay,
				};
			}
			const result = subtractRuntimeScanBaseline(optimizedResult, baselineResult);
			lastResult = result;

			const consoleText = runtimeScanErrorsToConsoleText(result);
			if (typeof opts.onConsoleText === 'function') {
				opts.onConsoleText(consoleText, result, pass, maxScans);
			}
			const errorCount = Math.max(0, Number(result.runtimeErrorCount || 0));
			const capturedCount = Math.max(errorCount, Number(result.capturedRuntimeErrorCount || errorCount));
			const baselineCount = Math.max(0, Number(result.baselineRuntimeErrorCount || 0));
			if (!errorCount || !consoleText.trim()) {
				status('Cycle ' + pass + '/' + maxScans + ': 0 new runtime errors after baseline comparison. ' + capturedCount + ' runtime error(s) were captured on the optimized page and ' + baselineCount + ' baseline error(s) were excluded from automatic UltraCache repair. Complete.');
				cycleComplete(pass, 'resolved');
				return {
					success: true,
					passes: pass,
					totalAdded,
					residualRuntimeErrors: 0,
					result,
					baselineResult,
					fixerScan: lastFixerScan,
					exclusionValue: cycleExclusions,
					forceValue: cycleForce,
					delayValue: cycleDelay,
				};
			}

			status('Cycle ' + pass + '/' + maxScans + ': ' + errorCount + ' new error(s) (' + capturedCount + ' captured, ' + baselineCount + ' baseline). Running JavaScript Error Fixer…');
			let fixerScan = null;
			if (typeof opts.runFixer === 'function') {
				fixerScan = await opts.runFixer(consoleText, result.scannedUrl || targetScanUrl, scanContext, 'Cycle ' + pass + '/' + maxScans + ': JavaScript Error Fixer is analyzing ' + errorCount + ' runtime error(s)…', result.scripts || []);
			} else {
				const response = await requestRuntimeJsConsoleFixer(consoleText, result.scannedUrl || targetScanUrl, scanContext, result.scripts || []);
				const job = response && response.jsDiagnosticQueue ? response.jsDiagnosticQueue : null;
				fixerScan = job && job.result && job.result.dashboardScan ? job.result.dashboardScan : null;
			}
			lastFixerScan = fixerScan;
			if (typeof opts.onFixerScan === 'function') {
				opts.onFixerScan(fixerScan, result, pass, maxScans);
			}
			if (!fixerScan) {
				throw new Error('JavaScript Error Fixer returned no result');
			}

			if (pendingDelayRepairTrial) {
				if (runtimeScanFixerReportsDelayRepairLine(fixerScan, pendingDelayRepairTrial)) {
					const rollback = pendingDelayRepairTrial.rollbackState || {};
					cycleExclusions = String(rollback.exclusionValue || '');
					cycleForce = String(rollback.forceValue || '');
					cycleDelay = String(rollback.delayValue || '');
					const rollbackDecision = {
						target: 'exclusion',
						lines: (pendingDelayRepairTrial.lines || []).slice(),
						rollback: true,
						policyWrite: runtimeVisiblePolicyDescriptor('exclusion'),
					};
					status('Cycle ' + pass + '/' + maxScans + ': reversible Delay trial still reports the same consumer error; restoring the exact prior JavaScript list state…');
					const rolledBack = await opts.saveSafeguards({
						exclusionValue: cycleExclusions,
						forceValue: cycleForce,
						delayValue: cycleDelay,
						decision: rollbackDecision,
						policyWrite: rollbackDecision.policyWrite,
						pass,
						maxScans,
					});
					if (rolledBack && rolledBack.success === false) {
						throw new Error(rolledBack.message ? String(rolledBack.message) : 'JavaScript safeguard rollback could not be saved');
					}
					pendingDelayRepairTrial = null;
					if (typeof opts.onSafeguardsApplied === 'function') {
						opts.onSafeguardsApplied({ exclusionValue: cycleExclusions, forceValue: cycleForce, delayValue: cycleDelay, decision: rollbackDecision, pass, maxScans });
					}
					cycleComplete(pass, 'rollback');
					return {
						success: false,
						reason: 'reversible-delay-repair-rolled-back',
						passes: pass,
						totalAdded,
						residualRuntimeErrors: errorCount,
						result,
						fixerScan,
						baselineResult,
						exclusionValue: cycleExclusions,
						forceValue: cycleForce,
						delayValue: cycleDelay,
					};
				}
				pendingDelayRepairTrial = null;
			}

			if (pass >= maxScans) {
				status('Cycle ' + pass + '/' + maxScans + ': scan limit reached with ' + errorCount + ' runtime error(s) still present.');
				cycleComplete(pass, 'scan-limit');
				return {
					success: false,
					reason: 'scan-limit',
					passes: pass,
					totalAdded,
					residualRuntimeErrors: errorCount,
					result,
					baselineResult,
					fixerScan,
					exclusionValue: cycleExclusions,
					forceValue: cycleForce,
					delayValue: cycleDelay,
				};
			}

			const decision = buildAutomaticRuntimeFixValues(
				fixerScan,
				cycleExclusions,
				cycleForce,
				cycleDelay,
				{
					preferDeferForAmbiguous: !!opts.preferDeferForAmbiguous,
					delayEnabled: opts.delayEnabled !== false,
				}
			);
			if (!decision.added) {
				status('Cycle ' + pass + '/' + maxScans + ': ' + errorCount + ' new runtime error(s) remain, but no further automatic safeguard can be applied.');
				cycleComplete(pass, 'no-automatic-fix');
				return {
					success: false,
					reason: 'no-automatic-fix',
					passes: pass,
					totalAdded,
					residualRuntimeErrors: errorCount,
					result,
					fixerScan,
					baselineResult,
					exclusionValue: cycleExclusions,
					forceValue: cycleForce,
					delayValue: cycleDelay,
				};
			}
			cycleExclusions = decision.exclusionValue;
			cycleForce = decision.forceValue;
			cycleDelay = decision.delayValue;
			const targetLabel = decision.target === 'delay'
				? 'Delay third-party JS Patterns'
				: (decision.target === 'force' ? 'Defer Instead of Delay' : 'Do Not Defer or Delay');
			if (!runtimeAutomaticDecisionIsVisiblePolicyOnly(decision)) {
				throw new Error('Runtime Scan refused a non-visible JavaScript policy write.');
			}
			status('Cycle ' + pass + '/' + maxScans + ': applying ' + decision.added + ' deterministic fix(es) to visible list ' + targetLabel + '…');
			const saved = await opts.saveSafeguards({
				exclusionValue: cycleExclusions,
				forceValue: cycleForce,
				delayValue: cycleDelay,
				decision,
				policyWrite: decision.policyWrite,
				pass,
				maxScans,
			});
			if (saved && saved.success === false) {
				throw new Error(saved.message ? String(saved.message) : 'JavaScript safeguards could not be saved');
			}
			totalAdded += decision.added;
			if (decision.reversibleDelayRepair && decision.rollbackState) {
				pendingDelayRepairTrial = { lines: (decision.lines || []).slice(), samples: (decision.trialSamples || []).slice(), rollbackState: Object.assign({}, decision.rollbackState) };
			}
			if (typeof opts.onSafeguardsApplied === 'function') {
				opts.onSafeguardsApplied({
					exclusionValue: cycleExclusions,
					forceValue: cycleForce,
					delayValue: cycleDelay,
					decision,
					pass,
					maxScans,
				});
			}
			cycleComplete(pass, 'fix-applied');
			status('Cycle ' + pass + '/' + maxScans + ': applied ' + decision.added + ' fix(es). Starting next cycle…');
		}

		return {
			success: false,
			reason: 'scan-limit',
			passes: maxScans,
			totalAdded,
			residualRuntimeErrors: lastResult ? Math.max(0, Number(lastResult.runtimeErrorCount || 0)) : 0,
			result: lastResult,
			baselineResult,
			fixerScan: lastFixerScan,
			exclusionValue: cycleExclusions,
			forceValue: cycleForce,
			delayValue: cycleDelay,
		};
		} finally {
			if (baselineStateActive && typeof opts.restoreBaselineState === 'function') {
				await opts.restoreBaselineState(baselineStateSnapshot);
			}
		}
	}

function formatRuntimeScanComparisonEntries(items) {
		const list = Array.isArray(items) ? items : [];
		if (!list.length) {
			return '(none)';
		}
		return list.map((item, index) => {
			const message = String(item && item.message ? item.message : '').trim();
			const source = runtimeScanErrorSourceIdentity(item);
			const signature = runtimeScanErrorSignature(item);
			return [
				'#' + String(index + 1),
				message ? 'Message: ' + message : 'Message: (empty)',
				source ? 'Source: ' + source : 'Source: (none)',
				'Signature: ' + signature,
			].join('\n');
		}).join('\n\n');
	}

function DeferDelayExclusionsField({ value, onSave, forceDeferValue, onForceDeferSave, onSaveBoth, delayPatternValue, delayPatternEnabled, functionalDelayPatternValue, functionalDelayPatternEnabled, onSaveLists, onSaveRuntimeSafeguards, disabled, placeholder, forceDeferPlaceholder, onPopulateDefaults, onPopulateDelayPatterns, onPopulateFunctionalDelayPatterns, onScan, onRuntimeScan, onRuntimeWindowPrepare, onRuntimeStrategyPrepare, onRuntimeCyclePrepare, onRuntimeBaselineBegin, onRuntimeBaselineRestore, debugBrowserScannerEnabled }) {
		const defaultScanUrl = (typeof ultracache !== "undefined" && ultracache && ultracache.frontendProbeUrl) ? String(ultracache.frontendProbeUrl || "") : "";
		const [draft, setDraft] = useState(value || "");
		const [forceDraft, setForceDraft] = useState(forceDeferValue || "");
		const [delayDraft, setDelayDraft] = useState(delayPatternValue || "");
		const [functionalDelayDraft, setFunctionalDelayDraft] = useState(functionalDelayPatternValue || "");
		const [delayPopulateBusy, setDelayPopulateBusy] = useState(false);
		const [functionalDelayPopulateBusy, setFunctionalDelayPopulateBusy] = useState(false);
		const [scanUrl, setScanUrl] = useState('');
		const [scan, setScan] = useState(null);
		const [populateBusy, setPopulateBusy] = useState(false);
		const [scanBusy, setScanBusy] = useState(false);
		const [scanStatus, setScanStatus] = useState('');
		const [scanProgress, setScanProgress] = useState(null);
		const [runtimeScanBusy, setRuntimeScanBusy] = useState(false);
		const [runtimeScanStatus, setRuntimeScanStatus] = useState('');
		const [runtimeScanProgressPercent, setRuntimeScanProgressPercent] = useState(0);
		const [runtimeScanModalOpen, setRuntimeScanModalOpen] = useState(false);
		const [runtimeScanAwaitingContinue, setRuntimeScanAwaitingContinue] = useState(false);
		const runtimeScanContinueResolverRef = useRef(null);
		const [runtimeScanContext, setRuntimeScanContext] = useState('anonymous');
		const [runtimeScanComparison, setRuntimeScanComparison] = useState(null);
		const [runtimeScanScripts, setRuntimeScanScripts] = useState([]);
		const [runtimeScanTargetResults, setRuntimeScanTargetResults] = useState([]);
		const [runtimeScanAggregateScan, setRuntimeScanAggregateScan] = useState(null);
		const [runtimeScanBaselineErrors, setRuntimeScanBaselineErrors] = useState([]);
		const [runtimeScanCurrentTarget, setRuntimeScanCurrentTarget] = useState(null);
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
			setDelayDraft(delayPatternValue || '');
		}, [delayPatternValue]);

		useEffect(() => {
			setFunctionalDelayDraft(functionalDelayPatternValue || '');
		}, [functionalDelayPatternValue]);

		useEffect(() => {
			if (!debugBrowserScannerEnabled) {
				setRuntimeScanComparison(null);
			}
		}, [debugBrowserScannerEnabled]);

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
		const currentDelayValue = String(delayPatternValue || '');
		const delayDraftValue = String(delayDraft || '');
		const currentFunctionalDelayValue = String(functionalDelayPatternValue || '');
		const functionalDelayDraftValue = String(functionalDelayDraft || '');
		const hasChanges = draftValue !== currentValue;
		const forceHasChanges = forceDraftValue !== currentForceValue;
		const delayHasChanges = delayDraftValue !== currentDelayValue;
		const functionalDelayHasChanges = functionalDelayDraftValue !== currentFunctionalDelayValue;
		const safeguardListsOverlap = normalizeSettingListLines(forceDraftValue).some((forceLine) => normalizeSettingListLines(draftValue).some((excludeLine) => settingLinesOverlap(forceLine, excludeLine)));
		const anyListChanges = hasChanges || forceHasChanges || delayHasChanges || functionalDelayHasChanges || safeguardListsOverlap;
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
		function suggestionDelayLine(item) {
			return String(item && item.delaySuggestion ? item.delaySuggestion : '').trim();
		}
		function suggestionInDelay(item) {
			const line = suggestionDelayLine(item);
			return !!line && (!!(item && item.delaySuggestionAlreadyListed) || isSuggestionPresentInDraft(delayDraftValue, line));
		}
		function suggestionPreferredTarget(item) {
			const target = item && item.preferredTarget ? String(item.preferredTarget).toLowerCase() : '';
			if (item && item.fallbackRecommended && !item.alreadyExcluded) {
				return 'exclusion';
			}
			return target === 'force' || target === 'exclusion' ? target : '';
		}
		function suggestionEffectiveTarget(item) {
			const delayLine = suggestionDelayLine(item);
			const delayWasAlreadyListedAtScan = !!(item && item.delaySuggestionAlreadyListed);
			const delayCoveredByStrongerList = !!delayLine && (isSuggestionPresentInDraft(forceDraftValue, delayLine) || isSuggestionPresentInDraft(draftValue, delayLine));
			const reversibleDelayRepair = !!(item && item.delayRepairRecommended && delayCoveredByStrongerList);
			if (delayPatternEnabled !== false && delayLine && !delayWasAlreadyListedAtScan && (!delayCoveredByStrongerList || reversibleDelayRepair)) {
				return 'delay';
			}
			return suggestionPreferredTarget(item);
		}
		function suggestionPrefersExclusion(item) {
			return suggestionPreferredTarget(item) === 'exclusion';
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
		const consoleSuggestions = consoleErrorScan && Array.isArray(consoleErrorScan.suggestions) ? consoleErrorScan.suggestions : [];
		const consoleActionableSuggestions = consoleSuggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored);
		const consolePersistentFailures = consoleActionableSuggestions.filter((item) => !!(item && item.stillFailingWhileListed));
		const consoleDependencyRisks = consoleActionableSuggestions.filter((item) => item && item.source === 'page-dependency-analysis');
		const consoleAppendableSuggestions = consoleActionableSuggestions.filter((item) => item.appendable !== false && !item.alreadyExcluded);
		const consoleReviewOnlySuggestions = consoleActionableSuggestions.filter((item) => item.appendable === false && !item.alreadyExcluded && !item.stillFailingWhileListed);
		const missingConsoleDelaySuggestions = consoleAppendableSuggestions
			.filter((item) => suggestionEffectiveTarget(item) === 'delay' && !suggestionInDelay(item))
			.map((item) => suggestionDelayLine(item))
			.filter((line, index, lines) => line && lines.indexOf(line) === index);
		const missingConsoleErrorSuggestions = consoleAppendableSuggestions
			.filter((item) => suggestionEffectiveTarget(item) === 'force' && !suggestionInForce(item) && !suggestionInFallback(item))
			.map((item) => suggestionLine(item))
			.filter((line, index, lines) => line && lines.indexOf(line) === index);
		const consoleFallbackSuggestions = consoleAppendableSuggestions
			.filter((item) => suggestionEffectiveTarget(item) === 'exclusion' && !suggestionInFallback(item))
			.map((item) => suggestionLine(item))
			.filter((line, index, lines) => line && lines.indexOf(line) === index);
		const missingConsoleReviewOnlySuggestions = consoleReviewOnlySuggestions.filter((item) => !suggestionInFallback(item) && !suggestionInForce(item));
		const runtimeAggregateSuggestions = runtimeScanAggregateScan && Array.isArray(runtimeScanAggregateScan.suggestions) ? runtimeScanAggregateScan.suggestions : [];
		const runtimeAggregateNeedsAction = runtimeAggregateSuggestions.filter((item) => {
			if (!item || item.appendable === false || item.ignored || item.confidence === 'ignored') {
				return false;
			}
			const target = suggestionEffectiveTarget(item);
			if (target === 'delay') {
				return !suggestionInDelay(item) && !suggestionInForce(item) && !suggestionInFallback(item);
			}
			if (target === 'force') {
				return !suggestionInForce(item) && !suggestionInFallback(item);
			}
			if (target === 'exclusion') {
				return !suggestionInFallback(item);
			}
			return !suggestionInForce(item) && !suggestionInFallback(item);
		});
		const runtimeAggregateAlreadyApplied = runtimeAggregateSuggestions.filter((item) => item && !item.ignored && (
			suggestionInFallback(item) || suggestionInForce(item) || suggestionInDelay(item) || item.alreadyExcluded || item.alreadyForceDeferred
		));
		const runtimeAggregateReviewOnly = runtimeAggregateSuggestions.filter((item) => item && !item.ignored && item.appendable === false && runtimeAggregateAlreadyApplied.indexOf(item) === -1);
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
			const policyWrite = runtimeVisiblePolicyDescriptor(target);
			if (!line || !policyWrite) {
				return;
			}
			if (policyWrite.target === 'force') {
				appendToForceDraft(line);
			} else if (policyWrite.target === 'exclusion') {
				appendToExclusionDraft(line);
			} else if (policyWrite.target === 'delay') {
				appendToDelayDraft(line);
			}
			setSelectedSuggestionActions((current) => Object.assign({}, current || {}, { [actionKey]: actionId }));
		}

		function renderSuggestionActionButtons(item, keyPrefix, index, allowAppend, options) {
			if (!allowAppend || !item) {
				return null;
			}
			const opts = options || {};
			const recommendedOnly = !!opts.recommendedOnly;
			const effectiveTarget = suggestionEffectiveTarget(item);
			if (recommendedOnly && (
				(effectiveTarget === 'delay' && suggestionInDelay(item))
				|| (effectiveTarget === 'force' && (suggestionInForce(item) || suggestionInFallback(item)))
				|| (effectiveTarget === 'exclusion' && suggestionInFallback(item))
			)) {
				return null;
			}
			const patterns = getSuggestionActionPatterns(item);
			const delayPattern = normalizeSuggestionActionPattern(suggestionDelayLine(item));
			const actionKey = getSuggestionActionKey(item, keyPrefix, index);
			const selected = String(selectedSuggestionActions[actionKey] || '');
			let actions = [
				{ id: 'delay-exact', target: 'delay', pattern: delayPattern, label: (suggestionInFallback(item) || suggestionInForce(item)) ? __('Move to Delay', 'ultracache') : __('Add to Delay', 'ultracache') },
				{ id: 'force-exact', target: 'force', pattern: patterns.exact, label: __('Defer Instead', 'ultracache') },
				{ id: 'force-chain', target: 'force', pattern: patterns.chain, label: __('Defer Chain', 'ultracache') },
				{ id: 'exclude-exact', target: 'exclusion', pattern: patterns.exact, label: __('Add to Do Not Defer or Delay', 'ultracache') },
				{ id: 'exclude-chain', target: 'exclusion', pattern: patterns.chain, label: __('Exclude Chain', 'ultracache') },
			].filter((action) => action.target !== 'delay' || !!action.pattern);
			if (recommendedOnly && !selected) {
				if (effectiveTarget === 'delay') {
					actions = actions.filter((action) => action.id === 'delay-exact');
				} else if (effectiveTarget === 'force') {
					actions = actions.filter((action) => action.id === 'force-exact');
				} else if (effectiveTarget === 'exclusion') {
					actions = actions.filter((action) => action.id === 'exclude-exact');
				} else {
					// A ready-to-append finding must never render with no action.
					// When runtime evidence cannot choose one strategy safely, expose
					// only the two exact actions and leave both un-recommended.
					actions = actions.filter((action) => action.id === 'force-exact' || action.id === 'exclude-exact');
				}
			}
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

		function appendToDelayDraft(lines) {
			const normalizedLines = normalizeSettingListLines(Array.isArray(lines) ? lines.join('\n') : lines);
			if (!normalizedLines.length) {
				return { added: 0, removedForce: 0, removedExclusions: 0 };
			}
			const merged = mergeUniqueSettingLines(delayDraftValue, normalizedLines);
			const cleanedForce = removeOverlappingSettingLines(forceDraftValue, normalizedLines);
			const cleanedExclusions = removeOverlappingSettingLines(draftValue, normalizedLines);
			setDelayDraft(merged.value);
			if (cleanedForce.value !== forceDraftValue) {
				setForceDraft(cleanedForce.value);
			}
			if (cleanedExclusions.value !== draftValue) {
				setDraft(cleanedExclusions.value);
			}
			setLastEditedSafeguardList('delay');
			return { added: merged.added, removedForce: cleanedForce.removed, removedExclusions: cleanedExclusions.removed };
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
			const providerLine = item && item.suggestedExclusion ? String(item.suggestedExclusion) : '';
			const delayLine = suggestionDelayLine(item);
			const effectiveTarget = suggestionEffectiveTarget(item);
			const line = effectiveTarget === 'delay' && delayLine ? delayLine : providerLine;
			const readOnly = !!(options && options.readOnly);
			const fallbackPresent = suggestionInFallback(item);
			const forcePresent = suggestionInForce(item);
			const delayPresent = suggestionInDelay(item);
			let canAppend = !readOnly && item && (item.appendable !== false || item.delayRepairRecommended) && !!line;
			if (effectiveTarget === 'delay') {
				canAppend = canAppend && !delayPresent;
			} else if (effectiveTarget === 'force') {
				canAppend = canAppend && !forcePresent && !fallbackPresent;
			} else if (effectiveTarget === 'exclusion') {
				canAppend = canAppend && !fallbackPresent;
			}

			let status = 'ready to append';
			let statusClass = 'text-[11px] font-semibold text-zinc-300';
			if (readOnly) {
				status = delayPresent ? 'already listed in Delay third-party JS' : ((fallbackPresent || forcePresent) ? 'already listed' : 'read only');
			} else if (effectiveTarget === 'delay') {
				status = delayPresent ? 'in Delay third-party JS · save/purge/rescan' : 'ready to append to Delay third-party JS';
				statusClass = delayPresent ? 'text-[11px] font-semibold text-emerald-300' : 'text-[11px] font-semibold text-amber-300';
			} else if (fallbackPresent) {
				status = 'in "Do Not Defer or Delay"';
				statusClass = 'text-[11px] font-semibold text-emerald-300';
			} else if (forcePresent) {
				status = 'in Defer Instead · can append to "Do Not Defer or Delay"';
				statusClass = 'text-[11px] font-semibold text-amber-300';
			}

			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-3 space-y-2', key: keyPrefix + '-' + index + '-' + line }, [
				h('div', { className: 'flex flex-wrap items-center gap-2' }, [
					h('code', { className: 'font-mono text-[11px] text-emerald-300 break-all bg-black/25 rounded px-2 py-1.5' }, line || 'unknown'),
					renderSuggestionActionButtons(item, keyPrefix, index, canAppend, { recommendedOnly: !!(options && options.recommendedOnly) }),
				]),
				h('div', { className: 'grid grid-cols-1 sm:grid-cols-3 gap-2' }, [
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Status'), h('div', { className: statusClass }, status)]),
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Confidence'), h('div', { className: 'text-[11px] font-semibold text-zinc-300' }, item && item.confidence ? String(item.confidence) : '—')]),
					h('div', { className: 'rounded bg-black/15 px-2 py-1' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Type'), h('div', { className: 'text-[11px] font-semibold text-violet-300' }, item && item.categoryLabel ? String(item.categoryLabel) : (options && options.title ? String(options.title) : 'Diagnostic result'))]),
				]),
				item && Array.isArray(item.detectedOn) && item.detectedOn.length ? h('div', { className: 'text-[11px] text-sky-300' }, 'Detected on: ' + item.detectedOn.join(', ')) : null,
				item && item.reason ? h('div', { className: 'text-zinc-400 leading-relaxed pt-1' }, item.reason) : null,
				item && item.sample ? h('div', { className: 'text-zinc-500 leading-relaxed break-all bg-black/15 rounded px-2 py-1.5' }, [h('span', { className: 'text-zinc-400 font-semibold' }, __('Sample: ', 'ultracache')), String(item.sample)]) : null,
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
			if (/complianz|cmplz|real-cookie-banner|devowl|\brcb-|wp-consent-api|wp_consent/.test(text)) {
				return { key: 'consent-control-plane', title: __("Consent-management scripts", 'ultracache'), reason: 'Consent-management assets were detected. They follow the configured JavaScript strategy and visible Defer Instead / Do Not Defer or Delay lists.' };
			}
			if (/cookieyes|cookielawinfo|cky-|cookiebot|iubenda|onetrust|optanon/.test(text)) {
				return { key: 'consent-management', title: __("Cookie / consent management", 'ultracache'), reason: 'Cookie/consent-management assets were detected. They follow the configured JavaScript strategy and visible Defer Instead / Do Not Defer or Delay lists.' };
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
				h('div', { className: 'text-[11px] text-zinc-500 mt-2 mb-2' }, __("These are network/resource load failures. JavaScript resource failures invalidate the Runtime Scan because the page did not execute the same script set. If Chrome shows ERR_BLOCKED_BY_CLIENT, disable any ad blocker or content-blocking extension for this site and try again.", 'ultracache')),
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

		async function requestConsoleErrorFixer(input, targetUrl, scanContext, runtimeScripts) {
			const response = await requestRuntimeJsConsoleFixer(input, targetUrl || scanUrl || defaultScanUrl, scanContext || runtimeScanContext, runtimeScripts || []);
			return applyJsDiagnosticQueueResponse(response);
		}

		async function runConsoleErrorFixer(input, targetUrl, scanContext, statusPrefix, runtimeScripts) {
			const text = String(input || '');
			if (!text.trim()) {
				setConsoleErrorSuggestions([]);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('Paste one or more browser console errors first.');
				return null;
			}
			setSelectedSuggestionActions({});
			setConsoleErrorBusy(true);
			setConsoleErrorStatus(statusPrefix || 'Parsing console errors with the JavaScript Error Fixer…');
			try {
				const job = await requestConsoleErrorFixer(text, targetUrl, scanContext, runtimeScripts || []);
				const scan = job && job.result && job.result.dashboardScan ? job.result.dashboardScan : null;
				const extracted = getJsDelaySafetySuggestions(scan);
				const reviewOnly = getJsDelayReviewSuggestions(scan);
				const appendableItems = scan && Array.isArray(scan.suggestions) ? scan.suggestions.filter((item) => item && item.suggestedExclusion && item.confidence !== 'ignored' && !item.ignored && item.appendable !== false && !item.alreadyExcluded) : [];
				const delayMissing = appendableItems.filter((item) => suggestionEffectiveTarget(item) === 'delay' && !suggestionInDelay(item));
				const forceMissing = appendableItems.filter((item) => suggestionEffectiveTarget(item) === 'force' && !suggestionInForce(item) && !suggestionInFallback(item));
				const exclusionMissing = appendableItems.filter((item) => suggestionEffectiveTarget(item) === 'exclusion' && !suggestionInFallback(item));
				const exactChoiceMissing = appendableItems.filter((item) => !suggestionEffectiveTarget(item) && !suggestionInForce(item) && !suggestionInFallback(item));
				const escalationCount = exclusionMissing.filter((item) => suggestionInForce(item)).length;
				setConsoleErrorSuggestions(extracted);
				setConsoleErrorScan(scan || null);
				const persistentCount = scan && typeof scan.persistentListedFailureCount !== 'undefined' ? Number(scan.persistentListedFailureCount || 0) : 0;
				const dependencyRiskCount = scan && typeof scan.dependencyRiskCount !== 'undefined' ? Number(scan.dependencyRiskCount || 0) : 0;
				if (!extracted.length && !reviewOnly.length && !persistentCount && !dependencyRiskCount) {
					setConsoleErrorStatus('No Runtime Scan suggestions were detected. UltraCache only reports exact paths/handles resolved from the error, the page dependency graph, or scanned local JS sources.');
				} else {
					setConsoleErrorStatus('Detected ' + extracted.length + ' appendable Runtime Scan suggestion(s): ' + delayMissing.length + ' Delay third-party JS, ' + forceMissing.length + ' Defer Instead, ' + exclusionMissing.length + ' Do Not Defer or Delay' + (exactChoiceMissing.length ? (', ' + exactChoiceMissing.length + ' exact-choice finding(s) requiring manual strategy selection') : '') + (dependencyRiskCount ? (', ' + dependencyRiskCount + ' page/file dependency risk(s)') : '') + (persistentCount ? (', and ' + persistentCount + ' already-listed script(s) where the runtime error still persists') : '') + (reviewOnly.length ? (', plus ' + reviewOnly.length + ' review-only candidate(s)') : '') + (escalationCount ? ('; ' + escalationCount + ' already in Defer Instead require escalation to Do Not Defer or Delay.') : '') + '. Review the stored result below, append the appropriate exact fixes, then save and purge cache.');
				}
				return scan;
			} catch (error) {
				setConsoleErrorSuggestions([]);
				setConsoleErrorScan(null);
				setConsoleErrorStatus('JavaScript Error Fixer failed. ' + (error && error.message ? String(error.message) : ''));
				throw error;
			} finally {
				setConsoleErrorBusy(false);
			}
		}

		async function handleExtractConsoleErrors() {
			const input = String(consoleErrorInput || '');
			setRuntimeScanAggregateScan(null);
			setRuntimeScanBaselineErrors([]);
			try {
				await runConsoleErrorFixer(input, scanUrl || defaultScanUrl, runtimeScanContext, 'Parsing console errors with the JavaScript Error Fixer…', runtimeScanScripts);
			} catch (error) {
				// Status is set by the shared Error Fixer runner.
			}
		}

		function handleAppendConsoleDelay() {
			const lines = missingConsoleDelaySuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'No missing console-error fix currently recommends Delay third-party JS.' : 'Extract console error suggestions before appending.');
				return;
			}
			const moved = appendToDelayDraft(lines);
			setConsoleErrorStatus(moved.added || moved.removedForce || moved.removedExclusions ? ('Appended ' + moved.added + ' console-error fix(es) to Delay third-party JS Patterns.' + ((moved.removedForce || moved.removedExclusions) ? ' Removed stronger overlapping safeguards so Delay can be tested first.' : '')) : 'All Delay-first console-error fixes are already listed.');
		}

		function handleAppendConsoleErrors() {
			const lines = missingConsoleErrorSuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'No missing console-error fix currently recommends Defer Instead.' : 'Extract console error suggestions before appending.');
				return;
			}
			const moved = appendToForceDraft(lines);
			setConsoleErrorStatus(moved.added || moved.removed ? ('Appended ' + moved.added + ' console-error fix(es) to Defer Instead of Delay' + (moved.removed ? (' and removed ' + moved.removed + ' overlap(s) from Do Not Defer or Delay') : '') + '.') : 'All extracted console-error fixes are already listed.');
		}

		function handleAppendConsoleFallbacks() {
			const lines = consoleFallbackSuggestions;
			if (!lines.length) {
				setConsoleErrorStatus(consoleErrorSuggestions.length ? 'No missing console-error fix currently recommends Do Not Defer or Delay.' : 'Extract console error suggestions before appending to "Do Not Defer or Delay".');
				return;
			}
			const moved = appendToExclusionDraft(lines);
			setConsoleErrorStatus(moved.added || moved.removed ? ('Appended ' + moved.added + ' console-error item(s) to "Do Not Defer or Delay"' + (moved.removed ? (' and removed ' + moved.removed + ' overlap(s) from Defer Instead') : '') + '.') : 'No missing console-error fix currently recommends Do Not Defer or Delay.');
		}

		function handleClearConsoleErrors() {
			setConsoleErrorInput('');
			setConsoleErrorSuggestions([]);
			setConsoleErrorScan(null);
			setConsoleErrorStatus('');
			setRuntimeScanScripts([]);
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
				const htmlScanUrl = String(scanUrl || defaultScanUrl || '').trim();
				const result = await onScan(htmlScanUrl, function(progress, message) {
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
			if (disabled || runtimeScanBusy || typeof onRuntimeScan !== 'function' || typeof onRuntimeCyclePrepare !== 'function') {
				return;
			}

			let runtimeBrowserWindow = null;
			if (typeof onRuntimeWindowPrepare === 'function') {
				runtimeBrowserWindow = onRuntimeWindowPrepare();
				if (runtimeBrowserWindow && runtimeBrowserWindow.unsupported) {
					setRuntimeScanStatus(runtimeBrowserWindow.message || 'Runtime Scan cycle failed. This browser does not support an isolated anonymous scanner frame.');
					setRuntimeScanModalOpen(true);
					return;
				}
			}

			setSelectedSuggestionActions({});
			setRuntimeScanComparison(null);
			setRuntimeScanScripts([]);
			setRuntimeScanTargetResults([]);
			setRuntimeScanAggregateScan(null);
			setRuntimeScanBaselineErrors([]);
			setRuntimeScanCurrentTarget(null);
			setConsoleErrorInput('');
			setConsoleErrorScan(null);
			setConsoleErrorSuggestions([]);
			setConsoleErrorStatus('');
			setJsDiagnosticQueue(null);
			setRuntimeScanAwaitingContinue(false);
			runtimeScanContinueResolverRef.current = null;
			setRuntimeScanStatus('Preparing Runtime Scan targets…');
			setRuntimeScanProgressPercent(0);
			setRuntimeScanModalOpen(true);
			setRuntimeScanBusy(true);

			try {
				const siteOutcome = await runRuntimeSiteScanAction({
					manualScanUrl: String(scanUrl || '').trim(),
					defaultScanUrl: String(defaultScanUrl || '').trim(),
					scanContext: runtimeScanContext,
					maxScans: 10,
					preferDeferForAmbiguous: true,
					browserWindow: runtimeBrowserWindow,
					exclusionValue: draftValue,
					forceValue: forceDraftValue,
					delayValue: delayDraftValue,
					delayEnabled: delayPatternEnabled !== false,
					prepareStrategySafeguards: typeof onRuntimeStrategyPrepare === 'function' ? onRuntimeStrategyPrepare : null,
					onStrategySafeguardsPrepared: function(strategyState) {
						if (!strategyState || typeof strategyState !== 'object' || !strategyState.changed) {
							return;
						}
						setDraft(String(strategyState.exclusionValue || ''));
						setForceDraft(String(strategyState.forceValue || ''));
						setDelayDraft(String(strategyState.delayValue || ''));
						setLastEditedSafeguardList('');
					},
					prepare: onRuntimeCyclePrepare,
					beginBaselineState: typeof onRuntimeBaselineBegin === 'function' ? onRuntimeBaselineBegin : null,
					restoreBaselineState: typeof onRuntimeBaselineRestore === 'function' ? onRuntimeBaselineRestore : null,
					afterBaselineCaptured: runtimeBrowserWindow && runtimeBrowserWindow.enabled ? async function(baselineResult, targetMeta) {
						setRuntimeScanStatus(String(targetMeta.targetPrefix || 'Runtime Scan') + ' · baseline captured. Inspect the visible Browser Scanner frame, then click Continue.');
						setRuntimeScanAwaitingContinue(true);
						await new Promise(function(resolve) {
							runtimeScanContinueResolverRef.current = resolve;
						});
						runtimeScanContinueResolverRef.current = null;
						setRuntimeScanAwaitingContinue(false);
					} : null,
					scan: onRuntimeScan,
					runFixer: runConsoleErrorFixer,
					saveSafeguards: async function(nextState) {
						const state = nextState && typeof nextState === 'object' ? nextState : {};
						if (typeof onSaveRuntimeSafeguards === 'function') {
							return onSaveRuntimeSafeguards(
								String(state.exclusionValue || ''),
								String(state.forceValue || ''),
								String(state.delayValue || ''),
								state.decision && typeof state.decision === 'object' ? state.decision : null
							);
						}
						if (typeof onSaveBoth !== 'function') {
							throw new Error('JavaScript safeguard save handler is unavailable');
						}
						return onSaveBoth(String(state.exclusionValue || ''), String(state.forceValue || ''));
					},
					onConsoleText: function(consoleText, result, pass, targetMeta) {
						setRuntimeScanComparison(debugBrowserScannerEnabled && result && typeof result === 'object' ? {
							pass: Math.max(1, Number(pass || 1)),
							baselineErrors: Array.isArray(result.baselineErrors) ? result.baselineErrors.slice() : [],
							capturedErrors: Array.isArray(result.capturedErrors) ? result.capturedErrors.slice() : [],
							differentialErrors: Array.isArray(result.errors) ? result.errors.slice() : [],
						} : null);
						setConsoleErrorInput(consoleText);
						setRuntimeScanScripts(result && Array.isArray(result.scripts) ? result.scripts.slice(0, 240) : []);
						if (!consoleText.trim()) {
							const baselineCount = result ? Math.max(0, Number(result.baselineRuntimeErrorCount || 0)) : 0;
							const capturedCount = result ? Math.max(0, Number(result.capturedRuntimeErrorCount || 0)) : 0;
							setConsoleErrorStatus(String(targetMeta.targetPrefix || 'Runtime Scan') + ': captured ' + capturedCount + ' runtime error(s); 0 were new after baseline comparison and ' + baselineCount + ' baseline error(s) were excluded from automatic repair.');
						}
					},
					onSafeguardsApplied: function(state) {
						setDraft(String(state && state.exclusionValue || ''));
						setForceDraft(String(state && state.forceValue || ''));
						setDelayDraft(String(state && state.delayValue || ''));
						setLastEditedSafeguardList('');
					},
					onTargetStart: function(targetMeta) {
						const meta = targetMeta && typeof targetMeta === 'object' ? targetMeta : {};
						const target = meta.target && typeof meta.target === 'object' ? meta.target : {};
						setRuntimeScanCurrentTarget({
							label: String(target.label || 'Page'),
							url: String(target.url || ''),
							number: Math.max(1, Number(meta.targetNumber || 1)),
							count: Math.max(1, Number(meta.targetCount || 1)),
						});
					},
					onTargetResults: function(results) {
						setRuntimeScanTargetResults(results);
					},
					onStatus: function(message) {
						setRuntimeScanStatus(String(message || ''));
					},
					onProgress: function(progressState) {
						const nextPercent = progressState && typeof progressState === 'object' ? Number(progressState.percent || 0) : 0;
						setRuntimeScanProgressPercent(Math.round(Math.max(0, Math.min(100, nextPercent)) * 10) / 10);
					},
				});

				setRuntimeScanBaselineErrors(siteOutcome && Array.isArray(siteOutcome.baselineErrors) ? siteOutcome.baselineErrors.slice() : []);
				if (siteOutcome && siteOutcome.mergedFixerScan) {
					setRuntimeScanAggregateScan(siteOutcome.mergedFixerScan);
					setConsoleErrorScan(siteOutcome.mergedFixerScan);
					setConsoleErrorSuggestions(getJsDelaySafetySuggestions(siteOutcome.mergedFixerScan));
					setConsoleErrorStatus('Runtime Site Scan aggregated ' + String(siteOutcome.mergedFixerScan.suggestions.length) + ' unique JavaScript finding(s) across the scanned targets.' + (siteOutcome.baselineErrorCount ? (' ' + String(siteOutcome.baselineErrorCount) + ' pre-existing baseline error(s) are shown separately and are not auto-fixed.') : ''));
				} else {
					setRuntimeScanAggregateScan(null);
					setConsoleErrorScan(null);
					setConsoleErrorSuggestions([]);
					setJsDiagnosticQueue(null);
					setConsoleErrorStatus('Runtime Site Scan aggregated 0 JavaScript findings across the scanned targets.' + (siteOutcome && siteOutcome.baselineErrorCount ? (' ' + String(siteOutcome.baselineErrorCount) + ' pre-existing baseline error(s) are shown separately and are not auto-fixed.') : ''));
				}

				setRuntimeScanStatus(
					siteOutcome && siteOutcome.summaryMessage
						? String(siteOutcome.summaryMessage)
						: 'Runtime Site Scan completed.'
				);
			} catch (error) {
				setRuntimeScanStatus('Runtime Scan cycle failed. ' + (error && error.message ? String(error.message) : ''));
			} finally {
				runtimeScanContinueResolverRef.current = null;
				setRuntimeScanAwaitingContinue(false);
				setRuntimeScanBusy(false);
			}
		}

		function handleRuntimeScanContinue() {
			const resolve = runtimeScanContinueResolverRef.current;
			if (!runtimeScanAwaitingContinue || typeof resolve !== 'function') {
				return;
			}
			runtimeScanContinueResolverRef.current = null;
			setRuntimeScanAwaitingContinue(false);
			const debugFrame = document.getElementById('ultracache-runtime-js-debug-frame');
			if (debugFrame && debugFrame.parentNode) {
				debugFrame.parentNode.removeChild(debugFrame);
			}
			setRuntimeScanStatus('Restoring JavaScript optimizations and continuing Runtime Scan…');
			resolve();
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

		async function handlePopulatePatternList(kind) {
			const isFunctional = kind === 'functional';
			const handler = isFunctional ? onPopulateFunctionalDelayPatterns : onPopulateDelayPatterns;
			const currentDraft = isFunctional ? functionalDelayDraftValue : delayDraftValue;
			const setBusy = isFunctional ? setFunctionalDelayPopulateBusy : setDelayPopulateBusy;
			const setValue = isFunctional ? setFunctionalDelayDraft : setDelayDraft;
			const warning = isFunctional
				? 'Your current known functional third-party delay patterns will be replaced with the recommended defaults.'
				: 'Your current third-party delay patterns will be replaced with the recommended defaults.';

			if (typeof handler !== 'function' || disabled) {
				return;
			}
			if (currentDraft.trim() && typeof window !== 'undefined' && typeof window.confirm === 'function' && !window.confirm(warning)) {
				return;
			}
			setBusy(true);
			try {
				const populatedValue = await handler(currentDraft);
				if (typeof populatedValue === 'string') {
					setValue(populatedValue);
				}
			} finally {
				setBusy(false);
			}
		}

		function handleSaveLists() {
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
			if (typeof onSaveLists === 'function') {
				if (
					nextForceValue !== currentForceValue
					|| nextExclusionValue !== currentValue
					|| delayDraftValue !== currentDelayValue
					|| functionalDelayDraftValue !== currentFunctionalDelayValue
				) {
					onSaveLists(nextExclusionValue, nextForceValue, delayDraftValue, functionalDelayDraftValue);
				}
				return;
			}
			if (typeof onSaveBoth === 'function' && (nextForceValue !== currentForceValue || nextExclusionValue !== currentValue)) {
				onSaveBoth(nextExclusionValue, nextForceValue);
			}
			if (typeof onForceDeferSave === 'function' && nextForceValue !== currentForceValue) {
				onForceDeferSave(nextForceValue);
			}
			if (typeof onSave === 'function' && nextExclusionValue !== currentValue) {
				onSave(nextExclusionValue);
			}
		}

		const runtimeStatusPercent = Math.round(Math.max(0, Math.min(100, Number(runtimeScanProgressPercent || 0))) * 10) / 10;
		const runtimeStatusPercentLabel = Number.isInteger(runtimeStatusPercent) ? String(runtimeStatusPercent) : runtimeStatusPercent.toFixed(1);
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
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 uc-exclusions-grid', key: 'js-delay-patterns-grid', style: { marginTop: '16px' } }, [
				h('div', { className: 'uc-field-wrap', key: 'delay-third-party-patterns' }, [
					h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Delay third-party JS Patterns', 'ultracache'), __("What it does: defines the user-editable matching fragments used by Delay third-party JS.\n\nWhy it helps: matching analytics, pixels, ads, tracking, and marketing scripts can stay out of the initial critical loading window.\n\nWatch for: these patterns select Delay candidates; Defer Instead of Delay and Do Not Defer or Delay remain stronger safeguards.", 'ultracache'))),
					h('div', { className: 'text-xs text-zinc-500 mb-2' }, __('User-editable matching fragments for scripts already printed by the site, theme, or another plugin.', 'ultracache')),
					h('textarea', {
						className: 'uc-field-input uc-field-textarea',
						value: delayDraft,
						disabled: !!disabled || delayPatternEnabled === false,
						placeholder: 'googletagmanager.com\ngoogle-analytics.com\nconnect.facebook.net\nclarity.ms',
						onChange: (e) => setDelayDraft(e.target.value),
					}),
					h('div', { className: 'mt-3 flex items-center justify-start gap-3' }, [
						h(Button, {
							onClick: () => handlePopulatePatternList('delay'),
							disabled: !!disabled || delayPatternEnabled === false || delayPopulateBusy,
						}, delayPopulateBusy ? __('Populating…', 'ultracache') : __('Populate Defaults', 'ultracache')),
					]),
				]),
				h('div', { className: 'uc-field-wrap', key: 'delay-functional-third-party-patterns' }, [
					h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Known Functional Third-Party Delay Patterns', 'ultracache'), __("What it does: defines matching fragments for third-party scripts that provide visible functionality.\n\nWhy it helps: cookie banners, captcha, maps, chat, booking, embedded forms, opt-in popups, newsletter widgets, and similar scripts can be delayed when the site tolerates it.\n\nWatch for: move a script to a stronger safeguard if the visible functionality must run earlier.", 'ultracache'))),
					h('div', { className: 'text-xs text-zinc-500 mb-2' }, __('User-editable matching fragments for scripts already printed by the site, theme, or another plugin. Matching consent, captcha, maps, chat, booking, embedded form, opt-in popup, newsletter, and widget scripts are delayed unless excluded.', 'ultracache')),
					h('textarea', {
						className: 'uc-field-input uc-field-textarea',
						value: functionalDelayDraft,
						disabled: !!disabled || functionalDelayPatternEnabled === false,
						placeholder: 'recaptcha\nhcaptcha\nmaps.googleapis.com\ncomplianz\ncmplz',
						onChange: (e) => setFunctionalDelayDraft(e.target.value),
					}),
					h('div', { className: 'mt-3 flex items-center justify-start gap-3' }, [
						h(Button, {
							onClick: () => handlePopulatePatternList('functional'),
							disabled: !!disabled || functionalDelayPatternEnabled === false || functionalDelayPopulateBusy,
						}, functionalDelayPopulateBusy ? __('Populating…', 'ultracache') : __('Populate Defaults', 'ultracache')),
					]),
				]),
			]),
			h('div', { className: 'flex flex-wrap items-center', style: { marginTop: '12px', gap: '12px' } }, [
				h(Button, { key: 'save-lists', onClick: handleSaveLists, disabled: !!disabled || !anyListChanges, variant: 'primary' }, __('Save Lists', 'ultracache')),
			]),
			h('div', { className: 'flex flex-wrap items-center', style: { marginTop: '10px', gap: '12px' } }, [
				h(Button, { key: 'defaults', onClick: handlePopulateDefaults, disabled: !!disabled || populateBusy }, populateBusy ? 'Appending…' : 'Append Broad WP Dependency Preset'),
				h(Button, { key: 'scan', onClick: handleScan, disabled: !!disabled || scanBusy }, scanBusy ? 'Analyzing…' : 'Analyze HTML JS Dependencies'),
				h(Button, { key: 'append-suggestions', onClick: handleAppendSuggestions, disabled: !!disabled || !suggestionMissingCount }, 'Append to Defer Instead' + (suggestionMissingCount ? ' (' + suggestionMissingCount + ')' : '')),
				h(Button, { key: 'append-fallbacks', onClick: handleAppendFallbackSuggestions, disabled: !!disabled || !fallbackMissingCount }, 'Append to "Do Not Defer or Delay"' + (fallbackMissingCount ? ' (' + fallbackMissingCount + ')' : '')),

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
						h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Browser Scanner', 'ultracache'), __("What it does: checks a real frontend page. HTML analysis reads the final markup, while Runtime Scan opens the page like a browser and watches console/runtime errors.\n\nWhy it helps: UltraCache can see which scripts were actually printed on that page instead of guessing from a generic list.\n\nWatch for: Runtime Scan can automatically apply only deterministic JavaScript Error Fixer actions during its bounded repair cycle.", 'ultracache'))),
						runtimeScanBusy ? h('span', { className: 'text-sky-300 font-mono text-[11px]' }, 'running') : null,
					]),
					h('div', { className: 'text-xs text-zinc-500 mb-3 leading-relaxed' }, 'Analyze reads final HTML for one page. Runtime Scan leaves this field blank for an automatic site scan: front page, one random published page, WooCommerce shop, and one random published product when available. Enter a URL to scan only that page. Every target gets its own unoptimized baseline before bounded automatic JavaScript Error Fixer cycles.'),
					h('label', { className: 'uc-field-label', style: { fontSize: '12px', color: '#6f7b8f' } }, renderLabelWithHelp(__('Page URL to scan', 'ultracache'), __("What it does: leave blank for the automatic site scan, or enter one exact frontend URL to scan only that page.\n\nWhy it helps: the automatic scan covers the front page, a random published page, and WooCommerce shop/product templates when available.\n\nWatch for: entering any URL switches Runtime Scan to single-page mode.", 'ultracache'))),
					h('input', {
						type: 'url',
						className: 'uc-field-input',
						value: scanUrl,
						disabled: !!disabled || scanBusy || runtimeScanBusy,
						placeholder: defaultScanUrl || 'https://example.com/page/',
						onChange: (e) => setScanUrl(e.target.value),
					}),
					h('div', { className: 'text-[11px] text-zinc-500', style: { marginTop: '10px' } }, [
						h('span', { className: 'text-zinc-400' }, __('Runtime Scan context: Anonymous frontend · isolated cookieless browser frame.', 'ultracache')),
					]),
					h('div', { className: 'text-[11px] text-sky-300', style: { marginTop: '6px' } }, scanUrl.trim() ? 'Manual scan: this URL only.' : 'Automatic site scan: Front page + random Page + Shop + random Product when available.'),
					runtimeScanTargetResults.length ? h('div', { className: 'mt-3 space-y-1' }, runtimeScanTargetResults.map((item, index) => h('div', { className: 'rounded bg-black/15 px-2 py-1 text-[11px]', key: 'runtime-target-' + index }, [
						h('span', { className: item.status === 'failed' ? 'text-red-300 font-semibold' : (item.status === 'unresolved' ? 'text-amber-300 font-semibold' : 'text-emerald-300 font-semibold') }, String(item.label || 'Page') + ': ' + String(item.status || 'unknown')),
						h('span', { className: 'text-zinc-500' }, ' · cycles ' + String(item.passes || 0) + ' · fixes ' + String(item.added || 0) + ' · remaining ' + String(item.residual || 0)),
						item.message ? h('div', { className: 'text-red-300 mt-1' }, String(item.message)) : null,
						item.warning ? h('div', { className: 'text-amber-300 mt-1' }, String(item.warning)) : null,
					]))) : null,
					runtimeScanBaselineErrors.length ? h('details', { className: 'mt-3 rounded-lg bg-black/15 px-3 py-3', open: false }, [
						h('summary', { className: 'cursor-pointer list-none flex flex-wrap items-center justify-between gap-2' }, [
							h('span', { className: 'text-zinc-300 font-semibold' }, 'Pre-existing Baseline Errors'),
							h('span', { className: 'text-zinc-400 font-mono text-[11px]' }, String(runtimeScanBaselineErrors.length)),
						]),
						h('div', { className: 'text-[11px] text-zinc-500 mt-2 mb-2' }, 'Visible for diagnosis only. These errors also occur with UltraCache JavaScript optimization disabled and are not auto-fixed.'),
						h('div', { className: 'space-y-2' }, runtimeScanBaselineErrors.map((item, index) => h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'runtime-baseline-' + index + '-' + runtimeScanErrorSignature(item) }, [
							h('div', { className: 'font-mono text-[11px] text-zinc-300 break-all' }, String(item && item.message ? item.message : 'Unknown runtime error')),
							runtimeScanErrorSourceIdentity(item) ? h('div', { className: 'font-mono text-[10px] text-zinc-500 break-all mt-1' }, runtimeScanErrorSourceIdentity(item)) : null,
							item && Array.isArray(item.detectedOn) && item.detectedOn.length ? h('div', { className: 'text-[10px] text-zinc-500 mt-1' }, 'Detected on: ' + item.detectedOn.join(', ')) : null,
						]))),
					]) : null,
					h('div', { className: 'flex flex-wrap', style: { marginTop: '10px', gap: '12px' } }, [
						h(Button, { key: 'runtime-scan', onClick: handleRuntimeScan, disabled: !!disabled || runtimeScanBusy }, runtimeScanBusy ? 'Runtime scanning…' : 'Scan Browser Runtime Errors'),
						h(Button, { key: 'append-confirmed-errors', onClick: handleAppendConfirmedErrorFixes, disabled: !!disabled || !confirmedErrorMissingCount }, 'Append Errors to Defer Instead' + (confirmedErrorMissingCount ? ' (' + confirmedErrorMissingCount + ')' : '')),

					]),
				]),
				(debugBrowserScannerEnabled && runtimeScanComparison) ? h('div', { key: 'runtime-scan-comparison', className: 'uc-field-wrap', style: { minWidth: 0, marginTop: '14px' } }, [
					h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
						h('div', null, [
							h('div', { className: 'uc-field-label' }, 'Runtime Scan Comparison'),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, 'Temporary diagnostics · optimized cycle ' + String(runtimeScanComparison.pass || 1) + ' · no scan behavior is changed.'),
						]),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-2 mb-3' }, [
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, 'Baseline captured'), h('div', { className: 'font-mono text-zinc-200' }, String(runtimeScanComparison.baselineErrors.length))]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, 'Optimized captured'), h('div', { className: 'font-mono text-zinc-200' }, String(runtimeScanComparison.capturedErrors.length))]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2' }, [h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, 'After subtraction'), h('div', { className: runtimeScanComparison.differentialErrors.length ? 'font-mono text-amber-300' : 'font-mono text-emerald-300' }, String(runtimeScanComparison.differentialErrors.length))]),
					]),
					[
						['Baseline errors + signatures', runtimeScanComparison.baselineErrors],
						['Raw optimized errors + signatures', runtimeScanComparison.capturedErrors],
						['Differential errors + signatures', runtimeScanComparison.differentialErrors],
					].map(function(section) {
						return h('div', { className: 'mb-3', key: section[0] }, [
							h('div', { className: 'text-[11px] font-semibold text-zinc-300 mb-1' }, section[0]),
							h('pre', { className: 'uc-field-input', style: { whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: '260px', overflow: 'auto', fontSize: '11px', lineHeight: '1.45', margin: 0 } }, formatRuntimeScanComparisonEntries(section[1])),
						]);
					}),
				]) : null,
				h('div', { key: 'console-handler-panel', className: 'uc-field-wrap', style: { minWidth: 0 } }, [
					h('div', { className: 'flex flex-wrap items-center justify-between gap-2 mb-2' }, [
						h('label', { className: 'uc-field-label' }, renderLabelWithHelp(__('Console Error Handler', 'ultracache'), __("What it does: reads pasted browser console errors, maps the script that actually failed to its WordPress-declared dependencies first, and only falls back to targeted active plugin/theme file discovery when the registry does not explain the error.\n\nWhy it helps: it aims for the smallest provider/consumer change that explains the reported error instead of adding unrelated page-wide dependency findings.\n\nWatch for: it only proposes visible fixes. It does not create hidden exceptions.", 'ultracache'))),
						(consoleErrorSuggestions.length || consolePersistentFailures.length) ? h('span', { className: (missingConsoleDelaySuggestions.length || missingConsoleErrorSuggestions.length || consoleFallbackSuggestions.length || consolePersistentFailures.length) ? 'text-amber-300 font-mono text-[11px]' : 'text-emerald-300 font-mono text-[11px]' }, String(missingConsoleDelaySuggestions.length) + ' Delay / ' + String(missingConsoleErrorSuggestions.length) + ' Defer Instead / ' + String(consoleFallbackSuggestions.length) + ' Do Not / ' + String(consolePersistentFailures.length) + ' persistent / ' + String(consoleDependencyRisks.length) + ' dependency') : null,
					]),
					h('div', { className: 'text-xs text-zinc-500 mb-3 leading-relaxed' }, "Paste browser console errors here. For each reported error, UltraCache first uses any provider relationship explicitly proven by the browser error itself (such as a missing jQuery plugin method or computed global). Otherwise it checks only the failing script's actual WordPress dependency chain, then performs targeted provider discovery from the relevant loaded scripts and active plugin/theme code. Page-wide lifecycle and silent dependency analysis stays in Analyze HTML JS Dependencies and is not mixed into Error Fixer results. Nothing changes until you append a proposed fix."),
					h('label', { className: 'uc-field-label', style: { fontSize: '12px', color: '#6f7b8f' } }, renderLabelWithHelp(__('Console errors to analyze', 'ultracache'), __("What it does: gives the handler the raw error text to study.\n\nWhy it helps: error lines, stack traces, and script URLs help UltraCache tell the difference between the script that failed and the missing script that caused the failure.\n\nWatch for: after applying one fix, test again. One missing dependency can hide the next error.", 'ultracache'))),
					h('textarea', {
						className: 'uc-field-input uc-field-textarea',
						style: { minHeight: '142px' },
						value: consoleErrorInput,
						disabled: !!disabled,
						placeholder: `Paste console errors, e.g. "complianz is not defined" or stack lines containing ${joinPublicPath(pluginsPublicPath, 'example/js/file.min.js')}`,
						onChange: (e) => { setConsoleErrorInput(e.target.value); setRuntimeScanScripts([]); },
					}),
					h('div', { className: 'flex flex-wrap', style: { marginTop: '10px', gap: '12px' } }, [
						h(Button, { key: 'extract-console-errors', onClick: handleExtractConsoleErrors, disabled: !!disabled || consoleErrorBusy }, consoleErrorBusy ? 'Extracting…' : 'Extract Console Error Suggestions'),
						h(Button, { key: 'append-console-delay', onClick: handleAppendConsoleDelay, disabled: !!disabled || !missingConsoleDelaySuggestions.length }, 'Append to Delay' + (missingConsoleDelaySuggestions.length ? ' (' + missingConsoleDelaySuggestions.length + ')' : '')),
						h(Button, { key: 'append-console-errors', onClick: handleAppendConsoleErrors, disabled: !!disabled || !missingConsoleErrorSuggestions.length }, 'Append to Defer Instead' + (missingConsoleErrorSuggestions.length ? ' (' + missingConsoleErrorSuggestions.length + ')' : '')),
						h(Button, { key: 'append-console-fallbacks', onClick: handleAppendConsoleFallbacks, disabled: !!disabled || !consoleFallbackSuggestions.length }, 'Append to Do Not Defer or Delay' + (consoleFallbackSuggestions.length ? ' (' + consoleFallbackSuggestions.length + ')' : '')),
						h(Button, { key: 'clear-console-errors', onClick: handleClearConsoleErrors, disabled: !!disabled || (!consoleErrorInput && !consoleErrorSuggestions.length) }, 'Clear Console Input'),

					]),
					consoleErrorStatus ? h('div', { className: 'mt-2 text-[11px] text-sky-300' }, consoleErrorStatus) : null,
				])
			]),
			jsDiagnosticQueue ? h('div', { className: 'mt-3 mb-3 rounded-xl bg-black/20 px-3 py-3' }, [
				h('div', { className: 'flex flex-wrap items-start justify-between gap-3 mb-2' }, [
					h('div', null, [
						h('div', { className: 'text-zinc-200 font-semibold' }, 'JS Diagnostic Queue Status'),
						h('div', { className: 'text-[11px] text-zinc-500 mt-1' }, 'DB-backed JS Diagnostic Queue · stored findings only; execution policy changes only when a visible JavaScript list is saved'),
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
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Review Only'), h('div', { className: 'font-mono text-sky-300' }, String(jsDiagnosticQueueBucketCounts.reviewOnly || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Already Listed'), h('div', { className: 'font-mono text-emerald-300' }, String(jsDiagnosticQueueBucketCounts.alreadyListed || 0))]),
					h('div', { className: 'rounded bg-black/15 px-2 py-2' }, [h('div', { className: 'text-[10px] uppercase tracking-widest text-zinc-500' }, 'Ignored'), h('div', { className: 'font-mono text-zinc-400' }, String(jsDiagnosticQueueBucketCounts.ignored || 0))]),
				]),
				h('div', { className: 'space-y-2 mb-3' }, [
					renderJsDiagnosticQueueCategory('Appendable Fixes', jsDiagnosticQueueBucketCounts.confirmedErrorFixes || 0, jsDiagnosticQueueBuckets.confirmedErrorFixes || [], 'No confirmed fixes in this stored result.', 'jsdq-confirmed', { recommendedOnly: !!(jsDiagnosticQueue && jsDiagnosticQueue.scanType === 'console'), help: 'Ready-to-append fixes detected from confirmed runtime/console errors. Error Fixer shows the exact recommended action when execution order resolves one; otherwise it exposes both exact actions without guessing.' }),
					renderJsDiagnosticQueueCategory('Additional Matches', jsDiagnosticQueueBucketCounts.suggestions || 0, jsDiagnosticQueueBuckets.suggestions || [], 'No dependency/file-scan suggestions in this stored result.', 'jsdq-suggestions', { help: 'Page-scoped dependency risks found from WordPress registered dependencies and readable local JS lifecycle relationships.' }),
					renderJsDiagnosticQueueCategory('Persistent Errors After Exclusion', jsDiagnosticQueueBucketCounts.persistentFailures || 0, jsDiagnosticQueueBuckets.persistentFailures || [], 'No already-listed script still reports the same runtime error.', 'jsdq-persistent', { readOnly: false, recommendedOnly: true, help: 'The script is already covered by Do Not Defer or Delay, but the same error still originates from it. When Runtime Scan proves that its provider remains delayed, Move to Delay atomically removes the exact consumer from stronger lists and retests the dependency island.' }),
					renderJsDiagnosticQueueCategory('Review Only', jsDiagnosticQueueBucketCounts.reviewOnly || 0, jsDiagnosticQueueBuckets.reviewOnly || [], 'No review-only items in this stored result.', 'jsdq-not-fixable', { readOnly: true, help: 'Information only. These findings are evidence or candidates that are not safe to append automatically from the current page/runtime evidence.' }),
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
				h('div', null, 'Runtime and console diagnostics create DB-backed queue jobs for findings only. Stored results never become hidden execution policy; a change takes effect only through the visible Delay third-party JS, Defer Instead of Delay, or Do Not Defer or Delay lists.'),
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
			]) : h('div', { className: 'mt-2 mb-2 text-[11px] text-zinc-500', style: { padding: '5px' } }, __("Leave Page URL to scan blank for the automatic Runtime Site Scan, or enter one same-site URL for single-page Runtime Scan. Analyze HTML JS Dependencies uses the entered URL when present and otherwise analyzes the front page. Scan buttons do not change either list automatically except the bounded Runtime Scan repair cycle's deterministic fixes.", 'ultracache')),
			runtimeScanModalOpen ? h('div', {
				className: 'uc-runtime-scan-modal',
				onClick: runtimeScanBusy ? undefined : function() { setRuntimeScanModalOpen(false); },
				role: 'presentation',
				key: 'runtime-scan-progress-modal',
			}, [
				h('div', {
					className: 'uc-runtime-scan-modal__dialog',
					onClick: function(event) { event.stopPropagation(); },
					role: 'dialog',
					'aria-modal': 'true',
					'aria-labelledby': 'uc-runtime-scan-modal-title',
					'aria-describedby': 'uc-runtime-scan-modal-status',
				}, [
					h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('JS / Defer Safeguards & Diagnostics', 'ultracache')),
					h('h3', { className: 'uc-support-modal__title', id: 'uc-runtime-scan-modal-title', key: 'title' }, __('Browser Runtime Scan', 'ultracache')),
					h('p', { className: 'uc-support-modal__text', key: 'text' }, __('UltraCache is clearing cache, warming the selected URL, scanning browser runtime errors, and applying only deterministic fixes. The scan can run for up to ten cycles.', 'ultracache')),
					h('div', { className: 'uc-runtime-scan-modal__target', key: 'target' }, [
						h('span', { className: 'uc-runtime-scan-modal__target-label', key: 'label' }, __('Current target', 'ultracache')),
						h('span', { className: 'uc-runtime-scan-modal__target-value', key: 'value' }, runtimeScanCurrentTarget
							? (String(runtimeScanCurrentTarget.number || 1) + '/' + String(runtimeScanCurrentTarget.count || 1) + ' · ' + String(runtimeScanCurrentTarget.label || 'Page') + (runtimeScanCurrentTarget.url ? (' · ' + String(runtimeScanCurrentTarget.url)) : ''))
							: __('Discovering Runtime Scan targets…', 'ultracache')),
					]),
					h('div', { className: 'uc-runtime-scan-modal__progress', key: 'progress' }, [
						h('div', { className: 'uc-runtime-scan-modal__progress-head', key: 'head' }, [
							h('span', { id: 'uc-runtime-scan-modal-status', className: 'uc-runtime-scan-modal__status', key: 'status' }, String(runtimeScanStatus || __('Preparing Runtime Scan…', 'ultracache'))),
							h('span', { className: 'uc-runtime-scan-modal__percent', key: 'percent' }, runtimeStatusPercentLabel + '%'),
						]),
						h('div', { className: 'uc-runtime-scan-modal__track', key: 'track' }, [
							h('div', { className: 'uc-runtime-scan-modal__bar', style: { width: String(runtimeStatusPercent) + '%' }, key: 'bar' }),
						]),
					]),
					runtimeScanAwaitingContinue ? h('div', { className: 'uc-runtime-scan-modal__actions', key: 'continue-actions' }, [
						h(Button, { onClick: handleRuntimeScanContinue, variant: 'primary', key: 'continue' }, __('Continue', 'ultracache')),
					]) : (!runtimeScanBusy ? h('div', { className: 'uc-runtime-scan-modal__actions', key: 'actions' }, [
						h(Button, { onClick: function() { setRuntimeScanModalOpen(false); }, variant: 'primary', key: 'close' }, __('Close', 'ultracache')),
					]) : null),
				]),
			]) : null,
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
				['ultracache_runtime_js_scan', 'ultracache_runtime_js_scan_id', 'ultracache_runtime_js_scan_token', 'ultracache_runtime_js_scan_nonce', 'ultracache_runtime_js_scan_mode', 'ultracache_runtime_js_scan_context', 'ultracache_rt', 'ultracache_rv', 'ultracache_bucket', 'ultracache_profile_bypass', 'ultracache_store_profile', 'ultracache_callback_profile', 'ultracache_store_profile_verbose', 'ultracache_store_profile_verbose_settings', 'ultracache_profile_run', 'ultracache_revalidate'].forEach((key) => parsed.searchParams.delete(key));
				return parsed.toString();
			} catch (error) {
				return value.replace(/([?&])ultracache_(runtime_js_scan(?:_id|_token|_nonce|_mode|_context)?|rt|rv|bucket|profile_bypass|store_profile(?:_verbose(?:_settings)?)?|callback_profile|profile_run|revalidate)=[^&#]*/g, '$1').replace(/[?&]$/, '');
			}
		}

function buildRuntimeJsScanUrl(url, scanId, scanToken) {
			let target = String(url || '').trim() || ((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/');
			let parsed;
			try {
				parsed = new URL(target, window.location.origin);
			} catch (error) {
				parsed = new URL((ultracache && ultracache.frontendProbeUrl) ? ultracache.frontendProbeUrl : '/', window.location.origin);
			}
			parsed.searchParams.set('ultracache_runtime_js_scan', '1');
			parsed.searchParams.set('ultracache_runtime_js_scan_id', scanId);
			parsed.searchParams.set('ultracache_runtime_js_scan_token', String(scanToken || ''));
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


	admin.define('diagnostics', {
		configure,
		getJsDelaySafetySuggestions,
		getJsDelayReviewSuggestions,
		isSuggestionPresentInDraft,
		runtimeScanErrorsToConsoleText,
		requestRuntimeJsConsoleFixer,
		buildAutomaticRuntimeFixValues,
		runRuntimeSiteScanAction,
		mergeRuntimeScanFixerRecords,
		DeferDelayExclusionsField,
		CssBundleExclusionsDiagnosticsField,
		getNextMediaEtaCheckpoint,
		formatEtaDuration,
		PerformanceProfilerCard,
		sanitizeRuntimeJsScanDisplayUrl,
		buildRuntimeJsScanUrl,
		runtimeJsScanUrlHasScanId,
		normalizeRuntimeJsScanResult,
	});
})(window);
