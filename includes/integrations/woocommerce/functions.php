<?php
/**
 * WooCommerce ESI template API.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the classic WooCommerce mini-cart ESI adapter markup.
 *
 * The adapter uses a private, non-cacheable fragment and the standard
 * WooCommerce `div.widget_shopping_cart_content` replacement selector.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return string
 */
function ultracache_get_woocommerce_esi_mini_cart_markup(array $args = array())
{
    if (!class_exists('Ultra_Cache_Engine') || !method_exists('Ultra_Cache_Engine', 'get_instance')) {
        return '';
    }

    $engine = Ultra_Cache_Engine::get_instance();
    if (!method_exists($engine, 'get_woocommerce_esi_mini_cart_markup')) {
        return '';
    }

    return (string) $engine->get_woocommerce_esi_mini_cart_markup($args);
}

/**
 * Echo the classic WooCommerce mini-cart ESI adapter markup.
 *
 * @param array $args Optional wrapper arguments. Supports `class`.
 * @return void
 */
function ultracache_render_woocommerce_esi_mini_cart(array $args = array())
{
    echo ultracache_get_woocommerce_esi_mini_cart_markup($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Adapter markup is rendered and escaped by UltraCache/WooCommerce.
}
