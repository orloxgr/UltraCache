'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const dashboard = fs.readFileSync(path.join(root, 'includes/admin/js/dashboard-application.js'), 'utf8');

function expect(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
    process.stdout.write('PASS: ' + message + '\n');
}

expect(
    dashboard.includes("getAttribute('data-ultracache-modules')"),
    'Runtime Scan verifier reads runtime bundle activation metadata'
);
expect(
    dashboard.includes("indexOf('runtime-js-scan-collector') !== -1"),
    'Runtime Scan verifier recognizes bundled runtime-js-scan-collector module id'
);
expect(
    dashboard.includes("src.indexOf('runtime-js-scan-collector.js') !== -1"),
    'Runtime Scan verifier retains standalone collector fallback compatibility'
);
expect(
    dashboard.includes('!state.scanner || !state.config || configScanId !== scanId'),
    'Runtime Scan still requires activated collector object, config, and matching scan id'
);
expect(
    !/collectorLoaded\s*=\s*!!\(frameDocument\s*&&\s*Array\.from\(frameDocument\.scripts\s*\|\|\s*\[\]\)\.some\(\(script\)\s*=>\s*String\(script\.src\s*\|\|\s*''\)\.indexOf\('runtime-js-scan-collector\.js'\)/.test(dashboard),
    'Runtime Scan verifier is no longer standalone-filename-only'
);

const detectCollector = (scripts) => scripts.some((script) => {
    const src = String(script.src || '');
    if (src.indexOf('runtime-js-scan-collector.js') !== -1) {
        return true;
    }
    const modules = String(script.getAttribute && script.getAttribute('data-ultracache-modules') || '');
    return modules.split(',').map((moduleId) => moduleId.trim()).indexOf('runtime-js-scan-collector') !== -1;
});

const bundledCollector = {
    src: 'https://example.test/wp-content/uploads/ultracache/runtime/runtime-native-abcdef.js',
    getAttribute(name) {
        return name === 'data-ultracache-modules'
            ? 'dynamic-script-finder,runtime-js-scan-collector'
            : null;
    },
};
const unrelatedBundle = {
    src: 'https://example.test/wp-content/uploads/ultracache/runtime/runtime-native-abcdef.js',
    getAttribute(name) {
        return name === 'data-ultracache-modules'
            ? 'dynamic-script-finder,lcp-observer'
            : null;
    },
};

expect(detectCollector([bundledCollector]), 'bundled collector is detected from module activation metadata');
expect(!detectCollector([unrelatedBundle]), 'unrelated runtime bundle is not mistaken for scanner collector');
