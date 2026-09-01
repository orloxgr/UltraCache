/**
 * UltraCache 3.12.22 runtime bundle module self-activation regression.
 *
 * Run:
 *   node tests/regression/runtime-bundle-module-activation-regression.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '../..');
const frontendPhp = fs.readFileSync(path.join(root, 'includes/engine/class-engine-frontend-assets-trait.php'), 'utf8');

function extractNowdoc(functionName) {
    const re = new RegExp(
        `private function ${functionName}\\(\\)\\s*\\{[\\s\\S]*?return <<<'JS'\\n([\\s\\S]*?)\\nJS;`,
        'm'
    );
    const match = frontendPhp.match(re);
    if (!match) {
        throw new Error(`Unable to extract ${functionName}`);
    }
    return match[1];
}

const prelude = extractNowdoc('ultracache_frontend_runtime_bundle_prelude');
const postlude = extractNowdoc('ultracache_frontend_runtime_bundle_postlude');

let passes = 0;
const failures = [];
function expect(ok, label) {
    if (ok) {
        passes++;
        console.log(`[PASS] ${label}`);
    } else {
        failures.push(label);
        console.log(`[FAIL] ${label}`);
    }
}

function buildBundle(moduleId, assetName) {
    const source = fs.readFileSync(path.join(root, 'assets/js', assetName), 'utf8');
    return `${prelude}\nfactories[${JSON.stringify(moduleId)}]=function(){\n${source}\n};\nvar autoModules=[${JSON.stringify(moduleId)}];\n${postlude}\n`;
}

function makeBaseContext(moduleId) {
    const currentScript = null;
    const document = {
        currentScript,
        readyState: 'complete',
        baseURI: 'https://example.test/',
        documentElement: {},
        addEventListener() {},
        removeEventListener() {},
        querySelectorAll() { return []; },
        getElementById() { return null; }
    };
    const window = {
        document,
        location: { href: 'https://example.test/', origin: 'https://example.test', search: '' },
        setTimeout() { return 1; },
        clearTimeout() {},
        addEventListener() {},
        removeEventListener() {},
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
        TextDecoder,
        Uint8Array,
        URL,
        parent: null,
        opener: null,
        name: ''
    };
    window.window = window;
    window.parent = window;
    return { window, document };
}

function runBundle(moduleId, assetName, setup) {
    const { window, document } = makeBaseContext(moduleId);
    if (typeof setup === 'function') setup(window, document);
    const context = vm.createContext({
        window,
        document,
        URL,
        console,
        setTimeout: window.setTimeout.bind(window),
        clearTimeout: window.clearTimeout.bind(window),
        MutationObserver: undefined,
        PerformanceObserver: undefined,
        Event: function Event(type) { this.type = type; },
        fetch: function () { return Promise.resolve({ ok: true, status: 200, json: async () => ({}) }); },
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
        encodeURIComponent,
        decodeURIComponent
    });
    vm.runInContext(buildBundle(moduleId, assetName), context, { timeout: 2000 });
    return { window, document, context };
}

const native = runBundle('delayed-js-interaction-bootstrap', 'delayed-js-interaction-bootstrap.js');
expect(typeof native.window.__ultracacheRuntimeModuleFactories['delayed-js-interaction-bootstrap'] === 'function', 'native bundle registers the requested module factory');
expect(!!native.window.__ultracacheDelayedJsEarlyInteractionV125, 'native bundle self-activates the requested module without document.currentScript metadata');
expect(native.window.__ultracacheRuntimeModuleExecuted['delayed-js-interaction-bootstrap'] === 1, 'native activation is recorded exactly once');

const defer = runBundle('lcp-observer', 'lcp-observer.js');
expect(typeof defer.window.__ultracacheRuntimeModuleFactories['lcp-observer'] === 'function', 'defer bundle registers the requested module factory');
expect(defer.window.__ultracacheLcpObserverV122 === 1, 'defer bundle self-activates the requested module without document.currentScript metadata');
expect(defer.window.__ultracacheRuntimeModuleExecuted['lcp-observer'] === 1, 'defer activation is recorded exactly once');

const scanner = runBundle('runtime-js-scan-collector', 'runtime-js-scan-collector.js', (window, document) => {
    window.ultracacheRuntimeJsScanConfig = { scanId: 'scan318', scanContext: 'anonymous' };
    window.name = 'ultracacheRuntimeJsScanBridge:scan318:run318:' + encodeURIComponent('https://admin.example');
    window.parent = { postMessage() {} };
    window.performance = {
        now() { return 0; },
        getEntriesByType() { return []; }
    };
    window.console = console;
    document.visibilityState = 'visible';
});
expect(typeof scanner.window.__ultracacheRuntimeModuleFactories['runtime-js-scan-collector'] === 'function', 'scanner bundle registers the collector factory');
expect(!!scanner.window.__ultracacheRuntimeJsScan, 'runtime scanner collector installs when its native bundle activates');
expect(!!scanner.window.__ultracacheJsClassificationAudit, 'runtime scanner classification collector is installed during activation');
expect(scanner.window.__ultracacheRuntimeModuleExecuted['runtime-js-scan-collector'] === 1, 'scanner collector activation is recorded exactly once');

// A controlled final-URL rebind changes navigation state, not the already-loaded
// bundle element. The installed collector must remain present after that rebind.
scanner.window.location.href = 'https://example.test/final-product/';
scanner.document.baseURI = scanner.window.location.href;
expect(!!scanner.window.__ultracacheRuntimeJsScan && !!scanner.window.__ultracacheJsClassificationAudit, 'verified collector remains installed after controlled final-URL rebind state change');

console.log(`\nResult: ${passes}/${passes + failures.length} PASS`);
if (failures.length) process.exit(1);
