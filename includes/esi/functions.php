<?php
/**
 * Public/private developer API for UltraCache ESI fragments.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-esi-registry.php';

/**
 * Whether a Varnish connection is available for automatic ESI activation.
 *
 * ESI has no user-facing enable switch. A configured Varnish connection makes
 * the framework eligible for testing, while live markup remains gated by the
 * corresponding end-to-end capability proof.
 *
 * @return bool
 */
function ultracache_esi_is_configured()
{
    $configured = false;
    if (class_exists('Ultra_Cache_WP')
        && method_exists('Ultra_Cache_WP', 'get_dashboard_settings')
        && method_exists('Ultra_Cache_WP', 'is_varnish_runtime_enabled')) {
        $settings = Ultra_Cache_WP::get_dashboard_settings();
        $configured = Ultra_Cache_WP::is_varnish_runtime_enabled($settings);
    }

    return (bool) apply_filters('ultracache_esi_configured', $configured);
}

/**
 * Whether verified public ESI markup may be emitted for frontend responses.
 *
 * Test Varnish must prove end-to-end public fragment composition. When proof
 * is missing, expired, or invalidated by a configuration change, registered
 * fragments render their inline fallback automatically.
 *
 * @return bool
 */
function ultracache_esi_is_enabled()
{
    $eligible = ultracache_esi_is_configured();
    $verified = $eligible
        && class_exists('Ultra_Cache_WP')
        && method_exists('Ultra_Cache_WP', 'is_varnish_esi_capability_verified')
        && Ultra_Cache_WP::is_varnish_esi_capability_verified();

    return (bool) apply_filters('ultracache_esi_enabled', $verified, $eligible);
}


/**
 * Return the maximum number of live ESI includes emitted into one parent page.
 *
 * @return int
 */
function ultracache_esi_get_max_parent_fragments()
{
    $limit = (int) apply_filters('ultracache_esi_max_parent_fragments', 32);
    return max(1, min(64, $limit));
}

/**
 * Return the maximum aggregate bytes used by generated ESI directives in one
 * parent document. Inline fallback HTML is not counted by this limit.
 *
 * @return int
 */
function ultracache_esi_get_max_parent_directive_bytes()
{
    $limit = (int) apply_filters('ultracache_esi_max_parent_directive_bytes', 65536);
    return max(4096, min(262144, $limit));
}

/**
 * Return a bounded locale identifier used to isolate deterministic fragment
 * URLs and render WordPress-native translations under the parent locale.
 *
 * @return string
 */
function ultracache_esi_get_current_locale()
{
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
    if (!is_string($locale) || strlen($locale) < 2 || strlen($locale) > 32) {
        $locale = 'en_US';
    }

    $locale = apply_filters('ultracache_esi_fragment_locale', $locale);
    $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
    if (!is_string($locale) || strlen($locale) < 2 || strlen($locale) > 32) {
        return 'en_US';
    }

    return $locale;
}


/**
 * Whether verified private/session ESI transport may be emitted.
 *
 * @return bool
 */
function ultracache_esi_private_is_enabled()
{
    $configured = ultracache_esi_is_configured();
    $verified = $configured
        && class_exists('Ultra_Cache_WP')
        && method_exists('Ultra_Cache_WP', 'is_varnish_private_esi_capability_verified')
        && Ultra_Cache_WP::is_varnish_private_esi_capability_verified();

    return (bool) apply_filters('ultracache_esi_private_enabled', $verified, $configured);
}

/**
 * Whether private ESI includes may use onerror=continue.
 *
 * @return bool
 */
function ultracache_esi_private_onerror_is_enabled()
{
    $enabled = ultracache_esi_private_is_enabled()
        && class_exists('Ultra_Cache_WP')
        && method_exists('Ultra_Cache_WP', 'is_varnish_private_esi_onerror_verified')
        && Ultra_Cache_WP::is_varnish_private_esi_onerror_verified();

    return (bool) apply_filters('ultracache_esi_private_onerror_enabled', $enabled);
}

/**
 * Whether a private fragment's cookies are explicitly declared in the VCL
 * transport policy. Capability verification proves the transport mechanism,
 * while these filters bind individual fragment definitions to the cookie
 * names/prefixes that the administrator configured Varnish to extract from
 * the shared parent request.
 *
 * @param array $definition Private fragment definition.
 * @return bool
 */
