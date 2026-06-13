<?php
/**
 * Core procedural helpers for UltraCache.
 *
 * These functions are loaded by ultracache.php before the main plugin class
 * and before the engine/REST/WP-CLI components. They intentionally keep the
 * existing ucwp_* function names for compatibility with the rest of the
 * codebase and generated drop-in helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('ucwp_json_encode_for_inline_script')) {
    function ucwp_json_encode_for_inline_script($value, $extra_flags = 0)
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | (int) $extra_flags;
        $json = wp_json_encode($value, $flags);
        return is_string($json) && '' !== $json ? $json : '';
    }
}


if (!function_exists('ucwp_storage_clean_relative_path')) {
    /**
     * Normalize a relative UltraCache storage path without allowing traversal.
     *
     * @param string $relative Relative path or filename.
     * @return string
     */
    function ucwp_storage_clean_relative_path($relative)
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
}

if (!function_exists('ucwp_storage_join_path')) {
    /**
     * Join an absolute base path with a sanitized relative path.
     *
     * @param string $base     Absolute base path.
     * @param string $relative Relative path.
     * @return string
     */
    function ucwp_storage_join_path($base, $relative = '')
    {
        $base = trailingslashit((string) $base);
        $relative = ucwp_storage_clean_relative_path($relative);
        $path = '' === $relative ? $base : $base . $relative;

        return function_exists('wp_normalize_path') ? wp_normalize_path($path) : str_replace('\\', '/', $path);
    }
}

if (!function_exists('ucwp_storage_join_url')) {
    /**
     * Join a base URL with a sanitized relative path, URL-encoding path segments.
     *
     * @param string $base     Base URL.
     * @param string $relative Relative path.
     * @return string
     */
    function ucwp_storage_join_url($base, $relative = '')
    {
        $base = trailingslashit((string) $base);
        $relative = ucwp_storage_clean_relative_path($relative);
        if ('' === $relative) {
            return $base;
        }

        $segments = array_map('rawurlencode', explode('/', $relative));
        return $base . implode('/', $segments);
    }
}

if (!function_exists('ucwp_content_cache_storage_dir')) {
    /**
     * Return the current internal UltraCache cache root under wp-content/cache.
     *
     * This is intentionally separate from public generated asset storage so the
     * later uploads move can be limited to browser-loaded assets only.
     *
     * @param string $relative Optional relative path under the cache root.
     * @return string
     */
    function ucwp_content_cache_storage_dir($relative = '')
    {
        $base = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/' : '';
        return '' === $base ? '' : trailingslashit(ucwp_storage_join_path($base, $relative));
    }
}

if (!function_exists('ucwp_content_cache_storage_url')) {
    /**
     * Return the public URL for the current the internal UltraCache cache root storage root.
     *
     * @param string $relative Optional relative path under the cache root.
     * @return string
     */
    function ucwp_content_cache_storage_url($relative = '')
    {
        return ucwp_storage_join_url(content_url('cache/ultracache/'), $relative);
    }
}

if (!function_exists('ucwp_object_cache_storage_dir')) {
    /**
     * Return the internal UltraCache object cache root.
     *
     * @param string $relative Optional relative path under the object cache root.
     * @return string
     */
    function ucwp_object_cache_storage_dir($relative = '')
    {
        $base = defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/' : '';
        return '' === $base ? '' : trailingslashit(ucwp_storage_join_path($base, $relative));
    }
}

if (!function_exists('ucwp_generated_asset_bucket_slug')) {
    /**
     * Map public generated asset buckets to their current storage directories.
     *
     * @param string $bucket Storage bucket.
     * @return string
     */
    function ucwp_generated_asset_bucket_slug($bucket)
    {
        $bucket = strtolower(trim((string) $bucket));
        $map = array(
            'css-bundles'        => 'css-bundles',
            'font-css'           => 'font-css',
            'optimized-css'      => 'optimized-css',
            'google-fonts'       => 'google-fonts',
            'deferred-inline-js' => 'deferred-inline-js',
        );

        return isset($map[$bucket]) ? $map[$bucket] : '';
    }
}

if (!function_exists('ucwp_generated_asset_dir')) {
    /**
     * Return the filesystem path for a public generated asset bucket under uploads/ultracache.
     *
     * @param string $bucket   Storage bucket.
     * @param string $relative Optional relative filename/path inside the bucket.
     * @return string
     */
    function ucwp_generated_asset_dir($bucket, $relative = '')
    {
        $slug = ucwp_generated_asset_bucket_slug($bucket);
        if ('' === $slug) {
            return '';
        }

        $uploads = ucwp_uploads_base_info();
        $base = ucwp_storage_join_path($uploads['basedir'], 'ultracache/' . $slug . '/');
        if ('' === $base) {
            return '';
        }

        $relative = ucwp_storage_clean_relative_path($relative);
        if ('' === $relative) {
            return trailingslashit($base);
        }

        return ucwp_storage_join_path($base, $relative);
    }
}

if (!function_exists('ucwp_generated_asset_url')) {
    /**
     * Return the URL for a public generated asset bucket under uploads/ultracache.
     *
     * @param string $bucket   Storage bucket.
     * @param string $relative Optional relative filename/path inside the bucket.
     * @return string
     */
    function ucwp_generated_asset_url($bucket, $relative = '')
    {
        $slug = ucwp_generated_asset_bucket_slug($bucket);
        if ('' === $slug) {
            return '';
        }

        $uploads = ucwp_uploads_base_info();
        return ucwp_storage_join_url($uploads['baseurl'], 'ultracache/' . $slug . '/' . ucwp_storage_clean_relative_path($relative));
    }
}

if (!function_exists('ucwp_generated_asset_public_path')) {
    /**
     * Return a root-relative public URL path for a generated asset bucket.
     *
     * @param string $bucket Storage bucket.
     * @return string
     */
    function ucwp_generated_asset_public_path($bucket = '')
    {
        $relative = '' !== (string) $bucket ? ucwp_generated_asset_bucket_slug($bucket) . '/' : '';
        $path = (string) wp_parse_url(ucwp_generated_asset_url($bucket, ''), PHP_URL_PATH);
        if ('' === (string) $bucket) {
            $uploads = ucwp_uploads_base_info();
            $path = (string) wp_parse_url(ucwp_storage_join_url($uploads['baseurl'], 'ultracache/'), PHP_URL_PATH);
        }
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
    }
}


if (!function_exists('ucwp_generated_asset_public_path_markers')) {
    /**
     * Return dynamic public URL path markers for UltraCache uploads assets.
     *
     * These markers are derived from wp_get_upload_dir()/wp_upload_dir() via
     * ucwp_generated_asset_public_path(). They intentionally replace brittle
     * hardcoded generated-uploads checks for customized upload paths.
     *
     * @param string[] $buckets Optional generated asset buckets.
     * @return string[]
     */
    function ucwp_generated_asset_public_path_markers(array $buckets = array())
    {
        $markers = array();
        if (empty($buckets)) {
            $markers[] = ucwp_generated_asset_public_path();
        } else {
            foreach ($buckets as $bucket) {
                $marker = ucwp_generated_asset_public_path((string) $bucket);
                if ('' !== $marker) {
                    $markers[] = $marker;
                }
            }
        }

        return array_values(array_unique(array_filter($markers, static function ($marker) {
            return '' !== (string) $marker;
        })));
    }
}

if (!function_exists('ucwp_generated_asset_reference_matches')) {
    /**
     * Check whether a URL/path/HTML fragment references generated uploads assets.
     *
     * @param string   $source  URL, path, or HTML fragment.
     * @param string[] $buckets Optional generated asset buckets.
     * @return bool
     */
    function ucwp_generated_asset_reference_matches($source, array $buckets = array())
    {
        $markers = ucwp_generated_asset_public_path_markers($buckets);
        return !empty($markers) && function_exists('ucwp_public_path_contains_any') && ucwp_public_path_contains_any((string) $source, $markers);
    }
}

if (!function_exists('ucwp_content_cache_reference_matches')) {
    /**
     * Check whether a URL/path/HTML fragment references UltraCache wp-content cache assets.
     *
     * This cache root is intentionally under wp-content/cache because the files are
     * internal page-cache/runtime files, not public generated uploads assets.
     *
     * @param string $source URL, path, or HTML fragment.
     * @return bool
     */
    function ucwp_content_cache_reference_matches($source)
    {
        $marker = ucwp_content_cache_public_path();
        return '' !== $marker && function_exists('ucwp_public_path_contains') && ucwp_public_path_contains((string) $source, $marker);
    }
}

