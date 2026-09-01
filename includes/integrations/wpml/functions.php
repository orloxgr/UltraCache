<?php
/**
 * Supported WPML integration helpers.
 *
 * UltraCache intentionally uses WPML's public hooks here instead of internal
 * WPML classes, tables, or language-path assumptions. The adapter is small on
 * purpose: cache subsystems can ask for language semantics without learning
 * WPML implementation details.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check whether WPML's public language API is available for this request.
 *
 * The result is deliberately not cached because plugin load order can make the
 * hooks unavailable early in bootstrap and available later in the same request.
 *
 * @return bool
 */
function ultracache_wpml_is_active()
{
    if (!function_exists('has_filter')) {
        return false;
    }

    // TranslatePress and other plugins can expose isolated WPML-named
    // compatibility hooks. WPML detection therefore requires the coherent
    // public topology contract rather than any single callback.
    $required_hooks = array(
        'wpml_default_language',
        'wpml_active_languages',
        'wpml_setting',
    );
    $public_contract = true;
    foreach ($required_hooks as $hook) {
        if (false === has_filter($hook)) {
            $public_contract = false;
            break;
        }
    }
    if ($public_contract) {
        return true;
    }

    // A completed SitePress bootstrap is also positive evidence, but keep the
    // check strict enough that a third-party action named wpml_loaded cannot
    // impersonate WPML on its own. Negative results are intentionally uncached.
    return function_exists('did_action')
        && did_action('wpml_loaded') > 0
        && defined('ICL_SITEPRESS_VERSION')
        && class_exists('SitePress', false);
}

/**
 * Normalize a WPML language code without inventing one.
 *
 * @param mixed $language_code Raw language code.
 * @return string
 */
function ultracache_wpml_normalize_language_code($language_code)
{
    $language_code = strtolower(trim((string) $language_code));
    if ('' === $language_code) {
        return '';
    }

    return preg_match('/^[a-z0-9_-]+$/', $language_code) ? $language_code : '';
}

/**
 * Return the current WPML language code.
 *
 * @return string
 */
function ultracache_wpml_get_current_language()
{
    if (!ultracache_wpml_is_active()) {
        return '';
    }

    return ultracache_wpml_normalize_language_code(apply_filters('wpml_current_language', null));
}

/**
 * Return the default WPML language code.
 *
 * @return string
 */
function ultracache_wpml_get_default_language()
{
    if (!ultracache_wpml_is_active()) {
        return '';
    }

    return ultracache_wpml_normalize_language_code(apply_filters('wpml_default_language', null));
}

/**
 * Return active WPML language codes in deterministic warm/discovery order.
 *
 * The default language is placed first when it is active; all remaining
 * languages retain the adapter's stable code ordering.
 *
 * @return array<int,string>
 */
function ultracache_wpml_get_active_language_codes()
{
    $languages = array_keys(ultracache_wpml_get_active_languages());
    $languages = array_values(array_unique(array_filter(array_map('ultracache_wpml_normalize_language_code', $languages))));
    if (empty($languages)) {
        return array();
    }

    $default = ultracache_wpml_get_default_language();
    if ('' !== $default && in_array($default, $languages, true)) {
        $languages = array_values(array_diff($languages, array($default)));
        array_unshift($languages, $default);
    }

    return $languages;
}

/**
 * Return whether WPML's contextual active-language switcher API is ready.
 *
 * WPML documents wpml_active_languages as a post-`wp` API because it depends on
 * the instantiated global query. REST, cron, admin, and early bootstrap code
 * must therefore consume the last proven topology instead of invoking the
 * contextual switcher filter prematurely.
 *
 * @return bool
 */
function ultracache_wpml_can_query_active_languages()
{
    return function_exists('did_action') && did_action('wp') > 0;
}

/**
 * Return the last successfully applied WPML topology snapshot.
 *
 * Provider-neutral state is authoritative when available; the legacy WPML
 * topology remains a read-only migration fallback.
 *
 * @return array<string,mixed>
 */
