/* UltraCache Admin - Media Library replacement state and workflow orchestration */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before media-replacement.js.');
	}

	const core = admin.get('core');
	const api = admin.get('api');
	const jobs = admin.get('jobs');
	if (!core || !api || !jobs) {
		throw new Error('UltraCache admin core/api/jobs modules are required before media-replacement.js.');
	}

	const { useState, useEffect, useRef, __ } = core;
	const { apiRequest } = api;
	const { createJobRunner } = jobs;
	const MEDIA_REPLACEMENT_SESSION_STORAGE_KEY = 'ultracache-media-replacement-session-v1';

	function normalizeMediaLibraryReplacementSessionToken(token) {
		return String(token || '').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 128);
	}

	function loadMediaLibraryReplacementSessionToken() {
		try {
			return normalizeMediaLibraryReplacementSessionToken(window.sessionStorage.getItem(MEDIA_REPLACEMENT_SESSION_STORAGE_KEY));
		} catch (error) {
			return '';
		}
	}

	function persistMediaLibraryReplacementSessionToken(token) {
		const normalizedToken = normalizeMediaLibraryReplacementSessionToken(token);
		try {
			if (normalizedToken) {
				window.sessionStorage.setItem(MEDIA_REPLACEMENT_SESSION_STORAGE_KEY, normalizedToken);
			} else {
				window.sessionStorage.removeItem(MEDIA_REPLACEMENT_SESSION_STORAGE_KEY);
			}
		} catch (error) {}
		return normalizedToken;
	}


	function useMediaReplacementState(initialStatus) {
		const [mediaLibraryReplacementBusy, setMediaLibraryReplacementBusy] = useState(false);
		const [mediaLibraryReplacementStatus, setMediaLibraryReplacementStatus] = useState(initialStatus);
		const [mediaLibraryReplacementPreview, setMediaLibraryReplacementPreview] = useState(null);
		const [mediaLibraryReplacementPreviewOpen, setMediaLibraryReplacementPreviewOpen] = useState(false);
		const [mediaLibraryReplacementDbPreview, setMediaLibraryReplacementDbPreview] = useState(null);
		const [mediaLibraryReplacementDbPreviewOpen, setMediaLibraryReplacementDbPreviewOpen] = useState(false);
		const [mediaLibraryReplacementCleanupPreview, setMediaLibraryReplacementCleanupPreview] = useState(null);
		const [mediaLibraryReplacementCleanupPreviewOpen, setMediaLibraryReplacementCleanupPreviewOpen] = useState(false);
		const [mediaLibraryReplacementWarningAction, setMediaLibraryReplacementWarningAction] = useState('');
		const [mediaLibraryReplacementWarningConfirmation, setMediaLibraryReplacementWarningConfirmation] = useState('');
		const mediaLibraryReplacementSessionTokenRef = useRef(loadMediaLibraryReplacementSessionToken());
		const mediaLibraryReplacementStatusRefreshInFlightRef = useRef(false);

		return {
			mediaLibraryReplacementBusy,
			setMediaLibraryReplacementBusy,
			mediaLibraryReplacementStatus,
			setMediaLibraryReplacementStatus,
			mediaLibraryReplacementPreview,
			setMediaLibraryReplacementPreview,
			mediaLibraryReplacementPreviewOpen,
			setMediaLibraryReplacementPreviewOpen,
			mediaLibraryReplacementDbPreview,
			setMediaLibraryReplacementDbPreview,
			mediaLibraryReplacementDbPreviewOpen,
			setMediaLibraryReplacementDbPreviewOpen,
			mediaLibraryReplacementCleanupPreview,
			setMediaLibraryReplacementCleanupPreview,
			mediaLibraryReplacementCleanupPreviewOpen,
			setMediaLibraryReplacementCleanupPreviewOpen,
			mediaLibraryReplacementWarningAction,
			setMediaLibraryReplacementWarningAction,
			mediaLibraryReplacementWarningConfirmation,
			setMediaLibraryReplacementWarningConfirmation,
			mediaLibraryReplacementSessionTokenRef,
			mediaLibraryReplacementStatusRefreshInFlightRef,
		};
	}

	function useMediaReplacementWorkflow(config) {
		const source = config && typeof config === 'object' ? config : {};
		const {
			mediaLibraryReplacementBusy,
			setMediaLibraryReplacementBusy,
			mediaLibraryReplacementStatus,
			setMediaLibraryReplacementStatus,
			mediaLibraryReplacementPreview,
			setMediaLibraryReplacementPreview,
			mediaLibraryReplacementPreviewOpen,
			setMediaLibraryReplacementPreviewOpen,
			mediaLibraryReplacementDbPreview,
			setMediaLibraryReplacementDbPreview,
			mediaLibraryReplacementDbPreviewOpen,
			setMediaLibraryReplacementDbPreviewOpen,
			mediaLibraryReplacementCleanupPreview,
			setMediaLibraryReplacementCleanupPreview,
			mediaLibraryReplacementCleanupPreviewOpen,
			setMediaLibraryReplacementCleanupPreviewOpen,
			mediaLibraryReplacementWarningAction,
			setMediaLibraryReplacementWarningAction,
			mediaLibraryReplacementWarningConfirmation,
			setMediaLibraryReplacementWarningConfirmation,
			mediaLibraryReplacementSessionTokenRef,
			mediaLibraryReplacementStatusRefreshInFlightRef,
			busy,
			mediaConversionTestBusy,
			process,
			cancelRequestedRef,
			setBusy,
			setProcess,
			updateProcessState,
			pushToast,
			getMediaConversionTestCacheBust,
		} = source;

		async function refreshMediaLibraryReplacementWorkflowStatus(jobId = '') {
			if (mediaLibraryReplacementStatusRefreshInFlightRef.current) {
				return mediaLibraryReplacementStatus || null;
			}

			mediaLibraryReplacementStatusRefreshInFlightRef.current = true;
			try {
				const response = await apiRequest('media_library_replacement_status', {
					cacheBust: getMediaConversionTestCacheBust(),
					jobId: jobId || getMediaLibraryReplacementCurrentJobId(),
				});
				setMediaLibraryReplacementStatus(response || null);
				if (response && response.replacementSession && response.replacementSession.active === false) {
					mediaLibraryReplacementSessionTokenRef.current = persistMediaLibraryReplacementSessionToken('');
				}
				return response;
			} finally {
				mediaLibraryReplacementStatusRefreshInFlightRef.current = false;
			}
		}

		useEffect(() => {
			let disposed = false;
			const refreshReplacementStatus = async () => {
				if (disposed || (typeof document !== 'undefined' && document.hidden)) {
					return;
				}
				try {
					await refreshMediaLibraryReplacementWorkflowStatus();
				} catch (error) {}
			};

			refreshReplacementStatus();
			const intervalId = window.setInterval(refreshReplacementStatus, 10000);
			return () => {
				disposed = true;
				window.clearInterval(intervalId);
			};
		}, []);

		async function persistMediaLibraryReplacementWorkflowStage(stage, jobId = '', message = '') {
			const response = await apiRequest('media_library_replacement_workflow_stage', {
				cacheBust: getMediaConversionTestCacheBust(),
				jobId: jobId || getMediaLibraryReplacementCurrentJobId(),
				stage,
				message,
			});
			setMediaLibraryReplacementStatus(response || null);
			return response;
		}

		async function loadMediaLibraryReplacementPreviewPage(offset = 0, jobId = '') {
			const response = await apiRequest('media_library_replacement_preview', {
				cacheBust: getMediaConversionTestCacheBust(),
				jobId: jobId || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? mediaLibraryReplacementStatus.jobId : ''),
				limit: 200,
				offset: Math.max(0, Number(offset || 0)),
			});
			setMediaLibraryReplacementPreview(response || null);
			return response;
		}


		function canRefreshMediaLibraryReplacementMappingPreview(response) {
			if (!response || !response.success || response.blocked || response.emptyRegistry || response.restartBlocked) {
				return false;
			}
			const status = response.status ? String(response.status) : '';
			return status !== 'blocked_no_candidates' && status !== 'empty_registry';
		}

		function canRefreshMediaLibraryReplacementDatabasePreview(response) {
			if (!response || !response.success || response.blocked || response.emptyRegistry || response.restartBlocked) {
				return false;
			}
			const status = response.status ? String(response.status) : '';
			return status !== 'blocked_no_candidates' && status !== 'empty_registry';
		}

		function getMediaLibraryReplacementNextActionKey() {
			if (mediaLibraryReplacementBusy) {
				return '';
			}

			const statusData = mediaLibraryReplacementStatus && typeof mediaLibraryReplacementStatus === 'object' ? mediaLibraryReplacementStatus : {};
			const mappingSummary = mediaLibraryReplacementPreview && mediaLibraryReplacementPreview.summary && typeof mediaLibraryReplacementPreview.summary === 'object' ? mediaLibraryReplacementPreview.summary : {};
			const dbSummary = mediaLibraryReplacementDbPreview && mediaLibraryReplacementDbPreview.summary && typeof mediaLibraryReplacementDbPreview.summary === 'object' ? mediaLibraryReplacementDbPreview.summary : (statusData.summary && typeof statusData.summary === 'object' ? statusData.summary : {});
			const status = statusData.status ? String(statusData.status) : '';
			const jobId = statusData.jobId || (mediaLibraryReplacementPreview && mediaLibraryReplacementPreview.jobId) || (mediaLibraryReplacementDbPreview && mediaLibraryReplacementDbPreview.jobId) || '';

			if (!jobId) {
				return 'prepare';
			}
			if (statusData.blocked || statusData.emptyRegistry || statusData.restartBlocked || status === 'blocked_no_candidates' || status === 'empty_registry') {
				return 'restart';
			}
			if (status === 'preparing' || status === 'prepared') {
				return statusData.hasMore ? 'prepare' : 'preview';
			}
			if (status === 'copying') {
				return 'copy';
			}
			if (status === 'metadata_preparing') {
				return 'metadata_prepare';
			}
			if (status === 'metadata_ready') {
				return 'metadata_apply';
			}
			if (status === 'metadata_applying') {
				return 'metadata_apply';
			}
			if (status === 'metadata_updated') {
				return 'refs_scan';
			}
			if (status === 'ref_indexing' || status === 'ref_indexed') {
				return statusData.hasMore ? 'refs_scan' : 'refs_match';
			}
			if (status === 'refs_matching' || status === 'refs_matched') {
				return statusData.hasMore ? 'refs_match' : 'db_preview';
			}
			if (status === 'db_replacing') {
				return 'db_apply';
			}
			if (status === 'db_replaced') {
				return 'db_verify';
			}
			if (status === 'db_verifying') {
				return 'db_verify';
			}
			if (status === 'db_verified') {
				return 'theme_css_scan';
			}
			if (status === 'theme_css_scanning') {
				return 'theme_css_scan';
			}
			if (status === 'theme_css_scanned') {
				return 'theme_css_preview';
			}
			if (status === 'theme_css_preview') {
				return Number(statusData.themeCssPendingRefs || 0) > 0 ? 'theme_css_apply' : 'cleanup_preview';
			}
			if (status === 'theme_css_applying' || status === 'theme_css_applied') {
				return Number(statusData.themeCssAppliedRefs || 0) > 0 || Number(statusData.themeCssVerifyFailedRefs || 0) > 0 ? 'theme_css_verify' : 'cleanup_preview';
			}
			if (status === 'theme_css_verifying') {
				return 'theme_css_verify';
			}
			if (status === 'theme_css_verified') {
				return 'cleanup_preview';
			}
			if (status === 'cleanup_applying') {
				return 'cleanup_apply';
			}
			if (status === 'cleanup_previewing') {
				return 'cleanup_preview';
			}
			if (status === 'cleanup_preview') {
				return Number(statusData.cleanupCandidates || 0) > 0 && !statusData.cleanupBlockedItems ? 'cleanup_apply' : 'cleanup_preview';
			}

			if (Number(dbSummary.pendingRefs || 0) > 0) {
				return 'db_apply';
			}
			if (Number(dbSummary.replacedRefs || 0) > Number(dbSummary.verifiedRefs || 0)) {
				return 'db_verify';
			}
			if (Number(dbSummary.verifyFailedRefs || 0) > 0) {
				return 'db_verify';
			}
			if (Number(dbSummary.totalRefs || 0) > 0 && Number(dbSummary.verifiedRefs || 0) >= Number(dbSummary.totalRefs || 0)) {
				if (typeof statusData.themeCssRefs === 'undefined') {
					return 'theme_css_scan';
				}
				if (Number(statusData.themeCssPendingRefs || 0) > 0) {
					return status === 'theme_css_preview' ? 'theme_css_apply' : 'theme_css_preview';
				}
				if (Number(statusData.themeCssAppliedRefs || 0) > 0 || Number(statusData.themeCssVerifyFailedRefs || 0) > 0) {
					return 'theme_css_verify';
				}
				if (mediaLibraryReplacementCleanupPreview && mediaLibraryReplacementCleanupPreview.cleanupReady && Number(mediaLibraryReplacementCleanupPreview.cleanupCandidates || 0) > 0) {
					return 'cleanup_apply';
				}
				return 'cleanup_preview';
			}

			if (Number(statusData.remainingToCopy || 0) > 0 || Number(mappingSummary.matched || 0) > 0) {
				return 'copy';
			}
			if (Number(statusData.remainingMetadata || 0) > 0 || Number(statusData.copied || 0) > 0 || Number(mappingSummary.copied || 0) > 0) {
				return 'metadata_prepare';
			}
			if (Number(statusData.metadataPrepared || 0) > 0 || Number(mappingSummary.metadata_ready || 0) > 0) {
				return 'metadata_apply';
			}
			if (Number(statusData.metadataUpdated || 0) > 0 || Number(mappingSummary.metadata_updated || 0) > 0) {
				return 'refs_scan';
			}
			if (Number(statusData.referencesFound || 0) > 0 && Number(statusData.matchedRefs || 0) <= 0) {
				return 'refs_match';
			}
			if (Number(statusData.matchedRefs || 0) > 0) {
				return 'db_preview';
			}

			return 'preview';
		}

		function getMediaLibraryReplacementActionClass(actionKey, baseClass) {
			return String(baseClass || '');
		}

		function getMediaLibraryReplacementWorkflowStage() {
			const statusData = mediaLibraryReplacementStatus && typeof mediaLibraryReplacementStatus === 'object' ? mediaLibraryReplacementStatus : {};
			const persistedStage = statusData.workflowStage ? String(statusData.workflowStage) : '';
			if (['prepare', 'do', 'verify', 'delete', 'complete'].indexOf(persistedStage) !== -1) {
				if ('delete' === persistedStage && !statusData.workflowVerifyCompleted) {
					return 'verify';
				}
				return persistedStage;
			}
			const cleanupDeleted = Number(statusData.cleanupDeleted || 0);
			const cleanupCandidates = Number(statusData.cleanupCandidates || 0);
			const cleanupFailed = Number(statusData.cleanupFailed || 0);
			const cleanupBlocked = Number(statusData.cleanupBlockedItems || 0);

			if ('complete' === persistedStage && cleanupDeleted > 0 && cleanupCandidates <= 0 && cleanupFailed <= 0 && cleanupBlocked <= 0) {
				return 'complete';
			}

			const nextAction = getMediaLibraryReplacementNextActionKey();
			let derivedStage = 'prepare';
			if (['prepare', 'preview', 'copy', 'metadata_prepare', 'refs_scan', 'refs_match', 'db_preview', 'theme_css_scan', 'theme_css_preview'].indexOf(nextAction) !== -1) {
				derivedStage = 'prepare';
			} else if (['metadata_apply', 'db_apply', 'theme_css_apply'].indexOf(nextAction) !== -1) {
				derivedStage = 'do';
			} else if (['db_verify', 'theme_css_verify', 'cleanup_preview'].indexOf(nextAction) !== -1) {
				derivedStage = 'verify';
			} else if (nextAction === 'cleanup_apply') {
				derivedStage = 'delete';
			}

			if ('prepare' === derivedStage) {
				return 'prepare';
			}

			if (['do', 'verify', 'delete'].indexOf(persistedStage) !== -1 && persistedStage === derivedStage) {
				return persistedStage;
			}

			return derivedStage;
		}

		function getMediaLibraryReplacementStepInactiveReason(step, stage) {
			if (stage === 'complete') {
				return __('Media Library replacement is complete for this job.', 'ultracache');
			}
			if (step === 'prepare') {
				return stage === 'prepare' ? '' : __('Prepare is complete for the current job.', 'ultracache');
			}
			if (step === 'do') {
				if (stage === 'prepare') {
					return __('Prepare the replacement plan first.', 'ultracache');
				}
				return stage === 'do' ? '' : __('Do is complete for the current job.', 'ultracache');
			}
			if (step === 'verify') {
				if (stage === 'prepare') {
					return __('Prepare the replacement plan first.', 'ultracache');
				}
				if (stage === 'do') {
					return __('Run Do first.', 'ultracache');
				}
				return stage === 'verify' ? '' : __('Verify is complete for the current job.', 'ultracache');
			}
			if (step === 'delete') {
				if (stage === 'prepare') {
					return __('Prepare the replacement plan first.', 'ultracache');
				}
				if (stage === 'do') {
					return __('Run Do first.', 'ultracache');
				}
				if (stage === 'verify') {
					return __('Run Verify first.', 'ultracache');
				}
			}
			return '';
		}

		function isMediaLibraryReplacementReadinessRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.readinessRunnerReady === true);
		}

		function getMediaLibraryReplacementReadinessStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.readiness && typeof mediaLibraryReplacementStatus.readiness === 'object'
				? mediaLibraryReplacementStatus.readiness
				: {};
		}

		async function manageMediaLibraryReplacementSession(action, token = '', activeStep = 'readiness') {
			const response = await apiRequest('media_library_replacement_session', {
				action: String(action || ''),
				token: String(token || mediaLibraryReplacementSessionTokenRef.current || ''),
				activeStep: String(activeStep || 'readiness'),
			});
			if (response && response.token) {
				mediaLibraryReplacementSessionTokenRef.current = persistMediaLibraryReplacementSessionToken(response.token);
			}
			if (action === 'pause' || action === 'end') {
				mediaLibraryReplacementSessionTokenRef.current = persistMediaLibraryReplacementSessionToken('');
			}
			return response || {};
		}

		async function runMediaLibraryReplacementReadiness(forceRestart = false, continueToPrepare = false) {
			if (!isMediaLibraryReplacementReadinessRunnerReady() || busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			let latestReadiness = getMediaLibraryReplacementReadinessStatus();
			let resetPending = !!forceRestart || !latestReadiness.generation || latestReadiness.status === 'idle' || latestReadiness.status === 'failed' || latestReadiness.inventoryComplete;
			const job = {
				type: 'media_replacement_readiness',
				label: __('Checking Replacement Readiness', 'ultracache'),
				processed: resetPending ? 0 : Math.max(0, Number(latestReadiness.scannedAttachments || 0)),
				total: Math.max(0, Number(latestReadiness.candidateAttachments || 0)),
				hasMore: true,
				cursor: String(latestReadiness.generation || ''),
				batchSize: 1,
				logs: [resetPending ? __('Starting a fresh replacement readiness inventory.', 'ultracache') : __('Resuming the server-side replacement readiness inventory.', 'ultracache')],
				showWhenInactive: true,
			};

			const runner = createJobRunner({
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => { cancelRequestedRef.current = false; },
				setBusy: (value) => {
					setBusy(!!value);
					setMediaLibraryReplacementBusy(!!value);
				},
				updateProcessState,
				persistJobState: () => {},
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media_replacement_readiness',
				beginExclusiveSession: async (state, preferredToken) => {
					const response = await manageMediaLibraryReplacementSession('begin', preferredToken, 'readiness');
					if (!response.success || !response.token) {
						throw new Error(response.message || __('Could not acquire Media Library replacement ownership.', 'ultracache'));
					}
					return String(response.token);
				},
				endExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('end', token, 'readiness');
						return true;
					} catch (error) {
						return false;
					}
				},
				pauseExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'readiness');
						return true;
					} catch (error) {
						return false;
					}
				},
				failExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'readiness');
						return true;
					} catch (error) {
						return false;
					}
				},
				shouldReleaseExclusiveSessionOnExit: () => true,
				fetchBatch: async (type, cursor) => {
					const readiness = await apiRequest('media_library_replacement_readiness_status', { cacheBust: getMediaConversionTestCacheBust() });
					latestReadiness = readiness || {};
					await refreshMediaLibraryReplacementWorkflowStatus();
					const complete = !resetPending && !!latestReadiness.inventoryComplete && !latestReadiness.hasMore;
					return {
						items: complete ? [] : [{ reset: resetPending }],
						total: Math.max(0, Number(latestReadiness.candidateAttachments || 0)),
						processed: Math.max(0, Number(latestReadiness.scannedAttachments || 0)),
						hasMore: !complete,
						nextCursor: String(latestReadiness.generation || cursor || ''),
					};
				},
				getBatchStatePatch: (state, batch) => ({
					total: Math.max(0, Number(batch.total || 0)),
					processed: Math.max(0, Number(batch.processed || state.processed || 0)),
				}),
				processItem: async (type, item, isCancelled, token) => {
					const renewal = await manageMediaLibraryReplacementSession('renew', token, 'readiness');
					if (!renewal.success) {
						throw new Error(renewal.message || __('The Media Library replacement lease was lost.', 'ultracache'));
					}
					const response = await apiRequest('media_library_replacement_readiness_scan', {
						reset: !!(item && item.reset),
						limit: 50,
						time_budget: 15,
					});
					resetPending = false;
					latestReadiness = response || {};
					await refreshMediaLibraryReplacementWorkflowStatus();
					return {
						line: response.message || __('Replacement readiness chunk complete.', 'ultracache'),
						progressIncrement: Math.max(0, Number(response.batchScanned || 0)),
						successIncrement: 0,
						skippedIncrement: 0,
						failedIncrement: 0,
					};
				},
				buildCompletionNotice: () => latestReadiness.readyForReplacement
					? { type: 'success', text: continueToPrepare
						? __('File readiness complete. Continuing automatically to Prepare.', 'ultracache')
						: __('Replacement readiness complete. Every required target-format file is ready.', 'ultracache') }
					: { type: 'warning', text: latestReadiness.message || __('Replacement readiness completed with blockers.', 'ultracache') },
				onCompleted: async () => { await refreshMediaLibraryReplacementWorkflowStatus(); },
				markProcessComplete: () => {
					setProcess((current) => Object.assign({}, current, { active: false, complete: true, cancellable: false, cancelRequested: false, showWhenInactive: true }));
				},
				getFailureText: () => __('Replacement readiness failed.', 'ultracache'),
				onReleaseFailure: () => pushToast({ type: 'warning', text: __('Replacement readiness finished, but its dashboard lease could not be released immediately.', 'ultracache') }),
				onPaused: async () => {
					await refreshMediaLibraryReplacementWorkflowStatus();
					setProcess((current) => Object.assign({}, current, { active: false, complete: false, cancellable: false, showWhenInactive: true }));
					pushToast({ type: 'success', text: __('Replacement readiness paused. Resume continues from the saved server cursor.', 'ultracache') });
				},
			});
			await runner(job, false, mediaLibraryReplacementSessionTokenRef.current || '');
			return latestReadiness;
		}

		function isMediaLibraryReplacementPrepareRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.prepareRunnerReady === true);
		}

		function getMediaLibraryReplacementPrepareStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.prepare && typeof mediaLibraryReplacementStatus.prepare === 'object'
				? mediaLibraryReplacementStatus.prepare
				: {};
		}

		async function runMediaLibraryReplacementPrepare(forceRestart = false, readinessOverride = null, readinessJustCompleted = false) {
			if (!isMediaLibraryReplacementPrepareRunnerReady() || busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			const readiness = readinessOverride && typeof readinessOverride === 'object'
				? readinessOverride
				: getMediaLibraryReplacementReadinessStatus();
			if (!readiness.inventoryComplete || !readiness.readyForReplacement) {
				pushToast({ type: 'error', text: readiness.message || __('Prepare is blocked because one or more required replacement files are not ready.', 'ultracache') });
				return;
			}

			let latestPrepare = getMediaLibraryReplacementPrepareStatus();
			let resetPending = !!forceRestart || !latestPrepare.jobId || latestPrepare.prepareFailed;
			const job = {
				type: 'media_replacement_prepare',
				label: __('Preparing Media Library Replacement', 'ultracache'),
				processed: resetPending ? 0 : Math.max(0, Number(latestPrepare.processed || 0)),
				total: resetPending ? 0 : Math.max(0, Number(latestPrepare.total || 0)),
				hasMore: true,
				cursor: String(latestPrepare.generation || ''),
				batchSize: 1,
				logs: readinessJustCompleted
					? [
						__('File readiness complete. All required replacement files are valid and current.', 'ultracache'),
						resetPending ? __('Continuing automatically with a fresh server-backed Prepare job.', 'ultracache') : __('Continuing automatically from the saved Prepare phase and cursor.', 'ultracache'),
					]
					: [resetPending ? __('Starting a fresh server-backed Prepare job.', 'ultracache') : __('Resuming Prepare from its saved server phase and cursor.', 'ultracache')],
				showWhenInactive: true,
			};

			const runner = createJobRunner({
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => { cancelRequestedRef.current = false; },
				setBusy: (value) => {
					setBusy(!!value);
					setMediaLibraryReplacementBusy(!!value);
				},
				updateProcessState,
				persistJobState: () => {},
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media_replacement_prepare',
				beginExclusiveSession: async (state, preferredToken) => {
					const response = await manageMediaLibraryReplacementSession('begin', preferredToken, 'prepare');
					if (!response.success || !response.token) {
						throw new Error(response.message || __('Could not acquire Media Library replacement Prepare ownership.', 'ultracache'));
					}
					return String(response.token);
				},
				endExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('end', token, 'prepare');
						return true;
					} catch (error) {
						return false;
					}
				},
				pauseExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'prepare');
						return true;
					} catch (error) {
						return false;
					}
				},
				failExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'prepare');
						return true;
					} catch (error) {
						return false;
					}
				},
				shouldReleaseExclusiveSessionOnExit: () => true,
				fetchBatch: async (type, cursor) => {
					const workflow = await refreshMediaLibraryReplacementWorkflowStatus();
					latestPrepare = workflow && workflow.prepare && typeof workflow.prepare === 'object' ? workflow.prepare : {};
					const complete = !resetPending && !!latestPrepare.prepareComplete && !latestPrepare.hasMore;
					return {
						items: complete ? [] : [{ reset: resetPending }],
						total: Math.max(0, Number(latestPrepare.total || 0)),
						processed: Math.max(0, Number(latestPrepare.processed || 0)),
						hasMore: !complete,
						nextCursor: String(latestPrepare.generation || cursor || ''),
					};
				},
				getBatchStatePatch: (state, batch) => ({
					total: Math.max(0, Number(batch.total || 0)),
					processed: Math.max(0, Number(batch.processed || state.processed || 0)),
				}),
				processItem: async (type, item, isCancelled, token) => {
					const renewal = await manageMediaLibraryReplacementSession('renew', token, 'prepare');
					if (!renewal.success) {
						throw new Error(renewal.message || __('The Media Library replacement Prepare lease was lost.', 'ultracache'));
					}
					const response = await apiRequest('media_library_replacement_prepare', {
						reset: !!(item && item.reset),
						readinessGeneration: String(readiness.generation || ''),
						sessionToken: String(token || ''),
						limit: 50,
						time_budget: 15,
					});
					resetPending = false;
					const workflow = await refreshMediaLibraryReplacementWorkflowStatus(response && response.jobId ? response.jobId : '');
					latestPrepare = workflow && workflow.prepare && typeof workflow.prepare === 'object' ? workflow.prepare : (response || {});
					return {
						line: response.message || __('Prepare chunk complete.', 'ultracache'),
						progressIncrement: Math.max(0, Number(response.batchProcessed || 0)),
						successIncrement: 0,
						skippedIncrement: 0,
						failedIncrement: 0,
					};
				},
				buildCompletionNotice: () => latestPrepare.prepareComplete
					? { type: 'success', text: __('Prepare complete. The hard pre-Do guard passed and all files/plans are ready.', 'ultracache') }
					: { type: 'warning', text: latestPrepare.message || __('Prepare stopped before completion.', 'ultracache') },
				onCompleted: async () => { await refreshMediaLibraryReplacementWorkflowStatus(); },
				markProcessComplete: () => {
					setProcess((current) => Object.assign({}, current, { active: false, complete: true, cancellable: false, cancelRequested: false, showWhenInactive: true }));
				},
				getFailureText: () => __('Media Library replacement Prepare failed.', 'ultracache'),
				onReleaseFailure: () => pushToast({ type: 'warning', text: __('Prepare finished, but its dashboard lease could not be released immediately.', 'ultracache') }),
				onPaused: async () => {
					await refreshMediaLibraryReplacementWorkflowStatus();
					setProcess((current) => Object.assign({}, current, { active: false, complete: false, cancellable: false, showWhenInactive: true }));
					pushToast({ type: 'success', text: __('Prepare paused. Resume continues from the saved server phase and cursor.', 'ultracache') });
				},
			});
			return runner(job, false, mediaLibraryReplacementSessionTokenRef.current || '');
		}

		function getMediaLibraryReplacementPrepareLabel() {
			if (mediaLibraryReplacementBusy && process && ['media_replacement_readiness', 'media_replacement_prepare'].includes(String(process.type || ''))) {
				return __('Preparing…', 'ultracache');
			}

			const prepare = getMediaLibraryReplacementPrepareStatus();
			if (prepare.prepareComplete) {
				return __('Prepare Complete', 'ultracache');
			}
			if (prepare.prepareFailed) {
				return __('Prepare Failed', 'ultracache');
			}

			const readiness = getMediaLibraryReplacementReadinessStatus();
			if (
				readiness.status === 'scanning'
				|| readiness.status === 'paused'
				|| prepare.jobId
				|| ['registry_scan', 'copy', 'validate', 'metadata_plan', 'database_scan', 'database_match', 'database_preview', 'theme_css_scan', 'theme_css_preview', 'pre_do_validate', 'prepare_complete'].includes(String(prepare.activeStep || ''))
			) {
				return __('Resume Prepare', 'ultracache');
			}
			if (readiness.inventoryComplete && !readiness.readyForReplacement) {
				return __('Retry Prepare', 'ultracache');
			}
			return __('Prepare Library Replacement', 'ultracache');
		}

		function isMediaLibraryReplacementDoRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.doRunnerReady === true);
		}

		function getMediaLibraryReplacementDoStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.do && typeof mediaLibraryReplacementStatus.do === 'object'
				? mediaLibraryReplacementStatus.do
				: {};
		}

		async function runMediaLibraryReplacementDo() {
			if (!isMediaLibraryReplacementDoRunnerReady() || busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			let latestDo = getMediaLibraryReplacementDoStatus();
			if (latestDo.doFailed) {
				pushToast({ type: 'error', text: latestDo.message || __('Do failed after destructive work started. Resume the current recovery path or use explicit rollback/uninstall.', 'ultracache') });
				return;
			}
			if (latestDo.doComplete) {
				pushToast({ type: 'success', text: __('Do is already complete. Run Verify next.', 'ultracache') });
				return;
			}
			if (!latestDo.doReady) {
				pushToast({ type: 'error', text: latestDo.message || __('Do is blocked until Prepare and the hard pre-Do guard are complete.', 'ultracache') });
				return;
			}

			const job = {
				type: 'media_replacement_do',
				label: __('Applying Media Library Replacement', 'ultracache'),
				processed: Math.max(0, Number(latestDo.processed || 0)),
				total: Math.max(0, Number(latestDo.total || 0)),
				hasMore: true,
				cursor: String(latestDo.generation || ''),
				batchSize: 1,
				logs: [latestDo.activeStep && latestDo.activeStep !== 'prepare_complete'
					? __('Resuming Do from its saved server phase.', 'ultracache')
					: __('Starting the server-backed Do job.', 'ultracache')],
				showWhenInactive: true,
			};

			const runner = createJobRunner({
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => { cancelRequestedRef.current = false; },
				setBusy: (value) => {
					setBusy(!!value);
					setMediaLibraryReplacementBusy(!!value);
				},
				updateProcessState,
				persistJobState: () => {},
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media_replacement_do',
				beginExclusiveSession: async (state, preferredToken) => {
					const response = await manageMediaLibraryReplacementSession('begin', preferredToken, 'do');
					if (!response.success || !response.token) {
						throw new Error(response.message || __('Could not acquire Media Library replacement Do ownership.', 'ultracache'));
					}
					return String(response.token);
				},
				endExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('end', token, 'do');
						return true;
					} catch (error) {
						return false;
					}
				},
				pauseExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'do');
						return true;
					} catch (error) {
						return false;
					}
				},
				failExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'do');
						await refreshMediaLibraryReplacementWorkflowStatus();
						return true;
					} catch (error) {
						return false;
					}
				},
				shouldReleaseExclusiveSessionOnExit: () => true,
				fetchBatch: async (type, cursor) => {
					const workflow = await refreshMediaLibraryReplacementWorkflowStatus();
					latestDo = workflow && workflow.do && typeof workflow.do === 'object' ? workflow.do : {};
					if (latestDo.doFailed) {
						throw new Error(latestDo.message || __('Media Library replacement Do failed.', 'ultracache'));
					}
					const complete = !!latestDo.doComplete && !latestDo.hasMore;
					return {
						items: complete ? [] : [{}],
						total: Math.max(0, Number(latestDo.total || 0)),
						processed: Math.max(0, Number(latestDo.processed || 0)),
						hasMore: !complete,
						nextCursor: String(latestDo.generation || cursor || ''),
					};
				},
				getBatchStatePatch: (state, batch) => ({
					total: Math.max(0, Number(batch.total || 0)),
					processed: Math.max(0, Number(batch.processed || state.processed || 0)),
				}),
				processItem: async (type, item, isCancelled, token) => {
					const renewal = await manageMediaLibraryReplacementSession('renew', token, 'do');
					if (!renewal.success) {
						throw new Error(renewal.message || __('The Media Library replacement Do lease was lost.', 'ultracache'));
					}
					const response = await apiRequest('media_library_replacement_do', {
						sessionToken: String(token || ''),
						limit: 50,
						time_budget: 15,
					});
					latestDo = response && typeof response === 'object' ? response : {};
					return {
						line: response.message || __('Do chunk complete.', 'ultracache'),
						progressIncrement: Math.max(0, Number(response.batchProcessed || 0)),
						successIncrement: 0,
						skippedIncrement: 0,
						failedIncrement: 0,
					};
				},
				buildCompletionNotice: () => latestDo.doComplete
					? { type: 'success', text: __('Do complete. Run Verify before deleting original JPG/PNG files.', 'ultracache') }
					: { type: 'warning', text: latestDo.message || __('Do stopped before completion.', 'ultracache') },
				onCompleted: async () => { await refreshMediaLibraryReplacementWorkflowStatus(); },
				markProcessComplete: () => {
					setProcess((current) => Object.assign({}, current, { active: false, complete: true, cancellable: false, cancelRequested: false, showWhenInactive: true }));
				},
				getFailureText: () => __('Media Library replacement Do failed.', 'ultracache'),
				onReleaseFailure: () => pushToast({ type: 'warning', text: __('Do finished, but its dashboard lease could not be released immediately.', 'ultracache') }),
				onPaused: async () => {
					await refreshMediaLibraryReplacementWorkflowStatus();
					setProcess((current) => Object.assign({}, current, { active: false, complete: false, cancellable: false, showWhenInactive: true }));
					pushToast({ type: 'success', text: __('Do paused. Resume continues from the saved metadata, database, or Theme CSS phase.', 'ultracache') });
				},
			});
			return runner(job, false, mediaLibraryReplacementSessionTokenRef.current || '');
		}

		function getMediaLibraryReplacementDoLabel() {
			if (mediaLibraryReplacementBusy && process && String(process.type || '') === 'media_replacement_do') {
				return __('Applying…', 'ultracache');
			}
			const doStatus = getMediaLibraryReplacementDoStatus();
			if (doStatus.doComplete) {
				return __('Do Complete', 'ultracache');
			}
			if (doStatus.doFailed) {
				return __('Do Failed', 'ultracache');
			}
			if (['metadata_apply', 'database_apply', 'theme_css_apply'].includes(String(doStatus.activeStep || '')) || doStatus.runStatus === 'paused') {
				return __('Resume Do', 'ultracache');
			}
			return __('Run Do', 'ultracache');
		}


		function isMediaLibraryReplacementVerifyRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.verifyRunnerReady === true);
		}

		function getMediaLibraryReplacementVerifyStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.verify && typeof mediaLibraryReplacementStatus.verify === 'object'
				? mediaLibraryReplacementStatus.verify
				: {};
		}

		async function runMediaLibraryReplacementVerify() {
			if (!isMediaLibraryReplacementVerifyRunnerReady() || busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			let latestVerify = getMediaLibraryReplacementVerifyStatus();
			if (latestVerify.verifyComplete) {
				pushToast({ type: 'success', text: __('Verify is already complete.', 'ultracache') });
				return;
			}
			if (!latestVerify.verifyReady) {
				pushToast({ type: 'error', text: latestVerify.message || __('Verify is blocked until Do is complete.', 'ultracache') });
				return;
			}

			const retrying = !!latestVerify.verifyFailed;
			const job = {
				type: 'media_replacement_verify',
				label: __('Verifying Media Library Replacement', 'ultracache'),
				processed: retrying ? 0 : Math.max(0, Number(latestVerify.processed || 0)),
				total: Math.max(0, Number(latestVerify.total || 0)),
				hasMore: true,
				cursor: String(latestVerify.generation || ''),
				batchSize: 1,
				logs: [retrying
					? __('Retrying Verify from a fresh destination and metadata validation pass.', 'ultracache')
					: (latestVerify.activeStep && latestVerify.activeStep !== 'do_complete'
						? __('Resuming Verify from its saved server phase.', 'ultracache')
						: __('Starting the server-backed Verify job.', 'ultracache'))],
				showWhenInactive: true,
			};

			const runner = createJobRunner({
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => { cancelRequestedRef.current = false; },
				setBusy: (value) => {
					setBusy(!!value);
					setMediaLibraryReplacementBusy(!!value);
				},
				updateProcessState,
				persistJobState: () => {},
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media_replacement_verify',
				beginExclusiveSession: async (state, preferredToken) => {
					const response = await manageMediaLibraryReplacementSession('begin', preferredToken, 'verify');
					if (!response.success || !response.token) {
						throw new Error(response.message || __('Could not acquire Media Library replacement Verify ownership.', 'ultracache'));
					}
					return String(response.token);
				},
				endExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('end', token, 'verify');
						return true;
					} catch (error) {
						return false;
					}
				},
				pauseExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'verify');
						return true;
					} catch (error) {
						return false;
					}
				},
				failExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'verify');
						await refreshMediaLibraryReplacementWorkflowStatus();
						return true;
					} catch (error) {
						return false;
					}
				},
				shouldReleaseExclusiveSessionOnExit: () => true,
				fetchBatch: async (type, cursor) => {
					const workflow = await refreshMediaLibraryReplacementWorkflowStatus();
					latestVerify = workflow && workflow.verify && typeof workflow.verify === 'object' ? workflow.verify : {};
					const complete = !!latestVerify.verifyComplete && !latestVerify.hasMore;
					return {
						items: complete ? [] : [{}],
						total: Math.max(0, Number(latestVerify.total || 0)),
						processed: Math.max(0, Number(latestVerify.processed || 0)),
						hasMore: !complete,
						nextCursor: String(latestVerify.generation || cursor || ''),
					};
				},
				getBatchStatePatch: (state, batch) => ({
					total: Math.max(0, Number(batch.total || 0)),
					processed: Math.max(0, Number(batch.processed || state.processed || 0)),
				}),
				processItem: async (type, item, isCancelled, token) => {
					const renewal = await manageMediaLibraryReplacementSession('renew', token, 'verify');
					if (!renewal.success) {
						throw new Error(renewal.message || __('The Media Library replacement Verify lease was lost.', 'ultracache'));
					}
					let response = null;
					try {
						response = await apiRequest('media_library_replacement_verify', {
							sessionToken: String(token || ''),
							limit: 50,
							time_budget: 15,
						});
					} catch (error) {
						const errorData = error && error.data && typeof error.data === 'object' ? error.data : {};
						const blockers = Array.isArray(errorData.blockers)
							? errorData.blockers
							: (Array.isArray(errorData.cleanupBlockers) ? errorData.cleanupBlockers : []);
						if (blockers.length > 0) {
							const blockerText = blockers.map((blocker) => {
								const message = blocker && blocker.message ? String(blocker.message) : __('Verify cleanup blocker.', 'ultracache');
								const count = Math.max(0, Number(blocker && blocker.count ? blocker.count : 0));
								return count > 0 ? (message + ' (' + count + ')') : message;
							}).join(' · ');
							error.message = String(error.message || __('Media Library replacement Verify failed.', 'ultracache')) + ' ' + blockerText;
						}
						throw error;
					}
					latestVerify = response && typeof response === 'object' ? response : {};
					if (response && response.cleanupPreview) {
						setMediaLibraryReplacementCleanupPreview(response.cleanupPreview);
					}
					return {
						line: response.message || __('Verify chunk complete.', 'ultracache'),
						progressIncrement: Math.max(0, Number(response.batchProcessed || 0)),
						successIncrement: 0,
						skippedIncrement: 0,
						failedIncrement: 0,
					};
				},
				buildCompletionNotice: () => latestVerify.verifyComplete
					? { type: 'success', text: __('Verify complete. Delete Originals is unlocked only for the verified cleanup candidates.', 'ultracache') }
					: { type: 'warning', text: latestVerify.message || __('Verify stopped before completion.', 'ultracache') },
				onCompleted: async () => { await refreshMediaLibraryReplacementWorkflowStatus(); },
				markProcessComplete: () => {
					setProcess((current) => Object.assign({}, current, { active: false, complete: true, cancellable: false, cancelRequested: false, showWhenInactive: true }));
				},
				getFailureText: () => __('Media Library replacement Verify failed.', 'ultracache'),
				onReleaseFailure: () => pushToast({ type: 'warning', text: __('Verify finished, but its dashboard lease could not be released immediately.', 'ultracache') }),
				onPaused: async () => {
					await refreshMediaLibraryReplacementWorkflowStatus();
					setProcess((current) => Object.assign({}, current, { active: false, complete: false, cancellable: false, showWhenInactive: true }));
					pushToast({ type: 'success', text: __('Verify paused. Resume continues from the saved file, metadata, database, Theme CSS, or cleanup phase.', 'ultracache') });
				},
			});
			return runner(job, false, mediaLibraryReplacementSessionTokenRef.current || '');
		}

		function getMediaLibraryReplacementVerifyLabel() {
			if (mediaLibraryReplacementBusy && process && String(process.type || '') === 'media_replacement_verify') {
				return __('Verifying…', 'ultracache');
			}
			const verifyStatus = getMediaLibraryReplacementVerifyStatus();
			if (verifyStatus.verifyComplete) {
				return __('Verify Complete', 'ultracache');
			}
			if (verifyStatus.verifyFailed) {
				return __('Retry Verify', 'ultracache');
			}
			if (['destination_verify', 'metadata_verify', 'database_verify', 'theme_css_verify', 'cleanup_preview'].includes(String(verifyStatus.activeStep || '')) || verifyStatus.runStatus === 'paused') {
				return __('Resume Verify', 'ultracache');
			}
			return __('Run Verify', 'ultracache');
		}


		function isMediaLibraryReplacementDeleteRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.deleteRunnerReady === true);
		}

		function getMediaLibraryReplacementDeleteStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.delete && typeof mediaLibraryReplacementStatus.delete === 'object'
				? mediaLibraryReplacementStatus.delete
				: {};
		}

		async function runMediaLibraryReplacementDelete() {
			if (!isMediaLibraryReplacementDeleteRunnerReady() || busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			let latestDelete = getMediaLibraryReplacementDeleteStatus();
			if (latestDelete.deleteComplete) {
				pushToast({ type: 'success', text: __('Delete Originals is already complete.', 'ultracache') });
				return;
			}
			if (!latestDelete.deleteReady) {
				pushToast({ type: 'error', text: latestDelete.message || __('Delete Originals is blocked until Verify completes cleanly.', 'ultracache') });
				return;
			}

			const startingFresh = String(latestDelete.activeStep || '') === 'verify_complete';
			if (startingFresh && typeof window !== 'undefined' && typeof window.confirm === 'function') {
				const confirmed = window.confirm(__('Delete all verified original JPG/PNG files for this replacement job? The copied AVIF/WebP files, switched attachment metadata, verified database references, and verified Theme CSS will remain. Keep an external backup until testing is complete.', 'ultracache'));
				if (!confirmed) {
					return;
				}
				try {
					const confirmation = await apiRequest('media_library_replacement_delete_confirm', {
						generation: String(latestDelete.generation || ''),
					});
					latestDelete = confirmation && typeof confirmation === 'object' ? confirmation : latestDelete;
					if (!confirmation || !confirmation.success) {
						throw new Error(confirmation && confirmation.message ? confirmation.message : __('The server did not confirm the current verified cleanup generation.', 'ultracache'));
					}
				} catch (error) {
					pushToast({ type: 'error', text: error && error.message ? error.message : __('Could not create a fresh Delete Originals confirmation.', 'ultracache') });
					return;
				}
			}

			const retrying = !!latestDelete.deleteFailed;
			const job = {
				type: 'media_replacement_delete',
				label: __('Deleting Verified Original Images', 'ultracache'),
				processed: Math.max(0, Number(latestDelete.processed || 0)),
				total: Math.max(0, Number(latestDelete.total || 0)),
				hasMore: true,
				cursor: String(latestDelete.generation || ''),
				batchSize: 1,
				logs: [retrying
					? __('Retrying failed cleanup rows after revalidating each original and replacement file.', 'ultracache')
					: (latestDelete.activeStep === 'delete_originals'
						? __('Resuming Delete Originals from the remaining server rows.', 'ultracache')
						: __('Starting the server-backed Delete Originals job.', 'ultracache'))],
				showWhenInactive: true,
			};

			const runner = createJobRunner({
				isCancelled: () => !!cancelRequestedRef.current,
				resetCancel: () => { cancelRequestedRef.current = false; },
				setBusy: (value) => {
					setBusy(!!value);
					setMediaLibraryReplacementBusy(!!value);
				},
				updateProcessState,
				persistJobState: () => {},
				pushToast,
				shouldAcquireExclusiveSession: (type) => type === 'media_replacement_delete',
				beginExclusiveSession: async (state, preferredToken) => {
					const response = await manageMediaLibraryReplacementSession('begin', preferredToken, 'delete');
					if (!response.success || !response.token) {
						throw new Error(response.message || __('Could not acquire Delete Originals ownership.', 'ultracache'));
					}
					return String(response.token);
				},
				endExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('end', token, 'delete');
						return true;
					} catch (error) {
						return false;
					}
				},
				pauseExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'delete');
						return true;
					} catch (error) {
						return false;
					}
				},
				failExclusiveSession: async (state, token) => {
					try {
						await manageMediaLibraryReplacementSession('pause', token, 'delete');
						await refreshMediaLibraryReplacementWorkflowStatus();
						return true;
					} catch (error) {
						return false;
					}
				},
				shouldReleaseExclusiveSessionOnExit: () => true,
				fetchBatch: async (type, cursor) => {
					const workflow = await refreshMediaLibraryReplacementWorkflowStatus();
					latestDelete = workflow && workflow.delete && typeof workflow.delete === 'object' ? workflow.delete : {};
					if (latestDelete.deleteFailed && !latestDelete.deleteReady) {
						throw new Error(latestDelete.message || __('Delete Originals failed.', 'ultracache'));
					}
					const complete = !!latestDelete.deleteComplete && !latestDelete.hasMore;
					return {
						items: complete ? [] : [{}],
						total: Math.max(0, Number(latestDelete.total || 0)),
						processed: Math.max(0, Number(latestDelete.processed || 0)),
						hasMore: !complete,
						nextCursor: String(latestDelete.generation || cursor || ''),
					};
				},
				getBatchStatePatch: (state, batch) => ({
					total: Math.max(0, Number(batch.total || 0)),
					processed: Math.max(0, Number(batch.processed || state.processed || 0)),
				}),
				processItem: async (type, item, isCancelled, token) => {
					const renewal = await manageMediaLibraryReplacementSession('renew', token, 'delete');
					if (!renewal.success) {
						throw new Error(renewal.message || __('The Delete Originals lease was lost.', 'ultracache'));
					}
					const response = await apiRequest('media_library_replacement_delete', {
						sessionToken: String(token || ''),
						generation: String(latestDelete.generation || ''),
						limit: 50,
						time_budget: 15,
					});
					latestDelete = response && typeof response === 'object' ? response : {};
					return {
						line: response.message || __('Delete Originals chunk complete.', 'ultracache'),
						progressIncrement: Math.max(0, Number(response.batchProcessed || 0)),
						successIncrement: Math.max(0, Number(response.batchDeleted || 0)),
						skippedIncrement: Math.max(0, Number(response.batchAlreadyMissing || 0)),
						failedIncrement: 0,
					};
				},
				buildCompletionNotice: () => latestDelete.deleteComplete
					? { type: 'success', text: __('Delete Originals complete. Review the site and retain your external backup until testing is finished.', 'ultracache') }
					: { type: 'warning', text: latestDelete.message || __('Delete Originals stopped before completion.', 'ultracache') },
				onCompleted: async () => { await refreshMediaLibraryReplacementWorkflowStatus(); },
				markProcessComplete: () => {
					setProcess((current) => Object.assign({}, current, { active: false, complete: true, cancellable: false, cancelRequested: false, showWhenInactive: true }));
				},
				getFailureText: () => __('Delete Originals failed.', 'ultracache'),
				onReleaseFailure: () => pushToast({ type: 'warning', text: __('Delete Originals finished, but its dashboard lease could not be released immediately.', 'ultracache') }),
				onPaused: async () => {
					await refreshMediaLibraryReplacementWorkflowStatus();
					setProcess((current) => Object.assign({}, current, { active: false, complete: false, cancellable: false, showWhenInactive: true }));
					pushToast({ type: 'success', text: __('Delete Originals paused. Resume continues from the remaining verified server rows.', 'ultracache') });
				},
			});
			return runner(job, false, mediaLibraryReplacementSessionTokenRef.current || '');
		}

		function getMediaLibraryReplacementDeleteLabel() {
			if (mediaLibraryReplacementBusy && process && String(process.type || '') === 'media_replacement_delete') {
				return __('Deleting…', 'ultracache');
			}
			const deleteStatus = getMediaLibraryReplacementDeleteStatus();
			if (deleteStatus.deleteComplete) {
				return __('Delete Complete', 'ultracache');
			}
			if (deleteStatus.deleteFailed) {
				return __('Retry Delete Originals', 'ultracache');
			}
			if (deleteStatus.activeStep === 'delete_originals' || deleteStatus.runStatus === 'paused') {
				return __('Resume Delete Originals', 'ultracache');
			}
			return __('Delete Originals', 'ultracache');
		}

		function isMediaLibraryReplacementRunnerReady() {
			return !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.runnerReady === true);
		}

		function getMediaLibraryReplacementRecoveryStatus() {
			return mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.recovery && typeof mediaLibraryReplacementStatus.recovery === 'object'
				? mediaLibraryReplacementStatus.recovery
				: {};
		}

		function isMediaLibraryReplacementOwnedByAnotherDashboard() {
			const recovery = getMediaLibraryReplacementRecoveryStatus();
			return !!recovery.activeElsewhere && !mediaLibraryReplacementSessionTokenRef.current;
		}

		function closeMediaLibraryReplacementWarning() {
			setMediaLibraryReplacementWarningAction('');
			setMediaLibraryReplacementWarningConfirmation('');
		}

		function openMediaLibraryReplacementWarning(action) {
			setMediaLibraryReplacementWarningConfirmation('');
			setMediaLibraryReplacementWarningAction(String(action || 'prepare'));
		}

		function shouldConfirmMediaLibraryReplacementPrepare() {
			const prepare = getMediaLibraryReplacementPrepareStatus();
			return !prepare.jobId || !!prepare.prepareFailed;
		}

		async function confirmMediaLibraryReplacementWarning() {
			if (String(mediaLibraryReplacementWarningConfirmation || '').trim() !== 'ok') {
				return;
			}

			const action = String(mediaLibraryReplacementWarningAction || '');
			closeMediaLibraryReplacementWarning();
			if (action === 'restart') {
				return restartMediaLibraryReplacementWorkflow(true);
			}
			return prepareMediaLibraryReplacementWorkflow(false, true);
		}

		async function restartMediaLibraryReplacementWorkflow(confirmationGranted = false) {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			const recovery = getMediaLibraryReplacementRecoveryStatus();
			if (!recovery.canRestart) {
				pushToast({ type: 'error', text: recovery.restartBlockedReason || __('The current replacement plan cannot be restarted.', 'ultracache') });
				return;
			}
			if (!confirmationGranted) {
				openMediaLibraryReplacementWarning('restart');
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				const response = await apiRequest('media_library_replacement_restart', {});
				mediaLibraryReplacementSessionTokenRef.current = persistMediaLibraryReplacementSessionToken('');
				setMediaLibraryReplacementPreview(null);
				setMediaLibraryReplacementDbPreview(null);
				setMediaLibraryReplacementCleanupPreview(null);
				setMediaLibraryReplacementStatus(response || null);
				pushToast({ type: 'success', text: response && response.message ? response.message : __('The replacement plan was cleared.', 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('The replacement plan could not be restarted.', 'ultracache') });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		function getMediaLibraryReplacementRunnerUnavailableMessage() {
			const guard = mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.startGuard
				? mediaLibraryReplacementStatus.startGuard
				: null;
			if (guard && guard.allowed !== true) {
				return guard.message || __('Media Library replacement cannot start until every required target-format file is ready.', 'ultracache');
			}
			if (guard && guard.allowed === true) {
				return __('Readiness, Prepare, Do, Verify, and Delete Originals use the shared resumable runner.', 'ultracache');
			}
			return __('The replacement workflow is locked. Complete the server-side readiness inventory before the shared resumable runner is connected.', 'ultracache');
		}

		function showMediaLibraryReplacementRunnerUnavailable() {
			pushToast({ type: 'error', text: getMediaLibraryReplacementRunnerUnavailableMessage() });
		}

		function getMediaLibraryReplacementWorkflowButtonState(step, deleteReason = '') {
			const stage = getMediaLibraryReplacementWorkflowStage();
			const prepareStatus = getMediaLibraryReplacementPrepareStatus();
			const isCurrent = stage === step;
			let reason = '';
			if (isMediaLibraryReplacementOwnedByAnotherDashboard()) {
				const recovery = getMediaLibraryReplacementRecoveryStatus();
				reason = recovery.leaseExpiresAt
					? __('This replacement job is running in another dashboard. It can be resumed here after that lease is paused or expires.', 'ultracache')
					: __('This replacement job is running in another dashboard.', 'ultracache');
			} else if (step === 'prepare' && isMediaLibraryReplacementReadinessRunnerReady() && isMediaLibraryReplacementPrepareRunnerReady()) {
				if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
					reason = __('Media Library replacement is busy.', 'ultracache');
				} else if (prepareStatus.prepareFailed) {
					reason = prepareStatus.message || __('Prepare failed. Use Restart Replacement Plan to clear the non-destructive plan explicitly.', 'ultracache');
				} else if (prepareStatus.prepareComplete) {
					reason = __('Prepare is complete for the current job.', 'ultracache');
				} else if (!isCurrent) {
					reason = getMediaLibraryReplacementStepInactiveReason(step, stage);
				}
			} else if (step === 'do' && isMediaLibraryReplacementDoRunnerReady()) {
				const doStatus = getMediaLibraryReplacementDoStatus();
				if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
					reason = __('Media Library replacement is busy.', 'ultracache');
				} else if (doStatus.doFailed) {
					reason = doStatus.message || __('Do failed after destructive work started. Resume the current recovery path or use explicit rollback/uninstall.', 'ultracache');
				} else if (!isCurrent) {
					reason = getMediaLibraryReplacementStepInactiveReason(step, stage);
				} else if (!doStatus.doReady && !doStatus.doComplete) {
					reason = doStatus.message || __('Do is blocked until Prepare and the hard pre-Do guard are complete.', 'ultracache');
				}
			} else if (step === 'verify' && isMediaLibraryReplacementVerifyRunnerReady()) {
				const verifyStatus = getMediaLibraryReplacementVerifyStatus();
				if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
					reason = __('Media Library replacement is busy.', 'ultracache');
				} else if (!isCurrent) {
					reason = getMediaLibraryReplacementStepInactiveReason(step, stage);
				} else if (!verifyStatus.verifyReady && !verifyStatus.verifyComplete) {
					reason = verifyStatus.message || __('Verify is blocked until Do is complete.', 'ultracache');
				}
			} else if (!isMediaLibraryReplacementRunnerReady()) {
				reason = getMediaLibraryReplacementRunnerUnavailableMessage();
			} else if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				reason = __('Media Library replacement is busy.', 'ultracache');
			} else if (step === 'delete' && isCurrent && deleteReason) {
				reason = deleteReason;
			} else if (!isCurrent) {
				reason = getMediaLibraryReplacementStepInactiveReason(step, stage);
			}
			return {
				stage,
				current: isCurrent,
				disabled: !!reason,
				reason,
				restartOnClick: false,
			};
		}

		function getMediaLibraryReplacementWorkflowButtonClass(state) {
			const baseClass = 'uc-btn w-full text-white py-3 font-bold';
			return baseClass + (state && state.disabled && !state.current ? ' opacity-50 cursor-not-allowed grayscale' : '');
		}


		function getMediaLibraryReplacementCurrentJobId() {
			return mediaLibraryReplacementCleanupPreview && mediaLibraryReplacementCleanupPreview.jobId
				? String(mediaLibraryReplacementCleanupPreview.jobId)
				: (mediaLibraryReplacementDbPreview && mediaLibraryReplacementDbPreview.jobId
					? String(mediaLibraryReplacementDbPreview.jobId)
					: (mediaLibraryReplacementPreview && mediaLibraryReplacementPreview.jobId
						? String(mediaLibraryReplacementPreview.jobId)
						: (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? String(mediaLibraryReplacementStatus.jobId) : '')));
		}

		function getMediaLibraryReplacementDeleteDisabledReason() {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return __('Media Library replacement is busy.', 'ultracache');
			}
			if (!isMediaLibraryReplacementDeleteRunnerReady()) {
				return __('The resumable Delete Originals runner is not available.', 'ultracache');
			}

			const deleteStatus = getMediaLibraryReplacementDeleteStatus();
			if (!getMediaLibraryReplacementCurrentJobId()) {
				return __('Prepare the replacement plan first.', 'ultracache');
			}
			if (deleteStatus.deleteComplete) {
				return __('Delete Originals is complete for this job.', 'ultracache');
			}
			if (!deleteStatus.deleteReady) {
				return deleteStatus.message || __('Run Verify first. Delete Originals unlocks only after verification and cleanup readiness are clean.', 'ultracache');
			}
			if (Number(deleteStatus.total || 0) <= 0) {
				return __('There are no original JPG/PNG files ready to delete.', 'ultracache');
			}
			return '';
		}

		async function prepareMediaLibraryReplacementWorkflow(forceRestart = false, confirmationGranted = false) {
			if (!confirmationGranted && (forceRestart || shouldConfirmMediaLibraryReplacementPrepare())) {
				openMediaLibraryReplacementWarning('prepare');
				return;
			}

			let readiness = getMediaLibraryReplacementReadinessStatus();
			let readinessJustCompleted = false;

			if (!readiness.inventoryComplete || !readiness.readyForReplacement) {
				await runMediaLibraryReplacementReadiness(!!forceRestart || !!readiness.inventoryComplete, true);
				const workflow = await refreshMediaLibraryReplacementWorkflowStatus();
				readiness = workflow && workflow.readiness && typeof workflow.readiness === 'object'
					? workflow.readiness
					: getMediaLibraryReplacementReadinessStatus();
				readinessJustCompleted = !!readiness.inventoryComplete && !!readiness.readyForReplacement;
			}

			if (!readiness.inventoryComplete || !readiness.readyForReplacement) {
				return;
			}

			return runMediaLibraryReplacementPrepare(!!forceRestart, readiness, readinessJustCompleted);
		}

		async function doMediaLibraryReplacementWorkflow() {
			return runMediaLibraryReplacementDo();
		}

		async function verifyMediaLibraryReplacementWorkflow() {
			return runMediaLibraryReplacementVerify();
		}

		async function deleteMediaLibraryReplacementOriginalsWorkflow() {
			return runMediaLibraryReplacementDelete();
		}

		async function prepareMediaLibraryReplacementFoundation(forceRestart = false) {
			return prepareMediaLibraryReplacementWorkflow(!!forceRestart);
		}

		async function openMediaLibraryReplacementPreviewModal() {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				const response = await loadMediaLibraryReplacementPreviewPage(0);
				setMediaLibraryReplacementPreview(response || null);
				setMediaLibraryReplacementPreviewOpen(true);
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media Library replacement preview could not be loaded.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		async function changeMediaLibraryReplacementPreviewPage(offset) {
			if (mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				await loadMediaLibraryReplacementPreviewPage(offset, mediaLibraryReplacementPreview && mediaLibraryReplacementPreview.jobId ? mediaLibraryReplacementPreview.jobId : '');
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media Library replacement preview page could not be loaded.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}




		async function loadMediaLibraryReplacementDbPreviewPage(offset = 0, jobId = '') {
			const response = await apiRequest('media_library_replacement_db_preview', {
				cacheBust: getMediaConversionTestCacheBust(),
				jobId: jobId || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? mediaLibraryReplacementStatus.jobId : ''),
				limit: 200,
				offset: Math.max(0, Number(offset || 0)),
			});
			setMediaLibraryReplacementDbPreview(response || null);
			return response;
		}

		async function openMediaLibraryReplacementDbPreviewModal() {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				const jobId = mediaLibraryReplacementPreview && mediaLibraryReplacementPreview.jobId
					? String(mediaLibraryReplacementPreview.jobId)
					: (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? String(mediaLibraryReplacementStatus.jobId) : '');
				const response = await loadMediaLibraryReplacementDbPreviewPage(0, jobId);
				setMediaLibraryReplacementDbPreview(response || null);
				setMediaLibraryReplacementStatus(response || null);
				setMediaLibraryReplacementDbPreviewOpen(true);
				pushToast({
					type: response && response.success && !response.blocked ? 'success' : 'error',
					text: response && response.message ? response.message : __('Media Library replacement database preview loaded.', 'ultracache'),
				});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media Library replacement database preview could not be loaded.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		async function changeMediaLibraryReplacementDbPreviewPage(offset) {
			if (mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				await loadMediaLibraryReplacementDbPreviewPage(offset, mediaLibraryReplacementDbPreview && mediaLibraryReplacementDbPreview.jobId ? mediaLibraryReplacementDbPreview.jobId : '');
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media Library replacement database preview page could not be loaded.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		async function loadMediaLibraryReplacementCleanupPreviewPage(offset = 0, jobId = '') {
			const response = await apiRequest('media_library_replacement_cleanup_preview', {
				cacheBust: getMediaConversionTestCacheBust(),
				jobId: jobId || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? mediaLibraryReplacementStatus.jobId : ''),
				limit: 200,
				offset: Math.max(0, Number(offset || 0)),
			});
			setMediaLibraryReplacementCleanupPreview(response || null);
			return response;
		}

		async function openMediaLibraryReplacementCleanupPreviewModal() {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy || !isMediaLibraryReplacementRunnerReady()) {
				showMediaLibraryReplacementRunnerUnavailable();
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				const jobId = getMediaLibraryReplacementCurrentJobId();
				const response = await loadMediaLibraryReplacementCleanupPreviewPage(0, jobId);
				setMediaLibraryReplacementStatus(response || null);
				setMediaLibraryReplacementCleanupPreviewOpen(true);
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : __('Media Library replacement cleanup preview could not be loaded.', 'ultracache') });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}


		async function changeMediaLibraryReplacementCleanupPreviewPage(offset) {
			if (mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				await loadMediaLibraryReplacementCleanupPreviewPage(offset, mediaLibraryReplacementCleanupPreview && mediaLibraryReplacementCleanupPreview.jobId ? mediaLibraryReplacementCleanupPreview.jobId : '');
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Media Library replacement cleanup preview page could not be loaded.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		function closeMediaLibraryReplacementCleanupPreviewModal() {
			setMediaLibraryReplacementCleanupPreviewOpen(false);
		}


		async function applyMediaLibraryReplacementCleanup() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function copyMediaLibraryReplacementFiles() {
			return runMediaLibraryReplacementPrepare(false);
		}



		async function prepareMediaLibraryReplacementMetadataUpdates() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function applyMediaLibraryReplacementMetadataUpdates() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function rollbackMediaLibraryReplacementMetadataUpdates() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function scanMediaLibraryReplacementReferences() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function matchMediaLibraryReplacementReferences() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function scanMediaLibraryReplacementThemeCssReferences() {
			showMediaLibraryReplacementRunnerUnavailable();
		}

		async function previewMediaLibraryReplacementThemeCssReplacements() {
			if (busy || mediaConversionTestBusy || mediaLibraryReplacementBusy) {
				return;
			}

			setMediaLibraryReplacementBusy(true);
			try {
				const jobId = mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.jobId ? String(mediaLibraryReplacementStatus.jobId) : '';
				const response = await apiRequest('media_library_replacement_theme_css_preview', {
					cacheBust: getMediaConversionTestCacheBust(),
					jobId,
					limit: 20,
					offset: 0,
				});
				setMediaLibraryReplacementStatus(response || null);
				pushToast({
					type: response && response.success && !response.blocked ? 'success' : 'error',
					text: response && response.message ? response.message : __('Theme CSS replacement preview completed.', 'ultracache'),
				});
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Theme CSS replacement preview failed.' });
			} finally {
				setMediaLibraryReplacementBusy(false);
			}
		}

		async function applyMediaLibraryReplacementThemeCssReplacements() {
			showMediaLibraryReplacementRunnerUnavailable();
		}

		async function verifyMediaLibraryReplacementThemeCssReplacements() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function applyMediaLibraryReplacementDatabaseReplacements() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function verifyMediaLibraryReplacementDatabaseReplacements() {
			showMediaLibraryReplacementRunnerUnavailable();
		}


		async function rollbackMediaLibraryReplacementDatabaseReplacements() {
			showMediaLibraryReplacementRunnerUnavailable();
		}

		return {
			refreshMediaLibraryReplacementWorkflowStatus,
			persistMediaLibraryReplacementWorkflowStage,
			loadMediaLibraryReplacementPreviewPage,
			canRefreshMediaLibraryReplacementMappingPreview,
			canRefreshMediaLibraryReplacementDatabasePreview,
			getMediaLibraryReplacementNextActionKey,
			getMediaLibraryReplacementActionClass,
			getMediaLibraryReplacementWorkflowStage,
			getMediaLibraryReplacementStepInactiveReason,
			isMediaLibraryReplacementReadinessRunnerReady,
			getMediaLibraryReplacementReadinessStatus,
			manageMediaLibraryReplacementSession,
			runMediaLibraryReplacementReadiness,
			isMediaLibraryReplacementPrepareRunnerReady,
			getMediaLibraryReplacementPrepareStatus,
			runMediaLibraryReplacementPrepare,
			getMediaLibraryReplacementPrepareLabel,
			isMediaLibraryReplacementDoRunnerReady,
			getMediaLibraryReplacementDoStatus,
			runMediaLibraryReplacementDo,
			getMediaLibraryReplacementDoLabel,
			isMediaLibraryReplacementVerifyRunnerReady,
			getMediaLibraryReplacementVerifyStatus,
			runMediaLibraryReplacementVerify,
			getMediaLibraryReplacementVerifyLabel,
			isMediaLibraryReplacementDeleteRunnerReady,
			getMediaLibraryReplacementDeleteStatus,
			runMediaLibraryReplacementDelete,
			getMediaLibraryReplacementDeleteLabel,
			isMediaLibraryReplacementRunnerReady,
			getMediaLibraryReplacementRecoveryStatus,
			isMediaLibraryReplacementOwnedByAnotherDashboard,
			restartMediaLibraryReplacementWorkflow,
			closeMediaLibraryReplacementWarning,
			confirmMediaLibraryReplacementWarning,
			getMediaLibraryReplacementRunnerUnavailableMessage,
			showMediaLibraryReplacementRunnerUnavailable,
			getMediaLibraryReplacementWorkflowButtonState,
			getMediaLibraryReplacementWorkflowButtonClass,
			getMediaLibraryReplacementCurrentJobId,
			getMediaLibraryReplacementDeleteDisabledReason,
			prepareMediaLibraryReplacementWorkflow,
			doMediaLibraryReplacementWorkflow,
			verifyMediaLibraryReplacementWorkflow,
			deleteMediaLibraryReplacementOriginalsWorkflow,
			prepareMediaLibraryReplacementFoundation,
			openMediaLibraryReplacementPreviewModal,
			changeMediaLibraryReplacementPreviewPage,
			loadMediaLibraryReplacementDbPreviewPage,
			openMediaLibraryReplacementDbPreviewModal,
			changeMediaLibraryReplacementDbPreviewPage,
			loadMediaLibraryReplacementCleanupPreviewPage,
			openMediaLibraryReplacementCleanupPreviewModal,
			changeMediaLibraryReplacementCleanupPreviewPage,
			closeMediaLibraryReplacementCleanupPreviewModal,
			applyMediaLibraryReplacementCleanup,
			copyMediaLibraryReplacementFiles,
			prepareMediaLibraryReplacementMetadataUpdates,
			applyMediaLibraryReplacementMetadataUpdates,
			rollbackMediaLibraryReplacementMetadataUpdates,
			scanMediaLibraryReplacementReferences,
			matchMediaLibraryReplacementReferences,
			scanMediaLibraryReplacementThemeCssReferences,
			previewMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementThemeCssReplacements,
			verifyMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementDatabaseReplacements,
			verifyMediaLibraryReplacementDatabaseReplacements,
			rollbackMediaLibraryReplacementDatabaseReplacements,
		};
	}

	admin.define('mediaReplacement', {
		useMediaReplacementState,
		useMediaReplacementWorkflow,
	});
})(window);
