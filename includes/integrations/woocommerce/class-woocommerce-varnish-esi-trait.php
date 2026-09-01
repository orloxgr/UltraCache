<?php
/**
 * WooCommerce classic mini-cart integration for private Varnish ESI fragments.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_WooCommerce_Varnish_ESI_Trait
{
    /** @var bool */
    private $woocommerce_varnish_esi_mini_cart_rendered = false;

    /** @var bool */
    private $woocommerce_varnish_esi_fragment_registered = false;

    /** @var array<int,array<string,mixed>> */
    private $woocommerce_varnish_esi_template_stack = array();

    /** @var array<int,string> */
    private $woocommerce_varnish_esi_auto_marker_stack = array();

    /** @var array<string,array<string,string>> */
    private $woocommerce_varnish_esi_auto_marker_contexts = array();

    /** @var array<string,string> */
    private $woocommerce_varnish_esi_secondary_fragment_snapshot = array();

    /** @var bool */
    private $woocommerce_varnish_esi_secondary_fragments_collected = false;

    /** @var int */
    private $woocommerce_varnish_esi_fragment_render_depth = 0;

    /** @var bool */
    private $woocommerce_varnish_esi_runtime_enabled = false;

    /**
     * Register WooCommerce ESI hooks.
     *
     * @return void
     */
    private function register_woocommerce_varnish_esi_hooks()
    {
        add_action('init', array($this, 'register_woocommerce_varnish_esi_mini_cart_fragment'), 20);
        add_filter('ultracache_esi_private_transport_cookie_names', array($this, 'filter_woocommerce_varnish_esi_transport_cookie_names'), 20);
        add_filter('ultracache_esi_private_transport_cookie_prefixes', array($this, 'filter_woocommerce_varnish_esi_transport_cookie_prefixes'), 20);
        add_filter('ultracache_esi_private_definition_transport_declared', array($this, 'filter_woocommerce_varnish_esi_transport_declaration'), 20, 2);
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'filter_woocommerce_varnish_esi_mini_cart_fragments'), PHP_INT_MAX);
        add_action('woocommerce_before_template_part', array($this, 'track_woocommerce_varnish_esi_template_start'), PHP_INT_MIN, 4);
        add_action('woocommerce_after_template_part', array($this, 'track_woocommerce_varnish_esi_template_end'), PHP_INT_MAX, 4);
        add_action('woocommerce_before_mini_cart', array($this, 'mark_woocommerce_varnish_esi_auto_mini_cart_start'), PHP_INT_MIN);
        add_action('woocommerce_after_mini_cart', array($this, 'mark_woocommerce_varnish_esi_auto_mini_cart_end'), PHP_INT_MAX);
        add_action('wp_footer', array($this, 'collect_woocommerce_varnish_esi_secondary_fragments'), 0);
        add_action('wp_footer', array($this, 'ensure_woocommerce_varnish_esi_mini_cart_runtime'), 1);
        add_action('wp_print_footer_scripts', array($this, 'collect_woocommerce_varnish_esi_secondary_fragments'), PHP_INT_MIN);
        add_action('wp_print_footer_scripts', array($this, 'ensure_woocommerce_varnish_esi_mini_cart_runtime'), 0);
        add_action('wp_enqueue_scripts', array($this, 'reserve_woocommerce_varnish_esi_browser_opt_in_runtime_module'), -992);
    }

    /**
     * Whether WooCommerce classic cart APIs are available.
     *
     * @return bool
     */
    private function is_woocommerce_varnish_esi_adapter_available()
    {
        return function_exists('WC')
            && function_exists('woocommerce_mini_cart')
            && (class_exists('WooCommerce') || defined('WC_VERSION'));
    }

    /**
     * Return the stable fragment ID.
     *
     * @return string
     */
    private function get_woocommerce_varnish_esi_mini_cart_fragment_id()
    {
        return 'woocommerce-mini-cart';
    }

    /**
     * Register the private classic mini-cart fragment and shortcode.
     *
     * @return void
     */
    public function register_woocommerce_varnish_esi_mini_cart_fragment()
    {
        if (!$this->is_woocommerce_varnish_esi_adapter_available()) {
            return;
        }

        add_shortcode('ultracache_esi_mini_cart', array($this, 'shortcode_woocommerce_varnish_esi_mini_cart'));
        add_shortcode('ultracache_varnish_esi_mini_cart', array($this, 'shortcode_woocommerce_varnish_esi_mini_cart'));

        if ($this->woocommerce_varnish_esi_fragment_registered || !function_exists('ultracache_register_varnish_esi_fragment')) {
            return;
        }

        $result = ultracache_register_varnish_esi_fragment(
            $this->get_woocommerce_varnish_esi_mini_cart_fragment_id(),
            array(
                'scope'                   => 'private',
                'cookie_names'            => array(
                    'woocommerce_items_in_cart',
                    'woocommerce_cart_hash',
                ),
                'cookie_prefixes'         => array('wp_woocommerce_session_'),
                'renderer'                => array($this, 'render_woocommerce_varnish_esi_mini_cart_fragment'),
                'fallback'                => $this->get_woocommerce_varnish_esi_mini_cart_fallback(),
                'context_keys'            => array('mode', 'template', 'path', 'args', 'selector'),
                'max_context_bytes'       => 1024,
                'max_context_value_bytes' => 512,
                'max_output_bytes'        => 524288,
                'max_cookie_header_bytes' => 12288,
                'needs_main_query'         => false,
            )
        );

        if (true === $result) {
            $this->woocommerce_varnish_esi_fragment_registered = true;
            return;
        }

        if (is_wp_error($result) && 'ultracache_esi_fragment_already_registered' === $result->get_error_code()) {
            $this->woocommerce_varnish_esi_fragment_registered = true;
            return;
        }

        if (is_wp_error($result)) {
            do_action('ultracache_woocommerce_esi_registration_error', $result);
        }
    }

    /**
     * Track the active WooCommerce template context using WooCommerce's public
     * template hooks. The classic mini-cart semantic hooks fire while this
     * frame is active, which lets UltraCache preserve the actual logical
     * template identifier and arguments without theme or filesystem guesses.
     *
     * @param string $template_name Logical WooCommerce template name.
     * @param string $template_path Template override path.
     * @param string $located       Resolved template filesystem path.
     * @param array  $args          Template arguments.
     * @return void
     */
    public function track_woocommerce_varnish_esi_template_start($template_name, $template_path, $located, $args)
    {
        $this->woocommerce_varnish_esi_template_stack[] = array(
            'template_name' => is_string($template_name) ? $template_name : '',
            'template_path' => is_string($template_path) ? $template_path : '',
            'located'       => is_string($located) ? $located : '',
            'args'          => is_array($args) ? $args : array(),
        );
    }

    /**
     * Close the most recent WooCommerce template frame.
     *
     * @param string $template_name Logical WooCommerce template name.
     * @param string $template_path Template override path.
     * @param string $located       Resolved template filesystem path.
     * @param array  $args          Template arguments.
     * @return void
     */
    public function track_woocommerce_varnish_esi_template_end($template_name, $template_path, $located, $args)
    {
        unset($template_name, $template_path, $located, $args);
        if (!empty($this->woocommerce_varnish_esi_template_stack)) {
            array_pop($this->woocommerce_varnish_esi_template_stack);
        }
    }

    /**
     * Whether automatic classic mini-cart ESI markers may be emitted for this
     * frontend template render.
     *
     * @return bool
     */
    private function should_mark_woocommerce_varnish_esi_auto_mini_cart()
    {
        if ($this->woocommerce_varnish_esi_fragment_render_depth > 0 || !$this->is_woocommerce_varnish_esi_adapter_available()) {
            return false;
        }
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (!function_exists('ultracache_varnish_esi_is_enabled') || !ultracache_varnish_esi_is_enabled()) {
            return false;
        }
        if (!$this->is_woocommerce_varnish_esi_browser_opt_in_effective()) {
            return false;
        }
        return true;
    }

    /**
     * Encode bounded scalar WooCommerce template arguments for the signed ESI
     * context. Unsupported complex arguments make this render ineligible for
     * automatic ESI rather than changing the theme's output contract.
     *
     * @param array $args Template arguments.
     * @return string
     */
    private function encode_woocommerce_varnish_esi_auto_template_args(array $args)
    {
        $normalized = array();
        foreach ($args as $key => $value) {
            $raw_key = (string) $key;
            $key = sanitize_key($raw_key);
            if ('' === $key || $key !== $raw_key || count($normalized) >= 16) {
                return '';
            }
            if (is_bool($value) || is_int($value)) {
                $normalized[$key] = $value;
                continue;
            }
            if (is_float($value) && is_finite($value)) {
                $normalized[$key] = (string) $value;
                continue;
            }
            if (is_string($value)) {
                $value = sanitize_text_field($value);
                if (strlen($value) > 200) {
                    return '';
                }
                $normalized[$key] = $value;
                continue;
            }
            if (null === $value) {
                $normalized[$key] = '';
                continue;
            }
            return '';
        }

        $json = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return strlen($encoded) <= 512 ? $encoded : '';
    }

    /**
     * Decode previously signed/bounded WooCommerce template arguments.
     *
     * @param string $encoded Encoded arguments.
     * @return array
     */
    private function decode_woocommerce_varnish_esi_auto_template_args($encoded)
    {
        $encoded = trim((string) $encoded);
        if ('' === $encoded || strlen($encoded) > 512 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return array();
        }
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return array();
        }

        $args = array();
        foreach ($decoded as $key => $value) {
            $raw_key = (string) $key;
            $key = sanitize_key($raw_key);
            if ('' === $key || $key !== $raw_key || count($args) >= 16) {
                return array();
            }
            if (is_bool($value) || is_int($value) || is_string($value)) {
                $args[$key] = $value;
                continue;
            }
            return array();
        }
        return $args;
    }

    /**
     * Start a trusted marker around a classic mini-cart rendered through the
     * WooCommerce semantic mini-cart contract.
     *
     * @return void
     */
    public function mark_woocommerce_varnish_esi_auto_mini_cart_start()
    {
        if (!$this->should_mark_woocommerce_varnish_esi_auto_mini_cart()) {
            return;
        }

        $this->register_woocommerce_varnish_esi_mini_cart_fragment();
        if (!$this->woocommerce_varnish_esi_fragment_registered || !function_exists('wp_generate_uuid4')) {
            return;
        }

        /*
         * Prefer the exact WooCommerce template contract when a normal
         * wc_get_template() frame is available. Some themes render the same
         * public mini-cart semantic hooks outside that wrapper; those renders
         * deliberately fall back to the stable woocommerce_mini_cart() API
         * instead of guessing a theme, filename, or filesystem location.
         */
        $context = array('mode' => 'classic');
        if (!empty($this->woocommerce_varnish_esi_template_stack)) {
            $index = count($this->woocommerce_varnish_esi_template_stack) - 1;
            $frame = $this->woocommerce_varnish_esi_template_stack[$index] ?? array();
            $template_name = sanitize_text_field((string) ($frame['template_name'] ?? ''));
            $template_path = sanitize_text_field((string) ($frame['template_path'] ?? ''));
            $encoded_args = $this->encode_woocommerce_varnish_esi_auto_template_args((array) ($frame['args'] ?? array()));

            $valid_template = '' !== $template_name
                && strlen($template_name) <= 200
                && false === strpos($template_name, '..')
                && false === strpos($template_name, '\\')
                && '/' !== substr($template_name, 0, 1);
            $valid_path = strlen($template_path) <= 200
                && false === strpos($template_path, '..')
                && false === strpos($template_path, '\\')
                && ('/' !== substr($template_path, 0, 1) || '' === $template_path);

            if ($valid_template && $valid_path && '' !== $encoded_args) {
                $context = array(
                    'mode'     => 'auto',
                    'template' => $template_name,
                    'path'     => $template_path,
                    'args'     => $encoded_args,
                );
            }
        }

        $marker_token = strtolower(str_replace('-', '', wp_generate_uuid4()));
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $marker_token)) {
            return;
        }

        $this->woocommerce_varnish_esi_auto_marker_stack[] = $marker_token;
        $this->woocommerce_varnish_esi_auto_marker_contexts[$marker_token] = $context;
        $this->woocommerce_varnish_esi_mini_cart_rendered = true;

        echo '<!--ultracache-woocommerce-mini-cart-auto-start:v2:' . esc_html($marker_token) . '-->'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted non-visible marker consumed by the WordPress template enhancement buffer.
    }

    /**
     * End the most recent trusted automatic classic mini-cart marker.
     *
     * @return void
     */
    public function mark_woocommerce_varnish_esi_auto_mini_cart_end()
    {
        if (empty($this->woocommerce_varnish_esi_auto_marker_stack)) {
            return;
        }
        $marker_token = (string) array_pop($this->woocommerce_varnish_esi_auto_marker_stack);
        echo '<!--ultracache-woocommerce-mini-cart-auto-end:v2:' . esc_html($marker_token) . '-->'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted non-visible marker consumed by the WordPress template enhancement buffer.
    }

    /**
     * Reserve the ESI browser opt-in factory before the three-lane bundles are finalized.
     * The module is activated only if finalized HTML actually contains a trusted
     * ESI mini-cart marker, preserving the existing semantic gate without a late
     * fourth JavaScript request.
     *
     * @return void
     */
    public function reserve_woocommerce_varnish_esi_browser_opt_in_runtime_module()
    {
        if (!$this->is_woocommerce_varnish_esi_browser_opt_in_effective()
            || !method_exists($this, 'ultracache_reserve_frontend_runtime_module')) {
            return;
        }

        $this->ultracache_reserve_frontend_runtime_module('ultracache-woocommerce-esi-optin');
    }

    /**
     * Convert trusted WooCommerce semantic markers into the existing UltraCache
     * Varnish ESI placeholder format. The captured theme HTML remains the exact
     * inline fallback; only the private subrequest is rendered dynamically.
     *
     * @param string $html Full WordPress template output.
     * @return string
     */
    private function apply_woocommerce_varnish_esi_auto_mini_cart_placeholders($html)
    {
        if (!is_string($html) || '' === $html) {
            return $html;
        }

        $html = $this->apply_woocommerce_varnish_esi_canonical_mini_cart_placeholder($html);
        $html = $this->apply_woocommerce_varnish_esi_semantic_mini_cart_placeholders($html);

        if (!$this->woocommerce_varnish_esi_mini_cart_rendered) {
            return $html;
        }

        $html = $this->apply_woocommerce_varnish_esi_secondary_fragment_placeholders($html);
        return $this->inject_woocommerce_varnish_esi_browser_opt_in_helper($html);
    }

    /**
     * Whether finalized HTML contains a WooCommerce mini-cart marker understood
     * by the browser opt-in helper. Keep this exact and aligned with
     * assets/js/woocommerce-esi-optin.js; do not infer theme-specific markup.
     *
     * @param string $html Full template output.
     * @return bool
     */
    private function html_has_woocommerce_varnish_esi_browser_opt_in_marker($html)
    {
        if (!is_string($html) || '' === $html) {
            return false;
        }

        return false !== stripos($html, 'data-ultracache-esi-adapter="woocommerce-classic-mini-cart"')
            || false !== stripos($html, "data-ultracache-esi-adapter='woocommerce-classic-mini-cart'")
            || false !== stripos($html, 'data-ultracache-esi-auto="woocommerce-mini-cart"')
            || false !== stripos($html, "data-ultracache-esi-auto='woocommerce-mini-cart'");
    }

    /**
     * Inject the tiny browser opt-in helper only when finalized HTML actually
     * contains a WooCommerce ESI mini-cart marker. This runs after the normal
     * enqueue/final-JS rewrite phase because canonical WooCommerce placeholders
     * are discovered only in the finalized template buffer.
     *
     * @param string $html Full template output.
     * @return string
     */
    private function inject_woocommerce_varnish_esi_browser_opt_in_helper($html)
    {
        if (
            !is_string($html)
            || '' === $html
            || !$this->is_woocommerce_varnish_esi_browser_opt_in_effective()
            || !$this->html_has_woocommerce_varnish_esi_browser_opt_in_marker($html)
            || false !== stripos($html, 'data-ultracache-runtime-activation="woocommerce-esi-optin"')
            || false !== stripos($html, "data-ultracache-runtime-activation='woocommerce-esi-optin'")
            || !method_exists($this, 'ultracache_render_frontend_runtime_module_activation')
        ) {
            return $html;
        }

        $handle = 'ultracache-woocommerce-esi-optin';
        $markup = $this->ultracache_render_frontend_runtime_module_activation($handle);
        if (!is_string($markup) || '' === trim($markup)) {
            return $html;
        }

        $body_close = stripos($html, '</body');
        if (false !== $body_close) {
            return substr($html, 0, $body_close) . $markup . "\n" . substr($html, $body_close);
        }

        $html_close = stripos($html, '</html');
        if (false !== $html_close) {
            return substr($html, 0, $html_close) . $markup . "\n" . substr($html, $html_close);
        }

        return $html . "\n" . $markup;
    }

    /**
     * Convert WooCommerce's canonical empty classic-cart widget container into
     * a private ESI include while preserving the theme-owned outer element.
     *
     * WC_Widget_Cart deliberately renders an empty
     * `div.widget_shopping_cart_content` placeholder in the page and the
     * WooCommerce refreshed-fragments contract fills the same container later.
     * Matching only that empty canonical element avoids theme detection,
     * template-path assumptions, CSS false positives, and arbitrary DOM guesses.
     *
     * @param string $html Full WordPress template output.
     * @return string
     */
    private function apply_woocommerce_varnish_esi_canonical_mini_cart_placeholder($html)
    {
        if (
            $this->woocommerce_varnish_esi_fragment_render_depth > 0
            || !$this->is_woocommerce_varnish_esi_adapter_available()
            || !$this->is_woocommerce_varnish_esi_browser_opt_in_effective()
            || !function_exists('ultracache_varnish_esi_is_enabled')
            || !ultracache_varnish_esi_is_enabled()
            || false === strpos($html, 'widget_shopping_cart_content')
        ) {
            return $html;
        }

        $this->register_woocommerce_varnish_esi_mini_cart_fragment();
        if (!$this->woocommerce_varnish_esi_fragment_registered) {
            return $html;
        }

        $registry = class_exists('Ultra_Cache_Varnish_ESI_Registry') ? Ultra_Cache_Varnish_ESI_Registry::instance() : null;
        $fragment_id = $this->get_woocommerce_varnish_esi_mini_cart_fragment_id();
        $definition = $registry ? $registry->get($fragment_id) : null;
        if (
            !$registry
            || !is_array($definition)
            || !function_exists('ultracache_varnish_esi_private_definition_is_enabled')
            || !ultracache_varnish_esi_private_definition_is_enabled($definition)
        ) {
            return $html;
        }

        $context = array('mode' => 'classic');
        $normalized_context = $registry->normalize_context($definition, $context);
        if (is_wp_error($normalized_context)) {
            do_action('ultracache_woocommerce_esi_auto_context_error', $normalized_context, $context);
            return $html;
        }

        $token = $registry->create_context_token($fragment_id, $normalized_context);
        if (is_wp_error($token)) {
            do_action('ultracache_woocommerce_esi_auto_context_error', $token, $context);
            return $html;
        }

        $placeholder = '<!--ultracache-esi-start:v1:' . $token . '--><!--ultracache-esi-end:v1:' . $token . '-->';
        $pattern = '~(<div\b(?=[^>]*\bclass\s*=\s*(?:"[^"]*(?<![A-Za-z0-9_-])widget_shopping_cart_content(?![A-Za-z0-9_-])[^"]*"|\'[^\']*(?<![A-Za-z0-9_-])widget_shopping_cart_content(?![A-Za-z0-9_-])[^\']*\'))[^>]*)(>)([\x20\t\r\n]*)(</div>)~i';
        $replacements = 0;
        $rewritten = preg_replace_callback(
            $pattern,
            static function ($matches) use ($placeholder, &$replacements) {
                if ($replacements >= 8) {
                    return $matches[0];
                }

                $open = isset($matches[1]) ? (string) $matches[1] : '<div';
                if (false === stripos($open, 'data-ultracache-esi-auto=')) {
                    $open .= ' data-ultracache-esi-auto="woocommerce-mini-cart"';
                }

                $replacements++;
                return $open . '>' . $placeholder . (string) ($matches[3] ?? '') . '</div>';
            },
            $html
        );

        if (!is_string($rewritten) || $replacements <= 0) {
            return $html;
        }

        $this->woocommerce_varnish_esi_mini_cart_rendered = true;
        do_action('ultracache_woocommerce_esi_canonical_placeholder_detected', $replacements);
        return $rewritten;
    }

    /**
     * Convert WooCommerce semantic mini-cart markers into trusted ESI
     * placeholders. The fallback remains the exact HTML already rendered by
     * the active theme/plugin. No template filename is required when the
     * semantic hooks fire outside wc_get_template().
     *
     * @param string $html Full WordPress template output.
     * @return string
     */
    private function apply_woocommerce_varnish_esi_semantic_mini_cart_placeholders($html)
    {
        if (empty($this->woocommerce_varnish_esi_auto_marker_contexts)
            || false === strpos($html, '<!--ultracache-woocommerce-mini-cart-auto-start:v2:')) {
            return $html;
        }

        $registry = class_exists('Ultra_Cache_Varnish_ESI_Registry') ? Ultra_Cache_Varnish_ESI_Registry::instance() : null;
        if (!$registry || !function_exists('ultracache_varnish_esi_private_definition_is_enabled')) {
            return $this->strip_woocommerce_varnish_esi_auto_markers($html);
        }

        $fragment_id = $this->get_woocommerce_varnish_esi_mini_cart_fragment_id();
        $definition = $registry->get($fragment_id);
        if (!is_array($definition) || !ultracache_varnish_esi_private_definition_is_enabled($definition)) {
            return $this->strip_woocommerce_varnish_esi_auto_markers($html);
        }

        $max_fallback_bytes = max(1024, min(1048576, absint($definition['max_output_bytes'] ?? 524288)));
        $contexts = $this->woocommerce_varnish_esi_auto_marker_contexts;
        $pattern = '/<!--ultracache-woocommerce-mini-cart-auto-start:v2:([a-f0-9]{32})-->(.*?)<!--ultracache-woocommerce-mini-cart-auto-end:v2:\\1-->/s';
        $rewritten = preg_replace_callback(
            $pattern,
            function ($matches) use ($registry, $fragment_id, $max_fallback_bytes, $contexts) {
                $marker_token = isset($matches[1]) ? (string) $matches[1] : '';
                $fallback = isset($matches[2]) ? (string) $matches[2] : '';
                if (!isset($contexts[$marker_token]) || strlen($fallback) > $max_fallback_bytes) {
                    return $fallback;
                }

                $context = $contexts[$marker_token];
                $normalized_context = $registry->normalize_context($registry->get($fragment_id), $context);
                if (is_wp_error($normalized_context)) {
                    do_action('ultracache_woocommerce_esi_auto_context_error', $normalized_context, $context);
                    return $fallback;
                }
                $token = $registry->create_context_token($fragment_id, $normalized_context);
                if (is_wp_error($token)) {
                    do_action('ultracache_woocommerce_esi_auto_context_error', $token, $context);
                    return $fallback;
                }

                return '<!--ultracache-esi-start:v1:' . $token . '-->'
                    . $fallback
                    . '<!--ultracache-esi-end:v1:' . $token . '-->';
            },
            $html
        );

        $html = is_string($rewritten) ? $rewritten : $html;
        return $this->strip_woocommerce_varnish_esi_auto_markers($html);
    }

    /**
     * Remove only request-local automatic marker comments emitted by UltraCache.
     *
     * @param string $html Full template output.
     * @return string
     */
    private function strip_woocommerce_varnish_esi_auto_markers($html)
    {
        foreach (array_keys($this->woocommerce_varnish_esi_auto_marker_contexts) as $marker_token) {
            $html = str_replace(
                array(
                    '<!--ultracache-woocommerce-mini-cart-auto-start:v2:' . $marker_token . '-->',
                    '<!--ultracache-woocommerce-mini-cart-auto-end:v2:' . $marker_token . '-->',
                ),
                '',
                (string) $html
            );
        }
        return (string) $html;
    }

    /**
     * Return whether one WooCommerce cart-fragment value is small/passive
     * enough to be replayed as an independent private ESI fragment.
     *
     * This intentionally excludes the full mini-cart body: the semantic
     * before/after hooks own that primary fragment. The cart-fragments contract
     * is used here for theme/plugin counters, badges, totals, and similarly
     * bounded passive HTML only.
     *
     * @param string $selector Fragment selector.
     * @param string $markup   Fragment HTML.
     * @return bool
     */
    private function is_woocommerce_varnish_esi_secondary_fragment_safe($selector, $markup)
    {
        $selector = trim((string) $selector);
        $markup = (string) $markup;
        if ('' === $selector || strlen($selector) > 200 || '' === $markup || strlen($markup) > 8192) {
            return false;
        }
        if (false !== stripos($markup, 'woocommerce-mini-cart') || false !== stripos($markup, 'widget_shopping_cart_content')) {
            return false;
        }
        if (preg_match('/<(?:script|style|iframe|object|embed|form|input|textarea|select|button|link|meta|base|noscript|esi:)[\\s>]/i', $markup)) {
            return false;
        }
        return true;
    }

    /**
     * Record bounded secondary fragments returned through WooCommerce's public
     * cart-fragments filter contract.
     *
     * @param array $fragments WooCommerce fragment map.
     * @return void
     */
    private function record_woocommerce_varnish_esi_secondary_fragments(array $fragments)
    {
        foreach ($fragments as $selector => $markup) {
            if (count($this->woocommerce_varnish_esi_secondary_fragment_snapshot) >= 8) {
                break;
            }
            if (!is_string($selector) || !is_string($markup)) {
                continue;
            }
            if (!$this->is_woocommerce_varnish_esi_secondary_fragment_safe($selector, $markup)) {
                continue;
            }
            $encoded_selector = rtrim(strtr(base64_encode($selector), '+/', '-_'), '=');
            if ('' === $encoded_selector || strlen($encoded_selector) > 300) {
                continue;
            }
            $this->woocommerce_varnish_esi_secondary_fragment_snapshot[$encoded_selector] = $markup;
        }
    }

    /**
     * Ask the normal WooCommerce cart-fragments filter chain for theme/plugin
     * secondary fragments before the WordPress template enhancement buffer is
     * finalized. No HTTP loopback and no additional output buffer is created.
     *
     * @return void
     */
    public function collect_woocommerce_varnish_esi_secondary_fragments()
    {
        if ($this->woocommerce_varnish_esi_secondary_fragments_collected
            || $this->woocommerce_varnish_esi_fragment_render_depth > 0
            || !function_exists('ultracache_varnish_esi_is_enabled')
            || !ultracache_varnish_esi_is_enabled()) {
            return;
        }

        $this->woocommerce_varnish_esi_secondary_fragments_collected = true;
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());
        if (is_array($fragments)) {
            $this->record_woocommerce_varnish_esi_secondary_fragments($fragments);
        }
    }

    /**
     * Replace exact, request-local secondary cart-fragment HTML with trusted
     * ESI placeholders. Exact string replacement avoids guessing CSS selector
     * semantics or parsing arbitrary theme DOM structures.
     *
     * @param string $html Full WordPress template output.
     * @return string
     */
    private function apply_woocommerce_varnish_esi_secondary_fragment_placeholders($html)
    {
        if (empty($this->woocommerce_varnish_esi_secondary_fragment_snapshot)) {
            return $html;
        }

        $registry = class_exists('Ultra_Cache_Varnish_ESI_Registry') ? Ultra_Cache_Varnish_ESI_Registry::instance() : null;
        $fragment_id = $this->get_woocommerce_varnish_esi_mini_cart_fragment_id();
        $definition = $registry ? $registry->get($fragment_id) : null;
        if (!$registry || !is_array($definition) || !function_exists('ultracache_varnish_esi_private_definition_is_enabled')
            || !ultracache_varnish_esi_private_definition_is_enabled($definition)) {
            return $html;
        }

        foreach ($this->woocommerce_varnish_esi_secondary_fragment_snapshot as $encoded_selector => $fallback) {
            if ('' === $fallback || false === strpos($html, $fallback)) {
                continue;
            }
            $context = array(
                'mode'     => 'fragment',
                'selector' => $encoded_selector,
            );
            $normalized_context = $registry->normalize_context($definition, $context);
            if (is_wp_error($normalized_context)) {
                continue;
            }
            $token = $registry->create_context_token($fragment_id, $normalized_context);
            if (is_wp_error($token)) {
                continue;
            }
            $placeholder = '<!--ultracache-esi-start:v1:' . $token . '-->'
                . $fallback
                . '<!--ultracache-esi-end:v1:' . $token . '-->';
            $html = str_replace($fallback, $placeholder, $html);
        }

        return $html;
    }

    /**
     * Decode one signed secondary fragment selector.
     *
     * @param string $encoded Encoded selector.
     * @return string
     */
    private function decode_woocommerce_varnish_esi_secondary_fragment_selector($encoded)
    {
        $encoded = trim((string) $encoded);
        if ('' === $encoded || strlen($encoded) > 300 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return '';
        }
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $selector = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($selector) || '' === trim($selector) || strlen($selector) > 200) {
            return '';
        }
        return $selector;
    }

    /**
     * Add exact WooCommerce cookies to the declared private transport policy.
     *
     * Actual enablement remains gated by the WooCommerce-specific Varnish
     * capability proof.
     *
     * @param array $names Existing exact cookie names.
     * @return array
     */
    public function filter_woocommerce_varnish_esi_transport_cookie_names(array $names)
    {
        $names[] = 'woocommerce_items_in_cart';
        $names[] = 'woocommerce_cart_hash';
        return array_values(array_unique($names));
    }

    /**
     * Add the WooCommerce session-cookie prefix to the transport declaration.
     *
     * @param array $prefixes Existing cookie prefixes.
     * @return array
     */
    public function filter_woocommerce_varnish_esi_transport_cookie_prefixes(array $prefixes)
    {
        $prefixes[] = 'wp_woocommerce_session_';
        return array_values(array_unique($prefixes));
    }

    /**
     * Require a WooCommerce-specific end-to-end cookie transport proof.
     *
     * @param bool  $declared   Whether the generic policy covers the definition.
     * @param array $definition Fragment definition.
     * @return bool
     */
    public function filter_woocommerce_varnish_esi_transport_declaration($declared, array $definition)
    {
        if ($this->get_woocommerce_varnish_esi_mini_cart_fragment_id() !== (string) ($definition['id'] ?? '')) {
            return (bool) $declared;
        }

        return (bool) $declared
            && class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'is_varnish_woocommerce_esi_capability_verified')
            && Ultra_Cache_WP::is_varnish_woocommerce_esi_capability_verified();
    }

    /**
     * Render the live classic mini-cart inside the standard WooCommerce
     * fragment replacement container.
     *
     * @param array $context    Signed fragment context; automatic renders carry the WooCommerce template contract.
     * @param array $definition Fragment definition.
     * @return string|WP_Error
     */
    public function render_woocommerce_varnish_esi_mini_cart_fragment(array $context = array(), array $definition = array())
    {
        unset($definition);

        if (!$this->is_woocommerce_varnish_esi_adapter_available()) {
            return new WP_Error(
                'ultracache_woocommerce_esi_unavailable',
                __('WooCommerce classic mini-cart APIs are unavailable.', 'ultracache')
            );
        }

        try {
            $woocommerce = WC();
            if ((!isset($woocommerce->cart) || null === $woocommerce->cart) && function_exists('wc_load_cart')) {
                wc_load_cart();
                $woocommerce = WC();
            }
        } catch (Throwable $e) {
            do_action('ultracache_woocommerce_esi_render_error', $e);
            return new WP_Error(
                'ultracache_woocommerce_esi_cart_unavailable',
                __('The WooCommerce cart could not be loaded.', 'ultracache')
            );
        }

        if (!isset($woocommerce->cart) || null === $woocommerce->cart) {
            return new WP_Error(
                'ultracache_woocommerce_esi_cart_unavailable',
                __('The WooCommerce cart is unavailable.', 'ultracache')
            );
        }

        $mode = (string) ($context['mode'] ?? '');

        if ('fragment' === $mode) {
            $selector = $this->decode_woocommerce_varnish_esi_secondary_fragment_selector((string) ($context['selector'] ?? ''));
            if ('' === $selector) {
                return new WP_Error(
                    'ultracache_woocommerce_esi_fragment_selector_invalid',
                    __('The WooCommerce cart-fragment selector is invalid.', 'ultracache')
                );
            }

            $this->woocommerce_varnish_esi_fragment_render_depth++;
            try {
                $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());
                if (!is_array($fragments) || !isset($fragments[$selector]) || !is_string($fragments[$selector])) {
                    return new WP_Error(
                        'ultracache_woocommerce_esi_fragment_unavailable',
                        __('The WooCommerce cart fragment is unavailable.', 'ultracache')
                    );
                }
                $markup = (string) $fragments[$selector];
                if (!$this->is_woocommerce_varnish_esi_secondary_fragment_safe($selector, $markup)) {
                    return new WP_Error(
                        'ultracache_woocommerce_esi_fragment_unsafe',
                        __('The WooCommerce cart fragment is not eligible for private ESI.', 'ultracache')
                    );
                }
                return $markup;
            } finally {
                $this->woocommerce_varnish_esi_fragment_render_depth = max(0, $this->woocommerce_varnish_esi_fragment_render_depth - 1);
            }
        }

        if ('classic' === $mode) {
            $this->woocommerce_varnish_esi_fragment_render_depth++;
            try {
                woocommerce_mini_cart();
                return '';
            } catch (Throwable $e) {
                do_action('ultracache_woocommerce_esi_render_error', $e);
                return new WP_Error(
                    'ultracache_woocommerce_esi_template_render_failed',
                    __('The WooCommerce mini-cart could not be rendered.', 'ultracache')
                );
            } finally {
                $this->woocommerce_varnish_esi_fragment_render_depth = max(0, $this->woocommerce_varnish_esi_fragment_render_depth - 1);
            }
        }

        if ('auto' === $mode) {
            $template_name = sanitize_text_field((string) ($context['template'] ?? ''));
            if (
                '' === $template_name
                || false !== strpos($template_name, '..')
                || false !== strpos($template_name, '\\')
                || '/' === substr($template_name, 0, 1)
                || !function_exists('wc_get_template_html')
            ) {
                return new WP_Error(
                    'ultracache_woocommerce_esi_template_unavailable',
                    __('The WooCommerce mini-cart template could not be rendered.', 'ultracache')
                );
            }

            $template_path = sanitize_text_field((string) ($context['path'] ?? ''));
            if (
                strlen($template_path) > 200
                || false !== strpos($template_path, '..')
                || false !== strpos($template_path, '\\')
                || ('/' === substr($template_path, 0, 1) && '' !== $template_path)
            ) {
                return new WP_Error(
                    'ultracache_woocommerce_esi_template_path_invalid',
                    __('The WooCommerce mini-cart template path is invalid.', 'ultracache')
                );
            }

            $template_args = $this->decode_woocommerce_varnish_esi_auto_template_args((string) ($context['args'] ?? ''));
            if ('' !== (string) ($context['args'] ?? '') && empty($template_args) && 'e30' !== (string) ($context['args'] ?? '')) {
                return new WP_Error(
                    'ultracache_woocommerce_esi_template_args_invalid',
                    __('The WooCommerce mini-cart template arguments are invalid.', 'ultracache')
                );
            }

            $this->woocommerce_varnish_esi_fragment_render_depth++;
            try {
                return (string) wc_get_template_html($template_name, $template_args, $template_path);
            } catch (Throwable $e) {
                do_action('ultracache_woocommerce_esi_render_error', $e);
                return new WP_Error(
                    'ultracache_woocommerce_esi_template_render_failed',
                    __('The WooCommerce mini-cart template could not be rendered.', 'ultracache')
                );
            } finally {
                $this->woocommerce_varnish_esi_fragment_render_depth = max(0, $this->woocommerce_varnish_esi_fragment_render_depth - 1);
            }
        }

        $this->woocommerce_varnish_esi_fragment_render_depth++;
        try {
            echo '<div class="widget_shopping_cart_content ultracache-esi-mini-cart" data-ultracache-esi-mini-cart="live">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static adapter wrapper captured by the registered ESI renderer buffer.
            woocommerce_mini_cart();
            echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static adapter wrapper captured by the registered ESI renderer buffer.
            return '';
        } catch (Throwable $e) {
            do_action('ultracache_woocommerce_esi_render_error', $e);
            return new WP_Error(
                'ultracache_woocommerce_esi_template_render_failed',
                __('The WooCommerce mini-cart template could not be rendered.', 'ultracache')
            );
        } finally {
            $this->woocommerce_varnish_esi_fragment_render_depth = max(0, $this->woocommerce_varnish_esi_fragment_render_depth - 1);
        }
    }

    /**
     * Return a generic, non-personalized fallback.
     *
     * @return string
     */
    private function get_woocommerce_varnish_esi_mini_cart_fallback()
    {
        $cart_url = function_exists('ultracache_get_woocommerce_page_url') ? ultracache_get_woocommerce_page_url('cart') : '';
        if ('' === $cart_url) {
            return '';
        }
        $cart_url = esc_url($cart_url);

        return '<div class="widget_shopping_cart_content ultracache-esi-mini-cart" data-ultracache-esi-mini-cart="fallback">'
            . '<p class="woocommerce-mini-cart__empty-message">'
            . '<a href="' . $cart_url . '">' . esc_html__('View cart', 'ultracache') . '</a>'
            . '</p></div>';
    }

    /**
     * Return the mini-cart fragment markup for templates.
     *
     * @param array $args Optional wrapper arguments.
     * @return string
     */
    public function get_woocommerce_varnish_esi_mini_cart_markup(array $args = array())
    {
        if (!$this->is_woocommerce_varnish_esi_adapter_available()) {
            return '';
        }

        $this->register_woocommerce_varnish_esi_mini_cart_fragment();
        $this->woocommerce_varnish_esi_mini_cart_rendered = true;

        $fragment = function_exists('ultracache_get_varnish_esi_fragment_markup')
            ? ultracache_get_varnish_esi_fragment_markup($this->get_woocommerce_varnish_esi_mini_cart_fragment_id())
            : $this->get_woocommerce_varnish_esi_mini_cart_fallback();

        $classes = array('ultracache-esi-mini-cart-shell');
        foreach (preg_split('/\s+/', (string) ($args['class'] ?? '')) as $class_name) {
            $class_name = sanitize_html_class($class_name);
            if ('' !== $class_name) {
                $classes[] = $class_name;
            }
        }
        $classes = array_values(array_unique($classes));

        $markup = '<div class="' . esc_attr(implode(' ', $classes)) . '" data-ultracache-esi-adapter="woocommerce-classic-mini-cart">'
            . $fragment
            . '</div>';

        return (string) apply_filters('ultracache_woocommerce_esi_mini_cart_markup', $markup, $args);
    }

    /**
     * Render the mini-cart shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string
     */
    public function shortcode_woocommerce_varnish_esi_mini_cart($atts = array())
    {
        $atts = shortcode_atts(
            array('class' => ''),
            is_array($atts) ? $atts : array(),
            'ultracache_esi_mini_cart'
        );

        return $this->get_woocommerce_varnish_esi_mini_cart_markup($atts);
    }

    /**
     * Whether this request rendered the classic ESI mini-cart adapter.
     *
     * @return bool
     */
    private function is_woocommerce_varnish_esi_mini_cart_rendered_for_request()
    {
        return $this->woocommerce_varnish_esi_mini_cart_rendered;
    }

    /**
     * Whether the verified WooCommerce ESI adapter may opt this browser into
     * the shared-parent Varnish handshake.
     *
     * @return bool
     */
    private function is_woocommerce_varnish_esi_browser_opt_in_effective()
    {
        return class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'is_varnish_woocommerce_esi_capability_verified')
            && Ultra_Cache_WP::is_varnish_woocommerce_esi_capability_verified();
    }

    /**
     * Ensure the native cart-fragments runtime remains available when the
     * classic adapter is present. This intentionally overrides UltraCache's
     * optional empty-cart suppression and delay controls for this request.
     *
     * @return void
     */
    public function ensure_woocommerce_varnish_esi_mini_cart_runtime()
    {
        if (!function_exists('wp_enqueue_script') || !$this->is_woocommerce_varnish_esi_adapter_available()) {
            return;
        }

        if ($this->woocommerce_varnish_esi_runtime_enabled || !$this->woocommerce_varnish_esi_mini_cart_rendered) {
            return;
        }
        $this->woocommerce_varnish_esi_runtime_enabled = true;

        wp_dequeue_script('ultracache-woocommerce-cart-fragments-delay');
        if (wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
        }

        do_action('ultracache_woocommerce_esi_runtime_enabled');
    }

    /**
     * Ensure add-to-cart responses contain the classic mini-cart selector used
     * by the adapter. WooCommerce's cart-fragments runtime replaces this exact
     * container after add/remove cart events.
     *
     * @param array $fragments Existing fragments.
     * @return array
     */
    public function filter_woocommerce_varnish_esi_mini_cart_fragments(array $fragments)
    {
        if ($this->is_woocommerce_varnish_esi_adapter_available() && $this->woocommerce_varnish_esi_fragment_render_depth <= 0) {
            $this->record_woocommerce_varnish_esi_secondary_fragments($fragments);
        }

        /*
         * Preserve WooCommerce/theme fragment output exactly as registered.
         * WooCommerce itself owns the canonical classic mini-cart fragment;
         * UltraCache no longer overwrites that public contract.
         */
        return $fragments;
    }
}
