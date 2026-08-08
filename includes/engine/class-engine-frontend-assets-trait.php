<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Frontend_Assets_Trait
{
    /**
     * Normalize a plugin-owned frontend JavaScript asset path.
     *
     * The staged reviewer-cleanup migration moves UltraCache runtime helpers from
     * raw inline script output into files under assets/js/ and loads them through
     * WordPress enqueue APIs. This helper keeps those handles/URLs centralized.
     *
     * @param string $relative_path Relative path below assets/js/.
     * @return string
     */
    protected function ultracache_normalize_frontend_js_asset_path($relative_path)
    {
        $relative_path = str_replace('\\', '/', (string) $relative_path);
        $relative_path = ltrim($relative_path, '/');
        $parts = array();

        foreach (explode('/', $relative_path) as $part) {
            $part = trim((string) $part);
            if ('' === $part || '.' === $part || '..' === $part) {
                continue;
            }
            $parts[] = sanitize_file_name($part);
        }

        return implode('/', $parts);
    }

    /**
     * Return the URL for a plugin-owned frontend JavaScript helper asset.
     *
     * @param string $relative_path Relative path below assets/js/.
     * @return string
     */
    protected function ultracache_frontend_js_asset_url($relative_path)
    {
        $relative_path = $this->ultracache_normalize_frontend_js_asset_path($relative_path);
        if ('' === $relative_path) {
            return '';
        }

        return ultracache_plugin_url('assets/js/' . $relative_path);
    }

    /**
     * Register a plugin-owned frontend JavaScript helper through WordPress APIs.
     *
     * @param string        $handle        Script handle.
     * @param string        $relative_path Relative path below assets/js/.
     * @param array<string> $dependencies  Script dependencies.
     * @param bool          $in_footer     Whether to load in the footer.
     * @return bool
     */
    protected function ultracache_register_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        $handle = sanitize_key((string) $handle);
        $src = $this->ultracache_frontend_js_asset_url($relative_path);

        if ('' === $handle || '' === $src) {
            return false;
        }

        wp_register_script(
            $handle,
            $src,
            is_array($dependencies) ? array_values(array_filter(array_map('sanitize_key', $dependencies))) : array(),
            ULTRACACHE_VERSION,
            array('in_footer' => (bool) $in_footer)
        );

        return true;
    }

    /**
     * Enqueue a plugin-owned frontend JavaScript helper through WordPress APIs.
     *
     * @param string        $handle        Script handle.
     * @param string        $relative_path Relative path below assets/js/.
     * @param array<string> $dependencies  Script dependencies.
     * @param bool          $in_footer     Whether to load in the footer.
     * @return bool
     */
    protected function ultracache_enqueue_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        if (!$this->ultracache_register_frontend_js_helper($handle, $relative_path, $dependencies, $in_footer)) {
            return false;
        }

        wp_enqueue_script(sanitize_key((string) $handle));

        return true;
    }

    /**
     * Attach sanitized configuration data before a registered helper script.
     *
     * @param string $handle      Script handle.
     * @param string $global_name JavaScript global object name.
     * @param array  $data        Configuration data.
     * @return bool
     */
    protected function ultracache_add_frontend_js_helper_data($handle, $global_name, array $data)
    {
        $handle = sanitize_key((string) $handle);
        $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) $global_name);

        if ('' === $handle || '' === $global_name || !wp_script_is($handle, 'registered')) {
            return false;
        }

        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json) || '' === $json) {
            return false;
        }

        wp_add_inline_script(
            $handle,
            'window.' . $global_name . ' = ' . $json . ';',
            'before'
        );

        return true;
    }


    /**
     * Enqueue the external async CSS activation runtime when this request can emit
     * UltraCache-managed non-blocking stylesheet links.
     *
     * @return void
     */
    public function enqueue_async_css_runtime_helper()
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        $critical_chain_delay_enabled = !empty($settings['critical_request_chain_relief'])
            && !empty($settings['critical_request_chain_delay_list'])
            && is_array($settings['critical_request_chain_delay_list']);

        $runtime_required = !empty($settings['async_css'])
            || !empty($settings['async_external_css'])
            || !empty($settings['aggressive_async_css'])
            || !empty($settings['font_mix_css_bundle_async'])
            || !empty($settings['delay_icon_fonts'])
            || $critical_chain_delay_enabled;

        if (!$runtime_required) {
            return;
        }

        $handle = 'ultracache-async-css-runtime';
        $this->ultracache_enqueue_frontend_js_helper($handle, 'async-css-runtime.js', array(), false);
    }

    /**
     * Enqueue the runtime JavaScript scan collector through WordPress-native script APIs.
     *
     * The collector is active only for verified runtime-scan requests. It must be
     * printed in the document head before other optimized scripts so it can catch
     * early errors, but it is still loaded through wp_enqueue_scripts,
     * wp_register_script(), wp_enqueue_script(), and wp_add_inline_script().
     *
     * @return void
     */
    /**
     * Enqueue the strict viewport-based third-party iframe activation runtime.
     *
     * @return void
     */
    public function enqueue_lazy_third_party_iframe_runtime_helper()
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['lazy_load_third_party_iframes']) || $this->should_skip_lazy_third_party_iframes_for_request()) {
            return;
        }

        $this->ultracache_enqueue_frontend_js_helper(
            'ultracache-lazy-third-party-iframes',
            'lazy-third-party-iframes.js',
            array(),
            false
        );
    }


    public function enqueue_runtime_js_scan_collector()
    {
        if (is_admin()) {
            return;
        }

        $data = $this->get_runtime_js_scan_request_data();
        if (false === $data || !is_array($data)) {
            return;
        }

        $scan_id = isset($data['scan_id']) ? (string) $data['scan_id'] : '';
        $endpoint = isset($data['endpoint']) ? (string) $data['endpoint'] : '';
        $rest_nonce = isset($data['rest_nonce']) ? (string) $data['rest_nonce'] : '';
        $scan_context = isset($data['scan_context']) && 'logged-in' === $data['scan_context'] ? 'logged-in' : 'anonymous';

        if ('' === $scan_id || '' === $endpoint || '' === $rest_nonce) {
            return;
        }

        $handle = 'ultracache-runtime-js-scan-collector';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'runtime-js-scan-collector.js', array(), false)) {
            return;
        }

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheRuntimeJsScanConfig', array(
            'scanId'      => $scan_id,
            'endpoint'    => $endpoint,
            'restNonce'   => $rest_nonce,
            'scanContext' => $scan_context,
        ));
    }

    /**
     * Enqueue the MailerLite lazy nonce helper with WordPress-native script APIs.
     *
     * This helper intentionally uses wp_enqueue_scripts + wp_register_script(),
     * wp_enqueue_script(), and wp_add_inline_script() only. It does not inject
     * script tags through the HTML output rewrite pipeline.
     *
     * @return void
     */
    public function enqueue_mailerlite_lazy_nonce_helper()
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['lazy_mailerlite_nonce'])) {
            return;
        }

        $handle = 'ultracache-mailerlite-lazy-nonce';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'mailerlite-lazy-nonce.js', array(), false)) {
            return;
        }

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheMailerLiteLazyNonceConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }

    private function should_skip_woocommerce_cart_fragments_empty_cart_control()
    {
        if ((function_exists('is_user_logged_in') && is_user_logged_in()) || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        foreach (array('is_cart', 'is_checkout', 'is_account_page') as $conditional) {
            if (function_exists($conditional) && call_user_func($conditional)) {
                return true;
            }
        }

        $cookie_names = array();
        if (isset($_COOKIE) && is_array($_COOKIE)) {
            $cookie_names = array_keys(wp_unslash($_COOKIE));
        }

        foreach ($cookie_names as $cookie_name) {
            if ($this->cookie_name_matches_any_pattern($cookie_name, array('woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_'))) {
                return true;
            }
        }

        return false;
    }

    private function should_skip_woocommerce_cart_fragments_delay()
    {
        return $this->should_skip_woocommerce_cart_fragments_empty_cart_control();
    }

    public function filter_woocommerce_cart_fragments_script_data($script_data, $handle)
    {
        if ('wc-cart-fragments' !== (string) $handle) {
            return $script_data;
        }

        if (method_exists($this, 'is_woocommerce_esi_mini_cart_rendered_for_request')
            && $this->is_woocommerce_esi_mini_cart_rendered_for_request()) {
            return $script_data;
        }

        if (is_admin()) {
            return $script_data;
        }

        $settings = $this->get_settings();
        if (empty($settings['woocommerce_cart_fragments_suppress_empty'])) {
            return $script_data;
        }

        if ($this->should_skip_woocommerce_cart_fragments_empty_cart_control()) {
            return $script_data;
        }

        return null;
    }

    private function get_woocommerce_cart_fragments_delay_ms(array $settings)
    {
        $timing = isset($settings['woocommerce_cart_fragments_delay_timing']) ? strtolower(trim((string) $settings['woocommerce_cart_fragments_delay_timing'])) : 'delayed-js';
        if ('delayed-js' === $timing) {
            $seconds = isset($settings['delayed_local_js_auto_start_seconds']) ? (float) $settings['delayed_local_js_auto_start_seconds'] : 0.05;
        } else {
            $seconds = (float) $timing;
        }

        $seconds = max(0.05, min(5.0, $seconds));
        return (int) round(1000 * $seconds);
    }

    private function get_delayed_js_autostart_event_names(array $settings)
    {
        $events = array();
        if (!empty($settings['delayed_js_autostart_mousemove'])) {
            $events[] = 'mousemove';
        }
        if (!empty($settings['delayed_js_autostart_scroll'])) {
            $events[] = 'scroll';
        }
        if (!empty($settings['delayed_js_autostart_click'])) {
            $events[] = 'click';
        }
        if (!empty($settings['delayed_js_autostart_touch_pointer'])) {
            $events[] = 'touchstart';
            $events[] = 'pointerdown';
        }
        if (!empty($settings['delayed_js_autostart_keyboard'])) {
            $events[] = 'keydown';
        }

        return array_values(array_unique(array_map('sanitize_key', $events)));
    }

    public function enqueue_woocommerce_cart_fragments_delay_helper()
    {
        if (is_admin()) {
            return;
        }

        if (!class_exists('WooCommerce') && !defined('WC_VERSION') && !function_exists('WC')) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['woocommerce_cart_fragments_delay'])
            || $this->should_skip_woocommerce_cart_fragments_delay()
            || (method_exists($this, 'is_woocommerce_esi_mini_cart_rendered_for_request')
                && $this->is_woocommerce_esi_mini_cart_rendered_for_request())) {
            return;
        }

        $handle = 'ultracache-woocommerce-cart-fragments-delay';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'woocommerce-cart-fragments-delay.js', array('jquery'), false)) {
            return;
        }

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheWooCartFragmentsDelayConfig', array(
            'autoEvents'      => $this->get_delayed_js_autostart_event_names($settings),
            'autoAfterLoad'   => !empty($settings['delayed_js_autostart_after_load']),
            'autoDelayMs'     => $this->get_woocommerce_cart_fragments_delay_ms($settings),
            'skipCartCookies' => true,
        ));
    }

}
