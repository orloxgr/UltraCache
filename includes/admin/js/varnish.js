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
	const varnishFlushScope = admin.get('varnishFlushScope');
	if (!core || !api || !ui || !cacheShared || !varnishFlushScope) {
		throw new Error('UltraCache admin Varnish modules are required before varnish.js.');
	}

	const { h, __, sprintf, formatNumber, formatBytes } = core;
	const { apiRequest } = api;
	const { CacheHelperConflictNotice } = cacheShared;
	const { getState: getFlushScopeState, renderControl: renderFlushScopeControl } = varnishFlushScope;
	const { IntegrationAccordionCard, Button, TextField, NumberRow, SelectField, StatusPill, DetailRow } = ui;

	function getDefaultVarnishServersForMode(mode) {
		return String(mode || 'http') === 'admin' ? '127.0.0.1:6082' : '127.0.0.1:82';
	}

	function normalizeVarnishComparableValue(key, value) {
		if (['varnishConnectionConfigured'].indexOf(key) !== -1) {
			return !!value;
		}
		if (['varnishCliTimeoutSeconds', 'varnishInvalidationsPerMinute'].indexOf(key) !== -1) {
			return Number(value || 0);
		}
		const text = String(value == null ? '' : value).replace(/\r\n?/g, '\n').trim();
		if (key === 'varnishCliServers') {
			return text.split(/\s+/).filter(Boolean).join(' ');
		}
		if (key === 'varnishCliMethod') {
			return text.toUpperCase();
		}
		if (['varnishCliMode', 'varnishInvalidationStrategy', 'varnishFlushScope'].indexOf(key) !== -1) {
			return text.toLowerCase();
		}
		return text;
	}

	function isVarnishFormDirty(form, settings) {
		const current = form && typeof form === 'object' ? form : {};
		const saved = settings && typeof settings === 'object' ? settings : {};
		const savedMode = saved.varnishCliMode || 'http';
		const expected = {
			varnishCliMode: savedMode,
			varnishCliServers: saved.varnishCliServers || getDefaultVarnishServersForMode(savedMode),
			varnishCliTimeoutSeconds: saved.varnishCliTimeoutSeconds || 2,
			varnishInvalidationsPerMinute: typeof saved.varnishInvalidationsPerMinute === 'undefined' ? 10 : saved.varnishInvalidationsPerMinute,
		};
		const fields = Object.keys(expected);
		for (let index = 0; index < fields.length; index += 1) {
			const key = fields[index];
			if (normalizeVarnishComparableValue(key, current[key]) !== normalizeVarnishComparableValue(key, expected[key])) {
				return true;
			}
		}
		return !!String(current.varnishCliKey || '').trim() || !!current.clearVarnishCliKey;
	}

	function normalizeVarnishDefaultEndpointCandidate(value, mode) {
		let normalized = String(value || '').trim();
		if (!normalized) {
			return '';
		}
		if (String(mode || 'http') === 'http') {
			normalized = normalized.replace(/^http:\/\//i, '');
		}
		return normalized.replace(/\/$/, '');
	}

	function isDefaultVarnishServersForMode(value, mode) {
		const normalized = normalizeVarnishDefaultEndpointCandidate(value, mode);
		return !normalized || normalized === getDefaultVarnishServersForMode(mode);
	}

	function isDefaultVarnishServersValue(value) {
		return isDefaultVarnishServersForMode(value, 'http') || isDefaultVarnishServersForMode(value, 'admin');
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
		if (!status || status === 'not-tested') {
			const reason = result && result.message
				? String(result.message)
				: __('No Varnish behavior test result is stored', 'ultracache');
			return __('Not tested', 'ultracache') + ' · ' + reason;
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
		if (status === 'canary-write-failed') {
			return __('Canary write failed', 'ultracache');
		}
		if (status === 'canary-route-unavailable') {
			return __('Canary route unavailable', 'ultracache');
		}
		if (status === 'canary-not-cacheable') {
			return __('Shared cache not observed', 'ultracache');
		}
		if (status === 'invalidation-not-observed') {
			return __('Invalidation not observed', 'ultracache');
		}
		if (status === 'observation-incomplete') {
			return __('Observation incomplete', 'ultracache');
		}
		if (status === 'partial-topology') {
			return __('Partial topology', 'ultracache');
		}
		if (status === 'endpoint-proofs-failed') {
			return __('Endpoint proofs failed', 'ultracache');
		}
		if (status === 'endpoint-route-unavailable') {
			return __('Endpoint route unavailable', 'ultracache');
		}
		if (status === 'static-route-only') {
			return __('Static route only', 'ultracache');
		}
		if (status === 'configuration-incomplete') {
			return __('Configuration incomplete', 'ultracache');
		}
		if (status === 'configuration-changed') {
			return __('Run test again', 'ultracache');
		}
		return status.replace(/-/g, ' ');
	}

	function getEsiVerificationState(capability, configured) {
		const source = capability && typeof capability === 'object' ? capability : {};
		const status = String(source.status || '').toLowerCase();
		const proofCurrent = !!source.tested && status !== 'configuration-changed' && !source.configurationChanged;
		const publicVerified = !!configured && proofCurrent && !!source.verified && !!source.effective;
		const privateVerified = publicVerified
			&& !!source.privateTransportVerified
			&& !!source.privateSessionIsolationVerified
			&& !!source.privateParentCacheVerified
			&& !!source.privateFragmentNoStoreVerified
			&& !!source.privateOnerrorVerified;
		const wooVerified = privateVerified && !!source.woocommerceAdapterAvailable && !!source.woocommerceTransportVerified;
		return { status, proofCurrent, publicVerified, privateVerified, wooVerified };
	}

	function getEsiCapabilityLabel(capability, configured, verified) {
		const status = String(capability && capability.status ? capability.status : '').toLowerCase();
		if (!configured) {
			return __('Unavailable', 'ultracache');
		}
		if (status === 'configuration-changed') {
			return __('Run test again', 'ultracache');
		}
		if (verified) {
			return status.indexOf('signals-hidden') !== -1
				? __('Verified, signals hidden', 'ultracache')
				: __('Verified', 'ultracache');
		}
		if (capability && capability.tested) {
			return __('Fallback only', 'ultracache');
		}
		return __('Test required', 'ultracache');
	}

	function getQueueErrorTypeLabel(type) {
		return String(type || '') === 'invalidation'
			? __('Varnish invalidation', 'ultracache')
			: __('Varnish refill', 'ultracache');
	}

	function formatQueueTerminalErrorDetail(detail, includeAction) {
		if (!detail || typeof detail !== 'object') {
			return '';
		}
		const type = String(detail.type || 'refill');
		const parts = [getQueueErrorTypeLabel(type)];
		const url = String(detail.url || '').trim();
		const message = String(detail.message || '').trim();
		const attempts = Number(detail.attempts || 0);
		if (url) {
			parts.push(url);
		}
		if (message) {
			parts.push(message);
		}
		if (attempts > 0) {
			parts.push(sprintf(__('%d attempt(s)', 'ultracache'), attempts));
		}
		let output = parts.join(' · ');
		if (includeAction) {
			output += '\n' + (type === 'invalidation'
				? __('Action: fix the reported Varnish transport or authentication error, then run the invalidation or Redetect Varnish Capabilities again.', 'ultracache')
				: __('Action: fix the reported HTTP or origin error, then run the affected-page or site warm-up again.', 'ultracache'));
		}
		return output;
	}

	function renderWarningSummary(items) {
		return (Array.isArray(items) ? items : []).filter((item) => item && item.message).map((item) => h('div', {
			className: 'text-xs text-amber-200 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2 whitespace-pre-line break-words',
			key: 'varnish-warning-' + String(item.category || 'general'),
		}, [
			h('strong', { className: 'text-amber-100' }, String(item.label || __('Warning', 'ultracache')) + ': '),
			String(item.message),
		]));
	}


	function getStaleGracePolicyPresentation(varnish) {
		const source = varnish && typeof varnish === 'object' ? varnish : {};
		const policy = source.staleWhileRevalidate && typeof source.staleWhileRevalidate === 'object'
			? source.staleWhileRevalidate
			: {};
		const seconds = Math.max(0, Number(policy.configuredSeconds || 0));
		const minutes = Math.max(0, Math.round(seconds / 60));
		if (seconds <= 0) {
			return {
				ok: false,
				text: __('Disabled · 0 min grace', 'ultracache'),
				tone: 'neutral',
				status: 'disabled',
				seconds: 0,
			};
		}
		if (!source.enabled) {
			return {
				ok: false,
				text: sprintf(__('Inactive · %d min configured', 'ultracache'), minutes),
				tone: 'neutral',
				status: 'inactive',
				seconds,
			};
		}
		return {
			ok: true,
			text: sprintf(__('Configured · %d min grace', 'ultracache'), minutes),
			tone: 'success',
			status: 'configured',
			seconds,
		};
	}

	function getHtmlVariantCapabilityPresentation(basicTest, effectiveCapabilities) {
		const test = basicTest && typeof basicTest === 'object' ? basicTest : {};
		const effective = effectiveCapabilities && typeof effectiveCapabilities === 'object' ? effectiveCapabilities : {};
		const capability = test.htmlVariantCapability && typeof test.htmlVariantCapability === 'object'
			? test.htmlVariantCapability
			: null;
		const presentation = {
			ok: false,
			text: __('Not tested · No HTML-variant probe result is stored', 'ultracache'),
			tone: 'neutral',
			status: 'not-tested',
			message: '',
			activeBuckets: [],
			verifiedBucketCount: 0,
			totalBucketCount: 0,
			details: [],
			hasCapability: !!capability,
		};

		if (!capability) {
			if (effective.htmlVariants) {
				presentation.ok = true;
				presentation.text = __('Supported · current endpoint proof', 'ultracache');
				presentation.tone = 'success';
				presentation.status = 'supported';
			}
			return presentation;
		}

		if (capability.tested === false) {
			presentation.message = String(capability.message || __('The HTML-variant probe did not run.', 'ultracache'));
			presentation.text = __('Not tested', 'ultracache') + ' · ' + presentation.message;
			presentation.tone = 'neutral';
			presentation.status = 'not-tested';
			return presentation;
		}

		presentation.activeBuckets = Array.isArray(capability.activeBuckets)
			? capability.activeBuckets.map((bucket) => String(bucket || '').trim()).filter(Boolean)
			: [];
		presentation.totalBucketCount = presentation.activeBuckets.length;
		presentation.verifiedBucketCount = Math.max(0, Number(capability.verifiedBucketCount || 0));
		presentation.details = Array.isArray(capability.details) ? capability.details : [];
		presentation.message = String(capability.message || '');

		if (capability.configurationChanged || String(capability.status || '') === 'configuration-changed') {
			presentation.text = __('Configuration changed · run Redetect Varnish Capabilities again', 'ultracache');
			presentation.tone = 'warning';
			presentation.status = 'configuration-changed';
			return presentation;
		}

		if (capability.applicable === false) {
			presentation.text = presentation.activeBuckets.length === 1
				? sprintf(__('Not applicable · one active HTML bucket (%s)', 'ultracache'), presentation.activeBuckets[0])
				: __('Not applicable · fewer than two active HTML buckets', 'ultracache');
			presentation.tone = 'neutral';
			presentation.status = 'not-applicable';
			return presentation;
		}

		const countText = presentation.totalBucketCount > 0
			? sprintf(__('%1$d of %2$d', 'ultracache'), presentation.verifiedBucketCount, presentation.totalBucketCount)
			: sprintf(__('%d verified', 'ultracache'), presentation.verifiedBucketCount);
		const normalizedStatus = String(capability.status || '').toLowerCase();
		if (normalizedStatus === 'observation-incomplete') {
			presentation.text = __('Observation incomplete', 'ultracache')
				+ ' · '
				+ (presentation.message || __('The HTML-variant observation did not complete.', 'ultracache'));
			presentation.tone = 'warning';
			presentation.status = 'observation-incomplete';
			return presentation;
		}
		if (capability.supported && effective.htmlVariants) {
			presentation.ok = true;
			presentation.text = __('Supported', 'ultracache') + ' · ' + countText
				+ (presentation.activeBuckets.length ? ' · ' + presentation.activeBuckets.join(', ') : '');
			presentation.tone = 'success';
			presentation.status = 'supported';
			return presentation;
		}
		if (capability.supported) {
			presentation.text = __('Verification incomplete · endpoint proof is not current', 'ultracache');
			presentation.tone = 'warning';
			presentation.status = 'verification-incomplete';
			return presentation;
		}

		presentation.text = __('Not supported', 'ultracache') + ' · ' + countText;
		presentation.tone = 'warning';
		presentation.status = 'not-supported';
		return presentation;
	}

	function renderHtmlVariantCapabilityDetails(presentation) {
		if (!presentation || !presentation.hasCapability) {
			return null;
		}
		const bucketText = presentation.activeBuckets.length
			? presentation.activeBuckets.join(', ')
			: __('None reported', 'ultracache');
		return h('div', { className: 'mt-4 pt-4 border-t border-white/5', key: 'html-variant-details' }, [
			h('div', { className: 'text-xs font-semibold text-zinc-300 mb-2' }, __('HTML variant verification details', 'ultracache')),
			presentation.message ? h('div', { className: 'text-xs text-zinc-400 mb-3 whitespace-pre-line break-words' }, presentation.message) : null,
			h(DetailRow, { label: __('Active buckets', 'ultracache'), value: bucketText }),
			h(DetailRow, { label: __('Verified buckets', 'ultracache'), value: sprintf(__('%1$d of %2$d', 'ultracache'), presentation.verifiedBucketCount, presentation.totalBucketCount) }),
			presentation.details.length ? h('div', { className: 'mt-3 space-y-3' }, presentation.details.map((detail, index) => {
				const bucket = String(detail.bucket || sprintf(__('Bucket %d', 'ultracache'), index + 1));
				const variants = String(detail.firstVariant || '—') + ' / ' + String(detail.secondVariant || '—');
				const httpCodes = String(Number(detail.firstHttpCode || 0)) + ' / ' + String(Number(detail.secondHttpCode || 0));
				const parts = [
					detail.supported ? __('Verified', 'ultracache') : __('Failed', 'ultracache'),
					sprintf(__('HTTP %s', 'ultracache'), httpCodes),
					sprintf(__('returned %s', 'ultracache'), variants),
					detail.secondCacheVerified ? __('HIT/STALE verified', 'ultracache') : __('HIT/STALE not verified', 'ultracache'),
					detail.varnishDetected ? __('Varnish detected', 'ultracache') : __('Varnish not detected', 'ultracache'),
				];
				return h(DetailRow, { key: 'html-variant-bucket-' + index, label: bucket, value: parts.join(' · ') });
			})) : null,
		]);
	}

	function renderVarnishDiagnosticRows(rows, tone) {
		return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', {
			className: 'flex items-center justify-between gap-4 py-2',
			key: row[0],
		}, [
			h('div', { className: 'text-sm text-white' }, row[0]),
			h(StatusPill, {
				ok: !!row[1],
				text: row[2],
				tone: row[3] || tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral'),
			}),
		])));
	}

	function renderVarnishDiagnosticsAccordion(varnish, form) {
		const source = varnish && typeof varnish === 'object' ? varnish : {};
		const settings = form && typeof form === 'object' ? form : {};
		const last = source.last && typeof source.last === 'object' ? source.last : {};
		const sharedCache = source.sharedCacheDelivery && typeof source.sharedCacheDelivery === 'object' ? source.sharedCacheDelivery : {};
		const basicTest = source.basicTest && typeof source.basicTest === 'object' ? source.basicTest : {};
		const endpointCapabilities = source.endpointCapabilities && typeof source.endpointCapabilities === 'object' ? source.endpointCapabilities : {};
		const effectiveCapabilities = endpointCapabilities.effective && typeof endpointCapabilities.effective === 'object' ? endpointCapabilities.effective : {};
		const capabilityStates = endpointCapabilities.capabilityStates && typeof endpointCapabilities.capabilityStates === 'object' ? endpointCapabilities.capabilityStates : {};
		const publicPathCapabilities = endpointCapabilities.publicPath && typeof endpointCapabilities.publicPath === 'object' ? endpointCapabilities.publicPath : {};
		const staleGracePresentation = getStaleGracePolicyPresentation(source);
		const htmlVariantPresentation = getHtmlVariantCapabilityPresentation(basicTest, effectiveCapabilities);
		const endpoints = Array.isArray(endpointCapabilities.endpoints) ? endpointCapabilities.endpoints : [];
		const runtimePlanner = source.runtimePlanner && typeof source.runtimePlanner === 'object' ? source.runtimePlanner : {};
		const targetedPlan = runtimePlanner.targeted && typeof runtimePlanner.targeted === 'object' ? runtimePlanner.targeted : {};
		const sitePlan = runtimePlanner.site && typeof runtimePlanner.site === 'object' ? runtimePlanner.site : {};
		const esiCapability = source.esiCapability && typeof source.esiCapability === 'object' ? source.esiCapability : {};
		const flushScope = source.flushScope && typeof source.flushScope === 'object' ? source.flushScope : {};
		const queue = source.queue && typeof source.queue === 'object' ? source.queue : {};
		const metrics = source.metrics && typeof source.metrics === 'object' ? source.metrics : {};
		const runtimeOutcomes = metrics.runtimeOutcomes && typeof metrics.runtimeOutcomes === 'object' ? metrics.runtimeOutcomes : {};
		const runtimeStrategies = metrics.runtimeStrategies && typeof metrics.runtimeStrategies === 'object' ? metrics.runtimeStrategies : {};
		const esiMetrics = metrics.esi && typeof metrics.esi === 'object' ? metrics.esi : {};
		const esiLast24Hours = esiMetrics.last24Hours && typeof esiMetrics.last24Hours === 'object' ? esiMetrics.last24Hours : {};
		const wooEsiLast24Hours = esiLast24Hours.woocommerceMiniCart && typeof esiLast24Hours.woocommerceMiniCart === 'object' ? esiLast24Hours.woocommerceMiniCart : {};
		const performance = source.performanceSnapshot && typeof source.performanceSnapshot === 'object' ? source.performanceSnapshot : {};
		const configuredEndpointCount = Number(endpointCapabilities.configuredEndpointCount || source.endpointCount || 0);
		let verifiedEndpointCount = Number(endpointCapabilities.verifiedExactEndpointCount || 0);
		const contractEndpointCount = Number(endpointCapabilities.contractEndpointCount || 0);
		const configuredMode = String(source.configuredMode || source.mode || 'http') === 'admin' ? 'admin' : 'http';
		const isAdminMode = configuredMode === 'admin';
		const requiredExactCapability = isAdminMode || String(source.method || '').toUpperCase() === 'BAN' ? 'exactBan' : 'exactPurge';
		const requiredExactState = capabilityStates[requiredExactCapability] && typeof capabilityStates[requiredExactCapability] === 'object'
			? capabilityStates[requiredExactCapability]
			: {};
		verifiedEndpointCount = Number(requiredExactState.behaviorVerifiedEndpointCount || 0);
		const modeLabel = isAdminMode ? __('Admin secret', 'ultracache') : __('HTTP endpoint', 'ultracache');
		const controlConnectionTested = !!(basicTest.controlTransportTested || basicTest.invalidationAttempted || endpoints.some((endpoint) => Number(endpoint.testedAt || 0) > 0));
		const controlConnectionVerified = !!(basicTest.controlConnectionAccepted || basicTest.controlTransportAccepted || endpoints.some((endpoint) => !!endpoint.controlConnectionVerified || !!endpoint.exactInvalidation));
		const sharedCacheTested = !!(basicTest.tested || basicTest.status || basicTest.sharedCacheVerified || basicTest.canaryCacheable);
		const sharedCacheVerified = !!(basicTest.sharedCacheVerified || basicTest.canaryCacheable);
		const sharedMode = !sharedCache.enabled
			? __('Inactive', 'ultracache')
			: (String(sharedCache.mode || '') === 'managed'
				? sprintf(__('Managed · %d min TTL', 'ultracache'), Number(sharedCache.managedTtlMinutes || 0))
				: sprintf(__('TTL expiry only · %d min', 'ultracache'), Number(sharedCache.ttlOnlyMinutes || sharedCache.ttlMinutes || 0)));
		const basicStatusCode = String(basicTest.status || 'not-tested').toLowerCase();
		const basicStatus = getBasicTestLabel(basicTest);
		const contractFailureMessages = Array.from(new Set(endpoints
			.filter((endpoint) => !endpoint.contractAuthenticated)
			.map((endpoint) => String(endpoint.contractMessage || endpoint.contractStatus || __('The endpoint did not return the authenticated UltraCache HTTP/VCL contract.', 'ultracache')).trim())
			.filter(Boolean)));
		const contractFailureReason = contractFailureMessages.length === 1
			? contractFailureMessages[0]
			: (contractFailureMessages.length > 1
				? __('Configured endpoints returned different HTTP/VCL contract outcomes.', 'ultracache')
				: __('The HTTP/VCL contract probe has not run for the configured endpoint set.', 'ultracache'));
		const contractPresentation = configuredEndpointCount > 0 && contractEndpointCount === configuredEndpointCount
			? { ok: true, text: __('Supported', 'ultracache'), tone: 'success' }
			: { ok: false, text: __('Not available', 'ultracache') + ' · ' + contractFailureReason, tone: 'neutral' };
		const capabilityText = (value) => value
			? __('Supported', 'ultracache')
			: __('Not supported', 'ultracache');
		const capabilityPresentation = (name, value) => {
			const state = capabilityStates[name] && typeof capabilityStates[name] === 'object'
				? capabilityStates[name]
				: {};
			const status = String(state.state || 'not-tested');
			const reason = String(state.message || state.reasonCode || __('No persisted capability outcome exists for the configured endpoint set.', 'ultracache')).replace(/-/g, ' ');
			const withReason = (label) => label + ' · ' + reason;
			if (value || state.behaviorVerifiedAllEndpoints) {
				return { ok: true, text: __('Supported', 'ultracache'), tone: 'success' };
			}
			if (status === 'partial') {
				return { ok: false, text: withReason(__('Partially verified', 'ultracache')), tone: 'warning' };
			}
			if (status === 'partially-tested') {
				return { ok: false, text: withReason(__('Partially tested', 'ultracache')), tone: 'warning' };
			}
			if (status === 'not-supported') {
				return { ok: false, text: withReason(__('Not supported', 'ultracache')), tone: 'warning' };
			}
			if (status === 'not-applicable') {
				return { ok: false, text: withReason(__('Not applicable', 'ultracache')), tone: 'neutral' };
			}
			if (status === 'observation-incomplete') {
				return { ok: false, text: withReason(__('Observation incomplete', 'ultracache')), tone: 'warning' };
			}
			if (status === 'configuration-changed') {
				return { ok: false, text: withReason(__('Configuration changed', 'ultracache')), tone: 'warning' };
			}
			if (status === 'unconfigured') {
				return { ok: false, text: withReason(__('Not configured', 'ultracache')), tone: 'neutral' };
			}
			return { ok: false, text: withReason(__('Not tested', 'ultracache')), tone: 'neutral' };
		};
		const capabilityRow = (label, name, value) => {
			const presentation = capabilityPresentation(name, value);
			return [label, presentation.ok, presentation.text, presentation.tone];
		};
		const publicPathPresentation = (value, tested, status, message) => {
			if (value) {
				return { ok: true, text: __('Supported', 'ultracache'), tone: 'success' };
			}
			const normalizedStatus = String(status || '');
			const reason = String(message || normalizedStatus || __('No persisted capability outcome exists for the configured endpoint set.', 'ultracache')).replace(/-/g, ' ');
			if (normalizedStatus === 'observation-incomplete') {
				return { ok: false, text: __('Observation incomplete', 'ultracache') + ' · ' + reason, tone: 'warning' };
			}
			if (['not-tested', 'probe-skipped', 'configuration-incomplete', 'configuration-changed'].indexOf(normalizedStatus) !== -1) {
				return { ok: false, text: __('Not tested', 'ultracache') + ' · ' + reason, tone: 'neutral' };
			}
			return tested
				? { ok: false, text: __('Not supported', 'ultracache') + ' · ' + reason, tone: 'warning' }
				: { ok: false, text: __('Not tested', 'ultracache') + ' · ' + reason, tone: 'neutral' };
		};
		const planText = (plan) => {
			if (!plan || typeof plan !== 'object') {
				return __('Unavailable', 'ultracache');
			}
			const strategy = String(plan.selectedStrategy || 'none').replace(/-/g, ' ');
			const outcome = String(plan.plannedOutcome || 'unsupported').replace(/-/g, ' ');
			return strategy + ' · ' + outcome + (plan.usingFallback ? ' · ' + __('fallback', 'ultracache') : '');
		};
		const runtimeOutcomeText = ['complete', 'partial', 'degraded', 'unsupported', 'failed'].map((key) => {
			return key + ' ' + formatNumber(runtimeOutcomes[key] || 0);
		}).join(' · ');
		const strategyEntries = Object.keys(runtimeStrategies).map((key) => [key, Number(runtimeStrategies[key] || 0)])
			.filter((item) => item[1] > 0)
			.sort((left, right) => right[1] - left[1])
			.slice(0, 8);
		const strategyText = strategyEntries.length
			? strategyEntries.map((item) => item[0].replace(/-/g, ' ') + ' ' + formatNumber(item[1])).join(' · ')
			: __('No recorded strategy', 'ultracache');
		const performanceText = !performance.tested
			? __('Not measured', 'ultracache')
			: (performance.configurationChanged
				? __('Configuration changed · run measurement again', 'ultracache')
				: (performance.stale
					? __('Measurement stale · run measurement again', 'ultracache')
					: (performance.signalsVisible
						? (Number(performance.hitRatePercent || 0).toFixed(1).replace(/\.0$/, '') + '% HIT · ' + Number(performance.cacheServedRatePercent || 0).toFixed(1).replace(/\.0$/, '') + '% cache-served')
						: __('Measured · cache signals hidden', 'ultracache'))));
		const formatMetricDecimal = (value) => {
			const number = Number(value || 0);
			return Number.isFinite(number) ? number.toFixed(1).replace(/\.0$/, '') : '0';
		};
		const formatMetricDuration = (value) => formatMetricDecimal(value) + ' ms';
		const esiSampleRate = Math.max(1, Number(esiMetrics.sampleRate || 1));
		const esiSampledRequests = Math.max(0, Number(esiLast24Hours.sampledRequests || 0));
		const esiEstimatedRequests = Math.max(0, Number(esiLast24Hours.estimatedRequests || 0));
		const esiContainedErrors = Math.max(0, Number(esiLast24Hours.containedErrors || 0));
		const esiRuntimeRows = esiSampledRequests < 1
			? [
				[__('Sampling', 'ultracache'), false, __('No sampled requests', 'ultracache'), 'neutral'],
				[__('Contained errors', 'ultracache'), 0 === esiContainedErrors, formatNumber(esiContainedErrors), esiContainedErrors > 0 ? 'warning' : 'neutral'],
			]
			: [
				[__('Sampling rate', 'ultracache'), false, sprintf(__('1 in %d fragment requests', 'ultracache'), esiSampleRate), 'neutral'],
				[__('Sampled requests', 'ultracache'), false, formatNumber(esiSampledRequests), 'neutral'],
				[__('Estimated requests', 'ultracache'), false, sprintf(__('%1$s total · %2$s per hour', 'ultracache'), formatNumber(esiEstimatedRequests), formatMetricDecimal(esiLast24Hours.estimatedRequestsPerHour)), 'neutral'],
				[__('Estimated request mix', 'ultracache'), false, sprintf(__('%1$s public · %2$s private', 'ultracache'), formatNumber(esiLast24Hours.estimatedPublicRequests || 0), formatNumber(esiLast24Hours.estimatedPrivateRequests || 0)), 'neutral'],
				[__('Average render time', 'ultracache'), false, formatMetricDuration(esiLast24Hours.averageRenderMs), 'neutral'],
				[__('Maximum render time', 'ultracache'), false, formatMetricDuration(esiLast24Hours.maximumRenderMs), 'neutral'],
				[__('Average output size', 'ultracache'), false, formatBytes(esiLast24Hours.averageOutputBytes || 0), 'neutral'],
				[__('Contained errors', 'ultracache'), 0 === esiContainedErrors, formatNumber(esiContainedErrors), esiContainedErrors > 0 ? 'warning' : 'neutral'],
			];
		const wooEsiSampledRequests = Math.max(0, Number(wooEsiLast24Hours.sampledRequests || 0));
		const wooEsiEstimatedRequests = Math.max(0, Number(wooEsiLast24Hours.estimatedRequests || 0));
		const wooEsiAverageRenderMs = Math.max(0, Number(wooEsiLast24Hours.averageRenderMs || 0));
		const wooEsiContainedErrors = Math.max(0, Number(wooEsiLast24Hours.containedErrors || 0));
		const wooEsiHighRenderCost = wooEsiEstimatedRequests >= 20 && wooEsiAverageRenderMs >= 100;
		const wooEsiRuntimeRows = wooEsiSampledRequests < 1
			? [
				[__('Sampling', 'ultracache'), false, __('No sampled requests', 'ultracache'), 'neutral'],
				[__('Contained errors', 'ultracache'), 0 === wooEsiContainedErrors, formatNumber(wooEsiContainedErrors), wooEsiContainedErrors > 0 ? 'warning' : 'neutral'],
			]
			: [
				[__('Sampled requests', 'ultracache'), false, formatNumber(wooEsiSampledRequests), 'neutral'],
				[__('Estimated requests', 'ultracache'), false, sprintf(__('%1$s total · %2$s per hour', 'ultracache'), formatNumber(wooEsiEstimatedRequests), formatMetricDecimal(wooEsiLast24Hours.estimatedRequestsPerHour)), 'neutral'],
				[__('Average render time', 'ultracache'), false, formatMetricDuration(wooEsiAverageRenderMs), wooEsiHighRenderCost ? 'warning' : 'neutral'],
				[__('Maximum render time', 'ultracache'), false, formatMetricDuration(wooEsiLast24Hours.maximumRenderMs), 'neutral'],
				[__('Average output size', 'ultracache'), false, formatBytes(wooEsiLast24Hours.averageOutputBytes || 0), 'neutral'],
				[__('Contained errors', 'ultracache'), 0 === wooEsiContainedErrors, formatNumber(wooEsiContainedErrors), wooEsiContainedErrors > 0 ? 'warning' : 'neutral'],
			];

		const secretConfigured = !!(source.secretConfigured || settings.varnishCliKeyConfigured);
		const secretStatus = settings.varnishCliKeyExternal
			? __('Configured externally', 'ultracache')
			: (settings.varnishCliKeyManaged ? __('Managed by UltraCache', 'ultracache') : (secretConfigured ? __('Configured', 'ultracache') : __('Not configured', 'ultracache')));
		const terminalErrorDetails = Array.isArray(queue.terminalErrorDetails) ? queue.terminalErrorDetails.slice(0, 12) : [];
		const lastOperationType = String(last.operationType || last.testType || last.type || '').replace(/-/g, ' ');
		const lastOperationStatus = String(last.runtimeOutcome || last.status || (last.success ? 'success' : '')).replace(/-/g, ' ');

		const publicEsiPresentation = publicPathPresentation(
			!!effectiveCapabilities.publicEsi,
			!!publicPathCapabilities.esiTested,
			publicPathCapabilities.esiStatus,
			publicPathCapabilities.esiMessage
		);
		const privateEsiPresentation = publicPathPresentation(
			!!effectiveCapabilities.privateEsi,
			!!publicPathCapabilities.esiTested,
			publicPathCapabilities.esiStatus,
			publicPathCapabilities.esiMessage
		);
		const flushCapability = flushScope.htmlCapability && typeof flushScope.htmlCapability === 'object'
			? flushScope.htmlCapability
			: {};
		const flushSupported = !!flushScope.supported || !!flushScope.hostSupported;
		const entireHostStatus = String(flushCapability.entireHostStatus || 'not-tested').toLowerCase();
		const entireHostNotApplicable = ['not-applicable', 'not-applicable-static-bypass'].indexOf(entireHostStatus) !== -1;
		const flushTested = Number(flushCapability.testedAt || 0) > 0;
		const flushReason = String(flushCapability.message || flushScope.fallbackReason || __('No persisted site-flush capability outcome exists for the current configuration.', 'ultracache'));
		const flushStatusPresentation = flushSupported
			? { ok: true, text: __('Supported', 'ultracache'), tone: 'success' }
			: (flushTested
				? { ok: false, text: __('Not supported', 'ultracache') + ' · ' + flushReason, tone: 'warning' }
				: { ok: false, text: __('Not tested', 'ultracache') + ' · ' + flushReason, tone: 'neutral' });
		const esiRuntimePresentation = publicPathPresentation(
			!!esiCapability.effective,
			!!esiCapability.tested,
			esiCapability.status,
			esiCapability.message
		);

		const connectionRows = [
			[__('Enabled', 'ultracache'), !!source.enabled, source.enabled ? __('Yes', 'ultracache') : __('No', 'ultracache')],
			[__('Authentication secret', 'ultracache'), secretConfigured, secretStatus],
			[__('Connection configured', 'ultracache'), !!source.connectionConfigured, source.connectionConfigured ? __('Yes', 'ultracache') : __('No', 'ultracache')],
			[__('Control support', 'ultracache'), !!source.available, capabilityText(source.available)],
			[__('Mode', 'ultracache'), false, modeLabel],
			[__('Configured endpoints', 'ultracache'), configuredEndpointCount > 0, formatNumber(configuredEndpointCount) + (source.servers ? ' · ' + String(source.servers) : '')],
			[__('Timeout', 'ultracache'), false, formatNumber(source.timeout || 0) + ' sec'],
			[__('Control connection', 'ultracache'), controlConnectionVerified, !controlConnectionTested
				? __('Not tested · No control-connection probe result is stored', 'ultracache')
				: (controlConnectionVerified ? __('Verified', 'ultracache') : __('Failed', 'ultracache')), !controlConnectionTested ? 'neutral' : (controlConnectionVerified ? 'success' : 'warning')],
			[__('Shared-cache behavior', 'ultracache'), sharedCacheVerified, !sharedCacheTested
				? __('Not tested · No shared-cache behavior probe result is stored', 'ultracache')
				: (sharedCacheVerified ? __('Verified', 'ultracache') : __('Not verified', 'ultracache')), !sharedCacheTested ? 'neutral' : (sharedCacheVerified ? 'success' : 'warning')],
			[__('Shared-cache policy', 'ultracache'), !!sharedCache.enabled, sharedMode, sharedCache.enabled ? (String(sharedCache.mode || '') === 'managed' ? 'success' : 'warning') : 'neutral'],
			[__('Stale grace policy', 'ultracache'), staleGracePresentation.ok, staleGracePresentation.text, staleGracePresentation.tone],
			[__('Behavior test', 'ultracache'), !!basicTest.success, basicStatus + (basicTest.message && basicStatusCode !== 'not-tested' ? ' · ' + String(basicTest.message) : ''), basicTest.success ? 'success' : (basicTest.tested || basicTest.status ? 'warning' : 'neutral')],
		];
		const registryStatus = String(endpointCapabilities.status || 'unconfigured').toLowerCase();
		const registryReason = String(
			endpointCapabilities.message
			|| requiredExactState.message
			|| requiredExactState.reasonCode
			|| (configuredEndpointCount > 0
				? __('No persisted endpoint capability outcome exists for the current configuration.', 'ultracache')
				: __('No Varnish endpoints are configured.', 'ultracache'))
		).replace(/-/g, ' ');
		const registryPresentation = endpointCapabilities.exactInvalidationVerified
			? { ok: true, text: __('Verified', 'ultracache'), tone: 'success' }
			: (registryStatus === 'unconfigured'
				? { ok: false, text: __('Not configured', 'ultracache') + ' · ' + registryReason, tone: 'neutral' }
				: (registryStatus === 'untested'
					? { ok: false, text: __('Not tested', 'ultracache') + ' · ' + registryReason, tone: 'neutral' }
					: (registryStatus === 'partial'
						? { ok: false, text: __('Partially verified', 'ultracache') + ' · ' + registryReason, tone: 'warning' }
						: { ok: false, text: __('Not verified', 'ultracache') + ' · ' + registryReason, tone: 'warning' })));
		const capabilityRows = [
			[__('Registry state', 'ultracache'), registryPresentation.ok, registryPresentation.text, registryPresentation.tone],
			[__('Exact endpoint proofs', 'ultracache'), configuredEndpointCount > 0 && verifiedEndpointCount === configuredEndpointCount, formatNumber(verifiedEndpointCount) + ' of ' + formatNumber(configuredEndpointCount)],
			isAdminMode
				? [__('VCL contract endpoints', 'ultracache'), false, __('Not applicable · Admin mode uses the authenticated Varnish CLI interface', 'ultracache'), 'neutral']
				: [__('VCL contract endpoints', 'ultracache'), contractPresentation.ok, contractPresentation.text, contractPresentation.tone],
			isAdminMode
				? [__('Exact PURGE', 'ultracache'), false, __('Unavailable in Admin/BAN mode', 'ultracache'), 'neutral']
				: capabilityRow(__('Exact PURGE', 'ultracache'), 'exactPurge', !!effectiveCapabilities.exactPurge),
			capabilityRow(__('Exact BAN', 'ultracache'), 'exactBan', !!effectiveCapabilities.exactBan),
			capabilityRow(__('Batch BAN', 'ultracache'), 'batchBan', !!effectiveCapabilities.batchBan),
			capabilityRow(__('HTML-only flush', 'ultracache'), 'htmlFlush', !!effectiveCapabilities.htmlFlush),
			capabilityRow(__('Entire-host flush', 'ultracache'), 'hostFlush', !!effectiveCapabilities.hostFlush),
			isAdminMode
				? [__('Soft purge', 'ultracache'), false, __('Unavailable in Admin/BAN mode', 'ultracache'), 'neutral']
				: capabilityRow(__('Soft purge', 'ultracache'), 'softPurge', !!effectiveCapabilities.softPurge),
			isAdminMode
				? [__('Origin revalidation', 'ultracache'), false, __('Unavailable in Admin/BAN mode', 'ultracache'), 'neutral']
				: capabilityRow(__('Origin revalidation', 'ultracache'), 'originRevalidation', !!effectiveCapabilities.originRevalidation),
			[__('Public ESI', 'ultracache'), publicEsiPresentation.ok, publicEsiPresentation.text, publicEsiPresentation.tone],
			[__('Private ESI', 'ultracache'), privateEsiPresentation.ok, privateEsiPresentation.text, privateEsiPresentation.tone],
			[__('HTML variants', 'ultracache'), htmlVariantPresentation.ok, htmlVariantPresentation.text, htmlVariantPresentation.tone],
		];
		const runtimeRows = [
			[__('Targeted runtime plan', 'ultracache'), String(targetedPlan.plannedOutcome || '') === 'complete', planText(targetedPlan), String(targetedPlan.plannedOutcome || '') === 'complete' ? 'success' : 'warning'],
			[__('Site runtime plan', 'ultracache'), String(sitePlan.plannedOutcome || '') === 'complete', planText(sitePlan), String(sitePlan.plannedOutcome || '') === 'complete' ? 'success' : 'warning'],
			[__('Flush scope status', 'ultracache'), flushStatusPresentation.ok, flushStatusPresentation.text, flushStatusPresentation.tone],
			[__('ESI capability status', 'ultracache'), esiRuntimePresentation.ok, esiRuntimePresentation.text, esiRuntimePresentation.tone],
			[__('Queue', 'ultracache'), Number(queue.terminalErrors || queue.failed || 0) === 0, formatNumber(queue.planned || queue.pending || 0) + ' pending · ' + formatNumber(queue.processing || 0) + ' processing · ' + formatNumber(queue.retrying || 0) + ' retrying · ' + formatNumber(queue.terminalErrors || queue.failed || 0) + ' errors', Number(queue.terminalErrors || queue.failed || 0) > 0 ? 'warning' : 'neutral'],
			[__('Runtime outcomes', 'ultracache'), Number(runtimeOutcomes.failed || 0) === 0, runtimeOutcomeText, Number(runtimeOutcomes.failed || 0) > 0 ? 'warning' : 'neutral'],
			[__('Runtime strategies', 'ultracache'), strategyEntries.length > 0, strategyText],
			[__('Performance snapshot', 'ultracache'), !!performance.tested && !performance.configurationChanged && !performance.stale, performanceText, performance.tested && !performance.configurationChanged && !performance.stale ? 'success' : (performance.tested ? 'warning' : 'neutral')],
		];

		return h('details', { className: 'uc-accordion uc-varnish-diagnostics mt-5', key: 'varnish-diagnostics' }, [
			h('summary', { className: 'uc-accordion__summary' }, [
				h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
					h('div', { className: 'uc-accordion__title' }, __('Varnish Diagnostics', 'ultracache')),
					h('div', { className: 'uc-accordion__description' }, __('Connection, capability proofs, runtime planning, queue activity, performance, and per-endpoint results.', 'ultracache')),
				]),
				h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
			]),
			h('div', { className: 'uc-accordion__body' }, [
				h('div', { className: 'grid grid-cols-1 xl:grid-cols-2 gap-6 mt-5', key: 'diagnostic-grid' }, [
					h('div', { className: 'rounded bg-black/10 p-4', key: 'connection' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('Connection and cache policy', 'ultracache')),
						renderVarnishDiagnosticRows(connectionRows, 'neutral'),
					]),
					h('div', { className: 'rounded bg-black/10 p-4', key: 'capabilities' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('Effective capability intersection', 'ultracache')),
						renderVarnishDiagnosticRows(capabilityRows, 'neutral'),
						renderHtmlVariantCapabilityDetails(htmlVariantPresentation),
					]),
					h('div', { className: 'rounded bg-black/10 p-4', key: 'runtime' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('Runtime planner, queue, and measurements', 'ultracache')),
						renderVarnishDiagnosticRows(runtimeRows, 'neutral'),
					]),
					h('div', { className: 'rounded bg-black/10 p-4', key: 'esi-runtime' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('All ESI fragments — last 24 hours', 'ultracache')),
						renderVarnishDiagnosticRows(esiRuntimeRows, 'neutral'),
					]),
					h('div', { className: 'rounded bg-black/10 p-4', key: 'woo-esi-runtime' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('WooCommerce mini-cart ESI — last 24 hours', 'ultracache')),
						renderVarnishDiagnosticRows(wooEsiRuntimeRows, 'neutral'),
					]),
				]),
				h('div', { className: 'grid grid-cols-1 xl:grid-cols-2 gap-6 mt-6', key: 'diagnostic-final-grid' }, [
					h('div', { className: 'rounded bg-black/10 p-4', key: 'endpoints' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('Per-endpoint proofs', 'ultracache')),
						endpoints.length ? h('div', { className: 'space-y-4' }, endpoints.map((endpoint, index) => {
							const current = endpoint.currentCapabilities && typeof endpoint.currentCapabilities === 'object' ? endpoint.currentCapabilities : {};
							const enabledCapabilities = Object.keys(current).filter((key) => !!current[key]).map((key) => key.replace(/([A-Z])/g, ' $1').toLowerCase());
							const endpointMode = String(endpoint.mode || configuredMode) === 'admin' ? 'admin' : 'http';
							const endpointConnectionLabel = endpointMode === 'admin' ? __('Admin connection', 'ultracache') : __('HTTP connection', 'ultracache');
							const endpointTested = Number(endpoint.testedAt || 0) > 0;
							const endpointCapabilityStates = endpoint.capabilityStates && typeof endpoint.capabilityStates === 'object' ? endpoint.capabilityStates : {};
							const endpointExactState = endpointMode === 'admin'
								? (endpointCapabilityStates.exactBan || {})
								: ((String(source.method || '').toUpperCase() === 'BAN' ? endpointCapabilityStates.exactBan : endpointCapabilityStates.exactPurge) || {});
							const endpointExactReason = String(endpointExactState.message || endpointExactState.reasonCode || __('No persisted exact-invalidation outcome exists for this endpoint.', 'ultracache')).replace(/-/g, ' ');
							const endpointExactTested = !!endpointExactState.tested;
							const endpointExactVerified = !!endpointExactState.current || !!endpointExactState.behaviorVerified;
							const endpointConnectionReason = String(endpoint.lastFailure || __('No control-connection probe result is stored for this endpoint.', 'ultracache'));
							const endpointConnectionSupported = endpointMode === 'admin' ? (!!endpoint.controlConnectionVerified || !!endpoint.exactInvalidation) : !!endpoint.runtimeReachable;
							const endpointExactStatus = String(endpointExactState.state || 'not-tested').toLowerCase();
							const endpointExactFailureLabel = endpointExactStatus === 'not-applicable'
								? __('Not applicable', 'ultracache')
								: (endpointExactStatus === 'observation-incomplete'
									? __('Observation incomplete', 'ultracache')
									: (endpointExactStatus === 'configuration-changed'
										? __('Configuration changed', 'ultracache')
									: (endpointExactTested ? __('Not verified', 'ultracache') : __('Not tested', 'ultracache')))
);
							const endpointStatus = endpointExactVerified
								? __('Exact invalidation verified', 'ultracache')
								: (endpointConnectionSupported
									? (endpointMode === 'admin' ? __('Admin transport verified', 'ultracache') : __('Transport verified', 'ultracache'))
									: endpointExactFailureLabel + ' · ' + endpointExactReason);
							const endpointRows = [
								[__('Endpoint', 'ultracache'), true, String(endpoint.endpoint || __('Unavailable', 'ultracache')), 'neutral'],
								[__('Adapter', 'ultracache'), true, String(endpoint.adapter || 'unverified'), 'neutral'],
								[__('Status', 'ultracache'), endpointExactVerified || endpointConnectionSupported, endpointStatus, endpointExactVerified || endpointConnectionSupported ? 'success' : (endpointTested ? 'warning' : 'neutral')],
								[endpointConnectionLabel, endpointConnectionSupported, !endpointTested
									? __('Not tested', 'ultracache') + ' · ' + endpointConnectionReason
									: (endpointConnectionSupported ? __('Verified', 'ultracache') : __('Failed', 'ultracache') + ' · ' + endpointConnectionReason), !endpointTested ? 'neutral' : (endpointConnectionSupported ? 'success' : 'warning')],
								[__('Exact invalidation', 'ultracache'), endpointExactVerified, !endpointExactTested
									? __('Not tested', 'ultracache') + ' · ' + endpointExactReason
									: (endpointExactVerified ? __('Verified', 'ultracache') : __('Not verified', 'ultracache') + ' · ' + endpointExactReason), !endpointExactTested ? 'neutral' : (endpointExactVerified ? 'success' : 'warning')],
								[__('Current capabilities', 'ultracache'), enabledCapabilities.length > 0, enabledCapabilities.length ? enabledCapabilities.join(', ') : __('None', 'ultracache'), 'neutral'],
							];
							if (endpoint.lastFailure) {
								endpointRows.push([__('Last failure', 'ultracache'), false, String(endpoint.lastFailure), 'warning']);
							}
							return h('div', { className: 'border-b border-white/5 pb-3 last:border-b-0', key: 'endpoint-' + index }, [
								renderVarnishDiagnosticRows(endpointRows, 'neutral'),
							]);
						})) : h('div', { className: 'text-xs text-zinc-500 pt-2' }, __('No endpoint capability profiles are stored.', 'ultracache')),
					]),
					h('div', { className: 'rounded bg-black/10 p-4', key: 'recent-operation' }, [
						h('div', { className: 'text-xs font-bold tracking-widest text-zinc-400 mb-2' }, __('Recent operation and failures', 'ultracache')),
						renderVarnishDiagnosticRows([
							[__('Operation', 'ultracache'), !!lastOperationType, lastOperationType || __('No recent operation', 'ultracache'), 'neutral'],
							[__('Result', 'ultracache'), String(lastOperationStatus || '') === 'complete', lastOperationStatus || __('Unavailable', 'ultracache'), String(lastOperationStatus || '') === 'complete' ? 'success' : 'neutral'],
							[__('Message', 'ultracache'), !!last.message, last.message || '', 'neutral'],
							[__('URL', 'ultracache'), !!last.url, last.url || '', 'neutral'],
						].filter((row) => row[2] !== ''), 'neutral'),
						terminalErrorDetails.length ? h('div', { className: 'mt-4 space-y-2', key: 'terminal-errors' }, terminalErrorDetails.map((detail, index) => h('div', {
							className: 'text-xs text-amber-200 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2 whitespace-pre-line break-words',
							key: 'terminal-error-' + index,
						}, formatQueueTerminalErrorDetail(detail, false)))) : h('div', { className: 'mt-3 text-xs text-zinc-500' }, __('No terminal Varnish queue failures are recorded.', 'ultracache')),
					]),
				]),
			]),
		]);
	}

	function VarnishCard({ form, savedSettings, diagnostics, busy, canManageInfrastructure, onEnabledChange, onFieldChange, onSave, onDetect, onTest, onMeasurePerformance, onFlushAll, onFlushEntireHost, onRemoveConflictingDropins, onRecheckConflicts }) {
		const varnish = diagnostics.varnish || {};
		const varnishEnabled = !!form.varnishCliEnabled;
		const infrastructureLocked = !canManageInfrastructure;
		const formDirty = isVarnishFormDirty(form, savedSettings);
		const last = varnish.last || {};
		const storedBasicResult = varnish.basicTest && typeof varnish.basicTest === 'object' ? varnish.basicTest : null;
		const legacyBasicResult = last && ['basic', 'behavior'].indexOf(String(last.testType || '')) !== -1 ? last : null;
		const behaviorResult = storedBasicResult || legacyBasicResult;
		const behaviorResultCurrent = !!(behaviorResult && !behaviorResult.configurationChanged && String(behaviorResult.status || '') !== 'configuration-changed');
		const behaviorTransportTested = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.controlTransportTested || behaviorResult.invalidationAttempted || behaviorResult.transportAccepted || behaviorResult.invalidationAccepted || behaviorResult.verified));
		const behaviorControlConnectionAccepted = !!(behaviorResultCurrent && behaviorResult && (behaviorResult.controlConnectionAccepted || behaviorResult.controlTransportAccepted || behaviorResult.transportAccepted || behaviorResult.invalidationAccepted || behaviorResult.verified));
		const productionInvalidationTypes = ['direct-invalidation', 'batch-invalidation', 'queued-invalidation', 'site-flush', 'site-flush-fallback'];
		const batchResult = last && productionInvalidationTypes.indexOf(String(last.operationType || '')) !== -1 ? last : null;
		const queueStats = varnish.queue && typeof varnish.queue === 'object' ? varnish.queue : {};
		const refillWorker = queueStats.refillWorker && typeof queueStats.refillWorker === 'object' ? queueStats.refillWorker : {};
		const refillWorkerStatus = String(refillWorker.status || 'idle').toLowerCase();
		const flushScopeStatus = varnish.flushScope && typeof varnish.flushScope === 'object' ? varnish.flushScope : {};
		const flushScopeUi = getFlushScopeState(form, flushScopeStatus);
		const configuredFlushScope = flushScopeUi.configured;
		const effectiveFlushScope = flushScopeUi.effective;
		const siteFlushActionAvailable = !!flushScopeUi.actionAvailable;
		const siteFlushUsesKnownUrls = String(flushScopeUi.runtimeStrategy || '').indexOf('known-url-') === 0;
		const siteFlushButtonLabel = siteFlushUsesKnownUrls
			? __('Invalidate Known Site URLs', 'ultracache')
			: (effectiveFlushScope === 'html'
				? __('Flush HTML Pages', 'ultracache')
				: (effectiveFlushScope === 'host' ? __('Flush Varnish for This Site', 'ultracache') : __('Immediate Site Flush Unavailable', 'ultracache')));
		const refreshAhead = varnish.refreshAhead && typeof varnish.refreshAhead === 'object' ? varnish.refreshAhead : {};
		const sharedCacheStatus = varnish.sharedCacheDelivery && typeof varnish.sharedCacheDelivery === 'object' ? varnish.sharedCacheDelivery : {};
		const sharedCacheEnabled = !!sharedCacheStatus.enabled;
		const automaticExpiryTtlMinutes = Number(sharedCacheStatus.ttlOnlyMinutes || sharedCacheStatus.ttlMinutes || 10);
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
		const esiCapability = varnish.esiCapability && typeof varnish.esiCapability === 'object' ? varnish.esiCapability : {};
		const esiConfigured = !!esiCapability.configured || !!form.varnishConnectionConfigured || !!varnish.connectionConfigured;
		const esiVerification = getEsiVerificationState(esiCapability, esiConfigured);
		const esiStatus = esiVerification.status;
		const esiProofCurrent = esiVerification.proofCurrent;
		const publicEsiVerified = esiVerification.publicVerified;
		const privateEsiVerified = esiVerification.privateVerified;
		const wooMiniCartAdapterAvailable = !!esiCapability.woocommerceAdapterAvailable;
		const wooMiniCartEsiVerified = esiVerification.wooVerified;
		const esiLabel = getEsiCapabilityLabel(esiCapability, esiConfigured, publicEsiVerified);
		const privateEsiLabel = !esiConfigured
			? __('Unavailable', 'ultracache')
			: (esiStatus === 'configuration-changed'
				? __('Run test again', 'ultracache')
				: (privateEsiVerified
					? __('Verified', 'ultracache')
					: (esiProofCurrent && publicEsiVerified ? __('VCL update required', 'ultracache') : __('Test required', 'ultracache'))));
		const esiTone = publicEsiVerified ? 'success' : (esiConfigured ? 'warning' : 'neutral');
		const privateEsiTone = privateEsiVerified ? 'success' : (esiConfigured ? 'warning' : 'neutral');
		const wooMiniCartEsiLabel = !esiConfigured
			? __('Unavailable', 'ultracache')
			: (esiStatus === 'configuration-changed'
				? __('Run test again', 'ultracache')
				: (wooMiniCartEsiVerified
					? __('Verified', 'ultracache')
					: (esiProofCurrent && publicEsiVerified ? __('VCL update required', 'ultracache') : __('Test required', 'ultracache'))));
		const wooMiniCartEsiTone = wooMiniCartEsiVerified ? 'success' : (esiConfigured ? 'warning' : 'neutral');
		const configuredStrategy = form.varnishInvalidationStrategy || strategyStatus.configured || String(form.varnishCliMethod || 'BAN').toLowerCase();
		const effectiveStrategy = String(strategyStatus.effective || (isAdminMode ? 'ban' : configuredStrategy)).toLowerCase();
		const effectiveMethod = effectiveStrategy === 'soft' ? 'soft PURGE' : (isAdminMode ? 'admin BAN' : effectiveStrategy.toUpperCase());
		const endpointCount = typeof varnish.endpointCount !== 'undefined' ? varnish.endpointCount : (formServers.trim() ? formServers.trim().split(/\s+/).length : 0);
		const endpointCapabilities = varnish.endpointCapabilities && typeof varnish.endpointCapabilities === 'object' ? varnish.endpointCapabilities : {};
		const configuredCapabilityEndpoints = Number(endpointCapabilities.configuredEndpointCount || 0);
		const verifiedCapabilityEndpoints = Number(endpointCapabilities.verifiedExactEndpointCount || 0);
		const endpointCapabilitiesAllVerified = configuredCapabilityEndpoints > 0 && !!endpointCapabilities.exactInvalidationVerified;
		const registryControlConnectionAccepted = Number(endpointCapabilities.reachableEndpointCount || 0) > 0;
		const controlConnectionAccepted = behaviorControlConnectionAccepted || registryControlConnectionAccepted;
		const controlConnectionTested = behaviorTransportTested || Number(endpointCapabilities.testedEndpointCount || 0) > 0;
		const contractCapabilityEndpoints = Number(endpointCapabilities.contractEndpointCount || 0);
		const connectionConfigured = !!form.varnishConnectionConfigured
			|| !!varnish.connectionConfigured;
		const contractCapabilityLabel = !connectionConfigured || configuredCapabilityEndpoints < 1
			? __('Not configured', 'ultracache')
			: (contractCapabilityEndpoints > 0 ? sprintf(__('%1$d of %2$d endpoints', 'ultracache'), contractCapabilityEndpoints, configuredCapabilityEndpoints) : __('Generic host contract', 'ultracache'));
		const contractCapabilityTone = contractCapabilityEndpoints > 0 ? (contractCapabilityEndpoints === configuredCapabilityEndpoints ? 'success' : 'warning') : 'neutral';
		const varnishMetrics = varnish.metrics && typeof varnish.metrics === 'object' ? varnish.metrics : {};
		const runtimeOutcomes = varnishMetrics.runtimeOutcomes && typeof varnishMetrics.runtimeOutcomes === 'object' ? varnishMetrics.runtimeOutcomes : {};
		const runtimeStrategies = varnishMetrics.runtimeStrategies && typeof varnishMetrics.runtimeStrategies === 'object' ? varnishMetrics.runtimeStrategies : {};
		const performanceSnapshot = varnish.performanceSnapshot && typeof varnish.performanceSnapshot === 'object' ? varnish.performanceSnapshot : {};
		const performanceTested = !!performanceSnapshot.tested;
		const performanceCurrent = performanceTested && !performanceSnapshot.configurationChanged && !performanceSnapshot.stale && ['configuration-changed', 'stale'].indexOf(String(performanceSnapshot.status || '')) === -1;
		const performanceSignalsVisible = performanceCurrent && !!performanceSnapshot.signalsVisible;
		const performanceRecommendations = Array.isArray(performanceSnapshot.recommendations) ? performanceSnapshot.recommendations.filter(Boolean).slice(0, 6) : [];
		const formatMeasuredRate = (value) => {
			const number = Number(value || 0);
			return (Number.isFinite(number) ? number.toFixed(1).replace(/\.0$/, '') : '0') + '%';
		};
		const endpointCapabilityLabel = !connectionConfigured
			? __('Disabled', 'ultracache')
			: (configuredCapabilityEndpoints < 1
				? __('No configured endpoints', 'ultracache')
				: sprintf(__('%1$d of %2$d exact verified', 'ultracache'), verifiedCapabilityEndpoints, configuredCapabilityEndpoints));
		const endpointCapabilityTone = !connectionConfigured || configuredCapabilityEndpoints < 1
			? 'neutral'
			: (endpointCapabilitiesAllVerified ? 'success' : 'warning');
		const secretConfigured = !!(varnish.secretConfigured || form.varnishCliKeyConfigured);
		const secretManaged = !!form.varnishCliKeyManaged;
		const secretExternal = !!form.varnishCliKeyExternal;
		const modeLabel = isAdminMode ? 'Admin secret' : 'HTTP frontend';

		const queuePending = Number(typeof queueStats.planned !== 'undefined' ? queueStats.planned : (queueStats.pending || 0));
		const queueProcessing = Number(queueStats.processing || 0);
		const queueRetrying = Number(queueStats.retrying || 0);
		const queueTerminal = Number(queueStats.terminalErrors || queueStats.failed || 0);
		const queueTerminalDetails = Array.isArray(queueStats.terminalErrorDetails) ? queueStats.terminalErrorDetails : [];
		const refillEnabled = !!varnish.refillAfterTargetedInvalidation || !!varnish.warmWithSiteWarmup;
		const activeRefillFailures = Number(queueStats.terminalRefillErrors || 0);
		const currentBasicRefillFailed = !!(behaviorResultCurrent && behaviorResult && String(behaviorResult.status || '') === 'refill-failed');
		const basicTestLabel = getBasicTestLabel(behaviorResult);
		const basicTestTone = behaviorResultCurrent && behaviorResult && behaviorResult.success ? 'success' : (behaviorResult ? 'warning' : 'neutral');
		const connectionLabel = !connectionConfigured
			? __('Disabled', 'ultracache')
			: (!controlConnectionTested ? __('Not tested · No control-connection probe result is stored', 'ultracache') : (controlConnectionAccepted ? __('Verified', 'ultracache') : __('Failed', 'ultracache')));
		const connectionTone = !connectionConfigured || !controlConnectionTested ? 'neutral' : (controlConnectionAccepted ? 'success' : 'warning');
		const runtimeExactInvalidationSupported = !!(flushScopeUi.methodCapability && flushScopeUi.methodCapability.exactInvalidationSupported);
		const exactInvalidationOk = !!connectionConfigured && endpointCapabilitiesAllVerified && runtimeExactInvalidationSupported;
		const exactInvalidationLabel = !connectionConfigured
			? __('Disabled', 'ultracache')
			: (exactInvalidationOk ? __('Supported', 'ultracache') : __('Not supported', 'ultracache'));
		const exactInvalidationTone = !connectionConfigured
			? 'neutral'
			: (exactInvalidationOk ? 'success' : (configuredCapabilityEndpoints > 0 ? 'warning' : 'neutral'));
		const productionInvalidationResult = batchResult && batchResult.message ? batchResult : null;
		const lastRuntimeOutcome = productionInvalidationResult ? String(productionInvalidationResult.runtimeOutcome || '').toLowerCase() : '';
		const lastInvalidationLabel = productionInvalidationResult
			? String(productionInvalidationResult.message)
			: __('No recent production result', 'ultracache');
		const lastInvalidationTone = productionInvalidationResult
			? (lastRuntimeOutcome === 'complete' || (!lastRuntimeOutcome && productionInvalidationResult.success && !productionInvalidationResult.partial) ? 'success' : 'warning')
			: 'neutral';
		const refillLabel = !refillEnabled
			? __('Disabled', 'ultracache')
			: (currentBasicRefillFailed
				? __('Last test failed', 'ultracache')
				: (activeRefillFailures > 0
					? sprintf(__('%d terminal error(s)', 'ultracache'), activeRefillFailures)
					: (queueStats.pendingRefills || queueStats.processingRefills
						? sprintf(__('%1$d queued · %2$d processing', 'ultracache'), Number(queueStats.pendingRefills || 0), Number(queueStats.processingRefills || 0))
						: __('Ready', 'ultracache'))));
		const queueLabel = sprintf(__('%1$d pending · %2$d processing · %3$d retrying · %4$d errors', 'ultracache'), queuePending, queueProcessing, queueRetrying, queueTerminal);
		const latestQueueError = queueTerminalDetails.length ? formatQueueTerminalErrorDetail(queueTerminalDetails[0], false) : '';
		const lastError = latestQueueError || (productionInvalidationResult && (lastRuntimeOutcome === 'failed' || lastRuntimeOutcome === 'unsupported' || (!lastRuntimeOutcome && !productionInvalidationResult.success && !productionInvalidationResult.skipped)) ? String(productionInvalidationResult.message || __('Invalidation failed', 'ultracache')) : '');
		const warningItems = [];
		if (connectionConfigured && (!varnish.available || endpointCount < 1 || (isAdminMode && !secretConfigured) || actionsBlocked)) {
			warningItems.push({ category: 'configuration', label: __('Connection', 'ultracache'), message: __('Varnish is not ready. Check the connection settings, save them, and run Redetect Varnish Capabilities.', 'ultracache') });
		}
		if (controlConnectionTested && !controlConnectionAccepted) {
			warningItems.push({ category: 'transport', label: __('Connection', 'ultracache'), message: __('The configured Varnish control connection could not be authenticated or reached. Check the endpoint and secret, then run Redetect Varnish Capabilities again.', 'ultracache') });
		}
		if (controlConnectionAccepted && !endpointCapabilitiesAllVerified) {
			warningItems.push({ category: 'invalidation', label: __('Cache clearing', 'ultracache'), message: __('The Varnish control connection succeeded, but exact cache invalidation was not verified for the active VCL. UltraCache will use the next verified fallback.', 'ultracache') });
		}
		if (connectionConfigured && configuredCapabilityEndpoints > 1 && !endpointCapabilitiesAllVerified) {
			warningItems.push({
				category: 'topology',
				label: __('Endpoint topology', 'ultracache'),
				message: sprintf(__('Only %1$d of %2$d configured endpoints have independent exact-invalidation proof. Managed invalidation remains unavailable until every active endpoint is verified.', 'ultracache'), verifiedCapabilityEndpoints, configuredCapabilityEndpoints),
			});
		}
		if (connectionConfigured && flushScopeUi.runtimeDegraded) {
			warningItems.push({
				category: 'site-runtime-plan',
				label: __('Site invalidation fallback', 'ultracache'),
				message: String(flushScopeUi.runtimeReason || (siteFlushUsesKnownUrls
					? __('No verified site-wide scope is available. UltraCache can invalidate only the bounded set of local URLs known to its crawl planner.', 'ultracache')
					: __('No immediate site invalidation is verified. Shared-cache objects remain correct through TTL expiry.', 'ultracache'))),
			});
		}
		if (currentBasicRefillFailed) {
			warningItems.push({
				category: 'refill',
				label: __('Refill', 'ultracache'),
				message: String(behaviorResult.message || __('The public refill failed.', 'ultracache')),
			});
		}
		if (esiConfigured && !publicEsiVerified) {
			warningItems.push({
				category: 'esi',
				label: __('ESI', 'ultracache'),
				message: String(esiCapability.message || __('Run Redetect Varnish Capabilities after installing the ESI VCL rules. Until verification succeeds, registered fragments render inline fallback HTML.', 'ultracache')),
			});
		}
		if (queueTerminal > 0 || (queuePending > 0 && queueProcessing === 0 && ['scheduled', 'recovered'].indexOf(refillWorkerStatus) === -1)) {
			let queueWarning = __('Pending work has no active or scheduled worker.', 'ultracache');
			if (queueTerminal > 0) {
				const detailLines = queueTerminalDetails.map((item) => formatQueueTerminalErrorDetail(item, true)).filter(Boolean);
				queueWarning = sprintf(__('%d queue item(s) stopped after their automatic retries.', 'ultracache'), queueTerminal);
				if (detailLines.length) {
					queueWarning += '\n' + detailLines.join('\n');
				}
				if (queueStats.terminalErrorDetailsTruncated) {
					queueWarning += '\n' + __('Additional terminal errors are not shown here.', 'ultracache');
				}
			}
			warningItems.push({ category: 'queue', label: __('Queue', 'ultracache'), message: queueWarning });
		}

		const sharedCacheManaged = sharedCacheEnabled && String(sharedCacheStatus.mode || '') === 'managed';
		const sharedCacheModeLabel = !sharedCacheEnabled
			? __('Disabled', 'ultracache')
			: (sharedCacheManaged
				? sprintf(__('Managed · %d min TTL', 'ultracache'), Number(sharedCacheStatus.managedTtlMinutes || (savedSettings && savedSettings.cacheFreshTtlMinutes) || 1440))
				: sprintf(__('TTL expiry only · %d min', 'ultracache'), automaticExpiryTtlMinutes));
		const parentCacheLabel = !performanceTested
			? __('Not measured', 'ultracache')
			: (!performanceCurrent
				? __('Run measurement again', 'ultracache')
				: (performanceSignalsVisible
					? sprintf(__('%1$s HIT · %2$s cache-served · %3$d bypass', 'ultracache'), formatMeasuredRate(performanceSnapshot.hitRatePercent), formatMeasuredRate(performanceSnapshot.cacheServedRatePercent), Number((performanceSnapshot.statusCounts && performanceSnapshot.statusCounts.BYPASS) || 0))
					: __('Signals hidden', 'ultracache')));
		const parentCacheTone = performanceSignalsVisible
			? (Number(performanceSnapshot.hitRatePercent || 0) >= 80 ? 'success' : 'warning')
			: (performanceTested ? 'warning' : 'neutral');
		const variantSampleLabel = performanceCurrent
			? sprintf(__('%1$d of %2$d variants observed', 'ultracache'), Number(performanceSnapshot.observedVariantCount || 0), Number(performanceSnapshot.expectedVariantCount || 0))
			: __('Not measured', 'ultracache');
		const runtimeFailed = Number(runtimeOutcomes.failed || 0);
		const runtimePartial = Number(runtimeOutcomes.partial || 0);
		const runtimeDegraded = Number(runtimeOutcomes.degraded || 0);
		const runtimeUnsupported = Number(runtimeOutcomes.unsupported || 0);
		const runtimeComplete = Number(runtimeOutcomes.complete || 0);
		const runtimeHealthLabel = runtimeFailed > 0 || runtimePartial > 0 || runtimeUnsupported > 0
			? sprintf(__('%1$d failed · %2$d partial · %3$d unsupported', 'ultracache'), runtimeFailed, runtimePartial, runtimeUnsupported)
			: (runtimeDegraded > 0
				? sprintf(__('%1$d complete · %2$d degraded', 'ultracache'), runtimeComplete, runtimeDegraded)
				: sprintf(__('%d complete operations', 'ultracache'), runtimeComplete));
		const strategyEntries = Object.keys(runtimeStrategies).map((key) => [key, Number(runtimeStrategies[key] || 0)]).filter((item) => item[1] > 0).sort((left, right) => right[1] - left[1]).slice(0, 3);
		const runtimeStrategyLabel = strategyEntries.length
			? strategyEntries.map((item) => String(item[0]).replace(/-/g, ' ') + ' ' + String(item[1])).join(' · ')
			: __('No recorded strategy', 'ultracache');
		const connectionSummaryLabel = exactInvalidationOk
			? __('Connected and working', 'ultracache')
			: (controlConnectionAccepted
				? __('Connected', 'ultracache')
				: (controlConnectionTested
					? __('Connection failed', 'ultracache')
					: (formServers.trim() ? __('Configured — run test', 'ultracache') : __('Not configured', 'ultracache'))));
		const connectionSummaryTone = exactInvalidationOk || controlConnectionAccepted ? 'success' : (formServers.trim() ? 'warning' : 'neutral');
		const cacheClearingSummaryLabel = exactInvalidationOk
			? __('Automatic', 'ultracache')
			: (sharedCacheEnabled ? __('Automatic expiry', 'ultracache') : __('Not active', 'ultracache'));
		const cacheClearingSummaryTone = exactInvalidationOk ? 'success' : (sharedCacheEnabled ? 'warning' : 'neutral');
		const siteRuntimeStrategy = String(flushScopeUi.runtimeStrategy || '').toLowerCase();
		const siteClearingSummaryLabel = siteRuntimeStrategy === 'host-flush'
			? __('Entire-host flush', 'ultracache')
			: (siteRuntimeStrategy === 'html-flush'
				? (flushScopeUi.runtimeDegraded ? __('HTML-only flush · degraded', 'ultracache') : __('HTML-only flush', 'ultracache'))
				: (siteFlushUsesKnownUrls ? __('Known site pages', 'ultracache') : __('Automatic expiry', 'ultracache')));
		const siteClearingSummaryTone = siteRuntimeStrategy === 'host-flush' || (siteRuntimeStrategy === 'html-flush' && !flushScopeUi.runtimeDegraded)
			? 'success'
			: 'warning';
		const measuredHitRate = Number(performanceSnapshot.hitRatePercent || 0);
		const measuredSecondPassMs = Number(performanceSnapshot.secondPassAverageMs || performanceSnapshot.averageSecondPassMs || 0);
		const performanceSummaryLabel = !performanceTested
			? __('Not measured', 'ultracache')
			: (!performanceCurrent
				? __('Run measurement again', 'ultracache')
				: (!performanceSignalsVisible
					? ((measuredSecondPassMs > 0 && measuredSecondPassMs <= 100) ? __('Working well', 'ultracache') : __('Cache signals hidden', 'ultracache'))
					: (measuredHitRate >= 80 || (measuredSecondPassMs > 0 && measuredSecondPassMs <= 100)
						? __('Working well', 'ultracache')
						: __('Needs review', 'ultracache'))));
		const performanceSummaryTone = !performanceTested
			? 'neutral'
			: ((performanceCurrent && ((measuredSecondPassMs > 0 && measuredSecondPassMs <= 100) || (performanceSignalsVisible && measuredHitRate >= 80))) ? 'success' : 'warning');
		const simpleStatusRows = [
			renderCompactStatusRow(__('Varnish', 'ultracache'), connectionSummaryLabel, connectionSummaryTone, exactInvalidationOk || controlConnectionAccepted, 'simple-connection'),
			renderCompactStatusRow(__('Automatic cache clearing', 'ultracache'), cacheClearingSummaryLabel, cacheClearingSummaryTone, exactInvalidationOk || sharedCacheEnabled, 'simple-cache-clearing'),
			renderCompactStatusRow(__('Full-site cache clearing', 'ultracache'), siteClearingSummaryLabel, siteClearingSummaryTone, siteFlushActionAvailable, 'simple-site-clearing'),
			renderCompactStatusRow(__('Performance', 'ultracache'), performanceSummaryLabel, performanceSummaryTone, performanceSummaryTone === 'success', 'simple-performance'),
		];
		const simpleWarnings = warningItems.filter((item) => ['configuration', 'transport', 'invalidation'].indexOf(String(item.category || '')) !== -1);
		const connectionDisabled = busy || infrastructureLocked;

		return h(IntegrationAccordionCard, {
			title: __("Varnish Cache", 'ultracache'),
			description: __("Varnish integration supports two purge methods: HTTP frontend endpoint mode and admin-secret mode. Use the method your host exposes; HTTP mode is optional and is not required when admin-secret mode is configured.", 'ultracache'),
			mainLabel: __("Enable Varnish Cache", 'ultracache'),
			mainDescription: __("Enable the UltraCache Varnish integration. Turning this on opens the accordion; turning it off preserves the saved connection while disabling automatic Varnish runtime actions.", 'ultracache'),
			enabled: varnishEnabled,
			onEnabledChange,
			toggleDisabled: busy || infrastructureLocked,
			toggleDisabledReason: infrastructureLocked ? __("Changing Varnish infrastructure settings requires plugin activation or network plugin management permission.", 'ultracache') : '',
			keyName: 'varnish-cache',
		}, [
			infrastructureLocked ? h('div', { className: 'mt-4 mb-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Redis and Varnish infrastructure settings and actions require plugin activation or network plugin management permission.", 'ultracache')) : null,
			!isAdminMode && endpointWarningMessages.length ? h('div', { className: 'space-y-2 mt-4' }, endpointWarningMessages.map((message, index) => h('div', { className: 'text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2', key: 'varnish-endpoint-warning-' + index }, message))) : null,
			formHasUnsafeEndpoint ? h('div', { className: 'mt-4 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-3 py-2' }, __("This endpoint appears to point at the public WordPress frontend instead of a Varnish listener. External Varnish infrastructure and custom ports are allowed when intentionally configured.", 'ultracache')) : null,
			h(CacheHelperConflictNotice, { diagnostics, busy, onRemove: onRemoveConflictingDropins, onRecheck: onRecheckConflicts }),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(SelectField, {
					label: __("Purge mode", 'ultracache'),
					description: __("Choose HTTP only when your host exposes a local Varnish HTTP purge listener. Choose Admin when your host provides the Varnish admin secret/socket.", 'ultracache'),
					value: form.varnishCliMode || 'http',
					onChange: (value) => onFieldChange('varnishCliMode', value),
					disabled: connectionDisabled,
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
					disabled: connectionDisabled,
					placeholder: isAdminMode ? '127.0.0.1:6082' : '127.0.0.1:82',
					key: 'servers',
				}),
				h('div', { className: 'uc-field-wrap', key: 'key-wrap' }, [
					h(TextField, {
						label: isAdminMode ? 'Admin secret' : 'HTTP token / VCL contract key',
						description: secretExternal
							? 'Configured outside the UltraCache managed block in wp-config.php. It is read-only here.'
							: (secretConfigured ? 'Managed by UltraCache in wp-config.php. Enter a new value to replace it; the current value is never displayed.' : 'Enter a value to save ULTRACACHE_VARNISH_PASSWORD in the UltraCache managed wp-config.php block. The optional CWP VCL v2 contract must use the same 32-128 character [A-Za-z0-9_-] token.'),
						value: form.varnishCliKey || '',
						onChange: (value) => {
							onFieldChange('varnishCliKey', value);
							if (value) {
								onFieldChange('clearVarnishCliKey', false);
							}
						},
						disabled: connectionDisabled || secretExternal,
						placeholder: secretExternal ? 'Externally configured' : (secretConfigured ? 'Leave blank to keep current value' : 'Enter password or token'),
						type: 'password',
						name: 'ultracache_varnish_secret',
						autoComplete: 'off',
						key: 'key-input',
					}),
					secretManaged ? h(Button, {
						onClick: () => onFieldChange('clearVarnishCliKey', !form.clearVarnishCliKey),
						disabled: connectionDisabled || secretExternal,
						variant: form.clearVarnishCliKey ? 'primary' : 'light',
						key: 'clear-key',
					}, form.clearVarnishCliKey ? 'Password will be removed on save' : 'Remove managed password') : null,
				]),
				h(NumberRow, {
					label: __("Timeout (seconds)", 'ultracache'),
					description: isAdminMode ? 'Connection and read timeout for each Varnish admin endpoint. Maximum: 15 seconds.' : 'Connection and read timeout for each Varnish HTTP endpoint. Maximum: 15 seconds.',
					value: form.varnishCliTimeoutSeconds || 2,
					onChange: (value) => onFieldChange('varnishCliTimeoutSeconds', value),
					disabled: connectionDisabled,
					min: 1,
					max: 15,
					step: 1,
					key: 'timeout',
				}),
				h(NumberRow, {
					label: __("Varnish invalidations per minute", 'ultracache'),
					description: __("Limits background PURGE and BAN operations sent to configured Varnish endpoints. This is independent from page warm-up speed.", 'ultracache'),
					value: typeof form.varnishInvalidationsPerMinute === 'undefined' ? 10 : form.varnishInvalidationsPerMinute,
					onChange: (value) => onFieldChange('varnishInvalidationsPerMinute', value),
					disabled: connectionDisabled,
					min: 1,
					max: 600,
					step: 1,
					key: 'invalidation-rate',
				}),
			]),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy || infrastructureLocked, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: onDetect, disabled: busy || infrastructureLocked || !varnish.available, variant: 'light' }, busy ? 'Working…' : __("Detect Varnish Configuration", 'ultracache')),
				h(Button, { onClick: onTest, disabled: busy || infrastructureLocked || formDirty || !connectionConfigured || !varnish.available || actionsBlocked, variant: 'light', title: formDirty ? __("Save Varnish Settings before redetecting capabilities.", 'ultracache') : '' }, busy ? 'Working…' : __("Redetect Varnish Capabilities", 'ultracache')),
				h(Button, { onClick: onFlushAll, disabled: busy || infrastructureLocked || formDirty || !connectionConfigured || !varnish.available || actionsBlocked || !siteFlushActionAvailable, variant: 'light', title: formDirty ? __("Save Varnish Settings before flushing.", 'ultracache') : '' }, busy ? 'Working…' : __("Flush Varnish Cache", 'ultracache')),
			]),
			formDirty ? h('div', { className: 'mt-3 text-xs text-amber-200 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-2' }, __("Unsaved Varnish changes detected. Save the settings before redetecting capabilities or flushing.", 'ultracache')) : null,
			h('div', { className: 'uc-diagnostic-group mt-5', key: 'varnish-simple-status' }, [
				h('div', { className: 'uc-section-title' }, __('Varnish status', 'ultracache')),
				h('div', { className: 'grid grid-cols-1 lg:grid-cols-2 gap-x-6' }, simpleStatusRows),
				simpleWarnings.length ? h('div', { className: 'space-y-2 mt-4' }, renderWarningSummary(simpleWarnings)) : null,
			]),
			renderVarnishDiagnosticsAccordion(varnish, form),
		]);
	}

	function createController(context) {
		const source = context && typeof context === 'object' ? context : {};
		const {
			varnishForm,
			setVarnishForm,
			settingsRef,
			queueSettingsPatch,
			enqueueUiOperation,
			saveSettingsPatch,
			applyDashboardPayload,
			mergeVarnishTestResult,
			queueDashboardAction,
		} = source;

		function updateVarnishEnabled(value) {
			const enabled = !!value;
			setVarnishForm((current) => Object.assign({}, current || {}, { varnishCliEnabled: enabled }));
			queueSettingsPatch({ varnishCliEnabled: enabled });
		}

		function updateVarnishField(key, value) {
			setVarnishForm((current) => {
				const currentForm = current || {};
				const next = Object.assign({}, currentForm, { [key]: value });
				if (key === 'varnishCliMode') {
					const previousMode = currentForm.varnishCliMode || 'http';
					if (isDefaultVarnishServersForMode(currentForm.varnishCliServers, previousMode)) {
						next.varnishCliServers = getDefaultVarnishServersForMode(value);
					}
				}
				return next;
			});

		}

		function buildVarnishSettingsPatch(form, includeSecretChanges = true) {
			const sourceForm = Object.assign({}, form || {});
			const configuredServers = String(sourceForm.varnishCliServers || '').trim();
			const patch = {
				varnishCliEnabled: !!sourceForm.varnishCliEnabled,
				configureVarnishConnection: true,
				varnishCliMode: sourceForm.varnishCliMode || 'http',
				varnishCliServers: configuredServers,
				varnishCliTimeoutSeconds: sourceForm.varnishCliTimeoutSeconds,
				varnishInvalidationsPerMinute: sourceForm.varnishInvalidationsPerMinute,
			};
			if (includeSecretChanges && sourceForm.varnishCliKey) {
				patch.varnishCliKey = sourceForm.varnishCliKey;
			}
			if (includeSecretChanges && sourceForm.clearVarnishCliKey) {
				patch.clearVarnishCliKey = true;
			}
			return patch;
		}

		async function performVarnishCapabilityDetection() {
			try {
				const response = await apiRequest('varnish_behavior_test', {});
				applyDashboardPayload(response || {});
				mergeVarnishTestResult(response || {});
				if (response && response.success === false) {
					throw new Error(formatVarnishResultMessage(response, 'Varnish capability detection failed.'));
				}
				return response;
			} catch (error) {
				const payload = error && error.data && typeof error.data === 'object' ? error.data : null;
				if (payload) {
					applyDashboardPayload(payload || {});
					mergeVarnishTestResult(payload || {});
					const detailedError = new Error(formatVarnishResultMessage(payload, error && error.message ? error.message : 'Varnish capability detection failed.'));
					detailedError.data = payload;
					detailedError.rest = error.rest || null;
					throw detailedError;
				}
				throw error;
			}
		}

		async function redetectVarnishCapabilitiesAndSave(form) {
			const response = await performVarnishCapabilityDetection();
			const finalPatch = buildVarnishSettingsPatch(form, false);
			await saveSettingsPatch(finalPatch);
			return response;
		}

		async function saveVarnishSettings() {
			return enqueueUiOperation('varnish_settings_save', 'Save Varnish settings', async () => {
				const form = Object.assign({}, varnishForm || {});
				const firstPatch = buildVarnishSettingsPatch(form, true);
				const firstResponse = await saveSettingsPatch(firstPatch);
				setVarnishForm((current) => Object.assign({}, current || {}, {
					varnishConnectionConfigured: true,
					varnishCliKey: '',
					clearVarnishCliKey: false,
				}));

				if (!firstPatch.varnishCliEnabled || !String(firstPatch.varnishCliServers || '').trim()) {
					return firstResponse;
				}

				return redetectVarnishCapabilitiesAndSave(form);
			}, {
				processingText: 'Saving Varnish settings, redetecting capabilities, and saving the verified configuration…',
				successText: 'Varnish settings saved and capabilities redetected.',
				failedText: 'Failed to save or verify Varnish settings.',
			});
		}

		function assertVarnishSettingsSaved(actionLabel) {
			if (!isVarnishFormDirty(varnishForm, settingsRef && settingsRef.current ? settingsRef.current : {})) {
				return;
			}
			throw new Error(sprintf(
				/* translators: %s: Varnish action name. */
				__("Save Varnish Settings before running %s.", 'ultracache'),
				String(actionLabel || __("this Varnish action", 'ultracache'))
			));
		}

		async function runVarnishDiscovery() {
			return enqueueUiOperation('varnish_discovery', 'Detect Varnish configuration', async () => {
				try {
					const response = await apiRequest('varnish_discover', {});
					const configuration = response && response.configuration && typeof response.configuration === 'object'
						? response.configuration
						: null;
					if (configuration) {
						const currentSavedSettings = settingsRef && settingsRef.current && typeof settingsRef.current === 'object'
							? settingsRef.current
							: {};
						const nextSavedSettings = Object.assign({}, currentSavedSettings, configuration);
						setVarnishForm((current) => Object.assign({}, current || {}, configuration, {
							varnishCliKey: current && current.varnishCliKey ? current.varnishCliKey : '',
							clearVarnishCliKey: current && current.clearVarnishCliKey ? true : false,
						}));

						if (response.saved && settingsRef) {
							settingsRef.current = nextSavedSettings;
						}
					}
					if (response && response.success === false) {
						throw new Error(response.message || 'Varnish discovery failed.');
					}
					return response;
				} catch (error) {
					const payload = error && error.data && typeof error.data === 'object' ? error.data : null;
					if (payload && payload.configuration && typeof payload.configuration === 'object') {
						setVarnishForm((current) => Object.assign({}, current || {}, payload.configuration));
					}
					throw error;
				}
			}, {
				processingText: 'Checking the homepage and locating candidate Varnish PURGE and BAN endpoints…',
				successText: (result) => result && result.message ? String(result.message) : 'Varnish candidate detected. Save the settings and run Redetect Varnish Capabilities.',
				failedText: 'Varnish configuration discovery failed.',
			});
		}

		async function runVarnishTest() {
			assertVarnishSettingsSaved(__("Redetect Varnish Capabilities", 'ultracache'));
			return enqueueUiOperation('varnish_test', 'Redetect Varnish capabilities', async () => {
				const form = Object.assign({}, varnishForm || {});
				return redetectVarnishCapabilitiesAndSave(form);
			}, {
				processingText: 'Redetecting Varnish connection, invalidation, refill, and runtime capabilities…',
				successText: (result) => result && result.message ? String(result.message) : 'Varnish capabilities redetected.',
				failedText: 'Varnish capability detection failed.',
			});
		}


		async function runVarnishPerformanceSnapshot() {
			assertVarnishSettingsSaved(__("Measure Varnish Performance", 'ultracache'));
			return enqueueUiOperation('varnish_performance_snapshot', 'Measure Varnish performance', async () => {
				try {
					const response = await apiRequest('varnish_performance_snapshot', {});
					applyDashboardPayload(response || {});
					if (response && response.success === false) {
						throw new Error(response.message || 'Varnish performance measurement failed.');
					}
					return response;
				} catch (error) {
					const payload = error && error.data && typeof error.data === 'object' ? error.data : null;
					if (payload) {
						applyDashboardPayload(payload || {});
					}
					throw error;
				}
			}, {
				processingText: 'Measuring bounded Varnish parent-cache and variant performance…',
				successText: (result) => result && result.message ? String(result.message) : 'Varnish performance measurement completed.',
				failedText: 'Varnish performance measurement failed.',
			});
		}

		async function runVarnishFlushAll() {
			assertVarnishSettingsSaved(__("the site Varnish flush", 'ultracache'));
			await queueDashboardAction('varnish_flush_all', { scope: 'configured' }, {
				queued: 'Varnish site flush processing via dashboard…',
				success: 'Varnish site flush finished.',
				failed: 'Varnish site flush failed.',
			}, 'varnish_flush_all');
		}

		async function runVarnishFlushEntireHost() {
			assertVarnishSettingsSaved(__("the entire-host Varnish flush", 'ultracache'));
			await queueDashboardAction('varnish_flush_all', { scope: 'entire-host' }, {
				queued: 'Varnish entire-host flush processing via dashboard…',
				success: 'Varnish entire-host flush finished.',
				failed: 'Varnish entire-host flush failed.',
			}, 'varnish_flush_entire_host');
		}

		return { updateVarnishEnabled, updateVarnishField, saveVarnishSettings, runVarnishDiscovery, runVarnishTest, runVarnishPerformanceSnapshot, runVarnishFlushAll, runVarnishFlushEntireHost };
	}

	admin.define('varnish', {
		getDefaultVarnishServersForMode,
		getStaleGracePolicyPresentation,
		getHtmlVariantCapabilityPresentation,
		isDefaultVarnishServersValue,
		formatVarnishResultDetailLines,
		formatVarnishResultMessage,
		isVarnishFormDirty,
		VarnishCard,
		createController,
	});
})(window);
