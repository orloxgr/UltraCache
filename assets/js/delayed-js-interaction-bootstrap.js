(function (window) {
    'use strict';

    if (window.__ultracacheDelayedJsEarlyInteractionV125) {
        return;
    }

    function decodeOpaqueConfig(encoded) {
        encoded = String(encoded || '').trim();
        if (!encoded) {
            return null;
        }

        try {
            encoded = encoded.replace(/-/g, '+').replace(/_/g, '/');
            while (encoded.length % 4) {
                encoded += '=';
            }
            var binary = window.atob(encoded);
            var json = binary;
            if (typeof window.TextDecoder === 'function' && typeof window.Uint8Array === 'function') {
                var bytes = new window.Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                json = new window.TextDecoder('utf-8').decode(bytes);
            } else {
                try {
                    json = decodeURIComponent(Array.prototype.map.call(binary, function (ch) {
                        return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
                    }).join(''));
                } catch (e) {}
            }
            var parsed = JSON.parse(json);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    var config = window.ultracacheDelayedJsInteractionBootstrapConfig || null;
    if (!config) {
        var bootstrapDocument = window.document || (typeof document !== 'undefined' ? document : null);
        var bootstrapScript = bootstrapDocument
            ? (bootstrapDocument.currentScript || (typeof bootstrapDocument.getElementById === 'function' ? bootstrapDocument.getElementById('ultracache-delayed-js-interaction-bootstrap-js') : null))
            : null;
        var opaqueConfig = bootstrapScript && typeof bootstrapScript.getAttribute === 'function'
            ? bootstrapScript.getAttribute('data-ultracache-config')
            : '';
        config = decodeOpaqueConfig(opaqueConfig);
    }
    config = config || {};
    var configuredEvents = Array.isArray(config.autoEvents) ? config.autoEvents : [];
    var priorities = {
        mousemove: 1,
        touchstart: 2,
        pointerdown: 3,
        keydown: 4,
        click: 5
    };
    var listeners = [];
    var state = {
        snapshot: null,
        stop: stop
    };

    window.__ultracacheDelayedJsEarlyInteractionV125 = state;

    function snapshotEvent(event) {
        if (!event || !event.type || !event.target || typeof event.target.dispatchEvent !== 'function') {
            return null;
        }

        var type = String(event.type || '').toLowerCase();
        return {
            type: type,
            priority: priorities[type] || 0,
            target: event.target,
            constructor: event.constructor,
            init: {
                bubbles: true,
                cancelable: true,
                composed: true,
                detail: event.detail || 0,
                screenX: event.screenX || 0,
                screenY: event.screenY || 0,
                clientX: event.clientX || 0,
                clientY: event.clientY || 0,
                button: typeof event.button === 'number' ? event.button : 0,
                buttons: typeof event.buttons === 'number' ? event.buttons : 0,
                ctrlKey: !!event.ctrlKey,
                shiftKey: !!event.shiftKey,
                altKey: !!event.altKey,
                metaKey: !!event.metaKey,
                key: event.key || '',
                code: event.code || '',
                location: event.location || 0,
                repeat: !!event.repeat,
                pointerId: event.pointerId || 0,
                pointerType: event.pointerType || '',
                isPrimary: event.isPrimary !== false,
                width: event.width || 1,
                height: event.height || 1,
                pressure: typeof event.pressure === 'number' ? event.pressure : 0
            }
        };
    }

    function capture(event) {
        var snapshot = snapshotEvent(event);
        if (!snapshot) {
            return;
        }

        if (!state.snapshot || snapshot.priority >= state.snapshot.priority) {
            state.snapshot = snapshot;
        }
    }

    function stop() {
        for (var i = 0; i < listeners.length; i++) {
            try {
                window.removeEventListener(listeners[i].name, listeners[i].handler, listeners[i].options);
            } catch (e) {}
        }
        listeners.length = 0;
    }

    configuredEvents.forEach(function (eventName) {
        eventName = String(eventName || '').toLowerCase();
        if (!priorities[eventName]) {
            return;
        }

        var options = { passive: true, once: true };
        var handler = function (event) {
            capture(event);
        };
        listeners.push({ name: eventName, handler: handler, options: options });
        window.addEventListener(eventName, handler, options);
    });

}(window));
