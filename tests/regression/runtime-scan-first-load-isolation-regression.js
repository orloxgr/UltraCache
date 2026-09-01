'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const dashboard = fs.readFileSync(path.join(root, 'includes/admin/js/dashboard-application.js'), 'utf8');
const diagnostics = fs.readFileSync(path.join(root, 'includes/admin/js/diagnostics.js'), 'utf8');

function expect(condition, message) {
    if (!condition) throw new Error(message);
    process.stdout.write('PASS: ' + message + '\n');
}

const start = dashboard.indexOf('async function runBrowserRuntimeJsScanForUrl');
const end = dashboard.indexOf('async function runJsDelaySafetyScanForUrl', start);
const scan = dashboard.slice(start, end);

expect(start >= 0 && end > start, 'Browser Runtime Scan implementation is present');
expect(scan.includes("apiRequest('runtime_js_scan_token', {\n\t\t\t\t\tscanId: scanId,\n\t\t\t\t\turl: scanUrl,"), 'scan token endpoint receives the selected target directly');
expect(!scan.includes('iframe.src = scanUrl'), 'measurement iframe is never used for pre-scan URL resolution');
expect(!scan.includes('resolveNavigation') && !scan.includes('navigationPromise'), 'browser-side pre-resolution navigation state is removed');
expect(!scan.includes('collectorRebind'), 'scanner no longer reloads a contaminated measurement context for collector rebinding');
expect(scan.includes("setAttribute('data-ultracache-runtime-scan-measurement', '1')"), 'measurement frame is explicitly identified');
expect((scan.match(/installFreshMeasurementFrame\(runtimeUrl\)/g) || []).length === 1, 'each scan pass installs exactly one measurement frame');
expect(scan.includes('No browser measurement is available for this target; the scanner will report this exact target failure without reusing the browser context.'), 'collector injection failure reports an exact per-target measurement failure without reusing the browser context');

expect(diagnostics.includes('runtimeScanCurrentTarget'), 'Runtime Scan modal tracks current target state');
expect(diagnostics.includes('onTargetStart: function(targetMeta)'), 'current target state updates from the real target-start callback');
expect(diagnostics.includes("__('Current target', 'ultracache')"), 'Runtime Scan popup labels the active target');
expect(diagnostics.includes("String(runtimeScanCurrentTarget.number || 1) + '/' + String(runtimeScanCurrentTarget.count || 1)"), 'Runtime Scan popup shows target position within the site scan');
expect(diagnostics.includes("String(runtimeScanCurrentTarget.label || 'Page')"), 'Runtime Scan popup shows the actual target label');
expect(diagnostics.includes("String(runtimeScanCurrentTarget.url)"), 'Runtime Scan popup shows the actual target URL');

console.log('PASS: Runtime Scan first-load isolation and current-target popup regression.');
