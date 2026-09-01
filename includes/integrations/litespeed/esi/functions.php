<?php
/**
 * Native LiteSpeed ESI developer API.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-litespeed-esi-registry.php';

/**
 * Whether native LiteSpeed ESI can be emitted for this site.
 *
 * OpenLiteSpeed is excluded because it does not provide ESI processing.
 *
 * @return bool
 */
function ultracache_litespeed_esi_is_configured()
{
    if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'get_dashboard_settings')) {
        return false;
    }

    $settings = Ultra_Cache_WP::get_dashboard_settings();
    $native_enabled = (!empty($settings['liteSpeedCacheEnabled']) || !empty($settings['litespeed_cache_enabled']))
        && (!empty($settings['pageCacheEnabled']) || !empty($settings['enabled']));
    if (!$native_enabled) {
        return false;
    }

    if (method_exists('Ultra_Cache_WP', 'is_varnish_runtime_enabled') && Ultra_Cache_WP::is_varnish_runtime_enabled($settings)) {
        return false;
    }

    $capability = method_exists('Ultra_Cache_WP', 'get_litespeed_esi_capability_read_only')
        ? Ultra_Cache_WP::get_litespeed_esi_capability_read_only()
        : array('status' => 'not_tested', 'serverType' => 'unknown');
    $supported = 'supported' === (string) ($capability['status'] ?? 'not_tested')
        && 'enterprise' === (string) ($capability['serverType'] ?? 'unknown');

    return (bool) apply_filters('ultracache_litespeed_esi_configured', $supported, $settings, $capability);
}

/**
 * Whether LiteSpeed ESI markup may be emitted.
 *
 * @return bool
 */
function ultracache_litespeed_esi_is_enabled()
{
    return (bool) apply_filters('ultracache_litespeed_esi_enabled', ultracache_litespeed_esi_is_configured());
}

/** @return int */
function ultracache_litespeed_esi_get_max_parent_fragments()
{
    return max(1, min(64, (int) apply_filters('ultracache_litespeed_esi_max_parent_fragments', 32)));
}

/** @return int */
function ultracache_litespeed_esi_get_max_parent_directive_bytes()
{
    return max(4096, min(262144, (int) apply_filters('ultracache_litespeed_esi_max_parent_directive_bytes', 65536)));
}

/** @return string */
function ultracache_litespeed_esi_get_current_locale()
{
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
    if (!is_string($locale) || strlen($locale) < 2 || strlen($locale) > 32) {
        $locale = 'en_US';
    }

    $locale = apply_filters('ultracache_litespeed_esi_fragment_locale', $locale);
    $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
    return is_string($locale) && strlen($locale) >= 2 && strlen($locale) <= 32 ? $locale : 'en_US';
}

/** @return string */
function ultracache_litespeed_esi_get_current_language()
{
    $language = function_exists('ultracache_wpml_get_current_language') ? ultracache_wpml_get_current_language() : '';
    $language = apply_filters('ultracache_litespeed_esi_fragment_language', $language);
    $language = function_exists('ultracache_wpml_normalize_language_code')
        ? ultracache_wpml_normalize_language_code($language)
        : strtolower(trim((string) $language));
    if ('' === $language) {
        return '';
    }
    if (function_exists('ultracache_wpml_get_active_languages')) {
        $active = ultracache_wpml_get_active_languages();
        if (!isset($active[$language])) {
            return '';
        }
    }
    return $language;
}

/**
 * Whether the private fragment declares an explicit cookie allowlist.
 *
 * @param array $definition Fragment definition.
 * @return bool
 */
function ultracache_litespeed_esi_private_definition_transport_is_declared(array $definition)
{
    return 'private' === (string) ($definition['scope'] ?? '')
        && (!empty($definition['cookie_names']) || !empty($definition['cookie_prefixes']));
}

/**
 * Whether a private LiteSpeed fragment may emit a live include.
 *
 * @param array $definition Fragment definition.
 * @return bool
 */
function ultracache_litespeed_esi_private_definition_is_enabled(array $definition)
{
    if (!ultracache_litespeed_esi_is_enabled() || 'private' !== (string) ($definition['scope'] ?? '')) {
        return false;
    }
    return !empty($definition['cookie_names']) || !empty($definition['cookie_prefixes']);
}

/**
 * Render a fallback without exposing private request state to a shared parent.
 *
 * @param Ultra_Cache_LiteSpeed_ESI_Registry $registry Registry.
 * @param array $definition Definition.
 * @param array $context Context.
 * @return string|WP_Error
 */
