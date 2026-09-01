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

const scanStart = dashboard.indexOf('async function runBrowserRuntimeJsScanForUrl');
const scanEnd = dashboard.indexOf('async function runJsDelaySafetyScanForUrl', scanStart);
const scan = dashboard.slice(scanStart, scanEnd);

expect(scanStart >= 0 && scanEnd > scanStart, 'Browser Runtime Scan implementation is present');
expect(scan.includes("integration: 'real-cookie-banner'") && scan.includes('Continuing Runtime Scan.'), 'RCB scanner-state reset failure is non-blocking and retained as a diagnostic warning');
expect(scan.includes("integration: 'complianz'") && scan.includes('Continuing Runtime Scan.'), 'Complianz scanner-state reset failure is non-blocking and retained as a diagnostic warning');
expect(!scan.includes("failureReason: 'real-cookie-banner-state-reset-failed'"), 'RCB reset failure no longer returns a fatal measurement result');
expect(!scan.includes("failureReason: 'complianz-state-reset-failed'"), 'Complianz reset failure no longer returns a fatal measurement result');
expect(dashboard.includes("reason: 'reset-frame-timeout'") && dashboard.includes("reason: 'reset-frame-error-event'"), 'reset diagnostics distinguish timeout from iframe error event');
expect(scan.includes('isolationDiagnostics: isolationDiagnostics') && scan.includes('isolationWarnings: isolationWarnings.slice()'), 'successful browser measurements retain reset diagnostics without blocking');
expect(scan.includes("apiRequest('runtime_js_scan_token', {\n\t\t\t\t\tscanId: scanId,\n\t\t\t\t\turl: scanUrl,"), '3.12.37 server-side final URL/token resolution remains in place');
expect(!scan.includes('collectorRebind'), 'graceful failure does not reintroduce contaminated collector rebinding');
expect(scan.includes('No browser measurement is available for this target; the scanner will report this exact target failure without reusing the browser context.'), 'collector failure reports exact target-level measurement unavailability');

const cyclesStart = diagnostics.indexOf('async function runRuntimeJsSelfHealingCycles');
const cyclesEnd = diagnostics.indexOf('function formatRuntimeScanComparisonEntries', cyclesStart);
const cycles = diagnostics.slice(cyclesStart, cyclesEnd);
expect(cyclesStart >= 0 && cyclesEnd > cyclesStart, 'Runtime self-healing cycle implementation is present');
expect(cycles.includes("reason: 'baseline-measurement-unavailable'"), 'baseline measurement failure returns a structured outcome');
expect(cycles.includes("reason: 'cycle-measurement-unavailable'"), 'optimized measurement failure returns a structured outcome');
expect(!cycles.includes("throw new Error((baselineResult && baselineResult.message)"), 'baseline browser measurement failure no longer throws out of the site scanner');
expect(!cycles.includes("throw new Error((optimizedResult && optimizedResult.message)"), 'optimized browser measurement failure no longer throws out of the site scanner');
expect(diagnostics.includes('measurementFailureCount'), 'multi-target scanner counts browser measurement failures separately');
expect(diagnostics.includes('summaryMessage: summaryMessage') && diagnostics.includes('issueMessages: issueMessages.slice()'), 'Runtime Site Scan owns one shared exact warning summary for every caller');
expect(diagnostics.includes('Consent-state reset warning:'), 'non-blocking consent reset warning remains visible per target when it occurs');

console.log('PASS: Runtime Scan graceful-failure regression.');

