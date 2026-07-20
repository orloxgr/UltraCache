/* UltraCache - Dashboard bootstrap */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	const app = admin && typeof admin.get === 'function' ? admin.get('app') : null;
	if (!app || typeof app.bootstrap !== 'function') {
		throw new Error('UltraCache admin app module was not loaded.');
	}

	app.bootstrap();
})(window);
