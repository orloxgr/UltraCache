/* UltraCache Admin - Varnish endpoint health and bounded metrics */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before varnish-metrics.js.');
	}

	const core = admin.get('core');
	const ui = admin.get('ui');
	if (!core || !ui) {
		throw new Error('UltraCache admin core/ui modules are required before varnish-metrics.js.');
	}

	const { h, __, sprintf, formatLooseTime } = core;
	const { StatusPill } = ui;

	function endpointTone(health) {
		if (health === 'healthy') {
			return 'success';
		}
		if (health === 'degraded' || health === 'unhealthy') {
			return 'warning';
		}
		return 'neutral';
	}

	function endpointLabel(endpoint) {
		const health = String(endpoint.health || 'untested');
		const latency = Number(endpoint.averageLatencyMs || 0);
		const failures = Number(endpoint.consecutiveFailures || 0);
		const parts = [health.replace(/-/g, ' ')];
		if (latency > 0) {
			parts.push(String(latency) + ' ms');
		}
		if (failures > 0) {
			parts.push(sprintf(__('%d consecutive failure(s)', 'ultracache'), failures));
		}
		return parts.join(' · ');
	}

	function renderStatusRows(metrics, queue, isAdminMode) {
		const state = metrics && typeof metrics === 'object' ? metrics : {};
		const operations = state.operations && typeof state.operations === 'object' ? state.operations : {};
		const endpoints = Array.isArray(state.endpoints) ? state.endpoints : [];
		const banPressure = state.banPressure && typeof state.banPressure === 'object' ? state.banPressure : {};
		const rows = [];

		endpoints.forEach((endpoint) => {
			const health = String(endpoint.health || 'untested');
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-endpoint-' + String(endpoint.label || '') }, [
				h('div', { className: 'text-sm text-white' }, String(endpoint.label || __('Varnish endpoint', 'ultracache'))),
				h(StatusPill, {
					ok: health === 'healthy',
					text: endpointLabel(endpoint),
					tone: endpointTone(health),
				}),
			]));
		});

		rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-recorded-invalidations' }, [
			h('div', { className: 'text-sm text-white' }, __('Recorded targeted invalidations', 'ultracache')),
			h(StatusPill, { ok: true, text: sprintf(__('%1$d operation(s) · %2$d unique URL(s)', 'ultracache'), Number(operations.invalidations || 0), Number(operations.uniqueUrls || 0)), tone: 'neutral' }),
		]));
		rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-refill-verification-metrics' }, [
			h('div', { className: 'text-sm text-white' }, __('Recorded refill verification', 'ultracache')),
			h(StatusPill, {
			ok: Number(operations.refillsBypassed || 0) === 0 && Number(operations.refillsInconclusive || 0) === 0,
			text: sprintf(__('%1$d verified · %2$d bypassed · %3$d inconclusive/not-hit', 'ultracache'), Number(operations.refillsVerified || 0), Number(operations.refillsBypassed || 0), Number(operations.refillsInconclusive || 0)),
			tone: Number(operations.refillsBypassed || 0) > 0 || Number(operations.refillsInconclusive || 0) > 0 ? 'warning' : 'neutral',
			}),
		]));

		rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-batching-saved' }, [
			h('div', { className: 'text-sm text-white' }, __('Endpoint requests saved by batching', 'ultracache')),
			h(StatusPill, { ok: true, text: String(Number(operations.requestsSavedByBatching || 0)), tone: 'neutral' }),
		]));
		rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-endpoint-failures' }, [
			h('div', { className: 'text-sm text-white' }, __('Recorded endpoint failures', 'ultracache')),
			h(StatusPill, { ok: Number(operations.endpointFailures || 0) === 0, text: String(Number(operations.endpointFailures || 0)), tone: Number(operations.endpointFailures || 0) > 0 ? 'warning' : 'neutral' }),
		]));
		rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-queue-retries' }, [
			h('div', { className: 'text-sm text-white' }, __('Pending retry jobs', 'ultracache')),
			h(StatusPill, {
				ok: Number(queue.retrying || 0) === 0,
				text: sprintf(__('%1$d job(s) · %2$d recorded attempt(s)', 'ultracache'), Number(queue.retrying || 0), Number(queue.retryAttempts || 0)),
				tone: Number(queue.retrying || 0) > 0 ? 'warning' : 'neutral',
			}),
		]));

		if (isAdminMode) {
			const pressureStatus = String(banPressure.status || 'not-tested');
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'varnish-ban-pressure' }, [
				h('div', { className: 'text-sm text-white' }, __('Ban-list pressure', 'ultracache')),
				h(StatusPill, {
					ok: pressureStatus === 'normal',
					text: banPressure.available
						? sprintf(__('%1$s · %2$d active · %3$d request-dependent', 'ultracache'), pressureStatus, Number(banPressure.activeEntries || 0), Number(banPressure.requestDependentEntries || 0))
						: pressureStatus.replace(/-/g, ' '),
					tone: pressureStatus === 'normal' ? 'success' : (pressureStatus === 'not-tested' || pressureStatus === 'unavailable' ? 'neutral' : 'warning'),
				}),
			]));
		}

		return rows;
	}

	function renderMessages(metrics) {
		const state = metrics && typeof metrics === 'object' ? metrics : {};
		const endpoints = Array.isArray(state.endpoints) ? state.endpoints : [];
		const banPressure = state.banPressure && typeof state.banPressure === 'object' ? state.banPressure : {};
		const messages = [];
		endpoints.forEach((endpoint) => {
			const timing = [];
			if (endpoint.lastSuccessAt) {
				timing.push(__('last success', 'ultracache') + ' ' + formatLooseTime(endpoint.lastSuccessAt));
			}
			if (endpoint.lastFailureAt) {
				timing.push(__('last failure', 'ultracache') + ' ' + formatLooseTime(endpoint.lastFailureAt));
			}
			if (endpoint.lastDetail || timing.length) {
				const details = [String(endpoint.lastDetail || '')].concat(timing).filter(Boolean).join(' · ');
				messages.push(h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'varnish-endpoint-message-' + String(endpoint.label || '') }, String(endpoint.label || '') + ': ' + details));
			}
		});
		if (banPressure.message) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'varnish-ban-pressure-message' }, String(banPressure.message)));
		}
		if (state.updatedAt) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'varnish-metrics-updated' }, __('Metrics updated:', 'ultracache') + ' ' + formatLooseTime(state.updatedAt)));
		}
		return messages;
	}

	admin.define('varnishMetrics', {
		renderStatusRows,
		renderMessages,
	});
})(window);
