<?php
/**
 * Provider-neutral multilingual facade.
 *
 * Provider-neutral multilingual contract. Provider ambiguity fails closed.
 * Warm/public-topology consumers began migrating in 3.04.33; 3.04.34 extends the
 * provider-neutral contract to strict frontend trust, native server-cache routing,
 * and diagnostics while provider-specific content semantics stay native.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the positively detected multilingual provider for this request.
 *
 * @return string none|wpml|translatepress|ambiguous
 */
function ultracache_multilingual_get_provider()
{
    $wpml = function_exists('ultracache_wpml_is_active') && ultracache_wpml_is_active();
    $translatepress = function_exists('ultracache_translatepress_is_active') && ultracache_translatepress_is_active();

    if ($wpml && $translatepress) {
        return 'ambiguous';
    }
    if ($wpml) {
        return 'wpml';
    }
    if ($translatepress) {
        return 'translatepress';
    }

    return 'none';
}

/**
 * Check whether exactly one supported multilingual provider is active.
 *
 * @return bool
 */
function ultracache_multilingual_is_active()
{
    return in_array(ultracache_multilingual_get_provider(), array('wpml', 'translatepress'), true);
}


/**
 * Normalize one language code through the selected provider without changing
 * provider-native identity semantics.
 *
 * @param mixed $language_code Raw provider language code.
 * @return string
 */
function ultracache_multilingual_normalize_language_code($language_code)
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider && function_exists('ultracache_wpml_normalize_language_code')) {
        return ultracache_wpml_normalize_language_code($language_code);
    }
    if ('translatepress' === $provider && function_exists('ultracache_translatepress_normalize_language_code')) {
        return ultracache_translatepress_normalize_language_code($language_code);
    }

    return '';
}

/**
 * Execute one callback in a provider-supported runtime language context.
 *
 * WPML exposes a real public runtime switch API. TranslatePress's supported
 * URL conversion does not require UltraCache to mutate its global render
 * language, so TranslatePress executes the callback unchanged and callers use
 * ultracache_multilingual_translate_url() for generated public URLs.
 *
 * @param string   $language_code Provider-native language code.
 * @param callable $callback      Bounded callback.
 * @return mixed
 */
function ultracache_multilingual_run_in_language($language_code, callable $callback)
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider && function_exists('ultracache_wpml_run_in_language')) {
        return ultracache_wpml_run_in_language($language_code, $callback);
    }

    return call_user_func($callback);
}

/**
 * Return the selected provider's public URL mode as a stable semantic name.
 *
 * @return string directory|domain|parameter|unknown
 */
function ultracache_multilingual_get_url_mode()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider && function_exists('ultracache_wpml_get_negotiation_type')) {
        return ultracache_wpml_get_negotiation_type();
    }

    if ('translatepress' === $provider) {
        $origins = array();
        foreach (ultracache_translatepress_get_language_home_urls() as $url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            if (in_array($scheme, array('http', 'https'), true)) {
                // URL mode is a host/vhost topology property, not a request-
                // context scheme property. Scheme-only differences must not
                // turn a directory installation into a fake domain mode.
                $origins[$host . $port] = true;
            }
        }

        return count($origins) > 1 ? 'domain' : 'directory';
    }

    return 'unknown';
}

/**
 * Return active languages from the selected provider.
 *
 * @return array<string,array<string,mixed>>
 */
function ultracache_multilingual_get_active_languages()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_active_languages();
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_active_languages();
    }

    return array();
}

/**
 * Return active language codes in provider-defined deterministic order.
 *
 * @return array<int,string>
 */
function ultracache_multilingual_get_active_language_codes()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_active_language_codes();
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_active_language_codes();
    }

    return array();
}

/**
 * Return the provider's default language code.
 *
 * @return string
 */
function ultracache_multilingual_get_default_language()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_default_language();
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_default_language();
    }

    return '';
}

/**
 * Return the provider's current frontend language code when proven.
 *
 * @return string
 */
function ultracache_multilingual_get_current_language()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_current_language();
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_current_language();
    }

    return '';
}

/**
 * Return canonical public home URLs keyed by provider-native language code.
 *
 * @return array<string,string>
 */
function ultracache_multilingual_get_language_home_urls()
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_language_home_urls();
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_language_home_urls();
    }

    return array();
}

/**
 * Translate one public URL through the selected provider.
 *
 * Ambiguous/no-provider state returns the input unchanged.
 *
 * @param string $url           Public URL.
 * @param string $language_code Provider-native language code.
 * @return string
 */
function ultracache_multilingual_translate_url($url, $language_code)
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_translate_url($url, $language_code);
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_translate_url($url, $language_code);
    }

    return (string) $url;
}

/**
 * Resolve the language explicitly represented by one public URL.
 *
 * @param string $url Public URL.
 * @return string
 */
function ultracache_multilingual_get_public_url_language($url)
{
    $provider = ultracache_multilingual_get_provider();
    if ('wpml' === $provider) {
        return ultracache_wpml_get_public_url_language($url);
    }
    if ('translatepress' === $provider) {
        return ultracache_translatepress_get_public_url_language($url);
    }

    return '';
}

