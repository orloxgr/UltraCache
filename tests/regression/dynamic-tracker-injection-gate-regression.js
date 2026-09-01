/**
 * UltraCache 3.12.07 dynamic tracker workaround removal regression.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const bootstrap = fs.readFileSync(path.join(root, 'assets/js/delayed-js-interaction-bootstrap.js'), 'utf8');
const loader = fs.readFileSync(path.join(root, 'assets/js/delayed-js-loader.js'), 'utf8');
const delayPhp = fs.readFileSync(path.join(root, 'includes/engine/js/class-js-delay-trait.php'), 'utf8');

let pass = 0;
const failures = [];
function expect(value, label) {
    if (value) { pass++; console.log('[PASS] ' + label); }
    else { failures.push(label); console.log('[FAIL] ' + label); }
}

expect(!bootstrap.includes('installDynamicTrackerGate'), 'dynamic tracker gate installer is removed');
expect(!bootstrap.includes('__ultracacheDynamicTrackerGate'), 'interaction bootstrap exposes no hidden tracker gate global');
expect(!bootstrap.includes('Node.prototype.insertBefore') && !bootstrap.includes('Node.prototype.appendChild') && !bootstrap.includes('Node.prototype.replaceChild'), 'interaction bootstrap no longer monkey-patches DOM insertion methods for tracker policy');
expect(!loader.includes('releaseDynamicTrackerGate'), 'Delay loader no longer owns tracker-gate release');
expect(!loader.includes('dynamic-tracker-count') && !loader.includes('dynamic-trackers-released'), 'Delay runtime emits no tracker-gate release telemetry markers');
expect(!delayPhp.includes('dynamicTrackerGate') && !delayPhp.includes('dynamic_tracker_patterns'), 'server bootstrap config contains no hidden tracker/CMP gate policy');
expect(bootstrap.includes('configuredEvents') && bootstrap.includes('snapshotEvent'), 'parser-early bootstrap still performs its valid early-interaction capture job');
expect(loader.includes('function runThirdPartyLane()'), 'normal third-party Delay lane remains intact');

console.log('\nResult: ' + pass + '/' + (pass + failures.length) + ' PASS');
if (failures.length) process.exit(1);
