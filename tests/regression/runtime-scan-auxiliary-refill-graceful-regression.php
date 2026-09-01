<?php
/** UltraCache 3.13.12 Runtime Scan auxiliary refill graceful-failure regression. */
$root = dirname(__DIR__, 2);
$rest = file_get_contents($root . '/includes/rest/class-rest-cache-trait.php');
$pipeline = file_get_contents($root . '/includes/warmup/class-warm-page-pipeline-trait.php');
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$pass = 0;
$fail = array();
$expect = function ($ok, $label) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "[PASS] $label\n"; }
    else { $fail[] = $label; echo "[FAIL] $label\n"; }
};

$expect(strpos($rest, '$manual_job_type = sanitize_key((string) ($initial_renewal[\'state\'][\'jobType\'] ?? \'\'));') !== false,
    'crawl-page derives the warm context from the existing shared foreground session job type');
$expect(strpos($rest, "'runtime_scan' === \$manual_job_type ? 'runtime_scan' : 'manual'") !== false,
    'Runtime Scan reuses crawl-page while normal manual warm retains the manual context');
$expect(strpos($pipeline, "\$external_refills_best_effort = 'runtime_scan' === \$warm_context;") !== false,
    'only the runtime_scan warm context makes external cache refills best effort');
$expect(strpos($pipeline, "\$external_refills_best_effort ? 'warning' : 'failed'") !== false,
    'an unavailable auxiliary refill becomes a warning only for Runtime Scan');
$expect(substr_count($pipeline, "(\$external_refills_best_effort ? 'warning' : 'failed')") >= 2,
    'both Varnish and LiteSpeed failed refill results use the shared Runtime Scan warning policy');
$expect(strpos($pipeline, "\$varnish_completed = !\$varnish_required || in_array(\$varnish_status, array('completed', 'warning'), true);") !== false,
    'Varnish warning does not invalidate a successful Runtime Scan page warm');
$expect(strpos($pipeline, "\$litespeed_completed = !\$litespeed_required || in_array(\$litespeed_status, array('completed', 'warning'), true);") !== false,
    'LiteSpeed warning does not invalidate a successful Runtime Scan page warm');
$expect(strpos($pipeline, "'failureClass' => sanitize_key((string) (\$litespeed_result['failureClass'] ?? ''))") !== false,
    'LiteSpeed exact failure class remains in the shared pipeline stage details');
$expect(strpos($pipeline, "\$result['auxiliaryWarnings'][] = array(") !== false
    && strpos($pipeline, "'failureClass' => sanitize_key((string) (\$stage_details['failureClass'] ?? ''))") !== false,
    'successful pipeline results expose bounded auxiliary warning diagnostics');
$expect(strpos($dashboard, 'const auxiliaryWarnings = warmResult && Array.isArray(warmResult.auxiliaryWarnings)') !== false,
    'Runtime Scanner consumes the shared warm pipeline auxiliary warnings');
$expect(strpos($dashboard, "report('runtime_scan_refresh', 'warning', warningMessage);") !== false,
    'Runtime Scanner reports auxiliary refill failure details as a non-blocking warning');
$expect(strpos($dashboard, "if (!warmResult || (!warmResult.success && !warmResult.skipped))") !== false,
    'Runtime Scanner still blocks when the required page warm itself does not succeed');
$expect(strpos($rest, "\$warm_args['include_litespeed'] = true;") !== false
    && strpos($rest, "\$warm_args['include_varnish'] = true;") !== false,
    'Runtime Scan still attempts configured external refills instead of silently skipping them');
$expect(strpos($pipeline, "array('manual', 'ui', 'dashboard', 'diagnostic', 'runtime_scan')") !== false,
    'Runtime Scan uses the ordinary foreground UI execution profile');

// Normal manual behavior must remain strict: the ternary preserves failed status
// outside runtime_scan rather than globally downgrading refill failures.
$expect(strpos($pipeline, "(\$external_refills_best_effort ? 'warning' : 'failed')") !== false,
    'normal manual refill failures remain failed rather than being globally downgraded');

echo "\nResult: $pass/" . ($pass + count($fail)) . " PASS\n";
if ($fail) { exit(1); }
