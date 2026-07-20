<?php
/**
 * UltraCache settings compatibility aggregator.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-settings-registration-trait.php';
require_once __DIR__ . '/class-settings-validation-trait.php';
require_once __DIR__ . '/class-settings-rendering-trait.php';
require_once __DIR__ . '/class-settings-migration-trait.php';

trait Ultra_Cache_WP_Settings_Trait
{
    use Ultra_Cache_WP_Settings_Registration_Trait;
    use Ultra_Cache_WP_Settings_Validation_Trait;
    use Ultra_Cache_WP_Settings_Rendering_Trait;
    use Ultra_Cache_WP_Settings_Migration_Trait;
}