function ultracache_wpml_get_persisted_topology_snapshot()
{
    if (!function_exists('get_option')) {
        return array();
    }

    foreach (array('ultracache_multilingual_topology_v1', 'ultracache_wpml_topology_v1') as $option_key) {
        $store = get_option($option_key, array());
        $store = is_array($store) ? $store : array();
        $snapshot = isset($store['appliedSnapshot']) && is_array($store['appliedSnapshot'])
            ? $store['appliedSnapshot']
            : array();
        if (empty($snapshot['ready'])) {
            continue;
        }
        if (isset($snapshot['provider']) && 'wpml' !== (string) $snapshot['provider']) {
            continue;
        }
        if (isset($snapshot['wpmlActive']) && empty($snapshot['wpmlActive'])) {
            continue;
        }

        $languages = isset($snapshot['activeLanguages']) && is_array($snapshot['activeLanguages'])
            ? array_values($snapshot['activeLanguages'])
            : array();
        $homes = isset($snapshot['languageHomes']) && is_array($snapshot['languageHomes'])
            ? $snapshot['languageHomes']
            : array();
        if (!empty($languages) && !empty($homes)) {
            return $snapshot;
        }
    }

    return array();
}

/**
 * Rebuild minimal active-language data from the last proven topology.
 *
 * @return array<string,array<string,mixed>>
 */
function ultracache_wpml_get_persisted_active_languages()
{
    $snapshot = ultracache_wpml_get_persisted_topology_snapshot();
    if (empty($snapshot)) {
        return array();
    }

    $homes = isset($snapshot['languageHomes']) && is_array($snapshot['languageHomes'])
        ? $snapshot['languageHomes']
        : array();
    $default = ultracache_wpml_normalize_language_code($snapshot['defaultLanguage'] ?? '');
    $current = ultracache_wpml_get_current_language();
    $languages = array();

    foreach ((array) ($snapshot['activeLanguages'] ?? array()) as $code) {
        $code = ultracache_wpml_normalize_language_code($code);
        $url = isset($homes[$code]) ? ultracache_wpml_normalize_public_url($homes[$code]) : '';
        if ('' === $code || '' === $url) {
            continue;
        }
        $display_name = apply_filters('wpml_translated_language_name', null, $code, '' !== $default ? $default : false);
        $display_name = is_string($display_name) ? trim($display_name) : '';
        $languages[$code] = array(
            'code'            => $code,
            'url'             => $url,
            'active'          => '' !== $current ? $code === $current : $code === $default,
            'native_name'     => $display_name,
            'translated_name' => $display_name,
        );
    }

    ksort($languages, SORT_STRING);
    return $languages;
}

/**
 * Return the WPML language assigned to one WordPress element.
 *
 * This uses only WPML's public wpml_element_language_code filter. The element
 * type is the normal WordPress post type or taxonomy name; WPML performs its
 * own public element-type conversion.
 *
 * @param int    $element_id   Post/term ID.
 * @param string $element_type Post type or taxonomy.
 * @return string
 */
function ultracache_wpml_get_element_language_code($element_id, $element_type)
{
    $element_id = absint($element_id);
    $element_type = sanitize_key((string) $element_type);
    if ($element_id < 1 || '' === $element_type || !ultracache_wpml_is_active()) {
        return '';
    }

    $language = apply_filters(
        'wpml_element_language_code',
        null,
        array(
            'element_id'   => $element_id,
            'element_type' => $element_type,
        )
    );
    $language = ultracache_wpml_normalize_language_code($language);
    if ('' === $language) {
        return '';
    }

    $active = ultracache_wpml_get_active_languages();
    return isset($active[$language]) ? $language : '';
}

/**
 * Execute one bounded callback in a requested WPML frontend language context.
 *
 * Language switching is performed exclusively through WPML's public
 * wpml_switch_language action and the original context is restored in finally.
 * Invalid/inactive language codes fail closed and execute without switching.
 *
 * @param string   $language_code Requested active language code.
 * @param callable $callback      Callback executed in that language context.
 * @return mixed
 */
