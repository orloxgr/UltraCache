#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const finderSource = fs.readFileSync(path.join(root, 'assets/js/dynamic-script-finder-bootstrap.js'), 'utf8');
const loaderSource = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');
const collectorSource = fs.readFileSync(path.join(root, 'assets/js/runtime-js-scan-collector.js'), 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) { passes++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

function isExecutableScript(node) {
    if (!node || String(node.tagName || '').toLowerCase() !== 'script') return false;
    const type = String(node.getAttribute('type') || '').toLowerCase().split(';')[0].trim();
    return !type || ['module', 'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'text/jscript', 'text/livescript'].includes(type);
}

function setConnected(node, value) {
    if (!node) return;
    node.isConnected = !!value;
    (node.children || []).forEach((child) => setConnected(child, value));
}

class FakeNode {
    constructor() { this.parentNode = null; this.children = []; this.isConnected = false; this.directExecutions = null; }
    appendChild(node) {
        node.parentNode = this;
        setConnected(node, !!this.isConnected);
        this.children.push(node);
        if (this.directExecutions && isExecutableScript(node)) this.directExecutions.push(node);
        return node;
    }
    insertBefore(node) { return this.appendChild(node); }
    replaceChild(node, oldNode) {
        const index = this.children.indexOf(oldNode);
        if (index >= 0) this.children[index] = node; else this.children.push(node);
        node.parentNode = this;
        setConnected(node, !!this.isConnected);
        if (oldNode) { oldNode.parentNode = null; setConnected(oldNode, false); }
        if (this.directExecutions && isExecutableScript(node)) this.directExecutions.push(node);
        return oldNode;
    }
}
class FakeElement extends FakeNode {
    constructor(tagName) { super(); this.tagName = String(tagName || '').toUpperCase(); this.nodeName = this.tagName; this._attrs = Object.create(null); this.textContent = ''; }
    get attributes() { return Object.keys(this._attrs).map((name) => ({ name, value: this._attrs[name] })); }
    getAttribute(name) { name = String(name).toLowerCase(); return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null; }
    setAttribute(name, value) { this._attrs[String(name).toLowerCase()] = String(value); }
    removeAttribute(name) { delete this._attrs[String(name).toLowerCase()]; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this._attrs, String(name).toLowerCase()); }
    querySelectorAll(selector) {
        const out = [];
        function walk(node) {
            (node.children || []).forEach((child) => {
                if (String(child.tagName || '').toLowerCase() === 'script') out.push(child);
                walk(child);
            });
        }
        walk(this);
        return selector === 'script' ? out : [];
    }
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
    const encodedPolicy = Buffer.from(JSON.stringify({ nativePatterns: ['native-only.js'] }), 'utf8').toString('base64url');
    const fakeWindow = {
        Node: FakeNode,
        Element: FakeElement,
        HTMLScriptElement: FakeScriptElement,
        Document: FakeDocument,
        WeakMap,
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

const env = runFinder();
const parent = new FakeElement('div');
parent.isConnected = true;
parent.directExecutions = [];
const executorCalls = [];
env.state.setExecutor(function (node, route) {
    executorCalls.push({ node, route, connected: !!node.isConnected, type: node.getAttribute('type') });
});

expect(typeof env.state.getRegistrySnapshot === 'function' && typeof env.state.setExecutor === 'function', 'A1: Dynamic Finder exposes bounded runtime registry and controlled executor hooks');

// NATIVE: classified and allowed to execute through the browser insertion.
env.state.setClassifier(function () { return { lane: 'native', reason: 'test-native', ruleId: 'test-native', interactionEligible: true }; });
let nativeNode = new FakeScriptElement();
nativeNode.src = 'https://site.example/native-runtime.js';
parent.appendChild(nativeNode);
expect(parent.directExecutions.includes(nativeNode), 'A2: runtime NATIVE script remains a browser passthrough');
expect(nativeNode.getAttribute('data-ultracache-runtime-lane') === 'native', 'A3: runtime NATIVE script receives a mandatory lane marker');

// DEFER inline: must become inert before insertion and execute only through UC executor.
env.state.setClassifier(function () { return { lane: 'defer', reason: 'default-defer-strategy', ruleId: 'default-defer', interactionEligible: true }; });
let inlineDefer = new FakeScriptElement();
inlineDefer.setAttribute('id', 'runtime-inline-js-extra');
inlineDefer.textContent = 'window.runtimeProvider = 1;';
const directBeforeInline = parent.directExecutions.length;
parent.appendChild(inlineDefer);
expect(parent.directExecutions.length === directBeforeInline, 'A4: runtime inline DEFER cannot execute directly during DOM insertion');
expect(inlineDefer.getAttribute('type') === 'text/ultracache-delayed-js' && inlineDefer.getAttribute('data-ultracache-inline') === '1', 'A5: runtime inline DEFER is neutralized into a controlled placeholder');
expect(inlineDefer.getAttribute('data-ultracache-runtime-lane') === 'defer', 'A6: runtime inline DEFER keeps its explicit lane identity');
expect(executorCalls.some((call) => call.node === inlineDefer && call.connected && call.route.lane === 'defer'), 'A7: runtime inline DEFER is dispatched only after its inert placeholder is connected');

// DELAY before release: classified but held; DELAY after release: still controlled, never direct.
env.state.setClassifier(function () { return { lane: 'delay', reason: 'functional-third-party', ruleId: 'functional-third-party', interactionEligible: false }; });
let heldDelay = new FakeScriptElement();
heldDelay.src = 'https://site.example/held-delay.js';
const executorBeforeHeld = executorCalls.length;
parent.appendChild(heldDelay);
expect(heldDelay.getAttribute('data-ultracache-runtime-lane') === 'delay' && heldDelay.getAttribute('type') === 'text/ultracache-delayed-js', 'A8: pre-release runtime DELAY script is classified and held');
expect(executorCalls.length === executorBeforeHeld, 'A9: pre-release DELAY script is not dispatched before its release phase');

env.state.setReleasePhase(2);
let liveDelay = new FakeScriptElement();
liveDelay.src = 'https://site.example/live-delay.js';
const directBeforeDelay = parent.directExecutions.length;
parent.appendChild(liveDelay);
expect(parent.directExecutions.length === directBeforeDelay, 'A10: post-release runtime DELAY script still cannot bypass the controlled executor');
expect(executorCalls.some((call) => call.node === liveDelay && call.route.lane === 'delay'), 'A11: post-release runtime DELAY script is dispatched through UltraCache');

// Subtree/fragment-like insertion: descendants must be classified before parent insertion.
env.state.setClassifier(function () { return { lane: 'defer', reason: 'default-defer-strategy', ruleId: 'default-defer', interactionEligible: true }; });
let wrapper = new FakeElement('section');
let nested = new FakeScriptElement();
nested.textContent = 'window.nestedRuntime = 1;';
const callsBeforeDetached = executorCalls.length;
wrapper.appendChild(nested); // detached: classify/neutralize, but do not execute until the subtree is connected.
expect(executorCalls.length === callsBeforeDetached, 'A12: detached runtime subtree is classified without premature execution');
const directBeforeWrapper = parent.directExecutions.length;
parent.appendChild(wrapper);
expect(parent.directExecutions.length === directBeforeWrapper, 'A13: nested executable runtime script cannot bypass classification through subtree insertion');
expect(nested.getAttribute('data-ultracache-runtime-lane') === 'defer' && executorCalls.some((call) => call.node === nested && call.connected), 'A14: held nested runtime script is dispatched only after its subtree becomes connected');

// Empty script inserted first, src assigned after connection: classification must happen on src assignment, not on empty insertion.
let lateSrc = new FakeScriptElement();
parent.appendChild(lateSrc);
const callsBeforeLateSrc = executorCalls.length;
lateSrc.src = 'https://site.example/late-src.js';
expect(lateSrc.getAttribute('data-ultracache-runtime-lane') === 'defer' && executorCalls.length === callsBeforeLateSrc + 1, 'A15: connected src assignment after an empty insertion still enters the runtime DEFER firewall');

const snapshot = env.state.getRegistrySnapshot();
expect(snapshot.seen === snapshot.native + snapshot.defer + snapshot.delay && snapshot.pending === 0 && snapshot.escaped === 0 && snapshot.invariantPassed, 'A16: runtime registry invariant is seen = NATIVE + DEFER + DELAY with escaped = 0');

expect(loaderSource.includes('setExecutor(executeClassifiedDynamicScript)') && loaderSource.includes("lane === 'defer' ? 'dynamic-defer' : 'dynamic-delay'"), 'B1: delayed loader owns controlled execution for both runtime DEFER and DELAY lanes');
expect(loaderSource.includes("data-ultracache-runtime-") && loaderSource.includes("data-ultracache-runtime-seen"), 'B2: executable replacements preserve runtime lane provenance');
expect(collectorSource.includes('collectRuntimeClassificationRegistry()') && collectorSource.includes('runtimeRegistry: runtimeRegistry'), 'B3: Runtime Scan transports the runtime classification registry invariant');
expect(collectorSource.includes('unclassified.length === 0 && runtimeRegistry.invariantPassed'), 'B4: Runtime Scan invariant requires both resource coverage and zero runtime classification escapes');
expect(!finderSource.toLowerCase().includes('mailchimp') && !finderSource.toLowerCase().includes('real cookie banner'), 'B5: firewall contains no Mailchimp or consent-manager hardcode');
expect(finderSource.includes('DocumentFragmentProto') && finderSource.includes("patchSingleInsertionOn(RangeProto, 'insertNode'") && finderSource.includes("patchVariadicOn(DocumentFragmentProto, 'append'"), 'B6: firewall covers fragment/shadow-style and Range insertion paths in addition to core Node APIs');
expect(loaderSource.includes("runtimeLane === 'delay' || runtimeLane === 'pending'"), 'B7: runtime DEFER placeholders are excluded from the normal DELAY queue and remain owned by the DEFER executor');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
