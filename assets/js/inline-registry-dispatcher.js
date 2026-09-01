(function (window, document) {
    'use strict';

    var current = document.currentScript;
    if (!current) {
        return;
    }

    function decodeAttrs(value) {
        value = String(value || '');
        if (!value) {
            return {};
        }
        try {
            return JSON.parse(decodeURIComponent(escape(window.atob(value)))) || {};
        } catch (e) {
            return {};
        }
    }

    function readRegistry() {
        if (window.__ultracacheInlineRegistryV1 && window.__ultracacheInlineRegistryV1.entries) {
            return window.__ultracacheInlineRegistryV1;
        }

        var node = document.getElementById('ultracache-inline-registry-v1');
        if (!node) {
            return null;
        }

        try {
            var parsed = JSON.parse(node.textContent || '{}');
            if (!parsed || typeof parsed !== 'object' || !parsed.entries || typeof parsed.entries !== 'object') {
                return null;
            }
            window.__ultracacheInlineRegistryV1 = parsed;
            return parsed;
        } catch (e) {
            return null;
        }
    }

    var key = String(current.getAttribute('data-ultracache-inline-registry-key') || '');
    var registry = readRegistry();
    var entry = registry && registry.entries ? registry.entries[key] : null;
    if (!key || !entry || typeof entry.code !== 'string') {
        try { current.setAttribute('data-ultracache-inline-registry-missing', '1'); } catch (e) {}
        return;
    }

    var script = document.createElement('script');
    var attrs = decodeAttrs(current.getAttribute('data-ultracache-inline-registry-attrs'));
    Object.keys(attrs).forEach(function (name) {
        if (!name || name === 'src' || name === 'async' || name === 'defer' || name === 'data-wp-strategy' || name.indexOf('data-ultracache-') === 0) {
            return;
        }
        try {
            script.setAttribute(name, String(attrs[name]));
        } catch (e) {}
    });

    script.setAttribute('data-ultracache-inline-registry-executed', '1');
    script.setAttribute('data-ultracache-inline-registry-key', key);
    script.setAttribute('data-ultracache-inline-fingerprint', String(entry.fingerprint || ''));
    script.setAttribute('data-ultracache-finder-bypass', '1');
    if (entry.handle) {
        script.setAttribute('data-ultracache-handle', String(entry.handle));
    }

    try {
        script.text = entry.code;
    } catch (e) {
        script.textContent = entry.code;
    }

    if (!current.parentNode) {
        return;
    }

    current.parentNode.replaceChild(script, current);
}(window, document));
