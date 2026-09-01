#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const finderSource = fs.readFileSync(path.join(root, 'assets/js/dynamic-script-finder-bootstrap.js'), 'utf8');
const collectorSource = fs.readFileSync(path.join(root, 'assets/js/runtime-js-scan-collector.js'), 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) { passes++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

class FakeNode {
    constructor() { this.parentNode = null; this.children = []; this.isConnected = false; }
    appendChild(node) { node.parentNode = this; node.isConnected = !!this.isConnected; this.children.push(node); return node; }
    insertBefore(node, reference) { return this.appendChild(node); }
    replaceChild(node, oldNode) { return oldNode; }
}
class FakeElement extends FakeNode {
    constructor(tagName) { super(); this.tagName = String(tagName || '').toUpperCase(); this.nodeName = this.tagName; this._attrs = Object.create(null); this.textContent = ''; }
    get attributes() { return Object.keys(this._attrs).map((name) => ({ name, value: this._attrs[name] })); }
    getAttribute(name) { name = String(name).toLowerCase(); return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null; }
    setAttribute(name, value) { this._attrs[String(name).toLowerCase()] = String(value); }
    removeAttribute(name) { delete this._attrs[String(name).toLowerCase()]; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this._attrs, String(name).toLowerCase()); }
}
class FakeScriptElement extends FakeElement {
    constructor() { super('script'); this.text = ''; }
    get src() { return this.getAttribute('src') || ''; }
    set src(value) { this._attrs.src = String(value); }
}
class FakeTemplateElement extends FakeElement {
    constructor() { super('template'); this.content = { querySelectorAll() { return []; } }; this._innerHTML = ''; }
    get innerHTML() { return this._innerHTML; }
    set innerHTML(value) { this._innerHTML = String(value); }
}
class FakeDocument extends FakeNode {
    constructor() { super(); this.isConnected = true; this.baseURI = 'https://site.example/page'; }
    createElement(name) { if (String(name).toLowerCase() === 'script') return new FakeScriptElement(); if (String(name).toLowerCase() === 'template') return new FakeTemplateElement(); return new FakeElement(name); }
    querySelectorAll() { return []; }
    write() {}
    writeln() {}
}

function runFinder() {
    const document = new FakeDocument();
    const encodedPolicy = Buffer.from(JSON.stringify({ nativePatterns: ['native.js'] }), 'utf8').toString('base64url');
    const fakeWindow = {
        Node: FakeNode,
        Element: FakeElement,
        HTMLScriptElement: FakeScriptElement,
        Document: FakeDocument,
        document,
        location: { origin: 'https://site.example', href: 'https://site.example/page' },
        ultracacheDynamicScriptFinderConfig: { encodedPolicy },
        URL,
        TextDecoder,
        Uint8Array,
        atob(v) { return Buffer.from(String(v), 'base64').toString('binary'); },
        btoa(v) { return Buffer.from(String(v), 'binary').toString('base64'); }
    };
    const sandbox = { window: fakeWindow, document, URL, TextDecoder, Uint8Array, encodeURIComponent, decodeURIComponent, unescape, console };
    vm.createContext(sandbox);
    vm.runInContext(finderSource, sandbox);
    return { window: fakeWindow, document, state: fakeWindow.__ultracacheDynamicScriptFinderV31211 };
}

let env = runFinder();
expect(!env.window.__ultracacheJsClassificationAudit, 'A1: normal production Finder creates no classification audit sink');
expect(env.state && typeof env.state.setAuditRecorder === 'function', 'A2: Finder exposes only a nullable audit callback hook for the diagnostics collector');

const records = [];
env.state.setAuditRecorder(function (node, route, src, caughtBy) {
    records.push({ node, lane: route.lane, reason: route.reason, ruleId: route.ruleId, matchedPattern: route.matchedPattern || '', src, caughtBy });
});
const parent = new FakeElement('div'); parent.isConnected = true;
let node = new FakeScriptElement(); node.src = 'https://cdn.example/native.js';
parent.appendChild(node);
expect(records.length === 1 && records[0].lane === 'native' && records[0].ruleId === 'visible-native', 'A3: visible NATIVE dynamic decision is exposed to the scan-only recorder hook');
expect(records[0].caughtBy === 'dynamic-finder', 'A4: dynamic recorder hook identifies the Finder catch path');

node = new FakeScriptElement(); node.src = 'https://cdn.example/pending.js';
parent.appendChild(node);
expect(records.length === 1 && node.getAttribute('data-ultracache-dynamic-unclassified') === '1', 'A5: pre-classifier pending capture does not fabricate a final classification record');
env.state.setClassifier(function () { return { lane: 'delay', reason: 'safe-third-party', ruleId: 'safe-third-party', matchedPattern: 'cdn.example', interactionEligible: false }; });
env.state.resolvePending(node, { lane: 'delay', reason: 'safe-third-party', ruleId: 'safe-third-party', matchedPattern: 'cdn.example', interactionEligible: false });
expect(records.length === 2 && records[1].lane === 'delay' && records[1].caughtBy === 'dynamic-finder-pending-resolve', 'A6: pending dynamic script exposes one final DELAY classification after unified classifier resolution');
expect(!finderSource.includes('__ultracacheJsClassificationAudit') && !finderSource.includes('new Error()') && !finderSource.includes('performance.getEntriesByType'), 'A7: heavy audit sink, stack capture, and ResourceTiming logic stay out of parser-early Finder');
expect(collectorSource.includes('installDynamicFinderClassificationAuditBridge()') && collectorSource.includes('setAuditRecorder(recordDynamicClassification)'), 'A8: diagnostics-only collector installs the dynamic Finder recorder bridge');
expect(collectorSource.includes('initiator: sourceFromStack((new Error()).stack)'), 'A9: dynamic initiator stack capture lives in diagnostics-only collector');

expect(collectorSource.includes("performance.getEntriesByType('resource')"), 'B1: collector reads actual browser ResourceTiming entries');
expect(collectorSource.includes("String(entry.initiatorType || '').toLowerCase() !== 'script'"), 'B2: escape check is restricted to real script resource requests');
expect(collectorSource.includes('unclassifiedRequests: unclassified'), 'B3: collector returns the concrete unclassified request list');
expect(collectorSource.includes('invariantPassed: unclassified.length === 0'), 'B4: collector exposes the classification invariant result');
expect(collectorSource.includes('classificationAudit: buildClassificationAuditPayload()'), 'B5: audit data uses the existing Runtime Scan transport instead of a second report channel');
expect(collectorSource.includes('installClassificationAuditDomObserver()') && collectorSource.includes('MutationObserver'), 'B6: scan-only DOM observer preserves parser classification records even if scripts are later removed');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
