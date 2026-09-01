<?php
/**
 * UltraCache 3.13.11 Setup Wizard / Runtime Scanner orchestration regression.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$diagnostics = file_get_contents($root . '/includes/admin/js/diagnostics.js');
$wizardTrait = file_get_contents($root . '/includes/setup/class-setup-wizard-trait.php');

if (!is_string($dashboard) || !is_string($diagnostics) || !is_string($wizardTrait)) {
    fwrite(STDERR, "FAIL: required source file missing\n");
    exit(1);
}

$passes = 0;
$expect = static function (bool $condition, string $message) use (&$passes): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    ++$passes;
    fwrite(STDOUT, "PASS: {$message}\n");
};

$assistantStart = strpos($dashboard, 'async function runJavascriptPostInstallAssistant(onProgress)');
$assistantEnd = strpos($dashboard, 'async function confirmFirstRunWizard()', (int) $assistantStart);
$assistant = false !== $assistantStart && false !== $assistantEnd ? substr($dashboard, $assistantStart, $assistantEnd - $assistantStart) : '';

$confirmStart = strpos($dashboard, 'async function confirmFirstRunWizard()');
$confirmEnd = strpos($dashboard, 'async function finishFirstRunWizard()', (int) $confirmStart);
$confirm = false !== $confirmStart && false !== $confirmEnd ? substr($dashboard, $confirmStart, $confirmEnd - $confirmStart) : '';

$expect('' !== $assistant, 'Wizard JavaScript orchestration function is present');
$expect(false !== strpos($assistant, 'runRuntimeSiteScanAction({'), 'Wizard delegates JavaScript verification to the shared Runtime Site Scan action');
$expect(false === strpos($assistant, 'Automatic JavaScript verification failed.'), 'Wizard no longer invents a generic JavaScript verification failure');
$expect(false === strpos($assistant, 'siteOutcome.failedCount'), 'Wizard does not redefine Runtime Site Scan failed-target semantics');
$expect(false === strpos($assistant, 'siteOutcome.unresolvedCount'), 'Wizard does not redefine Runtime Site Scan unresolved-target semantics');
$expect(false !== strpos($assistant, "siteOutcome.summaryState === 'warning'") && false !== strpos($assistant, 'siteOutcome.summaryMessage'), 'Wizard consumes the shared scanner warning/summary contract');
$expect(false !== strpos($assistant, 'Runtime Scanner could not complete automatic verification. Setup continued.'), 'Runtime Scanner infrastructure failure is downgraded to a Wizard warning and setup continues');

$expect('' !== $confirm, 'Wizard apply orchestration function is present');
$expect(false === strpos($confirm, "wizardFailureStep = 'javascript'"), 'Wizard never persists JavaScript as a failure-resume step');
$expect(false !== strpos($confirm, "wizardFailureStep = 'verify'"), 'Unexpected post-prepare failure resumes at Verify instead of looping back to JavaScript');
$expect(false !== strpos($dashboard, "const staleJavascriptFailure = resumeStep === 'javascript'"), 'Legacy persisted JavaScript failures are detected during resume');
$expect(false !== strpos($dashboard, "? 'verify'"), 'Legacy persisted JavaScript failures are normalized forward to Verify');

$expect(false !== strpos($wizardTrait, "if ('javascript' === \$requested_step)"), 'Server-side Wizard state normalizes legacy JavaScript failure requests');
$expect(false !== strpos($wizardTrait, "array('configure', 'prepare', 'verify')"), 'Server-side failure state no longer accepts JavaScript as a resumable failure step');

$expect(false !== strpos($diagnostics, 'summaryState: summaryState') && false !== strpos($diagnostics, 'summaryMessage: summaryMessage'), 'Runtime Site Scan action owns the shared completion/warning summary');
$expect(false !== strpos($diagnostics, "const reason = String(item.measurementFailureReason || item.message || item.reason"), 'Shared scanner summary preserves exact per-target failure provenance');

fwrite(STDOUT, "PASS: Setup Wizard is a Runtime Scanner orchestrator ({$passes}/{$passes}).\n");
