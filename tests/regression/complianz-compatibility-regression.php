<?php
/**
 * UltraCache 3.13.02 Complianz compatibility regression.
 *
 * Run:
 *   php tests/regression/complianz-compatibility-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-complianz-compatibility-trait.php';
require_once $root . '/includes/integrations/complianz/class-complianz-compatibility-trait.php';
require_once $root . '/includes/setup/class-setup-planning-trait.php';

if (!defined('ULTRACACHE_SETTINGS_KEY')) {
    define('ULTRACACHE_SETTINGS_KEY', 'ultracache_settings');
}
$GLOBALS['ultracache_cmplz_test_purge_count'] = 0;
$GLOBALS['ultracache_cmplz_test_active_plugins'] = array('complianz-gdpr-premium/complianz-gpdr-premium.php');
if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        if (ULTRACACHE_SETTINGS_KEY === $key) {
            return array('complianzCompatibilityEnabled' => true);
        }
        if ('active_plugins' === $key) {
            return $GLOBALS['ultracache_cmplz_test_active_plugins'];
        }
        return $default;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return false;
    }
}
if (!class_exists('Ultra_Cache_Engine')) {
    final class Ultra_Cache_Engine
    {
        public static function get_instance()
        {
            static $instance = null;
            if (null === $instance) {
                $instance = new self();
            }
            return $instance;
        }

        public function purge_all($context = array())
        {
            $GLOBALS['ultracache_cmplz_test_purge_count']++;
            $GLOBALS['ultracache_cmplz_test_last_purge_context'] = $context;
            return true;
        }
    }
}

final class UltraCacheComplianzLifecycleHarness
{
    use Ultra_Cache_WP_Complianz_Compatibility_Trait;
}

final class UltraCacheComplianzSetupHarness
{
    use Ultra_Cache_WP_Setup_Planning_Trait;
}

final class UltraCacheComplianzMatcherHarness
{
    use Ultra_Cache_Engine_JS_Complianz_Compatibility_Trait;

    public function matches(string $handle, string $src = '', string $tag = ''): bool
    {
        return $this->ultracache_is_complianz_infrastructure_script($handle, $src, $tag);
    }

    public function patterns(): array
    {
        return $this->ultracache_complianz_runtime_infrastructure_patterns();
    }
}

$files = array(
    'registration' => file_get_contents($root . '/includes/settings/class-settings-registration-trait.php'),
    'validation' => file_get_contents($root . '/includes/settings/class-settings-validation-trait.php'),
    'rendering' => file_get_contents($root . '/includes/settings/class-settings-rendering-trait.php'),
    'rest' => file_get_contents($root . '/includes/rest/class-rest-schemas-trait.php'),
    'admin' => file_get_contents($root . '/includes/admin/js/dashboard-application.js'),
    'router' => file_get_contents($root . '/includes/engine/js/class-js-router-trait.php'),
    'policy' => file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php'),
    'delay' => file_get_contents($root . '/includes/engine/js/class-js-delay-trait.php'),
    'loader' => file_get_contents($root . '/assets/js/delayed-js-loader.js'),
    'finder' => file_get_contents($root . '/assets/js/dynamic-script-finder-bootstrap.js'),
    'bootstrap' => file_get_contents($root . '/includes/bootstrap/class-bootstrap-trait.php'),
    'integration' => file_get_contents($root . '/includes/integrations/complianz/class-complianz-compatibility-trait.php'),
    'setup' => file_get_contents($root . '/includes/setup/class-setup-planning-trait.php'),
    'settingsActions' => file_get_contents($root . '/includes/admin/js/dashboard-settings-actions.js'),
);

$failures = array();
$passes = 0;
$expect = static function (bool $ok, string $label) use (&$failures, &$passes): void {
    if ($ok) {
        $passes++;
        echo '[PASS] ' . $label . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo '[FAIL] ' . $label . PHP_EOL;
};

$matcher = new UltraCacheComplianzMatcherHarness();
foreach (array('cmplz-cookiebanner', 'cmplz-tcf-stub', 'cmplz-tcf', 'cmplz-postscribe', 'cmplz-router') as $handle) {
    $expect($matcher->matches($handle), 'Complianz infrastructure handle is recognized: ' . $handle);
}
$expect($matcher->matches('', 'https://example.test/wp-content/plugins/complianz-gdpr-premium/cookiebanner/js/complianz.min.js'), 'Complianz Premium banner asset is recognized');
$expect($matcher->matches('', 'https://example.test/wp-content/plugins/complianz-gdpr/cookiebanner/js/complianz-router.min.js'), 'Complianz Lite router asset is recognized');
$expect($matcher->matches('', 'https://example.test/wp-content/plugins/complianz-gdpr-premium/pro/tcf-stub/build/index.js'), 'Complianz TCF stub is recognized');

foreach (array(
    array('google-analytics', 'https://www.googletagmanager.com/gtag/js?id=G-TEST', '<script data-category="statistics" data-cmplz-src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>'),
    array('facebook-pixel', 'https://connect.facebook.net/en_US/fbevents.js', '<script data-service="facebook" class="cmplz-activated"></script>'),
    array('youtube-api', 'https://www.youtube.com/iframe_api', '<script data-category="marketing"></script>'),
    array('generic-service', 'https://cdn.example.test/service.js', '<script type="text/plain" data-cmplz-src="https://cdn.example.test/service.js"></script>'),
) as $candidate) {
    $expect(!$matcher->matches($candidate[0], $candidate[1], $candidate[2]), 'consent-released/service script is not blanket-exempt: ' . $candidate[0]);
}
$patterns = implode("\n", $matcher->patterns());
$expect(false === stripos($patterns, 'data-cmplz-src') && false === stripos($patterns, 'data-category') && false === stripos($patterns, 'data-service') && false === stripos($patterns, 'cmplz-activated'), 'Complianz matcher does not use broad consent-release attributes/classes');

$expect(false !== strpos($files['registration'], "'complianzCompatibilityEnabled' => false"), 'Complianz compatibility defaults OFF');
$expect(false === strpos($files['registration'], "            'complianz',") && false === strpos($files['registration'], "            'cmplz',"), 'canonical functional defaults do not blanket-match Complianz control metadata');
$expect(false !== strpos($files['validation'], "complianzCompatibilityEnabled'] = !empty"), 'Complianz compatibility is boolean-normalized');
$expect(false !== strpos($files['rendering'], "'complianz_compatibility'"), 'Complianz compatibility maps into runtime settings');
$expect(false !== strpos($files['rest'], "'complianzCompatibilityEnabled'"), 'Complianz compatibility is writable through settings REST schema');
$advancedSettingsPos = strpos($files['admin'], 'title: __("Advanced settings"');
$togglePos = strpos($files['admin'], 'label: __("Complianz Compatibility"');
$expect(false !== $togglePos && false !== $advancedSettingsPos && $togglePos > $advancedSettingsPos, 'Complianz switch is visible under Advanced settings');
$expect(1 === substr_count($files['admin'], 'label: __("Complianz Compatibility"'), 'Complianz switch has one manual UI location');

$setupMethod = new ReflectionMethod(UltraCacheComplianzSetupHarness::class, 'get_setup_integration_capability');
$setupMethod->setAccessible(true);
$GLOBALS['ultracache_cmplz_test_active_plugins'] = array('complianz-gdpr-premium/complianz-gpdr-premium.php');
$premiumDetection = $setupMethod->invoke(null);
$expect(!empty($premiumDetection['complianz']['active']), 'Setup Wizard detects active Complianz Premium');
$GLOBALS['ultracache_cmplz_test_active_plugins'] = array('complianz-gdpr/complianz-gdpr.php');
$liteDetection = $setupMethod->invoke(null);
$expect(!empty($liteDetection['complianz']['active']), 'Setup Wizard detects active Complianz Lite');
$GLOBALS['ultracache_cmplz_test_active_plugins'] = array('akismet/akismet.php');
$noDetection = $setupMethod->invoke(null);
$expect(empty($noDetection['complianz']['active']), 'Setup Wizard does not detect unrelated plugins as Complianz');
$expect(false !== strpos($files['setup'], "'complianzCompatibility' => !empty(\$integrations['complianz']['active'])"), 'Setup plan recommends Complianz compatibility only on positive detection');
$expect(false !== strpos($files['settingsActions'], 'if (planIntegrationRecommendations.complianzCompatibility)') && false !== strpos($files['settingsActions'], 'mainPatch.complianzCompatibilityEnabled = true;'), 'Setup Wizard silently enables Complianz compatibility when detected');
$expect(false === strpos($files['settingsActions'], 'mainPatch.complianzCompatibilityEnabled = false;'), 'Setup Wizard never forces Complianz compatibility OFF when not detected');

$visibleNativePos = strpos($files['policy'], "'id' => 'visible-native'");
$visibleDeferPos = strpos($files['policy'], "'id' => 'visible-defer'");
$integrationPos = strpos($files['policy'], "'id' => 'explicit-integration'");
$expect(false !== $visibleNativePos && false !== $visibleDeferPos && false !== $integrationPos && $visibleNativePos < $visibleDeferPos && $visibleDeferPos < $integrationPos, 'visible NATIVE and DEFER lists remain above Complianz integration policy');
$expect(false !== strpos($files['router'], "'reason' => 'explicit-complianz-compatibility'"), 'registered Complianz infrastructure stays native');
$expect(false !== strpos($files['loader'], 'dynamicComplianzInfrastructurePatterns') && false !== strpos($files['loader'], 'explicit-complianz-compatibility'), 'runtime-created Complianz infrastructure uses unified classifier');
$expect(false !== strpos($files['finder'], 'complianzInfrastructurePatterns') && false !== strpos($files['finder'], 'explicit-complianz-compatibility'), 'parser-early finder receives narrow Complianz provenance');
$expect(false !== strpos($files['delay'], "'complianzInfrastructurePatterns'"), 'parser-early payload includes Complianz infrastructure patterns');

$expect(false !== strpos($files['admin'], "['cmplz_tcf_consent', 'cmplz_ac_string']"), 'Runtime Scan clears only Complianz-owned TCF localStorage keys');
$expect(false !== strpos($files['admin'], "['cmplz_user_data', 'cmplz_id', 'cmplz_cookie_data']"), 'Runtime Scan clears only Complianz-owned sessionStorage keys');
$expect(false !== strpos($files['admin'], "name.indexOf('cmplz_') === 0 || name === 'usprivacy'"), 'Runtime Scan cookie reset is restricted to Complianz cookies and usprivacy');
$expect(false === strpos($files['admin'], "failureReason: 'complianz-state-reset-failed'"), 'unverified Complianz scanner-state reset no longer aborts the measurement');
$expect(false !== strpos($files['admin'], "Continuing Runtime Scan.") && false !== strpos($files['admin'], "integration: 'complianz'"), 'Complianz reset failure is retained as a non-blocking diagnostic warning');
$expect(false !== strpos($files['admin'], "parsed.searchParams.set('action', 'ultracache_complianz_scanner_reset_frame')"), 'Complianz Runtime Scan reset uses a dedicated minimal endpoint');

$expect(false !== strpos($files['bootstrap'], "'update_option_cmplz_options'") && false !== strpos($files['bootstrap'], "'cmplz_after_css_generation'"), 'Complianz settings/banner lifecycle is wired to cache invalidation');
$expect(false !== strpos($files['integration'], "'banner-preview-'") && false !== strpos($files['integration'], "'reason' => 'complianz-change'"), 'preview CSS is ignored and real changes use explicit purge context');
UltraCacheComplianzLifecycleHarness::ultracache_handle_complianz_options_updated(array(), array(), 'cmplz_options');
UltraCacheComplianzLifecycleHarness::ultracache_handle_complianz_saved_fields(array('field' => 'value'));
UltraCacheComplianzLifecycleHarness::ultracache_handle_complianz_css_generation('/tmp/banner-1-optin.css');
$expect(1 === (int) $GLOBALS['ultracache_cmplz_test_purge_count'], 'multiple Complianz lifecycle hooks purge UltraCache at most once per request');
$expect('complianz-change' === ($GLOBALS['ultracache_cmplz_test_last_purge_context']['reason'] ?? '') && 'complianz' === ($GLOBALS['ultracache_cmplz_test_last_purge_context']['source'] ?? ''), 'Complianz purge carries explicit reason/source context');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('complianzCompatibilityEnabled' === ($contract['explicit_integrations']['complianz'] ?? ''), 'architecture contract records Complianz as explicit integration switch');
$expect(false !== strpos($files['admin'], 'Async Consent Banner CSS remains an independent setting.'), 'Complianz switch explicitly keeps Async Consent CSS independent');
$expect(false === strpos($files['settingsActions'], 'asyncConsentCssEnabled = true') && false === strpos($files['settingsActions'], 'asyncConsentCssAutoEnabled = true'), 'Complianz wizard recommendation does not force independent consent CSS switches');
$expect(false === strpos($files['registration'], "'contact-form-7-js'"), 'release does not reintroduce Contact Form 7 forced JS defaults');
$expect(false === strpos($files['registration'], "'author-arc-handler-js'"), 'release does not reintroduce author-arc forced JS defaults');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) {
    exit(1);
}
