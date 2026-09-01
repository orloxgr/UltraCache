/**
 * UltraCache 3.12.07 interaction-bootstrap config regression.
 *
 * The opaque transport remains available for parser-early interaction event
 * names, but it must no longer carry tracker/CMP classification data.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const bootstrap = fs.readFileSync(path.join(root, 'assets/js/delayed-js-interaction-bootstrap.js'), 'utf8');
const delayPhp = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-delay-trait.php'), 'utf8');

let pass = 0;
const failures = [];
function expect(value, label) {
    if (value) { pass++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

expect(bootstrap.includes('decodeOpaqueConfig'), 'opaque configuration transport remains available');
expect(bootstrap.includes('config.autoEvents'), 'opaque bootstrap configuration is used for interaction event names');
expect(!bootstrap.includes('dynamicTrackerGate'), 'bootstrap consumes no dynamic tracker policy');
expect(!delayPhp.includes("'dynamicTrackerGate'"), 'server emits no dynamic tracker gate configuration');
expect(delayPhp.includes("'autoEvents' => $auto_events"), 'server emits only the required interaction event config for this bootstrap');
expect(!delayPhp.includes('$dynamic_tracker_patterns') && !delayPhp.includes('$dynamic_tracker_exclusions'), 'server computes no tracker/CMP pattern payload for parser-early code');

console.log('\nResult: ' + pass + '/' + (pass + failures.length) + ' PASS');
if (failures.length) process.exit(1);
