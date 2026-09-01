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
			OPTIMAL_SETTINGS_RECIPE,
			getOptimalSettingsPatch,
			splitOptimalObjectCachePatch,
			splitOptimalCompressionPatch,
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
			enqueueUiOperation,
			getOptimalObjectCachePatch,
			populateDeferDelayExclusionDefaults,
			populateQueryStringAllowlist,
			runGoogleFontsLocalOptimizationEnableEffects,
			runDelayIconFontsEnableEffects,
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
			if (keys.configureVarnishConnection) {
				delete keys.configureVarnishConnection;
				keys.varnishConnectionConfigured = true;
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
			const opts = options || {};
			const requestPatch = Object.assign({}, settingsPatch);
			if (opts.preserveConfiguredInfrastructure) {
				requestPatch.preserveConfiguredInfrastructure = true;
			}
			if (opts.runtimeJsScanDecision && typeof opts.runtimeJsScanDecision === 'object') {
				requestPatch.runtimeJsScanDecision = JSON.stringify(Object.assign({ source: 'runtime-scan-auto' }, opts.runtimeJsScanDecision));
			}
			const response = await apiRequest('save_settings', { settings_json: JSON.stringify(requestPatch) });
			applySettingsSaveResponse(response || {}, settingsPatch, opts);
			return response;
		}

		async function waitForSettingsSaveToSettle() {
			const savePromise = lastSettingsSavePromiseRef.current || Promise.resolve();
			await savePromise;
			return true;
		}

		async function syncQueuedSettingsBeforeAction() {
			await waitForSettingsSaveToSettle();
		}

		function queueSettingsPatch(patch, options) {
			if (!patch || typeof patch !== 'object') {
				return Promise.resolve(null);
			}
			const opts = options || {};
			const queuedPatch = Object.assign({}, patch || {});
			const next = Object.assign({}, settingsRef.current || {}, queuedPatch);
			settingsRef.current = next;
			setSettings(next);
			const criticalPatch = isCriticalSettingsPatch(queuedPatch);
			const queuedSave = enqueueUiOperation('settings_save', criticalPatch ? 'Save critical settings' : 'Save settings', async () => {
				const response = await saveSettingsPatch(queuedPatch, opts);
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

		function getOptimalObjectCacheSnapshot() {
			const current = settingsRef.current || {};
			return {
				objectCacheEnabled: !!current.objectCacheEnabled,
				objectCacheBackend: String(current.objectCacheBackend || 'redis'),
				objectCacheFallbackBackend: String(current.objectCacheFallbackBackend || 'none'),
			};
		}

		function getOptimalCompressionDecision(capabilities) {
			const source = capabilities && typeof capabilities === 'object' ? capabilities : {};
			const message = source.message ? String(source.message) : '';

			if (source.indeterminate || (!source.serverManaged && !source.probeComplete && !source.brokenGzip && !source.brokenBrotli)) {
				return {
					determinate: false,
					mode: 'unchanged',
					patch: null,
					message: message || __('The live HTML compression probe was inconclusive, so the existing compression setting was preserved.', 'ultracache'),
				};
			}

			if (source.serverManaged) {
				return {
					determinate: true,
					mode: 'server-managed',
					patch: { gzipEnabled: false, brotliEnabled: false },
					message: message || __('Server-managed HTML compression is active, so UltraCache compression stays off.', 'ultracache'),
				};
			}

			if (source.brokenGzip || source.brokenBrotli || source.blocked) {
				return {
					determinate: true,
					mode: 'off',
					patch: { gzipEnabled: false, brotliEnabled: false },
					message: message || __('HTML compression was left off because the live probe detected an unsafe server response.', 'ultracache'),
				};
			}

			if (source.brotliAvailable) {
				return {
					determinate: true,
					mode: 'brotli',
					patch: { gzipEnabled: false, brotliEnabled: true },
					message: __('Brotli compression is available and was selected.', 'ultracache'),
				};
			}

			if (source.gzipAvailable) {
				return {
					determinate: true,
					mode: 'gzip',
					patch: { gzipEnabled: true, brotliEnabled: false },
					message: __('Gzip compression is available and was selected.', 'ultracache'),
				};
			}

			return {
				determinate: true,
				mode: 'off',
				patch: { gzipEnabled: false, brotliEnabled: false },
				message: message || __('No supported UltraCache HTML compression encoder is available.', 'ultracache'),
			};
		}

		async function detectOptimalHtmlCompression() {
			const response = await apiRequest('compression_capabilities', {});
			if (!response || response.success !== true || !response.capabilities || typeof response.capabilities !== 'object') {
				throw new Error(__('HTML compression capability detection returned an invalid response.', 'ultracache'));
			}
			return getOptimalCompressionDecision(response.capabilities);
		}

		async function verifyOptimalObjectCacheConfiguration(objectCachePatch) {
			const selectedBackend = String((objectCachePatch && objectCachePatch.objectCacheBackend) || (settingsRef.current || {}).objectCacheBackend || 'selected').toLowerCase();
			const payload = { backend: selectedBackend };
			const response = await apiRequest('object_cache_test', payload);
			if (!response || response.success !== true) {
				throw new Error(response && response.message ? String(response.message) : __('Object Cache runtime validation failed.', 'ultracache'));
			}
			return response;
		}


		function extractOptimalPatchKeys(patch, keys) {
			const source = patch && typeof patch === 'object' ? patch : {};
			const extracted = {};
			(keys || []).forEach((key) => {
				if (Object.prototype.hasOwnProperty.call(source, key)) {
					extracted[key] = source[key];
					delete source[key];
				}
			});
			return extracted;
		}

		function getOptimalMediaFormatResult(report, key) {
			const source = report && typeof report === 'object' ? report : {};
			const support = source.support && typeof source.support === 'object' ? source.support : {};
			const items = Array.isArray(source.items) ? source.items : [];
			if (support[key] !== true) {
				return { supported: false, pass: false, successful: 0, failed: 0 };
			}

			let successful = 0;
			let failed = 0;
			items.forEach((item) => {
				const variant = item && item[key] && typeof item[key] === 'object' ? item[key] : null;
				if (!variant) {
					return;
				}
				const status = String(variant.status || '').toLowerCase();
				if (status === 'generated' || status === 'source') {
					successful += 1;
				} else if (status === 'failed') {
					failed += 1;
				}
			});

			return {
				supported: true,
				pass: successful > 0 && failed === 0,
				successful: successful,
				failed: failed,
			};
		}

		function getOptimalMediaDecision(report) {
			if (!report || report.success !== true || !Array.isArray(report.items) || report.items.length === 0) {
				return {
					determinate: false,
					patch: null,
					mode: 'unchanged',
					message: report && report.message ? String(report.message) : __('Image conversion validation was inconclusive, so the existing image-format settings were preserved.', 'ultracache'),
				};
			}

			const avif = getOptimalMediaFormatResult(report, 'avif');
			const webp = getOptimalMediaFormatResult(report, 'webp');
			if (avif.pass) {
				return {
					determinate: true,
					mode: webp.pass ? 'avif-webp' : 'avif-original',
					patch: {
						mediaUploadConversionEnabled: true,
						mediaUploadFormat: 'avif',
						mediaOutputMode: 'avif',
						mediaFallbackFormat: webp.pass ? 'webp' : 'original',
					},
					message: webp.pass
						? __('AVIF and WebP passed the live Media Library conversion test. AVIF was selected with WebP fallback and AVIF upload conversion.', 'ultracache')
						: __('AVIF passed the live Media Library conversion test. AVIF was selected with original-file fallback and AVIF upload conversion.', 'ultracache'),
				};
			}

			if (webp.pass) {
				return {
					determinate: true,
					mode: 'webp',
					patch: {
						mediaUploadConversionEnabled: true,
						mediaUploadFormat: 'webp',
						mediaOutputMode: 'webp',
						mediaFallbackFormat: 'original',
					},
					message: __('WebP passed the live Media Library conversion test while AVIF did not. WebP was selected for rewrite and upload conversion.', 'ultracache'),
				};
			}

			return {
				determinate: true,
				mode: 'conversion-off',
				patch: { mediaUploadConversionEnabled: false },
				message: __('Neither AVIF nor WebP passed the live Media Library conversion test. Convert new uploads was left off and the existing rewrite formats were preserved.', 'ultracache'),
			};
		}

		function getOptimalIntegrationPatch(plan) {
			const capabilities = plan && plan.capabilities && typeof plan.capabilities === 'object' ? plan.capabilities : {};
			const integrations = capabilities.integrations && typeof capabilities.integrations === 'object' ? capabilities.integrations : {};
			const wooActive = !!(integrations.woocommerce && integrations.woocommerce.active);
			const mailerliteActive = !!(integrations.mailerlite && integrations.mailerlite.active);
			return {
				patch: {
					woocommerceCartFragmentsSuppressEmptyEnabled: wooActive,
					woocommerceCartFragmentsDelayEnabled: false,
					lazyMailerliteNonceEnabled: mailerliteActive,
				},
				wooActive: wooActive,
				mailerliteActive: mailerliteActive,
			};
		}

		function getOptimalPageDeliveryContext(plan, currentSettings) {
			const sourcePlan = plan && typeof plan === 'object' ? plan : {};
			const capabilities = sourcePlan.capabilities && typeof sourcePlan.capabilities === 'object' ? sourcePlan.capabilities : {};
			const server = capabilities.server && typeof capabilities.server === 'object' ? capabilities.server : {};
			const externalCache = capabilities.externalCache && typeof capabilities.externalCache === 'object' ? capabilities.externalCache : {};
			const liteSpeed = externalCache.litespeed && typeof externalCache.litespeed === 'object' ? externalCache.litespeed : {};
			const current = currentSettings && typeof currentSettings === 'object' ? currentSettings : {};
			const serverType = String(server.type || '').trim().toLowerCase();
			const liteSpeedDetected = serverType === 'litespeed' || !!liteSpeed.detected;

			return {
				serverType: serverType || 'unknown',
				liteSpeedDetected: liteSpeedDetected,
				liteSpeedAlreadyEnabled: !!current.liteSpeedCacheEnabled,
				apacheStaticAlreadyEnabled: !!current.apacheStaticHtmlDeliveryEnabled,
			};
		}

		function getOptimalNginxContext(plan, currentSettings) {
			const sourcePlan = plan && typeof plan === 'object' ? plan : {};
			const capabilities = sourcePlan.capabilities && typeof sourcePlan.capabilities === 'object' ? sourcePlan.capabilities : {};
			const server = capabilities.server && typeof capabilities.server === 'object' ? capabilities.server : {};
			const externalCache = capabilities.externalCache && typeof capabilities.externalCache === 'object' ? capabilities.externalCache : {};
			const nginx = externalCache.nginx && typeof externalCache.nginx === 'object' ? externalCache.nginx : {};
			const analysis = sourcePlan.analysis && typeof sourcePlan.analysis === 'object' ? sourcePlan.analysis : {};
			const detectedCaches = analysis.externalCaches && analysis.externalCaches.layers && typeof analysis.externalCaches.layers === 'object'
				? analysis.externalCaches.layers
				: {};
			const detectedNginx = detectedCaches.nginx && typeof detectedCaches.nginx === 'object' ? detectedCaches.nginx : {};
			const current = currentSettings && typeof currentSettings === 'object' ? currentSettings : {};
			const serverDetected = String(server.type || '').trim().toLowerCase() === 'nginx' || !!nginx.serverDetected;
			const flushHookAvailable = !!nginx.flushHookAvailable || !!detectedNginx.flushable;
			const integrationDetected = flushHookAvailable || !!nginx.integrationDetected || !!detectedNginx.detected;

			return {
				serverDetected: serverDetected,
				integrationDetected: integrationDetected,
				flushHookAvailable: flushHookAvailable,
				flushAllEnabled: !!current.flushAllIncludeNginx,
			};
		}

		function getOptimalVarnishContext(plan, currentSettings) {
			const sourcePlan = plan && typeof plan === 'object' ? plan : {};
			const capabilities = sourcePlan.capabilities && typeof sourcePlan.capabilities === 'object' ? sourcePlan.capabilities : {};
			const externalCache = capabilities.externalCache && typeof capabilities.externalCache === 'object' ? capabilities.externalCache : {};
			const varnish = externalCache.varnish && typeof externalCache.varnish === 'object' ? externalCache.varnish : {};
			const current = currentSettings && typeof currentSettings === 'object' ? currentSettings : {};
			const configured = !!current.varnishConnectionConfigured || (!!current.varnishCliEnabled && String(current.varnishCliServers || '').trim() !== '') || !!varnish.configured;
			return {
				configured: configured,
				detected: configured || !!varnish.detected,
				enabled: !!current.varnishCliEnabled,
				flushAllEnabled: !!current.flushAllIncludeVarnish,
			};
		}

		function getOptimalVarnishSettingsSnapshot(currentSettings) {
			const source = currentSettings && typeof currentSettings === 'object' ? currentSettings : {};
			const snapshot = {};
			[
				'varnishCliEnabled',
				'varnishConnectionConfigured',
				'varnishCliMode',
				'varnishCliServers',
				'varnishCliTimeoutSeconds',
				'varnishInvalidationsPerMinute',
				'varnishCliMethod',
				'varnishInvalidationStrategy',
				'varnishFlushScope',
				'flushAllIncludeVarnish',
			].forEach((key) => {
				if (Object.prototype.hasOwnProperty.call(source, key)) {
					snapshot[key] = source[key];
				}
			});
			return snapshot;
		}

		function getOptimalVarnishCandidatePatch(configuration) {
			const source = configuration && typeof configuration === 'object' ? configuration : {};
			const patch = { configureVarnishConnection: true };
			[
				'varnishCliMode',
				'varnishCliServers',
				'varnishCliTimeoutSeconds',
				'varnishInvalidationsPerMinute',
				'varnishCliMethod',
				'varnishInvalidationStrategy',
				'varnishFlushScope',
			].forEach((key) => {
				if (Object.prototype.hasOwnProperty.call(source, key)) {
					patch[key] = source[key];
				}
			});
			return patch;
		}

		function getVarnishVerificationPayload(responseOrError) {
			if (responseOrError && responseOrError.data && typeof responseOrError.data === 'object') {
				return responseOrError.data;
			}
			return responseOrError && typeof responseOrError === 'object' ? responseOrError : null;
		}

		function getOptimalWarmScopePatch(plan) {
			const sourcePlan = plan && typeof plan === 'object' ? plan : {};
			const recommendations = sourcePlan.recommendations && typeof sourcePlan.recommendations === 'object' ? sourcePlan.recommendations : {};
			const capabilities = sourcePlan.capabilities && typeof sourcePlan.capabilities === 'object' ? sourcePlan.capabilities : {};
			const warmup = recommendations.warmup && typeof recommendations.warmup === 'object' ? recommendations.warmup : {};
			const site = capabilities.site && typeof capabilities.site === 'object' ? capabilities.site : {};
			const menu = site.recommendedMenu && typeof site.recommendedMenu === 'object' ? site.recommendedMenu : {};
			const allowedSources = ['homepage', 'menus', 'pages', 'posts', 'categories'];
			const requestedSources = Array.isArray(warmup.fullSiteSources) ? warmup.fullSiteSources : [];
			const sources = [];

			requestedSources.forEach((source) => {
				const normalized = String(source || '').trim().toLowerCase();
				if (allowedSources.indexOf(normalized) !== -1 && sources.indexOf(normalized) === -1) {
					sources.push(normalized);
				}
			});

			const patch = {};
			const menuLocation = String(warmup.menuLocation || '').trim();
			const menuRecommended = menu.status === 'recommended' && !!menuLocation;
			if (menuRecommended) {
				patch.warmMenuLocation = menuLocation;
				patch.warmMenuDepth = '1';
			} else {
				const menuIndex = sources.indexOf('menus');
				if (menuIndex !== -1) {
					sources.splice(menuIndex, 1);
				}
			}

			if (sources.length) {
				patch.warmFullSiteSources = sources.join(',');
			}

			return {
				patch: patch,
				menuRecommended: menuRecommended,
				menuStatus: String(menu.status || ''),
				menuLabel: String(menu.label || menu.value || ''),
				sources: sources,
			};
		}

		async function applyOptimalSettings(options = {}) {
			const recipe = OPTIMAL_SETTINGS_RECIPE;
			const onProgress = options && typeof options.onProgress === 'function' ? options.onProgress : () => {};
			if (!recipe || !recipe.patch) {
				return;
			}
			const saveOptimalSettingsPatch = (patch) => saveSettingsPatch(patch, { preserveConfiguredInfrastructure: true });
			return enqueueUiOperation('apply_optimal_settings', 'Setup Wizard', async (toastId) => {
				onProgress({ phase: 'settings', state: 'running', message: __('Applying recommended cache and frontend settings…', 'ultracache') });
				const basePatch = getOptimalSettingsPatch();
				const wizardObjectCacheChoice = String(options && options.objectCacheBackend || '').trim().toLowerCase();
				const cacheConflictStatus = await apiRequest('cache_conflicts_status', {
					pageCacheRequested: !!basePatch.pageCacheEnabled,
					objectCacheRequested: wizardObjectCacheChoice !== 'external',
				});
				if (cacheConflictStatus && cacheConflictStatus.blocked) {
					throw new Error(cacheConflictStatus.message || __('Another active cache plugin must be deactivated before UltraCache can continue.', 'ultracache'));
				}
				const splitObjectPatch = splitOptimalObjectCachePatch(basePatch);
				const splitCompressionPatch = splitOptimalCompressionPatch(splitObjectPatch.mainPatch);
				const mainPatch = Object.assign({}, splitCompressionPatch.mainPatch);
				const javascriptStrategyChoice = String(options && options.javascriptStrategyChoice || '').toLowerCase();
				if (javascriptStrategyChoice === 'speed') {
					Object.assign(mainPatch, {
						javascriptStrategy: 'delay',
						deferJsEnabled: false,
						delayAllJsEnabled: true,
						delayedLocalJsAutoStart: 'infinite',
						delayedJsAutostartAfterLoadEnabled: false,
						delayedJsAutostartMousemoveEnabled: true,
						delayedJsAutostartScrollEnabled: false,
						delayedJsAutostartClickEnabled: true,
						delayedJsAutostartTouchPointerEnabled: true,
						delayedJsAutostartKeyboardEnabled: true,
						mainThreadReliefEnabled: true,
					});
				} else if (javascriptStrategyChoice === 'user_experience') {
					Object.assign(mainPatch, {
						javascriptStrategy: 'defer',
						deferJsEnabled: true,
						delayAllJsEnabled: false,
						delayedLocalJsAutoStart: 'custom',
						delayedLocalJsAutoStartSeconds: 2,
						delayedJsAutostartAfterLoadEnabled: false,
						delayedJsAutostartMousemoveEnabled: true,
						delayedJsAutostartScrollEnabled: false,
						delayedJsAutostartClickEnabled: true,
						delayedJsAutostartTouchPointerEnabled: true,
						delayedJsAutostartKeyboardEnabled: true,
					});
				}
				const planRecommendationsForCompatibility = options && options.plan && options.plan.recommendations && typeof options.plan.recommendations === 'object' ? options.plan.recommendations : {};
				const planIntegrationRecommendations = planRecommendationsForCompatibility.integrations && typeof planRecommendationsForCompatibility.integrations === 'object' ? planRecommendationsForCompatibility.integrations : {};
				if (planIntegrationRecommendations.realCookieBannerCompatibility) {
					mainPatch.realCookieBannerCompatibilityEnabled = true;
				}
				if (planIntegrationRecommendations.complianzCompatibility) {
					mainPatch.complianzCompatibilityEnabled = true;
				}
				extractOptimalPatchKeys(mainPatch, ['mediaUploadConversionEnabled', 'mediaUploadFormat', 'mediaOutputMode', 'mediaFallbackFormat']);
				const fontPatch = extractOptimalPatchKeys(mainPatch, ['googleFontsLocalOptimizationEnabled', 'fontMixCssBundleEnabled', 'fontMixCssBundleAsyncEnabled', 'delayIconFontsEnabled']);
				extractOptimalPatchKeys(mainPatch, ['woocommerceCartFragmentsSuppressEmptyEnabled', 'woocommerceCartFragmentsDelayEnabled', 'lazyMailerliteNonceEnabled']);
				const objectCacheBefore = getOptimalObjectCacheSnapshot();
				const firstPassOptimistic = Object.assign({}, settingsRef.current || {}, mainPatch);
				settingsRef.current = firstPassOptimistic;
				setSettings(firstPassOptimistic);
				setAdvancedForm((prev) => Object.assign({}, prev, {
					cacheFreshTtlMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheFreshTtlMinutes') ? mainPatch.cacheFreshTtlMinutes : prev.cacheFreshTtlMinutes,
					cacheMaxStaleMinutes: Object.prototype.hasOwnProperty.call(mainPatch, 'cacheMaxStaleMinutes') ? mainPatch.cacheMaxStaleMinutes : prev.cacheMaxStaleMinutes,
				}));

				pushToast({ id: toastId, type: 'info', title: 'Setup Wizard', text: __("Saving recommended settings…", 'ultracache'), persistent: true });
				const firstResponse = await saveOptimalSettingsPatch(mainPatch);
				onProgress({ phase: 'settings', state: 'complete', message: __('Recommended cache and frontend settings saved.', 'ultracache') });
				pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: __("Recommended settings saved.", 'ultracache'), persistent: true });

				let apacheStaticCapability = null;
				let pageDeliveryMode = 'php-early-cache';
				let pageDeliveryWarning = '';
				const pageDeliveryContext = getOptimalPageDeliveryContext(options && options.plan, settingsRef.current || {});
				onProgress({ phase: 'page_delivery', state: 'running', message: __('Selecting the best available HTML delivery path…', 'ultracache') });

				if (pageDeliveryContext.liteSpeedDetected) {
					const liteSpeedPatch = {
						apacheStaticHtmlDeliveryEnabled: false,
						liteSpeedCacheEnabled: true,
						flushAllIncludeLiteSpeed: true,
					};
					await saveOptimalSettingsPatch(liteSpeedPatch);
					pageDeliveryMode = 'litespeed-native';
					onProgress({ phase: 'page_delivery', state: 'complete', message: __('LiteSpeed origin detected; Native LiteSpeed HTML Cache enabled.', 'ultracache') });
				} else {
					const apacheStaticAlreadyEnabled = !!(settingsRef.current || {}).apacheStaticHtmlDeliveryEnabled;
					if (apacheStaticAlreadyEnabled) {
						pageDeliveryMode = 'apache-static-preserved';
						onProgress({ phase: 'page_delivery', state: 'complete', message: __('Apache Static HTML Delivery is already enabled; the existing verified delivery mode was preserved.', 'ultracache') });
					} else {
						try {
							// The setting owns capability detection. The wizard only asks the
							// Apache Static switch to enable itself and reports the result.
							await saveOptimalSettingsPatch({
								apacheStaticHtmlDeliveryEnabled: true,
								liteSpeedCacheEnabled: false,
							});
							pageDeliveryMode = 'apache-static';
							onProgress({ phase: 'page_delivery', state: 'complete', message: __('Apache-compatible static delivery verified by the Apache Static switch; Apache Static HTML Delivery enabled.', 'ultracache') });
						} catch (error) {
							pageDeliveryMode = 'php-early-cache';
							pageDeliveryWarning = error && error.message
								? error.message
								: __('Apache Static HTML Delivery could not be verified by its switch, so UltraCache PHP early cache will be used.', 'ultracache');
							onProgress({ phase: 'page_delivery', state: 'warning', message: pageDeliveryWarning });
						}
					}
				}

				let nginxMode = 'not-detected';
				let nginxWarning = '';
				const nginxContext = getOptimalNginxContext(options && options.plan, settingsRef.current || {});
				if (nginxContext.flushHookAvailable) {
					if (nginxContext.flushAllEnabled) {
						nginxMode = 'helper-preserved-enabled';
						onProgress({ phase: 'nginx_integration', state: 'complete', message: __('Nginx Helper purge hook detected; the existing Flush All integration was already enabled and was preserved.', 'ultracache') });
					} else {
						await saveOptimalSettingsPatch({ flushAllIncludeNginx: true });
						nginxMode = 'helper-enabled';
						onProgress({ phase: 'nginx_integration', state: 'complete', message: __('Nginx Helper purge hook detected and Flush All integration enabled.', 'ultracache') });
					}
				} else if (nginxContext.integrationDetected) {
					nginxMode = 'helper-no-purge-hook';
					nginxWarning = __('Nginx Helper was detected, but its purge hook is not available. Existing Nginx integration settings were preserved.', 'ultracache');
					onProgress({ phase: 'nginx_integration', state: 'warning', message: nginxWarning });
				} else if (nginxContext.serverDetected) {
					nginxMode = 'server-detected-no-helper';
					let nginxDeliveryMessage = __('Nginx server detected; no Nginx Helper purge integration was found. Existing Nginx integration settings were preserved.', 'ultracache');
					if (pageDeliveryMode === 'apache-static') {
						nginxDeliveryMessage = __('Nginx server detected; Apache-compatible static delivery was verified behind it. No Nginx Helper purge integration was found.', 'ultracache');
					} else if (pageDeliveryMode === 'php-early-cache') {
						nginxDeliveryMessage = __('Nginx server detected; Apache Static delivery was not verified, so UltraCache PHP early cache will be used. No Nginx Helper purge integration was found.', 'ultracache');
					}
					onProgress({ phase: 'nginx_integration', state: 'complete', message: nginxDeliveryMessage });
				}


				let varnishWarning = '';
				let varnishMode = 'not-detected';
				let varnishDiscovery = null;
				let varnishVerification = null;
				const varnishContext = getOptimalVarnishContext(options && options.plan, settingsRef.current || {});
				const varnishSettingsBeforeDiscovery = getOptimalVarnishSettingsSnapshot(settingsRef.current || {});

				if (pageDeliveryContext.liteSpeedDetected) {
					if (varnishContext.configured) {
						varnishMode = 'preserved-litespeed';
						onProgress({
							phase: 'varnish',
							state: 'complete',
							message: __('Existing Varnish configuration preserved; automatic Varnish setup is skipped on LiteSpeed.', 'ultracache'),
						});
					} else {
						varnishMode = 'not-detected';
					}
				} else {
					let shouldVerifyConfiguredVarnish = varnishContext.configured;
					if (shouldVerifyConfiguredVarnish) {
						onProgress({ phase: 'varnish', state: 'running', message: __('Verifying the existing Varnish integration…', 'ultracache') });
					}

					if (!shouldVerifyConfiguredVarnish) {
						let discoveryFoundVarnish = false;
						try {
							varnishDiscovery = await apiRequest('varnish_discover', { verifyCandidate: true, persistVerified: false });
							const discoveryStatus = String(varnishDiscovery && varnishDiscovery.status || '');
							if (varnishDiscovery && varnishDiscovery.verified && varnishDiscovery.configuration) {
								discoveryFoundVarnish = true;
								varnishMode = 'discovered';
								onProgress({ phase: 'varnish', state: 'running', message: __('Varnish detected; verifying exact invalidation…', 'ultracache') });
								const candidatePatch = getOptimalVarnishCandidatePatch(varnishDiscovery.configuration);
								await saveSettingsPatch(Object.assign({}, candidatePatch, {
									varnishCliEnabled: false,
									flushAllIncludeVarnish: false,
								}));
								shouldVerifyConfiguredVarnish = true;
							} else if (varnishDiscovery && varnishDiscovery.requiresToken) {
								discoveryFoundVarnish = true;
								varnishMode = 'token-required';
								varnishWarning = String(varnishDiscovery.message || __('Varnish was detected, but automatic configuration requires a token or control key. Existing Varnish settings were left unchanged.', 'ultracache'));
							} else if (varnishDiscovery && varnishDiscovery.configuration) {
								discoveryFoundVarnish = true;
								varnishMode = 'unverified-candidate';
								varnishWarning = String(varnishDiscovery.message || __('A Varnish endpoint candidate was found, but exact invalidation could not be verified. Existing Varnish settings were left unchanged.', 'ultracache'));
							} else if (['varnish-not-detected', 'not-found', 'site-url-unavailable'].indexOf(discoveryStatus) !== -1 || (varnishDiscovery && varnishDiscovery.success === false && !varnishDiscovery.configuration)) {
								varnishMode = 'not-detected';
							}
						} catch (error) {
							if (discoveryFoundVarnish || varnishMode === 'discovered') {
								varnishMode = 'discovery-error';
								varnishWarning = error && error.message ? error.message : __('Varnish was detected, but automatic setup could not be completed. Existing Varnish settings were left unchanged.', 'ultracache');
							} else {
								// No Varnish evidence means no Varnish row in the wizard.
								varnishMode = 'not-detected';
								varnishWarning = '';
							}
						}
					}

					if (shouldVerifyConfiguredVarnish) {
						const verifyingDiscoveredCandidate = varnishMode === 'discovered';
						try {
							varnishVerification = await apiRequest('varnish_behavior_test', { diagnosticEnable: true });
							const verificationPayload = getVarnishVerificationPayload(varnishVerification);
							const verified = !!(verificationPayload && verificationPayload.verified && verificationPayload.exactInvalidationVerified);
							if (!verified) {
								throw Object.assign(new Error(verificationPayload && verificationPayload.message ? String(verificationPayload.message) : __('Varnish exact invalidation was not verified.', 'ultracache')), { data: verificationPayload || null });
							}
							await saveSettingsPatch({ varnishCliEnabled: true, flushAllIncludeVarnish: true });
							varnishMode = verifyingDiscoveredCandidate ? 'discovered-verified' : 'configured-verified';
							varnishWarning = '';
						} catch (error) {
							const payload = getVarnishVerificationPayload(error);
							varnishVerification = payload || varnishVerification;
							const verificationMessage = error && error.message ? error.message : __('Varnish capability verification failed.', 'ultracache');
							if (verifyingDiscoveredCandidate) {
								try {
									await saveSettingsPatch(varnishSettingsBeforeDiscovery);
									varnishWarning = __('The discovered Varnish candidate failed persisted capability verification, so the previous Varnish settings were restored.', 'ultracache') + ' ' + verificationMessage;
									varnishMode = 'discovered-verification-failed-restored';
								} catch (rollbackError) {
									throw new Error(__('The discovered Varnish candidate failed verification and the previous Varnish settings could not be restored.', 'ultracache') + ' ' + verificationMessage);
								}
							} else {
								varnishWarning = __('Varnish capability verification failed. Existing Varnish endpoint, secret, and enable-state settings were preserved.', 'ultracache') + ' ' + verificationMessage;
								varnishMode = 'configured-verification-failed-preserved';
							}
						}
					}

					if (varnishWarning) {
						onProgress({ phase: 'varnish', state: 'warning', message: varnishWarning });
					} else if (varnishMode === 'configured-verified') {
						onProgress({ phase: 'varnish', state: 'complete', message: __('Existing Varnish configuration preserved; exact invalidation verified and Flush All integration enabled.', 'ultracache') });
					} else if (varnishMode === 'discovered-verified') {
						onProgress({ phase: 'varnish', state: 'complete', message: __('Varnish detected; exact invalidation verified, configuration saved, and Flush All integration enabled.', 'ultracache') });
					}
				}

				let warmScopeWarning = '';
				if (options && options.preserveWarmScope) {
					onProgress({ phase: 'warm_scope', state: 'complete', message: __('Wizard warm-up selections preserved.', 'ultracache') });
				} else {
					const warmScope = getOptimalWarmScopePatch(options && options.plan);
					onProgress({ phase: 'warm_scope', state: 'running', message: __('Configuring the recommended site warm-up scope…', 'ultracache') });
					if (warmScope.patch && Object.keys(warmScope.patch).length) {
						await saveOptimalSettingsPatch(warmScope.patch);
					}
					if (warmScope.menuRecommended) {
						const warmMessage = __('Primary menu, Depth 1, and recommended full-site warm-up sources configured.', 'ultracache');
						onProgress({ phase: 'warm_scope', state: 'complete', message: warmMessage });
						pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: warmMessage, persistent: true });
					} else if (warmScope.sources.length) {
						warmScopeWarning = warmScope.menuStatus === 'ambiguous'
							? __('Recommended full-site warm-up sources were configured, but multiple assigned menus prevented automatic primary-menu selection. The existing menu selection was preserved.', 'ultracache')
							: __('Recommended full-site warm-up sources were configured. No assigned frontend menu was available, so the existing menu selection was preserved.', 'ultracache');
						onProgress({ phase: 'warm_scope', state: 'warning', message: warmScopeWarning });
						pushToast({ type: 'warning', title: __('Warm-up menu unchanged', 'ultracache'), text: warmScopeWarning });
					} else {
						warmScopeWarning = __('No deterministic site warm-up scope was available, so the existing warm-up scope was preserved.', 'ultracache');
						onProgress({ phase: 'warm_scope', state: 'warning', message: warmScopeWarning });
						pushToast({ type: 'warning', title: __('Warm-up scope unchanged', 'ultracache'), text: warmScopeWarning });
					}
				}


				let compressionWarning = '';
				let compressionMode = 'unchanged';
				onProgress({ phase: 'compression', state: 'running', message: __('Testing live HTML compression and selecting the correct delivery mode…', 'ultracache') });
				pushToast({ id: toastId, type: 'info', title: 'Setup Wizard', text: __('Testing HTML compression…', 'ultracache'), persistent: true });
				try {
					const compressionDecision = await detectOptimalHtmlCompression();
					compressionMode = compressionDecision.mode || 'unchanged';
					if (compressionDecision.determinate && compressionDecision.patch) {
						const current = settingsRef.current || {};
						const changed = !!current.gzipEnabled !== !!compressionDecision.patch.gzipEnabled
							|| !!current.brotliEnabled !== !!compressionDecision.patch.brotliEnabled;
						if (changed) {
							await saveOptimalSettingsPatch(compressionDecision.patch);
						}
						onProgress({ phase: 'compression', state: 'complete', message: compressionDecision.message || __('HTML compression configured.', 'ultracache') });
						pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: compressionDecision.message || __('HTML compression configured.', 'ultracache'), persistent: true });
					} else {
						compressionWarning = compressionDecision.message || __('HTML compression was not changed because the live probe was inconclusive.', 'ultracache');
						onProgress({ phase: 'compression', state: 'warning', message: compressionWarning });
						pushToast({ type: 'warning', title: __('HTML Compression unchanged', 'ultracache'), text: compressionWarning });
					}
				} catch (error) {
					compressionWarning = error && error.message ? error.message : __('HTML compression capability detection failed.', 'ultracache');
					onProgress({ phase: 'compression', state: 'warning', message: compressionWarning });
					pushToast({ type: 'warning', title: __('HTML Compression unchanged', 'ultracache'), text: compressionWarning });
				}

				let objectCachePatch = {};
				let objectCacheWarning = '';
				const planCapabilities = options && options.plan && options.plan.capabilities && typeof options.plan.capabilities === 'object' ? options.plan.capabilities : {};
				const planObjectCache = planCapabilities.objectCache && typeof planCapabilities.objectCache === 'object' ? planCapabilities.objectCache : {};
				const objectCacheRecommendation = options && options.plan && options.plan.recommendations && options.plan.recommendations.objectCache && typeof options.plan.recommendations.objectCache === 'object'
					? options.plan.recommendations.objectCache
					: {};
				const skipUltraCacheObjectCache = String(options && options.objectCacheBackend || '').toLowerCase() === 'external';
				const requestedObjectCacheBackend = ['redis', 'apcu', 'sqlite', 'disk'].indexOf(String(options && options.objectCacheBackend || '').toLowerCase()) !== -1
					? String(options.objectCacheBackend).toLowerCase()
					: '';
				const requestedBackendChangesCurrent = !!requestedObjectCacheBackend && (!objectCacheBefore.objectCacheEnabled || String(objectCacheBefore.objectCacheBackend || '').toLowerCase() !== requestedObjectCacheBackend);
				const preserveExistingObjectCache = !requestedBackendChangesCurrent && (!!objectCacheRecommendation.preserveExisting || !!planObjectCache.configured || !!objectCacheBefore.objectCacheEnabled);
				const objectCacheManagedByUltraCache = Object.prototype.hasOwnProperty.call(objectCacheRecommendation, 'managedByUltraCache')
					? !!objectCacheRecommendation.managedByUltraCache
					: !!objectCacheBefore.objectCacheEnabled;

				if (skipUltraCacheObjectCache) {
					onProgress({ phase: 'object_cache', state: 'running', message: __('Leaving Object Cache management outside UltraCache…', 'ultracache') });
					if (objectCacheBefore.objectCacheEnabled) {
						await saveSettingsPatch({ objectCacheEnabled: false });
					}
					onProgress({ phase: 'object_cache', state: 'complete', message: __('UltraCache Object Cache is not managing object-cache.php.', 'ultracache') });
				} else if (preserveExistingObjectCache) {
					onProgress({ phase: 'object_cache', state: 'running', message: __('Preserving the existing Object Cache configuration…', 'ultracache') });
					if (objectCacheManagedByUltraCache && objectCacheBefore.objectCacheEnabled) {
						try {
							await verifyOptimalObjectCacheConfiguration(objectCacheBefore);
							onProgress({ phase: 'object_cache', state: 'complete', message: __('Existing Object Cache configuration preserved and runtime-verified.', 'ultracache') });
							pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: __('Existing Object Cache configuration preserved.', 'ultracache'), persistent: true });
						} catch (error) {
							const validationMessage = error && error.message ? error.message : __('Object Cache runtime validation failed.', 'ultracache');
							objectCacheWarning = __('Existing Object Cache configuration was preserved unchanged, but runtime verification failed. ', 'ultracache') + validationMessage;
							onProgress({ phase: 'object_cache', state: 'warning', message: objectCacheWarning });
							pushToast({ type: 'warning', title: __('Object Cache preserved', 'ultracache'), text: objectCacheWarning });
						}
					} else {
						onProgress({ phase: 'object_cache', state: 'complete', message: __('Existing external Object Cache configuration preserved without modification.', 'ultracache') });
						pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: __('Existing external Object Cache preserved.', 'ultracache'), persistent: true });
					}
				} else {
					onProgress({ phase: 'object_cache', state: 'running', message: requestedObjectCacheBackend ? __('Verifying the Object Cache backend selected in the Wizard…', 'ultracache') : __('Detecting and verifying the best Object Cache backend…', 'ultracache') });
					pushToast({ id: toastId, type: 'info', title: 'Setup Wizard', text: __("Setting up Object Cache…", 'ultracache'), persistent: true });
					try {
						objectCachePatch = await getOptimalObjectCachePatch(Object.assign({}, settingsRef.current || {}, basePatch), requestedObjectCacheBackend);
					} catch (error) {
						objectCacheWarning = error && error.message ? error.message : 'Object Cache backend detection failed.';
					}

					if (objectCachePatch && Object.keys(objectCachePatch).length) {
						const objectOptimistic = Object.assign({}, settingsRef.current || {}, objectCachePatch);
						settingsRef.current = objectOptimistic;
						setSettings(objectOptimistic);
						setRedisForm((prev) => Object.assign({}, prev, objectCachePatch));
						try {
							await saveSettingsPatch(objectCachePatch);
							onProgress({ phase: 'object_cache', state: 'running', message: __('Object Cache selected. Verifying runtime payload storage…', 'ultracache') });
							await verifyOptimalObjectCacheConfiguration(objectCachePatch);
							onProgress({ phase: 'object_cache', state: 'complete', message: __('Object Cache configured and runtime-verified.', 'ultracache') });
							pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: __('Object Cache configured and verified.', 'ultracache'), persistent: true });
						} catch (error) {
							const validationMessage = error && error.message ? error.message : __('Object Cache runtime validation failed.', 'ultracache');
							try {
								await saveSettingsPatch(objectCacheBefore);
								setRedisForm((prev) => Object.assign({}, prev, objectCacheBefore));
								objectCacheWarning = __('Object Cache validation failed and the previous configuration was restored. ', 'ultracache') + validationMessage;
							} catch (rollbackError) {
								throw new Error(__('Object Cache validation failed and the previous configuration could not be restored. ', 'ultracache') + validationMessage);
							}
							onProgress({ phase: 'object_cache', state: 'warning', message: objectCacheWarning });
							pushToast({ type: 'warning', title: __('Object Cache setup restored', 'ultracache'), text: objectCacheWarning });
						}
					} else if (objectCacheWarning) {
						onProgress({ phase: 'object_cache', state: 'warning', message: __('Object Cache was not changed: ', 'ultracache') + objectCacheWarning });
						pushToast({ type: 'warning', title: __("Object Cache setup skipped", 'ultracache'), text: 'Recommended settings were saved, but Object Cache was not changed. ' + objectCacheWarning });
					} else {
						onProgress({ phase: 'object_cache', state: 'complete', message: __('Object Cache already uses the recommended configuration.', 'ultracache') });
						pushToast({ id: toastId, type: 'success', title: 'Setup Wizard', text: __("Object Cache unchanged.", 'ultracache'), persistent: true });
					}
				}

				let mediaWarning = '';
				let mediaMode = 'unchanged';
				onProgress({ phase: 'media', state: 'running', message: __('Testing AVIF/WebP conversion and selecting the optimal image formats…', 'ultracache') });
				pushToast({ id: toastId, type: 'info', title: 'Setup Wizard', text: __('Testing Media Library image conversion…', 'ultracache'), persistent: true });
				try {
					const mediaReport = await apiRequest('media_conversion_test_run', {});
					const mediaDecision = getOptimalMediaDecision(mediaReport);
					mediaMode = mediaDecision.mode || 'unchanged';
					if (mediaDecision.determinate && mediaDecision.patch) {
						await saveOptimalSettingsPatch(mediaDecision.patch);
						const mediaState = mediaMode === 'conversion-off' ? 'warning' : 'complete';
						onProgress({ phase: 'media', state: mediaState, message: mediaDecision.message });
						pushToast({ type: mediaState === 'complete' ? 'success' : 'warning', title: __('Media setup', 'ultracache'), text: mediaDecision.message });
					} else {
						mediaWarning = mediaDecision.message;
						onProgress({ phase: 'media', state: 'warning', message: mediaWarning });
						pushToast({ type: 'warning', title: __('Media formats unchanged', 'ultracache'), text: mediaWarning });
					}
				} catch (error) {
					mediaWarning = error && error.message ? error.message : __('Image conversion validation failed, so the existing image-format settings were preserved.', 'ultracache');
					onProgress({ phase: 'media', state: 'warning', message: mediaWarning });
					pushToast({ type: 'warning', title: __('Media formats unchanged', 'ultracache'), text: mediaWarning });
				}

				let queryAllowlistWarning = '';
				onProgress({ phase: 'query_allowlist', state: 'running', message: __('Populating the Query-string args whitelist with the existing detection flow…', 'ultracache') });
				try {
					const currentQueryAllowlist = String(settingsRef.current && settingsRef.current.cacheQueryStringAllowlist || '');
					const populatedQueryAllowlist = await populateQueryStringAllowlist(currentQueryAllowlist);
					if (populatedQueryAllowlist === null) {
						throw new Error(__('Query-string args whitelist population failed.', 'ultracache'));
					}
					await saveOptimalSettingsPatch({ cacheQueryStringAllowlist: String(populatedQueryAllowlist || '') });
					onProgress({ phase: 'query_allowlist', state: 'complete', message: __('Query-string args whitelist processed with Populate and saved.', 'ultracache') });
				} catch (error) {
					queryAllowlistWarning = error && error.message ? error.message : __('Query-string args whitelist could not be populated.', 'ultracache');
					onProgress({ phase: 'query_allowlist', state: 'warning', message: queryAllowlistWarning });
				}

				let fontsWarning = '';
				onProgress({ phase: 'fonts', state: 'running', message: __('Enabling the recommended font optimization settings…', 'ultracache') });
				if (Object.keys(fontPatch).length) {
					await saveOptimalSettingsPatch(fontPatch);
				}

				const fontWarnings = [];
				if (fontPatch.googleFontsLocalOptimizationEnabled && typeof runGoogleFontsLocalOptimizationEnableEffects === 'function') {
					try {
						await runGoogleFontsLocalOptimizationEnableEffects({ background: false });
					} catch (error) {
						fontWarnings.push(error && error.message ? String(error.message) : __('Google Fonts cache rebuild did not complete.', 'ultracache'));
					}
				}
				if (fontPatch.delayIconFontsEnabled && typeof runDelayIconFontsEnableEffects === 'function') {
					try {
						const iconResult = await runDelayIconFontsEnableEffects({ persistImmediately: true });
						if (!iconResult || Math.max(0, Number(iconResult.detected || 0)) === 0) {
							fontWarnings.push(__('Delay icon fonts is enabled, but no likely icon font patterns were detected on the front page.', 'ultracache'));
						}
					} catch (error) {
						fontWarnings.push(error && error.message ? String(error.message) : __('Icon-font pattern detection did not complete.', 'ultracache'));
					}
				}

				if (fontWarnings.length) {
					fontsWarning = fontWarnings.join(' ');
					onProgress({ phase: 'fonts', state: 'warning', message: fontsWarning });
				} else {
					onProgress({ phase: 'fonts', state: 'complete', message: __('Local Google Fonts optimization, generated Font-Mix bundling, and icon-font delay are enabled and prepared.', 'ultracache') });
				}

				const integrationSetup = getOptimalIntegrationPatch(options && options.plan);
				onProgress({ phase: 'integrations', state: 'running', message: __('Applying plugin-specific optimizations only where the integration is detected…', 'ultracache') });
				await saveOptimalSettingsPatch(integrationSetup.patch);
				const integrationMessage = (integrationSetup.wooActive ? __('WooCommerce empty-cart suppression enabled.', 'ultracache') : __('WooCommerce optimization not applicable.', 'ultracache'))
					+ ' ' + (integrationSetup.mailerliteActive ? __('MailerLite lazy nonce refresh enabled.', 'ultracache') : __('MailerLite optimization not applicable.', 'ultracache'));
				onProgress({ phase: 'integrations', state: 'complete', message: integrationMessage });


				onProgress({ phase: 'complete', state: 'complete', message: __('Recommended configuration applied.', 'ultracache') });
				return { firstResponse: firstResponse, pageDeliveryMode: pageDeliveryMode, pageDeliveryWarning: pageDeliveryWarning, apacheStaticCapability: apacheStaticCapability, nginxMode: nginxMode, nginxWarning: nginxWarning, varnishMode: varnishMode, varnishWarning: varnishWarning, varnishDiscovery: varnishDiscovery, varnishVerification: varnishVerification, objectCacheWarning: objectCacheWarning, compressionWarning: compressionWarning, warmScopeWarning: warmScopeWarning, mediaWarning: mediaWarning, queryAllowlistWarning: queryAllowlistWarning, fontsWarning: fontsWarning, compressionMode: compressionMode, mediaMode: mediaMode };
			}, {
				processingText: 'Preparing recommended configuration…',
				successText: (result) => result && (result.pageDeliveryWarning || result.nginxWarning || result.varnishWarning || result.objectCacheWarning || result.compressionWarning || result.warmScopeWarning || result.mediaWarning || result.queryAllowlistWarning || result.fontsWarning) ? 'Setup Wizard completed with warnings.' : 'Setup Wizard configuration applied.',
				failedText: 'Setup Wizard failed.',
			});
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
					cacheFreshTtlMinutes: Number(formSnapshot.cacheFreshTtlMinutes || 1440),
					cacheMaxStaleMinutes: Number(formSnapshot.cacheMaxStaleMinutes || 2880),
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
			applyOptimalSettings,
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
