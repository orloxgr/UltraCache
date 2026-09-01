#!/usr/bin/env node
'use strict';

/**
 * UltraCache 3.13.05 runtime-created script completion-contract regression.
 *
 * Runtime-created scripts may carry Promise completion through onload/onerror
 * or addEventListener callbacks attached before insertion. UltraCache may hold
 * those nodes, but release must preserve the same DOM object and handlers.
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const loader = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');

let passes = 0;
const failures = [];
function expect(condition, label) {
    if (condition) { passes++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

function extractFunction(name) {
    const needle = 'function ' + name + '(';
    const start = loader.indexOf(needle);
    if (start < 0) return '';
    const brace = loader.indexOf('{', start);
    let depth = 0, quote = '', escaped = false, line = false, block = false;
    for (let i = brace; i < loader.length; i++) {
        const ch = loader[i], next = loader[i + 1] || '';
        if (line) { if (ch === '\n') line = false; continue; }
        if (block) { if (ch === '*' && next === '/') { block = false; i++; } continue; }
        if (quote) {
            if (escaped) { escaped = false; continue; }
            if (ch === '\\') { escaped = true; continue; }
            if (ch === quote) quote = '';
            continue;
        }
        if (ch === '/' && next === '/') { line = true; i++; continue; }
        if (ch === '/' && next === '*') { block = true; i++; continue; }
        if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
        if (ch === '{') depth++;
        if (ch === '}') {
            depth--;
            if (depth === 0) return loader.slice(start, i + 1);
        }
    }
    return '';
}

const external = extractFunction('reviveRuntimeCreatedExternalNode');
const inline = extractFunction('reviveRuntimeCreatedInlineNode');
const reinsert = extractFunction('reinsertRuntimeCreatedNode');
const loadInline = extractFunction('loadInline');
const loadExternalGroup = extractFunction('loadExternalGroup');

expect(!!external && !!inline && !!reinsert, 'A1: dedicated same-node runtime revive helpers exist');
expect(/point\.parent\.removeChild\(node\)/.test(external), 'A2: runtime external node is detached before executable restore');
expect(/node\.addEventListener\(['"]load['"],\s*onLoaded/.test(external), 'A3: UltraCache observes load additively without replacing creator onload');
expect(/node\.addEventListener\(['"]error['"],\s*onError/.test(external), 'A4: UltraCache observes error additively without replacing creator onerror');
expect(/node\.src\s*=\s*String\(src/.test(external), 'A5: original runtime external node receives restored src');
expect(/reinsertRuntimeCreatedNode\(node,\s*point\)/.test(external), 'A6: same runtime external node is reinserted at its original position');
expect(!/createElement\(['"]script['"]\)/.test(external), 'A7: runtime external revive never creates a replacement script object');

expect(/point\.parent\.removeChild\(node\)/.test(inline), 'B1: runtime inline node is detached before executable restore');
expect(/node\.text\s*=\s*String\(code/.test(inline), 'B2: runtime inline code is restored on the original object');
expect(/reinsertRuntimeCreatedNode\(node,\s*point\)/.test(inline), 'B3: same runtime inline object is reinserted for execution');
expect(!/createElement\(['"]script['"]\)/.test(inline), 'B4: runtime inline revive never creates a replacement script object');

expect(/isRuntimeCreatedDelayedNode\(node\)/.test(loadInline) && /reviveRuntimeCreatedInlineNode\(node,\s*inlineCode\)/.test(loadInline), 'C1: delayed inline loader selects same-node revive only for runtime-created nodes');
expect((loadExternalGroup.match(/reviveRuntimeCreatedExternalNode\(node,\s*src,\s*onLoaded,\s*onError\)/g) || []).length === 2, 'C2: ordered and parallel external lanes both preserve runtime-created node identity');
expect(/script\.onload\s*=\s*onLoaded/.test(loadExternalGroup) && /script\.onerror\s*=\s*onError/.test(loadExternalGroup), 'C3: static HTML replacement path retains its established completion handlers');

const lower = loader.toLowerCase();
expect(!lower.includes('revslider') && !lower.includes('slider revolution') && !lower.includes('tptools'), 'D1: completion-contract fix contains no Slider Revolution/vendor hardcode');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
