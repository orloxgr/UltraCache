#!/usr/bin/env node
'use strict';

/**
 * UltraCache 3.13.05 Delay JS dependency/family lane ordering regression suite.
 *
 * This suite executes the real production interaction eligibility functions
 * extracted from delayed-js-loader.js inside a minimal VM harness, then locks
 * the surrounding release-order invariants with source assertions.
 *
 * Run:
 *   node tests/regression/delay-runtime-lane-order-regression.js
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
        return;
    }
    failures.push(label);
    console.log('[FAIL] ' + label);
}

function extractFunction(name) {
    const needle = 'function ' + name + '(';
    const start = source.indexOf(needle);
    if (start < 0) {
        return '';
    }

    const brace = source.indexOf('{', start);
    if (brace < 0) {
        return '';
    }

    let depth = 0;
    let quote = '';
    let escaped = false;
    let lineComment = false;
    let blockComment = false;

    for (let i = brace; i < source.length; i++) {
        const ch = source[i];
        const next = source[i + 1] || '';

        if (lineComment) {
            if (ch === '\n') lineComment = false;
            continue;
        }
        if (blockComment) {
            if (ch === '*' && next === '/') {
                blockComment = false;
                i++;
            }
            continue;
        }
        if (quote) {
            if (escaped) {
                escaped = false;
                continue;
            }
            if (ch === '\\') {
                escaped = true;
                continue;
            }
            if (ch === quote) quote = '';
            continue;
        }
        if (ch === '/' && next === '/') {
            lineComment = true;
            i++;
            continue;
        }
        if (ch === '/' && next === '*') {
            blockComment = true;
            i++;
            continue;
        }
        if (ch === '"' || ch === "'" || ch === '`') {
            quote = ch;
            continue;
        }
        if (ch === '{') depth++;
        if (ch === '}') {
            depth--;
            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }

    return '';
}

const requiredFunctions = [
    'normalizeDelayedHandle',
    'delayedNodeHandle',
    'delayedNodeDependencies',
    'delayedNodeFamily',
    'isThirdPartyDelayReason',
    'isSameOriginScriptSrc',
    'isThirdPartyDelayedNode',
    'interactionNodeIsEligible',
    'buildDelayedTopology',
    'stableOrderDelayedNodes',
    'splitDelayedFullReleaseLanes',
    'dependencyAwareInteractionNodes',
    'triggerAll',
    'releaseInteractionLane',
    'finishInteractionRelease',
    'triggerAllFromInteraction',
    'run'
];

const functions = {};
for (const name of requiredFunctions) {
    functions[name] = extractFunction(name);
    expect(!!functions[name], 'A: production function exists: ' + name);
}

// Execute the exact production eligibility predicates in isolation.
const sandbox = {
    URL,
    window: {
        location: { origin: 'https://site.example', href: 'https://site.example/product/' }
    },
    document: {
        baseURI: 'https://site.example/product/'
    },
    ultracacheData(node, key) {
        return node && node.data ? (node.data[key] || '') : '';
    },
    isExternalNode(node) {
        return !!(node && node.external);
    }
};
vm.createContext(sandbox);

const executable = [
    functions.normalizeDelayedHandle,
    functions.delayedNodeHandle,
    functions.delayedNodeDependencies,
    functions.delayedNodeFamily,
    functions.isThirdPartyDelayReason,
    functions.isSameOriginScriptSrc,
    functions.isThirdPartyDelayedNode,
    functions.interactionNodeIsEligible,
    functions.buildDelayedTopology,
    functions.stableOrderDelayedNodes,
    functions.splitDelayedFullReleaseLanes,
    functions.dependencyAwareInteractionNodes
].join('\n');
vm.runInContext(executable, sandbox);

function node({ reason = '', src = '', external = true, handle = '', deps = '', family = '', familySequence = '', familyPhase = '' } = {}) {
    return {
        external,
        data: {
            'delay-reason': reason,
            handle,
            deps,
            family,
            'family-sequence': familySequence,
            'family-phase': familyPhase
        },
        getAttribute(name) {
            return name === 'data-ultracache-src' ? src : '';
        }
    };
}

const cases = [
    [node({ reason: 'first-party', src: '/assets/app.js' }), true, 'first-party script is interaction eligible'],
    [node({ reason: '', src: '/assets/app.js' }), true, 'same-origin script without third-party reason is interaction eligible'],
    [node({ reason: 'functional-third-party', src: 'https://cdn.example.net/widget.js' }), true, 'explicit functional third-party is interaction eligible'],
    [node({ reason: 'safe-third-party', src: 'https://www.googletagmanager.com/gtm.js?id=GTM-X' }), false, 'GTM safe-third-party stays out of interaction lane'],
    [node({ reason: 'safe-third-party', src: 'https://www.googletagmanager.com/gtag/js?id=G-X' }), false, 'gtag/GA4 safe-third-party stays out of interaction lane'],
    [node({ reason: 'safe-third-party', src: 'https://connect.facebook.net/en_US/fbevents.js' }), false, 'Meta safe-third-party stays out of interaction lane'],
    [node({ reason: 'all-third-party', src: 'https://analytics.tiktok.com/i18n/pixel/events.js' }), false, 'all-third-party marketing payload stays out of interaction lane'],
    [node({ reason: '', src: 'https://www.clarity.ms/tag/TEST' }), false, 'unknown external third-party stays out of interaction lane even without a reason'],
    [node({ reason: 'functional-third-party', src: '/proxy/widget.js' }), true, 'functional-third-party reason is eligible even through same-origin proxy'],
    [null, false, 'null node is never interaction eligible']
];

for (const [candidate, expected, label] of cases) {
    expect(sandbox.interactionNodeIsEligible(candidate) === expected, 'B: ' + label);
}

// Lock the release-order invariants around the executable predicate.
const interactionFn = functions.interactionNodeIsEligible;
expect(/if\s*\(\s*!isThirdPartyDelayedNode\(node\)\s*\)\s*\{[\s\S]*?return\s+true\s*;/.test(interactionFn), 'C1: interaction lane admits non-third-party nodes');
expect(/delay-reason['"]?\)\s*===\s*['"]functional-third-party['"]/.test(interactionFn), 'C2: third-party interaction eligibility is restricted to functional-third-party');
expect(!/safe-third-party/.test(interactionFn), 'C3: safe-third-party is not an interaction eligibility exception');
expect(!/all-third-party/.test(interactionFn), 'C4: all-third-party is not an interaction eligibility exception');

const releaseFn = functions.releaseInteractionLane;
expect(/dependencyAwareInteractionNodes\(pending\)/.test(releaseFn), 'D1: interaction release uses dependency-aware eligibility instead of filtering nodes independently');
expect(/interaction-release-scope['"],\s*['"]firstparty-functional-thirdparty['"]/.test(releaseFn), 'D2: runtime marker advertises restricted interaction scope');
expect(/startOrderedFetchScheduler\(interactionLane\)/.test(releaseFn), 'D3: ordered fetch scheduler receives only the filtered interaction lane');
expect(/load\(interactionLane,\s*0,\s*['"]interaction['"]/.test(releaseFn), 'D4: loader executes only the filtered interaction lane');

const triggerFn = functions.triggerAll;
expect(/if\s*\(interactionReleaseInProgress\)\s*\{[\s\S]*?fullTriggerPending\s*=\s*true[\s\S]*?return\s*;/.test(triggerFn), 'E1: full trigger is deferred while interaction release is in progress');

const finishFn = functions.finishInteractionRelease;
expect(/interactionReleaseInProgress\s*=\s*false\s*;/.test(finishFn), 'E2: interaction release flag clears before pending full trigger processing');
expect(/if\s*\(fullTriggerPending\)\s*\{[\s\S]*?fullTriggerPending\s*=\s*false[\s\S]*?triggerAll\(\)/.test(finishFn), 'E3: pending full trigger runs only after interaction release finishes');

const interactionTriggerFn = functions.triggerAllFromInteraction;
expect(/if\s*\(document\.readyState\s*!==\s*['"]loading['"]\)\s*\{[\s\S]*?startInteractionRelease\(\)[\s\S]*?return\s*;/.test(interactionTriggerFn), 'F1: post-DOM interaction starts restricted interaction release, not full release');
expect(/DOMContentLoaded[\s\S]*?startInteractionRelease\(\)/.test(interactionTriggerFn), 'F2: pre-DOM interaction waits for DOMContentLoaded before restricted release');
expect(!/triggerAll\(\)/.test(interactionTriggerFn), 'F3: interaction handler cannot directly start the full delayed queue');

// The full run must preserve first-party -> third-party ordering.
const runFn = functions.run;
const firstPartyLoad = runFn.indexOf("load(firstParty, 0, 'firstparty'");
const runThirdPartyCall = runFn.lastIndexOf('runThirdPartyLane();');
expect(firstPartyLoad >= 0, 'G1: full run contains explicit first-party lane load');
expect(runThirdPartyCall > firstPartyLoad, 'G2: third-party lane is released after first-party lane completion path');
expect(/function\s+runThirdPartyLane\(\)[\s\S]*?load\(thirdParty,\s*0,\s*['"]thirdparty['"]/.test(runFn), 'G3: full run keeps the late dependency lane in its own release phase');

// Exact dependency topology stays inside DELAY: provider before consumer, family order preserved.
const provider = node({ handle: 'wp-hooks', src: '/wp-includes/js/hooks.js' });
const consumer = node({ handle: 'wp-api-fetch', deps: 'wp-hooks', src: '/wp-includes/js/api-fetch.js' });
let ordered = sandbox.stableOrderDelayedNodes([consumer, provider]);
expect(ordered[0] === provider && ordered[1] === consumer, 'H1: declared provider is stably ordered before its delayed consumer even when input order is inverted');

const before = node({ handle: 'contact-form-7', family: 'contact-form-7', familySequence: '1', familyPhase: 'before', external: false });
const external = node({ handle: 'contact-form-7', family: 'contact-form-7', familySequence: '2', familyPhase: 'external', src: '/plugins/contact-form-7/index.js' });
const after = node({ handle: 'contact-form-7', family: 'contact-form-7', familySequence: '3', familyPhase: 'after', external: false });
ordered = sandbox.stableOrderDelayedNodes([before, external, after]);
expect(ordered[0] === before && ordered[1] === external && ordered[2] === after, 'H2: one delayed WordPress family preserves before -> external -> after DOM order');

const marketingProvider = node({ handle: 'marketing-sdk', reason: 'safe-third-party', src: 'https://cdn.example.net/marketing.js' });
const firstPartyDependent = node({ handle: 'site-consumer', deps: 'marketing-sdk', src: '/assets/site-consumer.js' });
const split = sandbox.splitDelayedFullReleaseLanes([marketingProvider, firstPartyDependent]);
expect(split.early.length === 0 && split.late[0] === marketingProvider && split.late[1] === firstPartyDependent, 'H3: a first-party consumer waits in the late lane when its declared provider is delayed third-party');
const interaction = sandbox.dependencyAwareInteractionNodes([marketingProvider, firstPartyDependent]);
expect(interaction.length === 0, 'H4: interaction release does not execute a consumer while its ineligible delayed provider remains held');

expect(source.includes("data-ultracache-executed-sequence"), 'H5: delayed executions expose scanner-only observable sequence metadata on executable script nodes');
expect(source.includes("inline-registry-pending"), 'H6: a missing inline-registry entry remains pending instead of executing an empty inline script');

console.log('');
console.log('Result: ' + passes + ' passed, ' + failures.length + ' failed.');

if (failures.length) {
    console.error('Failures:');
    failures.forEach((failure) => console.error(' - ' + failure));
    process.exit(1);
}
