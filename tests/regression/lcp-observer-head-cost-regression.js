#!/usr/bin/env node
'use strict';

/**
 * UltraCache 3.11.24 LCP observer head-cost regression suite.
 *
 * Run:
 *   node tests/regression/lcp-observer-head-cost-regression.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const bootstrapPath = path.join(root, 'assets', 'js', 'lcp-request-credentials-bootstrap.js');
const observerPath = path.join(root, 'assets', 'js', 'lcp-observer.js');
const lcpTraitPath = path.join(root, 'includes', 'engine', 'lcp', 'class-lcp-observation-trait.php');
const deferTraitPath = path.join(root, 'includes', 'engine', 'js', 'class-js-defer-trait.php');
const routerTraitPath = path.join(root, 'includes', 'engine', 'js', 'class-js-router-trait.php');
const classificationPath = path.join(root, 'includes', 'engine', 'js', 'class-js-classification-trait.php');
const registryPath = path.join(root, 'includes', 'engine', 'js', 'class-js-runtime-registry-trait.php');

const bootstrapSource = fs.readFileSync(bootstrapPath, 'utf8');
const observerSource = fs.readFileSync(observerPath, 'utf8');
const lcpTraitSource = fs.readFileSync(lcpTraitPath, 'utf8');
const deferTraitSource = fs.readFileSync(deferTraitPath, 'utf8');
const routerTraitSource = fs.readFileSync(routerTraitPath, 'utf8');
const classificationSource = fs.readFileSync(classificationPath, 'utf8');
const registrySource = fs.readFileSync(registryPath, 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) {
        passes++;
        console.log('[PASS] ' + label);
    } else {
        failures.push(label);
        console.log('[FAIL] ' + label);
    }
}

expect(/if \(\$credentials_learning_enabled\) \{[\s\S]*?lcp-request-credentials-bootstrap\.js/.test(lcpTraitSource),
    'A1: parser-early bootstrap is conditional on request-credentials learning');
expect(/ultracache-lcp-observer[\s\S]*?ultracache_add_frontend_js_helper_script_data\(\$handle, 'strategy', 'defer'\)/.test(lcpTraitSource)
    && /'lcp-observer'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource),
    'A2: full LCP observer remains assigned to native DEFER delivery through the runtime bundle');
expect(/if \(!\$credentials_learning_enabled\) \{[\s\S]*?ultracache_add_frontend_js_helper_data\(\$handle/.test(lcpTraitSource),
    'A3: config is attached directly to deferred observer when bootstrap is unnecessary');
expect(/\$runtime_module = \$this->ultracache_get_frontend_runtime_module\(\$handle\);[\s\S]*?\$runtime_module\['lane'\][\s\S]*?\$runtime_module\['route_action'\]/.test(routerTraitSource)
    && /case 'defer-native':[\s\S]*?add_defer_attribute_to_script_tag\(\$tag, true\)/.test(routerTraitSource)
    && deferTraitSource.includes('ultracache_build_registered_script_route')
    && /'ultracache-lcp-observer'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource),
    'A4: central router preserves registry-declared native defer for full LCP observer');
expect(/'ultracache-lcp-request-credentials-bootstrap'[\s\S]*?'lane'\s*=>\s*'native'/.test(registrySource),
    'A5: early bootstrap is protected through the UltraCache runtime registry');

expect(observerSource.includes('window.__ultracacheLcpRequestCredentialsV124'),
    'B1: deferred observer consumes the early request-credentials bridge');
expect(/observer\.observe\(\{type: "largest-contentful-paint", buffered: true\}\)/.test(observerSource),
    'B2: deferred observer uses buffered LCP entries');
expect(!observerSource.includes('Object.defineProperty(prototype, "src"'),
    'B3: full observer no longer installs parser-early image prototype hooks');
expect(bootstrapSource.includes('Object.defineProperty(prototype, "src"'),
    'B4: minimal bootstrap owns the early image src hook');

// Execute the real bootstrap with a minimal image prototype harness.
function HTMLImageElement() {
    this._src = '';
    this.crossOrigin = null;
    this._attrs = Object.create(null);
}
Object.defineProperty(HTMLImageElement.prototype, 'src', {
    configurable: true,
    enumerable: true,
    get() { return this._src; },
    set(value) { this._src = String(value); }
});
HTMLImageElement.prototype.setAttribute = function (name, value) {
    this._attrs[String(name)] = String(value);
    if (String(name).toLowerCase() === 'src') {
        this._src = String(value);
    }
};
HTMLImageElement.prototype.hasAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this._attrs, String(name));
};

const originalSrcDescriptor = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
const originalSetAttribute = HTMLImageElement.prototype.setAttribute;
const sandbox = {
    URL,
    document: { baseURI: 'https://site.example/product/' },
    window: {
        ultracacheLcpObserverConfig: { observeRequestCredentials: true },
        HTMLImageElement
    }
};
vm.createContext(sandbox);
vm.runInContext(bootstrapSource, sandbox);

const state = sandbox.window.__ultracacheLcpRequestCredentialsV124;
expect(!!state && !!state.modes, 'C1: bootstrap exposes bounded request-credentials state');
const image = new sandbox.window.HTMLImageElement();
image.crossOrigin = 'anonymous';
image.src = '/media/hero.webp';
expect(state.modes['https://site.example/media/hero.webp'] === 'anonymous',
    'C2: bootstrap records credential mode for runtime image src assignment');
const image2 = new sandbox.window.HTMLImageElement();
image2.setAttribute('src', '/media/other.webp');
expect(state.modes['https://site.example/media/other.webp'] === 'none',
    'C3: bootstrap records setAttribute(src) assignments');
expect(typeof state.restore === 'function', 'C4: bootstrap exposes restoration hook');
state.restore();
const restoredSrcDescriptor = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
expect(restoredSrcDescriptor.set === originalSrcDescriptor.set,
    'C5: bootstrap restores original image src descriptor');
expect(HTMLImageElement.prototype.setAttribute === originalSetAttribute,
    'C6: bootstrap restores original setAttribute implementation');

// When learning is disabled, bootstrap must not patch the image prototype.
function DisabledImage() {}
Object.defineProperty(DisabledImage.prototype, 'src', {
    configurable: true,
    get() { return ''; },
    set(value) { void value; }
});
DisabledImage.prototype.setAttribute = function () {};
const disabledSetter = Object.getOwnPropertyDescriptor(DisabledImage.prototype, 'src').set;
const disabledSetAttribute = DisabledImage.prototype.setAttribute;
const disabledSandbox = {
    URL,
    document: { baseURI: 'https://site.example/' },
    window: {
        ultracacheLcpObserverConfig: { observeRequestCredentials: false },
        HTMLImageElement: DisabledImage
    }
};
vm.createContext(disabledSandbox);
vm.runInContext(bootstrapSource, disabledSandbox);
expect(Object.getOwnPropertyDescriptor(DisabledImage.prototype, 'src').set === disabledSetter,
    'D1: disabled credentials learning does not patch image src');
expect(DisabledImage.prototype.setAttribute === disabledSetAttribute,
    'D2: disabled credentials learning does not patch setAttribute');

console.log('');
console.log('Result: ' + passes + ' passed, ' + failures.length + ' failed.');
if (failures.length) {
    console.error('Failures:');
    failures.forEach((failure) => console.error(' - ' + failure));
    process.exit(1);
}
