/* UltraCache Admin - Dashboard settings actions and persistence orchestration */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before dashboard-settings-actions.js.');
	}

	function createDashboardSettingsActions(context) {
		if (!context || typeof context !== 'object') {
			throw new Error('UltraCache dashboard settings actions require a context object.');
		}

		const {
			CRITICAL_SETTING_KEYS,
			PERFORMANCE_PROFILES,
			getPerformanceProfilePatch,
			splitProfileObjectCachePatch,
			buildSettingsExportPayload,
			getTransferableSettingsFromImport,
			triggerFileDownload,
			apiRequest,
			applyDashboardPayload,
			settingsRef,
			committedSettingsRef,
			lastSettingsSavePromiseRef,
			setSettings,
			setAdvancedForm,
			setRedisForm,
			setInspectResult,
			setBusy,
			pushToast,
			sleep,
			enqueueUiOperation,
			getProfileQueryAllowlistPatch,
			getProfileObjectCachePatch,
			populateDeferDelayExclusionDefaults,
			advancedForm,
			settings,
			ultracache,
			busy,
			importFileInputRef,
			initialDefaults,
			__,
		} = context;

		function isCriticalSettingsPatch(patch) {
			if (!patch || typeof patch !== 'object') {
				return false;
			}
			return Object.keys(patch).some((key) => CRITICAL_SETTING_KEYS.indexOf(key) !== -1);
		}

		function getSettingsResponseKeysForPatch(patch) {
			const keys = {};
			Object.keys(patch || {}).forEach((key) => {
				keys[key] = true;
			});

			if (keys.cacheFreshTtlMinutes || keys.cacheMaxStaleMinutes) {
				keys.cacheFreshTtlMinutes = true;
				keys.cacheMaxStaleMinutes = true;
			}
			if (keys.pageCssBundleOnEntryEnabled || keys.pageAsyncBundleOnEntryEnabled) {
				keys.pageCssBundleOnEntryEnabled = true;
				keys.pageAsyncBundleOnEntryEnabled = true;
			}
			if (keys.redisPassword || keys.clearRedisPassword) {
				keys.redisPassword = true;
				keys.redisPasswordConfigured = true;
				keys.redisPasswordManaged = true;
				keys.redisPasswordExternal = true;
			}
			if (keys.varnishCliKey || keys.clearVarnishCliKey) {
				keys.varnishCliKey = true;
				keys.varnishCliKeyConfigured = true;
				keys.varnishCliKeyManaged = true;
				keys.varnishCliKeyExternal = true;
			}

			return Object.keys(keys);
		}

		function applyServerSettings(responseSettings, options) {
			if (!responseSettings || typeof responseSettings !== 'object') {
				return;
			}

			const committed = Object.assign({}, responseSettings);
			committedSettingsRef.current = committed;

			const opts = options || {};
			let nextSettings = null;

			if (opts.fullReplace) {
				nextSettings = committed;
			} else if (opts.patch && typeof opts.patch === 'object') {
				const responseKeys = getSettingsResponseKeysForPatch(opts.patch);
				nextSettings = Object.assign({}, settingsRef.current || {});
				responseKeys.forEach((key) => {
					if (Object.prototype.hasOwnProperty.call(committed, key)) {
						nextSettings[key] = committed[key];
					}
				});
			} else if (Array.isArray(opts.keys) && opts.keys.length) {
				nextSettings = Object.assign({}, settingsRef.current || {});
				opts.keys.forEach((key) => {
					if (Object.prototype.hasOwnProperty.call(committed, key)) {
						nextSettings[key] = committed[key];
					}
				});
			} else {
				return;
			}

			settingsRef.current = nextSettings;
			setSettings(nextSettings);
		}

		function applySettingsSaveResponse(response, patch, options) {
			const opts = options || {};
			if (response && response.settings) {
				applyServerSettings(response.settings, opts.fullReplace ? { fullReplace: true } : { patch: patch || {} });
			}
			applyDashboardPayload(response || {}, { skipSettings: true });
		}

		async function saveSettingsPatch(patch, options) {
			const settingsPatch = Object.assign({}, patch || {});
			const response = await apiRequest('save_settings', { settings_json: JSON.stringify(settingsPatch) });
			applySettingsSaveResponse(response || {}, settingsPatch, options || {});
			return response;
		}

		async function waitForSettingsSaveToSettle(maxWaitMs = 15000) {
			const savePromise = lastSettingsSavePromiseRef.current || Promise.resolve();
			let timedOut = false;
			await Promise.race([
				savePromise.catch(() => null),
				sleep(maxWaitMs).then(() => { timedOut = true; }),
			]);
			return !timedOut;
		}

		async function syncQueuedSettingsBeforeAction() {
			if (!(await waitForSettingsSaveToSettle())) {
				throw new Error('Settings are still saving. Please wait for the save to finish before running this action.');
			}
		}

		function queueSettingsPatch(patch) {
			if (!patch || typeof patch !== 'object') {
				return Promise.resolve(null);
			}
			const queuedPatch = Object.assign({}, patch || {});
			const next = Object.assign({}, settingsRef.current || {}, queuedPatch);
			settingsRef.current = next;
			setSettings(next);
			const criticalPatch = isCriticalSettingsPatch(queuedPatch);
			const queuedSave = enqueueUiOperation('settings_save', criticalPatch ? 'Save critical settings' : 'Save settings', async () => {
				const response = await saveSettingsPatch(queuedPatch);
				if (response && response.settings) {
					committedSettingsRef.current = Object.assign({}, response.settings);
				}
				return response;
			}, {
				processingText: criticalPatch ? 'Processing critical settings save…' : 'Processing settings save…',
				successText: criticalPatch ? 'Critical cache settings saved.' : 'Settings saved.',
				failedText: 'Settings save failed.',
			});
			lastSettingsSavePromiseRef.current = queuedSave.catch(() => null);
			return queuedSave;
		}

		async function applyPerformanceProfile(profileKey) {
			const profile = PERFORMANCE_PROFILES[profileKey];
			if (!profile || !profile.patch) {
				return;
			}
			return enqueueUiOperation('apply_profile_' + profileKey, 'Apply ' + profile.label + ' profile', async (toastId) => {
				const basePatch = getPerformanceProfilePatch(profileKey);
				const splitPatch = splitProfileObjectCachePatch(basePatch);
				const queryAllowlistPatch = profileKey === 'off' ? {} : await getProfileQueryAllowlistPatch();
				const mainPatch = Object.assign({}, splitPatch.mainPatch, queryAllowlistPatch);
				const firstPassOptimistic = Object.assign({}, settingsRef.current || {}, mainPatch);
				settingsRef.current = firstPassOptimistic;
				setSettings(firstPassOptimistic);
				setAdvancedForm((prev) => Object.assign({}, prev, {
					cacheFreshTtlMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheFreshTtlMinutes') ? mainPatch.cacheFreshTtlMinutes : prev.cacheFreshTtlMinutes,
					cacheMaxStaleMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheMaxStaleMinutes') ? mainPatch.cacheMaxStaleMinutes : prev.cacheMaxStaleMinutes,
				}));

				pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Saving profile settings…", 'ultracache'), persistent: true });
				const firstResponse = await saveSettingsPatch(mainPatch);
				pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: __("Profile settings saved.", 'ultracache'), persistent: true });

				let objectCachePatch = {};
				let objectCacheWarning = '';
				pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Setting up Object Cache…", 'ultracache'), persistent: true });
				if (profileKey === 'off') {
					objectCachePatch = Object.assign({}, splitPatch.objectPatch, { objectCacheEnabled: false });
				} else if (Object.prototype.hasOwnProperty.call(splitPatch.objectPatch, 'objectCacheEnabled') && !splitPatch.objectPatch.objectCacheEnabled) {
					objectCachePatch = Object.assign({}, splitPatch.objectPatch, { objectCacheEnabled: false });
				} else {
					try {
						objectCachePatch = await getProfileObjectCachePatch(Object.assign({}, settingsRef.current || {}, basePatch));
					} catch (error) {
						objectCacheWarning = error && error.message ? error.message : 'Object Cache backend detection failed.';
					}
				}

				if (objectCachePatch && Object.keys(objectCachePatch).length) {
					const objectOptimistic = Object.assign({}, settingsRef.current || {}, objectCachePatch);
					settingsRef.current = objectOptimistic;
					setSettings(objectOptimistic);
					setRedisForm((prev) => Object.assign({}, prev, objectCachePatch));
					const objectResponse = await saveSettingsPatch(objectCachePatch);
					pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: objectCachePatch.objectCacheEnabled === false ? 'Object Cache disabled.' : 'Object Cache set up.', persistent: true });
				} else if (objectCacheWarning) {
					pushToast({ type: 'warning', title: __("Object Cache setup skipped", 'ultracache'), text: profile.label + ' profile settings were saved, but Object Cache was not changed. ' + objectCacheWarning });
				} else {
					pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: __("Object Cache unchanged.", 'ultracache'), persistent: true });
				}

				if (mainPatch.javascriptStrategy === 'defer' || !!mainPatch.deferJsEnabled) {
					pushToast({ id: toastId, type: 'info', title: 'Apply ' + profile.label + ' profile', text: __("Appending Defer JS dependency defaults…", 'ultracache'), persistent: true });
					const currentExclusions = settingsRef.current && typeof settingsRef.current.deferJsExcludeList !== 'undefined' ? settingsRef.current.deferJsExcludeList : '';
					const defaultExclusions = await populateDeferDelayExclusionDefaults(currentExclusions);
					if (defaultExclusions !== null && String(defaultExclusions || '') !== String(currentExclusions || '')) {
						const defaultsPatch = { deferJsExcludeList: String(defaultExclusions || '') };
						const defaultsOptimistic = Object.assign({}, settingsRef.current || {}, defaultsPatch);
						settingsRef.current = defaultsOptimistic;
						setSettings(defaultsOptimistic);
						await saveSettingsPatch(defaultsPatch);
						pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: 'Defer JS dependency defaults saved.', persistent: true });
					} else {
						pushToast({ id: toastId, type: 'success', title: 'Apply ' + profile.label + ' profile', text: 'Defer JS dependency defaults already present.', persistent: true });
					}
				}

				return { firstResponse: firstResponse, objectCacheWarning: objectCacheWarning };
			}, { processingText: 'Preparing ' + profile.label + ' profile…', successText: (result) => result && result.objectCacheWarning ? (profile.label + ' profile settings saved. Object Cache was not changed.') : (profile.label + ' profile applied.'), failedText: profile.label + ' profile failed.' });
		}

		function updateAdvancedField(key, value) {
			setAdvancedForm((prev) => Object.assign({}, prev, { [key]: value }));
		}

		async function saveAdvancedSettings() {
			return enqueueUiOperation('advanced_settings_save', 'Save advanced settings', async () => {
				const formSnapshot = Object.assign({}, advancedForm || {});
				const patch = {
					cacheCleanupIntervalHours: Number(formSnapshot.cacheCleanupIntervalHours || 24),
					cssBundleCleanupGraceHours: Number(formSnapshot.cssBundleCleanupGraceHours || 48),
					cssBundleCleanupDeleteLimit: Number(formSnapshot.cssBundleCleanupDeleteLimit || 60),
					cronWarmPagesPerMinute: Number(formSnapshot.cronWarmPagesPerMinute || 0),
					scheduledWarmLimit: Number(formSnapshot.scheduledWarmLimit || 1),
					cacheFreshTtlMinutes: Number(formSnapshot.cacheFreshTtlMinutes || 15),
					cacheMaxStaleMinutes: Number(formSnapshot.cacheMaxStaleMinutes || 720),
				};
				const response = await saveSettingsPatch(patch);
				if (response && response.settings) {
					setAdvancedForm((prev) => Object.assign({}, prev, {
						cacheCleanupIntervalHours: response.settings.cacheCleanupIntervalHours || prev.cacheCleanupIntervalHours,
						cssBundleCleanupGraceHours: typeof response.settings.cssBundleCleanupGraceHours === 'undefined' ? prev.cssBundleCleanupGraceHours : response.settings.cssBundleCleanupGraceHours,
						cssBundleCleanupDeleteLimit: typeof response.settings.cssBundleCleanupDeleteLimit === 'undefined' ? prev.cssBundleCleanupDeleteLimit : response.settings.cssBundleCleanupDeleteLimit,
						cronWarmPagesPerMinute: typeof response.settings.cronWarmPagesPerMinute === 'undefined' ? prev.cronWarmPagesPerMinute : response.settings.cronWarmPagesPerMinute,
						scheduledWarmLimit: typeof response.settings.scheduledWarmLimit === 'undefined' ? prev.scheduledWarmLimit : response.settings.scheduledWarmLimit,
						cacheFreshTtlMinutes: response.settings.cacheFreshTtlMinutes || prev.cacheFreshTtlMinutes,
						cacheMaxStaleMinutes: response.settings.cacheMaxStaleMinutes || prev.cacheMaxStaleMinutes,
					}));
				}
				return response;
			}, { processingText: 'Processing advanced settings save…', successText: 'Advanced settings saved.', failedText: 'Failed to save advanced settings.' });
		}

		function exportSettingsFile() {
			try {
				const payload = buildSettingsExportPayload(settings);
				const stamp = new Date().toISOString().replace(/[:.]/g, '-');
				const filename = 'ultracache-settings-' + (ultracache.version || 'export') + '-' + stamp + '.json';
				triggerFileDownload(filename, JSON.stringify(payload, null, 2), 'application/json');
				pushToast({ type: 'success', text: __("Settings exported.", 'ultracache') });
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
				const response = await saveSettingsPatch(importedSettings, { fullReplace: true });

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

		async function resetSettingsToDefaults() {
			if (busy) {
				return;
			}

			const defaultsPayload = initialDefaults && typeof initialDefaults === 'object' ? initialDefaults : {};
			if (!Object.keys(defaultsPayload).length) {
				pushToast({ type: 'error', text: __("Default settings are not available in this build.", 'ultracache') });
				return;
			}

			const confirmed = window.confirm('Reset all UltraCache settings to defaults? This also clears saved Redis and Varnish secrets.');
			if (!confirmed) {
				return;
			}

			setBusy(true);
			try {
				const response = await saveSettingsPatch(defaultsPayload, { fullReplace: true });
				setInspectResult(null);
				pushToast({ type: 'success', text: __("UltraCache settings were reset to defaults, including visible safeguard lists.", 'ultracache') });
			} catch (error) {
				pushToast({ type: 'error', text: error && error.message ? error.message : 'Failed to reset UltraCache settings.' });
			} finally {
				setBusy(false);
			}
		}

		return {
			isCriticalSettingsPatch,
			getSettingsResponseKeysForPatch,
			applyServerSettings,
			applySettingsSaveResponse,
			saveSettingsPatch,
			waitForSettingsSaveToSettle,
			syncQueuedSettingsBeforeAction,
			queueSettingsPatch,
			applyPerformanceProfile,
			updateAdvancedField,
			saveAdvancedSettings,
			exportSettingsFile,
			openImportSettingsDialog,
			importSettingsFile,
			resetSettingsToDefaults,
		};
	}

	admin.define('dashboardSettingsActions', { createDashboardSettingsActions });
})(window);
