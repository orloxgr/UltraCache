/* UltraCache Admin - REST client and response normalization */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before api.js.');
	}

	const DEFAULT_QUEUE_BATCH_SIZE = 100;
	let ultracacheRestBase = '';
	let ultracacheRestNonce = '';
	let ultracacheFetch = null;
	let mediaReplacementConfirmationTokens = {};

	const mediaReplacementDestructiveTokenKeys = {
		media_library_replacement_db_apply: 'databaseApply',
		media_library_replacement_theme_css_apply: 'themeCssApply',
		media_library_replacement_cleanup_apply: 'cleanupApply',
		media_library_replacement_delete: 'cleanupApply',
	};


	function captureMediaReplacementConfirmationTokens(data) {
		if (!data || typeof data !== 'object' || !data.confirmationTokens || typeof data.confirmationTokens !== 'object') {
			return;
		}
		Object.keys(data.confirmationTokens).forEach((key) => {
			const token = String(data.confirmationTokens[key] || '');
			if (token) {
				mediaReplacementConfirmationTokens[key] = token;
			}
		});
	}

	function configure(config) {
		const source = config && typeof config === 'object' ? config : {};
		ultracacheRestBase = String(source.restBase || '');
		ultracacheRestNonce = String(source.restNonce || '');
		ultracacheFetch = typeof source.fetch === 'function' ? source.fetch : null;
	}

	function getRestErrorMessage(subAction, route, requestUrl, response, data, fallbackMessage) {
		const method = route && route.method ? String(route.method) : 'GET';
		const path = route && route.path ? '/' + String(route.path).replace(/^\/+/, '') : String(subAction || 'unknown');
		const status = response && typeof response.status !== 'undefined' ? Number(response.status || 0) : 0;
		const code = data && data.code ? String(data.code) : '';
		const message = fallbackMessage ||
			(data && data.message ? String(data.message) : '') ||
			(data && data.data && data.data.message ? String(data.data.message) : '') ||
			(status ? ('HTTP ' + status) : 'Request failed.');
		return 'UltraCache REST failed: ' + method + ' ' + path + (status ? (' returned HTTP ' + status) : '') + (code ? (' (' + code + ')') : '') + '. ' + message;
	}

	function getRestBodyPreview(body) {
		const preview = String(body || '').replace(/\s+/g, ' ').trim();
		return preview.length > 500 ? preview.slice(0, 500) : preview;
	}

	function findBalancedJsonRange(text, startIndex) {
		const source = String(text || '');
		const opener = source.charAt(startIndex);
		const closer = opener === '{' ? '}' : (opener === '[' ? ']' : '');
		let depth = 0;
		let inString = false;
		let escaped = false;
	
		if (!closer) {
			return null;
		}
	
		for (let index = startIndex; index < source.length; index += 1) {
			const character = source.charAt(index);
	
			if (inString) {
				if (escaped) {
					escaped = false;
				} else if (character === '\\') {
					escaped = true;
				} else if (character === '"') {
					inString = false;
				}
				continue;
			}
	
			if (character === '"') {
				inString = true;
				continue;
			}
	
			if (character === opener) {
				depth += 1;
			} else if (character === closer) {
				depth -= 1;
				if (depth === 0) {
					return { start: startIndex, end: index + 1 };
				}
			}
		}
	
		return null;
	}

	function parseRestJsonText(responseText) {
		const original = String(responseText || '');
		const trimmed = original.trim();
	
		if (!trimmed) {
			return { data: null, noisy: false, noisePreview: '' };
		}
	
		try {
			return { data: JSON.parse(trimmed), noisy: false, noisePreview: '' };
		} catch (directError) {
			// Continue with the noisy-response fallback below.
		}
	
		for (let index = 0; index < original.length; index += 1) {
			const character = original.charAt(index);
			if (character !== '{' && character !== '[') {
				continue;
			}
	
			const range = findBalancedJsonRange(original, index);
			if (!range) {
				continue;
			}
	
			const candidate = original.slice(range.start, range.end);
			try {
				const data = JSON.parse(candidate);
				const noise = (original.slice(0, range.start) + ' ' + original.slice(range.end)).trim();
				return {
					data,
					noisy: !!noise,
					noisePreview: getRestBodyPreview(noise),
				};
			} catch (candidateError) {
				// Keep scanning; notices may include braces before the real REST JSON body.
			}
		}
	
		return { data: null, noisy: false, noisePreview: '' };
	}

	async function apiRequest(subAction, params = {}) {
		const routes = {
			stats: { path: 'stats', method: 'GET' },
			purge_all: { path: 'purge-all', method: 'POST' },
			storage_diagnostics_refresh: { path: 'diagnostics/storage/refresh', method: 'POST' },
			lcp_observations_query: { path: 'lcp-observations', method: 'GET' },
			lcp_observation_detail: { path: 'lcp-observations/detail', method: 'GET' },
			lcp_observation_action: { path: 'lcp-observations/action', method: 'POST' },
			compression_capabilities: { path: 'compression/capabilities', method: 'POST' },
			get_crawl_urls: { path: 'crawl-urls', method: 'GET' },
			inspect_url: { path: 'inspect-url', method: 'POST' },
			crawl_page: { path: 'crawl-page', method: 'POST' },
			manual_warm_page_stage: { path: 'manual-warm/page-stage', method: 'POST' },
			build_frontpage_css: { path: 'build-frontpage-css', method: 'POST' },
			warm_frontpage_html: { path: 'warm-frontpage-html', method: 'POST' },
			warm_frontpage_html_css: { path: 'warm-frontpage-html-css', method: 'POST' },
			get_media_ids: { path: 'media-ids', method: 'GET' },
			optimize_id: { path: 'optimize-id', method: 'POST' },
			optimize_media: { path: 'optimize-media', method: 'POST' },
			media_conversion_test_latest: { path: 'media/conversion-test', method: 'GET' },
			media_conversion_test_run: { path: 'media/conversion-test', method: 'POST' },
			media_library_replacement_status: { path: 'media/library-replacement/status', method: 'GET' },
			media_library_replacement_session: { path: 'media/library-replacement/session', method: 'POST' },
			media_library_replacement_restart: { path: 'media/library-replacement/restart', method: 'POST' },
			media_library_replacement_readiness_status: { path: 'media/library-replacement/readiness', method: 'GET' },
			media_library_replacement_readiness_scan: { path: 'media/library-replacement/readiness', method: 'POST' },
			media_library_replacement_workflow_stage: { path: 'media/library-replacement/workflow-stage', method: 'POST' },
			media_library_replacement_prepare: { path: 'media/library-replacement/prepare', method: 'POST' },
			media_library_replacement_do: { path: 'media/library-replacement/do', method: 'POST' },
			media_library_replacement_verify: { path: 'media/library-replacement/verify', method: 'POST' },
			media_library_replacement_delete_confirm: { path: 'media/library-replacement/delete/confirm', method: 'POST' },
			media_library_replacement_delete: { path: 'media/library-replacement/delete', method: 'POST' },
			media_library_replacement_preview: { path: 'media/library-replacement/preview', method: 'GET' },
			media_library_replacement_copy: { path: 'media/library-replacement/copy', method: 'POST' },
			media_library_replacement_metadata_prepare: { path: 'media/library-replacement/metadata/prepare', method: 'POST' },
			media_library_replacement_metadata_apply: { path: 'media/library-replacement/metadata/apply', method: 'POST' },
			media_library_replacement_metadata_rollback: { path: 'media/library-replacement/metadata/rollback', method: 'POST' },
			media_library_replacement_refs_scan: { path: 'media/library-replacement/references/scan', method: 'POST' },
			media_library_replacement_refs_match: { path: 'media/library-replacement/references/match', method: 'POST' },
			media_library_replacement_db_preview: { path: 'media/library-replacement/replacements/preview', method: 'GET' },
			media_library_replacement_db_apply: { path: 'media/library-replacement/replacements/apply', method: 'POST' },
			media_library_replacement_db_verify: { path: 'media/library-replacement/replacements/verify', method: 'POST' },
			media_library_replacement_db_rollback: { path: 'media/library-replacement/replacements/rollback', method: 'POST' },
			media_library_replacement_theme_css_scan: { path: 'media/library-replacement/theme-css/scan', method: 'POST' },
			media_library_replacement_theme_css_preview: { path: 'media/library-replacement/theme-css/preview', method: 'GET' },
			media_library_replacement_theme_css_apply: { path: 'media/library-replacement/theme-css/apply', method: 'POST' },
			media_library_replacement_theme_css_verify: { path: 'media/library-replacement/theme-css/verify', method: 'POST' },
			media_library_replacement_cleanup_preview: { path: 'media/library-replacement/cleanup/preview', method: 'GET' },
			media_library_replacement_cleanup_apply: { path: 'media/library-replacement/cleanup/apply', method: 'POST' },
			media_queue_status: { path: 'media-queue/status', method: 'GET' },
			media_queue_rebuild: { path: 'media-queue/rebuild', method: 'POST' },
			media_queue_process: { path: 'media-queue/process', method: 'POST' },
			media_manual_session: { path: 'media-queue/manual-session', method: 'POST' },
			media_background_control: { path: 'media-queue/background-work', method: 'POST' },
			media_queue_retry_failed: { path: 'media-queue/retry-failed', method: 'POST' },
			media_queue_requeue_completed_regeneration: { path: 'media-queue/requeue-completed-regeneration', method: 'POST' },
			media_queue_clear_completed: { path: 'media-queue/clear-completed', method: 'POST' },
			varnish_test: { path: 'varnish/test', method: 'POST' },
			varnish_behavior_test: { path: 'varnish/test-behavior', method: 'POST' },
			varnish_flush_all: { path: 'varnish/flush-all', method: 'POST' },
			opcache_flush: { path: 'opcache/flush', method: 'POST' },
			apcu_flush: { path: 'apcu/flush', method: 'POST' },
			litespeed_flush: { path: 'litespeed/flush', method: 'POST' },
			nginx_flush: { path: 'nginx/flush', method: 'POST' },
			external_caches_redetect: { path: 'external-caches/redetect', method: 'POST' },
			object_cache_test: { path: 'object-cache/backend-test', method: 'POST' },
			object_cache_flush: { path: 'object-cache/flush', method: 'POST' },
			remove_conflicting_cache_dropins: { path: 'cache-conflicts/remove-dropins', method: 'POST' },
			performance_profile_last: { path: 'performance-profile/last', method: 'GET' },
			performance_profile_clear: { path: 'performance-profile/clear', method: 'POST' },
			runtime_js_scan_report: { path: 'runtime-js-scan/report', method: 'GET' },
			runtime_js_scan_submit: { path: 'runtime-js-scan/report', method: 'POST' },
			runtime_js_scan_parse_console: { path: 'runtime-js-scan/parse-console', method: 'POST' },
			runtime_js_diagnostic_queue_start: { path: 'runtime-js-diagnostic-queue/start', method: 'POST' },
			runtime_js_diagnostic_queue_status: { path: 'runtime-js-diagnostic-queue/status', method: 'GET' },
			runtime_js_diagnostic_queue_pause: { path: 'runtime-js-diagnostic-queue/pause', method: 'POST' },
			runtime_js_diagnostic_queue_resume: { path: 'runtime-js-diagnostic-queue/resume', method: 'POST' },
			runtime_js_diagnostic_queue_cancel: { path: 'runtime-js-diagnostic-queue/cancel', method: 'POST' },
			manual_warm_session: { path: 'manual-warm/session', method: 'POST' },
			cron_warm_start: { path: 'cron-warm/start', method: 'POST' },
			cron_warm_stop: { path: 'cron-warm/stop', method: 'POST' },
			cron_warm_tick: { path: 'cron-warm/tick', method: 'POST' },
			admin_theme: { path: 'admin-theme', method: 'POST' },
			settings: { path: 'settings', method: 'POST' },
			save_settings: { path: 'settings', method: 'POST' },
			queue_action: { path: 'action-queue', method: 'POST' },
			queue_status: { path: 'action-queue/{id}', method: 'GET' },
			queue_run: { path: 'action-queue/{id}/run', method: 'POST' },
			delete_all_data: { path: 'delete-all-data', method: 'POST' },
			populate_query_allowlist: { path: 'query-string-allowlist/populate', method: 'POST' },
			font_patterns_scan: { path: 'font-patterns/scan-frontpage', method: 'POST' },
		};
	
		const route = routes[subAction];
		if (!route || !ultracacheRestBase) {
			throw new Error('REST route not available for action: ' + subAction);
		}
		if (!ultracacheFetch) {
			throw new Error('Browser fetch API is not available for UltraCache REST action: ' + subAction);
		}
	
		let payload = params;
		let requestUrl = ultracacheRestBase + route.path;
		if ((subAction === 'queue_status' || subAction === 'queue_run') && params && params.id) {
			requestUrl = ultracacheRestBase + route.path.replace('{id}', encodeURIComponent(String(params.id)));
		}
	
		if (route.method === 'GET' && params && typeof params === 'object') {
			const query = new URLSearchParams();
			Object.keys(params).forEach((key) => {
				if ((subAction === 'queue_status' || subAction === 'queue_run') && key === 'id') {
					return;
				}
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
			let normalizedValue = params.value;
			if (params.value === '1' || params.value === true) {
				normalizedValue = true;
			} else if (params.value === '0' || params.value === false) {
				normalizedValue = false;
			}
			payload = {
				[params.key]: normalizedValue,
			};
		} else if (subAction === 'save_settings') {
			payload = params.settings_json ? JSON.parse(params.settings_json) : {};
		} else if (subAction === 'queue_action') {
			payload = {
				action: params.action || '',
				params: params.params || {},
			};
		}

		const confirmationTokenKey = mediaReplacementDestructiveTokenKeys[subAction] || '';
		if (route.method !== 'GET' && confirmationTokenKey && payload && typeof payload === 'object' && !payload.confirmationToken) {
			payload = Object.assign({}, payload, {
				confirmationToken: mediaReplacementConfirmationTokens[confirmationTokenKey] || '',
			});
		}
	
		let response = null;
		let data = null;
		let responseText = '';
		try {
			response = await ultracacheFetch(requestUrl, {
				method: route.method,
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'X-WP-Nonce': ultracacheRestNonce || '',
					'Cache-Control': 'no-cache, no-store, max-age=0',
					'Pragma': 'no-cache',
					...(route.method !== 'GET' ? { 'Content-Type': 'application/json' } : {}),
				},
				...(route.method !== 'GET' ? { body: JSON.stringify(payload) } : {}),
			});
		} catch (error) {
			const wrapped = new Error(getRestErrorMessage(subAction, route, requestUrl, { status: 0 }, null, error && error.message ? error.message : 'Network request failed.'));
			wrapped.data = null;
			wrapped.rest = { action: subAction, method: route.method, path: route.path, url: requestUrl, status: 0, code: 'network_error' };
			throw wrapped;
		}
	
		try {
			responseText = await response.text();
		} catch (error) {
			const wrapped = new Error(getRestErrorMessage(subAction, route, requestUrl, response, null, error && error.message ? error.message : 'Could not read response body.'));
			wrapped.data = null;
			wrapped.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: 'response_body_unreadable',
			};
			throw wrapped;
		}
	
		const trimmedResponseText = String(responseText || '').trim();
		if (trimmedResponseText) {
			const parsedResponse = parseRestJsonText(responseText);
			data = parsedResponse.data;
	
			if (!data) {
				const preview = getRestBodyPreview(trimmedResponseText);
				const wrapped = new Error(getRestErrorMessage(
					subAction,
					route,
					requestUrl,
					response,
					null,
					'Invalid JSON response. Response preview: ' + (preview || '[empty]')
				));
				wrapped.data = null;
				wrapped.rest = {
					action: subAction,
					method: route.method,
					path: route.path,
					url: requestUrl,
					status: response.status,
					code: 'invalid_json',
					bodyPreview: preview,
				};
				throw wrapped;
			}
	
			if (parsedResponse.noisy) {
				if (data && typeof data === 'object') {
					try {
						Object.defineProperty(data, '__ultracacheNoisyRestResponse', {
							value: {
								action: subAction,
								method: route.method,
								path: route.path,
								status: response.status,
								preview: parsedResponse.noisePreview || '',
							},
							enumerable: false,
						});
					} catch (propertyError) {
						data.__ultracacheNoisyRestResponse = { action: subAction, method: route.method, path: route.path, status: response.status, preview: parsedResponse.noisePreview || '' };
					}
				}
	
			}
		}
	
		captureMediaReplacementConfirmationTokens(data);

		if (!response.ok) {
			const message = getRestErrorMessage(subAction, route, requestUrl, response, data, '');
			const error = new Error(message);
			error.data = data;
			error.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: data && data.code ? String(data.code) : '',
				message: data && data.message ? String(data.message) : '',
			};
			throw error;
		}
	
		if (!trimmedResponseText && response.status !== 204) {
			const error = new Error(getRestErrorMessage(subAction, route, requestUrl, response, null, 'Empty response body.'));
			error.data = null;
			error.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: 'empty_response',
			};
			throw error;
		}
	
		if (data && data.success === false && !data.skipped) {
			const responseMessage =
				(data.data && data.data.message) ||
				data.message ||
				'Request failed.';
			const error = new Error('UltraCache request failed: ' + route.method + ' /' + route.path + '. ' + responseMessage);
			error.data = data;
			error.rest = {
				action: subAction,
				method: route.method,
				path: route.path,
				url: requestUrl,
				status: response.status,
				code: data && data.code ? String(data.code) : '',
				message: responseMessage,
			};
			throw error;
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
	
		if (!data || typeof data !== 'object') {
			throw new Error('UltraCache REST failed: batch endpoint returned an invalid payload. Expected a JSON object or array.');
		}
	
		const source = data;
		const items = Array.isArray(source.items) ? source.items : [];
		const queue = source.queue && typeof source.queue === 'object' ? source.queue : null;
		const total = Math.max(items.length, Number(source.total || 0));
		const queueCompleted = queue
			? Math.max(0, Number(queue.done || 0)) + Math.max(0, Number(queue.skipped || 0)) + Math.max(0, Number(queue.failed || 0))
			: 0;
		const processed = typeof source.processed !== 'undefined'
			? Math.max(0, Number(source.processed || 0))
			: Math.max(0, Number(source.nextOffset ? source.nextOffset : items.length));
		const nextCursor = typeof source.nextCursor === 'string' ? source.nextCursor : '';
		const queueBuilding = queue ? !queue.buildComplete : false;
	
		return {
			items,
			total,
			workTotal: typeof source.workTotal !== 'undefined' ? Math.max(0, Number(source.workTotal || 0)) : total,
			attachmentTotal: typeof source.attachmentTotal !== 'undefined' ? Math.max(0, Number(source.attachmentTotal || total)) : total,
			cursor: typeof source.cursor === 'string' ? source.cursor : normalizedCursor,
			limit: typeof source.limit !== 'undefined' ? Number(source.limit || normalizedLimit) : normalizedLimit,
			nextCursor: nextCursor,
			nextOffset: typeof source.nextOffset !== 'undefined' ? Number(source.nextOffset || processed) : processed,
			processed: processed,
			queueCompleted: queueCompleted,
			queueBuilding: queueBuilding,
			queuePending: queue ? Math.max(0, Number(queue.pending || 0)) : 0,
			queueFailed: queue ? Math.max(0, Number(queue.failed || 0)) : 0,
			queueSkipped: queue ? Math.max(0, Number(queue.skipped || 0)) : 0,
			queueAlreadyOptimized: queue ? Math.max(0, Number(queue.alreadyOptimized || queue.skipped || 0)) : 0,
			queueIsComplete: queue ? !!queue.isComplete : !!source.complete,
			needsRepair: queue ? !!queue.needsRepair : false,
			repair: source.repair && typeof source.repair === 'object' ? source.repair : null,
			message: source.message ? String(source.message) : '',
			hasMore: typeof source.hasMore !== 'undefined' ? !!source.hasMore : !!nextCursor,
		};
	}

	admin.define('api', {
		configure,
		apiRequest,
		normalizeBatchResponse,
	});
})(window);
