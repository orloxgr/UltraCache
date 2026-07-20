<?php
/**
 * Diagnostics redaction and output preparation helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Diagnostics_Export_Trait
{
private static function redact_path_for_diagnostics($path)
        {
            $path = is_string($path) ? wp_normalize_path($path) : '';
            if ('' === $path) {
                return '';
            }

            $basename = wp_basename($path);
            $roots = array();

            $plugin_dir = ultracache_plugin_dir();
            if ('' !== $plugin_dir) {
                $roots[] = array('UltraCache plugin directory', wp_normalize_path($plugin_dir));
            }

            $plugins_root = function_exists('ultracache_plugins_root_dir') ? ultracache_plugins_root_dir() : '';
            if ('' !== $plugins_root) {
                $roots[] = array('PLUGINS_DIR', wp_normalize_path($plugins_root));
            }

            if (function_exists('get_theme_root')) {
                $theme_root = wp_normalize_path((string) get_theme_root());
                if ('' !== $theme_root) {
                    $roots[] = array('THEMES_DIR', $theme_root);
                }
            }

            $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : array();
            if (!empty($uploads['basedir'])) {
                $roots[] = array('UPLOADS_DIR', wp_normalize_path((string) $uploads['basedir']));
            }

            $core_root = function_exists('ultracache_wordpress_core_root_dir') ? ultracache_wordpress_core_root_dir() : '';
            if ('' !== $core_root) {
                $roots[] = array('WORDPRESS_CORE', wp_normalize_path($core_root));
            }

            $document_root = function_exists('ultracache_get_server_document_root_path') ? ultracache_get_server_document_root_path() : '';
            if ('' !== $document_root) {
                $roots[] = array('DOCUMENT_ROOT', wp_normalize_path($document_root));
            }

            foreach ($roots as $root) {
                $label = isset($root[0]) ? (string) $root[0] : '';
                $base = isset($root[1]) ? untrailingslashit((string) $root[1]) : '';
                if ('' === $label || '' === $base || ($path !== $base && 0 !== strpos($path, trailingslashit($base)))) {
                    continue;
                }

                $relative = ltrim(substr($path, strlen($base)), '/');
                return $label . ('/' . $relative);
            }

            return '[outside-known-roots]/' . $basename;
        }

private static function redact_diagnostics_for_output($value, $key = '', $depth = 0)
        {
            if (function_exists('ultracache_redact_sensitive_debug_value')) {
                return ultracache_redact_sensitive_debug_value($key, $value, $depth);
            }

            if ($depth > 8) {
                return is_scalar($value) || null === $value ? $value : '[truncated]';
            }

            if (is_array($value)) {
                $redacted = array();
                foreach ($value as $child_key => $child_value) {
                    $redacted[$child_key] = self::redact_diagnostics_for_output($child_value, (string) $child_key, $depth + 1);
                }
                return $redacted;
            }

            return $value;
        }

}
