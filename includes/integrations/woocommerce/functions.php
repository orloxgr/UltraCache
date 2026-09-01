<?php
/**
 * WooCommerce Varnish ESI template API.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the classic WooCommerce mini-cart ESI adapter markup.
 *
 * The adapter uses a private, non-cacheable fragment and the standard
 * WooCommerce `div.widget_shopping_cart_content` replacement selector.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return string
 */
function ultracache_get_woocommerce_varnish_esi_mini_cart_markup(array $args = array())
{
    if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
        return '';
    }

    $engine = Ultra_Cache_Engine::get_instance();
    if (!method_exists($engine, 'get_woocommerce_varnish_esi_mini_cart_markup')) {
        return '';
    }

    return (string) $engine->get_woocommerce_varnish_esi_mini_cart_markup($args);
}

/**
 * Echo the classic WooCommerce mini-cart ESI adapter markup.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return void
 */
function ultracache_render_woocommerce_varnish_esi_mini_cart(array $args = array())
{
    echo ultracache_get_woocommerce_varnish_esi_mini_cart_markup($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Adapter markup is rendered and escaped by UltraCache/WooCommerce.
}



/**
 * Return the native LiteSpeed ESI classic mini-cart adapter markup.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return string
 */
function ultracache_get_woocommerce_litespeed_esi_mini_cart_markup(array $args = array())
{
    if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
        return '';
    }

    $engine = Ultra_Cache_Engine::get_instance();
    if (!method_exists($engine, 'get_woocommerce_litespeed_esi_mini_cart_markup')) {
        return '';
    }

    return (string) $engine->get_woocommerce_litespeed_esi_mini_cart_markup($args);
}

/**
 * Echo the native LiteSpeed ESI classic mini-cart adapter markup.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return void
 */
function ultracache_render_woocommerce_litespeed_esi_mini_cart(array $args = array())
{
    echo ultracache_get_woocommerce_litespeed_esi_mini_cart_markup($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Legacy alias for the Varnish ESI classic mini-cart adapter.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return string
 */
function ultracache_get_woocommerce_esi_mini_cart_markup(array $args = array())
{
    return ultracache_get_woocommerce_varnish_esi_mini_cart_markup($args);
}

/**
 * Legacy alias for the Varnish ESI classic mini-cart renderer.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return void
 */
function ultracache_render_woocommerce_esi_mini_cart(array $args = array())
{
    ultracache_render_woocommerce_varnish_esi_mini_cart($args);
}

/**
 * Return the persistent option key for the resolved WooCommerce endpoint contract.
 *
 * @return string
 */
function ultracache_woocommerce_endpoint_contract_option_key()
{
    return 'ultracache_woocommerce_endpoint_contract_v1';
}

/**
 * Normalize one local WooCommerce page URL to the public request path used by
 * UltraCache's early cache and server-rule layers.
 *
 * @param string $url Absolute local URL.
 * @return string
 */
function ultracache_normalize_woocommerce_dynamic_path($url)
{
    $url = esc_url_raw((string) $url);
    if ('' === $url) {
        return '';
    }

    if (function_exists('ultracache_is_local_site_url') && !ultracache_is_local_site_url($url)) {
        return '';
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) {
        return '';
    }

    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if ('/' === $path) {
        /*
         * dynamicPaths is deliberately a path-only routing contract. A URL
         * such as /?page_id=11 is query-routed and cannot be represented as
         * '/' without changing its identity to the public homepage. Preserve
         * real root-page URLs, but reject root URLs whose routing information
         * lives in the query string.
         */
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && '' !== trim($query)) {
            return '';
        }

        return '/';
    }

    return trailingslashit(rtrim($path, '/'));
}

/**
 * Build an exact query-routed WooCommerce page rule from one local URL.
 *
 * The rule stores the request path plus the scalar query pairs required to
 * identify that WooCommerce page. Extra query arguments may still be present
 * on the request; all required routing pairs must match exactly.
 *
 * @param string $url Absolute local URL.
 * @return array<string,mixed>
 */
function ultracache_build_woocommerce_dynamic_query_rule($url)
{
    $url = esc_url_raw((string) $url);
    if ('' === $url) {
        return array();
    }
    if (function_exists('ultracache_is_local_site_url') && !ultracache_is_local_site_url($url)) {
        return array();
    }

    $query = wp_parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || '' === trim($query)) {
        return array();
    }

    wp_parse_str($query, $query_vars);
    if (empty($query_vars) || !is_array($query_vars)) {
        return array();
    }

    $required = array();
    foreach ($query_vars as $key => $value) {
        if (is_array($value) || is_object($value)) {
            return array();
        }
        $normalized_key = function_exists('ultracache_normalize_query_policy_key')
            ? ultracache_normalize_query_policy_key($key)
            : sanitize_key((string) $key);
        if ('' === $normalized_key) {
            return array();
        }
        $required[$normalized_key] = (string) $value;
    }
    if (empty($required)) {
        return array();
    }
    ksort($required, SORT_STRING);

    $path = wp_parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? '/' . ltrim(str_replace('\\', '/', $path), '/') : '/';
    $path = '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));

    return array(
        'path' => $path,
        'query' => $required,
    );
}