if (!function_exists('ucwp_generated_asset_local_path_matches')) {
    /**
     * Check whether a filesystem path is inside generated uploads asset storage.
     *
     * @param string   $path    Filesystem path.
     * @param string[] $buckets Optional generated asset buckets.
     * @return bool
     */
    function ucwp_generated_asset_local_path_matches($path, array $buckets = array())
    {
        $roots = array();
        if (empty($buckets)) {
            $uploads = ucwp_uploads_base_info();
            if (!empty($uploads['basedir'])) {
                $roots[] = ucwp_storage_join_path($uploads['basedir'], 'ultracache/');
            }
        } else {
            foreach ($buckets as $bucket) {
                $root = ucwp_generated_asset_dir((string) $bucket);
                if ('' !== $root) {
                    $roots[] = $root;
                }
            }
        }

        foreach ($roots as $root) {
            if ('' !== (string) $root && function_exists('ucwp_path_is_within_root') && ucwp_path_is_within_root((string) $path, (string) $root)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ucwp_internal_cache_local_path_matches')) {
    /**
     * Check whether a filesystem path is inside UltraCache internal wp-content cache storage.
     *
     * @param string $path Filesystem path.
     * @return bool
     */
    function ucwp_internal_cache_local_path_matches($path)
    {
        $root = ucwp_content_cache_storage_dir();
        return '' !== $root && function_exists('ucwp_path_is_within_root') && ucwp_path_is_within_root((string) $path, $root);
    }
}

if (!function_exists('ucwp_uploads_base_info')) {
    /**
     * Return WordPress uploads base directory and URL using WordPress APIs.
     *
     * @return array{basedir:string,baseurl:string}
     */
    function ucwp_uploads_base_info()
    {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array());
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

        return array(
            'basedir' => untrailingslashit($basedir),
            'baseurl' => untrailingslashit($baseurl),
        );
    }
}



if (!function_exists('ucwp_uploads_public_path')) {
    /**
     * Return the current root-relative public uploads path using WordPress upload APIs.
     *
     * @param string $relative Optional relative path under uploads.
     * @return string
     */
    function ucwp_uploads_public_path($relative = '')
    {
        $uploads = ucwp_uploads_base_info();
        $url = ucwp_storage_join_url($uploads['baseurl'], ucwp_storage_clean_relative_path($relative));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
    }
}


if (!function_exists('ucwp_public_path_from_url')) {
    /**
     * Return a normalized root-relative public path from a URL.
     *
     * @param string $url Public URL.
     * @return string
     */
    function ucwp_public_path_from_url($url)
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
    }
}

if (!function_exists('ucwp_plugins_public_path')) {
    /**
     * Return the root-relative public plugins path using WordPress APIs.
     *
     * @param string $relative Optional relative path under plugins.
     * @return string
     */
    function ucwp_plugins_public_path($relative = '')
    {
        $relative = ucwp_storage_clean_relative_path($relative);
        $url = function_exists('plugins_url') ? plugins_url($relative) : '';

        return ucwp_public_path_from_url($url);
    }
}

if (!function_exists('ucwp_mu_plugins_public_path')) {
    /**
     * Return the root-relative public mu-plugins path when available.
     *
     * @param string $relative Optional relative path under mu-plugins.
     * @return string
     */
    function ucwp_mu_plugins_public_path($relative = '')
    {
        if (!defined('WPMU_PLUGIN_URL')) {
            return '';
        }

        return ucwp_public_path_from_url(ucwp_storage_join_url(WPMU_PLUGIN_URL, $relative));
    }
}

if (!function_exists('ucwp_themes_public_paths')) {
    /**
     * Return root-relative public theme root paths using WordPress APIs.
     *
     * @param string $relative Optional relative path under each theme root.
     * @return string[]
     */
    function ucwp_themes_public_paths($relative = '')
    {
        $paths = array();
        $relative = ucwp_storage_clean_relative_path($relative);

        if (function_exists('get_theme_root_uri')) {
            $path = ucwp_public_path_from_url(ucwp_storage_join_url(get_theme_root_uri(), $relative));
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
                $path = ucwp_public_path_from_url(ucwp_storage_join_url(dirname($uri), $relative));
                if ('' !== $path) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique(array_filter($paths, static function ($path) {
            return '' !== (string) $path;
        })));
    }
}

if (!function_exists('ucwp_public_path_contains')) {
    /**
     * Check whether a public URL/path contains a normalized root-relative marker.
     *
     * @param string $path   URL path or URL.
     * @param string $marker Root-relative public marker.
     * @return bool
     */
    function ucwp_public_path_contains($path, $marker)
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
}

if (!function_exists('ucwp_public_path_contains_any')) {
    /**
     * Check whether a public URL/path contains any normalized root-relative marker.
     *
     * @param string $path    URL path or URL.
     * @param array  $markers Root-relative public markers.
     * @return bool
     */
    function ucwp_public_path_contains_any($path, array $markers)
    {
        foreach ($markers as $marker) {
            if (ucwp_public_path_contains($path, (string) $marker)) {
                return true;
            }
        }

        return false;
    }
}


if (!function_exists('ucwp_public_path_relative_to_marker')) {
    /**
     * Return the path segment relative to a normalized root-relative public marker.
     *
     * @param string $source URL or root-relative public path.
     * @param string $marker Root-relative public marker ending at the desired root.
     * @return string|false
     */
    function ucwp_public_path_relative_to_marker($source, $marker)
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
}

if (!function_exists('ucwp_plugin_theme_owner_from_public_source')) {
    /**
     * Resolve plugin/theme owner metadata from a public URL/path using dynamic root markers.
     *
     * @param string $source Public URL or path.
     * @return array{kind:string,slug:string,group:string,relative:string}|array{}
     */
    function ucwp_plugin_theme_owner_from_public_source($source)
    {
        $candidates = array();
        if (function_exists('ucwp_plugins_public_path')) {
            $candidates[] = array('plugin', ucwp_plugins_public_path());
        }
        if (function_exists('ucwp_themes_public_paths')) {
            foreach (ucwp_themes_public_paths() as $theme_path) {
                $candidates[] = array('theme', $theme_path);
            }
        }

        foreach ($candidates as $candidate) {
            $kind = (string) $candidate[0];
            $marker = (string) $candidate[1];
            $relative = ucwp_public_path_relative_to_marker($source, $marker);
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
}

if (!function_exists('ucwp_get_wordpress_home_path')) {
    /**
     * Return the filesystem path that serves the WordPress home URL.
     *
     * get_home_path() correctly handles WordPress installed in a subdirectory while
     * the public site is served from its parent directory.
     *
     * @return string
     */
    function ucwp_get_wordpress_home_path()
    {
        if (!function_exists('get_home_path') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $home_path = function_exists('get_home_path') ? get_home_path() : (defined('ABSPATH') ? ABSPATH : '');
        $home_path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $home_path) : str_replace('\\', '/', (string) $home_path);

        return untrailingslashit($home_path);
    }
}

if (!function_exists('ucwp_get_server_document_root_path')) {
    /**
     * Return the effective public document root for path/security diagnostics.
     *
     * @return string
     */
    function ucwp_get_server_document_root_path()
    {
        $document_root = function_exists('ucwp_server_value') ? (string) ucwp_server_value('DOCUMENT_ROOT') : '';
        $document_root = function_exists('wp_normalize_path') ? wp_normalize_path($document_root) : str_replace('\\', '/', $document_root);
        $document_root = untrailingslashit($document_root);

        if ('' === $document_root) {
            $document_root = ucwp_get_wordpress_home_path();
        }

        return untrailingslashit((string) $document_root);
    }
}

if (!function_exists('ucwp_get_runtime_secret_site_token')) {
    /**
     * Return the stable site token used in the runtime-secret filename.
     *
     * @return string
     */
    function ucwp_get_runtime_secret_site_token()
    {
        $site_root = defined('ABSPATH') ? wp_normalize_path(untrailingslashit(ABSPATH)) : ucwp_get_wordpress_home_path();
        $token = wp_basename($site_root);
        $token = is_string($token) ? strtolower($token) : '';
        $token = preg_replace('/[^a-z0-9._-]+/', '-', $token);
        $token = trim((string) $token, '.-_');

        return '' !== $token ? $token : 'site';
    }
}

if (!function_exists('ucwp_get_runtime_secret_path')) {
    /**
     * Return the canonical runtime-secret path outside the effective document root.
     *
     * @return string
     */
    function ucwp_get_runtime_secret_path()
    {
        $roots = array(
            ucwp_get_server_document_root_path(),
            ucwp_get_wordpress_home_path(),
            defined('ABSPATH') ? untrailingslashit((string) ABSPATH) : '',
            defined('WP_CONTENT_DIR') ? untrailingslashit((string) WP_CONTENT_DIR) : '',
        );

        $base = '';
        foreach ($roots as $root) {
            $root = function_exists('wp_normalize_path') ? wp_normalize_path((string) $root) : str_replace('\\', '/', (string) $root);
            $root = untrailingslashit($root);
            if ('' === $root) {
                continue;
            }

            $candidate = dirname($root);
            if (is_string($candidate) && '' !== trim($candidate) && '.' !== $candidate && '/' !== $candidate) {
                $base = $candidate;
                break;
            }
        }

        if ('' === $base) {
            return '';
        }

        return rtrim($base, '/\\') . '/.' . ucwp_get_runtime_secret_site_token() . '-ultracache-runtime-secrets.php';
    }
}

if (!function_exists('ucwp_plugin_main_file')) {
    /**
     * Resolve an installed plugin's real main file from the WordPress plugin inventory.
     *
     * @param string $slug Plugin directory slug.
     * @return string
     */
    function ucwp_plugin_main_file($slug)
    {
        $slug = sanitize_key((string) $slug);
        if ('' === $slug || !defined('WP_PLUGIN_DIR')) {
            return '';
        }

        if (!function_exists('get_plugins') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
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

            // WP_PLUGIN_DIR is required here only to turn WordPress' verified plugin basename into a full main-file path.
            return wp_normalize_path(trailingslashit(WP_PLUGIN_DIR) . $plugin_basename);
        }

        return '';
    }
}

if (!function_exists('ucwp_plugin_root_dir')) {
    /**
     * Return an installed plugin root directory from its real main file.
     *
     * @param string $slug Plugin slug.
     * @return string
     */
    function ucwp_plugin_root_dir($slug)
    {
        $plugin_file = ucwp_plugin_main_file($slug);
        return '' !== $plugin_file ? untrailingslashit(wp_normalize_path(plugin_dir_path($plugin_file))) : '';
    }
}

if (!function_exists('ucwp_plugin_root_uri')) {
    /**
     * Return an installed plugin root URI from its real main file.
     *
     * @param string $slug Plugin slug.
     * @return string
     */
    function ucwp_plugin_root_uri($slug)
    {
        $plugin_file = ucwp_plugin_main_file($slug);
        return '' !== $plugin_file ? untrailingslashit(esc_url_raw(plugin_dir_url($plugin_file))) : '';
    }
}

if (!function_exists('ucwp_local_font_css_scan_roots')) {
    /**
     * Return local roots to scan for CSS font sources without hardcoded wp-content paths.
     *
     * @return string[]
     */
    function ucwp_local_font_css_scan_roots()
    {
        $roots = array();
        $candidates = array();

        if (defined('WP_PLUGIN_DIR')) {
            $candidates[] = WP_PLUGIN_DIR;
        }
        if (defined('WPMU_PLUGIN_DIR')) {
            $candidates[] = WPMU_PLUGIN_DIR;
        }
        if (function_exists('get_theme_root')) {
            $candidates[] = get_theme_root();
        }
        $uploads = ucwp_uploads_base_info();
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
}

if (!function_exists('ucwp_revslider_uploads_public_path')) {
    /**
     * Return Slider Revolution's public uploads path marker.
     *
     * Slider Revolution stores imported slider assets relative to wp_upload_dir(),
     * normally uploads/revslider/, so this must use wp_upload_dir() as its base.
     *
     * @param string $relative Optional relative path under uploads/revslider.
     * @return string
     */
    function ucwp_revslider_uploads_public_path($relative = '')
    {
        return ucwp_uploads_public_path('revslider/' . ucwp_storage_clean_relative_path($relative));
    }
}

if (!function_exists('ucwp_revslider_optimized_uploads_public_path')) {
    /**
     * Return Slider Revolution's optimized-image public uploads path marker.
     *
     * Slider Revolution stores generated optimized slider images under
     * uploads/revslider/o/ using wp_upload_dir() as its base.
     *
     * @param string $relative Optional relative path under uploads/revslider/o.
     * @return string
     */
    function ucwp_revslider_optimized_uploads_public_path($relative = '')
    {
        return ucwp_uploads_public_path('revslider/o/' . ucwp_storage_clean_relative_path($relative));
    }
}


if (!function_exists('ucwp_normalize_public_url')) {
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
    function ucwp_normalize_public_url($url, array $args = array())
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
}

if (!function_exists('ucwp_is_local_site_url')) {
    /**
     * Check whether a URL belongs to this WordPress site's home/site host.
     *
     * @param string $url Public URL.
     * @return bool
     */
    function ucwp_is_local_site_url($url)
    {
        $url = ucwp_normalize_public_url($url);
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
}

if (!function_exists('ucwp_path_is_within_root')) {
    /**
     * Check whether a filesystem path is inside a root directory.
     *
     * @param string $path Filesystem path.
     * @param string $root Filesystem root.
     * @return bool
     */
    function ucwp_path_is_within_root($path, $root)
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
}

if (!function_exists('ucwp_canonical_local_path_from_relative')) {
    /**
     * Resolve a local path from a trusted root and sanitized relative path.
     *
     * @param string $root     Root directory.
     * @param string $relative Relative public path.
     * @return string
     */
    function ucwp_canonical_local_path_from_relative($root, $relative)
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

        return ucwp_path_is_within_root($candidate_real, $root_real) ? wp_normalize_path($candidate_real) : '';
    }
}

if (!function_exists('ucwp_content_public_path')) {
    /**
     * Return the root-relative public wp-content path.
     *
     * @param string $relative Optional relative path under wp-content.
     * @return string
     */
    function ucwp_content_public_path($relative = '')
    {
        $url = ucwp_storage_join_url(content_url('/'), ucwp_storage_clean_relative_path($relative));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
    }
}

if (!function_exists('ucwp_content_cache_public_path')) {
    /**
     * Return the root-relative public internal cache path.
     *
     * @param string $relative Optional relative path under the internal UltraCache cache root.
     * @return string
     */
    function ucwp_content_cache_public_path($relative = '')
    {
        $path = (string) wp_parse_url(ucwp_content_cache_storage_url($relative), PHP_URL_PATH);
        if ('' === $path) {
            return '';
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($path)), '/'));
    }
}

if (!function_exists('ucwp_public_path_to_local_path')) {
    /**
     * Resolve a root-relative public URL path to a readable local file path.
     *
     * @param string $path         Root-relative URL path.
     * @param array  $allowed_exts Optional extension allow-list without dots.
     * @return string
     */
    function ucwp_public_path_to_local_path($path, array $allowed_exts = array())
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

        $cache_path = ucwp_content_cache_public_path();
        if ('' !== $cache_path && 0 === strpos($path, $cache_path)) {
            $relative = ltrim(substr($path, strlen($cache_path)), '/');
            $cache_root = ucwp_content_cache_storage_dir();
            if ('' !== $cache_root) {
                $candidates[] = array($cache_root, $relative);
            }
        }

        $uploads_path = ucwp_uploads_public_path();
        if ('' !== $uploads_path && 0 === strpos($path, $uploads_path)) {
            $relative = ltrim(substr($path, strlen($uploads_path)), '/');
            $uploads = ucwp_uploads_base_info();
            if (!empty($uploads['basedir'])) {
                $candidates[] = array($uploads['basedir'], $relative);
            }
        }

        $plugins_path = ucwp_plugins_public_path();
        if ('' !== $plugins_path && 0 === strpos($path, $plugins_path) && defined('WP_PLUGIN_DIR')) {
            $relative = ltrim(substr($path, strlen($plugins_path)), '/');
            $candidates[] = array(WP_PLUGIN_DIR, $relative);
        }

        $mu_plugins_path = ucwp_mu_plugins_public_path();
        if ('' !== $mu_plugins_path && 0 === strpos($path, $mu_plugins_path) && defined('WPMU_PLUGIN_DIR')) {
            $relative = ltrim(substr($path, strlen($mu_plugins_path)), '/');
            $candidates[] = array(WPMU_PLUGIN_DIR, $relative);
        }

        if (function_exists('get_theme_root')) {
            foreach (ucwp_themes_public_paths() as $theme_path) {
                if ('' === $theme_path || 0 !== strpos($path, $theme_path)) {
                    continue;
                }
                $relative = ltrim(substr($path, strlen($theme_path)), '/');
                $candidates[] = array(get_theme_root(), $relative);
            }
        }

        $includes_path = (string) wp_parse_url(includes_url('/'), PHP_URL_PATH);
        $includes_path = trailingslashit('/' . ltrim(str_replace('\\', '/', rawurldecode($includes_path)), '/'));
        if ('' !== trim($includes_path, '/') && 0 === strpos($path, $includes_path) && defined('ABSPATH') && defined('WPINC')) {
            $relative = ltrim(substr($path, strlen($includes_path)), '/');
            // WordPress core include URLs map to the required ABSPATH . WPINC directory.
            $candidates[] = array(trailingslashit(ABSPATH) . WPINC, $relative);
        }

        foreach ($candidates as $candidate) {
            $root = isset($candidate[0]) ? (string) $candidate[0] : '';
            $relative = isset($candidate[1]) ? (string) $candidate[1] : '';
            if ('' === $root || '' === $relative) {
                continue;
            }
            $resolved = ucwp_canonical_local_path_from_relative($root, $relative);
            if ('' !== $resolved && is_file($resolved) && is_readable($resolved)) {
                return $resolved;
            }
        }

        return '';
    }
}

if (!function_exists('ucwp_local_path_from_public_url')) {
    /**
     * Resolve a local readable file path from a local public URL.
     *
     * @param string $url          Public URL or root-relative URL.
     * @param array  $allowed_exts Optional extension allow-list without dots.
     * @return string
     */
    function ucwp_local_path_from_public_url($url, array $allowed_exts = array())
    {
        $url = ucwp_normalize_public_url($url, array('strip_query' => true));
        if ('' === $url || !ucwp_is_local_site_url($url)) {
            return '';
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ('' === $path && 0 === strpos($url, '/')) {
            $path = $url;
        }
        if ('' === $path) {
            return '';
        }

        return ucwp_public_path_to_local_path($path, $allowed_exts);
    }
}

if (!function_exists('ucwp_public_url_from_local_path')) {
    /**
     * Convert a local path under known WordPress public roots to a public URL.
     *
     * @param string $path Local filesystem path.
     * @return string
     */
    function ucwp_public_url_from_local_path($path)
    {
        $path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
        if ('' === $path) {
            return '';
        }

        $roots = array();
        $cache_root = ucwp_content_cache_storage_dir();
        if ('' !== $cache_root) {
            $roots[] = array(wp_normalize_path($cache_root), ucwp_content_cache_storage_url());
        }
        $uploads = ucwp_uploads_base_info();
        if (!empty($uploads['basedir']) && !empty($uploads['baseurl'])) {
            $roots[] = array(wp_normalize_path($uploads['basedir']), $uploads['baseurl']);
        }
        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = array(wp_normalize_path(WP_PLUGIN_DIR), plugins_url());
        }
        if (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) {
            $roots[] = array(wp_normalize_path(WPMU_PLUGIN_DIR), WPMU_PLUGIN_URL);
        }
        if (function_exists('get_theme_root') && function_exists('get_theme_root_uri')) {
            $roots[] = array(wp_normalize_path(get_theme_root()), get_theme_root_uri());
        }
        if (defined('ABSPATH') && defined('WPINC')) {
            // WordPress core include files intentionally resolve from ABSPATH . WPINC.
            $roots[] = array(wp_normalize_path(trailingslashit(ABSPATH) . WPINC), includes_url('/'));
        }

        foreach ($roots as $root) {
            $root_path = untrailingslashit((string) $root[0]);
            $root_url = trailingslashit((string) $root[1]);
            if ('' === $root_path || !ucwp_path_is_within_root($path, $root_path)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($root_path)), '/');
            if ('' === $relative) {
                continue;
            }
            return ucwp_normalize_public_url(ucwp_storage_join_url($root_url, $relative));
        }

        return '';
    }
}

