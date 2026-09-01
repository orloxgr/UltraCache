#!/usr/bin/env node
'use strict';

/**
 * UltraCache 3.12.12 Minimum Delay Release regression suite.
 *
 * Locks the additive gate contract without changing existing Auto Release.
 * Run:
 *   node tests/regression/minimum-delay-release-regression.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const loaderPath = path.join(root, 'assets', 'js', 'delayed-js-loader.js');
const source = fs.readFileSync(loaderPath, 'utf8');

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

function extractFunction(name) {
    const needle = 'function ' + name + '(';
    const start = source.indexOf(needle);
    if (start < 0) return '';
    const brace = source.indexOf('{', start);
    let depth = 0;
    let quote = '';
    let escaped = false;
    let lineComment = false;
    let blockComment = false;
    for (let i = brace; i < source.length; i++) {
        const ch = source[i];
        const next = source[i + 1] || '';
        if (lineComment) { if (ch === '\n') lineComment = false; continue; }
        if (blockComment) { if (ch === '*' && next === '/') { blockComment = false; i++; } continue; }
        if (quote) {
            if (escaped) { escaped = false; continue; }
            if (ch === '\\') { escaped = true; continue; }
            if (ch === quote) quote = '';
            continue;
        }
        if (ch === '/' && next === '/') { lineComment = true; i++; continue; }
        if (ch === '/' && next === '*') { blockComment = true; i++; continue; }
        if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
        if (ch === '{') depth++;
        if (ch === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    return '';
}

const names = [
    'pageNavigationStartMs',
    'minimumReleaseRemainingMs',
    'flushMinimumReleaseGate',
    'holdForMinimumRelease',
    'triggerAll',
    'startInteractionRelease'
];
const fns = {};
for (const name of names) {
    fns[name] = extractFunction(name);
    expect(!!fns[name], 'A: production function exists: ' + name);
}

expect(/var minimumReleaseMs =/.test(source), 'B1: loader accepts a separate minimumReleaseMs config value');
expect(/minimumReleaseAtMs = pageNavigationStartMs\(\) \+ minimumReleaseMs/.test(source), 'B2: minimum threshold is page-start based');
expect(/performance\.timeOrigin/.test(fns.pageNavigationStartMs), 'B3: navigation start prefers Performance timeOrigin');
expect(/performance\.timing\.navigationStart/.test(fns.pageNavigationStartMs), 'B4: legacy navigationStart fallback remains available');
expect(/if \(minimumReleaseMs <= 0\)/.test(fns.minimumReleaseRemainingMs), 'B5: zero disables the gate');

expect(/holdForMinimumRelease\('full'\)/.test(fns.triggerAll), 'C1: full release requests pass through the minimum gate');
expect(/holdForMinimumRelease\('interaction'\)/.test(fns.startInteractionRelease), 'C2: interaction release requests pass through the minimum gate');
expect(/minimumInteractionRequestPending = true/.test(fns.holdForMinimumRelease), 'C3: early interaction release is retained as pending');
expect(/minimumFullRequestPending = true/.test(fns.holdForMinimumRelease), 'C4: early full release is retained as pending');
expect(/if \(interactionPending\)[\s\S]*startInteractionRelease\(\)[\s\S]*if \(fullPending\)[\s\S]*triggerAll\(\)/.test(fns.flushMinimumReleaseGate), 'C5: interaction pending is resumed before pending full release');

// Execute the exact production gate helpers with controlled time.
const calls = [];
const timers = [];
let now = 1000;
const sandbox = {
    window: { performance: { timeOrigin: 0 } },
    Date: { now: () => now },
    isFinite,
    Math,
    minimumReleaseMs: 0,
    minimumReleaseAtMs: 0,
    minimumInteractionRequestPending: false,
    minimumFullRequestPending: false,
    minimumGateTimer: null,
    started: 0,
    mark: () => {},
    setTimeout: (fn, delay) => { timers.push({ fn, delay }); return timers.length; },
    startInteractionRelease: () => calls.push('interaction'),
    triggerAll: () => calls.push('full')
};
vm.createContext(sandbox);
vm.runInContext([
    fns.minimumReleaseRemainingMs,
    fns.flushMinimumReleaseGate,
    fns.holdForMinimumRelease
].join('\n'), sandbox);

sandbox.minimumReleaseMs = 0;
sandbox.minimumReleaseAtMs = 0;
expect(sandbox.holdForMinimumRelease('interaction') === false, 'D1: Disabled gate never blocks interaction');
expect(timers.length === 0, 'D2: Disabled gate schedules no timer');

sandbox.minimumReleaseMs = 3000;
sandbox.minimumReleaseAtMs = 3000;
now = 1000;
expect(sandbox.holdForMinimumRelease('interaction') === true, 'D3: interaction before threshold is held');
expect(sandbox.minimumInteractionRequestPending === true, 'D4: interaction pending state is retained');
expect(timers.length === 1 && timers[0].delay === 2000, 'D5: gate timer waits only until the absolute minimum threshold');
expect(sandbox.holdForMinimumRelease('full') === true, 'D6: Auto/full request before threshold is held');
expect(sandbox.minimumFullRequestPending === true, 'D7: full pending state is retained');
expect(timers.length === 1, 'D8: multiple pending requests share one gate timer');

now = 3000;
sandbox.flushMinimumReleaseGate();
expect(calls.join(',') === 'interaction,full', 'D9: gate expiry resumes interaction before full release');
expect(sandbox.minimumInteractionRequestPending === false && sandbox.minimumFullRequestPending === false, 'D10: gate pending flags clear after expiry');

calls.length = 0;
now = 4000;
expect(sandbox.holdForMinimumRelease('interaction') === false, 'D11: interaction after minimum passes immediately');
expect(calls.length === 0, 'D12: gate itself never creates a release request');

// Existing Auto Release scheduling remains present and independent.
expect(/if \(autoTimerEnabled\) \{\s*afterDomReady\(triggerAll, autoDelayMs\);\s*\}/.test(source), 'E1: existing Auto Release timer generation is unchanged');
expect(/if \(autoAfterLoad\) \{\s*afterLoad\(triggerAll, 0\);\s*\}/.test(source), 'E2: existing After page load trigger is unchanged');
expect(/autoEvents\.forEach\(function \(eventName\) \{\s*window\.addEventListener\(eventName, triggerAllFromInteraction/.test(source), 'E3: existing interaction trigger registration is unchanged');

console.log('');
console.log('Result: ' + passes + ' passed, ' + failures.length + ' failed.');
if (failures.length) process.exit(1);
