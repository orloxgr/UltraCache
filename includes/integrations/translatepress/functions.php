<?php
/**
 * Supported TranslatePress integration helpers.
 *
 * UltraCache consumes TranslatePress through its runtime singleton/components.
 * Language slugs, translated paths, and language domains are never constructed
 * locally; the TranslatePress URL converter remains authoritative.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize one TranslatePress language code without changing its case.
 *
 * TranslatePress uses provider-native codes such as en_US, so lower-casing a
 * code would change its identity and can break URL conversion.
 *
 * @param mixed $language_code Raw language code.
 * @return string
 */
function ultracache_translatepress_normalize_language_code($language_code)
{
    $language_code = trim((string) $language_code);
    if ('' === $language_code) {
        return '';
    }

    return preg_match('/^[A-Za-z0-9_-]+$/', $language_code) ? $language_code : '';
}

/**
 * Return the live TranslatePress singleton without forcing an early bootstrap.
 *
 * A negative result is deliberately not cached. TranslatePress initializes its
 * singleton on plugins_loaded priority 1, so an early caller may legitimately
 * see no runtime and a later caller in the same request may see the full API.
 *
 * @return object|null
 */
function ultracache_translatepress_get_runtime()
{
    if (!class_exists('TRP_Translate_Press', false)
        || !defined('TRP_PLUGIN_VERSION')
        || !is_callable(array('TRP_Translate_Press', 'get_trp_instance'))
    ) {
        return null;
    }

    // TRP_PLUGIN_VERSION is defined by the TranslatePress runtime constructor.
    // Requiring it prevents UltraCache from calling get_trp_instance() merely
    // because the class file was loaded before TranslatePress ran its own
    // plugins_loaded bootstrap.
    try {
        $runtime = TRP_Translate_Press::get_trp_instance();
    } catch (Throwable $throwable) {
        return null;
    }

    return is_object($runtime) && is_callable(array($runtime, 'get_component'))
        ? $runtime
        : null;
}

/**
 * Return one live TranslatePress component.
 *
 * @param string $component Component name.
 * @return object|null
 */
function ultracache_translatepress_get_component($component)
{
    $component = trim((string) $component);
    $runtime = ultracache_translatepress_get_runtime();
    if ('' === $component || !is_object($runtime)) {
        return null;
    }

    try {
        $resolved = $runtime->get_component($component);
    } catch (Throwable $throwable) {
        return null;
    }

    return is_object($resolved) ? $resolved : null;
}

/**
 * Check whether the real TranslatePress runtime contract is available.
 *
 * @return bool
 */
function ultracache_translatepress_is_active()
{
    $settings = ultracache_translatepress_get_component('settings');
    $url_converter = ultracache_translatepress_get_component('url_converter');

    return is_object($settings)
        && is_callable(array($settings, 'get_settings'))
        && is_object($url_converter)
        && is_callable(array($url_converter, 'get_url_for_language'));
}

/**
 * Return TranslatePress runtime settings through its settings component.
 *
 * @return array<string,mixed>
 */
function ultracache_translatepress_get_settings()
{
    $component = ultracache_translatepress_get_component('settings');
    if (!is_object($component) || !is_callable(array($component, 'get_settings'))) {
        return array();
    }

    try {
        $settings = $component->get_settings();
    } catch (Throwable $throwable) {
        return array();
    }

    return is_array($settings) ? $settings : array();
}

/**
 * Return normalized published TranslatePress languages keyed by native code.
 *
 * @return array<string,array<string,mixed>>
 */
function ultracache_translatepress_get_active_languages()
{
    if (!ultracache_translatepress_is_active()) {
        return array();
    }

    $settings = ultracache_translatepress_get_settings();
    $published = isset($settings['publish-languages']) && is_array($settings['publish-languages'])
        ? $settings['publish-languages']
        : array();

    $current = ultracache_translatepress_get_current_language();
    $normalized = array();
    foreach ($published as $language_code) {
        $language_code = ultracache_translatepress_normalize_language_code($language_code);
        if ('' === $language_code || isset($normalized[$language_code])) {
            continue;
        }

        $normalized[$language_code] = array(
            'code'     => $language_code,
            'url'      => '',
            'active'   => $language_code === $current,
            'provider' => 'translatepress',
        );
    }

    return $normalized;
}