/**
 * Return proven provider capabilities and content-model metadata.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_capabilities()
{
    $provider = ultracache_multilingual_get_provider();
    $capabilities = array(
        'provider'                  => $provider,
        'contentModel'              => 'none',
        'urlModes'                  => array(),
        'canTranslateUrl'           => false,
        'canSwitchRuntimeLanguage'  => false,
        'canResolveElementLanguage' => false,
        'canClassifyPublicUrl'      => false,
    );

    if ('wpml' === $provider) {
        $mode = ultracache_wpml_get_negotiation_type();
        $capabilities['contentModel'] = 'translated-objects';
        $capabilities['urlModes'] = in_array($mode, array('directory', 'domain', 'parameter'), true)
            ? array($mode)
            : array();
        $capabilities['canTranslateUrl'] = function_exists('has_filter') && false !== has_filter('wpml_permalink');
        $capabilities['canSwitchRuntimeLanguage'] = function_exists('has_action') && false !== has_action('wpml_switch_language');
        $capabilities['canResolveElementLanguage'] = function_exists('has_filter') && false !== has_filter('wpml_element_language_code');
        $capabilities['canClassifyPublicUrl'] = true;
        return $capabilities;
    }

    if ('translatepress' === $provider) {
        $mode = ultracache_multilingual_get_url_mode();
        $capabilities['contentModel'] = 'shared-object-rendering';
        $capabilities['urlModes'] = in_array($mode, array('directory', 'domain'), true)
            ? array($mode)
            : array();
        $capabilities['canTranslateUrl'] = true;
        $capabilities['canClassifyPublicUrl'] = true;
        return $capabilities;
    }

    return $capabilities;
}

/**
 * Return the persistent option key for provider-neutral multilingual topology.
 *
 * The legacy ultracache_wpml_topology_v1 option is intentionally retained
 * during the migration release so an upgrade can establish the generic state
 * without deleting the last known WPML-only baseline.
 *
 * @return string
 */
function ultracache_multilingual_topology_option_key()
{
    return 'ultracache_multilingual_topology_v1';
}

/**
 * Return a stable page-cache bootstrap contract fingerprint for the current
 * multilingual provider state.
 *
 * Only WPML parameter-mode language routing changes the early page-cache
 * query contract. Directory/domain WPML, TranslatePress, and plain WordPress
 * all share the same disabled structural-language-query contract.
 *
 * @return string
 */
function ultracache_multilingual_get_page_cache_contract_fingerprint()
{
    if (function_exists('ultracache_wpml_get_parameter_cache_contract')) {
        $contract = ultracache_wpml_get_parameter_cache_contract();
        if (is_array($contract) && !empty($contract['fingerprint'])) {
            return (string) $contract['fingerprint'];
        }
    }

    return hash('sha256', "ultracache-wpml-parameter-cache-v1\n0\nlang\n\n");
}

/**
 * Normalize one provider-resolved language-home URL into scheme-independent
 * topology identity.
 *
 * Request-context scheme is intentionally excluded. Explicit ports and public
 * paths remain part of topology identity. WPML parameter mode additionally
 * keeps the validated language routing parameter because the path itself does
 * not carry that language identity.
 *
 * @param string $url           Provider-resolved public language-home URL.
 * @param string $language_code Provider-native language code.
 * @param string $url_mode      directory|domain|parameter.
 * @param string $provider      Selected provider.
 * @return array<string,string>
 */
function ultracache_multilingual_get_topology_url_descriptor($url, $language_code, $url_mode, $provider = '')
{
    $url = trim((string) $url);
    $provider = sanitize_key((string) $provider);
    $url_mode = sanitize_key((string) $url_mode);
    $language_code = function_exists('ultracache_multilingual_normalize_language_code')
        ? ultracache_multilingual_normalize_language_code($language_code)
        : '';

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
    if ('wpml' === $provider && 'parameter' === $url_mode) {
        $query = array();
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        if (isset($query['lang']) && is_scalar($query['lang'])) {
            $candidate = function_exists('ultracache_wpml_normalize_language_code')
                ? ultracache_wpml_normalize_language_code((string) $query['lang'])
                : '';
            if ($candidate === (string) $query['lang'] && $candidate === $language_code) {
                $parameter = 'lang=' . $language_code;
            }
        }
        if ('' === $parameter) {
            $parameter = 'lang=' . $language_code;
        }
    }

    return array(
        'host'      => $host,
        'hostPort'  => $host_port,
        'path'      => $path,
        'parameter' => $parameter,
        'identity'  => $host_port . '|' . $path . ('' !== $parameter ? '|' . $parameter : ''),
    );
}

