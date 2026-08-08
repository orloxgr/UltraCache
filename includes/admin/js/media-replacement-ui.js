/* UltraCache Admin - Media Library replacement UI and preview modals */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before media-replacement-ui.js.');
	}

	const core = admin.get('core');
	if (!core) {
		throw new Error('UltraCache admin core module is required before media-replacement-ui.js.');
	}

	const { h, __, formatNumber, formatBytes } = core;

	function createMediaReplacementUi(config) {
		const source = config && typeof config === 'object' ? config : {};
		const {
			renderLabelWithHelp,
			SelectField,
			settings,
			updateSetting,
			busy,
			mediaConversionTestBusy,
			mediaSupport,
			mediaLibraryReplacementBusy,
			mediaLibraryReplacementStatus,
			mediaLibraryReplacementPreview,
			mediaLibraryReplacementPreviewOpen,
			mediaLibraryReplacementDbPreview,
			mediaLibraryReplacementDbPreviewOpen,
			mediaLibraryReplacementBlockers,
			mediaLibraryReplacementBlockersOpen,
			mediaLibraryReplacementBlockerDecisions,
			setMediaLibraryReplacementBlockersOpen,
			setMediaLibraryReplacementBlockerDecisions,
			mediaLibraryReplacementCleanupPreview,
			mediaLibraryReplacementCleanupPreviewOpen,
			mediaLibraryReplacementWarningAction,
			mediaLibraryReplacementWarningConfirmation,
			setMediaLibraryReplacementWarningConfirmation,
			closeMediaLibraryReplacementWarning,
			confirmMediaLibraryReplacementWarning,
			setMediaLibraryReplacementPreviewOpen,
			setMediaLibraryReplacementDbPreviewOpen,
			closeMediaLibraryReplacementCleanupPreviewModal,
			changeMediaLibraryReplacementPreviewPage,
			changeMediaLibraryReplacementDbPreviewPage,
			changeMediaLibraryReplacementBlockersPage,
			openMediaLibraryReplacementBlockersModal,
			saveMediaLibraryReplacementBlockerDecisions,
			changeMediaLibraryReplacementCleanupPreviewPage,
			runMediaConversionTest,
			openMediaConversionTestModal,
			getMediaLibraryReplacementDeleteDisabledReason,
			getMediaLibraryReplacementRecoveryStatus,
			isMediaLibraryReplacementRunnerReady,
			isMediaLibraryReplacementOwnedByAnotherDashboard,
			getMediaLibraryReplacementWorkflowButtonState,
			getMediaLibraryReplacementWorkflowButtonClass,
			getMediaLibraryReplacementRunnerUnavailableMessage,
			getMediaLibraryReplacementActionClass,
			isMediaLibraryReplacementReadinessRunnerReady,
			getMediaLibraryReplacementPrepareLabel,
			getMediaLibraryReplacementDoLabel,
			getMediaLibraryReplacementVerifyLabel,
			getMediaLibraryReplacementDeleteLabel,
			prepareMediaLibraryReplacementWorkflow,
			doMediaLibraryReplacementWorkflow,
			verifyMediaLibraryReplacementWorkflow,
			deleteMediaLibraryReplacementOriginalsWorkflow,
			restartMediaLibraryReplacementWorkflow,
			recoverMediaLibraryReplacementWorkflow,
			prepareMediaLibraryReplacementFoundation,
			openMediaLibraryReplacementPreviewModal,
			openMediaLibraryReplacementDbPreviewModal,
			openMediaLibraryReplacementCleanupPreviewModal,
			copyMediaLibraryReplacementFiles,
			prepareMediaLibraryReplacementMetadataUpdates,
			applyMediaLibraryReplacementMetadataUpdates,
			scanMediaLibraryReplacementReferences,
			matchMediaLibraryReplacementReferences,
			applyMediaLibraryReplacementDatabaseReplacements,
			verifyMediaLibraryReplacementDatabaseReplacements,
			rollbackMediaLibraryReplacementDatabaseReplacements,
			scanMediaLibraryReplacementThemeCssReferences,
			previewMediaLibraryReplacementThemeCssReplacements,
			applyMediaLibraryReplacementThemeCssReplacements,
			verifyMediaLibraryReplacementThemeCssReplacements,
			rollbackMediaLibraryReplacementMetadataUpdates
		} = source;


function closeMediaLibraryReplacementPreviewModal() {
	setMediaLibraryReplacementPreviewOpen(false);
}


function closeMediaLibraryReplacementDbPreviewModal() {
	setMediaLibraryReplacementDbPreviewOpen(false);
}


function closeMediaLibraryReplacementBlockersModal() {
	setMediaLibraryReplacementBlockersOpen(false);
}



function renderMediaLibraryReplacementWarningModal() {
	if (!mediaLibraryReplacementWarningAction) {
		return null;
	}

	const isRestart = String(mediaLibraryReplacementWarningAction) === 'restart';
	const confirmationValid = String(mediaLibraryReplacementWarningConfirmation || '').trim() === 'ok';
	return h('div', { className: 'uc-media-test-modal', role: 'presentation', onClick: closeMediaLibraryReplacementWarning, key: 'media-library-replacement-warning-modal' }, [
		h('div', { className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-media-replacement-warning__dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'uc-media-replacement-warning-title', onClick: (event) => event.stopPropagation() }, [
			h('button', { type: 'button', className: 'uc-media-test-modal__close', onClick: closeMediaLibraryReplacementWarning, 'aria-label': __('Close warning', 'ultracache') }, '×'),
			h('div', { className: 'uc-support-modal__eyebrow' }, __('Media Library replacement', 'ultracache')),
			h('h3', { className: 'uc-support-modal__title', id: 'uc-media-replacement-warning-title' }, __('Media Library Replacement Warning', 'ultracache')),
			h('p', { className: 'uc-support-modal__text' }, __('You are about to start the Media Library Replacement workflow.', 'ultracache')),
			h('p', { className: 'uc-support-modal__text' }, __('Later stages of this process will replace Media Library files and update WordPress metadata, database references, and supported Theme CSS references to use the newly generated files.', 'ultracache')),
			h('p', { className: 'uc-support-modal__text' }, __('UltraCache performs validation, resumable processing, verification, and recovery checks, but no file and database migration can be guaranteed to work correctly on every WordPress setup.', 'ultracache')),
			h('p', { className: 'uc-support-modal__text' }, __('Before continuing, make sure you have:', 'ultracache')),
			h('ul', { className: 'uc-media-replacement-warning__list' }, [
				h('li', null, __('A current and working backup of your WordPress uploads directory.', 'ultracache')),
				h('li', null, __('A current and working backup of your WordPress database.', 'ultracache')),
				h('li', null, __('Confirmed that both backups can be restored if necessary.', 'ultracache')),
			]),
			h('label', { className: 'uc-media-replacement-warning__label', htmlFor: 'uc-media-replacement-warning-confirmation' }, __('Type ok below to confirm that you understand the risks and have the required backups.', 'ultracache')),
			h('input', {
				id: 'uc-media-replacement-warning-confirmation',
				type: 'text',
				value: mediaLibraryReplacementWarningConfirmation || '',
				autoComplete: 'off',
				autoFocus: true,
				placeholder: __('Type ok', 'ultracache'),
				onChange: (event) => setMediaLibraryReplacementWarningConfirmation(event.target.value),
				onKeyDown: (event) => {
					if (event.key === 'Enter' && confirmationValid) {
						event.preventDefault();
						confirmMediaLibraryReplacementWarning();
					}
				},
			}),
			h('div', { className: 'uc-media-replacement-warning__actions' }, [
				h('button', { type: 'button', className: 'uc-btn', onClick: closeMediaLibraryReplacementWarning }, __('Cancel', 'ultracache')),
				h('button', { type: 'button', className: 'uc-btn uc-btn--danger', disabled: !confirmationValid, onClick: confirmMediaLibraryReplacementWarning }, isRestart ? __('Continue with Restart', 'ultracache') : __('Continue with Prepare', 'ultracache')),
			]),
		]),
	]);
}


function getMediaLibraryReplacementProgressSuffix(status, hasMore) {
	const normalizedStatus = status ? String(status) : '';
	if (normalizedStatus === 'copying') {
		return ' · ' + __('copying in chunks', 'ultracache');
	}
	if (normalizedStatus === 'intermediate_expanding') {
		return ' · ' + __('expanding intermediate sizes', 'ultracache');
	}
	if (normalizedStatus === 'metadata_preparing') {
		return ' · ' + __('preparing metadata in chunks', 'ultracache');
	}
	if (normalizedStatus === 'metadata_applying') {
		return ' · ' + __('switching metadata in chunks', 'ultracache');
	}
	if (normalizedStatus === 'refs_scanning') {
		return ' · ' + __('scanning references in chunks', 'ultracache');
	}
	if (normalizedStatus === 'refs_matching') {
		return ' · ' + __('matching references in chunks', 'ultracache');
	}
	if (normalizedStatus === 'db_replacing') {
		return ' · ' + __('applying DB replacements in chunks', 'ultracache');
	}
	if (normalizedStatus === 'db_verifying') {
		return ' · ' + __('verifying DB replacements in chunks', 'ultracache');
	}
	return hasMore ? ' · ' + __('scanning in chunks', 'ultracache') : ' · ' + __('step complete', 'ultracache');
}


function renderMediaLibraryReplacementPreviewModal() {
	if (!mediaLibraryReplacementPreviewOpen) {
		return null;
	}

	const preview = mediaLibraryReplacementPreview && typeof mediaLibraryReplacementPreview === 'object' ? mediaLibraryReplacementPreview : {};
	const summary = preview.summary && typeof preview.summary === 'object' ? preview.summary : {};
	const items = Array.isArray(preview.items) ? preview.items : [];
	const targetFormat = preview.targetFormat ? String(preview.targetFormat).toUpperCase() : __('Target', 'ultracache');
	const returned = typeof preview.returned !== 'undefined' ? Number(preview.returned || 0) : items.length;
	const offset = typeof preview.offset !== 'undefined' ? Math.max(0, Number(preview.offset || 0)) : 0;
	const limit = typeof preview.limit !== 'undefined' ? Math.max(1, Number(preview.limit || 200)) : 200;
	const hasMorePreviewRows = !!preview.hasMore;
	const previousOffset = typeof preview.previousOffset !== 'undefined' ? Math.max(0, Number(preview.previousOffset || 0)) : Math.max(0, offset - limit);
	const nextOffset = typeof preview.nextOffset !== 'undefined' ? Math.max(0, Number(preview.nextOffset || 0)) : offset + returned;
	const total = Number(summary.total || 0);
	const matched = Number(summary.matched || 0);
	const copied = Number(summary.copied || 0);
	const metadataReady = Number(summary.metadata_ready || 0);
	const metadataUpdated = Number(summary.metadata_updated || 0);
	const refsScanned = Number(summary.refs_scanned || 0);
	const metadataRestored = Number(summary.metadata_restored || 0);
	const refsFound = Number(summary.refsFound || 0);
	const serializedRefs = Number(summary.serializedRefs || 0);
	const jsonRefs = Number(summary.jsonRefs || 0);
	const metadataFailed = Number(summary.metadata_failed || 0);
	const metadataRollbackFailed = Number(summary.metadata_rollback_failed || 0);
	const skipped = Number(summary.skipped || 0);
	const failed = Number(summary.failed || 0);
	const oldTotal = Number(summary.oldTotalSize || 0);
	const targetTotal = Number(summary.targetTotalSize || 0);
	const visibleFrom = total > 0 && returned > 0 ? offset + 1 : 0;
	const visibleTo = total > 0 && returned > 0 ? offset + returned : 0;

	return h('div', {
		className: 'uc-media-test-modal uc-media-replacement-preview-modal',
		onClick: closeMediaLibraryReplacementPreviewModal,
		role: 'presentation',
		key: 'media-library-replacement-preview-modal',
	}, [
		h('div', {
			className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-media-replacement-preview-modal__dialog',
			onClick: (event) => event.stopPropagation(),
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'uc-media-replacement-preview-title',
			key: 'dialog',
		}, [
			h('button', {
				type: 'button',
				className: 'uc-media-test-modal__close uc-support-modal__close',
				onClick: closeMediaLibraryReplacementPreviewModal,
				'aria-label': __('Close mapping preview', 'ultracache'),
				key: 'close',
			}, '×'),
			h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('Media Library replacement', 'ultracache')),
			h('h3', { className: 'uc-support-modal__title', id: 'uc-media-replacement-preview-title', key: 'title' }, __('Mapping preview', 'ultracache')),
			h('p', { className: 'uc-support-modal__text', key: 'summary' }, preview.hasPreview
				? (targetFormat + ' · ' + formatNumber(matched + copied + metadataReady + metadataUpdated + refsScanned + metadataRestored) + ' eligible of ' + formatNumber(total) + ' registry rows. Site content references were not replaced.')
				: (preview.message || __('No mapping preview is available yet.', 'ultracache'))
			),
			preview.hasPreview ? h('div', { className: 'uc-media-replacement-preview-summary', key: 'preview-summary' }, [
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'matched' }, [h('span', { key: 'label' }, __('Eligible pending copy', 'ultracache')), h('strong', { key: 'value' }, formatNumber(matched))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'copied' }, [h('span', { key: 'label' }, __('Copied files', 'ultracache')), h('strong', { key: 'value' }, formatNumber(copied))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'metadata-ready' }, [h('span', { key: 'label' }, __('Metadata plans', 'ultracache')), h('strong', { key: 'value' }, formatNumber(metadataReady))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'metadata-updated' }, [h('span', { key: 'label' }, __('Metadata switched', 'ultracache')), h('strong', { key: 'value' }, formatNumber(metadataUpdated))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'refs-scanned' }, [h('span', { key: 'label' }, __('References scanned', 'ultracache')), h('strong', { key: 'value' }, formatNumber(refsScanned))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'metadata-restored' }, [h('span', { key: 'label' }, __('Metadata restored', 'ultracache')), h('strong', { key: 'value' }, formatNumber(metadataRestored))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'refs-found' }, [h('span', { key: 'label' }, __('References found', 'ultracache')), h('strong', { key: 'value' }, formatNumber(refsFound) + ' · ' + __('Serialized', 'ultracache') + ' ' + formatNumber(serializedRefs) + ' · JSON ' + formatNumber(jsonRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'skipped' }, [h('span', { key: 'label' }, __('Skipped', 'ultracache')), h('strong', { key: 'value' }, formatNumber(skipped))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'failed' }, [h('span', { key: 'label' }, __('Failed', 'ultracache')), h('strong', { key: 'value' }, formatNumber(failed + metadataFailed + metadataRollbackFailed))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'sizes' }, [h('span', { key: 'label' }, __('Size plan', 'ultracache')), h('strong', { key: 'value' }, formatBytes(oldTotal) + ' → ' + formatBytes(targetTotal))]),
			]) : null,
			items.length ? h('div', { className: 'uc-media-replacement-preview-table', key: 'preview-table' }, [
				h('div', { className: 'uc-media-replacement-preview-row uc-media-replacement-preview-row--head', key: 'head' }, [
					h('span', { key: 'attachment' }, __('Attachment', 'ultracache')),
					h('span', { key: 'old' }, __('Current Library file', 'ultracache')),
					h('span', { key: 'generated' }, __('UltraCache generated source', 'ultracache')),
					h('span', { key: 'planned' }, __('Planned WordPress file', 'ultracache')),
					h('span', { key: 'size' }, __('Size change', 'ultracache')),
					h('span', { key: 'status' }, __('Status', 'ultracache')),
				]),
				items.map((item, index) => {
					const status = item && item.status ? String(item.status) : 'pending';
					const title = item && item.title ? String(item.title) : ('#' + String(index + 1));
					const oldPath = item && item.oldRelativePath ? String(item.oldRelativePath) : '—';
					const generatedPath = item && item.generatedPath ? String(item.generatedPath) : '—';
					const plannedPath = item && item.plannedRelativePath ? String(item.plannedRelativePath) : '—';
					const collision = !!(item && item.hasCollision);
					const existingReplacement = !!(item && item.existingUploadReplacement);
					const validExistingReplacement = !!(item && item.existingUploadReplacementValid);
					const savingPercent = item && typeof item.savingPercent !== 'undefined' ? Number(item.savingPercent || 0) : 0;
					const sizeText = (status === 'matched' || status === 'copied' || status === 'metadata_ready' || status === 'metadata_updated' || status === 'refs_scanned' || status === 'metadata_restored')
						? (formatBytes(item.oldSize || 0) + ' → ' + formatBytes(item.targetSize || 0) + ' · -' + String(savingPercent) + '%')
						: '—';
					const variantLabel = item && item.itemScope === 'intermediate' && item.sizeName ? ' · ' + __('Size', 'ultracache') + ': ' + String(item.sizeName) : ' · ' + __('Main file', 'ultracache');
					return h('div', { className: 'uc-media-replacement-preview-row is-' + status, key: 'preview-row-' + (item.id || index) }, [
						h('span', { key: 'attachment' }, [
							h('strong', { key: 'title' }, title),
							h('small', { key: 'id' }, 'ID ' + String(item.attachmentId || '—') + variantLabel + ' · ' + String(item.sourceFormat || '').toUpperCase() + ' → ' + String(item.targetFormat || '').toUpperCase()),
						]),
						h('span', { key: 'old' }, h('code', {}, oldPath)),
						h('span', { key: 'generated' }, h('code', {}, generatedPath)),
						h('span', { key: 'planned' }, [
							h('code', { key: 'path' }, plannedPath),
							collision ? h('small', { className: 'uc-media-replacement-preview-warning', key: 'collision' }, __('Different destination file exists. Prepare blocks it unless overwrite with verified backup is selected.', 'ultracache')) : null,
							existingReplacement ? h('small', { className: validExistingReplacement ? 'uc-media-replacement-preview-note' : 'uc-media-replacement-preview-warning', key: 'existing' }, validExistingReplacement ? __('Existing replacement file will be reused; no duplicate will be created.', 'ultracache') : __('Existing replacement file is invalid; no duplicate will be created.', 'ultracache')) : null,
							item && item.destinationOverwritten ? h('small', { className: 'uc-media-replacement-preview-note', key: 'overwritten' }, __('Existing destination was atomically overwritten after a verified backup was persisted.', 'ultracache')) : null,
						]),
						h('span', { key: 'size' }, sizeText),
						h('span', { key: 'status' }, [h('strong', { key: 'status-text' }, status), item && item.errorMessage ? h('small', { key: 'error' }, String(item.errorMessage)) : null]),
					]);
				}),
			]) : (preview.hasPreview ? h('div', { className: 'rounded-xl bg-white/5 text-zinc-400 text-sm px-4 py-4', key: 'empty' }, __('No mapping preview rows were returned for this page.', 'ultracache')) : null),
			preview.hasPreview ? h('div', { className: 'uc-media-replacement-preview-pagination', key: 'preview-pagination' }, [
				h('span', { className: 'uc-support-modal__text', key: 'range' }, __('Showing', 'ultracache') + ' ' + formatNumber(visibleFrom) + '–' + formatNumber(visibleTo) + ' ' + __('of', 'ultracache') + ' ' + formatNumber(total) + ' ' + __('registry rows.', 'ultracache')),
				h('div', { className: 'uc-media-replacement-preview-pagination__buttons', key: 'buttons' }, [
					h('button', {
						type: 'button',
						className: 'uc-btn',
						onClick: () => changeMediaLibraryReplacementPreviewPage(previousOffset),
						disabled: mediaLibraryReplacementBusy || offset <= 0,
						key: 'previous',
					}, __('Previous', 'ultracache')),
					h('button', {
						type: 'button',
						className: 'uc-btn uc-btn--primary',
						onClick: () => changeMediaLibraryReplacementPreviewPage(nextOffset),
						disabled: mediaLibraryReplacementBusy || !hasMorePreviewRows,
						key: 'next',
					}, __('Next', 'ultracache')),
				]),
			]) : null,
			preview.hasPreview && preview.nextStep ? h('p', { className: 'uc-support-modal__text mt-3', key: 'next-step' }, preview.nextStep) : null,
		]),
	]);
}



