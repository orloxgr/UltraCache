/* UltraCache Admin - Per-user dashboard theme */
(function (window) {
	'use strict';

	const admin = window.UltraCacheAdmin;
	if (!admin || typeof admin.define !== 'function') {
		throw new Error('UltraCacheAdmin namespace is required before theme.js.');
	}

	const core = admin.get('core');
	if (!core) {
		throw new Error('UltraCache admin core module is required before theme.js.');
	}

	const { h, useCallback, useEffect, useState, classNames, __ } = core;
	const THEME_NATIVE = 'native';
	const THEME_ULTRACACHE = 'ultracache';

	function normalizeTheme(value) {
		return String(value || '').toLowerCase() === THEME_ULTRACACHE ? THEME_ULTRACACHE : THEME_NATIVE;
	}

	function applyTheme(theme, rootElement, documentObject) {
		const nextTheme = normalizeTheme(theme);
		const doc = documentObject || (typeof document !== 'undefined' ? document : null);
		const root = rootElement || (doc ? doc.getElementById('uc-dashboard') : null);

		if (root) {
			root.setAttribute('data-uc-theme', nextTheme);
		}

		if (doc && doc.body && doc.body.classList) {
			doc.body.classList.toggle('ultracache-admin-theme-native', nextTheme === THEME_NATIVE);
			doc.body.classList.toggle('ultracache-admin-theme-ultracache', nextTheme === THEME_ULTRACACHE);
		}

		return nextTheme;
	}

	function useAdminTheme(options) {
		const config = options && typeof options === 'object' ? options : {};
		const apiRequest = typeof config.apiRequest === 'function' ? config.apiRequest : null;
		const rootElement = config.rootElement || null;
		const documentObject = config.documentObject || (typeof document !== 'undefined' ? document : null);
		const onError = typeof config.onError === 'function' ? config.onError : null;
		const [theme, setThemeState] = useState(() => normalizeTheme(config.initialTheme));
		const [saving, setSaving] = useState(false);

		useEffect(() => {
			applyTheme(theme, rootElement, documentObject);
		}, [theme, rootElement, documentObject]);

		const setTheme = useCallback(async (value) => {
			const nextTheme = normalizeTheme(value);
			const previousTheme = theme;
			if (nextTheme === previousTheme || saving) {
				return nextTheme;
			}

			setThemeState(nextTheme);
			setSaving(true);
			try {
				if (!apiRequest) {
					throw new Error('UltraCache admin theme REST action is unavailable.');
				}
				const response = await apiRequest('admin_theme', { theme: nextTheme });
				const savedTheme = normalizeTheme(response && response.theme ? response.theme : nextTheme);
				setThemeState(savedTheme);
				return savedTheme;
			} catch (error) {
				setThemeState(previousTheme);
				applyTheme(previousTheme, rootElement, documentObject);
				if (onError) {
					onError(error);
				}
				throw error;
			} finally {
				setSaving(false);
			}
		}, [apiRequest, documentObject, onError, rootElement, saving, theme]);

		return {
			theme,
			saving,
			isUltraCacheTheme: theme === THEME_ULTRACACHE,
			setTheme,
		};
	}

	function AdminThemeToggle({ checked, disabled, onChange }) {
		const inputId = 'uc-admin-theme-toggle';
		return h('div', { className: 'uc-admin-theme-control' }, [
			h('label', {
				className: 'uc-admin-theme-control__label',
				htmlFor: inputId,
				key: 'label',
			}, __('UltraCache Theme', 'ultracache')),
			h('label', {
				className: classNames('uc-toggle', disabled ? 'opacity-60 pointer-events-none' : ''),
				key: 'toggle',
			}, [
				h('input', {
					id: inputId,
					type: 'checkbox',
					role: 'switch',
					checked: !!checked,
					'aria-checked': !!checked,
					'aria-busy': !!disabled,
					disabled: !!disabled,
					onChange: (event) => {
						if (typeof onChange === 'function') {
							onChange(!!event.target.checked);
						}
					},
				}),
				h('span', { className: 'slider', 'aria-hidden': 'true' }),
			]),
		]);
	}

	admin.define('theme', {
		THEME_NATIVE,
		THEME_ULTRACACHE,
		useAdminTheme,
		AdminThemeToggle,
	});
})(window);