/**
 * Build the provider-qualified public multilingual topology snapshot.
 *
 * Ambiguous provider state fails closed. Plain WordPress is a valid baseline
 * with no multilingual routes. First observation is handled by the reconciler
 * as a no-purge migration baseline.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_topology_snapshot()
{
    $provider = ultracache_multilingual_get_provider();
    $configured_base = function_exists('ultracache_get_configured_site_base')
        ? ultracache_get_configured_site_base()
        : '';
    $page_cache_contract_fingerprint = ultracache_multilingual_get_page_cache_contract_fingerprint();

    if ('ambiguous' === $provider) {
        return array(
            'schemaVersion'                => 1,
            'ready'                        => false,
            'provider'                     => 'ambiguous',
            'multilingualActive'           => false,
            'urlMode'                      => 'unknown',
            'defaultLanguage'              => '',
            'activeLanguages'              => array(),
            'languageHomes'                => array(),
            'languageTopology'             => array(),
            'configuredBase'               => $configured_base,
            'pageCacheContractFingerprint' => $page_cache_contract_fingerprint,
            'fingerprint'                  => '',
        );
    }

    if ('none' === $provider) {
        $material = array(
            'schema'             => 1,
            'provider'           => 'none',
            'urlMode'            => 'inactive',
            'defaultLanguage'    => '',
            'activeLanguages'    => array(),
            'languageIdentities' => array(),
        );

        return array(
            'schemaVersion'                => 1,
            'ready'                        => true,
            'provider'                     => 'none',
            'multilingualActive'           => false,
            'urlMode'                      => 'inactive',
            'defaultLanguage'              => '',
            'activeLanguages'              => array(),
            'languageHomes'                => array(),
            'languageTopology'             => array(),
            'configuredBase'               => $configured_base,
            'pageCacheContractFingerprint' => $page_cache_contract_fingerprint,
            'fingerprint'                  => hash('sha256', wp_json_encode($material)),
        );
    }

    $url_mode = ultracache_multilingual_get_url_mode();
    $languages = ultracache_multilingual_get_active_language_codes();
    $default_language = ultracache_multilingual_get_default_language();
    $homes = ultracache_multilingual_get_language_home_urls();

    $allowed_modes = 'wpml' === $provider
        ? array('directory', 'domain', 'parameter')
        : array('directory', 'domain');
    $ready = in_array($url_mode, $allowed_modes, true)
        && !empty($languages)
        && '' !== $default_language
        && in_array($default_language, $languages, true);

    $language_topology = array();
    $language_homes = array();
    foreach ($languages as $language_code) {
        $language_code = ultracache_multilingual_normalize_language_code($language_code);
        $home = isset($homes[$language_code]) ? trim((string) $homes[$language_code]) : '';
        if ('' === $language_code || '' === $home) {
            $ready = false;
            continue;
        }

        $descriptor = ultracache_multilingual_get_topology_url_descriptor(
            $home,
            $language_code,
            $url_mode,
            $provider
        );
        if (empty($descriptor['identity'])) {
            $ready = false;
            continue;
        }

        $language_homes[$language_code] = function_exists('esc_url_raw')
            ? (string) esc_url_raw($home, array('http', 'https'))
            : $home;
        $language_topology[$language_code] = $descriptor;
    }

    $sorted_languages = array_values(array_unique($languages));
    sort($sorted_languages, SORT_STRING);
    ksort($language_homes, SORT_STRING);
    ksort($language_topology, SORT_STRING);

    if (count($language_topology) !== count($sorted_languages)) {
        $ready = false;
    }

    if ('parameter' !== $url_mode && !empty($language_topology)) {
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
        'provider'           => $provider,
        'urlMode'            => $url_mode,
        'defaultLanguage'    => $default_language,
        'activeLanguages'    => $sorted_languages,
        'languageIdentities' => $identities,
    );

    return array(
        'schemaVersion'                => 1,
        'ready'                        => (bool) $ready,
        'provider'                     => $provider,
        'multilingualActive'           => true,
        'urlMode'                      => $url_mode,
        'defaultLanguage'              => $default_language,
        'activeLanguages'              => $sorted_languages,
        'languageHomes'                => $language_homes,
        'languageTopology'             => $language_topology,
        'configuredBase'               => $configured_base,
        'pageCacheContractFingerprint' => $page_cache_contract_fingerprint,
        'fingerprint'                  => $ready ? hash('sha256', wp_json_encode($material)) : '',
    );
}

/**
 * Return whether a topology transition changes the early page-cache runtime
 * contract embedded in advanced-cache.php.
 *
 * @param array $applied_snapshot Last successfully applied topology.
 * @param array $observed_snapshot Current live topology.
 * @return bool
 */
function ultracache_multilingual_topology_requires_page_cache_bootstrap_sync(array $applied_snapshot, array $observed_snapshot)
{
    $old = (string) ($applied_snapshot['pageCacheContractFingerprint'] ?? '');
    $new = (string) ($observed_snapshot['pageCacheContractFingerprint'] ?? '');

    if ('' === $old || '' === $new) {
        return true;
    }

    return !hash_equals($old, $new);
}

/**
 * Expand public URLs across every rendering of a shared-object multilingual
 * provider such as TranslatePress.
 *
 * Providers with translated-object semantics (WPML) deliberately return the
 * input unchanged because their sibling objects must not be invalidated merely
 * because one translation changed.
 *
 * @param array<int,string> $urls Public source/current-language URLs.
 * @return array<int,string>
 */
function ultracache_multilingual_expand_shared_object_public_urls(array $urls)
{
    $capabilities = ultracache_multilingual_get_capabilities();
    if ('shared-object-rendering' !== (string) ($capabilities['contentModel'] ?? '')
        || empty($capabilities['canTranslateUrl'])
    ) {
        return array_values(array_unique(array_filter(array_map('strval', $urls))));
    }

    $languages = ultracache_multilingual_get_active_language_codes();
    if (empty($languages)) {
        return array_values(array_unique(array_filter(array_map('strval', $urls))));
    }

    $expanded = array();
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if ('' === $url) {
            continue;
        }

        foreach ($languages as $language_code) {
            $translated = ultracache_multilingual_translate_url($url, $language_code);
            $translated = trim((string) $translated);
            if ('' !== $translated) {
                $expanded[$translated] = $translated;
            }
        }
    }

    return array_values($expanded);
}


/**
 * Expand warm URLs only into languages enabled for one multilingual operation.
 *
 * Purge correctness is intentionally handled elsewhere. This helper exists
 * only for proactive warm selection on shared-object multilingual providers.
 *
 * @param array<int,string> $urls      Public source/current-language URLs.
 * @param string            $operation Multilingual warm policy operation id.
 * @return array<int,string>
 */
