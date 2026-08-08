/* UltraCache Admin - Media conversion policy and dashboard orchestration */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before media.js.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	if (!core || !api) {
		throw new Error('UltraCache admin core/api modules are required before media.js.');
	}

	const { __, sleep, formatNumber } = core;
	const { apiRequest, normalizeBatchResponse } = api;
	let mediaUnitDelayMs = 250;

	function configure(config) {
		const source = config && typeof config === 'object' ? config : {};
		if (Number(source.unitDelayMs) >= 0) {
			mediaUnitDelayMs = Math.max(0, Number(source.unitDelayMs));
		}
	}

	function createMediaRebuildGeneration() {
		if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'media-rebuild-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 14);
	}

	function getMediaConversionFailureData(error) {
		const data = error && error.data && typeof error.data === 'object' ? error.data : {};
		const status = error && error.rest ? Number(error.rest.status || 0) : 0;
		const reason = data.reason ? String(data.reason) : '';
		const queueStatus = data.queueStatus ? String(data.queueStatus) : '';
		const isConversionFailure = status === 422 && (
			reason === 'conversion_failed'
			|| reason === 'retry_limit'
			|| queueStatus === 'failed'
			|| Number(data.failedThisRun || 0) > 0
		);
		return isConversionFailure ? data : null;
	}

	function isRetryableMediaQueueLock(error) {
		const data = error && error.data && typeof error.data === 'object' ? error.data : {};
		const status = error && error.rest ? Number(error.rest.status || 0) : 0;
		const reason = data.reason ? String(data.reason) : '';
		return status === 409 && (reason === 'locked' || reason === 'already_claimed');
	}

	function formatMediaConversionFailureLine(item, data, error) {
		const parts = ['Failed attachment #' + item];
		if (data && data.sourceFile) {
			parts.push(String(data.sourceFile));
		}
		if (data && data.attemptedFormat) {
			parts.push('format ' + String(data.attemptedFormat).toUpperCase());
		}
		const attempt = Math.max(0, Number(data && data.failureAttempt ? data.failureAttempt : 0));
		const limit = Math.max(0, Number(data && data.failureLimit ? data.failureLimit : 0));
		if (attempt > 0) {
			parts.push('attempt ' + attempt + (limit > 0 ? '/' + limit : ''));
		}
		if (data && data.failureStage) {
			parts.push('stage ' + String(data.failureStage));
		}
		if (data && data.failureCode) {
			parts.push('code ' + String(data.failureCode));
		}
		const detail = data && (data.failureDetail || data.message)
			? String(data.failureDetail || data.message)
			: (error && error.message ? String(error.message) : 'The image conversion unit could not be generated.');
		return parts.join(' · ') + ' — ' + detail;
	}

	async function processJobItem(type, item, shouldCancel, manualSessionToken, jobState) {
		let complete = false;
		let totalUnits = 0;
		let avifCount = 0;
		let webpCount = 0;
		let alreadyOptimized = false;
		let queueStatus = '';
		let conversionFailureAttempts = 0;
		const semanticSkips = {};

		while (!complete) {
			if (typeof shouldCancel === 'function' && shouldCancel()) {
				throw new Error('Media optimization paused. You can resume it later.');
			}

			let response = null;
			try {
				response = await apiRequest('optimize_id', {
					id: item,
					manual_token: manualSessionToken || '',
					force_regenerate: !!(jobState && jobState.forceRegenerateExisting),
				});
			} catch (error) {
				if (isRetryableMediaQueueLock(error)) {
					await sleep(500);
					continue;
				}

				const failure = getMediaConversionFailureData(error);
				if (!failure) {
					throw error;
				}

				conversionFailureAttempts += 1;
				const unitIncrement = Math.max(0, Number(failure.workCompletedThisRun || 0));
				totalUnits += unitIncrement;
				avifCount += Math.max(0, Number(failure.avif || 0));
				webpCount += Math.max(0, Number(failure.webp || 0));
				queueStatus = failure.queueStatus ? String(failure.queueStatus) : '';

				const attempt = Math.max(conversionFailureAttempts, Number(failure.failureAttempt || 0));
				const limit = Math.max(1, Number(failure.failureLimit || 3));
				const terminal = queueStatus === 'failed'
					|| String(failure.reason || '') === 'retry_limit'
					|| (limit > 0 && attempt >= limit);

				if (!terminal) {
					await sleep(mediaUnitDelayMs);
					continue;
				}

				return {
					line: formatMediaConversionFailureLine(item, failure, error),
					progressIncrement: 1,
					attachmentIncrement: 1,
					unitIncrement: totalUnits,
					avifIncrement: avifCount,
					webpIncrement: webpCount,
					successIncrement: 0,
					skippedIncrement: 0,
					failedIncrement: 1,
				};
			}

			if (!response || response.success === false) {
				throw new Error(
					response && response.message
						? response.message
						: 'Media optimization request failed. The job was paused and can be resumed.'
				);
			}

			const unitIncrement = Math.max(0, Number(response.workCompletedThisRun || 0));
			totalUnits += unitIncrement;
			avifCount += Math.max(0, Number(response.avif || 0));
			webpCount += Math.max(0, Number(response.webp || 0));
			queueStatus = response.queueStatus ? String(response.queueStatus) : '';
			alreadyOptimized = alreadyOptimized || !!response.alreadyOptimized || response.skippedReason === 'already_optimized';
			if (response.skippedReason && response.skippedReason !== 'already_optimized' && response.skippedReason !== 'no_supported_work') {
				const skippedFormat = response.skippedFormat ? String(response.skippedFormat).toUpperCase() : 'FORMAT';
				const skippedReason = String(response.skippedReason);
				const skippedDetail = response.skipDetail ? String(response.skipDetail) : '';
				semanticSkips[skippedFormat + '|' + skippedReason] = skippedFormat + ' skipped (' + skippedReason + ')' + (skippedDetail ? ': ' + skippedDetail : '');
			}
			complete = !!response.complete || queueStatus === 'done' || queueStatus === 'skipped';

			if (!complete) {
				await sleep(mediaUnitDelayMs);
			}
		}

		const skipped = queueStatus === 'skipped';
		const verb = alreadyOptimized ? 'Already optimized attachment #' : (skipped ? 'Checked attachment #' : 'Processed attachment #');
		const statusSuffix = alreadyOptimized ? ' · up to date' : (queueStatus ? ' · ' + queueStatus : '');
		const semanticSkipSuffix = Object.keys(semanticSkips).length ? ' · ' + Object.values(semanticSkips).join(' · ') : '';
		return {
			line:
				verb + item
					+ ' · ' + totalUnits + ' unit' + (totalUnits === 1 ? '' : 's')
					+ ' completed · AVIF ' + avifCount
					+ ' · WebP ' + webpCount
					+ statusSuffix
					+ semanticSkipSuffix,
			progressIncrement: 1,
			attachmentIncrement: 1,
			unitIncrement: totalUnits,
			avifIncrement: avifCount,
			webpIncrement: webpCount,
			successIncrement: skipped ? 0 : 1,
			skippedIncrement: skipped ? 1 : 0,
			failedIncrement: 0,
		};
	}

	async function fetchJobBatch(cursor, limit) {
		const response = await apiRequest('get_media_ids', {
			offset: Math.max(0, Number(cursor || 0)),
			limit,
		});
		return normalizeBatchResponse(response, cursor, limit);
	}

	async function beginManualSession(config) {
		const source = config && typeof config === 'object' ? config : {};
		const response = await apiRequest('media_manual_session', {
			media_format: String(source.mediaFormat || 'best'),
			session_action: 'start',
			token: String(source.preferredToken || ''),
		});
		const token = response && response.token ? String(response.token) : '';
		if (!response || response.success === false || !token) {
			throw new Error(response && response.message ? response.message : 'Dashboard media conversion could not acquire exclusive queue ownership.');
		}
		if (typeof source.setToken === 'function') {
			source.setToken(token);
		}
		return token;
	}

	async function endManualSession(config) {
		const source = config && typeof config === 'object' ? config : {};
		const ownerToken = String(source.token || source.currentToken || '');
		if (!ownerToken) {
			return true;
		}
		try {
			await apiRequest('media_manual_session', {
				media_format: String(source.mediaFormat || 'best'),
				session_action: 'stop',
				token: ownerToken,
			});
			return true;
		} catch (error) {
			return false;
		} finally {
			if (typeof source.clearToken === 'function') {
				source.clearToken(ownerToken);
			}
		}
	}

	function createController(config) {
		const source = config && typeof config === 'object' ? config : {};
		const getJobControls = typeof source.getJobControls === 'function' ? source.getJobControls : function () { return { canResume: false }; };
		const getSavedJob = typeof source.getSavedJob === 'function' ? source.getSavedJob : function () { return null; };
		const isBusy = typeof source.isBusy === 'function' ? source.isBusy : function () { return false; };
		const runJob = typeof source.runJob === 'function' ? source.runJob : async function () {};
		const beginSession = typeof source.beginManualSession === 'function' ? source.beginManualSession : async function () { return ''; };
		const endSession = typeof source.endManualSession === 'function' ? source.endManualSession : async function () { return true; };
		const getSelectedMediaQueueFormat = typeof source.getSelectedMediaQueueFormat === 'function' ? source.getSelectedMediaQueueFormat : function () { return 'best'; };
		const applyMediaQueueStatus = typeof source.applyMediaQueueStatus === 'function' ? source.applyMediaQueueStatus : function () {};
		const setProcess = typeof source.setProcess === 'function' ? source.setProcess : function () {};
		const persistJobState = typeof source.persistJobState === 'function' ? source.persistJobState : function () {};
		const refreshMediaQueueStatus = typeof source.refreshMediaQueueStatus === 'function' ? source.refreshMediaQueueStatus : async function () { return null; };
		const refreshStats = typeof source.refreshStats === 'function' ? source.refreshStats : async function () {};
		const pushToast = typeof source.pushToast === 'function' ? source.pushToast : function () {};
		const setBusy = typeof source.setBusy === 'function' ? source.setBusy : function () {};
		const getCancelRequested = typeof source.getCancelRequested === 'function' ? source.getCancelRequested : function () { return false; };
		const setCancelRequested = typeof source.setCancelRequested === 'function' ? source.setCancelRequested : function () {};
		const getMediaBackgroundControlBusy = typeof source.getMediaBackgroundControlBusy === 'function' ? source.getMediaBackgroundControlBusy : function () { return false; };
		const setMediaBackgroundControlBusy = typeof source.setMediaBackgroundControlBusy === 'function' ? source.setMediaBackgroundControlBusy : function () {};
		const markMediaProcessCancelRequested = typeof source.markMediaProcessCancelRequested === 'function' ? source.markMediaProcessCancelRequested : function () {};
		const defaultBatchSize = Math.max(1, Number(source.defaultBatchSize || 100));

		async function rebuildMediaQueueIndexForRegeneration() {
			let response = null;
			let reset = true;
			let loops = 0;
			let lockWaits = 0;
			let rebuildGeneration = createMediaRebuildGeneration();
			do {
				try {
					response = await apiRequest('media_queue_rebuild', {
						media_format: getSelectedMediaQueueFormat(),
						limit: 0,
						reset,
						time_budget: 20,
						generation: rebuildGeneration,
					});
				} catch (error) {
					const restCode = error && error.rest && error.rest.code ? String(error.rest.code) : '';
					const isLocked = restCode === 'media_queue_rebuild_locked' || !!(error && error.data && error.data.locked);
					const isStale = restCode === 'media_queue_rebuild_stale_generation' || !!(error && error.data && error.data.staleGeneration);
					if (isLocked) {
						lockWaits += 1;
						if (lockWaits > 240) {
							throw new Error('Another media conversion or queue rebuild did not release its lock in time.');
						}
						await sleep(500);
						continue;
					}
					if (isStale) {
						rebuildGeneration = createMediaRebuildGeneration();
						reset = true;
						await sleep(250);
						continue;
					}
					throw error;
				}
				lockWaits = 0;
				reset = false;
				loops += 1;
				rebuildGeneration = response && response.rebuildGeneration ? String(response.rebuildGeneration) : rebuildGeneration;
				applyMediaQueueStatus(response);
				if (response && response.hasMore) {
					await sleep(80);
				}
			} while (response && response.hasMore && loops < 5000);

			return response;
		}

		async function ensureMediaQueueIndexedForRegeneration() {
			const status = await refreshMediaQueueStatus(false);
			const total = Math.max(0, Number(status && status.total ? status.total : 0));
			const buildComplete = !!(status && status.buildComplete);
			if (total > 0 && buildComplete) {
				return status;
			}

			pushToast({ type: 'info', text: 'Preparing media status before regenerating existing optimized images.' });
			return rebuildMediaQueueIndexForRegeneration();
		}

		async function requeueCompletedMediaForRegeneration() {
			await ensureMediaQueueIndexedForRegeneration();
			let response = null;
			let loops = 0;
			let totalRequeued = 0;
			do {
				loops += 1;
				response = await apiRequest('media_queue_requeue_completed_regeneration', {
					media_format: getSelectedMediaQueueFormat(),
				});
				applyMediaQueueStatus(response);
				totalRequeued += Math.max(0, Number(response && response.requeued ? response.requeued : 0));
				if (response && response.hasMore) {
					await sleep(80);
				}
			} while (response && response.hasMore && loops < 5000);

			return {
				response,
				requeued: totalRequeued,
			};
		}

		let mediaOptimizationStartInFlight = false;

		async function startMediaOptimization(forceRestart, forceRegenerateExisting) {
			forceRestart = !!forceRestart;
			forceRegenerateExisting = !!forceRegenerateExisting;
			const controls = getJobControls('media');
			const savedMediaJob = getSavedJob();
			const savedMediaJobIsRegeneration = !!(savedMediaJob && savedMediaJob.type === 'media' && savedMediaJob.forceRegenerateExisting);
			if (!forceRestart && controls.canResume && (savedMediaJobIsRegeneration || !forceRegenerateExisting)) {
				try {
					await runJob(savedMediaJob, false);
				} catch (error) {
					pushToast({ type: 'error', text: error && error.message ? error.message : 'Media optimization could not resume.' });
				}
				return;
			}

			if (mediaOptimizationStartInFlight || isBusy()) {
				return;
			}

			mediaOptimizationStartInFlight = true;
			setBusy(true);
			setCancelRequested(false);
			setProcess({
				type: 'media',
				active: true,
				showWhenInactive: true,
				cancellable: false,
				cancelRequested: false,
				label: forceRegenerateExisting ? 'Preparing Media Regeneration' : 'Preparing Media Conversion',
				current: 0,
				total: 0,
				logs: [forceRegenerateExisting ? 'Preparing regeneration: acquiring dashboard media session…' : 'Preparing media conversion: acquiring dashboard media session…'],
				startTime: Date.now(),
			});

			let preflightSessionToken = '';
			let handedToRunner = false;
			try {
				const savedJob = getSavedJob();
				preflightSessionToken = await beginSession(savedJob && savedJob.manualSessionToken ? savedJob.manualSessionToken : '');
				setProcess(function (prev) {
					return Object.assign({}, prev, {
						logs: (prev.logs || []).concat([forceRegenerateExisting ? 'Dashboard media session acquired; preparing regeneration queue…' : 'Dashboard media session acquired; checking media queue…']).slice(-50),
					});
				});
				let forceRequeue = { requeued: 0 };
				if (forceRegenerateExisting) {
					forceRequeue = await requeueCompletedMediaForRegeneration();
					setProcess(function (prev) {
						return Object.assign({}, prev, {
							logs: (prev.logs || []).concat(['Queued ' + formatNumber(forceRequeue.requeued || 0) + ' already optimized attachment' + (Number(forceRequeue.requeued || 0) === 1 ? '' : 's') + ' for regeneration.']).slice(-50),
						});
					});
					pushToast({
						type: 'info',
						text: 'Queued ' + formatNumber(forceRequeue.requeued || 0) + ' already optimized attachment' + (Number(forceRequeue.requeued || 0) === 1 ? '' : 's') + ' for regeneration.',
					});
				}
				const preflight = await fetchJobBatch('', 1);
				if (preflight.queue) {
					applyMediaQueueStatus(preflight.queue);
				}
				const repaired = preflight.repair && preflight.repair.repaired;
				const requeued = preflight.repair ? Math.max(0, Number(preflight.repair.requeued || 0)) : 0;
				if (!forceRegenerateExisting && !repaired && preflight.queueIsComplete && !preflight.hasMore && !(preflight.items && preflight.items.length)) {
					const alreadyOptimized = Math.max(0, Number(preflight.queueAlreadyOptimized || 0));
					const completeText = alreadyOptimized > 0
						? 'Media conversion complete. ' + formatNumber(alreadyOptimized) + ' attachment' + (alreadyOptimized === 1 ? ' is' : 's are') + ' already optimized/up to date.'
						: 'Media conversion complete. No pending media items need optimization.';
					pushToast({ type: 'success', text: completeText });
					setProcess(function (prev) {
						return Object.assign({}, prev, {
							type: 'media',
							active: false,
							showWhenInactive: true,
							cancellable: false,
							label: __('Media conversion complete', 'ultracache'),
							current: Math.max(0, Number(preflight.total || 0)),
							processed: Math.max(0, Number(preflight.total || 0)),
							total: Math.max(0, Number(preflight.total || 0)),
							logs: [completeText],
						});
					});
					persistJobState(null);
					await refreshMediaQueueStatus(false);
					await refreshStats();
					return;
				}

				const initialItems = Array.isArray(preflight.items) ? preflight.items.slice() : [];
				const initialLogs = forceRegenerateExisting
					? ['Rebuild existing optimized images is enabled; queued ' + formatNumber(forceRequeue.requeued || 0) + ' already optimized attachment' + (Number(forceRequeue.requeued || 0) === 1 ? '' : 's') + ' for regeneration.']
					: (repaired
						? ['Optimized image files were missing; requeued ' + formatNumber(requeued) + ' attachment' + (requeued === 1 ? '' : 's') + ' for repair.']
						: ['Background media generation paused; dashboard has exclusive conversion ownership.']);

				handedToRunner = true;
				await runJob({
					type: 'media',
					label: forceRegenerateExisting ? __('Regenerating Media', 'ultracache') : __('Checking Media', 'ultracache'),
					cursor: preflight.cursor || 0,
					nextCursor: preflight.nextCursor || 0,
					processed: Math.max(0, Number(preflight.queueCompleted || 0)),
					total: Math.max(0, Number(preflight.attachmentTotal || preflight.total || 0)),
					pendingItems: initialItems,
					hasMore: !!preflight.hasMore,
					queueBuilding: !!preflight.queueBuilding,
					logs: initialLogs,
					startTime: Date.now(),
					batchSize: defaultBatchSize,
					manualSessionToken: preflightSessionToken,
					forceRegenerateExisting,
					mode: forceRegenerateExisting ? 'regenerate_existing' : 'normal',
				}, forceRestart, preflightSessionToken);
				preflightSessionToken = '';
			} catch (error) {
				setProcess(function (prev) {
					return Object.assign({}, prev, {
						active: false,
						showWhenInactive: true,
						cancellable: false,
						cancelRequested: false,
						logs: (prev.logs || []).concat([error && error.message ? error.message : 'Media optimization could not start.']).slice(-50),
					});
				});
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media optimization could not start.' });
			} finally {
				if (preflightSessionToken) {
					await endSession(preflightSessionToken);
				}
				if (!handedToRunner) {
					setBusy(false);
				}
				mediaOptimizationStartInFlight = false;
			}
		}

		async function runMediaQueueRestAction(action, label, successText, extraParams) {
			if (isBusy()) {
				return null;
			}
			setBusy(true);
			setProcess({
				type: 'media',
				active: true,
				showWhenInactive: true,
				label,
				current: 0,
				total: 0,
				logs: [label + '…'],
				startTime: Date.now(),
				cancellable: false,
				cancelRequested: false,
			});
			try {
				const params = Object.assign({ media_format: getSelectedMediaQueueFormat() }, extraParams || {});
				let response = null;
				let loops = 0;
				let changedTotal = 0;
				do {
					loops += 1;
					response = await apiRequest(action, params);
					applyMediaQueueStatus(response);
					changedTotal += Math.max(0, Number((response && (response.retried || response.cleared || (response.repair && response.repair.requeued))) || 0));
					if (response && response.hasMore) {
						setProcess(function (prev) {
							return Object.assign({}, prev, {
								logs: (prev.logs || []).concat(['Processed ' + formatNumber(changedTotal) + ' row(s); continuing safely…']).slice(-50),
							});
						});
						await sleep(80);
					}
				} while (response && response.hasMore && loops < 5000);
				const message = response && response.message ? String(response.message) : successText;
				const statusText = 'Queue: ' + formatNumber(response && response.total ? response.total : 0) + ' attachment(s), ' + formatNumber(response && response.pending ? response.pending : 0) + ' pending, ' + formatNumber(response && response.alreadyOptimized ? response.alreadyOptimized : 0) + ' already optimized, ' + formatNumber(response && response.failed ? response.failed : 0) + ' failed.';
				setProcess({
					type: 'media',
					active: false,
					showWhenInactive: true,
					label: label + ' complete',
					complete: true,
					current: Math.max(0, Number(response && response.total ? response.total : 0)),
					total: Math.max(0, Number(response && response.total ? response.total : 0)),
					logs: [message, statusText],
					startTime: Date.now(),
					cancellable: false,
					cancelRequested: false,
				});
				pushToast({ type: 'success', text: successText });
				await refreshMediaQueueStatus(false);
				await refreshStats();
				return response;
			} catch (error) {
				setProcess(function (prev) {
					return Object.assign({}, prev, {
						type: 'media',
						active: false,
						showWhenInactive: true,
						cancellable: false,
						complete: false,
						logs: (prev.logs || []).concat([error && error.message ? error.message : 'Media queue action failed.']).slice(-50),
					});
				});
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media queue action failed.' });
				return null;
			} finally {
				setBusy(false);
			}
		}

		async function rebuildMediaQueue(forceRestart) {
			forceRestart = !!forceRestart;
			const controls = getJobControls('media_rebuild');
			const savedJob = getSavedJob();
			const isResume = !forceRestart && controls.canResume;
			if (!isResume && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm('Rebuild and repair the full media queue? This scans the Media Library, verifies required optimized files, and does not delete existing AVIF/WebP files.')) {
					return;
				}
			}
			if (isBusy()) {
				return;
			}
			setBusy(true);
			setCancelRequested(false);
			const startedAt = Date.now();
			let totalScanned = isResume ? Math.max(0, Number(savedJob && savedJob.scanned ? savedJob.scanned : 0)) : 0;
			let totalQueued = isResume ? Math.max(0, Number(savedJob && savedJob.queued ? savedJob.queued : 0)) : 0;
			let rebuildGeneration = isResume && savedJob && savedJob.rebuildGeneration
				? String(savedJob.rebuildGeneration)
				: createMediaRebuildGeneration();
			const initialProcessed = isResume ? Math.max(0, Number(savedJob && savedJob.processed ? savedJob.processed : 0)) : 0;
			const initialTotal = isResume ? Math.max(0, Number(savedJob && savedJob.total ? savedJob.total : 0)) : 0;
			setProcess({
				type: 'media_rebuild',
				active: true,
				showWhenInactive: true,
				label: isResume ? 'Resuming Media Queue Rebuild / Repair' : 'Rebuilding / Repairing Media Queue',
				current: initialProcessed,
				total: initialTotal,
				logs: [],
				startTime: startedAt,
				cancellable: true,
				cancelRequested: false,
			});
			persistJobState({
				type: 'media_rebuild',
				processed: initialProcessed,
				total: initialTotal,
				scanned: totalScanned,
				queued: totalQueued,
				hasMore: true,
				logs: [],
				startTime: startedAt,
				rebuildGeneration,
			});
			try {
				let reset = forceRestart || !isResume;
				let loops = 0;
				let lockWaits = 0;
				let response = null;
				do {
					if (getCancelRequested()) {
						const pausedJob = {
							type: 'media_rebuild',
							processed: response && response.buildOffset ? Number(response.buildOffset) : totalScanned,
							total: response && response.libraryTotal ? Number(response.libraryTotal) : (response && response.total ? Number(response.total) : 0),
							scanned: totalScanned,
							queued: totalQueued,
							hasMore: true,
							logs: [],
							startTime: startedAt,
							rebuildGeneration,
						};
						persistJobState(pausedJob);
						setProcess(function (prev) { return Object.assign({}, prev, { active: false, cancellable: false, cancelRequested: true }); });
						pushToast({ type: 'success', text: __('Media queue rebuild/repair paused. You can resume it later.', 'ultracache') });
						return;
					}

					try {
						response = await apiRequest('media_queue_rebuild', {
							media_format: getSelectedMediaQueueFormat(),
							limit: 0,
							reset,
							time_budget: 20,
							generation: rebuildGeneration,
						});
					} catch (error) {
						const restCode = error && error.rest && error.rest.code ? String(error.rest.code) : '';
						const isLocked = restCode === 'media_queue_rebuild_locked' || !!(error && error.data && error.data.locked);
						const isStale = restCode === 'media_queue_rebuild_stale_generation' || !!(error && error.data && error.data.staleGeneration);
						if (isLocked) {
							lockWaits += 1;
							if (lockWaits > 240) {
								throw new Error('Another media conversion or queue rebuild did not release its lock in time.');
							}
							await sleep(500);
							continue;
						}
						if (isStale) {
							persistJobState(null);
							throw new Error('This rebuild belongs to an older run and was stopped because a newer rebuild has started.');
						}
						throw error;
					}

					lockWaits = 0;
					reset = false;
					loops += 1;
					rebuildGeneration = response && response.rebuildGeneration ? String(response.rebuildGeneration) : rebuildGeneration;
					totalScanned += Math.max(0, Number(response && response.scanned ? response.scanned : 0));
					totalQueued += Math.max(0, Number(response && response.queued ? response.queued : 0));
					applyMediaQueueStatus(response);
					const total = Math.max(0, Number(response && response.libraryTotal ? response.libraryTotal : (response && response.total ? response.total : 0)));
					const rawCurrent = Math.max(0, Number(response && response.buildOffset ? response.buildOffset : totalScanned));
					const current = total > 0 ? Math.min(rawCurrent, total) : rawCurrent;
					setProcess(function (prev) { return Object.assign({}, prev, { current, total, cancellable: true }); });
					persistJobState({
						type: 'media_rebuild',
						processed: current,
						total,
						scanned: totalScanned,
						queued: totalQueued,
						hasMore: !!(response && response.hasMore),
						logs: [],
						startTime: startedAt,
						rebuildGeneration,
					});
					await sleep(80);
				} while (response && response.hasMore && loops < 5000);

				setProcess(function (prev) {
					const finalTotal = Math.max(0, Number(response && response.libraryTotal ? response.libraryTotal : (prev.total || 0)));
					return Object.assign({}, prev, {
						active: false,
						cancellable: false,
						cancelRequested: false,
						showWhenInactive: true,
						label: __('Media Queue Rebuild / Repair complete', 'ultracache'),
						current: finalTotal,
						total: finalTotal,
						logs: [],
					});
				});
				persistJobState(null);
				pushToast({ type: 'success', text: __('Media queue rebuilt and repaired.', 'ultracache') });
				await refreshMediaQueueStatus(false);
				await refreshStats();
			} catch (error) {
				setProcess(function (prev) {
					return Object.assign({}, prev, {
						active: false,
						cancellable: false,
						showWhenInactive: true,
						logs: (prev.logs || []).concat([error && error.message ? error.message : 'Media queue rebuild failed.']).slice(-50),
					});
				});
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media queue rebuild failed.' });
			} finally {
				setCancelRequested(false);
				setBusy(false);
			}
		}

		async function toggleMediaBackgroundWork(paused) {
			if (getMediaBackgroundControlBusy()) {
				return;
			}

			setMediaBackgroundControlBusy(true);
			if (paused) {
				setCancelRequested(true);
				markMediaProcessCancelRequested(true);
			}

			try {
				const response = await apiRequest('media_background_control', {
					media_format: getSelectedMediaQueueFormat(),
					paused: !!paused,
				});
				applyMediaQueueStatus(response);
				pushToast({
					type: 'success',
					text: paused
						? 'All media generation has been stopped. The queue and existing optimized files were preserved.'
						: 'Media generation resumed.',
				});
			} catch (error) {
				if (paused) {
					setCancelRequested(false);
					markMediaProcessCancelRequested(false);
				}
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media background control failed.' });
			} finally {
				setMediaBackgroundControlBusy(false);
			}
		}

		async function recountOptimizedImageFiles() {
			if (isBusy()) {
				return;
			}
			setBusy(true);
			try {
				const response = await refreshMediaQueueStatus(true);
				const counts = response && response.mediaFileCounts ? response.mediaFileCounts : {};
				const total = Math.max(0, Number(counts.total || 0));
				if (counts.needsRecount) {
					pushToast({ type: 'warning', text: 'The file recount did not finish. The previous count was kept.' });
				} else {
					pushToast({ type: 'success', text: 'Optimized image file count updated. Found ' + formatNumber(total) + ' file' + (total === 1 ? '' : 's') + '.' });
				}
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Optimized image file recount failed.' });
			} finally {
				setBusy(false);
			}
		}

		async function retryFailedMediaQueue() {
			await runMediaQueueRestAction('media_queue_retry_failed', 'Retrying Failed Media Items', 'Interrupted and failed media items moved back to pending.');
		}

		async function clearCompletedMediaQueue() {
			if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
				if (!window.confirm('Clear completed media queue rows? This does not delete optimized image files.')) {
					return;
				}
			}
			await runMediaQueueRestAction('media_queue_clear_completed', 'Clearing Completed Queue Rows', 'Completed media queue rows cleared.');
		}

		return {
			startMediaOptimization,
			runMediaQueueRestAction,
			rebuildMediaQueue,
			toggleMediaBackgroundWork,
			recountOptimizedImageFiles,
			retryFailedMediaQueue,
			clearCompletedMediaQueue,
		};
	}

	admin.define('media', {
		configure,
		processJobItem,
		fetchJobBatch,
		beginManualSession,
		endManualSession,
		createController,
	});
})(window);
