/*
 * UltraCache controlled performance A/B browser probe.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Run in DevTools Console or as a DevTools Snippet on the page under test.
 * For comparable evidence, execute once before visitor interaction and again
 * after the configured delayed-JS trigger. Results are stored in
 * window.__ultracachePerformanceABSnapshots.
 */
(function ultracachePerformanceABProbe() {
    'use strict';

    var root = document.documentElement;
    var now = (window.performance && typeof window.performance.now === 'function')
        ? window.performance.now()
        : 0;

    function toNumber(value) {
        var n = Number(value);
        return Number.isFinite(n) ? Math.round(n * 100) / 100 : null;
    }

    function resourceMatches(name, patterns) {
        var hay = String(name || '').toLowerCase();
        return patterns.some(function (pattern) { return hay.indexOf(pattern) !== -1; });
    }

    function collectResources(patterns) {
        if (!window.performance || typeof window.performance.getEntriesByType !== 'function') {
            return [];
        }
        return window.performance.getEntriesByType('resource')
            .filter(function (entry) { return resourceMatches(entry.name, patterns); })
            .map(function (entry) {
                return {
                    name: entry.name,
                    initiatorType: entry.initiatorType || '',
                    startTimeMs: toNumber(entry.startTime),
                    durationMs: toNumber(entry.duration),
                    transferSize: Number(entry.transferSize || 0),
                    encodedBodySize: Number(entry.encodedBodySize || 0),
                    decodedBodySize: Number(entry.decodedBodySize || 0)
                };
            });
    }

    function collectUltraCacheMarkers() {
        var result = {};
        if (!root || !root.dataset) {
            return result;
        }
        Object.keys(root.dataset).forEach(function (key) {
            if (key.toLowerCase().indexOf('ultracache') === 0) {
                result[key] = root.dataset[key];
            }
        });
        return result;
    }

    function scriptDescriptor(node) {
        return {
            src: node.getAttribute('src') || '',
            delayedSrc: node.getAttribute('data-ultracache-src') || '',
            handle: node.getAttribute('data-ultracache-handle') || '',
            type: node.getAttribute('type') || '',
            delayReason: node.getAttribute('data-ultracache-delay-reason') || '',
            defer: !!node.defer,
            async: !!node.async,
            className: node.getAttribute('class') || '',
            consentCategory: node.getAttribute('data-consent-category') || node.getAttribute('data-category') || '',
            consentService: node.getAttribute('data-service') || ''
        };
    }

    function collectDelayedScripts() {
        return Array.prototype.slice.call(document.querySelectorAll('script')).filter(function (node) {
            return node.hasAttribute('data-ultracache-src') ||
                node.hasAttribute('data-ultracache-handle') ||
                node.hasAttribute('data-ultracache-delay-reason') ||
                String(node.getAttribute('type') || '').toLowerCase().indexOf('ultracache') !== -1;
        }).map(scriptDescriptor);
    }

    function summarizeDataLayer() {
        var dl = Array.isArray(window.dataLayer) ? window.dataLayer : [];
        var summary = {
            length: dl.length,
            consentDefaultIndexes: [],
            consentUpdateIndexes: [],
            jsBootIndexes: []
        };
        dl.forEach(function (entry, index) {
            try {
                if (entry && entry[0] === 'consent' && entry[1] === 'default') {
                    summary.consentDefaultIndexes.push(index);
                }
                if (entry && entry[0] === 'consent' && entry[1] === 'update') {
                    summary.consentUpdateIndexes.push(index);
                }
                if (entry && entry[0] === 'js') {
                    summary.jsBootIndexes.push(index);
                }
            } catch (e) {}
        });
        return summary;
    }

    var trackerPatterns = [
        'googletagmanager.com', 'google-analytics.com', 'doubleclick.net',
        'connect.facebook.net', 'analytics.tiktok.com', 'clarity.ms',
        'hotjar.com', 'snap.licdn.com', 'bat.bing.com', 'googleadservices.com'
    ];
    var consentPatterns = [
        'complianz', 'cmplz', 'real-cookie-banner', 'devowl', 'wp-consent-api',
        'googlesitekit-consent-mode', 'cookiebot', 'cookielaw.org', 'iubenda'
    ];

    var navigation = null;
    if (window.performance && typeof window.performance.getEntriesByType === 'function') {
        var navEntries = window.performance.getEntriesByType('navigation');
        if (navEntries && navEntries[0]) {
            navigation = {
                domInteractiveMs: toNumber(navEntries[0].domInteractive),
                domContentLoadedMs: toNumber(navEntries[0].domContentLoadedEventEnd),
                loadEventMs: toNumber(navEntries[0].loadEventEnd)
            };
        }
    }

    var snapshot = {
        capturedAt: new Date().toISOString(),
        performanceNowMs: toNumber(now),
        url: location.href,
        readyState: document.readyState,
        userAgent: navigator.userAgent,
        navigation: navigation,
        dom: {
            totalElements: document.querySelectorAll('*').length,
            scripts: document.scripts.length,
            swiperDuplicateSlides: document.querySelectorAll('.swiper-slide-duplicate').length,
            swiperSlides: document.querySelectorAll('.swiper-slide').length
        },
        loaderMarkers: collectUltraCacheMarkers(),
        delayedScripts: collectDelayedScripts(),
        trackerResources: collectResources(trackerPatterns),
        consentResources: collectResources(consentPatterns),
        dataLayer: summarizeDataLayer()
    };

    if (!Array.isArray(window.__ultracachePerformanceABSnapshots)) {
        window.__ultracachePerformanceABSnapshots = [];
    }
    window.__ultracachePerformanceABSnapshots.push(snapshot);

    console.log('[UltraCache A/B probe] snapshot #' + window.__ultracachePerformanceABSnapshots.length, snapshot);
    console.table(snapshot.trackerResources.map(function (entry) {
        return {
            resource: entry.name.split('/').slice(-1)[0].slice(0, 80),
            startMs: entry.startTimeMs,
            durationMs: entry.durationMs,
            transferKiB: Math.round((entry.transferSize || 0) / 1024 * 10) / 10
        };
    }));
    console.log('[UltraCache A/B probe] DOM', snapshot.dom);
    console.log('[UltraCache A/B probe] loader markers', snapshot.loaderMarkers);
    console.log('[UltraCache A/B probe] dataLayer', snapshot.dataLayer);

    return snapshot;
}());