function ultracache_wpml_run_in_language($language_code, callable $callback)
{
    $language_code = ultracache_wpml_normalize_language_code($language_code);
    if ('' === $language_code || !ultracache_wpml_is_active()) {
        return call_user_func($callback);
    }

    $active = ultracache_wpml_get_active_languages();
    if (!isset($active[$language_code]) || !function_exists('do_action')) {
        return call_user_func($callback);
    }

    $previous = ultracache_wpml_get_current_language();
    if ($previous === $language_code) {
        return call_user_func($callback);
    }

    do_action('wpml_switch_language', $language_code);
    try {
        return call_user_func($callback);
    } finally {
        do_action('wpml_switch_language', '' !== $previous ? $previous : null);
    }
}

/**
 * Return normalized active WPML languages keyed by language code.
 *
 * Only public data returned by the wpml_active_languages hook is retained.
 * Unknown/malformed entries are ignored rather than guessed.
 *
 * @return array<string,array<string,mixed>>
 */
function ultracache_wpml_get_active_languages()
{
    if (!ultracache_wpml_is_active()) {
        return array();
    }

    if (!ultracache_wpml_can_query_active_languages()) {
        return ultracache_wpml_get_persisted_active_languages();
    }

    // Dashboard/REST/CLI topology evaluation can ask for the same language set
    // many times while building policies and diagnostics. WPML's public
    // wpml_active_languages filter performs language-switcher/query work, so
    // memoize only successful REST/CLI results for this request. Normal
    // frontend requests remain uncached because switcher URLs are contextual to
    // the current element.
    static $neutral_cache = null;
    $memoize = ultracache_wpml_should_memoize_active_languages();
    if ($memoize && is_array($neutral_cache) && !empty($neutral_cache)) {
        return $neutral_cache;
    }

    $languages = apply_filters(
        'wpml_active_languages',
        null,
        array(
            'skip_missing' => 0,
            'orderby'      => 'code',
            'order'        => 'asc',
        )
    );

    if (!is_array($languages)) {
        return ultracache_wpml_get_persisted_active_languages();
    }

    $normalized = array();
    foreach ($languages as $key => $language) {
        if (!is_array($language)) {
            continue;
        }

        $code = ultracache_wpml_normalize_language_code(
            $language['language_code'] ?? ($language['code'] ?? $key)
        );
        if ('' === $code) {
            continue;
        }

        $url = isset($language['url']) ? trim((string) $language['url']) : '';
        if ('' !== $url && function_exists('esc_url_raw')) {
            $url = (string) esc_url_raw($url, array('http', 'https'));
        }

        $normalized[$code] = array(
            'code'         => $code,
            'url'          => $url,
            'active'       => !empty($language['active']),
            'native_name'  => isset($language['native_name']) ? (string) $language['native_name'] : '',
            'translated_name' => isset($language['translated_name']) ? (string) $language['translated_name'] : '',
        );
    }

    ksort($normalized, SORT_STRING);
    if ($memoize && !empty($normalized)) {
        $neutral_cache = $normalized;
    }

    return !empty($normalized) ? $normalized : ultracache_wpml_get_persisted_active_languages();
}

/**
 * Return WPML's language URL negotiation mode as a stable semantic name.
 *
 * @return string directory|domain|parameter|unknown
 */
function ultracache_wpml_get_negotiation_type()
{
    if (!ultracache_wpml_is_active()) {
        return 'unknown';
    }

    $type = (int) apply_filters('wpml_setting', 0, 'language_negotiation_type');
    if (1 === $type) {
        return 'directory';
    }
    if (2 === $type) {
        return 'domain';
    }
    if (3 === $type) {
        return 'parameter';
    }

    return 'unknown';
}

/**
 * Convert one public URL to a requested WPML language using the public API.
 *
 * @param string $url           Public URL.
 * @param string $language_code WPML language code.
 * @return string
 */
function ultracache_wpml_translate_url($url, $language_code)
{
    $url = trim((string) $url);
    $language_code = ultracache_wpml_normalize_language_code($language_code);
    if ('' === $url || '' === $language_code || !ultracache_wpml_is_active()) {
        return $url;
    }

    $translated = apply_filters('wpml_permalink', $url, $language_code, true);
    $translated = is_string($translated) ? trim($translated) : '';
    if ('' === $translated) {
        return $url;
    }

    return function_exists('esc_url_raw')
        ? (string) esc_url_raw($translated, array('http', 'https'))
        : $translated;
}

