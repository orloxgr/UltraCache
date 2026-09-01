<?php
/**
 * Unified JavaScript execution policy shared by registered and runtime scripts.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Policy_Trait
{
    /**
     * Build the canonical declarative JavaScript lane policy for this request.
     *
     * The ordered rule table is the policy authority consumed by both the
     * server-side registered-script router and the deferred browser classifier.
     * Environment-specific code may only provide facts (for example a WordPress
     * dependency guard or whether a runtime URL is third-party); it must not
     * maintain an independent lane-precedence decision tree.
     *
     * @param array $settings Effective UltraCache settings.
     * @return array<string,mixed>
     */
    private function ultracache_build_unified_js_execution_policy(array $settings = array())
    {
        $defer_stage = $this->get_defer_stage_level($settings);
        $defer_all_js = !empty($settings['defer_all_js']);

        return array(
            'version' => '3.13.10',
            'flags' => array(
                'delayClassificationEnabled' => 2 <= $defer_stage,
                'delaySafe' => !empty($settings['delay_safe_third_party_js']),
                'delayFunctional' => !empty($settings['delay_functional_third_party_js']),
                'delayAllThirdParty' => !empty($settings['delay_all_third_party_js']),
                'delayAllJs' => !empty($settings['delay_all_js']),
                'delayNonCritical' => !empty($settings['delay_non_critical_js']),
                'delayNonCriticalAggressive' => !empty($settings['delay_non_critical_js_aggressive']),
                'javascriptOptimizationDisabled' => 0 === $defer_stage || (empty($settings['defer_js']) && !$defer_all_js),
                'realCookieBannerCompatibility' => !empty($settings['real_cookie_banner_compatibility']),
                'complianzCompatibility' => !empty($settings['complianz_compatibility']),
            ),
            'patterns' => array(
                'native' => isset($settings['defer_js_exclude_list']) && is_array($settings['defer_js_exclude_list'])
                    ? array_values($settings['defer_js_exclude_list'])
                    : array(),
                'defer' => isset($settings['defer_js_force_list']) && is_array($settings['defer_js_force_list'])
                    ? array_values($settings['defer_js_force_list'])
                    : array(),
                'safe' => $this->get_safe_third_party_delay_patterns($settings),
                'functional' => $this->get_functional_third_party_delay_patterns($settings),
                'nonCritical' => $this->get_non_critical_delay_patterns(),
                'realCookieBannerInfrastructure' => $this->ultracache_real_cookie_banner_runtime_infrastructure_patterns(),
                'complianzInfrastructure' => $this->ultracache_complianz_runtime_infrastructure_patterns(),
            ),
            'rules' => array(
                array(
                    'id' => 'visible-native',
                    'kind' => 'pattern-fact',
                    'fact' => 'visibleNativePattern',
                    'lane' => 'native',
                    'reason' => 'visible-do-not-defer-or-delay',
                    'action' => 'native-strip-loading',
                    'interactionEligible' => true,
                ),
                array(
                    'id' => 'visible-defer',
                    'kind' => 'pattern-fact',
                    'fact' => 'visibleDeferPattern',
                    'lane' => 'defer',
                    'reason' => 'visible-defer-instead-of-delay',
                    'action' => 'defer-force-ordered',
                    'interactionEligible' => true,
                ),
                array(
                    'id' => 'explicit-integration',
                    'kind' => 'route-fact',
                    'fact' => 'explicitIntegrationRoute',
                ),
                array(
                    'id' => 'delay-all',
                    'kind' => 'flag-bool-fact',
                    'flag' => 'delayAllJs',
                    'fact' => 'delayAllCandidate',
                    'lane' => 'delay',
                    'reason' => 'delay-all-js',
                    'action' => 'delay-html-pass',
                    'interactionEligible' => 'same-origin-only',
                ),
                array(
                    'id' => 'safe-third-party',
                    'kind' => 'flag-pattern-fact',
                    'flag' => 'delaySafe',
                    'requiresFlag' => 'delayClassificationEnabled',
                    'fact' => 'safePattern',
                    'lane' => 'delay',
                    'reason' => 'safe-third-party',
                    'action' => 'delay-tag',
                    'interactionEligible' => false,
                ),
                array(
                    'id' => 'functional-third-party',
                    'kind' => 'flag-pattern-fact',
                    'flag' => 'delayFunctional',
                    'requiresFlag' => 'delayClassificationEnabled',
                    'fact' => 'functionalPattern',
                    'lane' => 'delay',
                    'reason' => 'functional-third-party',
                    'action' => 'delay-tag',
                    'interactionEligible' => true,
                ),
                array(
                    'id' => 'all-third-party',
                    'kind' => 'flag-bool-fact',
                    'flag' => 'delayAllThirdParty',
                    'requiresFlag' => 'delayClassificationEnabled',
                    'fact' => 'isThirdParty',
                    'lane' => 'delay',
                    'reason' => 'all-third-party',
                    'action' => 'delay-tag',
                    'matchedPattern' => 'third-party-origin',
                    'interactionEligible' => false,
                ),
                array(
                    'id' => 'non-critical-first-party-pattern',
                    'kind' => 'flag-pattern-fact',
                    'flag' => 'delayNonCritical',
                    'requiresFlag' => 'delayClassificationEnabled',
                    'fact' => 'nonCriticalPattern',
                    'lane' => 'delay',
                    'reason' => 'non-critical-first-party',
                    'action' => 'delay-tag',
                    'interactionEligible' => true,
                ),
                array(
                    'id' => 'non-critical-first-party-aggressive',
                    'kind' => 'flag-bool-fact',
                    'flag' => 'delayNonCriticalAggressive',
                    'requiresFlags' => array('delayClassificationEnabled', 'delayNonCritical'),
                    'fact' => 'aggressiveNonCriticalCandidate',
                    'lane' => 'delay',
                    'reason' => 'non-critical-first-party-aggressive',
                    'action' => 'delay-tag',
                    'matchedPattern' => 'same-origin-wp-content',
                    'interactionEligible' => true,
                ),
                array(
                    'id' => 'async-transport',
                    'kind' => 'route-fact',
                    'fact' => 'asyncRoute',
                ),
                array(
                    'id' => 'optimization-disabled',
                    'kind' => 'flag-route',
                    'flag' => 'javascriptOptimizationDisabled',
                    'route' => array(
                        'lane' => 'native',
                        'reason' => 'javascript-optimization-disabled',
                        'action' => 'unchanged',
                        'interactionEligible' => true,
                    ),
                ),
                array(
                    'id' => 'delay-all-fallback',
                    'kind' => 'flag-route',
                    'flag' => 'delayAllJs',
                    'route' => array(
                        'lane' => 'native',
                        'reason' => 'delay-all-protected-or-non-candidate',
                        'action' => 'unchanged',
                        'interactionEligible' => true,
                    ),
                ),
                array(
                    'id' => 'default-defer',
                    'kind' => 'default-route',
                    'route' => array(
                        'lane' => 'defer',
                        'reason' => 'default-defer-strategy',
                        'action' => 'defer-default',
                        'interactionEligible' => true,
                    ),
                ),
            ),
        );
    }

    /**
     * Return current-request WordPress handles that own executable inline
     * before/after/data/translations companions. This is classification metadata
     * only: it does not create dependencies, reorder scripts, or override visible
     * third-party Delay patterns. Runtime-created copies of these handles can use
     * the same family fact as registered scripts.
     *
     * @return array<int,string>
     */
    private function ultracache_get_current_wp_inline_companion_family_handles()
    {
        if (!function_exists('wp_scripts')) {
            return array();
        }

        $wp_scripts = wp_scripts();
        if (!is_object($wp_scripts) || empty($wp_scripts->registered) || !is_array($wp_scripts->registered)) {
            return array();
        }

        $candidates = array();
        foreach (array('queue', 'to_do', 'done') as $property) {
            if (!empty($wp_scripts->{$property}) && is_array($wp_scripts->{$property})) {
                foreach ($wp_scripts->{$property} as $handle) {
                    $handle = sanitize_key((string) $handle);
                    if ('' !== $handle) {
                        $candidates[$handle] = true;
                    }
                }
            }
        }

        $families = array();
        foreach (array_keys($candidates) as $handle) {
            if (empty($wp_scripts->registered[$handle]) || !is_object($wp_scripts->registered[$handle])) {
                continue;
            }
            if (method_exists($this, 'script_handle_has_wp_inline_companion_segments')
                && $this->script_handle_has_wp_inline_companion_segments($handle)) {
                $families[$handle] = true;
            }
        }

        $handles = array_keys($families);
        sort($handles, SORT_STRING);
        return $handles;
    }



    /**
     * Evaluate the canonical rule table from environment-specific facts.
     *
     * @param array<string,mixed> $policy Canonical policy snapshot.
     * @param array<string,mixed> $facts  Facts resolved by the current environment.
     * @return array<string,mixed>
     */
    private function ultracache_evaluate_unified_js_execution_policy(array $policy, array $facts = array())
    {
        $flags = isset($policy['flags']) && is_array($policy['flags']) ? $policy['flags'] : array();
        $rules = isset($policy['rules']) && is_array($policy['rules']) ? $policy['rules'] : array();

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $kind = isset($rule['kind']) ? (string) $rule['kind'] : '';
            $flag = isset($rule['flag']) ? (string) $rule['flag'] : '';
            $requires_flag = isset($rule['requiresFlag']) ? (string) $rule['requiresFlag'] : '';
            $requires_flags = isset($rule['requiresFlags']) && is_array($rule['requiresFlags']) ? $rule['requiresFlags'] : array();
            if ('' !== $flag && empty($flags[$flag])) {
                continue;
            }
            if ('' !== $requires_flag && empty($flags[$requires_flag])) {
                continue;
            }
            $missing_required_flag = false;
            foreach ($requires_flags as $required_flag) {
                $required_flag = (string) $required_flag;
                if ('' !== $required_flag && empty($flags[$required_flag])) {
                    $missing_required_flag = true;
                    break;
                }
            }
            if ($missing_required_flag) {
                continue;
            }

            if ('route-fact' === $kind) {
                $fact = isset($rule['fact']) ? (string) $rule['fact'] : '';
                $route = '' !== $fact && isset($facts[$fact]) && is_array($facts[$fact]) ? $facts[$fact] : array();
                if (!empty($route['lane'])) {
                    if (empty($route['rule_id']) && !empty($rule['id'])) {
                        $route['rule_id'] = (string) $rule['id'];
                    }
                    return $route;
                }
                continue;
            }

            if ('flag-route' === $kind || 'default-route' === $kind) {
                $route = isset($rule['route']) && is_array($rule['route']) ? $rule['route'] : array();
                if (!empty($route['lane'])) {
                    if (empty($route['rule_id']) && !empty($rule['id'])) {
                        $route['rule_id'] = (string) $rule['id'];
                    }
                    return $route;
                }
                continue;
            }

            $fact = isset($rule['fact']) ? (string) $rule['fact'] : '';
            $fact_value = '' !== $fact && array_key_exists($fact, $facts) ? $facts[$fact] : null;
            if ('pattern-fact' === $kind || 'flag-pattern-fact' === $kind) {
                if (!is_string($fact_value) || '' === trim($fact_value)) {
                    continue;
                }
            } elseif ('flag-bool-fact' === $kind) {
                if (!$fact_value) {
                    continue;
                }
            } else {
                continue;
            }

            $route = array(
                'lane' => isset($rule['lane']) ? (string) $rule['lane'] : 'native',
                'reason' => isset($rule['reason']) ? (string) $rule['reason'] : 'unified-policy',
                'action' => isset($rule['action']) ? (string) $rule['action'] : 'unchanged',
                'rule_id' => isset($rule['id']) ? (string) $rule['id'] : '',
            );

            if (is_string($fact_value) && '' !== trim($fact_value)) {
                $route['matched_pattern'] = trim($fact_value);
                $route['matchedPattern'] = trim($fact_value);
            } elseif (!empty($rule['matchedPattern'])) {
                $route['matched_pattern'] = (string) $rule['matchedPattern'];
                $route['matchedPattern'] = (string) $rule['matchedPattern'];
            }

            $interaction_eligible = $rule['interactionEligible'] ?? true;
            if ('same-origin-only' === $interaction_eligible) {
                $interaction_eligible = empty($facts['isThirdParty']);
            }
            $route['interactionEligible'] = (bool) $interaction_eligible;

            return $route;
        }

        return array(
            'lane' => 'native',
            'reason' => 'unified-policy-failsafe',
            'action' => 'unchanged',
            'interactionEligible' => true,
            'rule_id' => 'failsafe',
        );
    }
}
