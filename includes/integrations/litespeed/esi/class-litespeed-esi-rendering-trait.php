<?php
/**
 * Native LiteSpeed ESI placeholder conversion.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LiteSpeed_ESI_Rendering_Trait
{
    /**
     * Convert trusted LiteSpeed ESI placeholders in the final frontend HTML.
     *
     * @param string $html Full HTML.
     * @return string
     */
    public function apply_litespeed_esi_template_output_buffer($html)
    {
        if (!is_string($html) || '' === $html || !function_exists('ultracache_litespeed_esi_is_enabled') || !ultracache_litespeed_esi_is_enabled()) {
            return $html;
        }
        if (false === strpos($html, '<!--ultracache-litespeed-esi-start:v1:')) {
            return $html;
        }

        $html = $this->neutralize_untrusted_litespeed_esi_markup($html);

        $max_fragments = function_exists('ultracache_litespeed_esi_get_max_parent_fragments')
            ? ultracache_litespeed_esi_get_max_parent_fragments()
            : 32;
        $max_directive_bytes = function_exists('ultracache_litespeed_esi_get_max_parent_directive_bytes')
            ? ultracache_litespeed_esi_get_max_parent_directive_bytes()
            : 65536;
        $emitted_fragments = 0;
        $emitted_directive_bytes = 0;
        $pattern = '/<!--ultracache-litespeed-esi-start:v1:([A-Za-z0-9_-]+\.[a-f0-9]{64})-->(.*?)<!--ultracache-litespeed-esi-end:v1:\1-->/s';

        $rewritten = preg_replace_callback(
            $pattern,
            function ($matches) use (&$emitted_fragments, &$emitted_directive_bytes, $max_fragments, $max_directive_bytes) {
                $token = isset($matches[1]) ? (string) $matches[1] : '';
                $fallback = isset($matches[2]) ? (string) $matches[2] : '';
                $decoded = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->decode_context_token($token);
                if (is_wp_error($decoded)) {
                    do_action('ultracache_litespeed_esi_placeholder_error', $decoded, $token);
                    return $fallback;
                }

                $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
                $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
                if ('private' === $scope && !ultracache_litespeed_esi_private_definition_is_enabled($definition)) {
                    return $fallback;
                }

                $endpoint_url = add_query_arg(array('ultracache_litespeed_esi' => $token), home_url('/'));
                $endpoint_url = wp_make_link_relative($endpoint_url);
                if (!is_string($endpoint_url) || '' === $endpoint_url || '/' !== substr($endpoint_url, 0, 1)) {
                    return $fallback;
                }

                if ('private' === $scope) {
                    // Mini-cart/session fragments must reflect current request state.
                    $cache_control = 'no-cache';
                } else {
                    $ttl = max(1, min(WEEK_IN_SECONDS, absint($definition['ttl'] ?? 300)));
                    $cache_control = 'public,max-age=' . $ttl;
                }

                $include = '<esi:include src="' . esc_attr($endpoint_url) . '" cache-control="' . esc_attr($cache_control) . '" />';
                $directive = '<esi:try><esi:attempt>' . $include . '</esi:attempt><esi:except>' . $fallback . '</esi:except></esi:try>';
                $directive_bytes = strlen($directive);
                if ($emitted_fragments >= $max_fragments || ($emitted_directive_bytes + $directive_bytes) > $max_directive_bytes) {
                    do_action('ultracache_litespeed_esi_parent_limit_reached', $definition['id'] ?? '', $emitted_fragments, $emitted_directive_bytes);
                    return $fallback;
                }

                $emitted_fragments++;
                $emitted_directive_bytes += $directive_bytes;
                return $directive;
            },
            $html
        );

        if (!is_string($rewritten)) {
            return $html;
        }

        if ($emitted_fragments > 0) {
            $this->enable_litespeed_esi_parent_response();
        }

        return $rewritten;
    }

    /**
     * Detect a finalized UltraCache native LiteSpeed ESI parent.
     *
     * This intentionally recognizes only the signed UltraCache ESI endpoint
     * emitted by apply_litespeed_esi_template_output_buffer(). LiteSpeed ESI is
     * runtime-gated and remains disabled when Varnish is the active frontend.
     *
     * @param string $html Final HTML.
     * @return array
     */
    private function get_litespeed_esi_parent_metadata_from_html($html)
    {
        if (
            !is_string($html)
            || '' === $html
            || !function_exists('ultracache_litespeed_esi_is_enabled')
            || !ultracache_litespeed_esi_is_enabled()
            || false === strpos($html, 'ultracache_litespeed_esi=')
        ) {
            return array();
        }

        $fragment_count = preg_match_all(
            '/<esi:include\b[^>]*\bsrc=["\'][^"\']*[?&]ultracache_litespeed_esi=[A-Za-z0-9_-]+\.[a-f0-9]{64}[^"\']*["\'][^>]*>/i',
            $html,
            $matches
        );
        $fragment_count = is_int($fragment_count) ? max(0, min(64, $fragment_count)) : 0;
        if ($fragment_count <= 0) {
            return array();
        }

        return array(
            'version'             => 1,
            'fragmentCount'       => $fragment_count,
            'woocommerceMiniCart' => false !== strpos($html, 'data-ultracache-esi-adapter="woocommerce-litespeed-classic-mini-cart"'),
        );
    }

    /**
     * Neutralize raw ESI directives before inserting signed UltraCache ones.
     *
     * @param string $html Full HTML.
     * @return string
     */
    private function neutralize_untrusted_litespeed_esi_markup($html)
    {
        $html = preg_replace_callback('/<\s*(\/?)\s*esi(?=[:_])/i', static function ($matches) {
            return '&lt;' . (!empty($matches[1]) ? '/' : '') . 'esi';
        }, (string) $html);
        $html = preg_replace('/<!--\s*esi\b/i', '&lt;!--esi', (string) $html);
        return is_string($html) ? $html : '';
    }

    /**
     * Append esi=on to the native LiteSpeed parent cache contract.
     *
     * @return void
     */
    private function enable_litespeed_esi_parent_response()
    {
        if (!function_exists('ultracache_litespeed_esi_is_enabled') || !ultracache_litespeed_esi_is_enabled()) {
            return;
        }

        $this->litespeed_esi_parent_response_enabled = true;

        if (headers_sent()) {
            return;
        }

        $existing = '';
        foreach (headers_list() as $header_line) {
            if (0 === stripos((string) $header_line, 'X-LiteSpeed-Cache-Control:')) {
                $existing = trim(substr((string) $header_line, strlen('X-LiteSpeed-Cache-Control:')));
            }
        }

        if ('' === $existing) {
            $settings = $this->get_settings();
            $seconds = method_exists($this, 'get_litespeed_html_ttl_seconds')
                ? $this->get_litespeed_html_ttl_seconds($settings)
                : 0;
            if ($seconds <= 0) {
                return;
            }
            $existing = 'public,max-age=' . (string) $seconds;
        }

        if (false === stripos($existing, 'esi=on')) {
            $existing .= ',esi=on';
        }

        header('X-LiteSpeed-Cache-Control: ' . $existing, true);
        header('X-UltraCache-LiteSpeed-ESI: 1', true);
    }
}
