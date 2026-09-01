(function (window, document) {
    'use strict';

    if (window.__ultracacheDynamicScriptFinderV31211) {
        return;
    }

    function decodeConfig(encoded) {
        encoded = String(encoded || '').trim();
        if (!encoded) {
            return {};
        }
        try {
            encoded = encoded.replace(/-/g, '+').replace(/_/g, '/');
            while (encoded.length % 4) {
                encoded += '=';
            }
            var binary = window.atob(encoded);
            var json = binary;
            try {
                json = decodeURIComponent(Array.prototype.map.call(binary, function (ch) {
                    return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
                }).join(''));
            } catch (e) {}
            return JSON.parse(json) || {};
        } catch (e) {
            return {};
        }
    }

    var config = window.ultracacheDynamicScriptFinderConfig || {};
    var bootstrapPolicy = decodeConfig(config.encodedPolicy || '');
    var nativePatterns = Array.isArray(bootstrapPolicy.nativePatterns) ? bootstrapPolicy.nativePatterns.map(function (item) {
        return String(item || '').trim().toLowerCase();
    }).filter(Boolean) : [];
    var realCookieBannerCompatibility = !!bootstrapPolicy.realCookieBannerCompatibility;
    var realCookieBannerInfrastructurePatterns = Array.isArray(bootstrapPolicy.realCookieBannerInfrastructurePatterns) ? bootstrapPolicy.realCookieBannerInfrastructurePatterns.map(function (item) {
        return String(item || '').trim().toLowerCase();
    }).filter(Boolean) : [];
    var complianzCompatibility = !!bootstrapPolicy.complianzCompatibility;
    var complianzInfrastructurePatterns = Array.isArray(bootstrapPolicy.complianzInfrastructurePatterns) ? bootstrapPolicy.complianzInfrastructurePatterns.map(function (item) {
        return String(item || '').trim().toLowerCase();
    }).filter(Boolean) : [];
    var releasePhase = 0;
    var classifier = null;
    var executor = null;
    var auditRecorder = null;
    var internalMutation = false;
    var registrySequence = 0;
    var registryStats = { seen: 0, native: 0, defer: 0, delay: 0, pending: 0, escaped: 0 };
    var registryWeak = typeof window.WeakMap === 'function' ? new window.WeakMap() : null;
    var pendingConnectedDispatch = [];

    var NodeProto = window.Node && window.Node.prototype;
    var ElementProto = window.Element && window.Element.prototype;
    var ScriptProto = window.HTMLScriptElement && window.HTMLScriptElement.prototype;
    var DocumentProto = window.Document && window.Document.prototype;
    var DocumentFragmentProto = window.DocumentFragment && window.DocumentFragment.prototype;
    var RangeProto = window.Range && window.Range.prototype;
    var appendChild = NodeProto && NodeProto.appendChild;
    var insertBefore = NodeProto && NodeProto.insertBefore;
    var replaceChild = NodeProto && NodeProto.replaceChild;
    var setAttribute = ElementProto && ElementProto.setAttribute;
    var getAttribute = ElementProto && ElementProto.getAttribute;
    var removeAttribute = ElementProto && ElementProto.removeAttribute;
    var hasAttribute = ElementProto && ElementProto.hasAttribute;
    var write = DocumentProto && DocumentProto.write;
    var writeln = DocumentProto && DocumentProto.writeln;
    var srcDescriptor = ScriptProto ? Object.getOwnPropertyDescriptor(ScriptProto, 'src') : null;
    var elementAppend = ElementProto && ElementProto.append;
    var elementPrepend = ElementProto && ElementProto.prepend;
    var elementBefore = ElementProto && ElementProto.before;
    var elementAfter = ElementProto && ElementProto.after;
    var elementReplaceWith = ElementProto && ElementProto.replaceWith;
    var elementReplaceChildren = ElementProto && ElementProto.replaceChildren;
    var elementInsertAdjacentElement = ElementProto && ElementProto.insertAdjacentElement;
    var documentAppend = DocumentProto && DocumentProto.append;
    var documentPrepend = DocumentProto && DocumentProto.prepend;
    var documentReplaceChildren = DocumentProto && DocumentProto.replaceChildren;
    var fragmentAppend = DocumentFragmentProto && DocumentFragmentProto.append;
    var fragmentPrepend = DocumentFragmentProto && DocumentFragmentProto.prepend;
    var fragmentReplaceChildren = DocumentFragmentProto && DocumentFragmentProto.replaceChildren;
    var rangeInsertNode = RangeProto && RangeProto.insertNode;

    function attr(node, name) {
        try {
            return String(getAttribute.call(node, name) || '');
        } catch (e) {
            return '';
        }
    }

    function has(node, name) {
        try {
            return !!hasAttribute.call(node, name);
        } catch (e) {
            return false;
        }
    }

    function put(node, name, value) {
        internalMutation = true;
        try { setAttribute.call(node, name, String(value)); } catch (e) {}
        internalMutation = false;
    }

    function remove(node, name) {
        internalMutation = true;
        try { removeAttribute.call(node, name); } catch (e) {}
        internalMutation = false;
    }

    function isScript(node) {
        return !!(node && String(node.tagName || node.nodeName || '').toLowerCase() === 'script');
    }

    function executable(node) {
        var type = attr(node, 'type').toLowerCase().split(';')[0].trim();
        return !type || type === 'module' || type === 'text/javascript' || type === 'application/javascript' || type === 'application/ecmascript' || type === 'text/ecmascript' || type === 'text/jscript' || type === 'text/livescript';
    }

    function hasExecutablePayload(node, prospectiveSrc) {
        if (String(prospectiveSrc || attr(node, 'src') || '').trim()) {
            return true;
        }
        return String(node && (node.text || node.textContent) || '').trim() !== '';
    }

    function authorOptOut(node) {
        return has(node, 'data-no-defer')
            || has(node, 'data-noptimize')
            || attr(node, 'data-cfasync').toLowerCase() === 'false';
    }

    function internalBypass(node) {
        return attr(node, 'data-ultracache-finder-bypass') === '1'
            || attr(node, 'data-ultracache-delayed') === '1';
    }

    function registryEntry(node) {
        if (!node) {
            return null;
        }
        var entry = null;
        if (registryWeak) {
            try { entry = registryWeak.get(node) || null; } catch (e) {}
        } else {
            try { entry = node.__ultracacheRuntimeRegistryEntry || null; } catch (e) {}
        }
        if (!entry) {
            entry = { id: ++registrySequence, seen: false, lane: '', pending: false, escaped: false };
            if (registryWeak) {
                try { registryWeak.set(node, entry); } catch (e) {}
            } else {
                try { node.__ultracacheRuntimeRegistryEntry = entry; } catch (e) {}
            }
        }
        return entry;
    }

    function registerSeen(node) {
        var entry = registryEntry(node);
        if (entry && !entry.seen) {
            entry.seen = true;
            registryStats.seen++;
            put(node, 'data-ultracache-runtime-seen', '1');
        }
        return entry;
    }

    function registerPending(node) {
        var entry = registerSeen(node);
        if (!entry) {
            return;
        }
        if (!entry.pending) {
            entry.pending = true;
            registryStats.pending++;
        }
        put(node, 'data-ultracache-runtime-lane', 'pending');
        put(node, 'data-ultracache-runtime-reason', 'dynamic-unclassified');
    }

    function registerFinal(node, route, caughtBy) {
        var lane = route && ['native', 'defer', 'delay'].indexOf(String(route.lane || '').toLowerCase()) !== -1
            ? String(route.lane || '').toLowerCase()
            : '';
        var entry = registerSeen(node);
        if (!entry || !lane) {
            if (entry && !entry.escaped) {
                entry.escaped = true;
                registryStats.escaped++;
            }
            put(node, 'data-ultracache-runtime-escaped', '1');
            return false;
        }
        if (entry.pending) {
            entry.pending = false;
            registryStats.pending = Math.max(0, registryStats.pending - 1);
        }
        if (entry.lane && registryStats[entry.lane] > 0) {
            registryStats[entry.lane]--;
        }
        entry.lane = lane;
        registryStats[lane]++;
        put(node, 'data-ultracache-runtime-lane', lane);
        put(node, 'data-ultracache-runtime-reason', String(route.reason || 'dynamic-classified'));
        put(node, 'data-ultracache-runtime-caught-by', String(caughtBy || 'dynamic-finder'));
        put(node, 'data-ultracache-runtime-interaction-eligible', route.interactionEligible === false ? '0' : '1');
        if (route.ruleId) {
            put(node, 'data-ultracache-runtime-rule', String(route.ruleId));
        }
        if (route.matchedPattern) {
            put(node, 'data-ultracache-runtime-pattern', String(route.matchedPattern));
        }
        return true;
    }

    function registrySnapshot() {
        var classified = registryStats.native + registryStats.defer + registryStats.delay;
        return {
            seen: registryStats.seen,
            native: registryStats.native,
            defer: registryStats.defer,
            delay: registryStats.delay,
            pending: registryStats.pending,
            escaped: registryStats.escaped,
            classified: classified,
            invariantPassed: registryStats.escaped === 0 && registryStats.pending === 0 && registryStats.seen === classified
        };
    }

    function controlledRouteNeedsDispatch(route) {
        if (!route) {
            return false;
        }
        if (route.lane === 'defer') {
            return true;
        }
        return route.lane === 'delay' && canExecute(route);
    }

    function heldRoute(node) {
        if (!node || attr(node, 'data-ultracache-dynamic') !== '1' || attr(node, 'type') !== 'text/ultracache-delayed-js') {
            return null;
        }
        var lane = attr(node, 'data-ultracache-runtime-lane').toLowerCase();
        if (['defer', 'delay'].indexOf(lane) === -1) {
            return null;
        }
        return {
            lane: lane,
            reason: attr(node, 'data-ultracache-runtime-reason') || 'dynamic-classified',
            ruleId: attr(node, 'data-ultracache-runtime-rule'),
            matchedPattern: attr(node, 'data-ultracache-runtime-pattern'),
            interactionEligible: attr(node, 'data-ultracache-runtime-interaction-eligible') !== '0'
        };
    }

    function dispatchControlled(node, route) {
        if (!node || !route || typeof executor !== 'function') {
            return false;
        }
        try {
            executor(node, route);
            return true;
        } catch (e) {
            var entry = registryEntry(node);
            if (entry && !entry.escaped) {
                entry.escaped = true;
                registryStats.escaped++;
            }
            put(node, 'data-ultracache-runtime-escaped', '1');
            return false;
        }
    }

    function notifyAudit(node, route, prospectiveSrc, caughtBy) {
        if (typeof auditRecorder !== 'function' || !route || ['native', 'defer', 'delay'].indexOf(String(route.lane || '').toLowerCase()) === -1) {
            return;
        }
        try {
            auditRecorder(node, route, String(prospectiveSrc || attr(node, 'src') || attr(node, 'data-ultracache-src') || ''), String(caughtBy || 'dynamic-finder'));
        } catch (e) {}
    }

    function runtimeCandidateHaystack(node, src) {
        var haystack = String(src || attr(node, 'src') || '') + ' ' + attr(node, 'id') + ' ';
        if (!src) {
            haystack += String(node.text || node.textContent || '');
        }
        if (node.attributes) {
            try {
                for (var i = 0; i < node.attributes.length; i++) {
                    haystack += ' ' + node.attributes[i].name + '=' + String(node.attributes[i].value || '');
                }
            } catch (e) {}
        }
        return haystack.toLowerCase();
    }

    function firstRuntimePattern(haystack, patterns) {
        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i] && haystack.indexOf(patterns[i]) !== -1) {
                return patterns[i];
            }
        }
        return '';
    }

    function visibleNative(node, src) {
        return !!firstRuntimePattern(runtimeCandidateHaystack(node, src), nativePatterns);
    }

    function realCookieBannerInfrastructure(node, src) {
        if (!realCookieBannerCompatibility || !realCookieBannerInfrastructurePatterns.length) {
            return '';
        }
        return firstRuntimePattern(runtimeCandidateHaystack(node, src), realCookieBannerInfrastructurePatterns);
    }

    function complianzInfrastructure(node, src) {
        if (!complianzCompatibility || !complianzInfrastructurePatterns.length) {
            return '';
        }
        return firstRuntimePattern(runtimeCandidateHaystack(node, src), complianzInfrastructurePatterns);
    }

    function encodeAttrs(node) {
        var attrs = {};
        try {
            for (var i = 0; node.attributes && i < node.attributes.length; i++) {
                var item = node.attributes[i];
                if (!item || !item.name) {
                    continue;
                }
                var name = String(item.name).toLowerCase();
                if (name === 'src' || name.indexOf('data-ultracache-') === 0) {
                    continue;
                }
                attrs[item.name] = String(item.value || '');
            }
            return window.btoa(unescape(encodeURIComponent(JSON.stringify(attrs))));
        } catch (e) {
            return '';
        }
    }

    function canExecute(route) {
        if (!route || route.lane !== 'delay') {
            return true;
        }
        return route.interactionEligible ? releasePhase >= 1 : releasePhase >= 2;
    }

    function hold(node, route, prospectiveSrc) {
        var src = String(prospectiveSrc || attr(node, 'src') || '');
        var attrs = encodeAttrs(node);
        var originalType = attr(node, 'type');
        if (attrs) {
            put(node, 'data-ultracache-attrs', attrs);
        }
        put(node, 'data-ultracache-original-type', originalType);
        if (src) {
            put(node, 'data-ultracache-src', src);
            remove(node, 'src');
        } else {
            put(node, 'data-ultracache-inline', '1');
        }
        put(node, 'data-ultracache-dynamic', '1');
        if (!route || route.lane === 'pending') {
            put(node, 'data-ultracache-dynamic-unclassified', '1');
            put(node, 'data-ultracache-delay-reason', 'dynamic-unclassified');
        } else {
            put(node, 'data-ultracache-delay-reason', route.reason || 'dynamic-delay');
            if (route.matchedPattern) {
                put(node, 'data-ultracache-delay-pattern', route.matchedPattern);
            }
        }
        put(node, 'type', 'text/ultracache-delayed-js');
    }

    function prepare(node, prospectiveSrc, caughtBy) {
        caughtBy = String(caughtBy || 'dynamic-finder');
        if (!isScript(node) || !executable(node) || !hasExecutablePayload(node, prospectiveSrc)) {
            return { lane: 'native', executable: false };
        }
        if (internalBypass(node)) {
            return { lane: 'native', executable: true, internal: true };
        }
        registerSeen(node);
        if (authorOptOut(node)) {
            var optOutRoute = { lane: 'native', reason: 'explicit-author-optimizer-opt-out', ruleId: 'explicit-author-opt-out', interactionEligible: true };
            registerFinal(node, optOutRoute, caughtBy);
            notifyAudit(node, optOutRoute, prospectiveSrc, caughtBy);
            return optOutRoute;
        }
        if (visibleNative(node, prospectiveSrc)) {
            var nativeRoute = { lane: 'native', reason: 'visible-do-not-defer-or-delay', ruleId: 'visible-native', interactionEligible: true };
            registerFinal(node, nativeRoute, caughtBy);
            notifyAudit(node, nativeRoute, prospectiveSrc, caughtBy);
            return nativeRoute;
        }
        var rcbInfrastructurePattern = realCookieBannerInfrastructure(node, prospectiveSrc);
        if (rcbInfrastructurePattern) {
            var rcbRoute = { lane: 'native', reason: 'explicit-real-cookie-banner-compatibility', ruleId: 'explicit-integration', matchedPattern: rcbInfrastructurePattern, interactionEligible: true };
            registerFinal(node, rcbRoute, caughtBy);
            notifyAudit(node, rcbRoute, prospectiveSrc, caughtBy);
            return rcbRoute;
        }
        var complianzInfrastructurePattern = complianzInfrastructure(node, prospectiveSrc);
        if (complianzInfrastructurePattern) {
            var complianzRoute = { lane: 'native', reason: 'explicit-complianz-compatibility', ruleId: 'explicit-integration', matchedPattern: complianzInfrastructurePattern, interactionEligible: true };
            registerFinal(node, complianzRoute, caughtBy);
            notifyAudit(node, complianzRoute, prospectiveSrc, caughtBy);
            return complianzRoute;
        }
        var route = classifier ? classifier(node, String(prospectiveSrc || attr(node, 'src') || ''), false) : { lane: 'pending' };
        if (!route || ['pending', 'native', 'defer', 'delay'].indexOf(String(route.lane || '').toLowerCase()) === -1) {
            route = { lane: 'native', reason: 'dynamic-classification-failsafe', ruleId: 'dynamic-failsafe', interactionEligible: true };
        }
        route.lane = String(route.lane || '').toLowerCase();
        if (route.lane === 'pending') {
            registerPending(node);
            hold(node, route, prospectiveSrc);
            return route;
        }
        registerFinal(node, route, caughtBy);
        notifyAudit(node, route, prospectiveSrc, caughtBy);
        if (route.lane === 'defer' || route.lane === 'delay') {
            hold(node, route, prospectiveSrc);
        }
        return route;
    }

    function prepareTree(node, caughtBy) {
        var actions = [];
        if (!node || !isScript(node)) {
            return actions;
        }
        var route = heldRoute(node) || prepare(node, '', caughtBy);
        if (route && controlledRouteNeedsDispatch(route)) {
            actions.push({ node: node, route: route });
        }
        return actions;
    }

    function rememberPendingConnectedDispatch(node) {
        if (!node || pendingConnectedDispatch.indexOf(node) !== -1) {
            return;
        }
        pendingConnectedDispatch.push(node);
        if (pendingConnectedDispatch.length > 256) {
            pendingConnectedDispatch.shift();
        }
    }

    function dispatchPendingConnected() {
        if (!pendingConnectedDispatch.length) {
            return;
        }
        var remaining = [];
        for (var i = 0; i < pendingConnectedDispatch.length; i++) {
            var node = pendingConnectedDispatch[i];
            if (!node) {
                continue;
            }
            if (!node.isConnected) {
                remaining.push(node);
                continue;
            }
            var route = heldRoute(node);
            if (route && controlledRouteNeedsDispatch(route)) {
                dispatchControlled(node, route);
            }
        }
        pendingConnectedDispatch = remaining;
    }

    function dispatchPrepared(actions) {
        for (var i = 0; i < actions.length; i++) {
            if (!actions[i].node) {
                continue;
            }
            if (actions[i].node.isConnected) {
                dispatchControlled(actions[i].node, actions[i].route);
            } else {
                rememberPendingConnectedDispatch(actions[i].node);
            }
        }
        dispatchPendingConnected();
    }

    function patch(name, original) {
        if (!NodeProto || typeof original !== 'function') {
            return;
        }
        NodeProto[name] = function (node) {
            var actions = !internalMutation ? prepareTree(node, 'dynamic-finder') : [];
            var result = original.apply(this, arguments);
            dispatchPrepared(actions);
            return result;
        };
    }

    function patchVariadicOn(proto, name, original) {
        if (!proto || typeof original !== 'function') {
            return;
        }
        proto[name] = function () {
            var actions = [];
            if (!internalMutation) {
                for (var i = 0; i < arguments.length; i++) {
                    var found = prepareTree(arguments[i], 'dynamic-finder');
                    if (found.length) {
                        actions = actions.concat(found);
                    }
                }
            }
            var result = original.apply(this, arguments);
            dispatchPrepared(actions);
            return result;
        };
    }

    function patchSingleInsertionOn(proto, name, original, nodeIndex) {
        if (!proto || typeof original !== 'function') {
            return;
        }
        proto[name] = function () {
            var node = arguments[nodeIndex || 0];
            var actions = !internalMutation ? prepareTree(node, 'dynamic-finder') : [];
            var result = original.apply(this, arguments);
            dispatchPrepared(actions);
            return result;
        };
    }

    patch('appendChild', appendChild);
    patch('insertBefore', insertBefore);
    patch('replaceChild', replaceChild);
    patchVariadicOn(ElementProto, 'append', elementAppend);
    patchVariadicOn(ElementProto, 'prepend', elementPrepend);
    patchVariadicOn(ElementProto, 'before', elementBefore);
    patchVariadicOn(ElementProto, 'after', elementAfter);
    patchVariadicOn(ElementProto, 'replaceWith', elementReplaceWith);
    patchVariadicOn(ElementProto, 'replaceChildren', elementReplaceChildren);
    patchSingleInsertionOn(ElementProto, 'insertAdjacentElement', elementInsertAdjacentElement, 1);
    patchVariadicOn(DocumentProto, 'append', documentAppend);
    patchVariadicOn(DocumentProto, 'prepend', documentPrepend);
    patchVariadicOn(DocumentProto, 'replaceChildren', documentReplaceChildren);
    patchVariadicOn(DocumentFragmentProto, 'append', fragmentAppend);
    patchVariadicOn(DocumentFragmentProto, 'prepend', fragmentPrepend);
    patchVariadicOn(DocumentFragmentProto, 'replaceChildren', fragmentReplaceChildren);
    patchSingleInsertionOn(RangeProto, 'insertNode', rangeInsertNode, 0);

    if (ElementProto && typeof setAttribute === 'function') {
        ElementProto.setAttribute = function (name, value) {
            if (!internalMutation && isScript(this) && String(name).toLowerCase() === 'src' && this.isConnected) {
                var route = prepare(this, String(value || ''), 'dynamic-finder-setAttribute-src');
                if (route && (route.lane === 'pending' || route.lane === 'defer' || route.lane === 'delay')) {
                    if (controlledRouteNeedsDispatch(route)) {
                        dispatchControlled(this, route);
                    }
                    return;
                }
            }
            return setAttribute.apply(this, arguments);
        };
    }

    if (srcDescriptor && typeof srcDescriptor.get === 'function' && typeof srcDescriptor.set === 'function') {
        try {
            Object.defineProperty(ScriptProto, 'src', {
                configurable: srcDescriptor.configurable !== false,
                enumerable: !!srcDescriptor.enumerable,
                get: srcDescriptor.get,
                set: function (value) {
                    if (!internalMutation && this.isConnected) {
                        var route = prepare(this, String(value || ''), 'dynamic-finder-src-property');
                        if (route && (route.lane === 'pending' || route.lane === 'defer' || route.lane === 'delay')) {
                            if (controlledRouteNeedsDispatch(route)) {
                                dispatchControlled(this, route);
                            }
                            return;
                        }
                    }
                    return srcDescriptor.set.call(this, value);
                }
            });
        } catch (e) {}
    }

    function rewriteWrite(value) {
        var html = String(value || '');
        if (html.toLowerCase().indexOf('<script') === -1 || html.toLowerCase().indexOf('</script>') === -1 || !document.createElement) {
            return html;
        }
        try {
            var template = document.createElement('template');
            if (!template || !('content' in template)) {
                return html;
            }
            template.innerHTML = html;
            var scripts = template.content.querySelectorAll ? template.content.querySelectorAll('script') : [];
            for (var i = 0; i < scripts.length; i++) {
                prepare(scripts[i], '', 'dynamic-finder-document-write');
            }
            return template.innerHTML;
        } catch (e) {
            return html;
        }
    }

    if (DocumentProto && typeof write === 'function') {
        DocumentProto.write = function () {
            return write.apply(this, Array.prototype.slice.call(arguments).map(rewriteWrite));
        };
    }
    if (DocumentProto && typeof writeln === 'function') {
        DocumentProto.writeln = function () {
            return writeln.apply(this, Array.prototype.slice.call(arguments).map(rewriteWrite));
        };
    }

    window.__ultracacheDynamicScriptFinderV31211 = {
        setClassifier: function (callback) {
            classifier = typeof callback === 'function' ? callback : null;
        },
        setExecutor: function (callback) {
            executor = typeof callback === 'function' ? callback : null;
        },
        setAuditRecorder: function (callback) {
            auditRecorder = typeof callback === 'function' ? callback : null;
        },
        setReleasePhase: function (phase) {
            phase = parseInt(phase, 10) || 0;
            if (phase > releasePhase) {
                releasePhase = Math.min(2, phase);
            }
        },
        getReleasePhase: function () {
            return releasePhase;
        },
        getRegistrySnapshot: function () {
            return registrySnapshot();
        },
        getPendingNodes: function () {
            if (!document.querySelectorAll) {
                return [];
            }
            return Array.prototype.slice.call(document.querySelectorAll('script[type="text/ultracache-delayed-js"][data-ultracache-dynamic-unclassified="1"]'));
        },
        resolvePending: function (node, route) {
            if (!node) {
                return;
            }
            remove(node, 'data-ultracache-dynamic-unclassified');
            if (route && ['native', 'defer', 'delay'].indexOf(String(route.lane || '').toLowerCase()) !== -1) {
                registerFinal(node, route, 'dynamic-finder-pending-resolve');
            }
            if (route && route.lane === 'delay') {
                put(node, 'data-ultracache-delay-reason', route.reason || 'dynamic-delay');
                if (route.matchedPattern) {
                    put(node, 'data-ultracache-delay-pattern', route.matchedPattern);
                }
            }
            notifyAudit(node, route, attr(node, 'data-ultracache-src') || attr(node, 'src'), 'dynamic-finder-pending-resolve');
        }
    };

}(window, document));
