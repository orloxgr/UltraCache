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
    protected function ucwp_normalize_frontend_js_asset_path($relative_path)
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
    protected function ucwp_frontend_js_asset_url($relative_path)
    {
        $relative_path = $this->ucwp_normalize_frontend_js_asset_path($relative_path);
        if ('' === $relative_path) {
            return '';
        }

        return plugins_url('assets/js/' . $relative_path, UCWP_FILE);
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
    protected function ucwp_register_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        $handle = sanitize_key((string) $handle);
        $src = $this->ucwp_frontend_js_asset_url($relative_path);

        if ('' === $handle || '' === $src) {
            return false;
        }

        wp_register_script(
            $handle,
            $src,
            is_array($dependencies) ? array_values(array_filter(array_map('sanitize_key', $dependencies))) : array(),
            UCWP_VERSION,
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
    protected function ucwp_enqueue_frontend_js_helper($handle, $relative_path, $dependencies = array(), $in_footer = false)
    {
        if (!$this->ucwp_register_frontend_js_helper($handle, $relative_path, $dependencies, $in_footer)) {
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
    protected function ucwp_add_frontend_js_helper_data($handle, $global_name, array $data)
    {
        $handle = sanitize_key((string) $handle);
        $global_name = preg_replace('/[^A-Za-z0-9_$]/', '', (string) $global_name);

        if ('' === $handle || '' === $global_name || !wp_script_is($handle, 'registered')) {
            return false;
        }

        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * Enqueue the runtime JavaScript scan collector through WordPress-native script APIs.
     *
     * The collector is active only for verified runtime-scan requests. It must be
     * printed in the document head before other optimized scripts so it can catch
     * early errors, but it is still loaded through wp_enqueue_scripts,
     * wp_register_script(), wp_enqueue_script(), and wp_add_inline_script().
     *
     * @return void
     */
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

        $handle = 'ucwp-runtime-js-scan-collector';
        if (!$this->ucwp_enqueue_frontend_js_helper($handle, 'runtime-js-scan-collector.js', array(), false)) {
            return;
        }

        $this->ucwp_add_frontend_js_helper_data($handle, 'ucwpRuntimeJsScanConfig', array(
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

        $handle = 'ucwp-mailerlite-lazy-nonce';
        if (!$this->ucwp_enqueue_frontend_js_helper($handle, 'mailerlite-lazy-nonce.js', array(), false)) {
            return;
        }

        $this->ucwp_add_frontend_js_helper_data($handle, 'ucwpMailerLiteLazyNonceConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }

}
