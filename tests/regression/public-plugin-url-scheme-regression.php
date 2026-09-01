<?php
/**
 * Regression: UltraCache plugin-owned public URLs must use the configured
 * public home scheme, even when an internal origin/warm request reaches
 * WordPress over plain HTTP.
 *
 * Run:
 *   php tests/regression/public-plugin-url-scheme-regression.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$plugin_source = file_get_contents($root . '/ultracache.php');
$rewrite_source = file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }

    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$expect(
    is_string($plugin_source)
        && false !== strpos($plugin_source, "wp_parse_url(home_url('/'), PHP_URL_SCHEME)")
        && false !== strpos($plugin_source, 'set_url_scheme($ultracache_plugin_url, $ultracache_public_scheme)'),
    'ULTRACACHE_URL is pinned to the configured public home scheme'
);

$expect(
    is_string($rewrite_source)
        && false !== strpos($rewrite_source, "trailingslashit((string) ULTRACACHE_URL) . 'assets/js/inline-registry-dispatcher.js'"),
    'inline registry dispatcher inherits the canonical UltraCache public base URL'
);

$canonicalize = static function (string $plugin_url, string $home_url): string {
    $scheme = strtolower((string) parse_url($home_url, PHP_URL_SCHEME));
    if (!in_array($scheme, array('http', 'https'), true)) {
        return $plugin_url;
    }

    return (string) preg_replace('#^https?://#i', $scheme . '://', $plugin_url, 1);
};

$expect(
    'https://site.example/wp-content/plugins/ultracache/' === $canonicalize(
        'http://site.example/wp-content/plugins/ultracache/',
        'https://site.example/'
    ),
    'HTTPS public site overrides an HTTP internal-origin plugin URL'
);

$expect(
    'http://site.example/wp-content/plugins/ultracache/' === $canonicalize(
        'https://site.example/wp-content/plugins/ultracache/',
        'http://site.example/'
    ),
    'genuine HTTP public site remains HTTP instead of being forced to HTTPS'
);

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if ($failures) {
    exit(1);
}