function ultracache_multilingual_expand_shared_object_warm_urls(array $urls, $operation)
{
    $capabilities = ultracache_multilingual_get_capabilities();
    if ('shared-object-rendering' !== (string) ($capabilities['contentModel'] ?? '')
        || empty($capabilities['canTranslateUrl'])
    ) {
        return array_values(array_unique(array_filter(array_map('strval', $urls))));
    }

    $languages = ultracache_multilingual_get_warm_languages($operation);
    if (empty($languages)) {
        return array();
    }

    $expanded = array();
    foreach ($urls as $url) {
        $url = trim((string) $url);
        if ('' === $url) {
            continue;
        }

        foreach ($languages as $language_code) {
            $translated = ultracache_multilingual_translate_url($url, $language_code);
            $translated = trim((string) $translated);
            if ('' !== $translated) {
                $expanded[$translated] = $translated;
            }
        }
    }

    return array_values($expanded);
}

/**
 * Return the canonical empty multilingual warm-policy store.
 *
 * The policy is provider-qualified so a site moving between multilingual
 * plugins never reuses a semantically unrelated language policy merely because
 * both providers expose the same language code.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_default_warm_policy_store()
{
    return array(
        'schemaVersion'    => 2,
        'migrationVersion' => 0,
        'providerPolicies' => array(),
        'providerStates'   => array(),
    );
}

/**
 * Normalize one nested multilingual policy boolean without PHP's string
 * truthiness surprises (for example, the literal string "false").
 *
 * @param mixed $value   Raw value.
 * @param bool  $default Default value.
 * @return bool
 */
function ultracache_multilingual_normalize_policy_boolean($value, $default = false)
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return 0 !== (int) $value;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (in_array($normalized, array('', '0', 'false', 'no', 'off', 'null'), true)) {
            return false;
        }
    }

    return (bool) $default;
}

/**
 * Sanitize the versioned multilingual warm-policy repository.
 *
 * 3.04.40 activates the manual Homepage/Menu/Full Site switches while later
 * roadmap versions activate automatic and CSS/source policy fields one operation
 * at a time.
 *
 * @param mixed $store Raw stored policy.
 * @return array<string,mixed>
 */
function ultracache_multilingual_sanitize_warm_policy_store($store)
{
    $clean = ultracache_multilingual_get_default_warm_policy_store();
    if (!is_array($store)) {
        return $clean;
    }

    $clean['migrationVersion'] = max(0, min(2, absint($store['migrationVersion'] ?? 0)));

    $providers = isset($store['providerPolicies']) && is_array($store['providerPolicies'])
        ? $store['providerPolicies']
        : array();
    $allowed_providers = array('wpml', 'translatepress');
    $switch_keys = array(
        'warmHomepage',
        'warmMenu',
        'warmFullSite',
        'warmScheduled',
        'warmAfterFlushAll',
        'warmAfterCleanup',
        'warmAffectedSave',
        'warmCssBundles',
        'useGlobalFullSiteSources',
    );
    $allowed_profiles = array('full', 'essential', 'on_demand', 'custom');
    $allowed_sources = array(
        'homepage',
        'menus',
        'pages',
        'posts',
        'categories',
        'tags',
        'woocommerce_products',
        'woocommerce_product_taxonomies',
        'custom_post_types',
        'custom_taxonomies',
    );

    foreach ($providers as $provider => $language_policies) {
        $provider = sanitize_key((string) $provider);
        if (!in_array($provider, $allowed_providers, true) || !is_array($language_policies)) {
            continue;
        }

        foreach ($language_policies as $language_code => $policy) {
            $language_code = trim((string) $language_code);
            if ('' === $language_code || !preg_match('/^[A-Za-z0-9_-]+$/', $language_code) || !is_array($policy)) {
                continue;
            }

            $profile = sanitize_key((string) ($policy['profile'] ?? 'on_demand'));
            if (!in_array($profile, $allowed_profiles, true)) {
                $profile = 'custom';
            }

            $language_clean = array('profile' => $profile);
            foreach ($switch_keys as $switch_key) {
                $switch_default = 'useGlobalFullSiteSources' === $switch_key ? true : false;
                $language_clean[$switch_key] = ultracache_multilingual_normalize_policy_boolean(
                    $policy[$switch_key] ?? $switch_default,
                    $switch_default
                );
            }

            $requested_sources = isset($policy['fullSiteSources']) && is_array($policy['fullSiteSources'])
                ? $policy['fullSiteSources']
                : array();
            $source_lookup = array();
            foreach ($requested_sources as $source) {
                $source = sanitize_key((string) $source);
                if (in_array($source, $allowed_sources, true)) {
                    $source_lookup[$source] = true;
                }
            }
            $language_clean['fullSiteSources'] = array_values(array_filter(
                $allowed_sources,
                static function ($source) use ($source_lookup) {
                    return isset($source_lookup[$source]);
                }
            ));

            if ('custom' !== $profile
                && function_exists('ultracache_multilingual_warm_policy_matches_profile')
                && !ultracache_multilingual_warm_policy_matches_profile($language_clean, $profile)
            ) {
                $language_clean['profile'] = 'custom';
            }

            $clean['providerPolicies'][$provider][$language_code] = $language_clean;
        }
    }

    $states = isset($store['providerStates']) && is_array($store['providerStates'])
        ? $store['providerStates']
        : array();
    foreach ($states as $provider => $state) {
        $provider = sanitize_key((string) $provider);
        if (!in_array($provider, $allowed_providers, true) || !is_array($state)) {
            continue;
        }

        $sanitize_codes = static function ($values) {
            $result = array();
            foreach (is_array($values) ? $values : array() as $value) {
                $value = trim((string) $value);
                if ('' !== $value && preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                    $result[$value] = $value;
                }
            }
            return array_values($result);
        };

        $default_language = trim((string) ($state['defaultLanguage'] ?? ''));
        if ('' !== $default_language && !preg_match('/^[A-Za-z0-9_-]+$/', $default_language)) {
            $default_language = '';
        }

        $fingerprint = trim((string) ($state['topologyFingerprint'] ?? ''));
        if ('' !== $fingerprint && !preg_match('/^[a-f0-9]{64}$/i', $fingerprint)) {
            $fingerprint = '';
        }

        $clean['providerStates'][$provider] = array(
            'initialized'         => ultracache_multilingual_normalize_policy_boolean($state['initialized'] ?? false, false),
            'knownLanguages'      => $sanitize_codes($state['knownLanguages'] ?? array()),
            'lastActiveLanguages' => $sanitize_codes($state['lastActiveLanguages'] ?? array()),
            'defaultLanguage'     => $default_language,
            'topologyFingerprint' => strtolower($fingerprint),
            'reconciledAt'        => absint($state['reconciledAt'] ?? 0),
        );
    }

    return $clean;
}

