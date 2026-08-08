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
	var autoDelayMs = typeof config.autoDelayMs === 'number' ? config.autoDelayMs : parseInt(config.autoDelayMs || 50, 10);
	var scriptTimeoutMs = typeof config.scriptTimeoutMs === 'number' ? config.scriptTimeoutMs : parseInt(config.scriptTimeoutMs || 8000, 10);
	var firstPartyParallelExecution = !!config.firstPartyParallelExecution;
	var thirdPartyParallelExecution = !!config.thirdPartyParallelExecution;
	var allDone = false;
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

	if (!isFinite(autoDelayMs) || autoDelayMs < 0) {
		autoDelayMs = 50;
	}

	if (!isFinite(scriptTimeoutMs) || scriptTimeoutMs < 1000) {
		scriptTimeoutMs = 8000;
	}

	if (scriptTimeoutMs > 30000) {
		scriptTimeoutMs = 30000;
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

	function queryDelayedScripts() {
		return Array.prototype.slice.call(
			document.querySelectorAll('script[type="text/ultracache-delayed-js"][data-ultracache-src],script[type="text/ultracache-delayed-js"][data-ultracache-inline="1"]')
		);
	}

	function ultracacheData(node, attr) {
		var value = node && node.getAttribute ? node.getAttribute('data-ultracache-' + attr) : '';
		return value || '';
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
			try {
				attrs = JSON.parse(atob(raw)) || {};
			} catch (e) {
				attrs = {};
			}
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
			var code = node.textContent || '';
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

	function isInlineNode(node) {
		return node && node.getAttribute('data-ultracache-inline') === '1';
	}

	function isExternalNode(node) {
		return node && node.getAttribute('data-ultracache-src') && !isInlineNode(node);
	}

	function loadInline(node, done) {
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
		claimScriptExecution(node);

		var script = document.createElement('script');
		applyAttrs(script, node);

		try {
			script.text = node.textContent || '';
		} catch (e) {
			script.text = '';
		}

		if (!replaceDelayedNode(node, script)) {
			done();
			return;
		}
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
		var useParallel = laneUsesParallelExecution(mode);
		mark('parallel-loader', useParallel ? '1' : '0');
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

			node.setAttribute('data-ultracache-loading', '1');
			claimScriptExecution(node);

			var src = node.getAttribute('data-ultracache-src');
			var script = document.createElement('script');
			var finished = false;
			var timeout = null;

			applyAttrs(script, node);
			script.async = false;

			function finish() {
				if (finished) {
					return;
				}

				finished = true;

				if (timeout) {
					clearTimeout(timeout);
				}

				tryHookReady();
				node.setAttribute('data-ultracache-loaded', '1');
				completed++;
				mark('all-ordered-completed', completed);
				mark(mode + '-ordered-completed', completed);
				loadOne(position + 1);
			}

			script.onload = finish;
			script.onerror = finish;
			timeout = setTimeout(function () {
				mark(mode + '-script-timeout', position + 1);
				finish();
			}, scriptTimeoutMs);
			script.src = src;
			if (!replaceDelayedNode(node, script)) {
				finish();
			}
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

				node.setAttribute('data-ultracache-loading', '1');
				claimScriptExecution(node);

				var src = node.getAttribute('data-ultracache-src');
				var script = document.createElement('script');
				var finished = false;
				var timeout = null;

				applyAttrs(script, node);
				script.async = true;

				function finish() {
					if (finished) {
						return;
					}

					finished = true;

					if (timeout) {
						clearTimeout(timeout);
					}

					tryHookReady();
					node.setAttribute('data-ultracache-loaded', '1');
					oneDone();
				}

				script.onload = finish;
				script.onerror = finish;
				timeout = setTimeout(function () {
					mark(mode + '-parallel-script-timeout', position + 1);
					finish();
				}, scriptTimeoutMs);
				script.src = src;
				if (!replaceDelayedNode(node, script)) {
					finish();
				}
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
				});
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

	function run() {
		counts();

		var list = queryDelayedScripts().filter(function (node) {
			return node && node.getAttribute('data-ultracache-loading') !== '1' && node.getAttribute('data-ultracache-loaded') !== '1';
		});

		if (!list.length) {
			mark('all-done', 'empty');
			return;
		}

		var firstParty = [];
		var thirdParty = [];

		for (var i = 0; i < list.length; i++) {
			if (isThirdPartyDelayedNode(list[i])) {
				thirdParty.push(list[i]);
			} else {
				firstParty.push(list[i]);
			}
		}

		mark('all-started', '1');
		mark('all-count', list.length);
		mark('firstparty-count', firstParty.length);
		mark('thirdparty-count', thirdParty.length);
		mark('execution-model', 'firstparty-then-thirdparty');
		emit('ultracache:delayed-scripts-start', { mode: 'split', count: list.length, firstParty: firstParty.length, thirdParty: thirdParty.length });

		function finishAll() {
			mark('all-done', '1');
			emit('ultracache:delayed-scripts-done', { mode: 'split', count: list.length, firstParty: firstParty.length, thirdParty: thirdParty.length });
		}

		function runThirdPartyLane() {
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
			runThirdPartyLane();
			return;
		}

		beginReadyHold();
		mark('firstparty-started', '1');
		load(firstParty, 0, 'firstparty', function () {
			flushReadyHold('firstparty');
			runThirdPartyLane();
		});
	}

	function triggerAll() {
		if (allDone) {
			return;
		}

		allDone = true;
		run();
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

	function hasElementorInlineBg(node) {
		try {
			return !!(node && node.querySelector && node.querySelector('[style*="background-image"],[style*="background:"]'));
		} catch (e) {
			return false;
		}
	}

	function revealElementorLazyBgs() {
		try {
			var viewportHeight = Math.max(window.innerHeight || 0, 600);
			var parents = Array.prototype.slice.call(document.querySelectorAll('.e-con.e-parent:not(.e-lazyloaded):not(.e-no-lazyload)'));
			var checked = 0;
			var revealed = 0;

			for (var i = 0; i < parents.length && checked < 80; i++) {
				var node = parents[i];
				checked++;

				if (!hasElementorInlineBg(node)) {
					continue;
				}

				var rect = node.getBoundingClientRect ? node.getBoundingClientRect() : { top: 0, bottom: 0 };
				if (i < 3 || (rect.top < viewportHeight * 2 && rect.bottom > -viewportHeight)) {
					node.classList.add('e-lazyloaded');
					node.setAttribute('data-ultracache-elementor-bg-lazy-class', '1');
					revealed++;
				}
			}

			mark('elementor-bg-lazy-checked', checked);
			mark('elementor-bg-lazy-revealed', revealed);
		} catch (e) {
			mark('elementor-bg-lazy-error', '1');
		}
	}

	function scheduleElementorLazyBgHelper() {
		var runHelper = function () {
			revealElementorLazyBgs();
		};
		var scheduled = false;

		afterDomReady(runHelper, 0);
		afterDomReady(runHelper, 250);
		afterLoad(runHelper, 0);

		function queue() {
			if (scheduled) {
				return;
			}

			scheduled = true;
			var callback = function () {
				scheduled = false;
				runHelper();
			};

			if (window.requestAnimationFrame) {
				window.requestAnimationFrame(callback);
			} else {
				setTimeout(callback, 80);
			}
		}

		['scroll', 'resize', 'orientationchange', 'touchstart', 'pointerdown'].forEach(function (eventName) {
			window.addEventListener(eventName, queue, { passive: true });
		});
	}

	counts();
	mark('loader', 'active');
	mark('policy', 'split-auto-start');
	mark('started-ms', started);
	mark('parallel-mode', (firstPartyParallelExecution || thirdPartyParallelExecution) ? 'configurable' : 'ordered');
	mark('firstparty-parallel-enabled', firstPartyParallelExecution ? '1' : '0');
	mark('thirdparty-parallel-enabled', thirdPartyParallelExecution ? '1' : '0');
	mark('auto-delay-ms', autoDelayMs);
	mark('script-timeout-ms', scriptTimeoutMs);
	mark('auto-after-load', autoAfterLoad);
	mark('auto-events', autoEvents.join(','));

	scheduleElementorLazyBgHelper();

	if (autoEvents && autoEvents.length) {
		autoEvents.forEach(function (eventName) {
			window.addEventListener(eventName, triggerAll, { passive: true, once: true });
		});
	}

	if (autoAfterLoad) {
		afterLoad(triggerAll, 0);
	}

	if (autoDelayMs >= 0) {
		afterDomReady(triggerAll, autoDelayMs);
	}
}());
