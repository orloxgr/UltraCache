<?php
/**
 * UltraCache 3.12.07 no-hidden-consent-policy regression.
 *
 * Consent/CMP identity must not create a private execution lane. The normal
 * router, visible lists, HTML semantics and explicit author opt-outs remain the
 * only generic scheduling authorities.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-runtime-registry-trait.php';
require_once $root . '/includes/engine/js/class-js-policy-trait.php';
require_once $root . '/includes/engine/js/class-js-router-trait.php';

final class UltraCacheConsentPolicyResetHarness
{
    use Ultra_Cache_Engine_JS_Runtime_Registry_Trait;
    use Ultra_Cache_Engine_JS_Policy_Trait;
    use Ultra_Cache_Engine_JS_Router_Trait;

    public array $stub = array();

    public function __call($name, $arguments)
    {
        if (array_key_exists($name, $this->stub)) {
            $value = $this->stub[$name];
            return is_callable($value) ? $value(...$arguments) : $value;
        }
        if ('is_delayable_external_script_tag' === $name) { return true; }
        if ('get_defer_stage_level' === $name) { return 2; }
        if (in_array($name, array('get_safe_third_party_delay_patterns', 'get_functional_third_party_delay_patterns', 'get_non_critical_delay_patterns'), true)) { return array(); }
        return false;
    }

    public function route(string $handle, string $src, string $tag, array $settings): array
    {
        return $this->ultracache_build_registered_script_route($tag, $handle, $src, $settings);
    }
}

$passes = 0;
$failures = array();
$expect = static function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) { $passes++; echo '[PASS] ' . $label . PHP_EOL; return; }
    $failures[] = $label; echo '[FAIL] ' . $label . PHP_EOL;
};

$engine_files = array(
    'includes/engine/js/class-js-router-trait.php',
    'includes/engine/js/class-js-delay-trait.php',
    'includes/engine/js/class-js-defer-trait.php',
    'includes/engine/js/class-js-html-rewrite-trait.php',
);
$engine = '';
foreach ($engine_files as $file) { $engine .= (string) file_get_contents($root . '/' . $file) . "\n"; }

foreach (array(
    'should_protect_consent_control_plane_script',
    'should_protect_consent_lifecycle_script',
    'should_native_defer_consent_control_plane_script',
    'is_consent_control_plane_optimization_active',
) as $removed) {
    $expect(false === strpos($engine, $removed), 'removed hidden consent scheduling helper: ' . $removed);
}

$h = new UltraCacheConsentPolicyResetHarness();
$route = $h->route('complianz-cookiebanner', '/wp-content/plugins/complianz-gdpr/cookiebanner/js/complianz.min.js', '<script src="/wp-content/plugins/complianz-gdpr/cookiebanner/js/complianz.min.js"></script>', array('defer_js' => true));
$expect('defer' === ($route['lane'] ?? '') && 'default-defer-strategy' === ($route['reason'] ?? ''), 'Complianz identity alone follows the normal DEFER strategy');

$h = new UltraCacheConsentPolicyResetHarness();
$route = $h->route('googlesitekit-consent-mode', '/wp-content/plugins/google-site-kit/dist/assets/js/googlesitekit-consent-mode.js', '<script src="/wp-content/plugins/google-site-kit/dist/assets/js/googlesitekit-consent-mode.js"></script>', array('defer_js' => true));
$expect('defer' === ($route['lane'] ?? ''), 'Site Kit consent identity alone does not force NATIVE');

$h = new UltraCacheConsentPolicyResetHarness();
$h->stub['get_safe_third_party_delay_patterns'] = array('cookiebot');
$h->stub['get_matching_third_party_delay_pattern'] = 'cookiebot';
$route = $h->route('cookiebot', 'https://consent.cookiebot.com/uc.js', '<script src="https://consent.cookiebot.com/uc.js"></script>', array('defer_js' => true, 'defer_stage_balanced' => true, 'delay_safe_third_party_js' => true));
$expect('delay' === ($route['lane'] ?? '') && 'safe-third-party' === ($route['reason'] ?? ''), 'Cookiebot can route to DELAY through the normal visible third-party pattern policy');

$h = new UltraCacheConsentPolicyResetHarness();
$h->stub['is_js_excluded_by_user_patterns'] = true;
$route = $h->route('onetrust-consent-sdk', 'https://cdn.cookielaw.org/scripttemplates/otSDKStub.js', '<script src="https://cdn.cookielaw.org/scripttemplates/otSDKStub.js"></script>', array('defer_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'visible-do-not-defer-or-delay' === ($route['reason'] ?? ''), 'visible Do Not Defer or Delay remains authoritative for consent assets');

$h = new UltraCacheConsentPolicyResetHarness();
$h->stub['is_script_tag_optimizer_opted_out'] = true;
$route = $h->route('iubenda-cs', 'https://cdn.iubenda.com/cs/iubenda_cs.js', '<script data-no-defer src="https://cdn.iubenda.com/cs/iubenda_cs.js"></script>', array('defer_js' => true));
$expect('native' === ($route['lane'] ?? '') && 'explicit-author-optimizer-opt-out' === ($route['reason'] ?? ''), 'explicit author opt-out remains a valid NATIVE contract');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if ($failures) { exit(1); }
