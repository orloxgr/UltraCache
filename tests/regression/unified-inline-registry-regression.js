#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const dispatcherSource = fs.readFileSync(path.join(root, 'assets/js/inline-registry-dispatcher.js'), 'utf8');
const loaderSource = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');
const htmlRewriteSource = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-html-rewrite-trait.php'), 'utf8');
const deferSource = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-defer-trait.php'), 'utf8');
const outputSource = fs.readFileSync(path.join(root, 'includes/engine/class-engine-html-output-trait.php'), 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) { passes++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

const entries = {};
for (let i = 0; i < 50; i++) {
    const key = 'uc-inline-' + (i + 1) + '-fixture' + i;
    entries[key] = {
        ordinal: i + 1,
        lane: 'defer',
        handle: 'google-for-woocommerce-product',
        id: 'google-product-inline-' + i,
        fingerprint: 'fp-' + i,
        code: 'window.__productPayloads.push(' + i + ');'
    };
}
const manifest = { version: 1, count: 50, entries };

class FakeScript {
    constructor() { this.attrs = Object.create(null); this.parentNode = null; this.text = ''; this.textContent = ''; }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
    setAttribute(name, value) { this.attrs[name] = String(value); if (name === 'id') this.id = String(value); }
}

const manifestNode = { textContent: JSON.stringify(manifest) };
const replacements = [];
let sandbox;
const parent = {
    replaceChild(script, current) {
        replacements.push({ script, current });
        vm.runInContext(String(script.text || script.textContent || ''), sandbox);
        return current;
    }
};

const document = {
    currentScript: null,
    getElementById(id) { return id === 'ultracache-inline-registry-v1' ? manifestNode : null; },
    createElement(name) { if (String(name).toLowerCase() !== 'script') throw new Error('unexpected element'); return new FakeScript(); }
};
const windowObj = {
    __productPayloads: [],
    atob(v) { return Buffer.from(String(v), 'base64').toString('binary'); }
};
sandbox = { window: windowObj, document, JSON, Object, String, Buffer, decodeURIComponent, encodeURIComponent, escape, unescape, console };
vm.createContext(sandbox);

for (let i = 0; i < 50; i++) {
    const key = 'uc-inline-' + (i + 1) + '-fixture' + i;
    const current = new FakeScript();
    current.parentNode = parent;
    current.setAttribute('id', 'google-product-inline-' + i);
    current.setAttribute('data-ultracache-inline-registry-key', key);
    current.setAttribute('data-ultracache-inline-registry-attrs', Buffer.from(JSON.stringify({ id: 'google-product-inline-' + i }), 'utf8').toString('base64'));
    document.currentScript = current;
    vm.runInContext(dispatcherSource, sandbox);
}

expect(windowObj.__productPayloads.length === 50, 'A1: 50 same-handle inline occurrences execute 50 times');
expect(windowObj.__productPayloads.every((value, index) => value === index), 'A2: 50 product payloads preserve exact occurrence order');
expect(replacements.length === 50, 'A3: every occurrence gets its own executable replacement');
expect(replacements.every((pair, index) => pair.script.getAttribute('id') === 'google-product-inline-' + index), 'A4: original per-occurrence script ids are restored on execution');
expect(replacements.every((pair) => pair.script.getAttribute('data-ultracache-finder-bypass') === '1'), 'A5: registry execution does not re-enter the runtime classification firewall');
expect(windowObj.__ultracacheInlineRegistryV1 && windowObj.__ultracacheInlineRegistryV1.count === 50, 'A6: one parsed manifest is shared across all dispatcher executions');

expect(htmlRewriteSource.includes('ultracache_collect_non_native_inline_registry_in_html'), 'B1: final HTML has one non-NATIVE inline registry collector');
expect(htmlRewriteSource.includes("'ordinal' => $ordinal") && htmlRewriteSource.includes("$key = 'uc-inline-' . $ordinal"), 'B2: registry identity is occurrence-based, not handle-based');
expect(htmlRewriteSource.includes("'lane' => $lane") && htmlRewriteSource.includes("'fingerprint' => $fingerprint"), 'B3: each registry entry preserves lane and exact-code fingerprint');
expect(htmlRewriteSource.includes('inline-registry-dispatcher.js') && htmlRewriteSource.includes("'defer' => true"), 'B4: DEFER occurrences use one shared external dispatcher at their original positions');
expect(htmlRewriteSource.includes('ultracache_build_inline_registry_delay_placeholder'), 'B5: DELAY inline code is also addressable through the same registry');
expect(loaderSource.includes('inlineRegistryEntry(node)') && loaderSource.includes('inlineNodeCode(node)'), 'B6: DELAY executor resolves registry-backed inline source before execution/identity checks');
expect(deferSource.includes('data-ultracache-inline-defer-candidate') && !deferSource.includes("$asset = $this->write_deferred_inline_js_asset($content, $record);"), 'B7: DEFER inline no longer creates one generated JS file per occurrence');
expect(outputSource.indexOf('dedupe-final-js-execution-identities') < outputSource.indexOf('collect-non-native-inline-registry'), 'B8: registry collection runs only after final lane normalization/dedupe');
expect(!/mailchimp|contact-form-7|facebook-for-woocommerce|salient/i.test(dispatcherSource + loaderSource), 'B9: registry runtime contains no vendor/site-specific policy');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
