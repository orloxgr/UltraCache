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
		const runtimePlan = status.runtimePlan && typeof status.runtimePlan === 'object' ? status.runtimePlan : {};
		const runtimeStrategy = String(status.runtimeStrategy || runtimePlan.selectedStrategy || 'none').toLowerCase();
		const runtimePlannedOutcome = String(status.runtimePlannedOutcome || runtimePlan.plannedOutcome || 'unsupported').toLowerCase();
		const runtimeDegraded = runtimePlannedOutcome === 'degraded';
		const runtimeFallback = !!status.runtimeFallback || !!runtimePlan.usingFallback;
		const actionAvailable = typeof status.actionAvailable === 'boolean' ? status.actionAvailable : !!runtimePlan.canExecute;
		const topologyVerified = !!capability.topologyVerified;
		const topologyTested = Number(capability.testedAt || 0) > 0;
		const htmlTested = Number(capability.htmlTestedAt || 0) > 0;
		const hostTested = Number(capability.hostTestedAt || 0) > 0;
		const automaticSupported = typeof methodCapability.htmlInvalidationContractSupported === 'boolean'
			? methodCapability.htmlInvalidationContractSupported
			: !!methodCapability.htmlInvalidationSupported;
		const manualSupported = automaticSupported;
		const hostSupported = typeof methodCapability.hostInvalidationSupported === 'boolean'
			? methodCapability.hostInvalidationSupported
			: !!methodCapability.hostInvalidationContractSupported;
		const transportAvailable = !!methodCapability.transportAvailable;
		const transportVerified = !!capability.transportVerified;
		const endpointBehaviorVerified = !!capability.endpointBehaviorVerified;
		const exactInvalidationSupported = !!methodCapability.exactInvalidationSupported;
		const exactInvalidationVerified = !!capability.exactInvalidationVerified;
		const exactInvalidationTested = Number(methodCapability.exactInvalidationTestedAt || 0) > 0;
		const entireHostVerified = !!capability.entireHostVerified;
		const staticRouteStatus = String(capability.staticRoute || 'untested').toLowerCase();
		const staticPreservationStatus = String(capability.staticPreservation || 'not-tested').toLowerCase();
		const entireHostStatus = String(capability.entireHostStatus || 'not-tested').toLowerCase();
		const entireHostNotApplicable = ['not-applicable', 'not-applicable-static-bypass'].indexOf(entireHostStatus) !== -1;
		const entireHostStaticInvalidation = String(capability.entireHostStaticInvalidation || 'not-tested').toLowerCase();
		const capabilityReason = String(capability.message || __('No flush-topology probe result is stored.', 'ultracache'));
		const methodReason = String(methodCapability.message || capabilityReason);
		const staticRouteReason = String(capability.staticRouteMessage || capabilityReason);
		const exactReason = String(methodCapability.exactInvalidationMessage || methodCapability.message || __('No exact-invalidation probe result is stored.', 'ultracache'));
		const configuredEndpointCount = Number(capability.configuredEndpointCount || methodCapability.endpointCount || 0);
		const successfulEndpointCount = Number(capability.successfulEndpointCount || 0);
		const failedEndpointCount = Number(capability.failedEndpointCount || 0);
		const transportMode = String(methodCapability.mode || capability.transportMode || '').toLowerCase();
		const transportMethod = String(methodCapability.method || capability.transportMethod || '');
		const contract = String(methodCapability.contract || 'unavailable').toLowerCase();
		const configured = form.varnishFlushScope || status.configured || 'auto';
		const reportedEffective = String(status.effective || '').toLowerCase();
		const effective = ['html', 'host', 'unsupported'].indexOf(reportedEffective) !== -1
			? reportedEffective
			: (configured === 'host'
				? (hostSupported ? 'host' : 'unsupported')
				: (automaticSupported ? 'html' : (hostSupported ? 'host' : 'unsupported')));
		const runtimeEffective = runtimeStrategy === 'html-flush'
			? 'html'
			: (runtimeStrategy === 'host-flush'
				? 'host'
				: (runtimeStrategy.indexOf('known-url-') === 0
					? 'known-urls'
					: (runtimeStrategy === 'ttl-expiry' ? 'ttl-expiry' : effective)));
		const runtimeEffectiveLabel = runtimeEffective === 'html'
			? __('HTML pages only', 'ultracache')
			: (runtimeEffective === 'host'
				? __('Entire host', 'ultracache')
				: (runtimeEffective === 'known-urls'
					? __('Known local URLs — degraded', 'ultracache')
					: (runtimeEffective === 'ttl-expiry' ? __('TTL expiry only', 'ultracache') : __('Unavailable', 'ultracache'))));
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
							: __('Not tested', 'ultracache') + ' · ' + staticRouteReason))));
		const staticPreservationLabel = staticPreservationStatus === 'verified'
			? __('Verified', 'ultracache')
			: (staticPreservationStatus === 'verified-by-ban-scope'
				? __('Verified by HTML BAN scope', 'ultracache')
				: (staticPreservationStatus === 'not-required'
					? __('Not required', 'ultracache')
					: (staticPreservationStatus === 'unobservable'
						? __('Unobservable', 'ultracache')
						: (staticPreservationStatus === 'failed' ? __('Failed', 'ultracache') : __('Not tested', 'ultracache') + ' · ' + staticRouteReason))));
		const entireHostLabel = entireHostNotApplicable
			? __('Not applicable', 'ultracache') + ' · ' + capabilityReason
			: (entireHostVerified
				? (entireHostStatus === 'verified-html-static-opaque' ? __('HTML verified; static opaque', 'ultracache') : __('Verified', 'ultracache'))
				: (entireHostStatus === 'observation-incomplete'
					? __('Observation incomplete', 'ultracache') + ' · ' + capabilityReason
					: (hostTested
						? __('Not supported', 'ultracache') + ' · ' + capabilityReason
						: __('Not tested', 'ultracache') + ' · ' + capabilityReason)));
		const transportLabel = transportMethod || (transportMode === 'admin' ? __('admin BAN', 'ultracache') : __('Not tested', 'ultracache') + ' · ' + methodReason);
		const endpointLabel = configuredEndpointCount > 0
			? sprintf(__('%1$d/%2$d complete · %3$d failed', 'ultracache'), successfulEndpointCount, configuredEndpointCount, failedEndpointCount)
			: __('Not tested', 'ultracache') + ' · ' + __('No endpoint topology probe result is stored.', 'ultracache');
		const contractLabel = contract === 'admin-ban'
			? __('Admin BAN contract', 'ultracache')
			: (contract === 'http-ban'
				? __('HTTP BAN contract', 'ultracache')
				: (contract === 'http-purge' ? __('HTTP PURGE contract', 'ultracache') : __('Unavailable', 'ultracache')));

		return {
			capability,
			methodCapability,
			topologyVerified,
			topologyTested,
			htmlTested,
			hostTested,
			automaticSupported,
			manualSupported,
			hostSupported,
			transportAvailable,
			transportVerified,
			endpointBehaviorVerified,
			exactInvalidationSupported,
			exactInvalidationVerified,
			exactInvalidationTested,
			entireHostVerified,
			entireHostNotApplicable,
			configured,
			effective: runtimeEffective,
			directEffective: effective,
			actionAvailable,
			runtimePlan,
			runtimeStrategy,
			runtimePlannedOutcome,
			runtimeDegraded,
			runtimeFallback,
			runtimeReason: String(runtimePlan.reason || ''),
			configuredLabel: configured === 'html' ? __('HTML pages only', 'ultracache') : (configured === 'host' ? __('Entire host', 'ultracache') : __('Automatic', 'ultracache')),
			effectiveLabel: runtimeEffectiveLabel,
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
			capabilityReason,
			methodReason,
			staticRouteReason,
			exactReason,
		};
	}

	function renderControl({ state, busy, infrastructureLocked, onFieldChange }) {
		return h(SelectField, {
			label: __('Site-wide flush scope', 'ultracache'),
			description: state.runtimeStrategy.indexOf('known-url-') === 0
				? __('No direct site-wide scope is verified. Site flush invalidates the bounded set of local URLs known to the full-site crawl planner and reports a degraded result.', 'ultracache')
				: (state.runtimeStrategy === 'ttl-expiry'
					? __('No immediate site invalidation strategy is verified. Shared-cache objects remain correct through the configured TTL-expiry-only policy.', 'ultracache')
					: (state.runtimeStrategy === 'host-flush'
						? __('Automatic uses the verified entire-host invalidation scope because the observed topology requires it.', 'ultracache')
						: (state.runtimeStrategy === 'html-flush'
							? (state.runtimeDegraded
								? __('Automatic uses verified HTML-only invalidation, but the observed static-object route is not fully covered.', 'ultracache')
								: __('Automatic uses the verified HTML-only invalidation scope because static objects bypass Varnish.', 'ultracache'))
							: __('No site-wide scope is verified. Exact URL invalidation remains available after the canary test succeeds.', 'ultracache')))),
			value: state.configured,
			onChange: (value) => onFieldChange('varnishFlushScope', value),
			disabled: busy || infrastructureLocked,
			options: [
				{ value: 'auto', label: __('Automatic', 'ultracache') },
				{
					value: 'html',
					label: state.automaticSupported
						? __('HTML pages only', 'ultracache')
						: (state.htmlTested ? __('HTML pages only — not supported', 'ultracache') + ': ' + state.capabilityReason : __('HTML pages only — not tested', 'ultracache') + ': ' + state.capabilityReason),
					disabled: !state.manualSupported,
					title: state.methodCapability.message || '',
				},
				{
					value: 'host',
					label: state.hostSupported
						? __('Entire host', 'ultracache')
						: (state.entireHostNotApplicable
							? __('Entire host — not applicable', 'ultracache')
							: (state.hostTested ? __('Entire host — not supported', 'ultracache') + ': ' + state.capabilityReason : __('Entire host — not tested', 'ultracache') + ': ' + state.capabilityReason)),
					disabled: !state.hostSupported,
					title: state.methodCapability.message || '',
				},
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
				h(StatusPill, {
					ok: state.actionAvailable,
					text: state.effectiveLabel,
					tone: state.runtimeDegraded || ['unsupported', 'ttl-expiry'].indexOf(state.effective) !== -1 ? 'warning' : (state.effective === 'html' ? 'success' : 'neutral'),
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-topology' }, [
				h('div', { className: 'text-sm text-white' }, __('Flush topology audit', 'ultracache')),
				h(StatusPill, {
					ok: state.topologyVerified,
					text: state.topologyVerified
						? __('Supported', 'ultracache')
						: (state.topologyTested ? __('Not supported', 'ultracache') + ' · ' + state.capabilityReason : __('Not tested', 'ultracache') + ' · ' + state.capabilityReason),
					tone: state.topologyVerified ? 'success' : (state.topologyTested ? 'warning' : 'neutral'),
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
					text: state.endpointBehaviorVerified
						? __('Supported', 'ultracache')
						: (state.topologyTested ? __('Not supported', 'ultracache') + ' · ' + state.capabilityReason : __('Not tested', 'ultracache') + ' · ' + state.capabilityReason),
					tone: state.endpointBehaviorVerified ? 'success' : (state.topologyTested ? 'warning' : 'neutral'),
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-exact' }, [
				h('div', { className: 'text-sm text-white' }, __('Exact URL invalidation', 'ultracache')),
				h(StatusPill, {
					ok: state.exactInvalidationSupported,
					text: state.exactInvalidationSupported
						? __('Supported', 'ultracache')
						: (state.exactInvalidationTested ? __('Not supported', 'ultracache') + ' · ' + state.exactReason : __('Not tested', 'ultracache') + ' · ' + state.exactReason),
					tone: state.exactInvalidationSupported ? 'success' : (state.exactInvalidationTested ? 'warning' : 'neutral'),
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-capability' }, [
				h('div', { className: 'text-sm text-white' }, __('HTML-only flush capability', 'ultracache')),
				h(StatusPill, {
					ok: state.automaticSupported,
					text: state.automaticSupported
						? __('Supported', 'ultracache')
						: (state.htmlTested ? __('Not supported', 'ultracache') + ' · ' + state.capabilityReason : __('Not tested', 'ultracache') + ' · ' + state.capabilityReason),
					tone: state.automaticSupported ? 'success' : (state.htmlTested ? 'warning' : 'neutral'),
				}),
			]),
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'flush-entire-host' }, [
				h('div', { className: 'text-sm text-white' }, __('Entire-host behavior', 'ultracache')),
				h(StatusPill, {
					ok: state.entireHostVerified || state.entireHostNotApplicable,
					text: state.entireHostNotApplicable
						? __('Not applicable', 'ultracache') + ' · ' + state.capabilityReason
						: (state.entireHostVerified
							? __('Supported', 'ultracache')
							: (state.hostTested ? __('Not supported', 'ultracache') + ' · ' + state.capabilityReason : __('Not tested', 'ultracache') + ' · ' + state.capabilityReason)),
					tone: state.entireHostVerified ? 'success' : (state.entireHostNotApplicable ? 'neutral' : (state.hostTested ? 'warning' : 'neutral')),
				}),
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
		if (state.runtimeReason) {
			messages.push(h('div', { className: state.runtimeDegraded ? 'text-xs text-amber-300 mt-2' : 'text-xs text-zinc-500 mt-2', key: 'flush-runtime-plan-message' }, String(state.runtimeReason)));
		}
		return messages;
	}

	admin.define('varnishFlushScope', { getState, renderControl, renderStatusRows, renderMessages });
})(window);
