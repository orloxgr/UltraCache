<?php
/**
 * UltraCache 3.13.01 Real Cookie Banner compatibility regression.
 *
 * Run:
 *   php tests/regression/real-cookie-banner-compatibility-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/engine/js/class-js-real-cookie-banner-compatibility-trait.php';
require_once $root . '/includes/integrations/real-cookie-banner/class-real-cookie-banner-compatibility-trait.php';
require_once $root . '/includes/setup/class-setup-planning-trait.php';

if (!defined('ULTRACACHE_SETTINGS_KEY')) {
    define('ULTRACACHE_SETTINGS_KEY', 'ultracache_settings');
}
$GLOBALS['ultracache_rcb_test_purge_count'] = 0;
$GLOBALS['ultracache_rcb_test_active_plugins'] = array('real-cookie-banner-pro/index.php');
if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        if (ULTRACACHE_SETTINGS_KEY === $key) {
            return array('realCookieBannerCompatibilityEnabled' => true);
        }
        if ('active_plugins' === $key) {
            return $GLOBALS['ultracache_rcb_test_active_plugins'];
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
            $GLOBALS['ultracache_rcb_test_purge_count']++;
            $GLOBALS['ultracache_rcb_test_last_purge_context'] = $context;
            return true;
        }
    }
}

final class UltraCacheRcbLifecycleHarness
{
    use Ultra_Cache_WP_Real_Cookie_Banner_Compatibility_Trait;
}

final class UltraCacheRcbSetupHarness
{
    use Ultra_Cache_WP_Setup_Planning_Trait;
}

final class UltraCacheRcbMatcherHarness
{
    use Ultra_Cache_Engine_JS_Real_Cookie_Banner_Compatibility_Trait;

    public function matches(string $handle, string $src = '', string $tag = ''): bool
    {
        return $this->ultracache_is_real_cookie_banner_infrastructure_script($handle, $src, $tag);
    }

    public function patterns(): array
    {
        return $this->ultracache_real_cookie_banner_runtime_infrastructure_patterns();
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
    'integration' => file_get_contents($root . '/includes/integrations/real-cookie-banner/class-real-cookie-banner-compatibility-trait.php'),
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

$matcher = new UltraCacheRcbMatcherHarness();
foreach (array(
    'real-cookie-banner-pro-banner',
    'real-cookie-banner-pro-banner_tcf',
    'real-cookie-banner-pro-blocker',
    'real-cookie-banner-pro-blocker_tcf',
    'real-cookie-banner-pro-vendor-real-cookie-banner-pro-banner',
    'real-cookie-banner-pro-vendor-real-cookie-banner-pro-blocker_tcf',
    'real-cookie-banner-banner',
    'real-cookie-banner-blocker',
    'iabtcf-stub',
) as $handle) {
    $expect($matcher->matches($handle), 'RCB infrastructure handle is recognized: ' . $handle);
}

$expect($matcher->matches('', '', '<script data-webpack="realCookieBanner_:chunk-4"></script>'), 'RCB webpack chunk provenance is recognized');
$expect($matcher->matches('', 'https://example.test/wp-content/plugins/real-cookie-banner-pro/public/dist/banner.pro.js'), 'RCB Pro banner asset path is recognized');
$expect($matcher->matches('', 'https://example.test/wp-content/plugins/real-cookie-banner/public/dist/blocker.lite.js'), 'RCB Lite blocker asset path is recognized');

foreach (array(
    array('mailchimp-woocommerce', 'https://example.test/wp-content/plugins/mailchimp-for-woocommerce/public/js/mailchimp-woocommerce-public.min.js', ''),
    array('facebook-for-woocommerce', 'https://connect.facebook.net/en_US/fbevents.js', ''),
    array('google-analytics', 'https://www.googletagmanager.com/gtag/js?id=G-TEST', ''),
    array('service-script', 'https://cdn.example.test/service.js', '<script type="application/javascript" consent-by="services" consent-required="123"></script>'),
) as $candidate) {
    $expect(!$matcher->matches($candidate[0], $candidate[1], $candidate[2]), 'consent-released/service script is not blanket-exempt: ' . $candidate[0]);
}

$patterns = implode("\n", $matcher->patterns());
$expect(false === stripos($patterns, 'consent-required') && false === stripos($patterns, 'consent-by') && false === stripos($patterns, 'application/consent'), 'RCB infrastructure matcher does not use generic consent placeholder attributes');

$expect(false !== strpos($files['registration'], "'realCookieBannerCompatibilityEnabled' => false"), 'compatibility switch defaults OFF');
$expect(false !== strpos($files['validation'], "realCookieBannerCompatibilityEnabled'] = !empty"), 'compatibility switch is boolean-normalized');
$expect(false !== strpos($files['rendering'], "'real_cookie_banner_compatibility'"), 'compatibility switch maps into runtime settings');
$expect(false !== strpos($files['rest'], "'realCookieBannerCompatibilityEnabled'"), 'compatibility switch is writable through settings REST schema');
$advancedSettingsPos = strpos($files['admin'], 'title: __("Advanced settings"');
$rcbTogglePos = strpos($files['admin'], 'label: __("Real Cookie Banner Compatibility"');
$expect(false !== $rcbTogglePos && false !== $advancedSettingsPos && $rcbTogglePos > $advancedSettingsPos, 'compatibility switch is visible under Advanced settings');
$expect(1 === substr_count($files['admin'], 'label: __("Real Cookie Banner Compatibility"'), 'compatibility switch has one manual UI location');

$setupMethod = new ReflectionMethod(UltraCacheRcbSetupHarness::class, 'get_setup_integration_capability');
$setupMethod->setAccessible(true);
$GLOBALS['ultracache_rcb_test_active_plugins'] = array('real-cookie-banner-pro/index.php');
$proDetection = $setupMethod->invoke(null);
$expect(!empty($proDetection['realCookieBanner']['active']), 'Setup Wizard detects active Real Cookie Banner Pro');
$GLOBALS['ultracache_rcb_test_active_plugins'] = array('real-cookie-banner/index.php');
$liteDetection = $setupMethod->invoke(null);
$expect(!empty($liteDetection['realCookieBanner']['active']), 'Setup Wizard detects active Real Cookie Banner Lite');
$GLOBALS['ultracache_rcb_test_active_plugins'] = array('akismet/akismet.php');
$noDetection = $setupMethod->invoke(null);
$expect(empty($noDetection['realCookieBanner']['active']), 'Setup Wizard does not detect unrelated plugins as Real Cookie Banner');
$expect(false !== strpos($files['setup'], "'realCookieBannerCompatibility' => !empty(\$integrations['realCookieBanner']['active'])"), 'Setup plan recommends RCB compatibility only on positive detection');
$expect(false !== strpos($files['settingsActions'], 'if (planIntegrationRecommendations.realCookieBannerCompatibility)') && false !== strpos($files['settingsActions'], 'mainPatch.realCookieBannerCompatibilityEnabled = true;'), 'Setup Wizard silently enables the existing compatibility switch when recommended');
$expect(false === strpos($files['settingsActions'], 'mainPatch.realCookieBannerCompatibilityEnabled = false;'), 'Setup Wizard never forces RCB compatibility OFF when no banner is detected');

$visibleNativePos = strpos($files['policy'], "'id' => 'visible-native'");
$visibleDeferPos = strpos($files['policy'], "'id' => 'visible-defer'");
$integrationPos = strpos($files['policy'], "'id' => 'explicit-integration'");
$expect(false !== $visibleNativePos && false !== $visibleDeferPos && false !== $integrationPos && $visibleNativePos < $visibleDeferPos && $visibleDeferPos < $integrationPos, 'visible NATIVE and DEFER lists remain above explicit integration policy');
$expect(false !== strpos($files['router'], "'reason' => 'explicit-real-cookie-banner-compatibility'") && false !== strpos($files['router'], "'action' => 'unchanged'"), 'registered RCB infrastructure preserves its emitted lifecycle');
$expect(false !== strpos($files['loader'], 'dynamicRealCookieBannerInfrastructurePatterns') && false !== strpos($files['loader'], 'explicit-real-cookie-banner-compatibility'), 'post-bootstrap dynamic RCB infrastructure uses the unified runtime classifier');
$expect(false !== strpos($files['finder'], 'realCookieBannerInfrastructurePatterns') && false !== strpos($files['finder'], 'explicit-real-cookie-banner-compatibility'), 'parser-early finder can pass RCB infrastructure without pending hold');
$expect(false !== strpos($files['delay'], "'realCookieBannerInfrastructurePatterns'"), 'parser-early finder receives explicit RCB infrastructure provenance from canonical policy');

$expect(false !== strpos($files['admin'], "indexOf('real_cookie_banner') === 0"), 'Runtime Scan reset is restricted to real_cookie_banner* state names');
$expect(false !== strpos($files['admin'], 'frameWindow.localStorage.removeItem') && false !== strpos($files['admin'], 'frameWindow.sessionStorage.removeItem'), 'Runtime Scan clears RCB localStorage and sessionStorage in the credentialless partition');
$expect(false === strpos($files['admin'], "failureReason: 'real-cookie-banner-state-reset-failed'"), 'unverified RCB scanner-state reset no longer aborts the measurement');
$expect(false !== strpos($files['admin'], "Continuing Runtime Scan.") && false !== strpos($files['admin'], "integration: 'real-cookie-banner'"), 'RCB reset failure is retained as a non-blocking diagnostic warning');
$expect(false !== strpos($files['admin'], "frame.setAttribute('credentialless', '')"), 'RCB scanner reset uses the same isolated credentialless partition model');
$expect(false !== strpos($files['admin'], "parsed.searchParams.set('action', 'ultracache_rcb_scanner_reset_frame')"), 'Runtime Scan reset uses a dedicated minimal endpoint instead of the 400 proof probe');
$expect(false !== strpos($files['bootstrap'], "wp_ajax_nopriv_ultracache_rcb_scanner_reset_frame") && false !== strpos($files['integration'], 'Runtime Scan Storage Reset'), 'credentialless reset endpoint is available without frontend page execution');

$expect(false !== strpos($files['bootstrap'], "'RCB/Customize/Updated'") && false !== strpos($files['bootstrap'], "'RCB/Settings/Updated'") && false !== strpos($files['bootstrap'], "'RCB/Revision/Hash'"), 'RCB change events are wired to UltraCache compatibility lifecycle');
$expect(false !== strpos($files['integration'], "'reason' => 'real-cookie-banner-change'") && false !== strpos($files['integration'], "'source' => 'real-cookie-banner'"), 'RCB change invalidation uses explicit UltraCache purge context');
$expect(false !== strpos($files['integration'], 'static $purged = false'), 'multiple RCB change hooks purge UltraCache at most once per request');
$customizeValue = array('customize' => 'ok');
$settingsValue = array('settings' => 'ok');
$revisionValue = array('hash' => 'abc');
$expect(UltraCacheRcbLifecycleHarness::ultracache_handle_real_cookie_banner_customize_updated($customizeValue) === $customizeValue, 'RCB customize filter value is preserved');
$expect(UltraCacheRcbLifecycleHarness::ultracache_handle_real_cookie_banner_settings_updated($settingsValue, null) === $settingsValue, 'RCB settings filter value is preserved');
$expect(UltraCacheRcbLifecycleHarness::ultracache_handle_real_cookie_banner_revision_hash($revisionValue, 'abc') === $revisionValue, 'RCB revision filter value is preserved');
$expect(1 === (int) $GLOBALS['ultracache_rcb_test_purge_count'], 'multiple RCB lifecycle hooks trigger one UltraCache purge per request');
$expect('real-cookie-banner-change' === ($GLOBALS['ultracache_rcb_test_last_purge_context']['reason'] ?? '') && 'real-cookie-banner' === ($GLOBALS['ultracache_rcb_test_last_purge_context']['source'] ?? ''), 'RCB lifecycle purge carries explicit reason/source context');
$expect(false === stripos($files['integration'], 'vary') && false === stripos($files['integration'], 'cache variant'), 'compatibility layer adds no consent-cookie page-cache variant logic');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect('realCookieBannerCompatibilityEnabled' === ($contract['explicit_integrations']['real_cookie_banner'] ?? ''), 'architecture contract records RCB as an explicit integration switch');

$expect(false === strpos($files['registration'], "'contact-form-7-js'"), 'compatibility release does not reintroduce Contact Form 7 forced defaults');
$expect(false === strpos($files['registration'], "'author-arc-handler-js'"), 'compatibility release does not reintroduce author-arc forced defaults');

$total = $passes + count($failures);
echo PHP_EOL . 'Result: ' . $passes . '/' . $total . ' PASS' . PHP_EOL;
if ($failures) {
    exit(1);
}
