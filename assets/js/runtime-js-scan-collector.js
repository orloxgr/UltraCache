(function () {
	'use strict';

	var config = window.ultracacheRuntimeJsScanConfig || {};
	var scanId = String(config.scanId || '');
	var endpoint = String(config.endpoint || '');
	var restNonce = String(config.restNonce || '');
	var scanContext = config.scanContext === 'logged-in' ? 'logged-in' : 'anonymous';

	if (!scanId || !endpoint || !restNonce) {
		return;
	}

	var startedAt = Date.now ? Date.now() : new Date().getTime();
	var errors = [];
	var sentCount = 0;
	var maxErrors = 120;
	var maxActivity = 18;
	var storageKey = 'ultracacheRuntimeJsScan:' + scanId;
	var recentActivity = [];
	var originalOnError = window.onerror;
	var originalOnUnhandledRejection = window.onunhandledrejection;
	var originalSetTimeout = window.setTimeout;

	window.__ultracacheRuntimeJsScan = window.__ultracacheRuntimeJsScan || {
		injectedAt: startedAt,
		context: scanContext,
		errors: errors,
		sentCount: 0,
		debug: {
			installed: true,
			source: 'wp-native-enqueue',
			context: scanContext,
			onerror: false,
			eventError: false,
			consoleError: false,
			directHarvest: false
		}
	};

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
				'ultracache_runtime_js_scan_nonce',
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
			return value.replace(/([?&])ultracache_(?:runtime_js_scan(?:_id|_nonce|_context)?|rt|profile_bypass|store_profile(?:_verbose(?:_settings)?)?|callback_profile|profile_run|revalidate)=[^&#]*/g, '$1').replace(/[?&]$/, '').split('#')[0];
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
			send(true);
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

		errors.push(item);
		window.__ultracacheRuntimeJsScan.errors = errors;
		if (errors.length > maxErrors) {
			errors = errors.slice(errors.length - maxErrors);
			window.__ultracacheRuntimeJsScan.errors = errors;
		}
		send(false);
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
				var delayed = !!(s && (s.type === 'text/ultracache-delayed-js' || (s.hasAttribute && (s.hasAttribute('data-ultracache-src') || s.hasAttribute('data-ultracache-inline') || s.hasAttribute('data-ultracache-delayed')))));
				var text = '';
				if ((!src || delayed) && s && s.textContent) {
					text = trimText(s.textContent, 60000);
				}
				list.push({
					id: trimText(id, 160),
					handle: trimText(handle, 160),
					src: trimText(src, 1200),
					type: trimText(s && s.type ? s.type : '', 120),
					defer: !!(s && s.defer),
					async: !!(s && s.async),
					strategy: trimText(s && s.getAttribute ? (s.getAttribute('data-wp-strategy') || '') : '', 80),
					delayed: delayed,
					text: text
				});
			}
		} catch (e) {}
		return list;
	}

	function send(completed) {
		if (!endpoint || !scanId || !window.fetch) {
			return;
		}

		var payload = {
			scanId: scanId,
			url: String(window.location.href || ''),
			completed: !!completed,
			scanContext: scanContext,
			errors: errors,
			scripts: collectScripts(),
			userAgent: String(window.navigator && window.navigator.userAgent ? window.navigator.userAgent : ''),
			sentCount: ++sentCount,
			elapsedMs: now() - startedAt,
			debug: window.__ultracacheRuntimeJsScan && window.__ultracacheRuntimeJsScan.debug ? window.__ultracacheRuntimeJsScan.debug : {}
		};

		window.__ultracacheRuntimeJsScan.sentCount = sentCount;
		window.__ultracacheRuntimeJsScan.lastPayload = payload;

		try {
			window.fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce
				},
				body: JSON.stringify(payload),
				keepalive: !!completed
			}).catch(function () {});
		} catch (e) {}
	}

	window.__ultracacheRuntimeJsScan.flush = send;

	function installTimeoutActivityProbe() {
		if (typeof originalSetTimeout !== 'function' || window.setTimeout.__ultracacheRuntimeScanWrapped) {
			return;
		}
		window.setTimeout = function (callback, delay) {
			if (typeof callback !== 'function') {
				return originalSetTimeout.apply(this, arguments);
			}
			var scheduledStack = (new Error()).stack || '';
			recordActivity('timeout-scheduled', '', 'delay=' + String(delay || 0), scheduledStack);
			var args = Array.prototype.slice.call(arguments, 2);
			return originalSetTimeout.call(this, function () {
				recordActivity('timeout-fired', '', 'delay=' + String(delay || 0), scheduledStack);
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

	if (window.console && typeof window.console.error === 'function' && !window.console.__ultracacheRuntimeScanWrapped) {
		var originalError = window.console.error;
		window.console.error = function () {
			try {
				window.__ultracacheRuntimeJsScan.debug.consoleError = true;
				var parts = [];
				for (var i = 0; i < arguments.length; i++) {
					parts.push(asText(arguments[i]));
				}
				addError('console-error', parts.join(' '), '', 0, 0, '');
			} catch (e) {}
			return originalError.apply(window.console, arguments);
		};
		window.console.__ultracacheRuntimeScanWrapped = true;
	}

	send(false);
	var tick = 0;
	var timer = setInterval(function () {
		tick++;
		send(false);
		if (tick >= 12) {
			clearInterval(timer);
		}
	}, 1000);

	window.addEventListener('load', function () {
		setTimeout(function () {
			send(true);
		}, 4500);
	}, false);

	setTimeout(function () {
		send(true);
	}, 12000);
}());
