<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Cache_Decision_Trait
{
        private function get_dynamic_query_args()
        {
            return array(
                'add-to-cart',
                'wc-ajax',
                'remove_item',
                'undo_item',
                'apply_coupon',
                'remove_coupon',
                'order_again',
            );
        }

        private function get_hard_security_excluded_query_args()
        {
            return array(
                '_wpnonce',
                '_ajax_nonce',
                'nonce',
                'security',
                'token',
                'auth',
                'auth_token',
                'access_token',
                'key',
                'order_key',
                'password',
                'pass',
                'pwd',
                'redirect_to',
                'rest_route',
                'customer-logout',
                'logout',
                'pay_for_order',
                'cancel_order',
                'download_file',
                'ultracache_revalidate',
                'ultracache_rt',
                'ultracache_store_profile',
                'ultracache_callback_profile',
                'ultracache_store_profile_verbose',
                'ultracache_store_profile_verbose_settings',
                'ultracache_profile_bypass',
                'ultracache_profile_run',
                'ultracache_runtime_js_scan',
                'ultracache_runtime_js_scan_id',
                'ultracache_runtime_js_scan_nonce',
            );
        }

        private function get_hard_security_excluded_paths()
        {
            return array_values(array_filter(array(
                function_exists('ultracache_wordpress_admin_public_path') ? ultracache_wordpress_admin_public_path() : '',
                '/wp-login.php',
                '/wp-json/',
                '/xmlrpc.php',
                '/wp-cron.php',
                '/wp-comments-post.php',
                '/wc-api/',
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/order-pay/',
                '/order-received/',
                '/add-payment-method/',
                '/lost-password/',
            )));
        }

        private function merge_hard_security_excluded_paths(array $excluded_paths)
        {
            return array_values(array_unique(array_merge($excluded_paths, $this->get_hard_security_excluded_paths())));
        }

        private function get_hard_security_unsafe_cookie_patterns()
        {
            return array(
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wp-postpass_',
                'comment_author_',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
            );
        }

        private function normalize_cookie_pattern_list($patterns)
        {
            $normalized = array();
            foreach ((array) $patterns as $pattern) {
                if (is_array($pattern) || is_object($pattern)) {
                    continue;
                }

                $pattern = trim((string) $pattern);
                if ('' === $pattern) {
                    continue;
                }

                $pattern = preg_replace('/[\x00-\x1F\x7F]/', '', $pattern);
                $pattern = is_string($pattern) ? preg_replace('/[^A-Za-z0-9_\-.\*]/', '', $pattern) : '';
                $pattern = trim((string) $pattern);
                if ('' === $pattern || '*' === $pattern) {
                    continue;
                }

                $normalized[strtolower($pattern)] = $pattern;
            }

            return array_values($normalized);
        }

        private function get_unsafe_cache_cookie_patterns(array $settings)
        {
            $configured = !empty($settings['unsafe_cache_cookie_patterns']) && is_array($settings['unsafe_cache_cookie_patterns'])
                ? $settings['unsafe_cache_cookie_patterns']
                : array();

            return $this->normalize_cookie_pattern_list(array_merge($configured, $this->get_hard_security_unsafe_cookie_patterns()));
        }

        private function get_safe_tracking_cookie_patterns(array $settings)
        {
            return $this->normalize_cookie_pattern_list(!empty($settings['safe_tracking_cookie_patterns']) && is_array($settings['safe_tracking_cookie_patterns']) ? $settings['safe_tracking_cookie_patterns'] : array());
        }

        private function cookie_name_matches_pattern($cookie_name, $pattern)
        {
            $cookie_name = strtolower(trim((string) $cookie_name));
            $pattern = strtolower(trim((string) $pattern));
            if ('' === $cookie_name || '' === $pattern || '*' === $pattern) {
                return false;
            }

            if (false !== strpos($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                return 1 === preg_match($regex, $cookie_name);
            }

            return false !== strpos($cookie_name, $pattern);
        }

        private function cookie_name_matches_any_pattern($cookie_name, array $patterns)
        {
            foreach ($patterns as $pattern) {
                if ($this->cookie_name_matches_pattern($cookie_name, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        private function all_cookie_names_match_patterns(array $cookie_names, array $patterns)
        {
            if (empty($cookie_names) || empty($patterns)) {
                return false;
            }

            foreach ($cookie_names as $cookie_name) {
                if (!$this->cookie_name_matches_any_pattern($cookie_name, $patterns)) {
                    return false;
                }
            }

            return true;
        }

        private function get_internal_control_query_args()
        {
            return array(
                'ultracache_revalidate',
                'ultracache_rt',
                'ultracache_store_profile',
                'ultracache_callback_profile',
                'ultracache_store_profile_verbose',
                'ultracache_store_profile_verbose_settings',
                'ultracache_profile_bypass',
                'ultracache_profile_run',
                'ultracache_runtime_js_scan',
                'ultracache_runtime_js_scan_id',
                'ultracache_runtime_js_scan_nonce',
            );
        }

        private function query_contains_internal_control_keys($query)
        {
            if ('' === (string) $query) {
                return false;
            }

            parse_str((string) $query, $query_vars);
            if (empty($query_vars) || !is_array($query_vars)) {
                return false;
            }

            $lookup = array_fill_keys($this->get_internal_control_query_args(), true);
            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if (isset($lookup[$normalized_key])) {
                    return true;
                }
            }

            return false;
        }

        private function request_has_internal_control_markers($query = '')
        {
            if ($this->query_contains_internal_control_keys($query)) {
                return true;
            }

            $headers = array(
                'HTTP_X_ULTRACACHE_REVALIDATE',
                'HTTP_X_ULTRACACHE_TOKEN',
                'HTTP_X_ULTRACACHE_PROFILE_BYPASS',
                'HTTP_X_ULTRACACHE_STORE_PROFILE',
                'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE',
                'HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS',
                'HTTP_X_ULTRACACHE_CALLBACK_PROFILE',
            );
            foreach ($headers as $header) {
                if ('' !== trim((string) ultracache_server_value($header))) {
                    return true;
                }
            }

            return false;
        }

        private function has_invalid_internal_control_request($query = '')
        {
            if (!$this->request_has_internal_control_markers($query)) {
                return false;
            }

            if ($this->is_internal_revalidate_request() || $this->is_profile_bypass_request()) {
                return false;
            }

            if (function_exists('ultracache_request_profiler_enabled') && ultracache_request_profiler_enabled()) {
                return false;
            }

            return true;
        }

        private function merge_hard_security_excluded_query_args(array $excluded_query_args)
        {
            return array_values(array_unique(array_merge($excluded_query_args, $this->get_hard_security_excluded_query_args())));
        }

        private function query_contains_excluded_keys($query, array $excluded_query_args)
        {
            if ('' === (string) $query) {
                return false;
            }

            $lookup = array();
            foreach ($excluded_query_args as $excluded_query_arg) {
                $normalized_arg = sanitize_key((string) $excluded_query_arg);
                if ('' !== $normalized_arg) {
                    $lookup[$normalized_arg] = true;
                }
            }

            if (empty($lookup)) {
                return false;
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' !== $normalized_key && isset($lookup[$normalized_key])) {
                    return true;
                }
            }

            return false;
        }

        private function get_query_allowlist(array $settings = array())
        {
            if (empty($settings)) {
                $settings = $this->get_settings();
            }

            if (empty($settings['cache_query_allowlist']) || !is_array($settings['cache_query_allowlist'])) {
                return array();
            }

            return array_values(array_unique(array_filter(array_map('sanitize_key', $settings['cache_query_allowlist']))));
        }

        private function sort_query_value_for_cache($value)
        {
            if (!is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $child) {
                $value[$key] = $this->sort_query_value_for_cache($child);
            }

            if (array_keys($value) === range(0, count($value) - 1)) {
                usort($value, static function ($a, $b) {
                    return strcmp((string) wp_json_encode($a), (string) wp_json_encode($b));
                });
                return $value;
            }

            ksort($value);
            return $value;
        }

        private function normalize_query_vars_for_cache($query, array $allowlist = array())
        {
            if (is_string($query)) {
                parse_str($query, $query_vars);
            } elseif (is_array($query)) {
                $query_vars = $query;
            } else {
                $query_vars = array();
            }

            if (empty($query_vars) || !is_array($query_vars)) {
                return array();
            }

            $lookup = array();
            foreach ($allowlist as $allowed_key) {
                $allowed_key = sanitize_key((string) $allowed_key);
                if ('' !== $allowed_key) {
                    $lookup[$allowed_key] = true;
                }
            }

            $filtered = array();
            foreach ($query_vars as $query_key => $query_value) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' === $normalized_key) {
                    continue;
                }
                if (!empty($lookup) && !isset($lookup[$normalized_key])) {
                    continue;
                }

                $filtered[$normalized_key] = $this->sort_query_value_for_cache($query_value);
            }

            if (empty($filtered)) {
                return array();
            }

            ksort($filtered);
            return $filtered;
        }

        private function build_normalized_query_string_for_cache($query, array $allowlist = array())
        {
            /*
             * Query-string HTML cache variants are intentionally allowlist-only.
             * An enabled query-string switch with an empty allowlist must not cache
             * every tracking/session/bot query as a separate homepage variant.
             */
            if (empty($allowlist)) {
                return '';
            }

            $filtered = $this->normalize_query_vars_for_cache($query, $allowlist);
            if (empty($filtered)) {
                return '';
            }

            return http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
        }

        private function get_first_non_allowlisted_query_key($query, array $allowlist = array())
        {
            if ('' === (string) $query) {
                return '';
            }

            if (is_string($query)) {
                parse_str($query, $query_vars);
            } elseif (is_array($query)) {
                $query_vars = $query;
            } else {
                $query_vars = array();
            }

            if (empty($query_vars) || !is_array($query_vars)) {
                return '';
            }

            $lookup = array();
            foreach ($allowlist as $allowed_key) {
                $allowed_key = sanitize_key((string) $allowed_key);
                if ('' !== $allowed_key) {
                    $lookup[$allowed_key] = true;
                }
            }

            if (empty($lookup)) {
                return '';
            }

            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' === $normalized_key || !isset($lookup[$normalized_key])) {
                    return '' !== $normalized_key ? $normalized_key : (string) $query_key;
                }
            }

            return '';
        }

        private function query_has_cacheable_allowlisted_variant($query, array $allowlist = array())
        {
            if ('' === (string) $query || empty($allowlist)) {
                return false;
            }

            if ('' !== $this->get_first_non_allowlisted_query_key($query, $allowlist)) {
                return false;
            }

            return !empty($this->normalize_query_vars_for_cache($query, $allowlist));
        }

        private function get_matching_path_rule($path, array $rules)
        {
            foreach ($rules as $rule) {
                if ($this->matches_path_rule($path, $rule)) {
                    return (string) $rule;
                }
            }

            return '';
        }

        private function get_matching_query_arg($query, array $candidate_args)
        {
            if ('' === (string) $query) {
                return '';
            }

            $lookup = array();
            foreach ($candidate_args as $candidate_arg) {
                $normalized_arg = sanitize_key((string) $candidate_arg);
                if ('' !== $normalized_arg) {
                    $lookup[$normalized_arg] = true;
                }
            }

            if (empty($lookup)) {
                return '';
            }

            parse_str((string) $query, $query_vars);
            foreach (array_keys($query_vars) as $query_key) {
                $normalized_key = sanitize_key((string) $query_key);
                if ('' !== $normalized_key && isset($lookup[$normalized_key])) {
                    return $normalized_key;
                }
            }

            return '';
        }

        private function normalize_inspection_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            if (0 === strpos($url, '//')) {
                $home_parts = wp_parse_url(home_url('/'));
                $scheme = !empty($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https';
                return esc_url_raw($scheme . ':' . $url);
            }

            $parts = wp_parse_url($url);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                return esc_url_raw($url);
            }

            if ('/' === substr($url, 0, 1) || '?' === substr($url, 0, 1) || '#' === substr($url, 0, 1)) {
                return esc_url_raw(home_url($url));
            }

            return esc_url_raw(home_url('/' . ltrim($url, '/')));
        }

        private function get_bypass_reason_label($reason)
        {
            $labels = array(
                'cacheable'                 => 'Cacheable',
                'invalid-url'               => 'Invalid URL',
                'disabled'                  => 'Page caching is disabled',
                'non-local-url'             => 'URL is not local to this site',
                'excluded-path'             => 'Excluded by path rule',
                'excluded-query-arg'        => 'Excluded by query-arg rule',
                'query-strings-disabled'    => 'Query strings are not cached',
                'query-allowlist-empty'     => 'Query-string caching requires a whitelist',
                'query-arg-not-allowlisted' => 'Query arg is not in the cache allowlist',
                'woocommerce-dynamic-path'  => 'WooCommerce dynamic path bypass',
                'woocommerce-dynamic-query' => 'WooCommerce dynamic query bypass',
                'woocommerce-cart'          => 'WooCommerce cart bypass',
                'woocommerce-checkout'      => 'WooCommerce checkout bypass',
                'woocommerce-account'       => 'WooCommerce account bypass',
                'woocommerce-endpoint'      => 'WooCommerce endpoint bypass',
            );

            return isset($labels[$reason]) ? $labels[$reason] : ucwords(str_replace(array('-', '_'), ' ', (string) $reason));
        }

        private function is_woocommerce_dynamic_request($url = '', array $settings = array())
        {
            if (empty($settings)) {
                $settings = $this->get_settings();
            }

            /*
             * Public cache-safety hardening: core WooCommerce dynamic endpoints are
             * never eligible for static HTML cache. The woo_safe_mode setting may
             * still control broader WooCommerce tuning elsewhere, but cart, checkout,
             * account, endpoint, and Woo query/cookie requests remain hard bypasses.
             */
            if (function_exists('is_cart') && is_cart()) {
                $this->last_bypass_reason = 'woocommerce-cart';
                return true;
            }

            if (function_exists('is_checkout') && is_checkout()) {
                $this->last_bypass_reason = 'woocommerce-checkout';
                return true;
            }

            if (function_exists('is_account_page') && is_account_page()) {
                $this->last_bypass_reason = 'woocommerce-account';
                return true;
            }

            if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
                $this->last_bypass_reason = 'woocommerce-endpoint';
                return true;
            }

            if (empty($url)) {
                $url = $this->get_current_request_url();
            }

            if (empty($url)) {
                return false;
            }

            $parts = wp_parse_url($url);
            $path  = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';

            $dynamic_paths = array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/order-pay/',
                '/order-received/',
                '/add-payment-method/',
                '/lost-password/',
            );

            if ($this->path_matches_any_rule($path, $dynamic_paths)) {
                $this->last_bypass_reason = 'woocommerce-dynamic-path';
                return true;
            }

            if ($this->query_contains_excluded_keys($query, $this->get_dynamic_query_args())) {
                $this->last_bypass_reason = 'woocommerce-dynamic-query';
                return true;
            }

            return false;
        }

        private function get_revalidate_secret()
        {
            return function_exists('ultracache_runtime_control_secret') ? ultracache_runtime_control_secret() : wp_hash('ultracache-revalidate-v1');
        }

        private function is_valid_revalidate_token($token)
        {
            if (!function_exists('ultracache_validate_runtime_control_token')) {
                return false;
            }

            return ultracache_validate_runtime_control_token($token, $this->get_revalidate_secret());
        }

        private function is_internal_revalidate_request()
        {
            $request_flag = sanitize_text_field(ultracache_query_value('ultracache_revalidate'));
            $header_flag  = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_REVALIDATE'));
            if ('1' !== $request_flag && '1' !== $header_flag) {
                return false;
            }

            $token = sanitize_text_field(ultracache_query_value('ultracache_rt'));
            if ('' === $token) {
                $token = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_TOKEN'));
            }

            if ('' === $token) {
                return false;
            }

            return $this->is_valid_revalidate_token($token);
        }

        private function is_profile_bypass_request()
        {
            $header_flag = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_PROFILE_BYPASS'));
            $query_flag = sanitize_text_field(ultracache_query_value('ultracache_profile_bypass'));
            if ('1' !== $header_flag && 'true' !== strtolower((string) $header_flag) && '1' !== $query_flag && 'true' !== strtolower((string) $query_flag)) {
                return false;
            }

            $token = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_TOKEN'));
            if ('' === $token) {
                $token = sanitize_text_field(ultracache_query_value('ultracache_rt'));
            }
            if ('' === $token) {
                return false;
            }

            return $this->is_valid_revalidate_token($token);
        }

        private function clear_revalidate_lock($url)
        {
            $lock = $this->get_revalidate_lock_path($url);
            if ($lock && file_exists($lock)) {
                ultracache_safe_unlink($lock);
            }
        }

        public function should_bypass_cache($url = '')
        {
            $this->profile_request_checkpoint('should_bypass_start');
            $this->last_bypass_reason = '';
            $this->profile_request_checkpoint('should_bypass_before_get_settings');
            $settings = $this->get_settings();
            $this->profile_request_checkpoint('should_bypass_after_get_settings', array(
                'settings_count' => is_array($settings) ? count($settings) : 0,
            ));

            $this->profile_request_checkpoint('should_bypass_before_basic_checks');
            if (empty($settings['enabled'])) {
                $this->last_bypass_reason = 'disabled';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                $this->last_bypass_reason = 'donotcachepage';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (wp_doing_ajax() || wp_doing_cron()) {
                $this->last_bypass_reason = 'ajax-or-cron';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_admin') && is_admin()) {
                $this->last_bypass_reason = 'admin';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_feed') && is_feed()) {
                $this->last_bypass_reason = 'feed';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_preview') && is_preview()) {
                $this->last_bypass_reason = 'preview';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            if (function_exists('is_customize_preview') && is_customize_preview()) {
                $this->last_bypass_reason = 'customize-preview';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }

            $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
            if (!in_array($request_method, array('GET', 'HEAD'), true)) {
                $this->last_bypass_reason = 'request-method';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_basic_checks', array('request_method' => $request_method));

            $this->profile_request_checkpoint('should_bypass_before_internal_revalidate');
            if ($this->is_internal_revalidate_request()) {
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => 'internal-revalidate-allowed'));
                return false;
            }
            if ($this->has_invalid_internal_control_request(ultracache_server_value('QUERY_STRING'))) {
                $this->last_bypass_reason = 'invalid-internal-control';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_internal_revalidate');

            $this->profile_request_checkpoint('should_bypass_before_woocommerce_dynamic');
            if ($this->is_woocommerce_dynamic_request($url, $settings)) {
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => (string) $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_woocommerce_dynamic');

            $this->profile_request_checkpoint('should_bypass_before_user_check');
            if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($settings['cache_logged_in_users'])) {
                $this->last_bypass_reason = 'logged-in-user';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_user_check');

            $cookies_to_bypass = $this->get_unsafe_cache_cookie_patterns($settings);

            $this->profile_request_checkpoint('should_bypass_before_cookie_checks', array('cookie_count' => count((array) $_COOKIE), 'unsafe_cookie_rule_count' => count($cookies_to_bypass)));
            foreach ((array) $_COOKIE as $cookie_name => $cookie_value) {
                foreach ($cookies_to_bypass as $needle) {
                    if ($this->cookie_name_matches_pattern($cookie_name, $needle)) {
                        $this->last_bypass_reason = 'cookie-' . preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) $needle);
                        $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                        return true;
                    }
                }
            }
            $this->profile_request_checkpoint('should_bypass_after_cookie_checks');

            if (empty($url)) {
                $this->profile_request_checkpoint('should_bypass_before_current_url');
                $url = $this->get_current_request_url();
                $this->profile_request_checkpoint('should_bypass_after_current_url', array('url_length' => strlen((string) $url)));
            }

            $this->profile_request_checkpoint('should_bypass_before_local_url_check');
            if (empty($url) || !$this->is_cacheable_local_url($url)) {
                $this->last_bypass_reason = 'non-local-url';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_local_url_check');

            $this->profile_request_checkpoint('should_bypass_before_url_parse');
            $parts = wp_parse_url($url);
            $path = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';
            if ($this->has_invalid_internal_control_request($query)) {
                $this->last_bypass_reason = 'invalid-internal-control';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            if ('' !== $query) {
                parse_str($query, $ultracache_query_vars_for_cacheability);
                unset(
                    $ultracache_query_vars_for_cacheability['ultracache_revalidate'],
                    $ultracache_query_vars_for_cacheability['ultracache_rt'],
                    $ultracache_query_vars_for_cacheability['ultracache_store_profile'],
                    $ultracache_query_vars_for_cacheability['ultracache_callback_profile'],
                    $ultracache_query_vars_for_cacheability['ultracache_store_profile_verbose'],
                    $ultracache_query_vars_for_cacheability['ultracache_store_profile_verbose_settings'],
                    $ultracache_query_vars_for_cacheability['ultracache_profile_bypass'],
                    $ultracache_query_vars_for_cacheability['ultracache_profile_run']
                );
                $query = !empty($ultracache_query_vars_for_cacheability) ? http_build_query($ultracache_query_vars_for_cacheability) : '';
            }
            $this->profile_request_checkpoint('should_bypass_after_url_parse', array('path' => substr((string) $path, 0, 160), 'query_length' => strlen($query)));

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            $excluded_paths = $this->merge_hard_security_excluded_paths($excluded_paths);
            $this->profile_request_checkpoint('should_bypass_before_excluded_path_rules', array('rule_count' => count($excluded_paths)));
            if ($this->path_matches_any_rule($path, $excluded_paths)) {
                $this->last_bypass_reason = 'excluded-path';
                $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                return true;
            }
            $this->profile_request_checkpoint('should_bypass_after_excluded_path_rules');

            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
            if ('' !== $query) {
                $this->profile_request_checkpoint('should_bypass_before_excluded_query_args', array('rule_count' => count($excluded_query_args)));
                if ($this->query_contains_excluded_keys($query, $excluded_query_args)) {
                    $this->last_bypass_reason = 'excluded-query-arg';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }
                $this->profile_request_checkpoint('should_bypass_after_excluded_query_args');

                $this->profile_request_checkpoint('should_bypass_before_query_allowlist');
                $query_allowlist = $this->get_query_allowlist($settings);
                $this->profile_request_checkpoint('should_bypass_after_query_allowlist', array('allowlist_count' => count($query_allowlist)));
                if (empty($settings['cache_query_strings'])) {
                    $this->last_bypass_reason = 'query-strings-disabled';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }

                if (empty($query_allowlist)) {
                    $this->last_bypass_reason = 'query-allowlist-empty';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }

                $this->profile_request_checkpoint('should_bypass_before_query_variant');
                if (!$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                    $this->last_bypass_reason = 'query-arg-not-allowlisted';
                    $this->profile_request_checkpoint('should_bypass_return', array('reason' => $this->last_bypass_reason));
                    return true;
                }
                $this->profile_request_checkpoint('should_bypass_after_query_variant');
            }

            $this->profile_request_checkpoint('should_bypass_return', array('reason' => 'cacheable'));
            return false;
        }

        private function normalize_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            $parts = wp_parse_url($url);
            if (empty($parts['host'])) {
                return '';
            }

            $settings = $this->get_settings();
            $allowlist = $this->get_query_allowlist($settings);

            $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
            $host = strtolower((string) $parts['host']);
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
            $query = '';

            if (!empty($parts['query']) && !empty($settings['cache_query_strings'])) {
                $query = $this->build_normalized_query_string_for_cache((string) $parts['query'], $allowlist);
            }

            return $scheme . '://' . $host . $port . $path . ($query ? '?' . $query : '');
        }

        public function is_cacheable_local_url($url)
        {
            $url = trim((string) $url);
            if ('' === $url) {
                return false;
            }

            if (function_exists('ultracache_is_strict_frontend_loopback_url')) {
                return ultracache_is_strict_frontend_loopback_url($url);
            }

            $parts = wp_parse_url($url);
            $home_parts = wp_parse_url(home_url('/'));
            if (empty($parts['scheme']) || empty($parts['host']) || empty($home_parts['host'])) {
                return false;
            }

            if (!in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)) {
                return false;
            }

            return strtolower((string) $parts['host']) === strtolower((string) $home_parts['host']);
        }

        public function inspect_url($url)
        {
            $input_url = trim((string) $url);
            $absolute_url = $this->normalize_inspection_url($input_url);
            $settings = $this->get_settings();
            $decision_parts = '' !== $absolute_url ? wp_parse_url($absolute_url) : array();
            $path = isset($decision_parts['path']) ? $this->normalize_path_value((string) $decision_parts['path']) : '/';
            $query = isset($decision_parts['query']) ? (string) $decision_parts['query'] : '';
            $normalized_url = '' !== $absolute_url ? $this->normalize_url($absolute_url) : '';
            $parts = !empty($decision_parts) && is_array($decision_parts) ? $decision_parts : array();
            $query_vars = array();
            if ('' !== $query) {
                parse_str($query, $query_vars);
            }

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
            $dynamic_paths = array(
                '/cart/',
                '/checkout/',
                '/my-account/',
                '/order-pay/',
                '/order-received/',
                '/add-payment-method/',
                '/lost-password/',
            );
            $dynamic_query_args = $this->get_dynamic_query_args();

            $matched_excluded_path_rule = '' !== $normalized_url ? $this->get_matching_path_rule($path, $excluded_paths) : '';
            $matched_excluded_query_arg = '' !== $query ? $this->get_matching_query_arg($query, $excluded_query_args) : '';
            $query_allowlist = $this->get_query_allowlist($settings);
            $matched_non_allowlisted_query_arg = '' !== $query ? $this->get_first_non_allowlisted_query_key($query, $query_allowlist) : '';
            $matched_woo_path_rule = '' !== $absolute_url ? $this->get_matching_path_rule($path, $dynamic_paths) : '';
            $matched_woo_query_arg = '' !== $query ? $this->get_matching_query_arg($query, $dynamic_query_args) : '';

            $reason = 'cacheable';
            $matched_woo_rule = '';
            $matched_woo_rule_type = '';

            if ('' === $input_url || '' === $absolute_url) {
                $reason = 'invalid-url';
            } elseif (empty($settings['enabled'])) {
                $reason = 'disabled';
            } elseif (!$this->is_cacheable_local_url($absolute_url)) {
                $reason = 'non-local-url';
            } elseif ('' !== $matched_woo_path_rule) {
                $reason = 'woocommerce-dynamic-path';
                $matched_woo_rule = $matched_woo_path_rule;
                $matched_woo_rule_type = 'path';
            } elseif ('' !== $matched_woo_query_arg) {
                $reason = 'woocommerce-dynamic-query';
                $matched_woo_rule = $matched_woo_query_arg;
                $matched_woo_rule_type = 'query';
            } elseif ('' !== $matched_excluded_path_rule) {
                $reason = 'excluded-path';
            } elseif ('' !== $matched_excluded_query_arg) {
                $reason = 'excluded-query-arg';
            } elseif ('' !== $query && empty($settings['cache_query_strings'])) {
                $reason = 'query-strings-disabled';
            } elseif ('' !== $query && empty($query_allowlist)) {
                $reason = 'query-allowlist-empty';
            } elseif ('' !== $query && !$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                $reason = 'query-arg-not-allowlisted';
            }

            $cacheable = 'cacheable' === $reason;
            $cache_paths = array();
            if ($cacheable && '' !== $normalized_url) {
                $cache_paths = array(
                    'orig' => $this->get_cache_path($normalized_url, 'orig'),
                    'webp' => $this->get_cache_path($normalized_url, 'webp'),
                    'avif' => $this->get_cache_path($normalized_url, 'avif'),
                );
            }

            return array(
                'success'                 => true,
                'inputUrl'                => $input_url,
                'url'                     => $absolute_url,
                'normalizedUrl'           => $normalized_url,
                'cacheable'               => $cacheable,
                'reason'                  => $reason,
                'reasonLabel'             => $this->get_bypass_reason_label($reason),
                'local'                   => '' !== $absolute_url ? $this->is_cacheable_local_url($absolute_url) : false,
                'host'                    => isset($parts['host']) ? (string) $parts['host'] : '',
                'path'                    => isset($parts['path']) ? (string) $parts['path'] : '',
                'normalizedPath'          => $path,
                'query'                   => $query,
                'queryArgKeys'            => array_values(array_map('strval', array_keys($query_vars))),
                'matchedExcludedPathRule' => $matched_excluded_path_rule,
                'matchedExcludedQueryArg' => $matched_excluded_query_arg,
                'matchedNonAllowlistedQueryArg' => $matched_non_allowlisted_query_arg,
                'matchedWooRule'          => $matched_woo_rule,
                'matchedWooRuleType'      => $matched_woo_rule_type,
                'pageCacheEnabled'        => !empty($settings['enabled']),
                'wooSafeModeEnabled'      => !empty($settings['woo_safe_mode']),
                'cacheQueryStrings'       => !empty($settings['cache_query_strings']),
                'cachePaths'              => $cache_paths,
                'simulationNote'          => 'Inspection simulates an anonymous frontend request. Admin login state and browser cookies are ignored.',
            );
        }

        private function get_current_request_scheme()
        {
            $is_ssl = ultracache_server_flag_enabled('HTTPS')
                || ('443' === ultracache_server_value('SERVER_PORT'));

            if ($is_ssl) {
                return 'https';
            }

            $forwarded_proto_parts = explode(',', ultracache_server_value('HTTP_X_FORWARDED_PROTO'));
            $forwarded_proto = strtolower(trim((string) reset($forwarded_proto_parts)));
            if ('https' === $forwarded_proto) {
                return 'https';
            }

            $forwarded_scheme = strtolower(trim((string) ultracache_server_value('HTTP_X_FORWARDED_SCHEME')));
            if ('https' === $forwarded_scheme) {
                return 'https';
            }

            $forwarded_ssl = strtolower(trim((string) ultracache_server_value('HTTP_X_FORWARDED_SSL')));
            if (in_array($forwarded_ssl, array('on', '1', 'true', 'https'), true)) {
                return 'https';
            }

            $frontend_https = strtolower(trim((string) ultracache_server_value('HTTP_FRONT_END_HTTPS')));
            if (in_array($frontend_https, array('on', '1', 'true'), true)) {
                return 'https';
            }

            $cloudfront_proto = strtolower(trim((string) ultracache_server_value('HTTP_CLOUDFRONT_FORWARDED_PROTO')));
            if ('https' === $cloudfront_proto) {
                return 'https';
            }

            $cf_visitor = (string) ultracache_server_value('HTTP_CF_VISITOR');
            if (false !== stripos($cf_visitor, '"scheme":"https"')) {
                return 'https';
            }

            return 'http';
        }

        private function get_current_request_url()
        {
            if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return '';
            }

            $scheme_value = $this->get_current_request_scheme();
            $scheme = $scheme_value . '://';
            $host = ultracache_get_validated_http_host(ultracache_server_value('HTTP_HOST'), 'engine_current_request_url');
            if ('' === $host) {
                return '';
            }

            $uri = ultracache_server_value('REQUEST_URI');
            $url = $scheme . $host . $uri;
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return esc_url_raw($url);
            }

            $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
            $query = '';
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query_vars);
                unset($query_vars['ultracache_revalidate'], $query_vars['ultracache_rt'], $query_vars['ultracache_store_profile'], $query_vars['ultracache_callback_profile'], $query_vars['ultracache_store_profile_verbose'], $query_vars['ultracache_store_profile_verbose_settings'], $query_vars['ultracache_profile_bypass'], $query_vars['ultracache_profile_run'], $query_vars['ultracache_runtime_js_scan'], $query_vars['ultracache_runtime_js_scan_id'], $query_vars['ultracache_runtime_js_scan_nonce']);
                if (!empty($query_vars)) {
                    ksort($query_vars);
                    $query = http_build_query($query_vars);
                }
            }

            return esc_url_raw($scheme . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path . ($query ? '?' . $query : ''));
        }

        private function should_bypass_preload_url($url, array $args = array())
        {
            $this->last_bypass_reason = '';
            $settings = $this->get_settings();
            $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);

            if (empty($settings['enabled'])) {
                $this->last_bypass_reason = 'disabled';
                return true;
            }

            if (!$ignore_runtime_bypass && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                $this->last_bypass_reason = 'donotcachepage';
                return true;
            }

            if ($this->is_woocommerce_dynamic_request($url, $settings)) {
                return true;
            }

            if (empty($url) || !$this->is_cacheable_local_url($url)) {
                $this->last_bypass_reason = 'non-local-url';
                return true;
            }

            $parts = wp_parse_url($url);
            $path = isset($parts['path']) ? $this->normalize_path_value((string) $parts['path']) : '/';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';
            if ($this->has_invalid_internal_control_request($query)) {
                $this->last_bypass_reason = 'invalid-internal-control';
                return true;
            }

            $excluded_paths = !empty($settings['excluded_paths']) && is_array($settings['excluded_paths']) ? $settings['excluded_paths'] : array();
            $excluded_paths = $this->merge_hard_security_excluded_paths($excluded_paths);
            if ($this->path_matches_any_rule($path, $excluded_paths)) {
                $this->last_bypass_reason = 'excluded-path';
                return true;
            }

            $excluded_query_args = !empty($settings['excluded_query_args']) && is_array($settings['excluded_query_args']) ? $settings['excluded_query_args'] : array();
            $excluded_query_args = $this->merge_hard_security_excluded_query_args($excluded_query_args);
            if ('' !== $query) {
                if ($this->query_contains_excluded_keys($query, $excluded_query_args)) {
                    $this->last_bypass_reason = 'excluded-query-arg';
                    return true;
                }

                $query_allowlist = $this->get_query_allowlist($settings);
                if (empty($settings['cache_query_strings'])) {
                    $this->last_bypass_reason = 'query-strings-disabled';
                    return true;
                }

                if (empty($query_allowlist)) {
                    $this->last_bypass_reason = 'query-allowlist-empty';
                    return true;
                }

                if (!$this->query_has_cacheable_allowlisted_variant($query, $query_allowlist)) {
                    $this->last_bypass_reason = 'query-arg-not-allowlisted';
                    return true;
                }
            }

            return false;
        }
}
