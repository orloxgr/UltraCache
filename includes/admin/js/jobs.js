/* UltraCache Admin - Generic persisted dashboard job engine */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before jobs.js.');
	}

	const core = typeof admin.get === 'function' ? admin.get('core') : null;
	if (!core) {
		throw new Error('UltraCache admin core module is required before jobs.js.');
	}
	const { reportNonFatalAdminError } = core;

	let jobStorageKey = 'ultracache-dashboard-job-state-v3';
	let defaultBatchSize = 100;
	let getNextEtaCheckpoint = function () { return 10; };

	const DEFAULT_NO_PROGRESS_MAX_COUNT = 15;
	const DEFAULT_NO_PROGRESS_MAX_ELAPSED_MS = 60000;
	const DEFAULT_MAX_BATCH_ITERATIONS = 10000;
	const DEFAULT_RETRY_DELAY_MS = 250;
	const MAX_RETRY_DELAY_MS = 4000;

	async function waitForJobRetry(delayMs, isCancelled) {
		let remaining = Math.max(0, Number(delayMs || 0));
		while (remaining > 0 && !isCancelled()) {
			const slice = Math.min(100, remaining);
			await new Promise(function (resolve) {
				window.setTimeout(resolve, slice);
			});
			remaining -= slice;
		}
	}

	function configure(config) {
		const source = config && typeof config === 'object' ? config : {};
		if (source.storageKey) {
			jobStorageKey = String(source.storageKey);
		}
		if (Number(source.defaultBatchSize || 0) > 0) {
			defaultBatchSize = Math.max(1, Number(source.defaultBatchSize));
		}
		if (typeof source.getNextEtaCheckpoint === 'function') {
			getNextEtaCheckpoint = source.getNextEtaCheckpoint;
		}
	}

	function loadSavedJob() {
		try {
			const raw = window.localStorage.getItem(jobStorageKey);
			return raw ? JSON.parse(raw) : null;
		} catch (error) {
			reportNonFatalAdminError('jobs.storage.load', error, { severity: 'warning', dedupeKey: 'jobs.storage.load' });
			return null;
		}
	}

	function saveJob(job) {
		try {
			window.localStorage.setItem(jobStorageKey, JSON.stringify(job));
		} catch (error) {
			reportNonFatalAdminError('jobs.storage.save', error, { severity: 'warning', dedupeKey: 'jobs.storage.save' });
		}
	}

	function clearSavedJob() {
		try {
			window.localStorage.removeItem(jobStorageKey);
		} catch (error) {
			reportNonFatalAdminError('jobs.storage.clear', error, { severity: 'warning', dedupeKey: 'jobs.storage.clear' });
		}
	}

	function getJobControls(savedJob, type, options) {
		const config = options && typeof options === 'object' ? options : {};
		const isWarmJobType = typeof config.isWarmJobType === 'function' ? config.isWarmJobType : function () { return false; };
		const warmupGeneration = Math.max(0, Number(config.warmupGeneration || 0));

		if (!savedJob || savedJob.type !== type) {
			return { canResume: false, canRestart: false, staleAfterFlush: false };
		}

		const processed = Math.max(0, Number(savedJob.processed || 0));
		const total = Math.max(0, Number(savedJob.total || 0));
		const hasPending = Array.isArray(savedJob.pendingItems) && savedJob.pendingItems.length > 0;
		const hasRebuildGeneration = type === 'media_rebuild' && !!String(savedJob.rebuildGeneration || '');
		const hasProgress = processed > 0 || total > 0 || hasPending || hasRebuildGeneration || (Array.isArray(savedJob.logs) && savedJob.logs.length > 0);
		const incomplete = hasPending || !!savedJob.hasMore || total === 0 || processed < total;
		const staleAfterFlush = isWarmJobType(type)
			&& typeof savedJob.warmupGeneration !== 'undefined'
			&& Number(savedJob.warmupGeneration || 0) !== warmupGeneration;

		return {
			canResume: hasProgress && incomplete && !staleAfterFlush,
			canRestart: hasProgress && incomplete && !staleAfterFlush,
			staleAfterFlush,
		};
	}

	function normalizeJobState(job, forceRestart, options) {
		const source = job && typeof job === 'object' ? job : {};
		const config = options && typeof options === 'object' ? options : {};
		const isWarmJobType = typeof config.isWarmJobType === 'function' ? config.isWarmJobType : function () { return false; };
		const warmupGeneration = Math.max(0, Number(config.warmupGeneration || 0));
		const batchSize = Math.max(1, Number(source.batchSize || config.defaultBatchSize || defaultBatchSize));
		const sampleCount = forceRestart ? 0 : Math.max(0, Number(source.etaSampleCount || 0));

		return Object.assign({}, source, {
			cursor: forceRestart ? '' : (typeof source.cursor === 'string' ? source.cursor : ''),
			nextCursor: forceRestart ? '' : (typeof source.nextCursor === 'string' ? source.nextCursor : ''),
			processed: forceRestart ? 0 : Number(source.processed || 0),
			total: Number(source.total || 0),
			pendingItems: forceRestart ? [] : (Array.isArray(source.pendingItems) ? source.pendingItems.slice(0, Math.max(1, Number(config.defaultBatchSize || defaultBatchSize))) : []),
			hasMore: forceRestart ? true : (typeof source.hasMore === 'boolean' ? source.hasMore : true),
			logs: Array.isArray(source.logs) ? source.logs.filter(function (line) { return line !== 'Paused by user.'; }).slice(-50) : [],
			unitCount: forceRestart ? 0 : Math.max(0, Number(source.unitCount || 0)),
			avifCount: forceRestart ? 0 : Math.max(0, Number(source.avifCount || 0)),
			webpCount: forceRestart ? 0 : Math.max(0, Number(source.webpCount || 0)),
			queueBuilding: forceRestart ? false : !!source.queueBuilding,
			active: true,
			cancelRequested: false,
			startTime: forceRestart ? Date.now() : (source.startTime || Date.now()),
			batchSize,
			successCount: forceRestart ? 0 : Math.max(0, Number(source.successCount || 0)),
			skippedCount: forceRestart ? 0 : Math.max(0, Number(source.skippedCount || 0)),
			failedCount: forceRestart ? 0 : Math.max(0, Number(source.failedCount || 0)),
			varnishWarmedCount: forceRestart ? 0 : Math.max(0, Number(source.varnishWarmedCount || 0)),
			liteSpeedWarmedCount: forceRestart ? 0 : Math.max(0, Number(source.liteSpeedWarmedCount || 0)),
			etaMeasuredMs: forceRestart ? 0 : Math.max(0, Number(source.etaMeasuredMs || 0)),
			etaSampleCount: sampleCount,
			etaPerItemMs: forceRestart ? 0 : Math.max(0, Number(source.etaPerItemMs || 0)),
			etaNextCheckpoint: forceRestart ? 10 : Math.max(10, Number(source.etaNextCheckpoint || getNextEtaCheckpoint(sampleCount))),
			warmupGeneration: isWarmJobType(source.type) ? warmupGeneration : source.warmupGeneration,
			currentItem: forceRestart ? '' : String(source.currentItem || ''),
			currentStageLabel: forceRestart ? '' : String(source.currentStageLabel || ''),
			currentPipeline: forceRestart ? null : (source.currentPipeline && typeof source.currentPipeline === 'object' ? source.currentPipeline : null),
		});
	}

	function buildProcessState(state, overrides) {
		const source = state && typeof state === 'object' ? state : {};
		const total = Number(source.total || 0);
		const current = total > 0 ? Math.min(Number(source.processed || 0), total) : Number(source.processed || 0);
		return Object.assign({
			type: source.type || '',
			active: !!source.active,
			label: source.label || '',
			current,
			total,
			queueBuilding: !!source.queueBuilding,
			unitCount: Math.max(0, Number(source.unitCount || 0)),
			successCount: Math.max(0, Number(source.successCount || 0)),
			skippedCount: Math.max(0, Number(source.skippedCount || 0)),
			failedCount: Math.max(0, Number(source.failedCount || 0)),
			avifCount: Math.max(0, Number(source.avifCount || 0)),
			webpCount: Math.max(0, Number(source.webpCount || 0)),
			varnishWarmedCount: Math.max(0, Number(source.varnishWarmedCount || 0)),
			liteSpeedWarmedCount: Math.max(0, Number(source.liteSpeedWarmedCount || 0)),
			logs: Array.isArray(source.logs) ? source.logs : [],
			startTime: Number(source.startTime || 0),
			etaPerItemMs: Math.max(0, Number(source.etaPerItemMs || 0)),
			etaSampleCount: Math.max(0, Number(source.etaSampleCount || 0)),
			etaNextCheckpoint: Math.max(10, Number(source.etaNextCheckpoint || 10)),
			cancellable: !!source.active,
			cancelRequested: !!source.cancelRequested,
			showWhenInactive: !!source.showWhenInactive || source.type === 'media',
			currentItem: String(source.currentItem || ''),
			currentStageLabel: String(source.currentStageLabel || ''),
		}, overrides && typeof overrides === 'object' ? overrides : {});
	}

	function normalizeJobItemResult(itemResult) {
		const isObject = itemResult && typeof itemResult === 'object';
		return {
			line: isObject ? itemResult.line : itemResult,
			progressIncrement: isObject ? Math.max(0, Number(itemResult.progressIncrement || 0)) : 1,
			attachmentIncrement: isObject ? Math.max(0, Number(itemResult.attachmentIncrement || 0)) : 0,
			unitIncrement: isObject ? Math.max(0, Number(itemResult.unitIncrement || 0)) : 0,
			successIncrement: isObject ? Math.max(0, Number(itemResult.successIncrement || 0)) : 1,
			skippedIncrement: isObject ? Math.max(0, Number(itemResult.skippedIncrement || 0)) : 0,
			failedIncrement: isObject ? Math.max(0, Number(itemResult.failedIncrement || 0)) : 0,
			avifIncrement: isObject ? Math.max(0, Number(itemResult.avifIncrement || 0)) : 0,
			webpIncrement: isObject ? Math.max(0, Number(itemResult.webpIncrement || 0)) : 0,
			varnishWarmedIncrement: isObject ? Math.max(0, Number(itemResult.varnishWarmedIncrement || 0)) : 0,
			liteSpeedWarmedIncrement: isObject ? Math.max(0, Number(itemResult.liteSpeedWarmedIncrement || 0)) : 0,
			varnishVerifiedIncrement: isObject ? Math.max(0, Number(itemResult.varnishVerifiedIncrement || 0)) : 0,
			varnishBypassedIncrement: isObject ? Math.max(0, Number(itemResult.varnishBypassedIncrement || 0)) : 0,
			varnishInconclusiveIncrement: isObject ? Math.max(0, Number(itemResult.varnishInconclusiveIncrement || 0)) : 0,
		};
	}

	function applyJobItemResult(state, itemResult, itemElapsedMs, options) {
		const source = state && typeof state === 'object' ? state : {};
		const result = normalizeJobItemResult(itemResult);
		const config = options && typeof options === 'object' ? options : {};
		const shouldMeasureEta = typeof config.shouldMeasureEta === 'function' ? config.shouldMeasureEta : function () { return false; };
		const nextProcessed = Number(source.processed || 0) + result.progressIncrement;
		let etaMeasuredMs = Math.max(0, Number(source.etaMeasuredMs || 0));
		let etaSampleCount = Math.max(0, Number(source.etaSampleCount || 0));
		let etaPerItemMs = Math.max(0, Number(source.etaPerItemMs || 0));
		let etaNextCheckpoint = Math.max(10, Number(source.etaNextCheckpoint || getNextEtaCheckpoint(etaSampleCount)));

		if (shouldMeasureEta(source.type) && result.progressIncrement > 0) {
			etaMeasuredMs += Math.max(0, Number(itemElapsedMs || 0));
			etaSampleCount += result.progressIncrement;
			if (etaSampleCount >= etaNextCheckpoint) {
				etaPerItemMs = etaMeasuredMs / Math.max(1, etaSampleCount);
				etaNextCheckpoint = getNextEtaCheckpoint(etaSampleCount);
			}
		}

		const patch = {
			processed: nextProcessed,
			attachmentsProcessed: Number(source.attachmentsProcessed || 0) + result.attachmentIncrement,
			unitCount: Number(source.unitCount || 0) + result.unitIncrement,
			avifCount: Number(source.avifCount || 0) + result.avifIncrement,
			webpCount: Number(source.webpCount || 0) + result.webpIncrement,
			successCount: Number(source.successCount || 0) + result.successIncrement,
			skippedCount: Number(source.skippedCount || 0) + result.skippedIncrement,
			failedCount: Number(source.failedCount || 0) + result.failedIncrement,
			varnishWarmedCount: Number(source.varnishWarmedCount || 0) + result.varnishWarmedIncrement,
			liteSpeedWarmedCount: Number(source.liteSpeedWarmedCount || 0) + result.liteSpeedWarmedIncrement,
			etaMeasuredMs,
			etaSampleCount,
			etaPerItemMs,
			etaNextCheckpoint,
		};

		if (typeof config.decorateState === 'function') {
			Object.assign(patch, config.decorateState(source, result) || {});
		}

		return {
			state: Object.assign({}, source, patch),
			result,
		};
	}

	function createJobRunner(callbacks) {
		const cb = callbacks && typeof callbacks === 'object' ? callbacks : {};
		const isWarmJobType = typeof cb.isWarmJobType === 'function' ? cb.isWarmJobType : function () { return false; };
		const getWarmScopeForType = typeof cb.getWarmScopeForType === 'function' ? cb.getWarmScopeForType : function () { return 'full'; };
		const getWarmupGeneration = typeof cb.getWarmupGeneration === 'function' ? cb.getWarmupGeneration : function () { return 0; };
		const isCancelled = typeof cb.isCancelled === 'function' ? cb.isCancelled : function () { return false; };
		const resetCancel = typeof cb.resetCancel === 'function' ? cb.resetCancel : function () {};
		const setBusy = typeof cb.setBusy === 'function' ? cb.setBusy : function () {};
		const updateProcessState = typeof cb.updateProcessState === 'function' ? cb.updateProcessState : function () {};
		const persistJobState = typeof cb.persistJobState === 'function' ? cb.persistJobState : function () {};
		const fetchBatch = typeof cb.fetchBatch === 'function' ? cb.fetchBatch : async function () { return { items: [], total: 0, hasMore: false }; };
		const processItem = typeof cb.processItem === 'function' ? cb.processItem : async function () { return ''; };
		const pushToast = typeof cb.pushToast === 'function' ? cb.pushToast : function () {};
		const shouldAcquireExclusiveSession = typeof cb.shouldAcquireExclusiveSession === 'function' ? cb.shouldAcquireExclusiveSession : function () { return false; };
		const beginExclusiveSession = typeof cb.beginExclusiveSession === 'function' ? cb.beginExclusiveSession : async function () { return ''; };
		const endExclusiveSession = typeof cb.endExclusiveSession === 'function' ? cb.endExclusiveSession : async function () { return true; };
		const pauseExclusiveSession = typeof cb.pauseExclusiveSession === 'function' ? cb.pauseExclusiveSession : async function () { return true; };
		const failExclusiveSession = typeof cb.failExclusiveSession === 'function' ? cb.failExclusiveSession : async function () { return true; };
		const shouldReleaseExclusiveSessionOnExit = typeof cb.shouldReleaseExclusiveSessionOnExit === 'function' ? cb.shouldReleaseExclusiveSessionOnExit : function () { return true; };
		const getBatchStatePatch = typeof cb.getBatchStatePatch === 'function' ? cb.getBatchStatePatch : function (state, batch) {
			return { total: Math.max(Number(state.total || 0), Number(batch.total || 0)) };
		};
		const shouldMeasureEta = typeof cb.shouldMeasureEta === 'function' ? cb.shouldMeasureEta : function () { return false; };
		const decorateStateAfterItem = typeof cb.decorateStateAfterItem === 'function' ? cb.decorateStateAfterItem : function () { return {}; };
		const buildCompletionNotice = typeof cb.buildCompletionNotice === 'function' ? cb.buildCompletionNotice : function () { return { type: 'success', text: 'Job complete.' }; };
		const onCompleted = typeof cb.onCompleted === 'function' ? cb.onCompleted : async function () {};
		const markProcessComplete = typeof cb.markProcessComplete === 'function' ? cb.markProcessComplete : function () {};
		const getFailureText = typeof cb.getFailureText === 'function' ? cb.getFailureText : function () { return 'Job failed.'; };
		const hasReleaseFailureHandler = typeof cb.onReleaseFailure === 'function';
		const onReleaseFailure = hasReleaseFailureHandler ? cb.onReleaseFailure : function () {};
		const onPaused = typeof cb.onPaused === 'function' ? cb.onPaused : function () {};
		const waitForRetry = typeof cb.waitForRetry === 'function' ? cb.waitForRetry : waitForJobRetry;
		const getNow = typeof cb.getNow === 'function' ? cb.getNow : function () { return Date.now(); };
		const noProgressMaxCount = Math.max(1, Number(cb.noProgressMaxCount || DEFAULT_NO_PROGRESS_MAX_COUNT));
		const noProgressMaxElapsedMs = Math.max(1000, Number(cb.noProgressMaxElapsedMs || DEFAULT_NO_PROGRESS_MAX_ELAPSED_MS));
		const maxBatchIterations = Math.max(1, Number(cb.maxBatchIterations || DEFAULT_MAX_BATCH_ITERATIONS));

		function reportExclusiveSessionFailure(context, error, message) {
			reportNonFatalAdminError(context, error, {
				severity: 'warning',
				dedupeKey: context,
				pushToast,
				userVisible: true,
				toastText: message,
			});
		}

		return async function runJob(job, forceRestart, existingExclusiveToken) {
			let state = normalizeJobState(job, forceRestart, {
				isWarmJobType,
				warmupGeneration: getWarmupGeneration(),
				defaultBatchSize,
			});
			let exclusiveToken = '';
			let completed = false;
			let batchIterationCount = 0;
			let noProgressCount = 0;
			let noProgressStartedAt = null;
			let lastQueueProgressToken = '';
			resetCancel();
			setBusy(true);
			updateProcessState(state);
			persistJobState(state);

			try {
				if (shouldAcquireExclusiveSession(state.type)) {
					exclusiveToken = await beginExclusiveSession(state, existingExclusiveToken || state.manualSessionToken || (job && job.manualSessionToken) || '');
					state = Object.assign({}, state, { manualSessionToken: exclusiveToken });
					updateProcessState(state);
					persistJobState(state);
				}

				while (true) {
					batchIterationCount += 1;
					if (batchIterationCount > maxBatchIterations) {
						throw new Error('Job batch iteration limit reached without completion. The job was paused and can be resumed.');
					}
					if (isCancelled()) {
						state = Object.assign({}, state, {
							active: false,
							cancelRequested: true,
							logs: state.logs.concat(['Paused by user.']).slice(-50),
						});
						persistJobState(state);
						updateProcessState(state, { active: false, cancellable: false });
						if (exclusiveToken) {
							try {
								await pauseExclusiveSession(state, exclusiveToken);
							} catch (sessionError) {
								reportExclusiveSessionFailure('jobs.session.pause', sessionError, 'The job paused locally, but its server lease could not be paused. Check the current job status before resuming.');
							}
						}
						onPaused(state, exclusiveToken);
						break;
					}

					let batchItems = Array.isArray(state.pendingItems) ? state.pendingItems.slice() : [];
					let batchNextCursor = state.nextCursor || state.cursor || '';
					let batchHasMore = typeof state.hasMore === 'boolean' ? state.hasMore : true;
					let batchWaitingForQueueBuild = false;
					let batchRetryAfterMs = 0;
					let batchProgressToken = '';
					const previousCursor = String(state.cursor || '');

					if (!batchItems.length) {
						const batch = await fetchBatch(state.type, state.cursor || '', state.batchSize, state.scope || getWarmScopeForType(state.type), state);
						batchItems = Array.isArray(batch.items) ? batch.items.slice() : [];
						batchNextCursor = batch.nextCursor || '';
						batchHasMore = !!batch.hasMore;
						batchWaitingForQueueBuild = !!batch.waitingForQueueBuild;
						batchRetryAfterMs = Math.max(0, Number(batch.retryAfterMs || 0));
						batchProgressToken = String(batch.queueProgressToken || '');
						state = Object.assign({}, state, getBatchStatePatch(state, batch), {
							hasMore: batchHasMore,
							nextCursor: batchNextCursor,
							pendingItems: batchItems.slice(),
							waitingForQueueBuild: batchWaitingForQueueBuild,
							queueRetryAfterMs: batchRetryAfterMs,
							queueProgressToken: batchProgressToken,
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (!batchItems.length) {
						completed = !batchHasMore;
						if (!completed) {
							const cursorAdvanced = String(batchNextCursor || '') !== previousCursor;
							const progressTokenAdvanced = !!batchProgressToken && batchProgressToken !== lastQueueProgressToken;
							if (cursorAdvanced || progressTokenAdvanced) {
								noProgressCount = 0;
								noProgressStartedAt = null;
							} else {
								noProgressCount += 1;
								if (null === noProgressStartedAt) {
									noProgressStartedAt = getNow();
								}
							}
							if (batchProgressToken) {
								lastQueueProgressToken = batchProgressToken;
							}
							state = Object.assign({}, state, {
								cursor: batchNextCursor,
								nextCursor: '',
								hasMore: batchHasMore,
							});
							persistJobState(state);
							const noProgressElapsedMs = null !== noProgressStartedAt ? Math.max(0, getNow() - noProgressStartedAt) : 0;
							if (noProgressCount >= noProgressMaxCount || noProgressElapsedMs >= noProgressMaxElapsedMs) {
								const message = state.type === 'media'
									? 'Media queue build is not making progress. The job was paused and can be resumed after the active queue builder advances.'
									: 'Job batching is not making progress. The job was paused and can be resumed.';
								throw new Error(message);
							}
							const shouldWait = batchWaitingForQueueBuild || noProgressCount > 0;
							if (shouldWait) {
								const baseDelay = Math.max(DEFAULT_RETRY_DELAY_MS, batchRetryAfterMs || DEFAULT_RETRY_DELAY_MS);
								const retryDelay = Math.min(MAX_RETRY_DELAY_MS, baseDelay * Math.pow(2, Math.max(0, noProgressCount - 1)));
								await waitForRetry(retryDelay, isCancelled);
							}
						}
						if (completed) {
							break;
						}
						continue;
					}

					noProgressCount = 0;
					noProgressStartedAt = null;
					if (batchProgressToken) {
						lastQueueProgressToken = batchProgressToken;
					}

					for (let index = 0; index < batchItems.length; index += 1) {
						if (isCancelled()) {
							break;
						}

						const item = batchItems[index];
						const itemStartedAt = Date.now();
						const checkpointItem = (patch) => {
							state = Object.assign({}, state, patch && typeof patch === 'object' ? patch : {});
							updateProcessState(state);
							persistJobState(state);
						};
						const itemResult = await processItem(state.type, item, isCancelled, exclusiveToken, state, checkpointItem);
						if (itemResult && itemResult.pauseItem) {
							state = Object.assign({}, state, {
								active: false,
								cancelRequested: true,
								pendingItems: batchItems.slice(index),
								nextCursor: batchNextCursor,
								hasMore: batchHasMore,
								logs: state.logs.concat([itemResult.line || 'Paused by user.']).slice(-50),
							});
							persistJobState(state);
							updateProcessState(state, { active: false, cancellable: false });
							if (exclusiveToken) {
								try {
									await pauseExclusiveSession(state, exclusiveToken);
								} catch (sessionError) {
									reportExclusiveSessionFailure('jobs.session.pause', sessionError, 'The job paused locally, but its server lease could not be paused. Check the current job status before resuming.');
								}
							}
							onPaused(state, exclusiveToken);
							return;
						}
						const applied = applyJobItemResult(state, itemResult, Math.max(0, Date.now() - itemStartedAt), {
							shouldMeasureEta,
							decorateState: decorateStateAfterItem,
						});
						state = Object.assign({}, applied.state, {
							logs: state.logs.concat([applied.result.line]).slice(-50),
							pendingItems: batchItems.slice(index + 1),
							nextCursor: batchNextCursor,
							hasMore: batchHasMore,
							cancelRequested: isCancelled(),
							currentItem: '',
							currentStageLabel: '',
							currentPipeline: null,
						});
						updateProcessState(state);
						persistJobState(state);
					}

					if (isCancelled()) {
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
					const finalNotice = buildCompletionNotice(state);
					state = Object.assign({}, state, { logs: state.logs.concat([finalNotice.text]).slice(-50) });
					await onCompleted(state, finalNotice);
					pushToast(finalNotice);
					markProcessComplete(state);
					persistJobState(null);
				}
			} catch (error) {
				const failureText = error && error.message ? error.message : getFailureText(state.type);
				state = Object.assign({}, state, {
					active: false,
					cancelRequested: false,
					logs: state.logs.concat([failureText]).slice(-50),
				});
				persistJobState(state);
				updateProcessState(state, { active: false, cancellable: false, cancelRequested: false });
				if (exclusiveToken) {
					try {
						await failExclusiveSession(state, exclusiveToken);
					} catch (sessionError) {
						reportExclusiveSessionFailure('jobs.session.fail', sessionError, 'The job failed, and its server lease could not be marked failed. Refresh status before retrying.');
					}
				}
				pushToast({ type: 'error', text: failureText });
			} finally {
				resetCancel();
				if (shouldAcquireExclusiveSession(state.type) && exclusiveToken && shouldReleaseExclusiveSessionOnExit(state, completed)) {
					try {
						const released = await endExclusiveSession(state, exclusiveToken);
						if (!released) {
							reportNonFatalAdminError('jobs.session.release', new Error('Exclusive server lease release returned false.'), {
								severity: 'warning',
								dedupeKey: 'jobs.session.release',
								pushToast,
								userVisible: !hasReleaseFailureHandler,
								toastText: 'The job finished locally, but its server lease could not be released. Refresh status before starting another exclusive job.',
							});
							onReleaseFailure(state);
						}
					} catch (releaseError) {
						reportNonFatalAdminError('jobs.session.release', releaseError, {
							severity: 'warning',
							dedupeKey: 'jobs.session.release',
							pushToast,
							userVisible: !hasReleaseFailureHandler,
							toastText: 'The job finished locally, but its server lease could not be released. Refresh status before starting another exclusive job.',
						});
						onReleaseFailure(state);
					}
				}
				setBusy(false);
			}
		};
	}

	admin.define('jobs', {
		configure,
		loadSavedJob,
		saveJob,
		clearSavedJob,
		getJobControls,
		buildProcessState,
		createJobRunner,
	});
})(window);
