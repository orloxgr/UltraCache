<?php
/**
 * UltraCache Retry Failed Media backend/UI regression contracts.
 *
 * WordPress-context source-level regression harness. It reads plugin source
 * without mutating the database or loading runtime media jobs.
 *
 * Run:
 *   wp eval-file tests/regression/retry-failed-media-regression.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$root = dirname(__DIR__, 2);
$failures = array();
$passes = 0;

function uc_retry_read(string $root, string $relative): string {
    $path = $root . '/' . ltrim($relative, '/');
    $contents = @file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException('Unable to read requested regression fixture.');
    }
    return $contents;
}

function uc_retry_function_body(string $source, string $name): string {
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

function uc_retry_expect(bool $condition, string $label): void {
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo esc_html('[PASS] ' . $label) . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo esc_html('[FAIL] ' . $label) . PHP_EOL;
}

function uc_retry_before(string $haystack, string $left, string $right): bool {
    $a = strpos($haystack, $left);
    $b = strpos($haystack, $right);
    return false !== $a && false !== $b && $a < $b;
}

$runner = uc_retry_read($root, 'includes/media/queue/class-media-queue-runner-trait.php');
$units = uc_retry_read($root, 'includes/media/queue/class-media-queue-unit-runner-trait.php');
$schema = uc_retry_read($root, 'includes/media/queue/class-media-queue-schema-trait.php');
$dashboard = uc_retry_read($root, 'includes/admin/js/dashboard-application.js');

$retry = uc_retry_function_body($runner, 'retry_failed_media_queue_items');
$retry_units = uc_retry_function_body($units, 'retry_media_queue_units_for_parent_format');
$get_status = uc_retry_function_body($schema, 'get_media_queue_status');

// A — Parent retry is FAILED-only.
uc_retry_expect(
    str_contains($retry, "WHERE format = %s AND status = 'failed'")
    && !str_contains($retry, "status = 'processing'")
    && !str_contains($retry, "status IN ('failed','processing')")
    && !str_contains($retry, "status IN ('processing','failed')"),
    'A: parent retry resets FAILED only'
);

// B — Physical-unit retry is FAILED-only.
uc_retry_expect(
    str_contains($retry_units, "units.status = 'failed'")
    && !str_contains($retry_units, "units.status = 'processing'")
    && !str_contains($retry_units, "units.status IN ('failed','processing')")
    && !str_contains($retry_units, "units.status IN ('processing','failed')"),
    'B: physical-unit retry resets FAILED only'
);

// C — Retry does not steal a live worker and uses the existing process lock.
uc_retry_expect(
    str_contains($retry, "acquire_media_queue_process_lock('retry_failed')")
    && str_contains($retry, "'busy' => true")
    && str_contains($retry, 'release_media_queue_process_lock($lock_token)'),
    'C: Retry Failed respects active-worker ownership'
);

// D — Dispatch only follows real failed work reset.
uc_retry_expect(
    str_contains($retry, 'if (($retried + $retried_units) > 0)')
    && uc_retry_before($retry, 'if (($retried + $retried_units) > 0)', "queue_background_generation_dispatch('retry_failed')")
    && str_contains($retry, "'recoveredInterrupted' => 0"),
    'D: Retry Failed dispatches only after real failed work was reset'
);

// E — Status keeps FAILED retryable and interrupted recovery separate.
uc_retry_expect(
    str_contains($get_status, "'retryable' => (int) \$counts['failed']")
    && str_contains($get_status, "'recoverableInterrupted' => (int) \$recoverable_interrupted"),
    'E: retryable status means FAILED media only'
);

// F — Dashboard consumes retryable contract, with compatibility fallback only.
uc_retry_expect(
    str_contains($dashboard, 'undefined !== effectiveMediaQueueStatus.retryable')
    && str_contains($dashboard, '? effectiveMediaQueueStatus.retryable')
    && str_contains($dashboard, ': mediaQueueFailed'),
    'F: dashboard enablement consumes the backend retryable contract'
);

// G — Retry control is always rendered, disabled at zero, and keeps the count visible.
$retry_block_start = strpos($dashboard, "h('div', { key: 'retry' }");
$retry_block_end = false !== $retry_block_start ? strpos($dashboard, "h('div', { key: 'clear-completed' }", $retry_block_start) : false;
$retry_block = (false !== $retry_block_start && false !== $retry_block_end)
    ? substr($dashboard, $retry_block_start, $retry_block_end - $retry_block_start)
    : '';
uc_retry_expect(
    '' !== $retry_block
    && str_contains($retry_block, 'Retry failed media')
    && str_contains($retry_block, 'mediaQueueRetryable <= 0')
    && str_contains($retry_block, "__('Failed media: ', 'ultracache') + formatNumber(mediaQueueFailed)"),
    'G: Retry failed media is always visible, disabled at zero, with persistent failed count'
);

// H — The retry action cannot be conditionally omitted from the Media Optimization action list.
$retry_prefix = false !== $retry_block_start ? substr($dashboard, max(0, $retry_block_start - 180), 180) : '';
uc_retry_expect(
    '' !== $retry_block
    && !preg_match('/\?\s*h\(\'div\'\s*,\s*\{\s*key:\s*\'retry\'/s', $retry_prefix . substr($retry_block, 0, 40)),
    'H: retry control is rendered unconditionally'
);

if (!empty($failures)) {
    echo PHP_EOL . esc_html(sprintf('Regression failures: %d', count($failures))) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . esc_html(sprintf('Retry Failed Media regression suite: %1$d/%1$d PASS', $passes)) . PHP_EOL;
exit(0);
