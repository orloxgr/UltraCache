(function () {
    'use strict';

    if (window.__ultracacheDynamicIconFontDelay) {
        return;
    }
    window.__ultracacheDynamicIconFontDelay = 1;

    var cfg = window.ultracacheDynamicIconFontDelayConfig || {};
    var rawPatterns = Array.isArray(cfg.patterns) ? cfg.patterns : [];
    var rawExcludes = Array.isArray(cfg.excludePatterns) ? cfg.excludePatterns : [];
    var head = document.head;
    var perf = window.performance;
    var stats = {
        calls: 0,
        stylesheetChecks: 0,
        matches: 0,
        patchedRules: 0,
        totalMatchMs: 0,
        maxMatchMs: 0,
        totalPatchMs: 0,
        maxPatchMs: 0
    };

    window.ultracacheIconFontDelayTiming = stats;

    if (!head || !rawPatterns.length) {
        return;
    }

    function now() {
        return perf && typeof perf.now === 'function' ? perf.now() : Date.now();
    }

    function normalize(value) {
        return String(value || '').toLowerCase().trim();
    }

    function compact(value) {
        return normalize(value).replace(/[^a-z0-9]+/g, '');
    }

    function normalizePatterns(values) {
        var normalized = [];
        var seen = {};
        for (var i = 0; i < values.length; i++) {
            var raw = normalize(values[i]);
            var packed = compact(values[i]);
            if (!raw || (!packed && raw.length < 2)) {
                continue;
            }
            var key = raw + '|' + packed;
            if (seen[key]) {
                continue;
            }
            seen[key] = true;
            normalized.push({ raw: raw, compact: packed });
        }
        return normalized;
    }

    var patterns = normalizePatterns(rawPatterns);
    var excludes = normalizePatterns(rawExcludes);

    if (!patterns.length) {
        return;
    }

    function matchesPattern(haystack, haystackCompact, entries) {
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].raw && haystack.indexOf(entries[i].raw) !== -1) {
                return true;
            }
            if (entries[i].compact && haystackCompact.indexOf(entries[i].compact) !== -1) {
                return true;
            }
        }
        return false;
    }

    function getStylesheetHref(node) {
        if (!node || node.nodeType !== 1 || normalize(node.tagName) !== 'link') {
            return '';
        }
        var rel = normalize(node.getAttribute && node.getAttribute('rel'));
        if (rel.indexOf('stylesheet') === -1) {
            return '';
        }
        return String((node.getAttribute && node.getAttribute('href')) || node.href || '');
    }

    function isSameOrigin(href) {
        try {
            var absolute = new URL(href, document.baseURI);
            return absolute.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function shouldIntercept(node) {
        var startedAt = now();
        var result = false;
        stats.calls++;

        try {
            var href = getStylesheetHref(node);
            if (!href) {
                return false;
            }
            stats.stylesheetChecks++;
            if (!isSameOrigin(href)) {
                return false;
            }

            var normalizedHref = normalize(href);
            var compactHref = compact(href);
            if (matchesPattern(normalizedHref, compactHref, excludes)) {
                return false;
            }
            result = matchesPattern(normalizedHref, compactHref, patterns);
            return result;
        } finally {
            var duration = Math.max(0, now() - startedAt);
            stats.totalMatchMs += duration;
            stats.maxMatchMs = Math.max(stats.maxMatchMs, duration);
        }
    }

    function patchRuleList(rules, depth) {
        if (!rules || depth > 4) {
            return 0;
        }
        var patched = 0;
        var length = Math.min(rules.length || 0, 600);

        for (var i = 0; i < length; i++) {
            var rule = rules[i];
            if (!rule) {
                continue;
            }

            var cssText = String(rule.cssText || '');
            var isFontFace = (typeof CSSRule !== 'undefined' && CSSRule.FONT_FACE_RULE && rule.type === CSSRule.FONT_FACE_RULE)
                || /^\s*@font-face\b/i.test(cssText);

            if (isFontFace && rule.style && typeof rule.style.setProperty === 'function') {
                try {
                    rule.style.setProperty('font-display', 'swap');
                    patched++;
                } catch (e) {}
                continue;
            }

            if (rule.cssRules) {
                try {
                    patched += patchRuleList(rule.cssRules, depth + 1);
                } catch (e) {}
            }
        }

        return patched;
    }

    function publishMeasure(node, duration, patched) {
        if (!perf || typeof perf.measure !== 'function') {
            return;
        }
        try {
            var href = getStylesheetHref(node);
            var label = href ? href.split('?')[0].split('/').pop() : 'stylesheet';
            perf.measure('UltraCache icon font interception: ' + label, {
                start: Math.max(0, now() - duration),
                duration: duration,
                detail: {
                    patchedRules: patched,
                    matches: stats.matches,
                    totalMatchMs: stats.totalMatchMs,
                    totalPatchMs: stats.totalPatchMs
                }
            });
        } catch (e) {}
    }

    function prepareMatchedStylesheet(node) {
        if (!node || node.__ultracacheDynamicIconFontPrepared) {
            return;
        }
        node.__ultracacheDynamicIconFontPrepared = true;
        stats.matches++;

        var originalMedia = String((node.getAttribute && node.getAttribute('media')) || node.media || '');
        var originalOnload = node.onload;

        try {
            node.setAttribute('media', 'print');
            node.setAttribute('data-ultracache-dynamic-icon-font', '1');
        } catch (e) {
            node.media = 'print';
        }

        if (typeof originalOnload === 'function') {
            node.onload = null;
        }

        var completed = false;
        var complete = function (event) {
            if (completed) {
                return;
            }
            completed = true;

            var startedAt = now();
            var patched = 0;
            try {
                patched = patchRuleList(node.sheet && (node.sheet.cssRules || node.sheet.rules), 0);
            } catch (e) {}

            try {
                node.setAttribute('media', originalMedia || 'all');
                node.setAttribute('data-ultracache-font-display-patched-rules', String(patched));
            } catch (e) {
                node.media = originalMedia || 'all';
            }

            var duration = Math.max(0, now() - startedAt);
            stats.patchedRules += patched;
            stats.totalPatchMs += duration;
            stats.maxPatchMs = Math.max(stats.maxPatchMs, duration);
            publishMeasure(node, duration, patched);

            if (typeof originalOnload === 'function') {
                try {
                    originalOnload.call(node, event);
                } catch (e) {
                    setTimeout(function () {
                        throw e;
                    }, 0);
                }
            }
        };

        if (typeof node.addEventListener === 'function') {
            node.addEventListener('load', complete, { once: true });
        } else {
            node.onload = complete;
        }
    }

    function wrapInsertion(methodName) {
        var original = head[methodName];
        if (typeof original !== 'function' || original.__ultracacheDynamicIconFontWrapped) {
            return;
        }

        var wrapped = function () {
            var node = arguments[0];
            if (shouldIntercept(node)) {
                prepareMatchedStylesheet(node);
            }
            return original.apply(this, arguments);
        };
        wrapped.__ultracacheDynamicIconFontWrapped = true;
        head[methodName] = wrapped;
    }

    wrapInsertion('appendChild');
    wrapInsertion('insertBefore');
}());
