<?php
/**
 * UltraCache media state-machine regression contracts.
 *
 * WordPress-context source-level regression harness. It reads plugin source
 * without mutating the database or loading runtime media jobs.
 *
 * Run:
 *   wp eval-file tests/regression/media-state-machine-regression.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
$failures = array();
$passes = 0;

function uc_read(string $root, string $relative): string {
    $path = $root . '/' . ltrim($relative, '/');
    $contents = @file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException('Unable to read requested regression fixture.');
    }
    return $contents;
}

function uc_function_body(string $source, string $name): string {
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

function uc_expect(bool $condition, string $label): void {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo esc_html('[PASS] ' . $label) . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo esc_html('[FAIL] ' . $label) . PHP_EOL;
}

function uc_before(string $haystack, string $left, string $right): bool {
    $a = strpos($haystack, $left);
    $b = strpos($haystack, $right);
    return false !== $a && false !== $b && $a < $b;
}

$schema = uc_read($root, 'includes/media/queue/class-media-queue-schema-trait.php');
$affected = uc_read($root, 'includes/media/queue/class-media-affected-pages-trait.php');
$runner = uc_read($root, 'includes/media/queue/class-media-queue-runner-trait.php');
$unit_runner = uc_read($root, 'includes/media/queue/class-media-queue-unit-runner-trait.php');
$path_url = uc_read($root, 'includes/media/class-media-path-url-trait.php');
$slider = uc_read($root, 'includes/media/class-media-slider-revolution-trait.php');
$units = uc_read($root, 'includes/media/queue/class-media-queue-units-trait.php');
$asset_registry = uc_read($root, 'includes/storage/class-cache-asset-registry-trait.php');
$locks = uc_read($root, 'includes/core/locks.php');

// A — DONE/terminal frontend state is authoritative and frontend upsert is preserve-only.
$attachment_discovery = uc_function_body($affected, 'maybe_queue_missing_optimized_media_from_public_url');
uc_expect(
    str_contains($attachment_discovery, "get_existing_on_demand_media_queue_status")
    && str_contains($attachment_discovery, "array('pending', 'processing')")
    && str_contains($attachment_discovery, "upsert_media_queue_item(\$attachment_id, \$queue_format, 'pending', \$message, 0, false, true)"),
    'A: frontend attachment discovery preserves existing queue states'
);

// B — Missing derivative does not make indeterminate/transient FS state actionable.
$avif = uc_function_body($path_url, 'get_avif_url_from_public_url');
$webp = uc_function_body($path_url, 'get_webp_url_from_public_url');
$slider_lookup = uc_function_body($slider, 'maybe_inventory_slider_revolution_image_source');
uc_expect(
    str_contains($avif, "array('missing', 'stale')") && !str_contains($avif, "'indeterminate'")
    && str_contains($webp, "array('missing', 'stale')") && !str_contains($webp, "'indeterminate'")
    && !str_contains($slider_lookup, "'indeterminate'"),
    'B: indeterminate filesystem state cannot enqueue media work'
);

// C — New work dispatch is gated by explicit actionable upsert effect.
$local_discovery = uc_function_body($affected, 'maybe_queue_missing_local_asset_media');
uc_expect(
    uc_before($attachment_discovery, 'last_media_queue_upsert_created_work()', "queue_background_generation_dispatch('on_demand')")
    && uc_before($local_discovery, 'last_media_queue_upsert_created_work()', "queue_background_generation_dispatch('on_demand_local_asset')"),
    'C: worker dispatch occurs only after actionable queue creation'
);

// D — Normal queue polling cannot silently repair completed media; terminal lookup precedes FS descriptor.
$batch = uc_function_body($runner, 'get_media_queue_batch');
uc_expect(
    !str_contains($batch, 'repair_media_queue_if_optimized_storage_missing(')
    && str_contains($batch, 'explicit_repair_required')
    && uc_before($avif, 'get_terminal_media_variant_lookup_from_public_url', 'get_local_image_source_descriptor_from_public_url')
    && uc_before($webp, 'get_terminal_media_variant_lookup_from_public_url', 'get_local_image_source_descriptor_from_public_url'),
    'D: completed/crawler fast path cannot trigger implicit repair or pre-terminal filesystem discovery'
);

// E — Affected-page identity uses the shared cache-significant query contract and a bounded write budget.
$normalize_page = uc_function_body($affected, 'normalize_on_demand_affected_page_url');
$page_budget = uc_function_body($affected, 'get_on_demand_affected_page_max_writes_per_request');
uc_expect(
    str_contains($normalize_page, 'ultracache_normalize_cache_significant_page_url')
    && str_contains($page_budget, 'ultracache_media_page_refs_max_per_request')
    && str_contains($page_budget, ': 50'),
    'E: query-string graph growth is canonicalized and request-bounded'
);

// F — Local assets consult queue state before filesystem normalization and preserve existing state on frontend upsert.
uc_expect(
    uc_before($local_discovery, 'get_existing_on_demand_media_queue_status', 'normalize_local_asset_queue_source')
    && str_contains($local_discovery, "upsert_local_asset_media_queue_item(\$source, \$missing_format, 'pending', \$message, 0, false, true)"),
    'F: local assets use the same terminal-state frontend contract'
);

// G — Runtime schema readiness trusts stored versions before any introspection path.
$ensure_queue = uc_function_body($schema, 'ensure_media_queue_table');
$ensure_units = uc_function_body($units, 'ensure_media_queue_units_table');
$ensure_refs = uc_function_body($affected, 'ensure_media_page_refs_table');
$ensure_css = uc_function_body($asset_registry, 'ensure_css_rewrite_map_table');
$ensure_assets = uc_function_body($asset_registry, 'ensure_cache_asset_refs_table');
$ensure_locks = uc_function_body($locks, 'ultracache_ensure_locks_table');
uc_expect(
    uc_before($ensure_queue, '!$force_schema_verify && self::MEDIA_QUEUE_DB_VERSION === $version', 'dbDelta(')
    && uc_before($ensure_units, '!$force_schema_verify && self::MEDIA_QUEUE_UNITS_DB_VERSION === $version', 'dbDelta(')
    && uc_before($ensure_refs, '!$force_schema_verify && self::MEDIA_PAGE_REFS_DB_VERSION === $version', 'dbDelta(')
    && str_contains($ensure_css, '!$force_schema_verify')
    && str_contains($ensure_assets, '!$force_schema_verify')
    && str_contains($ensure_locks, '!$force_schema_verify'),
    'G: normal runtime schema readiness is version-trusting, not introspection-driven'
);

// H — PROCESSING is worker-owned for non-forced generic attachment upserts.
$attachment_upsert = uc_function_body($schema, 'upsert_media_queue_item');
uc_expect(
    str_contains($attachment_upsert, "!\$force_pending && 'pending' === \$status")
    && str_contains($attachment_upsert, "array('failed', 'processing')")
    && str_contains($attachment_upsert, "set_last_media_queue_upsert_effect('preserved')"),
    'H: non-forced generic upsert cannot steal PROCESSING work'
);

// I — FAILED is absolute until explicit Retry Failed; retry SQL targets failed only.
$retry = uc_function_body($runner, 'retry_failed_media_queue_items');
$retry_units = uc_function_body($unit_runner, 'retry_media_queue_units_for_parent_format');
uc_expect(
    str_contains($attachment_upsert, "array('failed', 'processing')")
    && str_contains($retry, "status = 'failed'")
    && !str_contains($retry, "status = 'processing'")
    && str_contains($retry_units, "units.status = 'failed'")
    && !str_contains($retry_units, "units.status = 'processing'"),
    'I: FAILED remains terminal until explicit failed-only retry'
);

// J — Discovery cap is consumed before expensive source resolution/queue-state work.
$common_discovery = uc_function_body($path_url, 'maybe_queue_missing_optimized_media_for_source');
uc_expect(
    str_contains($common_discovery, 'consume_on_demand_queue_discovery_attempt()')
    && uc_before($common_discovery, 'consume_on_demand_queue_discovery_attempt()', 'maybe_queue_missing_optimized_media_from_public_url')
    && uc_before($common_discovery, 'consume_on_demand_queue_discovery_attempt()', 'maybe_queue_missing_local_asset_media'),
    'J: on-demand limit counts attempted expensive candidates, not successful inserts'
);

// K — Explicit repair remains available, but normal queue fetching cannot invoke it automatically.
uc_expect(
    str_contains($schema, 'function repair_media_queue_if_optimized_storage_missing(')
    && !str_contains($batch, 'repair_media_queue_if_optimized_storage_missing('),
    'K: completed-media repair exists only as an explicit path'
);

if (!empty($failures)) {
    echo PHP_EOL . esc_html(sprintf('Regression failures: %d', count($failures))) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . esc_html(sprintf('Media state-machine regression suite: %1$d/%1$d PASS', $passes)) . PHP_EOL;
exit(0);
