<?php
/**
 * Final HTML conversion for UltraCache ESI placeholders.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Varnish_ESI_Rendering_Trait
{
    /**
     * Apply ESI conversion to WordPress template-enhancement output even when
     * the local page-cache STORE callback is not active.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    public function apply_varnish_esi_template_output_buffer($html)
    {
        if (method_exists($this, 'apply_woocommerce_varnish_esi_auto_mini_cart_placeholders')) {
            $html = $this->apply_woocommerce_varnish_esi_auto_mini_cart_placeholders($html);
        }

        if (
            method_exists($this, 'profile_request_checkpoint')
            && method_exists($this, 'is_store_profiler_enabled')
            && $this->is_store_profiler_enabled()
        ) {
            $diagnostics = class_exists('Ultra_Cache_Varnish_ESI_Registry')
                && method_exists(Ultra_Cache_Varnish_ESI_Registry::instance(), 'get_template_buffer_diagnostics')
                ? Ultra_Cache_Varnish_ESI_Registry::instance()->get_template_buffer_diagnostics()
                : array();
            $this->profile_request_checkpoint('esi_template_output_buffer_apply', array(
                'html_bytes' => is_string($html) ? strlen($html) : 0,
                'marker_present' => is_string($html) && false !== strpos($html, '<!--ultracache-esi-start:v1:') ? 'yes' : 'no',
                'fragment_count' => (int) ($diagnostics['fragment_count'] ?? 0),
                'fragment_count_at_decision' => (int) ($diagnostics['fragment_count_at_decision'] ?? 0),
                'late_fragment_count' => (int) ($diagnostics['late_fragment_count'] ?? 0),
                'registered_after_init_started_count' => (int) ($diagnostics['registered_after_init_started_count'] ?? 0),
                'registration_hooks' => implode(',', array_slice((array) ($diagnostics['registration_hooks'] ?? array()), 0, 16)),
                'late_registration_hooks' => implode(',', array_slice((array) ($diagnostics['late_registration_hooks'] ?? array()), 0, 16)),
            ));
        }

        return $this->apply_varnish_esi_placeholders_before_cache_store($html);
    }

    /**
     * Convert validated UltraCache placeholders to same-origin ESI markup.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    private function apply_varnish_esi_placeholders_before_cache_store($html)
    {
        if (!is_string($html) || '' === $html || !function_exists('ultracache_varnish_esi_is_enabled') || !ultracache_varnish_esi_is_enabled()) {
            return $html;
        }
        if (false === strpos($html, '<!--ultracache-esi-start:v1:')) {
            return $html;
        }

        $html = $this->neutralize_untrusted_varnish_esi_markup($html);
        $max_fragments = function_exists('ultracache_varnish_esi_get_max_parent_fragments')
            ? ultracache_varnish_esi_get_max_parent_fragments()
            : 32;
        $max_directive_bytes = function_exists('ultracache_varnish_esi_get_max_parent_directive_bytes')
            ? ultracache_varnish_esi_get_max_parent_directive_bytes()
            : 65536;
        $emitted_fragments = 0;
        $emitted_directive_bytes = 0;
        $pattern = '/<!--ultracache-esi-start:v1:([A-Za-z0-9_-]+\.[a-f0-9]{64})-->(.*?)<!--ultracache-esi-end:v1:\1-->/s';
        $rewritten = preg_replace_callback(
            $pattern,
            function ($matches) use (&$emitted_fragments, &$emitted_directive_bytes, $max_fragments, $max_directive_bytes) {
                $token = isset($matches[1]) ? (string) $matches[1] : '';
                $fallback = isset($matches[2]) ? (string) $matches[2] : '';
                $decoded = Ultra_Cache_Varnish_ESI_Registry::instance()->decode_context_token($token);
                if (is_wp_error($decoded)) {
                    do_action('ultracache_esi_placeholder_error', $decoded, $token);
                    return $fallback;
                }

                $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
                $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
                if (
                    'private' === $scope
                    && (
                        !function_exists('ultracache_varnish_esi_private_definition_is_enabled')
                        || !ultracache_varnish_esi_private_definition_is_enabled($definition)
                    )
                ) {
                    return $fallback;
                }

                $endpoint_args = array('ultracache_esi' => $token);
                if ('private' === $scope) {
                    $endpoint_args['esi_scope'] = 'private';
                }
                $endpoint_url = add_query_arg($endpoint_args, home_url('/'));
                $endpoint_url = wp_make_link_relative($endpoint_url);
                if (!is_string($endpoint_url) || '' === $endpoint_url || '/' !== substr($endpoint_url, 0, 1)) {
                    return $fallback;
                }

                $onerror = 'private' === $scope
                    && function_exists('ultracache_varnish_esi_private_onerror_is_enabled')
                    && ultracache_varnish_esi_private_onerror_is_enabled()
                    ? ' onerror="continue"'
                    : '';
                $directive = '<esi:include src="' . esc_attr($endpoint_url) . '"' . $onerror . ' />';
                $directive_bytes = strlen($directive);
                if (
                    $emitted_fragments >= $max_fragments
                    || ($emitted_directive_bytes + $directive_bytes) > $max_directive_bytes
                ) {
                    do_action(
                        'ultracache_esi_parent_limit_reached',
                        $definition['id'] ?? '',
                        $emitted_fragments,
                        $emitted_directive_bytes
                    );
                    return $fallback;
                }

                $emitted_fragments++;
                $emitted_directive_bytes += $directive_bytes;
                return $directive . '<esi:remove>' . $fallback . '</esi:remove>';
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Return canonical metadata for an HTML document containing trusted ESI
     * includes generated by this engine.
     *
     * @param string $html Full frontend HTML.
     * @return array
     */
    private function get_varnish_esi_parent_metadata_from_html($html)
    {
        $html = (string) $html;
        if ('' === $html || false === stripos($html, '<esi:include')) {
            return array();
        }

        $count = preg_match_all('/<esi:include\s+src="(\/[^"<>]+)"(?:\s+onerror="continue")?\s*\/>/i', $html, $matches);
        $max_fragments = function_exists('ultracache_varnish_esi_get_max_parent_fragments') ? ultracache_varnish_esi_get_max_parent_fragments() : 32;
        $count = false === $count ? 0 : max(0, min($max_fragments, (int) $count));
        if ($count <= 0) {
            return array();
        }

        $fragment_ids = array();
        $ttl_values = array();
        $public_count = 0;
        $private_count = 0;
        foreach (array_slice((array) ($matches[1] ?? array()), 0, $max_fragments) as $endpoint_url) {
            $endpoint_url = html_entity_decode((string) $endpoint_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $query = (string) wp_parse_url($endpoint_url, PHP_URL_QUERY);
            if ('' === $query) {
                continue;
            }

            parse_str($query, $query_args);
            $token = isset($query_args['ultracache_esi']) && is_string($query_args['ultracache_esi'])
                ? rawurldecode($query_args['ultracache_esi'])
                : '';
            if ('' === $token) {
                continue;
            }

            $decoded = Ultra_Cache_Varnish_ESI_Registry::instance()->decode_context_token($token);
            if (is_wp_error($decoded)) {
                continue;
            }

            $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
            $fragment_id = sanitize_key((string) ($definition['id'] ?? ''));
            if ('' !== $fragment_id) {
                $fragment_ids[$fragment_id] = true;
            }
            if ('private' === (string) ($definition['scope'] ?? '')) {
                $private_count++;
            } else {
                $public_count++;
                $ttl = max(1, min(WEEK_IN_SECONDS, absint($definition['ttl'] ?? 300)));
                $ttl_values[] = $ttl;
            }
        }

        return array(
            'version'                 => 4,
            'fragmentCount'           => $count,
            'publicCount'             => $public_count,
            'privateCount'            => $private_count,
            'uniqueFragmentCount'     => count($fragment_ids),
            'woocommerceMiniCart'     => isset($fragment_ids['woocommerce-mini-cart']),
            'minTtl'                  => empty($ttl_values) ? 0 : min($ttl_values),
            'maxTtl'                  => empty($ttl_values) ? 0 : max($ttl_values),
        );
    }

    /**
     * Neutralize raw ESI directives before trusted UltraCache directives are
     * inserted. This keeps arbitrary theme, plugin, editor, and user content
     * from creating Varnish subrequests on an ESI-enabled parent response.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    private function neutralize_untrusted_varnish_esi_markup($html)
    {
        $html = preg_replace_callback('/<\s*(\/?)\s*esi:/i', static function ($matches) {
            return '&lt;' . (!empty($matches[1]) ? '/' : '') . 'esi:';
        }, (string) $html);
        $html = preg_replace('/<!--\s*esi\b/i', '&lt;!--esi', (string) $html);

        return is_string($html) ? $html : '';
    }

}
