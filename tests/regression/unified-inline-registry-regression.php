<?php
/**
 * UltraCache 3.12.36 Unified Inline Registry HTML regression.
 *
 * Run:
 *   php tests/regression/unified-inline-registry-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ULTRACACHE_URL', 'https://site.example/wp-content/plugins/ultracache/');
define('ULTRACACHE_VERSION', '3.12.36');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim((string) $value); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://site.example' . (string) $path; }
}
if (!function_exists('ultracache_query_value')) {
    function ultracache_query_value($key) { return ''; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($value) { return rtrim((string) $value, '/') . '/'; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url) { return (string) $url . (str_contains((string) $url, '?') ? '&' : '?') . rawurlencode((string) $key) . '=' . rawurlencode((string) $value); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, (int) $flags); }
}
if (!function_exists('wp_get_script_tag')) {
    function wp_get_script_tag($attributes) {
        $out = '<script';
        foreach ((array) $attributes as $name => $value) {
            if (true === $value) { $out .= ' ' . $name; continue; }
            if (false === $value || null === $value) { continue; }
            $out .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out . '></script>' . "\n";
    }
}
if (!function_exists('wp_get_inline_script_tag')) {
    function wp_get_inline_script_tag($content, $attributes = array()) {
        $out = '<script';
        foreach ((array) $attributes as $name => $value) {
            if (true === $value) { $out .= ' ' . $name; continue; }
            if (false === $value || null === $value) { continue; }
            $out .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $out . '>' . (string) $content . '</script>' . "\n";
    }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-html-rewrite-trait.php';

final class UltraCacheInlineRegistryHarness
{
    use Ultra_Cache_Engine_JS_HTML_Rewrite_Trait;

    private function html_tag_processor_available() { return false; }
    private function extract_attribute_from_html_tag($html, $attribute) {
        $attribute = preg_quote((string) $attribute, '/');
        if (preg_match('/\s' . $attribute . '\s*=\s*(?:\"([^\"]*)\"|\'([^\']*)\'|([^\s>]+))/i', (string) $html, $m)) {
            return html_entity_decode((string) ($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''))), ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
    private function absolutize_public_resource_url($src, $base = '') { return (string) $src; }
    private function normalize_public_resource_url($src) { return (string) $src; }

    public function collect(string $html): string
    {
        return $this->ultracache_collect_non_native_inline_registry_in_html($html, array());
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; }
    else { $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL; }
};

$body = '<html><body><script id="native-inline">window.nativeInline=1;</script>';
for ($i = 0; $i < 50; $i++) {
    $body .= '<script defer data-ultracache-handle="google-product-family" id="product-inline-' . $i . '">window.productPayloads.push(' . $i . ');</script>';
}
$delayAttrs = base64_encode('{}');
$body .= '<script type="text/ultracache-delayed-js" data-ultracache-inline="1" data-ultracache-handle="delayed-family" data-ultracache-attrs="' . $delayAttrs . '">window.delayedPayload=1;</script>';
$body .= '</body></html>';

$out = (new UltraCacheInlineRegistryHarness())->collect($body);

$expect(substr_count($out, 'inline-registry-dispatcher.js') === 50, 'A1: 50 DEFER inline occurrences become 50 same-asset dispatch positions');
$expect(substr_count($out, 'data-ultracache-inline-registry-dispatcher="1"') === 50, 'A2: every DEFER occurrence gets an explicit dispatcher marker');
$expect(str_contains($out, '<script id="native-inline">window.nativeInline=1;</script>'), 'A3: NATIVE inline JavaScript remains browser-owned');
$expect(str_contains($out, 'type="text/ultracache-delayed-js"') && str_contains($out, 'data-ultracache-inline-registry="1"'), 'A4: DELAY inline stays an inert delayed placeholder backed by the registry');
$expect(!str_contains($out, '>window.delayedPayload=1;</script>'), 'A5: DELAY source is removed from the individual placeholder body');

if (preg_match('/<script[^>]*id="ultracache-inline-registry-v1"[^>]*>(.*?)<\/script>/s', $out, $m)) {
    $manifest = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
} else {
    $manifest = null;
}
$expect(is_array($manifest) && ($manifest['count'] ?? 0) === 51, 'B1: one manifest contains all 50 DEFER + 1 DELAY inline occurrences');
$entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : array();
$expect(count($entries) === 51, 'B2: registry preserves one record per occurrence');
$productEntries = array_values(array_filter($entries, static fn($entry) => ($entry['handle'] ?? '') === 'google-product-family'));
$expect(count($productEntries) === 50, 'B3: same handle does not collapse 50 product records');
$expect(array_map(static fn($entry) => $entry['ordinal'], $productEntries) === range(1, 50), 'B4: product occurrence ordinals preserve original DOM order');
$expect(count(array_unique(array_map(static fn($entry) => $entry['fingerprint'], $productEntries))) === 50, 'B5: distinct product payloads retain distinct exact-code fingerprints');
$expect(($productEntries[0]['code'] ?? '') === 'window.productPayloads.push(0);' && ($productEntries[49]['code'] ?? '') === 'window.productPayloads.push(49);', 'B6: first and last product payload source are preserved exactly');
$expect(count(array_unique(array_keys($entries))) === 51, 'B7: every registry key is occurrence-unique');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