/**
 * Return the sanitized multilingual warm-policy repository from the canonical
 * UltraCache settings option.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_warm_policy_store()
{
    $settings = defined('ULTRACACHE_SETTINGS_KEY') && function_exists('get_option')
        ? get_option(ULTRACACHE_SETTINGS_KEY, array())
        : array();
    $settings = is_array($settings) ? $settings : array();

    return ultracache_multilingual_sanitize_warm_policy_store(
        $settings['multilingualWarmPolicyV1'] ?? array()
    );
}

/**
 * Return one provider-qualified stored language policy, if present.
 *
 * @param string $provider      Provider id.
 * @param string $language_code Provider-native language code.
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_stored_language_warm_policy($provider, $language_code)
{
    $provider = sanitize_key((string) $provider);
    $language_code = trim((string) $language_code);
    if (!in_array($provider, array('wpml', 'translatepress'), true)
        || '' === $language_code
        || !preg_match('/^[A-Za-z0-9_-]+$/', $language_code)
    ) {
        return array();
    }

    $store = ultracache_multilingual_get_warm_policy_store();
    return isset($store['providerPolicies'][$provider][$language_code])
        && is_array($store['providerPolicies'][$provider][$language_code])
        ? $store['providerPolicies'][$provider][$language_code]
        : array();
}


/**
 * Return the complete switch preset for one multilingual warm profile.
 *
 * Profile labels are a UI convenience only. Runtime consumers read the
 * individual switches returned by ultracache_multilingual_get_language_warm_policy().
 *
 * @param string $profile Warm profile id.
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_warm_profile_preset($profile)
{
    $profile = sanitize_key((string) $profile);
    $base = array(
        'profile'                  => $profile,
        'warmHomepage'             => false,
        'warmMenu'                 => false,
        'warmFullSite'             => false,
        'warmScheduled'            => false,
        'warmAfterFlushAll'        => false,
        'warmAfterCleanup'         => false,
        'warmAffectedSave'         => false,
        'warmCssBundles'           => false,
        'useGlobalFullSiteSources' => true,
        'fullSiteSources'          => array(),
    );

    if ('full' === $profile) {
        foreach (array(
            'warmHomepage',
            'warmMenu',
            'warmFullSite',
            'warmScheduled',
            'warmAfterFlushAll',
            'warmAfterCleanup',
            'warmAffectedSave',
            'warmCssBundles',
        ) as $key) {
            $base[$key] = true;
        }
        return $base;
    }

    if ('essential' === $profile) {
        $base['warmHomepage'] = true;
        $base['warmMenu'] = true;
        $base['warmAffectedSave'] = true;
        $base['warmCssBundles'] = true;
        return $base;
    }

    if ('on_demand' === $profile) {
        return $base;
    }

    $base['profile'] = 'custom';
    return $base;
}

/**
 * Return whether one policy exactly matches a named preset.
 *
 * Runtime never branches on the profile label. This guard only prevents the UI
 * from presenting a stale preset name when the stored switches/source mode no
 * longer match what that preset actually writes.
 *
 * @param array<string,mixed> $policy  Sanitized policy.
 * @param string              $profile Preset id.
 * @return bool
 */
function ultracache_multilingual_warm_policy_matches_profile(array $policy, $profile)
{
    $profile = sanitize_key((string) $profile);
    if (!in_array($profile, array('full', 'essential', 'on_demand'), true)) {
        return 'custom' === $profile;
    }

    $preset = ultracache_multilingual_get_warm_profile_preset($profile);
    foreach (array(
        'warmHomepage',
        'warmMenu',
        'warmFullSite',
        'warmScheduled',
        'warmAfterFlushAll',
        'warmAfterCleanup',
        'warmAffectedSave',
        'warmCssBundles',
        'useGlobalFullSiteSources',
    ) as $key) {
        if (!array_key_exists($key, $policy) || (bool) $policy[$key] !== (bool) $preset[$key]) {
            return false;
        }
    }

    $sources = isset($policy['fullSiteSources']) && is_array($policy['fullSiteSources'])
        ? array_values($policy['fullSiteSources'])
        : array();
    return $sources === array_values($preset['fullSiteSources']);
}

/**
 * Reconcile the versioned warm-policy repository against the active provider.
 *
 * The first 3.04.43 reconciliation migrates languages that were already active
 * in the previously applied multilingual topology using the legacy 3.04.42
 * behavior. Languages first published after that migration start On demand.
 * Removed-language policies are intentionally retained and restored if the
 * language is later re-enabled. Provider policies remain isolated.
 *
 * @return bool True when reconciliation completed for a supported provider.
 */
