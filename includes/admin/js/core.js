/* UltraCache Admin - Core helpers */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before core.js.');
	}

	const elementApi = (window.wp && window.wp.element) ? window.wp.element : null;
	const ReactApi = elementApi || window.React;
	const ReactDOMApi = elementApi || window.ReactDOM;
	const { createElement: h, useCallback, useEffect, useMemo, useRef, useState } = ReactApi;
	const ultracacheI18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {};
	const __ = typeof ultracacheI18n.__ === 'function' ? ultracacheI18n.__ : function (value) { return value; };
	const sprintf = typeof ultracacheI18n.sprintf === 'function' ? ultracacheI18n.sprintf : function (value) { return value; };
	const ADMIN_ERROR_HISTORY_LIMIT = 50;
	const ADMIN_ERROR_DEDUPE_WINDOW_MS = 5000;
	const nonFatalAdminErrorHistory = [];

	const normalizePublicPath = (value) => String(value || '').replace(/\\/g, '/');
	const joinPublicPath = (base, relative) => {
		const root = normalizePublicPath(base).replace(/\/+$/, '');
		const child = normalizePublicPath(relative).replace(/^\/+/, '');
		return root && child ? `${root}/${child}` : (root || child);
	};

	function classNames() {
		return Array.prototype.slice.call(arguments).filter(Boolean).join(' ');
	}

	function normalizeContentEncoding(headerValue) {
		return String(headerValue || '').toLowerCase();
	}

	function responseHasEncoding(headerValue, token) {
		return normalizeContentEncoding(headerValue).split(',').map((part) => part.trim()).includes(String(token || '').toLowerCase());
	}

	function ignoreExpectedAdminFailure(error) {
		void error;
	}

	function normalizeAdminError(error) {
		if (error instanceof Error) {
			return { name: String(error.name || 'Error'), message: String(error.message || 'Unknown error') };
		}
		if (error && typeof error === 'object') {
			return {
				name: String(error.name || 'Error'),
				message: String(error.message || error.code || 'Unknown error'),
			};
		}
		return { name: 'Error', message: String(error || 'Unknown error') };
	}

	function isAdminDebugLoggingEnabled() {
		const runtime = window && window.ultracacheData && typeof window.ultracacheData === 'object' ? window.ultracacheData : {};
		const settings = runtime.settings && typeof runtime.settings === 'object' ? runtime.settings : {};
		return !!settings.debugHeadersEnabled;
	}

	function reportNonFatalAdminError(context, error, options) {
		const config = options && typeof options === 'object' ? options : {};
		const normalized = normalizeAdminError(error);
		const now = Date.now();
		const normalizedContext = String(context || 'admin.unknown').slice(0, 160);
		const severity = ['debug', 'info', 'warning', 'error'].indexOf(config.severity) !== -1 ? config.severity : 'warning';
		const dedupeKey = String(config.dedupeKey || (normalizedContext + '|' + normalized.name + '|' + normalized.message)).slice(0, 320);
		const dedupeWindowMs = Math.max(0, Number(typeof config.dedupeWindowMs === 'undefined' ? ADMIN_ERROR_DEDUPE_WINDOW_MS : config.dedupeWindowMs));
		let entry = null;
		for (let index = nonFatalAdminErrorHistory.length - 1; index >= 0; index -= 1) {
			const candidate = nonFatalAdminErrorHistory[index];
			if (candidate.dedupeKey === dedupeKey && now - Number(candidate.lastAt || 0) <= dedupeWindowMs) {
				entry = candidate;
				break;
			}
		}

		if (entry) {
			entry.lastAt = now;
			entry.count = Math.max(1, Number(entry.count || 1)) + 1;
		} else {
			entry = {
				context: normalizedContext,
				name: normalized.name.slice(0, 80),
				message: normalized.message.slice(0, 500),
				severity,
				firstAt: now,
				lastAt: now,
				count: 1,
				dedupeKey,
			};
			nonFatalAdminErrorHistory.push(entry);
			if (nonFatalAdminErrorHistory.length > ADMIN_ERROR_HISTORY_LIMIT) {
				nonFatalAdminErrorHistory.splice(0, nonFatalAdminErrorHistory.length - ADMIN_ERROR_HISTORY_LIMIT);
			}
		}

		if ((config.console === true || isAdminDebugLoggingEnabled()) && window.console && typeof window.console.warn === 'function') {
			window.console.warn('[UltraCache admin][' + normalizedContext + ']', error);
		}

		if (config.userVisible && typeof config.pushToast === 'function') {
			config.pushToast({
				type: config.toastType || ('error' === severity ? 'error' : 'warning'),
				text: String(config.toastText || normalized.message),
			});
		}

		return Object.assign({}, entry);
	}

	function getNonFatalAdminErrorHistory() {
		return nonFatalAdminErrorHistory.map(function (entry) {
			return Object.assign({}, entry);
		});
	}

	function clearNonFatalAdminErrorHistory() {
		nonFatalAdminErrorHistory.splice(0, nonFatalAdminErrorHistory.length);
	}

	function getLocalStorageSafe() {
		try {
			if (typeof window !== 'undefined' && window.localStorage) {
				return window.localStorage;
			}
		} catch (error) {
			ignoreExpectedAdminFailure(error);
		}
		return null;
	}

	function isMobileViewport() {
		if (typeof window === 'undefined') {
			return false;
		}
		return window.innerWidth <= 782;
	}

	function formatNumber(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toLocaleString() : '0';
	}

	function formatCount(value, isMinimum) {
		return formatNumber(value) + (isMinimum ? '+' : '');
	}

	function formatFileCount(value, isMinimum) {
		return formatCount(value, isMinimum) + ' files';
	}

	function formatDurationSeconds(value) {
		const seconds = Number(value || 0);
		if (!Number.isFinite(seconds) || seconds <= 0) {
			return '0 seconds';
		}
		if (seconds % 86400 === 0) {
			const days = seconds / 86400;
			return formatNumber(days) + ' day' + (days === 1 ? '' : 's');
		}
		if (seconds % 3600 === 0) {
			const hours = seconds / 3600;
			return formatNumber(hours) + ' hour' + (hours === 1 ? '' : 's');
		}
		if (seconds % 60 === 0) {
			const minutes = seconds / 60;
			return formatNumber(minutes) + ' minute' + (minutes === 1 ? '' : 's');
		}
		return formatNumber(seconds) + ' seconds';
	}

	function formatEventTime(event) {
		if (!event || typeof event !== 'object') {
			return 'No frontend cache event yet';
		}
	
		const numericTime = Number(event.time);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}
	
		const mysqlTime = event.time_mysql || (typeof event.time === 'string' ? event.time : '');
		if (mysqlTime) {
			const normalized = String(mysqlTime).replace(' ', 'T');
			const parsed = Date.parse(normalized);
			if (!Number.isNaN(parsed)) {
				return new Date(parsed).toLocaleString();
			}
		}
	
		return 'No frontend cache event yet';
	}

	function formatPercent(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toFixed(num % 1 === 0 ? 0 : 1) + '%' : '0%';
	}

	function formatLooseTime(value) {
		if (!value) {
			return '—';
		}
		if (typeof value === 'object') {
			return formatEventTime(value);
		}
		const numericTime = Number(value);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}
		const parsed = Date.parse(String(value).replace(' ', 'T'));
		return Number.isNaN(parsed) ? String(value) : new Date(parsed).toLocaleString();
	}

	function formatBytes(value) {
		const num = Number(value || 0);
		if (!Number.isFinite(num) || num <= 0) {
			return '0 B';
		}
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let size = num;
		let index = 0;
		while (size >= 1024 && index < units.length - 1) {
			size /= 1024;
			index += 1;
		}
		const fixed = size >= 100 || 0 === index ? 0 : 1;
		return size.toFixed(fixed) + ' ' + units[index];
	}

	function sleep(ms) {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	admin.define('core', {
		ReactDOMApi,
		h,
		useCallback,
		useEffect,
		useMemo,
		useRef,
		useState,
		__,
		sprintf,
		normalizePublicPath,
		joinPublicPath,
		classNames,
		normalizeContentEncoding,
		responseHasEncoding,
		getLocalStorageSafe,
		ignoreExpectedAdminFailure,
		reportNonFatalAdminError,
		getNonFatalAdminErrorHistory,
		clearNonFatalAdminErrorHistory,
		isMobileViewport,
		formatNumber,
		formatCount,
		formatFileCount,
		formatDurationSeconds,
		formatEventTime,
		formatPercent,
		formatLooseTime,
		formatBytes,
		sleep,
	});
})(window);
