<?php
/**
 * Same-origin public and private ESI fragment endpoints.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_ESI_Endpoint_Trait
{
    /**
     * Register the ESI query variable.
     *
     * @param array $query_vars Public query variables.
     * @return array
     */
    public function register_esi_query_var(array $query_vars)
    {
        $query_vars[] = 'ultracache_esi';
        $query_vars[] = 'ultracache_esi_probe';
        $query_vars[] = 'ultracache_esi_probe_fragment';
        $query_vars[] = 'ultracache_esi_probe_private';
        $query_vars[] = 'ultracache_esi_probe_private_fragment';
        return array_values(array_unique($query_vars));
    }

    /**
     * Skip only the main posts query for a signed fragment that explicitly
     * declares it does not need main-query state.
     *
     * WordPress and integration bootstrap still run normally. Custom fragments
     * remain query-dependent unless they explicitly opt out in their registered
     * definition.
     *
     * @param array|null $posts Short-circuit posts value.
     * @param WP_Query   $query Current query object.
     * @return array|null
     */
    public function maybe_bypass_esi_fragment_main_query($posts, $query)
    {
        if (null !== $posts || !is_object($query) || !method_exists($query, 'is_main_query') || !$query->is_main_query()) {
            return $posts;
        }

        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return $posts;
        }

        $token = method_exists($query, 'get') ? $query->get('ultracache_esi') : '';
        if (!is_string($token) || '' === trim($token)) {
            return $posts;
        }

        $decoded = Ultra_Cache_ESI_Registry::instance()->decode_context_token(rawurldecode($token));
        if (is_wp_error($decoded)) {
            return $posts;
        }

        $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
        if (empty($definition) || !empty($definition['needs_main_query'])) {
            return $posts;
        }

        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $scope_enabled = 'private' === $scope
            ? function_exists('ultracache_esi_private_definition_is_enabled')
                && ultracache_esi_private_definition_is_enabled($definition)
            : function_exists('ultracache_esi_is_enabled') && ultracache_esi_is_enabled();
        if (!$scope_enabled) {
            return $posts;
        }

        do_action('ultracache_esi_main_query_bypassed', (string) ($definition['id'] ?? ''), $definition);
        return array();
    }

    /**
     * Serve one signed ESI fragment before normal template handling.
     *
     * @return void
     */
    public function maybe_serve_esi_fragment()
    {
        $probe_parent_token = get_query_var('ultracache_esi_probe', '');
        if (is_string($probe_parent_token) && '' !== trim($probe_parent_token)) {
            $this->serve_varnish_esi_probe_response($probe_parent_token, 'parent');
        }

        $probe_fragment_token = get_query_var('ultracache_esi_probe_fragment', '');
        if (is_string($probe_fragment_token) && '' !== trim($probe_fragment_token)) {
            $this->serve_varnish_esi_probe_response($probe_fragment_token, 'fragment');
        }

        $private_probe_parent_token = get_query_var('ultracache_esi_probe_private', '');
        if (is_string($private_probe_parent_token) && '' !== trim($private_probe_parent_token)) {
            $this->serve_varnish_private_esi_probe_response($private_probe_parent_token, 'parent');
        }

        $private_probe_fragment_token = get_query_var('ultracache_esi_probe_private_fragment', '');
        if (is_string($private_probe_fragment_token) && '' !== trim($private_probe_fragment_token)) {
            $this->serve_varnish_private_esi_probe_response($private_probe_fragment_token, 'fragment');
        }

        $token = get_query_var('ultracache_esi', '');
        if (!is_string($token) || '' === trim($token)) {
            return;
        }

        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            $this->send_esi_error_response(405);
        }

        $decoded = Ultra_Cache_ESI_Registry::instance()->decode_context_token(rawurldecode($token));
        if (is_wp_error($decoded)) {
            do_action('ultracache_esi_endpoint_error', $decoded, 'token');
            $this->send_esi_error_response(404);
        }

        $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
        $context = is_array($decoded['context'] ?? null) ? $decoded['context'] : array();
        $locale = isset($decoded['locale']) ? (string) $decoded['locale'] : '';
        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $scope_enabled = 'private' === $scope
            ? function_exists('ultracache_esi_private_definition_is_enabled')
                && ultracache_esi_private_definition_is_enabled($definition)
            : function_exists('ultracache_esi_is_enabled') && ultracache_esi_is_enabled();
        if (!$scope_enabled) {
            $this->send_esi_error_response(404);
        }

        $request_state = $this->prepare_esi_fragment_request_context($definition, $locale);
        if (is_wp_error($request_state)) {
            do_action('ultracache_esi_endpoint_error', $request_state, 'transport');
            $this->send_esi_error_response('private' === $scope ? 403 : 404);
        }

        $context_hash = Ultra_Cache_ESI_Registry::instance()->get_context_hash((string) $definition['id'], $context);
        if ('HEAD' === $method) {
            $this->restore_esi_fragment_request_context($request_state);
            $this->send_esi_fragment_success_headers(
                $definition,
                $scope,
                $context_hash,
                0,
                0,
                $request_state,
                false,
                true,
                ''
            );
            do_action('ultracache_esi_fragment_head', $definition['id'], $context, $definition);
            exit;
        }

        $render_started_at = microtime(true);
        $contained_error = false;
        $contained_error_code = '';
        try {
            do_action('ultracache_before_esi_fragment_render', $definition['id'], $context, $definition);
            $output = Ultra_Cache_ESI_Registry::instance()->render($definition, $context, 'renderer');
            if (is_wp_error($output)) {
                do_action('ultracache_esi_endpoint_error', $output, 'renderer');
            } else {
                $output = apply_filters('ultracache_esi_fragment_output', $output, $definition['id'], $context, $definition);
            }
        } catch (Throwable $e) {
            do_action('ultracache_esi_fragment_render_error', $definition['id'], $e);
            $output = new WP_Error('ultracache_esi_fragment_render_failed', __('The ESI fragment renderer failed.', 'ultracache'));
        }

        if (is_wp_error($output)) {
            $contained_error = true;
            $contained_error_code = sanitize_key((string) $output->get_error_code());
            $fallback = Ultra_Cache_ESI_Registry::instance()->render($definition, $context, 'fallback');
            $output = is_wp_error($fallback) ? $fallback : (string) $fallback;
            do_action('ultracache_esi_fragment_error_contained', $definition['id'], $context, $definition, $contained_error_code);
            if ('private' === $scope) {
                do_action('ultracache_esi_private_fragment_error_contained', $definition['id'], $context, $definition);
            }
        }

        if (!is_wp_error($output) && is_string($output)) {
            $output = $this->neutralize_esi_fragment_output($output);
        }

        $this->restore_esi_fragment_request_context($request_state);

        if (is_wp_error($output) || !is_string($output) || strlen($output) > (int) ($definition['max_output_bytes'] ?? 262144)) {
            $this->send_esi_error_response(500);
        }

        $output_bytes = strlen($output);
        $render_duration_ms = max(0, min(600000, (int) round((microtime(true) - $render_started_at) * 1000)));
        $this->send_esi_fragment_success_headers(
            $definition,
            $scope,
            $context_hash,
            $output_bytes,
            $render_duration_ms,
            $request_state,
            $contained_error,
            false,
            $contained_error_code
        );

        do_action('ultracache_after_esi_fragment_render', $definition['id'], $context, $definition, $output_bytes);
        do_action(
            'ultracache_esi_fragment_render_metrics',
            $definition['id'],
            $context_hash,
            $output_bytes,
            $render_duration_ms,
            absint($definition['ttl'] ?? 0),
            $scope
        );
        if ('HEAD' !== $method) {
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Registered renderer output is intentional frontend HTML.
        }
        exit;
    }

    /**
     * Send bounded success headers for a fragment GET or HEAD response.
     *
     * @param array  $definition          Fragment definition.
     * @param string $scope               public or private.
     * @param string $context_hash        Stable context hash.
     * @param int    $output_bytes        Rendered output bytes.
     * @param int    $render_duration_ms  Render duration.
     * @param array  $request_state       Restricted request state.
     * @param bool   $contained_error     Whether fallback contained an error.
     * @param bool   $head_only           Whether this is a HEAD response.
     * @param string $contained_error_code Stable contained error code.
     * @return void
     */
    private function send_esi_fragment_success_headers(
        array $definition,
        $scope,
        $context_hash,
        $output_bytes,
        $render_duration_ms,
        array $request_state,
        $contained_error,
        $head_only,
        $contained_error_code
    ) {
        if (headers_sent()) {
            return;
        }

        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header_remove('Set-Cookie');
        header_remove('Surrogate-Control');
        header_remove('Vary');
        header_remove('ETag');
        header_remove('Last-Modified');
        header_remove('Content-Length');
        header_remove('X-UltraCache-Cacheable');
        header_remove('X-UltraCache-Surrogate-TTL');
        header_remove('X-UltraCache-Stale-While-Revalidate');
        status_header(200);
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        if ($contained_error || $head_only || 'private' === $scope) {
            header('Cache-Control: private, no-store, max-age=0, must-revalidate');
            header('Surrogate-Control: no-store');
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
        } else {
            header('Cache-Control: public, max-age=0, s-maxage=' . (string) absint($definition['ttl']));
            header('X-UltraCache-Cacheable: 1');
            header('X-UltraCache-Surrogate-TTL: ' . (string) absint($definition['ttl']));
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-UltraCache-ESI-Fragment: ' . sanitize_key((string) $definition['id']));
        header('X-UltraCache-ESI-Scope: ' . $scope);
        header('X-UltraCache-ESI-TTL: ' . (string) absint($definition['ttl'] ?? 0));
        header('X-UltraCache-ESI-Context: ' . $context_hash);
        header('X-UltraCache-ESI-Bytes: ' . (string) max(0, (int) $output_bytes));
        header('X-UltraCache-ESI-Render-Ms: ' . (string) max(0, (int) $render_duration_ms));
        if ($head_only) {
            header('X-UltraCache-ESI-Head: 1');
        }
        if ('private' === $scope) {
            header('X-UltraCache-ESI-Private: 1');
            header('X-UltraCache-ESI-Cookie-Count: ' . (string) absint($request_state['cookieCount'] ?? 0));
        }
        if ($contained_error) {
            header('X-UltraCache-ESI-Error-Contained: 1');
            if ('' !== $contained_error_code) {
                header('X-UltraCache-ESI-Error-Code: ' . substr($contained_error_code, 0, 64));
            }
        }
    }

    /**
     * Prevent fragment output from introducing nested or untrusted ESI markup.
     *
     * @param string $output Fragment HTML.
     * @return string
     */
    private function neutralize_esi_fragment_output($output)
    {
        $output = preg_replace_callback('/<\s*(\/?)\s*esi:/i', static function ($matches) {
            return '&lt;' . (!empty($matches[1]) ? '/' : '') . 'esi:';
        }, (string) $output);
        $output = preg_replace('/<!--\s*esi\b/i', '&lt;!--esi', (string) $output);

        return is_string($output) ? $output : '';
    }

    /**
     * Restrict the active request to a fragment's cookie scope.
     *
     * @param array  $definition Fragment definition.
     * @param string $locale     Signed parent locale.
     * @return array|WP_Error
     */
    private function prepare_esi_fragment_request_context(array $definition, $locale = '')
    {
        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $cookie_header_present = isset($_SERVER['HTTP_COOKIE']);
        $cookie_header = $cookie_header_present ? ultracache_server_value('HTTP_COOKIE') : '';
        $state = array(
            'cookies' => isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array(),
            'cookieHeaderPresent' => $cookie_header_present,
            'cookieHeader' => $cookie_header,
            'userId' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'cookieCount' => 0,
            'localeSwitched' => false,
        );

        if ('private' === $scope) {
            $internal_marker = trim((string) ultracache_server_value('HTTP_X_ESI_PRIVATE_REQUEST'));
            $request_level = absint(ultracache_server_value('HTTP_X_ESI_REQUEST_LEVEL'));
            if ('1' !== $internal_marker || $request_level < 1) {
                return new WP_Error('ultracache_esi_private_transport_required', __('The private ESI request did not arrive through the verified internal transport.', 'ultracache'));
            }

            $filtered = Ultra_Cache_ESI_Registry::instance()->filter_cookie_header(
                $definition,
                (string) ultracache_server_value('HTTP_COOKIE')
            );
            if (is_wp_error($filtered)) {
                return $filtered;
            }

            $_COOKIE = (array) ($filtered['cookies'] ?? array());
            $_SERVER['HTTP_COOKIE'] = (string) ($filtered['header'] ?? '');
            $state['cookieCount'] = absint($filtered['count'] ?? 0);
        } else {
            $_COOKIE = array();
            $_SERVER['HTTP_COOKIE'] = '';
        }

        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
            if ('private' === $scope && function_exists('wp_validate_auth_cookie')) {
                $user_id = (int) wp_validate_auth_cookie('', 'logged_in');
                if ($user_id > 0) {
                    wp_set_current_user($user_id);
                }
            }
        }

        $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
        if (
            is_string($locale)
            && strlen($locale) >= 2
            && strlen($locale) <= 32
            && function_exists('switch_to_locale')
        ) {
            $state['localeSwitched'] = (bool) switch_to_locale($locale);
        }

        return $state;
    }

    /**
     * Restore request globals after fragment rendering.
     *
     * @param array $state Saved request state.
     * @return void
     */
    private function restore_esi_fragment_request_context(array $state)
    {
        $_COOKIE = isset($state['cookies']) && is_array($state['cookies']) ? $state['cookies'] : array();
        if (!empty($state['cookieHeaderPresent'])) {
            $_SERVER['HTTP_COOKIE'] = (string) ($state['cookieHeader'] ?? '');
        } else {
            unset($_SERVER['HTTP_COOKIE']);
        }
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user((int) ($state['userId'] ?? 0));
        }
        if (!empty($state['localeSwitched']) && function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
    }


    /**
     * Serve a short-lived parent or fragment response used by Test Varnish.
     *
     * @param string $token Signed probe token.
     * @param string $kind  parent or fragment.
     * @return void
     */
    private function serve_varnish_esi_probe_response($token, $kind)
    {
        $kind = 'fragment' === $kind ? 'fragment' : 'parent';
        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            $this->send_esi_error_response(405);
        }

        if (!method_exists(static::class, 'decode_varnish_esi_probe_token')) {
            $this->send_esi_error_response(404);
        }
        $decoded = self::decode_varnish_esi_probe_token(rawurldecode((string) $token), $kind);
        if (is_wp_error($decoded)) {
            do_action('ultracache_esi_endpoint_error', $decoded, 'probe-' . $kind);
            $this->send_esi_error_response(404);
        }

        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
        $_COOKIE = array();
        $_SERVER['HTTP_COOKIE'] = '';

        $nonce = (string) ($decoded['nonce'] ?? '');
        $body = '';
        if ('fragment' === $kind) {
            try {
                $render_token = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $render_token = substr(md5(uniqid('ultracache-esi-render-', true)), 0, 16);
            }
            $body = '<span data-ultracache-esi-probe="fragment">ULTRACACHE_ESI_FRAGMENT_' . esc_html($nonce)
                . ' ULTRACACHE_ESI_RENDER_' . esc_html($render_token) . '</span>';
        } else {
            $fragment_token = self::create_varnish_esi_probe_token('fragment', $nonce, (int) ($decoded['exp'] ?? (time() + 300)));
            $fragment_url = add_query_arg('ultracache_esi_probe_fragment', $fragment_token, home_url('/'));
            $fragment_url = wp_make_link_relative($fragment_url);
            if (!is_string($fragment_url) || '' === $fragment_url || '/' !== substr($fragment_url, 0, 1)) {
                $this->send_esi_error_response(500);
            }

            $body = '<!doctype html><html><head><meta charset="utf-8"><title>ESI capability probe</title></head><body>'
                . '<div data-ultracache-esi-probe="parent">ULTRACACHE_ESI_PARENT_' . esc_html($nonce) . '</div>'
                . '<esi:include src="' . esc_attr($fragment_url) . '" />'
                . '<esi:remove><span data-ultracache-esi-probe="fallback">ULTRACACHE_ESI_FALLBACK_' . esc_html($nonce) . '</span></esi:remove>'
                . '</body></html>';
        }

        if (!headers_sent()) {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header_remove('Set-Cookie');
            header_remove('Surrogate-Control');
            header_remove('Vary');
            status_header(200);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: public, max-age=0, s-maxage=120');
            header('Vary: Accept-Encoding');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header('X-UltraCache-ESI-Probe: ' . $kind);
            if ('parent' === $kind) {
                header('Surrogate-Control: content="ESI/1.0"');
            }
        }

        if ('HEAD' !== $method) {
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed diagnostic HTML is generated entirely by UltraCache.
        }
        exit;
    }

    /**
     * Read the bounded raw Cookie header used by the private ESI transport probe.
     *
     * WooCommerce may normalize its public cart cookies before the signed probe
     * endpoint executes. The transport proof therefore reads only the original
     * backend Cookie header and never uses these values outside diagnostics.
     *
     * @return array<string,string>
     */
    private function get_varnish_private_probe_transport_cookies()
    {
        $filtered = Ultra_Cache_ESI_Registry::instance()->filter_cookie_header(
            array(
                'scope' => 'private',
                'cookie_names' => array(
                    'esi_session',
                    'woocommerce_items_in_cart',
                    'woocommerce_cart_hash',
                    'wp_woocommerce_session_probe',
                ),
                'cookie_prefixes' => array(),
                'max_cookie_header_bytes' => 8192,
                'max_cookie_value_bytes' => 256,
                'max_cookie_count' => 4,
            ),
            (string) ultracache_server_value('HTTP_COOKIE')
        );

        return is_wp_error($filtered) ? array() : (array) ($filtered['cookies'] ?? array());
    }

    /**
     * Serve the private/session ESI transport capability probe.
     *
     * @param string $token Signed probe token.
     * @param string $kind  parent or fragment.
     * @return void
     */
    private function serve_varnish_private_esi_probe_response($token, $kind)
    {
        $kind = 'fragment' === $kind ? 'fragment' : 'parent';
        $token_kind = 'fragment' === $kind ? 'private-fragment' : 'private-parent';
        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            $this->send_esi_error_response(405);
        }

        if (!method_exists(static::class, 'decode_varnish_esi_probe_token')) {
            $this->send_esi_error_response(404);
        }
        $decoded = self::decode_varnish_esi_probe_token(rawurldecode((string) $token), $token_kind);
        if (is_wp_error($decoded)) {
            do_action('ultracache_esi_endpoint_error', $decoded, 'probe-' . $token_kind);
            $this->send_esi_error_response(404);
        }

        $nonce = (string) ($decoded['nonce'] ?? '');
        $body = '';
        if ('fragment' === $kind) {
            $internal_marker = trim((string) ultracache_server_value('HTTP_X_ESI_PRIVATE_REQUEST'));
            $request_level = absint(ultracache_server_value('HTTP_X_ESI_REQUEST_LEVEL'));
            if ('1' !== $internal_marker || $request_level < 1) {
                $this->send_esi_error_response(403);
            }

            $transport_cookies = $this->get_varnish_private_probe_transport_cookies();
            $session = sanitize_key((string) ($transport_cookies['esi_session'] ?? ''));
            if ('' === $session || strlen($session) > 64) {
                $this->send_esi_error_response(403);
            }
            $woocommerce_items = sanitize_key((string) ($transport_cookies['woocommerce_items_in_cart'] ?? ''));
            $woocommerce_hash = sanitize_key((string) ($transport_cookies['woocommerce_cart_hash'] ?? ''));
            $woocommerce_session = sanitize_key((string) ($transport_cookies['wp_woocommerce_session_probe'] ?? ''));
            try {
                $render_token = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $render_token = substr(md5(uniqid('ultracache-esi-private-render-', true)), 0, 16);
            }
            $body = '<span data-ultracache-esi-probe="private-fragment">ULTRACACHE_ESI_PRIVATE_FRAGMENT_' . esc_html($nonce)
                . ' ULTRACACHE_ESI_PRIVATE_SESSION_' . esc_html($session)
                . ' ULTRACACHE_ESI_WOO_ITEMS_' . esc_html($woocommerce_items)
                . ' ULTRACACHE_ESI_WOO_HASH_' . esc_html($woocommerce_hash)
                . ' ULTRACACHE_ESI_WOO_SESSION_' . esc_html($woocommerce_session)
                . ' ULTRACACHE_ESI_PRIVATE_RENDER_' . esc_html($render_token) . '</span>';
        } else {
            try {
                $parent_render_token = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $parent_render_token = substr(md5(uniqid('ultracache-esi-private-parent-', true)), 0, 16);
            }
            $fragment_token = self::create_varnish_esi_probe_token('private-fragment', $nonce, (int) ($decoded['exp'] ?? (time() + 300)));
            $fragment_url = add_query_arg(
                array(
                    'ultracache_esi_probe_private_fragment' => $fragment_token,
                    'esi_scope' => 'private',
                ),
                home_url('/')
            );
            $fragment_url = wp_make_link_relative($fragment_url);
            if (!is_string($fragment_url) || '' === $fragment_url || '/' !== substr($fragment_url, 0, 1)) {
                $this->send_esi_error_response(500);
            }

            $failing_url = add_query_arg(
                array(
                    'ultracache_esi_probe_private_fragment' => 'invalid',
                    'esi_scope' => 'private',
                ),
                home_url('/')
            );
            $failing_url = wp_make_link_relative($failing_url);
            $body = '<!doctype html><html><head><meta charset="utf-8"><title>Private ESI capability probe</title></head><body>'
                . '<div data-ultracache-esi-probe="private-parent">ULTRACACHE_ESI_PRIVATE_PARENT_' . esc_html($nonce)
                . ' ULTRACACHE_ESI_PRIVATE_PARENT_RENDER_' . esc_html($parent_render_token) . '</div>'
                . '<esi:include src="' . esc_attr($fragment_url) . '" onerror="continue" />'
                . '<esi:remove><span>ULTRACACHE_ESI_PRIVATE_FALLBACK_' . esc_html($nonce) . '</span></esi:remove>'
                . '<esi:include src="' . esc_attr($failing_url) . '" onerror="continue" />'
                . '<span>ULTRACACHE_ESI_PRIVATE_TAIL_' . esc_html($nonce) . '</span>'
                . '</body></html>';
        }

        if (!headers_sent()) {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header_remove('Set-Cookie');
            header_remove('Surrogate-Control');
            header_remove('Vary');
            status_header(200);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header('X-UltraCache-ESI-Probe: private-' . $kind);
            if ('parent' === $kind) {
                header('Cache-Control: public, max-age=0, s-maxage=120');
                header('Vary: Accept-Encoding');
                header('Surrogate-Control: content="ESI/1.0"');
                header('X-UltraCache-ESI-Shared-Parent: 1');
            } else {
                header('Cache-Control: private, no-store, max-age=0, must-revalidate');
                header('Surrogate-Control: no-store');
                header('X-UltraCache-ESI-Private: 1');
            }
        }

        if ('HEAD' !== $method) {
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed diagnostic HTML is generated entirely by UltraCache.
        }
        exit;
    }

    /**
     * Send a minimal non-cacheable endpoint error.
     *
     * @param int $status HTTP status.
     * @return void
     */
    private function send_esi_error_response($status)
    {
        $status = in_array((int) $status, array(403, 404, 405, 500), true) ? (int) $status : 404;
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header_remove('Vary');
            header_remove('ETag');
            header_remove('Last-Modified');
            header_remove('Content-Length');
            header_remove('X-UltraCache-Cacheable');
            header_remove('X-UltraCache-Surrogate-TTL');
            status_header($status);
            header('Cache-Control: private, no-store, max-age=0, must-revalidate');
            header('Surrogate-Control: no-store');
            header('X-UltraCache-Cacheable: 0');
            header('X-UltraCache-Surrogate-TTL: 0');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        exit;
    }
}
