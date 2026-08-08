<?php
/**
 * LiteSpeed cache detection and purge transport helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_LiteSpeed_Transport_Trait
{
    private static function get_litespeed_transport_status($server_software, $reverse_proxy)
    {
        $server_software = is_string($server_software) ? $server_software : '';
        $reverse_proxy = is_array($reverse_proxy) ? $reverse_proxy : array();

        $reverse_server = isset($reverse_proxy['server']) ? (string) $reverse_proxy['server'] : '';
        $x_litespeed_cache = isset($reverse_proxy['x_litespeed_cache']) ? trim((string) $reverse_proxy['x_litespeed_cache']) : '';
        $x_qc_cache = isset($reverse_proxy['x_qc_cache']) ? trim((string) $reverse_proxy['x_qc_cache']) : '';

        $server_detected = false !== stripos($server_software, 'LiteSpeed')
            || false !== stripos($server_software, 'OpenLiteSpeed')
            || false !== stripos($reverse_server, 'LiteSpeed')
            || false !== stripos($reverse_server, 'OpenLiteSpeed');
        $litespeed_cache_header_detected = '' !== $x_litespeed_cache;
        $quic_cloud_cache_header_detected = '' !== $x_qc_cache;
        $cache_header_detected = $litespeed_cache_header_detected || $quic_cloud_cache_header_detected;

        $purge_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge_all');
        $purge_tag_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge');
        $purge_url_hook_registered = function_exists('has_action') && false !== has_action('litespeed_purge_url');
        $plugin_marker_detected = defined('LSCWP_V') || defined('LITESPEED_STATIC_DIR');
        $legacy_class_detected = class_exists('LiteSpeed_Cache_API');
        $legacy_class_purge = $legacy_class_detected && method_exists('LiteSpeed_Cache_API', 'purge_all');
        $legacy_namespaced_purge = class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all');
        $legacy_function_purge = function_exists('litespeed_purge_all');

        $official_hook_available = $purge_hook_registered;
        $signed_control_configured = method_exists(__CLASS__, 'is_native_litespeed_html_cache_enabled')
            && self::is_native_litespeed_html_cache_enabled();
        $native_header_available = $litespeed_cache_header_detected
            || ($quic_cloud_cache_header_detected && $server_detected);
        $signed_control_available = $signed_control_configured
            && ($server_detected || $native_header_available);
        $plugin_detected = $plugin_marker_detected
            || $official_hook_available
            || $purge_tag_hook_registered
            || $purge_url_hook_registered
            || $legacy_class_detected
            || $legacy_namespaced_purge
            || $legacy_function_purge;
        $detected = $server_detected || $cache_header_detected || $plugin_detected;
        $enabled = $signed_control_available
            || $cache_header_detected
            || $official_hook_available
            || $legacy_class_purge
            || $legacy_namespaced_purge
            || $legacy_function_purge;
        $flushable = $signed_control_available
            || $official_hook_available
            || $native_header_available
            || $legacy_class_purge
            || $legacy_namespaced_purge
            || $legacy_function_purge;

        if ($signed_control_available) {
            $transport = 'signed_internal_control';
            $method = 'signed REST control response';
        } elseif ($official_hook_available) {
            $transport = 'official_wordpress_hook';
            $method = 'do_action(litespeed_purge_all)';
        } elseif ($native_header_available) {
            $transport = 'native_response_header';
            $method = 'X-LiteSpeed-Purge response header';
        } elseif ($legacy_class_purge) {
            $transport = 'legacy_class_api';
            $method = 'LiteSpeed_Cache_API::purge_all';
        } elseif ($legacy_namespaced_purge) {
            $transport = 'legacy_namespaced_api';
            $method = '\LiteSpeed\Purge::purge_all';
        } elseif ($legacy_function_purge) {
            $transport = 'legacy_function_api';
            $method = 'litespeed_purge_all';
        } else {
            $transport = 'unavailable';
            $method = 'not_available';
        }

        if ($signed_control_available) {
            $message = __('Native LiteSpeed HTML Cache is enabled on a confirmed LiteSpeed origin. The signed internal purge control is available.', 'ultracache');
        } elseif ($official_hook_available) {
            $message = __('LiteSpeed Cache WordPress purge integration detected.', 'ultracache');
        } elseif ($native_header_available) {
            $message = __('An active LiteSpeed cache response header was observed. Native LiteSpeed purge headers are available.', 'ultracache');
        } elseif ($quic_cloud_cache_header_detected) {
            $message = __('A QUIC.cloud cache response header was observed, but a LiteSpeed origin purge transport or WordPress purge hook has not been confirmed.', 'ultracache');
        } elseif ($legacy_class_purge || $legacy_namespaced_purge || $legacy_function_purge) {
            $message = __('A legacy LiteSpeed purge API is available as a compatibility fallback.', 'ultracache');
        } elseif ($plugin_marker_detected) {
            $message = __('LiteSpeed Cache plugin markers were detected, but no registered purge hook or compatibility transport is available.', 'ultracache');
        } elseif ($server_detected) {
            $message = __('LiteSpeed/OpenLiteSpeed server detected, but active LSCache or a WordPress purge integration has not been confirmed.', 'ultracache');
        } elseif ($detected) {
            $message = __('LiteSpeed was detected, but no supported purge transport is currently available.', 'ultracache');
        } else {
            $message = __('LiteSpeed Cache was not detected.', 'ultracache');
        }

        return array(
            'detected' => (bool) $detected,
            'enabled' => (bool) $enabled,
            'flushable' => (bool) $flushable,
            'method' => $method,
            'transport' => $transport,
            'serverDetected' => (bool) $server_detected,
            'cacheHeaderDetected' => (bool) $cache_header_detected,
            'liteSpeedCacheHeaderDetected' => (bool) $litespeed_cache_header_detected,
            'quicCloudCacheHeaderDetected' => (bool) $quic_cloud_cache_header_detected,
            'pluginDetected' => (bool) $plugin_detected,
            'officialHookAvailable' => (bool) $official_hook_available,
            'officialTagHookAvailable' => (bool) $purge_tag_hook_registered,
            'officialUrlHookAvailable' => (bool) $purge_url_hook_registered,
            'nativeHeaderAvailable' => (bool) $native_header_available,
            'signedControlConfigured' => (bool) $signed_control_configured,
            'signedControlAvailable' => (bool) $signed_control_available,
            'legacyFallbackAvailable' => (bool) ($legacy_class_purge || $legacy_namespaced_purge || $legacy_function_purge),
            'xLiteSpeedCache' => $x_litespeed_cache,
            'xQcCache' => $x_qc_cache,
            'message' => $message,
        );
    }

    private static function send_litespeed_purge_header($value = '*')
    {
        $value = is_string($value) ? trim($value) : '*';
        if ('' === $value) {
            $value = '*';
        }

        if ('*' !== $value && !preg_match('/^(?:url|tag|private|public)=[A-Za-z0-9_:\/.,?&=%+~#@!$;*()\[\]\-]+$/', $value)) {
            return array(
                'success' => false,
                'message' => __('Invalid LiteSpeed purge header value.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        if (PHP_SAPI === 'cli') {
            return array(
                'success' => false,
                'message' => __('LiteSpeed native purge headers require an HTTP response and cannot be sent from WP-CLI.', 'ultracache'),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        if (headers_sent($file, $line)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    /* translators: 1: PHP file path where headers were sent, 2: line number. */
                    __('LiteSpeed purge header could not be sent because headers were already sent at %1$s:%2$s.', 'ultracache'),
                    (string) $file,
                    (string) $line
                ),
                'method' => 'X-LiteSpeed-Purge response header',
                'transport' => 'native_response_header',
            );
        }

        header('X-LiteSpeed-Purge: ' . $value, false);
        header('X-UltraCache-LiteSpeed-Purge: requested', false);

        return array(
            'success' => true,
            'message' => __('LiteSpeed native purge header queued on this HTTP response.', 'ultracache'),
            'method' => 'X-LiteSpeed-Purge response header',
            'transport' => 'native_response_header',
        );
    }

    private static function dispatch_litespeed_purge_all($status)
    {
        $status = is_array($status) ? $status : array();

        if (!empty($status['officialHookAvailable'])) {
            try {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed Cache interoperability hook.
                do_action('litespeed_purge_all');

                return array(
                    'success' => true,
                    'message' => __('LiteSpeed Cache purge request dispatched through the official WordPress hook.', 'ultracache'),
                    'method' => 'do_action(litespeed_purge_all)',
                    'transport' => 'official_wordpress_hook',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %s: purge error message. */
                        __('LiteSpeed Cache purge hook failed: %s', 'ultracache'),
                        $throwable->getMessage()
                    ),
                    'method' => 'do_action(litespeed_purge_all)',
                    'transport' => 'official_wordpress_hook',
                );
            }
        }

        if (!empty($status['nativeHeaderAvailable'])) {
            return self::send_litespeed_purge_header('*');
        }

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            try {
                $result = @LiteSpeed_Cache_API::purge_all();
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy class API.', 'ultracache')
                        : __('The legacy LiteSpeed class API rejected the purge request.', 'ultracache'),
                    'method' => 'LiteSpeed_Cache_API::purge_all',
                    'transport' => 'legacy_class_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => 'LiteSpeed_Cache_API::purge_all',
                    'transport' => 'legacy_class_api',
                );
            }
        }

        if (class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
            try {
                $result = @call_user_func(array('\LiteSpeed\Purge', 'purge_all'));
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy namespaced API.', 'ultracache')
                        : __('The legacy LiteSpeed namespaced API rejected the purge request.', 'ultracache'),
                    'method' => '\LiteSpeed\Purge::purge_all',
                    'transport' => 'legacy_namespaced_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => '\LiteSpeed\Purge::purge_all',
                    'transport' => 'legacy_namespaced_api',
                );
            }
        }

        if (function_exists('litespeed_purge_all')) {
            try {
                $result = @litespeed_purge_all();
                $success = false !== $result;

                return array(
                    'success' => $success,
                    'message' => $success
                        ? __('LiteSpeed Cache purge request dispatched through the legacy function API.', 'ultracache')
                        : __('The legacy LiteSpeed function API rejected the purge request.', 'ultracache'),
                    'method' => 'litespeed_purge_all',
                    'transport' => 'legacy_function_api',
                );
            } catch (Throwable $throwable) {
                return array(
                    'success' => false,
                    'message' => $throwable->getMessage(),
                    'method' => 'litespeed_purge_all',
                    'transport' => 'legacy_function_api',
                );
            }
        }

        return array(
            'success' => false,
            'message' => __('LiteSpeed Cache purge is not available.', 'ultracache'),
            'method' => 'not_available',
            'transport' => 'unavailable',
        );
    }
}