/**
 * Convert one already-generated WordPress URL to WPML's requested URL route.
 *
 * Unlike ultracache_wpml_translate_url(), this deliberately leaves WPML full
 * resolution disabled. Warm discovery generates object URLs through WordPress
 * in the target language context, so it only needs directory/domain/parameter
 * routing and must not pay the hard-coded URL reverse-resolution cost.
 *
 * @param string $url           Public URL.
 * @param string $language_code WPML language code.
 * @return string
 */
function ultracache_wpml_translate_generated_url($url, $language_code)
{
    $url = trim((string) $url);
    $language_code = ultracache_wpml_normalize_language_code($language_code);
    if ('' === $url || '' === $language_code || !ultracache_wpml_is_active()) {
        return $url;
    }

    $translated = apply_filters('wpml_permalink', $url, $language_code, false);
    $translated = is_string($translated) ? trim($translated) : '';
    if ('' === $translated) {
        return $url;
    }

    return function_exists('esc_url_raw')
        ? (string) esc_url_raw($translated, array('http', 'https'))
        : $translated;
}

/**
 * Resolve one translated WordPress object ID through WPML's public API.
 *
 * Missing translations fail closed instead of returning the source object.
 *
 * @param int    $element_id   Source post/term ID.
 * @param string $element_type WordPress post type or taxonomy key.
 * @param string $language_code Target WPML language.
 * @return int
 */
function ultracache_wpml_get_translated_object_id($element_id, $element_type, $language_code)
{
    $element_id = absint($element_id);
    $element_type = sanitize_key((string) $element_type);
    $language_code = ultracache_wpml_normalize_language_code($language_code);
    if ($element_id < 1 || '' === $element_type || '' === $language_code || !ultracache_wpml_is_active()) {
        return 0;
    }

    $translated_id = apply_filters('wpml_object_id', $element_id, $element_type, false, $language_code);
    return absint($translated_id);
}

/**
 * Return whether active-language data may be memoized for this request.
 *
 * Restrict memoization to read-oriented REST/CLI contexts. Admin/AJAX/cron
 * requests can mutate multilingual configuration during the request and must
 * remain able to observe the new language set at the late reconciliation pass.
 *
 * @return bool
 */
function ultracache_wpml_should_memoize_active_languages()
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    return defined('REST_REQUEST') && REST_REQUEST;
}

/**
 * Normalize one WPML-supplied public URL without inventing routing semantics.
 *
 * @param mixed $url Raw public URL.
 * @return string
 */
function ultracache_wpml_normalize_public_url($url)
{
    $url = trim((string) $url);
    if ('' === $url) {
        return '';
    }

    if (function_exists('esc_url_raw')) {
        $url = (string) esc_url_raw($url, array('http', 'https'));
    }
    if ('' === $url) {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
        return '';
    }

    return $url;
}

/**
 * Return persisted, already-proven WPML language homes when available.
 *
 * Frontend requests must not reinterpret wpml_active_languages switcher URLs as
 * canonical language roots because those URLs follow the current element. The
 * last applied provider-neutral topology is therefore the stable frontend
 * fallback when WPML's current-language home filter cannot resolve a root.
 *
 * @return array<string,string>
 */
function ultracache_wpml_get_persisted_language_home_urls()
{
    if (!function_exists('get_option')) {
        return array();
    }

    $stores = array(
        get_option('ultracache_multilingual_topology_v1', array()),
        get_option('ultracache_wpml_topology_v1', array()),
    );

    foreach ($stores as $store) {
        if (!is_array($store)) {
            continue;
        }
        $snapshot = isset($store['appliedSnapshot']) && is_array($store['appliedSnapshot'])
            ? $store['appliedSnapshot']
            : array();
        if (empty($snapshot['ready'])) {
            continue;
        }
        if (isset($snapshot['provider']) && 'wpml' !== (string) $snapshot['provider']) {
            continue;
        }
        if (isset($snapshot['wpmlActive']) && empty($snapshot['wpmlActive'])) {
            continue;
        }

        $homes = isset($snapshot['languageHomes']) && is_array($snapshot['languageHomes'])
            ? $snapshot['languageHomes']
            : array();
        $normalized = array();
        foreach ($homes as $code => $url) {
            $code = ultracache_wpml_normalize_language_code($code);
            $url = ultracache_wpml_normalize_public_url($url);
            if ('' !== $code && '' !== $url) {
                $normalized[$code] = $url;
            }
        }
        if (!empty($normalized)) {
            return $normalized;
        }
    }

    return array();
}