function renderMediaLibraryReplacementDbPreviewModal() {
	if (!mediaLibraryReplacementDbPreviewOpen) {
		return null;
	}

	const preview = mediaLibraryReplacementDbPreview && typeof mediaLibraryReplacementDbPreview === 'object' ? mediaLibraryReplacementDbPreview : {};
	const summary = preview.summary && typeof preview.summary === 'object' ? preview.summary : {};
	const items = Array.isArray(preview.items) ? preview.items : [];
	const tables = Array.isArray(summary.tables) ? summary.tables : [];
	const totalRefs = Number(summary.totalRefs || 0);
	const pendingRefs = Number(summary.pendingRefs || 0);
	const serializedRefs = Number(summary.serializedRefs || 0);
	const jsonRefs = Number(summary.jsonRefs || 0);
	const plainRefs = Number(summary.plainRefs || 0);
	const failedRefs = Number(summary.failedRefs || 0);
	const duplicateRefsSkipped = Number(summary.duplicateRefsSkipped || preview.duplicateRefsSkipped || 0);
	const verifiedRefs = Number(summary.verifiedRefs || 0);
	const verifyFailedRefs = Number(summary.verifyFailedRefs || 0);
	const restoredRefs = Number(summary.restoredRefs || 0);
	const rollbackFailedRefs = Number(summary.rollbackFailedRefs || 0);
	const returned = typeof preview.returned !== 'undefined' ? Number(preview.returned || 0) : items.length;
	const offset = typeof preview.offset !== 'undefined' ? Math.max(0, Number(preview.offset || 0)) : 0;
	const limit = typeof preview.limit !== 'undefined' ? Math.max(1, Number(preview.limit || 200)) : 200;
	const hasMorePreviewRows = !!preview.hasMore;
	const previousOffset = typeof preview.previousOffset !== 'undefined' ? Math.max(0, Number(preview.previousOffset || 0)) : Math.max(0, offset - limit);
	const nextOffset = typeof preview.nextOffset !== 'undefined' ? Math.max(0, Number(preview.nextOffset || 0)) : offset + returned;
	const visibleFrom = totalRefs > 0 && returned > 0 ? offset + 1 : 0;
	const visibleTo = totalRefs > 0 && returned > 0 ? offset + returned : 0;
	const tableSummary = tables.length
		? tables.slice(0, 4).map((table) => String(table.table || '') + ' (' + formatNumber(table.count || 0) + ')').join(', ') + (tables.length > 4 ? ', +' + String(tables.length - 4) : '')
		: __('None', 'ultracache');

	return h('div', {
		className: 'uc-media-test-modal uc-media-replacement-preview-modal',
		onClick: closeMediaLibraryReplacementDbPreviewModal,
		role: 'presentation',
		key: 'media-library-replacement-db-preview-modal',
	}, [
		h('div', {
			className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-media-replacement-preview-modal__dialog',
			onClick: (event) => event.stopPropagation(),
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'uc-media-replacement-db-preview-title',
			key: 'dialog',
		}, [
			h('button', {
				type: 'button',
				className: 'uc-media-test-modal__close uc-support-modal__close',
				onClick: closeMediaLibraryReplacementDbPreviewModal,
				'aria-label': __('Close database replacement preview', 'ultracache'),
				key: 'close',
			}, '×'),
			h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('Media Library replacement', 'ultracache')),
			h('h3', { className: 'uc-support-modal__title', id: 'uc-media-replacement-db-preview-title', key: 'title' }, __('Database replacement preview', 'ultracache')),
			h('p', { className: 'uc-support-modal__text', key: 'summary' }, preview.hasReplacementPreview
				? (formatNumber(totalRefs) + ' database replacement' + (totalRefs === 1 ? '' : 's') + '. ' + (restoredRefs === totalRefs && totalRefs > 0 ? __('Site content references have been rolled back for the completed rows.', 'ultracache') : ((verifiedRefs > 0 || (pendingRefs === 0 && totalRefs > 0)) ? __('Site content references have been updated for the completed rows.', 'ultracache') : __('Site content has not been changed by this preview step.', 'ultracache'))))
				: (preview.message || __('No database replacement preview is available yet.', 'ultracache'))
			),
			preview.hasReplacementPreview ? h('div', { className: 'uc-media-replacement-preview-summary', key: 'db-preview-summary' }, [
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'total' }, [h('span', { key: 'label' }, __('Planned changes', 'ultracache')), h('strong', { key: 'value' }, formatNumber(totalRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'pending' }, [h('span', { key: 'label' }, __('Pending', 'ultracache')), h('strong', { key: 'value' }, formatNumber(pendingRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'verified' }, [h('span', { key: 'label' }, __('Verified', 'ultracache')), h('strong', { key: 'value' }, formatNumber(verifiedRefs) + ' / ' + formatNumber(verifyFailedRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'restored' }, [h('span', { key: 'label' }, __('Restored / Rollback failed', 'ultracache')), h('strong', { key: 'value' }, formatNumber(restoredRefs) + ' / ' + formatNumber(rollbackFailedRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'serialized' }, [h('span', { key: 'label' }, __('Serialized / JSON', 'ultracache')), h('strong', { key: 'value' }, formatNumber(serializedRefs) + ' / ' + formatNumber(jsonRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'plain' }, [h('span', { key: 'label' }, __('Plain / Failed', 'ultracache')), h('strong', { key: 'value' }, formatNumber(plainRefs) + ' / ' + formatNumber(failedRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'duplicates' }, [h('span', { key: 'label' }, __('Duplicate refs skipped', 'ultracache')), h('strong', { key: 'value' }, formatNumber(duplicateRefsSkipped))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'tables' }, [h('span', { key: 'label' }, __('Tables', 'ultracache')), h('strong', { key: 'value' }, tableSummary)]),
			]) : null,
			items.length ? h('div', { className: 'uc-media-replacement-preview-table', key: 'db-preview-table' }, [
				h('div', { className: 'uc-media-replacement-preview-row uc-media-replacement-preview-row--head', key: 'head' }, [
					h('span', { key: 'attachment' }, __('Attachment', 'ultracache')),
					h('span', { key: 'table' }, __('Table row', 'ultracache')),
					h('span', { key: 'column' }, __('Column', 'ultracache')),
					h('span', { key: 'old' }, __('Old fragment', 'ultracache')),
					h('span', { key: 'new' }, __('New fragment', 'ultracache')),
					h('span', { key: 'status' }, __('Status', 'ultracache')),
				]),
				items.map((item, index) => {
					const status = item && item.status ? String(item.status) : 'pending';
					const title = item && item.title ? String(item.title) : ('#' + String(index + 1));
					const oldFragment = item && item.oldFragment ? String(item.oldFragment) : '—';
					const newFragment = item && item.newFragment ? String(item.newFragment) : '—';
					const tableName = item && item.tableName ? String(item.tableName) : '—';
					const primary = item && item.primaryKeyColumn ? String(item.primaryKeyColumn) + '=' + String(item.primaryKeyValue || '—') : '—';
					const flags = (item && item.serialized ? __('Serialized', 'ultracache') : __('Plain', 'ultracache')) + (item && item.jsonDetected ? ' · JSON' : '');
					return h('div', { className: 'uc-media-replacement-preview-row is-' + status, key: 'db-preview-row-' + (item.id || index) }, [
						h('span', { key: 'attachment' }, [
							h('strong', { key: 'title' }, title),
							h('small', { key: 'id' }, 'ID ' + String(item.attachmentId || '—')),
						]),
						h('span', { key: 'table' }, [h('code', { key: 'table-name' }, tableName), h('small', { key: 'primary' }, primary)]),
						h('span', { key: 'column' }, h('code', {}, item && item.columnName ? String(item.columnName) : '—')),
						h('span', { key: 'old' }, h('code', {}, oldFragment)),
						h('span', { key: 'new' }, h('code', {}, newFragment)),
						h('span', { key: 'status' }, [h('strong', { key: 'status-text' }, status), h('small', { key: 'flags' }, flags), item && item.errorMessage ? h('small', { key: 'error' }, String(item.errorMessage)) : null]),
					]);
				}),
			]) : h('div', { className: 'rounded-xl bg-white/5 text-zinc-400 text-sm px-4 py-4', key: 'empty' }, totalRefs > 0 ? __('No database preview rows were returned for this page.', 'ultracache') : __('The reference scan found 0 old image references, so there is nothing to apply to site content.', 'ultracache')),
			preview.hasReplacementPreview ? h('div', { className: 'uc-media-replacement-preview-pagination', key: 'db-preview-pagination' }, [
				h('span', { className: 'uc-support-modal__text', key: 'range' }, totalRefs > 0
					? __('Showing', 'ultracache') + ' ' + formatNumber(visibleFrom) + '–' + formatNumber(visibleTo) + ' ' + __('of', 'ultracache') + ' ' + formatNumber(totalRefs) + ' ' + __('database references.', 'ultracache')
					: __('No database references are pending for this workflow.', 'ultracache')
				),
				totalRefs > 0 ? h('div', { className: 'uc-media-replacement-preview-pagination__buttons', key: 'buttons' }, [
					h('button', {
						type: 'button',
						className: 'uc-btn',
						onClick: () => changeMediaLibraryReplacementDbPreviewPage(previousOffset),
						disabled: mediaLibraryReplacementBusy || offset <= 0,
						key: 'previous',
					}, __('Previous', 'ultracache')),
					h('button', {
						type: 'button',
						className: 'uc-btn uc-btn--primary',
						onClick: () => changeMediaLibraryReplacementDbPreviewPage(nextOffset),
						disabled: mediaLibraryReplacementBusy || !hasMorePreviewRows,
						key: 'next',
					}, __('Next', 'ultracache')),
				]) : null,
			]) : null,
			preview.nextStep ? h('p', { className: 'uc-support-modal__text mt-3', key: 'next-step' }, preview.nextStep) : null,
		]),
	]);
}




function renderMediaLibraryReplacementBlockersModal() {
	if (!mediaLibraryReplacementBlockersOpen) {
		return null;
	}

	const preview = mediaLibraryReplacementBlockers && typeof mediaLibraryReplacementBlockers === 'object' ? mediaLibraryReplacementBlockers : {};
	const groups = Array.isArray(preview.groups) ? preview.groups : [];
	const items = Array.isArray(preview.items) ? preview.items : [];
	const activeCode = String(preview.activeBlockerCode || (groups[0] && groups[0].code) || '');
	const activeGroup = groups.find((group) => String(group && group.code || '') === activeCode) || groups[0] || {};
	const decisions = mediaLibraryReplacementBlockerDecisions && typeof mediaLibraryReplacementBlockerDecisions === 'object' ? mediaLibraryReplacementBlockerDecisions : {};
	const unresolved = groups.filter((group) => !String(decisions[String(group && group.code || '')] || (group && group.decision) || '')).length;
	const total = Math.max(0, Number(preview.total || 0));
	const returned = typeof preview.returned !== 'undefined' ? Math.max(0, Number(preview.returned || 0)) : items.length;
	const offset = Math.max(0, Number(preview.offset || 0));
	const limit = Math.max(1, Number(preview.limit || 100));
	const previousOffset = typeof preview.previousOffset !== 'undefined' ? Math.max(0, Number(preview.previousOffset || 0)) : Math.max(0, offset - limit);
	const nextOffset = typeof preview.nextOffset !== 'undefined' ? Math.max(0, Number(preview.nextOffset || 0)) : offset + returned;
	const hasMore = !!preview.hasMore;
	const visibleFrom = total > 0 && returned > 0 ? offset + 1 : 0;
	const visibleTo = total > 0 && returned > 0 ? offset + returned : 0;
	const actionLabel = (action) => action === 'overwrite_with_backup'
		? __('Overwrite with verified backup', 'ultracache')
		: __('Keep affected attachments as originals', 'ultracache');
	const actionDescription = (action) => action === 'overwrite_with_backup'
		? __('Back up each different destination, then atomically replace it with the prepared file.', 'ultracache')
		: __('Exclude every affected attachment from this replacement plan. Its files, metadata, references, and originals remain unchanged.', 'ultracache');
	const fileActionLabel = (action) => action === 'overwrite_with_backup'
		? __('Overwrite this destination with verified backup', 'ultracache')
		: __('Keep this attachment as original', 'ultracache');

	return h('div', {
		className: 'uc-media-test-modal uc-media-replacement-preview-modal',
		onClick: closeMediaLibraryReplacementBlockersModal,
		role: 'presentation',
		key: 'media-library-replacement-blockers-modal',
	}, [
		h('div', {
			className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-media-replacement-preview-modal__dialog',
			onClick: (event) => event.stopPropagation(),
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'uc-media-replacement-blockers-title',
			key: 'dialog',
		}, [
			h('button', { type: 'button', className: 'uc-media-test-modal__close uc-support-modal__close', onClick: closeMediaLibraryReplacementBlockersModal, 'aria-label': __('Close blocker decisions', 'ultracache'), key: 'close' }, '×'),
			h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('Media Library replacement', 'ultracache')),
			h('h3', { className: 'uc-support-modal__title', id: 'uc-media-replacement-blockers-title', key: 'title' }, __('Decide Blockers', 'ultracache')),
			h('p', { className: 'uc-support-modal__text', key: 'summary' }, __('Prepare found conditions that require decisions before Apply can be enabled. Choose a group policy, then override individual files where needed.', 'ultracache')),
			h('div', { className: 'uc-media-replacement-preview-summary', key: 'blocker-summary' }, [
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'groups' }, [h('span', { key: 'label' }, __('Blocker groups', 'ultracache')), h('strong', { key: 'value' }, formatNumber(preview.groupCount || groups.length))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'attachments' }, [h('span', { key: 'label' }, __('Affected attachments', 'ultracache')), h('strong', { key: 'value' }, formatNumber(preview.affectedAttachments || 0))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'variants' }, [h('span', { key: 'label' }, __('Affected units', 'ultracache')), h('strong', { key: 'value' }, formatNumber(preview.affectedVariants || 0))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'unresolved' }, [h('span', { key: 'label' }, __('Awaiting decision', 'ultracache')), h('strong', { key: 'value' }, formatNumber(unresolved))]),
			]),
			groups.length ? h('div', { className: 'space-y-3 mt-4', key: 'groups-list' }, groups.map((group, groupIndex) => {
				const code = String(group && group.code || '');
				const selected = String(decisions[code] || (group && group.decision) || '');
				const actions = Array.isArray(group && group.actions) ? group.actions : [];
				const active = code === activeCode;
				return h('div', { className: 'rounded-xl border p-4 ' + (active ? 'border-cyan-400/60 bg-cyan-400/5' : 'border-white/10 bg-white/5'), key: 'blocker-group-' + (code || groupIndex) }, [
					h('button', { type: 'button', className: 'w-full text-left bg-transparent border-0 p-0 cursor-pointer', onClick: () => changeMediaLibraryReplacementBlockersPage(0, code), key: 'group-heading' }, [
						h('div', { className: 'flex flex-col gap-1 md:flex-row md:items-center md:justify-between', key: 'heading-row' }, [
							h('strong', { className: 'text-white', key: 'title' }, String(group.title || code)),
							h('span', { className: 'text-xs text-zinc-400', key: 'counts' }, formatNumber(group.attachmentCount || 0) + ' ' + __('attachments', 'ultracache') + ' · ' + formatNumber(group.variantCount || 0) + ' ' + __('units', 'ultracache')),
						]),
						group.detail ? h('div', { className: 'text-xs text-zinc-400 mt-1', key: 'detail' }, String(group.detail)) : null,
					]),
					h('div', { className: 'mt-3 space-y-2', key: 'actions' }, actions.map((action) => h('label', { className: 'flex items-start gap-3 rounded-lg border border-white/10 px-3 py-2 cursor-pointer', key: 'action-' + action }, [
						h('input', { type: 'radio', name: 'uc-blocker-decision-' + code, value: action, checked: selected === action, onChange: () => setMediaLibraryReplacementBlockerDecisions((current) => Object.assign({}, current || {}, { [code]: action })), key: 'input' }),
						h('span', { key: 'copy' }, [h('strong', { className: 'block text-sm text-white', key: 'label' }, actionLabel(action)), h('small', { className: 'block text-xs text-zinc-400 mt-1', key: 'description' }, actionDescription(action))]),
					]))),
				]);
			})) : h('div', { className: 'rounded-xl bg-white/5 text-zinc-400 text-sm px-4 py-4 mt-4', key: 'no-groups' }, __('Prepare found no blocker groups.', 'ultracache')),
			activeCode ? h('div', { className: 'mt-5', key: 'active-group-units' }, [
				h('div', { className: 'flex items-center justify-between gap-3 mb-3', key: 'units-heading' }, [
					h('strong', { className: 'text-white', key: 'title' }, __('Affected units', 'ultracache') + ': ' + String(activeGroup.title || activeCode)),
					h('span', { className: 'text-xs text-zinc-400', key: 'range' }, formatNumber(visibleFrom) + '–' + formatNumber(visibleTo) + ' / ' + formatNumber(total)),
				]),
				items.length ? h('div', { className: 'uc-media-replacement-preview-table', key: 'items-table' }, [
					h('div', { className: 'uc-media-replacement-preview-row uc-media-replacement-preview-row--blockers uc-media-replacement-preview-row--head', key: 'head' }, [
						h('span', { key: 'attachment' }, __('Attachment', 'ultracache')),
						h('span', { key: 'scope' }, __('Scope', 'ultracache')),
						h('span', { key: 'source' }, __('Source', 'ultracache')),
						h('span', { key: 'format' }, __('Format', 'ultracache')),
						h('span', { key: 'destination' }, __('Destination', 'ultracache')),
						h('span', { key: 'detail' }, __('Details', 'ultracache')),
						h('span', { key: 'decision' }, __('File decision', 'ultracache')),
					]),
					items.map((item, index) => {
						const itemKey = 'item:' + String(item.id || index);
						const groupDecision = String(decisions[activeCode] || activeGroup.decision || '');
						const itemOverride = Object.prototype.hasOwnProperty.call(decisions, itemKey) ? String(decisions[itemKey] || '') : '';
						const itemActions = Array.isArray(item.actions) && item.actions.length ? item.actions : (Array.isArray(activeGroup.actions) ? activeGroup.actions : []);
						return h('div', { className: 'uc-media-replacement-preview-row uc-media-replacement-preview-row--blockers is-' + String(item.status || 'blocked'), key: 'blocker-item-' + String(item.id || index) }, [
							h('span', { key: 'attachment' }, [h('strong', { key: 'id' }, '#' + String(item.attachmentId || '—')), h('small', { key: 'status' }, String(item.status || 'blocked'))]),
							h('span', { key: 'scope' }, item.scope === 'intermediate' ? String(item.sizeName || __('Intermediate', 'ultracache')) : __('Main image', 'ultracache')),
							h('span', { key: 'source' }, h('code', {}, String(item.source || '—'))),
							h('span', { key: 'format' }, String(item.targetFormat || '').toUpperCase()),
							h('span', { key: 'destination' }, h('code', {}, String(item.destination || '—'))),
							h('span', { key: 'detail' }, [h('strong', { key: 'code' }, String(item.blockerCode || activeCode)), h('small', { key: 'text' }, String(item.detail || '—'))]),
							h('span', { key: 'decision' }, [
								h('select', {
								className: 'uc-select w-full',
								value: itemOverride,
								onChange: (event) => setMediaLibraryReplacementBlockerDecisions((current) => {
									const next = Object.assign({}, current || {});
									const value = String(event && event.target ? event.target.value : '');
									if (value) next[itemKey] = value; else delete next[itemKey];
									return next;
								}),
								disabled: mediaLibraryReplacementBusy || !groupDecision,
								key: 'select',
							}, [
								h('option', { value: '', key: 'group' }, groupDecision ? __('Use group decision', 'ultracache') + ': ' + actionLabel(groupDecision) : __('Choose group decision first', 'ultracache')),
								...itemActions.map((action) => h('option', { value: action, key: action }, fileActionLabel(action))),
							]),
							itemOverride ? h('small', { key: 'override' }, __('Individual override', 'ultracache')) : h('small', { key: 'group-label' }, __('Uses group decision', 'ultracache')),
						]),
						]);
					}),
				]) : h('div', { className: 'rounded-xl bg-white/5 text-zinc-400 text-sm px-4 py-4', key: 'empty-items' }, __('No affected units were returned for this page.', 'ultracache')),
				(total > limit || offset > 0 || hasMore) ? h('div', { className: 'uc-media-replacement-preview-pagination', key: 'pagination' }, [
					h('span', { key: 'page-summary' }, formatNumber(visibleFrom) + '–' + formatNumber(visibleTo) + ' / ' + formatNumber(total)),
					h('div', { className: 'uc-media-replacement-preview-pagination__actions', key: 'actions' }, [
						h('button', { type: 'button', className: 'uc-btn', onClick: () => changeMediaLibraryReplacementBlockersPage(previousOffset, activeCode), disabled: mediaLibraryReplacementBusy || offset <= 0, key: 'previous' }, __('Previous', 'ultracache')),
						h('button', { type: 'button', className: 'uc-btn uc-btn--primary', onClick: () => changeMediaLibraryReplacementBlockersPage(nextOffset, activeCode), disabled: mediaLibraryReplacementBusy || !hasMore, key: 'next' }, __('Next', 'ultracache')),
					]),
				]) : null,
			]) : null,
			h('div', { className: 'uc-media-replacement-warning__actions mt-5', key: 'footer' }, [
				h('button', { type: 'button', className: 'uc-btn', onClick: closeMediaLibraryReplacementBlockersModal, key: 'cancel' }, __('Cancel', 'ultracache')),
				h('button', { type: 'button', className: 'uc-btn uc-btn--primary', onClick: saveMediaLibraryReplacementBlockerDecisions, disabled: mediaLibraryReplacementBusy || groups.length === 0 || unresolved > 0, key: 'save' }, mediaLibraryReplacementBusy ? __('Saving decisions…', 'ultracache') : __('Save Decisions & Finish Prepare', 'ultracache')),
			]),
		]),
	]);
}

function renderMediaLibraryReplacementCleanupPreviewModal() {
	if (!mediaLibraryReplacementCleanupPreviewOpen) {
		return null;
	}

	const preview = mediaLibraryReplacementCleanupPreview && typeof mediaLibraryReplacementCleanupPreview === 'object' ? mediaLibraryReplacementCleanupPreview : {};
	const summary = preview.summary && typeof preview.summary === 'object' ? preview.summary : {};
	const items = Array.isArray(preview.items) ? preview.items : [];
	const totalItems = Number(summary.totalItems || 0);
	const candidateItems = Number(summary.candidateItems || preview.cleanupCandidates || 0);
	const blockedItems = Number(summary.blockedItems || preview.cleanupBlockedItems || 0);
	const cleanupDeletedItems = Number(summary.cleanupDeletedItems || 0);
	const cleanupFailedItems = Number(summary.cleanupFailedItems || 0);
	const uniqueOriginalFiles = Number(summary.uniqueOriginalFiles || 0);
	const databaseRefs = Number(summary.databaseRefs || 0);
	const databaseVerifiedRefs = Number(summary.databaseVerifiedRefs || 0);
	const potentialFreeBytes = Number(summary.potentialFreeBytes || preview.cleanupPotentialFreeBytes || 0);
	const replacementBytes = Number(summary.replacementBytes || 0);
	const orphanDuplicateFiles = Number(summary.orphanDuplicateFiles || 0);
	const orphanDuplicateBytes = Number(summary.orphanDuplicateBytes || 0);
	const cleanupReady = !!preview.cleanupReady;
	const returned = typeof preview.returned !== 'undefined' ? Number(preview.returned || 0) : items.length;
	const offset = typeof preview.offset !== 'undefined' ? Math.max(0, Number(preview.offset || 0)) : 0;
	const limit = typeof preview.limit !== 'undefined' ? Math.max(1, Number(preview.limit || 200)) : 200;
	const hasMorePreviewRows = !!preview.hasMore;
	const previousOffset = typeof preview.previousOffset !== 'undefined' ? Math.max(0, Number(preview.previousOffset || 0)) : Math.max(0, offset - limit);
	const nextOffset = typeof preview.nextOffset !== 'undefined' ? Math.max(0, Number(preview.nextOffset || 0)) : offset + returned;
	const visibleFrom = totalItems > 0 && returned > 0 ? offset + 1 : 0;
	const visibleTo = totalItems > 0 && returned > 0 ? offset + returned : 0;

	return h('div', {
		className: 'uc-media-test-modal uc-media-replacement-preview-modal',
		onClick: closeMediaLibraryReplacementCleanupPreviewModal,
		role: 'presentation',
		key: 'media-library-replacement-cleanup-preview-modal',
	}, [
		h('div', {
			className: 'uc-media-test-modal__dialog uc-support-modal__dialog uc-media-replacement-preview-modal__dialog',
			onClick: (event) => event.stopPropagation(),
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'uc-media-replacement-cleanup-preview-title',
			key: 'dialog',
		}, [
			h('button', {
				type: 'button',
				className: 'uc-media-test-modal__close uc-support-modal__close',
				onClick: closeMediaLibraryReplacementCleanupPreviewModal,
				'aria-label': __('Close cleanup preview', 'ultracache'),
				key: 'close',
			}, '×'),
			h('div', { className: 'uc-support-modal__eyebrow', key: 'eyebrow' }, __('Media Library replacement', 'ultracache')),
			h('h3', { className: 'uc-support-modal__title', id: 'uc-media-replacement-cleanup-preview-title', key: 'title' }, __('Cleanup preview', 'ultracache')),
			h('p', { className: 'uc-support-modal__text', key: 'summary' }, preview.hasCleanupPreview
				? (formatNumber(candidateItems) + ' original main/intermediate rows · ' + formatNumber(uniqueOriginalFiles || candidateItems) + ' unique files. ' + __('No files were deleted by this preview.', 'ultracache'))
				: (preview.message || __('No cleanup preview is available yet.', 'ultracache'))
			),
			preview.hasCleanupPreview ? h('div', { className: 'uc-media-replacement-preview-summary', key: 'cleanup-preview-summary' }, [
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'candidate-items' }, [h('span', { key: 'label' }, __('Cleanup candidates', 'ultracache')), h('strong', { key: 'value' }, formatNumber(candidateItems))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'blocked-items' }, [h('span', { key: 'label' }, __('Blocked rows', 'ultracache')), h('strong', { key: 'value' }, formatNumber(blockedItems))]),
			h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'deleted-items' }, [h('span', { key: 'label' }, __('Deleted rows', 'ultracache')), h('strong', { key: 'value' }, formatNumber(cleanupDeletedItems))]),
			h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'failed-items' }, [h('span', { key: 'label' }, __('Cleanup failed', 'ultracache')), h('strong', { key: 'value' }, formatNumber(cleanupFailedItems))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'db-ready' }, [h('span', { key: 'label' }, __('DB verified', 'ultracache')), h('strong', { key: 'value' }, formatNumber(databaseVerifiedRefs) + ' / ' + formatNumber(databaseRefs))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'potential-free' }, [h('span', { key: 'label' }, __('Potential free space', 'ultracache')), h('strong', { key: 'value' }, formatBytes(potentialFreeBytes))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'replacement-size' }, [h('span', { key: 'label' }, __('Replacement size', 'ultracache')), h('strong', { key: 'value' }, formatBytes(replacementBytes))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'orphan-duplicates' }, [h('span', { key: 'label' }, __('Orphan duplicate AVIF/WebP', 'ultracache')), h('strong', { key: 'value' }, formatNumber(orphanDuplicateFiles) + ' · ' + formatBytes(orphanDuplicateBytes))]),
				h('div', { className: 'uc-media-replacement-preview-summary__item', key: 'ready' }, [h('span', { key: 'label' }, __('Cleanup ready', 'ultracache')), h('strong', { key: 'value' }, cleanupReady ? __('Yes', 'ultracache') : __('No', 'ultracache'))]),
			]) : null,
			items.length ? h('div', { className: 'uc-media-replacement-preview-table', key: 'cleanup-preview-table' }, [
				h('div', { className: 'uc-media-replacement-preview-row uc-media-replacement-preview-row--head', key: 'head' }, [
					h('span', { key: 'attachment' }, __('Attachment', 'ultracache')),
					h('span', { key: 'old' }, __('Original file', 'ultracache')),
					h('span', { key: 'new' }, __('Replacement file', 'ultracache')),
					h('span', { key: 'metadata' }, __('Checks', 'ultracache')),
					h('span', { key: 'size' }, __('Space', 'ultracache')),
					h('span', { key: 'status' }, __('Status', 'ultracache')),
				]),
				items.map((item, index) => {
					const candidate = !!(item && item.cleanupCandidate);
					const title = item && item.title ? String(item.title) : ('#' + String(index + 1));
					const oldPath = item && item.oldRelativePath ? String(item.oldRelativePath) : '—';
					const newPath = item && item.newRelativePath ? String(item.newRelativePath) : '—';
					const checks = [
						(item && item.oldFileExists ? __('original exists', 'ultracache') : __('original missing', 'ultracache')),
						(item && item.newFileExists ? __('replacement exists', 'ultracache') : __('replacement missing', 'ultracache')),
						(item && item.metadataSwitched ? __('metadata switched', 'ultracache') : __('metadata mismatch', 'ultracache')),
					].join(' · ');
					const variantLabel = item && item.itemScope === 'intermediate' && item.sizeName ? ' · ' + __('Size', 'ultracache') + ': ' + String(item.sizeName) : ' · ' + __('Main file', 'ultracache');
					return h('div', { className: 'uc-media-replacement-preview-row ' + (candidate ? 'is-verified' : 'is-failed'), key: 'cleanup-preview-row-' + (item.id || index) }, [
						h('span', { key: 'attachment' }, [h('strong', { key: 'title' }, title), h('small', { key: 'id' }, 'ID ' + String(item.attachmentId || '—') + variantLabel)]),
						h('span', { key: 'old' }, h('code', {}, oldPath)),
						h('span', { key: 'new' }, h('code', {}, newPath)),
						h('span', { key: 'metadata' }, h('small', {}, checks)),
						h('span', { key: 'size' }, [h('strong', { key: 'old-size' }, formatBytes(item && item.oldSize ? item.oldSize : 0)), h('small', { key: 'new-size' }, (item && item.targetFormat ? String(item.targetFormat).toUpperCase() : __('Target', 'ultracache')) + ': ' + formatBytes(item && item.newSize ? item.newSize : 0))]),
						h('span', { key: 'status' }, [h('strong', { key: 'status-text' }, candidate ? __('candidate', 'ultracache') : __('blocked', 'ultracache')), item && item.reason ? h('small', { key: 'reason' }, String(item.reason)) : null]),
					]);
				}),
			]) : h('div', { className: 'rounded-xl bg-white/5 text-zinc-400 text-sm px-4 py-4', key: 'empty' }, __('No cleanup preview rows were returned for this page.', 'ultracache')),
			preview.hasCleanupPreview ? h('div', { className: 'uc-media-replacement-preview-pagination', key: 'cleanup-preview-pagination' }, [
				h('span', { className: 'uc-support-modal__text', key: 'range' }, totalItems > 0
					? __('Showing', 'ultracache') + ' ' + formatNumber(visibleFrom) + '–' + formatNumber(visibleTo) + ' ' + __('of', 'ultracache') + ' ' + formatNumber(totalItems) + ' ' + __('registry rows.', 'ultracache')
					: __('No registry rows are available for cleanup preview.', 'ultracache')
				),
				totalItems > 0 ? h('div', { className: 'uc-media-replacement-preview-pagination__buttons', key: 'buttons' }, [
					h('button', { type: 'button', className: 'uc-btn', onClick: () => changeMediaLibraryReplacementCleanupPreviewPage(previousOffset), disabled: mediaLibraryReplacementBusy || offset <= 0, key: 'previous' }, __('Previous', 'ultracache')),
					h('button', { type: 'button', className: 'uc-btn uc-btn--primary', onClick: () => changeMediaLibraryReplacementCleanupPreviewPage(nextOffset), disabled: mediaLibraryReplacementBusy || !hasMorePreviewRows, key: 'next' }, __('Next', 'ultracache')),
				]) : null,
			]) : null,
			preview.nextStep ? h('p', { className: 'uc-support-modal__text mt-3', key: 'next-step' }, preview.nextStep) : null,
		]),
	]);
}

		function renderMediaConversionTestControls() {
			return h('div', {
				className: 'mt-5 uc-media-batch-actions',
				style: { display: 'flex', flexDirection: 'column', gap: '12px' },
				key: 'media-conversion-test-actions'
			}, [
				h('div', {
					style: {
						display: 'grid',
						gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)',
						gap: '12px',
						alignItems: 'start',
						width: '100%',
					},
					key: 'media-conversion-test-button-row'
				}, [
					h('button', {
						type: 'button',
						className: 'uc-btn uc-btn--primary w-full text-white py-3 font-bold',
						style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
						onClick: runMediaConversionTest,
						disabled: busy || mediaConversionTestBusy || !mediaSupport.supported,
						key: 'run-image-conversion-test',
					}, mediaConversionTestBusy ? __('Testing images…', 'ultracache') : __('Image conversion test', 'ultracache')),
					h('button', {
						type: 'button',
						className: 'uc-btn w-full text-white py-3 font-bold',
						style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
						onClick: openMediaConversionTestModal,
						disabled: mediaConversionTestBusy,
						key: 'check-image-conversion-test',
					}, mediaConversionTestBusy ? __('Loading…', 'ultracache') : __('Check test', 'ultracache')),
				]),
				h('div', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'media-conversion-test-help' }, __('Runs a 10-image Media Library sample through the existing AVIF/WebP converter, clears the previous test files, and stores the latest result for review. The sample uses up to 3 PNG images and fills the rest with JPG images; if no PNG exists, it uses JPG images only.', 'ultracache')),
			]);
		}

		function renderMediaLibraryReplacementControls() {
			return h('div', { key: 'media-replacement-actions' }, [
				h('div', {
					className: 'uc-media-batch-actions',
					style: { display: 'flex', flexDirection: 'column', gap: '12px' },
					key: 'media-library-replacement-actions'
				}, [
					(() => {
						const deleteOriginalsDisabledReason = getMediaLibraryReplacementDeleteDisabledReason();
						const replacementRecovery = getMediaLibraryReplacementRecoveryStatus();
						const workflowBusy = busy || mediaConversionTestBusy || mediaLibraryReplacementBusy || !isMediaLibraryReplacementRunnerReady() || isMediaLibraryReplacementOwnedByAnotherDashboard();
						const prepareState = getMediaLibraryReplacementWorkflowButtonState('prepare', deleteOriginalsDisabledReason);
						const prepareStatus = mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.prepare && typeof mediaLibraryReplacementStatus.prepare === 'object'
							? mediaLibraryReplacementStatus.prepare
							: {};
						const prepareFailureGuidance = __('Preparation failed. Open Advanced / Manual Recovery and click “Restart Replacement Plan”, then run “Prepare Replacement” again.', 'ultracache');
						const doState = getMediaLibraryReplacementWorkflowButtonState('do', deleteOriginalsDisabledReason);
						const doStatus = mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.do && typeof mediaLibraryReplacementStatus.do === 'object'
							? mediaLibraryReplacementStatus.do
							: {};
						const verifyState = getMediaLibraryReplacementWorkflowButtonState('verify', deleteOriginalsDisabledReason);
						const deleteState = getMediaLibraryReplacementWorkflowButtonState('delete', deleteOriginalsDisabledReason);
						const replacementOverview = __('Replace existing JPG/PNG Media Library files and references with the selected image replacement format. Complete the steps in order: prepare the replacement, apply the changes, verify the result, and delete the original files when verification is complete.', 'ultracache');
						const replacementFormatLocked = !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.formatLocked);
						const replacementPolicyChanged = !!(mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.replacementPolicyChanged && !replacementFormatLocked);
						const replacementFormatSource = replacementFormatLocked && mediaLibraryReplacementStatus.formatLockTarget
							? mediaLibraryReplacementStatus.formatLockTarget
							: ((settings && settings.mediaReplacementFormat) || 'webp');
						const replacementTargetFormat = ('avif' === String(replacementFormatSource).toLowerCase()) ? 'avif' : 'webp';
						const replacementTitle = (typeof renderLabelWithHelp === 'function')
							? renderLabelWithHelp(__('Media Library Replacement', 'ultracache'), replacementOverview)
							: __('Media Library Replacement', 'ultracache');
						return h('div', { className: 'space-y-3', key: 'media-library-replacement-simple-workflow' }, [
							h('div', { className: 'flex items-center justify-between py-4', key: 'media-library-replacement-workflow-introduction' }, [
								h('div', { key: 'left' }, [
									h('div', { className: 'text-sm font-medium text-white', key: 'media-library-replacement-workflow-title' }, replacementTitle),
									h('div', { className: 'text-xs text-zinc-500', key: 'media-library-replacement-workflow-overview' }, replacementOverview),
								]),
							]),
							h('div', { className: 'uc-media-replacement-format-field', key: 'media-replacement-format-wrap' }, [
								h(SelectField, {
									label: __('Image replacement format', 'ultracache'),
									description: __('Choose the target format that replaces existing JPG/PNG Media Library files and references.', 'ultracache'),
									value: replacementTargetFormat,
									onChange: (value) => updateSetting('mediaReplacementFormat', value),
									disabled: workflowBusy || replacementFormatLocked,
									options: [
										{ value: 'avif', label: (mediaSupport.imagick_avif || mediaSupport.gd_avif) ? __('AVIF Format', 'ultracache') : __('AVIF Format (Self-Test Failed)', 'ultracache') },
										{ value: 'webp', label: __('WebP Format', 'ultracache') },
									],
								}),
								replacementFormatLocked ? h('div', { className: 'text-xs text-amber-300 mt-2', key: 'media-replacement-format-locked' },
									String(mediaLibraryReplacementStatus.formatLockMessage || __('Image replacement format is locked until the current destructive replacement workflow is complete or recovered.', 'ultracache'))
								) : null,
								replacementPolicyChanged
									? h('div', { className: 'text-xs text-amber-300 mt-2', key: 'media-replacement-format-changed' }, __('The image replacement format changed after the previous readiness or Prepare plan. Run Prepare again to build a new plan for the selected format.', 'ultracache'))
									: null,
							]),
							h('div', {
								style: { display: 'flex', flexDirection: 'column', gap: '12px', width: '100%' },
								key: 'media-library-replacement-primary-workflow-column',
							}, [
								h('div', { className: 'space-y-1', key: 'prepare-media-library-replacement-workflow-group' }, [
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementWorkflowButtonClass(prepareState),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: () => prepareMediaLibraryReplacementWorkflow(replacementPolicyChanged),
										disabled: prepareState.disabled,
										title: prepareState.reason || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.workflowMessage ? String(mediaLibraryReplacementStatus.workflowMessage) : ''),
										key: 'prepare-media-library-replacement-workflow',
									}, getMediaLibraryReplacementPrepareLabel()),
									prepareStatus.prepareFailed ? h('p', { className: 'text-xs text-amber-300 leading-relaxed', key: 'prepare-media-library-replacement-failure-guidance' }, prepareFailureGuidance) : null,
									h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'prepare-media-library-replacement-workflow-help' }, __('Scans the Media Library, verifies that the required replacement files are ready, and prepares metadata, database, and active-theme CSS changes. No live references are changed during this step.', 'ultracache')),
								]),
								h('div', { className: 'space-y-1', key: 'decide-media-library-replacement-blockers-group' }, [
									h('button', {
										type: 'button',
										className: 'uc-btn w-full text-white py-3 font-bold' + (!prepareStatus.decisionsRequired ? ' opacity-50 cursor-not-allowed grayscale' : ''),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: openMediaLibraryReplacementBlockersModal,
										disabled: workflowBusy || !prepareStatus.decisionsRequired,
										title: prepareStatus.decisionsRequired ? String(prepareStatus.message || __('Review and resolve every blocker group.', 'ultracache')) : __('Prepare has no unresolved blocker decisions.', 'ultracache'),
										key: 'decide-media-library-replacement-blockers',
									}, prepareStatus.decisionsRequired
										? __('Decide Blockers', 'ultracache') + (Number(prepareStatus.unresolvedBlockerGroups || 0) > 0 ? ' (' + formatNumber(prepareStatus.unresolvedBlockerGroups) + ')' : '')
										: __('No Blocker Decisions', 'ultracache')),
									h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'decide-media-library-replacement-blockers-help' }, __('When Prepare finds blocked units or destination collisions, choose one bulk decision for each blocker group. Apply remains disabled until every group is resolved.', 'ultracache')),
								]),
								h('div', { className: 'space-y-1', key: 'do-media-library-replacement-workflow-group' }, [
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementWorkflowButtonClass(doState),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: doMediaLibraryReplacementWorkflow,
										disabled: doState.disabled,
										title: doState.reason || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.workflowMessage ? String(mediaLibraryReplacementStatus.workflowMessage) : ''),
										key: 'do-media-library-replacement-workflow',
									}, getMediaLibraryReplacementDoLabel()),
									h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'do-media-library-replacement-workflow-help' }, __('Applies the prepared changes to attachment metadata, database references, and active-theme CSS. The website starts using the replacement files after this step.', 'ultracache')),
								]),
								doStatus.doFailed ? h('div', {
									className: 'space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3',
									key: 'media-library-replacement-do-recovery-controls',
								}, [
									h('div', { className: 'text-xs text-amber-200 leading-relaxed', key: 'media-library-replacement-do-recovery-message' },
										doStatus.message || __('Replacement stopped after preserving the completed work. Choose how to continue.', 'ultracache')
									),
									doStatus.canContinue ? h('button', {
										type: 'button',
										className: 'uc-btn w-full text-white py-3 font-bold',
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: () => recoverMediaLibraryReplacementWorkflow('continue'),
										disabled: workflowBusy,
										key: 'continue-media-library-replacement',
									}, __('Continue Replacement', 'ultracache')) : null,
									doStatus.canContinue ? h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'continue-media-library-replacement-help' },
										__('Keeps every completed metadata and database change, excludes UltraCache internal state, and retries only unresolved replacement rows.', 'ultracache')
									) : null,
									doStatus.canRestartDatabase ? h('button', {
										type: 'button',
										className: 'uc-btn w-full text-white py-3 font-bold',
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: () => recoverMediaLibraryReplacementWorkflow('restart_database'),
										disabled: workflowBusy,
										key: 'restart-media-library-replacement-database',
									}, __('Restart Database Replacement', 'ultracache')) : null,
									doStatus.canRestartDatabase ? h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'restart-media-library-replacement-database-help' },
										__('Preserves completed metadata and database changes, rebuilds only the unresolved database plan from the current database state, and then resumes replacement.', 'ultracache')
									) : null,
								]) : null,
								h('div', { className: 'space-y-1', key: 'verify-media-library-replacement-workflow-group' }, [
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementWorkflowButtonClass(verifyState),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: verifyMediaLibraryReplacementWorkflow,
										disabled: verifyState.disabled,
										title: verifyState.reason || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.workflowMessage ? String(mediaLibraryReplacementStatus.workflowMessage) : ''),
										key: 'verify-media-library-replacement-workflow',
									}, getMediaLibraryReplacementVerifyLabel()),
									h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'verify-media-library-replacement-workflow-help' }, __('Checks that replacement files exist and that attachment metadata, database references, and active-theme CSS references were updated correctly.', 'ultracache')),
								]),
								h('div', { className: 'space-y-1', key: 'delete-originals-media-library-replacement-workflow-group' }, [
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementWorkflowButtonClass(deleteState),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: deleteMediaLibraryReplacementOriginalsWorkflow,
										disabled: deleteState.disabled,
										title: deleteState.reason || (mediaLibraryReplacementStatus && mediaLibraryReplacementStatus.workflowMessage ? String(mediaLibraryReplacementStatus.workflowMessage) : ''),
										key: 'delete-originals-media-library-replacement-workflow',
									}, getMediaLibraryReplacementDeleteLabel()),
									h('p', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'delete-originals-media-library-replacement-workflow-help' }, __('Permanently deletes the original JPG/PNG files after the replacement has been applied and verified.', 'ultracache')),
								]),
							]),
							replacementRecovery.activeElsewhere ? h('div', { className: 'text-xs text-amber-300 leading-relaxed', key: 'media-library-replacement-active-elsewhere' }, __('This workflow is currently owned by another dashboard. Status refreshes automatically; Resume becomes available after that dashboard pauses or its lease expires.', 'ultracache')) : null,
							replacementRecovery.resumable ? h('div', { className: 'text-xs text-emerald-300 leading-relaxed', key: 'media-library-replacement-resumable' }, __('A paused server workflow was recovered. Use the active workflow button to continue from the saved phase and cursor.', 'ultracache')) : null,
							!isMediaLibraryReplacementReadinessRunnerReady() ? h('div', { className: 'text-xs text-zinc-500 leading-relaxed', key: 'media-library-replacement-help' }, getMediaLibraryReplacementRunnerUnavailableMessage()) : null,
						h('details', { className: 'pt-2', key: 'media-library-replacement-advanced-controls' }, [
								h('summary', { className: 'cursor-pointer text-sm font-semibold text-zinc-200', style: { paddingBottom: '10px' } }, __('Advanced / Manual Recovery', 'ultracache')),
								h('div', { className: 'mt-3 uc-media-batch-actions', style: { display: 'flex', flexDirection: 'column', gap: '12px' } }, [
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementActionClass('prepare', 'uc-btn w-full text-white py-3 font-bold'),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: () => prepareMediaLibraryReplacementFoundation(false),
										disabled: workflowBusy || prepareState.disabled,
										title: prepareState.reason || '',
										key: 'prepare-media-library-replacement',
									}, mediaLibraryReplacementBusy ? __('Preparing replacement registry…', 'ultracache') : __('Prepare / Resume Library Replacement', 'ultracache')),
									h('button', {
										type: 'button',
										className: getMediaLibraryReplacementActionClass('restart', 'uc-btn w-full text-white py-3 font-bold'),
										style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' },
										onClick: restartMediaLibraryReplacementWorkflow,
										disabled: workflowBusy || !replacementRecovery.canRestart,
										title: replacementRecovery.restartBlockedReason || '',
										key: 'restart-media-library-replacement',
									}, mediaLibraryReplacementBusy ? __('Restarting replacement plan…', 'ultracache') : __('Restart Replacement Plan', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('preview', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: openMediaLibraryReplacementPreviewModal, disabled: workflowBusy, key: 'preview-media-library-replacement' }, mediaLibraryReplacementBusy ? __('Loading replacement preview…', 'ultracache') : __('Preview Library Mapping', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('copy', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: copyMediaLibraryReplacementFiles, disabled: workflowBusy, key: 'copy-media-library-replacement-files' }, mediaLibraryReplacementBusy ? __('Copying replacement files…', 'ultracache') : __('Copy Replacement Files', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('metadata_prepare', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: prepareMediaLibraryReplacementMetadataUpdates, disabled: workflowBusy, key: 'prepare-media-library-replacement-metadata' }, mediaLibraryReplacementBusy ? __('Preparing metadata plans…', 'ultracache') : __('Prepare Metadata Updates', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('metadata_apply', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: applyMediaLibraryReplacementMetadataUpdates, disabled: workflowBusy, key: 'apply-media-library-replacement-metadata' }, mediaLibraryReplacementBusy ? __('Switching metadata…', 'ultracache') : __('Switch Attachment Metadata', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('refs_scan', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: scanMediaLibraryReplacementReferences, disabled: workflowBusy, key: 'scan-media-library-replacement-references' }, mediaLibraryReplacementBusy ? __('Scanning references…', 'ultracache') : __('Scan Database References', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('refs_match', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: matchMediaLibraryReplacementReferences, disabled: workflowBusy, key: 'match-media-library-replacement-references' }, mediaLibraryReplacementBusy ? __('Matching references…', 'ultracache') : __('Match Database References', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('db_preview', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: openMediaLibraryReplacementDbPreviewModal, disabled: workflowBusy, key: 'preview-media-library-replacement-db-replacements' }, mediaLibraryReplacementBusy ? __('Loading DB preview…', 'ultracache') : __('Preview DB Replacements', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('db_apply', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: applyMediaLibraryReplacementDatabaseReplacements, disabled: workflowBusy, key: 'apply-media-library-replacement-db-replacements' }, mediaLibraryReplacementBusy ? __('Applying DB replacements…', 'ultracache') : __('Apply DB Replacements', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('db_verify', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: verifyMediaLibraryReplacementDatabaseReplacements, disabled: workflowBusy, key: 'verify-media-library-replacement-db-replacements' }, mediaLibraryReplacementBusy ? __('Verifying DB replacements…', 'ultracache') : __('Verify DB Replacements', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('theme_css_scan', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: scanMediaLibraryReplacementThemeCssReferences, disabled: workflowBusy, key: 'scan-media-library-replacement-theme-css' }, mediaLibraryReplacementBusy ? __('Scanning theme CSS…', 'ultracache') : __('Scan Theme CSS References', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('theme_css_preview', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: previewMediaLibraryReplacementThemeCssReplacements, disabled: workflowBusy, key: 'preview-media-library-replacement-theme-css' }, mediaLibraryReplacementBusy ? __('Loading theme CSS preview…', 'ultracache') : __('Preview Theme CSS Replacements', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('theme_css_apply', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: applyMediaLibraryReplacementThemeCssReplacements, disabled: workflowBusy, key: 'apply-media-library-replacement-theme-css' }, mediaLibraryReplacementBusy ? __('Applying theme CSS…', 'ultracache') : __('Apply Theme CSS Replacements', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('theme_css_verify', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: verifyMediaLibraryReplacementThemeCssReplacements, disabled: workflowBusy, key: 'verify-media-library-replacement-theme-css' }, mediaLibraryReplacementBusy ? __('Verifying theme CSS…', 'ultracache') : __('Verify Theme CSS Replacements', 'ultracache')),
									h('button', { type: 'button', className: 'uc-btn w-full text-white py-3 font-bold', style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: rollbackMediaLibraryReplacementDatabaseReplacements, disabled: workflowBusy, key: 'rollback-media-library-replacement-db-replacements' }, mediaLibraryReplacementBusy ? __('Rolling back DB replacements…', 'ultracache') : __('Rollback DB Replacements', 'ultracache')),
									h('button', { type: 'button', className: 'uc-btn w-full text-white py-3 font-bold', style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: rollbackMediaLibraryReplacementMetadataUpdates, disabled: workflowBusy, key: 'rollback-media-library-replacement-metadata' }, mediaLibraryReplacementBusy ? __('Rolling back metadata…', 'ultracache') : __('Rollback Attachment Metadata', 'ultracache')),
									h('button', { type: 'button', className: getMediaLibraryReplacementActionClass('cleanup_preview', 'uc-btn w-full text-white py-3 font-bold'), style: { width: '100%', minWidth: 0, whiteSpace: 'nowrap' }, onClick: openMediaLibraryReplacementCleanupPreviewModal, disabled: workflowBusy, key: 'preview-media-library-replacement-cleanup' }, mediaLibraryReplacementBusy ? __('Loading cleanup preview…', 'ultracache') : __('Cleanup Preview', 'ultracache')),
											]),
							]),
						]);
					})(),
					mediaLibraryReplacementStatus ? h('div', { className: 'text-xs text-zinc-400 leading-relaxed space-y-1', key: 'media-library-replacement-status' }, [
						h('div', { key: 'media-library-replacement-message' }, mediaLibraryReplacementStatus.message || ''),
						mediaLibraryReplacementStatus.readiness ? h('div', { key: 'media-library-replacement-readiness' }, [
							__('Prepare file readiness', 'ultracache') + ': ',
							mediaLibraryReplacementStatus.readiness.inventoryComplete
								? (mediaLibraryReplacementStatus.readiness.readyForReplacement ? __('Ready', 'ultracache') : __('Blocked', 'ultracache'))
								: (mediaLibraryReplacementStatus.readiness.status === 'scanning'
									? (Number(mediaLibraryReplacementStatus.readiness.verificationPass || 0) > 0 ? __('Generating', 'ultracache') : __('Scanning', 'ultracache'))
									: (mediaLibraryReplacementStatus.readiness.status === 'paused' ? __('Paused', 'ultracache') : __('Not scanned', 'ultracache'))),
							' · ',
							String(mediaLibraryReplacementStatus.readiness.readyVariants || 0) + '/' + String(mediaLibraryReplacementStatus.readiness.requiredVariants || 0) + ' ' + String(mediaLibraryReplacementStatus.readiness.targetFormat || '').toUpperCase()
								+ (Number(mediaLibraryReplacementStatus.readiness.generatedUnits || 0) > 0 ? ' · ' + String(mediaLibraryReplacementStatus.readiness.generatedUnits || 0) + ' ' + __('generated unit(s)', 'ultracache') : ''),
						]) : null,
						mediaLibraryReplacementStatus.readiness && mediaLibraryReplacementStatus.readiness.inventoryComplete && !mediaLibraryReplacementStatus.readiness.readyForReplacement && mediaLibraryReplacementStatus.readiness.blockerSamples && mediaLibraryReplacementStatus.readiness.blockerSamples.length ? h('div', { key: 'media-library-replacement-primary-blocker', className: 'text-amber-300' }, (() => {
							const blocker = mediaLibraryReplacementStatus.readiness.blockerSamples[0] || {};
							const scope = blocker.scope === 'intermediate' ? (blocker.sizeName || __('Intermediate image', 'ultracache')) : __('Main image', 'ultracache');
							const detail = blocker.failureDetail || blocker.skipDetail || blocker.reason || __('Required output is not ready.', 'ultracache');
							const blockerCode = blocker.failureCode || blocker.skippedReason || '';
							const code = blockerCode ? String(blockerCode) + ': ' : '';
							return String(scope) + ' · #' + String(blocker.attachmentId || 0) + ' · ' + String(blocker.source || '') + ' · ' + code + String(detail);
						})()) : null,
						mediaLibraryReplacementStatus.startGuard ? h('div', { key: 'media-library-replacement-start-guard' }, [
							__('Start guard', 'ultracache') + ': ',
							mediaLibraryReplacementStatus.startGuard.allowed ? __('Ready', 'ultracache') : __('Blocked', 'ultracache'),
							mediaLibraryReplacementStatus.startGuard.blockers && mediaLibraryReplacementStatus.startGuard.blockers.length
								? ' · ' + String(mediaLibraryReplacementStatus.startGuard.blockers.length) + ' ' + __('blocker(s)', 'ultracache')
								: '',
						]) : null,
						mediaLibraryReplacementStatus.preDoGuard && Math.max(0, Number(mediaLibraryReplacementStatus.registryRows || 0)) > 0 && (!mediaLibraryReplacementStatus.do || ['', 'prepare_complete'].includes(String(mediaLibraryReplacementStatus.do.activeStep || ''))) ? h('div', { key: 'media-library-replacement-pre-do-guard' }, [
							__('Pre-Do guard', 'ultracache') + ': ',
							mediaLibraryReplacementStatus.preDoGuard.allowed ? __('Ready', 'ultracache') : __('Blocked', 'ultracache'),
							mediaLibraryReplacementStatus.preDoGuard.blockers && mediaLibraryReplacementStatus.preDoGuard.blockers.length
								? ' · ' + String(mediaLibraryReplacementStatus.preDoGuard.blockers.length) + ' ' + __('blocker(s)', 'ultracache')
								: '',
						]) : null,
						mediaLibraryReplacementStatus.prepare && (mediaLibraryReplacementStatus.prepare.activeStep || mediaLibraryReplacementStatus.prepare.prepareComplete || mediaLibraryReplacementStatus.prepare.prepareFailed) ? h('div', { key: 'media-library-replacement-prepare-status' }, [
							__('Prepare', 'ultracache') + ': ',
							mediaLibraryReplacementStatus.prepare.prepareComplete
								? __('Complete', 'ultracache')
								: (mediaLibraryReplacementStatus.prepare.prepareFailed ? __('Failed', 'ultracache') : String(mediaLibraryReplacementStatus.prepare.activeStep || __('Pending', 'ultracache'))),
							' · ' + String(mediaLibraryReplacementStatus.prepare.processed || 0) + '/' + String(mediaLibraryReplacementStatus.prepare.total || 0),
						]) : null,
						mediaLibraryReplacementStatus.prepare && String(mediaLibraryReplacementStatus.prepare.activeStep || '') === 'database_scan' ? h('div', { key: 'media-library-replacement-database-scan-live' }, [
							__('Database scan', 'ultracache') + ': ',
							String(mediaLibraryReplacementStatus.prepare.databaseColumnsScanned || 0) + '/' + String(mediaLibraryReplacementStatus.prepare.databaseColumnsTotal || 0),
							' · ' + String(mediaLibraryReplacementStatus.prepare.databaseScanTable || '—') + '.' + String(mediaLibraryReplacementStatus.prepare.databaseScanColumn || '—'),
							' · ' + __('rows', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.prepare.databaseRowsScanned || 0),
							' · ' + __('last batch', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.prepare.databaseScanLastBatchRows || 0),
							' · ' + __('cursor', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.prepare.databaseScanPagination === 'keyset' ? (mediaLibraryReplacementStatus.prepare.databaseScanCursorPrimary || '—') : (mediaLibraryReplacementStatus.prepare.databaseScanCursorOffset || 0)),
							' · ' + String(mediaLibraryReplacementStatus.prepare.databaseScanQueryMs || 0) + ' ms',
						]) : null,
						mediaLibraryReplacementStatus.do && (mediaLibraryReplacementStatus.do.activeStep || mediaLibraryReplacementStatus.do.doComplete || mediaLibraryReplacementStatus.do.doFailed) ? h('div', { key: 'media-library-replacement-do-status' }, [
							__('Do', 'ultracache') + ': ',
							mediaLibraryReplacementStatus.do.doComplete
								? __('Complete', 'ultracache')
								: (mediaLibraryReplacementStatus.do.doFailed ? __('Failed', 'ultracache') : String(mediaLibraryReplacementStatus.do.activeStep || __('Pending', 'ultracache'))),
							' · ' + String(mediaLibraryReplacementStatus.do.processed || 0) + '/' + String(mediaLibraryReplacementStatus.do.total || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.progressPercent !== 'undefined') ? h('div', { key: 'media-library-replacement-progress' }, [
							__('Progress', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.progressPercent || 0) + '%',
							getMediaLibraryReplacementProgressSuffix(mediaLibraryReplacementStatus.status, mediaLibraryReplacementStatus.hasMore),
						]) : null,
						(typeof mediaLibraryReplacementStatus.scanned !== 'undefined') ? h('div', { key: 'media-library-replacement-counts' }, [
							__('Scanned', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.scanned || 0) + (mediaLibraryReplacementStatus.totalCandidates ? '/' + String(mediaLibraryReplacementStatus.totalCandidates) : ''),
							' · ',
							__('Eligible', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.matched || 0),
							' · ',
							__('Missing generated', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.missingGenerated || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.intermediateScanned !== 'undefined') ? h('div', { key: 'media-library-replacement-intermediate' }, [
							__('Intermediate scanned', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.intermediateScanned || 0),
							' · ',
							__('Registered', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.intermediateMatched || 0),
							' · ',
							__('Existing', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.intermediateExisting || 0),
							' · ',
							__('Missing generated', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.intermediateMissing || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.targetTotalSize !== 'undefined') ? h('div', { key: 'media-library-replacement-size' }, [
							__('Original', 'ultracache') + ': ' + formatBytes(mediaLibraryReplacementStatus.oldTotalSize || 0),
							' · ',
							(mediaLibraryReplacementStatus.targetFormat ? String(mediaLibraryReplacementStatus.targetFormat).toUpperCase() : __('Target', 'ultracache')) + ': ' + formatBytes(mediaLibraryReplacementStatus.targetTotalSize || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.copied !== 'undefined') ? h('div', { key: 'media-library-replacement-copy' }, [
							__('Copied', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.copied || 0),
							' · ',
							__('Remaining', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.remainingToCopy || 0),
							' · ',
							__('Copied size', 'ultracache') + ': ' + formatBytes(mediaLibraryReplacementStatus.copiedBytes || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.metadataPrepared !== 'undefined') ? h('div', { key: 'media-library-replacement-metadata' }, [
							__('Metadata plans', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.metadataPrepared || 0),
							' · ',
							__('Metadata switched', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.metadataUpdated || 0),
							' · ',
							__('Remaining', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.remainingMetadata || 0),
							' · ',
							__('Failed', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.metadataFailed || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.referencesFound !== 'undefined') ? h('div', { key: 'media-library-replacement-references' }, [
							__('Reference scan', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.refsScanned || 0),
							' · ',
							__('Remaining', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.remainingRefsScan || 0),
							' · ',
							__('Found', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.referencesFound || 0),
							(typeof mediaLibraryReplacementStatus.matchedRefs !== 'undefined') ? ' · ' : '',
							(typeof mediaLibraryReplacementStatus.matchedRefs !== 'undefined') ? __('Matched', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.matchedRefs || 0) : '',
							' · ',
							__('Serialized', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.serializedRefs || 0),
							' · ',
							__('JSON', 'ultracache') + ': ' + String(mediaLibraryReplacementStatus.jsonRefs || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.replacedRefs !== 'undefined') ? h('div', { key: 'media-library-replacement-db-apply' }, [
							__('DB replaced', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.replacedRefs || 0),
							' · ',
							__('Pending', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.pendingRefs || 0),
							' · ',
							__('Failed', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.failedRefs || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.verifiedRefs !== 'undefined') ? h('div', { key: 'media-library-replacement-db-verify' }, [
							__('DB verified', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.verifiedRefs || 0),
							' · ',
							__('Pending verification', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.pendingVerifyRefs || 0),
							' · ',
							__('Verify failed', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.verifyFailedRefs || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.restoredRefs !== 'undefined') ? h('div', { key: 'media-library-replacement-db-rollback' }, [
							__('DB restored', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.restoredRefs || 0),
							' · ',
							__('Pending rollback', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.pendingRollbackRefs || 0),
							' · ',
							__('Rollback failed', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.rollbackFailedRefs || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.metadataRestored !== 'undefined') ? h('div', { key: 'media-library-replacement-metadata-rollback' }, [
							__('Metadata restored', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.metadataRestored || 0),
							' · ',
							__('Pending metadata rollback', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.pendingMetadataRollback || 0),
							' · ',
							__('Metadata rollback failed', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.metadataRollbackFailed || 0),
						]) : null,
						(typeof mediaLibraryReplacementStatus.themeCssRefs !== 'undefined') ? h('div', { key: 'media-library-replacement-theme-css' }, [
							__('Theme CSS refs', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.themeCssRefs || 0),
							' · ',
							__('Files', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.themeCssFilesWithRefs || 0),
							' · ',
							__('Pending', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.themeCssPendingRefs || 0),
							' · ',
							__('Applied', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.themeCssAppliedRefs || 0),
							' · ',
							__('Verified', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.themeCssVerifiedRefs || 0),
							' · ',
							__('Failed', 'ultracache') + ': ' + formatNumber((mediaLibraryReplacementStatus.themeCssFailedRefs || 0) + (mediaLibraryReplacementStatus.themeCssVerifyFailedRefs || 0)),
						]) : null,
						(typeof mediaLibraryReplacementStatus.cleanupCandidates !== 'undefined') ? h('div', { key: 'media-library-replacement-cleanup-preview' }, [
							__('Cleanup candidates', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.cleanupCandidates || 0),
							' · ',
							__('Blocked', 'ultracache') + ': ' + formatNumber(mediaLibraryReplacementStatus.cleanupBlockedItems || 0),
							' · ',
							__('Potential free', 'ultracache') + ': ' + formatBytes(mediaLibraryReplacementStatus.cleanupPotentialFreeBytes || 0),
						]) : null,
						mediaLibraryReplacementStatus.nextStep ? h('div', { key: 'media-library-replacement-next-step' }, mediaLibraryReplacementStatus.nextStep) : null,
					]) : null,
				]),
			]);
		}

		return {
			closeMediaLibraryReplacementPreviewModal,
			closeMediaLibraryReplacementDbPreviewModal,
			closeMediaLibraryReplacementBlockersModal,
			renderMediaLibraryReplacementWarningModal,
			renderMediaLibraryReplacementPreviewModal,
			renderMediaLibraryReplacementDbPreviewModal,
			renderMediaLibraryReplacementBlockersModal,
			renderMediaLibraryReplacementCleanupPreviewModal,
			renderMediaConversionTestControls,
			renderMediaLibraryReplacementControls,
		};
	}

	admin.define('mediaReplacementUi', {
		createMediaReplacementUi,
	});
})(window);
