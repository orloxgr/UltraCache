(function () {
    "use strict";

    if (window.__ultracacheLcpObserverV122) {
        return;
    }
    window.__ultracacheLcpObserverV122 = 1;

    var config = window.ultracacheLcpObserverConfig || {};
    var mode = config.mode === "automatic" ? "automatic" : "manual";
    var selectors = Array.isArray(config.manualSelectors) ? config.manualSelectors : [];
    var automatic = config.automatic && typeof config.automatic === "object" ? config.automatic : {};
    var observeRequestCredentials = !!config.observeRequestCredentials;

    if (!config.ajaxUrl || !config.pageUrl || !("PerformanceObserver" in window)) {
        return;
    }
    var expiresAt = Number(config.expiresAt || 0);
    if (expiresAt > 0 && Math.floor(Date.now() / 1000) >= expiresAt) {
        return;
    }
    if (window.location && String(window.location.search || "")) {
        return;
    }
    if (mode === "manual" && !selectors.length) {
        return;
    }
    if (mode === "automatic" && (!automatic.hash || !automatic.token)) {
        return;
    }

    function viewportBucket() {
        if (typeof window.matchMedia !== "function") {
            return "desktop";
        }
        if (window.matchMedia("(max-width: 767px)").matches) {
            return "mobile";
        }
        if (window.matchMedia("(max-width: 1024px)").matches) {
            return "tablet";
        }
        return "desktop";
    }

    var currentViewport = viewportBucket();
    if (mode === "automatic" && automatic.locked && automatic.locked[currentViewport]) {
        return;
    }
    if (mode === "manual") {
        var hasUnlockedSelector = selectors.some(function (item) {
            return !(item && item.locked && item.locked[currentViewport]);
        });
        if (!hasUnlockedSelector) {
            return;
        }
    }

    var latest = null;
    var sent = false;
    var inFlight = false;
    var observer = null;
    var fallbackTimer = 0;
    var runtimeImageRequestModes = Object.create(null);
    var restoreRuntimeImageObserver = null;

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

    function normalizeRequestCredentialsMode(value, image) {
        var mode = value === null || typeof value === "undefined" ? "" : String(value).toLowerCase();
        if (mode === "use-credentials") {
            return "use-credentials";
        }
        if (mode === "anonymous") {
            return "anonymous";
        }
        if (mode === "") {
            try {
                return image && typeof image.hasAttribute === "function" && image.hasAttribute("crossorigin") ? "anonymous" : "none";
            } catch (error) {
                return "none";
            }
        }
        return "none";
    }

    function rememberRuntimeImageRequest(url, image) {
        if (!observeRequestCredentials) {
            return;
        }
        var normalizedUrl = absoluteUrl(url);
        if (!normalizedUrl) {
            return;
        }
        var mode = normalizeRequestCredentialsMode(image ? image.crossOrigin : null, image);
        var existing = runtimeImageRequestModes[normalizedUrl];
        if (!existing) {
            runtimeImageRequestModes[normalizedUrl] = mode;
        } else if (existing !== mode) {
            runtimeImageRequestModes[normalizedUrl] = "conflict";
        }
    }

    function installRuntimeImageObserver() {
        if (!observeRequestCredentials || !window.HTMLImageElement || !window.HTMLImageElement.prototype) {
            return;
        }

        var prototype = window.HTMLImageElement.prototype;
        var srcDescriptor;
        try {
            srcDescriptor = Object.getOwnPropertyDescriptor(prototype, "src");
        } catch (error) {
            srcDescriptor = null;
        }
        var originalSetAttribute = prototype.setAttribute;
        var setAttributeDescriptor;
        try {
            setAttributeDescriptor = Object.getOwnPropertyDescriptor(prototype, "setAttribute");
        } catch (error) {
            setAttributeDescriptor = null;
        }
        var srcPatched = false;
        var setAttributePatched = false;

        if (srcDescriptor && srcDescriptor.configurable && typeof srcDescriptor.set === "function") {
            try {
                Object.defineProperty(prototype, "src", {
                    configurable: srcDescriptor.configurable,
                    enumerable: srcDescriptor.enumerable,
                    get: srcDescriptor.get,
                    set: function (value) {
                        rememberRuntimeImageRequest(value, this);
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
                        rememberRuntimeImageRequest(value, this);
                    }
                    return originalSetAttribute.call(this, name, value);
                };
                setAttributePatched = true;
            } catch (error) {}
        }

        restoreRuntimeImageObserver = function () {
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
            restoreRuntimeImageObserver = null;
        };
    }

    function requestCredentialsModeForObservation(element, resourceUrl, resourceType) {
        if (!observeRequestCredentials || ["image", "background", "poster"].indexOf(resourceType) === -1) {
            return "unknown";
        }

        var tag = element && element.tagName ? String(element.tagName).toLowerCase() : "";
        if ((tag === "img" || tag === "image") && element) {
            return normalizeRequestCredentialsMode(element.crossOrigin, element);
        }

        var normalizedUrl = absoluteUrl(resourceUrl);
        if (normalizedUrl && runtimeImageRequestModes[normalizedUrl]) {
            return runtimeImageRequestModes[normalizedUrl];
        }

        return "unavailable";
    }

    installRuntimeImageObserver();

    function extractCssUrl(value) {
        var match = String(value || "").match(/url\(\s*["']?([^"')]+)["']?\s*\)/i);
        return match && match[1] ? absoluteUrl(match[1]) : "";
    }

    function escapeIdentifier(value) {
        if (window.CSS && typeof window.CSS.escape === "function") {
            return window.CSS.escape(String(value || ""));
        }
        return String(value || "").replace(/[^A-Za-z0-9_-]/g, "\\$&");
    }

    function elementDescriptor(element) {
        if (!element || element.nodeType !== 1) {
            return "unknown";
        }
        var tag = element.tagName ? String(element.tagName).toLowerCase() : "unknown";
        if (element.id) {
            return "#" + escapeIdentifier(element.id);
        }
        var classes = [];
        if (element.classList && element.classList.length) {
            for (var i = 0; i < element.classList.length && classes.length < 3; i++) {
                var className = String(element.classList[i] || "").trim();
                if (className) {
                    classes.push("." + escapeIdentifier(className));
                }
            }
        }
        return tag + classes.join("");
    }

    function findSelectorMatch(element) {
        if (!element || element.nodeType !== 1) {
            return null;
        }
        for (var i = 0; i < selectors.length; i++) {
            var item = selectors[i] || {};
            if (item.locked && item.locked[currentViewport]) {
                continue;
            }
            try {
                if (item.selector && element.matches(item.selector)) {
                    return item;
                }
            } catch (error) {}
        }
        for (var j = 0; j < selectors.length; j++) {
            var scoped = selectors[j] || {};
            if (!scoped.selector || (scoped.locked && scoped.locked[currentViewport])) {
                continue;
            }
            try {
                var nodes = document.querySelectorAll(scoped.selector);
                for (var n = 0; n < nodes.length; n++) {
                    if (nodes[n] === element || nodes[n].contains(element)) {
                        return scoped;
                    }
                }
            } catch (error) {}
        }
        return null;
    }

    function firstVideoSourceUrl(element) {
        if (!element || typeof element.querySelector !== "function") {
            return "";
        }
        try {
            var source = element.querySelector("source[src]");
            return source ? absoluteUrl(source.currentSrc || source.src || source.getAttribute("src") || "") : "";
        } catch (error) {
            return "";
        }
    }

    function classify(entry, element) {
        var tag = element && element.tagName ? String(element.tagName).toLowerCase() : "";
        var entryUrl = entry && entry.url ? absoluteUrl(entry.url) : "";
        var url = entryUrl;
        var type = "unknown";

        if (tag === "video") {
            var posterUrl = absoluteUrl(element.poster || element.getAttribute("poster") || "");
            var videoUrl = absoluteUrl(element.currentSrc || element.src || element.getAttribute("src") || "") || firstVideoSourceUrl(element);

            if (posterUrl && (!entryUrl || entryUrl === posterUrl)) {
                url = posterUrl;
                type = "poster";
            } else {
                url = entryUrl || videoUrl || posterUrl;
                type = (entryUrl || videoUrl) ? "video" : (posterUrl ? "poster" : "unknown");
            }
        } else if (tag === "img" || tag === "image") {
            var directImageUrl = absoluteUrl(element.currentSrc || element.src || element.getAttribute("src") || "");
            url = entryUrl || directImageUrl;
            type = url ? "image" : "unknown";
        } else {
            if (!url && window.getComputedStyle) {
                try {
                    url = extractCssUrl(window.getComputedStyle(element).backgroundImage);
                } catch (error) {}
            }
            type = url ? "background" : "text";
        }
        return {
            resourceType: type,
            resourceUrl: url,
            requestCredentialsMode: requestCredentialsModeForObservation(element, url, type),
            tag: tag,
            elementSelector: elementDescriptor(element)
        };
    }

    function remember(entry) {
        var element = entry && entry.element ? entry.element : null;
        if (!element) {
            return;
        }
        var selector = mode === "automatic" ? automatic : findSelectorMatch(element);
        if (!selector || !selector.hash || !selector.token) {
            return;
        }
        latest = {selector: selector, observed: classify(entry, element)};
    }

    function clearFallbackTimer() {
        if (fallbackTimer) {
            window.clearTimeout(fallbackTimer);
            fallbackTimer = 0;
        }
    }

    function sendObservation() {
        if (sent || inFlight || !latest || !latest.selector || !latest.observed) {
            return;
        }
        clearFallbackTimer();
        if (observer && typeof observer.disconnect === "function") {
            observer.disconnect();
        }
        if (typeof restoreRuntimeImageObserver === "function") {
            restoreRuntimeImageObserver();
        }
        sent = true;
        inFlight = true;
        var body = new URLSearchParams();
        body.set("action", config.action || "ultracache_lcp_observation");
        body.set("pageUrl", config.pageUrl);
        body.set("mode", mode);
        body.set("token", latest.selector.token || "");
        body.set("selectorHash", latest.selector.hash || "");
        body.set("viewport", currentViewport);
        body.set("resourceType", latest.observed.resourceType || "unknown");
        body.set("resourceUrl", latest.observed.resourceUrl || "");
        body.set("requestCredentialsMode", latest.observed.requestCredentialsMode || "unknown");
        body.set("tag", latest.observed.tag || "");
        body.set("elementSelector", latest.observed.elementSelector || "unknown");
        try {
            fetch(config.ajaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {"Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"},
                body: body.toString(),
                keepalive: true
            }).catch(function () {}).finally(function () {
                inFlight = false;
            });
        } catch (error) {
            inFlight = false;
        }
    }

    try {
        observer = new PerformanceObserver(function (list) {
            var entries = list.getEntries();
            for (var i = 0; i < entries.length; i++) {
                remember(entries[i]);
            }
        });
        observer.observe({type: "largest-contentful-paint", buffered: true});
    } catch (error) {
        if (typeof restoreRuntimeImageObserver === "function") {
            restoreRuntimeImageObserver();
        }
        return;
    }

    function finalizeObservation() {
        if (observer && typeof observer.takeRecords === "function") {
            var pendingEntries = observer.takeRecords();
            for (var i = 0; i < pendingEntries.length; i++) {
                remember(pendingEntries[i]);
            }
        }
        sendObservation();
        if (!sent && typeof restoreRuntimeImageObserver === "function") {
            restoreRuntimeImageObserver();
        }
    }

    function scheduleTimedFinalization() {
        if (fallbackTimer || sent) {
            return;
        }
        fallbackTimer = window.setTimeout(finalizeObservation, 5000);
    }

    if (document.readyState === "complete") {
        scheduleTimedFinalization();
    } else {
        window.addEventListener("load", scheduleTimedFinalization, {once: true});
    }
}());
