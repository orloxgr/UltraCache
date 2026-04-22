/* UltraCache - Dashboard JS (No-Build Version) */
(function () {
	const elementApi = (window.wp && window.wp.element) ? window.wp.element : null;
	const ReactApi = elementApi || window.React;
	const ReactDOMApi = elementApi || window.ReactDOM;
	const { createElement: h, useCallback, useEffect, useMemo, useRef, useState } = ReactApi;

	const rootEl =
		document.getElementById('uc-dashboard') ||
		document.getElementById('ucwp-admin-root') ||
		document.getElementById('ucwp-root') ||
		document.getElementById('ultracache-root');
	if (!rootEl) {
		return;
	}

	const ucwp = window.ucwpData || {};
	const initialSettings = ucwp.settings || {};
	const initialStats = ucwp.stats || {};
	const avifSupport = ucwp.avifSupport || { supported: false };
	const initialDiagnostics = ucwp.diagnostics || initialStats.diagnostics || {};
	const crawlScopeSummary = ucwp.crawlScopeSummary || {};

	const CLEAR_NOTICE_DELAY = 4200;
	const SYSTEM_NOTICE_DELAY = 7000;
	const SYSTEM_NOTICE_COOLDOWN = 24 * 60 * 60 * 1000;
	const STATS_REFRESH_INTERVAL = 15000;
	const JOB_STORAGE_KEY = 'ucwp-dashboard-job-state-v3';
	const DEFAULT_QUEUE_BATCH_SIZE = 100;
	const MAX_ITEM_RETRIES = 2;
	const SUPPORT_MODAL_DELAY = 7000;
	const SUPPORT_MODAL_COOLDOWN = 24 * 60 * 60 * 1000;
	const SUPPORT_LINKS = {
		coffee: 'https://www.paypal.com/ncp/payment/LDBFB3RRB3E9J',
		beer: 'https://www.paypal.com/ncp/payment/G5RNTC3UF58VU',
		meal: 'https://www.paypal.com/ncp/payment/4NP9RNUYRFRFA',
		hire: 'mailto:byron@iniotakis.com?subject=Hire%20me%20for%20WordPress%20work',
	};
	const IMPORT_EXPORT_SETTING_KEYS = [
		'pageCacheEnabled',
		'objectCacheEnabled',
		'objectCacheBackend',
		'redisHost',
		'redisPort',
		'redisDatabase',
		'redisPrefix',
		'redisUseTls',
		'redisPersistent',
		'redisConnectTimeoutMs',
		'redisReadTimeoutMs',
		'brotliEnabled',
		'gzipEnabled',
		'avifConversionEnabled',
		'deferJsEnabled',
		'delayThirdPartyJsEnabled',
		'asyncExternalScriptsEnabled',
		'clsDimensionsEnabled',
		'asyncCssEnabled',
		'lcpImagePriorityEnabled',
		'googleFontsSwapEnabled',
		'googleFontsLocalOptimizationEnabled',
		'selfHostedFontCssOptimizationEnabled',
		'speculationRulesEnabled',
		'browserCacheRulesEnabled',
		'varnishCliEnabled',
		'varnishCliMode',
		'varnishCliServers',
		'varnishCliTimeoutSeconds',
		'varnishCliMethod',
		'varnishCliDebug',
		'preRenderOnSave',
		'woocommerceSafeModeEnabled',
		'cacheCleanupEnabled',
		'cronWarmEnabled',
		'cronWarmStartAfterCleanup',
		'warmAfterScheduledCleanup',
		'cronWarmPagesPerMinute',
		'scheduledWarmLimit',
		'staleWhileRevalidateEnabled',
		'cacheCleanupIntervalHours',
		'cacheFreshTtlMinutes',
		'cacheMaxStaleMinutes',
		'cacheExceptionPaths',
		'cacheExceptionQueryArgs',
		'cacheQueryStringAllowlist',
	];

	function classNames() {
		return Array.prototype.slice.call(arguments).filter(Boolean).join(' ');
	}

	function getLocalStorageSafe() {
		try {
			if (typeof window !== 'undefined' && window.localStorage) {
				return window.localStorage;
			}
		} catch (error) {}
		return null;
	}

	function getSystemNoticeStorageKey(id) {
		return 'ucwp-system-notice:' + String(id || 'notice');
	}

	function shouldShowSystemNotice(id, cooldownMs) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return true;
		}
		const raw = storage.getItem(getSystemNoticeStorageKey(id));
		const lastShown = raw ? Number(raw) : 0;
		if (!lastShown || Number.isNaN(lastShown)) {
			return true;
		}
		return (Date.now() - lastShown) >= Math.max(1000, Number(cooldownMs) || SYSTEM_NOTICE_COOLDOWN);
	}

	function markSystemNoticeShown(id) {
		const storage = getLocalStorageSafe();
		if (!storage) {
			return;
		}
		try {
			storage.setItem(getSystemNoticeStorageKey(id), String(Date.now()));
		} catch (error) {}
	}

	function isMobileViewport() {
		if (typeof window === 'undefined') {
			return false;
		}
		return window.innerWidth <= 782;
	}

	function formatNumber(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toLocaleString() : '0';
	}

	function getDefaultScheduledWarmLimit() {
		const value = Number(crawlScopeSummary.defaultScheduledWarmLimit || 0);
		if (Number.isFinite(value) && value > 0) {
			return value;
		}
		return 15;
	}

	function getScheduledWarmLimitSummary() {
		const menuCount = Number(crawlScopeSummary.menuUrlCount || 0);
		const baseCount = Number(crawlScopeSummary.baseUrlCount || 0);
		const contentCount = Number(crawlScopeSummary.contentUrlCount || 0);
		const totalCount = Number(crawlScopeSummary.estimatedTotal || 0);
		const defaultLimit = getDefaultScheduledWarmLimit();

		if (menuCount > 0 || contentCount > 0 || baseCount > 0 || totalCount > 0) {
			return 'Detected crawl scope: menu URLs ' + formatNumber(menuCount) + ' + content URLs ' + formatNumber(contentCount) + ' + base URLs ' + formatNumber(baseCount) + ' = estimated ' + formatNumber(totalCount) + ' total. Default scheduled limit is ' + formatNumber(defaultLimit) + '.';
		}

		return 'Set the maximum total URLs to process in one scheduled warm queue. Use 0 for unlimited.';
	}

	function formatEventTime(event) {
		if (!event || typeof event !== 'object') {
			return 'No frontend cache event yet';
		}

		const numericTime = Number(event.time);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}

		const mysqlTime = event.time_mysql || (typeof event.time === 'string' ? event.time : '');
		if (mysqlTime) {
			const normalized = String(mysqlTime).replace(' ', 'T');
			const parsed = Date.parse(normalized);
			if (!Number.isNaN(parsed)) {
				return new Date(parsed).toLocaleString();
			}
		}

		return 'No frontend cache event yet';
	}

	function formatPercent(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toFixed(num % 1 === 0 ? 0 : 1) + '%' : '0%';
	}

	function formatLooseTime(value) {
		if (!value) {
			return '—';
		}
		if (typeof value === 'object') {
			return formatEventTime(value);
		}
		const numericTime = Number(value);
		if (Number.isFinite(numericTime) && numericTime > 0) {
			return new Date(numericTime * 1000).toLocaleString();
		}
		const parsed = Date.parse(String(value).replace(' ', 'T'));
		return Number.isNaN(parsed) ? String(value) : new Date(parsed).toLocaleString();
	}


	function formatBytes(value) {
		const num = Number(value || 0);
		if (!Number.isFinite(num) || num <= 0) {
			return '0 B';
		}
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let size = num;
		let index = 0;
		while (size >= 1024 && index < units.length - 1) {
			size /= 1024;
			index += 1;
		}
		const fixed = size >= 100 || 0 === index ? 0 : 1;
		return size.toFixed(fixed) + ' ' + units[index];
	}

	function pickTransferableSettings(source) {
		const picked = {};
		const input = source && typeof source === 'object' ? source : {};

		IMPORT_EXPORT_SETTING_KEYS.forEach((key) => {
			if (Object.prototype.hasOwnProperty.call(input, key)) {
				picked[key] = input[key];
			}
		});

		return picked;
	}

	function buildSettingsExportPayload(source) {
		return {
			format: 'ultracache-settings-v1',
			plugin: 'UltraCache',
			version: ucwp.version || '',
			hotfixBundle: ucwp.hotfixBundle || '',
			exportedAt: new Date().toISOString(),
			site: window.location.origin || '',
			settings: pickTransferableSettings(source),
		};
	}

	function getTransferableSettingsFromImport(rawValue) {
		if (!rawValue || typeof rawValue !== 'object' || Array.isArray(rawValue)) {
			throw new Error('Invalid import file. Expected a JSON object.');
		}

		const candidate = rawValue.settings && typeof rawValue.settings === 'object' && !Array.isArray(rawValue.settings)
			? rawValue.settings
			: rawValue;
		const picked = pickTransferableSettings(candidate);

		if (!Object.keys(picked).length) {
			throw new Error('No supported UltraCache settings were found in this file.');
		}

		return picked;
	}

	function triggerFileDownload(filename, content, mimeType) {
		const blob = new Blob([content], { type: mimeType || 'application/json' });
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
	}

	function sleep(ms) {
		return new Promise((resolve) => setTimeout(resolve, ms));
	}

	async function apiRequest(subAction, params = {}) {
		const routes = {
			stats: { path: 'stats', method: 'GET' },
			purge_all: { path: 'purge-all', method: 'POST' },
			get_crawl_urls: { path: 'crawl-urls', method: 'GET' },
			inspect_url: { path: 'inspect-url', method: 'POST' },
			crawl_page: { path: 'crawl-page', method: 'POST' },
			build_frontpage_css: { path: 'build-frontpage-css', method: 'POST' },
			warm_frontpage_html: { path: 'warm-frontpage-html', method: 'POST' },
			warm_frontpage_html_css: { path: 'warm-frontpage-html-css', method: 'POST' },
			get_media_ids: { path: 'media-ids', method: 'GET' },
			optimize_id: { path: 'optimize-id', method: 'POST' },
			optimize_media: { path: 'optimize-media', method: 'POST' },
			varnish_test: { path: 'varnish/test', method: 'POST' },
			varnish_flush_all: { path: 'varnish/flush-all', method: 'POST' },
			redis_test: { path: 'object-cache/redis-test', method: 'POST' },
			object_cache_flush: { path: 'object-cache/flush', method: 'POST' },
			cron_warm_start: { path: 'cron-warm/start', method: 'POST' },
			cron_warm_stop: { path: 'cron-warm/stop', method: 'POST' },
			cron_warm_tick: { path: 'cron-warm/tick', method: 'POST' },
			settings: { path: 'settings', method: 'POST' },
			save_settings: { path: 'settings', method: 'POST' },
		};

		const route = routes[subAction];
		if (!route || !ucwp.restBase) {
			throw new Error('REST route not available for action: ' + subAction);
		}

		let payload = params;
		let requestUrl = ucwp.restBase + route.path;

		if (route.method === 'GET' && params && typeof params === 'object') {
			const query = new URLSearchParams();
			Object.keys(params).forEach((key) => {
				if (typeof params[key] === 'undefined' || params[key] === null || params[key] === '') {
					return;
				}
				query.append(key, String(params[key]));
			});
			const queryString = query.toString();
			if (queryString) {
				requestUrl += '?' + queryString;
			}
		}

		if (subAction === 'settings') {
			payload = {
				[params.key]: params.value === '1' || params.value === true,
			};
		} else if (subAction === 'save_settings') {
			payload = params.settings_json ? JSON.parse(params.settings_json) : {};
		}

		const response = await fetch(requestUrl, {
			method: route.method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': ucwp.restNonce || '',
				...(route.method !== 'GET' ? { 'Content-Type': 'application/json' } : {}),
			},
			...(route.method !== 'GET' ? { body: JSON.stringify(payload) } : {}),
		});

		let data = null;
		try {
			data = await response.json();
		} catch (error) {}

		if (!response.ok) {
			const message =
				(data && data.message) ||
				(data && data.data && data.data.message) ||
				('HTTP ' + response.status);
			throw new Error(message);
		}

		if (data && data.success === false) {
			const message =
				(data.data && data.data.message) ||
				data.message ||
				'Request failed.';
			throw new Error(message);
		}

		return data;
	}

	function normalizeBatchResponse(data, cursor, limit) {
		const normalizedCursor = typeof cursor === 'string' ? cursor : '';
		const normalizedLimit = Math.max(1, Number(limit || DEFAULT_QUEUE_BATCH_SIZE));

		if (Array.isArray(data)) {
			const total = data.length;
			const items = data.slice(0, normalizedLimit);
			return {
				items,
				total,
				cursor: normalizedCursor,
				limit: normalizedLimit,
				nextCursor: '',
				nextOffset: items.length,
				processed: items.length,
				hasMore: items.length < total,
			};
		}

		const items = Array.isArray(data && data.items) ? data.items : [];
		const total = Math.max(items.length, Number((data && data.total) || 0));
		const processed = typeof (data && data.processed) !== 'undefined'
			? Math.max(0, Number(data.processed || 0))
			: Math.max(0, Number(data && data.nextOffset ? data.nextOffset : items.length));
		const nextCursor = typeof (data && data.nextCursor) === 'string' ? data.nextCursor : '';

		return {
			items,
			total,
			cursor: typeof (data && data.cursor) === 'string' ? data.cursor : normalizedCursor,
			limit: typeof (data && data.limit) !== 'undefined' ? Number(data.limit || normalizedLimit) : normalizedLimit,
			nextCursor: nextCursor,
			nextOffset: typeof (data && data.nextOffset) !== 'undefined' ? Number(data.nextOffset || processed) : processed,
			processed: processed,
			hasMore: typeof (data && data.hasMore) !== 'undefined' ? !!data.hasMore : !!nextCursor,
		};
	}

	function isWarmJobType(type) {
		return ['warm', 'warm_css', 'warm_menu', 'warm_menu_css'].indexOf(type) !== -1;
	}

	function isWarmCssJobType(type) {
		return ['warm_css', 'warm_menu_css'].indexOf(type) !== -1;
	}

	function getWarmScopeForType(type) {
		return ['warm_menu', 'warm_menu_css'].indexOf(type) !== -1 ? 'menu' : 'full';
	}

	async function processJobItem(type, item) {
		let attempt = 0;
		let lastError = null;

		while (attempt <= MAX_ITEM_RETRIES) {
			try {
				if (isWarmJobType(type)) {
					const result = await apiRequest('crawl_page', { url: item });
					const detail = result && result.message ? ' — ' + result.message : '';
					await sleep(40);
					return ((result && result.cached !== false) ? 'Cached: ' : 'Skipped: ') + item + detail;
				}

				const response = await apiRequest('optimize_id', { id: item });
				await sleep(20);
				return response && response.converted ? 'Converted attachment #' + item : 'Skipped attachment #' + item;
			} catch (error) {
				lastError = error;
				if (attempt >= MAX_ITEM_RETRIES) {
					break;
				}
				await sleep((attempt + 1) * 500);
			}
			attempt += 1;
		}

		return (isWarmJobType(type) ? 'Failed: ' + item : 'Failed attachment #' + item) + ' — ' + (lastError && lastError.message ? lastError.message : 'Unknown error');
	}

	async function fetchJobBatch(type, cursor, limit, scope) {
		const action = isWarmJobType(type) ? 'get_crawl_urls' : 'get_media_ids';
		const params = isWarmJobType(type)
			? { cursor: cursor || '', limit, scope: scope || getWarmScopeForType(type) }
			: { offset: Math.max(0, Number(cursor || 0)), limit };
		const response = await apiRequest(action, params);
		return normalizeBatchResponse(response, cursor, limit);
	}

	function ToastViewport({ toasts, onDismiss }) {
		if (!Array.isArray(toasts) || !toasts.length) {
			return null;
		}

		return h('div', { className: 'uc-toast-viewport' },
			toasts.map((toast) => {
				const tone = toast && toast.type ? toast.type : 'info';
				return h('div', { className: classNames('uc-toast', 'uc-toast--' + tone), key: toast.id || toast.text }, [
					h('div', { className: 'uc-toast__body', key: 'body' }, [
						toast.title ? h('div', { className: 'uc-toast__title', key: 'title' }, toast.title) : null,
						h('div', { className: 'uc-toast__text', key: 'text' }, toast.text || ''),
					]),
					h('button', {
						type: 'button',
						className: 'uc-toast__close',
						onClick: () => onDismiss(toast.id),
						'aria-label': 'Dismiss notification',
						key: 'close',
					}, '×'),
				]);
			})
		);
	}


	function SupportLinks({ compact, onHireClick }) {
		const items = [
			{ key: 'coffee', label: 'Buy me a coffee', amount: '€5', href: SUPPORT_LINKS.coffee, kind: 'paypal' },
			{ key: 'beer', label: 'Buy me a beer', amount: '€10', href: SUPPORT_LINKS.beer, kind: 'paypal' },
			{ key: 'meal', label: 'Buy me a meal', amount: '€15', href: SUPPORT_LINKS.meal, kind: 'paypal' },
		];

		return h('div', { className: classNames('uc-support-links', compact ? 'uc-support-links--compact' : '') },
			items.map((item) => h(
				'a',
				{
					key: item.key,
					className: classNames('uc-support-link', 'uc-support-link--paypal'),
					href: item.href,
					target: item.kind === 'paypal' ? '_blank' : undefined,
					rel: item.kind === 'paypal' ? 'noopener noreferrer' : undefined,
					onClick: item.kind === 'hire' && typeof onHireClick === 'function' ? onHireClick : undefined,
				},
				[
					h('span', { className: 'uc-support-link__label', key: 'label' }, item.label),
					h('span', { className: 'uc-support-link__amount', key: 'amount' }, item.amount),
				]
			))
		);
	}

	function SupportInlineCard({ isMobile, onMobileTrigger, onHireClick }) {
		const triggerProps = isMobile
			? {
				role: 'button',
				tabIndex: 0,
				onClick: onMobileTrigger,
				onKeyDown: (event) => {
					if ('Enter' === event.key || ' ' === event.key) {
						event.preventDefault();
						onMobileTrigger();
					}
				},
			}
			: {};

		return h('div', { className: classNames('uc-support-inline', isMobile ? 'uc-support-inline--mobile' : '' ) }, [
			h('div', { className: 'uc-support-inline__copy', key: 'copy' }, [
				h('div', Object.assign({ className: 'uc-support-inline__title' }, triggerProps), 'Support this plugin'),
				!isMobile ? h('p', { className: 'uc-support-inline__text' }, 'If UltraCache saves you time, you can support future development or reach out for help.') : null,
			]),
			isMobile
				? null
				: h('div', { className: 'uc-support-inline__actions', key: 'actions' }, [
					h('div', { className: 'uc-support-inline__support-group', key: 'support-group' }, [
						h('div', { className: 'uc-support-inline__group-label' }, 'Support this plugin'),
						h(SupportLinks, { key: 'paypal-links' }),
						h('div', { className: 'uc-support-inline__need-support', key: 'need-support' }, [
							h('div', { className: 'uc-support-inline__group-label', key: 'need-label' }, 'Need Support?'),
							h('a', {
							href: SUPPORT_LINKS.hire,
							className: 'uc-support-inline__hire-link',
							onClick: typeof onHireClick === 'function' ? onHireClick : undefined,
							key: 'hire-link',
						}, 'Hire me'),
						]),
					]),
				]),
		]);
	}

	function SupportModal({ open, isMobile, onClose, onHireClick }) {
		useEffect(() => {
			if (!open) {
				return undefined;
			}
			const handleKeyDown = (event) => {
				if ('Escape' === event.key) {
					onClose();
				}
			};
			window.addEventListener('keydown', handleKeyDown);
			return () => window.removeEventListener('keydown', handleKeyDown);
		}, [open, onClose]);

		if (!open) {
			return null;
		}

		return h('div', {
			className: classNames('uc-support-modal', isMobile ? 'uc-support-modal--mobile' : 'uc-support-modal--desktop'),
			onClick: onClose,
		}, [
			h('div', {
				className: 'uc-support-modal__dialog',
				onClick: (event) => event.stopPropagation(),
				key: 'dialog',
			}, [
				h('button', {
					type: 'button',
					className: 'uc-support-modal__close',
					onClick: onClose,
					'aria-label': 'Close support modal',
					key: 'close',
				}, '×'),
				h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, 'Support this plugin'),
				h('h3', { className: 'uc-support-modal__title', key: 'title' }, 'Support this plugin'),
				h('p', { className: 'uc-support-modal__text', key: 'text' }, 'If UltraCache saves you time, you can support future updates or contact Byron directly for paid help.'),
				h('div', { className: 'uc-support-modal__section-label', key: 'support-label' }, 'Support this plugin'),
				h(SupportLinks, { compact: isMobile, onHireClick, key: 'links' }),
				h('div', { className: 'uc-support-modal__need-support', key: 'need-support' }, [
					h('div', { className: 'uc-support-modal__section-label', key: 'need-label' }, 'Need Support?'),
					h('a', {
					href: SUPPORT_LINKS.hire,
					className: 'uc-support-modal__hire-link',
					onClick: typeof onHireClick === 'function' ? onHireClick : undefined,
					key: 'hire-link',
				}, 'Hire me'),
			]),
			]),
		]);
	}

	function Card({ title, description, children, footer }) {
		return h('div', { className: 'uc-card' }, [
			h('div', { className: 'mb-5', key: 'head' }, [
				h('h3', { className: 'text-lg font-bold m-0 text-white', key: 'title' }, title),
				description
					? h(
							'p',
							{ className: 'uc-stat-label mt-1 mb-0', key: 'desc' },
							description
					  )
					: null,
			]),
			h('div', { key: 'body' }, children),
			footer
				? h('div', { className: 'mt-5 pt-4 border-t border-white/5', key: 'footer' }, footer)
				: null,
		]);
	}

	function StatCard({ label, value, hint }) {
		return h('div', { className: 'uc-card' }, [
			h('div', { className: 'text-xs uppercase tracking-widest text-zinc-500 mb-2', key: 'label' }, label),
			h('div', { className: 'text-3xl font-black tracking-tight text-white', key: 'value' }, value),
			h('div', { className: 'text-xs text-zinc-500 mt-2', key: 'hint' }, hint || '\u00A0'),
		]);
	}

	function Button({ onClick, disabled, children, variant }) {
		const styleClass =
			variant === 'primary'
				? 'uc-btn--primary text-white'
				: variant === 'light'
				? 'uc-btn--primary text-white'
				: '';

		return h(
			'button',
			{
				className: classNames('uc-btn', styleClass, 'disabled:opacity-50 disabled:cursor-not-allowed'),
				onClick,
				disabled,
			},
			children
		);
	}

	function ToggleRow({ label, description, checked, onChange, disabled }) {
		return h('div', { className: 'flex items-center justify-between py-4 border-b border-white/5 last:border-0' }, [
			h('div', { key: 'left' }, [
				h('div', { className: 'text-sm font-medium text-white' }, label),
				h('div', { className: 'text-xs text-zinc-500' }, description),
			]),
			h(
				'label',
				{
					className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
					key: 'right',
				},
				[
					h('input', {
						type: 'checkbox',
						checked: !!checked,
						disabled: !!disabled,
						onChange: (e) => onChange(e.target.checked),
					}),
					h('span', { className: 'slider' }),
				]
			),
		]);
	}

	function ToggleField({ label, description, checked, onChange, disabled }) {
		return h('div', { className: 'uc-field-wrap' }, [
			h('div', { className: 'flex items-center justify-between gap-4 px-1 py-1' }, [
				h('div', { key: 'left', className: 'min-w-0 flex-1' }, [
					label ? h('div', { className: 'uc-field-label mb-0' }, label) : null,
					description ? h('div', { className: 'text-xs text-zinc-500 mt-1' }, description) : null,
				]),
				h(
					'label',
					{
						className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
						key: 'right',
					},
					[
						h('input', {
							type: 'checkbox',
							checked: !!checked,
							disabled: !!disabled,
							onChange: (e) => onChange(e.target.checked),
						}),
						h('span', { className: 'slider' }),
					]
				),
			]),
		]);
	}

	function TextAreaField({ label, description, value, onChange, disabled, placeholder }) {
		return h('div', { className: 'uc-field-wrap' }, [
			h('label', { className: 'uc-field-label' }, label),
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('textarea', {
				className: 'uc-field-input uc-field-textarea',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function NumberField({ label, description, value, onChange, disabled, min, step }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: 'number',
				className: 'uc-field-input',
				value: value,
				disabled: !!disabled,
				min: typeof min === 'number' ? min : 0,
				step: typeof step === 'number' ? step : 1,
				onChange: (e) => onChange(e.target.value),
			}),
		]);
	}

	function TextField({ label, description, value, onChange, disabled, placeholder, onKeyDown, type }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('input', {
				type: type || 'text',
				className: 'uc-field-input',
				value: value || '',
				disabled: !!disabled,
				placeholder: placeholder || '',
				onChange: (e) => onChange(e.target.value),
				onKeyDown: onKeyDown,
			}),
		]);
	}


	function SelectField({ label, description, value, onChange, disabled, options }) {
		return h('div', { className: 'uc-field-wrap' }, [
			label ? h('label', { className: 'uc-field-label' }, label) : null,
			description ? h('div', { className: 'text-xs text-zinc-500 mb-2' }, description) : null,
			h('select', {
				className: 'uc-field-input',
				value: value || '',
				disabled: !!disabled,
				onChange: (e) => onChange(e.target.value),
			}, (options || []).map((option) => h('option', { value: option.value, key: option.value }, option.label))),
		]);
	}

	function DetailRow({ label, value, mono }) {
		if (value === null || typeof value === 'undefined' || value === '') {
			return null;
		}

		return h('div', { className: 'flex flex-col gap-1 py-2 border-b border-white/5 last:border-0' }, [
			h('div', { className: 'text-[11px] uppercase tracking-widest text-zinc-500' }, label),
			h('div', { className: classNames('text-sm text-white break-all', mono ? 'font-mono text-[12px]' : '') }, String(value)),
		]);
	}

	function AnalyticsSummaryCard({ stats, diagnostics }) {
		const reasons = stats && stats.topBypassReasons && typeof stats.topBypassReasons === 'object' ? stats.topBypassReasons : {};
		const bucketHits = stats && stats.pageCacheBucketHits && typeof stats.pageCacheBucketHits === 'object' ? stats.pageCacheBucketHits : {};
		const encodingHits = stats && stats.pageCacheEncodingHits && typeof stats.pageCacheEncodingHits === 'object' ? stats.pageCacheEncodingHits : {};
		const reasonEntries = Object.entries(reasons);
		const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : {};
		const proxyNotice = reverseProxy && reverseProxy.detected
			? (reverseProxy.message || 'External reverse proxy cache detected. UltraCache page-hit counters reflect only requests that reach PHP/advanced-cache.')
			: '';

		return h(Card, {
			title: 'Cache Analytics',
			description: 'Request counters for cache delivery and cache-generation activity. Warm/preload creates misses and writes, not hits.',
		}, [
			h('div', { className: 'grid grid-cols-2 xl:grid-cols-4 gap-3', key: 'metrics' }, [
				h(StatCard, { key: 'hits', label: 'Cache Hits', value: formatNumber(stats.pageCacheHits), hint: diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'Hits that reached PHP/advanced-cache' : 'Served from advanced-cache' }),
				h(StatCard, { key: 'misses', label: 'Render Misses', value: formatNumber(stats.pageCacheMisses), hint: 'Reached WordPress render path' }),
				h(StatCard, { key: 'bypasses', label: 'Bypasses', value: formatNumber(stats.pageCacheBypasses), hint: 'Skipped before buffering' }),
				h(StatCard, { key: 'ratio', label: 'Hit Ratio', value: formatPercent(stats.pageCacheHitRatio), hint: diagnostics && diagnostics.reverseProxy && diagnostics.reverseProxy.detected ? 'PHP-level ratio only; reverse proxy hits excluded' : 'Hits ÷ (hits + misses)' }),
			]),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-6 mt-5', key: 'detail-grid' }, [
				h('div', { key: 'buckets' }, [
					h('div', { className: 'text-[11px] uppercase tracking-widest text-zinc-500 mb-2' }, 'Served cache buckets'),
					h(DetailRow, { label: 'Original HTML', value: formatNumber(bucketHits.orig || 0) }),
					h(DetailRow, { label: 'WebP HTML', value: formatNumber(bucketHits.webp || 0) }),
					h(DetailRow, { label: 'AVIF HTML', value: formatNumber(bucketHits.avif || 0) }),
					h(DetailRow, { label: 'Identity encoding', value: formatNumber(encodingHits.identity || 0) }),
					h(DetailRow, { label: 'Gzip encoding', value: formatNumber(encodingHits.gzip || 0) }),
					h(DetailRow, { label: 'Brotli encoding', value: formatNumber(encodingHits.brotli || 0) }),
					h(DetailRow, { label: 'Cache writes', value: formatNumber(stats.pageCacheStores || 0) }),
					h(DetailRow, { label: 'Write skips', value: formatNumber(stats.pageCacheStoreSkips || 0) }),
					h(DetailRow, { label: 'Stale hits', value: formatNumber(stats.pageCacheStaleHits || 0) }),
					h(DetailRow, { label: 'Background refreshes', value: formatNumber(stats.pageCacheBackgroundRevalidations || 0) }),
				]),
				h('div', { key: 'reasons' }, [
					h('div', { className: 'text-[11px] uppercase tracking-widest text-zinc-500 mb-2' }, 'Top bypass reasons'),
					reasonEntries.length
						? h('div', { className: 'space-y-2' }, reasonEntries.map(([reason, count]) =>
							h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5 last:border-0', key: reason }, [
								h('div', { className: 'text-sm text-white break-all' }, reason),
								h('div', { className: 'text-xs font-mono text-zinc-400' }, formatNumber(count)),
							])
						))
						: h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No bypasses recorded yet.'),
				]),
			]),
		]);
	}

	function ActivitySummaryCard({ stats }) {
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

		function renderSummaryRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5 last:border-0', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: false, text: row[2], tone: 'neutral' }),
			])));
		}

		function renderStackRows(rows) {
			return h('div', { className: 'space-y-3' }, rows.filter((row) => row[1] !== null && typeof row[1] !== 'undefined' && row[1] !== '').map((row) => h('div', { className: 'py-2 border-b border-white/5 last:border-0', key: row[0] }, [
				h('div', { className: 'text-sm text-white mb-1' }, row[0]),
				h('div', { className: 'text-xs text-zinc-300 break-all whitespace-pre-wrap' }, String(row[1])),
			])));
		}

		return h(Card, {
			title: 'Activity Summary',
			description: 'Recent operational events and warm/object-cache counters.',
		}, [
			h('div', { className: 'uc-diagnostic-group', key: 'activity-counters' }, [
				h('div', { className: 'uc-section-title' }, 'Activity counters'),
				renderSummaryRows(counterRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-status' }, [
				h('div', { className: 'uc-section-title' }, 'Recent status'),
				renderSummaryRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'activity-timeline' }, [
				h('div', { className: 'uc-section-title' }, 'Recent activity'),
				renderStackRows(timelineRows),
			]),
		]);
	}

	function loadSavedJob() {
		try {
			const raw = window.localStorage.getItem(JOB_STORAGE_KEY);
			return raw ? JSON.parse(raw) : null;
		} catch (error) {
			return null;
		}
	}

	function saveJob(job) {
		try {
			window.localStorage.setItem(JOB_STORAGE_KEY, JSON.stringify(job));
		} catch (error) {}
	}

	function clearSavedJob() {
		try {
			window.localStorage.removeItem(JOB_STORAGE_KEY);
		} catch (error) {}
	}

	function StatusPill({ ok, text, tone }) {
		const variant = tone || (ok ? 'success' : 'neutral');
		return h('span', {
			className: classNames(
				'inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider',
				'success' === variant
					? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20'
					: ('warning' === variant
						? 'bg-cyan-500/5 text-cyan-400 border border-cyan-500/20'
						: 'bg-zinc-700/40 text-zinc-300 border border-zinc-600/40')
			),
		}, text);
	}

		function DiagnosticsCard({ diagnostics, stats }) {
		const compressionStatus = diagnostics.compression || {};
		const pathDiagnostics = diagnostics.paths || {};
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const avifDirDiag = pathDiagnostics.avifDir || {};
		const webpDirDiag = pathDiagnostics.webpDir || {};
		const browserCacheRulesDiag = pathDiagnostics.browserCacheRules || {};
		const proxyProviderText = reverseProxy && reverseProxy.provider ? reverseProxy.provider : (reverseProxy && reverseProxy.detected ? 'Detected' : 'Not detected');
		const reverseProxyText = reverseProxy.detected
			? (proxyProviderText + (reverseProxy.x_cache ? ' · ' + reverseProxy.x_cache : '') + (reverseProxy.x_litespeed_cache ? ' · ' + reverseProxy.x_litespeed_cache : '') + (reverseProxy.x_cache_status ? ' · ' + reverseProxy.x_cache_status : ''))
			: 'Not detected';
		const statusRows = [
			['Page Cache', diagnostics.pageCache && diagnostics.pageCache.active, diagnostics.pageCache && diagnostics.pageCache.active ? 'Active' : diagnostics.pageCache && diagnostics.pageCache.enabled ? 'Configured' : 'Disabled'],
			['Object Cache', diagnostics.objectCache && diagnostics.objectCache.active, diagnostics.objectCache && diagnostics.objectCache.active ? ('Active · ' + (diagnostics.objectCache.activeBackend || diagnostics.objectCache.selectedBackend || 'disk')) : diagnostics.objectCache && diagnostics.objectCache.enabled ? ('Configured · ' + (diagnostics.objectCache.selectedBackend || 'disk')) : 'Disabled'],
			['Cron Warm Up', diagnostics.cronWarm && diagnostics.cronWarm.active, diagnostics.cronWarm && diagnostics.cronWarm.active ? ('Running · ' + formatNumber(diagnostics.cronWarm.processed || 0) + '/' + formatNumber(diagnostics.cronWarm.total || 0)) : diagnostics.cronWarm && diagnostics.cronWarm.enabled ? ((diagnostics.cronWarm.completed ? 'Completed' : 'Enabled') + ' · ' + formatNumber(diagnostics.cronWarm.pagesPerMinute || 0) + '/min') : 'Disabled'],
			['Varnish', diagnostics.varnish && diagnostics.varnish.enabled, diagnostics.varnish && diagnostics.varnish.enabled ? (((diagnostics.varnish.mode || 'http').toUpperCase()) + ' · ' + ((((diagnostics.varnish.mode || 'http') === 'admin') ? 'BAN' : (diagnostics.varnish.method || 'BAN')) + ' · ' + ((diagnostics.varnish.servers || '').trim() || 'No endpoints'))) : 'Disabled'],
			['Reverse Proxy', !!reverseProxy.detected, reverseProxyText],
			['Brotli Compression', compressionStatus.brotli && compressionStatus.brotli.available, compressionStatus.brotli && compressionStatus.brotli.available ? (compressionStatus.preferred === 'brotli' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['Gzip Compression', compressionStatus.gzip && compressionStatus.gzip.available, compressionStatus.gzip && compressionStatus.gzip.available ? (compressionStatus.preferred === 'gzip' ? 'Available · Preferred' : 'Available') : 'Unavailable'],
			['AVIF Capability', diagnostics.formats && diagnostics.formats.avif, diagnostics.formats && diagnostics.formats.avif ? 'Available' : 'Unavailable'],
			['WebP Capability', diagnostics.formats && diagnostics.formats.webp, diagnostics.formats && diagnostics.formats.webp ? 'Available' : 'Unavailable'],
		];
		const runtimeRows = [
			['Cache directory', !!cacheDirDiag.exists, cacheDirDiag.exists ? (cacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['Advanced cache drop-in', !!advancedCacheDiag.exists, advancedCacheDiag.exists ? (advancedCacheDiag.managed ? 'Present · Managed by UltraCache' : 'Present · Not managed') : 'Missing'],
			['Object cache drop-in', !!objectCacheDiag.exists, objectCacheDiag.exists ? (objectCacheDiag.managed ? 'Present · Managed by UltraCache' : 'Present · Not managed') : 'Missing'],
			['Runtime config', !!runtimeConfigDiag.exists && !!runtimeConfigDiag.valid, runtimeConfigDiag.exists ? (runtimeConfigDiag.valid ? 'Present · Valid' : 'Present · Invalid') : 'Missing'],
			['Analytics file', !!analyticsDiag.exists && !!analyticsDiag.validJson, analyticsDiag.exists ? (analyticsDiag.validJson ? 'Present · Valid JSON' : 'Present · Invalid JSON') : 'Missing'],
			['Browser cache rules', !!browserCacheRulesDiag.exists && !!browserCacheRulesDiag.managed, browserCacheRulesDiag.exists ? (browserCacheRulesDiag.managed ? 'Present · Managed block found' : 'Present · No UltraCache block') : 'Missing'],
			['Object cache directory', !!objectCacheDirDiag.exists, objectCacheDirDiag.exists ? (objectCacheDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['AVIF cache directory', !!avifDirDiag.exists, avifDirDiag.exists ? (avifDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
			['WebP cache directory', !!webpDirDiag.exists, webpDirDiag.exists ? (webpDirDiag.writable ? 'Present · Writable' : 'Present · Not writable') : 'Missing'],
		];
		const loadedRuntime = runtimeConfigDiag.loaded || {};
		const allowlist = Array.isArray(loadedRuntime.cache_query_allowlist) ? loadedRuntime.cache_query_allowlist : [];
		const runtimeConfigRows = runtimeConfigDiag.valid ? [
			['Fresh TTL', false, formatNumber(loadedRuntime.cache_fresh_ttl_minutes || 0) + ' min'],
			['Max stale window', false, formatNumber(loadedRuntime.cache_max_stale_minutes || 0) + ' min'],
			['Stale-while-revalidate', !!loadedRuntime.stale_while_revalidate_enabled, loadedRuntime.stale_while_revalidate_enabled ? 'Enabled' : 'Disabled'],
			['Query string mode', !!loadedRuntime.cache_query_strings, loadedRuntime.cache_query_strings ? 'Enabled' : 'Disabled'],
			['Query allowlist', false, allowlist.length ? (formatNumber(allowlist.length) + ' key' + (allowlist.length === 1 ? '' : 's') + ' · ' + allowlist.join(', ')) : 'None'],
			['Woo safe mode', !!loadedRuntime.woo_safe_mode, loadedRuntime.woo_safe_mode ? 'Enabled' : 'Disabled'],
			['Excluded paths', false, formatNumber((loadedRuntime.excluded_paths || []).length) + ' path' + (((loadedRuntime.excluded_paths || []).length === 1) ? '' : 's')],
			['Excluded query args', false, formatNumber((loadedRuntime.excluded_query_args || []).length) + ' arg' + (((loadedRuntime.excluded_query_args || []).length === 1) ? '' : 's')],
			['Sync state', !!runtimeConfigDiag.inSync, runtimeConfigDiag.inSync ? 'In sync with dashboard settings' : 'Out of sync with dashboard settings'],
		] : [];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5 last:border-0', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		return h(Card, { title: 'Diagnostics', description: 'Live cache status' }, [
			h('div', { className: 'uc-diagnostic-group', key: 'status-group' }, [
				h('div', { className: 'uc-section-title' }, 'Capabilities'),
				renderRows(statusRows),
			]),
			h('div', { className: 'uc-diagnostic-group', key: 'runtime-group' }, [
				h('div', { className: 'uc-section-title' }, 'Runtime files & paths'),
				renderRows(runtimeRows),
			]),
			runtimeConfigRows.length ? h('div', { className: 'uc-diagnostic-group', key: 'runtime-config-group' }, [
				h('div', { className: 'uc-section-title' }, 'Runtime config in use'),
				renderRows(runtimeConfigRows),
			]) : null,
		]);
	}

	function AdvancedDiagnosticsCard({ diagnostics, stats }) {
		const last = diagnostics.lastEvent || {};
		const objectCacheStatus = diagnostics.objectCache || {};
		const compressionStatus = diagnostics.compression || {};
		const wpCacheStatus = diagnostics.wpCache || {};
		const pathDiagnostics = diagnostics.paths || {};
		const lastCacheWrite = diagnostics.lastCacheWrite || {};
		const lastStatus = last.status || last.type || '—';
		const lastSeen = formatEventTime(last);
		const bucketHits = stats && stats.pageCacheBucketHits && typeof stats.pageCacheBucketHits === 'object' ? stats.pageCacheBucketHits : {};
		const encodingHits = stats && stats.pageCacheEncodingHits && typeof stats.pageCacheEncodingHits === 'object' ? stats.pageCacheEncodingHits : {};
		const reasons = stats && stats.topBypassReasons && typeof stats.topBypassReasons === 'object' ? stats.topBypassReasons : {};
		const reasonEntries = Object.entries(reasons).slice(0, 8);
		const reverseProxy = diagnostics.reverseProxy || {};
		const runtimeConfigDiag = pathDiagnostics.runtimeConfig || {};
		const analyticsDiag = pathDiagnostics.analytics || {};
		const advancedCacheDiag = pathDiagnostics.advancedCache || {};
		const objectCacheDiag = pathDiagnostics.objectCache || {};
		const cacheDirDiag = pathDiagnostics.cacheDir || {};
		const objectCacheDirDiag = pathDiagnostics.objectCacheDir || {};
		const cacheDetailRows = [
			['Original HTML', false, formatNumber(bucketHits.orig || 0)],
			['WebP HTML', false, formatNumber(bucketHits.webp || 0)],
			['AVIF HTML', false, formatNumber(bucketHits.avif || 0)],
			['Identity encoding', false, formatNumber(encodingHits.identity || 0)],
			['Gzip encoding', false, formatNumber(encodingHits.gzip || 0)],
			['Brotli encoding', false, formatNumber(encodingHits.brotli || 0)],
			['Cache writes', false, formatNumber(stats.pageCacheStores || 0)],
			['Write skips', false, formatNumber(stats.pageCacheStoreSkips || 0)],
			['Stale hits', false, formatNumber(stats.pageCacheStaleHits || 0)],
			['Background refreshes', false, formatNumber(stats.pageCacheBackgroundRevalidations || 0)],
			['CLS images scanned', false, formatNumber(stats.clsImagesScanned || 0)],
			['CLS dimensions injected', false, formatNumber(stats.clsDimensionsInjected || 0)],
			['CLS skipped', false, formatNumber(stats.clsImagesSkipped || 0)],
			['CLS unresolved', false, formatNumber(stats.clsImagesUnresolved || 0)],
		];

		function renderRows(rows, tone) {
			return h('div', { className: 'space-y-3' }, rows.map((row) => h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5 last:border-0', key: row[0] }, [
				h('div', { className: 'text-sm text-white' }, row[0]),
				h(StatusPill, { ok: !!row[1], text: row[2], tone: tone || (typeof row[1] === 'boolean' ? (row[1] ? 'success' : 'neutral') : 'neutral') }),
			])));
		}

		function renderPathDetails(label, diag, extraRows) {
			if (!diag || !diag.path) {
				return null;
			}
			return h('div', { className: 'rounded bg-black/10 p-4', key: 'path-' + label }, [
				h('div', { className: 'text-xs font-bold uppercase tracking-widest text-zinc-400 mb-2' }, label),
				h('div', { className: 'space-y-3' }, [
					h(DetailRow, { label: 'Path', value: diag.path, mono: true }),
					h(DetailRow, { label: 'Exists', value: diag.exists ? 'Yes' : 'No' }),
					h(DetailRow, { label: 'Readable', value: diag.readable ? 'Yes' : 'No' }),
					h(DetailRow, { label: 'Writable', value: diag.writable ? 'Yes' : 'No' }),
					!diag.exists ? h(DetailRow, { label: 'Parent writable', value: diag.parentWritable ? 'Yes' : 'No' }) : null,
					diag.managed ? h(DetailRow, { label: 'Managed by UltraCache', value: 'Yes' }) : null,
					diag.size ? h(DetailRow, { label: 'Size', value: formatBytes(diag.size) }) : null,
					diag.modified ? h(DetailRow, { label: 'Modified', value: formatLooseTime(diag.modified) }) : null,
					diag.valid ? h(DetailRow, { label: 'Valid', value: 'Yes' }) : null,
					diag.validJson ? h(DetailRow, { label: 'Valid JSON', value: 'Yes' }) : null,
					diag.readError ? h(DetailRow, { label: 'Read error', value: diag.readError }) : null,
					extraRows || null,
				]),
			]);
		}

		return h('div', { className: 'uc-card' }, [
			h('details', { className: 'uc-accordion uc-accordion--card', key: 'advanced-diagnostics' }, [
				h('summary', { className: 'uc-accordion__summary' }, [
					h('div', { className: 'uc-accordion__summary-copy', key: 'copy' }, [
						h('div', { className: 'uc-accordion__title' }, 'Advanced Diagnostics'),
						h('div', { className: 'uc-accordion__description' }, 'Expanded path, proxy, cache-write, and bypass inspection.'),
					]),
					h('span', { className: 'uc-accordion__chevron', 'aria-hidden': 'true', key: 'chevron' }, '▸'),
				]),
				h('div', { className: 'uc-accordion__body' }, [
					h('div', { className: 'uc-diagnostic-group', key: 'last-cache-write' }, [
						h('div', { className: 'uc-section-title' }, 'Last page cache write'),
						(lastCacheWrite && (lastCacheWrite.path || lastCacheWrite.pageFiles)) ? h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: 'Page cache files', value: formatNumber(lastCacheWrite.pageFiles || 0) }),
							h(DetailRow, { label: 'Last modified', value: formatLooseTime(lastCacheWrite.modified || 0) }),
							h(DetailRow, { label: 'Last file size', value: formatBytes(lastCacheWrite.size || 0) }),
							h(DetailRow, { label: 'Last file path', value: lastCacheWrite.path || '', mono: true }),
							lastCacheWrite.error ? h(DetailRow, { label: 'Scan error', value: lastCacheWrite.error }) : null,
						]) : h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No page cache files have been detected yet.'),
					]),
					(runtimeConfigDiag.path || analyticsDiag.path || advancedCacheDiag.path || objectCacheDiag.path || cacheDirDiag.path || objectCacheDirDiag.path)
						? h('div', { className: 'uc-diagnostic-group', key: 'path-grid' }, [
							h('div', { className: 'uc-section-title' }, 'Path details'),
							h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
								renderPathDetails('advanced-cache.php', advancedCacheDiag),
								renderPathDetails('object-cache.php', objectCacheDiag),
								renderPathDetails('runtime-config.json', runtimeConfigDiag, runtimeConfigDiag.keys && runtimeConfigDiag.keys.length ? h(DetailRow, { label: 'Keys', value: runtimeConfigDiag.keys.join(', ') }) : null),
								renderPathDetails('analytics.json', analyticsDiag, analyticsDiag.keys && analyticsDiag.keys.length ? h(DetailRow, { label: 'Top keys', value: analyticsDiag.keys.join(', ') }) : null),
								renderPathDetails('Cache directory', cacheDirDiag),
								renderPathDetails('Object cache directory', objectCacheDirDiag),
							]),
						])
						: null,
					reverseProxy.detected ? h('div', { className: 'uc-diagnostic-group', key: 'proxy-box' }, [
						h('div', { className: 'uc-section-title' }, 'Reverse Proxy Details'),
						h('div', { className: 'rounded bg-black/10 p-4 space-y-3' }, [
							h(DetailRow, { label: 'Provider', value: reverseProxy.provider || 'Detected' }),
							h(DetailRow, { label: 'Server', value: reverseProxy.server || '' }),
							h(DetailRow, { label: 'Via', value: reverseProxy.via || '' }),
							h(DetailRow, { label: 'X-Cache', value: reverseProxy.x_cache || '' }),
							h(DetailRow, { label: 'X-Cache-Status', value: reverseProxy.x_cache_status || '' }),
							h(DetailRow, { label: 'X-Proxy-Cache', value: reverseProxy.x_proxy_cache || '' }),
							h(DetailRow, { label: 'X-FastCGI-Cache', value: reverseProxy.x_fastcgi_cache || '' }),
							h(DetailRow, { label: 'X-LiteSpeed-Cache', value: reverseProxy.x_litespeed_cache || '' }),
							h(DetailRow, { label: 'X-QC-Cache', value: reverseProxy.x_qc_cache || '' }),
							h(DetailRow, { label: 'CF-Cache-Status', value: reverseProxy.cf_cache_status || '' }),
							h(DetailRow, { label: 'Age', value: reverseProxy.age || '' }),
						]),
					]) : null,
					h('div', { className: 'uc-diagnostic-group', key: 'cache-group' }, [
						h('div', { className: 'uc-section-title' }, 'Cache diagnostics'),
						renderRows(cacheDetailRows, 'neutral'),
					]),
					h('div', { className: 'uc-diagnostic-group', key: 'reasons-group' }, [
						h('div', { className: 'uc-section-title' }, 'Top bypass reasons'),
						reasonEntries.length
							? renderRows(reasonEntries.map(([reason, count]) => [reason, false, formatNumber(count)]), 'neutral')
							: h('div', { className: 'text-xs text-zinc-500 pt-2' }, 'No bypasses recorded yet.'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500 space-y-1', key: 'last' }, [
						h('div', { key: 'status' }, 'Last cache event: ' + lastStatus + (last.reason ? ' · ' + last.reason : '')),
						h('div', { key: 'bucket' }, 'Last bucket: ' + (last.bucket || '—')),
						h('div', { key: 'time' }, 'Last seen: ' + lastSeen),
						compressionStatus.message ? h('div', { key: 'compression-note' }, 'Compression: ' + compressionStatus.message) : null,
						wpCacheStatus.message ? h('div', { key: 'wp-cache-note' }, 'WP_CACHE: ' + wpCacheStatus.message) : null,
						!objectCacheStatus.available && objectCacheStatus.message ? h('div', { key: 'object-cache-note' }, 'File object cache: ' + objectCacheStatus.message) : null,
						reverseProxy.message ? h('div', { key: 'reverse-proxy-note' }, 'Reverse proxy: ' + reverseProxy.message) : null,
					]),
				]),
			]),
		]);
	}

	function ProgressPanel({ process, etaText, onCancel }) {
		if (!process.active) {
			return null;
		}

		const percent =
			process.total > 0 ? Math.round((process.current / process.total) * 100) : 0;

		return h('div', { className: 'bg-emerald-500/5 border border-emerald-500/20 p-6 rounded' }, [
			h('div', { className: 'flex justify-between items-center mb-3 gap-3', key: 'head' }, [
				h(
					'h4',
					{ className: 'text-sm font-bold uppercase tracking-widest text-emerald-400 m-0' },
					process.label || 'Working'
				),
				h('div', { className: 'flex items-center gap-3', key: 'controls' }, [
					h('span', { className: 'text-[10px] text-zinc-500', key: 'eta' }, etaText || ''),
					process.cancellable && typeof onCancel === 'function'
						? h(
							'button',
							{
								className: 'uc-btn !bg-zinc-800 !text-white !border-white/10 !py-2 !px-3 text-xs font-bold',
								onClick: onCancel,
								disabled: !!process.cancelRequested,
								key: 'cancel',
							},
							process.cancelRequested ? 'Stopping…' : 'Cancel'
						  )
						: null,
				]),
			]),
			h('div', { className: 'flex justify-between text-xs mb-2', key: 'meta' }, [
				h('span', { className: 'text-zinc-400' }, 'Overall Progress'),
				h(
					'span',
					{ className: 'text-emerald-400 font-mono' },
					process.total > 0
						? process.current + ' / ' + process.total + ' (' + percent + '%)'
						: 'Preparing…'
				),
			]),
			h('div', { className: 'w-full bg-zinc-800 h-1 rounded-full overflow-hidden', key: 'bar-wrap' }, [
				h('div', {
					className: 'bg-emerald-500 h-full transition-all duration-300',
					style: { width: percent + '%' },
				}),
			]),
			process.logs && process.logs.length
				? h(
						'div',
						{
							className:
								'mt-4 max-h-40 overflow-y-auto text-[11px] font-mono text-zinc-400 bg-black/20 p-3 rounded space-y-1',
							key: 'logs',
						},
						process.logs.slice(-10).map((log, index) =>
							h('div', { key: 'log-' + index }, '> ' + log)
						)
				  )
				: null,
		]);
	}


	function VarnishCard({ form, diagnostics, busy, onFieldChange, onSave, onTest, onFlushAll }) {
		const varnish = diagnostics.varnish || {};
		const last = varnish.last || {};
		const supportMessage = varnish.message || '';
		const detailLines = Array.isArray(last.details) ? last.details.map((item) => {
			return (item.server || 'server') + ': ' + (item.success ? 'OK' : 'FAIL') + (item.detail ? ' · ' + item.detail : '');
		}).join('\n') : '';
		const mode = form.varnishCliMode || 'http';
		const isAdminMode = mode === 'admin';

		return h(Card, {
			title: 'Varnish',
			description: isAdminMode ? 'Send admin BAN commands to one or more Varnish admin endpoints using the shared secret.' : 'Send BAN or PURGE HTTP requests to one or more Varnish/frontend HTTP endpoints when UltraCache purges.',
		}, [
			h(ToggleRow, {
				label: isAdminMode ? 'Enable Varnish admin purge' : 'Enable Varnish HTTP purge',
				description: varnish.available ? (isAdminMode ? 'Propagate UltraCache purge-all and URL purge events through the Varnish admin interface.' : 'Propagate UltraCache purge-all and URL purge events to your Varnish frontend endpoints.') : (supportMessage || 'Unavailable on this server.'),
				checked: !!form.varnishCliEnabled,
				onChange: (value) => onFieldChange('varnishCliEnabled', value),
				disabled: busy || !varnish.available,
			}),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(SelectField, {
					label: 'Mode',
					description: 'Use HTTP for frontend BAN/PURGE endpoints, or Admin when you want to connect to the Varnish admin port with the shared secret.',
					value: form.varnishCliMode || 'http',
					onChange: (value) => onFieldChange('varnishCliMode', value),
					disabled: busy,
					options: [
						{ value: 'http', label: 'HTTP frontend' },
						{ value: 'admin', label: 'Admin (secret)' },
					],
					key: 'mode',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin endpoints' : 'HTTP endpoints',
					description: isAdminMode ? 'Space-separated Varnish admin endpoints in host:port format. Example: 127.0.0.1:6082' : 'Space-separated Varnish/frontend HTTP endpoints. Use host:port for HTTP, or a full URL for custom HTTPS/path targets. Example: 127.0.0.1:82 https://example.com:8443/',
					value: form.varnishCliServers || '',
					onChange: (value) => onFieldChange('varnishCliServers', value),
					disabled: busy,
					placeholder: isAdminMode ? '127.0.0.1:6082' : '127.0.0.1:82',
					key: 'servers',
				}),
				h(TextField, {
					label: isAdminMode ? 'Admin secret' : 'Control key',
					description: isAdminMode ? (form.varnishCliKeyConfigured ? 'A saved Varnish admin secret already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Shared secret used to authenticate against the Varnish admin interface.') : (form.varnishCliKeyConfigured ? 'A saved token header already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Optional token sent as the X-UltraCache-Token header with Varnish HTTP requests.'),
					value: form.varnishCliKey || '',
					onChange: (value) => onFieldChange('varnishCliKey', value),
					disabled: busy,
					placeholder: isAdminMode ? 'varnish-secret' : 'your-secret-key',
					key: 'key',
				}),
				h(SelectField, {
					label: 'Command type',
					description: isAdminMode ? 'Admin mode uses the Varnish admin interface. BAN is the effective action even if you change this selector.' : 'BAN is safer across most builds. PURGE automatically falls back to BAN on status 101.',
					value: form.varnishCliMethod || 'BAN',
					onChange: (value) => onFieldChange('varnishCliMethod', value),
					disabled: busy,
					options: isAdminMode ? [{ value: 'BAN', label: 'BAN' }] : [
						{ value: 'BAN', label: 'BAN' },
						{ value: 'PURGE', label: 'PURGE' },
					],
					key: 'method',
				}),
				h(NumberField, {
					label: 'Timeout (seconds)',
					description: isAdminMode ? 'Connection and read timeout for each Varnish admin endpoint.' : 'Connection and read timeout for each Varnish HTTP endpoint.',
					value: form.varnishCliTimeoutSeconds || 2,
					onChange: (value) => onFieldChange('varnishCliTimeoutSeconds', value),
					disabled: busy,
					min: 1,
					step: 1,
					key: 'timeout',
				}),
			]),
			h(ToggleRow, {
				label: 'Debug log',
				description: 'Write Varnish request details to wp-content/cache/ultracache/logs/varnish-cli.log',
				checked: !!form.varnishCliDebug,
				onChange: (value) => onFieldChange('varnishCliDebug', value),
				disabled: busy,
				key: 'debug',
			}),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Varnish Settings'),
				h(Button, { onClick: onTest, disabled: busy || !form.varnishCliEnabled || !varnish.available, variant: 'light' }, busy ? 'Working…' : 'Run Varnish Test'),
				h(Button, { onClick: onFlushAll, disabled: busy || !form.varnishCliEnabled || !varnish.available, variant: 'light' }, busy ? 'Working…' : 'Flush Varnish All'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Support'),
						h(StatusPill, { ok: !!varnish.available, text: varnish.available ? 'Available' : 'Unavailable', tone: varnish.available ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Mode'),
						h(StatusPill, { ok: true, text: (form.varnishCliMode || 'http').toUpperCase(), tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Configured endpoints'),
						h(StatusPill, { ok: false, text: form.varnishCliServers ? form.varnishCliServers : '—', tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Last result'),
						h(StatusPill, { ok: !!last.success, text: last.message || 'No Varnish action yet', tone: last.message ? (!!last.success ? 'success' : 'warning') : 'neutral' }),
					]),
				]),
				supportMessage ? h('div', { className: 'text-xs text-zinc-500 mt-4' }, supportMessage) : null,
				last.time ? h('div', { className: 'text-xs text-zinc-500 mt-2' }, 'Last run: ' + formatLooseTime(last.time)) : null,
				detailLines ? h('div', { className: 'text-xs text-zinc-400 mt-3 whitespace-pre-wrap break-all' }, detailLines) : null,
			]),
		]);
	}


	function RedisCard({ form, diagnostics, busy, onFieldChange, onSave, onTest, onFlush }) {
		const objectCache = diagnostics.objectCache || {};
		const redis = objectCache.redis || {};
		const backend = form.objectCacheBackend || 'disk';
		const redisSupportText = redis.available ? 'PHP Redis extension detected on this server.' : (redis.message || 'PHP Redis extension not detected. Disk fallback stays available.');
		const transportText = [form.redisUseTls ? 'TLS' : 'TCP', form.redisPersistent ? 'Persistent connections ON' : 'Persistent connections OFF'].join(' · ');
		const statusText = objectCache.active
			? ('Active backend: ' + (objectCache.activeBackend || backend))
			: (objectCache.enabled ? ('Configured backend: ' + backend) : 'Object cache is disabled.');
		const connectionText = redis.connected
			? 'Connected'
			: (redis.message || 'Not tested yet');

		return h(Card, {
			title: 'Object Cache Backend',
			description: 'Choose Disk or Redis for object caching. Disk remains the safe fallback backend.',
		}, [
			h(SelectField, {
				label: 'Object cache backend',
				description: 'Use Redis when available. If Redis cannot connect, or a cache value is too large or complex, UltraCache falls back to Disk automatically.',
				value: backend,
				onChange: (value) => onFieldChange('objectCacheBackend', value),
				disabled: busy,
				options: [
					{ value: 'disk', label: 'Use Disk (fallback)' },
					{ value: 'redis', label: 'Use Redis' },
				],
				key: 'backend',
			}),
			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
				h(TextField, {
					label: 'Redis host',
					description: 'Common default: 127.0.0.1',
					value: form.redisHost || '127.0.0.1',
					onChange: (value) => onFieldChange('redisHost', value),
					disabled: busy,
					placeholder: '127.0.0.1',
					key: 'redis-host',
				}),
				h(NumberField, {
					label: 'Redis port',
					description: 'Common default: 6379',
					value: form.redisPort || 6379,
					onChange: (value) => onFieldChange('redisPort', value),
					disabled: busy,
					min: 1,
					step: 1,
					key: 'redis-port',
				}),
				h(TextField, {
					label: 'Redis password',
					description: form.redisPasswordConfigured ? 'A saved Redis password already exists. Leave blank to keep it, or enter a new one to replace it.' : 'Leave empty when the server does not require auth.',
					value: form.redisPassword || '',
					onChange: (value) => onFieldChange('redisPassword', value),
					disabled: busy,
					placeholder: 'optional',
					type: 'password',
					key: 'redis-password',
				}),
				h(NumberField, {
					label: 'Redis database',
					description: 'Usually 0. Typical range: 0-15.',
					value: typeof form.redisDatabase === 'undefined' ? 0 : form.redisDatabase,
					onChange: (value) => onFieldChange('redisDatabase', value),
					disabled: busy,
					min: 0,
					step: 1,
					key: 'redis-db',
				}),
				h(TextField, {
					label: 'Redis prefix / namespace',
					description: 'Optional. Leave blank for automatic site-specific prefix.',
					value: form.redisPrefix || '',
					onChange: (value) => onFieldChange('redisPrefix', value),
					disabled: busy,
					placeholder: 'leave blank for auto',
					key: 'redis-prefix',
				}),
				h(NumberField, {
					label: 'Connect timeout (ms)',
					description: 'Advanced. Default: 200ms.',
					value: typeof form.redisConnectTimeoutMs === 'undefined' ? 200 : form.redisConnectTimeoutMs,
					onChange: (value) => onFieldChange('redisConnectTimeoutMs', value),
					disabled: busy,
					min: 50,
					step: 50,
					key: 'redis-connect-timeout',
				}),
				h(ToggleField, {
					label: 'Use TLS',
					description: 'Enable for managed Redis providers that require TLS/SSL transport.',
					checked: !!form.redisUseTls,
					onChange: (value) => onFieldChange('redisUseTls', value),
					disabled: busy,
					key: 'redis-use-tls',
				}),
				h(ToggleField, {
					label: 'Persistent connection',
					description: 'Advanced. Reuse the Redis connection across PHP worker requests when supported.',
					checked: !!form.redisPersistent,
					onChange: (value) => onFieldChange('redisPersistent', value),
					disabled: busy,
					key: 'redis-persistent',
				}),
				h(NumberField, {
					label: 'Read timeout (ms)',
					description: 'Advanced. Default: 200ms.',
					value: typeof form.redisReadTimeoutMs === 'undefined' ? 200 : form.redisReadTimeoutMs,
					onChange: (value) => onFieldChange('redisReadTimeoutMs', value),
					disabled: busy,
					min: 50,
					step: 50,
					key: 'redis-read-timeout',
				}),
			]),
			h('div', { className: 'mt-4 flex flex-wrap gap-3' }, [
				h(Button, { onClick: onSave, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Object Cache Settings'),
				h(Button, { onClick: onTest, disabled: busy, variant: 'secondary' }, busy ? 'Working…' : 'Test Redis Connection'),
				h(Button, { onClick: onFlush, disabled: busy, variant: 'ghost' }, busy ? 'Working…' : 'Flush Object Cache'),
			]),
			h('div', { className: 'uc-diagnostic-group mt-5' }, [
				h('div', { className: 'uc-section-title' }, 'Status'),
				h('div', { className: 'space-y-3' }, [
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Selected backend'),
						h(StatusPill, { ok: backend === 'redis' ? !!redis.available : true, text: backend === 'redis' ? 'Redis' : 'Disk', tone: backend === 'redis' ? (redis.available ? 'success' : 'warning') : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Disk fallback'),
						h(StatusPill, { ok: true, text: objectCache.fallbackBackend || 'disk', tone: 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Redis support'),
						h(StatusPill, { ok: !!redis.available, text: redis.available ? 'Available' : 'Unavailable', tone: redis.available ? 'success' : 'warning' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Redis connection'),
						h(StatusPill, { ok: !!redis.connected, text: connectionText, tone: redis.connected ? 'success' : (backend === 'redis' ? 'warning' : 'neutral') }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Runtime status'),
						h(StatusPill, { ok: !!objectCache.active, text: statusText, tone: objectCache.active ? 'success' : 'neutral' }),
					]),
					h('div', { className: 'flex items-center justify-between gap-4 py-2 border-b border-white/5' }, [
						h('div', { className: 'text-sm text-white' }, 'Effective prefix'),
						h('code', { className: 'text-xs text-zinc-300 break-all' }, redis.prefix || 'auto'),
					]),
				]),
				h('div', { className: 'text-xs text-zinc-500 mt-4' }, redisSupportText),
			]),
		]);
	}

	function App() {
		const [settings, setSettings] = useState(initialSettings);
		const [stats, setStats] = useState(initialStats);
		const [diagnostics, setDiagnostics] = useState(initialDiagnostics);
		const [busy, setBusy] = useState(false);
		const [toasts, setToasts] = useState([]);
		const [isMobile, setIsMobile] = useState(isMobileViewport());
		const [supportModalOpen, setSupportModalOpen] = useState(false);
		const [advancedForm, setAdvancedForm] = useState({
			cacheExceptionPaths: initialSettings.cacheExceptionPaths || '',
			cacheExceptionQueryArgs: initialSettings.cacheExceptionQueryArgs || '',
			cacheQueryStringAllowlist: initialSettings.cacheQueryStringAllowlist || '',
			cacheCleanupIntervalHours: initialSettings.cacheCleanupIntervalHours || 24,
			cronWarmPagesPerMinute: typeof initialSettings.cronWarmPagesPerMinute === 'undefined' ? 2 : initialSettings.cronWarmPagesPerMinute,
			scheduledWarmLimit: typeof initialSettings.scheduledWarmLimit === 'undefined' ? getDefaultScheduledWarmLimit() : initialSettings.scheduledWarmLimit,
			cacheFreshTtlMinutes: initialSettings.cacheFreshTtlMinutes || 15,
			cacheMaxStaleMinutes: initialSettings.cacheMaxStaleMinutes || 720,
		});
		const [varnishForm, setVarnishForm] = useState({
			varnishCliEnabled: !!initialSettings.varnishCliEnabled,
			varnishCliMode: initialSettings.varnishCliMode || 'http',
			varnishCliServers: initialSettings.varnishCliServers || '127.0.0.1:80',
			varnishCliKey: initialSettings.varnishCliKey || '',
			varnishCliTimeoutSeconds: initialSettings.varnishCliTimeoutSeconds || 2,
			varnishCliMethod: initialSettings.varnishCliMethod || 'BAN',
			varnishCliDebug: !!initialSettings.varnishCliDebug,
			varnishCliKeyConfigured: !!initialSettings.varnishCliKeyConfigured,
		});
		const [redisForm, setRedisForm] = useState({
			objectCacheBackend: initialSettings.objectCacheBackend || 'disk',
			redisHost: initialSettings.redisHost || '127.0.0.1',
			redisPort: initialSettings.redisPort || 6379,
			redisPassword: initialSettings.redisPassword || '',
			redisDatabase: typeof initialSettings.redisDatabase === 'undefined' ? 0 : initialSettings.redisDatabase,
			redisPrefix: initialSettings.redisPrefix || '',
			redisUseTls: !!initialSettings.redisUseTls,
			redisPersistent: !!initialSettings.redisPersistent,
			redisConnectTimeoutMs: typeof initialSettings.redisConnectTimeoutMs === 'undefined' ? 200 : initialSettings.redisConnectTimeoutMs,
			redisReadTimeoutMs: typeof initialSettings.redisReadTimeoutMs === 'undefined' ? 200 : initialSettings.redisReadTimeoutMs,
			redisPasswordConfigured: !!initialSettings.redisPasswordConfigured,
		});
		const [inspectUrl, setInspectUrl] = useState('');
		const [inspectBusy, setInspectBusy] = useState(false);
		const [inspectResult, setInspectResult] = useState(null);
		const [frontpageHtmlBusy, setFrontpageHtmlBusy] = useState(false);
		const [frontpageHtmlCssBusy, setFrontpageHtmlCssBusy] = useState(false);
		const [allUrlsCssBusy, setAllUrlsCssBusy] = useState(false);
		const [menuUrlsCssBusy, setMenuUrlsCssBusy] = useState(false);
		const [savedJob, setSavedJob] = useState(loadSavedJob());
		const cancelRequestedRef = useRef(false);
		const importFileInputRef = useRef(null);
		const statsRefreshInFlightRef = useRef(false);
		const [process, setProcess] = useState({
			active: false,
			label: '',
			current: 0,
			total: 0,
			logs: [],
			startTime: 0,
			cancellable: false,
			cancelRequested: false,
		});

		const dismissToast = useCallback((id) => {
			if (!id) {
				return;
			}
			setToasts((current) => current.filter((toast) => toast.id !== id));
		}, []);

		const pushToast = useCallback((toast) => {
			if (!toast || !toast.text) {
				return null;
			}

			const nextToast = Object.assign({
				id: toast.id || ('toast-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
				type: 'info',
				persistent: false,
				duration: CLEAR_NOTICE_DELAY,
			}, toast || {});

			setToasts((current) => {
				const filtered = current.filter((item) => item.id !== nextToast.id);
				return filtered.concat([nextToast]).slice(-6);
			});

			return nextToast.id;
		}, []);

		useEffect(() => {
			if (!Array.isArray(toasts) || !toasts.length) {
				return undefined;
			}

			const timers = toasts
				.filter((toast) => toast && !toast.persistent)
				.map((toast) => setTimeout(() => dismissToast(toast.id), typeof toast.duration === 'number' ? toast.duration : CLEAR_NOTICE_DELAY));

			return () => timers.forEach((timer) => clearTimeout(timer));
		}, [toasts, dismissToast]);

		useEffect(() => {
			const reverseProxy = diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy : null;
			if (reverseProxy && reverseProxy.detected) {
				if (shouldShowSystemNotice('reverse-proxy', SYSTEM_NOTICE_COOLDOWN)) {
					markSystemNoticeShown('reverse-proxy');
					pushToast({
						id: 'system-reverse-proxy',
						type: 'warning',
						title: 'Reverse proxy detected',
						text: reverseProxy.message || 'UltraCache hit counters reflect only requests that reach PHP/advanced-cache and may under-report public hits served before WordPress.',
						persistent: false,
						duration: SYSTEM_NOTICE_DELAY,
					});
				}
			} else {
				dismissToast('system-reverse-proxy');
			}
		}, [diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.detected : false, diagnostics && diagnostics.reverseProxy ? diagnostics.reverseProxy.message : '', pushToast, dismissToast]);

		useEffect(() => {
			const handleResize = () => setIsMobile(isMobileViewport());
			handleResize();
			window.addEventListener('resize', handleResize);
			return () => window.removeEventListener('resize', handleResize);
		}, []);

		useEffect(() => {
			if (isMobile) {
				setSupportModalOpen(false);
				return undefined;
			}

			if (!shouldShowSystemNotice('support-promo', SUPPORT_MODAL_COOLDOWN)) {
				return undefined;
			}

			markSystemNoticeShown('support-promo');
			setSupportModalOpen(true);

			return undefined;
		}, [isMobile]);

		useEffect(() => {
			const runRefresh = async () => {
				if (statsRefreshInFlightRef.current) {
					return;
				}
				statsRefreshInFlightRef.current = true;
				try {
					await refreshStats();
				} catch (error) {}
				finally {
					statsRefreshInFlightRef.current = false;
				}
			};

			runRefresh();
			const interval = window.setInterval(runRefresh, STATS_REFRESH_INTERVAL);
			return () => window.clearInterval(interval);
		}, []);

		useEffect(() => {
			setAdvancedForm({
				cacheExceptionPaths: settings.cacheExceptionPaths || '',
				cacheExceptionQueryArgs: settings.cacheExceptionQueryArgs || '',
				cacheQueryStringAllowlist: settings.cacheQueryStringAllowlist || '',
				cacheCleanupIntervalHours: settings.cacheCleanupIntervalHours || 24,
				cronWarmPagesPerMinute: typeof settings.cronWarmPagesPerMinute === 'undefined' ? 2 : settings.cronWarmPagesPerMinute,
				scheduledWarmLimit: typeof settings.scheduledWarmLimit === 'undefined' ? getDefaultScheduledWarmLimit() : settings.scheduledWarmLimit,
				cacheFreshTtlMinutes: settings.cacheFreshTtlMinutes || 15,
				cacheMaxStaleMinutes: settings.cacheMaxStaleMinutes || 720,
			});
		}, [
			settings.cacheExceptionPaths,
			settings.cacheExceptionQueryArgs,
			settings.cacheQueryStringAllowlist,
			settings.cacheCleanupIntervalHours,
			settings.cronWarmPagesPerMinute,
			settings.scheduledWarmLimit,
			settings.cacheFreshTtlMinutes,
			settings.cacheMaxStaleMinutes,
		]);


		useEffect(() => {
			setVarnishForm({
				varnishCliEnabled: !!settings.varnishCliEnabled,
				varnishCliMode: settings.varnishCliMode || 'http',
				varnishCliServers: settings.varnishCliServers || '127.0.0.1:80',
				varnishCliKey: settings.varnishCliKey || '',
				varnishCliTimeoutSeconds: settings.varnishCliTimeoutSeconds || 2,
				varnishCliMethod: settings.varnishCliMethod || 'BAN',
				varnishCliDebug: !!settings.varnishCliDebug,
				varnishCliKeyConfigured: !!settings.varnishCliKeyConfigured,
			});
		}, [
			settings.varnishCliEnabled,
			settings.varnishCliMode,
			settings.varnishCliServers,
			settings.varnishCliKey,
			settings.varnishCliTimeoutSeconds,
			settings.varnishCliMethod,
			settings.varnishCliDebug,
			settings.varnishCliKeyConfigured,
		]);

		useEffect(() => {
			setRedisForm({
				objectCacheBackend: settings.objectCacheBackend || 'disk',
				redisHost: settings.redisHost || '127.0.0.1',
				redisPort: settings.redisPort || 6379,
				redisPassword: settings.redisPassword || '',
				redisDatabase: typeof settings.redisDatabase === 'undefined' ? 0 : settings.redisDatabase,
				redisPrefix: settings.redisPrefix || '',
				redisUseTls: !!settings.redisUseTls,
				redisPersistent: !!settings.redisPersistent,
				redisConnectTimeoutMs: typeof settings.redisConnectTimeoutMs === 'undefined' ? 200 : settings.redisConnectTimeoutMs,
				redisReadTimeoutMs: typeof settings.redisReadTimeoutMs === 'undefined' ? 200 : settings.redisReadTimeoutMs,
				redisPasswordConfigured: !!settings.redisPasswordConfigured,
			});
		}, [
			settings.objectCacheBackend,
			settings.redisHost,
			settings.redisPort,
			settings.redisPassword,
			settings.redisDatabase,
			settings.redisPrefix,
			settings.redisUseTls,
			settings.redisPersistent,
			settings.redisConnectTimeoutMs,
			settings.redisReadTimeoutMs,
			settings.redisPasswordConfigured,
		]);

		const etaText = useMemo(() => {
			if (!process.active || !process.current || !process.total || !process.startTime) {
				return '';
			}

			const elapsed = Date.now() - process.startTime;
			const perItem = elapsed / process.current;
			const remaining = Math.max(0, (process.total - process.current) * perItem);
			const seconds = Math.round(remaining / 1000);

			if (seconds < 60) {
				return seconds + 's remaining';
			}

			return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's remaining';
		}, [process]);


		function updateVarnishField(key, value) {
			setVarnishForm((current) => Object.assign({}, current, { [key]: value }));
		}

		function updateRedisField(key, value) {
			setRedisForm((current) => Object.assign({}, current, { [key]: value }));
		}

		async function saveRedisSettings() {
			if (busy) {
				return;
			}

			const next = Object.assign({}, settings, redisForm || {});
			delete next.redisPasswordConfigured;
			if (!String((redisForm && redisForm.redisPassword) || '').trim()) {
				delete next.redisPassword;
			}
			setBusy(true);
			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(next) });
				if (response && response.settings) {
					setSettings(response.settings);
				}
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}
				pushToast({ type: 'success', text: 'Object cache backend settings saved.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to save object cache settings.' });
			} finally {
				setBusy(false);
			}
		}

		async function testRedisConnection() {
			if (busy) {
				return;
			}

			setBusy(true);
			try {
				const result = await apiRequest('redis_test', Object.assign({}, redisForm || {}));
				setDiagnostics((current) => Object.assign({}, current || {}, {
					objectCache: Object.assign({}, (current && current.objectCache) || {}, {
						redis: Object.assign({}, (((current && current.objectCache) || {}).redis) || {}, result || {}),
					}),
				}));
				pushToast({ type: result && result.success ? 'success' : 'error', text: result && result.message ? result.message : 'Redis connection test finished.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Redis connection test failed.' });
			} finally {
				setBusy(false);
			}
		}

		async function flushObjectCache() {
			if (busy) {
				return;
			}

			setBusy(true);
			try {
				const result = await apiRequest('object_cache_flush');
				if (result && result.stats) {
					setStats(normalizeStatsResponse(result));
				}
				if (result && result.diagnostics) {
					setDiagnostics(result.diagnostics);
				} else {
					await refreshStats();
				}
				pushToast({ type: result && result.success ? 'success' : 'error', text: result && result.message ? result.message : 'Object cache flush finished.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Object cache flush failed.' });
			} finally {
				setBusy(false);
			}
		}

		async function saveVarnishSettings() {
			if (busy) {
				return;
			}

			const next = Object.assign({}, settings, varnishForm || {});
			delete next.varnishCliKeyConfigured;
			if (!String((varnishForm && varnishForm.varnishCliKey) || '').trim()) {
				delete next.varnishCliKey;
			}
			setBusy(true);
			try {
				const response = await apiRequest('save_settings', { settings_json: JSON.stringify(next) });
				if (response && response.settings) {
					setSettings(response.settings);
				}
				if (response && response.stats) {
					setStats(response.stats);
				}
				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}
				pushToast({ type: 'success', text: 'Varnish settings saved.' });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to save Varnish settings.' });
			} finally {
				setBusy(false);
			}
		}

		function reloadDashboardPage(delay) {
			const timeout = typeof delay === 'number' ? delay : 450;
			window.setTimeout(function() {
				window.location.reload();
			}, timeout);
		}

		async function runVarnishTest() {
			if (busy) {
				return;
			}
			setBusy(true);
			try {
				const result = await apiRequest('varnish_test');
				await refreshStats();
				const success = !!(result && result.success);
				pushToast({ type: success ? 'success' : 'error', text: result && result.message ? result.message : 'Varnish test completed.' });
				if (success) {
					reloadDashboardPage();
					return;
				}
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Varnish test failed.' });
			} finally {
				setBusy(false);
			}
		}

		async function runVarnishFlushAll() {
			if (busy) {
				return;
			}
			setBusy(true);
			try {
				const result = await apiRequest('varnish_flush_all');
				await refreshStats();
				const success = !!(result && result.success);
				pushToast({ type: success ? 'success' : 'error', text: result && result.message ? result.message : 'Varnish flush finished.' });
				if (success) {
					reloadDashboardPage();
					return;
				}
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Varnish flush failed.' });
			} finally {
				setBusy(false);
			}
		}

		function normalizeStatsResponse(payload) {
			if (payload && typeof payload === 'object' && payload.stats && typeof payload.stats === 'object') {
				return payload.stats;
			}

			return payload && typeof payload === 'object' ? payload : {};
		}

		async function refreshStats() {
			const response = await apiRequest('stats');
			const freshStats = normalizeStatsResponse(response);
			const hasMeaningfulStats = freshStats && typeof freshStats === 'object' && (
				typeof freshStats.pageCacheFiles !== 'undefined' ||
				typeof freshStats.pageCacheHits !== 'undefined' ||
				typeof freshStats.objectCacheEntries !== 'undefined' ||
				typeof freshStats.cacheSizeBytes !== 'undefined' ||
				typeof freshStats.cacheSizeHuman !== 'undefined' ||
				typeof freshStats.optimizedImages !== 'undefined' ||
				typeof freshStats.imagesOptimized !== 'undefined'
			);

			if (hasMeaningfulStats) {
				setStats(freshStats);
				setDiagnostics((freshStats && freshStats.diagnostics) || initialDiagnostics || {});
			}
		}

		async function warmFrontpageHtml() {
			if (busy || frontpageHtmlBusy || frontpageHtmlCssBusy || process.active) {
				return;
			}

			setFrontpageHtmlBusy(true);
			try {
				const result = await apiRequest('warm_frontpage_html');
				await refreshStats();
				pushToast({
					type: result && result.success ? 'success' : 'error',
					text: result && result.message ? result.message : 'Frontpage HTML warm completed.',
				});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Frontpage HTML warm failed.' });
			} finally {
				setFrontpageHtmlBusy(false);
			}
		}

		async function warmFrontpageHtmlCss() {
			if (busy || frontpageHtmlBusy || frontpageHtmlCssBusy || process.active) {
				return;
			}

			setFrontpageHtmlCssBusy(true);
			try {
				const result = await apiRequest('warm_frontpage_html_css');
				await refreshStats();
				pushToast({
					type: (result && (result.success || result.skipped)) ? 'success' : 'error',
					text: result && result.message ? result.message : 'Frontpage HTML + CSS warm completed.',
				});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Frontpage HTML + CSS warm failed.' });
			} finally {
				setFrontpageHtmlCssBusy(false);
			}
		}

		async function startWarmingAllWithFrontpageCss(forceRestart = false) {
			const controls = getJobControls('warm_css');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm_css',
				label: 'Warming Full Site HTML Cache + Frontpage CSS',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting full site crawler + frontpage CSS…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}

		async function startMenuWarming(forceRestart = false) {
			const controls = getJobControls('warm_menu');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm_menu',
				scope: 'menu',
				label: 'Warming Menu HTML Cache',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting menu URL crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}

		async function startMenuWarmingWithFrontpageCss(forceRestart = false) {
			const controls = getJobControls('warm_menu_css');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm_menu_css',
				scope: 'menu',
				label: 'Warming Menu HTML Cache + Frontpage CSS',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting menu URL crawler + frontpage CSS…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}

		async function updateSetting(key, value) {
			if (busy) {
				return;
			}

			const previous = settings;
			const next = Object.assign({}, previous, { [key]: value });

			setSettings(next);
			setBusy(true);

			try {
				const response = await apiRequest('settings', {
					key,
					value: value ? '1' : '0',
				});

				if (response && response.settings) {
					setSettings(response.settings);
				}

				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				} else {
					await refreshStats();
				}

				pushToast({ type: 'success', text: 'Settings updated.' });
			} catch (error) {
				setSettings(previous);
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to update settings.',
				});
			} finally {
				setBusy(false);
			}
		}

		function updateAdvancedField(key, value) {
			setAdvancedForm((prev) => Object.assign({}, prev, { [key]: value }));
		}

		async function saveAdvancedSettings() {
			if (busy) {
				return;
			}

			setBusy(true);
			try {
				const response = await apiRequest('save_settings', {
					settings_json: JSON.stringify({
						cacheExceptionPaths: advancedForm.cacheExceptionPaths || '',
						cacheExceptionQueryArgs: advancedForm.cacheExceptionQueryArgs || '',
						cacheQueryStringAllowlist: advancedForm.cacheQueryStringAllowlist || '',
						cacheCleanupIntervalHours: Number(advancedForm.cacheCleanupIntervalHours || 24),
						cronWarmPagesPerMinute: Number(advancedForm.cronWarmPagesPerMinute || 0),
						scheduledWarmLimit: Number(advancedForm.scheduledWarmLimit || advancedForm.cronWarmPagesPerMinute || 0),
						cacheFreshTtlMinutes: Number(advancedForm.cacheFreshTtlMinutes || 1440),
						cacheMaxStaleMinutes: Number(advancedForm.cacheMaxStaleMinutes || 10080),
					}),
				});

				if (response && response.settings) {
					setSettings(response.settings);
				}

				if (response && response.stats) {
					setStats(response.stats);
				}

				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}

				pushToast({ type: 'success', text: 'Advanced settings saved.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to save advanced settings.',
				});
			} finally {
				setBusy(false);
			}
		}

		async function inspectCacheDecision() {
			if (inspectBusy || !String(inspectUrl || '').trim()) {
				return;
			}

			setInspectBusy(true);
			try {
				const response = await apiRequest('inspect_url', { url: String(inspectUrl || '').trim() });
				setInspectResult(response || null);
			} catch (error) {
				setInspectResult(null);
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to inspect URL.',
				});
			} finally {
				setInspectBusy(false);
			}
		}


		function persistJobState(job) {
			if (job && job.type) {
				saveJob(job);
				setSavedJob(job);
			} else {
				clearSavedJob();
				setSavedJob(null);
			}
		}

		function getJobControls(type) {
			if (!savedJob || savedJob.type !== type) {
				return { canResume: false, canRestart: false };
			}

			const processed = Math.max(0, Number(savedJob.processed || 0));
			const total = Math.max(0, Number(savedJob.total || 0));
			const hasPending = Array.isArray(savedJob.pendingItems) && savedJob.pendingItems.length > 0;
			const hasProgress = processed > 0 || total > 0 || hasPending || (Array.isArray(savedJob.logs) && savedJob.logs.length > 0);
			const incomplete = hasPending || !!savedJob.hasMore || total === 0 || processed < total;

			return {
				canResume: hasProgress && incomplete,
				canRestart: hasProgress,
			};
		}

		function updateProcessState(state, overrides = {}) {
			setProcess({
				active: !!state.active,
				label: state.label || '',
				current: Number(state.processed || 0),
				total: Number(state.total || 0),
				logs: Array.isArray(state.logs) ? state.logs : [],
				startTime: Number(state.startTime || 0),
				cancellable: !!state.active,
				cancelRequested: !!state.cancelRequested,
				...overrides,
			});
		}

		function requestCancel() {
			if (!process.active || cancelRequestedRef.current) {
				return;
			}
			cancelRequestedRef.current = true;
			setProcess((prev) => Object.assign({}, prev, { cancelRequested: true }));
		}

		async function runJob(job, forceRestart) {
			let state = Object.assign({}, job, {
				cursor: forceRestart ? '' : (typeof job.cursor === 'string' ? job.cursor : ''),
				nextCursor: forceRestart ? '' : (typeof job.nextCursor === 'string' ? job.nextCursor : ''),
				processed: forceRestart ? 0 : Number(job.processed || 0),
				total: Number(job.total || 0),
				pendingItems: forceRestart ? [] : (Array.isArray(job.pendingItems) ? job.pendingItems.slice(0, DEFAULT_QUEUE_BATCH_SIZE) : []),
				hasMore: forceRestart ? true : (typeof job.hasMore === 'boolean' ? job.hasMore : true),
				logs: Array.isArray(job.logs) ? job.logs.slice(-50) : [],
				active: true,
				cancelRequested: false,
				startTime: forceRestart ? Date.now() : (job.startTime || Date.now()),
				batchSize: Math.max(1, Number(job.batchSize || DEFAULT_QUEUE_BATCH_SIZE)),
			});
			let completed = false;
			cancelRequestedRef.current = false;
			setBusy(true);
			updateProcessState(state);
			persistJobState(state);

			try {
				while (true) {
					if (cancelRequestedRef.current) {
						state = Object.assign({}, state, {
							active: false,
							cancelRequested: true,
							logs: state.logs.concat(['Paused by user.']).slice(-50),
						});
						persistJobState(state);
						updateProcessState(state, { active: false, cancellable: false });
						pushToast({ type: 'success', text: 'Job paused. You can resume it later.' });
						break;
					}

					let batchItems = Array.isArray(state.pendingItems) ? state.pendingItems.slice() : [];
					let batchNextCursor = state.nextCursor || state.cursor || '';
					let batchHasMore = typeof state.hasMore === 'boolean' ? state.hasMore : true;

					if (!batchItems.length) {
						const batch = await fetchJobBatch(state.type, state.cursor || '', state.batchSize, state.scope || getWarmScopeForType(state.type));
						batchItems = Array.isArray(batch.items) ? batch.items.slice() : [];
						batchNextCursor = batch.nextCursor || '';
						batchHasMore = !!batch.hasMore;
						state = Object.assign({}, state, {
							total: Math.max(Number(state.total || 0), Number(batch.total || 0)),
							hasMore: batchHasMore,
							nextCursor: batchNextCursor,
							pendingItems: batchItems.slice(),
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (!batchItems.length) {
						completed = !batchHasMore;
						if (!completed) {
							state = Object.assign({}, state, {
								cursor: batchNextCursor,
								nextCursor: '',
								hasMore: batchHasMore,
							});
							persistJobState(state);
						}
						if (completed) {
							break;
						}
						continue;
					}

					for (let i = 0; i < batchItems.length; i++) {
						if (cancelRequestedRef.current) {
							break;
						}

						const item = batchItems[i];
						const line = await processJobItem(state.type, item);
						state = Object.assign({}, state, {
							processed: Number(state.processed || 0) + 1,
							logs: state.logs.concat([line]).slice(-50),
							pendingItems: batchItems.slice(i + 1),
							nextCursor: batchNextCursor,
							hasMore: batchHasMore,
							cancelRequested: cancelRequestedRef.current,
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (cancelRequestedRef.current) {
						continue;
					}

					state = Object.assign({}, state, {
						cursor: batchNextCursor,
						nextCursor: '',
						pendingItems: [],
						hasMore: batchHasMore,
					});
					updateProcessState(state);
					persistJobState(state);

					if (!batchHasMore && !state.pendingItems.length) {
						completed = true;
						break;
					}
				}

				if (completed) {
					let finalNotice = { type: 'success', text: isWarmJobType(state.type) ? 'Cache warming complete.' : 'Media optimization complete.' };
					if (isWarmCssJobType(state.type)) {
						const setCssBusy = 'warm_menu_css' === state.type ? setMenuUrlsCssBusy : setAllUrlsCssBusy;
						const successText = 'warm_menu_css' === state.type ? 'Menu URLs warmed and frontpage CSS built.' : 'Full site HTML warmed and frontpage CSS built.';
						const errorText = 'warm_menu_css' === state.type ? 'Menu URLs warmed, but frontpage CSS build failed.' : 'Full site HTML warmed, but frontpage CSS build failed.';
						setCssBusy(true);
						try {
							const cssResult = await apiRequest('build_frontpage_css');
							finalNotice = {
								type: (cssResult && (cssResult.success || cssResult.skipped)) ? 'success' : 'error',
								text: cssResult && cssResult.message ? cssResult.message : successText,
							};
						} catch (cssError) {
							finalNotice = { type: 'error', text: cssError && cssError.message ? cssError.message : errorText };
						} finally {
							setCssBusy(false);
						}
					}
					await refreshStats();
					pushToast(finalNotice);
					setProcess((prev) => Object.assign({}, prev, { active: false, cancellable: false, cancelRequested: false }));
					persistJobState(null);
				}
			} catch (error) {
				state = Object.assign({}, state, { active: false, cancelRequested: false });
				persistJobState(state);
				updateProcessState(state, { active: false, cancellable: false, cancelRequested: false });
				pushToast({ type: 'error', text: error && error.message ? error.message : (isWarmJobType(state.type) ? 'Cache warming failed.' : 'Media optimization failed.') });
			} finally {
				cancelRequestedRef.current = false;
				setBusy(false);
			}
		}

		async function startWarming(forceRestart = false) {
			const controls = getJobControls('warm');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'warm',
				label: 'Warming Full Site HTML Cache',
				cursor: '',
				nextCursor: '',
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting full site crawler…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}

		async function startMediaOptimization(forceRestart = false) {
			const controls = getJobControls('media');
			if (!forceRestart && controls.canResume) {
				await runJob(savedJob, false);
				return;
			}

			if (busy) {
				return;
			}

			await runJob({
				type: 'media',
				label: 'Optimizing Media',
				cursor: 0,
				nextCursor: 0,
				processed: 0,
				total: 0,
				pendingItems: [],
				hasMore: true,
				logs: ['Starting media conversion…'],
				startTime: Date.now(),
				batchSize: DEFAULT_QUEUE_BATCH_SIZE,
			}, !!forceRestart);
		}


		function exportSettingsFile() {
			try {
				const payload = buildSettingsExportPayload(settings);
				const stamp = new Date().toISOString().replace(/[:.]/g, '-');
				const filename = 'ultracache-settings-' + (ucwp.version || 'export') + '-' + stamp + '.json';
				triggerFileDownload(filename, JSON.stringify(payload, null, 2), 'application/json');
				pushToast({ type: 'success', text: 'Settings exported.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to export settings.',
				});
			}
		}

		function openImportSettingsDialog() {
			if (busy || !importFileInputRef.current) {
				return;
			}

			importFileInputRef.current.value = '';
			importFileInputRef.current.click();
		}

		async function importSettingsFile(event) {
			const input = event && event.target ? event.target : null;
			const file = input && input.files && input.files[0] ? input.files[0] : null;
			if (!file || busy) {
				return;
			}

			setBusy(true);
			try {
				const rawText = await file.text();
				const parsed = JSON.parse(rawText);
				const importedSettings = getTransferableSettingsFromImport(parsed);
				const response = await apiRequest('save_settings', {
					settings_json: JSON.stringify(importedSettings),
				});

				if (response && response.settings) {
					setSettings(response.settings);
				}

				if (response && response.stats) {
					setStats(response.stats);
				}

				if (response && response.diagnostics) {
					setDiagnostics(response.diagnostics);
				}

				pushToast({ type: 'success', text: 'Settings imported from ' + file.name + '.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to import settings.',
				});
			} finally {
				if (input) {
					input.value = '';
				}
				setBusy(false);
			}
		}

		async function purgeCache() {
			if (busy) {
				return;
			}

			setBusy(true);

			try {
				await apiRequest('purge_all');
				await refreshStats();
				pushToast({ type: 'success', text: 'All cache files cleared.' });
			} catch (error) {
				pushToast({
					type: 'error',
					text: error && error.message ? error.message : 'Failed to purge cache.',
				});
			} finally {
				setBusy(false);
			}
		}



		function openSupportModal() {
			setSupportModalOpen(true);
		}

		function closeSupportModal() {
			setSupportModalOpen(false);
		}

		function handleHireClick() {
			setSupportModalOpen(false);
		}

		return h('div', { className: 'max-w-6xl p-6 space-y-8' }, [
			h('header', { className: 'flex flex-col gap-4 md:flex-row md:justify-between md:items-end', key: 'header' }, [
				h('div', { key: 'title' }, [
					h('h1', { className: 'text-3xl font-black tracking-tighter m-0 text-white' }, 'UltraCache'),
					h(
						'p',
						{ className: 'text-zinc-500 text-xs tracking-widest uppercase mt-2 mb-0' },
						'Page cache, object cache, compression, warmups, fonts, and next-gen images'
					),
				]),
				h('div', { className: 'flex flex-wrap gap-3', key: 'actions' }, [
					h(Button, { onClick: purgeCache, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Flush All Cache'),
				]),
			]),

			h(ToastViewport, { toasts, onDismiss: dismissToast, key: 'toast-viewport' }),
			h(SupportModal, {
				open: supportModalOpen,
				isMobile,
				onClose: closeSupportModal,
				onHireClick: handleHireClick,
				key: 'support-modal',
			}),

			h(ProgressPanel, { process, etaText, onCancel: requestCancel, key: 'progress' }),

			h('div', { className: 'uc-summary-grid', key: 'stats' }, [
				h(StatCard, {
					label: 'Cached Pages',
					value: formatNumber(
						typeof stats.pagesCached !== 'undefined' ? stats.pagesCached : stats.pageCacheFiles
					),
					hint: 'Stored HTML cache files',
					key: 'pages',
				}),
				h(StatCard, {
					label: 'Optimized Images',
					value: formatNumber(
						typeof stats.imagesOptimized !== 'undefined' ? stats.imagesOptimized : stats.optimizedImages
					),
					hint:
						formatNumber(
							typeof stats.avifImagesOptimized !== 'undefined' ? stats.avifImagesOptimized : stats.avifFiles
						) + ' AVIF · ' + formatNumber(
							typeof stats.webpImagesOptimized !== 'undefined' ? stats.webpImagesOptimized : stats.webpFiles
						) + ' WebP',
					key: 'images',
				}),
				h(StatCard, {
					label: 'Object Entries',
					value: formatNumber(stats.objectCacheEntries),
					hint: stats.objectCacheSizeHuman || 'File object cache',
					key: 'object-cache',
				}),
				h(StatCard, {
					label: 'Cache Size',
					value: stats.cacheSizeHuman || '0 B',
					hint: 'Total cache footprint',
					key: 'size',
				}),
				h(StatCard, { key: 'hits', label: 'Cache Hits', value: formatNumber(stats.pageCacheHits), hint: 'Served from advanced-cache' }),
				h(StatCard, { key: 'misses', label: 'Render Misses', value: formatNumber(stats.pageCacheMisses), hint: 'Reached WordPress render path' }),
				h(StatCard, { key: 'bypasses', label: 'Bypasses', value: formatNumber(stats.pageCacheBypasses), hint: 'Skipped before buffering' }),
				h(StatCard, { key: 'ratio', label: 'Hit Ratio', value: formatPercent(stats.pageCacheHitRatio), hint: 'Hits ÷ (hits + misses)' }),
			]),

			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4', key: 'jobs' }, [
				h(
					Card,
					{
						title: 'Warm Cache',
						description: 'Crawl public URLs and prebuild static cache files.',
						key: 'warm',
					},
					[
						h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: warmFrontpageHtml,
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy ? 'Engine Busy' : (frontpageHtmlBusy ? 'Warming Frontpage HTML…' : 'Warm Up Frontpage HTML Cache')
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: warmFrontpageHtmlCss,
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlBusy || menuUrlsCssBusy || allUrlsCssBusy ? 'Engine Busy' : (frontpageHtmlCssBusy ? 'Warming Frontpage HTML + CSS…' : 'Warm Up Frontpage HTML Cache + Frontpage CSS')
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarming(false),
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy ? 'Engine Busy' : (getJobControls('warm_menu').canResume ? 'Resume Warm Up Menu HTML Cache' : 'Warm Up Menu HTML Cache')
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startMenuWarmingWithFrontpageCss(false),
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || allUrlsCssBusy ? 'Engine Busy' : (menuUrlsCssBusy ? 'Building Frontpage CSS…' : (getJobControls('warm_menu_css').canResume ? 'Resume Warm Up Menu HTML Cache + Frontpage CSS' : 'Warm Up Menu HTML Cache + Frontpage CSS'))
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarming(false),
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy ? 'Engine Busy' : (getJobControls('warm').canResume ? 'Resume Warm Up Full Site HTML Cache' : 'Warm Up Full Site HTML Cache')
							),
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[220px] text-white py-3 font-bold',
									onClick: () => startWarmingAllWithFrontpageCss(false),
									disabled: busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy || allUrlsCssBusy,
								},
								busy || process.active || frontpageHtmlBusy || frontpageHtmlCssBusy || menuUrlsCssBusy ? 'Engine Busy' : (allUrlsCssBusy ? 'Building Frontpage CSS…' : (getJobControls('warm_css').canResume ? 'Resume Warm Up Full Site HTML Cache + Frontpage CSS' : 'Warm Up Full Site HTML Cache + Frontpage CSS'))
							)
						]),
						h('div', { className: 'mt-4 text-xs text-zinc-400 space-y-1' }, [
							h('div', { key: 'fp-css-1' }, 'Frontpage CSS bundles built: ' + formatNumber(stats.frontpageCssBundlesBuilt || 0)),
							h('div', { key: 'fp-css-2' }, 'Frontpage styles bundled: ' + formatNumber(stats.frontpageCssStylesBundled || 0) + ' / ' + formatNumber(stats.frontpageCssStylesScanned || 0)),
							h('div', { key: 'fp-css-3' }, 'Skipped: ' + formatNumber(stats.frontpageCssStylesSkipped || 0) + ' · Unresolved: ' + formatNumber(stats.frontpageCssStylesUnresolved || 0)),
							h('div', { key: 'fp-css-4' }, 'Last frontpage CSS warm: ' + formatLooseTime(stats.lastFrontpageCssWarm || null)),
							(stats.lastFrontpageCssWarm && stats.lastFrontpageCssWarm.message)
								? h('div', { key: 'fp-css-5', className: 'text-zinc-500' }, stats.lastFrontpageCssWarm.message)
								: null,
						]),
					]
				),
				h(
					Card,
					{
						title: 'AVIF / WebP Batch',
						description: 'Convert existing uploads and generated image sizes to AVIF with WebP fallback.',
						key: 'avif',
						footer: h('div', { className: 'text-xs text-zinc-500 space-y-1' }, [
							h('div', { key: 's1' }, 'Imagick: ' + (avifSupport.imagick ? 'Yes' : 'No')),
							h('div', { key: 's2' }, 'Imagick AVIF: ' + (avifSupport.imagick_avif ? 'Yes' : 'No')),
							h('div', { key: 's3' }, 'Imagick WebP: ' + (avifSupport.imagick_webp ? 'Yes' : 'No')),
							h('div', { key: 's4' }, 'GD AVIF: ' + (avifSupport.gd_avif ? 'Yes' : 'No')),
							h('div', { key: 's5' }, 'GD WebP: ' + (avifSupport.gd_webp ? 'Yes' : 'No')),
						]),
					},
					[
						!avifSupport.supported
							? h(
									'div',
									{
										className:
											'mb-4 p-3 bg-rose-500/10 border border-rose-500/20 rounded text-rose-400 text-xs',
									},
									'This server cannot generate AVIF or WebP yet. Install Imagick with AVIF/WebP support or a GD build that includes imageavif()/imagewebp().' 
							  )
							: null,
						h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
							h(
								'button',
								{
									className: 'uc-btn uc-btn--primary flex-1 min-w-[180px] text-white py-3 font-bold',
									onClick: () => startMediaOptimization(false),
									disabled: busy || !avifSupport.supported,
								},
								busy ? 'Engine Busy' : (getJobControls('media').canResume ? 'Resume Media Conversion' : 'Start Media Conversion')
							),
							getJobControls('media').canRestart
								? h(
									'button',
									{
										className: 'uc-btn uc-btn--primary flex-1 min-w-[180px] text-white py-3 font-bold',
										onClick: () => startMediaOptimization(true),
										disabled: busy || !avifSupport.supported,
									},
									busy ? 'Engine Busy' : 'Restart Media Conversion'
								)
								: null,
						])
					]
				),
			]),

			h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-8', key: 'settings' }, [
				h(
					Card,
					{
						title: 'Cache Engine',
						description: 'Controls that affect page cache behavior and preload.',
						key: 'cache-engine',
					},
					[
						h(ToggleRow, {
							label: 'Page Caching',
							description: 'Store public pages as static HTML files.',
							checked: settings.pageCacheEnabled,
							onChange: (value) => updateSetting('pageCacheEnabled', value),
							disabled: busy,
							key: 'page',
						}),
						h(ToggleRow, {
							label: 'Pre-render on Save',
							description: 'Warm the updated page after content changes.',
							checked: settings.preRenderOnSave,
							onChange: (value) => updateSetting('preRenderOnSave', value),
							disabled: busy,
							key: 'preload',
						}),
						h(ToggleRow, {
							label: 'Defer JavaScript',
							description: 'Add defer to non-critical frontend scripts.',
							checked: settings.deferJsEnabled,
							onChange: (value) => updateSetting('deferJsEnabled', value),
							disabled: busy,
							key: 'defer',
						}),
						h(ToggleRow, {
							label: 'Delay Third-Party JavaScript',
							description: 'Delay common analytics, maps, and tracking scripts until interaction or a short fallback timeout.',
							checked: settings.delayThirdPartyJsEnabled,
							onChange: (value) => updateSetting('delayThirdPartyJsEnabled', value),
							disabled: busy,
							key: 'delay-third-party',
						}),
						h(ToggleRow, {
							label: 'Async External Scripts',
							description: 'Add async only to known external analytics and pixel scripts such as Google Tag Manager, Google Analytics, Meta Pixel, Bing, Clarity, Fathom, and Plausible.',
							checked: settings.asyncExternalScriptsEnabled,
							onChange: (value) => updateSetting('asyncExternalScriptsEnabled', value),
							disabled: busy || settings.delayThirdPartyJsEnabled,
							key: 'async-external-scripts',
						}),
						h(ToggleRow, {
							label: 'Safe CLS Dimensions',
							description: 'Inject missing width and height on local images using attachment metadata first and local file dimensions as fallback.',
							checked: settings.clsDimensionsEnabled,
							onChange: (value) => updateSetting('clsDimensionsEnabled', value),
							disabled: busy,
							key: 'cls-dimensions',
						}),
						h(ToggleRow, {
							label: 'Safe Async CSS',
							description: 'Rewrite only low-risk local stylesheet links to non-blocking print+onload loading with a noscript fallback.',
							checked: settings.asyncCssEnabled,
							onChange: (value) => updateSetting('asyncCssEnabled', value),
							disabled: busy,
							key: 'async-css',
						}),
						h(ToggleRow, {
							label: 'LCP Image Priority',
							description: 'Preload the first likely hero image and prevent lazy loading on that candidate.',
							checked: settings.lcpImagePriorityEnabled,
							onChange: (value) => updateSetting('lcpImagePriorityEnabled', value),
							disabled: busy,
							key: 'lcp-priority',
						}),
						h(ToggleRow, {
							label: 'Google Fonts display=swap',
							description: 'Append display=swap to Google Fonts requests generated by themes and plugins.',
							checked: settings.googleFontsSwapEnabled,
							onChange: (value) => updateSetting('googleFontsSwapEnabled', value),
							disabled: busy,
							key: 'fonts-swap',
						}),
						h(ToggleRow, {
							label: 'Local Google Fonts Optimization',
							description: 'Download Google Fonts CSS and WOFF2 files into the UltraCache cache, rewrite the frontend to serve local copies, and keep font-display: swap on the localized CSS.',
							checked: settings.googleFontsLocalOptimizationEnabled,
							onChange: (value) => updateSetting('googleFontsLocalOptimizationEnabled', value),
							disabled: busy,
							key: 'google-fonts-local',
						}),
						h(ToggleRow, {
							label: 'Optimize Self-Hosted Font CSS',
							description: 'Rewrite local @font-face CSS to add font-display: swap, normalize font URLs, and preload up to two likely first-paint WOFF2 files.',
							checked: settings.selfHostedFontCssOptimizationEnabled,
							onChange: (value) => updateSetting('selfHostedFontCssOptimizationEnabled', value),
							disabled: busy,
							key: 'self-hosted-fonts',
						}),
						h(ToggleRow, {
							label: 'Speculation Rules Prefetch',
							description: 'Inject a safe prefetch-only speculationrules block for likely next-page internal navigations. Logged-in users, query-string links, WooCommerce flows, admin-like paths, nofollow links, and target/download links stay excluded.',
							checked: settings.speculationRulesEnabled,
							onChange: (value) => updateSetting('speculationRulesEnabled', value),
							disabled: busy,
							key: 'speculation-rules',
					}),
						h(ToggleRow, {
							label: 'WooCommerce Safe Mode',
							description: 'Bypass cart, checkout, account, order endpoints, and cart-changing requests.',
							checked: settings.woocommerceSafeModeEnabled,
							onChange: (value) => updateSetting('woocommerceSafeModeEnabled', value),
							disabled: busy,
							key: 'woo-safe',
						}),
						h(ToggleRow, {
							label: 'Scheduled Cache Cleanup',
							description: 'Run an automatic full cache purge using the interval below.',
							checked: settings.cacheCleanupEnabled,
							onChange: (value) => updateSetting('cacheCleanupEnabled', value),
							disabled: busy,
							key: 'cleanup',
						}),
						h(ToggleRow, {
							label: 'Cron Warm Up',
							description: 'Enable the minute-by-minute background warm queue. Homepage is warmed first and the queue can also be started manually or after a flush.',
							checked: settings.cronWarmEnabled,
							onChange: (value) => updateSetting('cronWarmEnabled', value),
							disabled: busy,
							key: 'cron-warm-enabled',
						}),
						h(ToggleRow, {
							label: 'Start Cron Warm Up Automatically',
							description: 'Start the cron warm queue automatically after scheduled cleanup or Flush All Cache whenever Cron Warm Up is enabled.',
							checked: settings.cronWarmStartAfterCleanup,
							onChange: (value) => updateSetting('cronWarmStartAfterCleanup', value),
							disabled: busy || !settings.cacheCleanupEnabled || !settings.cronWarmEnabled,
							key: 'cleanup-warm',
						}),
					]
				),
				h(
					Card,
					{
						title: 'Compression, Images & Objects',
						description: 'Response compression, object cache, and next-gen image settings.',
						key: 'compression',
					},
					[
						h(ToggleRow, {
							label: 'Object Cache',
							description: diagnostics.objectCache && diagnostics.objectCache.available
								? 'Enable the managed WordPress object-cache.php drop-in. Backend can be Disk or Redis. Disk remains the fallback.'
								: ((diagnostics.objectCache && diagnostics.objectCache.message) || 'Temporarily unavailable in this hotfix.'),
							checked: settings.objectCacheEnabled,
							onChange: (value) => updateSetting('objectCacheEnabled', value),
							disabled: busy || !(diagnostics.objectCache && diagnostics.objectCache.available),
							key: 'object-cache',
						}),
						h(ToggleRow, {
							label: 'Gzip',
							description: 'Serve gzip sidecar cache files when supported.',
							checked: settings.gzipEnabled,
							onChange: (value) => updateSetting('gzipEnabled', value),
							disabled: busy,
							key: 'gzip',
						}),
						h(ToggleRow, {
							label: 'Brotli',
							description: 'Serve Brotli sidecar cache files when available.',
							checked: settings.brotliEnabled,
							onChange: (value) => updateSetting('brotliEnabled', value),
							disabled: busy,
							key: 'brotli',
						}),
						h(ToggleRow, {
							label: 'AVIF / WebP Conversion',
							description: 'Generate AVIF versions first and WebP fallback files when AVIF is unavailable.',
							checked: settings.avifConversionEnabled,
							onChange: (value) => updateSetting('avifConversionEnabled', value),
							disabled: busy,
							key: 'avif',
						}),
						h(ToggleRow, {
							label: 'Browser Cache Rules (.htaccess)',
							description: 'Write long-lived cache headers for CSS, JS, fonts, and static images on Apache-compatible hosts.',
							checked: settings.browserCacheRulesEnabled,
							onChange: (value) => updateSetting('browserCacheRulesEnabled', value),
							disabled: busy,
							key: 'browser-cache-rules',
						}),
					]
				),
			]),

			h(RedisCard, { form: redisForm, diagnostics, busy, onFieldChange: updateRedisField, onSave: saveRedisSettings, onTest: testRedisConnection, onFlush: flushObjectCache, key: 'redis-card' }),

			h(VarnishCard, { form: varnishForm, diagnostics, busy, onFieldChange: updateVarnishField, onSave: saveVarnishSettings, onTest: runVarnishTest, onFlushAll: runVarnishFlushAll, key: 'varnish-card' }),

			h('div', { className: 'uc-info-grid', key: 'info-cards' }, [
				h(DiagnosticsCard, { diagnostics, stats, key: 'diagnostics' }),
				h(ActivitySummaryCard, { stats, key: 'activity-summary' }),
			]),
			h(AdvancedDiagnosticsCard, { diagnostics, stats, key: 'advanced-diagnostics-card' }),

			h(
				Card,
				{
					title: 'Cache Decision Tester',
					description: 'Inspect how UltraCache evaluates a frontend URL without using your current admin session cookies.',
					key: 'inspector',
				},
				[
					h(TextField, {
						label: 'URL or path',
						description: 'Paste a full local URL or just a path like /checkout/ or /product/widget/?add-to-cart=12.',
						value: inspectUrl,
						onChange: setInspectUrl,
						disabled: inspectBusy,
						placeholder: '/sample-page/?utm_source=test',
						onKeyDown: (event) => {
							if ('Enter' === event.key) {
								event.preventDefault();
								inspectCacheDecision();
							}
						},
					}),
					h('div', { className: 'mt-4 flex flex-wrap gap-3 items-center' }, [
						h(Button, { onClick: inspectCacheDecision, disabled: inspectBusy || !String(inspectUrl || '').trim(), variant: 'primary' }, inspectBusy ? 'Inspecting…' : 'Inspect URL'),
						inspectResult
							? h(StatusPill, { ok: !!inspectResult.cacheable, text: inspectResult.cacheable ? 'Cacheable' : 'Bypassed' })
							: null,
					]),
					inspectResult
						? h('div', { className: 'mt-5 grid grid-cols-1 md:grid-cols-2 gap-6' }, [
							h('div', { className: 'space-y-0' }, [
								h(DetailRow, { label: 'Reason', value: inspectResult.reasonLabel || inspectResult.reason }),
								h(DetailRow, { label: 'Normalized URL', value: inspectResult.normalizedUrl || inspectResult.url, mono: true }),
								h(DetailRow, { label: 'Normalized path', value: inspectResult.normalizedPath || inspectResult.path, mono: true }),
								h(DetailRow, { label: 'Query string', value: inspectResult.query || '—', mono: true }),
								h(DetailRow, { label: 'Matched excluded path rule', value: inspectResult.matchedExcludedPathRule, mono: true }),
								h(DetailRow, { label: 'Matched excluded query arg', value: inspectResult.matchedExcludedQueryArg, mono: true }),
								h(DetailRow, { label: 'Matched WooCommerce rule', value: inspectResult.matchedWooRule ? ((inspectResult.matchedWooRuleType || 'rule') + ': ' + inspectResult.matchedWooRule) : '', mono: true }),
								h(DetailRow, { label: 'Query arg keys', value: Array.isArray(inspectResult.queryArgKeys) && inspectResult.queryArgKeys.length ? inspectResult.queryArgKeys.join(', ') : '' }),
								h(DetailRow, { label: 'Notes', value: inspectResult.simulationNote }),
							]),
							h('div', { className: 'space-y-0' }, [
								h(DetailRow, { label: 'Local URL', value: inspectResult.local ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'Page cache enabled', value: inspectResult.pageCacheEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'WooCommerce safe mode', value: inspectResult.wooSafeModeEnabled ? 'Yes' : 'No' }),
								h(DetailRow, { label: 'Cache query strings', value: inspectResult.cacheQueryStrings ? 'Yes' : 'No' }),
								inspectResult.cacheable && inspectResult.cachePaths
									? h('div', { className: 'pt-2' }, [
										h('div', { className: 'text-[11px] uppercase tracking-widest text-zinc-500 mb-2' }, 'Expected cache files'),
										h(DetailRow, { label: 'Original HTML', value: inspectResult.cachePaths.orig, mono: true }),
										h(DetailRow, { label: 'WebP HTML', value: inspectResult.cachePaths.webp, mono: true }),
										h(DetailRow, { label: 'AVIF HTML', value: inspectResult.cachePaths.avif, mono: true }),
									])
									: null,
							]),
						])
						: h('div', { className: 'mt-4 text-xs text-zinc-500' }, 'Enter a local URL or path to see the exact cache decision and matching bypass rule.'),
				]
			),

			h(
				Card,
				{
					title: 'Rules & Scheduling',
					description: 'Custom cache exceptions and scheduled maintenance controls.',
					key: 'rules',
				},
				[
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4' }, [
						h(NumberField, {
							label: 'Cleanup interval (hours)',
							description: 'Use 24 for daily, 168 for weekly, or any custom number of hours.',
							value: advancedForm.cacheCleanupIntervalHours,
							onChange: (value) => updateAdvancedField('cacheCleanupIntervalHours', value),
							disabled: busy,
							min: 1,
							key: 'cleanup-hours',
						}),
						h(NumberField, {
							label: 'Cron warm pages per minute',
							description: 'How many URLs to warm per minute in the cron warm-up queue. Homepage is always warmed first. Lower values are safer on slower servers. Set 0 to pause queue processing.',
							value: advancedForm.cronWarmPagesPerMinute,
							onChange: (value) => updateAdvancedField('cronWarmPagesPerMinute', value),
							disabled: busy,
							min: 0,
							key: 'warm-limit',
						}),
						h(NumberField, {
							label: 'Scheduled warm limit',
							description: getScheduledWarmLimitSummary(),
							value: advancedForm.scheduledWarmLimit,
							onChange: (value) => updateAdvancedField('scheduledWarmLimit', value),
							disabled: busy || !settings.cronWarmEnabled,
							min: 0,
							key: 'scheduled-warm-limit',
						}),
						h(NumberField, {
							label: 'Fresh TTL (minutes)',
							description: 'Serve a normal cache hit while the file age stays within this freshness window. Default: 15 minutes.',
							value: advancedForm.cacheFreshTtlMinutes,
							onChange: (value) => updateAdvancedField('cacheFreshTtlMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'fresh-ttl',
						}),
						h(NumberField, {
							label: 'Max stale window (minutes)',
							description: 'After freshness expires, UltraCache may still serve the stale file until this limit while it refreshes in the background. Default: 720 minutes (12 hours).',
							value: advancedForm.cacheMaxStaleMinutes,
							onChange: (value) => updateAdvancedField('cacheMaxStaleMinutes', value),
							disabled: busy || !settings.staleWhileRevalidateEnabled,
							min: 1,
							key: 'max-stale',
						}),
					]),
					h('div', { className: 'grid grid-cols-1 md:grid-cols-2 gap-4 mt-4' }, [
						h(TextAreaField, {
							label: 'Excluded paths',
							description: 'One path per line. Prefix matching is supported. Example: /members/ or /thank-you/.',
							value: advancedForm.cacheExceptionPaths,
							onChange: (value) => updateAdvancedField('cacheExceptionPaths', value),
							disabled: busy,
							placeholder: '/cart/\n/checkout/\n/my-account/',
							key: 'paths',
						}),
						h(TextAreaField, {
							label: 'Excluded query args',
							description: 'One query key per line. Useful for previews, campaign links, and dynamic actions.',
							value: advancedForm.cacheExceptionQueryArgs,
							onChange: (value) => updateAdvancedField('cacheExceptionQueryArgs', value),
							disabled: busy,
							placeholder: 'preview\nadd-to-cart\nwc-ajax',
							key: 'query-args',
						}),
					]),
					h('div', { className: 'mt-4 flex justify-end' }, [
						h(Button, { onClick: saveAdvancedSettings, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Save Rules & Schedule'),
					]),
				]
			),

			h(
				Card,
				{
					title: 'Export / Import Settings',
					description: 'Download a JSON backup of UltraCache dashboard settings or restore them on another site.',
					key: 'export-import',
				},
				[
					h('input', {
						type: 'file',
						accept: 'application/json,.json',
						ref: importFileInputRef,
						onChange: importSettingsFile,
						style: { display: 'none' },
						key: 'file-input',
					}),
					h('div', { className: 'space-y-3', key: 'copy' }, [
						h('div', { className: 'text-sm text-white' }, 'Export creates a portable JSON file from the current UltraCache dashboard settings.'),
						h('div', { className: 'text-xs text-zinc-500' }, 'Import applies only supported dashboard options. Generated cache files, drop-ins, and runtime state are rebuilt by the existing save flow.'),
					]),
					h('div', { className: 'mt-4 flex gap-3 flex-wrap' }, [
						h(Button, { onClick: exportSettingsFile, disabled: busy, variant: 'primary' }, busy ? 'Working…' : 'Export Settings'),
						h(Button, { onClick: openImportSettingsDialog, disabled: busy, variant: 'light' }, busy ? 'Working…' : 'Import Settings'),
					]),
					h('div', { className: 'mt-4 text-xs text-zinc-500', key: 'hint' }, 'Recommended flow: export from the known-good site, then import into the target site and review Diagnostics once.'),
				]
			),

			h(SupportInlineCard, {
				isMobile,
				onMobileTrigger: openSupportModal,
				onHireClick: handleHireClick,
				key: 'support-inline-card',
			}),

			h(
				'div',
				{
					className:
						'uc-help-box bg-zinc-900/50 border border-zinc-800 p-6 rounded text-xs text-zinc-400 leading-relaxed',
					key: 'notes',
				},
				[
					h('p', { className: 'mb-2 font-bold text-zinc-300' }, 'Quick start & examples'),
					h('p', { className: 'm-0' }, 'Enable Page Caching, then Flush All Cache once. Use Warm Up Frontpage HTML Cache for a single homepage warm, Warm Up Frontpage HTML Cache + Frontpage CSS for homepage HTML + bundle rebuild, Warm Up Menu HTML Cache for menu URLs only, Warm Up Menu HTML Cache + Frontpage CSS for menu URLs plus one homepage CSS bundle build, Warm Up Full Site HTML Cache for a full crawl, or Warm Up Full Site HTML Cache + Frontpage CSS for the full crawl followed by one homepage CSS bundle build. In WP-CLI run `wp ultracache help` for commands, `wp ultracache warm-html-all --purge-first` for the full crawl, `wp ultracache warm-html-all-css --purge-first` for the full crawl plus frontpage CSS, `wp ultracache warm-frontpage-html` for the homepage only, `wp ultracache warm-frontpage-html-css` for homepage HTML + CSS, `wp ultracache cron_warm start` to start the minute-by-minute warm queue, and `wp ultracache cron_warm tick` from a server cron each minute when you want deterministic warming. Use `wp ultracache purge` after major theme or plugin changes. Keep WooCommerce Safe Mode enabled on shops, enable Local Google Fonts Optimization when you want to proxy Google Fonts locally, turn on Speculation Rules Prefetch only after basic cache behavior looks stable, and review Cache diagnostics after testing.'),
				]
			),
		]);
	}

	if (ReactDOMApi && typeof ReactDOMApi.createRoot === 'function') {
		ReactDOMApi.createRoot(rootEl).render(h(App));
	} else if (ReactDOMApi && typeof ReactDOMApi.render === 'function') {
		ReactDOMApi.render(h(App), rootEl);
	}
})();