/**
 * Return each active WPML language's public home URL.
 *
 * Resolution stays entirely on WPML's supported public hooks. Live discovery
 * runs only after the documented post-`wp` active-language lifecycle point and
 * converts the neutral WordPress home through WPML's route-only permalink API.
 * REST, cron, admin, CLI, and other early contexts consume only the last
 * successfully applied persistent topology and never reinterpret contextual
 * switcher URLs as language roots.
 *
 * @return array<string,string> Language code => public home URL.
 */
function ultracache_wpml_get_language_home_urls()
{
    if (!ultracache_wpml_is_active()) {
        return array();
    }

    $persisted = ultracache_wpml_get_persisted_language_home_urls();
    if (!ultracache_wpml_can_query_active_languages()) {
        return $persisted;
    }

    $active = ultracache_wpml_get_active_languages();
    if (empty($active)) {
        return $persisted;
    }

    $base = function_exists('get_option') ? trim((string) get_option('home')) : '';
    if ('' === $base && function_exists('ultracache_get_configured_site_base')) {
        $base = trim((string) ultracache_get_configured_site_base());
    }
    if ('' === $base && function_exists('home_url')) {
        $base = trim((string) home_url('/'));
    }
    $base = ultracache_wpml_normalize_public_url($base);
    if ('' === $base) {
        return $persisted;
    }
    $base = rtrim($base, '/') . '/';

    $default = ultracache_wpml_get_default_language();
    $urls = array();
    foreach ($active as $code => $language) {
        unset($language);
        $code = ultracache_wpml_normalize_language_code($code);
        if ('' === $code) {
            continue;
        }

        $resolved = ultracache_wpml_normalize_public_url(
            ultracache_wpml_translate_generated_url($base, $code)
        );
        if (
            '' !== $resolved
            && $code !== $default
            && rtrim($resolved, '/') === rtrim($base, '/')
            && isset($persisted[$code])
        ) {
            $resolved = ultracache_wpml_normalize_public_url($persisted[$code]);
        }
        if ('' === $resolved && isset($persisted[$code])) {
            $resolved = ultracache_wpml_normalize_public_url($persisted[$code]);
        }
        if ('' !== $resolved) {
            $urls[$code] = $resolved;
        }
    }

    return !empty($urls) ? $urls : $persisted;
}

/**
 * Resolve the active WPML language represented by one public frontend URL.
 *
 * This is a diagnostic/classification helper only. It never rewrites the URL
 * and it uses only the public WPML topology already exposed by this adapter.
 * Directory mode matches the longest language-home path first, domain mode
 * matches the language origin, and parameter mode honors only an exact active
 * `lang` scalar before falling back to the default-language public home.
 *
 * @param string $url Public frontend URL.
 * @return string Active WPML language code, or an empty string when unproven.
 */
