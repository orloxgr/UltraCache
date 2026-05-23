(function () {
    "use strict";

    if (window.__ucwpSr7LcpPriorityV107) {
        return;
    }

    window.__ucwpSr7LcpPriorityV107 = 1;

    var config = window.ucwpSr7LcpPriorityConfig || {};
    var manualSelectors = Array.isArray(config.manualSelectors) ? config.manualSelectors : [];

    function tag(node) {
        return node && node.tagName ? String(node.tagName).toLowerCase() : "";
    }

    function abs(url) {
        try {
            return new URL(String(url || ""), document.baseURI).href;
        } catch (error) {
            return String(url || "");
        }
    }

    function clean(url) {
        url = abs(url).split("#")[0].split("?")[0];
        return url.replace(/^https?:\/\/[^/]+/i, "");
    }

    function imageUrl(node) {
        try {
            if (!node) {
                return "";
            }

            var value = node.currentSrc || node.src || "";
            if (!value && node.getAttribute) {
                value = node.getAttribute("src") || node.getAttribute("data-src") || node.getAttribute("data-bg") || node.getAttribute("data-background") || node.getAttribute("data-bg-image") || "";
            }

            if (!value && node.style && node.style.backgroundImage) {
                var match = String(node.style.backgroundImage).match(/url\(["']?([^"')]+)["']?\)/i);
                value = match && match[1] ? match[1] : "";
            }

            return value;
        } catch (error) {
            return "";
        }
    }

    function getPreloadUrl() {
        try {
            var link = document.querySelector('link[rel="preload"][as="image"][data-ucwp-lcp-preload="1"]');
            return link ? (link.href || link.getAttribute("href") || "") : "";
        } catch (error) {
            return "";
        }
    }

    function addScope(output, node) {
        if (!node || node.nodeType !== 1) {
            return;
        }

        for (var i = 0; i < output.length; i++) {
            if (output[i] === node) {
                return;
            }
        }

        output.push(node);
    }

    function getScopes() {
        var output = [];

        if (manualSelectors && manualSelectors.length) {
            for (var i = 0; i < manualSelectors.length; i++) {
                try {
                    document.querySelectorAll(manualSelectors[i]).forEach(function (node) {
                        addScope(output, node);
                    });
                } catch (error) {}
            }
        }

        try {
            document.querySelectorAll("sr7-module,rs-module").forEach(function (node) {
                addScope(output, node);
            });
        } catch (error) {}

        return output;
    }

    function collect(scope) {
        var nodes = [];

        try {
            if (/^(sr7-module-bg|sr7-img|img)$/i.test(tag(scope))) {
                nodes.push(scope);
            }

            if (scope && scope.querySelectorAll) {
                scope.querySelectorAll("sr7-module-bg,sr7-img,img").forEach(function (node) {
                    nodes.push(node);
                });
            }
        } catch (error) {}

        return nodes;
    }

    function matchesPreload(node, preloadUrl) {
        var url = imageUrl(node);
        if (!url || !preloadUrl) {
            return false;
        }

        return clean(url) === clean(preloadUrl) || abs(url) === abs(preloadUrl);
    }

    function scoreNoLayout(node, preloadUrl) {
        var nodeTag = tag(node);
        var url = imageUrl(node).toLowerCase();
        var score = 0;

        if (matchesPreload(node, preloadUrl)) {
            score += 1000000;
        }

        if (nodeTag === "sr7-module-bg") {
            score += 250000;
        } else if (nodeTag === "sr7-img") {
            score += 50000;
        }

        if (url.indexOf("revslider/o/") !== -1) {
            score -= 180000;
        }

        if (/book|cover|product|thumb|thumbnail|logo|icon|avatar/.test(url)) {
            score -= 120000;
        }

        if (/lcp|hero|background|bg|banner/.test(url)) {
            score += 90000;
        }

        return score;
    }

    function findBest(preloadUrl) {
        var scopes = getScopes();
        var best = null;
        var bestScore = -999999999;

        for (var i = 0; i < scopes.length; i++) {
            var nodes = collect(scopes[i]);
            for (var j = 0; j < nodes.length; j++) {
                var url = imageUrl(nodes[j]);
                if (!/\.(avif|webp|png|jpe?g|gif)(\?|#|$)/i.test(url)) {
                    continue;
                }

                var score = scoreNoLayout(nodes[j], preloadUrl);
                if (score > bestScore) {
                    best = nodes[j];
                    bestScore = score;
                }
            }
        }

        return best;
    }

    function mark(node, preloadUrl) {
        try {
            if (!node || node.nodeType !== 1) {
                return false;
            }

            if (!node.hasAttribute("fetchpriority")) {
                node.setAttribute("fetchpriority", "high");
                node.setAttribute("data-ucwp-added-fetchpriority", "1");
            } else if (node.getAttribute("fetchpriority") !== "high") {
                node.setAttribute("fetchpriority", "high");
            }

            node.setAttribute("data-ucwp-sr7-lcp", "1");
            node.setAttribute("data-ucwp-sr7-role", matchesPreload(node, preloadUrl) ? "preload-matched" : "preload-scoped");
            node.setAttribute("data-ucwp-lcp-runtime-winner", "1");
            node.setAttribute("data-ucwp-lcp-reason", matchesPreload(node, preloadUrl) ? "sr7-preload-matched-runtime" : "sr7-preload-scoped-runtime");

            if ((tag(node) === "img" || tag(node) === "sr7-img") && (!node.hasAttribute("loading") || node.getAttribute("loading") === "lazy")) {
                node.setAttribute("loading", "eager");
            }

            if (!node.hasAttribute("decoding")) {
                node.setAttribute("decoding", "sync");
            }

            window.__ucwpLcpDiscovery = window.__ucwpLcpDiscovery || {};
            window.__ucwpLcpDiscovery.runtimeWinner = {
                url: imageUrl(node),
                preload: preloadUrl || "",
                tag: tag(node),
                id: node.id || "",
                role: node.getAttribute("data-ucwp-sr7-role") || "",
                reason: node.getAttribute("data-ucwp-lcp-reason") || ""
            };

            return true;
        } catch (error) {
            return false;
        }
    }

    function run() {
        var preloadUrl = getPreloadUrl();
        var node = findBest(preloadUrl);
        if (node) {
            mark(node, preloadUrl);
            return true;
        }

        return false;
    }

    function schedule() {
        try {
            run();
        } catch (error) {}
    }

    document.addEventListener("sr.module.ready", schedule, true);
    document.addEventListener("SR7_MODULE_READY", schedule, true);
    document.addEventListener("DOMContentLoaded", schedule, { once: true });

    if (document.readyState !== "loading") {
        schedule();
    }

    var tries = [100, 400, 1000, 2200];
    for (var index = 0; index < tries.length; index++) {
        setTimeout(schedule, tries[index]);
    }
}());