function ultracache_render_litespeed_esi_fragment_fallback_safely($registry, array $definition, array $context)
{
    if ('private' !== (string) ($definition['scope'] ?? '')) {
        return $registry->render($definition, $context, 'fallback');
    }

    $saved_cookies = isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array();
    $cookie_header_present = isset($_SERVER['HTTP_COOKIE']);
    $saved_cookie_header = $cookie_header_present ? ultracache_server_value('HTTP_COOKIE') : '';
    $saved_user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $_COOKIE = array();
    $_SERVER['HTTP_COOKIE'] = '';
    if (function_exists('wp_set_current_user')) {
        wp_set_current_user(0);
    }
    try {
        return $registry->render($definition, $context, 'fallback');
    } finally {
        $_COOKIE = $saved_cookies;
        if ($cookie_header_present) {
            $_SERVER['HTTP_COOKIE'] = $saved_cookie_header;
        } else {
            unset($_SERVER['HTTP_COOKIE']);
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($saved_user_id);
        }
    }
}

/** @return bool|WP_Error */
function ultracache_register_litespeed_esi_fragment($fragment_id, array $args)
{
    return Ultra_Cache_LiteSpeed_ESI_Registry::instance()->register($fragment_id, $args);
}

/** @return string|WP_Error */
function ultracache_get_litespeed_esi_fragment_url($fragment_id, array $context = array())
{
    $registry = Ultra_Cache_LiteSpeed_ESI_Registry::instance();
    $token = $registry->create_context_token($fragment_id, $context);
    if (is_wp_error($token)) {
        return $token;
    }
    return add_query_arg(array('ultracache_litespeed_esi' => $token), home_url('/'));
}

/** @return string */
function ultracache_get_litespeed_esi_fragment_markup($fragment_id, array $context = array())
{
    $registry = Ultra_Cache_LiteSpeed_ESI_Registry::instance();
    $definition = $registry->get($fragment_id);
    if (null === $definition) {
        return '';
    }

    $normalized_context = $registry->normalize_context($definition, $context);
    if (is_wp_error($normalized_context)) {
        do_action('ultracache_litespeed_esi_fragment_markup_error', $fragment_id, $normalized_context);
        return '';
    }

    $fallback = ultracache_render_litespeed_esi_fragment_fallback_safely($registry, $definition, $normalized_context);
    if (is_wp_error($fallback)) {
        do_action('ultracache_litespeed_esi_fragment_markup_error', $fragment_id, $fallback);
        return '';
    }

    $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
    $enabled = 'private' === $scope
        ? ultracache_litespeed_esi_private_definition_is_enabled($definition)
        : ultracache_litespeed_esi_is_enabled();
    if (!$enabled) {
        return (string) $fallback;
    }

    if (method_exists($registry, 'get_template_buffer_diagnostics') && method_exists($registry, 'template_buffer_started') && !$registry->template_buffer_started()) {
        $diagnostics = $registry->get_template_buffer_diagnostics();
        if (!empty($diagnostics['decision_observed'])) {
            do_action('ultracache_litespeed_esi_unbuffered_fragment_fallback', $fragment_id, $normalized_context, $definition);
            return (string) $fallback;
        }
    }

    $token = $registry->create_context_token($fragment_id, $normalized_context);
    if (is_wp_error($token)) {
        return (string) $fallback;
    }

    return '<!--ultracache-litespeed-esi-start:v1:' . $token . '-->'
        . (string) $fallback
        . '<!--ultracache-litespeed-esi-end:v1:' . $token . '-->';
}

/** @return void */
function ultracache_render_litespeed_esi_fragment($fragment_id, array $context = array())
{
    echo ultracache_get_litespeed_esi_fragment_markup($fragment_id, $context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/** @return array<string,mixed>|null */
function ultracache_get_litespeed_esi_fragment_definition($fragment_id)
{
    return Ultra_Cache_LiteSpeed_ESI_Registry::instance()->get_definition_summary($fragment_id);
}

/** @return string */
function ultracache_get_litespeed_esi_fragment_context_hash($fragment_id, array $context = array())
{
    return Ultra_Cache_LiteSpeed_ESI_Registry::instance()->get_context_hash($fragment_id, $context);
}

/** @return array|WP_Error */
function ultracache_purge_litespeed_esi_fragment($fragment_id, array $context = array())
{
    $registry = Ultra_Cache_LiteSpeed_ESI_Registry::instance();
    $definition = $registry->get($fragment_id);
    if (!is_array($definition) || 'public' !== (string) ($definition['scope'] ?? '')) {
        return new WP_Error('ultracache_litespeed_esi_fragment_not_public', __('The public LiteSpeed ESI fragment is not registered.', 'ultracache'));
    }
    $url = ultracache_get_litespeed_esi_fragment_url($fragment_id, $context);
    if (is_wp_error($url)) {
        return $url;
    }
    if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'purge_litespeed_urls')) {
        return new WP_Error('ultracache_litespeed_esi_purge_unavailable', __('LiteSpeed exact-URL purge is unavailable.', 'ultracache'));
    }
    return Ultra_Cache_WP::purge_litespeed_urls(array((string) $url), false, 'litespeed-esi-fragment');
}
