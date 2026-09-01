/**
 * UltraCache 3.12.30 Runtime Scan cycle-weighted progress regression.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'includes/admin/js/diagnostics.js'), 'utf8');

const start = source.indexOf('async function runRuntimeSiteScanAction(options)');
const end = source.indexOf('\nfunction runtimeScanFixerReportsDelayRepairLine(', start);
if (start < 0 || end < 0) throw new Error('Could not extract runRuntimeSiteScanAction');
const fnSource = source.slice(start, end);

let passes = 0;
const failures = [];
function expect(ok, label) {
  if (ok) { passes++; console.log('[PASS] ' + label); }
  else { failures.push(label); console.log('[FAIL] ' + label); }
}

let requestedPasses = [];
let targetCall = 0;
const context = vm.createContext({
  sanitizeRuntimeJsScanDisplayUrl(value) { return String(value || ''); },
  mergeRuntimeScanFixerRecords() { return null; },
  runtimeScanErrorSignature(item) { return JSON.stringify(item || {}); },
  async runRuntimeJsSelfHealingCycles(options) {
    const count = Math.max(1, Number(requestedPasses[targetCall++] || 1));
    for (let pass = 1; pass <= count; pass++) {
      if (typeof options.onCycleProgress === 'function') {
        options.onCycleProgress({ pass, maxScans: Number(options.maxScans || 10), phase: 'cycle-complete' });
      }
    }
    return { success: true, passes: count, totalAdded: 0, residualRuntimeErrors: 0, baselineResult: { errors: [] } };
  },
});
vm.runInContext(fnSource, context);

async function run(labels, perTargetPasses, maxScans = 10) {
  const values = [];
  requestedPasses = perTargetPasses.slice();
  targetCall = 0;
  await context.runRuntimeSiteScanAction({
    defaultScanUrl: 'https://example.test/',
    maxScans,
    discoverTargets: async () => ({ targets: labels.map((label, index) => ({ label, role: 'page', url: 'https://example.test/' + index })) }),
    prepare() {},
    scan() {},
    saveSafeguards() {},
    onProgress(state) { values.push(Number(state.percent)); },
  });
  return values;
}

(async () => {
  const noWoo = await run(['Front page', 'Random published page'], [1, 3]);
  expect(JSON.stringify(noWoo) === JSON.stringify([0, 5, 50, 55, 60, 65, 95, 100]), '2 targets x 10 cycles gives 5% per cycle and jumps unused target slots');

  const woo = await run(['Front page', 'Random published page', 'WooCommerce shop', 'Random published product'], [1, 3, 2, 2]);
  expect(JSON.stringify(woo) === JSON.stringify([0, 2.5, 25, 27.5, 30, 32.5, 50, 52.5, 55, 75, 77.5, 80, 97.5, 100]), '4 targets x 10 cycles gives 2.5% per cycle and keeps final target below 100 until completion');

  const shortCycles = await run(['Front page', 'Random published page'], [1, 1], 4);
  expect(JSON.stringify(shortCycles) === JSON.stringify([0, 12.5, 50, 62.5, 87.5, 100]), 'custom max cycles uses 100/(targets x cycles) and final completion owns 100%');

  expect(!source.includes('runtimeStatusText.match'), 'progress does not parse percentage from status text');
  expect(source.includes('const runtimeProgressStep = 100 / runtimeProgressSlots;'), 'progress step is exactly 100 divided by target count times max cycles');
  expect(source.includes("progress(100, 'complete');"), '100 percent is emitted only by completed site-scan finalization');

  console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
  if (failures.length) process.exit(1);
})().catch((error) => { console.error(error); process.exit(1); });
