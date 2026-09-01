/* UltraCache Admin - Application composition */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function' || typeof admin.get !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before app.js.');
	}

	const dashboardApplication = admin.get('dashboardApplication');
	if (!dashboardApplication || typeof dashboardApplication.bootstrap !== 'function') {
		throw new Error('UltraCache admin dashboard application module was not loaded.');
	}

	function decorateRuntimeScanFailureDetails() {
		const diagnostics = admin.get('diagnostics');
		if (!diagnostics || typeof diagnostics.runRuntimeSiteScanAction !== 'function' || diagnostics.__ultracacheRuntimeFailureDetailsDecorated) {
			return;
		}

		const runRuntimeSiteScanAction = diagnostics.runRuntimeSiteScanAction;
		diagnostics.runRuntimeSiteScanAction = async function(options) {
			const result = await runRuntimeSiteScanAction(options);
			if (!result || typeof result !== 'object' || !Array.isArray(result.targetOutcomes)) {
				return result;
			}

			const failedJavaScriptResources = [];
			result.targetOutcomes.forEach((record) => {
				const outcome = record && record.outcome && typeof record.outcome === 'object' ? record.outcome : null;
				const measurementFailure = outcome && outcome.measurementFailure && typeof outcome.measurementFailure === 'object'
					? outcome.measurementFailure
					: null;
				const resourceErrors = measurementFailure && Array.isArray(measurementFailure.resourceErrors)
					? measurementFailure.resourceErrors
					: [];

				resourceErrors.forEach((item) => {
					const source = String(item && item.source ? item.source : '').trim();
					const looksLikeJavaScript = !!(item && item.isJavaScript) || /\.js(?:[?#]|$)/i.test(source);
					if (!source || !looksLikeJavaScript || failedJavaScriptResources.indexOf(source) !== -1) {
						return;
					}
					failedJavaScriptResources.push(source);
				});
			});

			if (!failedJavaScriptResources.length) {
				return result;
			}

			const visibleResources = failedJavaScriptResources.slice(0, 3);
			const hiddenResourceCount = Math.max(0, failedJavaScriptResources.length - visibleResources.length);
			const detail = ' Failed JS: ' + visibleResources.join(' | ')
				+ (hiddenResourceCount ? ' | +' + hiddenResourceCount + ' more' : '');

			result.summaryMessage = String(result.summaryMessage || '').trim() + detail;
			result.failedJavaScriptResources = failedJavaScriptResources.slice();
			return result;
		};
		diagnostics.__ultracacheRuntimeFailureDetailsDecorated = true;
	}

	function bootstrap() {
		decorateRuntimeScanFailureDetails();
		dashboardApplication.bootstrap();
	}

	admin.define('app', {
		bootstrap,
	});
})(window);
