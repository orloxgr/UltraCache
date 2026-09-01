(function () {
	'use strict';

	if (window.__ultracacheDelayLoader) {
		return;
	}

	window.__ultracacheDelayLoader = 1;

	var config = window.ultracacheDelayedJsLoaderConfig || {};
	var relief = !!config.relief;
	var autoEvents = Array.isArray(config.autoEvents) ? config.autoEvents : [];
	var autoAfterLoad = !!config.autoAfterLoad;
	var autoTimerEnabled = config.autoTimerEnabled !== false;
	var autoDelayMs = typeof config.autoDelayMs === 'number' ? config.autoDelayMs : parseInt(config.autoDelayMs || 50, 10);
	var minimumReleaseMs = typeof config.minimumReleaseMs === 'number' ? config.minimumReleaseMs : parseInt(config.minimumReleaseMs || 0, 10);
	var runtimeScanInfiniteTriggerMs = typeof config.runtimeScanInfiniteTriggerMs === 'number' ? config.runtimeScanInfiniteTriggerMs : parseInt(config.runtimeScanInfiniteTriggerMs || 0, 10);
	var scriptTimeoutMs = typeof config.scriptTimeoutMs === 'number' ? config.scriptTimeoutMs : parseInt(config.scriptTimeoutMs || 8000, 10);
	var firstPartyParallelExecution = !!config.firstPartyParallelExecution;
	var thirdPartyParallelExecution = !!config.thirdPartyParallelExecution;
	var orderedFetchEnabled = config.orderedFetchEnabled !== false;
	var orderedFetchConcurrency = typeof config.orderedFetchConcurrency === 'number' ? config.orderedFetchConcurrency : parseInt(config.orderedFetchConcurrency || 4, 10);
	var allDone = false;
	var interactionTriggerPending = false;
	var interactionReleaseInProgress = false;
	var fullTriggerPending = false;
	var firstPartyLaneCompleted = false;
	var fullRunCompleted = false;
	var interactionWaitForFullRun = false;
	var pendingInteractionReplay = null;
	var replayDispatchInProgress = false;
	var started = Date.now ? Date.now() : 0;
	var readyActive = false;
	var readyHooked = false;
	var readyHookTarget = null;
	var readyQueue = [];
	var readyOriginal = null;
	var readyThenHooked = false;
	var readyThenTarget = null;
	var readyThenOriginal = null;
	var executedScriptKeys = Object.create(null);
	var skippedDetached = 0;
	var skippedDuplicate = 0;
	var delayedPreDependencyInlinesByHandle = Object.create(null);
	var preDependencyInlineCompleted = 0;
	var scriptExecutionSequence = 0;
	var dynamicScriptFinder = window.__ultracacheDynamicScriptFinderV31211 || null;
	var dynamicBootstrapFlushReady = false;
	var dynamicBootstrapFullRequestPending = false;
	var dynamicBootstrapInteractionPending = false;
	var minimumInteractionRequestPending = false;
	var minimumFullRequestPending = false;
	var minimumGateTimer = null;

	function setDynamicScriptFinderReleasePhase(phase) {
		if (!dynamicScriptFinder || typeof dynamicScriptFinder.setReleasePhase !== 'function') {
			return;
		}
		try {
			dynamicScriptFinder.setReleasePhase(phase);
		} catch (e) {}
	}

	function decodeBase64Json(raw) {
		raw = String(raw || '').trim();
		if (!raw) {
			return {};
		}
		try {
			raw = raw.replace(/-/g, '+').replace(/_/g, '/');
			while (raw.length % 4) {
				raw += '=';
			}
			var binary = atob(raw);
			var json = binary;
			if (typeof window.TextDecoder === 'function' && typeof window.Uint8Array === 'function') {
				var bytes = new window.Uint8Array(binary.length);
				for (var i = 0; i < binary.length; i++) {
					bytes[i] = binary.charCodeAt(i);
				}
				json = new window.TextDecoder('utf-8').decode(bytes);
			} else {
				try {
					json = decodeURIComponent(Array.prototype.map.call(binary, function (ch) {
						return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
					}).join(''));
				} catch (e) {}
			}
			var parsed = JSON.parse(json);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return {};
		}
	}

	var dynamicPolicy = decodeBase64Json(config.dynamicPolicyEncoded || '');
	var dynamicPolicyFlags = dynamicPolicy && typeof dynamicPolicy.flags === 'object' ? dynamicPolicy.flags : {};
	var dynamicPolicyPatterns = dynamicPolicy && typeof dynamicPolicy.patterns === 'object' ? dynamicPolicy.patterns : {};
	var dynamicPolicyRules = Array.isArray(dynamicPolicy.rules) ? dynamicPolicy.rules : [];

	function normalizeDynamicPatternList(list) {
		var seen = Object.create(null);
		return (Array.isArray(list) ? list : []).map(function (item) {
			return String(item || '').trim().toLowerCase();
		}).filter(function (item) {
			if (!item || seen[item]) {
				return false;
			}
			seen[item] = 1;
			return true;
		});
	}

	var dynamicNativePatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.native);
	var dynamicDeferPatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.defer);
	var dynamicSafePatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.safe);
	var dynamicFunctionalPatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.functional);
	var dynamicNonCriticalPatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.nonCritical);
	var dynamicRealCookieBannerInfrastructurePatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.realCookieBannerInfrastructure);
	var dynamicComplianzInfrastructurePatterns = normalizeDynamicPatternList(dynamicPolicyPatterns.complianzInfrastructure);

	if (!isFinite(autoDelayMs) || autoDelayMs < 0) {
		autoDelayMs = 50;
	}

	if (!isFinite(minimumReleaseMs) || minimumReleaseMs < 0) {
		minimumReleaseMs = 0;
	}

	if (minimumReleaseMs > 4000) {
		minimumReleaseMs = 4000;
	}

	if (!isFinite(runtimeScanInfiniteTriggerMs) || runtimeScanInfiniteTriggerMs < 0) {
		runtimeScanInfiniteTriggerMs = 0;
	}

	if (runtimeScanInfiniteTriggerMs > 30000) {
		runtimeScanInfiniteTriggerMs = 30000;
	}

	if (!isFinite(scriptTimeoutMs) || scriptTimeoutMs < 1000) {
		scriptTimeoutMs = 8000;
	}

	if (scriptTimeoutMs > 30000) {
		scriptTimeoutMs = 30000;
	}

	if (!isFinite(orderedFetchConcurrency) || orderedFetchConcurrency < 1) {
		orderedFetchConcurrency = 4;
	}

	if (orderedFetchConcurrency > 8) {
		orderedFetchConcurrency = 8;
	}

	function root() {
		return document.documentElement || document.body || document.head;
	}

	function mark(key, value) {
		try {
			var target = root();
			if (target) {
				target.setAttribute('data-ultracache-delay-' + key, String(value));
			}
		} catch (e) {}
	}

	/*
	 * 3.12.12 Minimum Delay Release. This is deliberately a gate, not a
	 * second release trigger. Existing Auto Release and interaction code still
	 * generate requests exactly as before; requests arriving before the minimum
	 * page-age threshold are retained and resumed when the gate opens.
	 */
	function pageNavigationStartMs() {
		try {
			if (window.performance) {
				if (typeof window.performance.timeOrigin === 'number' && isFinite(window.performance.timeOrigin) && window.performance.timeOrigin > 0) {
					return window.performance.timeOrigin;
				}
				if (window.performance.timing && typeof window.performance.timing.navigationStart === 'number' && window.performance.timing.navigationStart > 0) {
					return window.performance.timing.navigationStart;
				}
			}
		} catch (e) {}
		return started;
	}

	var minimumReleaseAtMs = pageNavigationStartMs() + minimumReleaseMs;

	function minimumReleaseRemainingMs() {
		if (minimumReleaseMs <= 0) {
			return 0;
		}
		var now = Date.now ? Date.now() : started;
		return Math.max(0, minimumReleaseAtMs - now);
	}

	function flushMinimumReleaseGate() {
		minimumGateTimer = null;
		var remaining = minimumReleaseRemainingMs();
		if (remaining > 0) {
			minimumGateTimer = setTimeout(flushMinimumReleaseGate, remaining);
			return;
		}

		mark('minimum-gate-open', '1');
		var interactionPending = minimumInteractionRequestPending;
		var fullPending = minimumFullRequestPending;
		minimumInteractionRequestPending = false;
		minimumFullRequestPending = false;
		mark('minimum-interaction-pending', '0');
		mark('minimum-full-pending', '0');
		mark('minimum-remaining-ms', '0');

		// Preserve the established interaction-first semantics. If Auto Release
		// also requested the full queue while the gate was closed, triggerAll()
		// observes interactionReleaseInProgress and queues the full run behind it.
		if (interactionPending) {
			startInteractionRelease();
		}
		if (fullPending) {
			triggerAll();
		}
	}

	function holdForMinimumRelease(kind) {
		var remaining = minimumReleaseRemainingMs();
		if (remaining <= 0) {
			return false;
		}

		if (kind === 'interaction') {
			minimumInteractionRequestPending = true;
			mark('minimum-interaction-pending', '1');
		} else {
			minimumFullRequestPending = true;
			mark('minimum-full-pending', '1');
		}
		mark('minimum-gate-open', '0');
		mark('minimum-remaining-ms', Math.ceil(remaining));

		if (minimumGateTimer === null) {
			minimumGateTimer = setTimeout(flushMinimumReleaseGate, remaining);
		}
		return true;
	}


	function queryDelayedScripts() {
		return Array.prototype.slice.call(
			document.querySelectorAll('script[type="text/ultracache-delayed-js"][data-ultracache-src],script[type="text/ultracache-delayed-js"][data-ultracache-inline="1"]')
		).filter(function (node) {
			var runtimeLane = node && node.getAttribute ? String(node.getAttribute('data-ultracache-runtime-lane') || '').toLowerCase() : '';
			return !runtimeLane || runtimeLane === 'delay' || runtimeLane === 'pending';
		});
	}

	function ultracacheData(node, attr) {
		var value = node && node.getAttribute ? node.getAttribute('data-ultracache-' + attr) : '';
		return value || '';
	}


	var inlineRegistryCache = null;

	function readInlineRegistry() {
		if (inlineRegistryCache && inlineRegistryCache.entries) {
			return inlineRegistryCache;
		}
		if (window.__ultracacheInlineRegistryV1 && window.__ultracacheInlineRegistryV1.entries) {
			inlineRegistryCache = window.__ultracacheInlineRegistryV1;
			return inlineRegistryCache;
		}

		var manifest = document.getElementById('ultracache-inline-registry-v1');
		if (!manifest) {
			return null;
		}
		try {
			var parsed = JSON.parse(manifest.textContent || '{}');
			if (!parsed || typeof parsed !== 'object' || !parsed.entries || typeof parsed.entries !== 'object') {
				return null;
			}
			inlineRegistryCache = parsed;
			window.__ultracacheInlineRegistryV1 = parsed;
			return inlineRegistryCache;
		} catch (e) {
			return null;
		}
	}

	function inlineRegistryEntry(node) {
		var key = ultracacheData(node, 'inline-registry-key');
		if (!key) {
			return null;
		}
		var registry = readInlineRegistry();
		return registry && registry.entries && registry.entries[key] ? registry.entries[key] : null;
	}

	function inlineNodeCode(node) {
		var entry = inlineRegistryEntry(node);
		if (entry && typeof entry.code === 'string') {
			return entry.code;
		}
		return String(node && node.textContent || '');
	}

	function normalizeDelayedHandle(value) {
		return String(value || '').trim().toLowerCase();
	}

	function delayedNodeHandle(node) {
		return normalizeDelayedHandle(ultracacheData(node, 'handle'));
	}

	function delayedNodeBeforeDependencies(node) {
		var raw = String(ultracacheData(node, 'before-deps') || '');
		if (!raw) {
			return [];
		}

		var seen = Object.create(null);
		return raw.split(',').map(normalizeDelayedHandle).filter(function (handle) {
			if (!handle || seen[handle]) {
				return false;
			}
			seen[handle] = 1;
			return true;
		});
	}


	function delayedNodeDependencies(node) {
		var raw = String(ultracacheData(node, 'deps') || '');
		if (!raw) {
			return [];
		}
		var seen = Object.create(null);
		return raw.split(',').map(normalizeDelayedHandle).filter(function (handle) {
			if (!handle || seen[handle]) {
				return false;
			}
			seen[handle] = 1;
			return true;
		});
	}

	function delayedNodeFamily(node, fallbackIndex) {
		var family = normalizeDelayedHandle(ultracacheData(node, 'family'));
		if (!family) {
			family = delayedNodeHandle(node);
		}
		return family || ('__uc_anon_' + String(fallbackIndex || 0));
	}

	function buildDelayedTopology(nodes) {
		var groups = [];
		var byKey = Object.create(null);
		var handleToKey = Object.create(null);
		var list = Array.isArray(nodes) ? nodes : [];

		for (var i = 0; i < list.length; i++) {
			var node = list[i];
			var key = delayedNodeFamily(node, i + 1);
			if (!byKey[key]) {
				byKey[key] = {
					key: key,
					firstIndex: i,
					nodes: [],
					deps: Object.create(null),
					thirdParty: false,
					functionalThirdParty: true
				};
				groups.push(byKey[key]);
			}
			var group = byKey[key];
			group.nodes.push(node);
			var handle = delayedNodeHandle(node);
			if (handle) {
				handleToKey[handle] = key;
			}
			delayedNodeDependencies(node).forEach(function (dependency) {
				group.deps[dependency] = true;
			});
			if (isThirdPartyDelayedNode(node)) {
				group.thirdParty = true;
				if (ultracacheData(node, 'delay-reason') !== 'functional-third-party') {
					group.functionalThirdParty = false;
				}
			}
		}

		groups.forEach(function (group) {
			var resolved = Object.create(null);
			Object.keys(group.deps).forEach(function (dependency) {
				var depKey = handleToKey[dependency] || (byKey[dependency] ? dependency : '');
				if (depKey && depKey !== group.key) {
					resolved[depKey] = true;
				}
			});
			group.depKeys = Object.keys(resolved);
		});

		return { groups: groups, byKey: byKey, handleToKey: handleToKey };
	}

	function stableOrderDelayedNodes(nodes) {
		var topology = buildDelayedTopology(nodes);
		var groups = topology.groups;
		if (groups.length < 2) {
			return Array.isArray(nodes) ? nodes.slice() : [];
		}

		var completed = Object.create(null);
		var orderedGroups = [];
		while (orderedGroups.length < groups.length) {
			var candidate = null;
			for (var i = 0; i < groups.length; i++) {
				var group = groups[i];
				if (completed[group.key]) {
					continue;
				}
				var ready = true;
				for (var j = 0; j < group.depKeys.length; j++) {
					if (!completed[group.depKeys[j]]) {
						ready = false;
						break;
					}
				}
				if (ready && (!candidate || group.firstIndex < candidate.firstIndex)) {
					candidate = group;
				}
			}

			if (!candidate) {
				// Cycles or missing metadata: preserve original DOM order rather than
				// inventing an execution order.
				for (var k = 0; k < groups.length; k++) {
					if (!completed[groups[k].key] && (!candidate || groups[k].firstIndex < candidate.firstIndex)) {
						candidate = groups[k];
					}
				}
			}
			if (!candidate) {
				break;
			}
			completed[candidate.key] = true;
			orderedGroups.push(candidate);
		}

		var out = [];
		orderedGroups.forEach(function (group) {
			group.nodes.forEach(function (node) { out.push(node); });
		});
		return out;
	}

	function splitDelayedFullReleaseLanes(nodes) {
		var ordered = stableOrderDelayedNodes(nodes);
		var topology = buildDelayedTopology(ordered);
		var late = Object.create(null);

		topology.groups.forEach(function (group) {
			if (group.thirdParty) {
				late[group.key] = true;
			}
		});

		var changed = true;
		while (changed) {
			changed = false;
			topology.groups.forEach(function (group) {
				if (late[group.key]) {
					return;
				}
				for (var i = 0; i < group.depKeys.length; i++) {
					if (late[group.depKeys[i]]) {
						late[group.key] = true;
						changed = true;
						break;
					}
				}
			});
		}

		var earlyNodes = [];
		var lateNodes = [];
		topology.groups.forEach(function (group) {
			var target = late[group.key] ? lateNodes : earlyNodes;
			group.nodes.forEach(function (node) { target.push(node); });
		});
		return { ordered: ordered, early: earlyNodes, late: lateNodes };
	}

	function dependencyAwareInteractionNodes(nodes) {
		var ordered = stableOrderDelayedNodes(nodes);
		var topology = buildDelayedTopology(ordered);
		var eligible = Object.create(null);

		topology.groups.forEach(function (group) {
			eligible[group.key] = group.nodes.every(interactionNodeIsEligible);
		});

		var changed = true;
		while (changed) {
			changed = false;
			topology.groups.forEach(function (group) {
				if (!eligible[group.key]) {
					return;
				}
				for (var i = 0; i < group.depKeys.length; i++) {
					if (Object.prototype.hasOwnProperty.call(eligible, group.depKeys[i]) && !eligible[group.depKeys[i]]) {
						eligible[group.key] = false;
						changed = true;
						break;
					}
				}
			});
		}

		var out = [];
		topology.groups.forEach(function (group) {
			if (!eligible[group.key]) {
				return;
			}
			group.nodes.forEach(function (node) { out.push(node); });
		});
		return out;
	}

	function preparePreDependencyInlineIndex(nodes) {
		delayedPreDependencyInlinesByHandle = Object.create(null);

		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (!isInlineNode(node)) {
				continue;
			}

			var dependencies = delayedNodeBeforeDependencies(node);
			for (var dependencyIndex = 0; dependencyIndex < dependencies.length; dependencyIndex++) {
				var dependency = dependencies[dependencyIndex];
				if (!delayedPreDependencyInlinesByHandle[dependency]) {
					delayedPreDependencyInlinesByHandle[dependency] = [];
				}
				delayedPreDependencyInlinesByHandle[dependency].push(node);
			}
		}
	}

	function isThirdPartyDelayReason(reason) {
		return reason === 'safe-third-party' || reason === 'functional-third-party' || reason === 'all-third-party';
	}

	function isSameOriginScriptSrc(value) {
		if (!value) {
			return true;
		}

		try {
			return new URL(value, document.baseURI || window.location.href).origin === window.location.origin;
		} catch (e) {
			return true;
		}
	}

	function dynamicIdentifierBoundaryMatch(haystack, pattern) {
		var from = 0;
		var index;
		while ((index = haystack.indexOf(pattern, from)) !== -1) {
			if (index === 0 || !/[a-z0-9_]/i.test(haystack.charAt(index - 1))) {
				return true;
			}
			from = index + 1;
		}
		return false;
	}

	function dynamicPatternMatches(haystack, pattern) {
		haystack = String(haystack || '').toLowerCase();
		pattern = String(pattern || '').trim().toLowerCase();
		if (!haystack || !pattern) {
			return false;
		}
		if (/^[a-z0-9_-]+[-_]$/.test(pattern)) {
			return dynamicIdentifierBoundaryMatch(haystack, pattern);
		}
		return haystack.indexOf(pattern) !== -1;
	}

	function dynamicFirstPattern(haystacks, patterns) {
		for (var i = 0; i < patterns.length; i++) {
			for (var j = 0; j < haystacks.length; j++) {
				if (dynamicPatternMatches(haystacks[j], patterns[i])) {
					return patterns[i];
				}
			}
		}
		return '';
	}

	function decodeDynamicOriginalAttributes(node) {
		var raw = String(ultracacheData(node, 'attrs') || '').trim();
		if (!raw) {
			return {};
		}
		try {
			var binary = atob(raw);
			var json = decodeURIComponent(Array.prototype.map.call(binary, function (ch) {
				return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
			}).join(''));
			var parsed = JSON.parse(json);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return {};
		}
	}

	function dynamicCandidateHaystacks(node, prospectiveSrc) {
		var src = String(prospectiveSrc || ultracacheData(node, 'src') || (node && node.getAttribute ? node.getAttribute('src') : '') || '');
		var code = !src ? String(node && (node.text || node.textContent) || '') : '';
		var attrs = '';
		var originalAttrs = decodeDynamicOriginalAttributes(node);
		if (node && node.attributes) {
			try {
				for (var i = 0; i < node.attributes.length; i++) {
					var attr = node.attributes[i];
					if (attr && attr.name) {
						attrs += ' ' + attr.name + '=' + String(attr.value || '');
					}
				}
			} catch (e) {}
		}
		Object.keys(originalAttrs).forEach(function (name) {
			attrs += ' ' + name + '=' + String(originalAttrs[name] || '');
		});
		return [src, node && node.getAttribute ? String(node.getAttribute('id') || '') : '', attrs, code];
	}

	function dynamicIsThirdParty(src) {
		if (!src) {
			return false;
		}
		try {
			return new URL(src, document.baseURI || window.location.href).origin !== window.location.origin;
		} catch (e) {
			return false;
		}
	}

	function dynamicIsSameOriginWpContent(src) {
		if (!src || dynamicIsThirdParty(src)) {
			return false;
		}
		try {
			return String(new URL(src, document.baseURI || window.location.href).pathname || '').toLowerCase().indexOf('/wp-content/') !== -1;
		} catch (e) {
			return false;
		}
	}

	function dynamicDelayAllCandidate(node, prospectiveSrc) {
		var src = String(prospectiveSrc || ultracacheData(node, 'src') || (node && node.getAttribute ? node.getAttribute('src') : '') || '');
		if (!src) {
			return false;
		}
		var originalAttrs = decodeDynamicOriginalAttributes(node);
		var currentType = node && node.getAttribute ? String(node.getAttribute('type') || '') : '';
		var originalType = String(ultracacheData(node, 'original-type') || originalAttrs.type || currentType || '').toLowerCase();
		if (originalType === 'module') {
			return false;
		}
		if (Object.prototype.hasOwnProperty.call(originalAttrs, 'async') || Object.prototype.hasOwnProperty.call(originalAttrs, 'defer') || Object.prototype.hasOwnProperty.call(originalAttrs, 'nomodule')) {
			return false;
		}
		if (node && node.hasAttribute && (node.hasAttribute('async') || node.hasAttribute('defer') || node.hasAttribute('nomodule'))) {
			return false;
		}
		return true;
	}

	function dynamicRouteFromRule(rule, factValue, facts) {
		var route = {
			lane: String(rule.lane || 'native'),
			reason: String(rule.reason || 'unified-policy'),
			action: String(rule.action || 'unchanged'),
			interactionEligible: true,
			ruleId: String(rule.id || '')
		};
		if (typeof factValue === 'string' && factValue) {
			route.matchedPattern = factValue;
		} else if (rule.matchedPattern) {
			route.matchedPattern = String(rule.matchedPattern);
		}
		if (rule.interactionEligible === 'same-origin-only') {
			route.interactionEligible = !facts.isThirdParty;
		} else if (rule.interactionEligible === false) {
			route.interactionEligible = false;
		} else if (rule.interactionEligible === true) {
			route.interactionEligible = true;
		}
		return route;
	}

	function evaluateUnifiedDynamicPolicy(facts) {
		for (var i = 0; i < dynamicPolicyRules.length; i++) {
			var rule = dynamicPolicyRules[i] || {};
			var flag = String(rule.flag || '');
			var requiresFlag = String(rule.requiresFlag || '');
			var requiresFlags = Array.isArray(rule.requiresFlags) ? rule.requiresFlags : [];
			if (flag && !dynamicPolicyFlags[flag]) {
				continue;
			}
			if (requiresFlag && !dynamicPolicyFlags[requiresFlag]) {
				continue;
			}
			var missingRequiredFlag = false;
			for (var requiredIndex = 0; requiredIndex < requiresFlags.length; requiredIndex++) {
				var requiredFlag = String(requiresFlags[requiredIndex] || '');
				if (requiredFlag && !dynamicPolicyFlags[requiredFlag]) {
					missingRequiredFlag = true;
					break;
				}
			}
			if (missingRequiredFlag) {
				continue;
			}

			var kind = String(rule.kind || '');
			var factName = String(rule.fact || '');
			var factValue = factName ? facts[factName] : null;

			if (kind === 'route-fact') {
				if (factValue && typeof factValue === 'object' && factValue.lane) {
					var factRoute = {};
					Object.keys(factValue).forEach(function (key) { factRoute[key] = factValue[key]; });
					if (!factRoute.ruleId && rule.id) {
						factRoute.ruleId = String(rule.id);
					}
					return factRoute;
				}
				continue;
			}
			if (kind === 'flag-route' || kind === 'default-route') {
				if (rule.route && typeof rule.route === 'object' && rule.route.lane) {
					return {
						lane: String(rule.route.lane),
						reason: String(rule.route.reason || 'unified-policy'),
						action: String(rule.route.action || 'unchanged'),
						interactionEligible: rule.route.interactionEligible !== false,
						ruleId: String(rule.id || '')
					};
				}
				continue;
			}
			if (kind === 'pattern-fact' || kind === 'flag-pattern-fact') {
				if (typeof factValue !== 'string' || !factValue) {
					continue;
				}
				return dynamicRouteFromRule(rule, factValue, facts);
			}
			if (kind === 'flag-bool-fact') {
				if (!factValue) {
					continue;
				}
				return dynamicRouteFromRule(rule, factValue, facts);
			}
		}
		return { lane: 'native', reason: 'unified-policy-failsafe', action: 'unchanged', interactionEligible: true, ruleId: 'failsafe' };
	}


	function classifyDynamicScript(node, prospectiveSrc) {
		var src = String(prospectiveSrc || ultracacheData(node, 'src') || (node && node.getAttribute ? node.getAttribute('src') : '') || '');
		var haystacks = dynamicCandidateHaystacks(node, src);
		var thirdParty = dynamicIsThirdParty(src);
		var realCookieBannerInfrastructurePattern = dynamicPolicyFlags.realCookieBannerCompatibility
			? dynamicFirstPattern(haystacks, dynamicRealCookieBannerInfrastructurePatterns)
			: '';
		var complianzInfrastructurePattern = dynamicPolicyFlags.complianzCompatibility
			? dynamicFirstPattern(haystacks, dynamicComplianzInfrastructurePatterns)
			: '';
		var consentInfrastructurePattern = realCookieBannerInfrastructurePattern || complianzInfrastructurePattern;
		var safePattern = consentInfrastructurePattern ? '' : dynamicFirstPattern(haystacks, dynamicSafePatterns);
		var functionalPattern = consentInfrastructurePattern ? '' : dynamicFirstPattern(haystacks, dynamicFunctionalPatterns);
		var facts = {
			visibleNativePattern: dynamicFirstPattern(haystacks, dynamicNativePatterns),
			visibleDeferPattern: dynamicFirstPattern(haystacks, dynamicDeferPatterns),
			explicitIntegrationRoute: realCookieBannerInfrastructurePattern ? {
				lane: 'native',
				reason: 'explicit-real-cookie-banner-compatibility',
				action: 'unchanged',
				interactionEligible: true,
				matchedPattern: realCookieBannerInfrastructurePattern
			} : (complianzInfrastructurePattern ? {
				lane: 'native',
				reason: 'explicit-complianz-compatibility',
				action: 'unchanged',
				interactionEligible: true,
				matchedPattern: complianzInfrastructurePattern
			} : null),
			delayAllCandidate: dynamicDelayAllCandidate(node, src),
			safePattern: safePattern,
			functionalPattern: functionalPattern,
			isThirdParty: !!(src && thirdParty),
			nonCriticalPattern: !consentInfrastructurePattern && src && !thirdParty ? dynamicFirstPattern(haystacks, dynamicNonCriticalPatterns) : '',
			aggressiveNonCriticalCandidate: !!(!consentInfrastructurePattern && src && !thirdParty && dynamicIsSameOriginWpContent(src)),
			asyncRoute: null
		};
		return evaluateUnifiedDynamicPolicy(facts);
	}

	function executeClassifiedDynamicScript(node, route) {
		if (!node || !route || ['defer', 'delay'].indexOf(String(route.lane || '').toLowerCase()) === -1) {
			return;
		}
		var lane = String(route.lane || '').toLowerCase();
		preparePreDependencyInlineIndex([node]);
		load([node], 0, lane === 'defer' ? 'dynamic-defer' : 'dynamic-delay', function () {});
	}

	if (dynamicScriptFinder && typeof dynamicScriptFinder.setExecutor === 'function') {
		try {
			dynamicScriptFinder.setExecutor(executeClassifiedDynamicScript);
		} catch (e) {}
	}

	if (dynamicScriptFinder && typeof dynamicScriptFinder.setClassifier === 'function') {
		try {
			dynamicScriptFinder.setClassifier(classifyDynamicScript);
		} catch (e) {}
	}

	function isThirdPartyDelayedNode(node) {
		var reason = ultracacheData(node, 'delay-reason');
		if (isThirdPartyDelayReason(reason)) {
			return true;
		}

		if (isExternalNode(node) && !isSameOriginScriptSrc(node.getAttribute('data-ultracache-src'))) {
			return true;
		}

		return false;
	}

	function counts() {
		var all = queryDelayedScripts();
		var thirdParty = 0;
		var local = 0;

		for (var i = 0; i < all.length; i++) {
			if (isThirdPartyDelayedNode(all[i])) {
				thirdParty++;
			} else {
				local++;
			}
		}

		mark('queued', all.length);
		mark('queued-local', local);
		mark('queued-thirdparty', thirdParty);
	}

	function decodeAttrs(node) {
		var raw = ultracacheData(node, 'attrs');
		var attrs = {};

		if (raw) {
			attrs = decodeBase64Json(raw);
		}

		['id', 'crossorigin', 'referrerpolicy', 'integrity', 'nonce'].forEach(function (attr) {
			var value = ultracacheData(node, attr);
			if (value && !attrs[attr]) {
				attrs[attr] = value;
			}
		});

		return attrs;
	}

	function applyAttrs(script, node) {
		var attrs = decodeAttrs(node);
		Object.keys(attrs).forEach(function (attr) {
			var value = attrs[attr];
			if (!attr || attr === 'src' || attr === 'async' || attr === 'defer' || attr === 'data-wp-strategy' || value === null || typeof value === 'undefined') {
				return;
			}

			try {
				script.setAttribute(attr, String(value));
			} catch (e) {}
		});

		// Preserve execution provenance after replacing the inert delayed
		// placeholder with its executable script. Runtime diagnostics run after
		// this replacement and otherwise see an ordered replacement as a normal
		// blocking script (or a parallel replacement only as async), losing the
		// fact that UltraCache delayed it in the first/third-party loader lanes.
		// The marker is diagnostic metadata only; it does not affect execution.
		try {
			script.setAttribute('data-ultracache-delayed', '1');
			var handle = node && node.getAttribute ? String(node.getAttribute('data-ultracache-handle') || '') : '';
			if (handle) {
				script.setAttribute('data-ultracache-handle', handle);
			}
			var deps = node && node.getAttribute ? String(node.getAttribute('data-ultracache-deps') || '') : '';
			if (deps) {
				script.setAttribute('data-ultracache-deps', deps);
			}
			['lane', 'reason', 'source', 'caught-by', 'rule', 'pattern', 'handle'].forEach(function (auditField) {
				var auditValue = node && node.getAttribute ? String(node.getAttribute('data-ultracache-audit-' + auditField) || '') : '';
				if (auditValue) {
					script.setAttribute('data-ultracache-audit-' + auditField, auditValue);
				}
			});
			['lane', 'reason', 'caught-by', 'rule', 'pattern'].forEach(function (runtimeField) {
				var runtimeValue = node && node.getAttribute ? String(node.getAttribute('data-ultracache-runtime-' + runtimeField) || '') : '';
				if (runtimeValue) {
					script.setAttribute('data-ultracache-runtime-' + runtimeField, runtimeValue);
				}
			});
			if (node && node.getAttribute && node.getAttribute('data-ultracache-runtime-seen') === '1') {
				script.setAttribute('data-ultracache-runtime-seen', '1');
			}
		} catch (e) {}
	}

	function idle(callback) {
		if (!relief) {
			callback();
			return;
		}

		if ('requestIdleCallback' in window) {
			window.requestIdleCallback(callback, { timeout: 1200 });
			return;
		}

		setTimeout(callback, 60);
	}

	function wait(ms, callback) {
		if (!relief || ms <= 0) {
			callback();
			return;
		}

		setTimeout(callback, ms);
	}

	function emit(name, detail) {
		try {
			window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
		} catch (e) {}
	}

	function tryHookReady() {
		var jq = window.jQuery;
		if (!readyActive || !jq) {
			return;
		}

		if (!readyHooked && jq.fn && typeof jq.fn.ready === 'function') {
			readyHookTarget = jq.fn;
			readyOriginal = jq.fn.ready;
			var originalReady = readyOriginal;
			jq.fn.ready = function (fn) {
				if (readyActive && typeof fn === 'function') {
					readyQueue.push({ fn: fn, jq: jq, type: 'ready' });
					mark('ready-held', readyQueue.length);
					return this;
				}

				return originalReady.apply(this, arguments);
			};

			readyHooked = true;
			mark('ready-hooked', '1');
		}

		if (!readyThenHooked && jq.ready && typeof jq.ready.then === 'function' && typeof jq.Deferred === 'function') {
			readyThenTarget = jq.ready;
			readyThenOriginal = jq.ready.then;
			var originalReadyThen = readyThenOriginal;
			jq.ready.then = function (onFulfilled) {
				if (!readyActive || typeof onFulfilled !== 'function') {
					return originalReadyThen.apply(this, arguments);
				}

				var deferred = jq.Deferred();
				readyQueue.push({ fn: onFulfilled, jq: jq, type: 'then', deferred: deferred });
				mark('ready-held', readyQueue.length);
				return deferred.promise();
			};

			readyThenHooked = true;
			mark('ready-then-hooked', '1');
		}
	}

	function beginReadyHold() {
		readyActive = true;
		mark('ready-hold', '1');
		tryHookReady();
	}

	function flushReadyHold(mode) {
		tryHookReady();
		readyActive = false;

		var jq = window.jQuery;
		if (readyHooked && readyHookTarget && readyOriginal) {
			try {
				readyHookTarget.ready = readyOriginal;
			} catch (e) {}
		}
		if (readyThenHooked && readyThenTarget && readyThenOriginal) {
			try {
				readyThenTarget.then = readyThenOriginal;
			} catch (e) {}
		}

		readyHooked = false;
		readyHookTarget = null;
		readyOriginal = null;
		readyThenHooked = false;
		readyThenTarget = null;
		readyThenOriginal = null;
		mark('ready-hold', '0');
		mark('ready-flush-count', readyQueue.length);

		var queue = readyQueue.slice(0);
		readyQueue = [];
		emit('ultracache:delayed-jquery-ready-flush', { mode: mode || 'first-party', count: queue.length });

		for (var i = 0; i < queue.length; i++) {
			var item = queue[i];
			var itemJq = item.jq || jq;
			try {
				var value = item.fn.call(document, itemJq);
				if (item.deferred) {
					if (value && typeof value.then === 'function') {
						value.then((function (deferred) {
							return function (resolvedValue) {
								deferred.resolveWith(document, [resolvedValue]);
							};
						})(item.deferred), (function (deferred) {
							return function (error) {
								deferred.rejectWith(document, [error]);
							};
						})(item.deferred));
					} else {
						item.deferred.resolveWith(document, [value]);
					}
				}
			} catch (err) {
				if (item.deferred) {
					item.deferred.rejectWith(document, [err]);
					continue;
				}
				setTimeout((function (error) {
					return function () {
						throw error;
					};
				})(err), 0);
			}
		}
	}

	function nodeIsConnected(node) {
		if (!node) {
			return false;
		}

		if (typeof node.isConnected === 'boolean') {
			return node.isConnected;
		}

		return !!node.parentNode;
	}

	function normalizeScriptUrl(value) {
		value = String(value || '');
		if (!value) {
			return '';
		}

		try {
			return new URL(value, document.baseURI || window.location.href).href;
		} catch (e) {
			return value;
		}
	}

	function textFingerprint(value) {
		value = String(value || '');
		var hash = 2166136261;
		for (var i = 0; i < value.length; i++) {
			hash ^= value.charCodeAt(i);
			hash = Math.imul(hash, 16777619);
		}
		return (hash >>> 0).toString(16);
	}

	function scriptMode(node, attrs) {
		attrs = attrs || {};
		var nodeType = String(node && node.getAttribute ? node.getAttribute('type') || '' : '').toLowerCase();
		if (nodeType === 'text/ultracache-delayed-js') {
			nodeType = '';
		}
		var type = Object.prototype.hasOwnProperty.call(attrs, 'type') ? String(attrs.type || '').toLowerCase() : nodeType;
		var noModule = !!(attrs.nomodule || (node && node.hasAttribute && node.hasAttribute('nomodule')));
		return type + '|nomodule:' + (noModule ? '1' : '0');
	}

	function scriptExecutionKeys(node) {
		var keys = [];
		var attrs = decodeAttrs(node);
		var id = attrs.id || ultracacheData(node, 'id') || '';
		var handle = ultracacheData(node, 'handle');
		var mode = scriptMode(node, attrs);

		if (id) {
			keys.push('id:' + id);
		}

		if (isExternalNode(node)) {
			var src = normalizeScriptUrl(node.getAttribute('data-ultracache-src'));
			if (src) {
				keys.push('src:' + src + '|mode:' + mode);
			}
		} else if (isInlineNode(node)) {
			var code = inlineNodeCode(node);
			var fingerprint = textFingerprint(code);
			if (handle) {
				keys.push('inline:' + handle + ':' + fingerprint + '|mode:' + mode);
			} else if (id) {
				keys.push('inline-id:' + id + ':' + fingerprint + '|mode:' + mode);
			}
		}

		return keys;
	}

	function hasExecutableDuplicate(node) {
		var keys = scriptExecutionKeys(node);
		for (var i = 0; i < keys.length; i++) {
			if (executedScriptKeys[keys[i]]) {
				return true;
			}
		}

		var attrs = decodeAttrs(node);
		var id = attrs.id || ultracacheData(node, 'id') || '';
		var src = isExternalNode(node) ? normalizeScriptUrl(node.getAttribute('data-ultracache-src')) : '';
		var mode = scriptMode(node, attrs);
		var scripts = document.querySelectorAll('script:not([type="text/ultracache-delayed-js"])');

		for (var j = 0; j < scripts.length; j++) {
			var candidate = scripts[j];
			if (!candidate || candidate === node) {
				continue;
			}

			if (id && candidate.id === id) {
				return true;
			}

			if (src && normalizeScriptUrl(candidate.getAttribute('src')) === src && scriptMode(candidate, {}) === mode) {
				return true;
			}
		}

		return false;
	}

	function claimScriptExecution(node) {
		var keys = scriptExecutionKeys(node);
		for (var i = 0; i < keys.length; i++) {
			executedScriptKeys[keys[i]] = 1;
		}
	}

	function discardDelayedNode(node, reason) {
		if ('detached' === reason) {
			skippedDetached++;
			mark('skipped-detached', skippedDetached);
		} else if ('duplicate' === reason) {
			skippedDuplicate++;
			mark('skipped-duplicate', skippedDuplicate);
		}

		if (node && node.parentNode) {
			node.parentNode.removeChild(node);
		}
	}

	function replaceDelayedNode(node, script) {
		if (!nodeIsConnected(node) || !node.parentNode) {
			discardDelayedNode(node, 'detached');
			return false;
		}

		node.parentNode.replaceChild(script, node);
		return true;
	}

	function isRuntimeCreatedDelayedNode(node) {
		return !!(node && node.getAttribute && node.getAttribute('data-ultracache-dynamic') === '1');
	}

	function restoreRuntimeCreatedScriptType(node) {
		if (!node || !node.setAttribute || !node.removeAttribute) {
			return;
		}

		var originalType = String(ultracacheData(node, 'original-type') || '');
		if (originalType) {
			node.setAttribute('type', originalType);
		} else {
			node.removeAttribute('type');
		}
	}

	function runtimeCreatedInsertionPoint(node) {
		if (!nodeIsConnected(node) || !node.parentNode) {
			return null;
		}

		return { parent: node.parentNode, next: node.nextSibling };
	}

	function reinsertRuntimeCreatedNode(node, point) {
		if (!node || !point || !point.parent) {
			return false;
		}

		try {
			var before = point.next && point.next.parentNode === point.parent ? point.next : null;
			point.parent.insertBefore(node, before);
			return true;
		} catch (e) {
			return false;
		}
	}

	function reviveRuntimeCreatedInlineNode(node, code) {
		var point = runtimeCreatedInsertionPoint(node);
		if (!point) {
			discardDelayedNode(node, 'detached');
			return false;
		}

		try {
			node.setAttribute('data-ultracache-delayed', '1');
			point.parent.removeChild(node);
			applyAttrs(node, node);
			restoreRuntimeCreatedScriptType(node);
			node.text = String(code || '');
		} catch (e) {
			return false;
		}

		return reinsertRuntimeCreatedNode(node, point);
	}

	function reviveRuntimeCreatedExternalNode(node, src, onLoaded, onError) {
		var point = runtimeCreatedInsertionPoint(node);
		if (!point) {
			discardDelayedNode(node, 'detached');
			return false;
		}

		try {
			node.setAttribute('data-ultracache-delayed', '1');
			point.parent.removeChild(node);
			applyAttrs(node, node);
			restoreRuntimeCreatedScriptType(node);

			// Preserve the script creator's completion contract. Runtime loaders often
			// resolve Promises from handlers/listeners attached to the original element
			// before insertion, so UltraCache must not replace that DOM object.
			if (typeof node.addEventListener === 'function') {
				node.addEventListener('load', onLoaded, { once: true });
				node.addEventListener('error', onError, { once: true });
			}

			// Setting src while detached avoids a second Dynamic Finder classification.
			// Reinsert the same executable element at its original DOM position so the
			// browser fires load/error on the exact object retained by the caller.
			node.src = String(src || '');
		} catch (e) {
			return false;
		}

		return reinsertRuntimeCreatedNode(node, point);
	}

	function isInlineNode(node) {
		return node && node.getAttribute('data-ultracache-inline') === '1';
	}

	function isExternalNode(node) {
		return node && node.getAttribute('data-ultracache-src') && !isInlineNode(node);
	}

	function orderedFetchNodeIsEligible(node) {
		if (!orderedFetchEnabled || firstPartyParallelExecution || !isExternalNode(node)) {
			return false;
		}

		var src = node.getAttribute('data-ultracache-src') || '';
		if (!src || !isSameOriginScriptSrc(src) || isThirdPartyDelayedNode(node)) {
			return false;
		}

		var attrs = decodeAttrs(node);
		var type = String(attrs.type || '').trim().toLowerCase();
		if (type === 'module') {
			return false;
		}

		return true;
	}

	function startOrderedFetchScheduler(nodes) {
		if (!orderedFetchEnabled || firstPartyParallelExecution || !Array.isArray(nodes) || !nodes.length) {
			mark('ordered-fetch-enabled', '0');
			return;
		}

		var queue = [];
		var seen = Object.create(null);
		var active = 0;
		var completed = 0;
		var failed = 0;
		var skipped = 0;
		var cursor = 0;

		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i];
			if (!orderedFetchNodeIsEligible(node)) {
				skipped++;
				continue;
			}

			var src = node.getAttribute('data-ultracache-src') || '';
			var key = src;
			try {
				key = new URL(src, document.baseURI || window.location.href).href;
			} catch (e) {}

			if (seen[key]) {
				skipped++;
				continue;
			}
			seen[key] = 1;
			queue.push({ node: node, src: src, key: key });
		}

		mark('ordered-fetch-enabled', queue.length ? '1' : '0');
		mark('ordered-fetch-concurrency', orderedFetchConcurrency);
		mark('ordered-fetch-queued', queue.length);
		mark('ordered-fetch-skipped', skipped);

		if (!queue.length) {
			return;
		}

		function updateMarks() {
			mark('ordered-fetch-active', active);
			mark('ordered-fetch-completed', completed);
			mark('ordered-fetch-failed', failed);
		}

		function pump() {
			while (active < orderedFetchConcurrency && cursor < queue.length) {
				(function (item) {
					cursor++;
					active++;
					updateMarks();

					var link = document.createElement('link');
					var settled = false;
					var attrs = decodeAttrs(item.node);
					link.rel = 'preload';
					link.as = 'script';
					link.href = item.src;
					link.setAttribute('data-ultracache-ordered-fetch', '1');

					['crossorigin', 'integrity', 'referrerpolicy'].forEach(function (attr) {
						if (attrs[attr]) {
							try {
								link.setAttribute(attr, String(attrs[attr]));
							} catch (e) {}
						}
					});

					function settle(ok) {
						if (settled) {
							return;
						}
						settled = true;
						active--;
						if (ok) {
							completed++;
						} else {
							failed++;
						}
						updateMarks();
						pump();
					}

					link.onload = function () { settle(true); };
					link.onerror = function () { settle(false); };

					var target = document.head || document.documentElement || document.body;
					if (!target) {
						settle(false);
						return;
					}
					target.appendChild(link);
				})(queue[cursor]);
			}
		}

		pump();
	}



	function executionLaneForNode(node, mode) {
		var runtimeLane = String(ultracacheData(node, 'runtime-lane') || '').toLowerCase();
		if (runtimeLane === 'native' || runtimeLane === 'defer' || runtimeLane === 'delay') {
			return runtimeLane;
		}
		return String(mode || '').indexOf('defer') !== -1 ? 'defer' : 'delay';
	}

	function recordObservedScriptExecution(node, script, mode, kind, status) {
		var sequence = ++scriptExecutionSequence;
		var family = String(ultracacheData(node, 'family') || '');
		var familySequence = String(ultracacheData(node, 'family-sequence') || '');
		var familyPhase = String(ultracacheData(node, 'family-phase') || (kind === 'external' ? 'external' : 'inline'));
		var handle = String(delayedNodeHandle(node) || '');
		var lane = executionLaneForNode(node, mode);
		var src = kind === 'external' ? String(node.getAttribute('data-ultracache-src') || '') : '';

		if (script && script.setAttribute) {
			try {
				script.setAttribute('data-ultracache-executed-sequence', String(sequence));
				script.setAttribute('data-ultracache-executed-lane', lane);
				if (family) script.setAttribute('data-ultracache-family', family);
				if (familySequence) script.setAttribute('data-ultracache-family-sequence', familySequence);
				if (familyPhase) script.setAttribute('data-ultracache-family-phase', familyPhase);
			} catch (e) {}
		}

		emit('ultracache:script-execution', {
			state: 'executed', sequence: sequence, lane: lane, mode: String(mode || ''), kind: kind,
			handle: handle, family: family, familySequence: familySequence, familyPhase: familyPhase, src: src,
			status: String(status || 'executed')
		});
		return sequence;
	}

	function loadPreDependencyInlines(providerNode, done, mode) {
		var providerHandle = delayedNodeHandle(providerNode);
		var candidates = providerHandle ? (delayedPreDependencyInlinesByHandle[providerHandle] || []) : [];
		if (!candidates.length) {
			done();
			return;
		}

		var index = 0;
		function next() {
			while (index < candidates.length && (candidates[index].getAttribute('data-ultracache-loading') === '1' || candidates[index].getAttribute('data-ultracache-loaded') === '1')) {
				index++;
			}

			if (index >= candidates.length) {
				done();
				return;
			}

			var candidate = candidates[index++];
			loadInline(candidate, function () {
				preDependencyInlineCompleted++;
				mark('predependency-inline-completed', preDependencyInlineCompleted);
				next();
			}, mode);
		}

		next();
	}

	function loadInline(node, done, mode) {
		if (!node || node.getAttribute('data-ultracache-loading') === '1' || node.getAttribute('data-ultracache-loaded') === '1') {
			done();
			return;
		}

		if (!nodeIsConnected(node) || !node.parentNode) {
			discardDelayedNode(node, 'detached');
			done();
			return;
		}

		if (hasExecutableDuplicate(node)) {
			discardDelayedNode(node, 'duplicate');
			done();
			return;
		}

		node.setAttribute('data-ultracache-loading', '1');

		var dynamicNode = isRuntimeCreatedDelayedNode(node);
		var script = dynamicNode ? node : document.createElement('script');
		if (!dynamicNode) {
			applyAttrs(script, node);
		}

		var registryKey = ultracacheData(node, 'inline-registry-key');
		var registryEntry = registryKey ? inlineRegistryEntry(node) : null;
		if (registryKey && (!registryEntry || typeof registryEntry.code !== 'string')) {
			// Never consume a registry-backed inline occurrence without its code.
			// The manifest normally exists before DOMContentLoaded, but a missing
			// entry must remain pending rather than becoming an empty executed script.
			node.removeAttribute('data-ultracache-loading');
			mark('inline-registry-pending', registryKey);
			done();
			return;
		}

		var inlineCode = registryEntry && typeof registryEntry.code === 'string' ? registryEntry.code : inlineNodeCode(node);
		if (!dynamicNode) {
			try {
				script.text = inlineCode;
			} catch (e) {
				script.text = '';
			}
		}

		claimScriptExecution(node);
		var inserted = dynamicNode
			? reviveRuntimeCreatedInlineNode(node, inlineCode)
			: replaceDelayedNode(node, script);
		if (!inserted) {
			done();
			return;
		}
		recordObservedScriptExecution(node, script, mode, 'inline', 'executed');
		tryHookReady();
		done();
	}

	function laneUsesParallelExecution(mode) {
		return (mode === 'firstparty' && firstPartyParallelExecution) || (mode === 'thirdparty' && thirdPartyParallelExecution);
	}

	function loadExternalGroup(list, start, done, mode) {
		mode = mode || 'all';
		var end = start;
		var group = [];

		while (end < list.length && isExternalNode(list[end]) && list[end].getAttribute('data-ultracache-loading') !== '1' && list[end].getAttribute('data-ultracache-loaded') !== '1') {
			group.push(list[end]);
			end++;
		}

		if (!group.length) {
			done(start + 1);
			return;
		}

		var completed = 0;
		var dependencyOrdered = group.some(function (node) {
			return String(ultracacheData(node, 'ordered') || '') === '1' || !!ultracacheData(node, 'family') || delayedNodeDependencies(node).length > 0;
		});
		var useParallel = laneUsesParallelExecution(mode) && !dependencyOrdered;
		mark('parallel-loader', useParallel ? '1' : '0');
		if (dependencyOrdered) {
			mark(mode + '-dependency-ordered', '1');
		}
		mark('parallel-mode', useParallel ? mode : 'ordered');
		mark('all-' + (useParallel ? 'parallel' : 'ordered') + '-group-size', group.length);
		mark(mode + '-' + (useParallel ? 'parallel' : 'ordered') + '-group-size', group.length);

		function loadOne(position) {
			if (position >= group.length) {
				done(end);
				return;
			}

			var node = group[position];
			if (!nodeIsConnected(node) || !node.parentNode) {
				discardDelayedNode(node, 'detached');
				loadOne(position + 1);
				return;
			}

			if (hasExecutableDuplicate(node)) {
				discardDelayedNode(node, 'duplicate');
				loadOne(position + 1);
				return;
			}

			loadPreDependencyInlines(node, function () {
				node.setAttribute('data-ultracache-loading', '1');
				claimScriptExecution(node);

				var src = node.getAttribute('data-ultracache-src');
				var dynamicNode = isRuntimeCreatedDelayedNode(node);
				var script = dynamicNode ? node : document.createElement('script');
				var finished = false;
				var timeout = null;

				if (!dynamicNode) {
					applyAttrs(script, node);
					script.async = false;
				}
				function finish(status) {
					if (finished) {
						return;
					}

					finished = true;

					if (timeout) {
						clearTimeout(timeout);
					}

					if ((status || 'loaded') === 'loaded') {
						recordObservedScriptExecution(node, script, mode, 'external', 'loaded');
					}
					tryHookReady();
					node.setAttribute('data-ultracache-loaded', '1');
					completed++;
					mark('all-ordered-completed', completed);
					mark(mode + '-ordered-completed', completed);
					loadOne(position + 1);
				}

				var onLoaded = function () { finish('loaded'); };
				var onError = function () { finish('error'); };
				if (!dynamicNode) {
					script.onload = onLoaded;
					script.onerror = onError;
				}
				timeout = setTimeout(function () {
					mark(mode + '-script-timeout', position + 1);
					finish('timeout');
				}, scriptTimeoutMs);

				var inserted = dynamicNode
					? reviveRuntimeCreatedExternalNode(node, src, onLoaded, onError)
					: (function () {
						script.src = src;
						return replaceDelayedNode(node, script);
					}());
				if (!inserted) {
					finish('detached');
				}
			}, mode);
		}

		function loadParallel() {
			var total = group.length;
			if (!total) {
				done(end);
				return;
			}

			function oneDone() {
				completed++;
				mark('all-parallel-completed', completed);
				mark(mode + '-parallel-completed', completed);
				if (completed >= total) {
					done(end);
				}
			}

			group.forEach(function (node, position) {
				if (!nodeIsConnected(node) || !node.parentNode) {
					discardDelayedNode(node, 'detached');
					oneDone();
					return;
				}

				if (hasExecutableDuplicate(node)) {
					discardDelayedNode(node, 'duplicate');
					oneDone();
					return;
				}

				loadPreDependencyInlines(node, function () {
					node.setAttribute('data-ultracache-loading', '1');
					claimScriptExecution(node);

					var src = node.getAttribute('data-ultracache-src');
					var dynamicNode = isRuntimeCreatedDelayedNode(node);
					var script = dynamicNode ? node : document.createElement('script');
					var finished = false;
					var timeout = null;

					if (!dynamicNode) {
						applyAttrs(script, node);
						script.async = true;
					}
					function finish(status) {
						if (finished) {
							return;
						}

						finished = true;

						if (timeout) {
							clearTimeout(timeout);
						}

						if ((status || 'loaded') === 'loaded') {
							recordObservedScriptExecution(node, script, mode, 'external', 'loaded');
						}
						tryHookReady();
						node.setAttribute('data-ultracache-loaded', '1');
						oneDone();
					}

					var onLoaded = function () { finish('loaded'); };
					var onError = function () { finish('error'); };
					if (!dynamicNode) {
						script.onload = onLoaded;
						script.onerror = onError;
					}
					timeout = setTimeout(function () {
						mark(mode + '-parallel-script-timeout', position + 1);
						finish('timeout');
					}, scriptTimeoutMs);

					var inserted = dynamicNode
						? reviveRuntimeCreatedExternalNode(node, src, onLoaded, onError)
						: (function () {
							script.src = src;
							return replaceDelayedNode(node, script);
						}());
					if (!inserted) {
						finish('detached');
					}
				}, mode);
			});
		}

		if (useParallel) {
			loadParallel();
		} else {
			loadOne(0);
		}
	}

	function load(list, index, mode, done) {
		mode = mode || 'all';
		while (index < list.length && (list[index].getAttribute('data-ultracache-loaded') === '1' || list[index].getAttribute('data-ultracache-loading') === '1')) {
			index++;
		}

		if (index >= list.length) {
			mark(mode + '-done', '1');
			emit('ultracache:delayed-scripts-lane-done', { mode: mode, count: list.length });
			if (typeof done === 'function') {
				done();
			}
			return;
		}

		if (isInlineNode(list[index])) {
			idle(function () {
				loadInline(list[index], function () {
					wait(relief ? 30 : 0, function () {
						load(list, index + 1, mode, done);
					});
				}, mode);
			});
			return;
		}

		if (isExternalNode(list[index])) {
			idle(function () {
				loadExternalGroup(list, index, function (next) {
					wait(relief ? 30 : 0, function () {
						load(list, next, mode, done);
					});
				}, mode);
			});
			return;
		}

		load(list, index + 1, mode, done);
	}

	function finishDynamicBootstrapFlush() {
		dynamicBootstrapFlushReady = true;
		mark('dynamic-finder-bootstrap-ready', '1');

		if (dynamicBootstrapInteractionPending && pendingInteractionReplay) {
			dynamicBootstrapInteractionPending = false;
			if (document.readyState !== 'loading') {
				startInteractionRelease();
			} else if (!interactionTriggerPending) {
				interactionTriggerPending = true;
				mark('interaction-trigger-pending', '1');
				document.addEventListener('DOMContentLoaded', function () {
					startInteractionRelease();
				}, { once: true });
			}
		}

		if (dynamicBootstrapFullRequestPending) {
			dynamicBootstrapFullRequestPending = false;
			triggerAll();
		}
	}

	function flushDynamicBootstrapPendingScripts() {
		if (!dynamicScriptFinder || typeof dynamicScriptFinder.getPendingNodes !== 'function' || typeof dynamicScriptFinder.resolvePending !== 'function') {
			finishDynamicBootstrapFlush();
			return;
		}

		var pending = [];
		try {
			pending = dynamicScriptFinder.getPendingNodes() || [];
		} catch (e) {
			pending = [];
		}

		if (!pending.length) {
			finishDynamicBootstrapFlush();
			return;
		}

		var immediate = [];
		for (var i = 0; i < pending.length; i++) {
			var node = pending[i];
			var src = ultracacheData(node, 'src');
			var route = classifyDynamicScript(node, src);
			try {
				dynamicScriptFinder.resolvePending(node, route);
			} catch (e) {}
			if (!route || route.lane !== 'delay') {
				immediate.push(node);
			}
		}

		mark('dynamic-finder-pending', pending.length);
		mark('dynamic-finder-immediate', immediate.length);
		if (!immediate.length) {
			finishDynamicBootstrapFlush();
			return;
		}

		preparePreDependencyInlineIndex(immediate);
		load(immediate, 0, 'dynamic-defer', finishDynamicBootstrapFlush);
	}

	function pendingDelayedScripts() {
		return queryDelayedScripts().filter(function (node) {
			return node && node.getAttribute('data-ultracache-loading') !== '1' && node.getAttribute('data-ultracache-loaded') !== '1';
		});
	}

	function notifyFirstPartyLaneCompleted(reason) {
		if (firstPartyLaneCompleted) {
			return;
		}

		firstPartyLaneCompleted = true;
		mark('firstparty-completed-by', reason || 'unknown');
	}

	function run() {
		counts();

		var list = stableOrderDelayedNodes(pendingDelayedScripts());

		preparePreDependencyInlineIndex(list);

		if (!list.length) {
			setDynamicScriptFinderReleasePhase(2);
			fullRunCompleted = true;
			interactionWaitForFullRun = false;
			pendingInteractionReplay = null;
			mark('all-done', 'empty');
			return;
		}

		var releaseSplit = splitDelayedFullReleaseLanes(list);
		var firstParty = releaseSplit.early;
		var thirdParty = releaseSplit.late;

		mark('all-started', '1');
		mark('all-count', list.length);
		mark('firstparty-count', firstParty.length);
		mark('thirdparty-count', thirdParty.length);
		mark('execution-model', 'firstparty-then-thirdparty');
		emit('ultracache:delayed-scripts-start', { mode: 'split', count: list.length, firstParty: firstParty.length, thirdParty: thirdParty.length });

		function finishAll() {
			fullRunCompleted = true;
			mark('all-done', '1');
			emit('ultracache:delayed-scripts-done', { mode: 'split', count: list.length, firstParty: firstParty.length, thirdParty: thirdParty.length });

			if (interactionWaitForFullRun) {
				interactionWaitForFullRun = false;
				mark('interaction-waiting-for-full-run', '0');
				replayPendingInteraction();
			}
		}

		function runThirdPartyLane() {
			setDynamicScriptFinderReleasePhase(2);

			/*
			 * Runtime-created third-party scripts can be discovered while the
			 * first-party lane is executing. Re-read the inert queue at the exact
			 * third-party boundary so those scripts join the same release rather
			 * than becoming stranded after the initial run() snapshot.
			 */
			thirdParty = stableOrderDelayedNodes(pendingDelayedScripts());

			if (!thirdParty.length) {
				mark('thirdparty-done', 'empty');
				finishAll();
				return;
			}

			mark('thirdparty-started', '1');
			load(thirdParty, 0, 'thirdparty', finishAll);
		}

		if (!firstParty.length) {
			mark('firstparty-done', 'empty');
			notifyFirstPartyLaneCompleted('full-empty');
			runThirdPartyLane();
			return;
		}

		setDynamicScriptFinderReleasePhase(1);
		beginReadyHold();
		mark('firstparty-started', '1');
		startOrderedFetchScheduler(firstParty);
		load(firstParty, 0, 'firstparty', function () {
			flushReadyHold('firstparty');
			notifyFirstPartyLaneCompleted('full');
			runThirdPartyLane();
		});
	}

	function triggerAll() {
		if (!dynamicBootstrapFlushReady) {
			dynamicBootstrapFullRequestPending = true;
			mark('dynamic-finder-full-release-pending', '1');
			return;
		}
		if (allDone) {
			return;
		}

		if (holdForMinimumRelease('full')) {
			return;
		}

		if (interactionReleaseInProgress) {
			fullTriggerPending = true;
			mark('interaction-full-trigger-pending', '1');
			return;
		}

		allDone = true;
		run();
	}

	function interactionNodeIsEligible(node) {
		if (!node) {
			return false;
		}

		if (!isThirdPartyDelayedNode(node)) {
			return true;
		}

		return ultracacheData(node, 'delay-reason') === 'functional-third-party';
	}

	var interactionReplayPriorities = {
		mousemove: 1,
		touchstart: 2,
		pointerdown: 3,
		keydown: 4,
		click: 5
	};

	function rememberInteractionForReplay(event) {
		if (!event || !event.type || !event.target || typeof event.target.dispatchEvent !== 'function') {
			return;
		}

		var type = String(event.type || '').toLowerCase();
		var priority = interactionReplayPriorities[type] || 0;
		if (pendingInteractionReplay && priority < pendingInteractionReplay.priority) {
			return;
		}

		pendingInteractionReplay = {
			type: type,
			priority: priority,
			target: event.target,
			constructor: event.constructor,
			init: {
				bubbles: true, cancelable: true, composed: true,
				detail: event.detail || 0,
				screenX: event.screenX || 0, screenY: event.screenY || 0,
				clientX: event.clientX || 0, clientY: event.clientY || 0,
				button: typeof event.button === 'number' ? event.button : 0,
				buttons: typeof event.buttons === 'number' ? event.buttons : 0,
				ctrlKey: !!event.ctrlKey, shiftKey: !!event.shiftKey, altKey: !!event.altKey, metaKey: !!event.metaKey,
				key: event.key || '', code: event.code || '', location: event.location || 0, repeat: !!event.repeat,
				pointerId: event.pointerId || 0, pointerType: event.pointerType || '', isPrimary: event.isPrimary !== false,
				width: event.width || 1, height: event.height || 1, pressure: typeof event.pressure === 'number' ? event.pressure : 0
			}
		};
		mark('interaction-replay-pending', type);
	}

	function createReplayEvent(snapshot) {
		try {
			if (typeof snapshot.constructor === 'function') {
				return new snapshot.constructor(snapshot.type, snapshot.init);
			}
		} catch (e) {}

		try {
			return new Event(snapshot.type, snapshot.init);
		} catch (e) {
			return null;
		}
	}

	function replayPendingInteraction() {
		var snapshot = pendingInteractionReplay;
		pendingInteractionReplay = null;

		if (!snapshot || !snapshot.target || typeof snapshot.target.dispatchEvent !== 'function') {
			mark('interaction-replay-skipped', 'no-target');
			return;
		}

		if (snapshot.target.isConnected === false) {
			mark('interaction-replay-skipped', 'detached-target');
			return;
		}

		var replayEvent = createReplayEvent(snapshot);
		if (!replayEvent) {
			mark('interaction-replay-skipped', 'event-construction');
			return;
		}

		function suppressReplayDefault(event) {
			if (event === replayEvent && event.cancelable) {
				event.preventDefault();
			}
		}

		// The original trusted interaction already retained its native default
		// behavior. Suppress only the synthetic replay's default action so a
		// replayed click/keydown cannot navigate, submit, toggle, or type twice.
		window.addEventListener(snapshot.type, suppressReplayDefault, { once: true });
		replayDispatchInProgress = true;
		try {
			snapshot.target.dispatchEvent(replayEvent);
			mark('interaction-replayed', snapshot.type);
		} catch (e) {
			mark('interaction-replay-skipped', 'dispatch-error');
		}
		replayDispatchInProgress = false;
		window.removeEventListener(snapshot.type, suppressReplayDefault);
	}

	function releaseInteractionLane(done) {
		setDynamicScriptFinderReleasePhase(1);
		counts();

		var pending = pendingDelayedScripts();
		preparePreDependencyInlineIndex(pending);
		var interactionLane = dependencyAwareInteractionNodes(pending);

		mark('interaction-release-scope', 'firstparty-functional-thirdparty');
		mark('interaction-release-count', interactionLane.length);

		if (!interactionLane.length) {
			notifyFirstPartyLaneCompleted('interaction-empty');
			done(false);
			return;
		}

		beginReadyHold();
		startOrderedFetchScheduler(interactionLane);
		load(interactionLane, 0, 'interaction', function () {
			flushReadyHold('interaction');
			notifyFirstPartyLaneCompleted('interaction');
			done(true);
		});
	}

	function finishInteractionRelease(released) {
		interactionReleaseInProgress = false;
		interactionTriggerPending = false;
		mark('interaction-trigger-pending', '0');
		mark('interaction-release-in-progress', '0');

		if (released) {
			replayPendingInteraction();
		} else {
			pendingInteractionReplay = null;
		}

		if (fullTriggerPending) {
			fullTriggerPending = false;
			mark('interaction-full-trigger-pending', '0');
			triggerAll();
		}
	}

	function startInteractionRelease() {
		if (interactionReleaseInProgress || firstPartyLaneCompleted) {
			return;
		}

		if (holdForMinimumRelease('interaction')) {
			return;
		}

		interactionReleaseInProgress = true;
		mark('interaction-release-in-progress', '1');
		releaseInteractionLane(finishInteractionRelease);
	}

	/*
	 * 3.10.08: interaction no longer releases the entire delayed queue. Keep
	 * analytics/marketing third-party scripts delayed, release the ordered
	 * first-party + explicitly functional-third-party lane, then replay the
	 * strongest captured interaction after that lane is available. The original
	 * browser event is never cancelled or stopped, so native default behavior is
	 * preserved; replay is an additive second chance for handlers that were not
	 * installed when the original interaction occurred.
	 */
	function triggerAllFromInteraction(event) {
		if (replayDispatchInProgress) {
			return;
		}

		if (fullRunCompleted) {
			return;
		}

		rememberInteractionForReplay(event);

		if (!dynamicBootstrapFlushReady) {
			dynamicBootstrapInteractionPending = true;
			mark('dynamic-finder-interaction-release-pending', '1');
			return;
		}

		if (allDone) {
			interactionWaitForFullRun = true;
			mark('interaction-waiting-for-full-run', '1');
			return;
		}

		if (firstPartyLaneCompleted) {
			pendingInteractionReplay = null;
			return;
		}

		if (interactionReleaseInProgress) {
			return;
		}

		if (document.readyState !== 'loading') {
			startInteractionRelease();
			return;
		}

		if (interactionTriggerPending) {
			return;
		}

		interactionTriggerPending = true;
		mark('interaction-trigger-pending', '1');

		document.addEventListener('DOMContentLoaded', function () {
			startInteractionRelease();
		}, { once: true });
	}


	/*
	 * 3.11.25: the full loader is native-deferred. A tiny parser-early helper
	 * records the strongest configured interaction that happened while HTML was
	 * still parsing. Consume that snapshot using the exact same release state
	 * machine, without synthesizing an event until the normal replay phase.
	 */
	function consumeEarlyInteractionBootstrap() {
		var state = window.__ultracacheDelayedJsEarlyInteractionV125;
		if (!state) {
			return;
		}

		if (typeof state.stop === 'function') {
			try { state.stop(); } catch (e) {}
		}

		var snapshot = state.snapshot;
		state.snapshot = null;
		if (!snapshot || !snapshot.type || autoEvents.indexOf(String(snapshot.type).toLowerCase()) === -1) {
			mark('early-interaction-bootstrap', 'empty');
			return;
		}

		if (pendingInteractionReplay && snapshot.priority < pendingInteractionReplay.priority) {
			mark('early-interaction-bootstrap', 'lower-priority');
			return;
		}

		pendingInteractionReplay = snapshot;
		mark('early-interaction-bootstrap', String(snapshot.type).toLowerCase());
		mark('interaction-replay-pending', String(snapshot.type).toLowerCase());

		if (fullRunCompleted) {
			pendingInteractionReplay = null;
			return;
		}

		if (allDone) {
			interactionWaitForFullRun = true;
			mark('interaction-waiting-for-full-run', '1');
			return;
		}

		if (firstPartyLaneCompleted) {
			pendingInteractionReplay = null;
			return;
		}

		if (!dynamicBootstrapFlushReady) {
			dynamicBootstrapInteractionPending = true;
			mark('dynamic-finder-interaction-release-pending', '1');
			return;
		}

		if (interactionReleaseInProgress) {
			return;
		}

		if (document.readyState !== 'loading') {
			startInteractionRelease();
			return;
		}

		if (!interactionTriggerPending) {
			interactionTriggerPending = true;
			mark('interaction-trigger-pending', '1');
			document.addEventListener('DOMContentLoaded', function () {
				startInteractionRelease();
			}, { once: true });
		}
	}

	function afterDomReady(callback, delay) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () {
				setTimeout(callback, delay || 0);
			}, { once: true });
			return;
		}

		setTimeout(callback, delay || 0);
	}

	function afterLoad(callback, delay) {
		if (document.readyState === 'complete') {
			setTimeout(callback, delay || 0);
			return;
		}

		window.addEventListener('load', function () {
			setTimeout(callback, delay || 0);
		}, { once: true });
	}


	counts();
	mark('loader', 'active');
	mark('policy', 'split-auto-start');
	mark('started-ms', started);
	mark('parallel-mode', (firstPartyParallelExecution || thirdPartyParallelExecution) ? 'configurable' : 'ordered');
	mark('firstparty-parallel-enabled', firstPartyParallelExecution ? '1' : '0');
	mark('thirdparty-parallel-enabled', thirdPartyParallelExecution ? '1' : '0');
	mark('ordered-fetch-configured', orderedFetchEnabled ? '1' : '0');
	mark('ordered-fetch-concurrency', orderedFetchConcurrency);
	mark('auto-delay-ms', autoDelayMs);
	mark('minimum-release-ms', minimumReleaseMs);
	mark('minimum-gate-open', minimumReleaseRemainingMs() <= 0 ? '1' : '0');
	mark('script-timeout-ms', scriptTimeoutMs);
	mark('auto-after-load', autoAfterLoad);
	mark('auto-timer-enabled', autoTimerEnabled ? '1' : '0');
	mark('runtime-scan-infinite-trigger-ms', runtimeScanInfiniteTriggerMs);
	mark('auto-events', autoEvents.join(','));
	mark('interaction-release-scope', 'firstparty-functional-thirdparty');
	mark('interaction-replay-enabled', '1');

	flushDynamicBootstrapPendingScripts();

	if (autoEvents && autoEvents.length) {
		autoEvents.forEach(function (eventName) {
			window.addEventListener(eventName, triggerAllFromInteraction, { passive: true, once: true });
		});
	}

	consumeEarlyInteractionBootstrap();

	if (autoAfterLoad) {
		afterLoad(triggerAll, 0);
	}

	/*
	 * Infinite Delay intentionally has no visitor-facing timer. Browser Runtime
	 * Scan cannot provide a real interaction inside its isolated anonymous
	 * frame, so an authorized scan may supply a scan-only post-load trigger.
	 * The server emits this value only for verified Runtime Scan requests while
	 * Infinite is selected. Normal visitors and every timed mode keep the exact
	 * existing behavior.
	 */
	if (!autoTimerEnabled && runtimeScanInfiniteTriggerMs > 0) {
		afterLoad(function () {
			if (allDone || !queryDelayedScripts().length) {
				return;
			}

			mark('runtime-scan-infinite-triggered', '1');
			triggerAll();
		}, runtimeScanInfiniteTriggerMs);
	}

	if (autoTimerEnabled) {
		afterDomReady(triggerAll, autoDelayMs);
	}
}());
