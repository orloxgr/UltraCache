/**
 * UltraCache 3.12.24 Runtime Scan ambiguous auto-fix regression.
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

const fn = extractFunction('buildAutomaticRuntimeFixValues', 'mergeRuntimeScanFixerRecords');
let passes = 0;
const failures = [];
function expect(ok, label) {
  if (ok) { passes++; console.log('[PASS] ' + label); }
  else { failures.push(label); console.log('[FAIL] ' + label); }
}

function lines(value) { return String(value || '').split(/\r?\n/).map(v => v.trim()).filter(Boolean); }
const context = vm.createContext({
  runtimeVisiblePolicyDescriptor(target) { return { target }; },
  isSuggestionPresentInDraft(value, line) { return lines(value).some(v => v.toLowerCase() === String(line).toLowerCase()); },
  mergeUniqueSettingLines(value, add) {
    const current = lines(value); let added = 0;
    add.forEach(line => { if (!current.some(v => v.toLowerCase() === line.toLowerCase())) { current.push(line); added++; } });
    return { value: current.join('\n'), added };
  },
  removeOverlappingSettingLines(value, remove) {
    const rm = remove.map(v => v.toLowerCase());
    return lines(value).filter(v => !rm.includes(v.toLowerCase())).join('\n');
  },
  runtimeScanSuggestionLine(item) { return String(item && item.suggestedExclusion || '').trim(); },
  runtimeScanSuggestionPreferredTarget(item) {
    const target = String(item && item.preferredTarget || '').toLowerCase();
    return target === 'force' || target === 'exclusion' ? target : '';
  }
});
vm.runInContext(fn, context);

const scan = { suggestions: [{
  suggestedExclusion: 'woocommerce-products-filter/js/front.js',
  appendable: true,
  confidence: 'recommended',
  preferredTarget: ''
}] };
const first = context.buildAutomaticRuntimeFixValues(scan, '', '', '', { preferDeferForAmbiguous: true, delayEnabled: true });
expect(first.added === 1, 'ambiguous recommended runtime finding produces an automatic fix');
expect(first.target === 'force', 'first automatic fix goes to Defer Instead');
expect(first.forceValue.includes('woocommerce-products-filter/js/front.js'), 'Defer Instead receives the exact WOOF script pattern');

const second = context.buildAutomaticRuntimeFixValues(scan, '', first.forceValue, '', { preferDeferForAmbiguous: true, delayEnabled: true });
expect(second.added === 1, 'persistent ambiguous finding can escalate on the next cycle');
expect(second.target === 'exclusion', 'persistent finding escalates from Defer Instead to Do Not Defer or Delay');

expect(source.includes('preferDeferForAmbiguous: true'), 'Runtime Site Scan invokes the repair cycle with Defer-first ambiguity handling');
expect(!source.includes("(jsDiagnosticQueue && !runtimeScanAggregateScan)"), 'Runtime Site Scan does not suppress the actual JS Diagnostic Queue panel');
expect(!source.includes("'Runtime Scan Results'"), 'duplicate aggregate findings panel is absent');

console.log('\nResult: ' + passes + '/' + (passes + failures.length) + ' PASS');
if (failures.length) process.exit(1);