if (!function_exists('ucwp_optimized_images_storage_dir')) {
    /**
     * Return the current optimized media derivative directory.
     *
     * Optimized image derivatives are public generated media assets under uploads/ultracache/images.
     *
     * @param string $format Optional image format such as avif or webp.
     * @return string
     */
    function ucwp_optimized_images_storage_dir($format = '')
    {
        $uploads = ucwp_uploads_base_info();
        $relative = 'ultracache/images';
        $format = strtolower(trim((string) $format));
        if (in_array($format, array('avif', 'webp'), true)) {
            $relative .= '/' . $format;
        }

        return trailingslashit(ucwp_storage_join_path($uploads['basedir'], $relative . '/'));
    }
}

if (!function_exists('ucwp_optimized_images_storage_url')) {
    /**
     * Return the current full URL for optimized media derivatives.
     *
     * @param string $format Optional image format such as avif or webp.
     * @return string
     */
    function ucwp_optimized_images_storage_url($format = '')
    {
        $uploads = ucwp_uploads_base_info();
        $relative = 'ultracache/images';
        $format = strtolower(trim((string) $format));
        if (in_array($format, array('avif', 'webp'), true)) {
            $relative .= '/' . $format;
        }

        return ucwp_storage_join_url($uploads['baseurl'], $relative . '/');
    }
}

if (!function_exists('ucwp_optimized_images_storage_url_path')) {
    /**
     * Return the current root-relative public path for optimized media derivatives.
     *
     * @param string $format Optional image format such as avif or webp.
     * @return string
     */
    function ucwp_optimized_images_storage_url_path($format = '')
    {
        $path = (string) wp_parse_url(ucwp_optimized_images_storage_url($format), PHP_URL_PATH);
        if ('' === $path) {
            $path = ucwp_uploads_public_path('ultracache/images');
            $format = strtolower(trim((string) $format));
            if (in_array($format, array('avif', 'webp'), true)) {
                $path = ucwp_uploads_public_path('ultracache/images/' . $format);
            }
        }

        return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
    }
}

if (!function_exists('ucwp_dropin_path')) {
    /**
     * Return the WordPress-required path for supported drop-in files.
     *
     * Drop-ins are intentionally kept directly under WP_CONTENT_DIR because WordPress
     * itself only discovers advanced-cache.php and object-cache.php from that directory.
     * This helper centralizes that required exception so generated public assets can
     * still use uploads-based storage without treating drop-ins as movable assets.
     *
     * @param string $basename Drop-in basename.
     * @return string
     */
    function ucwp_dropin_path($basename)
    {
        $basename = sanitize_file_name((string) $basename);
        if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true) || !defined('WP_CONTENT_DIR')) {
            return '';
        }

        return ucwp_storage_join_path(WP_CONTENT_DIR, $basename);
    }
}

if (!function_exists('ucwp_is_sensitive_debug_key')) {
    function ucwp_is_sensitive_debug_key($key)
    {
        $key = strtolower((string) $key);
        if ('' === $key) {
            return false;
        }

        return 1 === preg_match('/(?:^key$|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|redis[_-]?password|varnish.*key|varnish.*secret|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret|ucwp_rt|x[-_]?ultracache[-_]?token)/i', $key);
    }
}

if (!function_exists('ucwp_redact_sensitive_string')) {
    function ucwp_redact_sensitive_string($value)
    {
        $value = (string) $value;

        $json_key_pattern = '(?:redis_password|redisPassword|varnish_admin_secret|varnishCliKey|password|passwd|pwd|secret|token|authorization|cookie|nonce|auth|credential|security|api[_-]?key|access[_-]?key|private[_-]?key|order[_-]?key|client[_-]?secret|key|ucwp_rt|x[-_]?ultracache[-_]?token)';
        $value = preg_replace('/((?:"|\')' . $json_key_pattern . '(?:"|\')\s*:\s*(?:"|\'))[^"\']*((?:"|\'))/i', '$1[redacted]$2', $value);
        $value = preg_replace('/(' . $json_key_pattern . '\s*[=:]\s*)[^,\s&}\]]+/i', '$1[redacted]', $value);
        $value = preg_replace('/((?:password|passwd|pwd|secret|token|nonce|auth|credential|security|key)=)([^&\s]+)/i', '$1[redacted]', $value);
        $value = preg_replace('/((?:ucwp_rt|ucwp_revalidate|ucwp_profile_bypass|ucwp_store_profile|ucwp_store_profile_verbose|ucwp_store_profile_verbose_settings|ucwp_callback_profile|ucwp_profile_run)=)([^&\s]+)/i', '$1[redacted]', $value);

        if (preg_match('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', $value)) {
            $value = preg_replace('/(?:bearer\s+|basic\s+)[a-z0-9._~+\/=:-]+/i', '[redacted]', $value);
        }

        return $value;
    }
}

if (!function_exists('ucwp_redact_sensitive_debug_value')) {
    function ucwp_redact_sensitive_debug_value($key, $value, $depth = 0)
    {
        if (ucwp_is_sensitive_debug_key($key)) {
            return '[redacted]';
        }

        if ($depth > 8) {
            return is_scalar($value) || null === $value ? ucwp_redact_sensitive_string((string) $value) : '[truncated]';
        }

        if (is_array($value)) {
            $redacted = array();
            foreach ($value as $child_key => $child_value) {
                $redacted[$child_key] = ucwp_redact_sensitive_debug_value($child_key, $child_value, $depth + 1);
            }
            return $redacted;
        }

        if (is_string($value)) {
            return ucwp_redact_sensitive_string($value);
        }

        return $value;
    }
}

if (!function_exists('ucwp_redact_sensitive_debug_context')) {
    function ucwp_redact_sensitive_debug_context(array $context)
    {
        $redacted = array();
        foreach ($context as $key => $value) {
            $redacted[$key] = ucwp_redact_sensitive_debug_value($key, $value, 0);
        }
        return $redacted;
    }
}

if (!function_exists('ucwp_debug_log')) {
    function ucwp_debug_log($message, array $context = array())
    {
        /**
         * Fires when UltraCache emits a debug event. Sensitive values are redacted before hooks receive the context.
         *
         * @param string $message Debug message.
         * @param array  $context Context data.
         */
        if (function_exists('ucwp_redact_sensitive_debug_context')) {
            $context = ucwp_redact_sensitive_debug_context($context);
        }
        do_action('ucwp_debug_log', (string) $message, $context);
    }
}

if (!function_exists('ucwp_get_wp_filesystem')) {
    function ucwp_get_wp_filesystem()
    {
        static $initialized = null;
        global $wp_filesystem;

        if (true === $initialized && is_object($wp_filesystem)) {
            return $wp_filesystem;
        }

        if (false === $initialized) {
            return false;
        }

        $initialized = false;

        if (!defined('ABSPATH')) {
            return false;
        }

        if (!function_exists('WP_Filesystem')) {
            $file_api = ABSPATH . 'wp-admin/includes/file.php';
            if (!file_exists($file_api)) {
                return false;
            }
            require_once $file_api;
        }

        if (!function_exists('WP_Filesystem')) {
            return false;
        }

        if (!WP_Filesystem()) {
            return false;
        }

        if (!is_object($wp_filesystem)) {
            return false;
        }

        $initialized = true;
        return $wp_filesystem;
    }
}


if (!function_exists('ucwp_path_is_writable')) {
    function ucwp_path_is_writable($path)
    {
        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem && method_exists($filesystem, 'is_writable')) {
            return (bool) $filesystem->is_writable($path);
        }

        if (function_exists('wp_is_writable')) {
            return wp_is_writable($path);
        }

        return false;
    }
}

if (!function_exists('ucwp_server_value')) {
    function ucwp_server_value($key)
    {
        if (!is_string($key) || '' === $key) {
            return '';
        }

        $value = null;
        if (function_exists('filter_input')) {
            $value = filter_input(INPUT_SERVER, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        if (null === $value || false === $value) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Fallback only when filter_input() does not expose server values.
            $value = isset($_SERVER[$key]) ? wp_unslash($_SERVER[$key]) : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('ucwp_server_flag_enabled')) {
    function ucwp_server_flag_enabled($key)
    {
        $value = strtolower(ucwp_server_value($key));
        return '' !== $value && 'off' !== $value && '0' !== $value;
    }
}

if (!function_exists('ucwp_query_value')) {
    function ucwp_query_value($key)
    {
        if (!is_string($key) || '' === $key) {
            return '';
        }

        $value = null;
        if (function_exists('filter_input')) {
            $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        if (null === $value || false === $value) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Fallback only when filter_input() does not expose query values.
            $value = isset($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}


if (!function_exists('ucwp_parse_size_to_bytes')) {
    function ucwp_parse_size_to_bytes($size)
    {
        $size = trim((string) $size);
        if ('' === $size || '-1' === $size) {
            return 0;
        }

        if (!preg_match('/^([0-9]+)\s*([kmg])?b?$/i', $size, $matches)) {
            return max(0, (int) $size);
        }

        $bytes = (int) $matches[1];
        $unit = isset($matches[2]) ? strtolower((string) $matches[2]) : '';
        if ('g' === $unit) {
            $bytes *= 1024 * 1024 * 1024;
        } elseif ('m' === $unit) {
            $bytes *= 1024 * 1024;
        } elseif ('k' === $unit) {
            $bytes *= 1024;
        }

        return max(0, $bytes);
    }
}

if (!function_exists('ucwp_is_cli_context')) {
    function ucwp_is_cli_context()
    {
        return (defined('WP_CLI') && WP_CLI) || 'cli' === PHP_SAPI;
    }
}

if (!function_exists('ucwp_get_safe_operation_budget')) {
    function ucwp_get_safe_operation_budget($context = 'rest', $requested = null, $hard_cap = null)
    {
        $context = sanitize_key((string) $context);
        $is_cli = ucwp_is_cli_context();
        $max_execution = (int) ini_get('max_execution_time');
        $memory_limit = ucwp_parse_size_to_bytes((string) ini_get('memory_limit'));

        $default_requested = $is_cli ? 120 : 20;
        if ('cron' === $context || false !== strpos($context, 'warm')) {
            $default_requested = $is_cli ? 120 : 20;
        } elseif (false !== strpos($context, 'background')) {
            $default_requested = 3;
        }

        $requested = null === $requested ? $default_requested : (int) $requested;
        $requested = max(0, $requested);

        $detected = $is_cli ? 300 : 20;
        if ($max_execution > 0) {
            $margin = max(3, min(10, (int) ceil($max_execution * 0.20)));
            $detected = max(3, $max_execution - $margin);
        }

        $cap = null === $hard_cap ? ($is_cli ? 300 : 45) : max(1, (int) $hard_cap);
        $seconds = $requested > 0 ? min($requested, $detected, $cap) : min($detected, $cap);
        $seconds = max(1, (int) $seconds);

        return array(
            'context' => $context,
            'started_at' => microtime(true),
            'seconds' => $seconds,
            'max_execution_time' => $max_execution,
            'memory_limit_bytes' => $memory_limit,
            'memory_stop_bytes' => $memory_limit > 0 ? (int) floor($memory_limit * 0.80) : 0,
        );
    }
}

if (!function_exists('ucwp_operation_pause_reason')) {
    function ucwp_operation_pause_reason(array $budget)
    {
        $seconds = isset($budget['seconds']) ? max(0, (int) $budget['seconds']) : 0;
        $started_at = isset($budget['started_at']) ? (float) $budget['started_at'] : 0.0;
        if ($seconds > 0 && $started_at > 0 && (microtime(true) - $started_at) >= $seconds) {
            return 'time_budget';
        }

        $memory_stop = isset($budget['memory_stop_bytes']) ? max(0, (int) $budget['memory_stop_bytes']) : 0;
        if ($memory_stop > 0 && function_exists('memory_get_usage') && memory_get_usage(true) >= $memory_stop) {
            return 'memory_budget';
        }

        return '';
    }
}

if (!function_exists('ucwp_runtime_control_secret')) {
    function ucwp_runtime_control_secret()
    {
        if (!function_exists('wp_hash')) {
            return '';
        }

        return (string) wp_hash('ucwp-revalidate-v1');
    }
}

if (!function_exists('ucwp_create_runtime_control_token')) {
    function ucwp_create_runtime_control_token($secret = '', $issued_at = null)
    {
        $secret = is_string($secret) && '' !== trim($secret) ? (string) $secret : ucwp_runtime_control_secret();
        if ('' === $secret) {
            return '';
        }

        $issued_at = null === $issued_at ? time() : (int) $issued_at;
        if ($issued_at <= 0) {
            return '';
        }

        $payload = 'v2|' . (string) $issued_at . '|ucwp-runtime-control';
        $mac = hash_hmac('sha256', $payload, $secret);

        return 'v2:' . (string) $issued_at . ':' . $mac;
    }
}

if (!function_exists('ucwp_validate_runtime_control_token')) {
    function ucwp_validate_runtime_control_token($token, $secret = '', $ttl = 900)
    {
        $token = is_scalar($token) ? trim((string) $token) : '';
        if ('' === $token || strlen($token) > 160) {
            return false;
        }

        if (function_exists('sanitize_text_field')) {
            $token = sanitize_text_field($token);
        }

        $secret = is_string($secret) && '' !== trim($secret) ? (string) $secret : ucwp_runtime_control_secret();
        if ('' === $secret) {
            return false;
        }

        $parts = explode(':', $token);
        if (3 !== count($parts) || 'v2' !== $parts[0]) {
            return false;
        }

        $issued_at = (int) $parts[1];
        $mac = (string) $parts[2];
        $ttl = max(60, min(3600, (int) $ttl));
        $now = time();
        if ($issued_at <= 0 || $issued_at > ($now + 60) || ($now - $issued_at) > $ttl) {
            return false;
        }

        if (1 !== preg_match('/^[a-f0-9]{64}$/', $mac)) {
            return false;
        }

        $expected = hash_hmac('sha256', 'v2|' . (string) $issued_at . '|ucwp-runtime-control', $secret);

        return function_exists('hash_equals') ? hash_equals($expected, $mac) : $expected === $mac;
    }
}

if (!function_exists('ucwp_request_profile_token_valid')) {
    function ucwp_request_profile_token_valid()
    {
        $token = trim((string) ucwp_query_value('ucwp_rt'));
        if ('' === $token) {
            $token = trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_TOKEN'));
        }

        return ucwp_validate_runtime_control_token($token);
    }
}

if (!function_exists('ucwp_request_profiler_enabled')) {
    function ucwp_request_profiler_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE')));
        $constant_flag = defined('UCWP_STORE_PROFILE') && UCWP_STORE_PROFILE;

        $requested = ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
        if (!$requested) {
            return false;
        }

        return ucwp_request_profile_token_valid();
    }
}

if (!function_exists('ucwp_request_callback_profiler_enabled')) {
    function ucwp_request_callback_profiler_enabled()
    {
        if (!ucwp_request_profiler_enabled()) {
            return false;
        }

        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_callback_profile')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_CALLBACK_PROFILE')));
        $constant_flag = defined('UCWP_CALLBACK_PROFILE') && UCWP_CALLBACK_PROFILE;

        return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag);
    }
}

if (!function_exists('ucwp_request_profile_request_started_at')) {
    function ucwp_request_profile_request_started_at()
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }

        if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
            return (float) $_SERVER['REQUEST_TIME'];
        }

        return microtime(true);
    }
}

