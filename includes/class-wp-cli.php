<?php
/**
 * WP-CLI integration for UltraCache.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/cli/class-wp-cli-helpers-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-cache-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-media-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-settings-stats-trait.php';
require_once __DIR__ . '/cli/class-wp-cli-integrations-trait.php';

if (!class_exists('UCWP_CLI_Command') && defined('WP_CLI') && WP_CLI && class_exists('WP_CLI_Command')) {
    class UCWP_CLI_Command extends WP_CLI_Command
    {
        use UCWP_CLI_Helpers_Trait;
        use UCWP_CLI_Cache_Trait;
        use UCWP_CLI_Media_Trait;
        use UCWP_CLI_Settings_Stats_Trait;
        use UCWP_CLI_Integrations_Trait;
    }
}

if (!class_exists('Ultra_Cache_WP_CLI')) {
    final class Ultra_Cache_WP_CLI
    {
        public static function register()
        {
            if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
                return;
            }

            if (!class_exists('UCWP_CLI_Command')) {
                return;
            }

            if (defined('UCWP_WP_CLI_REGISTERED')) {
                return;
            }

            define('UCWP_WP_CLI_REGISTERED', true);
            WP_CLI::add_command('ultracache', 'UCWP_CLI_Command');
        }
    }
}
