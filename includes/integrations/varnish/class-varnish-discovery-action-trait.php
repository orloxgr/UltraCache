<?php
/**
 * Varnish discovery action facade for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Discovery_Action_Trait
{
    /**
     * Persist one detected Varnish connection patch.
     *
     * @param array $configuration Detected Varnish settings patch.
     * @return true|WP_Error
     */
    private static function persist_verified_varnish_discovery_configuration(array $configuration)
    {
        $current = self::get_dashboard_settings();
        $next = self::sanitize_dashboard_settings(array_merge($current, $configuration));
        $validation = self::validate_varnish_settings($next);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $next['redisPassword'] = '';
        $next['varnishCliKey'] = '';
        $updated = update_option(ULTRACACHE_SETTINGS_KEY, $next);
        if (!$updated) {
            $stored = get_option(ULTRACACHE_SETTINGS_KEY, array());
            if (!is_array($stored)) {
                return new WP_Error(
                    'ultracache_varnish_discovery_save_failed',
                    __('The detected Varnish configuration could not be stored.', 'ultracache')
                );
            }

            foreach ($configuration as $key => $value) {
                if (($stored[$key] ?? null) !== ($next[$key] ?? null)) {
                    return new WP_Error(
                        'ultracache_varnish_discovery_save_failed',
                        __('The detected Varnish configuration could not be stored.', 'ultracache')
                    );
                }
            }
        }

        self::reset_settings_cache();
        return true;
    }

    /**
     * Run lightweight Varnish configuration discovery.
     *
     * @return array
     */
    public static function run_varnish_discovery()
    {
        $runner_class = 'Ultra_Cache_WP_Varnish_Discovery_Runner';
        if (!class_exists($runner_class, false)) {
            require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-discovery-runner.php');
        }

        if (!is_callable(array($runner_class, 'run'))) {
            return array(
                'success' => false,
                'verified' => false,
                'saved' => false,
                'status' => 'runner-unavailable',
                'message' => __('Varnish discovery runner is unavailable.', 'ultracache'),
            );
        }

        $result = call_user_func(array($runner_class, 'run'));
        if (!is_array($result)) {
            return array(
                'success' => false,
                'verified' => false,
                'saved' => false,
                'status' => 'invalid-result',
                'message' => __('Varnish discovery returned an invalid result.', 'ultracache'),
            );
        }

        $configuration = isset($result['configuration']) && is_array($result['configuration'])
            ? self::sanitize_varnish_result($result['configuration'])
            : array();

        if (!empty($result['verified']) && !empty($configuration)) {
            $save_result = self::persist_verified_varnish_discovery_configuration($configuration);
            if (is_wp_error($save_result)) {
                return array(
                    'success' => false,
                    'verified' => true,
                    'saved' => false,
                    'status' => 'save-failed',
                    'message' => self::sanitize_varnish_string($save_result->get_error_message()),
                    'configuration' => $configuration,
                );
            }

            return array(
                'success' => true,
                'verified' => true,
                'saved' => true,
                'status' => 'working',
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('Varnish was detected and configured automatically.', 'ultracache'))),
                'configuration' => $configuration,
            );
        }

        if (!empty($configuration)) {
            return array(
                'success' => !empty($result['success']),
                'verified' => false,
                'saved' => false,
                'status' => sanitize_key((string) ($result['status'] ?? 'candidate-found')),
                'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('A probable Varnish endpoint was detected.', 'ultracache'))),
                'configuration' => $configuration,
                'requiresToken' => !empty($result['requiresToken']),
            );
        }

        return array(
            'success' => false,
            'verified' => false,
            'saved' => false,
            'status' => sanitize_key((string) ($result['status'] ?? 'not-found')),
            'message' => self::sanitize_varnish_string((string) ($result['message'] ?? __('No working Varnish configuration was detected.', 'ultracache'))),
        );
    }
}