function ultracache_esi_private_definition_transport_is_declared(array $definition)
{
    if ('private' !== (string) ($definition['scope'] ?? '')) {
        return false;
    }

    $normalize = static function (array $values) {
        $normalized = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ('' === $value || strlen($value) > 128 || 1 !== preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $value)) {
                continue;
            }
            $normalized[$value] = $value;
            if (count($normalized) >= 64) {
                break;
            }
        }
        return array_values($normalized);
    };

    $transport_names = $normalize((array) apply_filters('ultracache_esi_private_transport_cookie_names', array()));
    $transport_prefixes = $normalize((array) apply_filters('ultracache_esi_private_transport_cookie_prefixes', array()));
    $name_lookup = array_fill_keys($transport_names, true);

    $name_is_covered = static function ($name) use ($name_lookup, $transport_prefixes) {
        if (isset($name_lookup[$name])) {
            return true;
        }
        foreach ($transport_prefixes as $prefix) {
            if ('' !== $prefix && 0 === strpos($name, $prefix)) {
                return true;
            }
        }
        return false;
    };

    foreach ((array) ($definition['cookie_names'] ?? array()) as $name) {
        if (!$name_is_covered((string) $name)) {
            return false;
        }
    }

    foreach ((array) ($definition['cookie_prefixes'] ?? array()) as $required_prefix) {
        $covered = false;
        foreach ($transport_prefixes as $transport_prefix) {
            if ('' !== $transport_prefix && 0 === strpos((string) $required_prefix, $transport_prefix)) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            return false;
        }
    }

    $declared = !empty($definition['cookie_names']) || !empty($definition['cookie_prefixes']);
    return (bool) apply_filters('ultracache_esi_private_definition_transport_declared', $declared, $definition);
}

/**
 * Whether one private fragment may emit a live ESI include.
 *
 * @param array $definition Private fragment definition.
 * @return bool
 */
function ultracache_esi_private_definition_is_enabled(array $definition)
{
    return ultracache_esi_private_is_enabled()
        && ultracache_esi_private_definition_transport_is_declared($definition);
}

/**
 * Render an inline fallback without allowing private request state into a
 * shared parent object.
 *
 * @param Ultra_Cache_ESI_Registry $registry   Registry instance.
 * @param array                    $definition Fragment definition.
 * @param array                    $context    Normalized context.
 * @return string|WP_Error
 */
function ultracache_render_esi_fragment_fallback_safely($registry, array $definition, array $context)
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

/**
 * Register one public or private ESI fragment.
 *
 * Private fragments must declare cookie_names and/or cookie_prefixes.
 *
 * @param string $fragment_id Stable fragment ID.
 * @param array  $args        Fragment definition.
 * @return bool|WP_Error
 */
function ultracache_register_esi_fragment($fragment_id, array $args)
{
    return Ultra_Cache_ESI_Registry::instance()->register($fragment_id, $args);
}

/**
 * Return the signed same-origin endpoint URL for a registered fragment.
 *
 * @param string $fragment_id Fragment ID.
 * @param array  $context     Fragment context.
 * @return string|WP_Error
 */
function ultracache_get_esi_fragment_url($fragment_id, array $context = array())
{
    $token = Ultra_Cache_ESI_Registry::instance()->create_context_token($fragment_id, $context);
    if (is_wp_error($token)) {
        return $token;
    }

    $definition = Ultra_Cache_ESI_Registry::instance()->get($fragment_id);
    $args = array('ultracache_esi' => $token);
    if (is_array($definition) && 'private' === (string) ($definition['scope'] ?? '')) {
        $args['esi_scope'] = 'private';
    }

    return add_query_arg($args, home_url('/'));
}

/**
 * Return ESI placeholder markup with an inline server-rendered fallback.
 *
 * When ESI is disabled, only the fallback HTML is returned.
 *
 * @param string $fragment_id Fragment ID.
 * @param array  $context     Fragment context.
 * @return string
 */