function ultracache_multilingual_reconcile_warm_policy_repository()
{
    $provider = ultracache_multilingual_get_provider();
    if (!in_array($provider, array('wpml', 'translatepress'), true) || !function_exists('get_option')) {
        return false;
    }

    $snapshot = ultracache_multilingual_get_topology_snapshot();
    if (!is_array($snapshot)
        || empty($snapshot['ready'])
        || (string) ($snapshot['provider'] ?? '') !== $provider
    ) {
        return false;
    }

    $active_codes = ultracache_multilingual_get_active_language_codes();
    $active_codes = array_values(array_filter(array_map('strval', $active_codes)));
    $default_language = ultracache_multilingual_get_default_language();
    if (empty($active_codes) || '' === $default_language || !in_array($default_language, $active_codes, true)) {
        return false;
    }

    $settings_key = defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings';
    $settings = get_option($settings_key, array());
    $settings = is_array($settings) ? $settings : array();
    $store = ultracache_multilingual_sanitize_warm_policy_store($settings['multilingualWarmPolicyV1'] ?? array());
    $original = $store;

    if (!isset($store['providerPolicies'][$provider]) || !is_array($store['providerPolicies'][$provider])) {
        $store['providerPolicies'][$provider] = array();
    }
    if (!isset($store['providerStates'][$provider]) || !is_array($store['providerStates'][$provider])) {
        $store['providerStates'][$provider] = array(
            'initialized'         => false,
            'knownLanguages'      => array(),
            'lastActiveLanguages' => array(),
            'defaultLanguage'     => '',
            'topologyFingerprint' => '',
            'reconciledAt'        => 0,
        );
    }

    $state = $store['providerStates'][$provider];
    $known_lookup = array();
    foreach ((array) ($state['knownLanguages'] ?? array()) as $code) {
        $known_lookup[(string) $code] = true;
    }
    foreach (array_keys($store['providerPolicies'][$provider]) as $code) {
        $known_lookup[(string) $code] = true;
    }

    $topology_state = get_option('ultracache_multilingual_topology_v1', array());
    $topology_state = is_array($topology_state) ? $topology_state : array();
    $previous_snapshot = isset($topology_state['appliedSnapshot']) && is_array($topology_state['appliedSnapshot'])
        ? $topology_state['appliedSnapshot']
        : array();
    $previous_provider = sanitize_key((string) ($previous_snapshot['provider'] ?? ''));
    $previous_active_lookup = array();
    if ($previous_provider === $provider) {
        foreach ((array) ($previous_snapshot['activeLanguages'] ?? array()) as $code) {
            $code = trim((string) $code);
            if ('' !== $code) {
                $previous_active_lookup[$code] = true;
            }
        }
    }

    $global_migration_pending = (int) ($store['migrationVersion'] ?? 0) < 2;
    $provider_initialized = !empty($state['initialized']);

    foreach ($active_codes as $language_code) {
        $language_code = trim((string) $language_code);
        if ('' === $language_code || isset($store['providerPolicies'][$provider][$language_code])) {
            $known_lookup[$language_code] = true;
            continue;
        }

        if ($global_migration_pending && isset($previous_active_lookup[$language_code])) {
            // Existing active language at upgrade: preserve 3.04.42 semantics.
            $store['providerPolicies'][$provider][$language_code] = ultracache_multilingual_get_legacy_fallback_language_warm_policy($language_code);
        } elseif (!$provider_initialized && $language_code === $default_language) {
            // First encounter of a genuinely new provider: preserve ordinary
            // default-language warming, but do not fan out translations.
            $store['providerPolicies'][$provider][$language_code] = ultracache_multilingual_get_warm_profile_preset('full');
        } else {
            // New publication, new translated language, or missing unknown
            // policy after initialization: conservative proactive default.
            $store['providerPolicies'][$provider][$language_code] = ultracache_multilingual_get_warm_profile_preset('on_demand');
        }
        $known_lookup[$language_code] = true;
    }

    $fingerprint = (string) ($snapshot['fingerprint'] ?? '');

    $known_languages = array_keys($known_lookup);
    sort($known_languages, SORT_STRING);
    $last_active = array_values(array_unique($active_codes));
    sort($last_active, SORT_STRING);

    $store['schemaVersion'] = 2;
    $store['migrationVersion'] = 2;
    $previous_state = isset($store['providerStates'][$provider]) && is_array($store['providerStates'][$provider])
        ? $store['providerStates'][$provider]
        : array();
    $next_state_material = array(
        'initialized'         => true,
        'knownLanguages'      => $known_languages,
        'lastActiveLanguages' => $last_active,
        'defaultLanguage'     => $default_language,
        'topologyFingerprint' => $fingerprint,
    );
    $previous_state_material = array(
        'initialized'         => !empty($previous_state['initialized']),
        'knownLanguages'      => array_values((array) ($previous_state['knownLanguages'] ?? array())),
        'lastActiveLanguages' => array_values((array) ($previous_state['lastActiveLanguages'] ?? array())),
        'defaultLanguage'     => (string) ($previous_state['defaultLanguage'] ?? ''),
        'topologyFingerprint' => (string) ($previous_state['topologyFingerprint'] ?? ''),
    );
    $store['providerStates'][$provider] = $next_state_material + array(
        'reconciledAt' => $next_state_material === $previous_state_material
            ? absint($previous_state['reconciledAt'] ?? 0)
            : time(),
    );

    $store = ultracache_multilingual_sanitize_warm_policy_store($store);
    if ($store !== $original) {
        $settings['multilingualWarmPolicyV1'] = $store;
        update_option($settings_key, $settings, false);
    }

    return true;
}

