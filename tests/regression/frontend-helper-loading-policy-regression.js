#!/usr/bin/env node
'use strict';

/**
 * UltraCache 3.11.25 frontend helper loading-policy regression suite.
 *
 * Run:
 *   node tests/regression/frontend-helper-loading-policy-regression.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const classificationSource = fs.readFileSync(path.join(root, 'includes', 'engine', 'js', 'class-js-classification-trait.php'), 'utf8');
const registrySource = fs.readFileSync(path.join(root, 'includes', 'engine', 'js', 'class-js-runtime-registry-trait.php'), 'utf8');
const deferSource = fs.readFileSync(path.join(root, 'includes', 'engine', 'js', 'class-js-defer-trait.php'), 'utf8');
const routerSource = fs.readFileSync(path.join(root, 'includes', 'engine', 'js', 'class-js-router-trait.php'), 'utf8');
const delayTraitSource = fs.readFileSync(path.join(root, 'includes', 'engine', 'js', 'class-js-delay-trait.php'), 'utf8');
const frontendAssetsSource = fs.readFileSync(path.join(root, 'includes', 'engine', 'class-engine-frontend-assets-trait.php'), 'utf8');
const loaderSource = fs.readFileSync(path.join(root, 'assets', 'js', 'delayed-js-loader.js'), 'utf8');
const bootstrapSource = fs.readFileSync(path.join(root, 'assets', 'js', 'delayed-js-interaction-bootstrap.js'), 'utf8');

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

expect(/'ultracache-delayed-js-loader'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource)
    && /'ultracache-lazy-third-party-iframes'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource)
    && /'ultracache-lcp-observer'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource),
    'A1: runtime registry preserves native-defer lanes for full delayed loader, lazy iframe runtime, and full LCP observer');
expect(/\$runtime_module = \$this->ultracache_get_frontend_runtime_module\(\$handle\);[\s\S]*?\$runtime_module\['lane'\][\s\S]*?\$runtime_module\['route_action'\]/.test(routerSource)
    && /case 'defer-native':[\s\S]*?add_defer_attribute_to_script_tag\(\$tag, true\)/.test(routerSource)
    && deferSource.includes('ultracache_build_registered_script_route'),
    'A2: central router consumes registry lane/action for UltraCache helpers');
expect(registrySource.includes("'ultracache-delayed-js-interaction-bootstrap'"),
    'A3: interaction bootstrap is registered as an UltraCache-owned frontend runtime module');
expect(registrySource.includes("'delayed-js-interaction-bootstrap.js'"),
    'A4: runtime registry recognizes the interaction bootstrap asset');
expect(registrySource.includes("'dynamic-script-finder-bootstrap.js'"),
    'A4b: runtime registry recognizes the generic dynamic finder asset');
expect(/'lazy-third-party-iframes'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource) && /'defer' === \$lane[\s\S]*?wp_script_add_data\(\$bundle_handle, 'strategy', 'defer'\)/.test(frontendAssetsSource),
    'A5: lazy third-party iframe runtime stays in the canonical DEFER bundle instead of entering Delay JS');

const earlyBlockingHandles = [
    'ultracache-dynamic-script-finder-bootstrap',
    'ultracache-delayed-js-interaction-bootstrap',
    'ultracache-lcp-request-credentials-bootstrap',
    'ultracache-runtime-js-scan-collector'
];
for (const handle of earlyBlockingHandles) {
    const marker = "'handle'       => '" + handle + "'";
    const start = registrySource.indexOf(marker);
    const section = start >= 0 ? registrySource.slice(start, start + 900) : '';
    expect(section.includes("'lane'                  => 'native'") || section.includes("'lane'         => 'native'"), 'B1: audited parser-early helper remains NATIVE: ' + handle);
    expect(section.includes("'parser_early_required' => true"), 'B2: NATIVE helper carries explicit parser-early requirement: ' + handle);
}

const movedToDeferHandles = [
    'ultracache-mailerlite-lazy-nonce',
    'ultracache-async-css-runtime',
    'ultracache-runtime-font-css-map',
    'ultracache-dynamic-icon-font-delay',
    'ultracache-font-display-cssom-patch',
    'ultracache-woocommerce-cart-fragments-delay',
    'ultracache-woocommerce-esi-optin',
    'ultracache-woocommerce-variable-product-guard'
];
for (const handle of movedToDeferHandles) {
    const marker = "'handle'       => '" + handle + "'";
    const start = registrySource.indexOf(marker);
    const section = start >= 0 ? registrySource.slice(start, start + 900) : '';
    expect(section.includes("'lane'         => 'defer'"), 'B3: non-critical helper moved out of parser-blocking NATIVE lane: ' + handle);
    expect(section.includes("'route_action' => 'defer-native'"), 'B4: moved helper uses native DEFER transport: ' + handle);
}
expect(!registrySource.includes("'policy_debt'  => 'native-lane-audit'"),
    'B5: completed helper native-lane audit leaves no blanket native policy debt marker');



expect(/if \(!empty\(\$auto_events\)\) \{[\s\S]*?ultracache-delayed-js-interaction-bootstrap[\s\S]*?autoEvents/.test(delayTraitSource) && !delayTraitSource.includes('dynamicTrackerGate'),
    'C1: parser-early bootstrap is enqueued only when configured interaction capture is needed');
expect(/'delayed-js-loader'[\s\S]*?'lane'\s*=>\s*'defer'/.test(registrySource) && /'defer' === \$lane[\s\S]*?wp_script_add_data\(\$bundle_handle, 'strategy', 'defer'\)/.test(frontendAssetsSource),
    'C2: full delayed loader is delivered through the WordPress native DEFER bundle');
expect(loaderSource.includes('window.__ultracacheDelayedJsEarlyInteractionV125'),
    'C3: full loader consumes the 3.11.25 early-interaction bridge');
expect(loaderSource.includes('consumeEarlyInteractionBootstrap();'),
    'C4: bootstrap consumption occurs during full-loader initialization');
expect(/consumeEarlyInteractionBootstrap\(\)[\s\S]*?startInteractionRelease\(\)/.test(loaderSource),
    'C5: captured early interaction enters the existing interaction release state machine');
expect(!bootstrapSource.includes('preventDefault(') && !bootstrapSource.includes('stopPropagation(') && !bootstrapSource.includes('stopImmediatePropagation('),
    'C6: early bootstrap never cancels or stops the real visitor interaction');

// Execute the real bootstrap with a minimal event-target harness.
const listeners = Object.create(null);
const fakeWindow = {
    ultracacheDelayedJsInteractionBootstrapConfig: {
        autoEvents: ['mousemove', 'click', 'keydown']
    },
    addEventListener(name, handler, options) {
        if (!listeners[name]) listeners[name] = [];
        listeners[name].push({ handler, options });
    },
    removeEventListener(name, handler) {
        if (!listeners[name]) return;
        listeners[name] = listeners[name].filter((item) => item.handler !== handler);
    }
};
const sandbox = { window: fakeWindow };
vm.createContext(sandbox);
vm.runInContext(bootstrapSource, sandbox);

const state = fakeWindow.__ultracacheDelayedJsEarlyInteractionV125;
expect(!!state && typeof state.stop === 'function', 'D1: bootstrap exposes bounded state + stop hook');
expect((listeners.click || []).length === 1 && (listeners.keydown || []).length === 1 && (listeners.mousemove || []).length === 1,
    'D2: bootstrap binds only configured supported interaction events');

const target = { dispatchEvent() {} };
function fire(name, event) {
    const items = (listeners[name] || []).slice();
    for (const item of items) item.handler(event);
}
fire('mousemove', { type: 'mousemove', target, constructor: function MouseEvent(){}, clientX: 1, clientY: 2 });
expect(state.snapshot && state.snapshot.type === 'mousemove', 'D3: bootstrap captures early mousemove');
fire('keydown', { type: 'keydown', target, constructor: function KeyboardEvent(){}, key: 'Enter', code: 'Enter' });
expect(state.snapshot && state.snapshot.type === 'keydown', 'D4: stronger early interaction replaces lower-priority snapshot');
fire('click', { type: 'click', target, constructor: function MouseEvent(){}, clientX: 5, clientY: 6, button: 0 });
expect(state.snapshot && state.snapshot.type === 'click' && state.snapshot.priority === 5, 'D5: click wins the replay priority ordering');
expect(state.snapshot.target === target, 'D6: captured snapshot retains the original replay target');
state.stop();
expect(Object.values(listeners).every((items) => items.length === 0), 'D7: stop hook removes all bootstrap listeners after loader takeover');

// A site without interaction triggers should not bind anything even if the file were executed.
const quietListeners = [];
const quietWindow = {
    ultracacheDelayedJsInteractionBootstrapConfig: { autoEvents: [] },
    addEventListener(name) { quietListeners.push(name); },
    removeEventListener() {}
};
const quietSandbox = { window: quietWindow };
vm.createContext(quietSandbox);
vm.runInContext(bootstrapSource, quietSandbox);
expect(quietListeners.length === 0, 'E1: empty autoEvents creates no parser-early interaction listeners');

console.log('');
console.log('Result: ' + passes + ' passed, ' + failures.length + ' failed.');
if (failures.length) {
    console.error('Failures:');
    failures.forEach((failure) => console.error(' - ' + failure));
    process.exit(1);
}
