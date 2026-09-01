<?php
/**
 * Registered public/private LiteSpeed ESI fragments and signed context handling.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ultra_Cache_LiteSpeed_ESI_Registry
{
    /** @var Ultra_Cache_LiteSpeed_ESI_Registry|null */
    private static $instance = null;

    /** @var array<string,array<string,mixed>> */
    private $fragments = array();

    /** @var int */
    private $render_depth = 0;

    /** @var bool */
    private $template_buffer_decision_observed = false;

    /** @var bool */
    private $template_buffer_started = false;

    /** @var array<int,string> */
    private $fragment_ids_at_template_buffer_decision = array();

    /** @var array<string,array<string,mixed>> */
    private $fragment_registration_metadata = array();

    /**
     * Return the singleton registry.
     *
     * @return Ultra_Cache_LiteSpeed_ESI_Registry
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register one ESI fragment.
     *
     * @param string $fragment_id Stable fragment identifier.
     * @param array  $args        Fragment definition.
     * @return bool|WP_Error
     */
    public function register($fragment_id, array $args)
    {
        $fragment_id = $this->normalize_fragment_id($fragment_id);
        if ('' === $fragment_id) {
            return new WP_Error('ultracache_litespeed_esi_invalid_fragment_id', __('The ESI fragment ID is invalid.', 'ultracache'));
        }
        if (isset($this->fragments[$fragment_id])) {
            return new WP_Error('ultracache_litespeed_esi_fragment_already_registered', __('The ESI fragment ID is already registered.', 'ultracache'));
        }

        $renderer = $args['renderer'] ?? null;
        if (!is_callable($renderer)) {
            return new WP_Error('ultracache_litespeed_esi_invalid_renderer', __('The ESI fragment renderer must be callable.', 'ultracache'));
        }
        $scope = strtolower(trim((string) ($args['scope'] ?? 'public')));
        if (!in_array($scope, array('public', 'private'), true)) {
            return new WP_Error('ultracache_litespeed_esi_scope_not_supported', __('The ESI fragment scope must be public or private.', 'ultracache'));
        }

        $cookie_names = $this->normalize_cookie_identifiers((array) ($args['cookie_names'] ?? array()), false);
        $cookie_prefixes = $this->normalize_cookie_identifiers((array) ($args['cookie_prefixes'] ?? array()), true);
        if ('private' === $scope && empty($cookie_names) && empty($cookie_prefixes)) {
            return new WP_Error('ultracache_litespeed_esi_private_cookie_allowlist_required', __('Private ESI fragments require at least one allowed cookie name or prefix.', 'ultracache'));
        }
        if ('public' === $scope) {
            $cookie_names = array();
            $cookie_prefixes = array();
        }

        $context_keys = array();
        foreach ((array) ($args['context_keys'] ?? array()) as $context_key) {
            $context_key = sanitize_key((string) $context_key);
            if ('' === $context_key) {
                continue;
            }
            $context_keys[$context_key] = $context_key;
            if (count($context_keys) >= 12) {
                break;
            }
        }

        $fallback = $args['fallback'] ?? ('private' === $scope ? '' : $renderer);
        if ('private' === $scope && !is_string($fallback)) {
            return new WP_Error(
                'ultracache_litespeed_esi_private_fallback_must_be_static',
                __('Private ESI fragment fallback must be static HTML.', 'ultracache')
            );
        }
        if (!is_callable($fallback) && !is_string($fallback)) {
            return new WP_Error('ultracache_litespeed_esi_invalid_fallback', __('The ESI fragment fallback must be callable or a string.', 'ultracache'));
        }

        $definition = array(
            'id'                      => $fragment_id,
            'scope'                   => $scope,
            'ttl'                     => 'public' === $scope ? max(1, min(WEEK_IN_SECONDS, absint($args['ttl'] ?? 300))) : 0,
            'renderer'                => $renderer,
            'fallback'                => $fallback,
            'context_keys'            => array_values($context_keys),
            'cookie_names'            => $cookie_names,
            'cookie_prefixes'         => $cookie_prefixes,
            'max_output_bytes'        => max(1024, min(1048576, absint($args['max_output_bytes'] ?? 262144))),
            'max_context_bytes'       => max(64, min(2048, absint($args['max_context_bytes'] ?? 1024))),
            'max_context_value_bytes' => max(16, min(512, absint($args['max_context_value_bytes'] ?? 200))),
            'max_cookie_header_bytes' => max(256, min(16384, absint($args['max_cookie_header_bytes'] ?? 8192))),
            'needs_main_query'         => !array_key_exists('needs_main_query', $args) || (bool) $args['needs_main_query'],
        );

        $filtered = apply_filters('ultracache_litespeed_esi_fragment_definition', $definition, $fragment_id, $args);
        if (!is_array($filtered) || !isset($filtered['renderer']) || !is_callable($filtered['renderer'])) {
            return new WP_Error('ultracache_litespeed_esi_invalid_filtered_definition', __('The filtered ESI fragment definition is invalid.', 'ultracache'));
        }

        $filtered['id'] = $fragment_id;
        $filtered['scope'] = $scope;
        $filtered['ttl'] = 'public' === $scope
            ? max(1, min(WEEK_IN_SECONDS, absint($filtered['ttl'] ?? $definition['ttl'])))
            : 0;
        $filtered['context_keys'] = array_slice(
            array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($filtered['context_keys'] ?? array()))))),
            0,
            12
        );
        $filtered['fallback'] = $filtered['fallback'] ?? $definition['fallback'];
        if ('private' === $scope && !is_string($filtered['fallback'])) {
            return new WP_Error(
                'ultracache_litespeed_esi_private_fallback_must_be_static',
                __('Private ESI fragment fallback must be static HTML.', 'ultracache')
            );
        }
        if (!is_callable($filtered['fallback']) && !is_string($filtered['fallback'])) {
            return new WP_Error('ultracache_litespeed_esi_invalid_filtered_fallback', __('The filtered ESI fragment fallback is invalid.', 'ultracache'));
        }
        $filtered['max_output_bytes'] = max(1024, min(1048576, absint($filtered['max_output_bytes'] ?? $definition['max_output_bytes'])));
        $filtered['max_context_bytes'] = max(64, min(2048, absint($filtered['max_context_bytes'] ?? $definition['max_context_bytes'])));
        $filtered['max_context_value_bytes'] = max(16, min(512, absint($filtered['max_context_value_bytes'] ?? $definition['max_context_value_bytes'])));
        $filtered['max_cookie_header_bytes'] = max(256, min(16384, absint($filtered['max_cookie_header_bytes'] ?? $definition['max_cookie_header_bytes'])));
        $filtered['needs_main_query'] = !array_key_exists('needs_main_query', $filtered)
            ? (bool) $definition['needs_main_query']
            : (bool) $filtered['needs_main_query'];
        $filtered['cookie_names'] = 'private' === $scope
            ? $this->normalize_cookie_identifiers((array) ($filtered['cookie_names'] ?? $definition['cookie_names']), false)
            : array();
        $filtered['cookie_prefixes'] = 'private' === $scope
            ? $this->normalize_cookie_identifiers((array) ($filtered['cookie_prefixes'] ?? $definition['cookie_prefixes']), true)
            : array();
        if ('private' === $scope && empty($filtered['cookie_names']) && empty($filtered['cookie_prefixes'])) {
            return new WP_Error('ultracache_litespeed_esi_private_cookie_allowlist_required', __('Private ESI fragments require at least one allowed cookie name or prefix.', 'ultracache'));
        }
        $this->fragments[$fragment_id] = $filtered;
        $this->fragment_registration_metadata[$fragment_id] = array(
            'hook' => function_exists('current_filter') ? sanitize_key((string) current_filter()) : '',
            'init_started' => function_exists('did_action') && did_action('init') > 0,
            'after_template_buffer_decision' => $this->template_buffer_decision_observed,
        );

        return true;
    }


    /**
     * Whether at least one ESI fragment is registered for this request.
     *
     * @return bool
     */
    public function has_fragments()
    {
        return !empty($this->fragments);
    }

    /**
     * Return the number of registered ESI fragments.
     *
     * @return int
     */
    public function get_fragment_count()
    {
        return count($this->fragments);
    }

    /**
     * Snapshot registry state at the first template-buffer decision.
     *
     * Later registrations remain supported in this release; they are recorded
     * only so selective buffering can be designed from real request evidence.
     *
     * @return array<string,mixed>
     */
    public function note_template_buffer_decision()
    {
        if (!$this->template_buffer_decision_observed) {
            $this->template_buffer_decision_observed = true;
            $this->fragment_ids_at_template_buffer_decision = array_keys($this->fragments);
        }

        return $this->get_template_buffer_diagnostics();
    }

    /**
     * Record that WordPress successfully started the template buffer.
     *
     * @return void
     */
    public function note_template_buffer_started()
    {
        $this->template_buffer_started = true;
    }

    /**
     * Whether the WordPress template-enhancement buffer started.
     *
     * @return bool
     */
    public function template_buffer_started()
    {
        return $this->template_buffer_started;
    }

    /**
     * Whether a fragment was registered after the buffer decision snapshot.
     *
     * @param string $fragment_id Fragment ID.
     * @return bool
     */
    public function fragment_registered_after_template_buffer_decision($fragment_id)
    {
        $fragment_id = $this->normalize_fragment_id($fragment_id);
        return '' !== $fragment_id
            && !empty($this->fragment_registration_metadata[$fragment_id]['after_template_buffer_decision']);
    }

    /**
     * Return bounded callback-free template-buffer diagnostics.
     *
     * @return array<string,mixed>
     */
    public function get_template_buffer_diagnostics()
    {
        $fragment_ids = array_keys($this->fragments);
        $late_fragment_ids = array();
        if ($this->template_buffer_decision_observed) {
            foreach ($this->fragment_registration_metadata as $fragment_id => $metadata) {
                if (!empty($metadata['after_template_buffer_decision'])) {
                    $late_fragment_ids[] = (string) $fragment_id;
                }
            }
        }
        $registration_hooks = array();
        $late_registration_hooks = array();
        $registered_after_init_started_count = 0;
        foreach ($this->fragment_registration_metadata as $fragment_id => $metadata) {
            if (!empty($metadata['init_started'])) {
                $registered_after_init_started_count++;
            }
            $hook = sanitize_key((string) ($metadata['hook'] ?? ''));
            if ('' !== $hook) {
                $registration_hooks[$hook] = $hook;
                if (in_array($fragment_id, $late_fragment_ids, true)) {
                    $late_registration_hooks[$hook] = $hook;
                }
            }
        }

        return array(
            'has_fragments' => !empty($fragment_ids),
            'fragment_count' => count($fragment_ids),
            'fragment_ids' => array_slice($fragment_ids, 0, 32),
            'decision_observed' => $this->template_buffer_decision_observed,
            'template_buffer_started' => $this->template_buffer_started,
            'fragment_count_at_decision' => count($this->fragment_ids_at_template_buffer_decision),
            'late_fragment_count' => count($late_fragment_ids),
            'late_fragment_ids' => array_slice($late_fragment_ids, 0, 32),
            'registered_after_init_started_count' => $registered_after_init_started_count,
            'registration_hooks' => array_slice(array_values($registration_hooks), 0, 16),
            'late_registration_hooks' => array_slice(array_values($late_registration_hooks), 0, 16),
        );
    }

    /**
     * Return a registered fragment definition.
     *
     * @param string $fragment_id Fragment ID.
     * @return array<string,mixed>|null
     */
    public function get($fragment_id)
    {
        $fragment_id = $this->normalize_fragment_id($fragment_id);
        return isset($this->fragments[$fragment_id]) ? $this->fragments[$fragment_id] : null;
    }

    /**
     * Normalize and strictly bound a fragment context.
     *
     * @param array $definition Fragment definition.
     * @param array $context    Requested context.
     * @return array<string,int|string|bool>|WP_Error
     */
    public function normalize_context(array $definition, array $context)
    {
        $allowed_keys = array_fill_keys((array) ($definition['context_keys'] ?? array()), true);
        if (empty($allowed_keys)) {
            return empty($context)
                ? array()
                : new WP_Error('ultracache_litespeed_esi_context_not_allowed', __('This ESI fragment does not accept context values.', 'ultracache'));
        }

        if (count($context) > min(12, count($allowed_keys))) {
            return new WP_Error('ultracache_litespeed_esi_context_too_many_values', __('The ESI fragment context contains too many values.', 'ultracache'));
        }

        $max_value_bytes = max(16, min(512, absint($definition['max_context_value_bytes'] ?? 200)));
        $normalized = array();
        foreach ($context as $key => $value) {
            $raw_key = (string) $key;
            $key = sanitize_key($raw_key);
            if ('' === $key || $key !== $raw_key || !isset($allowed_keys[$key])) {
                return new WP_Error('ultracache_litespeed_esi_context_key_not_allowed', __('The ESI fragment context contains an unsupported key.', 'ultracache'));
            }

            if (is_bool($value)) {
                $normalized[$key] = $value;
                continue;
            }
            if (is_int($value)) {
                $normalized[$key] = $value;
                continue;
            }
            if (is_float($value)) {
                if (!is_finite($value)) {
                    return new WP_Error('ultracache_litespeed_esi_context_value_invalid', __('ESI fragment context values must be scalar.', 'ultracache'));
                }
                $normalized[$key] = (string) $value;
                continue;
            }
            if (is_string($value)) {
                $value = sanitize_text_field($value);
                if (strlen($value) > $max_value_bytes) {
                    return new WP_Error('ultracache_litespeed_esi_context_value_too_long', __('An ESI fragment context value is too long.', 'ultracache'));
                }
                $normalized[$key] = $value;
                continue;
            }

            return new WP_Error('ultracache_litespeed_esi_context_value_invalid', __('ESI fragment context values must be scalar.', 'ultracache'));
        }

        ksort($normalized);

        $encoded_context = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $max_context_bytes = max(64, min(2048, absint($definition['max_context_bytes'] ?? 1024)));
        if (!is_string($encoded_context) || strlen($encoded_context) > $max_context_bytes) {
            return new WP_Error('ultracache_litespeed_esi_context_too_large', __('The ESI fragment context exceeds its configured size limit.', 'ultracache'));
        }

        return $normalized;
    }

    /**
     * Build a deterministic signed token for a fragment request.
     *
     * @param string $fragment_id Fragment ID.
     * @param array  $context     Fragment context.
     * @return string|WP_Error
     */
    public function create_context_token($fragment_id, array $context = array())
    {
        $definition = $this->get($fragment_id);
        if (null === $definition) {
            return new WP_Error('ultracache_litespeed_esi_fragment_not_registered', __('The ESI fragment is not registered.', 'ultracache'));
        }

        $context = $this->normalize_context($definition, $context);
        if (is_wp_error($context)) {
            return $context;
        }

        $payload = array(
            'v'   => 1,
            'id'  => (string) $definition['id'],
            'ctx' => $context,
            'loc' => function_exists('ultracache_litespeed_esi_get_current_locale')
                ? ultracache_litespeed_esi_get_current_locale()
                : (function_exists('get_locale') ? (string) get_locale() : 'en_US'),
            'lng' => function_exists('ultracache_litespeed_esi_get_current_language')
                ? ultracache_litespeed_esi_get_current_language()
                : '',
        );
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || '' === $json) {
            return new WP_Error('ultracache_litespeed_esi_context_encode_failed', __('The ESI fragment context could not be encoded.', 'ultracache'));
        }

        $encoded = $this->base64url_encode($json);
        $signature = ultracache_internal_sign('litespeed-esi-context', $encoded);
        $token = $encoded . '.' . $signature;
        if (strlen($token) > 4096) {
            return new WP_Error('ultracache_litespeed_esi_context_token_too_large', __('The ESI fragment context token exceeds the supported URL size.', 'ultracache'));
        }

        return $token;
    }

    /**
     * Decode and verify one signed fragment token.
     *
     * @param string $token Signed token.
     * @return array{definition:array<string,mixed>,context:array<string,int|string|bool>}|WP_Error
     */
    public function decode_context_token($token)
    {
        $token = trim((string) $token);
        if ('' === $token || strlen($token) > 4096 || 1 !== preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $matches)) {
            return new WP_Error('ultracache_litespeed_esi_context_token_invalid', __('The ESI fragment token is invalid.', 'ultracache'));
        }

        $encoded = (string) $matches[1];
        $provided_signature = (string) $matches[2];
        if (!ultracache_internal_verify('litespeed-esi-context', $encoded, $provided_signature)) {
            return new WP_Error('ultracache_litespeed_esi_context_signature_invalid', __('The ESI fragment signature is invalid.', 'ultracache'));
        }

        $json = $this->base64url_decode($encoded);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload) || 1 !== (int) ($payload['v'] ?? 0)) {
            return new WP_Error('ultracache_litespeed_esi_context_payload_invalid', __('The ESI fragment payload is invalid.', 'ultracache'));
        }

        $fragment_id = $this->normalize_fragment_id($payload['id'] ?? '');
        $definition = $this->get($fragment_id);
        if (null === $definition) {
            return new WP_Error('ultracache_litespeed_esi_fragment_not_registered', __('The ESI fragment is not registered.', 'ultracache'));
        }

        $context = is_array($payload['ctx'] ?? null) ? $payload['ctx'] : array();
        $context = $this->normalize_context($definition, $context);
        if (is_wp_error($context)) {
            return $context;
        }

        $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) ($payload['loc'] ?? ''));
        if (!is_string($locale) || strlen($locale) < 2 || strlen($locale) > 32) {
            $locale = function_exists('ultracache_litespeed_esi_get_current_locale')
                ? ultracache_litespeed_esi_get_current_locale()
                : (function_exists('get_locale') ? (string) get_locale() : 'en_US');
        }

        $language = function_exists('ultracache_wpml_normalize_language_code')
            ? ultracache_wpml_normalize_language_code($payload['lng'] ?? '')
            : strtolower(trim((string) ($payload['lng'] ?? '')));

        return array(
            'definition' => $definition,
            'context'    => $context,
            'locale'     => $locale,
            'language'   => $language,
        );
    }


    /**
     * Return a stable hash for one normalized fragment context.
     *
     * @param string $fragment_id Fragment ID.
     * @param array  $context     Fragment context.
     * @return string
     */
    public function get_context_hash($fragment_id, array $context = array())
    {
        $definition = $this->get($fragment_id);
        if (null === $definition) {
            return '';
        }

        $context = $this->normalize_context($definition, $context);
        if (is_wp_error($context)) {
            return '';
        }

        $payload = wp_json_encode(
            array(
                'id' => (string) $definition['id'],
                'ctx' => $context,
                'loc' => function_exists('ultracache_litespeed_esi_get_current_locale')
                    ? ultracache_litespeed_esi_get_current_locale()
                    : (function_exists('get_locale') ? (string) get_locale() : 'en_US'),
                'lng' => function_exists('ultracache_litespeed_esi_get_current_language')
                    ? ultracache_litespeed_esi_get_current_language()
                    : '',
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return is_string($payload) ? substr(hash('sha256', $payload), 0, 16) : '';
    }

    /**
     * Return a callback-free fragment definition summary.
     *
     * @param string $fragment_id Fragment ID.
     * @return array<string,mixed>|null
     */
    public function get_definition_summary($fragment_id)
    {
        $definition = $this->get($fragment_id);
        if (null === $definition) {
            return null;
        }

        return array(
            'id' => (string) $definition['id'],
            'scope' => (string) ($definition['scope'] ?? 'public'),
            'ttl' => absint($definition['ttl'] ?? 0),
            'contextKeys' => array_values((array) ($definition['context_keys'] ?? array())),
            'cookieNames' => array_values((array) ($definition['cookie_names'] ?? array())),
            'cookiePrefixes' => array_values((array) ($definition['cookie_prefixes'] ?? array())),
            'maxContextBytes' => absint($definition['max_context_bytes'] ?? 1024),
            'maxContextValueBytes' => absint($definition['max_context_value_bytes'] ?? 200),
            'maxOutputBytes' => absint($definition['max_output_bytes'] ?? 262144),
            'maxCookieHeaderBytes' => absint($definition['max_cookie_header_bytes'] ?? 8192),
            'needsMainQuery' => !empty($definition['needs_main_query']),
            'privateTransportDeclared' => 'private' === (string) ($definition['scope'] ?? '')
                && function_exists('ultracache_litespeed_esi_private_definition_transport_is_declared')
                && ultracache_litespeed_esi_private_definition_transport_is_declared($definition),
        );
    }

    /**
     * Render a fragment callback or fallback with bounded output.
     *
     * @param array  $definition Fragment definition.
     * @param array  $context    Normalized context.
     * @param string $mode       renderer or fallback.
     * @return string|WP_Error
     */
    public function render(array $definition, array $context, $mode = 'renderer')
    {
        if ($this->render_depth >= 1) {
            return new WP_Error('ultracache_litespeed_esi_nested_fragment_not_supported', __('Nested ESI fragment rendering is not supported in this version.', 'ultracache'));
        }

        $callback = 'fallback' === $mode ? ($definition['fallback'] ?? '') : ($definition['renderer'] ?? null);
        if (is_string($callback) && !is_callable($callback)) {
            return $this->bound_output($callback, $definition);
        }
        if (!is_callable($callback)) {
            return new WP_Error('ultracache_litespeed_esi_renderer_not_callable', __('The ESI fragment renderer is not callable.', 'ultracache'));
        }

        $this->render_depth++;
        $base_buffer_level = ob_get_level();
        ob_start();
        try {
            $returned = call_user_func($callback, $context, $definition);
            if (ob_get_level() <= $base_buffer_level) {
                throw new RuntimeException('The ESI fragment renderer closed the UltraCache output buffer.');
            }

            $echoed = '';
            while (ob_get_level() > $base_buffer_level) {
                $echoed = (string) ob_get_clean() . $echoed;
            }
            if (is_wp_error($returned)) {
                $this->render_depth--;
                return $returned;
            }

        } catch (Throwable $e) {
            while (ob_get_level() > $base_buffer_level) {
                ob_end_clean();
            }
            $this->render_depth--;
            do_action('ultracache_litespeed_esi_fragment_render_error', $definition['id'] ?? '', $e);
            return new WP_Error('ultracache_litespeed_esi_fragment_render_failed', __('The ESI fragment renderer failed.', 'ultracache'));
        }
        $this->render_depth--;

        $output = $echoed;
        if (is_string($returned) || is_numeric($returned)) {
            $output .= (string) $returned;
        }

        return $this->bound_output($output, $definition);
    }

    /**
     * Return only cookies explicitly allowed by a private fragment definition.
     *
     * The filtered HTTP Cookie header remains in raw wire format, while the
     * cookie map mirrors PHP's single rawurldecode() value handling. Duplicate
     * cookie names keep the first value, matching PHP request parsing.
     *
     * @param array  $definition   Fragment definition.
     * @param string $cookie_header Raw Cookie request header.
     * @return array{header:string,cookies:array<string,string>,count:int}|WP_Error
     */
    public function filter_cookie_header(array $definition, $cookie_header)
    {
        if ('private' !== (string) ($definition['scope'] ?? '')) {
            return array('header' => '', 'cookies' => array(), 'count' => 0);
        }

        $cookie_header = trim((string) $cookie_header);
        $max_header_bytes = max(256, min(16384, absint($definition['max_cookie_header_bytes'] ?? 8192)));
        $max_value_bytes = max(16, min(4096, absint($definition['max_cookie_value_bytes'] ?? 4096)));
        $max_cookie_count = max(1, min(24, absint($definition['max_cookie_count'] ?? 24)));
        if (strlen($cookie_header) > $max_header_bytes) {
            return new WP_Error('ultracache_litespeed_esi_private_cookie_header_too_large', __('The private ESI cookie header exceeds its configured limit.', 'ultracache'));
        }

        $allowed_names = array_fill_keys((array) ($definition['cookie_names'] ?? array()), true);
        $allowed_prefixes = (array) ($definition['cookie_prefixes'] ?? array());
        $raw_cookies = array();
        $decoded_cookies = array();
        foreach (array_slice(explode(';', $cookie_header), 0, 64) as $pair) {
            $pair = trim((string) $pair);
            if ('' === $pair || false === strpos($pair, '=')) {
                continue;
            }

            list($name, $raw_value) = array_map('trim', explode('=', $pair, 2));
            if (
                '' === $name
                || isset($raw_cookies[$name])
                || 1 !== preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]{1,128}$/", $name)
            ) {
                continue;
            }

            $allowed = isset($allowed_names[$name]);
            if (!$allowed) {
                foreach ($allowed_prefixes as $prefix) {
                    if ('' !== $prefix && 0 === strpos($name, $prefix)) {
                        $allowed = true;
                        break;
                    }
                }
            }
            if (
                !$allowed
                || strlen($raw_value) > $max_value_bytes
                || 1 === preg_match('/[\x00-\x20\x7f]/', $raw_value)
            ) {
                continue;
            }

            $decoded_value = rawurldecode($raw_value);
            if (
                strlen($decoded_value) > $max_value_bytes
                || 1 === preg_match('/[\x00-\x1f\x7f]/', $decoded_value)
            ) {
                continue;
            }

            $raw_cookies[$name] = $raw_value;
            $decoded_cookies[$name] = $decoded_value;
            if (count($decoded_cookies) >= $max_cookie_count) {
                break;
            }
        }

        $pairs = array();
        foreach ($raw_cookies as $name => $raw_value) {
            $pairs[] = $name . '=' . $raw_value;
        }
        $filtered_header = implode('; ', $pairs);
        if (strlen($filtered_header) > $max_header_bytes) {
            return new WP_Error('ultracache_litespeed_esi_private_cookie_header_too_large', __('The filtered private ESI cookie header exceeds its configured limit.', 'ultracache'));
        }

        return array(
            'header' => $filtered_header,
            'cookies' => $decoded_cookies,
            'count' => count($decoded_cookies),
        );
    }

    /**
     * Normalize exact cookie names or cookie-name prefixes.
     *
     * @param array $values Cookie identifiers.
     * @param bool  $prefix Whether identifiers are prefixes.
     * @return array<int,string>
     */
    private function normalize_cookie_identifiers(array $values, $prefix)
    {
        $normalized = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ('' === $value || strlen($value) > 128 || 1 !== preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $value)) {
                continue;
            }
            $normalized[$value] = $value;
            if (count($normalized) >= 16) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize a fragment ID.
     *
     * @param mixed $fragment_id Fragment ID.
     * @return string
     */
    private function normalize_fragment_id($fragment_id)
    {
        $fragment_id = sanitize_key((string) $fragment_id);
        if ('' === $fragment_id || strlen($fragment_id) > 64 || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $fragment_id)) {
            return '';
        }

        return $fragment_id;
    }

    /**
     * Bound renderer output.
     *
     * @param string $output     Renderer output.
     * @param array  $definition Fragment definition.
     * @return string|WP_Error
     */
    private function bound_output($output, array $definition)
    {
        $output = (string) $output;
        $max_bytes = max(1024, absint($definition['max_output_bytes'] ?? 262144));
        if (strlen($output) > $max_bytes) {
            return new WP_Error('ultracache_litespeed_esi_fragment_output_too_large', __('The ESI fragment output exceeds its configured limit.', 'ultracache'));
        }

        return $output;
    }

    /**
     * Base64url encode bytes.
     *
     * @param string $value Bytes.
     * @return string
     */
    private function base64url_encode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    /**
     * Base64url decode bytes.
     *
     * @param string $value Encoded value.
     * @return string|false
     */
    private function base64url_decode($value)
    {
        $value = strtr((string) $value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($value, true);
    }
}
