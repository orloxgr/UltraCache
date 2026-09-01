<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Runtime_JS_Scanner_Trait
{
    private function get_runtime_js_scan_current_setting_lines($key)
    {
        $values = array();
        $key = (string) $key;
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_dashboard_settings_for_client')) {
            $settings = Ultra_Cache_WP::get_dashboard_settings_for_client();
            if (is_array($settings) && isset($settings[$key])) {
                $values[] = (string) $settings[$key];
            }
        }
        if (empty($values)) {
            $raw = get_option(ULTRACACHE_SETTINGS_KEY, array());
            if (is_array($raw) && isset($raw[$key])) {
                $values[] = (string) $raw[$key];
            }
        }
        $lines = preg_split('/\r\n|\r|\n/', implode("\n", $values));
        $out = array();
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ('' !== $line) {
                $out[] = strtolower($line);
            }
        }
        return array_values(array_unique($out));
    }

    private function get_runtime_js_scan_current_exclusions()
    {
        return array(
            'fallback' => $this->get_runtime_js_scan_current_setting_lines('deferJsExcludeList'),
            'force'    => $this->get_runtime_js_scan_current_setting_lines('deferJsForceList'),
            'delay'    => $this->get_runtime_js_scan_current_setting_lines('delaySafeThirdPartyJsPatterns'),
        );
    }

    private function runtime_js_scan_policy_ownership_option_key()
    {
        return 'ultracache_runtime_js_scan_owned_safeguards_v1';
    }

    private function runtime_js_scan_normalize_policy_ownership($value)
    {
        $value = is_array($value) ? $value : array();
        $out = array('exclusion' => array(), 'force' => array(), 'delay' => array());
        foreach (array_keys($out) as $lane) {
            foreach ((array) ($value[$lane] ?? array()) as $line) {
                $line = strtolower(trim((string) $line));
                if ('' !== $line) {
                    $out[$lane][$line] = true;
                }
            }
            $out[$lane] = array_keys($out[$lane]);
        }
        return $out;
    }

    private function runtime_js_scan_get_policy_ownership()
    {
        return $this->runtime_js_scan_normalize_policy_ownership(get_option($this->runtime_js_scan_policy_ownership_option_key(), array()));
    }

    private function runtime_js_scan_policy_line_is_scanner_owned($lane, $line)
    {
        $lane = sanitize_key((string) $lane);
        $line = strtolower(trim((string) $line));
        if ('' === $line || !in_array($lane, array('exclusion', 'force', 'delay'), true)) {
            return false;
        }
        $ownership = $this->runtime_js_scan_get_policy_ownership();
        return in_array($line, (array) ($ownership[$lane] ?? array()), true);
    }

    private function runtime_js_scan_prune_policy_ownership_to_visible_lists(array $ownership)
    {
        $visible = array(
            'exclusion' => $this->get_runtime_js_scan_current_setting_lines('deferJsExcludeList'),
            'force' => $this->get_runtime_js_scan_current_setting_lines('deferJsForceList'),
            'delay' => $this->get_runtime_js_scan_current_setting_lines('delaySafeThirdPartyJsPatterns'),
        );
        foreach (array('exclusion', 'force', 'delay') as $lane) {
            $allowed = array_fill_keys(array_map('strtolower', (array) ($visible[$lane] ?? array())), true);
            $ownership[$lane] = array_values(array_filter((array) ($ownership[$lane] ?? array()), static function ($line) use ($allowed) {
                return isset($allowed[strtolower(trim((string) $line))]);
            }));
        }
        return $this->runtime_js_scan_normalize_policy_ownership($ownership);
    }

    private function runtime_js_scan_update_policy_ownership_after_settings_save($decision_json, array $patch)
    {
        $lane_keys = array(
            'exclusion' => 'deferJsExcludeList',
            'force' => 'deferJsForceList',
            'delay' => 'delaySafeThirdPartyJsPatterns',
        );
        $touched = array();
        foreach ($lane_keys as $lane => $setting_key) {
            if (array_key_exists($setting_key, $patch)) {
                $touched[$lane] = true;
            }
        }
        if (empty($touched)) {
            return;
        }

        $ownership = $this->runtime_js_scan_get_policy_ownership();
        $decision = json_decode((string) $decision_json, true);
        $is_runtime_write = is_array($decision) && 'runtime-scan-auto' === (string) ($decision['source'] ?? '');
        if (!$is_runtime_write) {
            foreach (array_keys($touched) as $lane) {
                $ownership[$lane] = array();
            }
            update_option($this->runtime_js_scan_policy_ownership_option_key(), $this->runtime_js_scan_prune_policy_ownership_to_visible_lists($ownership), false);
            return;
        }

        $target = sanitize_key((string) ($decision['target'] ?? ''));
        $lines = array();
        foreach ((array) ($decision['lines'] ?? array()) as $line) {
            $line = strtolower(trim((string) $line));
            if ('' !== $line) {
                $lines[$line] = true;
            }
        }
        if (!in_array($target, array('exclusion', 'force', 'delay'), true) || empty($lines)) {
            foreach (array_keys($touched) as $lane) {
                $ownership[$lane] = array();
            }
            update_option($this->runtime_js_scan_policy_ownership_option_key(), $this->runtime_js_scan_prune_policy_ownership_to_visible_lists($ownership), false);
            return;
        }

        foreach (array('exclusion', 'force', 'delay') as $lane) {
            $current = array_fill_keys((array) ($ownership[$lane] ?? array()), true);
            foreach (array_keys($lines) as $line) {
                unset($current[$line]);
                if ($lane === $target) {
                    $current[$line] = true;
                }
            }
            $ownership[$lane] = array_keys($current);
        }
        update_option($this->runtime_js_scan_policy_ownership_option_key(), $this->runtime_js_scan_prune_policy_ownership_to_visible_lists($ownership), false);
    }

    public function runtime_js_scan_strategy_state(WP_REST_Request $request)
    {
        $strategy = sanitize_key((string) $request->get_param('javascriptStrategy'));
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'sanitize_javascript_strategy')) {
            $strategy = Ultra_Cache_WP::sanitize_javascript_strategy($strategy);
        } elseif (!in_array($strategy, array('off', 'defer', 'delay'), true)) {
            $strategy = 'off';
        }

        $option_name = 'ultracache_runtime_js_scan_last_javascript_strategy';
        $dirty_option_name = 'ultracache_runtime_js_scan_javascript_strategy_dirty';
        $previous = sanitize_key((string) get_option($option_name, ''));
        $previous_valid = in_array($previous, array('off', 'defer', 'delay'), true);
        $dirty = '1' === (string) get_option($dirty_option_name, '');
        $active_strategy_switch = $previous_valid && (
            ('defer' === $previous && 'delay' === $strategy)
            || ('delay' === $previous && 'defer' === $strategy)
        );
        $changed = $dirty && $active_strategy_switch;
        $commit = rest_sanitize_boolean($request->get_param('commit'));

        if ($commit) {
            update_option($option_name, $strategy, false);
            delete_option($dirty_option_name);
        }

        return new WP_REST_Response(array(
            'success'     => true,
            'current'     => $strategy,
            'previous'    => $previous_valid ? $previous : '',
            'initialized' => $previous_valid,
            'dirty'       => $dirty,
            'changed'     => $changed,
            'committed'   => $commit,
        ), 200);
    }

    /**
     * Resolve one bounded representative frontend target set for a Runtime Scan session.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function runtime_js_scan_targets(WP_REST_Request $request)
    {
        unset($request);

        $targets = array();
        $seen = array();
        $add_target = static function ($role, $label, $url, $object_id = 0) use (&$targets, &$seen) {
            $url = esc_url_raw((string) $url);
            if ('' === $url) {
                return;
            }

            $key = strtolower(untrailingslashit($url));
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $targets[] = array(
                'role'     => sanitize_key((string) $role),
                'label'    => sanitize_text_field((string) $label),
                'url'      => $url,
                'objectId' => max(0, (int) $object_id),
            );
        };

        $front_page_id = (int) get_option('page_on_front');
        $add_target('home', __('Front page', 'ultracache'), home_url('/'), $front_page_id);

        $excluded_page_ids = array_filter(array($front_page_id));
        $woocommerce_active = class_exists('WooCommerce') && function_exists('wc_get_page_id');
        $shop_page_id = 0;

        if ($woocommerce_active) {
            foreach (array('shop', 'cart', 'checkout', 'myaccount', 'terms') as $woocommerce_page_key) {
                $woocommerce_page_id = (int) wc_get_page_id($woocommerce_page_key);
                if ($woocommerce_page_id > 0) {
                    $excluded_page_ids[] = $woocommerce_page_id;
                }
                if ('shop' === $woocommerce_page_key) {
                    $shop_page_id = $woocommerce_page_id;
                }
            }
        }

        $excluded_page_ids = array_values(array_filter(array_unique(array_map('absint', $excluded_page_ids))));
        $random_page_ids = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => max(1, count($excluded_page_ids) + 1),
            'orderby'          => 'rand',
            'fields'           => 'ids',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ));
        foreach ((array) $random_page_ids as $random_page_candidate_id) {
            $random_page_id = absint($random_page_candidate_id);
            if ($random_page_id <= 0 || in_array($random_page_id, $excluded_page_ids, true)) {
                continue;
            }
            $add_target('page', __('Random published page', 'ultracache'), get_permalink($random_page_id), $random_page_id);
            break;
        }

        if ($woocommerce_active) {
            if ($shop_page_id > 0 && 'publish' === get_post_status($shop_page_id)) {
                $add_target('shop', __('WooCommerce shop', 'ultracache'), get_permalink($shop_page_id), $shop_page_id);
            }

            if (function_exists('wc_get_products')) {
                $product_page = wc_get_products(array(
                    'status'   => 'publish',
                    'limit'    => 1,
                    'page'     => 1,
                    'paginate' => true,
                    'return'   => 'ids',
                ));
                $product_total = is_object($product_page) && isset($product_page->total)
                    ? max(0, (int) $product_page->total)
                    : 0;
                if ($product_total > 0) {
                    $random_product_page = wp_rand(1, $product_total);
                    $random_product_result = 1 === $random_product_page
                        ? $product_page
                        : wc_get_products(array(
                            'status'   => 'publish',
                            'limit'    => 1,
                            'page'     => $random_product_page,
                            'paginate' => true,
                            'return'   => 'ids',
                        ));
                    $product_ids = is_object($random_product_result) && isset($random_product_result->products)
                        ? (array) $random_product_result->products
                        : array();
                    if (!empty($product_ids[0])) {
                        $product_id = (int) $product_ids[0];
                        $add_target('product', __('Random published product', 'ultracache'), get_permalink($product_id), $product_id);
                    }
                }
            }
        }

        return new WP_REST_Response(array(
            'success'     => true,
            'targets'     => $targets,
            'targetCount' => count($targets),
            'woocommerce' => $woocommerce_active,
        ), 200);
    }

    /**
     * Resolve same-site redirects without loading the target in the browser scanner context.
     *
     * Runtime Scan measurements must start from the first browser lifecycle. Redirect
     * resolution therefore happens server-side before the one-time scan token is minted.
     * If the public HEAD probe cannot be completed, the already-normalized same-site URL
     * is retained and the browser measurement will fail closed if the collector is lost.
     *
     * @param string $url Normalized same-site target URL.
     * @return string
     */
    private function runtime_js_scan_resolve_final_target_url($url)
    {
        $current = function_exists('ultracache_runtime_js_scan_normalize_target_url')
            ? ultracache_runtime_js_scan_normalize_target_url($url)
            : '';
        if ('' === $current) {
            return '';
        }

        for ($hop = 0; $hop < 5; $hop++) {
            $response = wp_safe_remote_head($current, array(
                'timeout'             => 6,
                'redirection'         => 0,
                'reject_unsafe_urls'  => true,
                'user-agent'          => 'UltraCache Runtime Scan URL Resolver/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown'),
                'headers'             => array(
                    'Cache-Control' => 'no-cache',
                    'Pragma'        => 'no-cache',
                ),
            ));

            if (is_wp_error($response)) {
                return $current;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if (in_array($code, array(405, 501), true)) {
                $response = wp_safe_remote_get($current, array(
                    'timeout'             => 6,
                    'redirection'         => 0,
                    'reject_unsafe_urls'  => true,
                    'limit_response_size' => 1,
                    'user-agent'          => 'UltraCache Runtime Scan URL Resolver/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown'),
                    'headers'             => array(
                        'Cache-Control' => 'no-cache',
                        'Pragma'        => 'no-cache',
                    ),
                ));
                if (is_wp_error($response)) {
                    return $current;
                }
                $code = (int) wp_remote_retrieve_response_code($response);
            }

            if ($code < 300 || $code >= 400) {
                return $current;
            }

            $location = trim((string) wp_remote_retrieve_header($response, 'location'));
            if ('' === $location) {
                return $current;
            }

            if (class_exists('WP_Http') && method_exists('WP_Http', 'make_absolute_url')) {
                $location = WP_Http::make_absolute_url($location, $current);
            }

            $next = ultracache_runtime_js_scan_normalize_target_url($location);
            if ('' === $next || $next === $current) {
                return $current;
            }
            $current = $next;
        }

        return $current;
    }

    public function create_runtime_js_scan_token(WP_REST_Request $request)
    {
        $scan_id = sanitize_key((string) $request->get_param('scanId'));
        $target_url = esc_url_raw((string) $request->get_param('url'));
        if ('' === $scan_id || strlen($scan_id) > 64) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Invalid runtime JS scan id.', 'ultracache')), 400);
        }
        if (!function_exists('ultracache_runtime_js_scan_normalize_target_url') || !function_exists('ultracache_runtime_js_scan_mint_token')) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Runtime JS scan token service is unavailable.', 'ultracache')), 500);
        }

        $normalized_url = ultracache_runtime_js_scan_normalize_target_url($target_url);
        if ('' === $normalized_url) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Runtime JS scan URL must be on this site.', 'ultracache')), 400);
        }

        $resolved_url = $this->runtime_js_scan_resolve_final_target_url($normalized_url);
        if ('' === $resolved_url) {
            $resolved_url = $normalized_url;
        }

        $token = ultracache_runtime_js_scan_mint_token($scan_id, $resolved_url);
        if ('' === $token) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Could not create the Runtime JS scan token.', 'ultracache')), 500);
        }

        return new WP_REST_Response(array(
            'success'   => true,
            'scanId'    => $scan_id,
            'scanToken' => $token,
            'expiresIn' => 300,
            'url'          => $resolved_url,
            'requestedUrl' => $normalized_url,
            'language'     => function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($resolved_url)
                : '',
        ), 200);
    }

    private function runtime_js_scan_clean_console_candidate($candidate)
    {
        $candidate = html_entity_decode((string) $candidate, ENT_QUOTES, 'UTF-8');
        $candidate = preg_replace('/^[\s\(\[\{\"\'`@]+/', '', $candidate);
        $candidate = preg_replace('/[\s\)\]\}\"\'`,;]+$/', '', (string) $candidate);
        $candidate = preg_replace('/(?::\d+){1,2}$/', '', (string) $candidate);
        $candidate = preg_replace('/[?#].*$/', '', (string) $candidate);
        return trim((string) $candidate);
    }

    private function runtime_js_scan_sanitize_source($source)
    {
        $source = $this->runtime_js_scan_clean_console_candidate($source);
        if ('' === $source) {
            return '';
        }

        $source = html_entity_decode($source, ENT_QUOTES, 'UTF-8');
        if (preg_match('#^https?://#i', $source) || 0 === strpos($source, '/') || 0 === strpos($source, '//')) {
            return esc_url_raw($source);
        }

        // Preserve browser pseudo-sources such as wp-api-fetch-js-after:3225.
        // These inline WordPress handles are not valid URLs, but they are the
        // only reliable clue for mapping an inline-after error to its handle.
        return sanitize_text_field($source);
    }

    private function runtime_js_scan_sanitize_display_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $url = esc_url_raw($url);
        if ('' === $url) {
            return '';
        }

        return remove_query_arg(array(
            'ultracache_runtime_js_scan',
            'ultracache_runtime_js_scan_id',
            'ultracache_runtime_js_scan_token',
            'ultracache_runtime_js_scan_nonce',
            'ultracache_runtime_js_scan_mode',
            'ultracache_runtime_js_scan_context',
            'ultracache_rt',
            'ultracache_profile_bypass',
            'ultracache_store_profile',
            'ultracache_callback_profile',
            'ultracache_store_profile_verbose',
            'ultracache_store_profile_verbose_settings',
            'ultracache_profile_run',
            'ultracache_revalidate',
        ), $url);
    }

    private function runtime_js_scan_source_from_text($text)
    {
        $text = (string) $text;
        if ('' === $text) {
            return '';
        }

        if (preg_match('#https?://[^\s\)\]\}"\'<>]+\.js(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}#i', $text, $match)) {
            return $this->runtime_js_scan_sanitize_source((string) $match[0]);
        }

        if (preg_match('/([A-Za-z0-9._\/-]+\.js)(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}/i', $text, $match)) {
            return $this->runtime_js_scan_sanitize_source((string) $match[0]);
        }

        return '';
    }

    private function runtime_js_scan_split_symbol_tokens($symbol)
    {
        $symbol = trim((string) $symbol);
        if ('' === $symbol) {
            return array();
        }

        $expanded = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $symbol);
        $parts = preg_split('/[^A-Za-z0-9]+/', (string) $expanded);
        $tokens = array();
        foreach ((array) $parts as $part) {
            $token = strtolower(trim((string) $part));
            if ($this->runtime_js_scan_is_generic_token($token)) {
                continue;
            }
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }

    private function runtime_js_scan_script_basenames_from_text($text)
    {
        $text = (string) $text;
        $out = array();
        if ('' === $text) {
            return array();
        }

        if (preg_match_all('/(?:https?:\/\/[^\s\)\]\}\"\']+\/)?([^\s\)\]\}\"\'\/]+\.js)(?:\?[^\s\)\]\}\"\']*)?(?::\d+){0,2}/i', $text, $matches)) {
            foreach ((array) $matches[1] as $base) {
                $base = sanitize_text_field(basename((string) $base));
                if ('' === $base) {
                    continue;
                }
                $lower = strtolower($base);
                if ($this->runtime_js_scan_is_generic_script_basename($lower)) {
                    continue;
                }
                $out[$base] = true;
            }
        }

        return array_keys($out);
    }

    private function runtime_js_scan_url_fragments_from_text($text)
    {
        $text = (string) $text;
        $out = array();
        if ('' === $text) {
            return array();
        }

        if (preg_match_all('#https?://[^\s\)\]\}\"\'<>]+#i', $text, $matches)) {
            foreach ((array) $matches[0] as $url) {
                $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
                $url = preg_replace('/(?::\d+){1,2}$/', '', $url);
                $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
                $path = (string) wp_parse_url($url, PHP_URL_PATH);

                if ('' !== $host && !$this->runtime_js_scan_is_generic_token($host)) {
                    $is_local_host = function_exists('ultracache_is_trusted_public_host')
                        ? ultracache_is_trusted_public_host($host)
                        : in_array($host, array(
                            strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
                            strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST)),
                        ), true);
                    if (!$is_local_host) {
                        $out[$host] = true;
                    }
                }

                $path = trim($path, '/');
                if ('' === $path) {
                    continue;
                }

                $parts = array_values(array_filter(explode('/', strtolower($path)), 'strlen'));
                if (count($parts) >= 2) {
                    $last_two = implode('/', array_slice($parts, -2));
                    if (false === strpos($last_two, '.js') && false === strpos($last_two, '.css')) {
                        $out[$last_two] = true;
                    }
                }
                if (count($parts) >= 3) {
                    $last_three = implode('/', array_slice($parts, -3));
                    if (false === strpos($last_three, '.js') && false === strpos($last_three, '.css')) {
                        $out[$last_three] = true;
                    }
                }
            }
        }

        return array_keys($out);
    }

    private function runtime_js_scan_wordpress_family_from_id($id)
    {
        $id = sanitize_text_field(substr((string) $id, 0, 160));
        if ('' === $id) {
            return '';
        }

        if (!preg_match('/^(.+)-js(?:-extra|-before|-after|-translations)?$/i', $id, $match)) {
            return '';
        }

        return sanitize_key((string) ($match[1] ?? ''));
    }

    private function runtime_js_scan_normalize_script_inventory(array $scripts)
    {
        $out = array();
        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }

            $order = isset($script['order']) ? max(0, (int) $script['order']) : count($out);
            $src = isset($script['src']) ? $this->runtime_js_scan_sanitize_source((string) $script['src']) : '';
            $id = isset($script['id']) ? sanitize_text_field(substr((string) $script['id'], 0, 160)) : '';
            $handle = isset($script['handle']) ? sanitize_text_field(substr((string) $script['handle'], 0, 160)) : '';
            if ('' === $id && '' !== $handle) {
                $id = $handle;
            }
            $type = isset($script['type']) ? sanitize_text_field(substr((string) $script['type'], 0, 120)) : '';
            $strategy = isset($script['strategy']) ? sanitize_text_field(substr((string) $script['strategy'], 0, 80)) : '';
            $deps = array();
            foreach ((array) ($script['deps'] ?? array()) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $deps[$dependency] = true;
                }
            }
            $text = isset($script['text']) ? sanitize_textarea_field(substr((string) $script['text'], 0, 60000)) : '';
            if ('' === $id && '' !== $text) {
                $source_url_id = $this->runtime_js_scan_source_url_id_from_inline_text($text);
                if ('' !== $source_url_id) {
                    $id = $source_url_id;
                }
            }

            if ('' === $src && '' === $id && '' === $text) {
                continue;
            }

            $out[] = array(
                'order'    => $order,
                'executionSequence' => isset($script['executionSequence']) ? max(0, (int) $script['executionSequence']) : 0,
                'executionLane' => isset($script['executionLane']) ? sanitize_key((string) $script['executionLane']) : '',
                'family' => isset($script['family']) ? sanitize_key((string) $script['family']) : '',
                'familySequence' => isset($script['familySequence']) ? max(0, (int) $script['familySequence']) : 0,
                'familyPhase' => isset($script['familyPhase']) ? sanitize_key((string) $script['familyPhase']) : '',
                'id'       => $id,
                'handle'   => $handle,
                'src'      => $src,
                'type'     => $type,
                'defer'    => !empty($script['defer']),
                'async'    => !empty($script['async']),
                'strategy' => $strategy,
                'delayed'  => !empty($script['delayed']),
                'deps'     => array_keys($deps),
                'text'     => $text,
            );

            if (count($out) >= 240) {
                break;
            }
        }

        // WordPress can print one registered handle as several script elements, such as
        // handle-js, handle-js-before/after, and handle-js-translations. The registry
        // metadata may land on only one fragment. Build families only from an explicit
        // WordPress handle captured by data-ultracache-handle, then let matching sibling
        // IDs inherit that canonical handle and its declared dependency list. Do not
        // infer a new family from an ID alone.
        $families = array();
        $family_aliases = array();
        foreach ($out as $script) {
            $handle = sanitize_key((string) ($script['handle'] ?? ''));
            if ('' === $handle) {
                continue;
            }
            if (!isset($families[$handle])) {
                $families[$handle] = array('deps' => array());
            }
            $family_aliases[$handle][$handle] = true;
            $id_family = $this->runtime_js_scan_wordpress_family_from_id((string) ($script['id'] ?? ''));
            if ('' !== $id_family) {
                $family_aliases[$id_family][$handle] = true;
            }
            foreach ((array) ($script['deps'] ?? array()) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $families[$handle]['deps'][$dependency] = true;
                }
            }
        }

        if (empty($families)) {
            return $out;
        }

        foreach ($out as &$script) {
            $handle = sanitize_key((string) ($script['handle'] ?? ''));
            $family = $handle;
            if ('' === $family) {
                $candidate = $this->runtime_js_scan_wordpress_family_from_id((string) ($script['id'] ?? ''));
                if ('' !== $candidate && !empty($family_aliases[$candidate]) && 1 === count($family_aliases[$candidate])) {
                    $family = (string) array_key_first($family_aliases[$candidate]);
                    $script['handle'] = $family;
                }
            }

            if ('' === $family || !isset($families[$family])) {
                continue;
            }

            $deps = array();
            foreach ((array) ($script['deps'] ?? array()) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $deps[$dependency] = true;
                }
            }
            foreach (array_keys($families[$family]['deps']) as $dependency) {
                $deps[$dependency] = true;
            }
            $script['deps'] = array_keys($deps);
        }
        unset($script);

        return $out;
    }

    private function runtime_js_scan_merge_script_inventories(array $fetched_scripts, array $runtime_scripts)
    {
        $fetched_scripts = $this->runtime_js_scan_normalize_script_inventory($fetched_scripts);
        $runtime_scripts = $this->runtime_js_scan_normalize_script_inventory($runtime_scripts);
        if (empty($runtime_scripts)) {
            return $fetched_scripts;
        }

        $merged = array();
        $positions = array();
        $key_for = static function (array $script) {
            $src = trim((string) ($script['src'] ?? ''));
            if ('' !== $src) {
                $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
                $path = strtolower((string) wp_parse_url($src, PHP_URL_PATH));
                if ('' !== $path) {
                    return 'src:' . $host . $path;
                }
                return 'src:' . strtolower(preg_replace('/[?#].*$/', '', $src));
            }
            // Inline WordPress companions frequently share one registered handle
            // (handle-js-before / handle-js-after / translations / extra) while
            // remaining distinct executable segments. Prefer their concrete DOM id
            // before the family handle so merge enrichment cannot collapse several
            // inline providers/consumers into one record. External scripts continue
            // to key by normalized src above.
            $id = strtolower(trim((string) ($script['id'] ?? '')));
            if ('' !== $id) {
                return 'id:' . $id;
            }
            $handle = sanitize_key((string) ($script['handle'] ?? ''));
            return '' !== $handle ? 'handle:' . $handle : '';
        };

        // Runtime Scan is the authoritative loaded-script set and execution state.
        // Keep those records first so the 240-entry cap cannot discard a script
        // that actually existed in the failing browser document.
        foreach ($runtime_scripts as $runtime_script) {
            if (!is_array($runtime_script)) {
                continue;
            }
            $key = $key_for($runtime_script);
            if ('' !== $key && isset($positions[$key])) {
                continue;
            }
            if ('' !== $key) {
                $positions[$key] = count($merged);
            }
            $merged[] = $runtime_script;
            if (count($merged) >= 240) {
                break;
            }
        }

        // Enrich matching runtime records with the server-side inventory because
        // that fetch can carry WordPress dependency metadata and fuller inline
        // source text. Do not overwrite browser-observed defer/async/delay state.
        foreach ($fetched_scripts as $fetched_script) {
            if (!is_array($fetched_script)) {
                continue;
            }
            $key = $key_for($fetched_script);
            if ('' === $key || !isset($positions[$key])) {
                if (count($merged) >= 240) {
                    continue;
                }
                if ('' !== $key) {
                    $positions[$key] = count($merged);
                }
                $merged[] = $fetched_script;
                continue;
            }

            $index = (int) $positions[$key];
            $existing = isset($merged[$index]) && is_array($merged[$index]) ? $merged[$index] : array();
            foreach (array('id', 'handle', 'src', 'type', 'strategy') as $field) {
                if ('' === trim((string) ($existing[$field] ?? '')) && '' !== trim((string) ($fetched_script[$field] ?? ''))) {
                    $existing[$field] = $fetched_script[$field];
                }
            }
            $deps = array();
            foreach (array_merge((array) ($existing['deps'] ?? array()), (array) ($fetched_script['deps'] ?? array())) as $dependency) {
                $dependency = sanitize_key((string) $dependency);
                if ('' !== $dependency) {
                    $deps[$dependency] = true;
                }
            }
            $existing['deps'] = array_keys($deps);
            if ('' !== trim((string) ($fetched_script['text'] ?? ''))) {
                $existing['text'] = (string) $fetched_script['text'];
            }
            $merged[$index] = $existing;
        }

        return $this->runtime_js_scan_normalize_script_inventory(array_slice($merged, 0, 240));
    }

    private function runtime_js_scan_inventory_summary(array $scripts)
    {
        $summary = array(
            'total'      => count($scripts),
            'external'   => 0,
            'inline'     => 0,
            'delayed'    => 0,
            'sourceUrl'  => 0,
            'declaredDependencyEdges' => 0,
        );

        foreach ($scripts as $script) {
            if (!is_array($script)) {
                continue;
            }
            if (!empty($script['src'])) {
                $summary['external']++;
            } else {
                $summary['inline']++;
            }
            if (!empty($script['delayed'])) {
                $summary['delayed']++;
            }
            $summary['declaredDependencyEdges'] += count((array) ($script['deps'] ?? array()));
            $text = isset($script['text']) ? (string) $script['text'] : '';
            if ('' !== $text && '' !== $this->runtime_js_scan_source_url_id_from_inline_text($text)) {
                $summary['sourceUrl']++;
            }
        }

        return $summary;
    }

    private function runtime_js_scan_processor_attribute($processor, $name)
    {
        if (!$processor instanceof WP_HTML_Tag_Processor) {
            return '';
        }

        $value = $processor->get_attribute((string) $name);
        if (null === $value || false === $value) {
            return '';
        }
        if (true === $value) {
            return (string) $name;
        }

        return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function runtime_js_scan_url_to_absolute($url, $base_url = '')
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
        if ('' === $url) {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return esc_url_raw($url);
        }
        if (0 === strpos($url, '//')) {
            $scheme = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME);
            return esc_url_raw(($scheme ? $scheme : 'https') . ':' . $url);
        }
        if (0 === strpos($url, '/')) {
            return esc_url_raw(home_url($url));
        }

        $base_url = '' !== (string) $base_url ? (string) $base_url : home_url('/');
        $parts = wp_parse_url($base_url);
        if (empty($parts['host'])) {
            return esc_url_raw(home_url('/' . ltrim($url, '/')));
        }

        $scheme = !empty($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = (string) $parts['host'];
        $port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = !empty($parts['path']) ? (string) $parts['path'] : '/';
        $dir = rtrim(str_replace('\\\\', '/', dirname($path)), '/');
        if ('.' === $dir || '' === $dir) {
            $dir = '';
        }

        $combined = $dir . '/' . ltrim($url, '/');
        $segments = array();
        foreach (explode('/', $combined) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return esc_url_raw($scheme . '://' . $host . $port . '/' . implode('/', $segments));
    }

    private function runtime_js_scan_source_url_id_from_inline_text($text)
    {
        $text = (string) $text;
        if ('' === $text) {
            return '';
        }
        if (preg_match('/#\s*sourceURL\s*=\s*([^\s\r\n<]+)/i', $text, $match)) {
            $id = trim((string) $match[1]);
            $id = preg_replace('/[?#].*$/', '', $id);
            $id = basename($id);
            $id = sanitize_text_field(substr((string) $id, 0, 160));
            if ('' !== $id && !$this->runtime_js_scan_is_generic_token($id)) {
                return $id;
            }
        }
        return '';
    }

    private function runtime_js_scan_fetch_script_inventory_for_url($url = '')
    {
        $url = trim((string) $url);
        if ('' === $url) {
            $url = home_url('/');
        }

        $normalized = $this->normalize_performance_profile_url($url);
        if (is_wp_error($normalized)) {
            return array();
        }

        $request_url = add_query_arg(array(
            'ultracache_js_inventory' => '1',
            'ultracache_rt'           => time(),
        ), $normalized);

        $response = ultracache_safe_loopback_remote_request($request_url, array(
            'timeout'     => 8,
            'redirection' => 3,
            'headers'     => array(
                'Accept'        => 'text/html,application/xhtml+xml',
                'Cache-Control' => 'no-cache',
                'Pragma'        => 'no-cache',
            ),
            'user-agent'  => 'UltraCache JS inventory/' . (defined('ULTRACACHE_VERSION') ? ULTRACACHE_VERSION : 'unknown') . '; ' . home_url('/'),
        ), 'runtime-js-inventory-scan');
        if (is_wp_error($response)) {
            return array();
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return array();
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ('' === $html || !class_exists('WP_HTML_Tag_Processor')) {
            return array();
        }

        $scripts = array();
        try {
            $processor = new WP_HTML_Tag_Processor($html);
            while ($processor->next_tag('SCRIPT')) {
                $src = $this->runtime_js_scan_processor_attribute($processor, 'src');
                if ('' === $src) {
                    $src = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-src');
                }
                if ('' === $src) {
                    $src = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-original-src');
                }

                $body = method_exists($processor, 'get_modifiable_text') ? (string) $processor->get_modifiable_text() : '';
                $id = $this->runtime_js_scan_processor_attribute($processor, 'id');
                if ('' === $id) {
                    $id = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-id');
                }
                if ('' === $id) {
                    $source_url_id = $this->runtime_js_scan_source_url_id_from_inline_text($body);
                    if ('' !== $source_url_id) {
                        $id = $source_url_id;
                    }
                }
                if ('' === $id) {
                    $handle_id = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-handle');
                    if ('' !== $handle_id) {
                        $id = $handle_id;
                    }
                }

                $type = $this->runtime_js_scan_processor_attribute($processor, 'type');
                $handle = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-handle');
                $dependency_text = $this->runtime_js_scan_processor_attribute($processor, 'data-ultracache-deps');
                $deps = array();
                foreach (preg_split('/\s*,\s*/', $dependency_text) as $dependency) {
                    $dependency = sanitize_key((string) $dependency);
                    if ('' !== $dependency) {
                        $deps[$dependency] = true;
                    }
                }
                $strategy = $this->runtime_js_scan_processor_attribute($processor, 'data-wp-strategy');
                $is_delayed = (null !== $processor->get_attribute('data-ultracache-src')
                    || null !== $processor->get_attribute('data-ultracache-inline')
                    || null !== $processor->get_attribute('data-ultracache-delayed')
                    || false !== stripos($type, 'ultracache-delayed'));

                $scripts[] = array(
                    'order'    => count($scripts),
                    'id'       => sanitize_text_field(substr($id, 0, 160)),
                    'handle'   => sanitize_text_field(substr($handle, 0, 160)),
                    'src'      => '' !== $src ? $this->runtime_js_scan_url_to_absolute($src, $normalized) : '',
                    'type'     => sanitize_text_field(substr($type, 0, 120)),
                    'defer'    => null !== $processor->get_attribute('defer'),
                    'async'    => null !== $processor->get_attribute('async'),
                    'strategy' => $strategy,
                    'delayed'  => $is_delayed,
                    'deps'     => array_keys($deps),
                    'text'     => '' === $src || $is_delayed ? sanitize_textarea_field(substr($body, 0, 60000)) : '',
                );

                if (count($scripts) >= 240) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $this->runtime_js_scan_normalize_script_inventory($scripts);
    }

    private function runtime_js_scan_local_file_path_from_script_src($src)
    {
        $src = $this->runtime_js_scan_clean_console_candidate($src);
        if ('' === $src) {
            return '';
        }

        $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
        $absolute = $this->runtime_js_scan_url_to_absolute($src);
        if ('' === $absolute || !function_exists('ultracache_local_path_from_public_url')) {
            return '';
        }

        $path = ultracache_local_path_from_public_url($absolute, array('js', 'mjs'));
        if ('' === $path || !is_file($path) || !is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        if (false === $size || $size <= 0 || $size > 786432) {
            return '';
        }

        return $path;
    }

    private function runtime_js_scan_read_local_script_content($src)
    {
        static $cache = array();
        $src_key = md5((string) $src);
        if (array_key_exists($src_key, $cache)) {
            return $cache[$src_key];
        }

        $path = $this->runtime_js_scan_local_file_path_from_script_src($src);
        if ('' === $path) {
            $cache[$src_key] = '';
            return '';
        }

        $content = '';
        if (function_exists('ultracache_guarded_asset_file_get_contents')) {
            $raw = ultracache_guarded_asset_file_get_contents($path, 'js', 'runtime_js_scan_read_local_script_content', true);
            if (is_string($raw)) {
                $content = $raw;
            }
        }

        if (strlen($content) > 786432) {
            $content = '';
        }
        $cache[$src_key] = $content;
        return $content;
    }

    private function runtime_js_scan_script_content($script)
    {
        if (!is_array($script)) {
            return '';
        }
        $text = isset($script['text']) ? (string) $script['text'] : '';
        if ('' !== trim($text)) {
            return $text;
        }
        $src = isset($script['src']) ? (string) $script['src'] : '';
        if ('' === $src) {
            return '';
        }
        return $this->runtime_js_scan_read_local_script_content($src);
    }

    private function runtime_js_scan_theme_stage_roots()
    {
        $roots = array();
        $seen = array();
        $stylesheet = function_exists('get_stylesheet') ? sanitize_key((string) get_stylesheet()) : '';
        $template = function_exists('get_template') ? sanitize_key((string) get_template()) : '';

        $push = function ($stage, $slug, $dir, $uri) use (&$roots, &$seen) {
            $slug = sanitize_key((string) $slug);
            $dir = function_exists('wp_normalize_path') ? wp_normalize_path((string) $dir) : str_replace('\\', '/', (string) $dir);
            $uri = esc_url_raw((string) $uri);
            if ('' === $slug || '' === $dir || '' === $uri) {
                return;
            }
            $key = strtolower($slug . '|' . $dir);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $roots[] = array(
                'stage' => sanitize_text_field((string) $stage),
                'slug'  => $slug,
                'dir'   => untrailingslashit($dir),
                'uri'   => untrailingslashit($uri),
            );
        };

        if (function_exists('get_stylesheet_directory') && function_exists('get_stylesheet_directory_uri')) {
            $push(('' !== $template && '' !== $stylesheet && $stylesheet !== $template) ? 'active child theme' : 'active theme', $stylesheet, get_stylesheet_directory(), get_stylesheet_directory_uri());
        }

        if ('' !== $template && $template !== $stylesheet && function_exists('get_template_directory') && function_exists('get_template_directory_uri')) {
            $push('parent theme', $template, get_template_directory(), get_template_directory_uri());
        }

        return $roots;
    }

    private function runtime_js_scan_theme_stage_files($root, $max_files = 80, $max_depth = 6)
    {
        $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
        $root = untrailingslashit($root);
        if ('' === $root) {
            return array();
        }

        $files = array();
        $queue = array(array($root, 0));
        $blocked_dirs = array('node_modules', 'vendor', '.git', 'cache', 'dist/cache', 'build/cache');

        $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
        if (!$filesystem || !is_object($filesystem)) {
            return array();
        }

        while (!empty($queue) && count($files) < (int) $max_files) {
            $current = array_shift($queue);
            $dir = isset($current[0]) ? (string) $current[0] : '';
            $depth = isset($current[1]) ? (int) $current[1] : 0;
            if ('' === $dir || $depth > (int) $max_depth) {
                continue;
            }

            $items = ultracache_safe_scandir($dir, 'runtime_js_theme_stage_scan');
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $item = (string) $item;
                if ('.' === $item || '..' === $item || '' === $item) {
                    continue;
                }
                $path = function_exists('wp_normalize_path') ? wp_normalize_path(trailingslashit($dir) . $item) : str_replace('\\', '/', trailingslashit($dir) . $item);
                $lower_item = strtolower($item);
                if ($filesystem->is_dir($path)) {
                    if ($depth >= (int) $max_depth || in_array($lower_item, $blocked_dirs, true)) {
                        continue;
                    }
                    $queue[] = array($path, $depth + 1);
                    continue;
                }
                if (!$filesystem->is_file($path)) {
                    continue;
                }
                if (method_exists($filesystem, 'size') && (int) $filesystem->size($path) > 786432) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, array('js', 'mjs'), true)) {
                    continue;
                }
                $files[] = $path;
                if (count($files) >= (int) $max_files) {
                    break;
                }
            }
        }

        return $files;
    }

    private function runtime_js_scan_theme_stage_relative_path($file, $root)
    {
        $file = function_exists('wp_normalize_path') ? wp_normalize_path((string) $file) : str_replace('\\', '/', (string) $file);
        $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
        $root = trailingslashit($root);
        if (0 !== strpos($file, $root)) {
            return '';
        }
        return ltrim(substr($file, strlen($root)), '/');
    }

    private function runtime_js_scan_active_plugin_slugs()
    {
        $slugs = array();
        $push = static function ($plugin_file) use (&$slugs) {
            $plugin_file = trim((string) $plugin_file);
            if ('' === $plugin_file) {
                return;
            }
            if (function_exists('plugin_basename')) {
                $plugin_file = plugin_basename($plugin_file);
            }
            $plugin_file = str_replace('\\', '/', $plugin_file);
            $dir = dirname($plugin_file);
            $slug = ('.' === $dir || '' === $dir) ? preg_replace('/\.php$/i', '', basename($plugin_file)) : $dir;
            $slug = sanitize_key((string) $slug);
            if ('' !== $slug) {
                $slugs[$slug] = true;
            }
        };

        foreach ((array) get_option('active_plugins', array()) as $plugin_file) {
            $push($plugin_file);
        }

        if (is_multisite()) {
            foreach (array_keys((array) get_site_option('active_sitewide_plugins', array())) as $plugin_file) {
                $push($plugin_file);
            }
        }

        return array_keys($slugs);
    }

    private function runtime_js_scan_plugin_stage_owner_slugs($source, $message, $detail)
    {
        $slugs = array();
        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $owner = $this->runtime_js_scan_owner_group_from_source($candidate);
            if (empty($owner) || !isset($owner['kind']) || 'plugin' !== $owner['kind']) {
                continue;
            }
            $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
            if ('' !== $slug) {
                $slugs[$slug] = true;
            }
        }
        return array_keys($slugs);
    }

    private function runtime_js_scan_plugin_stage_has_any_owner($source, $message, $detail)
    {
        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            if (!empty($this->runtime_js_scan_owner_group_from_source($candidate))) {
                return true;
            }
        }
        return false;
    }

    private function runtime_js_scan_plugin_stage_roots($source, $message, $detail)
    {
        if (!function_exists('ultracache_plugin_root_dir')) {
            return array();
        }

        $active_slugs = array_fill_keys($this->runtime_js_scan_active_plugin_slugs(), true);
        if (empty($active_slugs)) {
            return array();
        }

        $owner_slugs = $this->runtime_js_scan_plugin_stage_owner_slugs($source, $message, $detail);
        $has_clear_owner = !empty($owner_slugs);
        if (!$has_clear_owner && $this->runtime_js_scan_plugin_stage_has_any_owner($source, $message, $detail)) {
            return array();
        }
        $scan_slugs = $has_clear_owner ? $owner_slugs : array_keys($active_slugs);
        $roots = array();
        $seen = array();
        $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
        if (!$filesystem || !is_object($filesystem)) {
            return array();
        }

        foreach ($scan_slugs as $slug) {
            $slug = sanitize_key((string) $slug);
            if ('' === $slug || empty($active_slugs[$slug])) {
                continue;
            }

            $dir = ultracache_plugin_root_dir($slug);
            if (!$filesystem->is_dir($dir)) {
                continue;
            }

            $key = strtolower($slug . '|' . $dir);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $roots[] = array(
                'stage'     => $has_clear_owner ? 'targeted active plugin' : 'active plugin scan',
                'slug'      => $slug,
                'dir'       => untrailingslashit($dir),
                'uri'       => function_exists('ultracache_plugin_root_uri') ? ultracache_plugin_root_uri($slug) : '',
                'max_files' => $has_clear_owner ? 120 : 35,
                'max_depth' => $has_clear_owner ? 6 : 4,
            );

            if (!$has_clear_owner && count($roots) >= 30) {
                break;
            }
        }

        return $roots;
    }

    private function runtime_js_scan_plugin_stage_files($root, $max_files = 60, $max_depth = 5)
    {
        $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
        $root = untrailingslashit($root);
        if ('' === $root) {
            return array();
        }

        $files = array();
        $queue = array(array($root, 0));
        $blocked_dirs = array('node_modules', 'vendor', '.git', 'cache', 'dist/cache', 'build/cache', 'tests', 'test');

        $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : null;
        if (!$filesystem || !is_object($filesystem)) {
            return array();
        }

        while (!empty($queue) && count($files) < (int) $max_files) {
            $current = array_shift($queue);
            $dir = isset($current[0]) ? (string) $current[0] : '';
            $depth = isset($current[1]) ? (int) $current[1] : 0;
            if ('' === $dir || $depth > (int) $max_depth) {
                continue;
            }

            $items = ultracache_safe_scandir($dir, 'runtime_js_plugin_stage_scan');
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $item = (string) $item;
                if ('.' === $item || '..' === $item || '' === $item) {
                    continue;
                }
                $path = function_exists('wp_normalize_path') ? wp_normalize_path(trailingslashit($dir) . $item) : str_replace('\\', '/', trailingslashit($dir) . $item);
                $lower_item = strtolower($item);
                if ($filesystem->is_dir($path)) {
                    if ($depth >= (int) $max_depth || in_array($lower_item, $blocked_dirs, true)) {
                        continue;
                    }
                    $queue[] = array($path, $depth + 1);
                    continue;
                }
                if (!$filesystem->is_file($path)) {
                    continue;
                }
                if (method_exists($filesystem, 'size') && (int) $filesystem->size($path) > 786432) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, array('js', 'mjs'), true)) {
                    continue;
                }
                $files[] = $path;
                if (count($files) >= (int) $max_files) {
                    break;
                }
            }
        }

        return $files;
    }

    private function runtime_js_scan_owner_from_script_source($source)
    {
        $owner = $this->runtime_js_scan_owner_group_from_source($source);
        if (empty($owner) || empty($owner['kind']) || empty($owner['slug']) || empty($owner['group'])) {
            return array();
        }
        return $owner;
    }

    private function runtime_js_scan_collect_direct_stack_sources($source, $message, $detail, array $scripts = array())
    {
        $sources = array();
        $seen = array();
        $push = function ($candidate) use (&$sources, &$seen, $scripts) {
            $candidate = $this->runtime_js_scan_clean_console_candidate((string) $candidate);
            if ('' === $candidate) {
                return;
            }
            if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($candidate)) {
                return;
            }
            $base = $this->runtime_js_scan_basename_from_source($candidate);
            if ('' === $base || !preg_match('/\.js$/i', $base)) {
                return;
            }

            // A generic basename is ambiguous only when it is all we know. If
            // the browser already supplied a plugin/theme-owned full source URL,
            // keep that exact identity. If it supplied only a basename/partial
            // source, accept it only when the current script inventory resolves
            // it to one unique plugin/theme-owned script.
            $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
            if (empty($owner) && !empty($scripts)) {
                $matches = $this->runtime_js_scan_find_scripts_by_source_hint($candidate, $scripts);
                if (1 === count($matches)) {
                    $matched_src = isset($matches[0]['src']) ? (string) $matches[0]['src'] : '';
                    if ('' !== $matched_src) {
                        $candidate = $matched_src;
                        $base = $this->runtime_js_scan_basename_from_source($candidate);
                        $owner = $this->runtime_js_scan_owner_from_script_source($candidate);
                    }
                }
            }

            if (empty($owner)) {
                return;
            }

            $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($candidate, 5);
            if ('' === $fragment) {
                return;
            }
            $key = strtolower($fragment . '|' . (string) $owner['kind'] . '|' . (string) $owner['slug']);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $sources[] = array(
                'source'   => $candidate,
                'fragment' => $fragment,
                'owner'    => $owner,
            );
        };

        foreach ($this->runtime_js_scan_source_candidates_from_error($source, $message, $detail) as $candidate) {
            $push($candidate);
        }
        foreach ($this->runtime_js_scan_console_sources_from_text((string) $source . "\n" . (string) $message . "\n" . (string) $detail) as $candidate) {
            $push($candidate);
        }

        return array_values($sources);
    }

    private function runtime_js_scan_owner_root_for_discovery(array $owner)
    {
        $kind = isset($owner['kind']) ? (string) $owner['kind'] : '';
        $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
        if ('' === $kind || '' === $slug) {
            return array();
        }
        if ('plugin' === $kind && function_exists('ultracache_plugin_root_dir')) {
            $dir = ultracache_plugin_root_dir($slug);
            if (is_dir($dir)) {
                return array('kind' => 'plugin', 'slug' => $slug, 'dir' => untrailingslashit($dir), 'uri' => function_exists('ultracache_plugin_root_uri') ? ultracache_plugin_root_uri($slug) : '');
            }
        }
        if ('theme' === $kind) {
            foreach ($this->runtime_js_scan_theme_stage_roots() as $root) {
                if (isset($root['slug']) && sanitize_key((string) $root['slug']) === $slug) {
                    return $root;
                }
            }
        }
        return array();
    }

    private function runtime_js_scan_find_symbol_definitions_for_owners($symbol, array $owners)
    {
        $definitions = array();
        $seen = array();
        $symbol = trim((string) $symbol);
        if ('' === $symbol || $this->runtime_js_scan_is_generic_token($symbol)) {
            return array();
        }

        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $root = $this->runtime_js_scan_owner_root_for_discovery($owner);
            if (empty($root) || empty($root['dir']) || empty($root['uri'])) {
                continue;
            }
            $kind = isset($root['kind']) ? (string) $root['kind'] : (isset($owner['kind']) ? (string) $owner['kind'] : '');
            $slug = isset($root['slug']) ? sanitize_key((string) $root['slug']) : (isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '');
            $root_dir = (string) $root['dir'];
            $root_uri = (string) $root['uri'];
            $files = ('plugin' === $kind) ? $this->runtime_js_scan_plugin_stage_files($root_dir, 140, 7) : $this->runtime_js_scan_theme_stage_files($root_dir, 120, 7);
            foreach ($files as $file) {
                $content = function_exists('ultracache_guarded_asset_file_get_contents') ? ultracache_guarded_asset_file_get_contents($file, 'js', 'runtime_js_discovery_symbol_search', true) : false;
                $filename_match = $this->runtime_js_scan_provider_identity_matches_symbol($file, $symbol);
                if (!$filename_match && (!is_string($content) || !$this->runtime_js_scan_file_defines_symbol($content, $symbol))) {
                    continue;
                }
                $relative = $this->runtime_js_scan_theme_stage_relative_path($file, $root_dir);
                if ('' === $relative) {
                    continue;
                }
                $url = esc_url_raw(trailingslashit($root_uri) . ltrim($relative, '/'));
                $fragment = $this->runtime_js_scan_targeted_source_fragment_from_source($url, 5);
                if ('' === $fragment) {
                    continue;
                }
                $key = strtolower($kind . '|' . $slug . '|' . $fragment . '|' . $symbol);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $definitions[] = array(
                    'symbol'   => $symbol,
                    'source'   => $url,
                    'fragment' => $fragment,
                    'owner'    => array(
                        'kind'  => $kind,
                        'slug'  => $slug,
                        'group' => isset($owner['group']) ? (string) $owner['group'] : ($slug . '/'),
                    ),
                );
                if (count($definitions) >= 12) {
                    return $definitions;
                }
            }
        }

        return $definitions;
    }

    private function runtime_js_scan_unique_direct_source_owners(array $direct_sources)
    {
        $owners = array();
        $seen = array();
        foreach ($direct_sources as $entry) {
            if (empty($entry['owner']) || !is_array($entry['owner'])) {
                continue;
            }
            $owner = $entry['owner'];
            $kind = isset($owner['kind']) ? (string) $owner['kind'] : '';
            $slug = isset($owner['slug']) ? sanitize_key((string) $owner['slug']) : '';
            if ('' === $kind || '' === $slug) {
                continue;
            }
            $key = strtolower($kind . '|' . $slug);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $owners[] = $owner;
        }
        return $owners;
    }

    private function normalize_runtime_js_scan_report_payload($payload)
    {
        $payload = is_array($payload) ? $payload : array();
        $scan_id = isset($payload['scanId']) ? sanitize_key((string) $payload['scanId']) : '';
        $errors = array();
        foreach ((array) ($payload['errors'] ?? array()) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $message = isset($error['message']) ? sanitize_text_field((string) $error['message']) : '';
            $detail = isset($error['detail']) ? sanitize_textarea_field(substr((string) $error['detail'], 0, 3000)) : '';
            $source = isset($error['source']) ? $this->runtime_js_scan_sanitize_source((string) $error['source']) : '';
            if ('' === $source) {
                $source = $this->runtime_js_scan_source_from_text($message . ' ' . $detail);
            }
            $errors[] = array(
                'kind'    => isset($error['kind']) ? sanitize_text_field((string) $error['kind']) : '',
                'message' => $message,
                'source'  => $source,
                'line'    => isset($error['line']) ? (int) $error['line'] : 0,
                'column'  => isset($error['column']) ? (int) $error['column'] : 0,
                'detail'  => $detail,
                'atMs'    => isset($error['atMs']) ? (int) $error['atMs'] : 0,
            );
            if (count($errors) >= 80) {
                break;
            }
        }

        return array(
            'scanId'    => $scan_id,
            'url'       => isset($payload['url']) ? $this->runtime_js_scan_sanitize_display_url((string) $payload['url']) : '',
            'completed' => !empty($payload['completed']),
            'errors'    => $errors,
            'scripts'   => isset($payload['scripts']) && is_array($payload['scripts']) ? $this->runtime_js_scan_normalize_script_inventory((array) $payload['scripts']) : array(),
            'scanContext' => isset($payload['scanContext']) && 'logged-in' === sanitize_key((string) $payload['scanContext']) ? 'logged-in' : 'anonymous',
            'userAgent' => isset($payload['userAgent']) ? sanitize_text_field((string) $payload['userAgent']) : '',
            'elapsedMs' => isset($payload['elapsedMs']) ? (int) $payload['elapsedMs'] : 0,
            'queueJobId' => isset($payload['queueJobId']) ? sanitize_text_field((string) $payload['queueJobId']) : '',
        );
    }

    private function runtime_js_scan_console_sources_from_text($text)
    {
        $text = (string) $text;
        $sources = array();
        $exact_url_bases = array();
        if ('' === trim($text)) {
            return array();
        }

        if (preg_match_all('#https?://[^\s\)\]\}"\'<>]+\.js(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+){0,2}#i', $text, $url_matches)) {
            foreach ((array) $url_matches[0] as $source) {
                $source = $this->runtime_js_scan_sanitize_source((string) $source);
                if ('' === $source || $this->runtime_js_scan_is_ultracache_runtime_helper_source($source)) {
                    continue;
                }

                // A browser stack URL is exact causal evidence. Preserve the
                // complete sanitized URL instead of reducing it to a fixed-depth
                // path fragment; deep plugin paths can otherwise lose their owner
                // identity (for example .../interactive-geo-maps/.../app.min.js).
                $clean = strtolower($this->runtime_js_scan_clean_console_candidate($source));
                if ('' !== $clean) {
                    $sources[$clean] = $source;
                }
                $base = strtolower($this->runtime_js_scan_basename_from_source($source));
                if ('' !== $base) {
                    $exact_url_bases[$base] = true;
                }
            }
        }


        if (preg_match_all('/\b([A-Za-z0-9_-]+-js-(?:after|before|extra|translations))(?::\d+(?::\d+)?)?/i', $text, $inline_matches)) {
            foreach ((array) $inline_matches[1] as $source) {
                $source = $this->runtime_js_scan_sanitize_source((string) $source);
                if ('' !== $source) {
                    $sources[strtolower($source)] = $source;
                }
            }
        }

        if (preg_match_all('/\b([A-Za-z0-9_.\/-]+\.(?:min\.)?js)(?:\?[^\s\)\]\}"\'<>]*)?(?::\d+(?::\d+)?)?/i', $text, $file_matches)) {
            foreach ((array) $file_matches[1] as $source) {
                $source = $this->runtime_js_scan_sanitize_source((string) $source);
                if ($this->runtime_js_scan_is_ultracache_runtime_helper_source($source)) {
                    continue;
                }
                $base = $this->runtime_js_scan_basename_from_source($source);
                if ('' !== $base && isset($exact_url_bases[strtolower($base)])) {
                    continue;
                }
                $path_fragment = $this->runtime_js_scan_path_fragment_from_source($source, 4);
                if ('' !== $path_fragment) {
                    $sources[strtolower($path_fragment)] = $path_fragment;
                } elseif ('' !== $source && !$this->runtime_js_scan_is_generic_script_basename($base)) {
                    $sources[strtolower($source)] = $source;
                }
            }
        }

        return array_slice(array_values($sources), 0, 12);
    }

    private function runtime_js_scan_console_input_max_bytes()
    {
        return 256 * 1024;
    }

    private function runtime_js_scan_console_input_max_lines()
    {
        return 2000;
    }

    private function runtime_js_scan_truncate_utf8_bytes($text, $max_bytes)
    {
        $text = (string) $text;
        $max_bytes = max(0, (int) $max_bytes);
        if (strlen($text) <= $max_bytes) {
            return $text;
        }
        if (0 === $max_bytes) {
            return '';
        }

        $truncated = substr($text, 0, $max_bytes);
        for ($attempt = 0; $attempt < 4 && '' !== $truncated; ++$attempt) {
            if (1 === preg_match('//u', $truncated)) {
                return $truncated;
            }
            $truncated = substr($truncated, 0, -1);
        }

        if (function_exists('wp_check_invalid_utf8')) {
            $checked = wp_check_invalid_utf8($truncated, true);
            return is_string($checked) ? $checked : '';
        }

        return 1 === preg_match('//u', $truncated) ? $truncated : '';
    }

    private function runtime_js_scan_prepare_console_input($text)
    {
        $raw = (string) $text;
        $max_bytes = $this->runtime_js_scan_console_input_max_bytes();
        $max_lines = $this->runtime_js_scan_console_input_max_lines();
        $original_byte_count = strlen($raw);
        $normalized = str_replace(array("\r\n", "\r"), "\n", $raw);
        $original_line_count = '' === $normalized ? 0 : substr_count($normalized, "\n") + 1;
        $truncation_reasons = array();

        if (strlen($normalized) > $max_bytes) {
            $normalized = $this->runtime_js_scan_truncate_utf8_bytes($normalized, $max_bytes);
            $last_newline = strrpos($normalized, "\n");
            if (false !== $last_newline && 0 < $last_newline) {
                $normalized = substr($normalized, 0, $last_newline);
            }
            $truncation_reasons[] = 'byte_limit';
        }

        if ('' !== $normalized) {
            $lines = explode("\n", $normalized, $max_lines + 1);
            if (count($lines) > $max_lines) {
                $normalized = implode("\n", array_slice($lines, 0, $max_lines));
                $truncation_reasons[] = 'line_limit';
            }
        }

        $normalized = sanitize_textarea_field($normalized);
        if (strlen($normalized) > $max_bytes) {
            $normalized = $this->runtime_js_scan_truncate_utf8_bytes($normalized, $max_bytes);
            $truncation_reasons[] = 'byte_limit';
        }

        $processed_line_count = '' === $normalized ? 0 : substr_count($normalized, "\n") + 1;

        return array(
            'text'                  => $normalized,
            'consoleInputTruncated' => !empty($truncation_reasons),
            'originalByteCount'     => $original_byte_count,
            'processedByteCount'    => strlen($normalized),
            'originalLineCount'     => $original_line_count,
            'processedLineCount'    => $processed_line_count,
            'truncationReasons'     => array_values(array_unique($truncation_reasons)),
            'maxBytes'              => $max_bytes,
            'maxLines'              => $max_lines,
        );
    }

    private function runtime_js_scan_console_input_metadata(array $prepared)
    {
        return array(
            'consoleInputTruncated'        => !empty($prepared['consoleInputTruncated']),
            'originalByteCount'            => isset($prepared['originalByteCount']) ? (int) $prepared['originalByteCount'] : 0,
            'processedByteCount'           => isset($prepared['processedByteCount']) ? (int) $prepared['processedByteCount'] : 0,
            'originalLineCount'            => isset($prepared['originalLineCount']) ? (int) $prepared['originalLineCount'] : 0,
            'processedLineCount'           => isset($prepared['processedLineCount']) ? (int) $prepared['processedLineCount'] : 0,
            'consoleInputTruncationReasons' => isset($prepared['truncationReasons']) && is_array($prepared['truncationReasons']) ? array_values($prepared['truncationReasons']) : array(),
            'consoleInputLimits'           => array(
                'maxBytes' => isset($prepared['maxBytes']) ? (int) $prepared['maxBytes'] : $this->runtime_js_scan_console_input_max_bytes(),
                'maxLines' => isset($prepared['maxLines']) ? (int) $prepared['maxLines'] : $this->runtime_js_scan_console_input_max_lines(),
            ),
        );
    }

    private function runtime_js_scan_console_text_to_errors($text)
    {
        $prepared = $this->runtime_js_scan_prepare_console_input($text);
        $text = (string) $prepared['text'];
        if ('' === trim($text)) {
            return array();
        }

        $lines = preg_split('/\n/', $text);
        $blocks = array();
        $current = array();
        $in_error = false;

        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = array();
                    $in_error = false;
                }
                continue;
            }

            if (preg_match('/^(?:Understand this (?:error|warning)|opt-in)$/i', $line) || preg_match('/JQMIGRATE:\s*Migrate is installed/i', $line)) {
                continue;
            }

            $starts_error = (bool) preg_match('/(?:Uncaught\s+)?(?:ReferenceError|TypeError|SyntaxError|RangeError|EvalError|URIError|Error):|jQuery\.Deferred exception|\bis not defined\b|\bis not a function\b|Cannot read properties|window\[[^\]]+\]\s+is\s+not\s+a\s+function/i', $line);
            $is_stack_line = (bool) (preg_match('/^at\s+/i', $line) || preg_match('/\.(?:m?js)(?:\?[^\s\)]*)?(?::\d+(?::\d+)?)?/i', $line));
            $starts_functional_failure = !$starts_error
                && method_exists($this, 'runtime_js_scan_is_functional_failure_console_message')
                && $this->runtime_js_scan_is_functional_failure_console_message($line);

            if ($starts_error || $starts_functional_failure) {
                if (!empty($current)) {
                    $blocks[] = $current;
                }
                $current = array($line);
                $in_error = true;
                continue;
            }

            if ($in_error && preg_match('/^(?:Error|Stack(?: trace)?)\:?$/i', $line)) {
                $current[] = $line;
                continue;
            }

            if ($in_error && $is_stack_line) {
                $current[] = $line;
                continue;
            }

            if (!empty($current)) {
                $blocks[] = $current;
                $current = array();
            }
            $in_error = false;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        if (empty($blocks)) {
            return array();
        }

        $errors = array();
        foreach ($blocks as $block) {
            $block_text = trim(implode("\n", (array) $block));
            if ('' === $block_text) {
                continue;
            }
            $message = '';
            foreach ((array) $block as $line) {
                $line = trim((string) $line);
                if ('' === $line || preg_match('/^at\s+/i', $line) || preg_match('/^\(?anonymous\)?\s*@/i', $line)) {
                    continue;
                }
                $message = $line;
                break;
            }
            if ('' === $message) {
                $message = substr($block_text, 0, 500);
            }
            $is_functional_failure = method_exists($this, 'runtime_js_scan_is_functional_failure_console_message')
                && $this->runtime_js_scan_is_functional_failure_console_message($message);
            if ($is_functional_failure && method_exists($this, 'runtime_js_scan_clean_functional_console_message')) {
                $message = $this->runtime_js_scan_clean_functional_console_message($message);
            }

            $sources = $this->runtime_js_scan_console_sources_from_text($block_text);
            if (empty($sources)) {
                $source = $this->runtime_js_scan_source_from_text($block_text);
                if ('' !== $source) {
                    $sources[] = $source;
                }
            }
            if (empty($sources)) {
                $sources[] = '';
            }

            foreach ($sources as $source) {
                $errors[] = array(
                    'kind' => $is_functional_failure ? 'console-paste-functional-failure' : 'console-paste',
                    'message' => sanitize_text_field(substr($message, 0, 500)),
                    'source'  => $this->runtime_js_scan_sanitize_source((string) $source),
                    'line'    => 0,
                    'column'  => 0,
                    'detail'  => sanitize_textarea_field(substr($block_text, 0, 4000)),
                    'atMs'    => 0,
                );
                if (count($errors) >= 80) {
                    break 2;
                }
            }
        }

        return $errors;
    }

    public function parse_runtime_js_scan_console_errors(WP_REST_Request $request)
    {
        $console_input = $this->runtime_js_scan_prepare_console_input($request->get_param('text'));
        $text = (string) $console_input['text'];
        if ('' === trim($text)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Missing console error text.', 'ultracache'),
            ), 400);
        }

        $url = (string) $request->get_param('url');
        $scripts = $this->runtime_js_scan_fetch_script_inventory_for_url($url);
        $errors = $this->runtime_js_scan_console_text_to_errors($text);
        $scan = $this->build_runtime_js_scan_suggestions($errors, $scripts);
        $response = array_merge(array(
            'available'            => true,
            'source'               => 'console-paste-runtime-engine',
            'runtimeErrorCount'    => count($errors),
            'resourceErrorCount'   => 0,
            'suggestionCount'      => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
            'missingCount'         => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
            'alreadyExcludedCount' => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
            'persistentListedFailureCount' => isset($scan['persistent_listed_failure_count']) ? (int) $scan['persistent_listed_failure_count'] : 0,
            'dependencyRiskCount' => isset($scan['dependency_risk_count']) ? (int) $scan['dependency_risk_count'] : 0,
            'suggestions'          => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
            'errors'               => array_slice($errors, 0, 40),
            'resourceErrors'       => array(),
            'scannedUrl'           => '' !== $url ? $this->runtime_js_scan_sanitize_display_url($url) : home_url('/'),
            'scannedLanguage'      => function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language('' !== $url ? $this->runtime_js_scan_sanitize_display_url($url) : home_url('/'))
                : '',
            'scriptInventoryCount' => count($scripts),
            'scriptInventorySummary' => $this->runtime_js_scan_inventory_summary($scripts),
            'scanContext'          => 'console-paste',
            'completed'            => true,
        ), $this->runtime_js_scan_console_input_metadata($console_input));

        return new WP_REST_Response(array('success' => true, 'consoleErrorScan' => $response), 200);
    }

    private function runtime_js_scan_report_from_job($job)
    {
        if (!is_array($job) || empty($job['result']) || !is_array($job['result'])) {
            return array();
        }
        $report = isset($job['result']['report']) && is_array($job['result']['report']) ? $job['result']['report'] : array();
        return $report;
    }

    private function merge_runtime_js_scan_errors(array $existing_errors, array $incoming_errors)
    {
        $merged_errors = array();
        foreach (array_merge($existing_errors, $incoming_errors) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $dedupe_key = md5((string) ($error['kind'] ?? '') . '|' . (string) ($error['message'] ?? '') . '|' . (string) ($error['source'] ?? '') . '|' . (string) ($error['line'] ?? ''));
            $merged_errors[$dedupe_key] = $error;
        }
        $errors = array_values($merged_errors);
        return count($errors) > 80 ? array_slice($errors, -80) : $errors;
    }

    private function merge_runtime_js_scan_report(array $existing, array $payload)
    {
        $errors = $this->merge_runtime_js_scan_errors(
            (array) ($existing['errors'] ?? array()),
            (array) ($payload['errors'] ?? array())
        );

        $report_url = !empty($payload['url'])
            ? (string) $payload['url']
            : $this->runtime_js_scan_sanitize_display_url((string) ($existing['url'] ?? ''));
        $report = array(
            'scanId'      => (string) $payload['scanId'],
            'url'         => $report_url,
            'language'    => function_exists('ultracache_multilingual_get_public_url_language')
                ? ultracache_multilingual_get_public_url_language($report_url)
                : '',
            'startedAt'   => isset($existing['startedAt']) ? (int) $existing['startedAt'] : time(),
            'updatedAt'   => time(),
            'completed'   => !empty($payload['completed']) || !empty($existing['completed']),
            'errors'      => $errors,
            'scripts'     => !empty($payload['scripts']) ? $payload['scripts'] : (array) ($existing['scripts'] ?? array()),
            'scanContext' => !empty($payload['scanContext']) ? (string) $payload['scanContext'] : (string) ($existing['scanContext'] ?? 'anonymous'),
            'errorCount'  => count($errors),
            'userAgent'   => !empty($payload['userAgent']) ? $payload['userAgent'] : (string) ($existing['userAgent'] ?? ''),
            'elapsedMs'   => max((int) ($existing['elapsedMs'] ?? 0), (int) $payload['elapsedMs']),
        );
        return $report;
    }

    public function save_runtime_js_scan_report(WP_REST_Request $request)
    {
        $payload = $this->normalize_runtime_js_scan_report_payload($request->get_json_params());
        $scan_id = (string) $payload['scanId'];
        if ('' === $scan_id) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Missing runtime JS scan id.', 'ultracache')), 400);
        }

        $lock_name = 'runtime-js-scan-merge:' . sha1($scan_id);
        $lock_token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('runtime_js_scan_', true);
        $lock_acquired = false;
        if (function_exists('ultracache_acquire_lock')) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if (ultracache_acquire_lock($lock_name, $lock_token, 15, array('scanId' => $scan_id))) {
                    $lock_acquired = true;
                    break;
                }
                if ($attempt < 4) {
                    usleep(75000);
                }
            }
        }
        if (!$lock_acquired) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Runtime JS scan report is being merged. Retry the request.', 'ultracache')), 409);
        }

        try {
            $existing_job = $this->runtime_js_diagnostic_queue_get_job_by_scan_id($scan_id);
            $existing_report = $this->runtime_js_scan_report_from_job($existing_job);
            $report = $this->merge_runtime_js_scan_report($existing_report, $payload);
            // Runtime Scan only persists raw browser evidence. Suggestion extraction is
            // intentionally handled later by the same Console Error Handler path used
            // by manually pasted console errors.
            $queue_result = array(
                'available' => true,
                'report' => $report,
                'runtimeErrorCount' => isset($report['errorCount']) ? (int) $report['errorCount'] : 0,
            );
            $queue_status = !empty($report['completed']) ? 'done' : 'running';
            $queue_progress = !empty($report['completed']) ? 100 : 60;
            $queue_job_id = sanitize_text_field((string) $payload['queueJobId']);
            $queue_job = '' !== $queue_job_id ? $this->runtime_js_diagnostic_queue_get_job($queue_job_id) : null;
            $target_job = null;

            if (is_array($queue_job)) {
                $target_job = $this->runtime_js_diagnostic_queue_update_job($queue_job_id, array(
                    'scan_id' => $scan_id,
                    'target_url' => (string) $report['url'],
                    'scan_context' => (string) $report['scanContext'],
                    'status' => $queue_status,
                    'message' => !empty($report['completed']) ? __('Browser runtime JS diagnostic queue completed.', 'ultracache') : __('Browser runtime JS diagnostic queue received an interim report.', 'ultracache'),
                    'progress_current' => $queue_progress,
                    'result' => $queue_result,
                    'finished_at' => !empty($report['completed']) ? time() : 0,
                ));
                if (is_array($existing_job) && !empty($existing_job['id']) && (string) $existing_job['id'] !== $queue_job_id && 0 === strpos((string) $existing_job['id'], 'jsdr_')) {
                    $this->runtime_js_diagnostic_queue_delete_job((string) $existing_job['id']);
                }
            } elseif (is_array($existing_job)) {
                $target_job = $this->runtime_js_diagnostic_queue_update_job((string) $existing_job['id'], array(
                    'scan_id' => $scan_id,
                    'target_url' => (string) $report['url'],
                    'scan_context' => (string) $report['scanContext'],
                    'status' => $queue_status,
                    'message' => !empty($report['completed']) ? __('Browser runtime JS diagnostic report completed.', 'ultracache') : __('Browser runtime JS diagnostic report updated.', 'ultracache'),
                    'progress_current' => $queue_progress,
                    'result' => $queue_result,
                    'finished_at' => !empty($report['completed']) ? time() : 0,
                ));
            } else {
                $target_job = $this->runtime_js_diagnostic_queue_insert_job(array(
                    'job_id' => $this->runtime_js_diagnostic_queue_report_job_id($scan_id),
                    'scan_id' => $scan_id,
                    'scan_type' => 'runtime',
                    'status' => $queue_status,
                    'target_url' => (string) $report['url'],
                    'scan_context' => (string) $report['scanContext'],
                    'message' => !empty($report['completed']) ? __('Browser runtime JS diagnostic report completed.', 'ultracache') : __('Browser runtime JS diagnostic report received.', 'ultracache'),
                    'progress_current' => $queue_progress,
                    'payload' => array('scanId' => $scan_id, 'url' => (string) $report['url'], 'scanContext' => (string) $report['scanContext']),
                    'result' => $queue_result,
                    'finished_at' => !empty($report['completed']) ? time() : 0,
                ));
            }

            if (!is_array($target_job)) {
                return new WP_REST_Response(array('success' => false, 'message' => __('Could not persist runtime JS diagnostic report.', 'ultracache')), 500);
            }

            $response = array('success' => true, 'runtimeJsScan' => $report);
            if ('' !== $queue_job_id && (string) ($target_job['id'] ?? '') === $queue_job_id) {
                $response['jsDiagnosticQueue'] = $target_job;
            }
            return new WP_REST_Response($response, 200);
        } finally {
            if (function_exists('ultracache_release_lock')) {
                ultracache_release_lock($lock_name, $lock_token);
            }
        }
    }

    private function get_runtime_js_scan_resource_errors(array $errors)
    {
        $resources = array();
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $kind = isset($error['kind']) ? strtolower((string) $error['kind']) : '';
            $message = isset($error['message']) ? strtolower((string) $error['message']) : '';
            $source = isset($error['source']) ? (string) $error['source'] : '';
            if ('resource-error' !== $kind && false === strpos($message, 'err_blocked_by_client') && false === strpos($message, 'failed to load resource')) {
                continue;
            }
            if ('' === $source) {
                continue;
            }
            $resources[] = array(
                'kind'    => sanitize_text_field((string) ($error['kind'] ?? 'resource-error')),
                'message' => sanitize_text_field((string) ($error['message'] ?? 'Resource failed to load')),
                'source'  => $this->runtime_js_scan_sanitize_source($source),
                'detail'  => isset($error['detail']) ? sanitize_text_field((string) $error['detail']) : '',
                'atMs'    => isset($error['atMs']) ? (int) $error['atMs'] : 0,
                'isJavaScript' => $this->runtime_js_scan_resource_is_javascript($source, (string) ($error['detail'] ?? '')),
                'likelyClientBlocked' => $this->runtime_js_scan_resource_likely_client_blocked($source, (string) ($error['message'] ?? ''), (string) ($error['detail'] ?? '')),
            );
            if (count($resources) >= 40) {
                break;
            }
        }
        return $resources;
    }

    private function runtime_js_scan_resource_is_javascript($source, $detail = '')
    {
        $detail = strtolower(trim((string) $detail));
        if (preg_match('/^(?:script|pendingscript)(?:#|\s|$)/i', $detail)) {
            return true;
        }
        $path = (string) wp_parse_url((string) $source, PHP_URL_PATH);
        return '' !== $path && (bool) preg_match('/\.m?js$/i', $path);
    }

    private function runtime_js_scan_resource_likely_client_blocked($source, $message = '', $detail = '')
    {
        $text = strtolower((string) $source . ' ' . (string) $message . ' ' . (string) $detail);
        if (false !== strpos($text, 'err_blocked_by_client') || false !== strpos($text, 'blocked by client')) {
            return true;
        }
        foreach (array('googletagmanager.com', 'google-analytics.com', 'woocommerce-google-analytics-integration', 'gtag/js', 'gtm.js', 'doubleclick.net', 'googleadservices.com', 'connect.facebook.net', 'fbevents.js', 'mailchimp-for-woocommerce', 'pixel-tracking', 'analytics.tiktok.com', 'clarity.ms', 'hotjar.com', 'taboola', 'outbrain', 'pixel', 'tracking', '/ads/', '/adservice') as $needle) {
            if (false !== strpos($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function summarize_runtime_js_scan_for_dashboard(array $report)
    {
        $all_errors = isset($report['errors']) && is_array($report['errors']) ? (array) $report['errors'] : array();
        $resource_errors = $this->get_runtime_js_scan_resource_errors($all_errors);
        $runtime_errors = array_values(array_filter($all_errors, static function ($error) {
            return is_array($error) && 'resource-error' !== strtolower((string) ($error['kind'] ?? ''));
        }));
        $scan = $this->build_runtime_js_scan_suggestions($runtime_errors, (array) ($report['scripts'] ?? array()));
        $javascript_resource_errors = array_values(array_filter($resource_errors, static function ($item) { return !empty($item['isJavaScript']); }));
        $blocked_javascript_resources = array_values(array_filter($javascript_resource_errors, static function ($item) { return !empty($item['likelyClientBlocked']); }));
        return array(
            'available'            => empty($javascript_resource_errors),
            'source'               => 'browser-runtime',
            'runtimeErrorCount'    => count($runtime_errors),
            'resourceErrorCount'   => count($resource_errors),
            'javascriptResourceErrorCount' => count($javascript_resource_errors),
            'blockedResourceCount' => count($blocked_javascript_resources),
            'scanContaminated'     => !empty($javascript_resource_errors),
            'failureReason'        => !empty($blocked_javascript_resources) ? 'client-script-blocked' : (!empty($javascript_resource_errors) ? 'javascript-resource-load-failed' : ''),
            'message'              => !empty($blocked_javascript_resources)
                ? __('Runtime Scan could not complete because JavaScript resources appear to be blocked by your browser or an extension. Please disable any ad blocker or content-blocking extension for this site and try again.', 'ultracache')
                : (!empty($javascript_resource_errors) ? __('Runtime Scan could not complete because one or more JavaScript resources failed to load. Fix the failed JavaScript resource, or disable any browser/content blocker affecting this site, and try again.', 'ultracache') : ''),
            'suggestionCount'      => isset($scan['suggestion_count']) ? (int) $scan['suggestion_count'] : 0,
            'missingCount'         => isset($scan['missing_count']) ? (int) $scan['missing_count'] : 0,
            'alreadyExcludedCount' => isset($scan['already_excluded_count']) ? (int) $scan['already_excluded_count'] : 0,
            'persistentListedFailureCount' => isset($scan['persistent_listed_failure_count']) ? (int) $scan['persistent_listed_failure_count'] : 0,
            'dependencyRiskCount' => isset($scan['dependency_risk_count']) ? (int) $scan['dependency_risk_count'] : 0,
            'suggestions'          => isset($scan['suggestions']) && is_array($scan['suggestions']) ? array_slice($scan['suggestions'], 0, 80) : array(),
            'errors'               => array_slice($runtime_errors, 0, 40),
            'resourceErrors'       => array_slice($resource_errors, 0, 40),
            'blockedResources'     => array_slice($resource_errors, 0, 40),
            'scannedUrl'           => isset($report['url']) ? (string) $report['url'] : '',
            'scannedLanguage'      => isset($report['language'])
                ? (string) $report['language']
                : (function_exists('ultracache_multilingual_get_public_url_language') ? ultracache_multilingual_get_public_url_language((string) ($report['url'] ?? '')) : ''),
            'scanContext'          => isset($report['scanContext']) && 'logged-in' === $report['scanContext'] ? 'logged-in' : 'anonymous',
            'completed'            => !empty($report['completed']),
        );
    }

    public function get_runtime_js_scan_report(WP_REST_Request $request)
    {
        $scan_id = sanitize_key((string) $request->get_param('scanId'));
        if ('' === $scan_id) {
            return new WP_REST_Response(array('success' => false, 'message' => __('Missing runtime JS scan id.', 'ultracache')), 400);
        }

        $job = $this->runtime_js_diagnostic_queue_get_job_by_scan_id($scan_id);
        $report = $this->runtime_js_scan_report_from_job($job);
        if (empty($report)) {
            return new WP_REST_Response(array(
                'success' => true,
                'runtimeJsScan' => array(
                    'scanId' => $scan_id,
                    'available' => false,
                    'completed' => false,
                    'errorCount' => 0,
                    'errors' => array(),
                    'scripts' => array(),
                ),
            ), 200);
        }
        return new WP_REST_Response(array('success' => true, 'runtimeJsScan' => $report), 200);
    }

}
