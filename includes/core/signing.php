<?php
/**
 * Stable internal HMAC helpers.
 *
 * Internal signatures are bound to WordPress salts, one fixed purpose, and
 * the current blog id. Request-context URLs are intentionally excluded so
 * language, proxy, scheme, or canonical URL filters cannot change the key.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sign one internal UltraCache payload.
 *
 * @param string $purpose Stable purpose name.
 * @param string $payload Payload bytes.
 * @return string Lowercase SHA-256 HMAC, or an empty string for an invalid purpose.
 */
function ultracache_internal_sign($purpose, $payload)
{
    $purpose = sanitize_key((string) $purpose);
    if ('' === $purpose) {
        return '';
    }

    $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    $message = 'ultracache|' . $purpose . '|' . $blog_id . '|' . (string) $payload;

    return hash_hmac('sha256', $message, (string) wp_salt('auth'));
}

/**
 * Verify one internal UltraCache payload signature.
 *
 * @param string $purpose Stable purpose name.
 * @param string $payload Payload bytes.
 * @param string $signature Candidate hexadecimal HMAC.
 * @return bool
 */
function ultracache_internal_verify($purpose, $payload, $signature)
{
    $signature = strtolower(trim((string) $signature));
    if (1 !== preg_match('/^[a-f0-9]{64}$/', $signature)) {
        return false;
    }

    $expected = ultracache_internal_sign($purpose, $payload);
    return '' !== $expected && hash_equals($expected, $signature);
}