/**
 * Return display names for published TranslatePress languages using its
 * public languages component. Provider-native language codes remain the keys.
 *
 * @param array<int,string> $language_codes Provider-native language codes.
 * @return array<string,string>
 */
function ultracache_translatepress_get_language_names(array $language_codes = array())
{
    if (!ultracache_translatepress_is_active()) {
        return array();
    }

    if (empty($language_codes)) {
        $language_codes = array_keys(ultracache_translatepress_get_active_languages());
    }

    $language_codes = array_values(array_unique(array_filter(array_map(
        'ultracache_translatepress_normalize_language_code',
        $language_codes
    ))));
    if (empty($language_codes)) {
        return array();
    }

    $languages = ultracache_translatepress_get_component('languages');
    if (!is_object($languages) || !is_callable(array($languages, 'get_language_names'))) {
        return array_fill_keys($language_codes, '');
    }

    try {
        $names = $languages->get_language_names($language_codes);
    } catch (Throwable $throwable) {
        $names = array();
    }

    $result = array();
    foreach ($language_codes as $language_code) {
        $name = is_array($names) && isset($names[$language_code])
            ? trim((string) $names[$language_code])
            : '';
        $result[$language_code] = $name;
    }

    return $result;
}

/**
 * Return published TranslatePress codes with the default language first.
 *
 * @return array<int,string>
 */
function ultracache_translatepress_get_active_language_codes()
{
    $languages = array_keys(ultracache_translatepress_get_active_languages());
    if (empty($languages)) {
        return array();
    }

    $default = ultracache_translatepress_get_default_language();
    if ('' !== $default && in_array($default, $languages, true)) {
        $languages = array_values(array_diff($languages, array($default)));
        array_unshift($languages, $default);
    }

    return $languages;
}

/**
 * Return the configured default TranslatePress language.
 *
 * @return string
 */
function ultracache_translatepress_get_default_language()
{
    if (!ultracache_translatepress_is_active()) {
        return '';
    }

    $settings = ultracache_translatepress_get_settings();
    $default = ultracache_translatepress_normalize_language_code($settings['default-language'] ?? '');
    if ('' === $default) {
        return '';
    }

    $published = isset($settings['publish-languages']) && is_array($settings['publish-languages'])
        ? $settings['publish-languages']
        : array();

    return in_array($default, $published, true) ? $default : '';
}

/**
 * Return the current TranslatePress frontend language when already resolved.
 *
 * The global is the runtime language value used by TranslatePress itself. This
 * helper does not infer a language when the current request has not resolved one.
 *
 * @return string
 */
function ultracache_translatepress_get_current_language()
{
    if (!ultracache_translatepress_is_active()) {
        return '';
    }

    global $TRP_LANGUAGE;
    $current = ultracache_translatepress_normalize_language_code($TRP_LANGUAGE ?? '');
    if ('' === $current) {
        return '';
    }

    $settings = ultracache_translatepress_get_settings();
    $published = isset($settings['publish-languages']) && is_array($settings['publish-languages'])
        ? $settings['publish-languages']
        : array();

    return in_array($current, $published, true) ? $current : '';
}

/**
 * Resolve one public URL for a published TranslatePress language.
 *
 * @param string $url           Public URL.
 * @param string $language_code Provider-native language code.
 * @return string
 */
function ultracache_translatepress_translate_url($url, $language_code)
{
    $url = trim((string) $url);
    $language_code = ultracache_translatepress_normalize_language_code($language_code);
    if ('' === $url || '' === $language_code || !ultracache_translatepress_is_active()) {
        return $url;
    }

    if (!in_array($language_code, ultracache_translatepress_get_active_language_codes(), true)) {
        return $url;
    }

    $url_converter = ultracache_translatepress_get_component('url_converter');
    if (!is_object($url_converter) || !is_callable(array($url_converter, 'get_url_for_language'))) {
        return $url;
    }

    try {
        $translated = $url_converter->get_url_for_language($language_code, $url, '');
    } catch (Throwable $throwable) {
        return $url;
    }

    $translated = is_string($translated) ? trim($translated) : '';
    if ('' === $translated) {
        return $url;
    }

    return function_exists('esc_url_raw')
        ? (string) esc_url_raw($translated, array('http', 'https'))
        : $translated;
}

