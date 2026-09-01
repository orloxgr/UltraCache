<?php
/**
 * Public ESI fragment lifecycle operations.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Varnish_ESI_Lifecycle_Trait
{
    /**
     * Invalidate one exact public fragment context in Varnish.
     *
     * The signed fragment URL is deterministic for the registered fragment ID
     * and normalized public context. Purging this URL does not invalidate any
     * parent page; the next parent delivery refetches only this fragment.
     *
     * @param string $fragment_id Registered public fragment ID.
     * @param array  $context     Exact public fragment context.
     * @return array|WP_Error
     */
    public static function purge_public_varnish_esi_fragment($fragment_id, array $context = array())
    {
        $registry = Ultra_Cache_Varnish_ESI_Registry::instance();
        $definition = $registry->get($fragment_id);
        if (null === $definition || 'public' !== (string) ($definition['scope'] ?? '')) {
            return new WP_Error(
                'ultracache_esi_fragment_not_registered',
                __('The public ESI fragment is not registered.', 'ultracache')
            );
        }

        $normalized_context = $registry->normalize_context($definition, $context);
        if (is_wp_error($normalized_context)) {
            return $normalized_context;
        }

        $url = function_exists('ultracache_get_varnish_esi_fragment_url')
            ? ultracache_get_varnish_esi_fragment_url((string) $definition['id'], $normalized_context)
            : new WP_Error('ultracache_esi_url_api_unavailable', __('The ESI fragment URL API is unavailable.', 'ultracache'));
        if (is_wp_error($url)) {
            return $url;
        }

        $url = esc_url_raw((string) $url);
        if ('' === $url
            || !function_exists('ultracache_is_strict_frontend_loopback_url')
            || !ultracache_is_strict_frontend_loopback_url($url)) {
            return new WP_Error(
                'ultracache_esi_fragment_url_untrusted',
                __('The ESI fragment URL is not an exact trusted frontend URL for this site.', 'ultracache')
            );
        }

        $context_hash = $registry->get_context_hash((string) $definition['id'], $normalized_context);
        do_action('ultracache_before_esi_fragment_purge', $definition['id'], $normalized_context, $url, $context_hash);

        if (!method_exists(static::class, 'varnish_flush_url')) {
            $result = array(
                'success' => false,
                'fragmentId' => (string) $definition['id'],
                'contextHash' => $context_hash,
                'url' => $url,
                'message' => self::maybe_translate('Varnish exact-URL invalidation is unavailable.'),
                'varnish' => array(),
            );
            if (method_exists(static::class, 'record_varnish_esi_fragment_invalidation_result')) {
                self::record_varnish_esi_fragment_invalidation_result(false);
            }
            do_action('ultracache_after_esi_fragment_purge', $result, $definition, $normalized_context);
            return $result;
        }

        $varnish_result = self::varnish_flush_url($url);
        $success = is_array($varnish_result) && !empty($varnish_result['success']);
        $result = array(
            'success' => $success,
            'fragmentId' => (string) $definition['id'],
            'contextHash' => $context_hash,
            'url' => $url,
            'message' => $success
                ? self::maybe_translate('The exact public ESI fragment URL was invalidated.')
                : (string) ($varnish_result['message'] ?? self::maybe_translate('The exact public ESI fragment URL could not be invalidated.')),
            'varnish' => is_array($varnish_result) ? $varnish_result : array(),
        );

        if (method_exists(static::class, 'record_varnish_esi_fragment_invalidation_result')) {
            self::record_varnish_esi_fragment_invalidation_result($success);
        }

        do_action('ultracache_after_esi_fragment_purge', $result, $definition, $normalized_context);
        return apply_filters('ultracache_esi_fragment_purge_result', $result, $definition, $normalized_context);
    }
}