function ultracache_wpml_get_public_url_language($url)
{
    $url = trim((string) $url);
    if ('' === $url || !ultracache_wpml_is_active()) {
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

    $active = ultracache_wpml_get_active_languages();
    if (empty($active)) {
        return '';
    }

    $mode = ultracache_wpml_get_negotiation_type();
    if ('parameter' === $mode && !empty($parts['query'])) {
        $query = array();
        parse_str((string) $parts['query'], $query);
        if (array_key_exists('lang', $query)) {
            if (!is_scalar($query['lang'])) {
                return '';
            }
            $raw_language = (string) $query['lang'];
            $language = ultracache_wpml_normalize_language_code($raw_language);
            if ('' !== $language && $raw_language === $language && isset($active[$language])) {
                return $language;
            }
            return '';
        }
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : (('https' === $scheme) ? 443 : 80);
    $path = isset($parts['path']) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
    if ('/' !== $path && '/' !== substr($path, -1)) {
        $path .= '/';
    }

    $matches = array();
    foreach (ultracache_wpml_get_language_home_urls() as $language => $home_url) {
        $language = ultracache_wpml_normalize_language_code($language);
        if ('' === $language || !isset($active[$language])) {
            continue;
        }

        $home_parts = wp_parse_url($home_url);
        if (!is_array($home_parts) || empty($home_parts['host'])) {
            continue;
        }

        $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));
        $home_host = strtolower(rtrim((string) ($home_parts['host'] ?? ''), '.'));
        $home_port = isset($home_parts['port']) ? (int) $home_parts['port'] : (('https' === $home_scheme) ? 443 : 80);
        if ($host !== $home_host || $port !== $home_port) {
            continue;
        }

        if ('domain' === $mode) {
            $matches[$language] = PHP_INT_MAX;
            continue;
        }

        $home_path = isset($home_parts['path']) && '' !== (string) $home_parts['path'] ? (string) $home_parts['path'] : '/';
        if ('/' !== $home_path && '/' !== substr($home_path, -1)) {
            $home_path .= '/';
        }
        if ('/' === $home_path || 0 === strpos($path, $home_path)) {
            $matches[$language] = strlen($home_path);
        }
    }

    if (!empty($matches)) {
        arsort($matches, SORT_NUMERIC);
        $top_score = reset($matches);
        $top = array_keys(array_filter($matches, static function ($score) use ($top_score) {
            return $score === $top_score;
        }));
        if (1 === count($top)) {
            return (string) $top[0];
        }
    }

    $default = ultracache_wpml_get_default_language();
    if ('' === $default || !isset($active[$default])) {
        return '';
    }

    $homes = ultracache_wpml_get_language_home_urls();
    $default_home = isset($homes[$default]) ? (string) $homes[$default] : '';
    $default_parts = '' !== $default_home
        ? (wp_parse_url($default_home))
        : array();
    if (is_array($default_parts) && !empty($default_parts['host'])) {
        $default_scheme = strtolower((string) ($default_parts['scheme'] ?? ''));
        $default_host = strtolower(rtrim((string) $default_parts['host'], '.'));
        $default_port = isset($default_parts['port']) ? (int) $default_parts['port'] : (('https' === $default_scheme) ? 443 : 80);
        if ($host === $default_host && $port === $default_port) {
            return $default;
        }
    }

    return '';
}

/**
 * Return the normalized cache contract for WPML's query-parameter language
 * negotiation mode.
 *
 * The early advanced-cache drop-in cannot call WPML APIs, so the current public
 * language topology is projected into a small secret-free runtime contract.
 * The contract is enabled only when WPML is active, parameter negotiation is
 * active, and at least one active language can be proven through WPML's public
 * hooks.
 *
 * @return array<string,mixed>
 */
function ultracache_wpml_get_parameter_cache_contract()
{
    $languages = array();
    if (ultracache_wpml_is_active() && 'parameter' === ultracache_wpml_get_negotiation_type()) {
        $languages = array_keys(ultracache_wpml_get_active_languages());
        $languages = array_values(array_unique(array_filter(array_map('ultracache_wpml_normalize_language_code', $languages))));
        sort($languages, SORT_STRING);
    }

    $default_language = ultracache_wpml_get_default_language();
    if ('' !== $default_language && !in_array($default_language, $languages, true)) {
        $default_language = '';
    }

    $enabled = !empty($languages);
    $fingerprint_material = implode("\n", array(
        'ultracache-wpml-parameter-cache-v1',
        $enabled ? '1' : '0',
        'lang',
        implode(',', $languages),
        $default_language,
    ));

    return array(
        'version'          => 1,
        'enabled'          => $enabled,
        'query_key'        => 'lang',
        'languages'        => $languages,
        'default_language' => $default_language,
        'fingerprint'      => hash('sha256', $fingerprint_material),
    );
}

/**
 * Normalize an embedded WPML parameter-cache contract.
 *
 * @param mixed $contract Candidate contract.
 * @return array<string,mixed>
 */
