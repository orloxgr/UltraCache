#!/usr/bin/env php
<?php
/**
 * Regression: unified JS exclusions protect WordPress script groups such as
 * googlesitekit-events-provider-woocommerce (-js-before / -js / -js-after).
 *
 * Usage:
 *   php bin/regression-site-kit-woocommerce-js-exclusion.php
 *   php bin/regression-site-kit-woocommerce-js-exclusion.php --url=https://example.com/
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$options = getopt('', array('url::'));
$url = isset($options['url']) ? trim((string) $options['url']) : '';

require_once dirname(__DIR__) . '/includes/core/functions.php';
require_once dirname(__DIR__) . '/includes/class-ultra-cache-engine.php';

$needle = 'googlesitekit-events-provider-woocommerce';
$settings = array(
    'defer_js'                        => true,
    'defer_all_js'                    => true,
    'defer_stage_safe'                => true,
    'defer_stage_balanced'            => true,
    'defer_stage_aggressive'          => true,
    'delay_safe_third_party_js'       => true,
    'delay_all_third_party_js'        => true,
    'delay_functional_third_party_js' => true,
    'delay_non_critical_js'           => true,
    'delay_non_critical_js_aggressive' => true,
    'main_thread_relief'              => true,
    'defer_js_exclude_list'           => array($needle),
);

$sample_html = <<<'HTML'
<!doctype html><html><head></head><body>
<script id="googlesitekit-events-provider-woocommerce-js-before">window._googlesitekit.wcdata = window._googlesitekit.wcdata || {};</script>
<script src="/wp-content/plugins/google-site-kit/dist/assets/js/googlesitekit-events-provider-woocommerce.js" id="googlesitekit-events-provider-woocommerce-js"></script>
<script id="googlesitekit-events-provider-woocommerce-js-after">/* after */</script>
</body></html>
HTML;

$engine = Ultra_Cache_Engine::get_instance();
$reflection = new ReflectionClass($engine);

$is_excluded = $reflection->getMethod('is_js_excluded_by_user_patterns');
$is_excluded->setAccessible(true);

$checks = array(
    array('googlesitekit-events-provider-woocommerce-js', '/wp-content/plugins/google-site-kit/dist/assets/js/googlesitekit-events-provider-woocommerce.js', '<script id="googlesitekit-events-provider-woocommerce-js"></script>', ''),
    array('googlesitekit-events-provider-woocommerce-js-before', '', '<script id="googlesitekit-events-provider-woocommerce-js-before"></script>', 'window._googlesitekit.wcdata = window._googlesitekit.wcdata || {};'),
);

foreach ($checks as $check) {
    if (!$is_excluded->invoke($engine, $check[0], $check[1], $check[2], $check[3], $settings)) {
        fwrite(STDERR, "FAIL: unified exclusion did not match {$check[0]}\n");
        exit(1);
    }
}

$delay_non_critical = $reflection->getMethod('delay_non_critical_scripts_in_html');
$delay_non_critical->setAccessible(true);
$delay_third_party = $reflection->getMethod('delay_third_party_analytics_scripts_in_html');
$delay_third_party->setAccessible(true);

$optimized = (string) $delay_non_critical->invoke($engine, $sample_html, $settings);
$optimized = (string) $delay_third_party->invoke($engine, $optimized, $settings);

$failures = array();
$patterns = array(
    'before-id' => '#<script[^>]*id=["\']googlesitekit-events-provider-woocommerce-js-before["\'][^>]*>#i',
    'main-id'   => '#<script[^>]*id=["\']googlesitekit-events-provider-woocommerce-js["\'][^>]*>#i',
    'after-id'  => '#<script[^>]*id=["\']googlesitekit-events-provider-woocommerce-js-after["\'][^>]*>#i',
);

foreach ($patterns as $label => $pattern) {
    if (!preg_match($pattern, $optimized, $match)) {
        $failures[] = 'Missing expected script tag: ' . $label;
        continue;
    }

    $tag = (string) $match[0];
    if (false !== stripos($tag, 'text/ucwp-delayed-js') || false !== stripos($tag, 'data-ucwp-src=')) {
        $failures[] = 'Script tag was delayed/transformed: ' . $label;
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL (fixture HTML)\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}

if ('' !== $url) {
    if (!function_exists('wp_remote_get')) {
        $wp_root = getenv('WP_ROOT');
        if (!is_string($wp_root) || '' === trim($wp_root)) {
            $wp_root = dirname(__DIR__, 4);
        }
        if (!file_exists($wp_root . '/wp-load.php')) {
            fwrite(STDERR, "Skip live URL check: wp-load.php not found. Set WP_ROOT.\n");
            exit(0);
        }
        require_once $wp_root . '/wp-load.php';
    }

    $response = wp_remote_get($url, array(
        'timeout'     => 30,
        'redirection' => 3,
        'headers'     => array(
            'Cache-Control' => 'no-cache',
            'Pragma'        => 'no-cache',
        ),
    ));

    if (is_wp_error($response)) {
        fwrite(STDERR, 'Live URL request failed: ' . $response->get_error_message() . PHP_EOL);
        exit(1);
    }

    $live_html = (string) wp_remote_retrieve_body($response);
    if (false === stripos($live_html, $needle)) {
        fwrite(STDOUT, "PASS fixture; SKIP live URL (needle not present in HTML).\n");
        exit(0);
    }

    $live_optimized = (string) $delay_non_critical->invoke($engine, $live_html, $settings);
    $live_optimized = (string) $delay_third_party->invoke($engine, $live_optimized, $settings);

    if (preg_match('#text/ucwp-delayed-js[^>]*id=["\']' . preg_quote($needle, '#') . '-js-before#i', $live_optimized)) {
        fwrite(STDERR, "FAIL live URL: js-before was delayed despite unified exclusion.\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: unified JS exclusion protects Site Kit WooCommerce script group.\n");
exit(0);