function ultracache_get_esi_fragment_markup($fragment_id, array $context = array())
{
    $registry = Ultra_Cache_ESI_Registry::instance();
    $definition = $registry->get($fragment_id);
    if (null === $definition) {
        return '';
    }

    $normalized_context = $registry->normalize_context($definition, $context);
    if (is_wp_error($normalized_context)) {
        do_action('ultracache_esi_fragment_markup_error', $fragment_id, $normalized_context);
        return '';
    }

    $fallback = ultracache_render_esi_fragment_fallback_safely($registry, $definition, $normalized_context);
    if (is_wp_error($fallback)) {
        do_action('ultracache_esi_fragment_markup_error', $fragment_id, $fallback);
        return '';
    }

    $scope = (string) ($definition['scope'] ?? 'public');
    $scope_enabled = 'private' === $scope
        ? ultracache_esi_private_definition_is_enabled($definition)
        : ultracache_esi_is_enabled();
    if (!$scope_enabled) {
        return $fallback;
    }

    if (
        method_exists($registry, 'get_template_buffer_diagnostics')
        && method_exists($registry, 'template_buffer_started')
        && !$registry->template_buffer_started()
    ) {
        $buffer_diagnostics = $registry->get_template_buffer_diagnostics();
        if (!empty($buffer_diagnostics['decision_observed'])) {
            do_action(
                'ultracache_esi_unbuffered_fragment_fallback',
                $fragment_id,
                $normalized_context,
                $definition,
                !empty($buffer_diagnostics['late_fragment_ids'])
                    && in_array($fragment_id, (array) $buffer_diagnostics['late_fragment_ids'], true)
            );
            return $fallback;
        }
    }

    $token = $registry->create_context_token($fragment_id, $normalized_context);
    if (is_wp_error($token)) {
        do_action('ultracache_esi_fragment_markup_error', $fragment_id, $token);
        return $fallback;
    }

    return '<!--ultracache-esi-start:v1:' . $token . '-->'
        . $fallback
        . '<!--ultracache-esi-end:v1:' . $token . '-->';
}

/**
 * Echo ESI placeholder markup.
 *
 * @param string $fragment_id Fragment ID.
 * @param array  $context     Fragment context.
 * @return void
 */
function ultracache_render_esi_fragment($fragment_id, array $context = array())
{
    echo ultracache_get_esi_fragment_markup($fragment_id, $context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Registered renderer output is intentional frontend HTML.
}

/**
 * Return a callback-free summary of one registered fragment.
 *
 * @param string $fragment_id Fragment ID.
 * @return array<string,mixed>|null
 */
function ultracache_get_esi_fragment_definition($fragment_id)
{
    return Ultra_Cache_ESI_Registry::instance()->get_definition_summary($fragment_id);
}

/**
 * Return the stable hash for one exact fragment context.
 *
 * @param string $fragment_id Fragment ID.
 * @param array  $context     Fragment context.
 * @return string
 */
function ultracache_get_esi_fragment_context_hash($fragment_id, array $context = array())
{
    return Ultra_Cache_ESI_Registry::instance()->get_context_hash($fragment_id, $context);
}

/**
 * Invalidate one exact public fragment context in Varnish.
 *
 * This invalidates only the deterministic signed fragment URL. Parent page
 * objects remain cached and refetch the fragment on their next delivery.
 *
 * @param string $fragment_id Registered public fragment ID.
 * @param array  $context     Exact public fragment context.
 * @return array|WP_Error
 */
function ultracache_purge_esi_fragment($fragment_id, array $context = array())
{
    $definition = Ultra_Cache_ESI_Registry::instance()->get($fragment_id);
    if (is_array($definition) && 'private' === (string) ($definition['scope'] ?? '')) {
        return new WP_Error(
            'ultracache_esi_private_fragment_not_cacheable',
            __('Private ESI fragments are never stored in shared cache and do not require invalidation.', 'ultracache')
        );
    }

    if (!class_exists('Ultra_Cache_WP') || !method_exists('Ultra_Cache_WP', 'purge_public_esi_fragment')) {
        return new WP_Error(
            'ultracache_esi_fragment_purge_unavailable',
            __('The ESI fragment purge API is unavailable.', 'ultracache')
        );
    }

    return Ultra_Cache_WP::purge_public_esi_fragment($fragment_id, $context);
}
