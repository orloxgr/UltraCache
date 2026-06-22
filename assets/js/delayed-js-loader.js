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
	var allDone = false;
	var started = Date.now ? Date.now() : 0;
	var readyActive = false;
	var readyHooked = false;
	var readyQueue = [];
	var readyOriginal = null;

	if (!isFinite(autoDelayMs) || autoDelayMs < 0) {
		autoDelayMs = 50;
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

	function counts() {
		var all = queryDelayedScripts();
		var thirdParty = 0;
		var local = 0;

		for (var i = 0; i < all.length; i++) {
			var reason = ultracacheData(all[i], 'delay-reason');
			if (reason === 'safe-third-party' || reason === 'functional-third-party' || reason === 'all-third-party') {
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
		if (!readyActive || readyHooked || !jq || !jq.fn || typeof jq.fn.ready !== 'function') {
			return;
		}

		readyOriginal = jq.fn.ready;
		jq.fn.ready = function (fn) {
			if (readyActive && typeof fn === 'function') {
				readyQueue.push({ fn: fn });
				mark('ready-held', readyQueue.length);
				return this;
			}

			return readyOriginal.apply(this, arguments);
		};

		readyHooked = true;
		mark('ready-hooked', '1');
	}

	function beginReadyHold() {
		readyActive = true;
		mark('ready-hold', '1');
		tryHookReady();
	}

	function flushReadyHold() {
		tryHookReady();
		readyActive = false;

		var jq = window.jQuery;
		if (readyHooked && jq && jq.fn && readyOriginal) {
			try {
				jq.fn.ready = readyOriginal;
			} catch (e) {}
		}

		readyHooked = false;
		mark('ready-hold', '0');
		mark('ready-flush-count', readyQueue.length);

		var queue = readyQueue.slice(0);
		readyQueue = [];
		emit('ultracache:delayed-jquery-ready-flush', { mode: 'all', count: queue.length });

		for (var i = 0; i < queue.length; i++) {
			try {
				queue[i].fn.call(document, jq);
			} catch (err) {
				setTimeout((function (error) {
					return function () {
						throw error;
					};
				})(err), 0);
			}
		}
	}

	function insertAndRemove(node, script) {
		if (node.parentNode) {
			node.parentNode.insertBefore(script, node);
			node.parentNode.removeChild(node);
			return;
		}

		(document.head || document.body || document.documentElement).appendChild(script);
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

		node.setAttribute('data-ultracache-loading', '1');

		var script = document.createElement('script');
		applyAttrs(script, node);

		try {
			script.text = node.textContent || '';
		} catch (e) {
			script.text = '';
		}

		insertAndRemove(node, script);
		tryHookReady();
		node.setAttribute('data-ultracache-loaded', '1');
		done();
	}

	function loadExternalGroup(list, start, done) {
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
		mark('parallel-loader', '0');
		mark('parallel-mode', 'ordered');
		mark('all-ordered-group-size', group.length);

		function loadOne(position) {
			if (position >= group.length) {
				done(end);
				return;
			}

			var node = group[position];
			node.setAttribute('data-ultracache-loading', '1');

			var src = node.getAttribute('data-ultracache-src');
			var script = document.createElement('script');
			var finished = false;

			applyAttrs(script, node);
			script.async = false;

			function finish() {
				if (finished) {
					return;
				}

				finished = true;
				tryHookReady();
				node.setAttribute('data-ultracache-loaded', '1');
				completed++;
				mark('all-ordered-completed', completed);
				loadOne(position + 1);
			}

			script.onload = finish;
			script.onerror = finish;
			script.src = src;
			insertAndRemove(node, script);
		}

		loadOne(0);
	}

	function load(list, index) {
		while (index < list.length && (list[index].getAttribute('data-ultracache-loaded') === '1' || list[index].getAttribute('data-ultracache-loading') === '1')) {
			index++;
		}

		if (index >= list.length) {
			flushReadyHold();
			mark('all-done', '1');
			emit('ultracache:delayed-scripts-done', { mode: 'all', count: list.length });
			return;
		}

		if (isInlineNode(list[index])) {
			idle(function () {
				loadInline(list[index], function () {
					wait(relief ? 30 : 0, function () {
						load(list, index + 1);
					});
				});
			});
			return;
		}

		if (isExternalNode(list[index])) {
			idle(function () {
				loadExternalGroup(list, index, function (next) {
					wait(relief ? 30 : 0, function () {
						load(list, next);
					});
				});
			});
			return;
		}

		load(list, index + 1);
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

		mark('all-started', '1');
		mark('all-count', list.length);
		beginReadyHold();
		emit('ultracache:delayed-scripts-start', { mode: 'all', count: list.length });
		load(list, 0);
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
	mark('policy', 'unified-auto-start');
	mark('started-ms', started);
	mark('parallel-mode', 'ordered');
	mark('auto-delay-ms', autoDelayMs);
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
