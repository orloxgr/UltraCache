/* UltraCache Admin - Varnish refresh-ahead controls */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before varnish-refresh-ahead.js.');
	}

	const core = admin.get('core');
	const ui = admin.get('ui');
	if (!core || !ui) {
		throw new Error('UltraCache admin core/ui modules are required before varnish-refresh-ahead.js.');
	}

	const { h, __, sprintf } = core;
	const { ToggleRow, NumberRow, TextAreaField, StatusPill } = ui;

	function getViewState(form, refreshAhead) {
		const source = refreshAhead && typeof refreshAhead === 'object' ? refreshAhead : {};
		const state = source.state && typeof source.state === 'object' ? source.state : {};
		return {
			available: !!source.available,
			active: !!source.active,
			state,
			threshold: Number.isFinite(Number(form.varnishRefreshAheadThresholdPercent)) ? Math.max(50, Math.min(95, Number(form.varnishRefreshAheadThresholdPercent))) : 85,
			maxPages: Number.isFinite(Number(form.varnishRefreshAheadMaxPages)) ? Math.max(1, Math.min(10, Number(form.varnishRefreshAheadMaxPages))) : 5,
			pinnedUrls: String(form.varnishRefreshAheadPinnedUrls || ''),
		};
	}

	function renderControls({ form, refreshAhead, busy, infrastructureLocked, onFieldChange }) {
		const view = getViewState(form, refreshAhead);
		return [
			h(ToggleRow, {
				label: __("Refresh hot pages before expiry", 'ultracache'),
				description: view.available
					? __("Uses a bounded candidate pool collected only by the active scanner from critical WordPress pages, menus, sitemap discovery, pinned URLs, and optional Cache Statistics observations. Each scan rotates the probe bucket, while eligible pages are refilled across all active HTML buckets.", 'ultracache')
					: String(refreshAhead.message || __("Refresh ahead is unavailable until the configured HTTP soft-purge and stale-refresh capability is active.", 'ultracache')),
				checked: !!form.varnishRefreshAheadEnabled,
				onChange: (value) => onFieldChange('varnishRefreshAheadEnabled', value),
				disabled: busy || infrastructureLocked || (!view.available && !form.varnishRefreshAheadEnabled),
				key: 'varnish-refresh-ahead-toggle',
			}),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4', key: 'varnish-refresh-ahead-fields' }, [
				h(NumberRow, {
					label: __("Refresh threshold (% of TTL)", 'ultracache'),
					description: __("Probe candidates and refresh only verified Varnish HITs whose numeric Age has reached this percentage of the configured shared HTML TTL.", 'ultracache'),
					value: view.threshold,
					onChange: (value) => onFieldChange('varnishRefreshAheadThresholdPercent', value),
					disabled: busy || infrastructureLocked || !form.varnishRefreshAheadEnabled || !view.available,
					min: 50,
					max: 95,
					step: 1,
					key: 'refresh-ahead-threshold',
				}),
				h(NumberRow, {
					label: __("Maximum pages per scan", 'ultracache'),
					description: __("Limits both Age probes and possible refreshes in one scan. The default is 5 and the maximum is 10.", 'ultracache'),
					value: view.maxPages,
					onChange: (value) => onFieldChange('varnishRefreshAheadMaxPages', value),
					disabled: busy || infrastructureLocked || !form.varnishRefreshAheadEnabled || !view.available,
					min: 1,
					max: 10,
					step: 1,
					key: 'refresh-ahead-max-pages',
				}),
			]),
			h(TextAreaField, {
				label: __("Pinned critical URLs", 'ultracache'),
				description: __("Optional local URLs or paths, one per line. Pinned pages receive the highest refresh-ahead priority; the list is limited to 25 entries.", 'ultracache'),
				value: view.pinnedUrls,
				onChange: (value) => onFieldChange('varnishRefreshAheadPinnedUrls', value),
				disabled: busy || infrastructureLocked,
				placeholder: "/\n/news/\nhttps://example.com/critical-page/",
				key: 'refresh-ahead-pinned-urls',
			}),
		];
	}

	function renderStatusRows(form, refreshAhead) {
		const source = refreshAhead && typeof refreshAhead === 'object' ? refreshAhead : {};
		const view = getViewState(form, source);
		const rows = [
			h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'refresh-ahead-status' }, [
				h('div', { className: 'text-sm text-white' }, __("Hot-page refresh ahead", 'ultracache')),
				h(StatusPill, {
					ok: view.active,
					text: view.active ? __("Active", 'ultracache') : (view.available ? __("Available", 'ultracache') : String(source.status || __("Unavailable", 'ultracache')).replace(/-/g, ' ')),
					tone: view.active ? 'success' : (form.varnishRefreshAheadEnabled ? 'warning' : 'neutral'),
				}),
			]),
		];

		if (view.active) {
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'refresh-ahead-candidates' }, [
				h('div', { className: 'text-sm text-white' }, __("Refresh candidates", 'ultracache')),
				h(StatusPill, { ok: Number(source.candidateCount || 0) > 0, text: String(Number(source.candidateCount || 0)), tone: Number(source.candidateCount || 0) > 0 ? 'neutral' : 'warning' }),
			]));
		}

		const candidateSources = source.candidateSources && typeof source.candidateSources === 'object' ? source.candidateSources : {};
		const sourceSummary = Object.keys(candidateSources)
			.filter((key) => Number(candidateSources[key] || 0) > 0)
			.map((key) => `${key.replace(/-/g, ' ')} ${Number(candidateSources[key] || 0)}`)
			.join(' · ');
		if (sourceSummary) {
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'refresh-ahead-candidate-sources' }, [
				h('div', { className: 'text-sm text-white' }, __("Candidate sources", 'ultracache')),
				h('div', { className: 'text-xs text-zinc-400 text-right' }, sourceSummary),
			]));
		}

		if (view.state.lastProbeBucket) {
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'refresh-ahead-probe-bucket' }, [
				h('div', { className: 'text-sm text-white' }, __("Last probe bucket", 'ultracache')),
				h(StatusPill, { ok: true, text: String(view.state.lastProbeBucket).toUpperCase(), tone: 'neutral' }),
			]));
		}

		if (view.state.lastScanAt) {
			rows.push(h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'refresh-ahead-last-scan' }, [
				h('div', { className: 'text-sm text-white' }, __("Last refresh-ahead scan", 'ultracache')),
				h(StatusPill, {
					ok: Number(view.state.errorCount || 0) === 0,
					text: sprintf(__('%1$d probed · %2$d near expiry · %3$d queued', 'ultracache'), Number(view.state.probedCount || 0), Number(view.state.eligibleCount || 0), Number(view.state.queuedCount || 0)),
					tone: Number(view.state.errorCount || 0) > 0 ? 'warning' : 'neutral',
				}),
			]));
		}

		return rows;
	}

	function renderMessages(refreshAhead) {
		const source = refreshAhead && typeof refreshAhead === 'object' ? refreshAhead : {};
		const state = source.state && typeof source.state === 'object' ? source.state : {};
		return [
			source.message ? h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'refresh-ahead-message' }, String(source.message)) : null,
			state.lastMessage ? h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'refresh-ahead-last-message' }, String(state.lastMessage)) : null,
		];
	}

	admin.define('varnishRefreshAhead', {
		renderControls,
		renderStatusRows,
		renderMessages,
	});
})(window);
