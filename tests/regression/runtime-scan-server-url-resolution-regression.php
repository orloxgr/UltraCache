<?php
/** UltraCache 3.12.37 Runtime Scan server-side URL resolution regression. */

$root = dirname(__DIR__, 2);
$scanner = file_get_contents($root . '/includes/profiler/class-runtime-js-scanner-trait.php');

function uc31237_expect($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

uc31237_expect(false !== strpos($scanner, 'private function runtime_js_scan_resolve_final_target_url'), 'server-side final-target resolver exists');
uc31237_expect(false !== strpos($scanner, 'wp_safe_remote_head($current'), 'redirect resolution happens through WordPress HTTP API, not a browser iframe');
uc31237_expect(false !== strpos($scanner, 'wp_safe_remote_get($current'), 'HEAD-not-supported targets fall back to a bounded one-byte GET without browser execution');
uc31237_expect(false !== strpos($scanner, "'redirection'         => 0"), 'redirect hops are followed explicitly and bounded');
uc31237_expect(false !== strpos($scanner, 'for ($hop = 0; $hop < 5; $hop++)'), 'redirect resolution has a bounded hop count');
uc31237_expect(false !== strpos($scanner, 'ultracache_runtime_js_scan_normalize_target_url($location)'), 'every redirect target is revalidated as same-site');
uc31237_expect(false !== strpos($scanner, '$resolved_url = $this->runtime_js_scan_resolve_final_target_url($normalized_url);'), 'token creation resolves the final target before minting');
uc31237_expect(false !== strpos($scanner, 'ultracache_runtime_js_scan_mint_token($scan_id, $resolved_url)'), 'scan token is bound to the pre-resolved URL');
uc31237_expect(false !== strpos($scanner, "'requestedUrl' => \$normalized_url"), 'token response preserves original normalized target for diagnostics');

echo "PASS: Runtime Scan server-side URL resolution regression.\n";