/**
 * Return the legacy-compatible fallback policy for an active language that has
 * not yet been explicitly stored in the 3.04.40 control plane.
 *
 * This preserves the exact 3.04.39 warm fan-out on upgrade: master ON means
 * every active language participates; master OFF means only the provider
 * default language participates. No option is written from this read path.
 *
 * @param string $language_code Provider-native language code.
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_legacy_fallback_language_warm_policy($language_code)
{
    $language_code = ultracache_multilingual_normalize_language_code($language_code);
    $default_language = ultracache_multilingual_get_default_language();
    $master_enabled = !function_exists('ultracache_should_warm_translation_pages')
        || ultracache_should_warm_translation_pages();

    if ($master_enabled || ('' !== $default_language && $language_code === $default_language)) {
        return ultracache_multilingual_get_warm_profile_preset('full');
    }

    return ultracache_multilingual_get_warm_profile_preset('on_demand');
}

/**
 * Return the effective per-language warm policy for the active provider.
 *
 * Stored policy wins. Missing policy uses the legacy-compatible fallback so an
 * upgrade does not silently change existing warm behavior.
 *
 * @param string $language_code Provider-native language code.
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_language_warm_policy($language_code)
{
    $provider = ultracache_multilingual_get_provider();
    $language_code = ultracache_multilingual_normalize_language_code($language_code);
    if (!in_array($provider, array('wpml', 'translatepress'), true) || '' === $language_code) {
        return array();
    }

    $active_codes = ultracache_multilingual_get_active_language_codes();
    if (!in_array($language_code, $active_codes, true)) {
        return array();
    }

    $stored = ultracache_multilingual_get_stored_language_warm_policy($provider, $language_code);
    if (!empty($stored)) {
        return $stored;
    }

    $store = ultracache_multilingual_get_warm_policy_store();
    $state = isset($store['providerStates'][$provider]) && is_array($store['providerStates'][$provider])
        ? $store['providerStates'][$provider]
        : array();
    if ((int) ($store['migrationVersion'] ?? 0) >= 2 && !empty($state['initialized'])) {
        return ultracache_multilingual_get_warm_profile_preset('on_demand');
    }

    return ultracache_multilingual_get_legacy_fallback_language_warm_policy($language_code);
}

/**
 * Return whether one active language participates in a specific warm operation.
 *
 * @param string $language_code Provider-native language code.
 * @param string $operation     Warm operation id.
 * @return bool
 */
function ultracache_multilingual_language_allows($language_code, $operation)
{
    $policy = ultracache_multilingual_get_language_warm_policy($language_code);
    if (empty($policy)) {
        return false;
    }

    $operation = sanitize_key((string) $operation);
    $map = array(
        'homepage'        => 'warmHomepage',
        'menu'            => 'warmMenu',
        'full_site'       => 'warmFullSite',
        'scheduled'       => 'warmScheduled',
        'after_flush'     => 'warmAfterFlushAll',
        'after_cleanup'   => 'warmAfterCleanup',
        'affected_save'   => 'warmAffectedSave',
        'css_bundle'      => 'warmCssBundles',
    );
    if (!isset($map[$operation])) {
        return false;
    }

    return !empty($policy[$map[$operation]]);
}

/**
 * Return active provider languages eligible for one warm operation.
 *
 * The existing master switch remains the coarse multilingual fan-out gate:
 * when it is OFF, non-default languages are excluded even if their stored
 * switch is ON. The default language still obeys its own per-language switch.
 *
 * @param string $operation Warm operation id.
 * @return array<int,string>
 */
function ultracache_multilingual_get_warm_languages($operation)
{
    if (!ultracache_multilingual_is_active()) {
        return array();
    }

    $operation = sanitize_key((string) $operation);
    $codes = ultracache_multilingual_get_active_language_codes();
    $default_language = ultracache_multilingual_get_default_language();
    $master_enabled = !function_exists('ultracache_should_warm_translation_pages')
        || ultracache_should_warm_translation_pages();
    $eligible = array();

    foreach ($codes as $language_code) {
        $language_code = ultracache_multilingual_normalize_language_code($language_code);
        if ('' === $language_code) {
            continue;
        }
        if (!$master_enabled && $language_code !== $default_language) {
            continue;
        }
        if (ultracache_multilingual_language_allows($language_code, $operation)) {
            $eligible[$language_code] = $language_code;
        }
    }

    return array_values($eligible);
}


