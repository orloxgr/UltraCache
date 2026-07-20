/* UltraCache Admin - Varnish integration */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before varnish.js.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	const ui = admin.get('ui');
	const cacheShared = admin.get('cacheShared');
	const varnishRefreshAhead = admin.get('varnishRefreshAhead');
	const varnishMetrics = admin.get('varnishMetrics');
	const varnishFlushScope = admin.get('varnishFlushScope');
	if (!core || !api || !ui || !cacheShared || !varnishRefreshAhead || !varnishMetrics || !varnishFlushScope) {
		throw new Error('UltraCache admin core/api/ui/cache-shared/varnish-refresh-ahead/varnish-metrics/varnish-flush-scope modules are required before varnish.js.');
	}

	const { h, __, sprintf, formatLooseTime, useState } = core;
	const { apiRequest } = api;
	const { CacheHelperConflictNotice } = cacheShared;
	const { renderControls: renderRefreshAheadControls, renderStatusRows: renderRefreshAheadStatusRows, renderMessages: renderRefreshAheadMessages } = varnishRefreshAhead;
	const { renderStatusRows: renderMetricsStatusRows, renderMessages: renderMetricsMessages } = varnishMetrics;
	const { getState: getFlushScopeState, renderControl: renderFlushScopeControl, renderStatusRows: renderFlushScopeStatusRows, renderMessages: renderFlushScopeMessages } = varnishFlushScope;
	const { Card, Button, ToggleRow, TextField, NumberRow, SelectField, StatusPill } = ui;

	function getDefaultVarnishServersForMode(mode) {
		return String(mode || 'http') === 'admin' ? '127.0.0.1:6082' : '127.0.0.1:82';
	}

	function isDefaultVarnishServersValue(value) {
		const normalized = String(value || '').trim();
		return !normalized || normalized === '127.0.0.1:82' || normalized === '127.0.0.1:6082';
	}

	function formatVarnishResultDetailLines(result) {
		const details = result && Array.isArray(result.details) ? result.details : [];
		const lines = details.map((item) => {
			if (!item || typeof item !== 'object') {
				return '';
			}
			const server = String(item.server || 'server');
			const status = item.success ? 'OK' : 'FAIL';
			const detail = item.detail ? String(item.detail) : '';
			return server + ': ' + status + (detail ? ' · ' + detail : '');
		}).filter((line) => line.length > 0);

		const rejections = result && Array.isArray(result.rejections) ? result.rejections : [];
		rejections.forEach((item) => {
			if (!item || typeof item !== 'object') {
				return;
			}
			const url = String(item.url || __('URL', 'ultracache'));
			const reason = String(item.reason || 'rejected');
			lines.push(__('Rejected', 'ultracache') + ': ' + url + ' · ' + reason);
		});

		if (result && result.detailsTruncated) {
			lines.push(__('Additional endpoint result lines were omitted from the dashboard.', 'ultracache'));
		}
		if (result && result.rejectionsTruncated) {
			lines.push(__('Additional rejected URL lines were omitted from the dashboard.', 'ultracache'));
		}

		return lines;
	}

	function formatVarnishResultMessage(result, fallback) {
		const message = result && result.message ? String(result.message) : (fallback || 'Varnish test failed.');
		const detailLines = formatVarnishResultDetailLines(result);
		return detailLines.length ? (message + '\n' + detailLines.join('\n')) : message;
	}

	function getVarnishBehaviorStepTone(step) {
		const status = String(step && step.status ? step.status : '').toUpperCase();
		if (status === 'HIT') {
			return 'success';
		}
		if (status === 'MISS' || status === 'STALE') {
			return 'warning';
		}
		if (status === 'ERROR' || status === 'BYPASS') {
			return 'warning';
		}
		return 'neutral';
	}

	function formatVarnishBehaviorStep(step) {
		if (!step || typeof step !== 'object') {
			return '—';
		}
		const status = String(step.status || 'INCONCLUSIVE').toUpperCase();
		const parts = [status];
		if (step.httpCode) {
			parts.push('HTTP ' + String(step.httpCode));
		}
		if (step.headers && typeof step.headers.age !== 'undefined' && String(step.headers.age) !== '') {
			parts.push('Age ' + String(step.headers.age));
		}
		if (step.headers && step.headers.ultraCache) {
			parts.push('UltraCache ' + String(step.headers.ultraCache));
		}
		if (step.headers && step.headers.ultraCacheVariant) {
			parts.push('Variant ' + String(step.headers.ultraCacheVariant));
		}
		if (step.headers && step.headers.ultraCacheSource) {
			parts.push('Source ' + String(step.headers.ultraCacheSource));
		}
		if (step.durationMs || step.durationMs === 0) {
			parts.push(String(step.durationMs) + ' ms');
		}
		return parts.join(' · ');
	}

	function VarnishCard({ form, diagnostics, busy, canManageInfrastructure, onFieldChange, onSave, onTest, onFlushAll, onFlushEntireHost, onRemoveConflictingDropins, onRecheckConflicts }) {
		const [statusOpen, setStatusOpen] = useState(false);
		const varnish = diagnostics.varnish || {};
		const infrastructureLocked = !canManageInfrastructure;
		const last = varnish.last || {};
		const behaviorResult = last && last.testType === 'behavior' ? last : null;
		const batchResult = last && ['batch-invalidation', 'queued-invalidation'].indexOf(last.operationType) !== -1 ? last : null;
		const queuedInvalidationResult = batchResult && batchResult.operationType === 'queued-invalidation' ? batchResult : null;
		const queuedRefillResult = last && last.operationType === 'queued-refill' ? last : null;
		const queueStats = varnish.queue && typeof varnish.queue === 'object' ? varnish.queue : {};
		const varnishMetricsStatus = varnish.metrics && typeof varnish.metrics === 'object' ? varnish.metrics : {};
		const flushScopeStatus = varnish.flushScope && typeof varnish.flushScope === 'object' ? varnish.flushScope : {};
		const flushScopeUi = getFlushScopeState(form, flushScopeStatus);
		const htmlCapability = flushScopeUi.capability;
		const htmlScopeVerified = flushScopeUi.automaticSupported;
		const htmlScopeManualSupported = flushScopeUi.manualSupported;
		const configuredFlushScope = flushScopeUi.configured;
		const effectiveFlushScope = flushScopeUi.effective;
		const behaviorSteps = behaviorResult && behaviorResult.steps && typeof behaviorResult.steps === 'object' ? behaviorResult.steps : {};
		const conditionalRevalidationTest = behaviorResult && behaviorResult.conditionalRevalidationTest && typeof behaviorResult.conditionalRevalidationTest === 'object' ? behaviorResult.conditionalRevalidationTest : {};
		const conditionalRevalidationStatus = String(conditionalRevalidationTest.status || 'not-run').toLowerCase();
		const htmlFlushSteps = behaviorResult && behaviorResult.htmlFlushSteps && typeof behaviorResult.htmlFlushSteps === 'object' ? behaviorResult.htmlFlushSteps : {};
		const htmlVariantStatus = varnish.htmlVariant && typeof varnish.htmlVariant === 'object' ? varnish.htmlVariant : {};
		const htmlTtlStatus = varnish.htmlTtl && typeof varnish.htmlTtl === 'object' ? varnish.htmlTtl : {};
		const staleWhileRevalidateStatus = varnish.staleWhileRevalidate && typeof varnish.staleWhileRevalidate === 'object' ? varnish.staleWhileRevalidate : {};
		const twoStageRefill = varnish.twoStageRefill && typeof varnish.twoStageRefill === 'object' ? varnish.twoStageRefill : {};
		const refreshAhead = varnish.refreshAhead && typeof varnish.refreshAhead === 'object' ? varnish.refreshAhead : {};
		const twoStageRefillStatus = String(twoStageRefill.status || 'untested').toLowerCase();
		const configuredHtmlTtlValue = typeof form.varnishHtmlTtlMinutes === 'undefined' ? 1440 : Number(form.varnishHtmlTtlMinutes);
		const configuredHtmlTtlMinutes = Number.isFinite(configuredHtmlTtlValue) ? Math.max(0, Math.min(525600, configuredHtmlTtlValue)) : 0;
		const configuredStaleValue = Number(form.varnishStaleWhileRevalidateSeconds || 0);
		const configuredStaleWhileRevalidateSeconds = Number.isFinite(configuredStaleValue) ? Math.max(0, Math.min(86400, configuredStaleValue)) : 0;
		const htmlTtlBehaviorStatus = String(htmlTtlStatus.status || (configuredHtmlTtlMinutes > 0 ? 'not-tested' : 'disabled')).toLowerCase();
		const variantTest = behaviorResult && behaviorResult.variantTest && typeof behaviorResult.variantTest === 'object' ? behaviorResult.variantTest : {};
		const activeHtmlBuckets = Array.isArray(htmlVariantStatus.activeBuckets) && htmlVariantStatus.activeBuckets.length ? htmlVariantStatus.activeBuckets : ['orig'];
		const variantTestStatus = String(variantTest.status || 'not-tested').toLowerCase();
		const supportMessage = varnish.message || '';
		const endpointDiagnostics = varnish.endpointDiagnostics || {};
		const endpointWarningMessages = Array.isArray(endpointDiagnostics.messages) ? endpointDiagnostics.messages : (varnish.unsafeEndpointMessage ? [varnish.unsafeEndpointMessage] : []);
		const hasUnsafeEndpoints = !!varnish.hasUnsafeEndpoints || !!endpointDiagnostics.unsafe;
		const legacyConflicts = diagnostics.legacyCacheConflicts || {};
		const detailLines = formatVarnishResultDetailLines(last).join('\n');
		const mode = form.varnishCliMode || 'http';
		const isAdminMode = mode === 'admin';
		const formServers = String(form.varnishCliServers || '');
		const currentFrontendHost = (typeof window !== 'undefined' && window.location && window.location.hostname ? String(window.location.hostname).replace(/^www\./i, '') : '');
		const frontendHostPattern = currentFrontendHost ? new RegExp('(^|\\s)(?:https?:\\/\\/)?(?:www\\.)?' + currentFrontendHost.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?::|\\s|$)', 'i') : null;
		const formHasUnsafeEndpoint = !isAdminMode && /:(80|443|8443)(\s|$)/.test(formServers) && !!frontendHostPattern && frontendHostPattern.test(formServers);
		const actionsBlocked = hasUnsafeEndpoints || formHasUnsafeEndpoint;
		const strategyStatus = varnish.invalidationStrategy && typeof varnish.invalidationStrategy === 'object' ? varnish.invalidationStrategy : {};
		const softCapability = strategyStatus.softCapability && typeof strategyStatus.softCapability === 'object' ? strategyStatus.softCapability : {};
		const softSupported = !!softCapability.supported;
		const configuredStrategy = form.varnishInvalidationStrategy || strategyStatus.configured || String(form.varnishCliMethod || 'BAN').toLowerCase();
		const effectiveStrategy = String(strategyStatus.effective || (isAdminMode ? 'ban' : configuredStrategy)).toLowerCase();
		const effectiveMethod = effectiveStrategy === 'soft' ? 'soft PURGE' : (isAdminMode ? 'admin BAN' : effectiveStrategy.toUpperCase());
		const endpointCount = typeof varnish.endpointCount !== 'undefined' ? varnish.endpointCount : (formServers.trim() ? formServers.trim().split(/\s+/).length : 0);
		const secretConfigured = !!(varnish.secretConfigured || form.varnishCliKeyConfigured);
		const secretManaged = !!form.varnishCliKeyManaged;
		const secretExternal = !!form.varnishCliKeyExternal;
		const modeLabel = isAdminMode ? 'Admin secret' : 'HTTP frontend';

		return h(Card, {
			title: __("Varnish Cache", 'ultracache'),
			description: __("Varnish integration supports two purge methods: HTTP frontend endpoint mode and admin-secret mode. Use the method your host exposes; HTTP mode is optional and is not required when admin-secret mode is configured.", 'ultracache'),
		}, [
			infrastructureLocked ? h('div', { className: 'mb-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Redis and Varnish infrastructure settings and actions require plugin activation or network plugin management permission.", 'ultracache')) : null,
			h(ToggleRow, {
				label: isAdminMode ? 'Enable Varnish admin-secret purge' : 'Enable Varnish HTTP endpoint purge',
				description: varnish.available ? (isAdminMode ? 'Saves immediately. Uses the Varnish admin socket and shared secret. HTTP endpoint tests are not used in this mode.' : 'Saves immediately. Sends BAN/PURGE requests to configured Varnish HTTP listener endpoints, including external infrastructure hosts when intentionally configured.') : (supportMessage || 'Unavailable on this server.'),
				checked: !!form.varnishCliEnabled,
				onChange: (value) => onFieldChange('varnishCliEnabled', value),
				disabled: busy || infrastructureLocked || !varnish.available,
			}),
			
			!isAdminMode && endpointWarningMessages.length ? h('div', { className: 'space-y-2 mt-4' }, endpointWarningMessages.map((message, index) => h('div', { className: 'text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2', key: 'varnish-endpoint-warning-' + index }, message))) : null,
			formHasUnsafeEndpoint ? h('div', { className: 'mt-4 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2' }, __("This endpoint appears to point at the public WordPress frontend instead of a Varnish listener. External Varnish infrastructure and custom ports are allowed when intentionally configured.", 'ultracache')) : null,
			h(CacheHelperConflictNotice, { diagnostics, busy, onRemove: onRemoveConflictingDropins, onRecheck: onRecheckConflicts }),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(SelectField, {
					label: __("Purge mode", 'ultracache'),
					description: __("Choose HTTP only when your host exposes a local Varnish HTTP purge listener. Choose Admin when your host provides the Varnish admin secret/socket.", 'ultracache'),
					value: form.varnishCliMode || 'http',
					onChange: (value) => onFieldChange('varnishCliMode', value),
					disabled: busy || infrastructureLocked,
					options: [
						{ value: 'http', label: __("HTTP frontend endpoint", 'ultracache') },
						{ value: 'admin', label: __("Admin secret", 'ultracache') },
					],
					key: 'mode',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin endpoints' : 'HTTP endpoints',
					description: isAdminMode ? 'Space-separated Varnish admin endpoints in host:port format. Example: 127.0.0.1:6082' : 'Space-separated Varnish HTTP listener endpoints in host:port format. Examples: 127.0.0.1:82, varnish.internal:82, or varnish.example.com:8080. Public frontend endpoints such as domain.com:443 are blocked.',
					value: form.varnishCliServers || '',
					onChange: (value) => onFieldChange('varnishCliServers', value),
					disabled: busy || infrastructureLocked,
					placeholder: isAdminMode ? '127.0.0.1:6082' : '127.0.0.1:82',
					key: 'servers',
				}),
				h('div', { className: 'uc-field-wrap', key: 'key-wrap' }, [
					h(TextField, {
						label: isAdminMode ? 'Admin secret' : 'HTTP token / control key',
						description: secretExternal
							? 'Configured outside the UltraCache managed block in wp-config.php. It is read-only here.'
							: (secretConfigured ? 'Managed by UltraCache in wp-config.php. Enter a new value to replace it; the current value is never displayed.' : 'Enter a value to save ULTRACACHE_VARNISH_PASSWORD in the UltraCache managed wp-config.php block.'),
						value: form.varnishCliKey || '',
						onChange: (value) => {
							onFieldChange('varnishCliKey', value);
							if (value) {
								onFieldChange('clearVarnishCliKey', false);
							}
						},
						disabled: busy || infrastructureLocked || secretExternal,
						placeholder: secretExternal ? 'Externally configured' : (secretConfigured ? 'Leave blank to keep current value' : 'Enter password or token'),
						type: 'password',
						key: 'key-input',
					}),
					secretManaged ? h(Button, {
						onClick: () => onFieldChange('clearVarnishCliKey', !form.clearVarnishCliKey),
						disabled: busy || infrastructureLocked || secretExternal,
						variant: form.clearVarnishCliKey ? 'primary' : 'light',
						key: 'clear-key',
					}, form.clearVarnishCliKey ? 'Password will be removed on save' : 'Remove managed password') : null,
				]),
				h(SelectField, {
					label: __("Invalidation strategy", 'ultracache'),
					description: isAdminMode
						? __("Admin-secret mode always uses BAN. Soft purge requires an HTTP endpoint and verified stale/grace behavior.", 'ultracache')
						: __("Automatic uses verified soft purge when available and otherwise keeps the configured hard BAN/PURGE fallback. Soft purge is enabled only after Test Varnish proves stale/grace followed by a fresh HIT.", 'ultracache'),
					value: isAdminMode ? 'ban' : configuredStrategy,
					onChange: (value) => {
						onFieldChange('varnishInvalidationStrategy', value);
						if (value === 'ban' || value === 'purge') {
							onFieldChange('varnishCliMethod', value.toUpperCase());
						}
					},
					disabled: busy || infrastructureLocked,
					options: isAdminMode ? [
						{ value: 'ban', label: 'BAN' },
					] : [
						{ value: 'auto', label: __("Automatic", 'ultracache') },
						{ value: 'ban', label: 'BAN' },
						{ value: 'purge', label: 'PURGE' },
						{ value: 'soft', label: softSupported ? __("Soft purge + refill", 'ultracache') : __("Soft purge + refill — run behavior test first", 'ultracache'), disabled: !softSupported, title: softCapability.message || '' },
					],
					key: 'invalidation-strategy',
				}),
				h('div', { className: 'contents' }, renderFlushScopeControl({ state: flushScopeUi, busy, infrastructureLocked, onFieldChange })),
				h(NumberRow, {
					label: __("Varnish HTML TTL (minutes)", 'ultracache'),
					description: __("Default: 1440 minutes (24 hours). The value is sent as s-maxage for public UltraCache HTML cache responses; 0 leaves the lifetime to Varnish.", 'ultracache'),
					value: configuredHtmlTtlMinutes,
					onChange: (value) => onFieldChange('varnishHtmlTtlMinutes', value),
					disabled: busy || infrastructureLocked,
					min: 0,
					max: 525600,
					step: 1,
					key: 'html-ttl',
				}),
				h(NumberRow, {
					label: __("Serve stale while refreshing (seconds)", 'ultracache'),
					description: !softSupported
						? __("Run Test Varnish and verify soft purge with stale/grace delivery before enabling this window.", 'ultracache')
						: (configuredHtmlTtlMinutes <= 0
							? __("Set a positive Varnish HTML TTL before enabling stale-while-revalidate.", 'ultracache')
							: __("Adds stale-while-revalidate to public UltraCache HTML responses. 0 disables the directive.", 'ultracache')),
					value: configuredStaleWhileRevalidateSeconds,
					onChange: (value) => onFieldChange('varnishStaleWhileRevalidateSeconds', (!softSupported || configuredHtmlTtlMinutes <= 0) ? 0 : value),
					disabled: busy || infrastructureLocked || ((!softSupported || configuredHtmlTtlMinutes <= 0) && configuredStaleWhileRevalidateSeconds <= 0),
					min: 0,
					max: 86400,
					step: 1,
					key: 'stale-while-revalidate',
				}),
				h(NumberRow, {
					label: __("Timeout (seconds)", 'ultracache'),
					description: isAdminMode ? 'Connection and read timeout for each Varnish admin endpoint. Maximum: 15 seconds.' : 'Connection and read timeout for each Varnish HTTP endpoint. Maximum: 15 seconds.',
					value: form.varnishCliTimeoutSeconds || 2,
					onChange: (value) => onFieldChange('varnishCliTimeoutSeconds', value),
					disabled: busy || infrastructureLocked,
					min: 1,
					max: 15,
					step: 1,
					key: 'timeout',
				}),
			]),
			h(ToggleRow, {
				label: __("Refill affected pages after targeted invalidation", 'ultracache'),
				description: __("After a successful targeted Varnish invalidation, queue the same eligible URLs for UltraCache rebuild and a public Varnish refill. Processing uses WP-Cron and does not block the content-save request.", 'ultracache'),
				checked: !!form.varnishRefillAfterTargetedInvalidation,
				onChange: (value) => onFieldChange('varnishRefillAfterTargetedInvalidation', value),
				disabled: busy || infrastructureLocked,
			}),
			h(ToggleRow, {
				label: __("Warm Varnish during manual cache warm-up", 'ultracache'),
				description: __("After each successful dashboard warm request, send normal public requests for the active orig, WebP, and AVIF HTML variants so the same URL is also populated in Varnish.", 'ultracache'),
				checked: !!form.varnishWarmDuringManualWarmup,
				onChange: (value) => onFieldChange('varnishWarmDuringManualWarmup', value),
				disabled: busy || infrastructureLocked,
			}),
			h(ToggleRow, {
				label: __("Verify Varnish HIT after refill", 'ultracache'),
				description: __("After each public refill request, send one additional request for the same active HTML variant and verify a real Varnish HIT when visible headers allow it. Hidden headers are reported as Inconclusive and do not fail an otherwise successful refill.", 'ultracache'),
				checked: !!form.varnishVerifyRefillHit,
				onChange: (value) => onFieldChange('varnishVerifyRefillHit', value),
				disabled: busy || infrastructureLocked || (!form.varnishRefillAfterTargetedInvalidation && !form.varnishWarmDuringManualWarmup),
			}),
			h('div', { className: 'contents' }, renderRefreshAheadControls({ form, refreshAhead, busy, infrastructureLocked, onFieldChange })),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy || infrastructureLocked, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: () => { setStatusOpen(true); return onTest(); }, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : __("Test Varnish", 'ultracache')),
				h(Button, { onClick: onFlushAll, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : (effectiveFlushScope === 'html' ? __("Flush HTML Pages", 'ultracache') : __("Flush Varnish for This Site", 'ultracache'))),
				h(Button, { onClick: onFlushEntireHost, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : __("Flush Entire Varnish Host", 'ultracache')),
			]),
			h('p', { className: 'uc-stat-label mt-2 mb-0' }, __("The Varnish test temporarily invalidates and refills the public front page. It checks ETag and Last-Modified 304 responses, canonical orig, WebP, and AVIF Accept profiles, verifies HTML-only invalidation, and independently classifies whether public static files pass through Varnish, bypass it, or hide route signals.", 'ultracache')),
			h('details', { className: 'uc-accordion uc-accordion--card mt-5', open: !!statusOpen, key: 'varnish-status-accordion' }, [
				h('summary', { className: 'uc-accordion__summary', onClick: (event) => { event.preventDefault(); setStatusOpen((current) => !current); } }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, __("Varnish Status & Test Results", 'ultracache')),
						h('div', { className: 'uc-accordion__description' }, __("Connection, cache behavior, invalidation, refill, variants, and endpoint metrics.", 'ultracache')),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'uc-diagnostic-group' }, [
						h('div', { className: 'uc-section-title' }, __("Status", 'ultracache')),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Support", 'ultracache')),
						h(StatusPill, { ok: !!varnish.available, text: varnish.available ? 'Available' : 'Unavailable', tone: varnish.available ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Configured mode", 'ultracache')),
						h(StatusPill, { ok: true, text: modeLabel, tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Effective purge method", 'ultracache')),
						h(StatusPill, { ok: true, text: effectiveMethod, tone: effectiveStrategy === 'soft' ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Soft purge capability", 'ultracache')),
						h(StatusPill, { ok: softSupported, text: softSupported ? __("Verified", 'ultracache') : String(softCapability.status || __("Untested", 'ultracache')).replace(/-/g, ' '), tone: softSupported ? 'success' : 'warning' }),
					]),
					...renderFlushScopeStatusRows(flushScopeUi),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Stale while refreshing", 'ultracache')),
						h(StatusPill, {
							ok: !!staleWhileRevalidateStatus.observed,
							text: configuredStaleWhileRevalidateSeconds <= 0 ? __("Disabled", 'ultracache') : (!!staleWhileRevalidateStatus.observed ? __("Observed", 'ultracache') : String(staleWhileRevalidateStatus.status || __("Not verified", 'ultracache')).replace(/-/g, ' ')),
							tone: staleWhileRevalidateStatus.observed ? 'success' : (configuredStaleWhileRevalidateSeconds > 0 ? 'warning' : 'neutral'),
						}),
					]),
					h('div', { className: 'contents' }, renderRefreshAheadStatusRows(form, refreshAhead)),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Active HTML variants", 'ultracache')),
						h(StatusPill, { ok: true, text: activeHtmlBuckets.join(' / '), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Vary: Accept required", 'ultracache')),
						h(StatusPill, { ok: true, text: htmlVariantStatus.varyAcceptRequired ? __("Yes", 'ultracache') : __("No", 'ultracache'), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Varnish variant handling", 'ultracache')),
						h(StatusPill, {
							ok: variantTestStatus === 'compatible',
							text: variantTestStatus === 'compatible' ? __("Compatible", 'ultracache') : (variantTestStatus === 'fragmented' ? __("Fragmented", 'ultracache') : __("Not verified", 'ultracache')),
							tone: variantTestStatus === 'compatible' ? 'success' : (variantTestStatus === 'fragmented' ? 'warning' : 'neutral'),
						}),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Shared HTML TTL", 'ultracache')),
						h(StatusPill, { ok: configuredHtmlTtlMinutes > 0, text: configuredHtmlTtlMinutes > 0 ? (String(configuredHtmlTtlMinutes) + ' min') : __("VCL controlled", 'ultracache'), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Shared TTL behavior", 'ultracache')),
						h(StatusPill, {
							ok: htmlTtlBehaviorStatus === 'observed',
							text: htmlTtlBehaviorStatus === 'observed' ? __("Observed on HIT", 'ultracache') : (htmlTtlBehaviorStatus === 'header-missing' ? __("Header missing", 'ultracache') : (htmlTtlBehaviorStatus === 'disabled' ? __("Disabled", 'ultracache') : (htmlTtlBehaviorStatus === 'inactive' ? __("Inactive", 'ultracache') : __("Not verified", 'ultracache')))),
							tone: htmlTtlBehaviorStatus === 'observed' ? 'success' : (htmlTtlBehaviorStatus === 'header-missing' ? 'warning' : 'neutral'),
						}),
					]),
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("ETag validator", 'ultracache')),
						h(StatusPill, { ok: !!conditionalRevalidationTest.etagAvailable, text: conditionalRevalidationTest.etagAvailable ? __("Available", 'ultracache') : __("Not seen", 'ultracache'), tone: conditionalRevalidationTest.etagAvailable ? 'success' : 'neutral' }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last-Modified validator", 'ultracache')),
						h(StatusPill, { ok: !!conditionalRevalidationTest.lastModifiedAvailable, text: conditionalRevalidationTest.lastModifiedAvailable ? __("Available", 'ultracache') : __("Not seen", 'ultracache'), tone: conditionalRevalidationTest.lastModifiedAvailable ? 'success' : 'neutral' }),
					]) : null,
					behaviorResult && conditionalRevalidationTest.source ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Validator response source", 'ultracache')),
						h(StatusPill, { ok: true, text: String(conditionalRevalidationTest.source), tone: 'neutral' }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Conditional 304 revalidation", 'ultracache')),
						h(StatusPill, {
							ok: conditionalRevalidationStatus === 'observed',
							text: conditionalRevalidationStatus === 'observed' ? __("Observed", 'ultracache') : (conditionalRevalidationStatus === 'partial' ? __("Partial", 'ultracache') : (conditionalRevalidationStatus === 'unavailable' ? __("Validators unavailable", 'ultracache') : (conditionalRevalidationStatus === 'not-observed' ? __("Not observed", 'ultracache') : (conditionalRevalidationStatus === 'error' ? __("Error", 'ultracache') : __("Not tested", 'ultracache'))))),
							tone: conditionalRevalidationStatus === 'observed' ? 'success' : (conditionalRevalidationStatus === 'partial' || conditionalRevalidationStatus === 'not-observed' || conditionalRevalidationStatus === 'error' ? 'warning' : 'neutral'),
						}),
					]) : null,
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Two-stage origin refill", 'ultracache')),
						h(StatusPill, {
							ok: !!twoStageRefill.available,
							text: twoStageRefill.available ? __("Available", 'ultracache') : (twoStageRefillStatus === 'unavailable' ? __("Fallback", 'ultracache') : (twoStageRefillStatus === 'error' ? __("Error", 'ultracache') : (twoStageRefillStatus === 'configuration-changed' ? __("Retest required", 'ultracache') : (twoStageRefillStatus === 'untested' ? __("Untested", 'ultracache') : __("Inconclusive", 'ultracache'))))),
							tone: twoStageRefill.available ? 'success' : (twoStageRefillStatus === 'error' || twoStageRefillStatus === 'unavailable' ? 'warning' : 'neutral'),
						}),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Configured endpoints", 'ultracache')),
						h(StatusPill, { ok: endpointCount > 0, text: endpointCount > 0 ? (endpointCount + ' endpoint(s)') : '—', tone: endpointCount > 0 ? 'neutral' : 'warning' }),
					]),
					isAdminMode ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Admin secret", 'ultracache')),
						h(StatusPill, { ok: secretConfigured, text: secretConfigured ? 'Configured' : 'Missing', tone: secretConfigured ? 'success' : 'warning' }),
					]) : h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Admin-secret mode", 'ultracache')),
						h(StatusPill, { ok: false, text: __("Not used", 'ultracache'), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("HTTP endpoint mode", 'ultracache')),
						h(StatusPill, { ok: !isAdminMode, text: isAdminMode ? 'Not used' : 'Used', tone: 'neutral' }),
					]),
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Connection & authentication", 'ultracache')),
						h(StatusPill, {
							ok: !!behaviorResult.invalidationAccepted,
							text: !behaviorResult.invalidationAttempted ? __("Not tested", 'ultracache') : (behaviorResult.invalidationAccepted ? __("Verified", 'ultracache') : __("Failed", 'ultracache')),
							tone: !behaviorResult.invalidationAttempted ? 'neutral' : (behaviorResult.invalidationAccepted ? 'success' : 'warning'),
						}),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Invalidation command", 'ultracache')),
						h(StatusPill, {
							ok: !!behaviorResult.invalidationAccepted,
							text: String(behaviorResult.effectiveMethod || effectiveMethod) + (!behaviorResult.invalidationAttempted ? ' · not tested' : (behaviorResult.invalidationAccepted ? ' · accepted' : ' · failed')),
							tone: !behaviorResult.invalidationAttempted ? 'neutral' : (behaviorResult.invalidationAccepted ? 'success' : 'warning'),
						}),
					]) : null,
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last result", 'ultracache')),
						h(StatusPill, { ok: !!last.success, text: last.message || 'No Varnish action yet', tone: last.message ? (!!last.success ? 'success' : 'warning') : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Pending invalidations", 'ultracache')),
						h(StatusPill, { ok: Number(queueStats.pendingInvalidations || 0) === 0, text: String(queueStats.pendingInvalidations || 0), tone: Number(queueStats.pendingInvalidations || 0) > 0 ? 'warning' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Pending refills", 'ultracache')),
						h(StatusPill, { ok: Number(queueStats.pendingRefills || 0) === 0, text: String(queueStats.pendingRefills || 0), tone: Number(queueStats.pendingRefills || 0) > 0 ? 'warning' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Refill HIT verification", 'ultracache')),
						h(StatusPill, { ok: !!form.varnishVerifyRefillHit, text: form.varnishVerifyRefillHit ? __("Enabled", 'ultracache') : __("Disabled", 'ultracache'), tone: form.varnishVerifyRefillHit ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Failed queue jobs", 'ultracache')),
						h(StatusPill, { ok: Number(queueStats.failed || 0) === 0, text: String(queueStats.failed || 0), tone: Number(queueStats.failed || 0) > 0 ? 'warning' : 'neutral' }),
					]),
					h('div', { className: 'contents' }, renderMetricsStatusRows(varnishMetricsStatus, queueStats, isAdminMode)),
					queuedRefillResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last queued refill", 'ultracache')),
						h(StatusPill, { ok: Number(queuedRefillResult.refillFailureCount || 0) === 0, text: sprintf(__('%1$d warmed · %2$d failed', 'ultracache'), Number(queuedRefillResult.refillSuccessCount || 0), Number(queuedRefillResult.refillFailureCount || 0)), tone: Number(queuedRefillResult.refillFailureCount || 0) > 0 ? 'warning' : 'neutral' }),
					]) : null,
					queuedRefillResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last refill origin stage", 'ultracache')),
						h(StatusPill, {
							ok: Number(queuedRefillResult.refillTwoStageAvailableCount || 0) > 0 && Number(queuedRefillResult.refillTwoStageFallbackCount || 0) === 0 && Number(queuedRefillResult.refillTwoStageInconclusiveCount || 0) === 0 && Number(queuedRefillResult.refillTwoStageErrorCount || 0) === 0,
							text: sprintf(__('%1$d two-stage · %2$d fallback · %3$d inconclusive/error', 'ultracache'), Number(queuedRefillResult.refillTwoStageAvailableCount || 0), Number(queuedRefillResult.refillTwoStageFallbackCount || 0), Number(queuedRefillResult.refillTwoStageInconclusiveCount || 0) + Number(queuedRefillResult.refillTwoStageErrorCount || 0)),
							tone: Number(queuedRefillResult.refillTwoStageFallbackCount || 0) > 0 || Number(queuedRefillResult.refillTwoStageInconclusiveCount || 0) > 0 || Number(queuedRefillResult.refillTwoStageErrorCount || 0) > 0 ? 'warning' : 'success',
						}),
					]) : null,
					queuedRefillResult && form.varnishVerifyRefillHit ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last refill HIT verification", 'ultracache')),
						h(StatusPill, {
							ok: Number(queuedRefillResult.refillVerifiedCount || 0) > 0 && Number(queuedRefillResult.refillBypassedCount || 0) === 0 && Number(queuedRefillResult.refillInconclusiveCount || 0) === 0 && Number(queuedRefillResult.refillNotHitCount || 0) === 0 && Number(queuedRefillResult.refillVerificationErrorCount || 0) === 0,
							text: sprintf(__('%1$d verified · %2$d bypassed · %3$d inconclusive/not-hit', 'ultracache'), Number(queuedRefillResult.refillVerifiedCount || 0), Number(queuedRefillResult.refillBypassedCount || 0), Number(queuedRefillResult.refillInconclusiveCount || 0) + Number(queuedRefillResult.refillNotHitCount || 0) + Number(queuedRefillResult.refillVerificationErrorCount || 0)),
							tone: Number(queuedRefillResult.refillBypassedCount || 0) > 0 || Number(queuedRefillResult.refillInconclusiveCount || 0) > 0 || Number(queuedRefillResult.refillNotHitCount || 0) > 0 || Number(queuedRefillResult.refillVerificationErrorCount || 0) > 0 ? 'warning' : 'success',
						}),
					]) : null,
					batchResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Invalidation URLs", 'ultracache')),
						h(StatusPill, { ok: Number(batchResult.uniqueUrlCount || 0) > 0, text: sprintf(__('%1$d unique · %2$d received', 'ultracache'), Number(batchResult.uniqueUrlCount || 0), Number(batchResult.receivedUrlCount || 0)), tone: Number(batchResult.uniqueUrlCount || 0) > 0 ? 'neutral' : 'warning' }),
					]) : null,
					batchResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Duplicates removed", 'ultracache')),
						h(StatusPill, { ok: true, text: String(batchResult.duplicateUrlCount || 0), tone: 'neutral' }),
					]) : null,
					batchResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Rejected URLs", 'ultracache')),
						h(StatusPill, { ok: Number(batchResult.rejectedUrlCount || 0) === 0, text: String(batchResult.rejectedUrlCount || 0), tone: Number(batchResult.rejectedUrlCount || 0) === 0 ? 'neutral' : 'warning' }),
					]) : null,
					batchResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, queuedInvalidationResult ? __("Queued invalidations", 'ultracache') : __("Invalidation requests", 'ultracache')),
						h(StatusPill, {
							ok: !!batchResult.success,
							text: queuedInvalidationResult
								? sprintf(__('%1$d URL(s) · approximately %2$d endpoint request(s)', 'ultracache'), Number(batchResult.queuedUrlCount || batchResult.uniqueUrlCount || 0), Number(batchResult.estimatedRequestCount || 0))
								: sprintf(__('%1$d request(s) · %2$d batch(es)', 'ultracache'), Number(batchResult.requestCount || 0), Number(batchResult.batchCount || 0)),
							tone: batchResult.success ? 'neutral' : 'warning',
						}),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Cache behavior", 'ultracache')),
						h(StatusPill, { ok: !!behaviorResult.verified, text: behaviorResult.verified ? __("Verified", 'ultracache') : (behaviorResult.status === 'bypassed' ? __("Bypassed", 'ultracache') : __("Inconclusive", 'ultracache')), tone: behaviorResult.verified ? 'success' : 'warning' }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("First request", 'ultracache')),
						h(StatusPill, { ok: String((behaviorSteps.first || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep(behaviorSteps.first), tone: getVarnishBehaviorStepTone(behaviorSteps.first) }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Second request", 'ultracache')),
						h(StatusPill, { ok: String((behaviorSteps.second || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep(behaviorSteps.second), tone: getVarnishBehaviorStepTone(behaviorSteps.second) }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("After invalidation", 'ultracache')),
						h(StatusPill, { ok: ['MISS', 'STALE'].indexOf(String((behaviorSteps.afterInvalidation || {}).status || '').toUpperCase()) !== -1, text: formatVarnishBehaviorStep(behaviorSteps.afterInvalidation), tone: getVarnishBehaviorStepTone(behaviorSteps.afterInvalidation) }),
					]) : null,
					behaviorResult ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Refill verification", 'ultracache')),
						h(StatusPill, { ok: String((behaviorSteps.verification || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep(behaviorSteps.verification), tone: getVarnishBehaviorStepTone(behaviorSteps.verification) }),
					]) : null,
					behaviorResult && conditionalRevalidationTest.etagStep && Object.keys(conditionalRevalidationTest.etagStep).length ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("ETag conditional request", 'ultracache')),
						h(StatusPill, { ok: Number(conditionalRevalidationTest.etagStep.httpCode || 0) === 304 && Number(conditionalRevalidationTest.etagStep.bodyBytes || 0) === 0, text: formatVarnishBehaviorStep(conditionalRevalidationTest.etagStep), tone: Number(conditionalRevalidationTest.etagStep.httpCode || 0) === 304 ? 'success' : getVarnishBehaviorStepTone(conditionalRevalidationTest.etagStep) }),
					]) : null,
					behaviorResult && conditionalRevalidationTest.lastModifiedStep && Object.keys(conditionalRevalidationTest.lastModifiedStep).length ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Last-Modified conditional request", 'ultracache')),
						h(StatusPill, { ok: Number(conditionalRevalidationTest.lastModifiedStep.httpCode || 0) === 304 && Number(conditionalRevalidationTest.lastModifiedStep.bodyBytes || 0) === 0, text: formatVarnishBehaviorStep(conditionalRevalidationTest.lastModifiedStep), tone: Number(conditionalRevalidationTest.lastModifiedStep.httpCode || 0) === 304 ? 'success' : getVarnishBehaviorStepTone(conditionalRevalidationTest.lastModifiedStep) }),
					]) : null,
					behaviorResult && Object.keys(htmlFlushSteps).length ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Static asset before HTML flush", 'ultracache')),
						h(StatusPill, { ok: String((htmlFlushSteps.assetSecond || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep(htmlFlushSteps.assetSecond), tone: getVarnishBehaviorStepTone(htmlFlushSteps.assetSecond) }),
					]) : null,
					behaviorResult && Object.keys(htmlFlushSteps).length ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("HTML after HTML-only flush", 'ultracache')),
						h(StatusPill, { ok: ['MISS', 'STALE'].indexOf(String((htmlFlushSteps.pageAfterHtmlFlush || {}).status || '').toUpperCase()) !== -1, text: formatVarnishBehaviorStep(htmlFlushSteps.pageAfterHtmlFlush), tone: getVarnishBehaviorStepTone(htmlFlushSteps.pageAfterHtmlFlush) }),
					]) : null,
					behaviorResult && Object.keys(htmlFlushSteps).length ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Static asset after HTML flush", 'ultracache')),
						h(StatusPill, { ok: String((htmlFlushSteps.assetAfterHtmlFlush || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep(htmlFlushSteps.assetAfterHtmlFlush), tone: getVarnishBehaviorStepTone(htmlFlushSteps.assetAfterHtmlFlush) }),
					]) : null,
					behaviorResult && behaviorResult.softPurgeSteps ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("After soft purge", 'ultracache')),
						h(StatusPill, { ok: String(((behaviorResult.softPurgeSteps || {}).afterSoftPurge || {}).status || '').toUpperCase() === 'STALE', text: formatVarnishBehaviorStep((behaviorResult.softPurgeSteps || {}).afterSoftPurge), tone: getVarnishBehaviorStepTone((behaviorResult.softPurgeSteps || {}).afterSoftPurge) }),
					]) : null,
					behaviorResult && behaviorResult.softPurgeSteps ? h('div', { className: 'flex items-center justify-between gap-4 py-2' }, [
						h('div', { className: 'text-sm text-white' }, __("Soft purge refill verification", 'ultracache')),
						h(StatusPill, { ok: String(((behaviorResult.softPurgeSteps || {}).verification || {}).status || '').toUpperCase() === 'HIT', text: formatVarnishBehaviorStep((behaviorResult.softPurgeSteps || {}).verification), tone: getVarnishBehaviorStepTone((behaviorResult.softPurgeSteps || {}).verification) }),
					]) : null,
				]),
				...renderFlushScopeMessages(flushScopeUi),
				softCapability.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(softCapability.message)) : null,
				htmlVariantStatus.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(htmlVariantStatus.message)) : null,
				htmlTtlStatus.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(htmlTtlStatus.message)) : null,
				staleWhileRevalidateStatus.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(staleWhileRevalidateStatus.message)) : null,
				...renderRefreshAheadMessages(refreshAhead),
				...renderMetricsMessages(varnishMetricsStatus),
				conditionalRevalidationTest.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(conditionalRevalidationTest.message)) : null,
				twoStageRefill.message ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(twoStageRefill.message)) : null,
				variantTest.message && variantTestStatus !== 'not-tested' && variantTestStatus !== 'not-run' ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, String(variantTest.message)) : null,
				supportMessage ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, supportMessage) : null,
				last.time ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, 'Last run: ' + formatLooseTime(last.time)) : null,
						detailLines ? h('div', { className: 'text-xs text-zinc-400 mt-3 whitespace-pre-wrap break-all' }, detailLines) : null,
					]),
				]),
			]),
		]);
	}

	function createController(context) {
		const source = context && typeof context === 'object' ? context : {};
		const {
			varnishForm,
			setVarnishForm,
			queueSettingsPatch,
			enqueueUiOperation,
			saveSettingsPatch,
			applyDashboardPayload,
			mergeVarnishTestResult,
			queueDashboardAction,
		} = source;

		function updateVarnishField(key, value) {
			setVarnishForm((current) => {
				const next = Object.assign({}, current || {}, { [key]: value });
				if (key === 'varnishCliMode' && isDefaultVarnishServersValue(current && current.varnishCliServers)) {
					next.varnishCliServers = getDefaultVarnishServersForMode(value);
				}
				return next;
			});

			if (key === 'varnishCliEnabled') {
				queueSettingsPatch({ [key]: !!value });
			}
		}

		async function saveVarnishSettings() {
			return enqueueUiOperation('varnish_settings_save', 'Save Varnish settings', async () => {
				const form = Object.assign({}, varnishForm || {});
				const patch = {
					varnishCliEnabled: !!form.varnishCliEnabled,
					varnishCliMode: form.varnishCliMode || 'http',
					varnishCliServers: form.varnishCliServers || '',
					varnishCliTimeoutSeconds: form.varnishCliTimeoutSeconds,
					varnishCliMethod: form.varnishCliMethod || 'BAN',
					varnishInvalidationStrategy: (form.varnishCliMode || 'http') === 'admin' ? 'ban' : (form.varnishInvalidationStrategy || String(form.varnishCliMethod || 'BAN').toLowerCase()),
					varnishFlushScope: form.varnishFlushScope || 'auto',
					varnishHtmlTtlMinutes: Number.isFinite(Number(form.varnishHtmlTtlMinutes)) ? Math.max(0, Math.min(525600, Number(form.varnishHtmlTtlMinutes))) : 1440,
					varnishStaleWhileRevalidateSeconds: Number.isFinite(Number(form.varnishStaleWhileRevalidateSeconds)) ? Math.max(0, Math.min(86400, Number(form.varnishStaleWhileRevalidateSeconds))) : 0,
					varnishRefillAfterTargetedInvalidation: !!form.varnishRefillAfterTargetedInvalidation,
					varnishWarmDuringManualWarmup: !!form.varnishWarmDuringManualWarmup,
					varnishVerifyRefillHit: !!form.varnishVerifyRefillHit,
					varnishRefreshAheadEnabled: !!form.varnishRefreshAheadEnabled,
					varnishRefreshAheadThresholdPercent: Number.isFinite(Number(form.varnishRefreshAheadThresholdPercent)) ? Math.max(50, Math.min(95, Number(form.varnishRefreshAheadThresholdPercent))) : 85,
					varnishRefreshAheadMaxPages: Number.isFinite(Number(form.varnishRefreshAheadMaxPages)) ? Math.max(1, Math.min(10, Number(form.varnishRefreshAheadMaxPages))) : 5,
				};
				if (form.varnishCliKey) {
					patch.varnishCliKey = form.varnishCliKey;
				}
				if (form.clearVarnishCliKey) {
					patch.clearVarnishCliKey = true;
				}
				const response = await saveSettingsPatch(patch);
				return response;
			}, { processingText: 'Processing Varnish settings save…', successText: 'Varnish settings saved.', failedText: 'Failed to save Varnish settings.' });
		}

		async function runVarnishTest() {
			return enqueueUiOperation('varnish_test', 'Test Varnish', async () => {
				try {
					const response = await apiRequest('varnish_behavior_test', {});
					applyDashboardPayload(response || {});
					mergeVarnishTestResult(response || {});
					if (response && response.success === false) {
						throw new Error(formatVarnishResultMessage(response, 'Varnish test failed.'));
					}
					return response;
				} catch (error) {
					const payload = error && error.data && typeof error.data === 'object' ? error.data : null;
					if (payload) {
						applyDashboardPayload(payload || {});
						mergeVarnishTestResult(payload || {});
						const detailedError = new Error(formatVarnishResultMessage(payload, error && error.message ? error.message : 'Varnish test failed.'));
						detailedError.data = payload;
						detailedError.rest = error.rest || null;
						throw detailedError;
					}
					throw error;
				}
			}, { processingText: 'Testing Varnish connection, invalidation, and public cache behavior…', successText: (result) => {
				const messages = [];
				if (result && result.message) {
					messages.push(String(result.message));
				}
				if (result && result.htmlFlushCapability && result.htmlFlushCapability.message) {
					messages.push(String(result.htmlFlushCapability.message));
				}
				return messages.length ? messages.join(' ') : 'Varnish test completed.';
			}, failedText: 'Varnish test failed.' });
		}

		async function runVarnishFlushAll() {
			await queueDashboardAction('varnish_flush_all', { scope: 'configured' }, {
				queued: 'Varnish site flush processing via dashboard…',
				success: 'Varnish site flush finished.',
				failed: 'Varnish site flush failed.',
			}, 'varnish_flush_all');
		}

		async function runVarnishFlushEntireHost() {
			await queueDashboardAction('varnish_flush_all', { scope: 'entire-host' }, {
				queued: 'Varnish entire-host flush processing via dashboard…',
				success: 'Varnish entire-host flush finished.',
				failed: 'Varnish entire-host flush failed.',
			}, 'varnish_flush_entire_host');
		}

		return { updateVarnishField, saveVarnishSettings, runVarnishTest, runVarnishFlushAll, runVarnishFlushEntireHost };
	}

	admin.define('varnish', {
		getDefaultVarnishServersForMode,
		isDefaultVarnishServersValue,
		formatVarnishResultDetailLines,
		formatVarnishResultMessage,
		getVarnishBehaviorStepTone,
		formatVarnishBehaviorStep,
		VarnishCard,
		createController,
	});
})(window);
