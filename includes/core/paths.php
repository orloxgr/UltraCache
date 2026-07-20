<?php
/**
 * Core path and URL helpers for UltraCache.
 *
 * Moved from functions.php without changing existing function names or behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a relative UltraCache storage path without allowing traversal.
 *
 * @param string $relative Relative path or filename.
 * @return string
 */
function ultracache_storage_clean_relative_path($relative)
{
    $relative = str_replace('\\', '/', (string) $relative);
    $relative = ltrim($relative, '/');
    $parts = array();
    foreach (explode('/', $relative) as $part) {
        $part = trim((string) $part);
        if ('' === $part || '.' === $part || '..' === $part) {
            continue;
        }
        // Do not use sanitize_file_name() for directory path segments: WordPress treats
        // extension-only folder names like "avif"/"webp" as filenames and rewrites
        // them to "unnamed-file.avif"/"unnamed-file.webp". Keep this as a
        // conservative storage path segment sanitizer instead.
        $part = preg_replace('/[^A-Za-z0-9._-]+/', '-', $part);
        $part = is_string($part) ? trim($part, '-') : '';
        if ('' === $part || '.' === $part || '..' === $part) {
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', array_filter($parts, static function ($part) {
        return '' !== (string) $part;
    }));
}

/**
 * Join an absolute base path with a sanitized relative path.
 *
 * @param string $base     Absolute base path.
 * @param string $relative Relative path.
 * @return string
 */
function ultracache_storage_join_path($base, $relative = '')
{
    $base = trailingslashit((string) $base);
    $relative = ultracache_storage_clean_relative_path($relative);
    $path = '' === $relative ? $base : $base . $relative;

    return function_exists('wp_normalize_path') ? wp_normalize_path($path) : str_replace('\\', '/', $path);
}

/**
 * Join a base URL with a sanitized relative path, URL-encoding path segments.
 *
 * @param string $base     Base URL.
 * @param string $relative Relative path.
 * @return string
 */
function ultracache_storage_join_url($base, $relative = '')
{
    $base = trailingslashit((string) $base);
    $relative = ultracache_storage_clean_relative_path($relative);
    if ('' === $relative) {
        return $base;
    }

    $segments = array_map('rawurlencode', explode('/', $relative));
    return $base . implode('/', $segments);
}

/**
 * Return an UltraCache plugin filesystem path resolved from the main plugin file.
 *
 * @param string $relative Optional relative path inside the plugin directory.
 * @return string
 */
function ultracache_plugin_dir($relative = '')
{
    if (!defined('ULTRACACHE_FILE')) {
        return '';
    }

    $base = plugin_dir_path(ULTRACACHE_FILE);
    $relative = ultracache_storage_clean_relative_path($relative);

    return '' === $relative
        ? trailingslashit(wp_normalize_path((string) $base))
        : ultracache_storage_join_path($base, $relative);
}

/**
 * Return an UltraCache plugin URL resolved from the main plugin file.
 *
 * @param string $relative Optional relative path inside the plugin directory.
 * @return string
 */
function ultracache_plugin_url($relative = '')
{
    if (!defined('ULTRACACHE_FILE')) {
        return '';
    }

    return ultracache_storage_join_url(plugin_dir_url(ULTRACACHE_FILE), $relative);
}

/**
 * Return the installed standard plugins root derived from this plugin's
 * WordPress-resolved main file instead of a hardcoded plugins-directory path.
 *
 * @return string
 */
function ultracache_plugins_root_dir()
{
    if (!defined('ULTRACACHE_FILE')) {
        return '';
    }

    $plugin_dir = ultracache_plugin_dir();
    $plugins_root = plugin_dir_path(untrailingslashit($plugin_dir));
    $plugins_root = wp_normalize_path((string) $plugins_root);

    return '' !== $plugins_root ? trailingslashit($plugins_root) : '';
}

/**
 * Return the WordPress core root. WordPress has no public path helper for
 * this directory, so the required ABSPATH constant is isolated here.
 *
 * @return string
 */
function ultracache_wordpress_core_root_dir()
{
    if (!defined('ABSPATH')) {
        return '';
    }

    return trailingslashit(wp_normalize_path((string) ABSPATH));
}