/**
 * Return a compact read-only diagnostic summary for one active language policy.
 *
 * This is dashboard/export data only. Runtime warmers continue to read the
 * individual operation switches through ultracache_multilingual_language_allows().
 *
 * @param string $language_code Provider-native language code.
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_language_warm_diagnostic_summary($language_code)
{
    $language_code = ultracache_multilingual_normalize_language_code($language_code);
    $policy = ultracache_multilingual_get_language_warm_policy($language_code);
    if ('' === $language_code || empty($policy)) {
        return array();
    }

    $operations = array(
        'homepage',
        'menu',
        'full_site',
        'scheduled',
        'after_flush',
        'after_cleanup',
        'affected_save',
        'css_bundle',
    );
    $enabled = array();
    $disabled = array();
    foreach ($operations as $operation) {
        if (ultracache_multilingual_language_allows($language_code, $operation)) {
            $enabled[] = $operation;
        } else {
            $disabled[] = $operation;
        }
    }

    return array(
        'profile'                  => sanitize_key((string) ($policy['profile'] ?? 'custom')),
        'enabledOperations'        => $enabled,
        'disabledOperations'       => $disabled,
        'enabledOperationCount'    => count($enabled),
        'operationCount'           => count($operations),
        'useGlobalFullSiteSources' => !empty($policy['useGlobalFullSiteSources']),
        'fullSiteSources'          => array_values(array_map('strval', (array) ($policy['fullSiteSources'] ?? array()))),
    );
}

/**
 * Return operation-level include/exclude diagnostics from the canonical policy engine.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_warm_operation_diagnostics()
{
    $provider = ultracache_multilingual_get_provider();
    $active = in_array($provider, array('wpml', 'translatepress'), true);
    $master_enabled = !function_exists('ultracache_should_warm_translation_pages')
        || ultracache_should_warm_translation_pages();
    $codes = $active ? ultracache_multilingual_get_active_language_codes() : array();
    $codes = array_values(array_filter(array_map('strval', $codes)));

    $operations = array(
        'homepage',
        'menu',
        'full_site',
        'scheduled',
        'after_flush',
        'after_cleanup',
        'affected_save',
        'css_bundle',
    );
    $diagnostics = array();
    foreach ($operations as $operation) {
        $included = $active ? ultracache_multilingual_get_warm_languages($operation) : array();
        $included_lookup = array_fill_keys(array_map('strval', $included), true);
        $excluded = array();
        foreach ($codes as $language_code) {
            if (!isset($included_lookup[$language_code])) {
                $excluded[] = $language_code;
            }
        }
        $diagnostics[$operation] = array(
            'included'      => array_values($included),
            'excluded'      => $excluded,
            'includedCount' => count($included),
            'excludedCount' => count($excluded),
        );
    }

    return array(
        'provider'      => $provider,
        'active'        => $active,
        'masterEnabled' => (bool) $master_enabled,
        'operations'    => $diagnostics,
    );
}

/**
 * Return whether a public URL may participate in one warm operation.
 *
 * Non-multilingual sites preserve the existing operation behavior. On an
 * active multilingual site the URL must be classifiable by the provider and
 * its language must be eligible for the requested warm operation.
 *
 * @param string $url       Public URL.
 * @param string $operation Warm operation id.
 * @return bool
 */
function ultracache_multilingual_public_url_allows_warm_operation($url, $operation)
{
    if (!ultracache_multilingual_is_active()) {
        return true;
    }

    $url = trim((string) $url);
    $operation = sanitize_key((string) $operation);
    if ('' === $url || '' === $operation) {
        return false;
    }

    $language_code = ultracache_multilingual_get_public_url_language($url);
    $language_code = ultracache_multilingual_normalize_language_code($language_code);
    if ('' === $language_code) {
        return false;
    }

    return in_array($language_code, ultracache_multilingual_get_warm_languages($operation), true);
}

/**
 * Return dashboard-safe multilingual runtime/topology status.
 *
 * @return array<string,mixed>
 */
function ultracache_multilingual_get_dashboard_status()
{
    $provider = ultracache_multilingual_get_provider();
    $snapshot = ultracache_multilingual_get_topology_snapshot();
    $default_language = ultracache_multilingual_get_default_language();
    $codes = ultracache_multilingual_get_active_language_codes();
    $homes = ultracache_multilingual_get_language_home_urls();
    $active = ultracache_multilingual_get_active_languages();

    $names = array();
    if ('translatepress' === $provider && function_exists('ultracache_translatepress_get_language_names')) {
        $names = ultracache_translatepress_get_language_names($codes);
    } elseif ('wpml' === $provider) {
        foreach ($active as $language_code => $language) {
            $name = is_array($language)
                ? trim((string) ($language['native_name'] ?? ($language['translated_name'] ?? '')))
                : '';
            $names[(string) $language_code] = $name;
        }
    }

    $languages = array();
    foreach ($codes as $language_code) {
        $language_code = ultracache_multilingual_normalize_language_code($language_code);
        if ('' === $language_code) {
            continue;
        }
        $home = isset($homes[$language_code]) ? trim((string) $homes[$language_code]) : '';
        $stored_policy = ultracache_multilingual_get_stored_language_warm_policy($provider, $language_code);
        $languages[] = array(
            'code'            => $language_code,
            'name'            => trim((string) ($names[$language_code] ?? '')),
            'home'            => $home,
            'isDefault'       => $language_code === $default_language,
            'warmPolicy'      => ultracache_multilingual_get_language_warm_policy($language_code),
            'warmSummary'     => ultracache_multilingual_get_language_warm_diagnostic_summary($language_code),
            'hasStoredPolicy' => !empty($stored_policy),
        );
    }

    $store = ultracache_multilingual_get_warm_policy_store();
    $provider_state = isset($store['providerStates'][$provider]) && is_array($store['providerStates'][$provider])
        ? $store['providerStates'][$provider]
        : array();
    $reason = '';
    if ('ambiguous' === $provider) {
        $reason = 'multiple-supported-providers';
    } elseif ('none' === $provider) {
        $reason = 'no-supported-provider';
    } elseif (empty($snapshot['ready'])) {
        $reason = 'provider-topology-not-ready';
    }

    return array(
        'provider'          => $provider,
        'active'            => in_array($provider, array('wpml', 'translatepress'), true),
        'ambiguous'         => 'ambiguous' === $provider,
        'ready'             => !empty($snapshot['ready']),
        'reason'            => $reason,
        'defaultLanguage'   => $default_language,
        'urlMode'           => (string) ($snapshot['urlMode'] ?? ultracache_multilingual_get_url_mode()),
        'languages'         => $languages,
        'policyState'       => array(
            'migrationVersion'   => (int) ($store['migrationVersion'] ?? 0),
            'providerInitialized'=> !empty($provider_state['initialized']),
            'knownLanguages'     => array_values((array) ($provider_state['knownLanguages'] ?? array())),
            'lastActiveLanguages'=> array_values((array) ($provider_state['lastActiveLanguages'] ?? array())),
        ),
        'warmDiagnostics'   => ultracache_multilingual_get_warm_operation_diagnostics(),
    );
}

