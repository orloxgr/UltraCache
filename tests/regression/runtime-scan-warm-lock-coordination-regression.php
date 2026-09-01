<?php
/** UltraCache 3.12.33 Runtime Scan warm lock coordination regression. */
$root = dirname(__DIR__, 2);
$rest = file_get_contents($root . '/includes/rest/class-rest-cache-trait.php');
$pipeline = file_get_contents($root . '/includes/warmup/class-warm-page-pipeline-trait.php');
$dashboard = file_get_contents($root . '/includes/admin/js/dashboard-application.js');
$runner = file_get_contents($root . '/includes/warmup/class-warm-runner-trait.php');
$pass = 0;
$fail = array();
$expect = function ($ok, $label) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "[PASS] $label\n"; }
    else { $fail[] = $label; echo "[FAIL] $label\n"; }
};

$restPurgePos = strpos($rest, '$purge_target_first = rest_sanitize_boolean');
$restWarmArgsPos = strpos($rest, "'purge_target_first'    => \$purge_target_first");
$restDirectPurge = strpos(substr($rest, $restPurgePos, max(0, $restWarmArgsPos - $restPurgePos)), 'purge_url($url)');
$expect(false !== $restPurgePos && false !== $restWarmArgsPos && false === $restDirectPurge,
    'REST passes purgeTargetFirst into the warm pipeline instead of purging before URL lock acquisition');

$lockPos = strpos($pipeline, '$lock = $this->acquire_warm_pipeline_url_lock');
$pipelinePurgePos = strpos($pipeline, "if (!empty(\$args['purge_target_first']))");
$runPos = strpos($pipeline, 'return $this->run_warm_page_pipeline($url, $args);');
$expect(false !== $lockPos && false !== $pipelinePurgePos && false !== $runPos && $lockPos < $pipelinePurgePos && $pipelinePurgePos < $runPos,
    'target purge occurs after URL lock acquisition and before warm pipeline execution');
$expect(strpos($pipeline, "'failureClass' => 'target-purge-failed'") !== false,
    'targeted purge failure is explicit and terminal inside the ownership boundary');
$expect(strpos($runner, "if (!empty(\$args['purge_target_first']) && method_exists(\$this, 'warm_page_pipeline'))") !== false,
    'direct warm_url purge requests are routed into the locked page pipeline instead of bypassing the ownership boundary');

$expect(strpos($dashboard, "String(warmError.data.failureClass || '') === 'url-lock-busy'") !== false
    && strpos($dashboard, 'warmError.data.coalesced') !== false
    && strpos($dashboard, 'await sleep(5000);') !== false,
    'thrown HTTP 202 url-lock-busy responses enter bounded Runtime Scan retry');
$expect(substr_count($dashboard, 'lockRetryCount += 1;') >= 2,
    'both returned and thrown url-lock-busy paths consume the same bounded retry budget');

echo "\nResult: $pass/" . ($pass + count($fail)) . " PASS\n";
if ($fail) { exit(1); }