(async () => {
    const vm = require('vm');
    const cycleStart = diagnostics.indexOf('async function runRuntimeJsSelfHealingCycles(options)');
    const cycleEnd = diagnostics.indexOf('\nfunction formatRuntimeScanComparisonEntries', cycleStart);
    const cycleSource = diagnostics.slice(cycleStart, cycleEnd);
    const cycleContext = vm.createContext({});
    vm.runInContext(cycleSource, cycleContext);

    let restoreCount = 0;
    const baselineUnavailable = await cycleContext.runRuntimeJsSelfHealingCycles({
        targetUrl: 'https://example.test/',
        maxScans: 2,
        beginBaselineState: async () => ({ saved: true }),
        restoreBaselineState: async () => { restoreCount++; },
        prepare: async () => {},
        scan: async () => ({ available: false, failureReason: 'collector-not-injected', message: 'collector missing' }),
        saveSafeguards: async () => ({ success: true }),
    });
    expect(baselineUnavailable && baselineUnavailable.reason === 'baseline-measurement-unavailable', 'baseline measurement failure resolves as a structured outcome instead of rejecting');
    expect(baselineUnavailable.measurementFailureReason === 'collector-not-injected', 'baseline structured outcome preserves the exact browser failure reason');
    expect(restoreCount === 1, 'baseline JavaScript settings are restored after graceful baseline measurement failure');

    let scanCall = 0;
    const optimizedUnavailable = await cycleContext.runRuntimeJsSelfHealingCycles({
        targetUrl: 'https://example.test/',
        maxScans: 2,
        prepare: async () => {},
        scan: async () => {
            scanCall++;
            if (scanCall === 1) return { available: true, runtimeErrorCount: 0, errors: [], scripts: [] };
            return { available: false, failureReason: 'incomplete-report', message: 'incomplete collector report' };
        },
        saveSafeguards: async () => ({ success: true }),
    });
    expect(optimizedUnavailable && optimizedUnavailable.reason === 'cycle-measurement-unavailable', 'optimized measurement failure resolves as a structured outcome instead of rejecting');
    expect(optimizedUnavailable.measurementFailureReason === 'incomplete-report', 'optimized structured outcome preserves the exact browser failure reason');

    const siteStart = diagnostics.indexOf('async function runRuntimeSiteScanAction(options)');
    const siteEnd = diagnostics.indexOf('\nfunction runtimeScanFixerReportsDelayRepairLine(', siteStart);
    const siteSource = diagnostics.slice(siteStart, siteEnd);
    let targetCall = 0;
    const siteContext = vm.createContext({
        sanitizeRuntimeJsScanDisplayUrl(value) { return String(value || ''); },
        mergeRuntimeScanFixerRecords() { return null; },
        runtimeScanErrorSignature(item) { return JSON.stringify(item || {}); },
        async runRuntimeJsSelfHealingCycles() {
            targetCall++;
            if (targetCall === 1) {
                return {
                    success: false,
                    reason: 'baseline-measurement-unavailable',
                    message: 'collector missing',
                    measurementFailureReason: 'collector-not-injected',
                    passes: 0,
                    totalAdded: 0,
                    residualRuntimeErrors: 0,
                    baselineResult: null,
                    result: null,
                };
            }
            return { success: true, reason: 'complete', passes: 1, totalAdded: 0, residualRuntimeErrors: 0, baselineResult: { errors: [] }, result: { isolationWarnings: [] } };
        },
    });
    vm.runInContext(siteSource, siteContext);
    const siteOutcome = await siteContext.runRuntimeSiteScanAction({
        defaultScanUrl: 'https://example.test/',
        maxScans: 2,
        discoverTargets: async () => ({ targets: [
            { label: 'First', role: 'page', url: 'https://example.test/one' },
            { label: 'Second', role: 'page', url: 'https://example.test/two' },
        ] }),
        prepare: async () => {},
        scan: async () => {},
        saveSafeguards: async () => ({ success: true }),
    });
    expect(targetCall === 2, 'multi-target Runtime Site Scan continues to the next target after a structured measurement failure');
    expect(siteOutcome.measurementFailureCount === 1, 'multi-target result counts the measurement-unavailable target separately');
    expect(siteOutcome.targetResults[0].measurementFailureReason === 'collector-not-injected', 'target result exposes the exact browser measurement failure reason');
    expect(siteOutcome.summaryState === 'warning', 'shared Runtime Site Scan summary classifies a measurement-unavailable result as warning rather than fatal');
    expect(String(siteOutcome.summaryMessage || '').includes('First: collector-not-injected'), 'shared Runtime Site Scan summary includes the exact failed target and browser reason');

    console.log('PASS: Runtime Scan graceful-failure functional checks.');
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
