(function () {
    "use strict";

    if (window.__ultracacheLcpObserverV119) {
        return;
    }
    window.__ultracacheLcpObserverV119 = 1;

    var config = window.ultracacheLcpObserverConfig || {};
    var mode = config.mode === "automatic" ? "automatic" : "manual";
    var selectors = Array.isArray(config.manualSelectors) ? config.manualSelectors : [];
    var automatic = config.automatic && typeof config.automatic === "object" ? config.automatic : {};

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

    function classify(entry, element) {
        var tag = element && element.tagName ? String(element.tagName).toLowerCase() : "";
        var url = entry && entry.url ? absoluteUrl(entry.url) : "";
        var directImageUrl = element ? absoluteUrl(element.currentSrc || element.src || element.getAttribute("src") || "") : "";
        var type = "unknown";
        if (tag === "img" || tag === "image" || directImageUrl) {
            url = url || directImageUrl;
            type = url ? "image" : "unknown";
        } else if (tag === "video") {
            url = url || absoluteUrl(element.poster || element.getAttribute("poster") || "");
            type = url ? "poster" : "unknown";
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

    function sendObservation() {
        if (sent || inFlight || !latest || !latest.selector || !latest.observed) {
            return;
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
        var observer = new PerformanceObserver(function (list) {
            var entries = list.getEntries();
            for (var i = 0; i < entries.length; i++) {
                remember(entries[i]);
            }
        });
        observer.observe({type: "largest-contentful-paint", buffered: true});
    } catch (error) {
        return;
    }

    window.addEventListener("load", function () {
        window.setTimeout(sendObservation, 2500);
    }, {once: true});
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "hidden") {
            sendObservation();
        }
    });
    window.addEventListener("pagehide", sendObservation, {once: true});
}());
