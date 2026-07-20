/* UltraCache Admin - Dashboard diagnostics UI */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before dashboard-diagnostics-ui.js.');
	}

	function createDashboardDiagnosticsUi(context) {
		if (!context || typeof context !== 'object') {
			throw new Error('UltraCache dashboard diagnostics UI requires a context object.');
		}

		const {
			h,
			useState,
			useEffect,
			__,
			formatNumber,
			formatBytes,
			formatFileCount,
			formatCount,
			formatLooseTime,
			formatDurationSeconds,
			formatEventTime,
			getCssCleanupGraceLabel,
			getCssCleanupDeleteLimitLabel,
			DetailRow,
			StatusPill,
			ToggleRow,
			SelectField,
		} = context;

		function DiagnosticsCard({ diagnostics, stats, open, onToggle, onRefreshStorageDiagnostics, busy }) {
		const compressionStatus = diagnostics.compression || {};
		const pathDiagnostics = diagnostics.paths || {};
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const environmentDiag = diagnostics.environment || {};
		const mediaRuntimeDiag = diagnostics.mediaRuntime || {};
		const analyticsBackend = diagnostics.analytics || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const analyticsProbeText = analyticsBackend && analyticsBackend.enabled ? ((analyticsBackend.readWrite === false) ? ' · probe failed' : (analyticsBackend.readWrite ? ' · probe passed' : '')) : '';
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const cacheStorageDiag = diagnostics.cacheStorage || {};
		const storageTotal = cacheStorageDiag.total || {};
		const storagePageCache = cacheStorageDiag.pageCache || {};
		const storageCssBundles = cacheStorageDiag.cssBundles || {};
		const storageObjectDisk = cacheStorageDiag.objectCacheDisk || {};
		const storageMediaCache = cacheStorageDiag.mediaCache || {};
		const storageWarnings = Array.isArray(cacheStorageDiag.warnings) ? cacheStorageDiag.warnings : [];
		const storageWarningLevel = cacheStorageDiag.warningLevel || 'ok';
		const avifDirDiag = pathDiagnostics.avifDir || {};
		const webpDirDiag = pathDiagnostics.webpDir || {};
		const browserCacheRulesDiag = pathDiagnostics.browserCacheRules || {};
		const proxyProviderText = reverseProxy && reverseProxy.provider ? reverseProxy.provider : (reverseProxy && reverseProxy.detected ? 'Detected' : 'Not detected');
		const reverseProxyText = reverseProxy.detected
			? (proxyProviderText + (reverseProxy.x_cache ? ' · ' + reverseProxy.x_cache : '') + (reverseProxy.x_litespeed_cache ? ' · ' + reverseProxy.x_litespeed_cache : '') + (reverseProxy.x_cache_status ? ' · ' + reverseProxy.x_cache_status : ''))
			: 'Not detected';
		const loadedRuntime = runtimeConfigDiag.loaded || {};
		const allowlist = Array.isArray(loadedRuntime.cache_query_allowlist) ? loadedRuntime.cache_query_allowlist : [];
		const queryStringCachingText = loadedRuntime.cache_query_strings
			? ('Enabled' + (allowlist.length ? ' - Whitelist: ' + allowlist.join(', ') : ' - Whitelist empty: query-string variants bypass cache'))
			: 'Disabled';
		const safeTrackingCookieCacheText = loadedRuntime.cache_safe_tracking_cookies === false
			? 'Strict mode: any Set-Cookie skips cache'
			: 'Enabled: safe tracking Set-Cookie can store public HTML';
		const objectCacheStatus = diagnostics.objectCache || {};
		const pageCacheStatus = diagnostics.pageCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || (objectCacheStatus.fallbackActive ? activeObjectBackend : 'apcu');
		const objectRuntimeOnly = activeObjectBackend === 'runtime' || !!objectCacheStatus.activeBackendRuntimeOnly;
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend && activeObjectBackend && selectedObjectBackend !== activeObjectBackend);
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = objectRuntimeOnly ? 'Runtime only' : (activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable');
		const fallbackObjectBackendText = fallbackObjectBackend ? (String(fallbackObjectBackend).toLowerCase() === 'runtime' ? 'Runtime only' : String(fallbackObjectBackend).toUpperCase()) : 'Unavailable';
		const objectCacheActiveTone = !objectCacheStatus.enabled ? 'neutral' : (!objectCacheStatus.active ? 'warning' : (objectFallbackActive || objectRuntimeOnly ? 'warning' : 'success'));
		const objectCacheFallbackTone = objectFallbackActive ? 'warning' : 'neutral';

		function describeDropIn(diag) {
			if (!diag || !diag.exists) {
				return 'Missing';
			}
			const parts = ['Present', diag.managed ? 'Managed by UltraCache' : 'Not managed'];
			if (diag.dropInBuild) {
				parts.push('Build ' + diag.dropInBuild);
			}
			return parts.join(' · ');
		}

		const statusRows = [
			['Page cache switch', !!pageCacheStatus.enabled, pageCacheStatus.enabled ? 'Enabled' : 'Disabled'],
			['Page cache drop-in active', !!pageCacheStatus.active, pageCacheStatus.active ? 'Active' : (pageCacheStatus.enabled ? 'Configured · drop-in inactive' : 'Disabled')],
			['Object cache switch', !!objectCacheStatus.enabled, objectCacheStatus.enabled ? 'Enabled' : 'Disabled'],
			['Selected object cache backend', !!objectCacheStatus.enabled, objectCacheStatus.enabled ? selectedObjectBackendText : 'Disabled'],
			['Active object cache backend', !!objectCacheStatus.active, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled'), objectCacheActiveTone],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby'), objectCacheFallbackTone],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : ('Disabled' + (analyticsBackend.message ? ' · ' + analyticsBackend.message : ''))],
			['Cron Warm Up', diagnostics.cronWarm && diagnostics.cronWarm.active, diagnostics.cronWarm && diagnostics.cronWarm.blockedByManualWarm ? __('Blocked by manual warm-up', 'ultracache') : (diagnostics.cronWarm && diagnostics.cronWarm.active ? ('Running · ' + formatNumber(diagnostics.cronWarm.processed || 0) + '/' + formatNumber(diagnostics.cronWarm.total || 0)) : diagnostics.cronWarm && diagnostics.cronWarm.enabled ? ((diagnostics.cronWarm.completed ? 'Completed' : 'Enabled') + ' · ' + formatNumber(diagnostics.cronWarm.pagesPerMinute || 0) + '/min') : 'Disabled')],
			['Varnish', diagnostics.varnish && diagnostics.varnish.enabled, diagnostics.varnish && diagnostics.varnish.enabled ? ('Varnish mode: ' + ((diagnostics.varnish.configuredMode || diagnostics.varnish.mode || 'http') === 'admin' ? 'admin-secret' : 'HTTP endpoint') + ' · ' + (diagnostics.varnish.effectiveMethod || (((diagnostics.varnish.mode || 'http') === 'admin') ? 'admin BAN' : (diagnostics.varnish.method || 'BAN'))) + ' · ' + ((diagnostics.varnish.endpointCount || 0) ? (diagnostics.varnish.endpointCount + ' endpoint(s)') : ((diagnostics.varnish.servers || '').trim() || 'No endpoints'))) : 'Disabled'],
			['Reverse Proxy', !!reverseProxy.detected, reverseProxyText],
			['Brotli Compression', compressionStatus.brotli && compressionStatus.brotli.available, compressionStatus.brotli && compressionStatus.brotli.available ? (compressionStatus.preferred === 'brotli' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['Gzip Compression', compressionStatus.gzip && compressionStatus.gzip.available, compressionStatus.gzip && compressionStatus.gzip.available ? (compressionStatus.preferred === 'gzip' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['AVIF Capability', diagnostics.formats && diagnostics.formats.avif, diagnostics.formats && diagnostics.formats.avif ? 'Available' : 'Unavailable'],
			['WebP Capability', diagnostics.formats && diagnostics.formats.webp, diagnostics.formats && diagnostics.formats.webp ? 'Available' : 'Unavailable'],
			['Query-string args caching', !!loadedRuntime.cache_query_strings, queryStringCachingText],
			['Safe tracking cookie cache', loadedRuntime.cache_safe_tracking_cookies !== false, safeTrackingCookieCacheText],
		];
		const runtimeRows = [
			['Cache directory', !!cacheDirDiag.exists, cacheDirDiag.exists ? (cacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['Advanced cache drop-in', !!advancedCacheDiag.exists, describeDropIn(advancedCacheDiag)],
			['Advanced-cache generated version', !!advancedCacheDiag.dropInBuild, advancedCacheDiag.dropInBuild ? ('Build ' + advancedCacheDiag.dropInBuild) : 'Unavailable'],
			['Object cache drop-in', !!objectCacheDiag.exists, describeDropIn(objectCacheDiag)],
			['Object-cache generated version', !!objectCacheDiag.dropInBuild, objectCacheDiag.dropInBuild ? ('Build ' + objectCacheDiag.dropInBuild) : 'Unavailable'],
			['Object-cache storage format', !!objectCacheDiag.storageFormat, objectCacheDiag.storageFormat || 'Unavailable'],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : ('Disabled' + (analyticsBackend.message ? ' · ' + analyticsBackend.message : ''))],
			['Embedded runtime config', !!runtimeConfigDiag.exists && !!runtimeConfigDiag.valid && !!runtimeConfigDiag.inSync, runtimeConfigDiag.exists ? (runtimeConfigDiag.inSync ? 'Embedded · In sync' : 'Embedded · Out of sync') : 'Advanced cache missing'],
			['Analytics storage', !!analyticsDiag.exists && !!analyticsDiag.valid, analyticsDiag.exists ? ('DB table · ' + formatNumber(analyticsDiag.rows || 0) + ' rows') : 'Missing DB table'],
			['Browser cache rules', !!browserCacheRulesDiag.exists && !!browserCacheRulesDiag.managed, browserCacheRulesDiag.exists ? (browserCacheRulesDiag.managed ? 'Present · Managed block found' : 'Present · No UltraCache block') : 'Missing'],
			['Object cache directory', !!objectCacheDirDiag.exists, objectCacheDirDiag.exists ? (objectCacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['AVIF optimized media directory', !!avifDirDiag.exists, avifDirDiag.exists ? (avifDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['WebP optimized media directory', !!webpDirDiag.exists, webpDirDiag.exists ? (webpDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
		];
		const storageRows = [
			['Total UltraCache storage', storageWarningLevel === 'ok', formatBytes(storageTotal.bytes || 0) + ' · ' + formatFileCount(storageTotal.files || 0, !!storageTotal.truncated) + (storageTotal.truncated ? ' · capped scan' : '')],
			['HTML/page cache', false, formatBytes(storagePageCache.bytes || 0) + ' · ' + formatFileCount(storagePageCache.files || 0, !!storagePageCache.truncated) + (storagePageCache.truncated ? ' · capped scan' : '')],
			['CSS bundle storage', storageCssBundles.warningLevel === 'ok', formatBytes(storageCssBundles.bytes || 0) + ' · ' + formatFileCount(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || storageCssBundles.files || 0, false)],
			['CSS recent / old files', false, formatNumber(storageCssBundles.recentFiles || 0) + ' / ' + formatNumber(storageCssBundles.oldFiles || 0)],
			['CSS orphan-like files', storageCssBundles.oldOrphanLikeFiles === 0, formatNumber(storageCssBundles.orphanLikeFiles || 0) + ' total · ' + formatNumber(storageCssBundles.oldOrphanLikeFiles || 0) + ' eligible · ' + formatNumber(storageCssBundles.recentOrphanLikeFiles || 0) + ' protected by grace · ' + formatNumber(storageCssBundles.protectedByCachedHtmlRefs || 0) + ' protected by cached HTML'],
			['Cleanup grace / delete limit', false, getCssCleanupGraceLabel(storageCssBundles) + ' · ' + getCssCleanupDeleteLimitLabel(storageCssBundles)],
			['Generated AVIF/WebP storage', false, formatBytes(storageMediaCache.bytes || 0) + ' · ' + formatFileCount(storageMediaCache.files || 0, !!storageMediaCache.truncated) + ' · AVIF ' + formatCount(storageMediaCache.avifFiles || 0, !!storageMediaCache.avifTruncated) + ' / WebP ' + formatCount(storageMediaCache.webpFiles || 0, !!storageMediaCache.webpTruncated)],
		];

		const runtimeConfigRows = runtimeConfigDiag.valid ? [
			['Fresh TTL', false, formatNumber(loadedRuntime.cache_fresh_ttl_minutes || 0) + ' min'],
			['Max stale window', false, formatNumber(loadedRuntime.cache_max_stale_minutes || 0) + ' min'],
			['Stale-while-revalidate', !!loadedRuntime.stale_while_revalidate_enabled, loadedRuntime.stale_while_revalidate_enabled ? 'Enabled' : 'Disabled'],
			['Query allowlist', false, allowlist.length ? (formatNumber(allowlist.length) + ' key' + (allowlist.length === 1 ? '' : 's') + ' · ' + allowlist.join(', ')) : 'None'],
			['Woo safe mode', !!loadedRuntime.woo_safe_mode, loadedRuntime.woo_safe_mode ? 'Enabled' : 'Disabled'],
			['Excluded paths', false, formatNumber((loadedRuntime.excluded_paths || []).length) + ' path' + (((loadedRuntime.excluded_paths || []).length === 1) ? '' : 's')],
			['Excluded query-string args', false, formatNumber((loadedRuntime.excluded_query_args || []).length) + ' arg' + (((loadedRuntime.excluded_query_args || []).length === 1) ? '' : 's')],
			['Sync state', !!runtimeConfigDiag.inSync, runtimeConfigDiag.inSync ? 'In sync with dashboard settings' : 'Out of sync with dashboard settings'],
		] : [];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: row[3] || tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'diagnostics', open: !!open }, [
				h('summary', { className: 'uc-accordion__summary', onClick: function(event) { event.preventDefault(); if (onToggle) { onToggle(); } } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Diagnostics", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Live cache status", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'status-group' }, [
				h('div', { className: 'uc-section-title' }, __("Runtime status", 'ultracache')),
				renderRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'runtime-group' }, [
				h('div', { className: 'uc-section-title' }, __("Runtime files, drop-ins & versions", 'ultracache')),
				renderRows(runtimeRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'cache-storage-summary-group' }, [
				h('div', { className: 'uc-section-title' }, __("Cache storage diagnostics", 'ultracache')),
				renderRows(storageRows, 'plain'),
				h('button', {
					className: 'uc-btn mt-3 text-white py-2 px-3 font-bold',
					onClick: onRefreshStorageDiagnostics,
					disabled: busy || !onRefreshStorageDiagnostics,
				}, busy ? 'Engine Busy' : 'Refresh storage diagnostics'),
				cacheStorageDiag.message ? h('div', { className: 'mt-2 text-xs text-zinc-500' }, cacheStorageDiag.message) : null,
				storageWarnings.length ? h('div', { className: 'mt-3 text-xs text-cyan-300 space-y-1' }, storageWarnings.map(function(message, index) {
					return h('div', { key: 'diagnostics-storage-warning-' + index }, message);
				})) : null,
			]),
			runtimeConfigRows.length ? h('div', { className: 'uc-diagnostic-group', key: 'runtime-config-group' }, [
				h('div', { className: 'uc-section-title' }, __("Embedded runtime config in use", 'ultracache')),
				renderRows(runtimeConfigRows),
			]) : null,
				]),
			]),
		]);
	}

	function AdvancedDiagnosticsCard({ diagnostics, stats, onRefreshStorageDiagnostics, busy }) {
		const last = diagnostics.lastEvent || {};
		const objectCacheStatus = diagnostics.objectCache || {};
		const selectedObjectBackend = objectCacheStatus.selectedBackend || 'redis';
		const activeObjectBackend = objectCacheStatus.activeBackend || selectedObjectBackend;
		const fallbackObjectBackend = objectCacheStatus.fallbackBackend || (objectCacheStatus.fallbackActive ? activeObjectBackend : 'apcu');
		const objectRuntimeOnly = activeObjectBackend === 'runtime' || !!objectCacheStatus.activeBackendRuntimeOnly;
		const objectFallbackActive = !!objectCacheStatus.fallbackActive || (selectedObjectBackend && activeObjectBackend && selectedObjectBackend !== activeObjectBackend);
		const selectedObjectBackendText = selectedObjectBackend ? String(selectedObjectBackend).toUpperCase() : 'Unavailable';
		const activeObjectBackendText = objectRuntimeOnly ? 'Runtime only' : (activeObjectBackend ? String(activeObjectBackend).toUpperCase() : 'Unavailable');
		const fallbackObjectBackendText = fallbackObjectBackend ? (String(fallbackObjectBackend).toLowerCase() === 'runtime' ? 'Runtime only' : String(fallbackObjectBackend).toUpperCase()) : 'Unavailable';
		const objectCacheActiveTone = !objectCacheStatus.enabled ? 'neutral' : (!objectCacheStatus.active ? 'warning' : (objectFallbackActive || objectRuntimeOnly ? 'warning' : 'success'));
		const objectCacheFallbackTone = objectFallbackActive ? 'warning' : 'neutral';
		const compressionStatus = diagnostics.compression || {};
		const wpCacheStatus = diagnostics.wpCache || {};
		const pathDiagnostics = diagnostics.paths || {};
		const lastCacheWrite = diagnostics.lastCacheWrite || {};
		const lastStatus = last.status || last.type || '—';
		const lastSeen = formatEventTime(last);
		const statsDisabled = !!(stats && stats.disabled);
		const statsCountText = (value) => statsDisabled ? 'Stats disabled' : formatNumber(value || 0);
		const bucketHits = stats && stats.pageCacheBucketHits && typeof stats.pageCacheBucketHits === 'object' ? stats.pageCacheBucketHits : {};
		const encodingHits = stats && stats.pageCacheEncodingHits && typeof stats.pageCacheEncodingHits === 'object' ? stats.pageCacheEncodingHits : {};
		const reasons = stats && stats.topBypassReasons && typeof stats.topBypassReasons === 'object' ? stats.topBypassReasons : {};
		const reasonEntries = Object.entries(reasons).slice(0, 8);
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const environmentDiag = diagnostics.environment || {};
		const mediaRuntimeDiag = diagnostics.mediaRuntime || {};
		const analyticsBackend = diagnostics.analytics || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const analyticsProbeText = analyticsBackend && analyticsBackend.enabled ? ((analyticsBackend.readWrite === false) ? ' · probe failed' : (analyticsBackend.readWrite ? ' · probe passed' : '')) : '';
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const cacheStorageDiag = diagnostics.cacheStorage || {};
		const storageTotal = cacheStorageDiag.total || {};
		const storagePageCache = cacheStorageDiag.pageCache || {};
		const storageCssBundles = cacheStorageDiag.cssBundles || {};
		const storageObjectDisk = cacheStorageDiag.objectCacheDisk || {};
		const storageMediaCache = cacheStorageDiag.mediaCache || {};
		const storageWarnings = Array.isArray(cacheStorageDiag.warnings) ? cacheStorageDiag.warnings : [];
		const storageWarningLevel = cacheStorageDiag.warningLevel || 'ok';
		const cacheDetailRows = [
			['Original HTML', false, statsCountText(bucketHits.orig)],
			['WebP HTML', false, statsCountText(bucketHits.webp)],
			['AVIF HTML', false, statsCountText(bucketHits.avif)],
			['Identity encoding', false, statsCountText(encodingHits.identity)],
			['Gzip encoding', false, statsCountText(encodingHits.gzip)],
			['Brotli encoding', false, statsCountText(encodingHits.brotli)],
			['Cache writes', false, statsCountText(stats && stats.pageCacheStores)],
			['Write skips', false, statsCountText(stats && stats.pageCacheStoreSkips)],
			['Stale hits', false, statsCountText(stats && stats.pageCacheStaleHits)],
			['Background refreshes', false, statsCountText(stats && stats.pageCacheBackgroundRevalidations)],
			['Selected object cache backend', false, selectedObjectBackendText],
			['Active object cache backend', !!objectCacheStatus.active, objectCacheStatus.active ? (activeObjectBackendText + (objectFallbackActive ? ' fallback' : '')) : (objectCacheStatus.enabled ? 'Drop-in inactive' : 'Disabled'), objectCacheActiveTone],
			['Object cache fallback', !objectFallbackActive, objectFallbackActive ? (fallbackObjectBackendText + ' active') : (fallbackObjectBackendText + ' standby'), objectCacheFallbackTone],
			['Analytics hit backend', !!analyticsBackend.enabled && analyticsBackend.readWrite !== false, analyticsBackend.enabled ? ('Active · ' + (analyticsBackend.activeBackend || 'apcu') + analyticsProbeText) : 'Disabled'],
			['CLS images scanned', false, statsCountText(stats && stats.clsImagesScanned)],
			['CLS dimensions injected', false, statsCountText(stats && stats.clsDimensionsInjected)],
			['CLS skipped', false, statsCountText(stats && stats.clsImagesSkipped)],
			['CLS unresolved', false, statsCountText(stats && stats.clsImagesUnresolved)],
		];

		const environmentRows = [
			['Server Hostname', false, environmentDiag.serverHostname || 'Unavailable'],
			['Origin IP:Port', false, environmentDiag.originIpPort || 'Unavailable'],
			['Server Document Root', false, environmentDiag.serverDocumentRoot || 'Unavailable'],
			['PHP Version', false, environmentDiag.phpVersion || 'Unavailable'],
			['PHP SAPI', false, environmentDiag.phpSapi || 'Unavailable'],
			['PHP Max Script Execute Time', false, environmentDiag.phpMaxExecutionTime ? (String(environmentDiag.phpMaxExecutionTime) + 's') : 'Unavailable'],
			['PHP Memory Limit', false, environmentDiag.phpMemoryLimit || 'Unavailable'],
			['PHP Max Upload Size', false, environmentDiag.phpMaxUploadSize || 'Unavailable'],
			['Max Post Size', false, environmentDiag.phpMaxPostSize || 'Unavailable'],
			['Max Input Vars', false, environmentDiag.phpMaxInputVars || 'Unavailable'],
			['WordPress Memory Limit', false, environmentDiag.wpMemoryLimit || 'Unavailable'],
			['MYSQL Query Cache Size', false, environmentDiag.mysqlQueryCacheSize || 'Unavailable'],
			['MYSQL Maximum Packet Size', false, environmentDiag.mysqlMaxAllowedPacket || 'Unavailable'],
		];

		const storageRows = [
			['Total UltraCache storage', storageWarningLevel === 'ok', formatBytes(storageTotal.bytes || 0) + ' · ' + formatFileCount(storageTotal.files || 0, !!storageTotal.truncated) + (storageTotal.truncated ? ' · capped scan' : '')],
			['HTML/page cache', false, formatBytes(storagePageCache.bytes || 0) + ' · ' + formatFileCount(storagePageCache.files || 0, !!storagePageCache.truncated) + (storagePageCache.truncated ? ' · capped scan' : '')],
			['CSS bundle storage', storageCssBundles.warningLevel === 'ok', formatBytes(storageCssBundles.bytes || 0) + ' · ' + formatFileCount(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || storageCssBundles.files || 0, false)],
			['Disk object cache storage', false, storageObjectDisk.exists ? (formatBytes(storageObjectDisk.bytes || 0) + ' · ' + formatFileCount(storageObjectDisk.files || 0, !!storageObjectDisk.truncated) + (storageObjectDisk.truncated ? ' · capped scan' : '')) : 'Directory not present'],
			['Generated AVIF/WebP storage', false, formatBytes(storageMediaCache.bytes || 0) + ' · ' + formatFileCount(storageMediaCache.files || 0, !!storageMediaCache.truncated) + ' · AVIF ' + formatCount(storageMediaCache.avifFiles || 0, !!storageMediaCache.avifTruncated) + ' / WebP ' + formatCount(storageMediaCache.webpFiles || 0, !!storageMediaCache.webpTruncated)],
		];

		const cssStorageRows = [
			['Recognized / directory CSS files', false, formatNumber(storageCssBundles.recognizedBundleFiles || storageCssBundles.totalFiles || 0) + ' / ' + formatNumber(storageCssBundles.allDirectoryFiles || storageCssBundles.totalFiles || 0)],
			['Main / delayed CSS files', false, formatNumber(storageCssBundles.mainBundleFiles || 0) + ' / ' + formatNumber(storageCssBundles.delayedFontFiles || 0)],
			['Safe / leftover / aggressive / full', false, formatNumber(storageCssBundles.safeFiles || 0) + ' / ' + formatNumber(storageCssBundles.leftoverFiles || 0) + ' / ' + formatNumber(storageCssBundles.aggressiveFiles || 0) + ' / ' + formatNumber(storageCssBundles.fullFiles || 0)],
			['Recent / old CSS files', false, formatNumber(storageCssBundles.recentFiles || 0) + ' / ' + formatNumber(storageCssBundles.oldFiles || 0)],
			['Manifest-active CSS files', false, formatNumber(storageCssBundles.activeManifestFiles || 0)],
			['Orphan-like CSS files', storageCssBundles.oldOrphanLikeFiles === 0, formatNumber(storageCssBundles.orphanLikeFiles || 0) + ' total · ' + formatNumber(storageCssBundles.oldOrphanLikeFiles || 0) + ' eligible · ' + formatNumber(storageCssBundles.recentOrphanLikeFiles || 0) + ' protected by grace · ' + formatNumber(storageCssBundles.protectedByCachedHtmlRefs || 0) + ' protected by cached HTML'],
			['Cached HTML CSS refs', false, formatNumber(storageCssBundles.cachedHtmlRefFiles || 0) + ' refs · ' + formatNumber(storageCssBundles.cachedHtmlRefFilesWithRefs || 0) + '/' + formatNumber(storageCssBundles.cachedHtmlRefFilesScanned || 0) + ' HTML files' + (storageCssBundles.cachedHtmlRefScanTimedOut ? ' · time-capped scan' : (storageCssBundles.cachedHtmlRefScanTruncated ? ' · capped scan' : ''))],
			['Cleanup grace / delete limit', false, getCssCleanupGraceLabel(storageCssBundles) + ' · ' + getCssCleanupDeleteLimitLabel(storageCssBundles)],
			['Cleanup policy source', false, (storageCssBundles.cleanupPolicySource || 'dashboard/filter') + ' · ' + (storageCssBundles.cleanupGraceFilter || 'ultracache_css_bundle_cleanup_grace_seconds') + ' · ' + (storageCssBundles.cleanupDeleteLimitFilter || 'ultracache_css_bundle_cleanup_max_deletes_per_run')],
			['Cleanup bounds', false, 'Grace ' + (storageCssBundles.cleanupGraceMinLabel || formatDurationSeconds(storageCssBundles.cleanupGraceMinSeconds || 3600)) + '–' + (storageCssBundles.cleanupGraceMaxLabel || formatDurationSeconds(storageCssBundles.cleanupGraceMaxSeconds || 604800)) + ' · delete limit ' + formatNumber(storageCssBundles.cleanupDeleteLimitMin || 5) + '–' + formatNumber(storageCssBundles.cleanupDeleteLimitMax || 500)],
		];

		const mediaQueueDiag = mediaRuntimeDiag.queue || {};
		const mediaRuntimeRows = [
			['Preferred image editor', false, mediaRuntimeDiag.preferredEditor || 'Unavailable'],
			['Last image editor class', false, mediaRuntimeDiag.lastImageEditorClass || 'Unavailable'],
			['Imagick AVIF self-test', !!mediaRuntimeDiag.imagickAvif, mediaRuntimeDiag.imagickAvif ? 'Passed' : 'Failed'],
			['Imagick WebP support', !!mediaRuntimeDiag.imagickWebp, mediaRuntimeDiag.imagickWebp ? 'Available' : 'Unavailable'],
			['GD AVIF self-test', !!mediaRuntimeDiag.gdAvif, mediaRuntimeDiag.gdAvif ? 'Passed' : 'Failed'],
			['GD WebP support', !!mediaRuntimeDiag.gdWebp, mediaRuntimeDiag.gdWebp ? 'Available' : 'Unavailable'],
			['Media conversion queue', !!mediaQueueDiag.enabled, mediaQueueDiag.enabled ? (formatNumber(mediaQueueDiag.pending || 0) + ' pending · ' + formatNumber(mediaQueueDiag.done || 0) + ' done · ' + formatNumber(mediaQueueDiag.failed || 0) + ' failed · ' + formatNumber(mediaQueueDiag.alreadyOptimized || mediaQueueDiag.skipped || 0) + ' already optimized' + (mediaQueueDiag.needsRepair ? ' · repair needed' : '')) : 'Unavailable'],
			['Last AVIF encode engine', false, mediaRuntimeDiag.lastAvifEncodeEngine || 'Unavailable'],
			['Last AVIF encode error', false, mediaRuntimeDiag.lastAvifEncodeError || 'None'],
			['Last AVIF encode file', false, mediaRuntimeDiag.lastAvifEncodeFile || 'Unavailable'],
			['Last AVIF encode at', false, mediaRuntimeDiag.lastAvifEncodeAt ? formatLooseTime(mediaRuntimeDiag.lastAvifEncodeAt) : 'Unavailable'],
		];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: row[3] || tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		function renderPathDetails(label, diag, extraRows) {
			if (!diag || !diag.path) {
				return null;
			}
			return h('div', { className: 'rounded bg-black/10 p-4', key: 'path-' + label }, [
				h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, label),
				h('div', { className: 'space-y-3' }, [
					h(DetailRow, { label: __("Path", 'ultracache'), value: diag.path, mono: true }),
					h(DetailRow, { label: __("Exists", 'ultracache'), value: diag.exists ? 'Yes' : 'No' }),
					h(DetailRow, { label: __("Readable", 'ultracache'), value: diag.readable ? 'Yes' : 'No' }),
					h(DetailRow, { label: __("Writable", 'ultracache'), value: diag.writable ? 'Yes' : 'No' }),
					!diag.exists ? h(DetailRow, { label: __("Parent writable", 'ultracache'), value: diag.parentWritable ? 'Yes' : 'No' }) : null,
					diag.managed ? h(DetailRow, { label: __("Managed by UltraCache", 'ultracache'), value: 'Yes' }) : null,
					diag.dropInBuild ? h(DetailRow, { label: __("Generated build", 'ultracache'), value: diag.dropInBuild }) : null,
					diag.storageFormat ? h(DetailRow, { label: __("Storage format", 'ultracache'), value: diag.storageFormat }) : null,
					diag.size ? h(DetailRow, { label: __("Size", 'ultracache'), value: formatBytes(diag.size) }) : null,
					diag.modified ? h(DetailRow, { label: __("Modified", 'ultracache'), value: formatLooseTime(diag.modified) }) : null,
					diag.valid ? h(DetailRow, { label: __("Valid", 'ultracache'), value: 'Yes' }) : null,
					diag.validJson ? h(DetailRow, { label: __("Valid JSON", 'ultracache'), value: 'Yes' }) : null,
					diag.readError ? h(DetailRow, { label: __("Read error", 'ultracache'), value: diag.readError }) : null,
					extraRows || null,
				]),
			]);
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'advanced-diagnostics' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Advanced Diagnostics", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Expanded server, PHP, media, proxy, cache-write, and bypass inspection.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h(SettingsTransparencyPanel, { diagnostics: diagnostics, key: 'advanced-diagnostics-settings-transparency' }),
					h(SecurityCorrectnessPanel, { diagnostics: diagnostics, key: 'advanced-diagnostics-security-correctness' }),
					h('div', { className: 'uc-diagnostic-group', key: 'last-cache-write' }, [
						h('div', { className: 'uc-section-title' }, __("Last page cache write", 'ultracache')),
						(lastCacheWrite && (lastCacheWrite.path || lastCacheWrite.pageFiles)) ? h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: __("Page cache files", 'ultracache'), value: formatNumber(lastCacheWrite.pageFiles || 0) }),
							h(DetailRow, { label: __("Last modified", 'ultracache'), value: formatLooseTime(lastCacheWrite.modified || 0) }),
							h(DetailRow, { label: __("Last file size", 'ultracache'), value: formatBytes(lastCacheWrite.size || 0) }),
							h(DetailRow, { label: __("Last file path", 'ultracache'), value: lastCacheWrite.path || '', mono: true }),
							lastCacheWrite.error ? h(DetailRow, { label: __("Scan error", 'ultracache'), value: lastCacheWrite.error }) : null,
						]) : h('div', { className: 'text-xs text-zinc-500 pt-2' }, __("No page cache files have been detected yet.", 'ultracache')),
					]),
					(analyticsDiag.path || advancedCacheDiag.path || objectCacheDiag.path || cacheDirDiag.path || objectCacheDirDiag.path)
						? h('div', { className: 'uc-diagnostic-group', key: 'path-grid' }, [
							h('div', { className: 'uc-section-title' }, __("Path details", 'ultracache')),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
								renderPathDetails('advanced-cache.php', advancedCacheDiag),
								renderPathDetails('object-cache.php', objectCacheDiag),
								analyticsDiag.table ? renderPathDetails('Analytics DB table', Object.assign({}, analyticsDiag, { path: analyticsDiag.table, readable: analyticsDiag.exists, writable: analyticsDiag.exists }), analyticsDiag.keys && analyticsDiag.keys.length ? h(DetailRow, { label: __("Top keys", 'ultracache'), value: analyticsDiag.keys.join(', ') }) : null) : null,
								renderPathDetails('Cache directory', cacheDirDiag),
								renderPathDetails('Object cache directory', objectCacheDirDiag),
							]),
						])
						: null,
					h('div', { className: 'uc-diagnostic-group', key: 'cache-storage-diagnostics' }, [
						h('div', { className: 'uc-section-title' }, __("Cache storage diagnostics", 'ultracache')),
						renderRows(storageRows, 'plain'),
						h('button', {
							className: 'uc-btn mt-3 text-white py-2 px-3 font-bold',
							onClick: onRefreshStorageDiagnostics,
							disabled: busy || !onRefreshStorageDiagnostics,
						}, busy ? 'Engine Busy' : 'Refresh storage diagnostics'),
						cacheStorageDiag.message ? h('div', { className: 'mt-2 text-xs text-zinc-500' }, cacheStorageDiag.message) : null,
						storageWarnings.length ? h('div', { className: 'mt-3 text-xs text-cyan-300 space-y-1' }, storageWarnings.map(function(message, index) {
							return h('div', { key: 'storage-warning-' + index }, message);
						})) : null,
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'css-bundle-storage-diagnostics' }, [
						h('div', { className: 'uc-section-title' }, __("CSS bundle storage", 'ultracache')),
						renderRows(cssStorageRows, 'plain'),
						storageCssBundles.message ? h('div', { className: storageCssBundles.warningLevel === 'ok' ? 'mt-3 text-xs text-zinc-500' : 'mt-3 text-xs text-cyan-300' }, storageCssBundles.message) : null,
						storageCssBundles.cleanupPolicyMessage ? h('div', { className: 'mt-2 text-xs text-zinc-400' }, storageCssBundles.cleanupPolicyMessage) : null,
						storageCssBundles.recentProtectedMessage ? h('div', { className: 'mt-2 text-xs text-cyan-300' }, storageCssBundles.recentProtectedMessage) : null,
						storageCssBundles.oldEligibleMessage ? h('div', { className: 'mt-2 text-xs text-amber-300' }, storageCssBundles.oldEligibleMessage) : null,
						Array.isArray(storageCssBundles.largestFiles) && storageCssBundles.largestFiles.length ? h('div', { className: 'mt-4 rounded bg-black/10 p-4 space-y-3' }, [
							h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400' }, __("Largest CSS bundle files", 'ultracache')),
							storageCssBundles.largestFiles.map(function(file) {
								return h(DetailRow, { key: file.name, label: file.name, value: formatBytes(file.bytes || 0) + ' · ' + formatLooseTime(file.modified || 0), mono: true });
							}),
						]) : null,
					]),
					reverseProxy.detected ? h('div', { className: 'uc-diagnostic-group', key: 'proxy-box' }, [
						h('div', { className: 'uc-section-title' }, __("Reverse Proxy Details", 'ultracache')),
						h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: __("Provider", 'ultracache'), value: reverseProxy.provider || 'Detected' }),
							h(DetailRow, { label: __("Server", 'ultracache'), value: reverseProxy.server || '' }),
							h(DetailRow, { label: 'Via', value: reverseProxy.via || '' }),
							h(DetailRow, { label: 'X-Cache', value: reverseProxy.x_cache || '' }),
							h(DetailRow, { label: 'X-Cache-Status', value: reverseProxy.x_cache_status || '' }),
							h(DetailRow, { label: 'X-Proxy-Cache', value: reverseProxy.x_proxy_cache || '' }),
							h(DetailRow, { label: 'X-FastCGI-Cache', value: reverseProxy.x_fastcgi_cache || '' }),
							h(DetailRow, { label: 'X-LiteSpeed-Cache', value: reverseProxy.x_litespeed_cache || '' }),
							h(DetailRow, { label: 'X-QC-Cache', value: reverseProxy.x_qc_cache || '' }),
							h(DetailRow, { label: 'CF-Cache-Status', value: reverseProxy.cf_cache_status || '' }),
							h(DetailRow, { label: __("Age", 'ultracache'), value: reverseProxy.age || '' }),
						]),
					]) : null,
					h('div', { className: 'uc-diagnostic-group', key: 'environment-group' }, [
						h('div', { className: 'uc-section-title' }, __("Server & PHP environment", 'ultracache')),
						renderRows(environmentRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'media-runtime-group' }, [
						h('div', { className: 'uc-section-title' }, __("Media runtime diagnostics", 'ultracache')),
						renderRows(mediaRuntimeRows, 'neutral'),
					]),
					h(FontPipelineDiagnosticsPanel, { diagnostics, key: 'font-pipeline-diagnostics-panel' }),
					h('div', { className: 'uc-diagnostic-group', key: 'cache-group' }, [
						h('div', { className: 'uc-section-title' }, __("Cache diagnostics", 'ultracache')),
						renderRows(cacheDetailRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'reasons-group' }, [
						h('div', { className: 'uc-section-title' }, __("Top bypass reasons", 'ultracache')),
						reasonEntries.length
							? renderRows(reasonEntries.map(([reason, count]) => [reason, false, statsDisabled ? 'Stats disabled' : formatNumber(count)]), 'neutral')
							: h('div', { className: 'text-xs text-zinc-500 pt-2' }, statsDisabled ? 'Cache Statistics are OFF, so bypass counters are not collected.' : 'No bypasses recorded yet.'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500 space-y-1', key: 'last' }, [
						h('div', { key: 'status' }, 'Last cache event: ' + lastStatus + (last.reason ? ' · ' + last.reason : '')),
						h('div', { key: 'bucket' }, 'Last bucket: ' + (last.bucket || '—')),
						h('div', { key: 'time' }, 'Last seen: ' + lastSeen),
						compressionStatus.message ? h('div', { key: 'compression-note' }, 'Compression: ' + compressionStatus.message) : null,
						compressionStatus.serverDefault && compressionStatus.serverDefault.message ? h('div', { key: 'compression-server-default-note' }, 'Frontend compression: ' + compressionStatus.serverDefault.message) : null,
						diagnostics && diagnostics.loopbackSsl && diagnostics.loopbackSsl.fallbackUsed && diagnostics.loopbackSsl.message ? h('div', { key: 'loopback-ssl-note' }, 'Loopback SSL: ' + diagnostics.loopbackSsl.message) : null,
						wpCacheStatus.message ? h('div', { key: 'wp-cache-note' }, 'WP_CACHE: ' + wpCacheStatus.message) : null,
						analyticsBackend.message ? h('div', { key: 'analytics-backend-note' }, 'Analytics hits: ' + analyticsBackend.message) : null,
						!objectCacheStatus.available && objectCacheStatus.message ? h('div', { key: 'object-cache-note' }, 'File object cache: ' + objectCacheStatus.message) : null,
						reverseProxy.message ? h('div', { key: 'reverse-proxy-note' }, 'Reverse proxy: ' + reverseProxy.message) : null,
					]),
				]),
			]),
		]);
	}



	function FontPipelineDiagnosticsPanel({ diagnostics }) {
		const fontDiag = diagnostics && diagnostics.fontPipeline ? diagnostics.fontPipeline : {};
		const fontCss = fontDiag.fontCss || {};
		const bundles = fontDiag.cssBundles || {};
		const optimizedCss = fontDiag.optimizedCss || {};
		const google = fontDiag.googleFontsLocal || {};
		const cfg = fontDiag.settings || {};
		const hasMissing = (bundles.missingBundleFiles || 0) > 0 || (bundles.missingDelayedFontFiles || 0) > 0;
		const settingLine = [
			cfg.selfHostedFontCssOptimizationEnabled ? 'Self-hosted CSS ON' : 'Self-hosted CSS OFF',
			cfg.selfHostedFontRuntimeRewriteEnabled ? 'Runtime rewrite ON' : 'Runtime rewrite OFF',
			cfg.delayIconFontsEnabled ? 'Icon delay ON' : 'Icon delay OFF',
			cfg.cssBundlingEnabled ? ('CSS bundle ' + (cfg.cssBundleScope || 'on')) : 'CSS bundle OFF',
		].join(' · ');

		return h('div', { className: 'uc-diagnostic-group', key: 'font-pipeline-diagnostics-lite' }, [
			h('div', { className: 'uc-section-title', key: 'title' }, __("Font Pipeline Diagnostics", 'ultracache')),
			h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'description' }, __("Read-only summary for local font CSS, delayed icon-font CSS, and bundle font metadata.", 'ultracache')),
			h('div', { className: 'space-y-3', key: 'rows' }, [
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'status' }, [
					h('div', { className: 'text-sm text-white' }, __("Status", 'ultracache')),
					h(StatusPill, { ok: !hasMissing, text: hasMissing ? 'Needs attention' : 'OK', tone: hasMissing ? 'warning' : 'success' }),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'settings' }, [
					h('div', { className: 'text-sm text-white' }, __("Settings", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right break-all' }, settingLine),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'font-css' }, [
					h('div', { className: 'text-sm text-white' }, 'font-css'),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(fontCss.files || 0) + ' file(s) · ' + formatBytes(fontCss.bytes || 0) + ' · Delayed: ' + String(fontCss.delayedFiles || 0) + ' · ' + formatBytes(fontCss.delayedBytes || 0)),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'bundle-fonts' }, [
					h('div', { className: 'text-sm text-white' }, __("Bundle font metadata", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(bundles.entriesWithDelayedFonts || 0) + '/' + String(bundles.manifestEntries || 0) + ' delayed entries · Font-face blocks: ' + String(bundles.delayedFontFaceBlocks || 0)),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'google-imports' }, [
					h('div', { className: 'text-sm text-white' }, __("Google @import rewrites", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(optimizedCss.localGoogleImportRules || 0) + ' local · ' + String(optimizedCss.remoteGoogleImportRules || 0) + ' remote · ' + String(optimizedCss.filesWithGoogleImportRules || 0) + ' file(s)'),
				]),
				h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'google-local' }, [
					h('div', { className: 'text-sm text-white' }, __("Local Google Fonts", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-300 text-right' }, String(google.cssFiles || 0) + ' CSS · ' + String(google.fontFilesOrAssets || 0) + ' asset(s) · ' + formatBytes((google.cssBytes || 0) + (google.fontBytesOrAssetBytes || 0))),
				]),
			]),
			hasMissing ? h('div', { className: 'text-xs text-amber-300 pt-1', key: 'missing' }, 'Missing bundle refs detected: main=' + String(bundles.missingBundleFiles || 0) + ', delayed-font=' + String(bundles.missingDelayedFontFiles || 0) + '.') : null,
			bundles.delayedFontFamilies && bundles.delayedFontFamilies.length ? h('div', { className: 'mt-2 text-xs text-zinc-500 break-all', key: 'families' }, bundles.delayedFontFamilies.join(' · ')) : null,
		]);
	}


	function LcpDiagnosticsCard({ settings, busy, onSettingChange, onQuery, onDetail, queryBusy, onAction, busyKey }) {
		const [open, setOpen] = useState(false);

		return h('div', { className: 'uc-card', style: { gridColumn: '1 / -1' } }, [
			h('details', {
				className: 'uc-accordion uc-accordion--card',
				open,
				onToggle: (event) => setOpen(!!event.currentTarget.open),
				key: 'lcp-diagnostics',
			}, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __('LCP Diagnostics & Settings', 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __('Configure frontend discovery and load discovered URLs only when this section is opened.', 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				open ? h('div', { className: 'uc-accordion__body' }, [
					h(LcpObservationsPanel, {
						settings,
						busy,
						onSettingChange,
						onQuery,
						onDetail,
						queryBusy,
						onAction,
						busyKey,
						key: 'lcp-observations-panel',
					}),
				]) : null,
			]),
		]);
	}


	function LcpObservationsPanel({ settings, busy, onSettingChange, onQuery, onDetail, queryBusy, onAction, busyKey }) {
		const [listData, setListData] = useState(null);
		const [detailDataByHash, setDetailDataByHash] = useState({});
		const [openDetails, setOpenDetails] = useState({});
		const [detailLoadingByHash, setDetailLoadingByHash] = useState({});
		const [detailErrorByHash, setDetailErrorByHash] = useState({});
		const [searchText, setSearchText] = useState('');
		const [appliedSearch, setAppliedSearch] = useState('');
		const [currentCursor, setCurrentCursor] = useState('');
		const [cursorStack, setCursorStack] = useState([]);
		const [confirmation, setConfirmation] = useState(null);
		const [loadError, setLoadError] = useState('');

		const summary = listData && listData.summary ? listData.summary : {};
		const urls = listData && Array.isArray(listData.urls) ? listData.urls : [];
		const pagination = listData && listData.pagination ? listData.pagination : {};
		const discoveryEnabled = !!(settings && settings.lcpFrontendDiscoveryEnabled);
		const discoveryAdminsOnly = !!(settings && settings.lcpFrontendDiscoveryAdminsOnly);
		const discoveryDuration = settings && settings.lcpFrontendDiscoveryDuration ? settings.lcpFrontendDiscoveryDuration : 'indefinitely';
		const discoveryExpiresAt = Number(settings && settings.lcpFrontendDiscoveryExpiresAt ? settings.lcpFrontendDiscoveryExpiresAt : 0);
		const nowSeconds = Math.floor(Date.now() / 1000);
		let discoveryStatus = __('Disabled', 'ultracache');
		if (discoveryEnabled) {
			if (discoveryExpiresAt > 0 && discoveryExpiresAt <= nowSeconds) {
				discoveryStatus = __('Expired', 'ultracache');
			} else if (discoveryExpiresAt > 0) {
				discoveryStatus = __('Active until', 'ultracache') + ' ' + formatLooseTime(discoveryExpiresAt);
			} else {
				discoveryStatus = __('Active indefinitely', 'ultracache');
			}
		}

		function stat(label, value, tone) {
			return h('div', { className: 'uc-lcp-diagnostics__stat' + (tone ? ' is-' + tone : '') }, [
				h('div', { className: 'uc-lcp-diagnostics__stat-label' }, label),
				h('strong', { className: 'uc-lcp-diagnostics__stat-value' }, formatNumber(value || 0)),
			]);
		}

		function stateLabel(state) {
			if (state === 'locked') return __('Locked', 'ultracache');
			if (state === 'learning') return __('Learning', 'ultracache');
			if (state === 'stale') return __('Stale', 'ultracache');
			return __('Needs attention', 'ultracache');
		}

		function stateTone(state) {
			if (state === 'locked') return 'success';
			if (state === 'stale') return 'neutral';
			return 'warning';
		}

		function statusText(record) {
			const status = String(record && record.warmRefreshStatus ? record.warmRefreshStatus : 'none');
			if (status === 'pending') return __('Refresh pending', 'ultracache');
			if (status === 'done') return __('Refresh complete', 'ultracache');
			if (status === 'error') return __('Refresh failed', 'ultracache');
			return __('No queued refresh', 'ultracache');
		}

		function resetDetails() {
			setDetailDataByHash({});
			setOpenDetails({});
			setDetailLoadingByHash({});
			setDetailErrorByHash({});
			setConfirmation(null);
		}

		async function loadList(overrides) {
			if (typeof onQuery !== 'function' || queryBusy) {
				return null;
			}

			const next = Object.assign({
				search: appliedSearch,
				cursor: currentCursor,
				includeSummary: !listData,
			}, overrides || {});
			setLoadError('');
			const response = await onQuery(next);
			if (!response || !response.lcpObservations) {
				setLoadError(__('The LCP URL list could not be loaded.', 'ultracache'));
				return null;
			}
			setListData((current) => {
				const incoming = response.lcpObservations;
				if ((!incoming.summary || typeof incoming.summary !== 'object') && current && current.summary) {
					return Object.assign({}, incoming, { summary: current.summary });
				}
				return incoming;
			});
			return response.lcpObservations;
		}

		async function loadDetail(pageHash, force) {
			const normalizedHash = String(pageHash || '');
			if (!normalizedHash || typeof onDetail !== 'function' || detailLoadingByHash[normalizedHash]) {
				return null;
			}
			if (!force && detailDataByHash[normalizedHash]) {
				return detailDataByHash[normalizedHash];
			}

			setDetailLoadingByHash((current) => Object.assign({}, current, { [normalizedHash]: true }));
			setDetailErrorByHash((current) => Object.assign({}, current, { [normalizedHash]: '' }));
			const response = await onDetail(normalizedHash);
			if (!response || !response.lcpDetail) {
				setDetailErrorByHash((current) => Object.assign({}, current, { [normalizedHash]: __('The selected LCP URL details could not be loaded.', 'ultracache') }));
				setDetailLoadingByHash((current) => Object.assign({}, current, { [normalizedHash]: false }));
				return null;
			}
			setDetailDataByHash((current) => Object.assign({}, current, { [normalizedHash]: response.lcpDetail }));
			setDetailLoadingByHash((current) => Object.assign({}, current, { [normalizedHash]: false }));
			return response.lcpDetail;
		}

		function toggleDetail(item) {
			const pageHash = String(item && item.pageHash ? item.pageHash : '');
			if (!pageHash) {
				return;
			}
			if (openDetails[pageHash]) {
				setOpenDetails((current) => Object.assign({}, current, { [pageHash]: false }));
				return;
			}
			setOpenDetails((current) => Object.assign({}, current, { [pageHash]: true }));
			if (!detailDataByHash[pageHash]) {
				loadDetail(pageHash, false);
			}
		}

		useEffect(() => {
			loadList({ search: '', cursor: '', includeSummary: true });
		}, []);

		function submitSearch(event) {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			const rawValue = String(searchText || '').trim();
			const value = rawValue === '*' ? '' : rawValue;
			if (rawValue === '*') {
				setSearchText('');
			}
			setAppliedSearch(value);
			setCurrentCursor('');
			setCursorStack([]);
			resetDetails();
			loadList({ search: value, cursor: '', includeSummary: true });
		}

		function clearSearch() {
			setSearchText('');
			setAppliedSearch('');
			setCurrentCursor('');
			setCursorStack([]);
			resetDetails();
			loadList({ search: '', cursor: '', includeSummary: true });
		}

		function goNext() {
			const nextCursor = String(pagination.nextCursor || '');
			if (!nextCursor || queryBusy) {
				return;
			}
			setCursorStack(cursorStack.concat([currentCursor]));
			setCurrentCursor(nextCursor);
			resetDetails();
			loadList({ cursor: nextCursor, includeSummary: false });
		}

		function goPrevious() {
			if (!cursorStack.length || queryBusy) {
				return;
			}
			const nextStack = cursorStack.slice(0, -1);
			const previousCursor = cursorStack[cursorStack.length - 1] || '';
			setCursorStack(nextStack);
			setCurrentCursor(previousCursor);
			resetDetails();
			loadList({ cursor: previousCursor, includeSummary: false });
		}

		function actionKey(record, action) {
			return String(record && record.id ? record.id : 0) + ':' + String(action || '');
		}

		function requestAction(record, action, pageHash, pageUrl) {
			if (!record || !record.id || typeof onAction !== 'function') {
				return;
			}
			if (action === 'forget' || action === 'relearn') {
				setConfirmation({ record, action, pageHash, pageUrl });
				return;
			}
			runAction(record, action, pageHash);
		}

		async function runAction(record, action, pageHash) {
			const response = await onAction(record, action);
			if (!response) {
				return;
			}
			const currentDetail = detailDataByHash[pageHash] || {};
			const currentMappings = Array.isArray(currentDetail.mappings) ? currentDetail.mappings : [];
			await loadList({ search: appliedSearch, cursor: currentCursor, includeSummary: true });
			if (action === 'forget' && currentMappings.length <= 1) {
				setOpenDetails((current) => Object.assign({}, current, { [pageHash]: false }));
				setDetailDataByHash((current) => {
					const next = Object.assign({}, current);
					delete next[pageHash];
					return next;
				});
				return;
			}
			await loadDetail(pageHash, true);
		}

		function confirmAction() {
			if (!confirmation) {
				return;
			}
			const pending = confirmation;
			setConfirmation(null);
			runAction(pending.record, pending.action, pending.pageHash);
		}

		function viewportSummary(item) {
			const viewports = item && item.viewports && typeof item.viewports === 'object' ? item.viewports : {};
			return ['mobile', 'tablet', 'desktop'].map((viewport) => {
				const state = viewports[viewport] && viewports[viewport].state ? viewports[viewport].state : 'not-learned';
				const label = viewport.charAt(0).toUpperCase() + viewport.slice(1);
				const value = state === 'not-learned' ? __('Not learned', 'ultracache') : stateLabel(state);
				return h('span', { className: 'uc-lcp-diagnostics__viewport-state is-' + state, key: viewport }, label + ': ' + value);
			});
		}

		function renderDetailPanel(item) {
			const pageHash = String(item && item.pageHash ? item.pageHash : '');
			const detailData = detailDataByHash[pageHash] || null;
			const detailMappings = detailData && Array.isArray(detailData.mappings) ? detailData.mappings : [];
			const detailLoading = !!detailLoadingByHash[pageHash];
			const detailError = String(detailErrorByHash[pageHash] || '');
			const pageUrl = detailData && detailData.pageUrl ? detailData.pageUrl : (item && item.pageUrl ? item.pageUrl : '');

			return h('div', { className: 'uc-lcp-diagnostics__detail-panel is-inline', key: 'detail-' + pageHash }, [
				h('div', { className: 'uc-lcp-diagnostics__detail-head' }, [
					h('div', null, [
						h('div', { className: 'uc-section-title' }, __('URL details', 'ultracache')),
						h('div', { className: 'uc-lcp-diagnostics__detail-url' }, pageUrl),
					]),
					h('button', { type: 'button', className: 'uc-btn', onClick: () => toggleDetail(item) }, __('Close details', 'ultracache')),
				]),
				detailLoading ? h('div', { className: 'uc-lcp-diagnostics__loading', role: 'status' }, __('Loading details for this URL…', 'ultracache')) : null,
				detailError ? h('div', { className: 'uc-lcp-diagnostics__empty is-error' }, detailError) : null,
				detailData && detailMappings.length ? h('div', { className: 'uc-lcp-diagnostics__detail-mappings' }, detailMappings.map((record) => {
					const rowBusy = !!busyKey && String(busyKey).indexOf(String(record.id) + ':') === 0;
					const learningState = String(record.learningState || 'locked');
					const status = String(record.status || 'unknown');
					const state = status === 'stale' ? 'stale' : (learningState === 'learning' ? 'learning' : 'locked');
					const resource = record.resourceUrl || (record.resourceType === 'text' ? __('Text LCP: no image resource', 'ultracache') : __('No resource URL recorded', 'ultracache'));
					return h('div', { className: 'uc-lcp-diagnostics__record', key: 'detail-' + record.id }, [
						h('div', { className: 'uc-lcp-diagnostics__record-head' }, [
							h('strong', null, String(record.viewport || 'unknown').toUpperCase()),
							h(StatusPill, { ok: state === 'locked', text: stateLabel(state), tone: stateTone(state) }),
						]),
						h('div', { className: 'uc-lcp-diagnostics__meta' }, [
							h(DetailRow, { label: __('Source', 'ultracache'), value: record.observationSource === 'automatic' ? __('Automatic browser LCP', 'ultracache') : __('Manual selector', 'ultracache') }),
							h(DetailRow, { label: __('Selector', 'ultracache'), value: record.selector || '—', mono: true }),
							h(DetailRow, { label: __('Element', 'ultracache'), value: (record.elementTag || 'unknown') + ' · ' + (record.resourceType || 'unknown') }),
							h(DetailRow, { label: __('Resource', 'ultracache'), value: resource, mono: true }),
							h(DetailRow, { label: __('Observations', 'ultracache'), value: formatNumber(record.observationCount || 0) }),
							h(DetailRow, { label: __('Confirmations', 'ultracache'), value: formatNumber(record.confirmationCount || 0) }),
							h(DetailRow, { label: __('Recent candidates', 'ultracache'), value: Array.isArray(record.candidateFingerprints) && record.candidateFingerprints.length ? record.candidateFingerprints.map((value) => String(value).slice(0, 12)).join(' · ') : '—', mono: true }),
							h(DetailRow, { label: __('First seen', 'ultracache'), value: record.firstSeen ? formatLooseTime(record.firstSeen) : '—' }),
							h(DetailRow, { label: __('Last seen', 'ultracache'), value: record.lastSeen ? formatLooseTime(record.lastSeen) : '—' }),
							h(DetailRow, { label: __('Locked at', 'ultracache'), value: record.lockedAt ? formatLooseTime(record.lockedAt) : '—' }),
							h(DetailRow, { label: __('Warm refresh', 'ultracache'), value: statusText(record) + (record.warmRefreshMessage ? ' · ' + record.warmRefreshMessage : '') }),
						]),
						h('div', { className: 'uc-lcp-diagnostics__actions' }, [
							h('a', { className: 'uc-btn', href: pageUrl || '#', target: '_blank', rel: 'noopener noreferrer' }, __('View page', 'ultracache')),
							h('button', { type: 'button', className: 'uc-btn', disabled: rowBusy, onClick: () => requestAction(record, 'refresh', pageHash, pageUrl) }, rowBusy && busyKey === actionKey(record, 'refresh') ? __('Working…', 'ultracache') : __('Refresh cache', 'ultracache')),
							h('button', { type: 'button', className: 'uc-btn', disabled: rowBusy, onClick: () => requestAction(record, 'relearn', pageHash, pageUrl) }, rowBusy && busyKey === actionKey(record, 'relearn') ? __('Working…', 'ultracache') : __('Relearn', 'ultracache')),
							h('button', { type: 'button', className: 'uc-btn uc-btn--danger', disabled: rowBusy, onClick: () => requestAction(record, 'forget', pageHash, pageUrl) }, rowBusy && busyKey === actionKey(record, 'forget') ? __('Working…', 'ultracache') : __('Forget mapping', 'ultracache')),
						]),
					]);
				})) : null,
			]);
		}

		const lastRefresh = summary.lastSuccessfulRefresh && typeof summary.lastSuccessfulRefresh === 'object' ? summary.lastSuccessfulRefresh : {};
		const pageNumber = cursorStack.length + 1;

		return h('div', { className: 'uc-diagnostic-group', key: 'lcp-observation-diagnostics' }, [
			h('div', { className: 'uc-lcp-diagnostics__settings', key: 'settings' }, [
				h('div', { className: 'uc-section-title' }, __('Frontend discovery settings', 'ultracache')),
				h('div', { className: 'uc-lcp-diagnostics__effective-status' }, [
					h('strong', null, __('Effective status', 'ultracache') + ': '),
					h('span', null, discoveryStatus),
				]),
				h(ToggleRow, {
					label: __('Only enable LCP frontend discovery for administrators', 'ultracache'),
					description: __('Limits discovery to logged-in administrators. You will need to visit pages while logged in for UltraCache to learn them.', 'ultracache'),
					checked: discoveryAdminsOnly,
					onChange: (value) => onSettingChange('lcpFrontendDiscoveryAdminsOnly', value),
					disabled: busy || !discoveryEnabled,
					key: 'lcp-diagnostics-admin-only',
				}),
				h(SelectField, {
					label: __('Run LCP frontend discovery for', 'ultracache'),
					description: __('Choose how long discovery remains active. Indefinitely is the default; each page and viewport stops reporting when its result is locked.', 'ultracache'),
					value: discoveryDuration,
					onChange: (value) => onSettingChange('lcpFrontendDiscoveryDuration', value),
					disabled: busy || !discoveryEnabled,
					options: [
						{ value: '1_hour', label: __('1 hour', 'ultracache') },
						{ value: '4_hours', label: __('4 hours', 'ultracache') },
						{ value: '8_hours', label: __('8 hours', 'ultracache') },
						{ value: '1_day', label: __('1 day', 'ultracache') },
						{ value: '3_days', label: __('3 days', 'ultracache') },
						{ value: '1_week', label: __('1 week', 'ultracache') },
						{ value: 'indefinitely', label: __('Indefinitely', 'ultracache') },
					],
					key: 'lcp-diagnostics-duration',
				}),
			]),
			h('div', { className: 'uc-section-title' }, __('Discovered URLs', 'ultracache')),
			h('div', { className: 'text-xs text-zinc-500 mb-3' }, listData && listData.message ? listData.message : __('Opening this section loads only ten URLs. Full mapping data is loaded only after you select a URL.', 'ultracache')),
			listData ? h('div', { className: 'uc-lcp-diagnostics__summary' }, [
				stat(__('Learned pages', 'ultracache'), summary.learnedPages || 0),
				stat(__('Confirmed mappings', 'ultracache'), summary.confirmedMappings || 0),
				stat(__('Learning mappings', 'ultracache'), summary.learningMappings || 0),
				stat(__('Pending refreshes', 'ultracache'), summary.pendingRefreshes || 0, (summary.pendingRefreshes || 0) > 0 ? 'active' : ''),
				stat(__('Failed refreshes', 'ultracache'), summary.failedRefreshes || 0, (summary.failedRefreshes || 0) > 0 ? 'active' : ''),
				stat(__('Stale mappings', 'ultracache'), summary.staleMappings || 0, (summary.staleMappings || 0) > 0 ? 'muted' : ''),
			]) : null,
			lastRefresh.timestamp ? h('div', { className: 'uc-lcp-diagnostics__last-refresh' }, [
				h('strong', null, __('Last successful LCP refresh', 'ultracache') + ': '),
				h('span', null, formatLooseTime(lastRefresh.timestamp)),
				lastRefresh.url ? h('span', { className: 'uc-lcp-diagnostics__last-refresh-url' }, ' · ' + lastRefresh.url) : null,
			]) : null,
			h('form', { className: 'uc-lcp-diagnostics__compact-search', onSubmit: submitSearch }, [
				h('input', {
					type: 'search',
					value: searchText,
					disabled: !!queryBusy,
					placeholder: __('Search discovered URLs', 'ultracache'),
					onChange: (event) => setSearchText(event.target.value),
				}),
				h('button', { type: 'submit', className: 'uc-btn', disabled: !!queryBusy }, queryBusy ? __('Loading…', 'ultracache') : __('Search', 'ultracache')),
				appliedSearch ? h('button', { type: 'button', className: 'uc-btn', disabled: !!queryBusy, onClick: clearSearch }, __('Clear', 'ultracache')) : null,
			]),
			queryBusy && !listData ? h('div', { className: 'uc-lcp-diagnostics__loading', role: 'status' }, __('Loading the first ten discovered URLs…', 'ultracache')) : null,
			loadError ? h('div', { className: 'uc-lcp-diagnostics__empty is-error' }, loadError) : null,
			listData && urls.length ? h('div', { className: 'uc-lcp-diagnostics__url-list' }, urls.map((item) => {
				const pageHash = String(item && item.pageHash ? item.pageHash : '');
				const isOpen = !!openDetails[pageHash];
				return h('div', { className: 'uc-lcp-diagnostics__url-item', key: pageHash }, [
					h('button', {
						type: 'button',
						className: 'uc-lcp-diagnostics__url-row' + (isOpen ? ' is-selected' : ''),
						onClick: () => toggleDetail(item),
						'aria-expanded': isOpen,
					}, [
						h('div', { className: 'uc-lcp-diagnostics__url-main' }, [
							h('div', { className: 'uc-lcp-diagnostics__page' }, item.pageUrl || '—'),
							h('div', { className: 'uc-lcp-diagnostics__url-meta' }, [
								h('span', null, formatNumber(item.mappingCount || 0) + ' ' + __('mapping(s)', 'ultracache')),
								item.lastSeen ? h('span', null, __('Last seen', 'ultracache') + ': ' + formatLooseTime(item.lastSeen)) : null,
							]),
							h('div', { className: 'uc-lcp-diagnostics__viewport-states' }, viewportSummary(item)),
						]),
						h('div', { className: 'uc-lcp-diagnostics__url-side' }, [
							h(StatusPill, { ok: item.state === 'locked', text: stateLabel(item.state), tone: stateTone(item.state) }),
							h('span', { className: 'uc-lcp-diagnostics__url-chevron', 'aria-hidden': 'true' }, '▸'),
						]),
					]),
					isOpen ? renderDetailPanel(item) : null,
				]);
			})) : (listData && !queryBusy ? h('div', { className: 'uc-lcp-diagnostics__empty' }, listData.message || __('No discovered URLs were found.', 'ultracache')) : null),
			listData ? h('div', { className: 'uc-lcp-diagnostics__pagination' }, [
				h('div', { className: 'uc-lcp-diagnostics__range' }, __('Page', 'ultracache') + ' ' + formatNumber(pageNumber) + ' · ' + formatNumber(pagination.returned || urls.length || 0) + ' ' + __('URL(s) loaded', 'ultracache')),
				h('div', { className: 'uc-lcp-diagnostics__pagination-actions' }, [
					h('button', { type: 'button', className: 'uc-btn', disabled: !!queryBusy || !cursorStack.length, onClick: goPrevious }, __('Previous', 'ultracache')),
					h('button', { type: 'button', className: 'uc-btn', disabled: !!queryBusy || !pagination.hasMore || !pagination.nextCursor, onClick: goNext }, __('Next', 'ultracache')),
				]),
			]) : null,
			confirmation ? h('div', { className: 'uc-media-test-modal', role: 'presentation', onClick: () => setConfirmation(null), key: 'lcp-confirmation-modal' }, [
				h('div', { className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-lcp-diagnostics__confirm-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'uc-lcp-confirmation-title', onClick: (event) => event.stopPropagation() }, [
					h('button', { type: 'button', className: 'uc-media-test-modal__close', onClick: () => setConfirmation(null), 'aria-label': __('Close confirmation', 'ultracache') }, '×'),
					h('div', { className: 'uc-support-modal__eyebrow' }, __('LCP observations', 'ultracache')),
					h('h3', { className: 'uc-support-modal__title', id: 'uc-lcp-confirmation-title' }, confirmation.action === 'forget' ? __('Forget this LCP mapping?', 'ultracache') : __('Relearn this LCP mapping?', 'ultracache')),
					h('p', { className: 'uc-support-modal__text' }, confirmation.action === 'forget'
						? __('The stored mapping will be deleted and the affected page cache will be refreshed without it.', 'ultracache')
						: __('The current mapping remains active as a fallback, but its confirmation evidence is cleared. New eligible visits will relearn and lock this viewport again.', 'ultracache')),
					h('div', { className: 'uc-lcp-diagnostics__confirm-url' }, confirmation.pageUrl || ''),
					h('div', { className: 'uc-lcp-diagnostics__confirm-actions' }, [
						h('button', { type: 'button', className: 'uc-btn', onClick: () => setConfirmation(null) }, __('Cancel', 'ultracache')),
						h('button', { type: 'button', className: confirmation.action === 'forget' ? 'uc-btn uc-btn--danger' : 'uc-btn uc-btn--primary', onClick: confirmAction }, confirmation.action === 'forget' ? __('Forget mapping', 'ultracache') : __('Relearn', 'ultracache')),
					]),
				]),
			]) : null,
		]);
	}

	function SettingsTransparencyPanel({ diagnostics }) {
		const diag = diagnostics && diagnostics.settingsTransparency ? diagnostics.settingsTransparency : {};
		const summary = diag.summary || {};
		const visibleLists = Array.isArray(diag.visibleLists) ? diag.visibleLists : [];
		const engineOnly = Array.isArray(diag.engineOnlySafeguards) ? diag.engineOnlySafeguards : [];
		const legacyLists = Array.isArray(diag.legacyLists) ? diag.legacyLists : [];
		const visibleCount = summary.visibleEditableLists || visibleLists.length || 0;
		const defaultCount = summary.listsWithDefaults || visibleLists.filter((item) => item && item.populateDefaultAvailable).length || 0;
		const engineCount = summary.engineOnlySafeguards || engineOnly.length || 0;
		const legacyCount = summary.legacyLists || legacyLists.length || 0;

		function renderMiniStat(label, value, tone) {
			const cls = 'rounded-lg bg-black/25 px-3 py-2';
			return h('div', { className: cls, key: label }, [
				h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, label),
				h('div', { className: tone === 'warning' ? 'font-mono text-amber-300' : 'font-mono text-zinc-200', key: 'value' }, String(value || 0)),
			]);
		}

		function renderVisibleListRow(item, index) {
			const currentCount = item && typeof item.currentCount !== 'undefined' ? item.currentCount : 0;
			const defaultCount = item && typeof item.defaultCount !== 'undefined' ? item.defaultCount : 0;
			const badge = item && item.shared ? 'shared override' : (item && item.populateDefaultAvailable ? 'has defaults' : 'manual/empty default');
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'visible-' + index + '-' + (item && item.key ? item.key : index) }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2', key: 'top' }, [
					h('span', { className: 'text-zinc-200 font-semibold', key: 'label' }, item && item.label ? item.label : 'Visible list'),
					h('span', { className: item && item.populateDefaultAvailable ? 'text-emerald-300 font-mono text-[11px]' : 'text-zinc-500 font-mono text-[11px]', key: 'badge' }, badge),
				]),
				h('div', { className: 'text-[11px] text-zinc-500 mt-1 font-mono break-all', key: 'meta' }, [
					String(item && item.key ? item.key : ''),
					' · ',
					String(item && item.area ? item.area : 'General'),
					__(" · current ", 'ultracache'),
					String(currentCount),
					__(" · default ", 'ultracache'),
					String(defaultCount),
				]),
			]);
		}

		function renderEngineSafeguard(item, index) {
			const examples = Array.isArray(item && item.examples) ? item.examples : [];
			return h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'engine-' + index }, [
				h('div', { className: 'flex flex-wrap items-center justify-between gap-2', key: 'top' }, [
					h('span', { className: 'text-zinc-200 font-semibold', key: 'label' }, item && item.label ? item.label : 'Engine-only safeguard'),
					h('span', { className: 'text-sky-300 font-mono text-[11px]', key: 'badge' }, 'engine-only'),
				]),
				item && item.reason ? h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'reason' }, item.reason) : null,
				examples.length ? h('div', { className: 'mt-2 flex flex-wrap gap-1', key: 'examples' }, examples.map((example, exampleIndex) => h('code', { className: 'font-mono text-[11px] text-sky-300 bg-black/25 rounded px-2 py-1', key: 'example-' + exampleIndex }, String(example)))) : null,
			]);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' }, key: 'settings-transparency-panel' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'details' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Settings Transparency", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Read-only map of visible safeguard lists, engine-only safety floors, and reset/default coverage.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'message' }, diag.message || 'User-editable safeguards are listed separately from engine-only safety floors.'),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3', key: 'stats' }, [
						renderMiniStat('Visible editable lists', visibleCount),
						renderMiniStat('Lists with defaults', defaultCount),
						renderMiniStat('Engine-only floors', engineCount, engineCount ? 'warning' : ''),
						renderMiniStat('Legacy mapped lists', legacyCount),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500', key: 'reset' }, summary.resetUsesDashboardDefaults ? 'Reset Settings uses the dashboard defaults payload, so recommended exclusion defaults are restored during full reset.' : 'Reset defaults status is unavailable.'),
					h('div', { className: 'mt-4', key: 'visible-section' }, [
						h('div', { className: 'uc-section-title' }, __("Visible / editable safeguard lists", 'ultracache')),
						visibleLists.length ? h('div', { className: 'space-y-2 mt-2' }, visibleLists.map(renderVisibleListRow)) : h('div', { className: 'text-xs text-zinc-500' }, __("No visible list diagnostics were reported.", 'ultracache')),
					]),
					h('div', { className: 'mt-4', key: 'engine-section' }, [
						h('div', { className: 'uc-section-title' }, __("Engine-only safety floors", 'ultracache')),
						engineOnly.length ? h('div', { className: 'space-y-2 mt-2' }, engineOnly.map(renderEngineSafeguard)) : h('div', { className: 'text-xs text-zinc-500' }, __("No engine-only safeguards were reported.", 'ultracache')),
					]),
					legacyLists.length ? h('div', { className: 'mt-4', key: 'legacy-section' }, [
						h('div', { className: 'uc-section-title' }, __("Legacy mapped lists", 'ultracache')),
						h('div', { className: 'space-y-2 mt-2' }, legacyLists.map((item, index) => h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'legacy-' + index }, [
							h('div', { className: 'text-zinc-200 font-semibold', key: 'label' }, item.label || item.key || 'Legacy list'),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1', key: 'message' }, item.message || ''),
							h('div', { className: 'text-[11px] text-zinc-500 mt-1 font-mono', key: 'map' }, String(item.key || '') + ' → ' + String(item.mappedTo || '')),
						]))),
					]) : null,
				]),
			]),
		]);
	}


	function SecurityCorrectnessPanel({ diagnostics }) {
		const diag = diagnostics && diagnostics.securityCorrectness ? diagnostics.securityCorrectness : {};
		const summary = diag.summary || {};
		const engineOnly = Array.isArray(diag.engineOnlySafeguards) ? diag.engineOnlySafeguards : [];
		const missing = Array.isArray(diag.hardSensitiveQueryArgsMissingFromVisibleList) ? diag.hardSensitiveQueryArgsMissingFromVisibleList : [];
		const runtime = diag.runtimeConfigProtection || {};
		const ok = !!summary.debugContextRedactionEnabled && !!summary.secretsRedactedFromClientSettings;

		function stat(label, value, tone) {
			return h('div', { className: 'rounded-lg bg-black/25 px-3 py-2', key: label }, [
				h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]', key: 'label' }, label),
				h('div', { className: tone === 'warning' ? 'font-mono text-amber-300' : 'font-mono text-zinc-200', key: 'value' }, String(value)),
			]);
		}

		return h('div', { className: 'uc-field-wrap', style: { gridColumn: '1 / -1' }, key: 'security-correctness-panel' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'details' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Security / Cache Correctness", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Read-only audit of cache-poisoning safeguards, secret redaction, and embedded runtime configuration.", 'ultracache')),
					]),
					h('span', { className: ok ? 'text-emerald-300 font-mono text-[11px]' : 'text-amber-300 font-mono text-[11px]', key: 'status' }, ok ? 'Guarded' : 'Review'),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'text-xs text-zinc-500 mb-3', key: 'message' }, diag.message || 'Security diagnostics are read-only.'),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3', key: 'stats' }, [
						stat('Hard query args', summary.hardSensitiveQueryArgs || 0),
						stat('Visible list missing', summary.hardSensitiveQueryArgsMissingFromVisibleList || 0, missing.length ? 'warning' : ''),
						stat('Woo safe mode', summary.woocommerceSafeModeEnabled ? 'ON' : 'OFF', summary.woocommerceSafeModeEnabled ? '' : 'warning'),
						stat('Secret redaction', summary.debugContextRedactionEnabled ? 'ON' : 'OFF', summary.debugContextRedactionEnabled ? '' : 'warning'),
					]),
					missing.length ? h('div', { className: 'mt-3 rounded-lg bg-amber-500/10 text-amber-200 text-xs px-3 py-2', key: 'missing' }, 'These sensitive query args are enforced by the engine but are not in the visible exclusion textarea: ' + missing.join(', ')) : null,
					h('div', { className: 'mt-4', key: 'engine' }, [
						h('div', { className: 'uc-section-title' }, __("Engine safety floors", 'ultracache')),
						engineOnly.length ? h('div', { className: 'space-y-2 mt-2' }, engineOnly.map((item, index) => h('div', { className: 'flex items-center justify-between gap-3 rounded-lg bg-black/20 px-3 py-2', key: 'guard-' + index }, [
							h('span', { className: 'text-sm text-zinc-200' }, item.label || 'Safety floor'),
							h('span', { className: 'font-mono text-[11px] text-sky-300' }, item.status || 'reported'),
						]))) : h('div', { className: 'text-xs text-zinc-500' }, __("No security safeguards were reported.", 'ultracache')),
					]),
					h('div', { className: 'mt-4 grid grid-cols-1 md:grid-cols-2 gap-3', key: 'files' }, [
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'runtime' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Embedded runtime config", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'advanced-cache.php: ' + (runtime.advancedCacheExists ? 'present' : 'missing')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'Configuration: ' + (runtime.configInSync ? 'embedded and in sync' : 'review required')),
						]),
						h('div', { className: 'rounded-lg bg-black/20 px-3 py-2', key: 'secrets' }, [
							h('div', { className: 'text-zinc-500 uppercase tracking-wider text-[10px]' }, __("Secret configuration", 'ultracache')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'Redis: ' + (summary.redisSecretConfigured ? 'WP_REDIS_PASSWORD configured' : 'not configured')),
							h('div', { className: 'text-xs text-zinc-300 mt-1' }, 'Varnish: ' + (summary.varnishSecretConfigured ? 'ULTRACACHE_VARNISH_PASSWORD configured' : 'not configured')),
						]),
					]),
				]),
			]),
		]);
	}

		return {
			DiagnosticsCard,
			AdvancedDiagnosticsCard,
			LcpDiagnosticsCard,
			FontPipelineDiagnosticsPanel,
			SettingsTransparencyPanel,
			SecurityCorrectnessPanel,
		};
	}

	admin.define('dashboardDiagnosticsUi', { createDashboardDiagnosticsUi });
})(window);
