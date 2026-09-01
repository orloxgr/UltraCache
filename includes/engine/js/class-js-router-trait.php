<?php
/**
 * Central JavaScript execution-lane router.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Router_Trait
{
    /**
     * Build one explicit routing decision for a registered external script.
     *
     * Since 3.12.11 the registered-script router no longer owns an independent
     * generic NATIVE / DEFER / DELAY decision tree. It resolves WordPress-only
     * facts and feeds them into the same declarative policy table used by the
     * runtime-created script classifier in delayed-js-loader.js.
     *
     * @param string $tag      Rendered script tag.
     * @param string $handle   WordPress script handle.
     * @param string $src      Script source URL.
     * @param array  $settings Effective UltraCache settings.
     * @return array{lane:string,reason:string,action:string,delay_reason?:string}
     */
    private function ultracache_build_registered_script_route($tag, $handle, $src, array $settings = array())
    {
        $runtime_bundle = $this->ultracache_get_frontend_runtime_bundle($handle);
        if (!empty($runtime_bundle)) {
            return array(
                'lane' => isset($runtime_bundle['lane']) ? (string) $runtime_bundle['lane'] : 'native',
                'reason' => isset($runtime_bundle['reason']) ? (string) $runtime_bundle['reason'] : 'ultracache-runtime-bundle',
                'action' => isset($runtime_bundle['route_action']) ? (string) $runtime_bundle['route_action'] : 'runtime-bundle-native',
                'rule_id' => 'ultracache-runtime-bundle',
            );
        }

        $runtime_module = $this->ultracache_get_frontend_runtime_module($handle);
        if (!empty($runtime_module)) {
            return array(
                'lane' => isset($runtime_module['lane']) ? (string) $runtime_module['lane'] : 'native',
                'reason' => isset($runtime_module['reason']) ? (string) $runtime_module['reason'] : 'ultracache-runtime-module',
                'action' => isset($runtime_module['route_action']) ? (string) $runtime_module['route_action'] : 'native-strip-loading',
                'rule_id' => 'ultracache-runtime-module',
            );
        }

        if ($this->is_script_tag_optimizer_opted_out($tag)) {
            return array(
                'lane' => 'native',
                'reason' => 'explicit-author-optimizer-opt-out',
                'action' => 'unchanged',
                'rule_id' => 'explicit-author-opt-out',
            );
        }

        if (!$this->is_delayable_external_script_tag($tag)) {
            return array(
                'lane' => 'native',
                'reason' => 'html-js-semantics-non-delayable',
                'action' => 'unchanged',
                'rule_id' => 'html-js-semantics',
            );
        }

        $policy = $this->ultracache_build_unified_js_execution_policy($settings);
        $facts = $this->ultracache_build_registered_script_policy_facts($tag, $handle, $src, $settings, $policy);
        $route = $this->ultracache_evaluate_unified_js_execution_policy($policy, $facts);

        if ('delay-tag' === (string) ($route['action'] ?? '')) {
            $route['delay_reason'] = (string) ($route['reason'] ?? '');
        }

        return $route;
    }

    /**
     * Resolve WordPress/server facts without deciding rule precedence.
     *
     * The browser classifier resolves equivalent DOM/runtime facts and feeds
     * them into the same ordered policy rules. WordPress-only dependency and
     * explicit-integration safeguards remain facts because runtime-created
     * scripts do not have a registered dependency graph.
     *
     * @param string $tag      Rendered script tag.
     * @param string $handle   WordPress script handle.
     * @param string $src      Script source URL.
     * @param array  $settings Effective settings.
     * @param array  $policy   Canonical policy snapshot.
     * @return array<string,mixed>
     */
    private function ultracache_build_registered_script_policy_facts($tag, $handle, $src, array $settings, array $policy)
    {
        $flags = isset($policy['flags']) && is_array($policy['flags']) ? $policy['flags'] : array();
        $delay_all_js = !empty($flags['delayAllJs']);
        $delay_pipeline_active = $delay_all_js || !empty($settings['delay_non_critical_js']) || !empty($settings['lcp_boundary_defer']);

        $visible_native = $this->is_js_excluded_by_user_patterns($handle, $src, $tag, '', $settings);
        $visible_defer = $this->is_script_user_force_deferred($handle, $src, $tag, $settings);

        $elementor_protected = $this->should_protect_elementor_compatibility_script($handle, $src, $tag, $settings);
        $wpbakery_protected = $this->should_protect_wpbakery_animation_script($handle, $src, $tag, $settings);
        $woocommerce_protected = $this->should_protect_woocommerce_variable_product_interaction_script($handle, $src, $tag, $settings);
        $real_cookie_banner_protected = !empty($settings['real_cookie_banner_compatibility'])
            && $this->ultracache_is_real_cookie_banner_infrastructure_script($handle, $src, $tag);
        $complianz_protected = !empty($settings['complianz_compatibility'])
            && $this->ultracache_is_complianz_infrastructure_script($handle, $src, $tag);

        $explicit_integration_route = array();
        if ($real_cookie_banner_protected) {
            $explicit_integration_route = array(
                'lane' => 'native',
                'reason' => 'explicit-real-cookie-banner-compatibility',
                'action' => 'unchanged',
                'interactionEligible' => true,
            );
        } elseif ($complianz_protected) {
            $explicit_integration_route = array(
                'lane' => 'native',
                'reason' => 'explicit-complianz-compatibility',
                'action' => 'unchanged',
                'interactionEligible' => true,
            );
        } elseif ($delay_pipeline_active && $elementor_protected) {
            $explicit_integration_route = array(
                'lane' => 'defer',
                'reason' => 'explicit-elementor-compatibility',
                'action' => 'defer-native',
                'interactionEligible' => true,
            );
        } elseif ($delay_pipeline_active && $wpbakery_protected) {
            $explicit_integration_route = array(
                'lane' => 'defer',
                'reason' => 'explicit-wpbakery-compatibility',
                'action' => 'defer-force-ordered',
                'interactionEligible' => true,
            );
        }

        $safe_pattern = '';
        $functional_pattern = '';
        $is_third_party = false;
        if (!$real_cookie_banner_protected && !$complianz_protected && !$woocommerce_protected && !$elementor_protected && !$wpbakery_protected && !$this->should_native_defer_all_local_script($src, $settings)) {
            $is_third_party = $this->is_external_third_party_script_url($src);
            $patterns = isset($policy['patterns']) && is_array($policy['patterns']) ? $policy['patterns'] : array();
            $safe_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, isset($patterns['safe']) && is_array($patterns['safe']) ? $patterns['safe'] : array());
            $functional_pattern = $this->get_matching_third_party_delay_pattern($handle, $src, $tag, isset($patterns['functional']) && is_array($patterns['functional']) ? $patterns['functional'] : array());
        }

        $non_critical_pattern = '';
        $aggressive_non_critical_candidate = false;
        if (!$real_cookie_banner_protected && !$complianz_protected && !$woocommerce_protected && !$elementor_protected && !$wpbakery_protected
            && $this->is_same_host_public_url($src)
            && !$this->should_native_defer_all_local_script($src, $settings)) {
            if (!empty($settings['critical_request_chain_relief'])
                && $this->script_matches_fragment_list($handle, $src, $this->get_critical_request_chain_delay_fragments($settings))) {
                $non_critical_pattern = 'critical-request-chain-relief';
            } elseif ($this->matches_non_critical_delay_patterns($handle, $src, $tag)) {
                $non_critical_pattern = 'non-critical-pattern';
            }
            $aggressive_non_critical_candidate = $this->is_local_wp_content_script_url($src) && $this->script_handle_is_footer_group($handle);
        }

        $async_route = array();
        if (!empty($settings['async_external_scripts']) && $this->should_async_external_script($handle, $src, $tag, $settings)) {
            if ($this->script_handle_has_active_dependency_edges($handle) || $this->script_handle_has_wp_inline_companion_segments($handle)) {
                $async_route = array(
                    'lane' => 'defer',
                    'reason' => 'generic-dependency-ordered-defer',
                    'action' => 'defer-force-ordered',
                    'interactionEligible' => true,
                );
            } else {
                $async_route = array(
                    'lane' => 'native',
                    'reason' => 'explicit-async-external-policy',
                    'action' => 'async',
                    'interactionEligible' => true,
                );
            }
        }

        return array(
            'visibleNativePattern' => $visible_native ? 'registered-visible-native' : '',
            'visibleDeferPattern' => $visible_defer ? 'registered-visible-defer' : '',
            'explicitIntegrationRoute' => $explicit_integration_route,
            'delayAllCandidate' => $delay_all_js && $this->is_defer_all_js_candidate($handle, $src, $tag, $settings),
            'safePattern' => $safe_pattern,
            'functionalPattern' => $functional_pattern,
            'isThirdParty' => $is_third_party,
            'nonCriticalPattern' => $non_critical_pattern,
            'aggressiveNonCriticalCandidate' => $aggressive_non_critical_candidate,
            'asyncRoute' => $async_route,
        );
    }

    /** Add current-request module metadata/config to one lane bundle tag. */
    private function ultracache_add_runtime_bundle_attributes_to_script_tag($tag, $handle, $lane)
    {
        $lane = strtolower(trim((string) $lane));
        $modules = array();
        $opaque_config = '';
        if (function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
            if (is_object($wp_scripts)) {
                if (!empty($wp_scripts->registered[$handle]) && is_object($wp_scripts->registered[$handle])) {
                    $extra = isset($wp_scripts->registered[$handle]->extra) && is_array($wp_scripts->registered[$handle]->extra)
                        ? $wp_scripts->registered[$handle]->extra
                        : array();
                    if (!empty($extra['ultracache_runtime_modules']) && is_array($extra['ultracache_runtime_modules'])) {
                        $modules = array_values(array_unique(array_filter(array_map('sanitize_key', $extra['ultracache_runtime_modules']))));
                    }
                }
                if (method_exists($wp_scripts, 'get_data')) {
                    $opaque_config = (string) $wp_scripts->get_data($handle, 'ultracache_opaque_config');
                }
            }
        }
        if (empty($modules)) {
            $modules = $this->ultracache_get_frontend_runtime_auto_module_ids($lane);
        }
        sort($modules, SORT_STRING);

        if (class_exists('WP_HTML_Tag_Processor')) {
            try {
                $processor = new WP_HTML_Tag_Processor((string) $tag);
                if ($processor->next_tag('SCRIPT')) {
                    $processor->set_attribute('data-ultracache-runtime-bundle', $lane);
                    if (!empty($modules)) {
                        $processor->set_attribute('data-ultracache-modules', implode(',', $modules));
                    }
                    if ('' !== $opaque_config) {
                        $processor->set_attribute('data-ultracache-config', preg_replace('/[^A-Za-z0-9_-]/', '', $opaque_config));
                    }
                    $updated = $processor->get_updated_html();
                    if (is_string($updated) && '' !== $updated) {
                        return $updated;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to conservative string insertion.
            }
        }

        $tag = preg_replace('/\sdata-ultracache-runtime-bundle=(?:"[^"]*"|\'[^\']*\')/i', '', (string) $tag);
        $tag = preg_replace('/\sdata-ultracache-modules=(?:"[^"]*"|\'[^\']*\')/i', '', (string) $tag);
        $tag = preg_replace('/\sdata-ultracache-config=(?:"[^"]*"|\'[^\']*\')/i', '', (string) $tag);
        $attributes = ' data-ultracache-runtime-bundle="' . esc_attr($lane) . '"';
        if (!empty($modules)) {
            $attributes .= ' data-ultracache-modules="' . esc_attr(implode(',', $modules)) . '"';
        }
        if ('' !== $opaque_config) {
            $attributes .= ' data-ultracache-config="' . esc_attr(preg_replace('/[^A-Za-z0-9_-]/', '', $opaque_config)) . '"';
        }
        $updated = preg_replace('/<script\b/i', '<script' . $attributes, (string) $tag, 1);
        return is_string($updated) && '' !== $updated ? $updated : $tag;
    }

    /**
     * Late transport guard for runtime-bundle diagnostic module metadata.
     *
     * Generated bundles self-activate from compile-time state. Reapply the finalized
     * lane/module attributes at the end of the script-loader filter chain so Runtime
     * Scan and diagnostics can still inspect the actual external bundle element.
     */
    public function ultracache_finalize_runtime_bundle_activation_script_tag($tag, $handle, $src)
    {
        unset($src);

        $bundle = $this->ultracache_get_frontend_runtime_bundle($handle);
        if (empty($bundle['lane'])) {
            return $tag;
        }

        return $this->ultracache_add_runtime_bundle_attributes_to_script_tag(
            $tag,
            sanitize_key((string) $handle),
            (string) $bundle['lane']
        );
    }

    /** Whether classification audit metadata is enabled for this request. */
    private function ultracache_classification_audit_enabled()
    {
        static $enabled = null;
        if (null === $enabled) {
            $enabled = method_exists($this, 'is_runtime_js_scan_request') && $this->is_runtime_js_scan_request();
        }
        return (bool) $enabled;
    }

    /** Resolve a stable diagnostics-only decision-source label for one route. */
    private function ultracache_classification_audit_decision_source(array $route)
    {
        $rule_id = sanitize_key((string) ($route['rule_id'] ?? ''));
        $reason = sanitize_key((string) ($route['reason'] ?? ''));

        if (in_array($rule_id, array('visible-native', 'visible-defer'), true)
            || in_array($reason, array('visible-do-not-defer-or-delay', 'visible-defer-instead-of-delay'), true)) {
            return 'visible-list';
        }
        if ('explicit-author-opt-out' === $rule_id || 'explicit-author-optimizer-opt-out' === $reason) {
            return 'explicit-author-opt-out';
        }
        if ('explicit-integration' === $rule_id || 0 === strpos($reason, 'explicit-')) {
            return 'explicit-integration-switch';
        }
        if ('html-js-semantics' === $rule_id || 0 === strpos($reason, 'html-js-semantics-')) {
            return 'html-js-semantics';
        }
        if (false !== strpos($reason, 'dependency') || false !== strpos($reason, 'companion') || false !== strpos($reason, 'family-coherence')) {
            return 'generic-dependency-semantics';
        }
        if (in_array($rule_id, array('ultracache-runtime-bundle', 'ultracache-runtime-module'), true)) {
            return 'ultracache-runtime-registry';
        }
        return 'default-strategy';
    }

    /**
     * Add diagnostics-only classification metadata to a rendered script tag.
     *
     * This runs only for a verified Runtime Scan request. Normal production
     * HTML receives no audit attributes and no telemetry/network behavior.
     */
    private function ultracache_add_classification_audit_attributes_to_script_tag($tag, array $route, $handle, $src, $caught_by = 'registered-router')
    {
        if (!$this->ultracache_classification_audit_enabled() || !is_string($tag) || '' === $tag || false === stripos($tag, '<script')) {
            return $tag;
        }

        $lane = sanitize_key((string) ($route['lane'] ?? ''));
        if (!in_array($lane, array('native', 'defer', 'delay'), true)) {
            return $tag;
        }

        $attributes = array(
            'data-ultracache-audit-lane' => $lane,
            'data-ultracache-audit-reason' => sanitize_key((string) ($route['reason'] ?? 'unknown')),
            'data-ultracache-audit-source' => $this->ultracache_classification_audit_decision_source($route),
            'data-ultracache-audit-caught-by' => sanitize_key((string) $caught_by),
            'data-ultracache-audit-rule' => sanitize_key((string) ($route['rule_id'] ?? '')),
        );

        $matched_pattern = (string) ($route['matched_pattern'] ?? ($route['matchedPattern'] ?? ''));
        if ('' !== trim($matched_pattern)) {
            $attributes['data-ultracache-audit-pattern'] = sanitize_text_field(substr(trim($matched_pattern), 0, 180));
        }
        if ('' !== trim((string) $handle)) {
            $attributes['data-ultracache-audit-handle'] = sanitize_text_field(substr((string) $handle, 0, 180));
        }

        if (class_exists('WP_HTML_Tag_Processor')) {
            try {
                $processor = new WP_HTML_Tag_Processor($tag);
                if ($processor->next_tag('SCRIPT')) {
                    foreach ($attributes as $name => $value) {
                        if ('' !== (string) $value) {
                            $processor->set_attribute($name, (string) $value);
                        }
                    }
                    $updated = $processor->get_updated_html();
                    if (is_string($updated) && '' !== $updated) {
                        return $updated;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to conservative string insertion.
            }
        }

        $insertion = '';
        foreach ($attributes as $name => $value) {
            if ('' !== (string) $value) {
                $insertion .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
            }
        }
        if ('' === $insertion) {
            return $tag;
        }
        $updated = preg_replace('/<script\b/i', '<script' . $insertion, $tag, 1);
        return is_string($updated) && '' !== $updated ? $updated : $tag;
    }

    /**
     * Apply a previously built route without making any new policy decision.
     *
     * @param array  $route  Route returned by ultracache_build_registered_script_route().
     * @param string $tag    Rendered script tag.
     * @param string $handle WordPress script handle.
     * @param string $src    Script source URL.
     * @param array  $settings Effective UltraCache settings.
     * @return string
     */
    private function ultracache_apply_registered_script_route(array $route, $tag, $handle, $src, array $settings = array())
    {
        $action = isset($route['action']) ? (string) $route['action'] : 'unchanged';
        $result = $tag;

        switch ($action) {
            case 'runtime-bundle-native':
                $result = $this->ultracache_add_runtime_bundle_attributes_to_script_tag($tag, $handle, 'native');
                $result = $this->strip_native_loading_attributes_from_script_tag($result);
                break;

            case 'runtime-bundle-defer':
                $result = $this->ultracache_add_runtime_bundle_attributes_to_script_tag($tag, $handle, 'defer');
                $result = $this->add_defer_attribute_to_script_tag($result, true);
                break;

            case 'runtime-bundle-delay':
                $result = $this->ultracache_add_runtime_bundle_attributes_to_script_tag($tag, $handle, 'delay');
                $result = $this->build_delayed_script_tag($result, $handle, $src, 'ultracache-runtime-delay');
                break;

            case 'defer-native':
                $result = $this->add_defer_attribute_to_script_tag($tag, true);
                break;

            case 'opaque-bootstrap':
                $result = $this->strip_native_loading_attributes_from_script_tag($tag);
                $opaque_config = '';
                if (function_exists('wp_scripts')) {
                    $wp_scripts = wp_scripts();
                    if (is_object($wp_scripts) && method_exists($wp_scripts, 'get_data')) {
                        $opaque_config = (string) $wp_scripts->get_data($handle, 'ultracache_opaque_config');
                    }
                }
                $result = $this->add_ultracache_opaque_config_to_script_tag($result, $opaque_config);
                break;

            case 'native-strip-loading':
                $result = $this->strip_native_loading_attributes_from_script_tag($tag);
                break;

            case 'defer-force-ordered':
                $result = $this->add_defer_attribute_to_script_tag($tag, true);
                break;

            case 'delay-tag':
                $result = $this->build_delayed_script_tag(
                    $tag,
                    $handle,
                    $src,
                    isset($route['delay_reason']) ? (string) $route['delay_reason'] : ''
                );
                break;

            case 'async':
                $result = $this->add_async_attribute_to_script_tag($tag);
                break;

            case 'defer-default':
                if ($this->script_handle_has_active_dependency_edges($handle) || $this->script_handle_has_wp_inline_companion_segments($handle)) {
                    $result = $this->add_defer_attribute_to_script_tag($tag, true);
                } else {
                    $result = $this->add_defer_or_parallel_attribute_to_script_tag($tag, $src, $settings, false);
                }
                break;

            case 'delay-html-pass':
            case 'unchanged':
            default:
                $result = $tag;
                break;
        }

        return $this->ultracache_add_classification_audit_attributes_to_script_tag(
            $result,
            $route,
            $handle,
            $src,
            'registered-router'
        );
    }
}
