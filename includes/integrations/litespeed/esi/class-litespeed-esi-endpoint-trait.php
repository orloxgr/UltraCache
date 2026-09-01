<?php
/**
 * Native LiteSpeed ESI fragment endpoint.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_ESI_Endpoint_Trait
{
    /** @param array $query_vars @return array */
    public function register_litespeed_esi_query_var(array $query_vars)
    {
        $query_vars[] = 'ultracache_litespeed_esi';
        return array_values(array_unique($query_vars));
    }

    /**
     * Skip the main posts query when the signed fragment definition does not
     * need main-query state.
     *
     * @param array|null $posts Existing short-circuit value.
     * @param WP_Query $query Query.
     * @return array|null
     */
    public function maybe_bypass_litespeed_esi_fragment_main_query($posts, $query)
    {
        if (null !== $posts || !is_object($query) || !method_exists($query, 'is_main_query') || !$query->is_main_query()) {
            return $posts;
        }
        if (!in_array(strtoupper((string) ultracache_server_value('REQUEST_METHOD')), array('GET', 'HEAD'), true)) {
            return $posts;
        }
        $token = method_exists($query, 'get') ? $query->get('ultracache_litespeed_esi') : '';
        if (!is_string($token) || '' === trim($token)) {
            return $posts;
        }
        $decoded = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->decode_context_token(rawurldecode($token));
        if (is_wp_error($decoded)) {
            return $posts;
        }
        $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
        if (empty($definition) || !empty($definition['needs_main_query'])) {
            return $posts;
        }
        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $enabled = 'private' === $scope
            ? function_exists('ultracache_litespeed_esi_private_definition_is_enabled') && ultracache_litespeed_esi_private_definition_is_enabled($definition)
            : function_exists('ultracache_litespeed_esi_is_enabled') && ultracache_litespeed_esi_is_enabled();
        return $enabled ? array() : $posts;
    }

    /** @return void */
    public function maybe_serve_litespeed_esi_fragment()
    {
        $token = get_query_var('ultracache_litespeed_esi', '');
        if (!is_string($token) || '' === trim($token)) {
            return;
        }

        $method = strtoupper((string) ultracache_server_value('REQUEST_METHOD'));
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            if (!headers_sent()) {
                header('Allow: GET, HEAD');
            }
            $this->send_litespeed_esi_error_response(405);
        }

        $decoded = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->decode_context_token(rawurldecode($token));
        if (is_wp_error($decoded)) {
            do_action('ultracache_litespeed_esi_endpoint_error', $decoded, 'token');
            $this->send_litespeed_esi_error_response(404);
        }

        $definition = is_array($decoded['definition'] ?? null) ? $decoded['definition'] : array();
        $context = is_array($decoded['context'] ?? null) ? $decoded['context'] : array();
        $locale = isset($decoded['locale']) ? (string) $decoded['locale'] : '';
        $language = isset($decoded['language']) ? (string) $decoded['language'] : '';
        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $enabled = 'private' === $scope
            ? function_exists('ultracache_litespeed_esi_private_definition_is_enabled') && ultracache_litespeed_esi_private_definition_is_enabled($definition)
            : function_exists('ultracache_litespeed_esi_is_enabled') && ultracache_litespeed_esi_is_enabled();
        if (!$enabled) {
            $this->send_litespeed_esi_error_response(404);
        }

        $request_state = $this->prepare_litespeed_esi_fragment_request_context($definition, $locale, $language);
        if (is_wp_error($request_state)) {
            do_action('ultracache_litespeed_esi_endpoint_error', $request_state, 'request-context');
            $this->send_litespeed_esi_error_response(403);
        }

        $context_hash = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->get_context_hash((string) ($definition['id'] ?? ''), $context);
        if ('HEAD' === $method) {
            $this->restore_litespeed_esi_fragment_request_context($request_state);
            $this->send_litespeed_esi_fragment_success_headers($definition, $scope, $context_hash, 0, true);
            exit;
        }

        $render_started = microtime(true);
        try {
            do_action('ultracache_litespeed_esi_before_fragment_render', $definition['id'] ?? '', $context, $definition);
            $output = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->render($definition, $context, 'renderer');
        } catch (Throwable $e) {
            do_action('ultracache_litespeed_esi_fragment_render_error', $definition['id'] ?? '', $e);
            $output = new WP_Error('ultracache_litespeed_esi_fragment_render_failed', __('The LiteSpeed ESI fragment renderer failed.', 'ultracache'));
        }

        $contained_error = is_wp_error($output);
        if ($contained_error) {
            $fallback = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->render($definition, $context, 'fallback');
            $output = is_wp_error($fallback) ? '' : (string) $fallback;
        } else {
            $output = apply_filters('ultracache_litespeed_esi_fragment_output', (string) $output, $definition['id'] ?? '', $context, $definition);
        }

        $output = $this->neutralize_litespeed_esi_fragment_output((string) $output);
        $this->restore_litespeed_esi_fragment_request_context($request_state);

        if (strlen($output) > (int) ($definition['max_output_bytes'] ?? 262144)) {
            $this->send_litespeed_esi_error_response(500);
        }

        $render_ms = max(0, min(600000, (int) round((microtime(true) - $render_started) * 1000)));
        $this->send_litespeed_esi_fragment_success_headers($definition, $scope, $context_hash, $render_ms, false, $contained_error);
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Registered fragment renderer output.
        exit;
    }

    /**
     * Restrict cookies to the fragment definition and restore signed language.
     *
     * @param array $definition Definition.
     * @param string $locale Locale.
     * @param string $language WPML language.
     * @return array|WP_Error
     */
    private function prepare_litespeed_esi_fragment_request_context(array $definition, $locale = '', $language = '')
    {
        $scope = 'private' === (string) ($definition['scope'] ?? '') ? 'private' : 'public';
        $cookie_header_present = isset($_SERVER['HTTP_COOKIE']);
        $state = array(
            'cookies' => isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array(),
            'cookieHeaderPresent' => $cookie_header_present,
            'cookieHeader' => $cookie_header_present ? ultracache_server_value('HTTP_COOKIE') : '',
            'userId' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'localeSwitched' => false,
            'wpmlPreviousLanguage' => '',
            'wpmlLanguageSwitched' => false,
        );

        if ('private' === $scope) {
            $filtered = Ultra_Cache_LiteSpeed_ESI_Registry::instance()->filter_cookie_header($definition, (string) ultracache_server_value('HTTP_COOKIE'));
            if (is_wp_error($filtered)) {
                return $filtered;
            }
            $_COOKIE = (array) ($filtered['cookies'] ?? array());
            $_SERVER['HTTP_COOKIE'] = (string) ($filtered['header'] ?? '');
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

        $language = function_exists('ultracache_wpml_normalize_language_code')
            ? ultracache_wpml_normalize_language_code($language)
            : strtolower(trim((string) $language));
        if ('' !== $language && function_exists('ultracache_wpml_get_active_languages')) {
            $active = ultracache_wpml_get_active_languages();
            if (!isset($active[$language])) {
                $this->restore_litespeed_esi_fragment_request_context($state);
                return new WP_Error('ultracache_litespeed_esi_wpml_language_inactive', __('The signed LiteSpeed ESI language is inactive.', 'ultracache'));
            }
            $state['wpmlPreviousLanguage'] = function_exists('ultracache_wpml_get_current_language') ? ultracache_wpml_get_current_language() : '';
            if ((string) $state['wpmlPreviousLanguage'] !== $language) {
                do_action('wpml_switch_language', $language);
                $state['wpmlLanguageSwitched'] = true;
            }
        }

        $locale = preg_replace('/[^A-Za-z0-9_@.-]/', '', (string) $locale);
        if (is_string($locale) && strlen($locale) >= 2 && strlen($locale) <= 32 && function_exists('switch_to_locale')) {
            $state['localeSwitched'] = (bool) switch_to_locale($locale);
        }

        return $state;
    }

    /** @param array $state @return void */
    private function restore_litespeed_esi_fragment_request_context(array $state)
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
        if (!empty($state['wpmlLanguageSwitched'])) {
            $previous = (string) ($state['wpmlPreviousLanguage'] ?? '');
            do_action('wpml_switch_language', '' !== $previous ? $previous : null);
        }
    }

    /**
     * Send LiteSpeed cache semantics for the ESI resource.
     *
     * @param array $definition Definition.
     * @param string $scope Scope.
     * @param string $context_hash Context hash.
     * @param int $render_ms Render duration.
     * @param bool $head_only HEAD response.
     * @param bool $contained_error Fallback used.
     * @return void
     */
    private function send_litespeed_esi_fragment_success_headers(array $definition, $scope, $context_hash, $render_ms, $head_only, $contained_error = false)
    {
        if (headers_sent()) {
            return;
        }
        header_remove('Content-Length');
        header_remove('Set-Cookie');
        status_header(200);
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'));
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');

        if ('public' === $scope && !$contained_error && !$head_only) {
            $ttl = max(1, min(WEEK_IN_SECONDS, absint($definition['ttl'] ?? 300)));
            header('Cache-Control: public, max-age=0, s-maxage=' . (string) $ttl, true);
            header('X-LiteSpeed-Cache-Control: public,max-age=' . (string) $ttl, true);
            $tag = 'ucesi_' . substr(hash('sha256', (string) ($definition['id'] ?? '') . '|' . $context_hash), 0, 24);
            header('X-LiteSpeed-Tag: ' . $tag, true);
        } else {
            header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
            header('X-LiteSpeed-Cache-Control: no-cache', true);
        }

        header('X-UltraCache-LiteSpeed-ESI-Fragment: ' . sanitize_key((string) ($definition['id'] ?? '')), true);
        header('X-UltraCache-LiteSpeed-ESI-Scope: ' . $scope, true);
        header('X-UltraCache-LiteSpeed-ESI-Context: ' . substr($context_hash, 0, 64), true);
        header('X-UltraCache-LiteSpeed-ESI-Render-Ms: ' . (string) max(0, (int) $render_ms), true);
        if ($contained_error) {
            header('X-UltraCache-LiteSpeed-ESI-Error-Contained: 1', true);
        }
    }

    /** @param string $output @return string */
    private function neutralize_litespeed_esi_fragment_output($output)
    {
        $output = preg_replace_callback('/<\s*(\/?)\s*esi(?=[:_])/i', static function ($matches) {
            return '&lt;' . (!empty($matches[1]) ? '/' : '') . 'esi';
        }, (string) $output);
        return is_string($output) ? $output : '';
    }

    /** @param int $status @return void */
    private function send_litespeed_esi_error_response($status)
    {
        $status = max(400, min(599, (int) $status));
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            status_header($status);
            header('Cache-Control: private, no-store, max-age=0, must-revalidate', true);
            header('X-LiteSpeed-Cache-Control: no-cache', true);
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
            header('Content-Type: text/plain; charset=' . get_option('blog_charset', 'UTF-8'));
        }
        exit;
    }
}