if (!function_exists('ucwp_request_profile_sanitize_stage')) {
    function ucwp_request_profile_sanitize_stage($stage)
    {
        $stage = strtolower((string) $stage);
        $stage = preg_replace('/[^a-z0-9_\-.]+/', '_', $stage);
        $stage = trim((string) $stage, '_-.');

        return '' !== $stage ? substr($stage, 0, 96) : 'checkpoint';
    }
}

if (!function_exists('ucwp_request_profile_current_hook_summary')) {
    function ucwp_request_profile_current_hook_summary($hook_name)
    {
        global $wp_filter;

        $hook_name = (string) $hook_name;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name])) {
            return array();
        }

        $callbacks = isset($wp_filter[$hook_name]->callbacks) && is_array($wp_filter[$hook_name]->callbacks) ? $wp_filter[$hook_name]->callbacks : array();
        if (empty($callbacks)) {
            return array('hook' => $hook_name, 'callback_count' => 0, 'priorities' => array());
        }

        $count = 0;
        $priorities = array();
        foreach ($callbacks as $priority => $items) {
            $item_count = is_array($items) ? count($items) : 0;
            $count += $item_count;
            $priorities[] = (string) $priority . ':' . (string) $item_count;
        }

        return array(
            'hook' => $hook_name,
            'callback_count' => $count,
            'priorities' => $priorities,
        );
    }
}

if (!function_exists('ucwp_request_profile_callback_label')) {
    function ucwp_request_profile_callback_label($callback)
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback) && count($callback) >= 2) {
            $target = $callback[0];
            $method = is_scalar($callback[1]) ? (string) $callback[1] : 'unknown';
            if (is_object($target)) {
                return get_class($target) . '->' . $method;
            }
            if (is_string($target)) {
                return $target . '::' . $method;
            }
        }

        if ($callback instanceof Closure) {
            return 'Closure';
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return get_class($callback) . '::__invoke';
        }

        return 'unknown_callback';
    }
}

if (!function_exists('ucwp_request_profile_hook_priority_details')) {
    function ucwp_request_profile_hook_priority_details($hook_name, $priority, $limit = 30)
    {
        global $wp_filter;

        $hook_name = (string) $hook_name;
        $priority_key = (string) $priority;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name])) {
            return array(
                'hook' => $hook_name,
                'priority' => $priority_key,
                'callback_count' => 0,
                'callbacks' => array(),
            );
        }

        $callbacks = isset($wp_filter[$hook_name]->callbacks) && is_array($wp_filter[$hook_name]->callbacks) ? $wp_filter[$hook_name]->callbacks : array();
        $items = array();
        if (array_key_exists($priority, $callbacks) && is_array($callbacks[$priority])) {
            $items = $callbacks[$priority];
        } elseif (array_key_exists($priority_key, $callbacks) && is_array($callbacks[$priority_key])) {
            $items = $callbacks[$priority_key];
        }

        $labels = array();
        $count = 0;
        foreach ($items as $item) {
            $count++;
            if (count($labels) >= (int) $limit) {
                continue;
            }
            $cb = is_array($item) && array_key_exists('function', $item) ? $item['function'] : $item;
            $labels[] = ucwp_request_profile_callback_label($cb);
        }

        return array(
            'hook' => $hook_name,
            'priority' => $priority_key,
            'callback_count' => $count,
            'callbacks' => $labels,
            'truncated' => $count > count($labels),
        );
    }
}

if (!function_exists('ucwp_request_profile_verbose_settings_enabled')) {
    function ucwp_request_profile_verbose_settings_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose_settings')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE_SETTINGS')));
        $constant_flag = defined('UCWP_STORE_PROFILE_VERBOSE_SETTINGS') && UCWP_STORE_PROFILE_VERBOSE_SETTINGS;
        $query_verbose_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose')));
        $header_verbose_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
        $constant_verbose_flag = defined('UCWP_STORE_PROFILE_VERBOSE') && UCWP_STORE_PROFILE_VERBOSE;

        return (
            '1' === $query_flag
            || 'true' === $query_flag
            || '1' === $header_flag
            || 'true' === $header_flag
            || $constant_flag
            || '1' === $query_verbose_flag
            || 'true' === $query_verbose_flag
            || '1' === $header_verbose_flag
            || 'true' === $header_verbose_flag
            || $constant_verbose_flag
        );
    }
}


if (!function_exists('ucwp_request_profile_verbose_enabled')) {
    function ucwp_request_profile_verbose_enabled()
    {
        $query_flag = strtolower(trim((string) ucwp_query_value('ucwp_store_profile_verbose')));
        $header_flag = strtolower(trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE_VERBOSE')));
        $constant_flag = defined('UCWP_STORE_PROFILE_VERBOSE') && UCWP_STORE_PROFILE_VERBOSE;

        return ('1' === $query_flag || 'true' === $query_flag || '1' === $header_flag || 'true' === $header_flag || $constant_flag || ucwp_request_profile_verbose_settings_enabled());
    }
}

if (!function_exists('ucwp_request_profile_compact_stages')) {
    function ucwp_request_profile_compact_stages()
    {
        return array(
            'plugin_file_loaded' => true,
            'ultracache_wp_construct_start' => true,
            'ultracache_dependencies_loaded' => true,
            'ultracache_hooks_registered' => true,
            'dependency_load_start' => true,
            'dependency_load_end' => true,
            'engine_construct' => true,
            'plugins_loaded_p-1000' => true,
            'plugins_loaded_p0' => true,
            'plugins_loaded_p4_before_components' => true,
            'plugins_loaded_p5_before_components' => true,
            'plugins_loaded_p5_components' => true,
            'plugins_loaded_p6_after_components' => true,
            'plugins_loaded_p18_before_reconcile' => true,
            'plugins_loaded_p19_before_page_cache_reconcile' => true,
            'page_cache_reconcile_skipped' => true,
            'page_cache_reconcile_light_start' => true,
            'page_cache_reconcile_light_end' => true,
            'page_cache_reconcile_full_start' => true,
            'page_cache_reconcile_full_end' => true,
            'plugins_loaded_p20_before_object_cache_reconcile' => true,
            'object_cache_reconcile_skipped' => true,
            'object_cache_reconcile_light_start' => true,
            'object_cache_reconcile_light_end' => true,
            'object_cache_reconcile_full_start' => true,
            'object_cache_reconcile_full_end' => true,
            'plugins_loaded_p21_before_runtime_config_reconcile' => true,
            'runtime_config_reconcile_skipped' => true,
            'runtime_config_reconcile_light_start' => true,
            'runtime_config_reconcile_light_end' => true,
            'runtime_config_reconcile_full_before_sync' => true,
            'runtime_config_reconcile_full_after_sync' => true,
            'plugins_loaded_p22_after_reconcile' => true,
            'plugins_loaded_end' => true,
            'setup_theme_start' => true,
            'setup_theme_end' => true,
            'after_setup_theme_start' => true,
            'after_setup_theme_end' => true,
            'init_start' => true,
            'init' => true,
            'init_end' => true,
            'wp_loaded_start' => true,
            'wp_loaded' => true,
            'wp_loaded_end' => true,
            'template_redirect_global_start' => true,
            'template_redirect_start' => true,
            'maybe_start_buffering_start' => true,
            'maybe_start_buffering_reentry_return' => true,
            'maybe_start_buffering_after_reentry_check' => true,
            'maybe_start_buffering_before_should_bypass' => true,
            'maybe_start_buffering_after_should_bypass' => true,
            'bypass_selected' => true,
            'should_bypass_return' => true,
            'early_hit_check_start' => true,
            'early_hit_check_end' => true,
            'early_hit_no_file_return' => true,
            'early_hit_served' => true,
            'page_generation_lock_before' => true,
            'page_generation_lock_before_current_url' => true,
            'page_generation_lock_after_current_url' => true,
            'page_generation_lock_before_cache_path' => true,
            'page_generation_lock_after_cache_path' => true,
            'page_generation_lock_acquire_start' => true,
            'page_generation_lock_acquired' => true,
            'page_generation_lock_checked' => true,
            'record_analytics_miss_start' => true,
            'record_analytics_miss_end' => true,
            'send_debug_headers_start' => true,
            'send_debug_headers_end' => true,
            'buffer_start' => true,
            'diagnostic_fallback_output_buffer_started' => true,
            'diagnostic_fallback_output_buffer_flush_start' => true,
            'diagnostic_fallback_output_buffer_flush_step' => true,
            'diagnostic_fallback_output_buffer_missing_on_shutdown' => true,
            'diagnostic_fallback_output_buffer_callback' => true,
            'diagnostic_fallback_output_buffer_store_start' => true,
            'template_redirect_global_end' => true,
            'wp_head_start' => true,
            'wp_enqueue_scripts_start' => true,
            'wp_enqueue_scripts_end' => true,
            'wp_head_end' => true,
            'shutdown_start' => true,
            'cache_output_callback_start' => true,
            'store_profile_start' => true,
            'store_profile_diagnostic_skip_start' => true,
            'cache_output_callback_end' => true,
            'store_profile_finalize_start' => true,
            'output_buffer_callback_missing' => true,
            'shutdown_end' => true,
            'engine_shutdown_profile_update' => true,
            'callback_slow' => true,
        );
    }
}

if (!function_exists('ucwp_request_profile_should_record_checkpoint')) {
    function ucwp_request_profile_should_record_checkpoint($stage)
    {
        $stage = ucwp_request_profile_sanitize_stage($stage);
        if (ucwp_request_profile_verbose_enabled()) {
            return true;
        }

        $compact = ucwp_request_profile_compact_stages();
        if (isset($compact[$stage])) {
            return true;
        }

        if (0 === strpos($stage, 'advanced_cache_setup_')) {
            return true;
        }

        if (0 === strpos($stage, 'callback_') && ucwp_request_callback_profiler_enabled()) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ucwp_request_profile_settings_checkpoint')) {
    function ucwp_request_profile_settings_checkpoint($stage, array $extra = array())
    {
        if (!ucwp_request_profile_verbose_settings_enabled()) {
            return;
        }

        ucwp_request_profile_checkpoint($stage, $extra);
    }
}

if (!function_exists('ucwp_request_profile_normalize_path')) {
    function ucwp_request_profile_normalize_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        return preg_replace('#/+#', '/', $path);
    }
}

if (!function_exists('ucwp_request_profile_relative_path')) {
    function ucwp_request_profile_relative_path($file, $base)
    {
        $file = ucwp_request_profile_normalize_path($file);
        $base = rtrim(ucwp_request_profile_normalize_path($base), '/') . '/';
        if ('' !== $base && 0 === strpos($file, $base)) {
            return ltrim(substr($file, strlen($base)), '/');
        }

        return '';
    }
}

if (!function_exists('ucwp_request_profile_callback_origin')) {
    function ucwp_request_profile_callback_origin($callback)
    {
        $file = '';
        $line = 0;
        try {
            if (is_string($callback) && function_exists($callback)) {
                $ref = new ReflectionFunction($callback);
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            } elseif ($callback instanceof Closure) {
                $ref = new ReflectionFunction($callback);
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            } elseif (is_array($callback) && count($callback) >= 2) {
                $target = $callback[0];
                $method = is_scalar($callback[1]) ? (string) $callback[1] : '';
                if ('' !== $method && (is_object($target) || is_string($target)) && method_exists($target, $method)) {
                    $ref = new ReflectionMethod($target, $method);
                    $file = (string) $ref->getFileName();
                    $line = (int) $ref->getStartLine();
                }
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $ref = new ReflectionMethod($callback, '__invoke');
                $file = (string) $ref->getFileName();
                $line = (int) $ref->getStartLine();
            }
        } catch (Throwable $e) {
            $file = '';
            $line = 0;
        }

        $file = ucwp_request_profile_normalize_path($file);
        $type = 'unknown';
        $name = '';
        $relative = '';

        if ('' !== $file) {
            $plugin_dir = defined('WP_PLUGIN_DIR') ? ucwp_request_profile_normalize_path(WP_PLUGIN_DIR) : '';
            $mu_plugin_dir = defined('WPMU_PLUGIN_DIR') ? ucwp_request_profile_normalize_path(WPMU_PLUGIN_DIR) : '';
            $content_dir = defined('WP_CONTENT_DIR') ? ucwp_request_profile_normalize_path(WP_CONTENT_DIR) : '';
            $abs_path = defined('ABSPATH') ? ucwp_request_profile_normalize_path(ABSPATH) : '';

            if ('' !== $plugin_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $plugin_dir))) {
                $type = 'plugin';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif ('' !== $mu_plugin_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $mu_plugin_dir))) {
                $type = 'mu-plugin';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif (function_exists('get_stylesheet_directory') && '' !== ($relative = ucwp_request_profile_relative_path($file, get_stylesheet_directory()))) {
                $type = 'theme';
                $name = function_exists('get_stylesheet') ? (string) get_stylesheet() : 'stylesheet';
            } elseif (function_exists('get_template_directory') && '' !== ($relative = ucwp_request_profile_relative_path($file, get_template_directory()))) {
                $type = 'theme';
                $name = function_exists('get_template') ? (string) get_template() : 'template';
            } elseif ('' !== $content_dir && '' !== ($relative = ucwp_request_profile_relative_path($file, $content_dir))) {
                $type = 'wp-content';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            } elseif ('' !== $abs_path && '' !== ($relative = ucwp_request_profile_relative_path($file, $abs_path))) {
                $type = 'core';
                $parts = explode('/', $relative);
                $name = (string) reset($parts);
            }
        }

        return array(
            'type' => $type,
            'name' => $name,
            'file' => $file,
            'relative_file' => $relative,
            'line' => $line,
        );
    }
}

