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

	function bootstrap() {
		dashboardApplication.bootstrap();
	}

	admin.define('app', {
		bootstrap,
	});
})(window);
