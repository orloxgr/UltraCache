<?php
/**
 * WooCommerce classic mini-cart integration for native LiteSpeed ESI.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_WooCommerce_LiteSpeed_ESI_Trait
{
    /** @var bool */
    private $woocommerce_litespeed_esi_mini_cart_rendered = false;

    /** @var bool */
    private $woocommerce_litespeed_esi_fragment_registered = false;

    /** @var bool */
    private $woocommerce_litespeed_esi_canonical_fragment_registered = false;

    /** @return void */
    private function register_woocommerce_litespeed_esi_hooks()
    {
        add_action('init', array($this, 'register_woocommerce_litespeed_esi_mini_cart_fragment'), 21);
        add_action('init', array($this, 'register_woocommerce_litespeed_esi_canonical_mini_cart_fragment'), 22);
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'filter_woocommerce_litespeed_esi_mini_cart_fragments'), PHP_INT_MAX - 1);
        add_action('wp_footer', array($this, 'ensure_woocommerce_litespeed_esi_mini_cart_runtime'), 2);
    }

    /** @return bool */
    private function is_woocommerce_litespeed_esi_adapter_available()
    {
        return function_exists('WC')
            && function_exists('woocommerce_mini_cart')
            && (class_exists('WooCommerce') || defined('WC_VERSION'))
            && function_exists('ultracache_litespeed_esi_is_enabled')
            && ultracache_litespeed_esi_is_enabled();
    }

    /** @return string */
    private function get_woocommerce_litespeed_esi_mini_cart_fragment_id()
    {
        return 'woocommerce-mini-cart';
    }

    /** @return string */
    private function get_woocommerce_litespeed_esi_canonical_mini_cart_fragment_id()
    {
        return 'woocommerce-mini-cart-canonical';
    }

    /** @return void */
    public function register_woocommerce_litespeed_esi_mini_cart_fragment()
    {
        add_shortcode('ultracache_litespeed_esi_mini_cart', array($this, 'shortcode_woocommerce_litespeed_esi_mini_cart'));
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()
            || $this->woocommerce_litespeed_esi_fragment_registered
            || !function_exists('ultracache_register_litespeed_esi_fragment')) {
            return;
        }

        $result = ultracache_register_litespeed_esi_fragment(
            $this->get_woocommerce_litespeed_esi_mini_cart_fragment_id(),
            array(
                'scope' => 'private',
                'cookie_names' => array(
                    'woocommerce_items_in_cart',
                    'woocommerce_cart_hash',
                ),
                'cookie_prefixes' => array(
                    'wp_woocommerce_session_',
                    'wordpress_logged_in_',
                ),
                'renderer' => array($this, 'render_woocommerce_litespeed_esi_mini_cart_fragment'),
                'fallback' => $this->get_woocommerce_litespeed_esi_mini_cart_fallback(),
                'max_output_bytes' => 524288,
                'max_cookie_header_bytes' => 16384,
                'needs_main_query' => false,
            )
        );

        if (true === $result || (is_wp_error($result) && 'ultracache_litespeed_esi_fragment_already_registered' === $result->get_error_code())) {
            $this->woocommerce_litespeed_esi_fragment_registered = true;
            return;
        }

        if (is_wp_error($result)) {
            do_action('ultracache_woocommerce_litespeed_esi_registration_error', $result);
        }
    }

    /** @return void */
    public function register_woocommerce_litespeed_esi_canonical_mini_cart_fragment()
    {
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()
            || $this->woocommerce_litespeed_esi_canonical_fragment_registered
            || !function_exists('ultracache_register_litespeed_esi_fragment')) {
            return;
        }

        $result = ultracache_register_litespeed_esi_fragment(
            $this->get_woocommerce_litespeed_esi_canonical_mini_cart_fragment_id(),
            array(
                'scope' => 'private',
                'cookie_names' => array(
                    'woocommerce_items_in_cart',
                    'woocommerce_cart_hash',
                ),
                'cookie_prefixes' => array(
                    'wp_woocommerce_session_',
                    'wordpress_logged_in_',
                ),
                'renderer' => array($this, 'render_woocommerce_litespeed_esi_canonical_mini_cart_fragment'),
                'fallback' => '',
                'max_output_bytes' => 524288,
                'max_cookie_header_bytes' => 16384,
                'needs_main_query' => false,
            )
        );

        if (true === $result || (is_wp_error($result) && 'ultracache_litespeed_esi_fragment_already_registered' === $result->get_error_code())) {
            $this->woocommerce_litespeed_esi_canonical_fragment_registered = true;
            return;
        }

        if (is_wp_error($result)) {
            do_action('ultracache_woocommerce_litespeed_esi_registration_error', $result);
        }
    }

    /**
     * Render only the canonical cart-widget container body. The theme-owned
     * `div.widget_shopping_cart_content` remains in the parent document so the
     * standard WooCommerce cart-fragments selector keeps its normal contract.
     *
     * @return string|WP_Error
     */
    public function render_woocommerce_litespeed_esi_canonical_mini_cart_fragment()
    {
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()) {
            return new WP_Error('ultracache_woocommerce_litespeed_esi_unavailable', __('WooCommerce classic mini-cart APIs are unavailable.', 'ultracache'));
        }

        try {
            $woocommerce = WC();
            if ((!isset($woocommerce->cart) || null === $woocommerce->cart) && function_exists('wc_load_cart')) {
                wc_load_cart();
                $woocommerce = WC();
            }
        } catch (Throwable $e) {
            do_action('ultracache_woocommerce_litespeed_esi_render_error', $e);
            return new WP_Error('ultracache_woocommerce_litespeed_esi_cart_unavailable', __('The WooCommerce cart could not be loaded.', 'ultracache'));
        }

        if (!isset($woocommerce->cart) || null === $woocommerce->cart) {
            return new WP_Error('ultracache_woocommerce_litespeed_esi_cart_unavailable', __('The WooCommerce cart is unavailable.', 'ultracache'));
        }

        woocommerce_mini_cart();
        return '';
    }

    /**
     * Render the live WooCommerce classic mini-cart.
     *
     * @return string|WP_Error
     */
    public function render_woocommerce_litespeed_esi_mini_cart_fragment()
    {
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()) {
            return new WP_Error('ultracache_woocommerce_litespeed_esi_unavailable', __('WooCommerce classic mini-cart APIs are unavailable.', 'ultracache'));
        }

        try {
            $woocommerce = WC();
            if ((!isset($woocommerce->cart) || null === $woocommerce->cart) && function_exists('wc_load_cart')) {
                wc_load_cart();
                $woocommerce = WC();
            }
        } catch (Throwable $e) {
            do_action('ultracache_woocommerce_litespeed_esi_render_error', $e);
            return new WP_Error('ultracache_woocommerce_litespeed_esi_cart_unavailable', __('The WooCommerce cart could not be loaded.', 'ultracache'));
        }

        if (!isset($woocommerce->cart) || null === $woocommerce->cart) {
            return new WP_Error('ultracache_woocommerce_litespeed_esi_cart_unavailable', __('The WooCommerce cart is unavailable.', 'ultracache'));
        }

        ob_start();
        echo '<div class="widget_shopping_cart_content ultracache-litespeed-esi-mini-cart" data-ultracache-litespeed-esi-mini-cart="live">';
        woocommerce_mini_cart();
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** @return string */
    private function get_woocommerce_litespeed_esi_mini_cart_fallback()
    {
        $cart_url = function_exists('ultracache_get_woocommerce_page_url') ? ultracache_get_woocommerce_page_url('cart') : '';
        if ('' === $cart_url) {
            return '';
        }

        return '<div class="widget_shopping_cart_content ultracache-litespeed-esi-mini-cart" data-ultracache-litespeed-esi-mini-cart="fallback">'
            . '<p class="woocommerce-mini-cart__empty-message"><a href="' . esc_url($cart_url) . '">'
            . esc_html__('View cart', 'ultracache')
            . '</a></p></div>';
    }

    /** @param array $args @return string */
    public function get_woocommerce_litespeed_esi_mini_cart_markup(array $args = array())
    {
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()) {
            return $this->get_woocommerce_litespeed_esi_mini_cart_fallback();
        }

        $this->register_woocommerce_litespeed_esi_mini_cart_fragment();
        $this->woocommerce_litespeed_esi_mini_cart_rendered = true;
        $fragment = ultracache_get_litespeed_esi_fragment_markup($this->get_woocommerce_litespeed_esi_mini_cart_fragment_id());

        $classes = array('ultracache-litespeed-esi-mini-cart-shell');
        foreach (preg_split('/\s+/', (string) ($args['class'] ?? '')) as $class_name) {
            $class_name = sanitize_html_class($class_name);
            if ('' !== $class_name) {
                $classes[] = $class_name;
            }
        }

        $markup = '<div class="' . esc_attr(implode(' ', array_values(array_unique($classes)))) . '" data-ultracache-esi-adapter="woocommerce-litespeed-classic-mini-cart">'
            . $fragment
            . '</div>';

        return (string) apply_filters('ultracache_woocommerce_litespeed_esi_mini_cart_markup', $markup, $args);
    }

    /** @param array|string $atts @return string */
    public function shortcode_woocommerce_litespeed_esi_mini_cart($atts = array())
    {
        $atts = shortcode_atts(array('class' => ''), is_array($atts) ? $atts : array(), 'ultracache_litespeed_esi_mini_cart');
        return $this->get_woocommerce_litespeed_esi_mini_cart_markup($atts);
    }

    /**
     * Convert WooCommerce's canonical empty classic-cart widget container into
     * a native LiteSpeed ESI include while preserving the theme-owned outer
     * element. This mirrors the existing theme-agnostic canonical detection
     * used by the Varnish adapter but emits the LiteSpeed signed placeholder
     * format and does not touch the Varnish path.
     *
     * @param string $html Full WordPress template output.
     * @return string
     */
    private function apply_woocommerce_litespeed_esi_auto_mini_cart_placeholders($html)
    {
        if (
            !is_string($html)
            || '' === $html
            || !$this->is_woocommerce_litespeed_esi_adapter_available()
            || false === strpos($html, 'widget_shopping_cart_content')
            || !function_exists('ultracache_get_litespeed_esi_fragment_markup')
        ) {
            return $html;
        }

        $this->register_woocommerce_litespeed_esi_canonical_mini_cart_fragment();
        if (!$this->woocommerce_litespeed_esi_canonical_fragment_registered) {
            return $html;
        }

        $fragment = ultracache_get_litespeed_esi_fragment_markup(
            $this->get_woocommerce_litespeed_esi_canonical_mini_cart_fragment_id()
        );
        if (
            !is_string($fragment)
            || '' === $fragment
            || false === strpos($fragment, '<!--ultracache-litespeed-esi-start:v1:')
        ) {
            return $html;
        }

        $pattern = '~(<div\b(?=[^>]*\bclass\s*=\s*(?:"[^"]*(?<![A-Za-z0-9_-])widget_shopping_cart_content(?![A-Za-z0-9_-])[^"]*"|\'[^\']*(?<![A-Za-z0-9_-])widget_shopping_cart_content(?![A-Za-z0-9_-])[^\']*\'))[^>]*)(>)([\x20\t\r\n]*)(</div>)~i';
        $replacements = 0;
        $rewritten = preg_replace_callback(
            $pattern,
            static function ($matches) use ($fragment, &$replacements) {
                if ($replacements >= 8) {
                    return $matches[0];
                }

                $open = isset($matches[1]) ? (string) $matches[1] : '<div';
                if (false === stripos($open, 'data-ultracache-esi-adapter=')) {
                    $open .= ' data-ultracache-esi-adapter="woocommerce-litespeed-classic-mini-cart"';
                }
                if (false === stripos($open, 'data-ultracache-litespeed-esi-auto=')) {
                    $open .= ' data-ultracache-litespeed-esi-auto="woocommerce-mini-cart"';
                }

                $replacements++;
                return $open . '>' . $fragment . (string) ($matches[3] ?? '') . '</div>';
            },
            $html
        );

        if (!is_string($rewritten) || $replacements <= 0) {
            return $html;
        }

        $this->woocommerce_litespeed_esi_mini_cart_rendered = true;
        do_action('ultracache_woocommerce_litespeed_esi_canonical_placeholder_detected', $replacements);
        return $rewritten;
    }

    /**
     * Whether this request rendered the native LiteSpeed classic mini-cart adapter.
     *
     * @return bool
     */
    private function is_woocommerce_litespeed_esi_mini_cart_rendered_for_request()
    {
        return $this->woocommerce_litespeed_esi_mini_cart_rendered;
    }

    /** @return void */
    public function ensure_woocommerce_litespeed_esi_mini_cart_runtime()
    {
        if (!$this->woocommerce_litespeed_esi_mini_cart_rendered || !function_exists('wp_enqueue_script')) {
            return;
        }
        wp_dequeue_script('ultracache-woocommerce-cart-fragments-delay');
        if (wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
        }
        do_action('ultracache_woocommerce_litespeed_esi_runtime_enabled');
    }

    /** @param array $fragments @return array */
    public function filter_woocommerce_litespeed_esi_mini_cart_fragments(array $fragments)
    {
        if (!$this->is_woocommerce_litespeed_esi_adapter_available()) {
            return $fragments;
        }
        $rendered = $this->render_woocommerce_litespeed_esi_mini_cart_fragment();
        if (!is_wp_error($rendered) && '' !== $rendered) {
            $fragments['div.widget_shopping_cart_content'] = $rendered;
        }
        return $fragments;
    }
}
