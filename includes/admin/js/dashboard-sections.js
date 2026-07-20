/* UltraCache Admin - Dashboard section and summary-card UI */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before dashboard-sections.js.');
	}

	function createDashboardSections(context) {
		if (!context || typeof context !== 'object') {
			throw new Error('UltraCache dashboard sections require a context object.');
		}

		const {
			h,
			__,
			classNames,
			formatNumber,
			formatPercent,
			formatLooseTime,
			StatCard,
			StatusPill,
			hasDashboardStatsCounters,
			formatStatsNumber,
			formatStatsPercent,
			getStatsRefreshHint,
			formatObjectEntries,
			getObjectEntriesHint,
		} = context;

	function CacheStatisticsPanel({ settings, stats, diagnostics, busy, asyncActions, onToggleStats, onFullObjectCount }) {
		const enabled = !!(settings && settings.cacheStatsEnabled);
		return h('div', { className: 'uc-card uc-cache-stats-panel', key: 'cache-statistics-panel' }, [
			h('div', { className: 'flex flex-col md:flex-row md:items-start md:justify-between gap-4', key: 'head' }, [
				h('div', { key: 'copy' }, [
					h('div', { className: 'text-xs tracking-widest text-zinc-500 mb-1', key: 'eyebrow' }, __("Cache Statistics", 'ultracache')),
					h('h3', { className: 'text-lg font-black tracking-tight text-white m-0', key: 'title' }, __("Count Cache stats", 'ultracache')),
					h('p', { className: 'text-xs text-zinc-500 mt-2 mb-0 max-w-3xl', key: 'desc' }, __("Track cache hits, misses, bypasses and object-cache counters. Disabling this avoids counter writes during frontend requests.", 'ultracache')),
				]),
				h('label', {
					className: classNames('uc-toggle', busy ? 'opacity-60 pointer-events-none' : ''),
					key: 'toggle',
				}, [
					h('input', {
						type: 'checkbox',
						checked: enabled,
						disabled: !!busy,
						onChange: (event) => onToggleStats(event.target.checked),
					}),
					h('span', { className: 'slider' }),
				]),
			]),
			enabled
				? h('div', { className: 'uc-warning-box mt-4', key: 'warning' }, __("Enabling cache statistics may add a small performance overhead because UltraCache needs to update counters during requests. If you use Varnish, nginx full-page cache, Cloudflare, or another reverse proxy, stats may under-report requests served before WordPress.", 'ultracache'))
				: h('div', { className: 'text-xs text-zinc-500 mt-4', key: 'disabled-copy' }, __("Stats are disabled. The dashboard will not refresh or write live request counters until this switch is enabled.", 'ultracache')),
			enabled && !hasDashboardStatsCounters(stats)
				? h('div', { className: 'text-xs text-sky-300 bg-sky-500/10 rounded-xl px-3 py-2 mt-3', key: 'stats-refreshing-copy' }, __("Stats are ON. Waiting for the authenticated REST refresh; placeholder values are not shown as zeros.", 'ultracache'))
				: null,
			enabled
				? h('details', { className: 'uc-accordion uc-accordion--card mt-4', open: true, key: 'details' }, [
					h('summary', { key: 'summary' }, __("Show detailed stats", 'ultracache')),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: __("Cached Pages", 'ultracache'),
							value: formatStatsNumber(stats, typeof stats.pagesCached !== 'undefined' ? stats.pagesCached : stats.pageCacheFiles),
							hint: getStatsRefreshHint(stats, 'Stored HTML cache files'),
							key: 'pages',
						}),
						h(StatCard, {
							label: __("Object Entries", 'ultracache'),
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: __("Run full object-cache count", 'ultracache'),
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: __("Cache Size", 'ultracache'), value: hasDashboardStatsCounters(stats) ? (stats.cacheSizeHuman || '0 B') : 'Refreshing…', hint: getStatsRefreshHint(stats, 'Total cache footprint'), key: 'size' }),
						h(StatCard, { key: 'hits', label: __("Cache Hits", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheHits), hint: getStatsRefreshHint(stats, diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache') }),
						h(StatCard, { key: 'misses', label: __("Render Misses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheMisses), hint: getStatsRefreshHint(stats, 'Reached WordPress render path') }),
						h(StatCard, { key: 'bypasses', label: __("Bypasses", 'ultracache'), value: formatStatsNumber(stats, stats.pageCacheBypasses), hint: getStatsRefreshHint(stats, 'Skipped before buffering') }),
						h(StatCard, { key: 'ratio', label: __("Hit Ratio", 'ultracache'), value: formatStatsPercent(stats, stats.pageCacheHitRatio), hint: getStatsRefreshHint(stats, 'Hits ÷ (hits + misses)') }),
					]),
				])
				: h('details', { className: 'uc-accordion uc-accordion--card mt-4', key: 'manual-details' }, [
					h('summary', { key: 'summary' }, __("Manual object-cache diagnostics", 'ultracache')),
					h('div', { className: 'uc-summary-grid mt-4', key: 'grid' }, [
						h(StatCard, {
							label: __("Object Entries", 'ultracache'),
							value: formatObjectEntries(stats),
							hint: getObjectEntriesHint(stats),
							action: {
								label: '+',
								title: __("Run full object-cache count", 'ultracache'),
								onClick: onFullObjectCount,
								disabled: busy || !!(asyncActions && asyncActions.object_cache_full_count),
							},
							key: 'object-cache',
						}),
						h(StatCard, { label: __("Cache Size", 'ultracache'), value: stats.cacheSizeHuman || '0 B', hint: __("Static cache footprint", 'ultracache'), key: 'size' }),
					]),
				]),
		]);
	}

	function ActivitySummaryCard({ stats, cssBundleDiagnostics, open, onToggle }) {
		const lastPurge = stats && stats.lastPurge ? stats.lastPurge : {};
		const lastWarm = stats && stats.lastWarm ? stats.lastWarm : {};
		const counterRows = [
			['Warm successes', false, formatNumber(stats.warmSuccessCount || 0)],
			['Warm failures', false, formatNumber(stats.warmFailureCount || 0)],
			['Last warm files', false, typeof lastWarm.files !== 'undefined' ? formatNumber(lastWarm.files) : '0'],
			['Object cache hits', false, formatNumber(stats.objectCacheHits || 0)],
			['Object cache misses', false, formatNumber(stats.objectCacheMisses || 0)],
			['Object cache hit ratio', false, formatPercent(stats.objectCacheHitRatio || 0)],
		];
		const statusRows = [
			['Last warm result', false, typeof lastWarm.success === 'boolean' ? (lastWarm.success ? 'Success' : 'Failed') : '—'],
			['Last purge scope', false, lastPurge.scope || '—'],
		];
		const timelineRows = [
			['Last purge time', formatLooseTime(lastPurge)],
			['Last warm URL', lastWarm.url],
			['Last purge URL', lastPurge.url],
			['Last warm message', lastWarm.message],
			['Last warm time', formatLooseTime(lastWarm)],
		];

		const cssSummary = cssBundleDiagnostics || {};
		const cssFiles = cssSummary.files || {};
		const cssManifest = cssSummary.manifest || {};
		const cssSummaryRows = [
			['Bundles built', false, formatNumber(cssSummary.bundlesBuilt || 0)],
			['Styles bundled/scanned', false, formatNumber(cssSummary.stylesBundled || 0) + ' / ' + formatNumber(cssSummary.stylesScanned || 0)],
			['Skipped/unresolved', false, formatNumber(cssSummary.stylesSkipped || 0) + ' / ' + formatNumber(cssSummary.stylesUnresolved || 0)],
			['Last CSS bundle warm', false, formatLooseTime(cssSummary.lastWarm || null)],
			['Bundle files / delayed fonts', false, formatNumber(cssFiles.bundleFiles || 0) + ' / ' + formatNumber(cssFiles.delayedFontFiles || 0)],
			['Manifest entries / sources', false, formatNumber(cssManifest.entries || 0) + ' / ' + formatNumber(cssManifest.sourceUrls || 0)],
			['Missing main/delayed files', false, formatNumber(cssManifest.missingBundleFiles || 0) + ' / ' + formatNumber(cssManifest.missingDelayedFontFiles || 0)],
		];

		function renderSummaryRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: false, text: row[2], tone: 'neutral' }),
			])));
		}

		function renderStackRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.filter((row) => row[1] !== null && typeof row[1] !== 'undefined' && row[1] !== '').map((row) => h('div', { className: 'py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white mb-1' }, row[0]),
				h('div', { className: 'text-xs text-zinc-300 break-all whitespace-pre-wrap' }, String(row[1])),
			])));
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'activity-summary', open: !!open }, [
				h('summary', { className: 'uc-accordion__summary', onClick: function(event) { event.preventDefault(); if (onToggle) { onToggle(); } } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Activity Summary", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Recent operational events and warm/object-cache counters.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'activity-counters' }, [
				h('div', { className: 'uc-section-title' }, __("Activity counters", 'ultracache')),
				renderSummaryRows(counterRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-status' }, [
				h('div', { className: 'uc-section-title' }, __("Recent status", 'ultracache')),
				renderSummaryRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-css-bundle-summary' }, [
				h('div', { className: 'uc-section-title' }, __("CSS Bundle Summary", 'ultracache')),
				renderSummaryRows(cssSummaryRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-timeline' }, [
				h('div', { className: 'uc-section-title' }, __("Recent activity", 'ultracache')),
				renderStackRows(timelineRows),
			]),
				]),
			]),
		]);
	}

		return {
			CacheStatisticsPanel,
			ActivitySummaryCard,
		};
	}

	admin.define('dashboardSections', { createDashboardSections });
})(window);
