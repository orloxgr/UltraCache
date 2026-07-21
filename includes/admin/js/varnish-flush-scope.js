/* UltraCache Admin - Varnish flush-scope method-contract UI */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before varnish-flush-scope.js.');
	}

	const core = admin.get('core');
	const ui = admin.get('ui');
	if (!core || !ui) {
		throw new Error('UltraCache admin core/ui modules are required before varnish-flush-scope.js.');
	}

	const { h, __, sprintf } = core;
	const { SelectField, StatusPill } = ui;

	function getState(form, flushScopeStatus) {
		const status = flushScopeStatus && typeof flushScopeStatus === 'object' ? flushScopeStatus : {};
		const capability = status.htmlCapability && typeof status.htmlCapability === 'object' ? status.htmlCapability : {};
		const methodCapability = status.methodCapability && typeof status.methodCapability === 'object'
			? status.methodCapability
			: capability;
		const topologyVerified = !!capability.topologyVerified;
		const automaticSupported = typeof methodCapability.htmlInvalidationContractSupported === 'boolean'
			? methodCapability.htmlInvalidationContractSupported
			: !!methodCapability.htmlInvalidationSupported;
		const manualSupported = automaticSupported;
		const transportAvailable = !!methodCapability.transportAvailable;
		const transportVerified = !!capability.transportVerified;
		const endpointBehaviorVerified = !!capability.endpointBehaviorVerified;
		const exactInvalidationSupported = !!methodCapability.exactInvalidationSupported;
		const exactInvalidationVerified = !!capability.exactInvalidationVerified;
		const entireHostVerified = !!capability.entireHostVerified;
		const staticRouteStatus = String(capability.staticRoute || 'untested').toLowerCase();
		const staticPreservationStatus = String(capability.staticPreservation || 'not-tested').toLowerCase();
		const entireHostStatus = String(capability.entireHostStatus || 'not-tested').toLowerCase();
		const entireHostStaticInvalidation = String(capability.entireHostStaticInvalidation || 'not-tested').toLowerCase();
		const configuredEndpointCount = Number(capability.configuredEndpointCount || methodCapability.endpointCount || 0);
		const successfulEndpointCount = Number(capability.successfulEndpointCount || 0);
		const failedEndpointCount = Number(capability.failedEndpointCount || 0);
		const transportMode = String(methodCapability.mode || capability.transportMode || '').toLowerCase();
		const transportMethod = String(methodCapability.method || capability.transportMethod || '');
		const contract = String(methodCapability.contract || 'unavailable').toLowerCase();
		const configured = form.varnishFlushScope || status.configured || 'auto';
		const effective = configured === 'host'
			? 'host'
			: (automaticSupported ? 'html' : 'host');
		const staticRouteLabel = staticRouteStatus === 'through-varnish'
			? __('Through Varnish', 'ultracache')
			: (staticRouteStatus === 'varnish-bypass'
				? __('Varnish bypass', 'ultracache')
				: (staticRouteStatus === 'admin-html-ban-static-opaque'
					? __('Opaque static route; HTML BAN scoped', 'ultracache')
					: (staticRouteStatus === 'outside-or-unobservable'
						? __('Outside Varnish or signals hidden', 'ultracache')
						: (staticRouteStatus === 'varnish-unverified'
							? __('Varnish visible, cache unverified', 'ultracache')
							: String(staticRouteStatus || __('Untested', 'ultracache')).replace(/-/g, ' ')))));
		const staticPreservationLabel = staticPreservationStatus === 'verified'
			? __('Verified', 'ultracache')
			: (staticPreservationStatus === 'verified-by-ban-scope'
				? __('Verified by HTML BAN scope', 'ultracache')
				: (staticPreservationStatus === 'not-required'
					? __('Not required', 'ultracache')
					: (staticPreservationStatus === 'unobservable'
						? __('Unobservable', 'ultracache')
						: (staticPreservationStatus === 'failed' ? __('Failed', 'ultracache') : __('Not tested', 'ultracache')))));
		const entireHostLabel = entireHostVerified
			? (entireHostStatus === 'verified-html-static-opaque' ? __('HTML verified; static opaque', 'ultracache') : __('Verified', 'ultracache'))
			: String(entireHostStatus || __('Not tested', 'ultracache')).replace(/-/g, ' ');
		const transportLabel = transportMethod || (transportMode === 'admin' ? __('admin BAN', 'ultracache') : __('Not tested', 'ultracache'));
		const endpointLabel = configuredEndpointCount > 0
			? sprintf(__('%1$d/%2$d complete · %3$d failed', 'ultracache'), successfulEndpointCount, configuredEndpointCount, failedEndpointCount)
			: __('Not tested', 'ultracache');
		const contractLabel = contract === 'admin-ban'
			? __('Admin BAN contract', 'ultracache')
			: (contract === 'http-ban'
				? __('HTTP BAN contract', 'ultracache')
				: (contract === 'http-purge' ? __('HTTP PURGE contract', 'ultracache') : __('Unavailable', 'ultracache')));

		return {
			capability,
			methodCapability,
			topologyVerified,
			automaticSupported,
			manualSupported,
			transportAvailable,
			transportVerified,
			endpointBehaviorVerified,
			exactInvalidationSupported,
			exactInvalidationVerified,
			entireHostVerified,
			configured,
			effective,
			configuredLabel: configured === 'html' ? __('HTML pages only', 'ultracache') : (configured === 'host' ? __('Entire host', 'ultracache') : __('Automatic', 'ultracache')),
			effectiveLabel: effective === 'html' ? __('HTML pages only', 'ultracache') : __('Entire host', 'ultracache'),
			staticRouteStatus,
			staticRouteLabel,
			staticPreservationStatus,
			staticPreservationLabel,
			entireHostStatus,
			entireHostLabel,
			entireHostStaticInvalidation,
			transportMode,
			transportMethod,
			transportLabel,
			contract,
			contractLabel,
			configuredEndpointCount,
			successfulEndpointCount,
			failedEndpointCount,
			endpointLabel,
		};
	}

	function renderControl({ state, busy, infrastructureLocked, onFieldChange }) {
		return h(SelectField, {
			label: __('Site-wide flush scope', 'ultracache'),
			description: state.automaticSupported
				? __('Automatic uses HTML-only invalidation because the configured BAN method exposes an explicit host-and-Content-Type contract.', 'ultracache')
				: __('Automatic uses an entire-host flush because the configured HTTP PURGE method has no portable HTML-only contract.', 'ultracache'),
			value: state.configured,
			onChange: (value) => onFieldChange('varnishFlushScope', value),
			disabled: busy || infrastructureLocked,
			options: [
				{ value: 'auto', label: __('Automatic', 'ultracache') },
				{
					value: 'html',
					label: state.automaticSupported ? __('HTML pages only', 'ultracache') : __('HTML pages only — unavailable for HTTP PURGE', 'ultracache'),
					disabled: !state.manualSupported,
					title: state.methodCapability.message || '',
				},
				{ value: 'host', label: __('Entire host', 'ultracache') },
			],
			key: 'flush-scope',
		});
	}

	function renderStatusRows(state) {
		return [
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-configured' }, [
				h('div', { className: 'text-sm text-white' }, __('Configured flush scope', 'ultracache')),
				h(StatusPill, { ok: true, text: state.configuredLabel, tone: 'neutral' }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-effective' }, [
				h('div', { className: 'text-sm text-white' }, __('Effective flush scope', 'ultracache')),
				h(StatusPill, { ok: state.effective === 'html', text: state.effectiveLabel, tone: state.effective === 'html' ? 'success' : 'neutral' }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-topology' }, [
				h('div', { className: 'text-sm text-white' }, __('Flush topology audit', 'ultracache')),
				h(StatusPill, {
					ok: state.topologyVerified,
					text: state.topologyVerified ? __('Verified', 'ultracache') : String(state.capability.status || __('Untested', 'ultracache')).replace(/-/g, ' '),
					tone: state.topologyVerified ? 'success' : 'neutral',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-transport' }, [
				h('div', { className: 'text-sm text-white' }, __('Invalidation transport', 'ultracache')),
				h(StatusPill, { ok: state.transportAvailable, text: state.transportAvailable ? state.transportLabel : __('Configuration incomplete', 'ultracache'), tone: state.transportAvailable ? 'success' : 'warning' }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-endpoints' }, [
				h('div', { className: 'text-sm text-white' }, __('Endpoint audit', 'ultracache')),
				h(StatusPill, { ok: state.transportVerified && state.failedEndpointCount === 0, text: state.endpointLabel, tone: state.failedEndpointCount > 0 ? 'warning' : (state.transportVerified ? 'success' : 'neutral') }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-endpoint-behavior' }, [
				h('div', { className: 'text-sm text-white' }, __('Per-endpoint public behavior', 'ultracache')),
				h(StatusPill, {
					ok: state.endpointBehaviorVerified,
					text: state.endpointBehaviorVerified ? __('Verified', 'ultracache') : (state.configuredEndpointCount > 1 ? __('Not individually verified', 'ultracache') : __('Not verified', 'ultracache')),
					tone: state.endpointBehaviorVerified ? 'success' : 'warning',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-exact' }, [
				h('div', { className: 'text-sm text-white' }, __('Exact URL invalidation', 'ultracache')),
				h(StatusPill, { ok: state.exactInvalidationSupported, text: state.exactInvalidationSupported ? __('Supported by method contract', 'ultracache') : __('Configuration incomplete', 'ultracache'), tone: state.exactInvalidationSupported ? 'success' : 'warning' }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-capability' }, [
				h('div', { className: 'text-sm text-white' }, __('HTML-only flush capability', 'ultracache')),
				h(StatusPill, {
					ok: state.automaticSupported,
					text: state.automaticSupported ? state.contractLabel : __('Unavailable for this method', 'ultracache'),
					tone: state.automaticSupported ? 'success' : 'neutral',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-entire-host' }, [
				h('div', { className: 'text-sm text-white' }, __('Entire-host behavior', 'ultracache')),
				h(StatusPill, { ok: state.entireHostVerified, text: state.entireHostLabel, tone: state.entireHostVerified ? 'success' : 'warning' }),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-static-route' }, [
				h('div', { className: 'text-sm text-white' }, __('Static delivery route', 'ultracache')),
				h(StatusPill, {
					ok: ['through-varnish', 'varnish-bypass', 'admin-html-ban-static-opaque'].indexOf(state.staticRouteStatus) !== -1,
					text: state.staticRouteLabel,
					tone: ['through-varnish', 'varnish-bypass', 'admin-html-ban-static-opaque'].indexOf(state.staticRouteStatus) !== -1 ? 'success' : 'warning',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-static-preservation' }, [
				h('div', { className: 'text-sm text-white' }, __('Static preservation after HTML-only flush', 'ultracache')),
				h(StatusPill, {
					ok: ['verified', 'verified-by-ban-scope', 'not-required'].indexOf(state.staticPreservationStatus) !== -1,
					text: state.staticPreservationLabel,
					tone: ['verified', 'verified-by-ban-scope', 'not-required'].indexOf(state.staticPreservationStatus) !== -1 ? 'success' : 'warning',
				}),
			]),
		];
	}

	function renderMessages(state) {
		const messages = [];
		if (state.methodCapability.message) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'flush-capability-message' }, String(state.methodCapability.message)));
		}
		if (state.capability.staticRouteMessage) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'flush-static-route-message' }, String(state.capability.staticRouteMessage)));
		}
		return messages;
	}

	admin.define('varnishFlushScope', { getState, renderControl, renderStatusRows, renderMessages });
})(window);
