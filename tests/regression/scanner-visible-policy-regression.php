<?php
/**
 * UltraCache 3.12.13 Scanner-as-Teacher regression.
 *
 * The Runtime Scanner may store diagnostic findings, and the explicit
 * self-healing workflow may save a deterministic fix, but every policy write
 * must terminate in one of the visible JavaScript lists. Diagnostic queue
 * state must never become an execution-policy input.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contract = require $root . '/tests/architecture/js-policy-contract.php';
$diagnostics = file_get_contents($root . '/includes/admin/js/diagnostics.js');
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$rules = file_get_contents($root . '/includes/profiler/class-runtime-js-rules-trait.php');

$failures = array();
$passes = 0;
$expect = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$expect('3.13.10' === ($contract['contract_version'] ?? ''), 'A1: architecture contract is 3.13.10');
$scanner = $contract['scanner_policy'] ?? array();
$expect('teacher' === ($scanner['role'] ?? ''), 'A2: scanner role is teacher');
$expect(false === ($scanner['diagnostic_queue_is_policy_authority'] ?? true), 'A3: diagnostic queue is not policy authority');
$expect(false === ($scanner['stored_findings_affect_runtime'] ?? true), 'A4: stored findings do not affect runtime');
$expect('visible-setting-only' === ($scanner['automatic_write_mode'] ?? ''), 'A5: automatic writes are visible-setting-only');
$expect(false === ($scanner['hidden_override_storage'] ?? true), 'A6: hidden scanner override storage is forbidden');

$targets = $scanner['allowed_targets'] ?? array();
$expect('delaySafeThirdPartyJsPatterns' === ($targets['delay']['setting'] ?? ''), 'B1: DELAY target is the visible Delay third-party list');
$expect('deferJsForceList' === ($targets['force']['setting'] ?? ''), 'B2: DEFER target is visible Defer Instead of Delay');
$expect('deferJsExcludeList' === ($targets['exclusion']['setting'] ?? ''), 'B3: NATIVE target is visible Do Not Defer or Delay');
$expect('delay' === ($targets['delay']['lane'] ?? ''), 'B4: Delay list maps to DELAY lane');
$expect('defer' === ($targets['force']['lane'] ?? ''), 'B5: Defer Instead maps to DEFER lane');
$expect('native' === ($targets['exclusion']['lane'] ?? ''), 'B6: Do Not Defer maps to NATIVE lane');

$expect(is_string($rules) && false !== strpos($rules, "'policyAuthority'    => 'visible-lists'"), 'C1: every generated Runtime Scan suggestion declares visible-list authority');
$expect(is_string($rules) && false !== strpos($rules, "'policyWriteMode'    => 'visible-setting-only'"), 'C2: every generated suggestion declares visible-setting-only writes');
$expect(is_string($rules) && false !== strpos($rules, "'hiddenOverride'     => false"), 'C3: generated suggestions explicitly reject hidden override semantics');

$expect(is_string($diagnostics) && false !== strpos($diagnostics, 'function runtimeVisiblePolicyDescriptor(target)'), 'D1: admin diagnostics has one visible-policy target descriptor');
$expect(substr_count((string) $diagnostics, "settingKey: 'delaySafeThirdPartyJsPatterns'") === 1, 'D2: automatic descriptor has exactly one Delay visible setting target');
$expect(substr_count((string) $diagnostics, "settingKey: 'deferJsForceList'") === 1, 'D3: automatic descriptor has exactly one Defer visible setting target');
$expect(substr_count((string) $diagnostics, "settingKey: 'deferJsExcludeList'") === 1, 'D4: automatic descriptor has exactly one Native visible setting target');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, 'runtimeAutomaticDecisionIsVisiblePolicyOnly(decision)'), 'D5: automatic decisions are validated before persistence');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, "throw new Error('Runtime Scan refused a non-visible JavaScript policy write.')"), 'D6: self-healing cycle rejects non-visible policy writes');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, "policyWrite: runtimeVisiblePolicyDescriptor('delay')"), 'D7: Delay auto-fix carries visible policy descriptor');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, "policyWrite: runtimeVisiblePolicyDescriptor('force')"), 'D8: Defer auto-fix carries visible policy descriptor');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, "policyWrite: runtimeVisiblePolicyDescriptor('exclusion')"), 'D9: Native auto-fix carries visible policy descriptor');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, 'const policyWrite = runtimeVisiblePolicyDescriptor(target);'), 'D10: manual suggestion action validates the target against visible policy');
$expect(is_string($diagnostics) && false === strpos($diagnostics, "} else {\n\t\t\t\tappendToDelayDraft(line);"), 'D11: unknown manual action target cannot silently fall through to Delay');

$expect(is_string($dashboard) && false !== strpos($dashboard, 'onSaveRuntimeSafeguards: (excludeValue, forceValue, delayValue, decision) => queueSettingsPatch({'), 'E1: scanner auto-save terminates in normal settings patching while carrying request-only provenance metadata');
$autoSavePos = is_string($dashboard) ? strpos($dashboard, 'onSaveRuntimeSafeguards: (excludeValue, forceValue, delayValue, decision) => queueSettingsPatch({') : false;
$autoSaveChunk = false !== $autoSavePos ? substr($dashboard, $autoSavePos, 430) : '';
$expect(false !== strpos($autoSaveChunk, 'deferJsExcludeList: excludeValue'), 'E2: scanner auto-save writes visible native list');
$expect(false !== strpos($autoSaveChunk, 'deferJsForceList: forceValue'), 'E3: scanner auto-save writes visible defer list');
$expect(false !== strpos($autoSaveChunk, 'delaySafeThirdPartyJsPatterns: delayValue'), 'E4: scanner auto-save writes visible delay list');
$expect(false === strpos($autoSaveChunk, 'update_option') && false === strpos($autoSaveChunk, 'hidden') && false === strpos($autoSaveChunk, 'override'), 'E5: auto-save callback contains no hidden execution-policy persistence channel');
$expect(false !== strpos((string) $dashboard, 'runtimeJsScanDecision: decision || null'), 'E6: scanner provenance is passed as request metadata alongside visible-list writes');

$engineDir = $root . '/includes/engine/js';
$engineQueueRefs = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($engineDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || 'php' !== strtolower((string) $file->getExtension())) {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source)) {
        continue;
    }
    $engineQueueRefs += substr_count($source, 'runtime_js_diagnostic_queue');
    $engineQueueRefs += substr_count($source, 'ultracache_js_diagnostic_queue');
}
$expect(0 === $engineQueueRefs, 'F1: JS engine never reads diagnostic queue state');
$engineOwnershipRefs = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($engineDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || 'php' !== strtolower((string) $file->getExtension())) { continue; }
    $source = file_get_contents($file->getPathname());
    if (is_string($source)) { $engineOwnershipRefs += substr_count($source, 'ultracache_runtime_js_scan_owned_safeguards_v1'); }
}
$expect(0 === $engineOwnershipRefs, 'F1b: scanner ownership metadata is provenance only and is never read by the JavaScript execution engine');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, 'Stored results never become hidden execution policy'), 'F2: UI explicitly states stored findings are not runtime policy');

$expect(is_string($diagnostics) && false !== strpos($diagnostics, "writeMode: 'visible-setting-only'"), 'G1: visible policy descriptor marks its write mode');
$expect(is_string($diagnostics) && false !== strpos($diagnostics, 'hiddenOverride: false'), 'G2: visible policy descriptor explicitly forbids hidden override');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
