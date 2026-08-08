<?php
/**
 * WooCommerce classic mini-cart integration for private ESI fragments.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_WooCommerce_ESI_Trait
{
    /** @var bool */
    private $woocommerce_esi_mini_cart_rendered = false;

    /** @var bool */
    private $woocommerce_esi_fragment_registered = false;

    /**
     * Register WooCommerce ESI hooks.
     *
     * @return void
     */
    private function register_woocommerce_esi_hooks()
    {
        add_action('init', array($this, 'register_woocommerce_esi_mini_cart_fragment'), 20);
        add_filter('ultracache_esi_private_transport_cookie_names', array($this, 'filter_woocommerce_esi_transport_cookie_names'), 20);
        add_filter('ultracache_esi_private_transport_cookie_prefixes', array($this, 'filter_woocommerce_esi_transport_cookie_prefixes'), 20);
        add_filter('ultracache_esi_private_definition_transport_declared', array($this, 'filter_woocommerce_esi_transport_declaration'), 20, 2);
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'filter_woocommerce_esi_mini_cart_fragments'), PHP_INT_MAX);
        add_action('wp_footer', array($this, 'ensure_woocommerce_esi_mini_cart_runtime'), 1);
    }

    /**
     * Whether WooCommerce classic cart APIs are available.
     *
     * @return bool
     */
    private function is_woocommerce_esi_adapter_available()
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
    private function get_woocommerce_esi_mini_cart_fragment_id()
    {
        return 'woocommerce-mini-cart';
    }

    /**
     * Register the private classic mini-cart fragment and shortcode.
     *
     * @return void
     */
    public function register_woocommerce_esi_mini_cart_fragment()
    {
        if (!$this->is_woocommerce_esi_adapter_available()) {
            return;
        }

        add_shortcode('ultracache_esi_mini_cart', array($this, 'shortcode_woocommerce_esi_mini_cart'));

        if ($this->woocommerce_esi_fragment_registered || !function_exists('ultracache_register_esi_fragment')) {
            return;
        }

        $result = ultracache_register_esi_fragment(
            $this->get_woocommerce_esi_mini_cart_fragment_id(),
            array(
                'scope'                   => 'private',
                'cookie_names'            => array(
                    'woocommerce_items_in_cart',
                    'woocommerce_cart_hash',
                ),
                'cookie_prefixes'         => array('wp_woocommerce_session_'),
                'renderer'                => array($this, 'render_woocommerce_esi_mini_cart_fragment'),
                'fallback'                => $this->get_woocommerce_esi_mini_cart_fallback(),
                'max_output_bytes'        => 524288,
                'max_cookie_header_bytes' => 12288,
                'needs_main_query'         => false,
            )
        );

        if (true === $result) {
            $this->woocommerce_esi_fragment_registered = true;
            return;
        }

        if (is_wp_error($result) && 'ultracache_esi_fragment_already_registered' === $result->get_error_code()) {
            $this->woocommerce_esi_fragment_registered = true;
            return;
        }

        if (is_wp_error($result)) {
            do_action('ultracache_woocommerce_esi_registration_error', $result);
        }
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
    public function filter_woocommerce_esi_transport_cookie_names(array $names)
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
    public function filter_woocommerce_esi_transport_cookie_prefixes(array $prefixes)
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
    public function filter_woocommerce_esi_transport_declaration($declared, array $definition)
    {
        if ($this->get_woocommerce_esi_mini_cart_fragment_id() !== (string) ($definition['id'] ?? '')) {
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
     * @param array $context    Empty fragment context.
     * @param array $definition Fragment definition.
     * @return string|WP_Error
     */
    public function render_woocommerce_esi_mini_cart_fragment(array $context = array(), array $definition = array())
    {
        unset($context, $definition);

        if (!$this->is_woocommerce_esi_adapter_available()) {
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

        ob_start();
        echo '<div class="widget_shopping_cart_content ultracache-esi-mini-cart" data-ultracache-esi-mini-cart="live">';
        woocommerce_mini_cart();
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Return a generic, non-personalized fallback.
     *
     * @return string
     */
    private function get_woocommerce_esi_mini_cart_fallback()
    {
        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
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
    public function get_woocommerce_esi_mini_cart_markup(array $args = array())
    {
        if (!$this->is_woocommerce_esi_adapter_available()) {
            return '';
        }

        $this->register_woocommerce_esi_mini_cart_fragment();
        $this->woocommerce_esi_mini_cart_rendered = true;

        $fragment = function_exists('ultracache_get_esi_fragment_markup')
            ? ultracache_get_esi_fragment_markup($this->get_woocommerce_esi_mini_cart_fragment_id())
            : $this->get_woocommerce_esi_mini_cart_fallback();

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
    public function shortcode_woocommerce_esi_mini_cart($atts = array())
    {
        $atts = shortcode_atts(
            array('class' => ''),
            is_array($atts) ? $atts : array(),
            'ultracache_esi_mini_cart'
        );

        return $this->get_woocommerce_esi_mini_cart_markup($atts);
    }

    /**
     * Whether this request rendered the classic ESI mini-cart adapter.
     *
     * @return bool
     */
    private function is_woocommerce_esi_mini_cart_rendered_for_request()
    {
        return $this->woocommerce_esi_mini_cart_rendered;
    }

    /**
     * Whether the verified WooCommerce ESI adapter may opt this browser into
     * the shared-parent Varnish handshake.
     *
     * @return bool
     */
    private function is_woocommerce_esi_browser_opt_in_effective()
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
    public function ensure_woocommerce_esi_mini_cart_runtime()
    {
        if (!$this->woocommerce_esi_mini_cart_rendered || !function_exists('wp_enqueue_script')) {
            return;
        }

        wp_dequeue_script('ultracache-woocommerce-cart-fragments-delay');
        if (wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
        }

        if (
            $this->is_woocommerce_esi_browser_opt_in_effective()
            && method_exists($this, 'ultracache_enqueue_frontend_js_helper')
        ) {
            $this->ultracache_enqueue_frontend_js_helper(
                'ultracache-woocommerce-esi-optin',
                'woocommerce-esi-optin.js',
                array(),
                true
            );
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
    public function filter_woocommerce_esi_mini_cart_fragments(array $fragments)
    {
        if (!$this->is_woocommerce_esi_adapter_available()) {
            return $fragments;
        }

        $rendered = $this->render_woocommerce_esi_mini_cart_fragment();
        if (!is_wp_error($rendered) && '' !== $rendered) {
            $fragments['div.widget_shopping_cart_content'] = $rendered;
        }

        return $fragments;
    }
}
