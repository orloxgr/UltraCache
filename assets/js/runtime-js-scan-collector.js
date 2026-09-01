(function () {
	'use strict';

	var config = window.ultracacheRuntimeJsScanConfig || {};
	var scanId = String(config.scanId || '');
	if (!scanId) {
		try {
			var scanUrl = new URL(String(window.location && window.location.href || ''), window.location.origin);
			if (scanUrl.searchParams.get('ultracache_runtime_js_scan') === '1') {
				scanId = String(scanUrl.searchParams.get('ultracache_runtime_js_scan_id') || '');
			}
		} catch (scanUrlError) {
			scanId = '';
		}
		if (scanId) {
			config.scanId = scanId;
			config.scanContext = 'anonymous';
			window.ultracacheRuntimeJsScanConfig = config;
		}
	}
	var endpoint = String(config.endpoint || '');
	var restNonce = String(config.restNonce || '');
	var scanContext = 'anonymous';
	var credentialless = window.credentialless === true;
	var bridgePrefix = 'ultracacheRuntimeJsScanBridge:';
	var bridgeName = String(window.name || '');
	var bridgeToken = bridgePrefix + scanId + ':';
	var parentBridgeMode = window.parent !== window && bridgeName.indexOf(bridgeToken) === 0;
	var openerBridgeMode = !!window.opener && bridgeName.indexOf(bridgeToken) === 0;
	var bridgeMode = parentBridgeMode || openerBridgeMode;
	var bridgeRunId = '';
	var configuredBridgeParentOrigin = '';
	if (bridgeMode) {
		try {
			var bridgeRemainder = bridgeName.slice(bridgeToken.length);
			var bridgeSeparator = bridgeRemainder.indexOf(':');
			if (bridgeSeparator > 0) {
				bridgeRunId = bridgeRemainder.slice(0, bridgeSeparator);
				configuredBridgeParentOrigin = decodeURIComponent(bridgeRemainder.slice(bridgeSeparator + 1));
			} else {
				configuredBridgeParentOrigin = decodeURIComponent(bridgeRemainder);
			}
		} catch (e) {
			bridgeRunId = '';
			configuredBridgeParentOrigin = '';
		}
	}

	if (!scanId || (!bridgeMode && (!endpoint || !restNonce))) {
		return;
	}

	var startedAt = Date.now ? Date.now() : new Date().getTime();

	function ensureClassificationAuditSink() {
		var existing = window.__ultracacheJsClassificationAudit;
		if (existing && existing.enabled === true && typeof existing.record === 'function' && Array.isArray(existing.records)) {
			return existing;
		}
		var records = [];
		var sink = {
			enabled: true,
			startedAt: startedAt,
			records: records,
			maxRecords: 600,
			record: function (record) {
				if (!record || typeof record !== 'object') {
					return;
				}
				var lane = String(record.lane || '').toLowerCase();
				if (['native', 'defer', 'delay'].indexOf(lane) === -1) {
					return;
				}
				records.push({
					url: String(record.url || ''),
					handle: String(record.handle || ''),
					lane: lane,
					reason: String(record.reason || ''),
					decisionSource: String(record.decisionSource || ''),
					caughtBy: String(record.caughtBy || ''),
					ruleId: String(record.ruleId || ''),
					matchedPattern: String(record.matchedPattern || ''),
					initiator: String(record.initiator || ''),
					atMs: typeof record.atMs === 'number' ? record.atMs : (now() - startedAt)
				});
				if (records.length > sink.maxRecords) {
					records.splice(0, records.length - sink.maxRecords);
				}
			}
		};
		window.__ultracacheJsClassificationAudit = sink;
		return sink;
	}

	var classificationAudit = ensureClassificationAuditSink();
	var errors = [];
	var sentCount = 0;
	var maxErrors = 120;
	var maxActivity = 18;
	var storageKey = 'ultracacheRuntimeJsScan:' + scanId + (bridgeRunId ? ':' + bridgeRunId : '');
	var recentActivity = [];
	var originalOnError = window.onerror;
	var originalOnUnhandledRejection = window.onunhandledrejection;
	var originalSetTimeout = window.setTimeout;
	var finalReportSent = false;
	var errorKeys = {};
	var errorGeneration = 0;
	var delayedScriptsDoneObserved = false;
	var settleScheduled = false;
	var settleRounds = 0;
	var settleQuietRounds = 0;
	var observedTimeouts = {};
	var observedTimeoutSequence = 0;
	var settleTimerObservationUntil = 0;

	window.__ultracacheRuntimeJsScan = window.__ultracacheRuntimeJsScan || {
		injectedAt: startedAt,
		context: scanContext,
		errors: errors,
		sentCount: 0,
		classificationAudit: classificationAudit,
		debug: {
			installed: true,
			source: 'wp-native-enqueue',
			context: scanContext,
			credentialless: credentialless,
			onerror: false,
			eventError: false,
			consoleError: false,
			consoleFunctionalLog: false,
			directHarvest: false
		}
	};

	installClassificationAuditDomObserver();
	installDynamicFinderClassificationAuditBridge();

	function dynamicClassificationDecisionSource(route) {
		route = route && typeof route === 'object' ? route : {};
		var ruleId = String(route.ruleId || route.rule_id || '').toLowerCase();
		var reason = String(route.reason || '').toLowerCase();
		if (ruleId === 'visible-native' || ruleId === 'visible-defer' || reason.indexOf('visible-') === 0) {
			return 'visible-list';
		}
		if (ruleId === 'explicit-author-opt-out' || reason === 'explicit-author-optimizer-opt-out') {
			return 'explicit-author-opt-out';
		}
		if (ruleId === 'explicit-integration' || reason.indexOf('explicit-integration') === 0) {
			return 'explicit-integration-switch';
		}
		if (ruleId === 'html-js-semantics') {
			return 'html-js-semantics';
		}
		return 'default-strategy';
	}

	function recordDynamicClassification(node, route, prospectiveSrc, caughtBy) {
		if (!classificationAudit || !route || typeof route !== 'object') {
			return;
		}
		var lane = String(route.lane || '').toLowerCase();
		if (['native', 'defer', 'delay'].indexOf(lane) === -1) {
			return;
		}
		var src = String(prospectiveSrc || (node && node.getAttribute ? (node.getAttribute('src') || node.getAttribute('data-ultracache-src') || '') : ''));
		var ruleId = String(route.ruleId || route.rule_id || '');
		var reason = String(route.reason || '');
		var decisionSource = dynamicClassificationDecisionSource(route);
		var caught = String(caughtBy || 'dynamic-finder');
		var matchedPattern = String(route.matchedPattern || route.matched_pattern || '');
		var handle = '';
		if (node && node.getAttribute) {
			handle = String(node.getAttribute('data-ultracache-audit-handle') || node.getAttribute('data-ultracache-handle') || node.id || '');
			try {
				node.setAttribute('data-ultracache-audit-lane', lane);
				node.setAttribute('data-ultracache-audit-reason', reason);
				node.setAttribute('data-ultracache-audit-source', decisionSource);
				node.setAttribute('data-ultracache-audit-caught-by', caught);
				node.setAttribute('data-ultracache-audit-rule', ruleId);
				if (matchedPattern) {
					node.setAttribute('data-ultracache-audit-pattern', matchedPattern);
				}
			} catch (e) {}
		}
		classificationAudit.record({
			url: src,
			handle: handle,
			lane: lane,
			reason: reason,
			decisionSource: decisionSource,
			caughtBy: caught,
			ruleId: ruleId,
			matchedPattern: matchedPattern,
			initiator: sourceFromStack((new Error()).stack),
			atMs: now() - startedAt
		});
	}

	function installDynamicFinderClassificationAuditBridge() {
		var finder = window.__ultracacheDynamicScriptFinderV31211;
		if (!finder || typeof finder.setAuditRecorder !== 'function') {
			return;
		}
		finder.setAuditRecorder(recordDynamicClassification);
	}

	function now() {
		return Date.now ? Date.now() : new Date().getTime();
	}

	function asText(value) {
		try {
			if (value instanceof Error) {
				return value.name + ': ' + value.message;
			}
			if (typeof value === 'string') {
				return value;
			}
			return JSON.stringify(value);
		} catch (e) {
			return String(value);
		}
	}

	function trimText(value, max) {
		value = String(value || '');
		max = max || 800;
		return value.length > max ? value.slice(0, max) : value;
	}

	function captureClassificationAuditNode(node) {
		if (!classificationAudit || !node || String(node.tagName || node.nodeName || '').toLowerCase() !== 'script' || !node.getAttribute) {
			return;
		}
		var lane = String(node.getAttribute('data-ultracache-audit-lane') || '').toLowerCase();
		if (['native', 'defer', 'delay'].indexOf(lane) === -1) {
			return;
		}
		classificationAudit.record({
			url: String(node.getAttribute('src') || node.getAttribute('data-ultracache-src') || node.getAttribute('data-ultracache-original-src') || ''),
			handle: String(node.getAttribute('data-ultracache-audit-handle') || node.getAttribute('data-ultracache-handle') || node.id || ''),
			lane: lane,
			reason: String(node.getAttribute('data-ultracache-audit-reason') || ''),
			decisionSource: String(node.getAttribute('data-ultracache-audit-source') || ''),
			caughtBy: String(node.getAttribute('data-ultracache-audit-caught-by') || 'registered-router'),
			ruleId: String(node.getAttribute('data-ultracache-audit-rule') || ''),
			matchedPattern: String(node.getAttribute('data-ultracache-audit-pattern') || ''),
			initiator: 'parser-dom',
			atMs: now() - startedAt
		});
	}

	function installClassificationAuditDomObserver() {
		try {
			var existing = document.querySelectorAll ? document.querySelectorAll('script[data-ultracache-audit-lane]') : [];
			for (var i = 0; i < existing.length; i++) {
				captureClassificationAuditNode(existing[i]);
			}
		} catch (e) {}

		if (typeof window.MutationObserver !== 'function') {
			return;
		}
		try {
			var observer = new window.MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					var added = mutations[i] && mutations[i].addedNodes ? mutations[i].addedNodes : [];
					for (var j = 0; j < added.length; j++) {
						var node = added[j];
						captureClassificationAuditNode(node);
						if (node && node.querySelectorAll) {
							var nested = node.querySelectorAll('script[data-ultracache-audit-lane]');
							for (var k = 0; k < nested.length; k++) {
								captureClassificationAuditNode(nested[k]);
							}
						}
					}
				}
			});
			observer.observe(document.documentElement || document, { childList: true, subtree: true });
			window.__ultracacheRuntimeJsScan.classificationAuditObserver = observer;
		} catch (e) {}
	}

	function isFunctionalFailureMessage(message) {
		message = String(message || '').toLowerCase();
		if (message.length < 6) {
			return false;
		}
		return /\b(?:not|never)\s+(?:loaded|initialized|initialised|available|ready|defined|found|created|rendered)(?:\s+properly)?\b|\b(?:failed|unable)\s+to\s+(?:load|initialize|initialise|start|create|render|resolve|find)\b|\bcould\s+not\s+(?:load|initialize|initialise|start|create|render|resolve|find)\b|\b(?:missing|unavailable)\s+(?:dependency|dependencies|library|libraries|script|scripts|file|files|module|modules|provider|global)\b/i.test(message);
	}

	function normalizeUrlForLoop(value) {
		value = String(value || '');
		if (!value) {
			return '';
		}
		try {
			var parsed = new URL(value, window.location.href);
			[
				'ultracache_runtime_js_scan',
				'ultracache_runtime_js_scan_id',
				'ultracache_runtime_js_scan_token',
				'ultracache_runtime_js_scan_mode',
				'ultracache_runtime_js_scan_context',
				'ultracache_rt',
				'ultracache_profile_bypass',
				'ultracache_store_profile',
				'ultracache_callback_profile',
				'ultracache_store_profile_verbose',
				'ultracache_store_profile_verbose_settings',
				'ultracache_profile_run',
				'ultracache_revalidate'
			].forEach(function (key) {
				parsed.searchParams.delete(key);
			});
			parsed.hash = '';
			return parsed.href;
		} catch (e) {
			return value.replace(/([?&])ultracache_(?:runtime_js_scan(?:_id|_token|_mode|_context)?|rt|profile_bypass|store_profile(?:_verbose(?:_settings)?)?|callback_profile|profile_run|revalidate)=[^&#]*/g, '$1').replace(/[?&]$/, '').split('#')[0];
		}
	}

	function readScanState() {
		try {
			var raw = window.sessionStorage ? window.sessionStorage.getItem(storageKey) : '';
			return raw ? (JSON.parse(raw) || {}) : {};
		} catch (e) {
			return {};
		}
	}

	function writeScanState(state) {
		try {
			if (window.sessionStorage) {
				window.sessionStorage.setItem(storageKey, JSON.stringify(state || {}));
			}
		} catch (e) {}
	}

	function sourceFromStack(stack) {
		stack = String(stack || '');
		if (!stack) {
			return '';
		}
		var matches = stack.match(/https?:\/\/[^\s\)\]\}"'<>]+\.js(?:\?[^\s\)\]\}"'<>]*)?(?::\d+){0,2}/gi) || [];
		for (var i = 0; i < matches.length; i++) {
			var source = String(matches[i] || '');
			var sourceLc = source.toLowerCase();
			if (sourceLc.indexOf('/wp-includes/js/') !== -1 || sourceLc.indexOf('/ultracache/assets/js/') !== -1 || sourceLc.indexOf('jquery.min.js') !== -1 || sourceLc.indexOf('jquery-migrate') !== -1) {
				continue;
			}
			return source;
		}
		return matches.length ? String(matches[0] || '') : '';
	}

	function summarizeElement(element) {
		try {
			if (!element || !element.tagName) {
				return '';
			}
			var out = String(element.tagName || '').toLowerCase();
			if (element.id) {
				out += '#' + element.id;
			}
			if (element.className && typeof element.className === 'string') {
				out += '.' + element.className.split(/\s+/).filter(Boolean).slice(0, 5).join('.');
			}
			if (element.name) {
				out += '[name="' + String(element.name).slice(0, 80) + '"]';
			}
			if (element.value) {
				out += '[value="' + String(element.value).slice(0, 80) + '"]';
			}
			return out;
		} catch (e) {
			return '';
		}
	}

	function recordActivity(kind, target, detail, stack) {
		stack = stack || (new Error()).stack || '';
		var item = {
			kind: trimText(kind, 80),
			target: trimText(target, 240),
			detail: trimText(detail, 500),
			source: trimText(sourceFromStack(stack), 1000),
			stack: trimText(stack, 2400),
			atMs: now() - startedAt
		};
		recentActivity.push(item);
		if (recentActivity.length > maxActivity) {
			recentActivity = recentActivity.slice(recentActivity.length - maxActivity);
		}
		try {
			window.__ultracacheRuntimeJsScan.recentActivity = recentActivity;
		} catch (e) {}
		return item;
	}

	function selectNavigationCause() {
		for (var i = recentActivity.length - 1; i >= 0; i--) {
			var item = recentActivity[i] || {};
			var text = String((item.kind || '') + ' ' + (item.target || '') + ' ' + (item.detail || '')).toLowerCase();
			if (text.indexOf('change') !== -1 && (text.indexOf('orderby') !== -1 || text.indexOf('woocommerce-ordering') !== -1 || text.indexOf('select') !== -1)) {
				return item;
			}
		}
		for (var j = recentActivity.length - 1; j >= 0; j--) {
			if (recentActivity[j] && recentActivity[j].source) {
				return recentActivity[j];
			}
		}
		return recentActivity.length ? recentActivity[recentActivity.length - 1] : null;
	}

	function detectSameUrlReloadLoop() {
		var normalizedUrl = normalizeUrlForLoop(window.location.href || '');
		if (!normalizedUrl) {
			return;
		}
		var previous = readScanState();
		var previousUrl = String(previous.url || '');
		var previousAt = Number(previous.at || 0);
		var previousCount = Number(previous.sameUrlCount || 0);
		var elapsed = previousAt ? (now() - previousAt) : 0;
		var sameUrlCount = (previousUrl && previousUrl === normalizedUrl && elapsed >= 0 && elapsed < 20000) ? (previousCount + 1) : 1;
		var previousCause = previous.navigationCause || null;

		writeScanState({
			url: normalizedUrl,
			at: now(),
			sameUrlCount: sameUrlCount,
			navigationCause: previousCause,
			recentActivity: recentActivity
		});

		if (sameUrlCount >= 2) {
			var detail = {
				normalizedUrl: normalizedUrl,
				sameUrlCount: sameUrlCount,
				previousCause: previousCause,
				previousActivity: Array.isArray(previous.recentActivity) ? previous.recentActivity.slice(-8) : []
			};
			addError(
				'same-url-navigation-loop',
				'Same URL document navigation repeated during Runtime Scan. A script likely triggered a startup change or redirect back to the current URL.',
				previousCause && previousCause.source ? previousCause.source : normalizedUrl,
				0,
				0,
				JSON.stringify(detail)
			);
			finalizeReport();
		}
	}

	function addError(kind, message, source, line, column, detail) {
		var item = {
			kind: trimText(kind, 40),
			message: trimText(message, 1000),
			source: trimText(source, 1000),
			line: Number(line || 0),
			column: Number(column || 0),
			detail: trimText(detail, 1000),
			atMs: now() - startedAt
		};

		var dedupeKey = [item.message, item.source, item.line, item.column].join('|');
		if (errorKeys[dedupeKey]) {
			return;
		}
		errorKeys[dedupeKey] = true;
		errorGeneration++;
		errors.push(item);
		window.__ultracacheRuntimeJsScan.errors = errors;
		if (errors.length > maxErrors) {
			errors = errors.slice(errors.length - maxErrors);
			window.__ultracacheRuntimeJsScan.errors = errors;
		}
	}

	function getResourceUrl(target) {
		try {
			if (!target || !target.getAttribute) {
				return '';
			}
			return String(target.getAttribute('src') || target.getAttribute('href') || target.currentSrc || target.src || target.href || '');
		} catch (e) {
			return '';
		}
	}

	function describeResourceTarget(target) {
		try {
			if (!target) {
				return '';
			}
			var tag = target.tagName ? String(target.tagName).toLowerCase() : 'resource';
			var id = target.id ? ('#' + target.id) : '';
			var rel = target.rel ? (' rel=' + target.rel) : '';
			var type = target.type ? (' type=' + target.type) : '';
			return tag + id + rel + type;
		} catch (e) {
			return '';
		}
	}

	function collectScripts() {
		var list = [];
		try {
			var scripts = document.getElementsByTagName('script');
			for (var i = 0; i < scripts.length && list.length < 240; i++) {
				var s = scripts[i];
				var src = s && s.getAttribute ? String(s.getAttribute('src') || s.getAttribute('data-ultracache-src') || s.getAttribute('data-ultracache-original-src') || '') : '';
				var id = s && s.getAttribute ? String((s.id || '') || s.getAttribute('data-ultracache-id') || s.getAttribute('data-ultracache-handle') || '') : '';
				var handle = s && s.getAttribute ? String(s.getAttribute('data-ultracache-handle') || '') : '';
				var depsText = s && s.getAttribute ? String(s.getAttribute('data-ultracache-deps') || '') : '';
				var deps = depsText ? depsText.split(',').map(function (value) { return String(value || '').trim(); }).filter(Boolean) : [];
				var delayed = !!(s && (s.type === 'text/ultracache-delayed-js' || (s.hasAttribute && (s.hasAttribute('data-ultracache-src') || s.hasAttribute('data-ultracache-inline') || s.hasAttribute('data-ultracache-delayed')))));
				var text = '';
				if ((!src || delayed) && s && s.textContent) {
					text = trimText(s.textContent, 60000);
				}
				list.push({
					order: i,
					executionSequence: Number(s && s.getAttribute ? (s.getAttribute('data-ultracache-executed-sequence') || 0) : 0),
					executionLane: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-executed-lane') || '') : '', 32),
					family: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-family') || '') : '', 160),
					familySequence: Number(s && s.getAttribute ? (s.getAttribute('data-ultracache-family-sequence') || 0) : 0),
					familyPhase: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-family-phase') || '') : '', 40),
					id: trimText(id, 160),
					handle: trimText(handle, 160),
					src: trimText(src, 1200),
					type: trimText(s && s.type ? s.type : '', 120),
					defer: !!(s && s.defer),
					async: !!(s && s.async),
					strategy: trimText(s && s.getAttribute ? (s.getAttribute('data-wp-strategy') || '') : '', 80),
					delayed: delayed,
					deps: deps.slice(0, 40),
					auditLane: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-lane') || '') : '', 32),
					auditReason: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-reason') || '') : '', 160),
					auditSource: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-source') || '') : '', 120),
					auditCaughtBy: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-caught-by') || '') : '', 120),
					auditRule: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-rule') || '') : '', 120),
					auditPattern: trimText(s && s.getAttribute ? (s.getAttribute('data-ultracache-audit-pattern') || '') : '', 180),
					text: text
				});
			}
		} catch (e) {}
		return list;
	}

	function normalizeClassificationUrl(value) {
		value = String(value || '').trim();
		if (!value) {
			return '';
		}
		try {
			var parsed = new URL(value, window.location.href);
			if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
				return '';
			}
			parsed.hash = '';
			return parsed.href;
		} catch (e) {
			return '';
		}
	}

	function collectDomClassificationRecords() {
		var records = [];
		try {
			var scripts = document.getElementsByTagName('script');
			for (var i = 0; i < scripts.length && records.length < 400; i++) {
				var script = scripts[i];
				if (!script || !script.getAttribute) {
					continue;
				}
				var auditLane = String(script.getAttribute('data-ultracache-audit-lane') || '').toLowerCase();
				var runtimeLane = String(script.getAttribute('data-ultracache-runtime-lane') || '').toLowerCase();
				var lane = ['native', 'defer', 'delay'].indexOf(auditLane) !== -1 ? auditLane : runtimeLane;
				if (['native', 'defer', 'delay'].indexOf(lane) === -1) {
					continue;
				}
				records.push({
					url: String(script.getAttribute('src') || script.getAttribute('data-ultracache-src') || script.getAttribute('data-ultracache-original-src') || ''),
					handle: String(script.getAttribute('data-ultracache-audit-handle') || script.getAttribute('data-ultracache-handle') || script.id || ''),
					lane: lane,
					reason: String(script.getAttribute('data-ultracache-audit-reason') || script.getAttribute('data-ultracache-runtime-reason') || ''),
					decisionSource: String(script.getAttribute('data-ultracache-audit-source') || (runtimeLane ? 'runtime-router' : '')),
					caughtBy: String(script.getAttribute('data-ultracache-audit-caught-by') || script.getAttribute('data-ultracache-runtime-caught-by') || 'registered-router'),
					ruleId: String(script.getAttribute('data-ultracache-audit-rule') || script.getAttribute('data-ultracache-runtime-rule') || ''),
					matchedPattern: String(script.getAttribute('data-ultracache-audit-pattern') || script.getAttribute('data-ultracache-runtime-pattern') || ''),
					initiator: '',
					atMs: 0
				});
			}
		} catch (e) {}
		return records;
	}

	function collectScriptResourceRequests() {
		var out = [];
		if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
			return out;
		}
		try {
			var entries = window.performance.getEntriesByType('resource') || [];
			for (var i = 0; i < entries.length && out.length < 500; i++) {
				var entry = entries[i] || {};
				if (String(entry.initiatorType || '').toLowerCase() !== 'script') {
					continue;
				}
				var url = normalizeClassificationUrl(entry.name || '');
				if (!url) {
					continue;
				}
				out.push({
					url: url,
					startTime: Number(entry.startTime || 0),
					duration: Number(entry.duration || 0),
					transferSize: Number(entry.transferSize || 0)
				});
			}
		} catch (e) {}
		return out;
	}

	function collectRuntimeClassificationRegistry() {
		var finder = window.__ultracacheDynamicScriptFinderV31211;
		if (!finder || typeof finder.getRegistrySnapshot !== 'function') {
			return { seen: 0, native: 0, defer: 0, delay: 0, pending: 0, escaped: 0, classified: 0, invariantPassed: true };
		}
		try {
			var snapshot = finder.getRegistrySnapshot() || {};
			return {
				seen: Number(snapshot.seen || 0),
				native: Number(snapshot.native || 0),
				defer: Number(snapshot.defer || 0),
				delay: Number(snapshot.delay || 0),
				pending: Number(snapshot.pending || 0),
				escaped: Number(snapshot.escaped || 0),
				classified: Number(snapshot.classified || 0),
				invariantPassed: snapshot.invariantPassed !== false
			};
		} catch (e) {
			return { seen: 0, native: 0, defer: 0, delay: 0, pending: 0, escaped: 1, classified: 0, invariantPassed: false };
		}
	}

	function buildClassificationAuditPayload() {
		var combined = [];
		var sinkRecords = classificationAudit && Array.isArray(classificationAudit.records) ? classificationAudit.records.slice(0) : [];
		var domRecords = collectDomClassificationRecords();
		var seen = Object.create(null);
		[sinkRecords, domRecords].forEach(function (group) {
			group.forEach(function (record) {
				if (!record || typeof record !== 'object') {
					return;
				}
				var lane = String(record.lane || '').toLowerCase();
				if (['native', 'defer', 'delay'].indexOf(lane) === -1) {
					return;
				}
				var normalizedUrl = normalizeClassificationUrl(record.url || '');
				var normalized = {
					url: normalizedUrl,
					handle: trimText(record.handle || '', 180),
					lane: lane,
					reason: trimText(record.reason || '', 180),
					decisionSource: trimText(record.decisionSource || '', 120),
					caughtBy: trimText(record.caughtBy || '', 120),
					ruleId: trimText(record.ruleId || '', 120),
					matchedPattern: trimText(record.matchedPattern || '', 180),
					initiator: trimText(record.initiator || '', 1000),
					atMs: Number(record.atMs || 0)
				};
				var key = [normalized.url, normalized.handle, lane, normalized.reason, normalized.caughtBy, normalized.ruleId].join('|');
				if (!seen[key]) {
					seen[key] = true;
					combined.push(normalized);
				}
			});
		});

		var classifiedUrls = Object.create(null);
		combined.forEach(function (record) {
			if (record.url) {
				classifiedUrls[record.url] = true;
			}
		});

		var resources = collectScriptResourceRequests();
		var unclassified = resources.filter(function (resource) {
			return !classifiedUrls[resource.url];
		});
		var runtimeRegistry = collectRuntimeClassificationRegistry();

		return {
			enabled: true,
			records: combined.slice(0, 600),
			resourceRequests: resources,
			unclassifiedRequests: unclassified,
			runtimeRegistry: runtimeRegistry,
			invariantPassed: unclassified.length === 0 && runtimeRegistry.invariantPassed,
			recordCount: combined.length,
			resourceRequestCount: resources.length,
			unclassifiedCount: unclassified.length
		};
	}

	function buildPayload(completed) {
		var payload = {
			scanId: scanId,
			url: String(window.location.href || ''),
			completed: !!completed,
			scanContext: scanContext,
			errors: errors.slice(0),
			scripts: collectScripts(),
			classificationAudit: buildClassificationAuditPayload(),
			userAgent: String(window.navigator && window.navigator.userAgent ? window.navigator.userAgent : ''),
			sentCount: ++sentCount,
			elapsedMs: now() - startedAt,
			debug: window.__ultracacheRuntimeJsScan && window.__ultracacheRuntimeJsScan.debug ? window.__ultracacheRuntimeJsScan.debug : {}
		};

		window.__ultracacheRuntimeJsScan.sentCount = sentCount;
		window.__ultracacheRuntimeJsScan.lastPayload = payload;
		return payload;
	}

	function bridgeTargetOrigin() {
		if (configuredBridgeParentOrigin) {
			return configuredBridgeParentOrigin;
		}
		try {
			if (document.referrer) {
				return new URL(document.referrer, window.location.href).origin;
			}
		} catch (e) {}
		try {
			if (parentBridgeMode && window.parent && window.parent.location && window.parent.location.origin) {
				return String(window.parent.location.origin || '');
			}
		} catch (e) {}
		try {
			if (openerBridgeMode && window.opener && window.opener.location && window.opener.location.origin) {
				return String(window.opener.location.origin || '');
			}
		} catch (e) {}
		return String(window.location && window.location.origin ? window.location.origin : '');
	}

	function bridgeTargetWindow() {
		if (parentBridgeMode && window.parent && window.parent !== window) {
			return window.parent;
		}
		if (openerBridgeMode && window.opener) {
			return window.opener;
		}
		return null;
	}

	function sendBridgeReady() {
		var targetWindow = bridgeTargetWindow();
		if (!bridgeMode || !targetWindow) {
			return false;
		}
		var targetOrigin = bridgeTargetOrigin();
		if (!targetOrigin) {
			window.__ultracacheRuntimeJsScan.debug.bridgeReadyFailed = true;
			return false;
		}
		try {
			targetWindow.postMessage({
				channel: 'ultracache-runtime-js-scan',
				type: 'ready',
				scanId: scanId,
				payload: {
					scanId: scanId,
					url: String(window.location.href || ''),
					credentialless: credentialless
				}
			}, targetOrigin);
			window.__ultracacheRuntimeJsScan.debug.bridgeReadySent = true;
			window.__ultracacheRuntimeJsScan.debug.bridgeTargetOrigin = targetOrigin;
			return true;
		} catch (e) {
			window.__ultracacheRuntimeJsScan.debug.bridgeReadyFailed = true;
			return false;
		}
	}

	function sendBridgeReport(payload) {
		var targetWindow = bridgeTargetWindow();
		if (!bridgeMode || !targetWindow) {
			return false;
		}
		var targetOrigin = bridgeTargetOrigin();
		if (!targetOrigin) {
			window.__ultracacheRuntimeJsScan.debug.bridgeFailed = true;
			return false;
		}
		try {
			// Force the bridge payload through JSON-compatible primitives before
			// postMessage so Error/DOM/circular values can never poison delivery.
			var wirePayload = JSON.parse(JSON.stringify(payload));
			targetWindow.postMessage({
				channel: 'ultracache-runtime-js-scan',
				type: 'completed',
				scanId: scanId,
				payload: wirePayload
			}, targetOrigin);
			window.__ultracacheRuntimeJsScan.debug.bridgeSent = true;
			window.__ultracacheRuntimeJsScan.debug.bridgeTransport = openerBridgeMode ? 'opener' : 'parent';
			window.__ultracacheRuntimeJsScan.debug.bridgeTargetOrigin = targetOrigin;
			return true;
		} catch (e) {
			window.__ultracacheRuntimeJsScan.debug.bridgeFailed = true;
			return false;
		}
	}

	sendBridgeReady();

	function finalizeReport() {
		if (finalReportSent || !scanId) {
			return;
		}

		finalReportSent = true;
		var payload = buildPayload(true);

		// Manual Browser Runtime Scan uses one direct bridge delivery to the hidden iframe parent or visible scanner opener.
		// It does not write or poll the REST report endpoint.
		if (bridgeMode) {
			window.__ultracacheRuntimeJsScan.debug.transport = openerBridgeMode ? 'opener-bridge' : 'parent-bridge';
			sendBridgeReport(payload);
			return;
		}

		if (!endpoint || !window.fetch) {
			window.__ultracacheRuntimeJsScan.debug.reportFailed = true;
			return;
		}

		window.__ultracacheRuntimeJsScan.sending = true;
		var request;
		try {
			request = window.fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce
				},
				body: JSON.stringify(payload),
				keepalive: true
			});
		} catch (e) {
			request = null;
		}

		if (!request || typeof request.then !== 'function') {
			window.__ultracacheRuntimeJsScan.sending = false;
			window.__ultracacheRuntimeJsScan.debug.reportFailed = true;
			return;
		}

		request.then(function (response) {
			if (!response || !response.ok) {
				window.__ultracacheRuntimeJsScan.debug.reportFailed = true;
				window.__ultracacheRuntimeJsScan.debug.reportStatus = response ? Number(response.status || 0) : 0;
				return;
			}
			window.__ultracacheRuntimeJsScan.debug.reportStored = true;
		}, function () {
			window.__ultracacheRuntimeJsScan.debug.reportFailed = true;
		}).then(function () {
			window.__ultracacheRuntimeJsScan.sending = false;
		});
	}

	window.__ultracacheRuntimeJsScan.flush = function () {
		finalizeReport();
	};

	function installTimeoutActivityProbe() {
		if (typeof originalSetTimeout !== 'function' || window.setTimeout.__ultracacheRuntimeScanWrapped) {
			return;
		}
		window.setTimeout = function (callback, delay) {
			if (typeof callback !== 'function') {
				return originalSetTimeout.apply(this, arguments);
			}
			var scheduledStack = (new Error()).stack || '';
			var numericDelay = Math.max(0, Number(delay || 0));
			recordActivity('timeout-scheduled', '', 'delay=' + String(numericDelay), scheduledStack);
			var args = Array.prototype.slice.call(arguments, 2);
			var timeoutKey = '';

			// Runtime failures such as SDK/provider timeout checks commonly fire a
			// few seconds after load. Track only already-scheduled, bounded one-shot
			// timers so finalization can observe them without waiting for long-lived
			// application timers or changing visitor behavior.
			if (numericDelay >= 250 && numericDelay <= 10000) {
				timeoutKey = 't' + (++observedTimeoutSequence);
				observedTimeouts[timeoutKey] = now() + numericDelay;
			}

			return originalSetTimeout.call(this, function () {
				if (timeoutKey) {
					delete observedTimeouts[timeoutKey];
				}
				recordActivity('timeout-fired', '', 'delay=' + String(numericDelay), scheduledStack);
				return callback.apply(this, args);
			}, delay);
		};
		window.setTimeout.__ultracacheRuntimeScanWrapped = true;
	}

	function installJqueryActivityProbe() {
		var jq = window.jQuery;
		if (!jq || !jq.fn || jq.fn.__ultracacheRuntimeScanWrapped) {
			return false;
		}

		if (typeof jq.fn.trigger === 'function') {
			var originalTrigger = jq.fn.trigger;
			jq.fn.trigger = function (eventType) {
				try {
					var type = typeof eventType === 'string' ? eventType : (eventType && eventType.type ? String(eventType.type) : '');
					if (type === 'change') {
						var stack = (new Error()).stack || '';
						this.each(function () {
							recordActivity('jquery-trigger-change', summarizeElement(this), 'event=change', stack);
						});
					}
				} catch (e) {}
				return originalTrigger.apply(this, arguments);
			};
		}

		if (typeof jq.fn.change === 'function') {
			var originalChange = jq.fn.change;
			jq.fn.change = function () {
				try {
					if (!arguments.length) {
						var stack = (new Error()).stack || '';
						this.each(function () {
							recordActivity('jquery-change-shortcut', summarizeElement(this), 'event=change', stack);
						});
					}
				} catch (e) {}
				return originalChange.apply(this, arguments);
			};
		}

		jq.fn.__ultracacheRuntimeScanWrapped = true;
		window.__ultracacheRuntimeJsScan.debug.jqueryActivityProbe = true;
		return true;
	}

	function waitForJqueryActivityProbe(tries) {
		if (installJqueryActivityProbe()) {
			return;
		}
		if (tries > 240) {
			return;
		}
		originalSetTimeout(function () {
			waitForJqueryActivityProbe(tries + 1);
		}, 25);
	}

	function installNavigationLoopProbe() {
		installTimeoutActivityProbe();
		waitForJqueryActivityProbe(0);
		detectSameUrlReloadLoop();
		window.addEventListener('beforeunload', function () {
			var state = readScanState();
			state.url = normalizeUrlForLoop(window.location.href || '');
			state.at = now();
			state.navigationCause = selectNavigationCause();
			state.recentActivity = recentActivity.slice(-8);
			writeScanState(state);
		}, true);
	}

	installNavigationLoopProbe();

	window.onerror = function (message, source, line, column, error) {
		try {
			window.__ultracacheRuntimeJsScan.debug.onerror = true;
			addError('window-onerror', message || 'Script error', source || '', line || 0, column || 0, error && error.stack ? error.stack : asText(error || message));
		} catch (e) {}
		if (typeof originalOnError === 'function') {
			return originalOnError.apply(this, arguments);
		}
		return false;
	};

	window.addEventListener('error', function (event) {
		if (!event) {
			return;
		}
		window.__ultracacheRuntimeJsScan.debug.eventError = true;
		var target = event.target || event.srcElement || null;
		if (target && target !== window && (target.tagName || target.getAttribute)) {
			var resourceUrl = getResourceUrl(target);
			if (resourceUrl) {
				addError('resource-error', 'Resource failed to load', resourceUrl, 0, 0, describeResourceTarget(target));
				return;
			}
		}
		var detail = event.error ? asText(event.error && event.error.stack ? event.error.stack : event.error) : '';
		addError('error', event.message || 'Script error', event.filename || '', event.lineno || 0, event.colno || 0, detail);
	}, true);

	window.onunhandledrejection = function (event) {
		try {
			var reason = event && event.reason ? event.reason : '';
			addError('window-unhandledrejection', asText(reason), '', 0, 0, reason && reason.stack ? reason.stack : '');
		} catch (e) {}
		if (typeof originalOnUnhandledRejection === 'function') {
			return originalOnUnhandledRejection.apply(this, arguments);
		}
	};

	window.addEventListener('unhandledrejection', function (event) {
		var reason = event && event.reason ? event.reason : '';
		addError('unhandledrejection', asText(reason), '', 0, 0, reason && reason.stack ? reason.stack : '');
	}, true);

	if (window.console && !window.console.__ultracacheRuntimeScanWrapped) {
		if (typeof window.console.error === 'function') {
			var originalError = window.console.error;
			window.console.error = function () {
				try {
					window.__ultracacheRuntimeJsScan.debug.consoleError = true;
					var parts = [];
					for (var i = 0; i < arguments.length; i++) {
						parts.push(asText(arguments[i]));
					}
					var errorStack = (new Error()).stack || '';
					addError('console-error', parts.join(' '), sourceFromStack(errorStack), 0, 0, errorStack);
				} catch (e) {}
				return originalError.apply(window.console, arguments);
			};
		}

		// Some frontend libraries report a real broken state through console.log()
		// instead of throwing. Capture only high-signal failure language and keep
		// the caller stack so normal debug/info logging does not flood Runtime Scan.
		if (typeof window.console.log === 'function') {
			var originalLog = window.console.log;
			window.console.log = function () {
				try {
					var logParts = [];
					for (var j = 0; j < arguments.length; j++) {
						logParts.push(asText(arguments[j]));
					}
					var logMessage = logParts.join(' ');
					if (isFunctionalFailureMessage(logMessage)) {
						window.__ultracacheRuntimeJsScan.debug.consoleFunctionalLog = true;
						var logStack = (new Error()).stack || '';
						addError('console-functional-failure', logMessage, sourceFromStack(logStack), 0, 0, logStack);
					}
				} catch (e) {}
				return originalLog.apply(window.console, arguments);
			};
		}
		window.console.__ultracacheRuntimeScanWrapped = true;
	}

	function delayedRuntimeScriptsComplete() {
		try {
			var root = document.documentElement || document.body;
			if (root && root.getAttribute && root.getAttribute('data-ultracache-delay-all-done')) {
				return true;
			}
			var nodes = document.querySelectorAll('script[type="text/ultracache-delayed-js"][data-ultracache-src],script[type="text/ultracache-delayed-js"][data-ultracache-inline="1"]');
			if (!nodes || !nodes.length || !window.__ultracacheDelayLoader) {
				return true;
			}
			for (var i = 0; i < nodes.length; i++) {
				if (nodes[i].getAttribute('data-ultracache-loaded') !== '1') {
					return false;
				}
			}
			return true;
		} catch (e) {
			return true;
		}
	}

	function queueSettleMicrotask(callback) {
		if (window.queueMicrotask) {
			window.queueMicrotask(callback);
			return;
		}
		if (window.Promise && typeof window.Promise.resolve === 'function') {
			window.Promise.resolve().then(callback);
			return;
		}
		originalSetTimeout(callback, 0);
	}

	function queueSettleTask(callback) {
		if (window.MessageChannel) {
			try {
				var channel = new MessageChannel();
				channel.port1.onmessage = function () {
					try { channel.port1.close(); } catch (e) {}
					try { channel.port2.close(); } catch (e) {}
					callback();
				};
				channel.port2.postMessage(1);
				return;
			} catch (e) {}
		}
		originalSetTimeout(callback, 0);
	}

	function queueSettleFrame(callback) {
		if (typeof window.requestAnimationFrame === 'function') {
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(callback);
			});
			return;
		}
		queueSettleTask(callback);
	}

	function pendingObservedTimeoutHorizon() {
		var current = now();
		var horizon = current;
		Object.keys(observedTimeouts).forEach(function (key) {
			var due = Number(observedTimeouts[key] || 0);
			if (due > current && due > horizon && due <= current + 10000) {
				horizon = due;
			}
		});
		return horizon;
	}

	function settleRuntimeLifecycleRound(reason) {
		if (finalReportSent) {
			return;
		}
		settleRounds++;
		var generationBefore = errorGeneration;
		window.__ultracacheRuntimeJsScan.debug.settling = true;
		window.__ultracacheRuntimeJsScan.debug.settleReason = String(reason || 'runtime-lifecycle');
		window.__ultracacheRuntimeJsScan.debug.settleRounds = settleRounds;

		queueSettleMicrotask(function () {
			queueSettleFrame(function () {
				queueSettleTask(function () {
					queueSettleMicrotask(function () {
						if (errorGeneration === generationBefore) {
							settleQuietRounds++;
						} else {
							settleQuietRounds = 0;
						}
						window.__ultracacheRuntimeJsScan.debug.settleQuietRounds = settleQuietRounds;

						// Require two complete browser event-loop/render passes with no new
						// runtime errors. This catches observer/callback errors that fire just
						// after the delayed-script loader reports done, without using a fixed
						// wall-clock wait. The bounded round cap prevents pathological pages
						// with continuously unique errors from keeping the scanner open forever.
						if (settleQuietRounds >= 2 || settleRounds >= 8) {
							var current = now();
							if (settleTimerObservationUntil > current) {
								window.__ultracacheRuntimeJsScan.debug.timeoutObservationRemainingMs = Math.max(0, settleTimerObservationUntil - current);
								originalSetTimeout(function () {
									settleRuntimeLifecycleRound(reason);
								}, Math.min(250, Math.max(1, settleTimerObservationUntil - current)));
								return;
							}
							settleScheduled = false;
							window.__ultracacheRuntimeJsScan.debug.settling = false;
							finalizeReport();
							return;
						}
						settleRuntimeLifecycleRound(reason);
					});
				});
			});
		});
	}

	function scheduleSettledFinalize(reason) {
		if (finalReportSent || settleScheduled) {
			return;
		}
		settleScheduled = true;
		settleRounds = 0;
		settleQuietRounds = 0;
		settleTimerObservationUntil = pendingObservedTimeoutHorizon();
		window.__ultracacheRuntimeJsScan.debug.timeoutObservationUntil = settleTimerObservationUntil;
		settleRuntimeLifecycleRound(reason);
	}

	function finalizeFromRuntimeLifecycle() {
		if (delayedScriptsDoneObserved || delayedRuntimeScriptsComplete()) {
			scheduleSettledFinalize('window-load');
		}
	}

	window.addEventListener('ultracache:delayed-scripts-done', function () {
		delayedScriptsDoneObserved = true;
		if (document.readyState === 'complete') {
			scheduleSettledFinalize('delayed-scripts-done');
		}
	}, false);

	// Runtime Scan is a collector only. Complete from real browser/runtime lifecycle
	// state: normal pages complete at window load, while UltraCache-delayed pages
	// complete when the delayed-script loader reports its terminal done event. Before
	// sending the one final report, allow the browser event loop/render/observer cycle
	// to become quiet. If the page already scheduled bounded one-shot timers (for
	// example an SDK/provider timeout check), observe their existing due time too so
	// late functional failures are not lost. No visitor runtime is changed.
	if (document.readyState === 'complete') {
		finalizeFromRuntimeLifecycle();
	} else {
		window.addEventListener('load', finalizeFromRuntimeLifecycle, { once: true });
	}
}());
