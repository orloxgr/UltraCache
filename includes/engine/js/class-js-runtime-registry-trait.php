<?php
/**
 * Central registry for UltraCache-owned frontend JavaScript runtime modules.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_JS_Runtime_Registry_Trait
{
    /** @var array<string,bool> Runtime modules whose source must be present in the current request bundle. */
    private $ultracache_frontend_runtime_included_modules = array();

    /** @var array<string,bool> Runtime modules requested to execute for the current request. */
    private $ultracache_frontend_runtime_requested_modules = array();

    /** @var array<string,bool> Dependency-free runtime modules that may auto-activate when their lane bundle executes. */
    private $ultracache_frontend_runtime_auto_modules = array();

    /** @var array<string,array<int,array{global:string,data:array}>> Deferred inline configuration per runtime module. */
    private $ultracache_frontend_runtime_module_configs = array();

    /** @var array<string,array<string,mixed>> Deferred WordPress script metadata per runtime module. */
    private $ultracache_frontend_runtime_module_script_data = array();

    /** @var array<string,array<string,mixed>> Finalized current-request bundle assets by lane. */
    private $ultracache_frontend_runtime_bundle_assets = array();

    /**
     * Return the canonical UltraCache frontend runtime module definitions.
     *
     * 3.12.06 completes the first per-module native-lane audit. Parser-early
     * execution is now reserved for modules that cannot recover information lost
     * before deferred execution (early interaction/LCP credential capture) plus
     * the explicitly requested runtime scanner. Other helpers use DEFER even when
     * they perform non-critical interception or optimization work.
     *
     * @return array<string,array<string,mixed>>
     */
    private function ultracache_frontend_runtime_module_definitions()
    {
        return array(
            'async-css-runtime' => array(
                'handle'       => 'ultracache-async-css-runtime',
                'asset'        => 'async-css-runtime.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'defer-safe-managed-css-recovery-scan',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'dynamic-script-finder-bootstrap' => array(
                'handle'       => 'ultracache-dynamic-script-finder-bootstrap',
                'asset'        => 'dynamic-script-finder-bootstrap.js',
                'lane'                  => 'native',
                'parser_early_required' => true,
                'dependencies'          => array(),
                'reason'                => 'capture-runtime-created-scripts-before-execution',
                'route_action'          => 'native-strip-loading',
                'in_footer'             => false,
            ),
            'delayed-js-interaction-bootstrap' => array(
                'handle'       => 'ultracache-delayed-js-interaction-bootstrap',
                'asset'        => 'delayed-js-interaction-bootstrap.js',
                'lane'                  => 'native',
                'parser_early_required' => true,
                'dependencies'          => array(),
                'reason'                => 'capture-pre-defer-visitor-interaction',
                'route_action'          => 'opaque-bootstrap',
                'in_footer'    => false,
            ),
            'delayed-js-loader' => array(
                'handle'       => 'ultracache-delayed-js-loader',
                'asset'        => 'delayed-js-loader.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'delay-executor-must-load-before-delay-release',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'inline-registry-dispatcher' => array(
                'handle'       => 'ultracache-inline-registry-dispatcher',
                'asset'        => 'inline-registry-dispatcher.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'per-occurrence-inline-registry-dispatch',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'elementor-compatibility-runtime' => array(
                'handle'       => 'ultracache-elementor-compatibility-runtime',
                'asset'        => 'elementor-compatibility-runtime.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'explicit-elementor-compatibility-runtime',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'dynamic-icon-font-delay' => array(
                'handle'       => 'ultracache-dynamic-icon-font-delay',
                'asset'        => 'dynamic-icon-font-delay.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'noncritical-dynamic-font-interception',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'font-display-cssom-patch' => array(
                'handle'       => 'ultracache-font-display-cssom-patch',
                'asset'        => 'font-display-cssom-patch.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'defer-safe-cssom-rescan-and-observer',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'lazy-third-party-iframes' => array(
                'handle'       => 'ultracache-lazy-third-party-iframes',
                'asset'        => 'lazy-third-party-iframes.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'deferred-viewport-iframe-runtime',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'lcp-observer' => array(
                'handle'       => 'ultracache-lcp-observer',
                'asset'        => 'lcp-observer.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'buffered-performance-observer-supports-defer',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'lcp-request-credentials-bootstrap' => array(
                'handle'       => 'ultracache-lcp-request-credentials-bootstrap',
                'asset'        => 'lcp-request-credentials-bootstrap.js',
                'lane'                  => 'native',
                'parser_early_required' => true,
                'dependencies'          => array(),
                'reason'                => 'capture-pre-defer-image-request-credential-assignments',
                'route_action'          => 'native-strip-loading',
                'in_footer'    => false,
            ),
            'mailerlite-lazy-nonce' => array(
                'handle'       => 'ultracache-mailerlite-lazy-nonce',
                'asset'        => 'mailerlite-lazy-nonce.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'interaction-driven-mailerlite-nonce-runtime',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'runtime-font-css-map' => array(
                'handle'       => 'ultracache-runtime-font-css-map',
                'asset'        => 'runtime-font-css-map.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'noncritical-runtime-font-url-rewrite',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'runtime-js-scan-collector' => array(
                'handle'       => 'ultracache-runtime-js-scan-collector',
                'asset'        => 'runtime-js-scan-collector.js',
                'lane'                  => 'native',
                'parser_early_required' => true,
                'diagnostic_only'       => true,
                'dependencies'          => array(),
                'reason'                => 'explicit-runtime-scan-request-early-error-capture',
                'route_action'          => 'native-strip-loading',
                'in_footer'    => false,
            ),
            'woocommerce-cart-fragments-delay' => array(
                'handle'       => 'ultracache-woocommerce-cart-fragments-delay',
                'asset'        => 'woocommerce-cart-fragments-delay.js',
                'lane'         => 'defer',
                'dependencies' => array('jquery'),
                'reason'       => 'defer-before-dom-ready-cart-fragments-interception',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'woocommerce-esi-optin' => array(
                'handle'       => 'ultracache-woocommerce-esi-optin',
                'asset'        => 'woocommerce-esi-optin.js',
                'lane'         => 'defer',
                'dependencies' => array(),
                'reason'       => 'dom-ready-esi-optin-runtime',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
            'woocommerce-variable-product-guard' => array(
                'handle'       => 'ultracache-woocommerce-variable-product-guard',
                'asset'        => 'woocommerce-variable-product-guard.js',
                'lane'         => 'defer',
                'dependencies' => array('jquery'),
                'reason'       => 'delegated-user-interaction-guard',
                'route_action' => 'defer-native',
                'in_footer'    => false,
            ),
        );
    }

    /**
     * Return the three canonical UltraCache runtime bundle definitions.
     *
     * Bundles are generated per active module-set. The fixed handle is the lane
     * identity; the URL is a content-addressed generated asset under uploads.
     *
     * @return array<string,array<string,mixed>>
     */
    private function ultracache_frontend_runtime_bundle_definitions()
    {
        return array(
            'native' => array(
                'handle'       => 'ultracache-runtime-native',
                'lane'         => 'native',
                'reason'       => 'ultracache-runtime-native-bundle',
                'route_action' => 'runtime-bundle-native',
                'in_footer'    => false,
            ),
            'defer' => array(
                'handle'       => 'ultracache-runtime-defer',
                'lane'         => 'defer',
                'reason'       => 'ultracache-runtime-defer-bundle',
                'route_action' => 'runtime-bundle-defer',
                'in_footer'    => false,
            ),
            'delay' => array(
                'handle'       => 'ultracache-runtime-delay',
                'lane'         => 'delay',
                'reason'       => 'ultracache-runtime-delay-bundle',
                'route_action' => 'runtime-bundle-delay',
                'in_footer'    => false,
            ),
        );
    }

    /** Return one bundle definition by lane. */
    private function ultracache_get_frontend_runtime_bundle_by_lane($lane)
    {
        $lane = strtolower(trim((string) $lane));
        $definitions = $this->ultracache_frontend_runtime_bundle_definitions();
        return isset($definitions[$lane]) ? $definitions[$lane] : array();
    }

    /** Return one bundle definition by WordPress handle/id. */
    private function ultracache_get_frontend_runtime_bundle($handle)
    {
        $handle = $this->ultracache_normalize_frontend_runtime_module_handle($handle);
        if ('' === $handle) {
            return array();
        }
        foreach ($this->ultracache_frontend_runtime_bundle_definitions() as $lane => $bundle) {
            if ($handle === (string) ($bundle['handle'] ?? '')) {
                $bundle['id'] = (string) $lane;
                return $bundle;
            }
        }
        return array();
    }

    /** Include one module source in its lane bundle without necessarily executing it. */
    private function ultracache_include_frontend_runtime_module($handle, $requested = false)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        if (empty($module['id'])) {
            return array();
        }
        $module_id = (string) $module['id'];
        $this->ultracache_frontend_runtime_included_modules[$module_id] = true;
        if (method_exists($this, 'ultracache_ensure_frontend_runtime_bundle_placeholder')) {
            $this->ultracache_ensure_frontend_runtime_bundle_placeholder((string) ($module['lane'] ?? 'native'));
        }
        if ($requested) {
            $this->ultracache_frontend_runtime_requested_modules[$module_id] = true;
            if (empty($module['dependencies'])) {
                $this->ultracache_frontend_runtime_auto_modules[$module_id] = true;
            }
        }
        return $module;
    }

    /** Reserve source for a late activation path without executing it at bundle load. */
    private function ultracache_reserve_frontend_runtime_module($handle)
    {
        return !empty($this->ultracache_include_frontend_runtime_module($handle, false));
    }

    /** Mark a module as requested and included for this request. */
    private function ultracache_request_frontend_runtime_module($handle)
    {
        return $this->ultracache_include_frontend_runtime_module($handle, true);
    }

    /** Whether one module was requested to execute in the current request. */
    private function ultracache_is_frontend_runtime_module_requested($handle)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        return !empty($module['id']) && !empty($this->ultracache_frontend_runtime_requested_modules[(string) $module['id']]);
    }

    /** Whether one module source is present in the current request bundle set. */
    private function ultracache_is_frontend_runtime_module_included($handle)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        return !empty($module['id']) && !empty($this->ultracache_frontend_runtime_included_modules[(string) $module['id']]);
    }

    /** Return included module ids for one lane in deterministic order. */
    private function ultracache_get_frontend_runtime_included_module_ids($lane)
    {
        $lane = strtolower(trim((string) $lane));
        $ids = array();
        foreach ($this->ultracache_frontend_runtime_module_definitions() as $module_id => $module) {
            if ($lane === (string) ($module['lane'] ?? '') && !empty($this->ultracache_frontend_runtime_included_modules[(string) $module_id])) {
                $ids[] = (string) $module_id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Return dependency-free auto-activation module ids for one lane. */
    private function ultracache_get_frontend_runtime_auto_module_ids($lane)
    {
        $lane = strtolower(trim((string) $lane));
        $ids = array();
        foreach ($this->ultracache_frontend_runtime_module_definitions() as $module_id => $module) {
            if ($lane === (string) ($module['lane'] ?? '') && !empty($this->ultracache_frontend_runtime_auto_modules[(string) $module_id])) {
                $ids[] = (string) $module_id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Store one module configuration until the lane bundle handle is registered. */
    private function ultracache_store_frontend_runtime_module_config($handle, $global_name, array $data)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) $global_name);
        if (empty($module['id']) || '' === $global_name) {
            return false;
        }
        $module_id = (string) $module['id'];
        if (!isset($this->ultracache_frontend_runtime_module_configs[$module_id])) {
            $this->ultracache_frontend_runtime_module_configs[$module_id] = array();
        }
        $this->ultracache_frontend_runtime_module_configs[$module_id][] = array(
            'global' => $global_name,
            'data'   => $data,
        );
        return true;
    }

    /** Store one module script-data value until its lane bundle is registered. */
    private function ultracache_store_frontend_runtime_module_script_data($handle, $key, $value)
    {
        $module = $this->ultracache_get_frontend_runtime_module($handle);
        $key = sanitize_key((string) $key);
        if (empty($module['id']) || '' === $key) {
            return false;
        }
        $module_id = (string) $module['id'];
        if (!isset($this->ultracache_frontend_runtime_module_script_data[$module_id])) {
            $this->ultracache_frontend_runtime_module_script_data[$module_id] = array();
        }
        $this->ultracache_frontend_runtime_module_script_data[$module_id][$key] = $value;
        return true;
    }

    /**
     * Normalize a candidate handle/id to a canonical WordPress script handle.
     *
     * @param string $handle Candidate handle or rendered script id.
     * @return string
     */
    private function ultracache_normalize_frontend_runtime_module_handle($handle)
    {
        $handle = sanitize_key((string) $handle);
        if ('' === $handle) {
            return '';
        }

        $handle = preg_replace('/-js(?:-extra)?$/', '', $handle);
        return is_string($handle) ? $handle : '';
    }

    /**
     * Return one canonical runtime module by handle/id.
     *
     * @param string $handle Script handle or rendered id.
     * @return array<string,mixed>
     */
    private function ultracache_get_frontend_runtime_module($handle)
    {
        $handle = $this->ultracache_normalize_frontend_runtime_module_handle($handle);
        if ('' === $handle) {
            return array();
        }

        foreach ($this->ultracache_frontend_runtime_module_definitions() as $module_id => $module) {
            if ($handle !== (string) ($module['handle'] ?? '')) {
                continue;
            }
            $module['id'] = (string) $module_id;
            $module['enabled'] = !empty($this->ultracache_frontend_runtime_requested_modules[(string) $module_id]);
            return $module;
        }

        return array();
    }

    /**
     * Return one canonical runtime module by its UltraCache asset URL/path.
     *
     * @param string $src Script URL/path.
     * @return array<string,mixed>
     */
    private function ultracache_get_frontend_runtime_module_by_src($src)
    {
        $src = (string) $src;
        if ('' === $src || false === strpos($src, '/ultracache/assets/js/')) {
            return array();
        }

        $path = parse_url($src, PHP_URL_PATH);
        $basename = basename(is_string($path) ? $path : $src);
        if ('' === $basename) {
            return array();
        }

        foreach ($this->ultracache_frontend_runtime_module_definitions() as $module_id => $module) {
            if ($basename !== basename((string) ($module['asset'] ?? ''))) {
                continue;
            }
            $handle = (string) ($module['handle'] ?? '');
            $module['id'] = (string) $module_id;
            $module['enabled'] = !empty($this->ultracache_frontend_runtime_requested_modules[(string) $module_id]);
            return $module;
        }

        return array();
    }

    /**
     * Return all registered module definitions with current-request enqueue state.
     *
     * The `enabled` flag remains observational in 3.12.06: existing feature/settings
     * code still decides whether a module is requested. The registry now also
     * supplies the generated lane bundles without becoming a second feature
     * activation policy.
     *
     * @return array<string,array<string,mixed>>
     */
    private function ultracache_frontend_runtime_modules()
    {
        $modules = array();
        foreach ($this->ultracache_frontend_runtime_module_definitions() as $module_id => $module) {
            $handle = (string) ($module['handle'] ?? '');
            $module['id'] = (string) $module_id;
            $module['enabled'] = !empty($this->ultracache_frontend_runtime_requested_modules[(string) $module_id]);
            $modules[(string) $module_id] = $module;
        }
        return $modules;
    }
}
