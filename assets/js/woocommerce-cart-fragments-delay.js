(function () {
	'use strict';

	if (window.__ultracacheWooCartFragmentsDelay) {
		return;
	}
	window.__ultracacheWooCartFragmentsDelay = 1;

	var config = window.ultracacheWooCartFragmentsDelayConfig || {};
	var autoEvents = Array.isArray(config.autoEvents) ? config.autoEvents : [];
	var autoAfterLoad = !!config.autoAfterLoad;
	var autoTimerEnabled = config.autoTimerEnabled !== false;
	var autoDelayMs = typeof config.autoDelayMs === 'number' ? config.autoDelayMs : parseInt(config.autoDelayMs || 50, 10);
	var skipCartCookies = config.skipCartCookies !== false;
	var released = false;
	var queue = [];
	var originalAjax = null;
	var $ = null;

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
				target.setAttribute('data-ultracache-wc-fragments-' + key, String(value));
			}
		} catch (e) {}
	}

	function hasCartCookie() {
		if (!skipCartCookies) {
			return false;
		}

		var cookie = String(document.cookie || '').toLowerCase();
		return cookie.indexOf('woocommerce_items_in_cart') !== -1 ||
			cookie.indexOf('woocommerce_cart_hash') !== -1 ||
			cookie.indexOf('wp_woocommerce_session_') !== -1;
	}

	function bodyHasUnsafeWooContext() {
		try {
			var body = document.body;
			if (!body || !body.className) {
				return false;
			}

			var classes = ' ' + String(body.className || '').toLowerCase() + ' ';
			return classes.indexOf(' woocommerce-cart ') !== -1 ||
				classes.indexOf(' woocommerce-checkout ') !== -1 ||
				classes.indexOf(' woocommerce-account ') !== -1 ||
				classes.indexOf(' cart ') !== -1 ||
				classes.indexOf(' checkout ') !== -1 ||
				classes.indexOf(' account ') !== -1;
		} catch (e) {
			return false;
		}
	}

	function getOptionObject(args) {
		if (!args || !args.length) {
			return {};
		}
		if (typeof args[0] === 'string') {
			return args[1] && typeof args[1] === 'object' ? args[1] : {};
		}
		return args[0] && typeof args[0] === 'object' ? args[0] : {};
	}

	function getRequestUrl(args) {
		var options = getOptionObject(args);
		if (args && typeof args[0] === 'string') {
			return args[0];
		}
		return options && options.url ? String(options.url) : '';
	}

	function bodyToString(data) {
		try {
			if (!data) {
				return '';
			}
			if (typeof data === 'string') {
				return data;
			}
			if (typeof URLSearchParams !== 'undefined' && data instanceof URLSearchParams) {
				return data.toString();
			}
			if (typeof FormData !== 'undefined' && data instanceof FormData) {
				var parts = [];
				data.forEach(function (value, key) {
					parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
				});
				return parts.join('&');
			}
			if (typeof data === 'object') {
				return Object.keys(data).map(function (key) {
					return encodeURIComponent(key) + '=' + encodeURIComponent(String(data[key]));
				}).join('&');
			}
		} catch (e) {}
		return '';
	}

	function isCartFragmentsRequest(args) {
		var options = getOptionObject(args);
		var url = getRequestUrl(args);
		var data = bodyToString(options && options.data ? options.data : '');
		var haystack = String(url + '&' + data).toLowerCase();

		return haystack.indexOf('wc-ajax=get_refreshed_fragments') !== -1 ||
			haystack.indexOf('/wc-ajax/get_refreshed_fragments') !== -1 ||
			haystack.indexOf('get_refreshed_fragments') !== -1;
	}

	function isImmediateCommerceInteraction(target) {
		try {
			return !!(target && target.closest && target.closest(
				'.add_to_cart_button, .single_add_to_cart_button, [name="add-to-cart"], .woocommerce-mini-cart, .widget_shopping_cart, .cart, .checkout, a[href*="cart"], a[href*="checkout"]'
			));
		} catch (e) {
			return false;
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

	function replay(item) {
		if (!item || item.aborted) {
			return;
		}

		var real = originalAjax.apply($, item.args);
		item.real = real;

		if (real && typeof real.done === 'function') {
			real.done(function () {
				item.deferred.resolveWith(this, arguments);
			});
		}

		if (real && typeof real.fail === 'function') {
			real.fail(function () {
				item.deferred.rejectWith(this, arguments);
			});
		}

		for (var i = 0; i < item.statusCodeMaps.length; i++) {
			if (real && typeof real.statusCode === 'function') {
				real.statusCode(item.statusCodeMaps[i]);
			}
		}
	}

	function release(reason) {
		if (released) {
			return;
		}

		released = true;
		mark('released', reason || 'timer');
		mark('released-count', queue.length);

		while (queue.length) {
			replay(queue.shift());
		}
	}

	function makeQueuedAjax(args) {
		var deferred = $.Deferred();
		var item = {
			args: Array.prototype.slice.call(args),
			deferred: deferred,
			real: null,
			aborted: false,
			statusCodeMaps: []
		};

		var proxy = deferred.promise({
			readyState: 0,
			status: 0,
			statusText: 'ultracache-delayed',
			abort: function (statusText) {
				item.aborted = true;
				if (item.real && typeof item.real.abort === 'function') {
					item.real.abort(statusText);
				} else {
					deferred.rejectWith(this, [proxy, statusText || 'abort', '']);
				}
				return proxy;
			},
			getResponseHeader: function (name) {
				return item.real && typeof item.real.getResponseHeader === 'function' ? item.real.getResponseHeader(name) : null;
			},
			getAllResponseHeaders: function () {
				return item.real && typeof item.real.getAllResponseHeaders === 'function' ? item.real.getAllResponseHeaders() : '';
			},
			setRequestHeader: function () {
				return proxy;
			},
			overrideMimeType: function () {
				return proxy;
			},
			statusCode: function (map) {
				if (map) {
					item.statusCodeMaps.push(map);
				}
				return proxy;
			}
		});

		item.proxy = proxy;
		queue.push(item);
		mark('queued', queue.length);
		return proxy;
	}

	function install() {
		if (hasCartCookie() || bodyHasUnsafeWooContext()) {
			mark('skipped', 'cart-context');
			return;
		}

		$ = window.jQuery;
		if (!$ || typeof $.ajax !== 'function' || typeof $.Deferred !== 'function') {
			mark('skipped', 'missing-jquery');
			return;
		}

		originalAjax = $.ajax;
		$.ajax = function () {
			if (!released && !hasCartCookie() && !bodyHasUnsafeWooContext() && isCartFragmentsRequest(arguments)) {
				return makeQueuedAjax(arguments);
			}
			return originalAjax.apply(this, arguments);
		};

		mark('active', '1');
		mark('auto-delay-ms', autoDelayMs);
		mark('auto-after-load', autoAfterLoad ? '1' : '0');
		mark('auto-timer-enabled', autoTimerEnabled ? '1' : '0');
		mark('auto-events', autoEvents.join(','));

		if (autoEvents && autoEvents.length) {
			autoEvents.forEach(function (eventName) {
				window.addEventListener(eventName, function () {
					release(eventName);
				}, { passive: true, once: true });
			});
		}

		document.addEventListener('click', function (event) {
			if (isImmediateCommerceInteraction(event && event.target ? event.target : null)) {
				release('commerce-interaction');
			}
		}, true);

		if ($ && $.fn && typeof $(document).on === 'function') {
			$(document).on('adding_to_cart added_to_cart removed_from_cart wc_fragments_refreshed', function (event) {
				release(event && event.type ? event.type : 'woocommerce-event');
			});
		}

		if (autoAfterLoad) {
			afterLoad(function () {
				release('load');
			}, 0);
		}

		if (autoTimerEnabled) {
			afterDomReady(function () {
				release('timer');
			}, autoDelayMs);
		}
	}

	function installWhenReady(tries) {
		if (window.jQuery && window.jQuery.ajax && window.jQuery.Deferred) {
			install();
			return;
		}

		if (tries > 80) {
			mark('skipped', 'missing-jquery-timeout');
			return;
		}

		setTimeout(function () {
			installWhenReady(tries + 1);
		}, 25);
	}

	installWhenReady(0);
}());
