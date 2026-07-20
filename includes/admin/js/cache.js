/* UltraCache Admin - Cache backends, external layers, and purge controls */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before cache.js.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	const help = admin.get('help');
	const ui = admin.get('ui');
	const cacheShared = admin.get('cacheShared');
	const varnishModule = admin.get('varnish');
	if (!core || !api || !help || !ui || !cacheShared || !varnishModule) {
		throw new Error('UltraCache admin core/api/help/ui/cache-shared/varnish modules are required before cache.js.');
	}

	const { h, __, classNames, formatNumber, formatPercent, formatLooseTime, formatBytes } = core;
	const { apiRequest } = api;
	const { renderLabelWithHelp, getOptionHelpText } = help;
	const {
		Card,
		Button,
		ToggleField,
		ToggleRow,
		TextField,
		TextRow,
		NumberRow,
		SelectField,
		StatusPill,
	} = ui;
	const { CacheHelperConflictNotice } = cacheShared;
	const {
		getDefaultVarnishServersForMode,
		isDefaultVarnishServersValue,
		formatVarnishResultDetailLines,
		formatVarnishResultMessage,
		VarnishCard,
		createController: createVarnishController,
	} = varnishModule;



	function OPcacheCard({ stats, busy, onFlush }) {
		const opcache = stats && stats.opcache ? stats.opcache : {};
		const isAvailable = !!opcache.available;
		const isEnabled = !!opcache.enabled;
		if (!isAvailable || !isEnabled) {
			return null;
		}
		const statusText = !isAvailable ? 'Unavailable' : (isEnabled ? 'Enabled' : 'Disabled');
		const rows = [
			['Status', statusText],
			['Used memory', opcache.memoryUsedHuman || '—'],
			['Free memory', opcache.memoryFreeHuman || '—'],
			['Wasted memory', opcache.memoryWastedHuman || '—'],
			['Interned strings used', opcache.internedUsedHuman || '—'],
			['Interned strings free', opcache.internedFreeHuman || '—'],
			['Cached scripts', typeof opcache.cachedScripts !== 'undefined' ? formatNumber(opcache.cachedScripts) : '—'],
			['Cached keys', typeof opcache.cachedKeys !== 'undefined' ? (formatNumber(opcache.cachedKeys) + (opcache.maxCachedKeys ? ' / ' + formatNumber(opcache.maxCachedKeys) : '')) : '—'],
			['Hit rate', typeof opcache.hitRate !== 'undefined' ? formatPercent(opcache.hitRate) : '—'],
			['Last restart', opcache.lastRestartTimeHuman || 'Never'],
			['Last flush', opcache.lastFlushTimeHuman || 'Never'],
		];

		return h(Card, {
			title: 'OPcache',
			description: __("Production-safe visibility into PHP OPcache memory usage, hit rate, and restart state, with a manual flush control for post-deployment opcode invalidation when application code changes.", 'ultracache'),
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush OPcache'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				(opcache.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, opcache.message) : null),
			]),
		]);
	}

	function APCuCard({ stats, settings, busy, onFlush, onToggleScheduledCleanup }) {
		const apcu = stats && stats.apcu ? stats.apcu : {};
		const isAvailable = !!apcu.available;
		const isEnabled = !!apcu.enabled;
		if (!isAvailable || !isEnabled) {
			return null;
		}
		const statusText = !isAvailable ? 'Unavailable' : (isEnabled ? 'Enabled' : 'Disabled');
		const objectCacheStatus = stats && stats.objectCache ? stats.objectCache : {};
		const diagnosticsObjectCacheStatus = stats && stats.diagnostics && stats.diagnostics.objectCache ? stats.diagnostics.objectCache : {};
		const normalizeBackendValue = (value) => {
			value = String(value || '').toLowerCase().trim();
			return ['redis', 'apcu', 'sqlite', 'disk', 'runtime'].indexOf(value) !== -1 ? value : '';
		};
		const firstBackendValue = (values) => {
			for (let i = 0; i < values.length; i++) {
				const normalized = normalizeBackendValue(values[i]);
				if (normalized) {
					return normalized;
				}
			}
			return '';
		};
		const activeObjectBackend = firstBackendValue([
			objectCacheStatus.activeBackend,
			diagnosticsObjectCacheStatus.activeBackend,
			stats && stats.objectCacheActiveBackend,
			stats && stats.objectCacheStatsSource,
			objectCacheStatus.activeFallbackBackend,
			diagnosticsObjectCacheStatus.activeFallbackBackend,
			stats && stats.objectCacheBackend,
		]);
		const fallbackObjectBackend = firstBackendValue([
			objectCacheStatus.activeFallbackBackend,
			diagnosticsObjectCacheStatus.activeFallbackBackend,
			objectCacheStatus.fallbackBackend,
			diagnosticsObjectCacheStatus.fallbackBackend,
		]);
		const selectedObjectBackend = firstBackendValue([
			objectCacheStatus.selectedBackend,
			diagnosticsObjectCacheStatus.selectedBackend,
			stats && stats.objectCacheBackend,
			settings && settings.objectCacheBackend,
		]);
		const fallbackActive = !!(
			objectCacheStatus.fallbackActive ||
			diagnosticsObjectCacheStatus.fallbackActive ||
			(stats && stats.objectCacheFallbackActive)
		);
		const apcuIsActiveObjectBackend = activeObjectBackend === 'apcu' || (fallbackActive && fallbackObjectBackend === 'apcu') || (!activeObjectBackend && selectedObjectBackend === 'apcu' && settings && settings.objectCacheEnabled);
		const memoryUsage = typeof apcu.memoryUsagePercent !== 'undefined' ? Number(apcu.memoryUsagePercent) : 0;
		const apcuWarnings = [];
		if (memoryUsage >= 85) {
			apcuWarnings.push(apcuIsActiveObjectBackend
				? 'APCu memory usage is above 85%. Consider increasing apc.shm_size if object-cache hit rate drops or expunges increase.'
				: 'APCu memory usage is above 85%. APCu is not the active object-cache backend right now, so treat this as an analytics/shared-memory advisory.');
		}
		if (Number(apcu.expunges || 0) > 0) {
			apcuWarnings.push(apcuIsActiveObjectBackend
				? 'APCu has expunges. This means entries were cleared to make room for new object-cache writes.'
				: 'APCu has expunges. Since APCu is not the active object-cache backend, this may affect analytics/shared-memory entries rather than persistent object caching.');
		}
		const usageText = typeof apcu.memoryUsagePercent !== 'undefined' ? formatPercent(apcu.memoryUsagePercent) : '—';
		const rows = [
			['Status', statusText],
			['Used memory', apcu.memoryUsedHuman || '—'],
			['Free memory', apcu.memoryFreeHuman || '—'],
			['Total memory', apcu.memoryTotalHuman || '—'],
			['Memory usage', usageText],
			['Cached entries', typeof apcu.cachedEntries !== 'undefined' ? formatNumber(apcu.cachedEntries) : '—'],
			['Hit rate', typeof apcu.hitRate !== 'undefined' ? formatPercent(apcu.hitRate) : '—'],
			['Hits / Misses', (typeof apcu.hits !== 'undefined' ? formatNumber(apcu.hits) : '—') + ' / ' + (typeof apcu.misses !== 'undefined' ? formatNumber(apcu.misses) : '—')],
			['Inserts', typeof apcu.inserts !== 'undefined' ? formatNumber(apcu.inserts) : '—'],
			['Expunges', typeof apcu.expunges !== 'undefined' ? formatNumber(apcu.expunges) : '—'],
			['Started', apcu.startTimeHuman || '—'],
		];

		return h(Card, {
			title: 'APCu',
			description: __("Local shared-memory cache used for lightweight hit analytics and as the safe local object-cache fallback when Redis is unavailable.", 'ultracache'),
		}, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !isAvailable || !isEnabled, variant: 'primary' }, busy ? 'Working…' : 'Flush APCu Cache'),
			]),
			h('div', { className: 'mt-4' }, [
				h(ToggleField, {
					label: __("Include APCu Flush on Scheduled Cache Cleanup", 'ultracache'),
					description: __("Warning: this clears the whole APCu user cache for this PHP runtime, including entries used by other plugins/apps in the same PHP-FPM context.", 'ultracache'),
					checked: !!(settings && settings.apcuFlushOnScheduledCleanup),
					onChange: onToggleScheduledCleanup,
					disabled: busy,
				}),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				(apcu.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, apcu.message) : null),
				apcuWarnings.length ? h('div', { className: 'space-y-2 mt-3' }, apcuWarnings.map((warning, index) => h('div', { className: 'text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2', key: 'apcu-warning-' + index }, warning))) : null,
			]),
		]);
	}


	function getExternalCacheLayer(stats, key) {
		const detection = stats && stats.externalCaches ? stats.externalCaches : {};
		const layers = detection && detection.layers ? detection.layers : {};
		return layers && layers[key] ? layers[key] : {};
	}

	function ExternalCacheCard({ title, description, layer, busy, onFlush }) {
		layer = layer || {};
		if (!layer.detected || !layer.flushable) {
			return null;
		}
		const rows = [
			['Status', layer.enabled ? 'Detected' : 'Detected'],
			['Flushable', layer.flushable ? 'Yes' : 'No'],
			['Method', layer.method || '—'],
		];
		return h(Card, { title: title, description: description }, [
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onFlush, disabled: busy || !layer.flushable, variant: 'primary' }, busy ? 'Working…' : ('Flush ' + title)),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Detection", 'ultracache')),
				h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: row[0] }, [
					h('div', { className: 'text-sm text-white' }, row[0]),
					h('div', { className: 'text-sm text-zinc-300 text-right break-all' }, row[1]),
				]))),
				layer.message ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, layer.message) : null,
			]),
		]);
	}

	function ExternalCacheFlushSettingsCard({ stats, diagnostics, settings, busy, canManageInfrastructure, onRedetect, onToggle }) {
		const detection = stats && stats.externalCaches ? stats.externalCaches : {};
		const layers = detection && detection.layers ? detection.layers : {};
		const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : {};
		const varnishDiagnostic = diagnostics && diagnostics.varnish ? diagnostics.varnish : {};
		const varnishLayer = layers && layers.varnish ? layers.varnish : {};
		const reverseProxyTextForVarnish = [
			reverseProxy.provider,
			reverseProxy.server,
			reverseProxy.via,
			reverseProxy.x_cache,
			reverseProxy.x_cache_status,
			reverseProxy.message,
		].join(' ').toLowerCase();
		const reverseProxyLooksLikeVarnish = !!(reverseProxy && reverseProxy.detected && reverseProxyTextForVarnish.indexOf('varnish') !== -1);
		const varnishConfigured = !!(
			(settings && (settings.varnishCliEnabled || settings.varnishCliServers || settings.flushAllIncludeVarnish)) ||
			(varnishDiagnostic && (varnishDiagnostic.enabled || varnishDiagnostic.available || varnishDiagnostic.servers || varnishDiagnostic.endpointCount || varnishDiagnostic.secretConfigured))
		);
		const showVarnishCandidate = !!((varnishLayer && varnishLayer.detected) || reverseProxyLooksLikeVarnish || varnishConfigured);
		const candidates = [
			{ key: 'opcache', setting: 'flushAllIncludeOpcache', label: 'OPcache', description: __("Also reset PHP OPcache when Flush All Cache runs.", 'ultracache') },
			{ key: 'apcu', setting: 'flushAllIncludeApcu', label: 'APCu', description: __("Also clear the APCu user cache when Flush All Cache runs. This is always on if APCu cache is used.", 'ultracache') },
			{ key: 'litespeed', setting: 'flushAllIncludeLiteSpeed', label: __("LiteSpeed Cache", 'ultracache'), description: __("Also purge LiteSpeed Cache when Flush All Cache runs. Uses the LiteSpeed plugin API when present, otherwise the server-level X-LiteSpeed-Purge response header.", 'ultracache') },
			{ key: 'nginx', setting: 'flushAllIncludeNginx', label: __("Nginx Cache", 'ultracache'), description: __("Also trigger the detected Nginx Helper purge hook when Flush All Cache runs.", 'ultracache') },
			{ key: 'varnish', setting: 'flushAllIncludeVarnish', label: __("Varnish Cache", 'ultracache'), description: __("Also flush the configured UltraCache Varnish endpoint when Flush All Cache runs.", 'ultracache') },
		];
		const visible = candidates.filter((item) => {
			const layer = layers[item.key] || {};
			if (item.key === 'varnish') {
				return showVarnishCandidate;
			}
			return !!(layer.detected && layer.flushable);
		});
		const renderCandidate = (item) => {
			const layer = layers[item.key] || {};
			const isVarnish = item.key === 'varnish';
			const isApcu = item.key === 'apcu';
			const apcuObjectCacheSelected = !!(settings && String(settings.objectCacheBackend || '').toLowerCase() === 'apcu');
			const flushable = !!layer.flushable;
			const disabled = !!busy || (isVarnish && (!flushable || !canManageInfrastructure)) || (isApcu && apcuObjectCacheSelected);
			let description = item.description;
			if (isVarnish && !canManageInfrastructure) {
				description = __("Changing Varnish Flush All behavior requires plugin activation or network plugin management permission.", 'ultracache');
			} else if (isVarnish && !flushable) {
				description = 'Varnish is detected or configured, but UltraCache Varnish purge is currently disabled or not flushable. Enable and test the Varnish purge integration before including it in Flush All Cache.';
			}
			return h(ToggleRow, {
				label: 'Also flush ' + item.label,
				description: description,
				checked: !!(isApcu && apcuObjectCacheSelected) || !!(settings && settings[item.setting]),
				onChange: (value) => onToggle(item.setting, isApcu && apcuObjectCacheSelected ? true : value),
				disabled: disabled,
				key: item.setting,
			});
		};
		return h(Card, {
			title: __("External Cache Flush", 'ultracache'),
			description: __("Also empty detected external/server cache layers with each Flush All Cache. Detected or configured Varnish is shown even when purge integration still needs to be enabled/tested.", 'ultracache'),
		}, [
			h('div', { className: 'flex flex-wrap items-center justify-between gap-3 mt-2' }, [
				h('div', { className: 'text-xs text-zinc-500', key: 'detected-at' }, detection.detectedAtHuman ? ('Last detected: ' + detection.detectedAtHuman) : 'No cache detection result saved yet.'),
				h(Button, { onClick: onRedetect, disabled: busy, variant: 'light', key: 'redetect' }, busy ? 'Working…' : 'Redetect Caches'),
			]),
			visible.length ? h('div', { className: 'mt-4 divide-y divide-white/5' }, visible.map(renderCandidate)) : h('div', { className: 'text-xs text-zinc-500 mt-4' }, __("No external/server cache layer with a safe flush mechanism is detected. Use Redetect Caches after enabling OPcache/APCu, Varnish settings, Nginx Helper, or after confirming a LiteSpeed/OpenLiteSpeed cache layer.", 'ultracache')),
		]);
	}

	function RedisCard({ form, diagnostics, busy, canManageInfrastructure, objectCacheEnabled, onObjectCacheEnabledChange, onFieldChange, onSave, onTest, onFlush, onRemoveConflictingDropins, onRecheckConflicts }) {
		const objectCache = diagnostics.objectCache || {};
		const infrastructureLocked = !canManageInfrastructure;
		const redis = objectCache.redis || {};
		const secretManaged = !!form.redisPasswordManaged;
		const secretExternal = !!form.redisPasswordExternal;
		const legacyConflicts = diagnostics.legacyCacheConflicts || {};
		const normalizeBackendChoice = (value) => {
			value = String(value || '').toLowerCase();
			return ['redis', 'apcu', 'sqlite', 'disk'].indexOf(value) !== -1 ? value : 'redis';
		};
		const backend = normalizeBackendChoice(form.objectCacheBackend || objectCache.selectedBackend || 'redis');
		const fallbackPolicy = form.objectCacheFallbackBackend || objectCache.configuredFallbackBackend || 'apcu';
		const selectedBackend = backend;
		const activeBackend = objectCache.activeBackend || selectedBackend;
		const fallbackActive = !!objectCache.fallbackActive;
		const fallbackBackend = objectCache.fallbackBackend || (fallbackActive ? activeBackend : ('none' === fallbackPolicy ? 'runtime' : fallbackPolicy));
		const apcu = objectCache.apcu || {};
		const sqlite = objectCache.sqlite || {};
		const backendLabel = (value) => value === 'redis' ? 'Redis' : (value === 'apcu' ? 'APCu' : (value === 'sqlite' ? 'SQLite' : (value === 'disk' ? 'Disk' : (value === 'runtime' ? 'Runtime-only' : String(value || 'Unavailable')))));
		const rawRedisDropinError = redis.dropinError || (objectCache.backendStatus && objectCache.backendStatus.redis && objectCache.backendStatus.redis.error) || '';
		const isRedisPayloadGuardMessage = (message) => /^Redis payload (rejected|skipped):/i.test(String(message || ''));
		const redisPayloadSkipReason = redis.payloadSkipReason || (objectCache.backendStatus && objectCache.backendStatus.redis && objectCache.backendStatus.redis.payloadSkipReason) || (isRedisPayloadGuardMessage(rawRedisDropinError) ? rawRedisDropinError.replace(/^Redis payload rejected:/i, 'Redis payload skipped:') : '');
		const redisDropinError = isRedisPayloadGuardMessage(rawRedisDropinError) ? '' : rawRedisDropinError;
		const fallbackMessage = objectCache.fallbackMessage || (fallbackActive ? (backendLabel(selectedBackend) + ' selected, ' + backendLabel(fallbackBackend) + ' fallback active.' + (redisDropinError ? ' Reason: ' + redisDropinError : '')) : '');
		const redisSupportText = redis.available ? 'PHP Redis extension detected on this server.' : (redis.message || 'PHP Redis extension not detected. The configured fallback policy will be used.');
		const manualPayloadProbe = objectCache.activationPayloadProbe || objectCache.manualPayloadProbe || redis.payloadProbe || {};
		const manualPayloadProbeKnown = !!manualPayloadProbe && typeof manualPayloadProbe === 'object' && (typeof manualPayloadProbe.success !== 'undefined' || !!manualPayloadProbe.message);
		const manualPayloadProbeText = manualPayloadProbeKnown
			? (manualPayloadProbe.success ? ('Passed' + (manualPayloadProbe.safeProbeBytes && manualPayloadProbe.payloadLimitBytes ? (' · tested ' + formatBytes(manualPayloadProbe.safeProbeBytes) + ' / limit ' + formatBytes(manualPayloadProbe.payloadLimitBytes)) : '')) : (manualPayloadProbe.message || 'Failed'))
			: '';
		const manualBackendTest = objectCache.manualBackendTest && typeof objectCache.manualBackendTest === 'object' ? objectCache.manualBackendTest : {};
		const manualBackendTestBackend = String(manualBackendTest.backend || '').toLowerCase();
		const manualBackendTestVisible = !!manualBackendTestBackend && manualBackendTestBackend === backend && (typeof manualBackendTest.success !== 'undefined' || !!manualBackendTest.message);
		const manualBackendChecks = manualBackendTest.checks && typeof manualBackendTest.checks === 'object' ? manualBackendTest.checks : {};
		const sqliteDatabaseSizeOptions = [32, 64, 128, 256, 512, 1024, 2048];
		const sqliteDatabaseSizeMb = sqliteDatabaseSizeOptions.indexOf(Number(form.sqliteDatabaseSizeMb)) !== -1 ? Number(form.sqliteDatabaseSizeMb) : 256;
		const sqliteFunctionalChecks = [
			{ key: 'write', label: __('Write', 'ultracache') },
			{ key: 'read', label: __('Read', 'ultracache') },
			{ key: 'delete', label: __('Delete', 'ultracache') },
			{ key: 'expiration', label: __('Expiration', 'ultracache') },
		];
		const showApcuSupport = backend === 'apcu' || (fallbackActive && activeBackend === 'apcu');
		const configuredFallbackUnavailable = !fallbackActive && (('apcu' === fallbackPolicy && apcu && apcu.available === false) || ('sqlite' === fallbackPolicy && sqlite && sqlite.available === false));
		const dropinInstallable = typeof objectCache.dropinInstallable === 'undefined' ? !!objectCache.available : !!objectCache.dropinInstallable;
		const selectedBackendSupported = typeof objectCache.selectedBackendSupported === 'undefined'
			? (selectedBackend === 'redis' ? !!redis.available : (selectedBackend === 'apcu' ? !!apcu.available : (selectedBackend === 'sqlite' ? !!sqlite.available : true)))
			: !!objectCache.selectedBackendSupported;
		const fallbackStatusText = fallbackActive
			? (backendLabel(fallbackBackend) + ' active')
			: (configuredFallbackUnavailable ? (backendLabel(fallbackPolicy) + ' unavailable · runtime-only final fallback') : ('none' === fallbackPolicy ? 'None / runtime-only' : backendLabel(fallbackPolicy) + ' standby'));
		const testButtonLabel = 'apcu' === backend ? 'Test APCu Connection' : ('sqlite' === backend ? 'Test SQLite Object Cache' : 'Test Disk Object Cache');
		const flushButtonLabel = 'redis' === backend ? 'Flush Redis Object Cache' : ('apcu' === backend ? 'Flush APCu Object Cache' : ('sqlite' === backend ? 'Flush SQLite Object Cache' : 'Flush Disk Object Cache'));
		const transportText = [form.redisUseTls ? 'TLS' : 'TCP', form.redisPersistent ? 'Persistent connections ON' : 'Persistent connections OFF'].join(' · ');
		const runtimeStatusText = objectCache.active
			? ('Active via ' + backendLabel(activeBackend) + (fallbackActive ? ' fallback' : ''))
			: (objectCache.enabled ? 'Drop-in inactive' : 'Disabled');
		const redisRuntimeConnected = !!objectCache.active && 'redis' === activeBackend && !redisDropinError;
		const redisConnectionText = redisRuntimeConnected
			? 'Connected'
			: (redisDropinError || (objectCache.runtimeConfigStale ? 'Pending page reload' : (fallbackActive ? 'Unavailable · fallback active' : 'Not active')));
		const payloadBackendStatus = manualPayloadProbe && manualPayloadProbe.backendStatus && typeof manualPayloadProbe.backendStatus === 'object' ? manualPayloadProbe.backendStatus : {};
		const payloadProbeBackend = String(payloadBackendStatus.active || objectCache.activationProbeBackend || activeBackend || selectedBackend).toLowerCase();
		const payloadProbeLabel = backendLabel(payloadProbeBackend) + ((fallbackActive && payloadProbeBackend === String(activeBackend).toLowerCase()) ? ' fallback' : '') + ' payload probe';
		const backendChoices = [
			{ value: 'redis', label: 'Redis', description: __("Recommended production backend. Fallback behavior is controlled by the Object Cache Fallback dropdown below.", 'ultracache') },
			{ value: 'apcu', label: 'APCu', description: __("Local memory backend for single-server sites. APCu is cleared on PHP-FPM restart.", 'ultracache') },
			{ value: 'sqlite', label: 'SQLite', description: __("Persistent local database backend for WordPress object caching.", 'ultracache') },
			{ value: 'disk', label: 'Disk', description: __("Advanced/debug only. Not recommended for production because it can create many small files.", 'ultracache') },
		];
		const renderBackendChoice = (choice) => {
			const selected = backend === choice.value;
			const infrastructureChoiceLocked = infrastructureLocked && ('redis' === choice.value || 'redis' === backend);
			return h('div', { className: 'uc-object-cache-backend-choice', key: 'backend-column-' + choice.value }, [
				h('button', {
					type: 'button',
					className: classNames(
						'uc-btn uc-object-cache-backend-button w-full py-3 font-bold text-white',
						selected ? 'uc-btn--primary' : '',
						(busy || infrastructureChoiceLocked) ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'
					),
					disabled: !!busy || infrastructureChoiceLocked,
					'aria-pressed': selected ? 'true' : 'false',
					onClick: () => {
						if (!busy && !infrastructureChoiceLocked && !selected) {
							onFieldChange('objectCacheBackend', choice.value);
						}
					},
					key: 'backend-button-' + choice.value,
				}, choice.label),
				h('p', { className: 'uc-object-cache-backend-description m-0 text-xs text-zinc-500 leading-relaxed', key: 'backend-description-' + choice.value }, choice.description),
			]);
		};
				return h(Card, {
			title: __("Object Cache", 'ultracache'),
			description: 'Enable the WordPress object-cache.php drop-in. The selected backend and active runtime backend are shown separately.',
		}, [
			infrastructureLocked ? h('div', { className: 'mb-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Redis configuration, testing, and flushing require plugin activation or network plugin management permission. APCu, SQLite, and Disk actions remain available.", 'ultracache')) : null,
			h(ToggleField, {
				label: __("Enable Object Cache", 'ultracache'),
				description: 'Enable the WordPress object-cache.php drop-in. Configure the primary backend and fallback policy below.',
				checked: !!objectCacheEnabled,
				onChange: onObjectCacheEnabledChange,
				disabled: busy || (infrastructureLocked && 'redis' === backend),
				key: 'object-cache-enabled',
			}),
			h(CacheHelperConflictNotice, { diagnostics, busy, onRemove: onRemoveConflictingDropins, onRecheck: onRecheckConflicts }),
			h('div', { className: 'objectcache uc-object-cache-backend-grid mt-4', role: 'group', 'aria-label': __("Object Cache backend selector", 'ultracache') }, backendChoices.map(renderBackendChoice)),
			backend === 'apcu' ? h('div', { className: 'mt-4 text-xs text-zinc-500' }, __("APCu has no connection credentials; use the test button to verify that the PHP APCu extension is available for the frontend runtime.", 'ultracache')) : null,
			backend === 'disk' ? h('div', { className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Disk object cache is advanced/debug only and may add filesystem I/O, but it will be used if configured or if fallback activates.", 'ultracache')) : null,
			(backend === 'sqlite' || fallbackPolicy === 'sqlite') ? h('div', { className: 'mt-4 flex items-center justify-between gap-4 py-4' }, [
				h('div', { className: 'min-w-0 pr-4' }, [
					h('div', { className: 'uc-field-label' }, __("Maximum SQLite database size", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-500 mt-1' }, __("Automatically saved. Limits the main SQLite object-cache database file. UltraCache removes expired and then oldest cache entries before the limit; lowering the limit below the current file size rebuilds this disposable cache database.", 'ultracache')),
				]),
				h('div', { className: 'uc-select-wrap shrink-0 w-40 max-w-full' }, [
					h('select', {
						className: 'uc-field-input uc-field-select',
						value: sqliteDatabaseSizeMb,
						disabled: !!busy,
						onChange: (e) => onFieldChange('sqliteDatabaseSizeMb', Number(e.target.value)),
					}, sqliteDatabaseSizeOptions.map((size) => h('option', { value: size, key: 'sqlite-database-' + size }, size + ' MB'))),
					h('span', { className: 'uc-select-icon', 'aria-hidden': true }, '▾'),
				]),
			]) : null,
			h('div', { className: 'mt-4 flex items-center justify-between gap-4 py-4' }, [
				h('div', { className: 'min-w-0 pr-4' }, [
					h('div', { className: 'uc-field-label' }, renderLabelWithHelp(
						__("Object Cache Fallback", 'ultracache'),
						getOptionHelpText(
							__("Object Cache Fallback", 'ultracache'),
							__("Used only when the selected backend cannot connect or is unavailable. Runtime-only cache is always the final emergency fallback.", 'ultracache'),
							__("What it does: chooses what UltraCache tries if the selected object-cache backend cannot be used.\n\nWhy it helps: the site can keep running with APCu, SQLite, Disk, or runtime-only cache instead of failing hard.\n\nWatch for: APCu is local memory for one server, SQLite is persistent local storage, Disk can add filesystem work, and runtime-only persists only for the current request.", 'ultracache')
						)
					)),
					h('div', { className: 'text-xs text-zinc-500 mt-1' }, __("Used only when the selected backend cannot connect or is unavailable. Runtime-only cache is always the final emergency fallback.", 'ultracache')),
					h('div', { className: 'text-xs text-zinc-400 mt-1' }, 'Fallback policy: ' + ('none' === fallbackPolicy ? 'None / runtime-only' : backendLabel(fallbackPolicy)) + '. Fallback status: ' + fallbackStatusText + '.'),
					'disk' === fallbackPolicy ? h('div', { className: 'text-xs text-amber-300 mt-1' }, __("Disk fallback is advanced/debug only and may add filesystem I/O.", 'ultracache')) : null,
				]),
				h('div', { className: 'uc-select-wrap shrink-0 w-56 max-w-full' }, [
					h('select', {
						className: 'uc-field-input uc-field-select',
						value: fallbackPolicy,
						disabled: !!busy || (infrastructureLocked && 'redis' === backend),
						onChange: (e) => onFieldChange('objectCacheFallbackBackend', e.target.value),
					}, [
						h('option', { value: 'none', key: 'none' }, __("None / runtime-only", 'ultracache')),
						h('option', { value: 'apcu', key: 'apcu' }, 'APCu'),
						h('option', { value: 'sqlite', key: 'sqlite' }, 'SQLite'),
						h('option', { value: 'disk', key: 'disk' }, __("Disk (advanced/debug)", 'ultracache')),
					]),
					h('span', { className: 'uc-select-icon', 'aria-hidden': true }, '▾'),
				]),
			]),
			fallbackActive ? h('div', { className: 'mt-4 text-xs text-zinc-500' }, fallbackMessage) : null,
			backend === 'redis' ? h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(TextRow, {
					label: __("Redis host", 'ultracache'),
					description: __("Common default: 127.0.0.1. External Redis hosts are supported when intentionally configured.", 'ultracache'),
					value: form.redisHost || '127.0.0.1',
					onChange: (value) => onFieldChange('redisHost', value),
					disabled: busy || infrastructureLocked,
					placeholder: '127.0.0.1',
					key: 'redis-host',
				}),
				h(NumberRow, {
					label: __("Redis port", 'ultracache'),
					description: __("Common default: 6379. Custom Redis ports are supported.", 'ultracache'),
					value: form.redisPort || 6379,
					onChange: (value) => onFieldChange('redisPort', value),
					disabled: busy || infrastructureLocked,
					min: 1,
					step: 1,
					key: 'redis-port',
				}),
				h(TextRow, {
					label: __("Redis username", 'ultracache'),
					description: __("Optional. Required only for Redis ACL users. Leave empty for password-only Redis.", 'ultracache'),
					value: form.redisUsername || '',
					onChange: (value) => onFieldChange('redisUsername', value),
					disabled: busy || infrastructureLocked,
					placeholder: 'optional',
					autoComplete: 'off',
					key: 'redis-username',
				}),
				h('div', { key: 'redis-password-wrap' }, [
					h(TextRow, {
						label: __("Redis password", 'ultracache'),
						description: secretExternal
							? 'Configured outside the UltraCache managed block in wp-config.php. It is read-only here.'
							: (form.redisPasswordConfigured ? 'Managed by UltraCache in wp-config.php. Enter a new value to replace it; the current value is never displayed.' : 'Enter a value to save WP_REDIS_PASSWORD in the UltraCache managed wp-config.php block.'),
						value: form.redisPassword || '',
						onChange: (value) => {
							onFieldChange('redisPassword', value);
							if (value) {
								onFieldChange('clearRedisPassword', false);
							}
						},
						disabled: busy || infrastructureLocked || secretExternal,
						placeholder: secretExternal ? 'Externally configured' : (form.redisPasswordConfigured ? 'Leave blank to keep current value' : 'Enter Redis password'),
						type: 'password',
						autoComplete: 'new-password',
						key: 'redis-password-input',
					}),
					secretManaged ? h('div', { className: 'pb-4 flex justify-end', key: 'redis-password-clear-wrap' }, [
						h(Button, {
							onClick: () => onFieldChange('clearRedisPassword', !form.clearRedisPassword),
							disabled: busy || infrastructureLocked || secretExternal,
							variant: form.clearRedisPassword ? 'primary' : 'light',
							key: 'redis-password-clear',
						}, form.clearRedisPassword ? 'Password will be removed on save' : 'Remove managed password'),
					]) : null,
				]),
				h(NumberRow, {
					label: __("Redis database", 'ultracache'),
					description: __("Usually 0. Typical range: 0-15.", 'ultracache'),
					value: typeof form.redisDatabase === 'undefined' ? 0 : form.redisDatabase,
					onChange: (value) => onFieldChange('redisDatabase', value),
					disabled: busy || infrastructureLocked,
					min: 0,
					step: 1,
					key: 'redis-db',
				}),
				h(TextRow, {
					label: __("Redis prefix / namespace", 'ultracache'),
					description: __("Optional. Leave blank for automatic site-specific prefix.", 'ultracache'),
					value: form.redisPrefix || '',
					onChange: (value) => onFieldChange('redisPrefix', value),
					disabled: busy || infrastructureLocked,
					placeholder: __("leave blank for auto", 'ultracache'),
					key: 'redis-prefix',
				}),
				h(NumberRow, {
					label: __("Connect timeout (ms)", 'ultracache'),
					description: __("Advanced. Default: 200ms. Maximum: 15000ms.", 'ultracache'),
					value: typeof form.redisConnectTimeoutMs === 'undefined' ? 200 : form.redisConnectTimeoutMs,
					onChange: (value) => onFieldChange('redisConnectTimeoutMs', value),
					disabled: busy || infrastructureLocked,
					min: 50,
					max: 15000,
					step: 50,
					key: 'redis-connect-timeout',
				}),
				h(NumberRow, {
					label: __("Read timeout (ms)", 'ultracache'),
					description: __("Advanced. Default: 200ms. Maximum: 15000ms.", 'ultracache'),
					value: typeof form.redisReadTimeoutMs === 'undefined' ? 200 : form.redisReadTimeoutMs,
					onChange: (value) => onFieldChange('redisReadTimeoutMs', value),
					disabled: busy || infrastructureLocked,
					min: 50,
					max: 15000,
					step: 50,
					key: 'redis-read-timeout',
				}),
				h(ToggleField, {
					label: __("Persistent connection", 'ultracache'),
					description: __("Advanced. Saved and validated with the Redis settings button. Reuse the Redis connection across PHP worker requests when supported.", 'ultracache'),
					checked: !!form.redisPersistent,
					onChange: (value) => onFieldChange('redisPersistent', value),
					disabled: busy || infrastructureLocked,
					key: 'redis-persistent',
				}),
				h(ToggleField, {
					label: __("Use TLS", 'ultracache'),
					description: __("Saved and validated with the Redis settings button. Enable for managed Redis providers that require TLS/SSL transport.", 'ultracache'),
					checked: !!form.redisUseTls,
					onChange: (value) => onFieldChange('redisUseTls', value),
					disabled: busy || infrastructureLocked,
					key: 'redis-use-tls',
				}),
			]) : null,
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				backend === 'redis' ? h(Button, { onClick: onSave, disabled: busy || infrastructureLocked, variant: 'primary' }, busy ? 'Working…' : 'Save Redis Settings') : null,
				backend !== 'redis' ? h(Button, { onClick: onTest, disabled: busy, variant: 'primary' }, busy ? 'Working…' : testButtonLabel) : null,
				h(Button, { onClick: onFlush, disabled: busy || (infrastructureLocked && 'redis' === backend), variant: 'primary' }, busy ? 'Working…' : flushButtonLabel),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Status", 'ultracache')),
						h(StatusPill, { ok: !!objectCache.active && !fallbackActive, text: runtimeStatusText, tone: fallbackActive ? 'warning' : (objectCache.active ? 'success' : 'neutral') }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Configured backend", 'ultracache')),
						h(StatusPill, {
							ok: selectedBackendSupported,
							text: backendLabel(selectedBackend),
							tone: selectedBackend === 'disk' ? 'warning' : (selectedBackendSupported ? 'success' : 'warning')
						}),
					]),
					backend === 'redis' ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Redis connection", 'ultracache')),
						redisRuntimeConnected
							? h(StatusPill, { ok: true, text: redisConnectionText, tone: 'success' })
							: h('div', { className: 'text-xs text-amber-300 text-right break-all max-w-xl' }, redisConnectionText),
					]) : null,
					backend === 'redis' ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("PHP Redis extension", 'ultracache')),
						h(StatusPill, { ok: !!redis.available, text: redis.available ? 'Available' : 'Unavailable', tone: redis.available ? 'success' : 'warning' }),
					]) : null,
					showApcuSupport ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("PHP APCu extension", 'ultracache')),
						h(StatusPill, { ok: !!apcu.available, text: apcu.available ? 'Available' : 'Unavailable in this runtime', tone: apcu.available ? 'success' : 'neutral' }),
					]) : null,
					(backend === 'sqlite' || fallbackPolicy === 'sqlite' || (fallbackActive && activeBackend === 'sqlite')) ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("PHP SQLite3 extension", 'ultracache')),
						h(StatusPill, { ok: !!sqlite.available, text: sqlite.available ? ('Available' + (sqlite.version ? ' · ' + sqlite.version : '')) : 'Unavailable', tone: sqlite.available ? 'success' : 'warning' }),
					]) : null,
					((backend === 'sqlite' || activeBackend === 'sqlite') && sqlite.journalMode) ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("SQLite journal mode", 'ultracache')),
						h('code', { className: 'text-xs text-zinc-300' }, String(sqlite.journalMode).toUpperCase()),
					]) : null,
					manualPayloadProbeKnown ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, payloadProbeLabel),
						h(StatusPill, { ok: !!manualPayloadProbe.success, text: manualPayloadProbeText, tone: manualPayloadProbe.success ? 'success' : 'warning' }),
					]) : null,
					backend === 'redis' ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Redis prefix", 'ultracache')),
						h('code', { className: 'text-xs text-zinc-300 break-all' }, redis.prefix || 'auto'),
					]) : null,
					!dropinInstallable ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Drop-in installable", 'ultracache')),
						h(StatusPill, { ok: false, text: 'No', tone: 'warning' }),
					]) : null,
				]),
				manualBackendTestVisible ? h('div', { className: 'mt-4 rounded-xl border border-white/10 bg-black/20 px-4 py-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-1' }, [
						h('div', { className: 'text-sm font-semibold text-white' }, backendLabel(manualBackendTestBackend) + ' ' + __('Functional test', 'ultracache')),
						h(StatusPill, {
							ok: !!manualBackendTest.success,
							text: manualBackendTest.success ? __('PASS', 'ultracache') : __('FAIL', 'ultracache'),
							tone: manualBackendTest.success ? 'success' : 'warning',
						}),
					]),
					manualBackendTest.message ? h('div', { className: 'mt-2 text-xs text-zinc-400 break-words' }, manualBackendTest.message) : null,
					manualBackendTestBackend === 'sqlite' ? h('div', { className: 'mt-2 divide-y divide-white/5' }, sqliteFunctionalChecks.map((check) => h('div', { className: 'flex items-center justify-between gap-4 py-2', key: 'sqlite-check-' + check.key }, [
						h('div', { className: 'text-sm text-zinc-300' }, check.label),
						h(StatusPill, {
							ok: manualBackendChecks[check.key] === true,
							text: manualBackendChecks[check.key] === true ? __('PASS', 'ultracache') : __('FAIL', 'ultracache'),
							tone: manualBackendChecks[check.key] === true ? 'success' : 'warning',
						}),
					]))) : null,
				]) : null,
				backend === 'redis' ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, redisSupportText) : null,
			]),
		]);
	}


	function createController(context) {
		const source = context && typeof context === 'object' ? context : {};
		const {
			redisForm,
			varnishForm,
			settingsRef,
			busy,
			stats,
			setVarnishForm,
			setRedisForm,
			queueSettingsPatch,
			enqueueUiOperation,
			saveSettingsPatch,
			applyDashboardPayload,
			mergeObjectCacheTestResult,
			queueDashboardAction,
			refreshStats,
			pushToast,
			setBusy,
			setStats,
			setDiagnostics,
			mergeManualObjectCacheTestIntoDiagnostics,
			mergeVarnishTestResult,
			stageDashboardPayloadForQueue,
		} = source;

		const varnishController = createVarnishController({
			varnishForm,
			setVarnishForm,
			queueSettingsPatch,
			enqueueUiOperation,
			saveSettingsPatch,
			applyDashboardPayload,
			mergeVarnishTestResult,
			queueDashboardAction,
		});
		const {
			updateVarnishField,
			saveVarnishSettings,
			runVarnishTest,
			runVarnishFlushAll,
			runVarnishFlushEntireHost,
		} = varnishController;

		function updateRedisField(key, value) {
			const normalizedBackend = (candidate) => {
				candidate = String(candidate || '').toLowerCase();
				return ['redis', 'apcu', 'sqlite', 'disk'].indexOf(candidate) !== -1 ? candidate : 'redis';
			};
			const normalizedFallback = (candidate) => {
				candidate = String(candidate || '').toLowerCase();
				return ['none', 'runtime'].indexOf(candidate) !== -1 ? 'none' : (['apcu', 'sqlite', 'disk'].indexOf(candidate) !== -1 ? candidate : 'apcu');
			};
			const currentForm = redisForm || {};
			const currentSettings = settingsRef.current || {};
			const currentEnabled = !!currentSettings.objectCacheEnabled;
			const currentBackend = normalizedBackend(currentForm.objectCacheBackend || currentSettings.objectCacheBackend || 'redis');
			const currentFallback = normalizedFallback(currentForm.objectCacheFallbackBackend || currentSettings.objectCacheFallbackBackend || 'apcu');

			if (key === 'objectCacheBackend') {
				const nextBackend = normalizedBackend(value);
				setRedisForm((current) => Object.assign({}, current, { objectCacheBackend: nextBackend }));
				queueSettingsPatch(Object.assign({
					objectCacheEnabled: currentEnabled,
					objectCacheBackend: nextBackend,
					objectCacheFallbackBackend: currentFallback,
				}, nextBackend === 'apcu' ? { flushAllIncludeApcu: true } : {}));
				return;
			}

			if (key === 'objectCacheFallbackBackend') {
				const nextFallback = normalizedFallback(value);
				setRedisForm((current) => Object.assign({}, current, { objectCacheFallbackBackend: nextFallback }));
				queueSettingsPatch({
					objectCacheEnabled: currentEnabled,
					objectCacheBackend: currentBackend,
					objectCacheFallbackBackend: nextFallback,
				});
				return;
			}

			if (key === 'sqliteDatabaseSizeMb') {
				const allowedSizes = [32, 64, 128, 256, 512, 1024, 2048];
				const nextSize = allowedSizes.indexOf(Number(value)) !== -1 ? Number(value) : 256;
				setRedisForm((current) => Object.assign({}, current, { sqliteDatabaseSizeMb: nextSize }));
				queueSettingsPatch({ sqliteDatabaseSizeMb: nextSize });
				return;
			}

			setRedisForm((current) => Object.assign({}, current, { [key]: value }));
		}


		async function saveRedisSettings() {
			return enqueueUiOperation('object_cache_settings_save', 'Save object-cache settings', async () => {
				const form = Object.assign({}, redisForm || {});
				const patch = {
					objectCacheBackend: form.objectCacheBackend || 'redis',
					objectCacheFallbackBackend: form.objectCacheFallbackBackend || 'apcu',
					sqliteDatabaseSizeMb: [32, 64, 128, 256, 512, 1024, 2048].indexOf(Number(form.sqliteDatabaseSizeMb)) !== -1 ? Number(form.sqliteDatabaseSizeMb) : 256,
					redisHost: form.redisHost || '127.0.0.1',
					redisPort: form.redisPort,
					redisUsername: form.redisUsername || '',
					redisDatabase: form.redisDatabase,
					redisPrefix: form.redisPrefix || '',
					redisUseTls: !!form.redisUseTls,
					redisPersistent: !!form.redisPersistent,
					redisConnectTimeoutMs: form.redisConnectTimeoutMs,
					redisReadTimeoutMs: form.redisReadTimeoutMs,
				};
				if (form.redisPassword) {
					patch.redisPassword = form.redisPassword;
				}
				if (form.clearRedisPassword) {
					patch.clearRedisPassword = true;
				}
				if (String(patch.objectCacheBackend || '').toLowerCase() === 'apcu') {
					patch.flushAllIncludeApcu = true;
				}
				patch.validateRedisSettings = String(patch.objectCacheBackend || '').toLowerCase() === 'redis';
				const response = await saveSettingsPatch(patch);
				try {
					window.sessionStorage.setItem('ultracacheObjectCacheActivationProbe', JSON.stringify({ backend: 'redis' }));
				} catch (error) {}
				window.setTimeout(() => window.location.reload(), 350);
				return response;
			}, { processingText: 'Validating and saving Redis settings…', successText: 'Redis settings verified and saved. Reloading…', failedText: 'Redis settings were not saved.' });
		}

		async function testObjectCacheBackend() {
			return enqueueUiOperation('object_cache_test', 'Test object cache backend', async () => {
				const selectedBackend = (redisForm && redisForm.objectCacheBackend) || (settingsRef.current || {}).objectCacheBackend || 'redis';
				const payload = Object.assign({}, redisForm || {}, {
					backend: selectedBackend,
				});
				try {
					const response = await apiRequest('object_cache_test', payload);
					applyDashboardPayload(response || {});
					mergeObjectCacheTestResult(response || {});
					return response;
				} catch (error) {
					if (error && error.data && typeof error.data === 'object') {
						applyDashboardPayload(error.data);
						mergeObjectCacheTestResult(error.data);
					}
					throw error;
				}
			}, { processingText: 'Processing object-cache backend test…', successText: 'Object cache backend test finished.', failedText: 'Object cache backend test failed.' });
		}

		async function flushObjectCache() {
			const selectedBackend = (redisForm && redisForm.objectCacheBackend) || (settingsRef.current || {}).objectCacheBackend || 'active';
			await queueDashboardAction('object_cache_flush', { backend: selectedBackend }, {
				queued: 'Object cache flush processing via dashboard…',
				success: 'Object cache flush finished.',
				failed: 'Object cache flush failed.',
			}, 'object_cache_flush');
		}

		function scrollToCacheConflictReview() {
			try {
				const element = document.getElementById('ultracache-cache-conflict-review');
				if (element && typeof element.scrollIntoView === 'function') {
					element.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			} catch (error) {}
		}

		async function recheckCacheConflicts() {
			try {
				await refreshStats();
				pushToast({ type: 'info', text: __("Cache conflict diagnostics refreshed.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Cache conflict re-check failed.' });
			}
		}

		async function removeConflictingCacheDropins() {
			if (busy) {
				return;
			}

			const confirmed = window.confirm('UltraCache will permanently remove the detected advanced-cache.php/object-cache.php files that are not managed by UltraCache. Continue?');
			if (!confirmed) {
				pushToast({ type: 'info', text: __("Cache helper removal cancelled.", 'ultracache') });
				return;
			}

			setBusy(true);
			try {
				const response = await apiRequest('remove_conflicting_cache_dropins', {});
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(mergeManualObjectCacheTestIntoDiagnostics(response.diagnostics));
				}
				await refreshStats();
				pushToast({ type: 'success', text: response && response.message ? response.message : 'Conflicting cache helpers removed.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to remove conflicting cache helpers.' });
			} finally {
				setBusy(false);
			}
		}

		async function runFullObjectCount() {
			await queueDashboardAction('object_cache_full_count', {}, {
				queued: 'Full object-cache count processing via dashboard…',
				success: 'Full object-cache count finished.',
				failed: 'Full object-cache count failed.',
				runningLabel: 'Counting…',
			}, 'object_cache_full_count');
		}



		async function flushOpcache() {
			return enqueueUiOperation('opcache_flush', 'Flush OPcache', async () => {
				const response = await apiRequest('opcache_flush', {});
				applyDashboardPayload(response || {});
				if (response && response.opcache) {
					stageDashboardPayloadForQueue({ stats: Object.assign({}, stats || {}, { opcache: response.opcache }) });
				}
				try {
					await refreshStats();
				} catch (error) {}
				return response;
			}, { processingText: 'Processing OPcache flush…', successText: 'OPcache flush finished.', failedText: 'OPcache flush failed.' });
		}

		async function flushApcu() {
			await queueDashboardAction('apcu_flush', {}, {
				queued: 'APCu flush processing via dashboard…',
				success: 'APCu flush finished.',
				failed: 'APCu flush failed.',
			}, 'apcu_flush');
		}


		async function flushLiteSpeed() {
			return enqueueUiOperation('litespeed_flush', 'Flush LiteSpeed Cache', async () => {
				const response = await apiRequest('litespeed_flush', {});
				applyDashboardPayload(response || {});
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Processing LiteSpeed Cache flush…', successText: 'LiteSpeed Cache flush finished.', failedText: 'LiteSpeed Cache flush failed.' });
		}

		async function flushNginx() {
			return enqueueUiOperation('nginx_flush', 'Flush Nginx Cache', async () => {
				const response = await apiRequest('nginx_flush', {});
				applyDashboardPayload(response || {});
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Processing Nginx Cache flush…', successText: 'Nginx Cache flush finished.', failedText: 'Nginx Cache flush failed.' });
		}

		async function redetectExternalCaches() {
			return enqueueUiOperation('external_caches_redetect', 'Redetect caches', async () => {
				const response = await apiRequest('external_caches_redetect', {});
				applyDashboardPayload(response || {});
				if (response && response.layers) {
					stageDashboardPayloadForQueue({ stats: Object.assign({}, stats || {}, { externalCaches: response }) });
				}
				try { await refreshStats(); } catch (error) {}
				return response;
			}, { processingText: 'Detecting external caches…', successText: 'Cache detection refreshed.', failedText: 'Cache detection failed.' });
		}

		async function purgeCache() {
			await queueDashboardAction('purge_all', {}, {
				queued: 'Full cache purge processing via dashboard…',
				success: 'All cache files cleared.',
				failed: 'Failed to purge cache.',
			}, 'purge_all');
		}

		return {
			updateVarnishField,
			updateRedisField,
			saveRedisSettings,
			testObjectCacheBackend,
			flushObjectCache,
			scrollToCacheConflictReview,
			recheckCacheConflicts,
			removeConflictingCacheDropins,
			runFullObjectCount,
			saveVarnishSettings,
			runVarnishTest,
			runVarnishFlushAll,
			runVarnishFlushEntireHost,
			flushOpcache,
			flushApcu,
			flushLiteSpeed,
			flushNginx,
			redetectExternalCaches,
			purgeCache,
		};
	}

	admin.define('cache', {
		CacheHelperConflictNotice,
		getDefaultVarnishServersForMode,
		isDefaultVarnishServersValue,
		formatVarnishResultDetailLines,
		formatVarnishResultMessage,
		VarnishCard,
		OPcacheCard,
		APCuCard,
		getExternalCacheLayer,
		ExternalCacheCard,
		ExternalCacheFlushSettingsCard,
		RedisCard,
		createController,
	});
})(window);
