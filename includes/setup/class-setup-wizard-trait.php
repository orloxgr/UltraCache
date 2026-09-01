<?php
/**
 * Fresh-install setup wizard state.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Setup_Wizard_Trait
{
    private static function get_setup_wizard_option_key()
    {
        return 'ultracache_setup_wizard_state_v1';
    }

    private static function get_setup_wizard_default_state()
    {
        return array(
            'status'          => 'not_required',
            'current_step'    => '',
            'completed_steps' => array(),
            'last_error'      => '',
            'last_error_phase' => '',
            'updated_at'      => '',
        );
    }

    public static function initialize_setup_wizard_for_fresh_install()
    {
        $key = self::get_setup_wizard_option_key();
        if (false !== get_option($key, false)) {
            return self::get_setup_wizard_state();
        }

        $state = array(
            'status'          => 'pending',
            'current_step'    => 'welcome',
            'completed_steps' => array(),
            'last_error'      => '',
            'last_error_phase' => '',
            'updated_at'      => current_time('mysql', true),
        );

        add_option('ultracache_setup_wizard_state_v1', $state, '', false);
        return self::get_setup_wizard_state();
    }

    public static function get_setup_wizard_state()
    {
        $stored = get_option(self::get_setup_wizard_option_key(), false);
        if (!is_array($stored)) {
            return self::get_setup_wizard_default_state();
        }

        $allowed_statuses = array('pending', 'in_progress', 'completed');
        $allowed_steps = array('welcome', 'analyze', 'configure', 'prepare', 'javascript', 'verify', 'done');

        $status = isset($stored['status']) ? sanitize_key((string) $stored['status']) : 'pending';
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'pending';
        }

        $current_step = isset($stored['current_step']) ? sanitize_key((string) $stored['current_step']) : 'welcome';
        if (!in_array($current_step, $allowed_steps, true)) {
            $current_step = 'welcome';
        }

        $completed = array();
        foreach ((array) ($stored['completed_steps'] ?? array()) as $step) {
            $step = sanitize_key((string) $step);
            if (in_array($step, $allowed_steps, true) && !in_array($step, $completed, true)) {
                $completed[] = $step;
            }
        }

        return array(
            'status'          => $status,
            'current_step'    => $current_step,
            'completed_steps' => $completed,
            'last_error'      => sanitize_text_field((string) ($stored['last_error'] ?? '')),
            'last_error_phase' => sanitize_key((string) ($stored['last_error_phase'] ?? '')),
            'updated_at'      => sanitize_text_field((string) ($stored['updated_at'] ?? '')),
        );
    }

    public static function update_setup_wizard_state($action, array $payload = array())
    {
        $current = self::get_setup_wizard_state();
        $action = sanitize_key((string) $action);
        if ('not_required' === $current['status'] && 'start' !== $action) {
            return $current;
        }

        $state = $current;

        if ('start' === $action) {
            $state['status'] = 'in_progress';
            $state['current_step'] = 'analyze';
            $state['completed_steps'] = array();
            $state['last_error'] = '';
            $state['last_error_phase'] = '';
        } elseif ('progress' === $action) {
            $allowed_steps = array('analyze', 'configure', 'prepare', 'javascript', 'verify');
            $requested_step = sanitize_key((string) ($payload['current_step'] ?? ''));
            if (in_array($requested_step, $allowed_steps, true)) {
                $state['status'] = 'in_progress';
                $state['current_step'] = $requested_step;
            }

            if (isset($payload['completed_steps']) && is_array($payload['completed_steps'])) {
                $completed = array();
                foreach ($payload['completed_steps'] as $step) {
                    $step = sanitize_key((string) $step);
                    if (in_array($step, array('analyze', 'configure', 'prepare', 'javascript'), true) && !in_array($step, $completed, true)) {
                        $completed[] = $step;
                    }
                }
                $state['completed_steps'] = $completed;
            }
            if (array_key_exists('last_error', $payload)) {
                $state['last_error'] = sanitize_text_field((string) $payload['last_error']);
                if ('' === $state['last_error']) {
                    $state['last_error_phase'] = '';
                }
            }
            if (array_key_exists('last_error_phase', $payload)) {
                $state['last_error_phase'] = sanitize_key((string) $payload['last_error_phase']);
            }
        } elseif ('fail' === $action) {
            $state['status'] = 'in_progress';
            $requested_step = sanitize_key((string) ($payload['current_step'] ?? 'configure'));
            if ('javascript' === $requested_step) {
                $requested_step = 'verify';
            }
            $state['current_step'] = in_array($requested_step, array('configure', 'prepare', 'verify'), true) ? $requested_step : 'configure';
            $state['last_error'] = sanitize_text_field((string) ($payload['last_error'] ?? ''));
            $state['last_error_phase'] = sanitize_key((string) ($payload['last_error_phase'] ?? ''));
        } elseif ('complete' === $action) {
            $state['status'] = 'completed';
            $state['current_step'] = 'done';
            $state['completed_steps'] = array('analyze', 'configure', 'prepare', 'javascript', 'verify');
            $state['last_error'] = '';
            $state['last_error_phase'] = '';
        } else {
            return new WP_Error('ultracache_setup_wizard_action_invalid', 'Invalid setup wizard action.');
        }

        $state['updated_at'] = current_time('mysql', true);
        update_option(self::get_setup_wizard_option_key(), $state, false);
        return self::get_setup_wizard_state();
    }
}
