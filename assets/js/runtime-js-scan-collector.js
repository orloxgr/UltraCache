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
	var originalOnError = window.onerror;
	var originalOnUnhandledRejection = window.onunhandledrejection;

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
