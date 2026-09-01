#!/usr/bin/env node
'use strict';
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'includes/admin/js/diagnostics.js'), 'utf8');
let pass = 0; const fail = [];
function expect(ok, label) { if (ok) { pass++; console.log('[PASS] ' + label); } else { fail.push(label); console.log('[FAIL] ' + label); } }

const start = source.indexOf("setRuntimeScanStatus('Preparing Runtime Scan targets…');");
const pre = source.slice(Math.max(0, start - 900), start);
expect(pre.includes("setConsoleErrorInput('');"), 'new Runtime Scan clears prior console input');
expect(pre.includes('setConsoleErrorScan(null);'), 'new Runtime Scan clears prior fixer scan');
expect(pre.includes('setConsoleErrorSuggestions([]);'), 'new Runtime Scan clears prior suggestions');
expect(pre.includes('setJsDiagnosticQueue(null);'), 'new Runtime Scan clears prior DB-backed queue display');

const aggregate = source.indexOf('if (siteOutcome && siteOutcome.mergedFixerScan)');
const aggregateBlock = source.slice(aggregate, aggregate + 1800);
expect(aggregateBlock.includes('} else {') && aggregateBlock.includes('setRuntimeScanAggregateScan(null);'), 'empty aggregate explicitly clears prior aggregate scan');
expect(aggregateBlock.includes('setConsoleErrorScan(null);') && aggregateBlock.includes('setConsoleErrorSuggestions([]);'), 'empty aggregate persists a true empty fixer result');
expect(aggregateBlock.includes('setJsDiagnosticQueue(null);'), 'empty aggregate cannot leave a prior queue result visible');
expect(aggregateBlock.includes('aggregated 0 JavaScript findings'), 'empty aggregate reports zero findings explicitly');

console.log('\nResult: ' + pass + '/' + (pass + fail.length) + ' PASS');
if (fail.length) process.exit(1);
