(function () {
    "use strict";

    if (window.__ultracacheLcpRequestCredentialsV124) {
        return;
    }

    var config = window.ultracacheLcpObserverConfig || {};
    var state = {
        modes: Object.create(null),
        restore: null
    };
    window.__ultracacheLcpRequestCredentialsV124 = state;

    if (!config.observeRequestCredentials || !window.HTMLImageElement || !window.HTMLImageElement.prototype) {
        return;
    }

    function absoluteUrl(url) {
        var value = String(url || "").trim();
        if (!value) {
            return "";
        }
        try {
            return new URL(value, document.baseURI).href;
        } catch (error) {
            return value;
        }
    }

    function normalizeMode(image) {
        var value = "";
        try {
            value = image && image.crossOrigin !== null && typeof image.crossOrigin !== "undefined"
                ? String(image.crossOrigin).toLowerCase()
                : "";
        } catch (error) {}
        if (value === "use-credentials") {
            return "use-credentials";
        }
        if (value === "anonymous") {
            return "anonymous";
        }
        try {
            return image && typeof image.hasAttribute === "function" && image.hasAttribute("crossorigin") ? "anonymous" : "none";
        } catch (error) {
            return "none";
        }
    }

    function remember(url, image) {
        var normalizedUrl = absoluteUrl(url);
        if (!normalizedUrl) {
            return;
        }
        var mode = normalizeMode(image);
        var existing = state.modes[normalizedUrl];
        if (!existing) {
            state.modes[normalizedUrl] = mode;
        } else if (existing !== mode) {
            state.modes[normalizedUrl] = "conflict";
        }
    }

    var prototype = window.HTMLImageElement.prototype;
    var srcDescriptor = null;
    var setAttributeDescriptor = null;
    var originalSetAttribute = prototype.setAttribute;
    var srcPatched = false;
    var setAttributePatched = false;

    try {
        srcDescriptor = Object.getOwnPropertyDescriptor(prototype, "src");
    } catch (error) {}
    try {
        setAttributeDescriptor = Object.getOwnPropertyDescriptor(prototype, "setAttribute");
    } catch (error) {}

    if (srcDescriptor && srcDescriptor.configurable && typeof srcDescriptor.set === "function") {
        try {
            Object.defineProperty(prototype, "src", {
                configurable: srcDescriptor.configurable,
                enumerable: srcDescriptor.enumerable,
                get: srcDescriptor.get,
                set: function (value) {
                    remember(value, this);
                    return srcDescriptor.set.call(this, value);
                }
            });
            srcPatched = true;
        } catch (error) {}
    }

    if (typeof originalSetAttribute === "function") {
        try {
            prototype.setAttribute = function (name, value) {
                if (String(name || "").toLowerCase() === "src") {
                    remember(value, this);
                }
                return originalSetAttribute.call(this, name, value);
            };
            setAttributePatched = true;
        } catch (error) {}
    }

    state.restore = function () {
        if (srcPatched) {
            try {
                Object.defineProperty(prototype, "src", srcDescriptor);
            } catch (error) {}
        }
        if (setAttributePatched) {
            try {
                if (setAttributeDescriptor) {
                    Object.defineProperty(prototype, "setAttribute", setAttributeDescriptor);
                } else {
                    delete prototype.setAttribute;
                }
            } catch (error) {}
        }
        state.restore = null;
    };
}());