/**
 * Return the normalized WooCommerce query-route rules.
 *
 * @return array<int,array<string,mixed>>
 */
function ultracache_get_woocommerce_dynamic_query_rules()
{
    $contract = ultracache_get_woocommerce_endpoint_contract();
    if (empty($contract['active']) || empty($contract['available'])) {
        return array();
    }

    $rules = array();
    foreach ((array) ($contract['dynamicQueryRules'] ?? array()) as $rule) {
        if (!is_array($rule) || empty($rule['query']) || !is_array($rule['query'])) {
            continue;
        }
        $path = '/' . ltrim((string) ($rule['path'] ?? '/'), '/');
        $path = '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
        $query = array();
        foreach ($rule['query'] as $key => $value) {
            $key = function_exists('ultracache_normalize_query_policy_key')
                ? ultracache_normalize_query_policy_key($key)
                : sanitize_key((string) $key);
            if ('' !== $key && !is_array($value) && !is_object($value)) {
                $query[$key] = (string) $value;
            }
        }
        if (!empty($query)) {
            ksort($query, SORT_STRING);
            $rules[] = array('path' => $path, 'query' => $query);
        }
    }

    return $rules;
}

/**
 * Return WooCommerce endpoint query keys using WooCommerce's current query-var
 * contract, including administrator-customized endpoint slugs.
 *
 * @return string[]
 */
function ultracache_get_woocommerce_dynamic_query_keys()
{
    $contract = ultracache_get_woocommerce_endpoint_contract();
    if (empty($contract['active']) || empty($contract['available'])) {
        return array();
    }

    $keys = array();
    foreach ((array) ($contract['dynamicQueryKeys'] ?? array()) as $key) {
        $key = function_exists('ultracache_normalize_query_policy_key')
            ? ultracache_normalize_query_policy_key($key)
            : sanitize_key((string) $key);
        if ('' !== $key) {
            $keys[$key] = $key;
        }
    }

    return array_values($keys);
}

/**
 * Match one URL against the WooCommerce exact query-route contract.
 *
 * @param string $url Absolute request URL.
 * @return string Diagnostic rule label, or empty string when unmatched.
 */
function ultracache_match_woocommerce_dynamic_query_rule($url)
{
    $url = esc_url_raw((string) $url);
    if ('' === $url) {
        return '';
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? '/' . ltrim(str_replace('\\', '/', $path), '/') : '/';
    $path = '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
    $query = wp_parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || '' === $query) {
        return '';
    }

    wp_parse_str($query, $query_vars);
    if (!is_array($query_vars) || empty($query_vars)) {
        return '';
    }
    $normalized = array();
    foreach ($query_vars as $key => $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $key = function_exists('ultracache_normalize_query_policy_key')
            ? ultracache_normalize_query_policy_key($key)
            : sanitize_key((string) $key);
        if ('' !== $key) {
            $normalized[$key] = (string) $value;
        }
    }

    foreach (ultracache_get_woocommerce_dynamic_query_rules() as $rule) {
        if ($path !== (string) ($rule['path'] ?? '/')) {
            continue;
        }
        $matched = true;
        foreach ((array) ($rule['query'] ?? array()) as $key => $value) {
            if (!array_key_exists($key, $normalized) || (string) $normalized[$key] !== (string) $value) {
                $matched = false;
                break;
            }
        }
        if ($matched) {
            $pairs = array();
            foreach ((array) $rule['query'] as $key => $value) {
                $pairs[] = $key . '=' . $value;
            }
            return $path . '?' . implode('&', $pairs);
        }
    }

    return '';
}