function ultracache_wpml_normalize_parameter_cache_contract($contract)
{
    $contract = is_array($contract) ? $contract : array();
    $languages = array();
    foreach ((array) ($contract['languages'] ?? array()) as $language_code) {
        $language_code = ultracache_wpml_normalize_language_code($language_code);
        if ('' !== $language_code) {
            $languages[$language_code] = true;
        }
    }
    $languages = array_keys($languages);
    sort($languages, SORT_STRING);

    $default_language = ultracache_wpml_normalize_language_code($contract['default_language'] ?? '');
    if ('' !== $default_language && !in_array($default_language, $languages, true)) {
        $default_language = '';
    }

    $query_key = strtolower(trim((string) ($contract['query_key'] ?? 'lang')));
    $query_key = preg_replace('/[^a-z0-9_-]/', '', $query_key);
    $query_key = is_string($query_key) ? $query_key : '';
    if ('lang' !== $query_key) {
        $query_key = 'lang';
    }

    $enabled = !empty($contract['enabled']) && !empty($languages);
    $fingerprint_material = implode("\n", array(
        'ultracache-wpml-parameter-cache-v1',
        $enabled ? '1' : '0',
        $query_key,
        implode(',', $languages),
        $default_language,
    ));

    return array(
        'version'          => 1,
        'enabled'          => $enabled,
        'query_key'        => $query_key,
        'languages'        => $languages,
        'default_language' => $default_language,
        'fingerprint'      => hash('sha256', $fingerprint_material),
    );
}

/**
 * Return the persistent option key for the observed/applied WPML public topology.
 *
 * @return string
 */
function ultracache_wpml_topology_option_key()
{
    return 'ultracache_wpml_topology_v1';
}

/**
 * Normalize one WPML language-home URL into scheme-independent topology identity.
 *
 * Scheme is deliberately excluded from the identity. A filtered frontend
 * context may expose HTTP while the configured site is HTTPS (or vice versa),
 * but that must not make the same WPML routing topology look different. An
 * explicit non-default port remains part of the public host contract.
 *
 * @param string $url             Public language-home URL.
 * @param string $language_code   Active WPML language code.
 * @param string $negotiation_type WPML URL negotiation mode.
 * @return array<string,string>
 */
