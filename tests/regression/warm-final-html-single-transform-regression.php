<?php
/** UltraCache 3.13.08 warm raw-source ownership regression. */
$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/includes/warmup/class-warm-runner-trait.php');
$engine = file_get_contents($root . '/includes/class-ultra-cache-engine.php');
$storage = file_get_contents($root . '/includes/engine/class-engine-storage-trait.php');
$pass = 0;
$fail = array();
$expect = function ($ok, $label) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "[PASS] $label\n"; }
    else { $fail[] = $label; echo "[FAIL] $label\n"; }
};

$expect(strpos($storage, "private function is_warm_raw_source_loopback_request()") !== false
    && strpos($storage, "HTTP_X_ULTRACACHE_WARM_SOURCE") !== false
    && strpos($storage, "ultracache_is_authenticated_internal_request('warm')") !== false,
    'raw warm source contract requires an authenticated internal warm request');

$sourceBypassPos = strpos($storage, "if (\$this->is_warm_raw_source_loopback_request())");
$earlyHitPathPos = strpos($storage, "if (\$this->is_force_refresh_loopback_request())");
$expect(false !== $sourceBypassPos && false !== $earlyHitPathPos && $sourceBypassPos < $earlyHitPathPos,
    'raw warm source requests bypass the WordPress early page-cache hit before rendering');

$rawBranchPos = strpos($engine, "if (\$this->is_warm_raw_source_loopback_request())");
$rawContractPos = strpos($engine, "header('X-UltraCache-Warm-Body-Contract: raw-v1')");
$transformPos = strpos($engine, '$html = $this->process_final_html_for_cache_storage($html, true');
$expect(false !== $rawBranchPos && false !== $rawContractPos && false !== $transformPos
    && $rawBranchPos < $rawContractPos && $rawContractPos < $transformPos,
    'engine returns authenticated raw warm source before the normal final/store transformer');
$expect(strpos($engine, "X-UltraCache-Warm-Body-Contract: final-v1") === false,
    'engine no longer exposes already-final warm HTML to warm_url');

$expect(strpos($runner, "'X-UltraCache-Warm-Source'        => 'raw-v1'") !== false,
    'warm runner explicitly requests the raw source contract');
$expect(strpos($runner, "'Cache-Control'                   => 'no-cache, no-store, must-revalidate, max-age=0'") !== false,
    'raw warm source loopback is requested as non-cacheable transport');

$shared = '$shared_source_render = count($buckets) > 1 && in_array(\'orig\', $buckets, true);';
$expect(strpos($runner, $shared) !== false,
    'normal and force-refresh warms may share one raw WordPress render across media buckets');
$expect(strpos($runner, '$shared_source_render = !$force_refresh') === false,
    'force-refresh no longer disables shared raw-source rendering');

$expect(substr_count($runner, 'process_final_html_for_cache_storage($bucket_html, false') === 1,
    'warm runner owns exactly one per-bucket final-transform call site');
$expect(strpos($runner, "if (!\$response_body_is_final && method_exists(\$this, 'process_final_html_for_cache_storage'))") === false,
    'warm runner no longer treats an already-final orig response as a derivation source');
$expect(strpos($runner, "\$response_body_is_raw = 'raw-v1' === \$warm_body_contract;") !== false
    && strpos($runner, "'responseBodyRaw' => \$response_body_is_raw") !== false,
    'force-refresh diagnostics expose the negotiated raw warm body contract');


$requestPolicy = file_get_contents($root . '/includes/core/request-policy.php');
$expect(strpos($requestPolicy, "!empty(\$GLOBALS['ultracache_anonymous_public_cache_transform'])") !== false,
    'logged-in frontend bypass is disabled only inside the scoped anonymous public-cache transform context');
$expect(strpos($runner, "\$GLOBALS['ultracache_anonymous_public_cache_transform'] = true;") !== false
    && strpos($runner, 'finally {') !== false
    && strpos($runner, "unset(\$GLOBALS['ultracache_anonymous_public_cache_transform']);") !== false,
    'warm finalizer scopes anonymous public-cache context and restores it in finally');

echo "\nResult: $pass/" . ($pass + count($fail)) . " PASS\n";
if ($fail) { exit(1); }
