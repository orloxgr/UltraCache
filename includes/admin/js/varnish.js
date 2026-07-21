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
	const varnishFlushScope = admin.get('varnishFlushScope');
	if (!core || !api || !ui || !cacheShared || !varnishRefreshAhead || !varnishFlushScope) {
		throw new Error('UltraCache admin Varnish modules are required before varnish.js.');
	}

	const { h, __, sprintf } = core;
	const { apiRequest } = api;
	const { CacheHelperConflictNotice } = cacheShared;
	const { renderControls: renderRefreshAheadControls } = varnishRefreshAhead;
	const { getState: getFlushScopeState, renderControl: renderFlushScopeControl } = varnishFlushScope;
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

	function renderCompactStatusRow(label, text, tone, ok, key) {
		return h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5 last:border-b-0', key }, [
			h('div', { className: 'text-sm text-white' }, label),
			h(StatusPill, { ok: !!ok, text: String(text || '—'), tone: tone || 'neutral' }),
		]);
	}

	function getBasicTestLabel(result) {
		const status = String(result && result.status ? result.status : '').toLowerCase();
		if (!status) {
			return __('Not tested', 'ultracache');
		}
		if (status === 'working') {
			return __('Working', 'ultracache');
		}
		if (status === 'working-signals-hidden') {
			return __('Working, signals hidden', 'ultracache');
		}
		if (status === 'authentication-failed') {
			return __('Authentication failed', 'ultracache');
		}
		if (status === 'invalidation-failed') {
			return __('Invalidation failed', 'ultracache');
		}
		if (status === 'refill-failed') {
			return __('Refill failed', 'ultracache');
		}
		if (status === 'configuration-incomplete') {
			return __('Configuration incomplete', 'ultracache');
		}
		if (status === 'configuration-changed') {
			return __('Run test again', 'ultracache');
		}
		return status.replace(/-/g, ' ');
	}

	function renderWarningSummary(items) {
		return (Array.isArray(items) ? items : []).filter((item) => item && item.message).map((item) => h('div', {
			className: 'text-xs text-amber-200 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2',
			key: 'varnish-warning-' + String(item.category || 'general'),
		}, [
			h('strong', { className: 'text-amber-100' }, String(item.label || __('Warning', 'ultracache')) + ': '),
			String(item.message),
		]));
	}

	function VarnishCard({ form, diagnostics, busy, canManageInfrastructure, onFieldChange, onSave, onTest, onFlushAll, onFlushEntireHost, onRemoveConflictingDropins, onRecheckConflicts }) {
		const varnish = diagnostics.varnish || {};
		const infrastructureLocked = !canManageInfrastructure;
		const last = varnish.last || {};
		const storedBasicResult = varnish.basicTest && typeof varnish.basicTest === 'object' ? varnish.basicTest : null;
		const legacyBasicResult = last && ['basic', 'behavior'].indexOf(String(last.testType || '')) !== -1 ? last : null;
		const behaviorResult = storedBasicResult || legacyBasicResult;
		const behaviorResultCurrent = !!(behaviorResult && !behaviorResult.configurationChanged && String(behaviorResult.status || '') !== 'configuration-changed');
		const behaviorConnectionTested = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.connectionTested || behaviorResult.invalidationAttempted || behaviorResult.invalidationAccepted || behaviorResult.verified));
		const behaviorConnectionVerified = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.connectionVerified || behaviorResult.invalidationAccepted || behaviorResult.invalidationVerified || behaviorResult.verified));
		const behaviorInvalidationAttempted = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.invalidationAttempted || behaviorResult.invalidationAccepted || behaviorResult.invalidationVerified || behaviorResult.verified));
		const behaviorInvalidationAccepted = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.invalidationAccepted || behaviorResult.invalidationVerified || behaviorResult.verified));
		const productionInvalidationTypes = ['direct-invalidation', 'batch-invalidation', 'queued-invalidation', 'site-flush'];
		const batchResult = last && productionInvalidationTypes.indexOf(String(last.operationType || '')) !== -1 ? last : null;
		const queueStats = varnish.queue && typeof varnish.queue === 'object' ? varnish.queue : {};
		const refillWorker = queueStats.refillWorker && typeof queueStats.refillWorker === 'object' ? queueStats.refillWorker : {};
		const refillWorkerStatus = String(refillWorker.status || 'idle').toLowerCase();
		const varnishMetricsStatus = varnish.metrics && typeof varnish.metrics === 'object' ? varnish.metrics : {};
		const flushScopeStatus = varnish.flushScope && typeof varnish.flushScope === 'object' ? varnish.flushScope : {};
		const flushScopeUi = getFlushScopeState(form, flushScopeStatus);
		const configuredFlushScope = flushScopeUi.configured;
		const effectiveFlushScope = flushScopeUi.effective;
		const refreshAhead = varnish.refreshAhead && typeof varnish.refreshAhead === 'object' ? varnish.refreshAhead : {};
		const configuredHtmlTtlValue = typeof form.varnishHtmlTtlMinutes === 'undefined' ? 1440 : Number(form.varnishHtmlTtlMinutes);
		const configuredHtmlTtlMinutes = Number.isFinite(configuredHtmlTtlValue) ? Math.max(0, Math.min(525600, configuredHtmlTtlValue)) : 0;
		const configuredStaleValue = Number(form.varnishStaleWhileRevalidateSeconds || 0);
		const configuredStaleWhileRevalidateSeconds = Number.isFinite(configuredStaleValue) ? Math.max(0, Math.min(86400, configuredStaleValue)) : 0;
		const supportMessage = varnish.message || '';
		const endpointDiagnostics = varnish.endpointDiagnostics || {};
		const endpointWarningMessages = Array.isArray(endpointDiagnostics.messages) ? endpointDiagnostics.messages : (varnish.unsafeEndpointMessage ? [varnish.unsafeEndpointMessage] : []);
		const hasUnsafeEndpoints = !!varnish.hasUnsafeEndpoints || !!endpointDiagnostics.unsafe;
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

		const queuePending = Number(queueStats.pending || 0);
		const queueProcessing = Number(queueStats.processing || 0);
		const queueRetrying = Number(queueStats.retrying || 0);
		const queueTerminal = Number(queueStats.terminalErrors || queueStats.failed || 0);
		const refillEnabled = !!form.varnishRefillAfterTargetedInvalidation || !!form.varnishWarmDuringManualWarmup;
		const refillFailures = Number((((varnishMetricsStatus.operations || {}).refillFailures) || 0));
		const basicTestLabel = getBasicTestLabel(behaviorResult);
		const basicTestTone = behaviorResultCurrent && behaviorResult && behaviorResult.success ? 'success' : (behaviorResult ? 'warning' : 'neutral');
		const connectionLabel = !form.varnishCliEnabled
			? __('Disabled', 'ultracache')
			: (!behaviorConnectionTested ? __('Not tested', 'ultracache') : (behaviorConnectionVerified ? __('Verified', 'ultracache') : __('Failed', 'ultracache')));
		const connectionTone = !form.varnishCliEnabled || !behaviorConnectionTested ? 'neutral' : (behaviorConnectionVerified ? 'success' : 'warning');
		const productionInvalidationResult = batchResult && batchResult.message ? batchResult : null;
		const lastInvalidationLabel = productionInvalidationResult
			? String(productionInvalidationResult.message)
			: __('No recent production result', 'ultracache');
		const lastInvalidationTone = productionInvalidationResult
			? (productionInvalidationResult.success && !productionInvalidationResult.partial ? 'success' : 'warning')
			: 'neutral';
		const refillLabel = !refillEnabled
			? __('Disabled', 'ultracache')
			: (refillFailures > 0 ? sprintf(__('%d failed', 'ultracache'), refillFailures) : __('Enabled', 'ultracache'));
		const queueLabel = sprintf(__('%1$d pending · %2$d processing · %3$d retrying · %4$d errors', 'ultracache'), queuePending, queueProcessing, queueRetrying, queueTerminal);
		const lastError = queueTerminal > 0
			? sprintf(__('%d terminal queue error(s)', 'ultracache'), queueTerminal)
			: (productionInvalidationResult && !productionInvalidationResult.success ? String(productionInvalidationResult.message || __('Invalidation failed', 'ultracache')) : '');
		const warningItems = [];
		if (!varnish.available || !form.varnishCliEnabled || endpointCount < 1 || (isAdminMode && !secretConfigured) || actionsBlocked) {
			warningItems.push({ category: 'configuration', label: __('Configuration', 'ultracache'), message: !varnish.available ? (supportMessage || __('Varnish is unavailable.', 'ultracache')) : (!form.varnishCliEnabled ? __('Varnish integration is disabled.', 'ultracache') : (actionsBlocked ? __('The configured endpoint is blocked by the endpoint guard.', 'ultracache') : (endpointCount < 1 ? __('No Varnish endpoint is configured.', 'ultracache') : __('The admin secret is missing.', 'ultracache')))) });
		}
		if (behaviorConnectionTested && !behaviorConnectionVerified) {
			warningItems.push({ category: 'transport', label: __('Transport', 'ultracache'), message: String(behaviorResult.message || __('Connection or authentication failed.', 'ultracache')) });
		}
		if (behaviorConnectionVerified && behaviorInvalidationAttempted && !behaviorInvalidationAccepted) {
			warningItems.push({ category: 'invalidation', label: __('Invalidation', 'ultracache'), message: String(behaviorResult.message || __('The exact invalidation request failed.', 'ultracache')) });
		}
		if ((behaviorResult && behaviorInvalidationAccepted && String(behaviorResult.status || '') === 'refill-failed') || refillFailures > 0) {
			warningItems.push({ category: 'refill', label: __('Refill', 'ultracache'), message: behaviorResult && String(behaviorResult.status || '') === 'refill-failed' ? String(behaviorResult.message || __('The public refill failed.', 'ultracache')) : sprintf(__('%d production refill(s) failed.', 'ultracache'), refillFailures) });
		}
		if (queueTerminal > 0 || (queuePending > 0 && queueProcessing === 0 && ['scheduled', 'recovered'].indexOf(refillWorkerStatus) === -1)) {
			warningItems.push({ category: 'queue', label: __('Queue', 'ultracache'), message: queueTerminal > 0 ? sprintf(__('%d terminal queue error(s) require attention.', 'ultracache'), queueTerminal) : __('Pending work has no active or scheduled worker.', 'ultracache') });
		}

		const compactStatusRows = [
			renderCompactStatusRow(__('Support', 'ultracache'), varnish.available ? __('Available', 'ultracache') : __('Unavailable', 'ultracache'), varnish.available ? 'success' : 'neutral', !!varnish.available, 'compact-support'),
			renderCompactStatusRow(__('Mode', 'ultracache'), modeLabel, 'neutral', true, 'compact-mode'),
			renderCompactStatusRow(__('Connection', 'ultracache'), connectionLabel, connectionTone, behaviorConnectionVerified, 'compact-connection'),
			renderCompactStatusRow(__('Purge method', 'ultracache'), effectiveMethod, effectiveStrategy === 'soft' ? 'success' : 'neutral', true, 'compact-method'),
			renderCompactStatusRow(__('Flush scope', 'ultracache'), flushScopeUi.effectiveLabel, 'neutral', true, 'compact-scope'),
			renderCompactStatusRow(__('Last invalidation', 'ultracache'), lastInvalidationLabel, lastInvalidationTone, !!(productionInvalidationResult && productionInvalidationResult.success), 'compact-invalidation'),
			renderCompactStatusRow(__('Refill', 'ultracache'), refillLabel, refillFailures > 0 ? 'warning' : (refillEnabled ? 'success' : 'neutral'), refillEnabled && refillFailures === 0, 'compact-refill'),
			renderCompactStatusRow(__('Queue', 'ultracache'), queueLabel, queueTerminal > 0 || queueRetrying > 0 ? 'warning' : 'neutral', queueTerminal === 0, 'compact-queue'),
			lastError ? renderCompactStatusRow(__('Last error', 'ultracache'), lastError, 'warning', false, 'compact-error') : null,
			renderCompactStatusRow(__('Last basic test', 'ultracache'), basicTestLabel, basicTestTone, !!(behaviorResultCurrent && behaviorResult && behaviorResult.success), 'compact-basic-test'),
		].filter(Boolean);

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
					description: isAdminMode ? 'Space-separated Varnish admin endpoints in host:port format. Example: 127.0.0.1:6082' : 'Space-separated Varnish HTTP listener endpoints. Host:port defaults to HTTP; explicit http:// and https:// schemes are preserved. Examples: 127.0.0.1:82, http://varnish.internal:82, or https://varnish.example.com:443. Public WordPress frontend endpoints are blocked.',
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
						: __("Automatic uses soft purge when the configured HTTP capability is active and otherwise keeps the hard BAN/PURGE fallback.", 'ultracache'),
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
						{ value: 'soft', label: softSupported ? __("Soft purge + refill", 'ultracache') : __("Soft purge + refill — unavailable in current configuration", 'ultracache'), disabled: !softSupported, title: softCapability.message || '' },
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
						? __("Stale-while-revalidate requires an active HTTP soft-purge capability.", 'ultracache')
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
				label: __("Warm Varnish with site warm-up", 'ultracache'),
				description: __("For dashboard, cron, and warm-after-flush jobs, complete the active orig, WebP, and AVIF Varnish variants for each page inside the same site warm-up pipeline.", 'ultracache'),
				checked: !!form.varnishWarmDuringManualWarmup,
				onChange: (value) => onFieldChange('varnishWarmDuringManualWarmup', value),
				disabled: busy || infrastructureLocked,
			}),
			h('div', { className: 'contents' }, renderRefreshAheadControls({ form, refreshAhead, busy, infrastructureLocked, onFieldChange })),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy || infrastructureLocked, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: onTest, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : __("Test Varnish", 'ultracache')),
				h(Button, { onClick: onFlushAll, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : (effectiveFlushScope === 'html' ? __("Flush HTML Pages", 'ultracache') : __("Flush Varnish for This Site", 'ultracache'))),
				h(Button, { onClick: onFlushEntireHost, disabled: busy || infrastructureLocked || !form.varnishCliEnabled || !varnish.available || actionsBlocked, variant: 'light' }, busy ? 'Working…' : __("Flush Entire Varnish Host", 'ultracache')),
			]),
			h('p', { className: 'uc-stat-label mt-2 mb-0' }, __("Test Varnish checks the configured connection, one exact front-page invalidation, and the public refill.", 'ultracache')),

			h('div', { className: 'uc-diagnostic-group mt-5', key: 'varnish-compact-status' }, [
				h('div', { className: 'uc-section-title' }, __('Varnish status', 'ultracache')),
				h('div', { className: 'grid grid-cols-1 lg:grid-cols-2 gap-x-6' }, compactStatusRows),
				warningItems.length ? h('div', { className: 'space-y-2 mt-4' }, renderWarningSummary(warningItems)) : null,
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
					varnishRefreshAheadEnabled: !!form.varnishRefreshAheadEnabled,
					varnishRefreshAheadThresholdPercent: Number.isFinite(Number(form.varnishRefreshAheadThresholdPercent)) ? Math.max(50, Math.min(95, Number(form.varnishRefreshAheadThresholdPercent))) : 85,
					varnishRefreshAheadMaxPages: Number.isFinite(Number(form.varnishRefreshAheadMaxPages)) ? Math.max(1, Math.min(10, Number(form.varnishRefreshAheadMaxPages))) : 5,
					varnishRefreshAheadPinnedUrls: String(form.varnishRefreshAheadPinnedUrls || ''),
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
			}, { processingText: 'Testing Varnish connection, exact invalidation, and public refill…', successText: (result) => {
				return result && result.message ? String(result.message) : 'Varnish test completed.';
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
		VarnishCard,
		createController,
	});
})(window);
