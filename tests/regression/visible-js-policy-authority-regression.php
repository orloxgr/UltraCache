<?php
/**
 * UltraCache 3.12.08 visible JavaScript policy authority regression.
 *
 * Run:
 *   php tests/regression/visible-js-policy-authority-regression.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/settings/class-settings-registration-trait.php';
require_once $root . '/includes/settings/class-settings-validation-trait.php';

final class UltraCacheVisiblePolicySettingsHarness
{
    use Ultra_Cache_WP_Settings_Registration_Trait;
    use Ultra_Cache_WP_Settings_Validation_Trait;

    public static function safeDefaults(): array
    {
        return self::get_default_safe_third_party_delay_patterns();
    }

    public static function functionalDefaults(): array
    {
        return self::get_default_functional_third_party_delay_patterns();
    }

    public static function nativeDefaults(): array
    {
        return self::get_default_js_delay_defer_exclusion_patterns();
    }
}

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

$safe = UltraCacheVisiblePolicySettingsHarness::safeDefaults();
$functional = UltraCacheVisiblePolicySettingsHarness::functionalDefaults();
$expect(in_array('gtag(', $safe, true), 'visible safe third-party defaults restore gtag( matching');
$expect(in_array('dataLayer', $safe, true), 'visible safe third-party defaults restore dataLayer matching');
$expect(!in_array('complianz', $functional, true), 'functional third-party defaults do not blanket-match Complianz-released service scripts');
$expect(!in_array('cmplz', $functional, true), 'functional third-party defaults do not blanket-match cmplz metadata on released service scripts');
$expect(in_array('cookieyes', $functional, true), 'visible functional third-party defaults restore CookieYes matching');
$expect(in_array('cky-', $functional, true), 'visible functional third-party defaults restore cky- matching');

$nativeDefaults = UltraCacheVisiblePolicySettingsHarness::nativeDefaults();
$expect(!in_array('contact-form-7-js', $nativeDefaults, true), 'CF7 is not a forced visible native default');
$expect(!in_array('author-arc-handler-js', $nativeDefaults, true), 'author-arc is not a forced visible native default');
$expect(count($nativeDefaults) <= 1, 'Do Not Defer or Delay canonical defaults contain no injected vendor fallbacks');

$router = file_get_contents($root . '/includes/engine/js/class-js-router-trait.php');
$policy = file_get_contents($root . '/includes/engine/js/class-js-policy-trait.php');
$html = file_get_contents($root . '/includes/engine/js/class-js-html-rewrite-trait.php');
$exclusions = file_get_contents($root . '/includes/engine/js/class-js-exclusions-trait.php');

$expect(is_string($router) && false !== strpos($router, 'ultracache_evaluate_unified_js_execution_policy'), 'registered-script router delegates visible policy resolution to the unified policy evaluator');
$nativePos = is_string($policy) ? strpos($policy, "'id' => 'visible-native'") : false;
$deferPos = is_string($policy) ? strpos($policy, "'id' => 'visible-defer'") : false;
$expect(false !== $nativePos && false !== $deferPos && $nativePos < $deferPos, 'unified policy checks NATIVE visible rule before DEFER override');

$restoreStart = is_string($html) ? strpos($html, 'private function restore_user_excluded_delayed_scripts_in_html') : false;
$restoreEnd = false !== $restoreStart ? strpos($html, 'private function restore_delayed_script_record_tag', $restoreStart) : false;
$restoreBody = (false !== $restoreStart && false !== $restoreEnd) ? substr($html, $restoreStart, $restoreEnd - $restoreStart) : '';
$expect(false !== strpos($restoreBody, '$user_native') && false !== strpos($restoreBody, '$user_force_defer = !$user_native'), 'ordered HTML restoration prevents DEFER override when NATIVE visible policy matched');
$expect(is_string($exclusions) && false !== strpos($exclusions, '$native_groups = $this->get_user_excluded_script_dependency_groups') && false !== strpos($exclusions, "empty(\$native_groups[\$group])"), 'dependency-group DEFER propagation cannot override a NATIVE visible group');

$validation = file_get_contents($root . '/includes/settings/class-settings-validation-trait.php');
$expect(is_string($validation) && false === strpos($validation, 'migrate_unsafe_legacy_js_delay_default_patterns'), 'obsolete 3.09.04 hidden-consent migration is removed');
$expect(is_string($validation) && false === strpos($validation, 'migrate_js_visible_policy_defaults_31208'), '3.12.08 JavaScript visible-list migration is removed');
$expect(is_string($validation) && false === strpos($validation, 'migrate_js_visible_vendor_fallbacks_31209'), '3.12.09 JavaScript vendor-list migration is removed');

$contract = require $root . '/tests/architecture/js-policy-contract.php';
$expect(array('native_list', 'defer_list', 'default_lane') === ($contract['visible_policy']['precedence'] ?? array()), 'contract freezes visible policy precedence');
$expect(true === ($contract['visible_policy']['native_wins_on_overlap'] ?? false), 'contract freezes NATIVE as overlap winner');
$expect(false === ($contract['visible_policy']['post_list_hidden_lane_override'] ?? true), 'contract forbids post-list hidden lane override');

$autoReleaseSource = file_get_contents($root . '/includes/settings/class-settings-registration-trait.php');
$expect(is_string($autoReleaseSource) && false !== strpos($autoReleaseSource, "'delayedLocalJsAutoStartSeconds' => 0.05"), 'existing Auto Release default remains untouched');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
