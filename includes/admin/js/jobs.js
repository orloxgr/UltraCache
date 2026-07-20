/* UltraCache Admin - Generic persisted dashboard job engine */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before jobs.js.');
	}

	let jobStorageKey = 'ultracache-dashboard-job-state-v3';
	let defaultBatchSize = 100;
	let getNextEtaCheckpoint = function () { return 10; };

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
			return null;
		}
	}

	function saveJob(job) {
		try {
			window.localStorage.setItem(jobStorageKey, JSON.stringify(job));
		} catch (error) {}
	}

	function clearSavedJob() {
		try {
			window.localStorage.removeItem(jobStorageKey);
		} catch (error) {}
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
			varnishVerifiedCount: forceRestart ? 0 : Math.max(0, Number(source.varnishVerifiedCount || 0)),
			varnishBypassedCount: forceRestart ? 0 : Math.max(0, Number(source.varnishBypassedCount || 0)),
			varnishInconclusiveCount: forceRestart ? 0 : Math.max(0, Number(source.varnishInconclusiveCount || 0)),
			etaMeasuredMs: forceRestart ? 0 : Math.max(0, Number(source.etaMeasuredMs || 0)),
			etaSampleCount: sampleCount,
			etaPerItemMs: forceRestart ? 0 : Math.max(0, Number(source.etaPerItemMs || 0)),
			etaNextCheckpoint: forceRestart ? 10 : Math.max(10, Number(source.etaNextCheckpoint || getNextEtaCheckpoint(sampleCount))),
			warmupGeneration: isWarmJobType(source.type) ? warmupGeneration : source.warmupGeneration,
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
			avifCount: Math.max(0, Number(source.avifCount || 0)),
			webpCount: Math.max(0, Number(source.webpCount || 0)),
			varnishWarmedCount: Math.max(0, Number(source.varnishWarmedCount || 0)),
			varnishVerifiedCount: Math.max(0, Number(source.varnishVerifiedCount || 0)),
			varnishBypassedCount: Math.max(0, Number(source.varnishBypassedCount || 0)),
			varnishInconclusiveCount: Math.max(0, Number(source.varnishInconclusiveCount || 0)),
			logs: Array.isArray(source.logs) ? source.logs : [],
			startTime: Number(source.startTime || 0),
			etaPerItemMs: Math.max(0, Number(source.etaPerItemMs || 0)),
			etaSampleCount: Math.max(0, Number(source.etaSampleCount || 0)),
			etaNextCheckpoint: Math.max(10, Number(source.etaNextCheckpoint || 10)),
			cancellable: !!source.active,
			cancelRequested: !!source.cancelRequested,
			showWhenInactive: !!source.showWhenInactive || source.type === 'media',
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
			varnishVerifiedCount: Number(source.varnishVerifiedCount || 0) + result.varnishVerifiedIncrement,
			varnishBypassedCount: Number(source.varnishBypassedCount || 0) + result.varnishBypassedIncrement,
			varnishInconclusiveCount: Number(source.varnishInconclusiveCount || 0) + result.varnishInconclusiveIncrement,
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
		const onReleaseFailure = typeof cb.onReleaseFailure === 'function' ? cb.onReleaseFailure : function () {};
		const onPaused = typeof cb.onPaused === 'function' ? cb.onPaused : function () {};

		return async function runJob(job, forceRestart, existingExclusiveToken) {
			let state = normalizeJobState(job, forceRestart, {
				isWarmJobType,
				warmupGeneration: getWarmupGeneration(),
				defaultBatchSize,
			});
			let exclusiveToken = '';
			let completed = false;
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
							} catch (sessionError) {}
						}
						onPaused(state, exclusiveToken);
						break;
					}

					let batchItems = Array.isArray(state.pendingItems) ? state.pendingItems.slice() : [];
					let batchNextCursor = state.nextCursor || state.cursor || '';
					let batchHasMore = typeof state.hasMore === 'boolean' ? state.hasMore : true;

					if (!batchItems.length) {
						const batch = await fetchBatch(state.type, state.cursor || '', state.batchSize, state.scope || getWarmScopeForType(state.type), state);
						batchItems = Array.isArray(batch.items) ? batch.items.slice() : [];
						batchNextCursor = batch.nextCursor || '';
						batchHasMore = !!batch.hasMore;
						state = Object.assign({}, state, getBatchStatePatch(state, batch), {
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

					for (let index = 0; index < batchItems.length; index += 1) {
						if (isCancelled()) {
							break;
						}

						const item = batchItems[index];
						const itemStartedAt = Date.now();
						const itemResult = await processItem(state.type, item, isCancelled, exclusiveToken, state);
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
					} catch (sessionError) {}
				}
				pushToast({ type: 'error', text: failureText });
			} finally {
				resetCancel();
				if (shouldAcquireExclusiveSession(state.type) && exclusiveToken && shouldReleaseExclusiveSessionOnExit(state, completed)) {
					const released = await endExclusiveSession(state, exclusiveToken);
					if (!released) {
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