/**
 * Whether WordPress's permalink/rewrite runtime is ready for WooCommerce page
 * permalink helpers.
 *
 * WP-CLI and admin/maintenance bootstrap can execute UltraCache's drop-in
 * reconciliation on `plugins_loaded`, before the global WP_Rewrite instance
 * exists. Calling `wc_get_page_permalink()` in that window reaches
 * `get_permalink()` / `_get_page_link()` and would dereference a null rewrite
 * object. Keep this capability check independent from request type.
 *
 * @return bool
 */
function ultracache_woocommerce_routing_runtime_ready()
{
    global $wp_rewrite;

    return is_object($wp_rewrite)
        && method_exists($wp_rewrite, 'get_page_permastruct');
}

/**
 * Build the current WooCommerce public dynamic-page contract from the modern
 * WooCommerce page APIs. UltraCache intentionally does not maintain a legacy
 * WooCommerce 8.x compatibility resolver.
 *
 * The builder is safe to call during early WordPress bootstrap. When the
 * rewrite runtime has not been initialized yet, it returns an unavailable
 * fail-closed observation instead of calling permalink helpers that require
 * the global WP_Rewrite instance.
 *
 * @return array<string,mixed>
 */
function ultracache_build_woocommerce_endpoint_contract()
{
    $active = class_exists('WooCommerce') || defined('WC_VERSION') || function_exists('WC');
    $modern_api_available = $active
        && function_exists('wc_get_page_id')
        && function_exists('wc_get_page_permalink')
        && function_exists('wc_get_endpoint_url');
    $routing_runtime_ready = !$active || ultracache_woocommerce_routing_runtime_ready();
    $available = $modern_api_available && $routing_runtime_ready;
    $contract = array(
        'version' => 2,
        'active' => $active,
        'available' => $available,
        'reason' => '',
        'woocommerceVersion' => defined('WC_VERSION') ? sanitize_text_field((string) WC_VERSION) : '',
        'routingRuntimeReady' => $routing_runtime_ready,
        'pageIds' => array(),
        'pageUrls' => array(),
        'dynamicPaths' => array(),
        'dynamicQueryRules' => array(),
        'dynamicQueryKeys' => array(),
        'endpointSlugs' => array(),
        'routingFingerprint' => '',
        'fingerprint' => '',
        'resolvedAt' => time(),
    );

    if ($active && !$modern_api_available) {
        $contract['reason'] = 'modern-routing-api-unavailable';
    } elseif ($active && !$routing_runtime_ready) {
        $contract['reason'] = 'wordpress-rewrite-runtime-unavailable';
    } elseif (!$active) {
        $contract['reason'] = 'woocommerce-inactive';
    }

    if (!$active || empty($contract['available'])) {
        $state = $active ? 'active-unavailable' : 'inactive';
        $state_reason = (string) ($contract['reason'] ?? '');
        $contract['routingFingerprint'] = hash('sha256', 'ultracache-woocommerce-endpoint-routing-v2|' . $state . '|' . $state_reason);
        $contract['fingerprint'] = hash('sha256', 'ultracache-woocommerce-endpoint-contract-v2|' . $state . '|' . $state_reason);
        return $contract;
    }

    foreach (array('cart', 'checkout', 'myaccount') as $page_key) {
        $page_id = (int) wc_get_page_id($page_key);
        if ($page_id <= 0) {
            continue;
        }

        // Passing false prevents WooCommerce from falling back to home_url()
        // when an assigned page is missing or has no usable permalink.
        $url = esc_url_raw((string) wc_get_page_permalink($page_key, false));
        if ('' === $url) {
            continue;
        }
        if (function_exists('ultracache_is_local_site_url') && !ultracache_is_local_site_url($url)) {
            continue;
        }

        $contract['pageIds'][$page_key] = $page_id;
        $contract['pageUrls'][$page_key] = $url;

        $path = ultracache_normalize_woocommerce_dynamic_path($url);
        if ('' !== $path) {
            // A root path is only the WooCommerce page route when WordPress
            // actually assigns that page as the static front page. This keeps
            // filtered/fallback root URLs from broadening the contract to '/'.
            if ('/' !== $path || (
                'page' === (string) get_option('show_on_front', '')
                && $page_id === absint(get_option('page_on_front', 0))
            )) {
                $contract['dynamicPaths'][$path] = $path;
            }
        }

        $query_rule = ultracache_build_woocommerce_dynamic_query_rule($url);
        if (!empty($query_rule)) {
            $rule_key = hash('sha256', (string) wp_json_encode($query_rule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $contract['dynamicQueryRules'][$rule_key] = $query_rule;
        }
    }

    if (function_exists('WC')) {
        $woocommerce = WC();
        if (is_object($woocommerce) && isset($woocommerce->query) && is_object($woocommerce->query) && method_exists($woocommerce->query, 'get_query_vars')) {
            foreach ((array) $woocommerce->query->get_query_vars() as $endpoint_key => $endpoint_slug) {
                $endpoint_key = sanitize_key((string) $endpoint_key);
                $endpoint_slug = trim(sanitize_text_field((string) $endpoint_slug), " \t\n\r\0\x0B/");
                $query_key = function_exists('ultracache_normalize_query_policy_key')
                    ? ultracache_normalize_query_policy_key($endpoint_slug)
                    : sanitize_key($endpoint_slug);
                if ('' !== $endpoint_key && '' !== $endpoint_slug && '' !== $query_key) {
                    $contract['endpointSlugs'][$endpoint_key] = $endpoint_slug;
                    $contract['dynamicQueryKeys'][$query_key] = $query_key;
                }
            }
        }
    }

    // WooCommerce only registers its endpoint rewrites at EP_ROOT when the
    // Checkout or My Account page is the configured static front page. Mirror
    // that supported WooCommerce routing rule instead of materializing every
    // endpoint at root whenever any WooCommerce page happens to resolve to '/'.
    $front_page_id = 'page' === (string) get_option('show_on_front', '')
        ? absint(get_option('page_on_front', 0))
        : 0;
    $root_endpoint_base_key = '';
    foreach (array('checkout', 'myaccount') as $page_key) {
        if ($front_page_id > 0 && $front_page_id === absint($contract['pageIds'][$page_key] ?? 0)) {
            $root_endpoint_base_key = $page_key;
            break;
        }
    }

    if ('' !== $root_endpoint_base_key) {
        if (empty($contract['endpointSlugs'])) {
            $contract['available'] = false;
            $contract['reason'] = 'endpoint-query-vars-unavailable';
        } else {
            $base_url = (string) ($contract['pageUrls'][$root_endpoint_base_key] ?? home_url('/'));
            foreach (array_keys($contract['endpointSlugs']) as $endpoint_key) {
                $endpoint_url = esc_url_raw((string) wc_get_endpoint_url($endpoint_key, '', $base_url));
                $endpoint_path = ultracache_normalize_woocommerce_dynamic_path($endpoint_url);
                if ('' !== $endpoint_path && '/' !== $endpoint_path) {
                    $contract['dynamicPaths'][$endpoint_path] = $endpoint_path;
                }
            }
        }
    }

    if (!$contract['available']) {
        $contract['pageIds'] = array();
        $contract['pageUrls'] = array();
        $contract['dynamicPaths'] = array();
        $contract['dynamicQueryRules'] = array();
        $contract['dynamicQueryKeys'] = array();
    }

    $contract['dynamicPaths'] = array_values($contract['dynamicPaths']);
    sort($contract['dynamicPaths'], SORT_STRING);
    $contract['dynamicQueryRules'] = array_values($contract['dynamicQueryRules']);
    usort($contract['dynamicQueryRules'], static function ($a, $b) {
        return strcmp((string) wp_json_encode($a), (string) wp_json_encode($b));
    });
    $contract['dynamicQueryKeys'] = array_values($contract['dynamicQueryKeys']);
    sort($contract['dynamicQueryKeys'], SORT_STRING);
    ksort($contract['pageIds'], SORT_STRING);
    ksort($contract['pageUrls'], SORT_STRING);
    ksort($contract['endpointSlugs'], SORT_STRING);

    $routing_payload = array(
        'version' => 2,
        'active' => true,
        'available' => !empty($contract['available']),
        'pageIds' => $contract['pageIds'],
        'pageUrls' => $contract['pageUrls'],
        'dynamicPaths' => $contract['dynamicPaths'],
        'dynamicQueryRules' => $contract['dynamicQueryRules'],
        'dynamicQueryKeys' => $contract['dynamicQueryKeys'],
    );
    $routing_json = wp_json_encode($routing_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $contract['routingFingerprint'] = hash('sha256', is_string($routing_json) ? $routing_json : serialize($routing_payload));

    $fingerprint_payload = array(
        'routing' => $routing_payload,
        'woocommerceVersion' => $contract['woocommerceVersion'],
        'endpointSlugs' => $contract['endpointSlugs'],
    );
    $fingerprint_json = wp_json_encode($fingerprint_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $contract['fingerprint'] = hash('sha256', is_string($fingerprint_json) ? $fingerprint_json : serialize($fingerprint_payload));

    return $contract;
}

/**
 * Return the best available WooCommerce endpoint contract.
 *
 * Full WordPress requests prefer live WooCommerce APIs. The stored contract is
 * used only when the APIs are not currently loaded, such as diagnostics during
 * an unusual bootstrap phase. The advanced-cache drop-in receives a separate
 * embedded snapshot and never loads WooCommerce itself.
 *
 * @return array<string,mixed>
 */
function ultracache_get_woocommerce_endpoint_contract()
{
    $active = class_exists('WooCommerce') || defined('WC_VERSION') || function_exists('WC');
    $stored = get_option(ultracache_woocommerce_endpoint_contract_option_key(), array());
    $stored = is_array($stored) ? $stored : array();

    if ($active && ultracache_woocommerce_routing_runtime_ready()) {
        return ultracache_build_woocommerce_endpoint_contract();
    }

    // Early bootstrap (notably plugins_loaded reconciliation and WP-CLI load)
    // must not call WooCommerce permalink helpers before WP_Rewrite exists.
    // Reuse the last persisted observation/applied contract until the normal
    // WordPress routing runtime is ready. This is conservative across route
    // changes because the previous private paths remain excluded temporarily.
    if (!empty($stored['fingerprint'])) {
        return $stored;
    }

    // First install with no persisted contract: return the builder's guarded
    // fail-closed observation. It will be replaced by the live contract once
    // admin_init/shutdown synchronization runs after normal bootstrap.
    return ultracache_build_woocommerce_endpoint_contract();
}

/**
 * Whether WooCommerce is active but its modern routing contract is unavailable.
 *
 * @return bool
 */
function ultracache_woocommerce_endpoint_contract_requires_fail_closed()
{
    $contract = ultracache_get_woocommerce_endpoint_contract();
    return !empty($contract['active']) && empty($contract['available']);
}

/**
 * Return resolved WooCommerce public dynamic page paths.
 *
 * @return string[]
 */
function ultracache_get_woocommerce_dynamic_paths()
{
    $contract = ultracache_get_woocommerce_endpoint_contract();
    if (empty($contract['active']) || empty($contract['available'])) {
        return array();
    }

    $paths = array();
    foreach ((array) ($contract['dynamicPaths'] ?? array()) as $path) {
        $path = '/' . ltrim((string) $path, '/');
        $path = '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
        $paths[$path] = $path;
    }

    return array_values($paths);
}

/**
 * Return one resolved WooCommerce base page URL from the endpoint contract.
 *
 * @param string $page_key cart, checkout, or myaccount.
 * @return string
 */
function ultracache_get_woocommerce_page_url($page_key)
{
    $page_key = sanitize_key((string) $page_key);
    $contract = ultracache_get_woocommerce_endpoint_contract();
    $url = isset($contract['pageUrls'][$page_key]) ? esc_url_raw((string) $contract['pageUrls'][$page_key]) : '';

    return $url;
}
