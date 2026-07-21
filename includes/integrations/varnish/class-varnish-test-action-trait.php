<?php
/**
 * Minimal Varnish test action for UltraCache.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_Test_Action_Trait
{
    /**
     * Return the non-autoload option used by the latest basic Varnish test.
     *
     * @return string
     */
    private static function get_varnish_basic_test_option_name()
    {
        return 'ultracache_varnish_diagnostic_basic_v1';
    }


    /**
     * Bound the compact basic-test payload before it is stored.
     *
     * @param mixed    $value  Current value.
     * @param int      $depth  Current recursion depth.
     * @param int|null $budget Remaining item budget.
     * @return mixed
     */
    private static function bound_varnish_basic_test_value($value, $depth = 0, &$budget = null)
    {
        if (null === $budget) {
            $budget = 160;
        }

        if ($depth > 5 || $budget <= 0) {
            return null;
        }

        $budget--;
        if (is_array($value)) {
            $bounded = array();
            $count = 0;
            foreach ($value as $key => $child) {
                if ($count >= 30 || $budget <= 0) {
                    break;
                }
                $bounded_key = is_int($key)
                    ? $key
                    : substr(self::sanitize_varnish_string((string) $key), 0, 160);
                $bounded[$bounded_key] = self::bound_varnish_basic_test_value($child, $depth + 1, $budget);
                $count++;
            }
            return $bounded;
        }

        if (is_string($value)) {
            return substr(self::sanitize_varnish_string($value), 0, 1000);
        }

        if (is_scalar($value) || null === $value) {
            return $value;
        }

        return null;
    }

    /**
     * Persist the compact latest basic Varnish test result.
     *
     * @param array $result Basic test result.
     * @return array
     */
    private static function store_varnish_basic_test_result(array $result)
    {
        $result['time'] = absint($result['time'] ?? time());
        if (method_exists(static::class, 'bind_varnish_capability_contracts')) {
            $result = self::bind_varnish_capability_contracts(
                $result,
                array('transport', 'html-invalidation', 'refill')
            );
        }

        $bounded = self::bound_varnish_basic_test_value($result);
        if (!is_array($bounded)) {
            $bounded = array();
        }

        update_option(self::get_varnish_basic_test_option_name(), $bounded, false);
        return $bounded;
    }

    /**
     * Read the latest basic Varnish test result.
     *
     * @return array
     */
    public static function get_varnish_basic_test_result()
    {
        $value = get_option(self::get_varnish_basic_test_option_name(), array());
        if (!is_array($value)) {
            return array();
        }

        if (!empty($value)
            && method_exists(static::class, 'varnish_capability_contracts_match')
            && !self::varnish_capability_contracts_match(
                $value,
                array('transport', 'html-invalidation', 'refill')
            )) {
            $value['success'] = false;
            $value['verified'] = false;
            $value['configurationChanged'] = true;
            $value['status'] = 'configuration-changed';
            $value['message'] = __('The Varnish configuration changed. Run Test Varnish again.', 'ultracache');
        }

        return $value;
    }

    /**
     * Run the compact connection, exact invalidation, and public refill test.
     *
     * @return array
     */
    public static function run_varnish_basic_test()
    {
        $runner_class = 'Ultra_Cache_WP_Varnish_Test_Runner';
        if (!class_exists($runner_class, false)) {
            require_once ultracache_plugin_dir('includes/integrations/varnish/class-varnish-test-runner.php');
        }

        if (!is_callable(array($runner_class, 'run'))) {
            return array(
                'success' => false,
                'status' => 'runner-unavailable',
                'message' => __('Varnish test runner is unavailable.', 'ultracache'),
                'time' => time(),
            );
        }

        $result = call_user_func(array($runner_class, 'run'));
        if (!is_array($result)) {
            $result = array(
                'success' => false,
                'status' => 'invalid-result',
                'message' => __('Varnish test returned an invalid result.', 'ultracache'),
                'time' => time(),
            );
        }

        return self::store_varnish_basic_test_result($result);
    }
}