/**
 * Return the exact wp-config.php used by the current WordPress execution.
 *
 * Normal web requests expose the loaded configuration through PHP's
 * included-files list. WP-CLI evaluates wp-config.php instead of including
 * it, so CLI executions use WP-CLI's own authoritative config locator.
 * No ABSPATH/root/parent guessing is performed by UltraCache.
 *
 * @return string Normalized absolute path, or an empty string when absent.
 */
function ultracache_loaded_wp_config_path()
{
    foreach (get_included_files() as $included_file) {
        $included_file = is_string($included_file) ? wp_normalize_path($included_file) : '';
        if ('' !== $included_file && 'wp-config.php' === wp_basename($included_file)) {
            return $included_file;
        }
    }

    if (
        defined('WP_CLI')
        && WP_CLI
        && function_exists('WP_CLI\\Utils\\locate_wp_config')
    ) {
        $cli_config = \WP_CLI\Utils\locate_wp_config();
        $cli_config = is_string($cli_config) ? wp_normalize_path($cli_config) : '';
        if ('' !== $cli_config && 'wp-config.php' === wp_basename($cli_config)) {
            return $cli_config;
        }
    }

    return '';
}

/**
 * Check whether a path is the exact wp-config.php loaded by WordPress.
 *
 * @param string $path Candidate filesystem path.
 * @return bool
 */
function ultracache_is_loaded_wp_config_path($path)
{
    $candidate = ultracache_normalize_filesystem_path_for_guard($path);
    $config = ultracache_normalize_filesystem_path_for_guard(ultracache_loaded_wp_config_path());

    return '' !== $candidate && '' !== $config && $candidate === $config;
}

/**
 * Return a canonical WordPress admin-include path from the centralized
 * WordPress core-root resolver. Only the core files UltraCache uses are
 * accepted; no plugin-controlled or user-controlled path is allowed.
 *
 * @param string $filename WordPress admin include filename.
 * @return string
 */
function ultracache_wordpress_admin_include_path($filename)
{
    $filename = (string) $filename;
    if ('' === $filename || false !== strpos($filename, '/') || false !== strpos($filename, '\\')) {
        return '';
    }

    $filename = wp_basename($filename);

    $allowed = array(
        'file.php',
        'image.php',
        'plugin.php',
        'upgrade.php',
    );

    if (!in_array($filename, $allowed, true)) {
        return '';
    }

    $core_root = ultracache_wordpress_core_root_dir();
    if ('' === $core_root) {
        return '';
    }

    return ultracache_storage_join_path($core_root, 'wp-admin/includes/' . $filename);
}

/**
 * Load one approved WordPress admin include through the canonical resolver.
 * WordPress core guarantees these files for a valid installation, so this
 * helper intentionally avoids a native filesystem probe before require_once.
 *
 * @param string $filename          WordPress admin include filename.
 * @param string $required_function Optional function expected after loading.
 * @return bool
 */
function ultracache_require_wordpress_admin_include($filename, $required_function = '')
{
    $required_function = (string) $required_function;
    if ('' !== $required_function && function_exists($required_function)) {
        return true;
    }

    $path = ultracache_wordpress_admin_include_path($filename);
    if ('' === $path) {
        return false;
    }

    require_once $path;

    return '' === $required_function || function_exists($required_function);
}

/**
 * Return the local directory represented by includes_url().
 *
 * @return string
 */
function ultracache_wordpress_includes_dir()
{
    $core_root = ultracache_wordpress_core_root_dir();
    if ('' === $core_root || !defined('WPINC')) {
        return '';
    }

    return trailingslashit(ultracache_storage_join_path($core_root, (string) WPINC));
}

/**
 * Return the current public wp-includes path using includes_url().
 *
 * @param string $relative Optional file or directory under wp-includes.
 * @return string Root-relative public path or an empty string.
 */
