<?php
/**
 * UltraCache 3.12.19 JavaScript settings no-migration regression.
 *
 * Saving visible JavaScript policy lists must preserve user authority.
 * Populate Defaults/fresh defaults may provide canonical defaults, but the
 * sanitizer must not reinsert 3.12.x compatibility entries.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);
require_once $root . '/includes/settings/class-settings-registration-trait.php';

final class UltraCacheNoJsMigrationDefaultsHarness
{
    use Ultra_Cache_WP_Settings_Registration_Trait;

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

$validation = file_get_contents($root . '/includes/settings/class-settings-validation-trait.php');
$registration = file_get_contents($root . '/includes/settings/class-settings-registration-trait.php');
$defaults = UltraCacheNoJsMigrationDefaultsHarness::nativeDefaults();

$expect(is_string($validation) && false === strpos($validation, 'migrate_js_visible_policy_defaults_31208'), '3.12.08 JavaScript migration is absent');
$expect(is_string($validation) && false === strpos($validation, 'migrate_js_visible_vendor_fallbacks_31209'), '3.12.09 JavaScript migration is absent');
$expect(is_string($validation) && false === strpos($validation, 'contact-form-7-js'), 'settings sanitizer never reinserts Contact Form 7');
$expect(is_string($validation) && false === strpos($validation, 'author-arc-handler-js'), 'settings sanitizer never reinserts author-arc');
$expect(is_string($registration) && false === strpos($registration, "'contact-form-7-js'"), 'canonical defaults do not force Contact Form 7');
$expect(is_string($registration) && false === strpos($registration, "'author-arc-handler-js'"), 'canonical defaults do not force author-arc');
$expect(!in_array('co', $defaults, true), 'canonical Do Not Defer or Delay defaults contain no stray co entry');
$expect(count($defaults) <= 1, 'canonical Do Not Defer or Delay defaults contain only the intended universal entry');

echo PHP_EOL . 'Result: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . PHP_EOL;
if (!empty($failures)) {
    exit(1);
}