/**
 * Return each published TranslatePress language's canonical public home URL.
 *
 * @return array<string,string>
 */
function ultracache_translatepress_get_language_home_urls()
{
    if (!ultracache_translatepress_is_active()) {
        return array();
    }

    $base = function_exists('ultracache_get_configured_site_base')
        ? ultracache_get_configured_site_base()
        : '';
    if ('' === $base && function_exists('get_option')) {
        $base = trim((string) get_option('home'));
    }
    if ('' === $base) {
        return array();
    }

    $urls = array();
    foreach (ultracache_translatepress_get_active_language_codes() as $language_code) {
        $translated = ultracache_translatepress_translate_url($base, $language_code);
        if ('' === $translated) {
            continue;
        }

        $parts = wp_parse_url($translated);
        if (!is_array($parts) || empty($parts['host'])) {
            continue;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, array('http', 'https'), true)) {
            continue;
        }

        $urls[$language_code] = $translated;
    }

    return $urls;
}

/**
 * Return the published language explicitly encoded by one TranslatePress URL.
 *
 * The TranslatePress URL converter is authoritative for explicit language
 * slugs/domains. When the default language intentionally lives at the provider-
 * resolved root, the default home authority supplies the bounded fallback.
 *
 * @param string $url Public frontend URL.
 * @return string
 */
function ultracache_translatepress_get_public_url_language($url)
{
    $url = trim((string) $url);
    if ('' === $url || !ultracache_translatepress_is_active()) {
        return '';
    }

    $url = function_exists('esc_url_raw')
        ? (string) esc_url_raw($url, array('http', 'https'))
        : $url;
    if ('' === $url) {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $active_codes = ultracache_translatepress_get_active_language_codes();
    if (empty($active_codes)) {
        return '';
    }

    $url_converter = ultracache_translatepress_get_component('url_converter');
    if (is_object($url_converter) && is_callable(array($url_converter, 'get_lang_from_url_string'))) {
        try {
            $language = $url_converter->get_lang_from_url_string($url);
        } catch (Throwable $throwable) {
            $language = '';
        }

        $language = ultracache_translatepress_normalize_language_code($language);
        if ('' !== $language && in_array($language, $active_codes, true)) {
            return $language;
        }
    }

    // TranslatePress intentionally returns no language slug for a default
    // language that lives at the site root. In that one bounded case, prove
    // the default language from the provider-resolved default home authority.
    $default = ultracache_translatepress_get_default_language();
    $homes = ultracache_translatepress_get_language_home_urls();
    $default_home = '' !== $default && isset($homes[$default]) ? (string) $homes[$default] : '';
    if ('' === $default_home) {
        return '';
    }

    $home_parts = wp_parse_url($default_home);
    if (!is_array($home_parts) || empty($home_parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : (('https' === $scheme) ? 443 : 80);
    $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));
    $home_host = strtolower(rtrim((string) ($home_parts['host'] ?? ''), '.'));
    $home_port = isset($home_parts['port']) ? (int) $home_parts['port'] : (('https' === $home_scheme) ? 443 : 80);
    $home_path = isset($home_parts['path']) && '' !== (string) $home_parts['path'] ? (string) $home_parts['path'] : '/';
    $path = isset($parts['path']) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
    if ('/' !== $home_path && '/' !== substr($home_path, -1)) {
        $home_path .= '/';
    }
    if ('/' !== $path && '/' !== substr($path, -1)) {
        $path .= '/';
    }

    if ($host === $home_host
        && $port === $home_port
        && ('/' === $home_path || 0 === strpos($path, $home_path))
    ) {
        return $default;
    }

    return '';
}
