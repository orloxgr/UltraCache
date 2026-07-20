/* UltraCache Admin - Varnish flush-scope topology UI */
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

	const { h, __ } = core;
	const { SelectField, StatusPill } = ui;

	function getState(form, flushScopeStatus) {
		const status = flushScopeStatus && typeof flushScopeStatus === 'object' ? flushScopeStatus : {};
		const capability = status.htmlCapability && typeof status.htmlCapability === 'object' ? status.htmlCapability : {};
		const automaticSupported = !!capability.supported;
		const manualSupported = !!capability.manualSupported || automaticSupported;
		const staticRouteStatus = String(capability.staticRoute || 'untested').toLowerCase();
		const staticPreservationStatus = String(capability.staticPreservation || 'not-tested').toLowerCase();
		const configured = form.varnishFlushScope || status.configured || 'auto';
		const effective = configured === 'host'
			? 'host'
			: ((configured === 'html' && manualSupported) || (configured === 'auto' && automaticSupported) ? 'html' : 'host');
		const staticRouteLabel = staticRouteStatus === 'through-varnish'
			? __('Through Varnish', 'ultracache')
			: (staticRouteStatus === 'varnish-bypass'
				? __('Varnish bypass', 'ultracache')
				: (staticRouteStatus === 'outside-or-unobservable'
					? __('Outside Varnish or signals hidden', 'ultracache')
					: (staticRouteStatus === 'varnish-unverified'
						? __('Varnish visible, cache unverified', 'ultracache')
						: String(staticRouteStatus || __('Untested', 'ultracache')).replace(/-/g, ' '))));
		const staticPreservationLabel = staticPreservationStatus === 'verified'
			? __('Verified', 'ultracache')
			: (staticPreservationStatus === 'not-required'
				? __('Not required', 'ultracache')
				: (staticPreservationStatus === 'failed' ? __('Failed', 'ultracache') : __('Not tested', 'ultracache')));

		return {
			capability,
			automaticSupported,
			manualSupported,
			configured,
			effective,
			configuredLabel: configured === 'html' ? __('HTML pages only', 'ultracache') : (configured === 'host' ? __('Entire host', 'ultracache') : __('Automatic', 'ultracache')),
			effectiveLabel: effective === 'html' ? __('HTML pages only', 'ultracache') : __('Entire host', 'ultracache'),
			staticRouteStatus,
			staticRouteLabel,
			staticPreservationStatus,
			staticPreservationLabel,
		};
	}

	function renderControl({ state, busy, infrastructureLocked, onFieldChange }) {
		return h(SelectField, {
			label: __('Site-wide flush scope', 'ultracache'),
			description: state.automaticSupported
				? __('Automatic uses verified HTML-only invalidation. Static delivery is classified independently so the test works with Varnish-all, HTML-only Varnish, and layered proxy topologies.', 'ultracache')
				: (state.manualSupported
					? __('HTML-only invalidation is verified for manual use, but Automatic keeps the entire-host scope because the public static route was not fully observable.', 'ultracache')
					: __('Automatic uses an entire-host flush until Test Varnish verifies HTML-only invalidation for the current topology.', 'ultracache')),
			value: state.configured,
			onChange: (value) => onFieldChange('varnishFlushScope', value),
			disabled: busy || infrastructureLocked,
			options: [
				{ value: 'auto', label: __('Automatic', 'ultracache') },
				{
					value: 'html',
					label: state.automaticSupported
						? __('HTML pages only', 'ultracache')
						: (state.manualSupported ? __('HTML pages only — manual verification', 'ultracache') : __('HTML pages only — run behavior test first', 'ultracache')),
					disabled: !state.manualSupported,
					title: state.capability.message || '',
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
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-capability' }, [
				h('div', { className: 'text-sm text-white' }, __('HTML-only flush capability', 'ultracache')),
				h(StatusPill, {
					ok: state.automaticSupported,
					text: state.automaticSupported
						? __('Verified for Automatic', 'ultracache')
						: (state.manualSupported ? __('Verified for manual use', 'ultracache') : String(state.capability.status || __('Untested', 'ultracache')).replace(/-/g, ' ')),
					tone: state.automaticSupported ? 'success' : 'warning',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-static-route' }, [
				h('div', { className: 'text-sm text-white' }, __('Static delivery route', 'ultracache')),
				h(StatusPill, {
					ok: ['through-varnish', 'varnish-bypass', 'outside-or-unobservable'].indexOf(state.staticRouteStatus) !== -1,
					text: state.staticRouteLabel,
					tone: ['through-varnish', 'varnish-bypass'].indexOf(state.staticRouteStatus) !== -1 ? 'success' : 'neutral',
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-static-preservation' }, [
				h('div', { className: 'text-sm text-white' }, __('Static preservation test', 'ultracache')),
				h(StatusPill, {
					ok: ['verified', 'not-required'].indexOf(state.staticPreservationStatus) !== -1,
					text: state.staticPreservationLabel,
					tone: state.staticPreservationStatus === 'verified' ? 'success' : (state.staticPreservationStatus === 'failed' ? 'warning' : 'neutral'),
				}),
			]),
		];
	}

	function renderMessages(state) {
		const messages = [];
		if (state.capability.message) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'flush-capability-message' }, String(state.capability.message)));
		}
		if (state.capability.staticRouteMessage) {
			messages.push(h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'flush-static-route-message' }, String(state.capability.staticRouteMessage)));
		}
		return messages;
	}

	admin.define('varnishFlushScope', { getState, renderControl, renderStatusRows, renderMessages });
})(window);
