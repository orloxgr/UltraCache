const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'assets/js/runtime-js-scan-collector.js'), 'utf8');
const failures = [];
const expect = (condition, message) => { if (!condition) failures.push(message); };

expect(source.includes("searchParams.get('ultracache_runtime_js_scan') === '1'"), 'collector verifies Runtime Scan marker before URL fallback');
expect(source.includes("searchParams.get('ultracache_runtime_js_scan_id')"), 'collector can recover scan id from verified scan URL');
expect(source.includes('window.ultracacheRuntimeJsScanConfig = config;'), 'collector restores verifier-visible config after URL fallback');
expect(source.includes("if (!scanId || (!bridgeMode && (!endpoint || !restNonce)))"), 'collector still refuses to install without a scan id');

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('PASS: Runtime Scan collector can self-bootstrap scanId from the authorized scan URL.');
