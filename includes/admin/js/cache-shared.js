/* UltraCache Admin - Shared cache UI components */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before cache-shared.js.');
	}

	const core = admin.get('core');
	const ui = admin.get('ui');
	if (!core || !ui) {
		throw new Error('UltraCache admin core/ui modules are required before cache-shared.js.');
	}

	const { h, __ } = core;
	const { Button } = ui;

	function CacheHelperConflictNotice({ diagnostics, busy, onRemove, onRecheck }) {
		const legacyConflicts = diagnostics && diagnostics.legacyCacheConflicts ? diagnostics.legacyCacheConflicts : {};
		const dropins = Array.isArray(legacyConflicts.dropins) ? legacyConflicts.dropins.filter((item) => item && item.exists && !item.managed) : [];
		const removableDropins = dropins.filter((item) => item && item.removable);

		if (!dropins.length) {
			return null;
		}

		return h('div', { id: 'ultracache-cache-conflict-review', className: 'mt-4 text-xs text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl px-3 py-3' }, [
			h('div', { className: 'font-bold text-amber-200 mb-2', key: 'title' }, __("Conflicting WordPress cache helpers detected", 'ultracache')),
			h('div', { className: 'space-y-1 mb-2', key: 'dropins' }, dropins.map((item) => h('div', { key: 'dropin-' + item.file }, [
				h('span', { className: 'font-mono text-amber-100' }, item.file || 'drop-in'),
				h('span', {}, ' — owner: ' + (item.owner || 'Unknown') + (item.removable ? ' · removable' : '')),
			]))),
			h('div', { className: 'text-amber-100/90', key: 'message' }, __("UltraCache can remove these conflicting WordPress cache drop-ins immediately.", 'ultracache')),
			h('div', { className: 'flex flex-wrap gap-3 mt-3', key: 'actions' }, [
				removableDropins.length ? h(Button, { onClick: onRemove, disabled: busy, variant: 'danger', key: 'remove' }, busy ? 'Working…' : 'Remove conflicting cache helpers') : null,
				h(Button, { onClick: onRecheck, disabled: busy, variant: 'light', key: 'recheck' }, busy ? 'Working…' : 'Re-check'),
			]),
		]);
	}

	admin.define('cacheShared', {
		CacheHelperConflictNotice,
	});
})(window);