function ultracache_wpml_get_topology_url_descriptor($url, $language_code, $negotiation_type)
{
    $url = trim((string) $url);
    $language_code = ultracache_wpml_normalize_language_code($language_code);
    $negotiation_type = sanitize_key((string) $negotiation_type);
    if ('' === $url || '' === $language_code) {
        return array();
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return array();
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    if ('' === $host) {
        return array();
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    $host_port = $host . ($port > 0 ? ':' . $port : '');

    $path = isset($parts['path']) ? (string) $parts['path'] : '/';
    if ('' === $path) {
        $path = '/';
    }
    if ('/' !== $path[0]) {
        $path = '/' . $path;
    }
    $path = preg_replace('#/+#', '/', $path);
    $path = is_string($path) && '' !== $path ? $path : '/';
    if ('/' !== $path && '/' !== substr($path, -1)) {
        $path .= '/';
    }

    $parameter = '';
    if ('parameter' === $negotiation_type) {
        $query = array();
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        if (isset($query['lang']) && is_scalar($query['lang'])) {
            $candidate = ultracache_wpml_normalize_language_code((string) $query['lang']);
            if ($candidate === (string) $query['lang'] && $candidate === $language_code) {
                $parameter = 'lang=' . $language_code;
            }
        }
        // Parameter-mode topology is the language identity even if WPML's
        // public permalink callback omits the query on the default language.
        if ('' === $parameter) {
            $parameter = 'lang=' . $language_code;
        }
    }

    return array(
        'host'      => $host,
        'hostPort'  => $host_port,
        'path'      => $path,
        'parameter' => $parameter,
        'identity'  => $host_port . '|' . $path . ('parameter' === $negotiation_type ? '|' . $parameter : ''),
    );
}

/**
 * Build the public WPML URL-topology snapshot used for cache-coherency changes.
 *
 * The fingerprint intentionally includes only routing semantics exposed by
 * supported WPML/WordPress APIs: active language codes, default language,
 * negotiation mode, and each public language home's host/explicit-port/path.
 * Contextual request scheme is diagnostic metadata only and cannot invalidate
 * the topology fingerprint.
 *
 * @return array<string,mixed>
 */
function ultracache_wpml_get_topology_snapshot()
{
    $active = ultracache_wpml_is_active();
    if (!$active) {
        $material = array(
            'schema'             => 1,
            'wpmlActive'         => false,
            'negotiationType'    => 'inactive',
            'defaultLanguage'    => '',
            'activeLanguages'    => array(),
            'languageIdentities' => array(),
        );

        return array(
            'schemaVersion'      => 1,
            'ready'              => true,
            'wpmlActive'         => false,
            'negotiationType'    => 'inactive',
            'defaultLanguage'    => '',
            'activeLanguages'    => array(),
            'languageHomes'      => array(),
            'languageTopology'   => array(),
            'configuredBase'     => function_exists('ultracache_get_configured_site_base') ? ultracache_get_configured_site_base() : '',
            'fingerprint'        => hash('sha256', wp_json_encode($material)),
        );
    }

    $mode = ultracache_wpml_get_negotiation_type();
    $languages = ultracache_wpml_get_active_language_codes();
    $default = ultracache_wpml_get_default_language();
    $homes = ultracache_wpml_get_language_home_urls();

    $ready = in_array($mode, array('directory', 'domain', 'parameter'), true)
        && !empty($languages)
        && '' !== $default
        && in_array($default, $languages, true);

    $language_topology = array();
    $language_homes = array();
    foreach ($languages as $language_code) {
        $language_code = ultracache_wpml_normalize_language_code($language_code);
        $home = isset($homes[$language_code]) ? trim((string) $homes[$language_code]) : '';
        if ('' === $language_code || '' === $home) {
            $ready = false;
            continue;
        }

        $descriptor = ultracache_wpml_get_topology_url_descriptor($home, $language_code, $mode);
        if (empty($descriptor['identity'])) {
            $ready = false;
            continue;
        }

        $language_homes[$language_code] = function_exists('esc_url_raw')
            ? (string) esc_url_raw($home, array('http', 'https'))
            : $home;
        $language_topology[$language_code] = $descriptor;
    }

    $sorted_languages = $languages;
    sort($sorted_languages, SORT_STRING);
    ksort($language_homes, SORT_STRING);
    ksort($language_topology, SORT_STRING);

    if (count($language_topology) !== count($sorted_languages)) {
        $ready = false;
    }

    // Directory/domain routing must expose an unambiguous public home identity
    // for every active language. A missing/partial WPML callback can otherwise
    // fall back to the configured base and make two languages look identical.
    if ('parameter' !== $mode && !empty($language_topology)) {
        $seen_identities = array();
        foreach ($language_topology as $descriptor) {
            $identity = (string) ($descriptor['identity'] ?? '');
            if ('' === $identity || isset($seen_identities[$identity])) {
                $ready = false;
                break;
            }
            $seen_identities[$identity] = true;
        }
    }

    $identities = array();
    foreach ($sorted_languages as $language_code) {
        if (isset($language_topology[$language_code]['identity'])) {
            $identities[$language_code] = (string) $language_topology[$language_code]['identity'];
        }
    }

    $material = array(
        'schema'             => 1,
        'wpmlActive'         => true,
        'negotiationType'    => $mode,
        'defaultLanguage'    => $default,
        'activeLanguages'    => $sorted_languages,
        'languageIdentities' => $identities,
    );

    return array(
        'schemaVersion'      => 1,
        'ready'              => (bool) $ready,
        'wpmlActive'         => true,
        'negotiationType'    => $mode,
        'defaultLanguage'    => $default,
        'activeLanguages'    => $sorted_languages,
        'languageHomes'      => $language_homes,
        'languageTopology'   => $language_topology,
        'configuredBase'     => function_exists('ultracache_get_configured_site_base') ? ultracache_get_configured_site_base() : '',
        'fingerprint'        => $ready ? hash('sha256', wp_json_encode($material)) : '',
    );
}