function ultracache_wordpress_includes_public_path($relative = '')
{
    $raw_relative = str_replace('\\', '/', (string) $relative);
    $directory_reference = '' === $raw_relative || '/' === substr($raw_relative, -1);
    $relative = ultracache_storage_clean_relative_path($raw_relative);
    $path = (string) wp_parse_url(includes_url($relative), PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    $path = '/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
    return $directory_reference ? trailingslashit($path) : untrailingslashit($path);
}

/**
 * Return the current public WordPress admin path using admin_url().
 *
 * @param string $relative Optional file or directory under wp-admin.
 * @return string Root-relative public path or an empty string.
 */
function ultracache_wordpress_admin_public_path($relative = '')
{
    $raw_relative = str_replace('\\', '/', (string) $relative);
    $directory_reference = '' === $raw_relative || '/' === substr($raw_relative, -1);
    $relative = ultracache_storage_clean_relative_path($raw_relative);
    $path = (string) wp_parse_url(admin_url($relative), PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    $path = '/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
    return $directory_reference ? trailingslashit($path) : untrailingslashit($path);
}

/**
 * Return a normalized root-relative public path from a URL.
 *
 * @param string $url Public URL.
 * @return string
 */
function ultracache_public_path_from_url($url)
{
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
}

/**
 * Return the root-relative public plugins path using WordPress APIs.
 *
 * @param string $relative Optional relative path under plugins.
 * @return string
 */
function ultracache_plugins_public_path($relative = '')
{
    $relative = ultracache_storage_clean_relative_path($relative);
    $url = function_exists('plugins_url') ? plugins_url($relative) : '';

    return ultracache_public_path_from_url($url);
}

/**
 * Return the configured must-use plugins directory.
 *
 * WordPress exposes no public path helper for the mu-plugins root, so the
 * configurable core constant is isolated in this one resolver.
 *
 * @return string Normalized, trailing-slashed path or an empty string.
 */
function ultracache_mu_plugins_root_dir()
{
    if (!defined('WPMU_PLUGIN_DIR')) {
        return '';
    }

    $directory = wp_normalize_path((string) WPMU_PLUGIN_DIR);
    return '' !== $directory ? trailingslashit($directory) : '';
}

/**
 * Return the configured must-use plugins root URL.
 *
 * WordPress exposes no public URL helper for the mu-plugins root, so the
 * configurable core constant is isolated in this one resolver.
 *
 * @return string Trailing-slashed URL or an empty string.
 */
function ultracache_mu_plugins_root_url()
{
    if (!defined('WPMU_PLUGIN_URL')) {
        return '';
    }

    $url = esc_url_raw((string) WPMU_PLUGIN_URL);
    return '' !== $url ? trailingslashit($url) : '';
}

/**
 * Return the root-relative public mu-plugins path when available.
 *
 * @param string $relative Optional relative path under mu-plugins.
 * @return string
 */
function ultracache_mu_plugins_public_path($relative = '')
{
    $root_url = ultracache_mu_plugins_root_url();
    if ('' === $root_url) {
        return '';
    }

    return ultracache_public_path_from_url(ultracache_storage_join_url($root_url, $relative));
}

/**
 * Return root-relative public theme root paths using WordPress APIs.
 *
 * @param string $relative Optional relative path under each theme root.
 * @return string[]
 */
function ultracache_themes_public_paths($relative = '')
{
    $paths = array();
    $relative = ultracache_storage_clean_relative_path($relative);

    if (function_exists('get_theme_root_uri')) {
        $path = ultracache_public_path_from_url(ultracache_storage_join_url(get_theme_root_uri(), $relative));
        if ('' !== $path) {
            $paths[] = $path;
        }
    }

    if (function_exists('wp_get_themes')) {
        foreach ((array) wp_get_themes() as $theme) {
            if (!is_object($theme) || !method_exists($theme, 'get_stylesheet_directory_uri')) {
                continue;
            }
            $uri = (string) $theme->get_stylesheet_directory_uri();
            if ('' === $uri) {
                continue;
            }
            $path = ultracache_public_path_from_url(ultracache_storage_join_url(dirname($uri), $relative));
            if ('' !== $path) {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique(array_filter($paths, static function ($path) {
        return '' !== (string) $path;
    })));
}

/**
 * Check whether a public URL/path contains a normalized root-relative marker.
 *
 * @param string $path   URL path or URL.
 * @param string $marker Root-relative public marker.
 * @return bool
 */
function ultracache_public_path_contains($path, $marker)
{
    $path = (string) $path;
    $path = (string) wp_parse_url($path, PHP_URL_PATH) ?: $path;
    $path = strtolower('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));

    $marker = strtolower('/' . ltrim(str_replace('\\', '/', rawurldecode((string) $marker)), '/'));
    if ('' === trim($marker, '/')) {
        return false;
    }

    return false !== strpos($path, $marker);
}

/**
 * Check whether a public URL/path contains any normalized root-relative marker.
 *
 * @param string $path    URL path or URL.
 * @param array  $markers Root-relative public markers.
 * @return bool
 */
function ultracache_public_path_contains_any($path, array $markers)
{
    foreach ($markers as $marker) {
        if (ultracache_public_path_contains($path, (string) $marker)) {
            return true;
        }
    }

    return false;
}

/**
 * Return the path segment relative to a normalized root-relative public marker.
 *
 * @param string $source URL or root-relative public path.
 * @param string $marker Root-relative public marker ending at the desired root.
 * @return string|false
 */
function ultracache_public_path_relative_to_marker($source, $marker)
{
    $path = (string) wp_parse_url((string) $source, PHP_URL_PATH);
    if ('' === $path) {
        $path = (string) preg_replace('/[?#].*$/', '', (string) $source);
    }
    $path = '/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/');

    $marker = '/' . ltrim(str_replace('\\', '/', rawurldecode((string) $marker)), '/');
    $marker = trailingslashit($marker);
    if ('' === trim($path, '/') || '' === trim($marker, '/')) {
        return false;
    }

    $path_lc = strtolower($path);
    $marker_lc = strtolower($marker);
    if (0 !== strpos($path_lc, $marker_lc)) {
        return false;
    }

    return ltrim(substr($path, strlen($marker)), '/');
}

/**
 * Resolve plugin/theme owner metadata from a public URL/path using dynamic root markers.
 *
 * @param string $source Public URL or path.
 * @return array{kind:string,slug:string,group:string,relative:string}|array{}
 */
function ultracache_plugin_theme_owner_from_public_source($source)
{
    $candidates = array();
    if (function_exists('ultracache_plugins_public_path')) {
        $candidates[] = array('plugin', ultracache_plugins_public_path());
    }
    if (function_exists('ultracache_themes_public_paths')) {
        foreach (ultracache_themes_public_paths() as $theme_path) {
            $candidates[] = array('theme', $theme_path);
        }
    }

    foreach ($candidates as $candidate) {
        $kind = (string) $candidate[0];
        $marker = (string) $candidate[1];
        $relative = ultracache_public_path_relative_to_marker($source, $marker);
        if (false === $relative || '' === $relative) {
            continue;
        }

        $parts = explode('/', str_replace('\\', '/', $relative), 2);
        $slug = sanitize_key((string) $parts[0]);
        if ('' === $slug) {
            continue;
        }
        $child = isset($parts[1]) ? trim((string) $parts[1], '/') : '';

        return array(
            'kind'     => $kind,
            'slug'     => $slug,
            'group'    => sanitize_text_field($slug . '/'),
            'relative' => sanitize_text_field(substr($child, 0, 220)),
        );
    }

    return array();
}

/**
 * Return the filesystem path that serves the WordPress home URL.
 *
 * get_home_path() correctly handles WordPress installed in a subdirectory while
 * the public site is served from its parent directory.
 *
 * @return string
 */
function ultracache_get_wordpress_home_path()
{
    if (!function_exists('get_home_path')) {
        ultracache_require_wordpress_admin_include('file.php', 'get_home_path');
    }

    $home_path = function_exists('get_home_path')
        ? get_home_path()
        : ultracache_wordpress_core_root_dir();
    $home_path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $home_path) : str_replace('\\', '/', (string) $home_path);

    return untrailingslashit($home_path);
}

/**
 * Return the effective public document root for path/security diagnostics.
 *
 * @return string
 */
function ultracache_get_server_document_root_path()
{
    $document_root = function_exists('ultracache_server_value') ? (string) ultracache_server_value('DOCUMENT_ROOT') : '';
    $document_root = function_exists('wp_normalize_path') ? wp_normalize_path($document_root) : str_replace('\\', '/', $document_root);
    $document_root = untrailingslashit($document_root);

    if ('' === $document_root) {
        $document_root = ultracache_get_wordpress_home_path();
    }

    return untrailingslashit((string) $document_root);
}

/**
 * Resolve an installed plugin's real main file from the WordPress plugin inventory.
 *
 * @param string $slug Plugin directory slug.
 * @return string
 */
function ultracache_plugin_main_file($slug)
{
    $slug = sanitize_key((string) $slug);
    $plugins_root = ultracache_plugins_root_dir();
    if ('' === $slug || '' === $plugins_root) {
        return '';
    }

    if (!function_exists('get_plugins')) {
        ultracache_require_wordpress_admin_include('plugin.php', 'get_plugins');
    }
    if (!function_exists('get_plugins')) {
        return '';
    }

    static $installed_plugins = null;
    if (null === $installed_plugins) {
        $installed_plugins = (array) get_plugins();
    }

    foreach (array_keys($installed_plugins) as $plugin_basename) {
        $plugin_basename = str_replace('\\', '/', (string) $plugin_basename);
        $plugin_parts = explode('/', $plugin_basename);
        $plugin_slug = count($plugin_parts) > 1
            ? sanitize_key((string) reset($plugin_parts))
            : sanitize_key(pathinfo($plugin_basename, PATHINFO_FILENAME));
        if ($slug !== $plugin_slug) {
            continue;
        }

        // The basename comes from WordPress' verified plugin inventory.
        return wp_normalize_path($plugins_root . $plugin_basename);
    }

    return '';
}

/**
 * Return an installed plugin root directory from its real main file.
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function ultracache_plugin_root_dir($slug)
{
    $plugin_file = ultracache_plugin_main_file($slug);
    return '' !== $plugin_file ? untrailingslashit(wp_normalize_path(plugin_dir_path($plugin_file))) : '';
}

/**
 * Return an installed plugin root URI from its real main file.
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function ultracache_plugin_root_uri($slug)
{
    $plugin_file = ultracache_plugin_main_file($slug);
    return '' !== $plugin_file ? untrailingslashit(esc_url_raw(plugin_dir_url($plugin_file))) : '';
}

/**
 * Return local roots to scan for CSS font sources without hardcoded wp-content paths.
 *
 * @return string[]
 */
function ultracache_local_font_css_scan_roots()
{
    $roots = array();
    $candidates = array();

    $plugins_root = ultracache_plugins_root_dir();
    if ('' !== $plugins_root) {
        $candidates[] = $plugins_root;
    }
    $mu_plugins_root = ultracache_mu_plugins_root_dir();
    if ('' !== $mu_plugins_root) {
        $candidates[] = $mu_plugins_root;
    }
    if (function_exists('get_theme_root')) {
        $candidates[] = get_theme_root();
    }
    $uploads = ultracache_uploads_base_info();
    if (!empty($uploads['basedir'])) {
        $candidates[] = $uploads['basedir'];
    }

    foreach ($candidates as $root) {
        $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
        $root = untrailingslashit($root);
        if ('' !== $root && is_dir($root) && !in_array($root, $roots, true)) {
            $roots[] = $root;
        }
    }

    return $roots;
}

/**
 * Return Slider Revolution's public uploads path marker.
 *
 * Slider Revolution stores imported slider assets relative to wp_upload_dir(),
 * normally uploads/revslider/, so this must use wp_upload_dir() as its base.
 *
 * @param string $relative Optional relative path under uploads/revslider.
 * @return string
 */
function ultracache_revslider_uploads_public_path($relative = '')
{
    return ultracache_uploads_public_path('revslider/' . ultracache_storage_clean_relative_path($relative));
}

/**
 * Return Slider Revolution's optimized-image public uploads path marker.
 *
 * Slider Revolution stores generated optimized slider images under
 * uploads/revslider/o/ using wp_upload_dir() as its base.
 *
 * @param string $relative Optional relative path under uploads/revslider/o.
 * @return string
 */
function ultracache_revslider_optimized_uploads_public_path($relative = '')
{
    return ultracache_uploads_public_path('revslider/o/' . ultracache_storage_clean_relative_path($relative));
}

/**
 * Normalize a public URL for local comparisons without changing its resource identity.
 *
 * Query strings are preserved by default because WordPress-enqueued assets often use
 * versioned URLs, and CSS bundle source matching needs the same URL identity that was
 * present in the rendered HTML/manifest. Callers that resolve filesystem paths may pass
 * array('strip_query' => true).
 *
 * @param string $url  Public URL or root-relative URL.
 * @param array  $args Optional flags. Supported: strip_query, strip_fragment.
 * @return string
 */
function ultracache_normalize_public_url($url, array $args = array())
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
    if ('' === $url) {
        return '';
    }

    $strip_query = !empty($args['strip_query']);
    $strip_fragment = array_key_exists('strip_fragment', $args) ? (bool) $args['strip_fragment'] : true;

    $home = function_exists('home_url') ? home_url('/') : '';
    $preferred_scheme = (string) wp_parse_url($home, PHP_URL_SCHEME);
    if ('' === $preferred_scheme && function_exists('is_ssl')) {
        $preferred_scheme = is_ssl() ? 'https' : 'http';
    }
    if ('' === $preferred_scheme) {
        $preferred_scheme = 'http';
    }

    if (0 === strpos($url, '//')) {
        $url = $preferred_scheme . ':' . $url;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
    if ('' !== $path) {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    $normalized = '';
    if (!empty($parts['scheme'])) {
        $scheme = strtolower((string) $parts['scheme']);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $home_host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        if ('' !== $home_host && $home_host === $host && '' !== $preferred_scheme) {
            $scheme = strtolower($preferred_scheme);
        }
        $normalized .= $scheme . '://';
    }
    if (!empty($parts['user'])) {
        $normalized .= $parts['user'];
        if (isset($parts['pass'])) {
            $normalized .= ':' . $parts['pass'];
        }
        $normalized .= '@';
    }
    if (!empty($parts['host'])) {
        $normalized .= strtolower((string) $parts['host']);
    }
    if (!empty($parts['port'])) {
        $normalized .= ':' . $parts['port'];
    }
    $normalized .= $path;

    if (!$strip_query && isset($parts['query']) && '' !== (string) $parts['query']) {
        $normalized .= '?' . (string) $parts['query'];
    }
    if (!$strip_fragment && isset($parts['fragment']) && '' !== (string) $parts['fragment']) {
        $normalized .= '#' . (string) $parts['fragment'];
    }

    return '' !== $normalized ? $normalized : $url;
}

/**
 * Check whether a URL belongs to this WordPress site's home/site host.
 *
 * @param string $url Public URL.
 * @return bool
 */
function ultracache_is_local_site_url($url)
{
    $url = ultracache_normalize_public_url($url);
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ('' === $host && 0 === strpos($url, '/')) {
        return true;
    }
    if ('' === $host) {
        return false;
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $site_host = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
    $allowed = array_filter(array_unique(array($home_host, $site_host)));
    $host = preg_replace('/^www\./', '', $host);
    $allowed = array_map(static function ($candidate) {
        return preg_replace('/^www\./', '', (string) $candidate);
    }, $allowed);

    return in_array($host, $allowed, true);
}

/**
 * Check whether a filesystem path is inside a root directory.
 *
 * @param string $path Filesystem path.
 * @param string $root Filesystem root.
 * @return bool
 */
function ultracache_path_is_within_root($path, $root)
{
    $normalize = static function ($value) {
        $value = function_exists('wp_normalize_path') ? wp_normalize_path((string) $value) : str_replace('\\', '/', (string) $value);
        return rtrim($value, '/');
    };
    $path = $normalize($path);
    $root = $normalize($root);
    if ('' === $path || '' === $root) {
        return false;
    }

    return $path === $root || 0 === strpos($path, $root . '/');
}

/**
 * Resolve a local path from a trusted root and sanitized relative path.
 *
 * @param string $root     Root directory.
 * @param string $relative Relative public path.
 * @return string
 */
function ultracache_canonical_local_path_from_relative($root, $relative)
{
    $root_real = realpath($root);
    if (!is_string($root_real) || '' === $root_real) {
        return '';
    }

    $relative = rawurldecode(str_replace('\\', '/', (string) $relative));
    $relative = ltrim($relative, '/');
    if ('' === $relative) {
        return '';
    }

    foreach (explode('/', $relative) as $segment) {
        if ('' === $segment || '.' === $segment || '..' === $segment) {
            return '';
        }
    }

    $candidate = trailingslashit($root_real) . $relative;
    $candidate_real = realpath($candidate);
    if (!is_string($candidate_real) || '' === $candidate_real) {
        return '';
    }

    return ultracache_path_is_within_root($candidate_real, $root_real) ? wp_normalize_path($candidate_real) : '';
}

/**
 * Return the root-relative public wp-content path.
 *
 * @param string $relative Optional relative path under wp-content.
 * @return string
 */
function ultracache_content_public_path($relative = '')
{
    $url = ultracache_storage_join_url(content_url('/'), ultracache_storage_clean_relative_path($relative));
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
}

/**
 * Return the root-relative public internal cache path.
 *
 * @param string $relative Optional relative path under the internal UltraCache cache root.
 * @return string
 */
function ultracache_content_cache_public_path($relative = '')
{
    $path = (string) wp_parse_url(ultracache_content_cache_storage_url($relative), PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
}

/**
 * Resolve a root-relative public URL path to a readable local file path.
 *
 * @param string $path         Root-relative URL path.
 * @param array  $allowed_exts Optional extension allow-list without dots.
 * @return string
 */
function ultracache_public_path_to_local_path($path, array $allowed_exts = array())
{
    $path = '/' . ltrim(str_replace('\\', '/', rawurldecode((string) $path)), '/');
    if ('' === trim($path, '/')) {
        return '';
    }

    if (!empty($allowed_exts)) {
        $ext = strtolower(pathinfo((string) wp_parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowed_exts = array_map('strtolower', array_map('strval', $allowed_exts));
        if ('' === $ext || !in_array($ext, $allowed_exts, true)) {
            return '';
        }
    }

    $candidates = array();

    $cache_path = ultracache_content_cache_public_path();
    if ('' !== $cache_path && 0 === strpos($path, $cache_path)) {
        $relative = ltrim(substr($path, strlen($cache_path)), '/');
        $cache_root = ultracache_content_cache_storage_dir();
        if ('' !== $cache_root) {
            $candidates[] = array($cache_root, $relative);
        }
    }

    $uploads_path = ultracache_uploads_public_path();
    if ('' !== $uploads_path && 0 === strpos($path, $uploads_path)) {
        $relative = ltrim(substr($path, strlen($uploads_path)), '/');
        $uploads = ultracache_uploads_base_info();
        if (!empty($uploads['basedir'])) {
            $candidates[] = array($uploads['basedir'], $relative);
        }
    }

    $plugins_path = ultracache_plugins_public_path();
    $plugins_root = ultracache_plugins_root_dir();
    if ('' !== $plugins_path && '' !== $plugins_root && 0 === strpos($path, $plugins_path)) {
        $relative = ltrim(substr($path, strlen($plugins_path)), '/');
        $candidates[] = array($plugins_root, $relative);
    }

    $mu_plugins_path = ultracache_mu_plugins_public_path();
    $mu_plugins_root = ultracache_mu_plugins_root_dir();
    if ('' !== $mu_plugins_path && '' !== $mu_plugins_root && 0 === strpos($path, $mu_plugins_path)) {
        $relative = ltrim(substr($path, strlen($mu_plugins_path)), '/');
        $candidates[] = array($mu_plugins_root, $relative);
    }

    if (function_exists('get_theme_root')) {
        foreach (ultracache_themes_public_paths() as $theme_path) {
            if ('' === $theme_path || 0 !== strpos($path, $theme_path)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($theme_path)), '/');
            $candidates[] = array(get_theme_root(), $relative);
        }
    }

    $includes_path = (string) wp_parse_url(includes_url('/'), PHP_URL_PATH);
    $includes_path = trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($includes_path)), '/'));
    $includes_root = ultracache_wordpress_includes_dir();
    if ('' !== trim($includes_path, '/') && '' !== $includes_root && 0 === strpos($path, $includes_path)) {
        $relative = ltrim(substr($path, strlen($includes_path)), '/');
        $candidates[] = array($includes_root, $relative);
    }

    foreach ($candidates as $candidate) {
        $root = isset($candidate[0]) ? (string) $candidate[0] : '';
        $relative = isset($candidate[1]) ? (string) $candidate[1] : '';
        if ('' === $root || '' === $relative) {
            continue;
        }
        $resolved = ultracache_canonical_local_path_from_relative($root, $relative);
        if ('' !== $resolved && is_file($resolved) && is_readable($resolved)) {
            return $resolved;
        }
    }

    return '';
}

/**
 * Resolve a local readable file path from a local public URL.
 *
 * @param string $url          Public URL or root-relative URL.
 * @param array  $allowed_exts Optional extension allow-list without dots.
 * @return string
 */
function ultracache_local_path_from_public_url($url, array $allowed_exts = array())
{
    $url = ultracache_normalize_public_url($url, array('strip_query' => true));
    if ('' === $url || !ultracache_is_local_site_url($url)) {
        return '';
    }

    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if ('' === $path && 0 === strpos($url, '/')) {
        $path = $url;
    }
    if ('' === $path) {
        return '';
    }

    return ultracache_public_path_to_local_path($path, $allowed_exts);
}

/**
 * Convert a local path under known WordPress public roots to a public URL.
 *
 * @param string $path Local filesystem path.
 * @return string
 */
function ultracache_public_url_from_local_path($path)
{
    $path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
    if ('' === $path) {
        return '';
    }

    $roots = array();
    $cache_root = ultracache_content_cache_storage_dir();
    if ('' !== $cache_root) {
        $roots[] = array(wp_normalize_path($cache_root), ultracache_content_cache_storage_url());
    }
    $uploads = ultracache_uploads_base_info();
    if (!empty($uploads['basedir']) && !empty($uploads['baseurl'])) {
        $roots[] = array(wp_normalize_path($uploads['basedir']), $uploads['baseurl']);
    }
    $plugins_root = ultracache_plugins_root_dir();
    if ('' !== $plugins_root) {
        $roots[] = array(wp_normalize_path($plugins_root), plugins_url());
    }
    $mu_plugins_root = ultracache_mu_plugins_root_dir();
    $mu_plugins_url = ultracache_mu_plugins_root_url();
    if ('' !== $mu_plugins_root && '' !== $mu_plugins_url) {
        $roots[] = array(wp_normalize_path($mu_plugins_root), $mu_plugins_url);
    }
    if (function_exists('get_theme_root') && function_exists('get_theme_root_uri')) {
        $roots[] = array(wp_normalize_path(get_theme_root()), get_theme_root_uri());
    }
    $includes_root = ultracache_wordpress_includes_dir();
    if ('' !== $includes_root) {
        $roots[] = array(wp_normalize_path($includes_root), includes_url('/'));
    }

    foreach ($roots as $root) {
        $root_path = untrailingslashit((string) $root[0]);
        $root_url = trailingslashit((string) $root[1]);
        if ('' === $root_path || !ultracache_path_is_within_root($path, $root_path)) {
            continue;
        }
        $relative = ltrim(substr($path, strlen($root_path)), '/');
        if ('' === $relative) {
            continue;
        }
        return ultracache_normalize_public_url(ultracache_storage_join_url($root_url, $relative));
    }

    return '';
}

/**
 * Resolve the WordPress content directory through the Filesystem API.
 *
 * WordPress requires cache drop-ins directly in this directory. Using the
 * active WP_Filesystem transport keeps path discovery aligned with custom
 * content directories and remote filesystem mappings without reading the
 * internal content-directory constant in UltraCache drop-in code.
 *
 * @return string Normalized, trailing-slashed filesystem path or an empty string.
 */
function ultracache_wordpress_content_dir()
{
    $filesystem = function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : false;
    if (!$filesystem || !method_exists($filesystem, 'wp_content_dir')) {
        return '';
    }

    $content_dir = $filesystem->wp_content_dir();
    if (!is_string($content_dir) || '' === trim($content_dir)) {
        return '';
    }

    return trailingslashit(wp_normalize_path($content_dir));
}

/**
 * Return the WordPress-required path for a supported cache drop-in.
 *
 * @param string $basename Drop-in basename.
 * @return string
 */
function ultracache_dropin_path($basename)
{
    $basename = sanitize_file_name((string) $basename);
    if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true)) {
        return '';
    }

    $content_dir = ultracache_wordpress_content_dir();
    return '' !== $content_dir ? ultracache_storage_join_path($content_dir, $basename) : '';
}
