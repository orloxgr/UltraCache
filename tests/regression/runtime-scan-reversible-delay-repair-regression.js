/**
 * UltraCache 3.12.27 reversible Runtime Scan repair regression.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'includes/admin/js/diagnostics.js'), 'utf8');

function extractFunction(name, nextName) {
  const start = source.indexOf('function ' + name + '(');
  const end = source.indexOf('\nfunction ' + nextName + '(', start + 1);
  if (start < 0 || end < 0) throw new Error('Could not extract ' + name);
  return source.slice(start, end);
}

let passes = 0;
const failures = [];
function expect(ok, label) {
  if (ok) { passes++; console.log('[PASS] ' + label); }
  else { failures.push(label); console.log('[FAIL] ' + label); }
}
function lines(value) { return String(value || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean); }
function remove(value, removals) {
  const rm = lines(Array.isArray(removals) ? removals.join('\n') : removals).map(v => v.toLowerCase());
  const current = lines(value); const kept = []; const removedLines = [];
  current.forEach(v => { if (rm.includes(v.toLowerCase())) removedLines.push(v); else kept.push(v); });
  return { value: kept.join('\n'), removedLines, removed: removedLines.length };
}
const context = vm.createContext({
  runtimeVisiblePolicyDescriptor(target) { return { target }; },
  isSuggestionPresentInDraft(value, line) { return lines(value).some(v => v.toLowerCase() === String(line).toLowerCase()); },
  mergeUniqueSettingLines(value, add) {
    const current = lines(value); let added = 0;
    add.forEach(line => { if (!current.some(v => v.toLowerCase() === line.toLowerCase())) { current.push(line); added++; } });
    return { value: current.join('\n'), added };
  },
  removeOverlappingSettingLines: remove,
  runtimeScanSuggestionLine(item) { return String(item && item.suggestedExclusion || '').trim(); },
  runtimeScanSuggestionPreferredTarget(item) {
    const target = String(item && item.preferredTarget || '').toLowerCase();
    return target === 'force' || target === 'exclusion' ? target : '';
  }
});
vm.runInContext(extractFunction('buildAutomaticRuntimeFixValues', 'mergeRuntimeScanFixerRecords'), context);

const line = 'googlesitekit-events-provider-woocommerce';
const scanOwned = { suggestions: [{
  suggestedExclusion: line,
  delaySuggestion: line,
  delayRepairRecommended: true,
  delayRepairAutoEligible: true,
  delaySuggestionScannerOwnedExclusion: true,
  alreadyExcluded: true,
  appendable: false,
  confidence: 'recommended'
}] };
const moved = context.buildAutomaticRuntimeFixValues(scanOwned, line, '', '', { delayEnabled: true, preferDeferForAmbiguous: true });
expect(moved.target === 'delay', 'scanner-owned blocking consumer is moved back to Delay first');
expect(moved.reversibleDelayRepair === true, 'move is explicitly marked reversible');
expect(moved.rollbackState && moved.rollbackState.exclusionValue === line, 'exact prior exclusion state is retained for rollback');
expect(!lines(moved.exclusionValue).includes(line), 'consumer is atomically removed from Do Not Defer or Delay');
expect(lines(moved.delayValue).includes(line), 'consumer is atomically added to Delay');

const userOwned = { suggestions: [{
  suggestedExclusion: line,
  delaySuggestion: line,
  delayRepairRecommended: true,
  delayRepairAutoEligible: false,
  alreadyExcluded: true,
  appendable: false,
  confidence: 'recommended'
}] };
const untouched = context.buildAutomaticRuntimeFixValues(userOwned, line, '', '', { delayEnabled: true, preferDeferForAmbiguous: true });
expect(untouched.added === 0, 'unknown/user-authored exclusion is never auto-removed');
expect(source.includes("__('Move to Delay', 'ultracache')"), 'persistent proven dependency repair exposes visible Move to Delay action');
expect(source.includes('reversible-delay-repair-rolled-back'), 'failed reversible trial has an explicit rollback outcome');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
