/* UltraCache Admin - Internal module namespace */
(function (window) {
	'use strict';

	const current = (window.UltraCacheAdmin && typeof window.UltraCacheAdmin === 'object')
		? window.UltraCacheAdmin
		: {};
	const modules = (current.modules && typeof current.modules === 'object')
		? current.modules
		: Object.create(null);
	const owns = (name) => Object.prototype.hasOwnProperty.call(modules, name);
	const normalizeName = (name) => String(name || '').trim();

	const define = (name, value) => {
		const normalizedName = normalizeName(name);
		if (!normalizedName) {
			throw new Error('UltraCacheAdmin.define() requires a module name.');
		}
		if (owns(normalizedName)) {
			throw new Error('UltraCacheAdmin module already defined: ' + normalizedName);
		}
		modules[normalizedName] = value;
		return value;
	};

	const get = (name) => {
		const normalizedName = normalizeName(name);
		return normalizedName && owns(normalizedName) ? modules[normalizedName] : undefined;
	};

	const has = (name) => {
		const normalizedName = normalizeName(name);
		return Boolean(normalizedName && owns(normalizedName));
	};

	const list = () => Object.keys(modules).sort();

	window.UltraCacheAdmin = Object.assign(current, {
		namespaceVersion: 1,
		modules,
		define,
		get,
		has,
		list,
	});
})(window);
