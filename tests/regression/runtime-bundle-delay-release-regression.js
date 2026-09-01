/**
 * UltraCache 3.12.22 functional Delay-loader self-activation/release regression.
 *
 * Run:
 *   node tests/regression/runtime-bundle-delay-release-regression.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '../..');
const frontendPhp = fs.readFileSync(path.join(root, 'includes/engine/class-engine-frontend-assets-trait.php'), 'utf8');
const loaderSource = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');

function extractNowdoc(functionName) {
    const re = new RegExp(`private function ${functionName}\\(\\)\\s*\\{[\\s\\S]*?return <<<'JS'\\n([\\s\\S]*?)\\nJS;`, 'm');
    const match = frontendPhp.match(re);
    if (!match) throw new Error(`Unable to extract ${functionName}`);
    return match[1];
}
const prelude = extractNowdoc('ultracache_frontend_runtime_bundle_prelude');
const postlude = extractNowdoc('ultracache_frontend_runtime_bundle_postlude');
const bundleSource = `${prelude}\nfactories["delayed-js-loader"]=function(){\n${loaderSource}\n};\nvar autoModules=["delayed-js-loader"];\n${postlude}\n`;

let passes = 0;
const failures = [];
function expect(ok, label) {
    if (ok) { passes++; console.log(`[PASS] ${label}`); }
    else { failures.push(label); console.log(`[FAIL] ${label}`); }
}

let nextTimerId = 1;
const timers = new Map();
function fakeSetTimeout(fn, delay) {
    const id = nextTimerId++;
    timers.set(id, { fn, delay: Number(delay || 0), cleared: false });
    return id;
}
function fakeClearTimeout(id) {
    if (timers.has(id)) timers.get(id).cleared = true;
}
function runTimersAt(delay) {
    const jobs = [...timers.entries()].filter(([, job]) => !job.cleared && job.delay === delay);
    jobs.forEach(([id, job]) => {
        job.cleared = true;
        job.fn();
        timers.set(id, job);
    });
}

function makeNode(initialAttrs = {}) {
    const attrs = { ...initialAttrs };
    return {
        isConnected: true,
        parentNode: null,
        textContent: '',
        id: attrs.id || '',
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? String(attrs[name]) : ''; },
        setAttribute(name, value) { attrs[name] = String(value); if (name === 'id') this.id = String(value); },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
        removeAttribute(name) { delete attrs[name]; },
        _attrs: attrs
    };
}

const delayedNode = makeNode({
    type: 'text/ultracache-delayed-js',
    'data-ultracache-src': 'https://example.test/wp-content/themes/test/app.js',
    'data-ultracache-handle': 'test-app',
    'data-ultracache-delay-reason': 'noncritical-local'
});
const executedScripts = [];
const requestedUrls = [];

const parent = {
    replaceChild(script, oldNode) {
        oldNode.isConnected = false;
        oldNode.parentNode = null;
        script.isConnected = true;
        script.parentNode = parent;
        executedScripts.push(script);
        if (script.src) requestedUrls.push(String(script.src));
        if (typeof script.onload === 'function') script.onload();
        return oldNode;
    },
    removeChild(node) {
        node.isConnected = false;
        node.parentNode = null;
    }
};
delayedNode.parentNode = parent;

const rootElement = { setAttribute() {} };
const currentScript = null;
const document = {
    currentScript,
    readyState: 'complete',
    baseURI: 'https://example.test/',
    documentElement: rootElement,
    body: rootElement,
    head: rootElement,
    querySelectorAll(selector) {
        if (selector.indexOf('text/ultracache-delayed-js') !== -1 && selector.indexOf('script:not') === -1) {
            return delayedNode.isConnected ? [delayedNode] : [];
        }
        if (selector.indexOf('script:not') !== -1) {
            return executedScripts.slice();
        }
        return [];
    },
    createElement(name) {
        const node = makeNode({});
        node.nodeName = String(name).toUpperCase();
        node.async = false;
        node.onload = null;
        node.onerror = null;
        node.src = '';
        node.text = '';
        return node;
    },
    addEventListener() {},
    removeEventListener() {}
};

const window = {
    document,
    window: null,
    location: { href: 'https://example.test/', origin: 'https://example.test', search: '' },
    ultracacheDelayedJsLoaderConfig: {
        autoEvents: [],
        autoAfterLoad: false,
        autoTimerEnabled: true,
        autoDelayMs: 2000,
        minimumReleaseMs: 0,
        runtimeScanInfiniteTriggerMs: 0,
        scriptTimeoutMs: 8000,
        firstPartyParallelExecution: false,
        thirdPartyParallelExecution: false,
        orderedFetchEnabled: false,
        orderedFetchConcurrency: 4,
        relief: false
    },
    setTimeout: fakeSetTimeout,
    clearTimeout: fakeClearTimeout,
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() {},
    performance: { timeOrigin: Date.now() - 100, now() { return 100; } },
    atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
    TextDecoder,
    Uint8Array,
    URL
};
window.window = window;

const context = vm.createContext({
    window,
    document,
    URL,
    console,
    setTimeout: fakeSetTimeout,
    clearTimeout: fakeClearTimeout,
    CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options && options.detail; },
    Event: function Event(name) { this.type = name; },
    Date,
    Math,
    JSON,
    Array,
    Object,
    String,
    Number,
    Boolean,
    RegExp,
    Error,
    Promise,
    isFinite,
    parseInt,
    decodeURIComponent,
    encodeURIComponent
});

vm.runInContext(bundleSource, context, { timeout: 3000 });

expect(window.__ultracacheDelayLoader === 1, 'DEFER runtime bundle activation starts delayed-js-loader');
expect(window.__ultracacheRuntimeModuleExecuted['delayed-js-loader'] === 1, 'bundle activation records delayed-js-loader execution');
expect([...timers.values()].some(job => !job.cleared && job.delay === 2000), 'Auto Release schedules the existing 2000 ms full-release timer');
expect(requestedUrls.length === 0, 'delayed script is not requested before the Auto Release boundary');

runTimersAt(2000);

expect(requestedUrls.length === 1, 'Auto Release causes the delayed external script to be requested');
expect(requestedUrls[0] === 'https://example.test/wp-content/themes/test/app.js', 'Auto Release requests the original delayed script URL');
expect(executedScripts.length === 1 && executedScripts[0].getAttribute('data-ultracache-delayed') === '1', 'released script is replaced as an executable UltraCache-delayed script');
expect(delayedNode.isConnected === false, 'inert delayed placeholder is removed after release');

console.log(`\nResult: ${passes}/${passes + failures.length} PASS`);
if (failures.length) process.exit(1);
