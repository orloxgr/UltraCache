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

	function runtimeFailureResourceLabel(item) {
		if (!item || typeof item !== 'object') {
			return '';
		}

		const direct = [item.source, item.src, item.url, item.href]
			.map((value) => String(value || '').trim())
			.find(Boolean);
		if (direct) {
			return direct;
		}

		const detail = String(item.detail || '').trim();
		const message = String(item.message || '').trim();
		const text = [detail, message].filter(Boolean).join(' ');
		if (!text) {
			return '';
		}

		const absoluteMatch = text.match(/https?:\/\/[^\s"'<>]+?\.m?js(?:\?[^\s"'<>]*)?/i);
		if (absoluteMatch) {
			return String(absoluteMatch[0] || '').trim();
		}
		const relativeMatch = text.match(/(?:^|[\s("'=])([^\s"'<>]+\.m?js(?:\?[^\s"'<>]*)?)/i);
		if (relativeMatch) {
			return String(relativeMatch[1] || '').trim();
		}

		return text.slice(0, 300);
	}

	function collectRuntimeFailureResourceDetails(result) {
		const labels = [];
		const labelIndex = Object.create(null);
		const visited = typeof WeakSet === 'function' ? new WeakSet() : null;

		function addLabel(item) {
			const kind = String(item && item.kind ? item.kind : '').trim().toLowerCase();
			const isJavaScript = !!(item && item.isJavaScript);
			if (kind !== 'resource-error' && !isJavaScript) {
				return;
			}
			const label = runtimeFailureResourceLabel(item);
			if (!label) {
				return;
			}
			const key = label.toLowerCase();
			if (!labelIndex[key]) {
				labelIndex[key] = true;
				labels.push(label);
			}
		}

		function walk(value, depth) {
			if (!value || typeof value !== 'object' || depth > 10) {
				return;
			}
			if (visited) {
				if (visited.has(value)) {
					return;
				}
				visited.add(value);
			}

			if (!Array.isArray(value)) {
				addLabel(value);
			}

			if (Array.isArray(value)) {
				value.forEach((item) => walk(item, depth + 1));
				return;
			}

			Object.keys(value).forEach((key) => {
				const child = value[key];
				if (child && typeof child === 'object') {
					walk(child, depth + 1);
				}
			});
		}

		walk(result, 0);
		return labels;
	}

	function runtimeResultHasJavaScriptMeasurementFailure(result) {
		if (!result || typeof result !== 'object') {
			return false;
		}
		const text = JSON.stringify({
			reason: result.reason || '',
			summaryMessage: result.summaryMessage || '',
			issueMessages: result.issueMessages || [],
			targetResults: result.targetResults || [],
		}).toLowerCase();
		return text.indexOf('javascript-resource-load-failed') !== -1 || text.indexOf('client-script-blocked') !== -1;
	}

	function decorateRuntimeScanFailureDetails() {
		const diagnostics = admin.get('diagnostics');
		if (!diagnostics || typeof diagnostics.runRuntimeSiteScanAction !== 'function' || diagnostics.__ultracacheRuntimeFailureDetailsDecorated) {
			return;
		}

		const runRuntimeSiteScanAction = diagnostics.runRuntimeSiteScanAction;
		diagnostics.runRuntimeSiteScanAction = async function(options) {
			const result = await runRuntimeSiteScanAction(options);
			if (!result || typeof result !== 'object' || !runtimeResultHasJavaScriptMeasurementFailure(result)) {
				return result;
			}

			const failedJavaScriptResources = collectRuntimeFailureResourceDetails(result);
			const visibleResources = failedJavaScriptResources.slice(0, 3);
			const hiddenResourceCount = Math.max(0, failedJavaScriptResources.length - visibleResources.length);
			const detail = visibleResources.length
				? (' Failed JS: ' + visibleResources.join(' | ') + (hiddenResourceCount ? ' | +' + hiddenResourceCount + ' more' : ''))
				: ' Failed JS diagnostic: the Runtime Scan result reported a JavaScript resource failure but exposed no resource-error detail.';

			result.summaryMessage = String(result.summaryMessage || '').trim() + detail;
			result.failedJavaScriptResources = failedJavaScriptResources.slice();
			result.runtimeFailureDetailsDecorated = true;
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
