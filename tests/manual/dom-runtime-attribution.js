/*
 * UltraCache DOM/runtime attribution audit.
 * Development/manual diagnostic only; never loaded by the plugin runtime.
 *
 * Recommended use:
 *   1. Open DevTools > Sources > Snippets.
 *   2. Run this snippet before the delayed-JS interaction/full trigger.
 *   3. Reproduce the interaction or wait for the configured full release.
 *   4. Run: window.__ultracacheDomRuntimeAudit.report()
 *   5. Run: copy(window.__ultracacheDomRuntimeAudit.exportJson())
 *
 * The diagnostic intentionally adds instrumentation overhead. Do not use the
 * instrumented run as a Lighthouse score. Use it to attribute DOM growth.
 */
(function ultracacheInstallDomRuntimeAttributionAudit() {
    'use strict';

    if (window.__ultracacheDomRuntimeAudit && typeof window.__ultracacheDomRuntimeAudit.stop === 'function') {
        try { window.__ultracacheDomRuntimeAudit.stop(); } catch (e) {}
    }

    var auditStart = performance && typeof performance.now === 'function' ? performance.now() : Date.now();
    var root = document.documentElement;
    var events = [];
    var operations = [];
    var scriptReplacements = [];
    var scriptExecutions = [];
    var scriptExecutionByPlaceholder = typeof WeakMap === 'function' ? new WeakMap() : null;
    var mutationBatches = [];
    var listeners = [];
    var restored = false;
    var observer = null;
    var maxOperations = 4000;
    var droppedOperations = 0;
    var currentPhase = 'installed';
    var originalDescriptors = [];
    var originalMethods = [];

    function ultracacheAuditNow() {
        return performance && typeof performance.now === 'function' ? performance.now() : (Date.now() - auditStart);
    }

    function ultracacheAuditRound(value) {
        return Math.round(Number(value || 0) * 100) / 100;
    }

    function ultracacheAuditElementCount(node) {
        if (!node) { return 0; }
        var count = node.nodeType === 1 ? 1 : 0;
        try {
            if (node.querySelectorAll) {
                count += node.querySelectorAll('*').length;
            }
        } catch (e) {}
        return count;
    }

    function ultracacheAuditNodeLabel(node) {
        if (!node) { return ''; }
        if (node.nodeType === 3) { return '#text'; }
        if (node.nodeType === 11) { return '#fragment'; }
        if (node.nodeType !== 1) { return String(node.nodeName || 'node'); }
        var label = String(node.tagName || '').toLowerCase();
        if (node.id) { label += '#' + node.id; }
        if (node.classList && node.classList.length) {
            label += '.' + Array.prototype.slice.call(node.classList, 0, 4).join('.');
        }
        return label.slice(0, 180);
    }

    function ultracacheAuditInteresting(node) {
        if (!node || node.nodeType !== 1) { return false; }
        var hay = [
            node.id || '',
            node.className && typeof node.className === 'string' ? node.className : '',
            node.getAttribute ? (node.getAttribute('data-widget_type') || '') : '',
            node.getAttribute ? (node.getAttribute('data-element_type') || '') : ''
        ].join(' ').toLowerCase();
        return /swiper|slider|carousel|elementor|sr7|revslider|rev-slide|slick|splide/.test(hay);
    }

    function ultracacheAuditSourceFromStack(stack) {
        var lines = String(stack || '').split('\n');
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (!line || /ultracacheInstallDomRuntimeAttributionAudit|ultracacheAudit/.test(line)) {
                continue;
            }
            var match = line.match(/((?:https?:|blob:|file:)[^\s)]+:\d+:\d+)/i);
            if (match) {
                return match[1];
            }
            if (/\bat\b/.test(line) && !/native code/.test(line)) {
                return line.trim().slice(0, 260);
            }
        }
        return 'unknown';
    }

    function ultracacheAuditStackSource() {
        try { return ultracacheAuditSourceFromStack((new Error()).stack); } catch (e) { return 'unknown'; }
    }

    function ultracacheAuditCounts() {
        function count(selector) {
            try { return document.querySelectorAll(selector).length; } catch (e) { return 0; }
        }
        return {
            totalElements: count('*'),
            scripts: document.scripts ? document.scripts.length : count('script'),
            swiperSlides: count('.swiper-slide'),
            swiperDuplicates: count('.swiper-slide-duplicate'),
            elementorElements: count('.elementor-element'),
            sliderRevolutionNodes: count('rs-module,rs-slide,sr7-module,sr7-slide,.rev_slider,.tp-revslider-mainul'),
            slickSlides: count('.slick-slide'),
            splideSlides: count('.splide__slide')
        };
    }

    function ultracacheAuditMarkers() {
        var result = {};
        if (!root || !root.attributes) { return result; }
        Array.prototype.forEach.call(root.attributes, function (attr) {
            if (String(attr.name || '').indexOf('data-ultracache-delay-') === 0) {
                result[attr.name] = attr.value;
            }
        });
        return result;
    }

    function ultracacheAuditSnapshot(kind, detail) {
        var item = {
            timeMs: ultracacheAuditRound(ultracacheAuditNow()),
            kind: kind,
            phase: currentPhase,
            detail: detail || null,
            counts: ultracacheAuditCounts(),
            markers: ultracacheAuditMarkers()
        };
        events.push(item);
        return item;
    }

    function ultracacheAuditRecordOperation(method, target, node, elementDelta, extra) {
        if (operations.length >= maxOperations) {
            droppedOperations++;
            return;
        }
        var interesting = ultracacheAuditInteresting(target) || ultracacheAuditInteresting(node);
        if (!interesting && elementDelta < 4) {
            return;
        }
        operations.push({
            timeMs: ultracacheAuditRound(ultracacheAuditNow()),
            phase: currentPhase,
            method: method,
            source: ultracacheAuditStackSource(),
            target: ultracacheAuditNodeLabel(target),
            node: ultracacheAuditNodeLabel(node),
            elementDelta: Number(elementDelta || 0),
            interesting: interesting,
            extra: extra || null
        });
    }

    function ultracacheAuditPatchMethod(owner, name, wrapperFactory) {
        if (!owner || typeof owner[name] !== 'function') { return; }
        var original = owner[name];
        var wrapped = wrapperFactory(original);
        originalMethods.push({ owner: owner, name: name, original: original });
        owner[name] = wrapped;
    }

    ultracacheAuditPatchMethod(Element.prototype, 'setAttribute', function (original) {
        return function ultracacheAuditSetAttribute(name, value) {
            var attrName = String(name || '').toLowerCase();
            var attrValue = String(value || '');
            var descriptor = null;
            var execution = null;

            if ((attrName === 'data-ultracache-loading' || attrName === 'data-ultracache-loaded') && attrValue === '1') {
                descriptor = ultracacheAuditDelayedDescriptor(this);
            }

            if (descriptor && attrName === 'data-ultracache-loading') {
                execution = {
                    startTimeMs: ultracacheAuditRound(ultracacheAuditNow()),
                    endTimeMs: null,
                    phase: currentPhase,
                    inferredLane: ultracacheAuditInferLane(descriptor),
                    descriptor: descriptor,
                    countsBefore: ultracacheAuditCounts(),
                    countsAfter: null,
                    domDelta: null,
                    completion: 'pending'
                };
                scriptExecutions.push(execution);
                if (scriptExecutionByPlaceholder) {
                    try { scriptExecutionByPlaceholder.set(this, execution); } catch (e) {}
                }
            }

            var result = original.apply(this, arguments);

            if (descriptor && attrName === 'data-ultracache-loaded') {
                if (scriptExecutionByPlaceholder) {
                    try { execution = scriptExecutionByPlaceholder.get(this) || null; } catch (e) { execution = null; }
                }
                if (execution && !execution.countsAfter) {
                    execution.endTimeMs = ultracacheAuditRound(ultracacheAuditNow());
                    execution.countsAfter = ultracacheAuditCounts();
                    execution.domDelta = execution.countsAfter.totalElements - execution.countsBefore.totalElements;
                    execution.completion = 'loaded';
                }
            }

            return result;
        };
    });

    ultracacheAuditPatchMethod(Node.prototype, 'appendChild', function (original) {
        return function ultracacheAuditAppendChild(node) {
            var delta = ultracacheAuditElementCount(node);
            ultracacheAuditRecordOperation('appendChild', this, node, delta);
            return original.apply(this, arguments);
        };
    });

    ultracacheAuditPatchMethod(Node.prototype, 'insertBefore', function (original) {
        return function ultracacheAuditInsertBefore(node) {
            var delta = ultracacheAuditElementCount(node);
            ultracacheAuditRecordOperation('insertBefore', this, node, delta);
            return original.apply(this, arguments);
        };
    });

    ultracacheAuditPatchMethod(Node.prototype, 'replaceChild', function (original) {
        return function ultracacheAuditReplaceChild(node, oldNode) {
            var added = ultracacheAuditElementCount(node);
            var removed = ultracacheAuditElementCount(oldNode);
            ultracacheAuditRecordOperation('replaceChild', this, node, added - removed, {
                replaced: ultracacheAuditNodeLabel(oldNode)
            });
            return original.apply(this, arguments);
        };
    });

    ultracacheAuditPatchMethod(Element.prototype, 'insertAdjacentHTML', function (original) {
        return function ultracacheAuditInsertAdjacentHTML(position, html) {
            var before = 0;
            try { before = this.querySelectorAll('*').length; } catch (e) {}
            var result = original.apply(this, arguments);
            var after = before;
            try { after = this.querySelectorAll('*').length; } catch (e) {}
            ultracacheAuditRecordOperation('insertAdjacentHTML', this, null, Math.max(0, after - before), {
                position: String(position || ''),
                htmlLength: String(html || '').length
            });
            return result;
        };
    });

    try {
        var innerHtmlDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
        if (innerHtmlDescriptor && innerHtmlDescriptor.get && innerHtmlDescriptor.set && innerHtmlDescriptor.configurable) {
            originalDescriptors.push({ owner: Element.prototype, name: 'innerHTML', descriptor: innerHtmlDescriptor });
            Object.defineProperty(Element.prototype, 'innerHTML', {
                configurable: innerHtmlDescriptor.configurable,
                enumerable: innerHtmlDescriptor.enumerable,
                get: innerHtmlDescriptor.get,
                set: function ultracacheAuditInnerHtml(value) {
                    var before = 0;
                    try { before = this.querySelectorAll('*').length; } catch (e) {}
                    innerHtmlDescriptor.set.call(this, value);
                    var after = before;
                    try { after = this.querySelectorAll('*').length; } catch (e) {}
                    ultracacheAuditRecordOperation('innerHTML', this, null, after - before, {
                        htmlLength: String(value || '').length
                    });
                }
            });
        }
    } catch (e) {}

    function ultracacheAuditDelayedDescriptor(node) {
        if (!node || node.nodeType !== 1 || String(node.tagName || '').toLowerCase() !== 'script') {
            return null;
        }
        return {
            handle: node.getAttribute('data-ultracache-handle') || '',
            src: node.getAttribute('data-ultracache-src') || node.getAttribute('src') || '',
            delayReason: node.getAttribute('data-ultracache-delay-reason') || '',
            delayed: node.getAttribute('data-ultracache-delayed') || '',
            inline: node.getAttribute('data-ultracache-inline') || '',
            type: node.getAttribute('type') || ''
        };
    }

    function ultracacheAuditInferLane(descriptor) {
        var markers = ultracacheAuditMarkers();
        if (markers['data-ultracache-delay-interaction-release-in-progress'] === '1') {
            return 'interaction';
        }
        if (descriptor && /^(safe-third-party|functional-third-party|all-third-party)$/.test(descriptor.delayReason || '')) {
            return 'thirdparty';
        }
        return 'firstparty';
    }

    observer = new MutationObserver(function (records) {
        var added = 0;
        var removed = 0;
        var interestingAdded = 0;
        var replacementCandidates = [];

        records.forEach(function (record) {
            Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                added += ultracacheAuditElementCount(node);
                if (ultracacheAuditInteresting(node)) { interestingAdded++; }
            });
            Array.prototype.forEach.call(record.removedNodes || [], function (node) {
                removed += ultracacheAuditElementCount(node);
                var descriptor = ultracacheAuditDelayedDescriptor(node);
                if (descriptor && (descriptor.src || descriptor.inline || descriptor.handle)) {
                    replacementCandidates.push(descriptor);
                }
            });
        });

        if (replacementCandidates.length) {
            replacementCandidates.forEach(function (descriptor) {
                scriptReplacements.push({
                    timeMs: ultracacheAuditRound(ultracacheAuditNow()),
                    phase: currentPhase,
                    inferredLane: ultracacheAuditInferLane(descriptor),
                    descriptor: descriptor,
                    counts: ultracacheAuditCounts()
                });
            });

            records.forEach(function (record) {
                Array.prototype.forEach.call(record.removedNodes || [], function (node) {
                    var descriptor = ultracacheAuditDelayedDescriptor(node);
                    if (!descriptor || descriptor.inline !== '1' || !scriptExecutionByPlaceholder) { return; }
                    var execution = null;
                    try { execution = scriptExecutionByPlaceholder.get(node) || null; } catch (e) {}
                    if (execution && !execution.countsAfter) {
                        execution.endTimeMs = ultracacheAuditRound(ultracacheAuditNow());
                        execution.countsAfter = ultracacheAuditCounts();
                        execution.domDelta = execution.countsAfter.totalElements - execution.countsBefore.totalElements;
                        execution.completion = 'inline-replacement-observed';
                    }
                });
            });
        }

        if (added || removed) {
            mutationBatches.push({
                timeMs: ultracacheAuditRound(ultracacheAuditNow()),
                phase: currentPhase,
                records: records.length,
                addedElements: added,
                removedElements: removed,
                netElements: added - removed,
                interestingAddedRoots: interestingAdded,
                counts: ultracacheAuditCounts()
            });
        }
    });

    observer.observe(document.documentElement || document, {
        childList: true,
        subtree: true
    });

    function ultracacheAuditListen(target, name, handler, options) {
        target.addEventListener(name, handler, options || false);
        listeners.push({ target: target, name: name, handler: handler, options: options || false });
    }

    function ultracacheAuditPhaseEvent(name, phase) {
        ultracacheAuditListen(window, name, function (event) {
            currentPhase = phase;
            ultracacheAuditSnapshot(name, event && event.detail ? event.detail : null);
        });
    }

    ultracacheAuditPhaseEvent('ultracache:delayed-scripts-start', 'full-release');
    ultracacheAuditPhaseEvent('ultracache:delayed-scripts-lane-done', 'lane-done');
    ultracacheAuditPhaseEvent('ultracache:delayed-scripts-done', 'full-done');

    ultracacheAuditListen(document, 'DOMContentLoaded', function () {
        currentPhase = 'dom-content-loaded';
        ultracacheAuditSnapshot('DOMContentLoaded');
    }, { once: true });

    ultracacheAuditListen(window, 'load', function () {
        currentPhase = 'window-load';
        ultracacheAuditSnapshot('load');
    }, { once: true });

    function ultracacheAuditAggregateOperations() {
        var grouped = {};
        operations.forEach(function (item) {
            var key = item.source || 'unknown';
            if (!grouped[key]) {
                grouped[key] = {
                    source: key,
                    calls: 0,
                    positiveElementDelta: 0,
                    negativeElementDelta: 0,
                    interestingCalls: 0,
                    methods: {}
                };
            }
            var row = grouped[key];
            row.calls++;
            if (item.elementDelta > 0) { row.positiveElementDelta += item.elementDelta; }
            if (item.elementDelta < 0) { row.negativeElementDelta += item.elementDelta; }
            if (item.interesting) { row.interestingCalls++; }
            row.methods[item.method] = (row.methods[item.method] || 0) + 1;
        });
        return Object.keys(grouped).map(function (key) { return grouped[key]; })
            .sort(function (a, b) {
                if (b.positiveElementDelta !== a.positiveElementDelta) {
                    return b.positiveElementDelta - a.positiveElementDelta;
                }
                return b.calls - a.calls;
            });
    }

    function ultracacheAuditRestore() {
        if (restored) { return; }
        restored = true;
        try { if (observer) { observer.disconnect(); } } catch (e) {}
        listeners.forEach(function (item) {
            try { item.target.removeEventListener(item.name, item.handler, item.options); } catch (e) {}
        });
        originalMethods.reverse().forEach(function (item) {
            try { item.owner[item.name] = item.original; } catch (e) {}
        });
        originalDescriptors.reverse().forEach(function (item) {
            try { Object.defineProperty(item.owner, item.name, item.descriptor); } catch (e) {}
        });
    }

    function ultracacheAuditExportObject() {
        return {
            meta: {
                capturedAt: new Date().toISOString(),
                url: location.href,
                userAgent: navigator.userAgent,
                droppedOperations: droppedOperations,
                instrumentationWarning: 'Diagnostic instrumentation adds overhead; do not use this run as a Lighthouse score.'
            },
            start: events[0] || null,
            end: ultracacheAuditSnapshot('export'),
            events: events.slice(),
            scriptReplacements: scriptReplacements.slice(),
            scriptExecutions: scriptExecutions.slice(),
            mutationBatches: mutationBatches.slice(),
            operationSummary: ultracacheAuditAggregateOperations(),
            operations: operations.slice()
        };
    }

    var api = {
        checkpoint: function ultracacheAuditCheckpoint(label) {
            return ultracacheAuditSnapshot('checkpoint:' + String(label || 'manual'));
        },
        report: function ultracacheAuditReport() {
            var summary = ultracacheAuditAggregateOperations();
            console.log('[UltraCache DOM audit] current counts', ultracacheAuditCounts());
            console.table(summary.slice(0, 30));
            console.table(scriptExecutions.map(function (item) {
                return {
                    startMs: item.startTimeMs,
                    endMs: item.endTimeMs,
                    lane: item.inferredLane,
                    handle: item.descriptor.handle,
                    reason: item.descriptor.delayReason,
                    domBefore: item.countsBefore ? item.countsBefore.totalElements : null,
                    domAfter: item.countsAfter ? item.countsAfter.totalElements : null,
                    domDelta: item.domDelta,
                    completion: item.completion,
                    src: String(item.descriptor.src || '').slice(0, 100)
                };
            }));
            console.table(scriptReplacements.map(function (item) {
                return {
                    timeMs: item.timeMs,
                    lane: item.inferredLane,
                    handle: item.descriptor.handle,
                    reason: item.descriptor.delayReason,
                    src: String(item.descriptor.src || '').slice(0, 120),
                    totalElements: item.counts.totalElements,
                    swiperSlides: item.counts.swiperSlides,
                    swiperDuplicates: item.counts.swiperDuplicates,
                    sliderRevolutionNodes: item.counts.sliderRevolutionNodes
                };
            }));
            console.table(mutationBatches.filter(function (item) {
                return Math.abs(item.netElements) >= 10 || item.interestingAddedRoots > 0;
            }).slice(-50));
            return {
                counts: ultracacheAuditCounts(),
                sourceSummary: summary,
                scriptExecutions: scriptExecutions.slice(),
                scriptReplacements: scriptReplacements.slice(),
                mutationBatches: mutationBatches.slice(),
                droppedOperations: droppedOperations
            };
        },
        exportObject: ultracacheAuditExportObject,
        exportJson: function ultracacheAuditExportJson() {
            return JSON.stringify(ultracacheAuditExportObject(), null, 2);
        },
        stop: function ultracacheAuditStop() {
            ultracacheAuditSnapshot('stop');
            ultracacheAuditRestore();
            return api.report();
        }
    };

    window.__ultracacheDomRuntimeAudit = api;
    ultracacheAuditSnapshot('installed');
    console.log('[UltraCache DOM audit] installed. Reproduce the delayed-JS release, then run window.__ultracacheDomRuntimeAudit.report().');
    return api;
}());
