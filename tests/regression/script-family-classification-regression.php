<?php
/**
 * UltraCache 3.13.05 scanner-first script-family regression.
 *
 * Inline companions must inherit the lane chosen by normal policy; merely
 * having before/after/data/translations companions must never promote the
 * external family from DELAY to DEFER.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);

require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/js/class-js-classification-trait.php';
require_once $root . '/includes/engine/js/class-js-policy-trait.php';
require_once $root . '/includes/engine/js/class-js-router-trait.php';

if (!function_exists('sanitize_key')) {
    function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://site.example' . (string) $path; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!class_exists('WP_Scripts')) {
    class WP_Scripts {
        public array $registered = array();
        public array $queue = array();
        public array $to_do = array();
        public array $done = array();
        public array $data = array();
        public function get_data($handle, $key) { return $this->data[$handle][$key] ?? false; }
    }
}
if (!function_exists('wp_scripts')) {
    function wp_scripts() { global $wp_scripts; return $wp_scripts; }
}

final class UltraCacheFamilyHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Classification_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;

    public array $stub = array();

    public function __call($name, $arguments)
    {
        if (array_key_exists($name, $this->stub)) {
            $value = $this->stub[$name];
            return is_callable($value) ? $value(...$arguments) : $value;
        }
        if ('is_delayable_external_script_tag' === $name) return true;
        if ('get_defer_stage_level' === $name) return 2;
        if (in_array($name, array('get_safe_third_party_delay_patterns', 'get_functional_third_party_delay_patterns', 'get_non_critical_delay_patterns'), true)) return array();
        if ('get_matching_third_party_delay_pattern' === $name) return '';
        return false;
    }

    public function route(array $settings, string $handle = 'family-handle', string $src = 'https://site.example/wp-content/plugins/example/app.js'): array
    {
        $tag = '<script id="' . $handle . '-js" src="' . $src . '"></script>';
        return $this->ultracache_build_registered_script_route($tag, $handle, $src, $settings);
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$policySource = (string) file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');
$routerSource = (string) file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$loaderSource = (string) file_get_contents($root . '/assets/js/delayed-js-loader.js');
$htmlRewriteSource = (string) file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');

$expect(false === strpos($policySource, "'id' => 'wp-script-family-coherence'"), 'A1: canonical policy has no automatic family-to-DEFER rule');
$expect(false === strpos($policySource, "'familyDeferHandles' =>"), 'A2: family handles are not exported as promotion metadata');
$expect(false === strpos($routerSource, "'familyDeferCandidate' =>"), 'A3: registered router supplies no family promotion fact');
$expect(false === strpos($loaderSource, 'dynamicFamilyDeferCandidate') && false === strpos($loaderSource, 'dynamicFamilyHandle'), 'A4: runtime classifier contains no family promotion path');
$expect(false !== strpos($htmlRewriteSource, 'ultracache_normalize_inline_companion_group_lanes_in_html'), 'A5: inline companion lane-coherence pass remains present');
$expect(false !== strpos($htmlRewriteSource, "if ('delay' === \$lane)") && false !== strpos($htmlRewriteSource, 'inline-companion-lane-coherence'), 'A6: lane normalizer can keep companions in DELAY');

$wp_scripts = new WP_Scripts();
$wp_scripts->registered['family-handle'] = (object) array('deps' => array(), 'src' => '/wp-content/plugins/example/app.js');
$wp_scripts->queue = array('family-handle');
$wp_scripts->data['family-handle']['after'] = array('window.familyConfig = true;');

$h = new UltraCacheFamilyHarness();
$h->stub['is_defer_all_js_candidate'] = true;
$route = $h->route(array('defer_js' => true, 'delay_all_js' => true));
$expect('delay' === ($route['lane'] ?? '') && 'delay-all-js' === ($route['reason'] ?? ''), 'B1: inline companion family remains DELAY under Delay All');

$h = new UltraCacheFamilyHarness();
$h->stub['get_safe_third_party_delay_patterns'] = array('explicit-delay');
$h->stub['get_matching_third_party_delay_pattern'] = 'explicit-delay';
$route = $h->route(array('defer_js' => true, 'defer_stage_balanced' => true, 'delay_safe_third_party_js' => true), 'family-handle', 'https://third.example/explicit-delay.js');
$expect('delay' === ($route['lane'] ?? '') && 'safe-third-party' === ($route['reason'] ?? ''), 'B2: inline companion family remains DELAY when a visible third-party Delay rule matches');

$h = new UltraCacheFamilyHarness();
$h->stub['is_js_excluded_by_user_patterns'] = true;
$route = $h->route(array('defer_js' => true, 'delay_all_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'visible-do-not-defer-or-delay' === ($route['reason'] ?? ''), 'B3: visible NATIVE list remains highest authority');

$h = new UltraCacheFamilyHarness();
$h->stub['is_script_user_force_deferred'] = true;
$route = $h->route(array('defer_js' => true, 'delay_all_js' => true));
$expect('defer' === ($route['lane'] ?? '') && 'visible-defer-instead-of-delay' === ($route['reason'] ?? ''), 'B4: visible DEFER list can explicitly promote the family');

$expect(false === stripos($policySource . $routerSource . $loaderSource, 'contact-form-7') && false === stripos($policySource . $routerSource . $loaderSource, 'mailchimp'), 'C1: generic policy contains no vendor hardcode');
$expect(false === strpos($policySource . $routerSource, 'MutationObserver') && false === strpos($policySource . $routerSource, 'scriptTimeoutMs'), 'C2: family policy adds no timer/order heuristic');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) exit(1);
