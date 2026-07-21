<?php
/**
 * Core Varnish integration helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return the stable site-specific signature used by the VCL origin-revalidation
 * pass contract. The signature is derived from WordPress salts and is not persisted.
 *
 * @return string
 */
function ultracache_get_varnish_revalidation_vcl_signature()
{
    $secret = function_exists('ultracache_runtime_control_secret')
        ? (string) ultracache_runtime_control_secret()
        : '';
    if ('' === $secret) {
        return '';
    }

    $site = function_exists('home_url') ? (string) home_url('/') : '';
    return hash_hmac('sha256', 'ultracache-varnish-origin-revalidation|' . $site, $secret);
}