if (!function_exists('ucwp_request_profile_record_callback_timing')) {
    function ucwp_request_profile_record_callback_timing($hook_name, $priority, $index, $callback_id, $label, array $origin, $start_ms, $duration_ms)
    {
        if (!ucwp_request_callback_profiler_enabled()) {
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_callback_timings']) || !is_array($GLOBALS['ucwp_request_profile_callback_timings'])) {
            $GLOBALS['ucwp_request_profile_callback_timings'] = array();
        }

        if (!isset($GLOBALS['ucwp_request_profile_callback_timing_summary']) || !is_array($GLOBALS['ucwp_request_profile_callback_timing_summary'])) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'] = array();
        }

        $duration_ms = (int) max(0, $duration_ms);
        $entry = array(
            'hook' => (string) $hook_name,
            'priority' => (string) $priority,
            'callback_index' => (string) $index,
            'callback_id' => (string) $callback_id,
            'callback_label' => (string) $label,
            'duration_ms' => $duration_ms,
            'start_ms' => (int) max(0, $start_ms),
            'origin_type' => (string) ($origin['type'] ?? ''),
            'origin_name' => (string) ($origin['name'] ?? ''),
            'origin_file' => (string) ($origin['relative_file'] ?? ''),
            'origin_line' => (int) ($origin['line'] ?? 0),
        );

        $GLOBALS['ucwp_request_profile_callback_timings'][] = $entry;

        $key = implode('|', array($entry['hook'], $entry['priority'], $entry['callback_label'], $entry['origin_type'], $entry['origin_name'], $entry['origin_file']));
        if (!isset($GLOBALS['ucwp_request_profile_callback_timing_summary'][$key])) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key] = array(
                'hook' => $entry['hook'],
                'priority' => $entry['priority'],
                'callback_label' => $entry['callback_label'],
                'origin_type' => $entry['origin_type'],
                'origin_name' => $entry['origin_name'],
                'origin_file' => $entry['origin_file'],
                'count' => 0,
                'total_ms' => 0,
                'max_ms' => 0,
            );
        }

        $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['count']++;
        $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['total_ms'] += $duration_ms;
        if ($duration_ms > (int) $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['max_ms']) {
            $GLOBALS['ucwp_request_profile_callback_timing_summary'][$key]['max_ms'] = $duration_ms;
        }

        if ($duration_ms >= 200) {
            ucwp_request_profile_checkpoint('callback_slow', array(
                'target_hook' => $entry['hook'],
                'target_priority' => $entry['priority'],
                'target_callback' => $entry['callback_label'],
                'callback' => $entry['callback_label'],
                'callback_label' => $entry['callback_label'],
                'duration_ms' => $duration_ms,
                'origin_type' => $entry['origin_type'],
                'origin_name' => $entry['origin_name'],
                'origin_file' => $entry['origin_file'],
                'plugin' => ('plugin' === $entry['origin_type']) ? $entry['origin_name'] : '',
                'file' => $entry['origin_file'],
            ));
        }
    }
}

if (!function_exists('ucwp_get_request_profile_callback_timings')) {
    function ucwp_get_request_profile_callback_timings($limit = 120)
    {
        $timings = isset($GLOBALS['ucwp_request_profile_callback_timings']) && is_array($GLOBALS['ucwp_request_profile_callback_timings']) ? $GLOBALS['ucwp_request_profile_callback_timings'] : array();
        usort($timings, function ($a, $b) {
            return (int) ($b['duration_ms'] ?? 0) <=> (int) ($a['duration_ms'] ?? 0);
        });

        return array_slice($timings, 0, max(1, (int) $limit));
    }
}

if (!function_exists('ucwp_get_request_profile_callback_timing_summary')) {
    function ucwp_get_request_profile_callback_timing_summary($limit = 80)
    {
        $summary = isset($GLOBALS['ucwp_request_profile_callback_timing_summary']) && is_array($GLOBALS['ucwp_request_profile_callback_timing_summary']) ? array_values($GLOBALS['ucwp_request_profile_callback_timing_summary']) : array();
        foreach ($summary as $i => $row) {
            $count = max(1, (int) ($row['count'] ?? 1));
            $summary[$i]['avg_ms'] = (int) round(((int) ($row['total_ms'] ?? 0)) / $count);
        }

        usort($summary, function ($a, $b) {
            $a_total = (int) ($a['total_ms'] ?? 0);
            $b_total = (int) ($b['total_ms'] ?? 0);
            if ($a_total === $b_total) {
                return (int) ($b['max_ms'] ?? 0) <=> (int) ($a['max_ms'] ?? 0);
            }
            return $b_total <=> $a_total;
        });

        return array_slice($summary, 0, max(1, (int) $limit));
    }
}

if (!function_exists('ucwp_request_profile_wrap_hook_callbacks')) {
    function ucwp_request_profile_wrap_hook_callbacks($hook_name, $priorities = null)
    {
        if (!ucwp_request_callback_profiler_enabled()) {
            return;
        }

        global $wp_filter;
        $hook_name = (string) $hook_name;
        if ('' === $hook_name || empty($wp_filter[$hook_name]) || !is_object($wp_filter[$hook_name]) || empty($wp_filter[$hook_name]->callbacks) || !is_array($wp_filter[$hook_name]->callbacks)) {
            ucwp_request_profile_checkpoint('callback_wrap_skipped', array('target_hook' => $hook_name, 'reason' => 'no_callbacks'));
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_wrapped_callbacks']) || !is_array($GLOBALS['ucwp_request_profile_wrapped_callbacks'])) {
            $GLOBALS['ucwp_request_profile_wrapped_callbacks'] = array();
        }
        if (!isset($GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name])) {
            $GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name] = array();
        }

        $available_priorities = array_keys($wp_filter[$hook_name]->callbacks);
        $target_priorities = null === $priorities ? $available_priorities : array_map('intval', (array) $priorities);
        $wrapped = 0;
        $priority_counts = array();

        foreach ($target_priorities as $priority) {
            $priority_key = (string) $priority;
            $actual_priority = null;
            if (array_key_exists($priority, $wp_filter[$hook_name]->callbacks)) {
                $actual_priority = $priority;
            } elseif (array_key_exists($priority_key, $wp_filter[$hook_name]->callbacks)) {
                $actual_priority = $priority_key;
            }

            if (null === $actual_priority || empty($wp_filter[$hook_name]->callbacks[$actual_priority]) || !is_array($wp_filter[$hook_name]->callbacks[$actual_priority])) {
                $priority_counts[$priority_key] = 0;
                continue;
            }

            $index = 0;
            foreach ($wp_filter[$hook_name]->callbacks[$actual_priority] as $callback_id => $item) {
                if (!is_array($item) || !array_key_exists('function', $item)) {
                    $index++;
                    continue;
                }

                $wrapped_key = (string) $actual_priority . ':' . (is_scalar($callback_id) ? (string) $callback_id : (string) $index);
                if (!empty($GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key])) {
                    $index++;
                    continue;
                }

                $original_callback = $item['function'];
                if (!is_callable($original_callback)) {
                    $index++;
                    continue;
                }

                $accepted_args = isset($item['accepted_args']) ? (int) $item['accepted_args'] : 1;
                if ($accepted_args < 0) {
                    $accepted_args = 0;
                }

                $label = ucwp_request_profile_callback_label($original_callback);
                $origin = ucwp_request_profile_callback_origin($original_callback);
                $current_index = $index;
                $current_id = is_scalar($callback_id) ? (string) $callback_id : 'callback_' . (string) $current_index;
                $current_priority = (string) $actual_priority;

                $wp_filter[$hook_name]->callbacks[$actual_priority][$callback_id]['function'] = function () use ($hook_name, $original_callback, $accepted_args, $current_priority, $current_index, $current_id, $label, $origin) {
                    $args = func_get_args();
                    if ($accepted_args >= 0) {
                        $args = array_slice($args, 0, $accepted_args);
                    }

                    $request_start = ucwp_request_profile_request_started_at();
                    $start = microtime(true);
                    $result = null;
                    try {
                        $result = call_user_func_array($original_callback, $args);
                    } finally {
                        $end = microtime(true);
                        ucwp_request_profile_record_callback_timing(
                            $hook_name,
                            $current_priority,
                            $current_index,
                            $current_id,
                            $label,
                            $origin,
                            (int) round(max(0, $start - $request_start) * 1000),
                            (int) round(max(0, $end - $start) * 1000)
                        );
                    }

                    return $result;
                };

                $GLOBALS['ucwp_request_profile_wrapped_callbacks'][$hook_name][$wrapped_key] = true;
                $wrapped++;
                $index++;
            }

            $priority_counts[$priority_key] = $index;
        }

        ucwp_request_profile_checkpoint('callback_wrap_done', array(
            'target_hook' => $hook_name,
            'wrapped_count' => (string) $wrapped,
            'priority_counts' => $priority_counts,
        ));
    }
}

if (!function_exists('ucwp_request_profile_checkpoint')) {
    function ucwp_request_profile_checkpoint($stage, array $extra = array())
    {
        if (!ucwp_request_profiler_enabled()) {
            return;
        }

        $stage = ucwp_request_profile_sanitize_stage($stage);
        if (!ucwp_request_profile_should_record_checkpoint($stage)) {
            return;
        }

        if (!isset($GLOBALS['ucwp_request_profile_checkpoints']) || !is_array($GLOBALS['ucwp_request_profile_checkpoints'])) {
            $GLOBALS['ucwp_request_profile_checkpoints'] = array();
        }

        $now = microtime(true);
        $request_start = ucwp_request_profile_request_started_at();
        $last = isset($GLOBALS['ucwp_request_profile_last_checkpoint_at']) && is_numeric($GLOBALS['ucwp_request_profile_last_checkpoint_at'])
            ? (float) $GLOBALS['ucwp_request_profile_last_checkpoint_at']
            : $request_start;

        $current_hook = function_exists('current_filter') ? (string) current_filter() : '';
        $checkpoint = array_merge(array(
            'stage' => $stage,
            'source' => 'plugin',
            'hook' => $current_hook,
            'at_ms' => (int) round(max(0, $now - $request_start) * 1000),
            'since_previous_ms' => (int) round(max(0, $now - $last) * 1000),
            'memory_bytes' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
            'peak_memory_bytes' => function_exists('memory_get_peak_usage') ? (int) memory_get_peak_usage(true) : 0,
        ), $extra);

        if (ucwp_request_profile_verbose_enabled() && '' !== $current_hook && !isset($checkpoint['hook_summary'])) {
            $summary = ucwp_request_profile_current_hook_summary($current_hook);
            if (!empty($summary)) {
                $checkpoint['hook_summary'] = $summary;
            }
        }

        $GLOBALS['ucwp_request_profile_checkpoints'][] = $checkpoint;
        $GLOBALS['ucwp_request_profile_last_checkpoint_at'] = $now;
    }
}

if (!function_exists('ucwp_get_request_profile_checkpoints')) {
    function ucwp_get_request_profile_checkpoints()
    {
        return isset($GLOBALS['ucwp_request_profile_checkpoints']) && is_array($GLOBALS['ucwp_request_profile_checkpoints'])
            ? $GLOBALS['ucwp_request_profile_checkpoints']
            : array();
    }
}

ucwp_request_profile_checkpoint('plugin_file_loaded');

if (!function_exists('ucwp_php_string_literal')) {
    function ucwp_php_string_literal($value)
    {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
    }
}

if (!function_exists('ucwp_php_float_literal')) {
    function ucwp_php_float_literal($value)
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
    }
}

if (!function_exists('ucwp_is_allowed_socket_target')) {
    function ucwp_is_allowed_socket_target($host, $port, $context = '')
    {
        $host = strtolower(trim((string) $host));
        $port = (int) $port;
        $context = (string) $context;

        if ('' === $host || $port <= 0 || $port > 65535) {
            return false;
        }

        $default_allowed_ports = array(80, 82, 443, 6081, 6082);
        if (false !== stripos($context, 'varnish')) {
            // Varnish is commonly deployed on custom ports. Endpoint trust is handled by the caller/context.
            $default_allowed_ports[] = $port;
        }

        $allowed_ports = apply_filters('ucwp_allowed_socket_ports', array_values(array_unique(array_map('intval', $default_allowed_ports))), $host, $context);
        if (is_array($allowed_ports) && !in_array($port, array_map('intval', $allowed_ports), true)) {
            return false;
        }

        if (false !== stripos($context, 'configured_varnish') || false !== stripos($context, 'trusted_infrastructure')) {
            return (bool) apply_filters('ucwp_allow_configured_infrastructure_socket_target', true, $host, $port, $context);
        }

        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $home_host = strtolower((string) $home_host);
        $local_hosts = array_filter(array_unique(array('localhost', '127.0.0.1', '::1', '[::1]', $home_host)));

        if (in_array($host, $local_hosts, true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (0 === strpos($host, '10.') || 0 === strpos($host, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) {
                    return true;
                }
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ('fc' === substr($host, 0, 2) || 'fd' === substr($host, 0, 2))) {
                return true;
            }
            return false;
        }

        if ('' !== $home_host && ($host === $home_host || preg_match('/(?:^|\.)' . preg_quote($home_host, '/') . '$/i', $host))) {
            return true;
        }

        return (bool) apply_filters('ucwp_is_allowed_socket_target', false, $host, $port, $context);
    }
}

if (!function_exists('ucwp_is_allowed_redis_socket_target')) {
    function ucwp_is_allowed_redis_socket_target($host, $port, $context = 'redis_endpoint')
    {
        $raw_host = trim((string) $host);
        $host = preg_replace('#^(?:tcp|tls|ssl)://#i', '', $raw_host);
        $host = trim((string) $host, " \t\n\r\0\x0B[]");
        $port = (int) $port;
        $context = (string) $context;

        if ('' === $host || $port <= 0 || $port > 65535 || false !== strpos($host, '/')) {
            return false;
        }

        $normalized = strtolower($host);
        $trusted_hosts = apply_filters('ucwp_trusted_redis_socket_hosts', array(), $host, $port, $context);
        if (is_array($trusted_hosts)) {
            $trusted_hosts = array_map(static function ($value) {
                return strtolower(trim((string) $value));
            }, $trusted_hosts);
            if (in_array($normalized, $trusted_hosts, true)) {
                return true;
            }
        }

        $allowed = apply_filters('ucwp_allow_configured_external_redis_endpoint', true, $host, $port, $context);
        if ($allowed) {
            return true;
        }

        return (bool) apply_filters('ucwp_is_allowed_redis_socket_target', false, $host, $port, $context);
    }
}

if (!function_exists('ucwp_internal_file_get_contents')) {
    /**
     * Internal file read primitive. Do not call this with user/configurable paths.
     * Use ucwp_guarded_file_get_contents() or ucwp_safe_file_get_contents() instead.
     */
    function ucwp_internal_file_get_contents($path, $context = '', $suppress_warnings = false)
    {
        $path = (string) $path;
        $context = (string) $context;
        $suppress_warnings = (bool) $suppress_warnings;
        $data = false;

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem && $filesystem->exists($path) && $filesystem->is_file($path)) {
            $data = $filesystem->get_contents($path);
        } else {
            $exists = file_exists($path);
            $is_file = $exists && is_file($path);
            $readable = $is_file && is_readable($path);

            if (!$suppress_warnings || $readable) {
                $data = @file_get_contents($path);
            }
        }

        if (false === $data) {
            $log_context = array('path' => $path, 'context' => $context);
            ucwp_debug_log('file_get_contents failed', $log_context);
        }

        return $data;
    }
}

