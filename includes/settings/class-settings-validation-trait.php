<?php
/**
 * Settings sanitization and validation.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Settings_Validation_Trait
{


    /**
     * Sanitize the registered settings option through the canonical settings map.
     *
     * @param mixed $value Submitted option value.
     * @return array<string,mixed>
     */
    public static function sanitize_registered_dashboard_settings($value)
    {
        if (!is_array($value)) {
            add_settings_error(
                ULTRACACHE_SETTINGS_KEY,
                'ultracache_settings_invalid_payload',
                __('UltraCache settings must be submitted as an array. Safe defaults were used.', 'ultracache'),
                'error'
            );
            return self::get_dashboard_defaults();
        }

        if (
            array_key_exists('sqliteDatabaseSizeMb', $value)
            && !self::validate_sqlite_database_size_mb($value['sqliteDatabaseSizeMb'])
        ) {
            add_settings_error(
                ULTRACACHE_SETTINGS_KEY,
                'ultracache_invalid_sqlite_database_size',
                __('Maximum SQLite database size must be one of the available choices. The default value of 256 MB was used.', 'ultracache'),
                'error'
            );
            $value['sqliteDatabaseSizeMb'] = 256;
        }

        if (
            array_key_exists('mediaStaleWorkerThreshold', $value)
            && !self::validate_media_stale_worker_threshold($value['mediaStaleWorkerThreshold'])
        ) {
            add_settings_error(
                ULTRACACHE_SETTINGS_KEY,
                'ultracache_invalid_media_stale_worker_threshold',
                __('Pause after stale workers must be a whole number greater than zero. The default value of 3 was used.', 'ultracache'),
                'error'
            );
            $value['mediaStaleWorkerThreshold'] = 3;
        }

        return self::sanitize_dashboard_settings($value, false);
    }



    /**
     * Return the WordPress REST schema used by every stale-worker threshold input path.
     *
     * @return array<string,mixed>
     */
    private static function get_media_stale_worker_threshold_schema()
    {
        return array(
            'type'    => 'integer',
            'minimum' => 1,
        );
    }



    /**
     * Validate a stale-worker threshold with the WordPress REST schema validator.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private static function validate_media_stale_worker_threshold($value)
    {
        $validated = rest_validate_value_from_schema(
            $value,
            self::get_media_stale_worker_threshold_schema(),
            'mediaStaleWorkerThreshold'
        );

        return true === $validated;
    }



    /**
     * Sanitize a stale-worker threshold with the WordPress REST schema sanitizer.
     *
     * @param mixed $value   Candidate value.
     * @param int   $default Safe fallback.
     * @return int
     */
    private static function sanitize_media_stale_worker_threshold($value, $default = 3)
    {
        if (!self::validate_media_stale_worker_threshold($value)) {
            return max(1, absint($default));
        }

        $sanitized = rest_sanitize_value_from_schema(
            $value,
            self::get_media_stale_worker_threshold_schema(),
            'mediaStaleWorkerThreshold'
        );

        if (is_wp_error($sanitized)) {
            return max(1, absint($default));
        }

        return max(1, absint($sanitized));
    }



    public static function sanitize_uninstall_cleanup_policy($policy)
    {
        $policy = strtolower(trim((string) $policy));
        $allowed = array(
            'plugin_only',
            'keep_settings',
            'keep_settings_tables',
            'delete_everything',
        );

        return in_array($policy, $allowed, true) ? $policy : 'plugin_only';
    }



    private static function sanitize_cookie_pattern_line($line)
    {
        $line = trim((string) $line);
        if ('' === $line) {
            return '';
        }

        $line = preg_replace('/[\x00-\x1F\x7F]/', '', $line);
        $line = is_string($line) ? trim($line) : '';
        if ('' === $line) {
            return '';
        }

        // Cookie names are case-sensitive in the browser, but matching here
        // is intentionally case-insensitive and pattern-like. Keep only
        // characters valid for cookie names plus '*' as a visible wildcard.
        $line = preg_replace('/[^A-Za-z0-9_\-.\*]/', '', $line);
        $line = is_string($line) ? trim($line) : '';
        if ('' === $line || '*' === $line) {
            return '';
        }

        return $line;
    }



    private static function sanitize_cookie_pattern_setting($value, $limit = 200)
    {
        return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_cookie_pattern_line'), $limit);
    }



    private static function normalize_textarea_setting($value)
    {
        if (is_array($value)) {
            $value = implode("\n", array_map('strval', $value));
        }

        $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
        $lines = array_filter(array_map('trim', explode("\n", $value)), static function ($line) {
            return '' !== $line;
        });

        return implode("\n", array_values(array_unique($lines)));
    }



    private static function merge_textarea_settings($first, $second)
    {
        $lines = array_merge(self::parse_textarea_setting($first), self::parse_textarea_setting($second));
        return self::normalize_textarea_setting($lines);
    }



    private static function is_generic_root_js_safeguard_line($line)
    {
        return in_array(
            strtolower(trim((string) $line)),
            array('woocommerce', 'wordpress', 'frontend', 'main', 'plugin', 'plugins', 'script', 'scripts', 'data', 'params', 'cart', 'checkout', 'account'),
            true
        );
    }



    private static function js_safeguard_lines_overlap($left, $right)
    {
        $left = strtolower(trim((string) $left));
        $right = strtolower(trim((string) $right));
        if ('' === $left || '' === $right) {
            return false;
        }
        if (self::is_generic_root_js_safeguard_line($left) || self::is_generic_root_js_safeguard_line($right)) {
            return $left === $right;
        }
        return $left === $right || false !== strpos($right, $left) || false !== strpos($left, $right);
    }



    private static function remove_overlapping_js_safeguard_lines($value, $winning_value)
    {
        $lines = self::parse_textarea_setting($value);
        $winning_lines = self::parse_textarea_setting($winning_value);
        if (empty($lines) || empty($winning_lines)) {
            return self::normalize_textarea_setting($lines);
        }

        $kept = array();
        foreach ($lines as $line) {
            $overlaps = false;
            foreach ($winning_lines as $winning_line) {
                if (self::js_safeguard_lines_overlap($line, $winning_line)) {
                    $overlaps = true;
                    break;
                }
            }
            if (!$overlaps) {
                $kept[] = $line;
            }
        }

        return self::normalize_textarea_setting($kept);
    }



    private static function normalize_multiline_setting_with_callback($value, callable $callback, $limit = 200)
    {
        $lines = self::parse_textarea_setting($value);
        $normalized = array();

        foreach ($lines as $line) {
            $sanitized = call_user_func($callback, $line);
            if (!is_string($sanitized) || '' === $sanitized) {
                continue;
            }

            $normalized[$sanitized] = $sanitized;
            if (count($normalized) >= max(1, absint($limit))) {
                break;
            }
        }

        return implode("\n", array_values($normalized));
    }



    private static function get_reserved_setting_keys()
    {
        return array(
            '__proto__',
            'constructor',
            'prototype',
        );
    }



    private static function sanitize_setting_key_line($value)
    {
        $value = strtolower(trim((string) $value));
        if ('' === $value) {
            return '';
        }

        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
            return '';
        }

        if (in_array($value, self::get_reserved_setting_keys(), true)) {
            return '';
        }

        return $value;
    }



    private static function sanitize_setting_key_list($value, $limit = 200)
    {
        return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_setting_key_line'), $limit);
    }



    private static function sanitize_excluded_path_line($value)
    {
        $rule = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
        if ('' === $rule) {
            return '';
        }

        $rule = str_replace('\\', '/', $rule);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rule)) {
            return '';
        }

        if (false !== strpos($rule, '?') || false !== strpos($rule, '#')) {
            return '';
        }

        if (preg_match('/[[:cntrl:]\s]/u', $rule)) {
            return '';
        }

        if ('/' !== substr($rule, 0, 1)) {
            return '';
        }

        if ('/' === $rule) {
            return '';
        }

        if (false !== strpos($rule, '//')) {
            return '';
        }

        $wildcard = false;
        if (substr($rule, -2) === '/*') {
            $wildcard = true;
            $rule = substr($rule, 0, -2);
        } elseif (false !== strpos($rule, '*')) {
            return '';
        }

        if ('' === $rule || '/' === $rule) {
            return '';
        }

        foreach (explode('/', trim($rule, '/')) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return '';
            }
        }

        if ($wildcard) {
            $rule = rtrim($rule, '/') . '/*';
        }

        return $rule;
    }



    private static function sanitize_excluded_paths_setting($value, $limit = 200)
    {
        return self::normalize_multiline_setting_with_callback($value, array(__CLASS__, 'sanitize_excluded_path_line'), $limit);
    }



    private static function sanitize_positive_integer_setting($value, $default, $min = 1)
    {
        $default = max((int) $min, (int) $default);
        $min = max(0, (int) $min);

        if (is_string($value)) {
            $value = trim($value);
            if ('' === $value || !preg_match('/^\d+$/', $value)) {
                return $default;
            }
            $value = (int) $value;
        } elseif (is_int($value)) {
            $value = (int) $value;
        } elseif (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        } else {
            return $default;
        }

        return max($min, $value);
    }



    private static function sanitize_bounded_integer_setting($value, $default, $min, $max)
    {
        $default = (int) $default;
        $min = (int) $min;
        $max = (int) $max;

        if ($min > $max) {
            $swap = $min;
            $min = $max;
            $max = $swap;
        }

        if ($default < $min || $default > $max) {
            $default = max($min, min($max, $default));
        }

        if (is_string($value)) {
            $value = trim($value);
            if ('' === $value || !preg_match('/^\d+$/', $value)) {
                return $default;
            }
            $value = (int) $value;
        } elseif (is_int($value)) {
            $value = (int) $value;
        } else {
            return $default;
        }

        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }



    private static function sanitize_bounded_number_setting($value, $default, $min, $max)
    {
        $default = (float) $default;
        $min = (float) $min;
        $max = (float) $max;

        if ($min > $max) {
            $swap = $min;
            $min = $max;
            $max = $swap;
        }

        if ($default < $min || $default > $max) {
            $default = max($min, min($max, $default));
        }

        if (is_string($value)) {
            $value = trim($value);
            if ('' === $value || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
                return $default;
            }
            $value = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $value = (float) $value;
        } else {
            return $default;
        }

        if ($value < $min || $value > $max) {
            return $default;
        }

        return (float) rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }



    private static function parse_textarea_setting($value)
    {
        $normalized = self::normalize_textarea_setting($value);
        if ('' === $normalized) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('trim', explode("\n", $normalized)))));
    }



    private static function manual_lcp_selector_line_is_image($line)
    {
        $line = trim((string) $line);
        if ('' === $line) {
            return false;
        }

        if (preg_match('/^image\s+\S+/i', $line)) {
            return true;
        }

        if (preg_match('#^(?:https?:)?//#i', $line) || preg_match('#^/#', $line)) {
            return true;
        }

        return (bool) preg_match('/\.(?:avif|webp|png|jpe?g|gif|svg)(?:[?#].*)?$/i', $line);
    }



    private static function normalize_manual_lcp_image_entry($line)
    {
        $line = trim((string) $line);
        if (preg_match('/^image\s+(.+)$/i', $line, $matches)) {
            $line = trim((string) $matches[1]);
        }

        return $line;
    }



    private static function split_manual_lcp_selector_setting($value)
    {
        $lines = self::parse_textarea_setting($value);
        $selectors = array();
        $images = array();

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }

            if (self::manual_lcp_selector_line_is_image($line)) {
                $image = self::normalize_manual_lcp_image_entry($line);
                if ('' !== $image) {
                    $images[$image] = $image;
                }
                continue;
            }

            $selectors[$line] = $line;
        }

        return array(
            'selectors' => array_values($selectors),
            'images'    => array_values($images),
        );
    }




    private static function sanitize_object_cache_backend($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('redis', 'apcu', 'sqlite', 'disk'), true) ? $value : 'redis';
    }



    private static function sanitize_object_cache_fallback_backend($value)
    {
        $value = strtolower(trim((string) $value));
        if ('none' === $value || 'runtime' === $value || '' === $value) {
            return 'none';
        }
        return in_array($value, array('apcu', 'sqlite', 'disk'), true) ? $value : 'apcu';
    }



    private static function validate_sqlite_database_size_mb($value)
    {
        return in_array(absint($value), array(32, 64, 128, 256, 512, 1024, 2048), true);
    }



    private static function sanitize_sqlite_database_size_mb($value, $default = 256)
    {
        $default = self::validate_sqlite_database_size_mb($default) ? absint($default) : 256;
        return self::validate_sqlite_database_size_mb($value) ? absint($value) : $default;
    }



    private static function sanitize_homepage_css_bundle_mode($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('safe', 'aggressive', 'full'), true) ? $value : 'safe';
    }



    private static function sanitize_css_bundle_scope($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('homepage', 'shared', 'per-page'), true) ? $value : 'homepage';
    }



    private static function sanitize_redis_host($value)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return '127.0.0.1';
        }

        $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
        $value = trim((string) $value);
        if ('' === $value) {
            return '127.0.0.1';
        }

        if (strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }

        return $value;
    }



    private static function sanitize_redis_username($value)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
        $value = trim((string) $value);
        if (strlen($value) > 128) {
            $value = substr($value, 0, 128);
        }

        return sanitize_text_field($value);
    }



    private static function sanitize_redis_database($value)
    {
        return self::sanitize_bounded_integer_setting($value, 0, 0, 15);
    }



    private static function sanitize_redis_prefix($value)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9:_\-]/', '', $value);
        $value = trim((string) $value, ':');

        return '' === $value ? '' : $value . ':';
    }



    private static function sanitize_varnish_mode($value)
    {
        return ('admin' === strtolower(trim((string) $value))) ? 'admin' : 'http';
    }



    private static function sanitize_varnish_invalidation_strategy($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('auto', 'ban', 'purge', 'soft'), true) ? $value : 'ban';
    }

    private static function sanitize_varnish_flush_scope($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('auto', 'html', 'host'), true) ? $value : 'auto';
    }



    private static function normalize_varnish_compare_host($host)
    {
        $host = strtolower(trim((string) $host));
        $host = trim($host, "[] \t\n\r\0\x0B.");
        if (0 === strpos($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }



    private static function get_varnish_site_host_candidates()
    {
        $hosts = array();
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($home_host) {
            $hosts[] = self::normalize_varnish_compare_host($home_host);
        }

        foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'LOCAL_ADDR') as $key) {
            $server_value = function_exists('ultracache_server_value') ? ultracache_server_value($key) : '';
            if ('' !== $server_value) {
                $hosts[] = self::normalize_varnish_compare_host(sanitize_text_field($server_value));
            }
        }

        $expanded = array();
        foreach ($hosts as $host) {
            if ('' === $host) {
                continue;
            }
            $expanded[] = $host;
            $expanded[] = 'www.' . $host;
        }

        return array_values(array_unique(array_filter($expanded)));
    }



    private static function get_varnish_http_endpoint_block_message($host, $port)
    {
        $host = self::normalize_varnish_compare_host($host);
        $port = (int) $port;

        if ('' === $host || $port <= 0) {
            return self::maybe_translate('Invalid Varnish HTTP endpoint. Use host:port, for example 127.0.0.1:82.');
        }

        if (in_array($host, self::get_varnish_site_host_candidates(), true) && in_array($port, array(80, 443, 8443), true)) {
            return self::maybe_translate_sprintf('Blocked unsafe Varnish endpoint %1$s:%2$d because it points to the public WordPress frontend. Use a configured Varnish listener such as 127.0.0.1:82, varnish.internal:82, or Admin mode such as 127.0.0.1:6082.', $host, $port);
        }

        return '';
    }



    private static function validate_varnish_http_endpoint($terminal)
    {
        $terminal = trim((string) $terminal);
        if ('' === $terminal) {
            return array('valid' => false, 'message' => self::maybe_translate('Empty Varnish HTTP endpoint. Use host:port or an explicit http(s) URL, for example 127.0.0.1:82 or https://varnish.example.com:443.'));
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $terminal, $matches)) {
            $explicit_scheme = strtolower((string) $matches[1]);
            if (!in_array($explicit_scheme, array('http', 'https'), true)) {
                return array('valid' => false, 'message' => self::maybe_translate('Varnish HTTP endpoints must use http:// or https://.'));
            }
        }

        list($scheme, $host, $port) = self::parse_varnish_http_terminal($terminal);
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host || $port <= 0) {
            return array('valid' => false, 'message' => self::maybe_translate('Invalid Varnish HTTP endpoint. Use host:port or an explicit http(s) URL.'));
        }
        $message = self::get_varnish_http_endpoint_block_message($host, $port);
        if ('' !== $message) {
            return array('valid' => false, 'message' => $message, 'scheme' => $scheme, 'host' => $host, 'port' => $port);
        }

        return array('valid' => true, 'message' => '', 'scheme' => $scheme, 'host' => $host, 'port' => $port);
    }



    private static function validate_varnish_settings(array $settings)
    {
        if (!self::is_varnish_runtime_enabled($settings)) {
            return true;
        }

        $mode = self::sanitize_varnish_mode($settings['varnishCliMode'] ?? 'http');
        if ('http' !== $mode) {
            return true;
        }

        $diagnostics = self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'] ?? '', $mode);
        if (!empty($diagnostics['unsafe'])) {
            $message = !empty($diagnostics['messages'][0]) ? (string) $diagnostics['messages'][0] : self::maybe_translate('Unsafe Varnish HTTP endpoint blocked.');
            return new WP_Error('ultracache_unsafe_varnish_http_endpoint', $message);
        }

        return true;
    }



    private static function sanitize_varnish_servers_string($value, $mode = 'http')
    {
        $mode = self::sanitize_varnish_mode($mode);

        if (is_array($value)) {
            $value = implode("
", array_map('strval', $value));
        }

        $value = str_replace(array("
", "
", ",", ";", "	"), array("
", "
", "
", "
", "
"), (string) $value);
        $servers = preg_split('/\s+/', $value);
        if (!is_array($servers)) {
            return '';
        }

        $normalized = array();
        foreach ($servers as $server) {
            $server = trim((string) $server);
            if ('' === $server) {
                continue;
            }

            $scheme = '';
            if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $server, $matches)) {
                $scheme = strtolower((string) $matches[1]);
                $server = substr($server, strlen((string) $matches[0]));
            }

            $server = preg_replace('~[/?#].*$~', '', $server);
            $server = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $server);
            if ('' === $server) {
                continue;
            }

            if ('http' === $mode && '' !== $scheme) {
                $normalized[] = $scheme . '://' . $server;
            } else {
                $normalized[] = $server;
            }
        }

        if (empty($normalized)) {
            $normalized[] = ('admin' === $mode) ? self::get_default_varnish_admin_endpoint() : self::get_default_varnish_http_endpoint();
        }

        return implode("
", array_values(array_unique($normalized)));
    }




    private static function sanitize_media_output_mode($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('avif', 'webp'), true) ? $value : 'webp';
    }



    private static function sanitize_media_fallback_format($value, $output_mode = 'avif')
    {
        $output_mode = self::sanitize_media_output_mode($output_mode);
        $value = strtolower(trim((string) $value));
        if (in_array($value, array('jpeg/png', 'jpeg_png', 'jpeg-png', 'jpg/png', 'jpg_png', 'jpg-png', 'jpeg', 'jpg', 'png', 'original'), true)) {
            $value = 'original';
        }

        if ('avif' === $output_mode && 'webp' === $value) {
            return 'webp';
        }

        return 'original';
    }



    private static function sanitize_media_quality($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('original', 'high', 'balanced', 'compact', 'smallest'), true) ? $value : 'balanced';
    }



    public static function sanitize_javascript_strategy($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('off', 'defer', 'delay'), true) ? $value : 'off';
    }



    private static function sanitize_lcp_frontend_discovery_duration($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1_hour', '4_hours', '8_hours', '1_day', '3_days', '1_week', 'indefinitely'), true)
            ? $value
            : 'indefinitely';
    }



    private static function sanitize_woocommerce_cart_fragments_delay_timing($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('delayed-js', '0.5', '1', '2', '3', '5'), true) ? $value : 'delayed-js';
    }



    private static function normalize_boolean_setting_value($value, $default = false)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return 0 !== (int) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ('' === $normalized) {
                return (bool) $default;
            }

            if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
                return true;
            }

            if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
                return false;
            }
        }

        if (null === $value) {
            return (bool) $default;
        }

        return !empty($value);
    }


    /**
     * Keep the two server-level HTML delivery modes mutually exclusive.
     *
     * A patch that explicitly enables native LiteSpeed HTML Cache disables
     * Apache Static HTML Delivery. A patch that enables Apache Static HTML
     * Delivery disables native LiteSpeed HTML Cache. When both are explicitly
     * enabled, native LiteSpeed takes precedence.
     *
     * @param array $settings Settings patch or complete settings payload.
     * @return array
     */
    public static function normalize_page_delivery_mode_patch(array $settings)
    {
        $has_litespeed = array_key_exists('liteSpeedCacheEnabled', $settings);
        $has_apache_static = array_key_exists('apacheStaticHtmlDeliveryEnabled', $settings);
        $litespeed_enabled = $has_litespeed
            && self::normalize_boolean_setting_value($settings['liteSpeedCacheEnabled'], false);
        $apache_static_enabled = $has_apache_static
            && self::normalize_boolean_setting_value($settings['apacheStaticHtmlDeliveryEnabled'], false);

        if ($litespeed_enabled) {
            $settings['liteSpeedCacheEnabled'] = true;
            $settings['apacheStaticHtmlDeliveryEnabled'] = false;
        } elseif ($apache_static_enabled) {
            $settings['apacheStaticHtmlDeliveryEnabled'] = true;
            $settings['liteSpeedCacheEnabled'] = false;
        }

        return $settings;
    }


    private static function sanitize_local_url_textarea_setting($value, $limit = 25)
    {
        $limit = max(1, min(100, absint($limit)));
        $lines = preg_split('/[\r\n,]+/', (string) $value);
        $urls = array();
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }
            if ('/' === substr($line, 0, 1)) {
                $line = home_url($line);
            }
            $line = preg_replace('/#.*$/', '', $line);
            $url = esc_url_raw((string) $line, array('http', 'https'));
            if ('' === $url) {
                continue;
            }

            $trusted = function_exists('ultracache_is_trusted_loopback_url')
                ? ultracache_is_trusted_loopback_url($url)
                : ('' !== $home_host && hash_equals($home_host, strtolower((string) wp_parse_url($url, PHP_URL_HOST))));
            if (!$trusted) {
                continue;
            }

            $urls[$url] = $url;
            if (count($urls) >= $limit) {
                break;
            }
        }

        return implode("\n", array_values($urls));
    }



    private static function migrate_legacy_cron_warm_master_setting(array $settings)
    {
        // The removed Cron Warm Up master switch is accepted only as a legacy
        // migration input. Existing automatic triggers remain enabled only when
        // both the old master and the corresponding trigger were enabled.
        if (!array_key_exists('cronWarmEnabled', $settings)) {
            return $settings;
        }

        $legacy_cron_warm_enabled = self::normalize_boolean_setting_value(
            $settings['cronWarmEnabled'],
            false
        );
        $settings['cronWarmStartAfterCleanup'] = $legacy_cron_warm_enabled
            && self::normalize_boolean_setting_value($settings['cronWarmStartAfterCleanup'] ?? false, false);
        $settings['cronWarmStartAfterManualPurge'] = $legacy_cron_warm_enabled
            && self::normalize_boolean_setting_value($settings['cronWarmStartAfterManualPurge'] ?? false, false);
        unset($settings['cronWarmEnabled']);

        return $settings;
    }


    /**
     * Initialize the new upload and replacement format contracts from the
     * existing rewrite format when an installation has not saved them yet.
     *
     * @param array $settings Raw dashboard settings.
     * @return array
     */
    private static function migrate_split_media_format_settings(array $settings)
    {
        $legacy_output_format = self::sanitize_media_output_mode($settings['mediaOutputMode'] ?? 'webp');

        if (!array_key_exists('mediaUploadFormat', $settings)) {
            $settings['mediaUploadFormat'] = $legacy_output_format;
        }

        if (!array_key_exists('mediaReplacementFormat', $settings)) {
            $settings['mediaReplacementFormat'] = $legacy_output_format;
        }

        return $settings;
    }



    /**
     * Remove the legacy Scroll delayed-JS release trigger.
     *
     * Existing installations/imports that had Scroll enabled are migrated to
     * the four explicit interaction triggers that remain supported. The legacy
     * key is retained as accepted input for backward-compatible imports, but
     * canonical settings always persist it disabled.
     *
     * @param array $settings Raw dashboard settings.
     * @return array
     */
    private static function migrate_removed_delayed_js_scroll_trigger_setting(array $settings)
    {
        if (!array_key_exists('delayedJsAutostartScrollEnabled', $settings)) {
            return $settings;
        }

        $scroll_enabled = self::normalize_boolean_setting_value(
            $settings['delayedJsAutostartScrollEnabled'],
            false
        );
        $settings['delayedJsAutostartScrollEnabled'] = false;

        if ($scroll_enabled) {
            $settings['delayedJsAutostartMousemoveEnabled'] = true;
            $settings['delayedJsAutostartKeyboardEnabled'] = true;
            $settings['delayedJsAutostartTouchPointerEnabled'] = true;
            $settings['delayedJsAutostartClickEnabled'] = true;
        }

        return $settings;
    }

    /**
     * Restore the explicit Varnish enable switch without changing the behavior
     * of installations that used the connection marker as the runtime gate.
     *
     * @param array $settings Raw dashboard settings.
     * @return array
     */
    private static function migrate_legacy_varnish_enable_setting(array $settings)
    {
        $varnish_enabled_was_saved = array_key_exists('varnishCliEnabled', $settings);
        $legacy_varnish_configured = self::normalize_boolean_setting_value(
            $settings['varnishConnectionConfigured'] ?? false,
            false
        ) || self::normalize_boolean_setting_value(
            $settings['sharedCacheDeliveryEnabled'] ?? false,
            false
        );

        $settings['varnishCliEnabled'] = $varnish_enabled_was_saved
            ? self::normalize_boolean_setting_value($settings['varnishCliEnabled'], false)
            : $legacy_varnish_configured;
        $settings['varnishConnectionConfigured'] = $legacy_varnish_configured;
        unset($settings['sharedCacheDeliveryEnabled'], $settings['esiEnabled']);

        return $settings;
    }


    public static function sanitize_dashboard_settings(array $settings, $validate_support = true)
    {
        $raw_settings = $settings;
        $settings = self::migrate_split_media_format_settings($settings);
        $settings = self::migrate_legacy_cron_warm_master_setting($settings);
        $settings = self::migrate_removed_delayed_js_scroll_trigger_setting($settings);

        $settings = self::migrate_legacy_varnish_enable_setting($settings);
        $defaults = self::get_dashboard_defaults();
        $settings = wp_parse_args($settings, $defaults);

        // Canonicalize every boolean dashboard setting before any runtime
        // mapping or !empty() checks. This prevents imported/CLI/direct
        // option values such as "false", "0", "off", or null from
        // being treated as enabled.
        foreach ($defaults as $setting_key => $default_value) {
            if (is_bool($default_value)) {
                $settings[$setting_key] = self::normalize_boolean_setting_value(
                    $settings[$setting_key] ?? $default_value,
                    $default_value
                );
            }
        }

        // Native LiteSpeed page caching and Apache Static HTML Delivery are
        // alternative server-level HTML delivery modes. Runtime consumers,
        // imports, profiles, and legacy saved options must never see both on.
        $settings = self::normalize_page_delivery_mode_patch($settings);
        // Broad icon-font auto-detection is no longer a hidden runtime rule.
        // The scanner can append discovered patterns to the visible font list.
        $settings['delayIconFontsAutoDetectEnabled'] = false;

        // JavaScript Strategy is the canonical UI model for the two base
        // engine booleans. The other local/third-party/LCP delay controls
        // remain independent and are intentionally not changed here.
        $settings['javascriptStrategy'] = self::sanitize_javascript_strategy($settings['javascriptStrategy'] ?? $defaults['javascriptStrategy']);
        $settings['deferJsEnabled'] = ('defer' === $settings['javascriptStrategy']);
        $settings['delayAllJsEnabled'] = ('delay' === $settings['javascriptStrategy']);

        $settings['cronWarmPagesPerMinute']    = max(0, min(600, absint($settings['cronWarmPagesPerMinute'])));
        $settings['warmMenuLocation']          = sanitize_key((string) $settings['warmMenuLocation']);
        $settings['warmMenuDepth']             = in_array((string) $settings['warmMenuDepth'], array('1', '2', '3', 'all'), true) ? (string) $settings['warmMenuDepth'] : '';
        $warm_full_site_sources = preg_split('/[\r\n,]+/', (string) $settings['warmFullSiteSources']);
        $warm_full_site_allowed = self::get_full_site_warm_source_order_keys();
        $warm_full_site_requested = array();
        foreach ((array) $warm_full_site_sources as $warm_full_site_source) {
            $warm_full_site_source = sanitize_key((string) $warm_full_site_source);
            if ('' !== $warm_full_site_source && in_array($warm_full_site_source, $warm_full_site_allowed, true)) {
                $warm_full_site_requested[$warm_full_site_source] = true;
            }
        }
        $warm_full_site_clean = array();
        foreach ($warm_full_site_allowed as $warm_full_site_source) {
            if (isset($warm_full_site_requested[$warm_full_site_source])) {
                $warm_full_site_clean[$warm_full_site_source] = true;
            }
        }
        $settings['warmFullSiteSources']       = implode(',', array_keys($warm_full_site_clean));
        $settings['scheduledWarmLimit']        = max(1, min(5000, absint($settings['scheduledWarmLimit'])));
        $settings['varnishCliTimeoutSeconds']  = max(1, min(15, absint($settings['varnishCliTimeoutSeconds'])));
        $settings['varnishInvalidationsPerMinute'] = max(1, min(600, absint($settings['varnishInvalidationsPerMinute'])));
        $settings['cacheFreshTtlMinutes']      = self::sanitize_bounded_integer_setting($settings['cacheFreshTtlMinutes'], $defaults['cacheFreshTtlMinutes'], 1, 525600);
        $settings['cacheMaxStaleMinutes']      = max((int) $settings['cacheFreshTtlMinutes'], self::sanitize_bounded_integer_setting($settings['cacheMaxStaleMinutes'], $defaults['cacheMaxStaleMinutes'], 1, 525600));
        $settings['cacheExceptionPaths']       = self::sanitize_excluded_paths_setting($settings['cacheExceptionPaths']);
        $settings['cacheExceptionQueryArgs']   = self::sanitize_setting_key_list($settings['cacheExceptionQueryArgs']);
        $settings['cacheQueryStringAllowlist'] = self::sanitize_setting_key_list($settings['cacheQueryStringAllowlist']);
        $settings['cacheQueryCombinationLevel'] = in_array((string) ($settings['cacheQueryCombinationLevel'] ?? $defaults['cacheQueryCombinationLevel']), array('1', '2', '3', '4', 'all'), true)
            ? (string) ($settings['cacheQueryCombinationLevel'] ?? $defaults['cacheQueryCombinationLevel'])
            : (string) $defaults['cacheQueryCombinationLevel'];
        $settings['cacheSafeTrackingCookiesEnabled'] = self::normalize_boolean_setting_value($settings['cacheSafeTrackingCookiesEnabled'] ?? $defaults['cacheSafeTrackingCookiesEnabled'], $defaults['cacheSafeTrackingCookiesEnabled']);
        $settings['safeTrackingCookieList']    = self::sanitize_cookie_pattern_setting($settings['safeTrackingCookieList']);
        $settings['unsafeCacheCookieList']     = self::sanitize_cookie_pattern_setting($settings['unsafeCacheCookieList']);
        $settings['delayedLocalJsAutoStart'] = in_array((string) ($settings['delayedLocalJsAutoStart'] ?? $defaults['delayedLocalJsAutoStart']), array('interaction', 'custom', 'infinite'), true) ? (string) $settings['delayedLocalJsAutoStart'] : $defaults['delayedLocalJsAutoStart'];
        $settings['delayedLocalJsAutoStartSeconds'] = self::sanitize_bounded_number_setting($settings['delayedLocalJsAutoStartSeconds'] ?? $defaults['delayedLocalJsAutoStartSeconds'], $defaults['delayedLocalJsAutoStartSeconds'], 0.05, 5);
        $settings['delayedJsMinimumReleaseSeconds'] = self::sanitize_bounded_integer_setting($settings['delayedJsMinimumReleaseSeconds'] ?? $defaults['delayedJsMinimumReleaseSeconds'], $defaults['delayedJsMinimumReleaseSeconds'], 0, 4);
        $delayed_js_trigger_keys = array('delayedJsAutostartAfterLoadEnabled', 'delayedJsAutostartMousemoveEnabled', 'delayedJsAutostartClickEnabled', 'delayedJsAutostartTouchPointerEnabled', 'delayedJsAutostartKeyboardEnabled');
        $has_delayed_js_trigger = false;
        foreach ($delayed_js_trigger_keys as $delayed_js_trigger_key) {
            $settings[$delayed_js_trigger_key] = !empty($settings[$delayed_js_trigger_key]);
            $has_delayed_js_trigger = $has_delayed_js_trigger || $settings[$delayed_js_trigger_key];
        }
        $settings['delayedJsAutostartScrollEnabled'] = false;
        if ('infinite' === $settings['delayedLocalJsAutoStart'] && !$has_delayed_js_trigger) {
            $settings['delayedJsAutostartMousemoveEnabled'] = true;
            $settings['delayedJsAutostartClickEnabled'] = true;
            $settings['delayedJsAutostartTouchPointerEnabled'] = true;
            $settings['delayedJsAutostartKeyboardEnabled'] = true;
        }
        $settings['firstPartyJsParallelExecutionEnabled'] = !empty($settings['firstPartyJsParallelExecutionEnabled']);
        $settings['thirdPartyJsParallelExecutionEnabled'] = !empty($settings['thirdPartyJsParallelExecutionEnabled']);
        $settings['realCookieBannerCompatibilityEnabled'] = !empty($settings['realCookieBannerCompatibilityEnabled']);
        $settings['complianzCompatibilityEnabled'] = !empty($settings['complianzCompatibilityEnabled']);
        $settings['deferJsForceList']         = self::normalize_textarea_setting($settings['deferJsForceList']);
        $settings['deferJsExcludeList']       = self::merge_textarea_settings($settings['deferJsExcludeList'], $settings['delayNonCriticalJsExcludeList']);
        // Existing installs keep their saved visible JS Delay / Defer Exclusions.
        // Fresh installs receive the visible jQuery safety default in the
        // visible textarea; there are still no hidden safe-stage exclusions.
        $settings['deferJsExcludeList'] = self::normalize_textarea_setting($settings['deferJsExcludeList']);
        $settings['deferJsForceList'] = self::remove_overlapping_js_safeguard_lines($settings['deferJsForceList'], $settings['deferJsExcludeList']);
        $settings['delayNonCriticalJsExcludeList'] = '';
        $settings['delaySafeThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delaySafeThirdPartyJsPatterns']);
        $settings['delayFunctionalThirdPartyJsPatterns'] = self::normalize_textarea_setting($settings['delayFunctionalThirdPartyJsPatterns']);
        $settings['homepageCssBundleExcludeList'] = self::normalize_textarea_setting($settings['homepageCssBundleExcludeList']);
        $settings['delayIconFontsList'] = self::normalize_textarea_setting($settings['delayIconFontsList']);
        $settings['delayIconFontsExcludeList'] = self::normalize_textarea_setting($settings['delayIconFontsExcludeList']);
        $settings['homepageCssBundleMode'] = self::sanitize_homepage_css_bundle_mode($settings['homepageCssBundleMode']);
        $settings['cssBundleScope'] = self::sanitize_css_bundle_scope($settings['cssBundleScope'] ?? 'homepage');
        if (!empty($settings['pageAsyncBundleOnEntryEnabled'])) {
            $settings['pageCssBundleOnEntryEnabled'] = false;
        }
        $settings['asyncCssExcludeList']       = self::normalize_textarea_setting($settings['asyncCssExcludeList']);
        $settings['asyncExternalCssExcludeList'] = self::normalize_textarea_setting($settings['asyncExternalCssExcludeList'] ?? '');
        $settings['delayNonCriticalJsExcludeList'] = self::normalize_textarea_setting($settings['delayNonCriticalJsExcludeList']);
        $settings['assetCleanupExcludeList'] = self::normalize_textarea_setting($settings['assetCleanupExcludeList']);
        $settings['woocommerceCartFragmentsDelayTiming'] = self::sanitize_woocommerce_cart_fragments_delay_timing($settings['woocommerceCartFragmentsDelayTiming'] ?? $defaults['woocommerceCartFragmentsDelayTiming']);
        $settings['lcpFrontendDiscoveryDuration'] = self::sanitize_lcp_frontend_discovery_duration($settings['lcpFrontendDiscoveryDuration'] ?? $defaults['lcpFrontendDiscoveryDuration']);
        $settings['lcpFrontendDiscoveryStartedAt'] = absint($settings['lcpFrontendDiscoveryStartedAt'] ?? 0);
        $settings['lcpFrontendDiscoveryExpiresAt'] = absint($settings['lcpFrontendDiscoveryExpiresAt'] ?? 0);
        if (!empty($settings['woocommerceCartFragmentsSuppressEmptyEnabled'])) {
            $settings['woocommerceCartFragmentsDelayEnabled'] = false;
        }
        $settings['googleFontsAdditionalScanUrls'] = self::normalize_textarea_setting($settings['googleFontsAdditionalScanUrls']);
        $settings['manualLcpHeroSelector'] = self::normalize_textarea_setting($settings['manualLcpHeroSelector']);
        unset($settings['lcpImagePriorityOverride']);
        $settings['criticalResourcePreloadList'] = self::normalize_textarea_setting($settings['criticalResourcePreloadList']);
        $settings['criticalRequestChainDelayList'] = self::normalize_textarea_setting($settings['criticalRequestChainDelayList']);
        $settings['mediaUploadConversionEnabled'] = !empty($settings['mediaUploadConversionEnabled']);
        $settings['mediaStaleWorkerThreshold'] = self::sanitize_media_stale_worker_threshold(
            $settings['mediaStaleWorkerThreshold'] ?? $defaults['mediaStaleWorkerThreshold'],
            $defaults['mediaStaleWorkerThreshold']
        );
        $settings['imageUploadMaxSide'] = self::sanitize_bounded_integer_setting(
            $settings['imageUploadMaxSide'] ?? $defaults['imageUploadMaxSide'],
            $defaults['imageUploadMaxSide'],
            1,
            8192
        );
        $settings['mediaIgnoreColorProfilePreservation'] = !empty($settings['mediaIgnoreColorProfilePreservation']);
        $settings['mediaUploadFormat']         = self::sanitize_media_output_mode($settings['mediaUploadFormat'] ?? $defaults['mediaUploadFormat']);
        $raw_media_output_mode = strtolower(trim((string) ($raw_settings['mediaOutputMode'] ?? $settings['mediaOutputMode'] ?? $defaults['mediaOutputMode'])));
        $settings['mediaOutputMode']           = self::sanitize_media_output_mode($settings['mediaOutputMode']);
        if (!array_key_exists('mediaFallbackFormat', $raw_settings) && 'avif' === $raw_media_output_mode) {
            $settings['mediaFallbackFormat'] = 'webp';
        }
        $settings['mediaFallbackFormat']       = self::sanitize_media_fallback_format($settings['mediaFallbackFormat'] ?? $defaults['mediaFallbackFormat'], $settings['mediaOutputMode']);
        $settings['mediaReplacementFormat']    = self::sanitize_media_output_mode($settings['mediaReplacementFormat'] ?? $defaults['mediaReplacementFormat']);
        $settings['mediaQuality']              = self::sanitize_media_quality($settings['mediaQuality'] ?? $defaults['mediaQuality']);
        $settings['objectCacheBackend']        = self::sanitize_object_cache_backend($settings['objectCacheBackend']);
        if ('apcu' === $settings['objectCacheBackend']) {
            $settings['flushAllIncludeApcu'] = true;
        }
        $settings['flushAllIncludeElementor'] = !empty($settings['flushAllIncludeElementor']);
        $settings['objectCacheFallbackBackend'] = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
        $settings['sqliteDatabaseSizeMb']       = self::sanitize_sqlite_database_size_mb($settings['sqliteDatabaseSizeMb'] ?? $defaults['sqliteDatabaseSizeMb'], $defaults['sqliteDatabaseSizeMb']);
        $settings['redisHost']                 = self::sanitize_redis_host($settings['redisHost']);
        $settings['redisPort']                 = self::sanitize_bounded_integer_setting($settings['redisPort'], $defaults['redisPort'], 1, 65535);
        $settings['redisUsername']             = self::sanitize_redis_username($settings['redisUsername'] ?? '');
        $settings['redisPassword']             = '';
        $settings['redisDatabase']             = self::sanitize_redis_database($settings['redisDatabase']);
        $settings['redisPrefix']               = self::sanitize_redis_prefix($settings['redisPrefix']);
        $settings['redisUseTls']               = !empty($settings['redisUseTls']);
        $settings['redisPersistent']           = !empty($settings['redisPersistent']);
        $settings['redisConnectTimeoutMs']     = self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], $defaults['redisConnectTimeoutMs'], 50, 15000);
        $settings['redisReadTimeoutMs']        = self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], $defaults['redisReadTimeoutMs'], 50, 15000);
        $settings['varnishCliEnabled']          = !empty($settings['varnishCliEnabled']);
        $settings['varnishConnectionConfigured'] = self::is_varnish_connection_configured($settings);
        $settings['varnishCliMode']            = self::sanitize_varnish_mode($settings['varnishCliMode']);
        $settings['varnishCliServers']         = self::sanitize_varnish_servers_string($settings['varnishCliServers'], $settings['varnishCliMode']);
        $settings['varnishCliKey']             = '';
        $settings['varnishCliMethod']          = ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN';
        if (!array_key_exists('varnishInvalidationStrategy', $raw_settings)) {
            $settings['varnishInvalidationStrategy'] = ('PURGE' === $settings['varnishCliMethod']) ? 'purge' : 'ban';
        } else {
            $settings['varnishInvalidationStrategy'] = self::sanitize_varnish_invalidation_strategy($settings['varnishInvalidationStrategy']);
        }
        $settings['varnishFlushScope']         = self::sanitize_varnish_flush_scope($settings['varnishFlushScope'] ?? $defaults['varnishFlushScope']);

        unset($settings['frontendSafeModeEnabled']);

        if ($validate_support) {
            ultracache_request_profile_checkpoint('sanitize_settings_support_checks_not_mutating_values');
        } else {
            ultracache_request_profile_checkpoint('sanitize_settings_support_checks_skipped_runtime');
        }

        $settings['cronWarmStartAfterCleanup'] = !empty($settings['cronWarmStartAfterCleanup']);
        $settings['cronWarmStartAfterManualPurge'] = !empty($settings['cronWarmStartAfterManualPurge']);
        $settings['warmUncachedUrlsOnFirstVisit'] = !empty($settings['warmUncachedUrlsOnFirstVisit']);
        $settings['warmCssBundlesEnabled'] = !empty($settings['warmCssBundlesEnabled']);
        $settings['alsoWarmTranslationPagesEnabled'] = !empty($settings['alsoWarmTranslationPagesEnabled']);
        $incoming_multilingual_policy = isset($settings['multilingualWarmPolicyV1']) && is_array($settings['multilingualWarmPolicyV1'])
            ? $settings['multilingualWarmPolicyV1']
            : array();
        if (function_exists('ultracache_multilingual_sanitize_warm_policy_store')) {
            // Lifecycle/migration metadata is runtime-owned. A stale dashboard
            // tab may update providerPolicies, but it must never roll back the
            // server's migration marker or known-language lifecycle state.
            $stored_settings = function_exists('get_option') && defined('ULTRACACHE_SETTINGS_KEY')
                ? get_option(ULTRACACHE_SETTINGS_KEY, array())
                : array();
            $stored_settings = is_array($stored_settings) ? $stored_settings : array();
            $stored_multilingual_policy = ultracache_multilingual_sanitize_warm_policy_store(
                $stored_settings['multilingualWarmPolicyV1'] ?? array()
            );
            $incoming_multilingual_policy['schemaVersion'] = 2;
            $incoming_multilingual_policy['migrationVersion'] = (int) ($stored_multilingual_policy['migrationVersion'] ?? 0);
            $incoming_multilingual_policy['providerStates'] = (array) ($stored_multilingual_policy['providerStates'] ?? array());
            $settings['multilingualWarmPolicyV1'] = ultracache_multilingual_sanitize_warm_policy_store($incoming_multilingual_policy);
        } else {
            $settings['multilingualWarmPolicyV1'] = array('schemaVersion' => 2, 'migrationVersion' => 0, 'providerPolicies' => array(), 'providerStates' => array());
        }
        $settings['uninstallCleanupPolicy'] = self::sanitize_uninstall_cleanup_policy($settings['uninstallCleanupPolicy'] ?? $defaults['uninstallCleanupPolicy']);

        // Keep the public settings payload canonical. Stored options may still contain
        // obsolete keys, but they must not leak back to CLI, REST, exports, or
        // runtime settings after sanitization.
        $settings = array_intersect_key($settings, $defaults);

        return $settings;
    }



    private static function setting_was_enabled_by_patch(array $current, array $previous, $key)
    {
        return !empty($current[$key]) && empty($previous[$key]);
    }



    private static function validate_critical_settings_support_before_persist(array $current, array $previous)
    {
        $brotli_enabled_by_patch = self::setting_was_enabled_by_patch($current, $previous, 'brotliEnabled');
        $gzip_enabled_by_patch = self::setting_was_enabled_by_patch($current, $previous, 'gzipEnabled');

        if ($brotli_enabled_by_patch || $gzip_enabled_by_patch) {
            $compression_support = self::get_compression_support_status();

            if ($brotli_enabled_by_patch && empty($compression_support['brotli'])) {
                return new WP_Error('ultracache_brotli_unavailable', self::maybe_translate('Brotli compression is not available on this server, so UltraCache HTML Compression was not enabled.'));
            }

            if ($gzip_enabled_by_patch && empty($compression_support['gzip'])) {
                return new WP_Error('ultracache_gzip_unavailable', self::maybe_translate('Gzip compression is not available on this server, so UltraCache HTML Compression was not enabled.'));
            }

            $frontend_compression = self::get_frontend_compression_probe_status(true);
            if (
                !empty($frontend_compression['brotli'])
                || !empty($frontend_compression['gzip'])
                || !empty($frontend_compression['brokenGzip'])
            ) {
                return new WP_Error('ultracache_html_compression_frontend_conflict', self::maybe_translate('Server-side compression is already active. UltraCache compression was not enabled.'));
            }
        }

        if (self::setting_was_enabled_by_patch($current, $previous, 'apacheStaticHtmlDeliveryEnabled')) {
            $server_capability = method_exists(static::class, 'get_setup_server_capability')
                ? self::get_setup_server_capability()
                : array();
            $apache_detected = 'apache' === strtolower((string) ($server_capability['type'] ?? ''));

            // A locally detected Apache origin is authoritative for applicability.
            // Public requests may be answered by Varnish before Apache is observable,
            // so a loopback delivery probe must not block the settings save in that case.
            if (!$apache_detected) {
                if (!method_exists(static::class, 'run_apache_static_html_delivery_capability_probe')) {
                    return new WP_Error(
                        'ultracache_apache_static_capability_unavailable',
                        self::maybe_translate('Apache Static HTML Delivery cannot be enabled because its capability verification is unavailable.')
                    );
                }

                $apache_static_capability = self::run_apache_static_html_delivery_capability_probe();
                if (empty($apache_static_capability['verified']) || 'verified' !== (string) ($apache_static_capability['status'] ?? '')) {
                    $status = sanitize_key((string) ($apache_static_capability['status'] ?? 'inconclusive'));
                    $message = !empty($apache_static_capability['message'])
                        ? (string) $apache_static_capability['message']
                        : self::maybe_translate('Apache Static HTML Delivery could not be verified, so the setting was not enabled.');
                    $code = 'unsupported' === $status
                        ? 'ultracache_apache_static_unsupported'
                        : 'ultracache_apache_static_unverified';

                    return new WP_Error($code, $message);
                }
            }
        }

        if (self::setting_was_enabled_by_patch($current, $previous, 'objectCacheEnabled')) {
            $object_cache_support = self::get_object_cache_support_status(true);
            if (empty($object_cache_support['available'])) {
                $message = !empty($object_cache_support['message']) ? (string) $object_cache_support['message'] : self::maybe_translate('Object Cache cannot be enabled because the UltraCache object-cache drop-in helper is unavailable.');
                return new WP_Error('ultracache_object_cache_unavailable', $message);
            }
        }

        $sqlite_selected = !empty($current['objectCacheEnabled'])
            && (
                'sqlite' === strtolower((string) ($current['objectCacheBackend'] ?? ''))
                || 'sqlite' === strtolower((string) ($current['objectCacheFallbackBackend'] ?? ''))
            );
        $sqlite_selection_changed = self::setting_was_enabled_by_patch($current, $previous, 'objectCacheEnabled')
            || (string) ($current['objectCacheBackend'] ?? '') !== (string) ($previous['objectCacheBackend'] ?? '')
            || (string) ($current['objectCacheFallbackBackend'] ?? '') !== (string) ($previous['objectCacheFallbackBackend'] ?? '');

        if (
            $sqlite_selected
            && $sqlite_selection_changed
            && class_exists('Ultra_Cache_Object_Cache_Manager')
            && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_sqlite_public_exposure_status')
        ) {
            $sqlite_exposure = Ultra_Cache_Object_Cache_Manager::get_sqlite_public_exposure_status();
            if (empty($sqlite_exposure['checked']) || !empty($sqlite_exposure['exposed'])) {
                $message = !empty($sqlite_exposure['message'])
                    ? (string) $sqlite_exposure['message']
                    : __('SQLite object cache cannot be enabled until database access protection is verified.', 'ultracache');
                $code = !empty($sqlite_exposure['exposed'])
                    ? 'ultracache_sqlite_publicly_exposed'
                    : 'ultracache_sqlite_exposure_unverified';
                return new WP_Error($code, $message);
            }
        }

        if (
            self::setting_was_enabled_by_patch($current, $previous, 'mediaOptimizationEnabled')
            || self::setting_was_enabled_by_patch($current, $previous, 'mediaGenerateOnUploadEnabled')
            || self::setting_was_enabled_by_patch($current, $previous, 'mediaGenerateOnDemandEnabled')
        ) {
            $media_support = self::get_media_support_status();
            if (empty($media_support['supported'])) {
                return new WP_Error('ultracache_media_optimization_unavailable', self::maybe_translate('Media optimization is not available on this server, so the media optimization setting was not enabled.'));
            }
        }

        if (self::setting_was_enabled_by_patch($current, $previous, 'varnishCliEnabled')) {
            $varnish_support = self::get_varnish_support_status();
            if (empty($varnish_support['available'])) {
                $message = !empty($varnish_support['message']) ? (string) $varnish_support['message'] : self::maybe_translate('Varnish integration is not available on this server, so Varnish was not enabled.');
                return new WP_Error('ultracache_varnish_unavailable', $message);
            }
        }

        return true;
    }

}
