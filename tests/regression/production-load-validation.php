<?php
/**
 * UltraCache 3.11.x production-load regression contracts.
 *
 * WordPress-context validation for the completed-media + crawler-storm
 * scenario. It reads plugin source without mutating the database.
 *
 * Run:
 *   wp eval-file tests/regression/production-load-validation.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
$failures = array();
$passes = 0;

function uc_load_read(string $root, string $relative): string {
    $path = $root . '/' . ltrim($relative, '/');
    $contents = @file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException('Unable to read requested regression fixture.');
    }
    return $contents;
}

function uc_load_function_body(string $source, string $name): string {
    $needle = 'function ' . $name . '(';
    $start = strpos($source, $needle);
    if (false === $start) {
        throw new RuntimeException('Requested function was not found in regression fixture.');
    }
    $brace = strpos($source, '{', $start);
    if (false === $brace) {
        throw new RuntimeException('Opening brace was not found for requested regression function.');
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        if ('{' === $source[$i]) {
            $depth++;
        } elseif ('}' === $source[$i]) {
            $depth--;
            if (0 === $depth) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('Closing brace was not found for requested regression function.');
}

function uc_load_expect(bool $condition, string $label): void {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo esc_html('[PASS] ' . $label) . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo esc_html('[FAIL] ' . $label) . PHP_EOL;
}

function uc_load_before(string $haystack, string $left, string $right): bool {
    $a = strpos($haystack, $left);
    $b = strpos($haystack, $right);
    return false !== $a && false !== $b && $a < $b;
}

$path_url = uc_load_read($root, 'includes/media/class-media-path-url-trait.php');
$affected = uc_load_read($root, 'includes/media/queue/class-media-affected-pages-trait.php');
$runner = uc_load_read($root, 'includes/media/queue/class-media-queue-runner-trait.php');
$schema = uc_load_read($root, 'includes/media/queue/class-media-queue-schema-trait.php');
$units = uc_load_read($root, 'includes/media/queue/class-media-queue-units-trait.php');
$asset_registry = uc_load_read($root, 'includes/storage/class-cache-asset-registry-trait.php');
$analytics = uc_load_read($root, 'includes/engine/class-engine-analytics-trait.php');
$lcp = uc_load_read($root, 'includes/engine/lcp/class-lcp-observation-storage-trait.php');
$action_queue = uc_load_read($root, 'includes/rest/class-rest-action-queue-trait.php');
$warm = uc_load_read($root, 'includes/warmup/class-cron-warm-orchestrator-trait.php');
$lifecycle = uc_load_read($root, 'includes/lifecycle/class-plugin-data-cleanup-trait.php');
$locks = uc_load_read($root, 'includes/core/locks.php');
$scanner = uc_load_read($root, 'includes/profiler/class-runtime-js-scanner-trait.php');
$settings_validation = uc_load_read($root, 'includes/settings/class-settings-validation-trait.php');

$avif = uc_load_function_body($path_url, 'get_avif_url_from_public_url');
$webp = uc_load_function_body($path_url, 'get_webp_url_from_public_url');
$terminal = uc_load_function_body($path_url, 'get_terminal_media_variant_lookup_from_public_url');
$discover = uc_load_function_body($path_url, 'maybe_queue_missing_optimized_media_for_source');
$attachment_discovery = uc_load_function_body($affected, 'maybe_queue_missing_optimized_media_from_public_url');
$local_discovery = uc_load_function_body($affected, 'maybe_queue_missing_local_asset_media');
$batch = uc_load_function_body($runner, 'get_media_queue_batch');
$normalize_page = uc_load_function_body($affected, 'normalize_on_demand_affected_page_url');
$ensure_queue = uc_load_function_body($schema, 'ensure_media_queue_table');
$ensure_units = uc_load_function_body($units, 'ensure_media_queue_units_table');
$ensure_refs = uc_load_function_body($affected, 'ensure_media_page_refs_table');
$get_css = uc_load_function_body($asset_registry, 'get_css_rewrite_map_by_source_url');
$ensure_css = uc_load_function_body($asset_registry, 'ensure_css_rewrite_map_table');
$ensure_assets = uc_load_function_body($asset_registry, 'ensure_cache_asset_refs_table');
$ensure_locks = uc_load_function_body($locks, 'ultracache_ensure_locks_table');
$ensure_analytics = uc_load_function_body($analytics, 'ensure_analytics_table');
$ensure_lcp = uc_load_function_body($lcp, 'ensure_lcp_observations_table');
$ensure_actions = uc_load_function_body($action_queue, 'ensure_action_jobs_table');
$warm_read_ready = uc_load_function_body($warm, 'cron_warm_queue_table_read_ready');
$warm_schema_current = uc_load_function_body($warm, 'cron_warm_queue_schema_is_current');
$warm_recreate = uc_load_function_body($warm, 'recreate_empty_cron_warm_queue_schema');
$lifecycle_run = uc_load_function_body($lifecycle, 'run_schema_lifecycle_upgrade');
$critical_settings_validation = uc_load_function_body($settings_validation, 'validate_critical_settings_support_before_persist');

// A — terminal media is decided before any source/derivative filesystem descriptor.
uc_load_expect(
    uc_load_before($avif, 'get_terminal_media_variant_lookup_from_public_url', 'get_local_image_source_descriptor_from_public_url')
    && uc_load_before($webp, 'get_terminal_media_variant_lookup_from_public_url', 'get_local_image_source_descriptor_from_public_url')
    && str_contains($terminal, "array('done', 'failed', 'skipped')"),
    'A: completed/failed/skipped media terminate before filesystem discovery'
);

// B — transient/indeterminate filesystem state can never create discovery work.
uc_load_expect(
    str_contains($avif, "array('missing', 'stale')") && !str_contains($avif, "'indeterminate'")
    && str_contains($webp, "array('missing', 'stale')") && !str_contains($webp, "'indeterminate'")
    && str_contains($discover, "array('missing', 'stale')"),
    'B: indeterminate filesystem state is non-actionable'
);

// C — crawler repetition cannot dispatch merely by observing an existing queue row.
uc_load_expect(
    uc_load_before($attachment_discovery, 'last_media_queue_upsert_created_work()', "queue_background_generation_dispatch('on_demand')")
    && uc_load_before($local_discovery, 'last_media_queue_upsert_created_work()', "queue_background_generation_dispatch('on_demand_local_asset')"),
    'C: on-demand workers require newly actionable queue work'
);

// D — normal queue polling cannot resurrect completed storage.
uc_load_expect(
    !str_contains($batch, 'repair_media_queue_if_optimized_storage_missing(')
    && str_contains($batch, 'explicit_repair_required'),
    'D: normal worker polling cannot perform implicit completed-media repair'
);

// E — discovery work is bounded before expensive child discovery.
uc_load_expect(
    str_contains($discover, 'consume_on_demand_queue_discovery_attempt()')
    && uc_load_before($discover, 'consume_on_demand_queue_discovery_attempt()', 'maybe_queue_missing_optimized_media_from_public_url')
    && uc_load_before($discover, 'consume_on_demand_queue_discovery_attempt()', 'maybe_queue_missing_local_asset_media'),
    'E: crawler candidates consume a bounded attempted-work budget'
);

// F — arbitrary query variants use the same cache-significant logical-page policy.
uc_load_expect(
    str_contains($normalize_page, 'ultracache_normalize_cache_significant_page_url')
    && str_contains($affected, 'ultracache_media_page_refs_max_per_request'),
    'F: affected-page graph is canonicalized and request-bounded'
);

// G — media/CSS/locks normal readiness trusts schema versions before structural probes.
uc_load_expect(
    uc_load_before($ensure_queue, '!$force_schema_verify && self::MEDIA_QUEUE_DB_VERSION === $version', 'dbDelta(')
    && uc_load_before($ensure_units, '!$force_schema_verify && self::MEDIA_QUEUE_UNITS_DB_VERSION === $version', 'dbDelta(')
    && uc_load_before($ensure_refs, '!$force_schema_verify && self::MEDIA_PAGE_REFS_DB_VERSION === $version', 'dbDelta(')
    && str_contains($ensure_css, '!$force_schema_verify')
    && str_contains($ensure_assets, '!$force_schema_verify')
    && str_contains($ensure_locks, '!$force_schema_verify'),
    'G: media/CSS/lock runtime readiness performs zero schema introspection when current'
);

// H — CSS rewrite-map cache is consulted before schema readiness/SQL.
uc_load_expect(
    uc_load_before($get_css, 'wp_cache_get(', 'ensure_css_rewrite_map_table(')
    && str_contains($get_css, '$cache_found')
    && str_contains($get_css, 'return is_array($cached) ? $cached : array();'),
    'H: CSS rewrite-map positive/negative cache hits return before schema/SQL'
);

// I — analytics hot path trusts its version marker; full existence verification is force-only.
uc_load_expect(
    str_contains($ensure_analytics, 'ensure_analytics_table($force_schema_verify = false)')
    && uc_load_before($ensure_analytics, '!$force_schema_verify && self::get_analytics_db_version() === $version', 'analytics_table_exists()'),
    'I: analytics runtime avoids SHOW TABLES when schema version is current'
);

// J — LCP hot path trusts its version marker while explicit failure/lifecycle force remains available.
uc_load_expect(
    uc_load_before($ensure_lcp, '!$force_check && self::get_lcp_observations_db_version() === $version', 'lcp_observations_table_exists()')
    && str_contains($lcp, 'ensure_lcp_observations_table(true)'),
    'J: LCP runtime avoids schema introspection and retains explicit failure verification'
);

// K — action-job runtime trusts current schema; central lifecycle forces structural verification.
uc_load_expect(
    str_contains($ensure_actions, 'ensure_action_jobs_table($force_schema_verify = false)')
    && uc_load_before($ensure_actions, '!$force_schema_verify && $this->get_action_jobs_db_version() === $version', 'action_jobs_table_exists()')
    && str_contains($lifecycle_run, 'ensure_action_jobs_table(true)'),
    'K: action queue runtime trusts version while lifecycle verifies explicitly'
);

// L — warm queue status/runtime readiness contains no SHOW query and current-schema check verifies only when forced.
uc_load_expect(
    !str_contains($warm_read_ready, "'SHOW TABLES LIKE %s'")
    && !str_contains($warm_read_ready, 'cron_warm_queue_table_exists()')
    && str_contains($warm_schema_current, 'if (!$force_schema_verify)')
    && uc_load_before($warm_schema_current, 'if (!$force_schema_verify)', 'cron_warm_queue_table_exists()')
    && substr_count($warm_recreate, 'cron_warm_queue_schema_is_current($force_schema_verify)') >= 2
    && str_contains($lifecycle_run, 'ensure_cron_warm_queue_table(true)'),
    'L: warm queue normal runtime is version-trusting; lifecycle owns structural verification'
);

// M — analytics lifecycle verification is explicit, not paid by frontend traffic.
uc_load_expect(
    str_contains($lifecycle_run, 'ensure_analytics_table(true)')
    && str_contains($lifecycle_run, 'ensure_lcp_observations_table(true)'),
    'M: central lifecycle explicitly verifies analytics/LCP schemas'
);

// N — frontend media URL resolution contains no direct custom-table DB calls.
uc_load_expect(
    !str_contains($terminal, '$wpdb->')
    && str_contains($terminal, 'get_latest_media_queue_unit_state_by_source_path')
    && str_contains($terminal, 'get_existing_on_demand_media_queue_status'),
    'N: frontend media terminal lookup delegates custom-table reads to queue storage helpers'
);

// O — runtime scanner avoids exclusionary post__not_in queries.
uc_load_expect(
    !str_contains($scanner, 'post__not_in')
    && str_contains($scanner, 'in_array($random_page_id, $excluded_page_ids, true)'),
    'O: runtime scanner filters excluded random pages in PHP without post__not_in'
);

// P — Apache detected by the existing setup server detector bypasses the public capability probe gate.
uc_load_expect(
    str_contains($critical_settings_validation, "'apache' === strtolower")
    && str_contains($critical_settings_validation, '$apache_detected =')
    && uc_load_before($critical_settings_validation, 'if (!$apache_detected)', 'run_apache_static_html_delivery_capability_probe()'),
    'P: locally detected Apache cannot be rejected because Varnish obscures a public capability probe'
);

// Q — production-load contract: all required invariants above must hold together.
$contract_ok = 16 === $passes && empty($failures);
uc_load_expect(
    $contract_ok,
    'Q: completed-media crawler-load and Apache detection contracts are internally consistent'
);

if (!empty($failures)) {
    echo PHP_EOL . esc_html(sprintf('Regression failures: %d', count($failures))) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . esc_html(sprintf('Production-load regression suite: %1$d/%1$d PASS', $passes)) . PHP_EOL;
echo esc_html('Validated contract: completed/terminal media cannot trigger filesystem integrity discovery, queue resurrection, implicit repair, redundant worker dispatch, or runtime schema introspection.') . PHP_EOL;
exit(0);