if (!function_exists('ucwp_guarded_file_get_contents')) {
    function ucwp_guarded_file_get_contents($path, $context = '', $suppress_warnings = false, $allowed_roots = array())
    {
        $path = (string) $path;
        $context = (string) $context;

        if (!function_exists('ucwp_is_allowed_readable_path') || !ucwp_is_allowed_readable_path($path, $context, is_array($allowed_roots) ? $allowed_roots : array())) {
            ucwp_debug_log('file_get_contents blocked: path outside allowed read roots', array('path' => $path, 'context' => $context));
            return false;
        }

        return ucwp_internal_file_get_contents($path, $context, $suppress_warnings);
    }
}

if (!function_exists('ucwp_safe_file_get_contents')) {
    /**
     * Back-compatible guarded read helper. The name is retained for existing code,
     * but reads now pass through UltraCache's readable-path allowlist.
     */
    function ucwp_safe_file_get_contents($path, $context = '', $suppress_warnings = false, $allowed_roots = array())
    {
        return ucwp_guarded_file_get_contents($path, $context, $suppress_warnings, $allowed_roots);
    }
}

if (!function_exists('ucwp_get_asset_readable_roots')) {
    /**
     * Return narrowly scoped filesystem roots for local CSS/JS/font asset reads.
     * These guards are for optimization reads only; blocked reads must leave the
     * original frontend asset untouched rather than breaking the page.
     */
    function ucwp_get_asset_readable_roots($type = '')
    {
        $type = strtolower(trim((string) $type));
        $roots = array();

        if ('generated-css' === $type && defined('UCWP_CACHE_DIR')) {
            $roots = array(UCWP_CACHE_DIR);
        } elseif ('cached-html' === $type && defined('UCWP_CACHE_DIR')) {
            $roots = array(UCWP_CACHE_DIR);
        } else {
            foreach (array('UCWP_CACHE_DIR', 'UCWP_OPTIMIZED_IMAGES_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR') as $constant) {
                if (defined($constant)) {
                    $roots[] = constant($constant);
                }
            }
            if (defined('WP_PLUGIN_DIR')) {
                $roots[] = WP_PLUGIN_DIR;
            }
            if (defined('WPMU_PLUGIN_DIR')) {
                $roots[] = WPMU_PLUGIN_DIR;
            }
            if (function_exists('get_theme_root')) {
                $roots[] = get_theme_root();
            }
            $uploads = ucwp_uploads_base_info();
            if (!empty($uploads['basedir'])) {
                $roots[] = $uploads['basedir'];
            }
            if (defined('ABSPATH') && defined('WPINC')) {
                // WordPress core assets are resolved through ABSPATH . WPINC because includes_url() maps to this required core directory.
                $roots[] = rtrim((string) ABSPATH, '/\\') . '/' . WPINC;
            }
        }

        $plugin_root = defined('UCWP_PATH') ? UCWP_PATH : '';
        if (is_string($plugin_root) && '' !== $plugin_root) {
            $roots[] = $plugin_root;
        }

        $normalized = array();
        foreach ($roots as $root) {
            $root = ucwp_normalize_filesystem_path_for_guard($root);
            if ('' !== $root && !in_array($root, $normalized, true)) {
                $normalized[] = $root;
            }
        }

        return (array) apply_filters('ucwp_asset_readable_roots', $normalized, $type);
    }
}

if (!function_exists('ucwp_asset_read_allowed_extensions')) {
    function ucwp_asset_read_allowed_extensions($type = '')
    {
        $type = strtolower(trim((string) $type));
        switch ($type) {
            case 'css':
            case 'font-css':
            case 'generated-css':
                return array('css');
            case 'js':
                return array('js', 'mjs');
            case 'cached-html':
                return array('html', 'htm');
            default:
                return array();
        }
    }
}

if (!function_exists('ucwp_guarded_asset_file_get_contents')) {
    /**
     * Guarded local asset read for optimization pipelines.
     * This never controls frontend delivery; if a read is blocked or fails, callers
     * should skip optimization and keep the original asset reference.
     */
    function ucwp_guarded_asset_file_get_contents($path, $type = '', $context = '', $suppress_warnings = false)
    {
        $path = (string) $path;
        $type = strtolower(trim((string) $type));
        $context = '' !== (string) $context ? (string) $context : ('asset_read_' . $type);

        $allowed_extensions = ucwp_asset_read_allowed_extensions($type);
        if (!empty($allowed_extensions)) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ('' === $extension || !in_array($extension, $allowed_extensions, true)) {
                ucwp_debug_log('asset file_get_contents blocked: extension not allowed', array(
                    'path' => $path,
                    'type' => $type,
                    'context' => $context,
                    'extension' => $extension,
                ));
                return false;
            }
        }

        return ucwp_guarded_file_get_contents($path, $context, (bool) $suppress_warnings, ucwp_get_asset_readable_roots($type));
    }
}



if (!function_exists('ucwp_get_allowed_custom_table_basenames')) {
    function ucwp_get_allowed_custom_table_basenames()
    {
        return array(
            'ultracache_media_queue',
            'ultracache_media_page_refs',
            'ultracache_action_jobs',
            'ultracache_js_diagnostic_jobs',
            'ultracache_cron_warm_queue',
            'ultracache_analytics',
            'ultracache_cache_asset_refs',
            'ultracache_css_rewrite_map',
        );
    }
}

if (!function_exists('ucwp_is_allowed_custom_table_name')) {
    function ucwp_is_allowed_custom_table_name($table)
    {
        global $wpdb;

        $table = (string) $table;
        if ('' === $table || !($wpdb instanceof wpdb)) {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        $allowed = array();
        foreach (ucwp_get_allowed_custom_table_basenames() as $basename) {
            $allowed[(string) $wpdb->prefix . $basename] = true;
        }

        return isset($allowed[$table]);
    }
}

if (!function_exists('ucwp_validate_custom_table_name')) {
    function ucwp_validate_custom_table_name($table, $context = '')
    {
        $table = (string) $table;
        if (ucwp_is_allowed_custom_table_name($table)) {
            return $table;
        }

        ucwp_debug_log('blocked invalid UltraCache custom table identifier', array('table' => $table, 'context' => (string) $context));
        return '';
    }
}

if (!function_exists('ucwp_safe_fsockopen')) {
    function ucwp_safe_fsockopen($host, $port, &$errno, &$errstr, $timeout = 0, $context = '')
    {
        $host = (string) $host;
        $port = (int) $port;
        $timeout = (float) $timeout;
        $context = (string) $context;
        $errno = 0;
        $errstr = '';

        if (!ucwp_is_allowed_socket_target($host, $port, $context)) {
            $errstr = 'Socket target is not allowed.';
            ucwp_debug_log('stream_socket_client blocked: unsafe socket target', array(
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'context' => $context,
            ));
            return false;
        }

        $remote_socket = 'tcp://' . $host . ':' . $port;
        $stream = @stream_socket_client($remote_socket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (false === $stream) {
            $log_context = array(
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'context' => $context,
                'errno' => (int) $errno,
            );
            if ('' !== (string) $errstr) {
                $log_context['error'] = (string) $errstr;
            }
            ucwp_debug_log('stream_socket_client failed', $log_context);
        }

        return $stream;
    }
}

if (!function_exists('ucwp_strip_source_mapping_url_comments')) {
    /**
     * Remove source map reference comments from generated assets.
     *
     * Production-generated UltraCache CSS/JS does not ship matching .map files.
     * Keeping sourceMappingURL comments causes browsers/devtools to request missing
     * .map files and can trigger noisy 404 burst firewall alerts during testing.
     */
    function ucwp_strip_source_mapping_url_comments($contents)
    {
        $contents = (string) $contents;
        if ('' === $contents || false === stripos($contents, 'sourceMappingURL')) {
            return $contents;
        }

        // CSS-style block source-map comments: /*# sourceMappingURL=file.css.map */
        $contents = (string) preg_replace('/\/\*[#@]\s*sourceMappingURL\s*=\s*[\s\S]*?\*\//i', '', $contents);

        // JS-style line source-map comments: //# sourceMappingURL=file.js.map
        $contents = (string) preg_replace('/^[ \t]*\/\/[#@]\s*sourceMappingURL\s*=.*(?:\r?\n|$)/mi', '', $contents);

        // Also catch inline JS source-map comments after a statement terminator.
        $contents = (string) preg_replace('/([;{}])\s*\/\/[#@]\s*sourceMappingURL\s*=[^\r\n]*/i', '$1', $contents);

        return $contents;
    }
}

if (!function_exists('ucwp_safe_file_put_contents')) {
    function ucwp_safe_file_put_contents($path, $contents, $flags = 0, $context = '')
    {
        $path = (string) $path;
        $context = (string) $context;
        if ('' === $path) {
            ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'empty-path'));
            return false;
        }

        if (!ucwp_is_allowed_writable_path($path, $context)) {
            ucwp_debug_log('file_put_contents blocked: path outside allowed write roots', array('path' => $path, 'context' => $context));
            return false;
        }

        $dir = dirname($path);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
            if (function_exists('ucwp_safe_mkdir')) {
                ucwp_safe_mkdir($dir, 0755, true, $context . ' parent mkdir');
            } elseif (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            }
        }

        if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !ucwp_path_is_writable($dir))) {
            if (!is_dir($dir) || !ucwp_path_is_writable($dir)) {
                ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'parent-not-writable'));
                return false;
            }
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $existing = '';
            if (FILE_APPEND === ($flags & FILE_APPEND) && $filesystem->exists($path)) {
                $existing = (string) $filesystem->get_contents($path);
            }
            $data = $existing . (string) $contents;
            $result = $filesystem->put_contents($path, $data, FS_CHMOD_FILE);
            if (false !== $result) {
                return strlen($data);
            }
        }

        $result = @file_put_contents($path, $contents, $flags);
        if (false === $result) {
            ucwp_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_normalize_filesystem_path_for_guard')) {
    function ucwp_normalize_filesystem_path_for_guard($path)
    {
        $path = is_string($path) ? trim($path) : '';
        if ('' === $path) {
            return '';
        }

        $real = realpath($path);
        if (false !== $real) {
            return str_replace('\\', '/', $real);
        }

        $dir = dirname($path);
        $base = basename($path);
        $dir_real = realpath($dir);
        if (false !== $dir_real) {
            return rtrim(str_replace('\\', '/', $dir_real), '/') . '/' . $base;
        }

        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('ucwp_path_has_dir_prefix')) {
    function ucwp_path_has_dir_prefix($path, $dir)
    {
        $path = ucwp_normalize_filesystem_path_for_guard($path);
        $dir = ucwp_normalize_filesystem_path_for_guard($dir);
        if ('' === $path || '' === $dir) {
            return false;
        }

        $dir = rtrim($dir, '/') . '/';
        return 0 === strpos($path, $dir) || rtrim($path, '/') === rtrim($dir, '/');
    }
}


if (!function_exists('ucwp_get_default_readable_roots')) {
    function ucwp_get_default_readable_roots($context = '')
    {
        $roots = array();

        foreach (array('UCWP_CACHE_DIR', 'UCWP_OPTIMIZED_IMAGES_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR', 'UCWP_OBJECT_CACHE_DIR') as $constant) {
            if (defined($constant)) {
                $roots[] = constant($constant);
            }
        }
        if (defined('WP_PLUGIN_DIR')) {
            $roots[] = WP_PLUGIN_DIR;
        }
        if (defined('WPMU_PLUGIN_DIR')) {
            $roots[] = WPMU_PLUGIN_DIR;
        }
        if (function_exists('get_theme_root')) {
            $roots[] = get_theme_root();
        }
        $uploads = ucwp_uploads_base_info();
        if (!empty($uploads['basedir'])) {
            $roots[] = $uploads['basedir'];
        }
        if (defined('ABSPATH') && defined('WPINC')) {
            // WordPress core includes are intentionally readable for core CSS/JS diagnostics.
            $roots[] = rtrim((string) ABSPATH, '/\\') . '/' . WPINC;
        }

        $plugin_root = defined('UCWP_PATH') ? UCWP_PATH : '';
        if (is_string($plugin_root) && '' !== $plugin_root) {
            $roots[] = $plugin_root;
        }

        $normalized = array();
        foreach ($roots as $root) {
            $root = ucwp_normalize_filesystem_path_for_guard($root);
            if ('' !== $root && !in_array($root, $normalized, true)) {
                $normalized[] = $root;
            }
        }

        return (array) apply_filters('ucwp_default_readable_roots', $normalized, (string) $context);
    }
}

if (!function_exists('ucwp_read_context_allows_wp_config')) {
    function ucwp_read_context_allows_wp_config($context)
    {
        $context = strtolower((string) $context);
        foreach (array('wp_config', 'wp-cache', 'set_wp_cache_flag', 'get_wp_cache_define_status') as $token) {
            if (false !== strpos($context, $token)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ucwp_read_context_allows_root_server_config')) {
    function ucwp_read_context_allows_root_server_config($context)
    {
        $context = strtolower((string) $context);
        foreach (array('sync_browser_cache_rules', 'browser_cache', 'dashboard diagnostics', 'dashboard path diagnostic', 'path_diagnostic') as $token) {
            if (false !== strpos($context, $token)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ucwp_is_allowed_readable_path')) {
    function ucwp_is_allowed_readable_path($path, $context = '', $allowed_roots = array())
    {
        $path = is_string($path) ? trim($path) : '';
        $context = (string) $context;
        if ('' === $path) {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return false;
        }

        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        if ('' === $normalized) {
            return false;
        }

        $roots = is_array($allowed_roots) && !empty($allowed_roots) ? $allowed_roots : ucwp_get_default_readable_roots($context);
        foreach ($roots as $root) {
            $root = ucwp_normalize_filesystem_path_for_guard($root);
            if ('' !== $root && ucwp_path_has_dir_prefix($normalized, $root)) {
                return true;
            }
        }

        $base = basename($normalized);

        if ('wp-config.php' === $base && ucwp_read_context_allows_wp_config($context) && defined('ABSPATH')) {
            $allowed_files = array(
                rtrim((string) ABSPATH, '/\\') . '/wp-config.php',
                dirname(rtrim((string) ABSPATH, '/\\')) . '/wp-config.php',
            );
            foreach ($allowed_files as $file) {
                if ($normalized === ucwp_normalize_filesystem_path_for_guard($file)) {
                    return true;
                }
            }
        }

        if (false !== strpos(strtolower($context), 'runtime') && function_exists('ucwp_is_canonical_runtime_secret_path_for_guard') && ucwp_is_canonical_runtime_secret_path_for_guard($normalized)) {
            return true;
        }

        if (ucwp_read_context_allows_root_server_config($context) && in_array($base, array('.htaccess', 'web.config'), true)) {
            $root = ucwp_normalize_filesystem_path_for_guard(ucwp_get_wordpress_home_path());
            if ('' !== $root && ucwp_path_has_dir_prefix($normalized, $root)) {
                return true;
            }
        }

        foreach (array('advanced-cache.php', 'object-cache.php') as $dropin_file) {
            $dropin_path = function_exists('ucwp_dropin_path') ? ucwp_dropin_path($dropin_file) : '';
            if ('' !== $dropin_path && $normalized === ucwp_normalize_filesystem_path_for_guard($dropin_path)) {
                // WordPress requires these two drop-ins directly under WP_CONTENT_DIR; allow read access only to these exact managed file paths for owner/conflict detection.
                return true;
            }
        }

        return (bool) apply_filters('ucwp_is_allowed_readable_path', false, $normalized, $context, $allowed_roots);
    }
}


if (!function_exists('ucwp_get_canonical_runtime_secret_path_for_guard')) {
    function ucwp_get_canonical_runtime_secret_path_for_guard()
    {
        return function_exists('ucwp_get_runtime_secret_path') ? ucwp_get_runtime_secret_path() : '';
    }
}

if (!function_exists('ucwp_is_canonical_runtime_secret_path_for_guard')) {
    function ucwp_is_canonical_runtime_secret_path_for_guard($path)
    {
        $canonical = ucwp_get_canonical_runtime_secret_path_for_guard();
        if ('' === $canonical) {
            return false;
        }

        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        $canonical_normalized = ucwp_normalize_filesystem_path_for_guard($canonical);
        if ('' === $normalized || '' === $canonical_normalized) {
            return false;
        }

        if ($normalized === $canonical_normalized) {
            return true;
        }

        $canonical_dir = dirname($canonical_normalized);
        $canonical_base = basename($canonical_normalized);
        $candidate_dir = dirname($normalized);
        $candidate_base = basename($normalized);

        if ($candidate_dir !== $canonical_dir) {
            return false;
        }

        $tmp_prefix = '.' . $canonical_base . '.tmp-';
        if (0 !== strpos($candidate_base, $tmp_prefix)) {
            return false;
        }

        $suffix = substr($candidate_base, strlen($tmp_prefix));
        return is_string($suffix) && '' !== $suffix && (bool) preg_match('/^[A-Za-z0-9_.-]+$/', $suffix);
    }
}

if (!function_exists('ucwp_is_allowed_destructive_path')) {
    function ucwp_is_allowed_destructive_path($path, $context = '')
    {
        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        if ('' === $normalized) {
            return false;
        }

        $allowed_dirs = array();
        foreach (array('UCWP_CACHE_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR', 'UCWP_OBJECT_CACHE_DIR') as $constant) {
            if (defined($constant)) {
                $allowed_dirs[] = constant($constant);
            }
        }

        $uploads = function_exists('ucwp_uploads_base_info') ? ucwp_uploads_base_info() : array();
        $uploads_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ('' !== $uploads_base && function_exists('ucwp_storage_join_path')) {
            $allowed_dirs[] = ucwp_storage_join_path($uploads_base, 'ultracache');
        }

        foreach ($allowed_dirs as $dir) {
            if (ucwp_path_has_dir_prefix($normalized, $dir)) {
                return true;
            }
        }

        $allowed_files = array_filter(array(
            function_exists('ucwp_dropin_path') ? ucwp_dropin_path('advanced-cache.php') : '',
            function_exists('ucwp_dropin_path') ? ucwp_dropin_path('object-cache.php') : '',
        ));

        foreach ($allowed_files as $file) {
            if ($normalized === ucwp_normalize_filesystem_path_for_guard($file)) {
                return true;
            }
        }

        $base = basename($normalized);
        $dir = dirname($normalized);

        if (preg_match('/^wp-config-backup-\\d{8}-\\d{6}-[A-Za-z0-9]+\\.php$/', $base) && file_exists($dir . '/wp-config.php')) {
            return true;
        }

        if (preg_match('/^wp-config\\.php\\.tmp-[A-Za-z0-9_.]+$/', $base) && file_exists($dir . '/wp-config.php')) {
            return true;
        }

        if (false !== strpos((string) $context, 'runtime') && ucwp_is_canonical_runtime_secret_path_for_guard($normalized)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ucwp_is_allowed_writable_path')) {
    function ucwp_is_allowed_writable_path($path, $context = '')
    {
        $normalized = ucwp_normalize_filesystem_path_for_guard($path);
        $context = (string) $context;
        if ('' === $normalized) {
            return false;
        }

        $allowed_dirs = array();
        foreach (array('UCWP_CACHE_DIR', 'UCWP_OPTIMIZED_IMAGES_DIR', 'UCWP_AVIF_DIR', 'UCWP_WEBP_DIR', 'UCWP_OBJECT_CACHE_DIR') as $constant) {
            if (defined($constant)) {
                $allowed_dirs[] = constant($constant);
            }
        }

        $uploads = function_exists('ucwp_uploads_base_info') ? ucwp_uploads_base_info() : array();
        $uploads_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ('' !== $uploads_base && function_exists('ucwp_storage_join_path')) {
            $allowed_dirs[] = ucwp_storage_join_path($uploads_base, 'ultracache');
        }

        foreach ($allowed_dirs as $dir) {
            if (ucwp_path_has_dir_prefix($normalized, $dir)) {
                return true;
            }
        }

        $base = basename($normalized);
        $dir = dirname($normalized);

        foreach (array('advanced-cache.php', 'object-cache.php') as $managed_file) {
            $target = function_exists('ucwp_dropin_path') ? ucwp_dropin_path($managed_file) : '';
            if ('' === $target) {
                continue;
            }
            $dropin_dir = dirname(wp_normalize_path($target));
            if ($normalized === ucwp_normalize_filesystem_path_for_guard($target)) {
                return true;
            }
            if (0 === strpos($base, $managed_file . '.tmp-') && ucwp_path_has_dir_prefix($normalized, $dropin_dir)) {
                return true;
            }
        }

        if (false !== strpos($context, 'runtime') && ucwp_is_canonical_runtime_secret_path_for_guard($normalized)) {
            return true;
        }

        if (false !== strpos($context, 'set_wp_cache_flag')) {
            if ('wp-config.php' === $base && file_exists($dir . '/wp-config.php')) {
                return true;
            }
            if (preg_match('/^wp-config\.php\.tmp-[A-Za-z0-9_.]+$/', $base) && file_exists($dir . '/wp-config.php')) {
                return true;
            }
            if (preg_match('/^wp-config-backup-\d{8}-\d{6}-[A-Za-z0-9]+\.php$/', $base) && file_exists($dir . '/wp-config.php')) {
                return true;
            }
        }

        if (false !== strpos($context, 'sync_browser_cache_rules')) {
            $root = wp_normalize_path(ucwp_get_wordpress_home_path());
            if (ucwp_path_has_dir_prefix($normalized, $root) && ('.htaccess' === $base || preg_match('/^\.htaccess\.tmp-[A-Za-z0-9_.]+$/', $base))) {
                return true;
            }
        }

        return (bool) apply_filters('ucwp_is_allowed_writable_path', false, $normalized, $context);
    }
}

if (!function_exists('ucwp_safe_unlink')) {
    function ucwp_safe_unlink($path, $context = '')
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!ucwp_is_allowed_destructive_path($path, $context)) {
            ucwp_debug_log('unlink blocked: path outside allowed roots', array('path' => $path, 'context' => (string) $context));
            return false;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->delete($path, false, 'f');
            if ($result || !file_exists($path)) {
                return true;
            }
        }

        if (function_exists('wp_delete_file')) {
            wp_delete_file($path);
        }

        $result = !file_exists($path);
        if (!$result) {
            ucwp_debug_log('unlink failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_rename')) {
    function ucwp_safe_rename($from, $to, $context = '')
    {
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';
        if ('' === $from || '' === $to) {
            ucwp_debug_log('rename failed: empty path', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        if ($from === $to) {
            return file_exists($to);
        }

        if (!ucwp_is_allowed_writable_path($from, $context) || !ucwp_is_allowed_writable_path($to, $context)) {
            ucwp_debug_log('rename blocked: path outside allowed write roots', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        clearstatcache(true, $from);
        clearstatcache(true, $to);
        if (!file_exists($from)) {
            $already_moved = file_exists($to);
            if (!$already_moved) {
                ucwp_debug_log('rename failed: source missing', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            }
            return $already_moved;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Prefer native atomic rename for path-guarded generated cache files, then fall back to WP_Filesystem.
        if (@rename($from, $to)) {
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            return file_exists($to) && !file_exists($from);
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->move($from, $to, true);
            clearstatcache(true, $from);
            clearstatcache(true, $to);
            if ($result || (file_exists($to) && !file_exists($from))) {
                return true;
            }
        }

        ucwp_debug_log('rename failed', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        return false;
    }
}


if (!function_exists('ucwp_safe_chmod')) {
    function ucwp_safe_chmod($path, $mode = 0600, $context = '')
    {
        $path = is_string($path) ? $path : '';
        $mode = (int) $mode;
        $context = (string) $context;

        if ('' === $path || !file_exists($path)) {
            return false;
        }

        if (!ucwp_is_allowed_writable_path($path, $context) && !ucwp_is_allowed_destructive_path($path, $context)) {
            ucwp_debug_log('chmod blocked: path outside allowed roots', array('path' => $path, 'mode' => $mode, 'context' => $context));
            return false;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem && method_exists($filesystem, 'chmod')) {
            $filesystem->chmod($path, $mode);
            clearstatcache(true, $path);
            $perms = (is_file($path) && is_readable($path)) ? fileperms($path) : false;
            if (false !== $perms && (($perms & 0777) === $mode)) {
                return true;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Guarded fallback for generated UltraCache files when WP_Filesystem chmod cannot apply permissions.
        @chmod($path, $mode);
        clearstatcache(true, $path);
        $perms = (is_file($path) && is_readable($path)) ? fileperms($path) : false;
        $ok = false !== $perms && (($perms & 0777) === $mode);
        if (!$ok) {
            ucwp_debug_log('chmod failed', array('path' => $path, 'mode' => $mode, 'context' => $context));
        }

        return $ok;
    }
}


if (!function_exists('ucwp_safe_copy')) {
    function ucwp_safe_copy($from, $to, $context = '')
    {
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';
        if ('' === $from || '' === $to || !file_exists($from)) {
            ucwp_debug_log('copy failed: invalid path', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        if (!ucwp_is_allowed_writable_path($to, $context)) {
            ucwp_debug_log('copy blocked: destination outside allowed write roots', array('from' => $from, 'to' => $to, 'context' => (string) $context));
            return false;
        }

        $dir = dirname($to);
        if ('' !== $dir && '.' !== $dir && !is_dir($dir) && !ucwp_safe_mkdir($dir, 0755, true, $context . ' parent mkdir')) {
            return false;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($filesystem) {
            $result = $filesystem->copy($from, $to, true, FS_CHMOD_FILE);
            if ($result) {
                return true;
            }
        }

        $result = copy($from, $to);
        if (!$result) {
            ucwp_debug_log('copy failed', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_mkdir')) {
    function ucwp_safe_mkdir($dir, $mode = 0755, $recursive = true, $context = '')
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !ucwp_is_allowed_writable_path($dir, $context)) {
            ucwp_debug_log('mkdir blocked: directory outside allowed write roots', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
            return false;
        }

        if (is_dir($dir)) {
            return true;
        }

        $filesystem = ucwp_get_wp_filesystem();
        if ($recursive && function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
            return true;
        }

        if ($filesystem) {
            $result = $filesystem->mkdir($dir, $mode);
            if ($result || is_dir($dir)) {
                return true;
            }
        }

        ucwp_debug_log('mkdir failed', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
        return is_dir($dir);
    }
}

if (!function_exists('ucwp_native_delete_directory')) {
    function ucwp_native_delete_directory($dir)
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!ucwp_native_delete_directory($path)) {
                    return false;
                }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Path-guarded recursive cleanup fallback after WP_Filesystem deletion.
            } elseif (file_exists($path) && !@unlink($path)) {
                return false;
            }
        }

        clearstatcache(true, $dir);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Path-guarded recursive cleanup fallback after WP_Filesystem deletion.
        return @rmdir($dir) || !file_exists($dir);
    }
}

if (!function_exists('ucwp_safe_rmdir_empty')) {
    function ucwp_safe_rmdir_empty($dir, $context = '')
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir) || is_link($dir)) {
            ucwp_debug_log('rmdir empty blocked: path is not a real directory', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        if (!ucwp_is_allowed_destructive_path($dir, $context)) {
            ucwp_debug_log('rmdir empty blocked: path outside allowed roots', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        clearstatcache(true, $dir);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Non-recursive rmdir is intentional so symlink children are never traversed by filesystem adapters.
        if (@rmdir($dir)) {
            clearstatcache(true, $dir);
            return true;
        }

        return !file_exists($dir);
    }
}

if (!function_exists('ucwp_safe_rmdir')) {
    function ucwp_safe_rmdir($dir, $context = '')
    {
        $dir = is_string($dir) ? $dir : '';
        if ('' === $dir || !file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir) || is_link($dir)) {
            ucwp_debug_log('rmdir blocked: path is not a real directory', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        if (!ucwp_is_allowed_destructive_path($dir, $context)) {
            ucwp_debug_log('rmdir blocked: path outside allowed roots', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        if (ucwp_native_delete_directory($dir)) {
            clearstatcache(true, $dir);
            return true;
        }

        ucwp_debug_log('rmdir failed', array('dir' => $dir, 'context' => (string) $context));
        return !file_exists($dir);
    }
}


if (!function_exists('ucwp_safe_filemtime')) {
    function ucwp_safe_filemtime($path, $context = '')
    {
        $result = filemtime($path);
        if (false === $result && file_exists($path)) {
            ucwp_debug_log('filemtime failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_filesize')) {
    function ucwp_safe_filesize($path, $context = '')
    {
        $path = (string) $path;
        if ('' === $path || !file_exists($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $result = filesize($path);
        if (false === $result && file_exists($path)) {
            ucwp_debug_log('filesize failed', array('path' => $path, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_tempnam')) {
    function ucwp_safe_tempnam($dir, $prefix = 'ucwp', $context = '')
    {
        $dir = (string) $dir;
        $prefix = (string) $prefix;
        if ('' === $dir || !ucwp_is_allowed_writable_path($dir, $context) || !is_dir($dir) || !ucwp_path_is_writable($dir)) {
            ucwp_debug_log('tempnam directory unavailable or blocked', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        $sanitized_prefix = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix);
        if (!is_string($sanitized_prefix) || '' === $sanitized_prefix) {
            $sanitized_prefix = 'ucwp';
        }

        $result = tempnam($dir, substr($sanitized_prefix, 0, 32));
        if (false === $result) {
            ucwp_debug_log('tempnam failed', array('dir' => $dir, 'prefix' => $sanitized_prefix, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_fread')) {
    function ucwp_safe_fread($stream, $length, $context = '')
    {
        $length = max(0, (int) $length);
        if ($length <= 0) {
            return '';
        }

        if (!is_resource($stream)) {
            ucwp_debug_log('fread failed: invalid stream', array('context' => (string) $context, 'length' => $length));
            return false;
        }

        $result = stream_get_contents($stream, $length);
        if (false === $result) {
            ucwp_debug_log('stream_get_contents failed', array('context' => (string) $context, 'length' => $length));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_scandir')) {
    function ucwp_safe_scandir($dir, $context = '')
    {
        $dir = (string) $dir;
        if ('' === $dir || !is_dir($dir)) {
            return false;
        }

        if (!is_readable($dir)) {
            ucwp_debug_log('scandir failed: directory not readable', array('dir' => $dir, 'context' => (string) $context));
            return false;
        }

        $result = scandir($dir);
        if (false === $result) {
            ucwp_debug_log('scandir failed', array('dir' => $dir, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_safe_stream_set_blocking')) {
    function ucwp_safe_stream_set_blocking($stream, $enable, $context = '')
    {
        $result = stream_set_blocking($stream, $enable);
        if (false === $result) {
            ucwp_debug_log('stream_set_blocking failed', array('context' => (string) $context, 'enable' => (bool) $enable));
        }

        return $result;
    }
}


if (!function_exists('ucwp_safe_remote_request')) {
    function ucwp_safe_remote_request($url, array $args = array(), $context = '')
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return new WP_Error('ucwp_empty_remote_url', __('Remote request URL is empty.', 'ultracache'));
        }


        $defaults = array(
            'timeout' => 10,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'user-agent' => 'UltraCache/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown') . '; ' . home_url('/'),
        );
        $args = array_merge($defaults, $args);
        $args['redirection'] = max(0, min(5, (int) $args['redirection']));
        $args['timeout'] = max(1, min(60, (int) $args['timeout']));
        $args['reject_unsafe_urls'] = true;

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            ucwp_debug_log('wp_safe_remote_request failed', array(
                'url' => (string) $url,
                'context' => (string) $context,
                'error' => $response->get_error_message(),
            ));
        }

        return $response;
    }
}

if (!function_exists('ucwp_safe_configured_infrastructure_remote_request')) {
    function ucwp_safe_configured_infrastructure_remote_request($url, array $args = array(), $context = '')
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return new WP_Error('ucwp_empty_remote_url', __('Remote request URL is empty.', 'ultracache'));
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return new WP_Error('ucwp_invalid_infrastructure_url', __('Configured infrastructure URL is invalid.', 'ultracache'));
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower(trim((string) $parts['host'])) : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host || $port <= 0 || $port > 65535) {
            return new WP_Error('ucwp_invalid_infrastructure_url', __('Configured infrastructure URL must use http(s) with a valid host and port.', 'ultracache'));
        }

        if (!ucwp_is_allowed_socket_target($host, $port, 'trusted_infrastructure_' . (string) $context)) {
            return new WP_Error('ucwp_blocked_infrastructure_url', __('Configured infrastructure target is blocked by UltraCache socket policy.', 'ultracache'));
        }

        $defaults = array(
            'timeout' => 10,
            'redirection' => 0,
            'reject_unsafe_urls' => false,
            'user-agent' => 'UltraCache/' . (defined('UCWP_VERSION') ? UCWP_VERSION : 'unknown') . '; ' . home_url('/'),
        );
        $args = array_merge($defaults, $args);
        $args['redirection'] = max(0, min(2, (int) $args['redirection']));
        $args['timeout'] = max(1, min(60, (int) $args['timeout']));
        $args['reject_unsafe_urls'] = false;

        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_request_wp_remote_request -- This wrapper is only used for administrator-configured same-server infrastructure endpoints after ucwp_is_allowed_socket_target() validation; wp_safe_remote_request() would block trusted loopback targets needed for Varnish/reverse-proxy integrations.
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            ucwp_debug_log('wp_remote_request infrastructure request failed', array(
                'url' => (string) $url,
                'context' => (string) $context,
                'error' => $response->get_error_message(),
            ));
        }

        return $response;
    }
}


if (!function_exists('ucwp_get_loopback_ssl_status')) {
    function ucwp_get_loopback_ssl_status()
    {
        $status = get_transient('ultracache_loopback_ssl_status_v1');
        if (!is_array($status)) {
            $status = array();
        }

        return wp_parse_args($status, array(
            'strictByDefault' => true,
            'fallbackUsed'    => false,
            'lastUrl'         => '',
            'lastError'       => '',
            'context'         => '',
            'message'         => '',
            'updatedAt'       => 0,
        ));
    }
}

if (!function_exists('ucwp_set_loopback_ssl_status')) {
    function ucwp_set_loopback_ssl_status(array $status)
    {
        set_transient('ultracache_loopback_ssl_status_v1', $status, DAY_IN_SECONDS);
    }
}

if (!function_exists('ucwp_reset_loopback_ssl_status')) {
    function ucwp_reset_loopback_ssl_status()
    {
        delete_transient('ultracache_loopback_ssl_status_v1');
    }
}

if (!function_exists('ucwp_is_local_https_url')) {
    function ucwp_is_local_https_url($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host   = isset($parts['host']) ? ucwp_normalize_host((string) $parts['host']) : '';
        if ('https' !== $scheme || '' === $host) {
            return false;
        }

        $trusted_hosts = ucwp_get_trusted_hosts();
        return in_array($host, $trusted_hosts, true);
    }
}


if (!function_exists('ucwp_get_default_port_for_scheme')) {
    function ucwp_get_default_port_for_scheme($scheme)
    {
        $scheme = strtolower((string) $scheme);
        if ('https' === $scheme) {
            return 443;
        }

        if ('http' === $scheme) {
            return 80;
        }

        return 0;
    }
}

if (!function_exists('ucwp_get_allowed_frontend_ports_for_scheme')) {
    function ucwp_get_allowed_frontend_ports_for_scheme($scheme, $host)
    {
        $scheme = strtolower((string) $scheme);
        $host = ucwp_normalize_host($host);
        $ports = array();

        $default = ucwp_get_default_port_for_scheme($scheme);
        if ($default > 0) {
            $ports[$default] = true;
        }

        foreach (array(home_url('/'), site_url('/')) as $site_url) {
            $parts = wp_parse_url((string) $site_url);
            if (!is_array($parts)) {
                continue;
            }

            $site_scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
            $site_host = isset($parts['host']) ? ucwp_normalize_host((string) $parts['host']) : '';
            if ($site_scheme !== $scheme || $site_host !== $host) {
                continue;
            }

            if (isset($parts['port'])) {
                $port = (int) $parts['port'];
                if ($port > 0 && $port <= 65535) {
                    $ports[$port] = true;
                }
            }
        }

        return array_map('intval', array_keys($ports));
    }
}

if (!function_exists('ucwp_is_strict_frontend_loopback_url')) {
    function ucwp_is_strict_frontend_loopback_url($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ('' === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? ucwp_normalize_host((string) $parts['host']) : '';
        if (!in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return false;
        }

        $trusted_hosts = ucwp_get_trusted_hosts();
        if (!in_array($host, $trusted_hosts, true)) {
            return false;
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            $allowed_ports = ucwp_get_allowed_frontend_ports_for_scheme($scheme, $host);
            if ($port <= 0 || $port > 65535 || !in_array($port, $allowed_ports, true)) {
                return false;
            }
        }

        return (bool) apply_filters('ucwp_is_strict_frontend_loopback_url', true, $url, $host, $scheme, isset($parts['port']) ? (int) $parts['port'] : 0);
    }
}

if (!function_exists('ucwp_is_ssl_verification_wp_error')) {
    function ucwp_is_ssl_verification_wp_error($error)
    {
        if (!($error instanceof WP_Error)) {
            return false;
        }

        $message = strtolower(trim((string) $error->get_error_message()));
        if ('' === $message) {
            return false;
        }

        $needles = array(
            'ssl certificate',
            'certificate verify failed',
            'peer certificate',
            'self signed certificate',
            'unable to get local issuer certificate',
            'unable to verify the first certificate',
            'tlsv1 alert',
            'certificate has expired',
            'hostname mismatch',
            'curl error 60',
            'curl error 51',
        );

        foreach ($needles as $needle) {
            if (false !== strpos($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ucwp_is_trusted_loopback_url')) {
    function ucwp_is_trusted_loopback_url($url)
    {
        // Frontend loopback requests are intentionally stricter than configured
        // infrastructure endpoints. Redis/Varnish/custom infrastructure validation
        // must continue to use the configured socket/endpoint helpers, not this one.
        return function_exists('ucwp_is_strict_frontend_loopback_url') && ucwp_is_strict_frontend_loopback_url($url);
    }
}

if (!function_exists('ucwp_safe_loopback_remote_request')) {
    function ucwp_safe_loopback_remote_request($url, array $args = array(), $context = '')
    {
        if (!ucwp_is_trusted_loopback_url($url)) {
            ucwp_debug_log('loopback remote request blocked: untrusted URL', array('url' => (string) $url, 'context' => (string) $context));
            return new WP_Error('ucwp_untrusted_loopback_url', __('Loopback request URL is not local/trusted for this site.', 'ultracache'));
        }

        $is_local_https = ucwp_is_local_https_url($url);
        if (!$is_local_https) {
            return ucwp_safe_remote_request($url, $args, $context);
        }

        $strict_args = $args;
        $strict_args['sslverify'] = true;
        $response = ucwp_safe_remote_request($url, $strict_args, $context . ':strict');
        if (!is_wp_error($response)) {
            return $response;
        }

        if (!ucwp_is_ssl_verification_wp_error($response)) {
            return $response;
        }

        $fallback_args = $args;
        $fallback_args['sslverify'] = false;
        $fallback = ucwp_safe_remote_request($url, $fallback_args, $context . ':fallback');
        if (!is_wp_error($fallback)) {
            ucwp_set_loopback_ssl_status(array(
                'strictByDefault' => true,
                'fallbackUsed'    => true,
                'lastUrl'         => (string) $url,
                'lastError'       => (string) $response->get_error_message(),
                'context'         => (string) $context,
                'message'         => function_exists('__') ? __('Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.', 'ultracache') : 'Strict local SSL verification failed and UltraCache temporarily retried the same-host HTTPS loopback request without certificate verification.',
                'updatedAt'       => time(),
            ));
            return $fallback;
        }

        return $response;
    }
}

if (!function_exists('ucwp_safe_wp_parse_url')) {
    function ucwp_safe_wp_parse_url($url, $component = -1, $context = '')
    {
        if (-1 === $component) {
            $result = wp_parse_url((string) $url);
        } else {
            $result = wp_parse_url((string) $url, $component);
        }

        if (false === $result) {
            ucwp_debug_log('wp_parse_url failed', array('url' => (string) $url, 'component' => $component, 'context' => (string) $context));
        }

        return $result;
    }
}

if (!function_exists('ucwp_normalize_host')) {
    function ucwp_normalize_host($host)
    {
        $host = trim((string) $host);
        if ('' === $host) {
            return '';
        }

        if (false !== strpos($host, ',')) {
            $parts = explode(',', $host);
            $host = (string) reset($parts);
        }

        $host = preg_replace('/\s+/', '', $host);
        $parsed = wp_parse_url('http://' . ltrim($host, '/'));
        if (is_array($parsed) && !empty($parsed['host'])) {
            $host = (string) $parsed['host'];
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ('' === $host) {
            return '';
        }

        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])$/i', $host)) {
            return '';
        }

        return $host;
    }
}

if (!function_exists('ucwp_get_trusted_hosts')) {
    function ucwp_get_trusted_hosts()
    {
        $hosts = array();
        foreach (array(home_url('/'), site_url('/')) as $url) {
            $host = ucwp_normalize_host(wp_parse_url((string) $url, PHP_URL_HOST));
            if ('' !== $host) {
                $hosts[$host] = true;
            }
        }

        return array_values(array_keys($hosts));
    }
}

if (!function_exists('ucwp_get_validated_http_host')) {
    function ucwp_get_validated_http_host($host, $context = '')
    {
        $normalized = ucwp_normalize_host($host);
        if ('' === $normalized) {
            ucwp_debug_log('invalid host header', array('host' => (string) $host, 'context' => (string) $context));
            return '';
        }

        $trusted = array_fill_keys(ucwp_get_trusted_hosts(), true);
        if (empty($trusted) || !isset($trusted[$normalized])) {
            ucwp_debug_log('untrusted host header rejected', array('host' => $normalized, 'context' => (string) $context, 'trusted_hosts' => array_keys($trusted)));
            return '';
        }

        return $normalized;
    }
}
