<?php
/**
 * Core storage-location and drop-in filesystem helpers for UltraCache.
 *
 * Moved from functions.php without changing existing function names or behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return an installation-stable, non-filesystem identity for Redis, APCu,
 * and analytics namespaces. The same inputs are available to WordPress
 * drop-ins before the full plugin runtime loads.
 *
 * @return string
 */
function ultracache_site_namespace_seed()
{
    global $table_prefix;

    $parts = array(
        defined('DB_NAME') ? (string) DB_NAME : '',
        defined('DB_USER') ? (string) DB_USER : '',
        is_scalar($table_prefix) ? (string) $table_prefix : '',
        defined('DOMAIN_CURRENT_SITE') ? (string) DOMAIN_CURRENT_SITE : '',
        defined('PATH_CURRENT_SITE') ? (string) PATH_CURRENT_SITE : '',
    );

    return implode('|', $parts);
}

/**
 * Return the internal UltraCache page-cache operational root under uploads.
 *
 * @param string $relative Optional relative path under the cache root.
 * @return string
 */
function ultracache_content_cache_storage_dir($relative = '')
{
    $base = ultracache_uploads_storage_dir('ultracache/cache');
    return '' === $base ? '' : trailingslashit(ultracache_storage_join_path($base, $relative));
}

/**
 * Return the public URL for the internal UltraCache cache root under uploads.
 *
 * @param string $relative Optional relative path under the cache root.
 * @return string
 */
function ultracache_content_cache_storage_url($relative = '')
{
    return ultracache_uploads_storage_url('ultracache/cache/' . ultracache_storage_clean_relative_path($relative));
}

/**
 * Return the internal UltraCache disk-object-cache root under uploads.
 *
 * @param string $relative Optional relative path under the object cache root.
 * @return string
 */
function ultracache_object_cache_storage_dir($relative = '')
{
    $base = ultracache_uploads_storage_dir('ultracache/object-cache');
    return '' === $base ? '' : trailingslashit(ultracache_storage_join_path($base, $relative));
}

/**
 * Return the public URL for the UltraCache object-cache storage root.
 *
 * This is used only for the SQLite exposure self-test.
 *
 * @param string $relative Optional relative path under the object-cache root.
 * @return string
 */
function ultracache_object_cache_storage_url($relative = '')
{
    return ultracache_uploads_storage_url('ultracache/object-cache/' . ultracache_storage_clean_relative_path($relative));
}

/**
 * Map public generated asset buckets to their current storage directories.
 *
 * @param string $bucket Storage bucket.
 * @return string
 */
function ultracache_generated_asset_bucket_slug($bucket)
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

/**
 * Return the filesystem path for a public generated asset bucket under uploads/ultracache.
 *
 * @param string $bucket   Storage bucket.
 * @param string $relative Optional relative filename/path inside the bucket.
 * @return string
 */
function ultracache_generated_asset_dir($bucket, $relative = '')
{
    $slug = ultracache_generated_asset_bucket_slug($bucket);
    if ('' === $slug) {
        return '';
    }

    $base = ultracache_uploads_storage_dir('ultracache/' . $slug);
    if ('' === $base) {
        return '';
    }

    $relative = ultracache_storage_clean_relative_path($relative);
    if ('' === $relative) {
        return trailingslashit($base);
    }

    return ultracache_storage_join_path($base, $relative);
}

/**
 * Return the URL for a public generated asset bucket under uploads/ultracache.
 *
 * @param string $bucket   Storage bucket.
 * @param string $relative Optional relative filename/path inside the bucket.
 * @return string
 */
function ultracache_generated_asset_url($bucket, $relative = '')
{
    $slug = ultracache_generated_asset_bucket_slug($bucket);
    if ('' === $slug) {
        return '';
    }

    return ultracache_uploads_storage_url('ultracache/' . $slug . '/' . ultracache_storage_clean_relative_path($relative));
}

/**
 * Return a root-relative public URL path for a generated asset bucket.
 *
 * @param string $bucket Storage bucket.
 * @return string
 */
function ultracache_generated_asset_public_path($bucket = '')
{
    $relative = '' !== (string) $bucket ? ultracache_generated_asset_bucket_slug($bucket) . '/' : '';
    $path = (string) wp_parse_url(ultracache_generated_asset_url($bucket, ''), PHP_URL_PATH);
    if ('' === (string) $bucket) {
        $uploads = ultracache_uploads_base_info();
        $path = (string) wp_parse_url(ultracache_storage_join_url($uploads['baseurl'], 'ultracache/'), PHP_URL_PATH);
    }
    if ('' === $path) {
        return '';
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
}

/**
 * Return dynamic public URL path markers for UltraCache uploads assets.
 *
 * These markers are derived from wp_get_upload_dir()/wp_upload_dir() via
 * ultracache_generated_asset_public_path(). They intentionally replace brittle
 * hardcoded generated-uploads checks for customized upload paths.
 *
 * @param string[] $buckets Optional generated asset buckets.
 * @return string[]
 */
function ultracache_generated_asset_public_path_markers(array $buckets = array())
{
    $markers = array();
    if (empty($buckets)) {
        $markers[] = ultracache_generated_asset_public_path();
    } else {
        foreach ($buckets as $bucket) {
            $marker = ultracache_generated_asset_public_path((string) $bucket);
            if ('' !== $marker) {
                $markers[] = $marker;
            }
        }
    }

    return array_values(array_unique(array_filter($markers, static function ($marker) {
        return '' !== (string) $marker;
    })));
}

/**
 * Check whether a URL/path/HTML fragment references generated uploads assets.
 *
 * @param string   $source  URL, path, or HTML fragment.
 * @param string[] $buckets Optional generated asset buckets.
 * @return bool
 */
function ultracache_generated_asset_reference_matches($source, array $buckets = array())
{
    $markers = ultracache_generated_asset_public_path_markers($buckets);
    return !empty($markers) && function_exists('ultracache_public_path_contains_any') && ultracache_public_path_contains_any((string) $source, $markers);
}

/**
 * Check whether a filesystem path is inside generated uploads asset storage.
 *
 * @param string   $path    Filesystem path.
 * @param string[] $buckets Optional generated asset buckets.
 * @return bool
 */
function ultracache_generated_asset_local_path_matches($path, array $buckets = array())
{
    $roots = array();
    if (empty($buckets)) {
        $uploads = ultracache_uploads_base_info();
        if (!empty($uploads['basedir'])) {
            $roots[] = ultracache_storage_join_path($uploads['basedir'], 'ultracache/');
        }
    } else {
        foreach ($buckets as $bucket) {
            $root = ultracache_generated_asset_dir((string) $bucket);
            if ('' !== $root) {
                $roots[] = $root;
            }
        }
    }

    foreach ($roots as $root) {
        if ('' !== (string) $root && function_exists('ultracache_path_is_within_root') && ultracache_path_is_within_root((string) $path, (string) $root)) {
            return true;
        }
    }

    return false;
}

/**
 * Check whether a filesystem path is inside UltraCache internal uploads cache storage.
 *
 * @param string $path Filesystem path.
 * @return bool
 */
function ultracache_internal_cache_local_path_matches($path)
{
    $root = ultracache_content_cache_storage_dir();
    return '' !== $root && function_exists('ultracache_path_is_within_root') && ultracache_path_is_within_root((string) $path, $root);
}

/**
 * Return WordPress uploads base directory and URL using WordPress APIs.
 *
 * @return array{basedir:string,baseurl:string}
 */
function ultracache_uploads_base_info()
{
    $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array());
    $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

    return array(
        'basedir' => untrailingslashit($basedir),
        'baseurl' => untrailingslashit($baseurl),
    );
}

/**
 * Return a filesystem path inside the WordPress uploads directory.
 *
 * @param string $relative Optional relative path inside uploads.
 * @return string
 */
function ultracache_uploads_storage_dir($relative = '')
{
    $uploads = ultracache_uploads_base_info();
    if ('' === $uploads['basedir']) {
        return '';
    }

    $relative = ultracache_storage_clean_relative_path($relative);
    return '' === $relative
        ? trailingslashit(wp_normalize_path($uploads['basedir']))
        : ultracache_storage_join_path($uploads['basedir'], $relative);
}

/**
 * Return a public URL inside the WordPress uploads directory.
 *
 * @param string $relative Optional relative path inside uploads.
 * @return string
 */
function ultracache_uploads_storage_url($relative = '')
{
    $uploads = ultracache_uploads_base_info();
    if ('' === $uploads['baseurl']) {
        return '';
    }

    return ultracache_storage_join_url($uploads['baseurl'], $relative);
}

/**
 * Return the current root-relative public uploads path using WordPress upload APIs.
 *
 * @param string $relative Optional relative path under uploads.
 * @return string
 */
function ultracache_uploads_public_path($relative = '')
{
    $url = ultracache_uploads_storage_url($relative);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if ('' === $path) {
        return '';
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
}

/**
 * Return the current optimized media derivative directory.
 *
 * Optimized image derivatives are public generated media assets under uploads/ultracache/images.
 *
 * @param string $format Optional image format such as avif or webp.
 * @return string
 */
function ultracache_optimized_images_storage_dir($format = '')
{
    $relative = 'ultracache/images';
    $format = strtolower(trim((string) $format));
    if (in_array($format, array('avif', 'webp'), true)) {
        $relative .= '/' . $format;
    }

    $dir = ultracache_uploads_storage_dir($relative);
    return '' === $dir ? '' : trailingslashit($dir);
}

/**
 * Return the current full URL for optimized media derivatives.
 *
 * @param string $format Optional image format such as avif or webp.
 * @return string
 */
function ultracache_optimized_images_storage_url($format = '')
{
    $relative = 'ultracache/images';
    $format = strtolower(trim((string) $format));
    if (in_array($format, array('avif', 'webp'), true)) {
        $relative .= '/' . $format;
    }

    $url = ultracache_uploads_storage_url($relative);
    return '' === $url ? '' : trailingslashit($url);
}

/**
 * Return the current root-relative public path for optimized media derivatives.
 *
 * @param string $format Optional image format such as avif or webp.
 * @return string
 */
function ultracache_optimized_images_storage_url_path($format = '')
{
    $path = (string) wp_parse_url(ultracache_optimized_images_storage_url($format), PHP_URL_PATH);
    if ('' === $path) {
        $path = ultracache_uploads_public_path('ultracache/images');
        $format = strtolower(trim((string) $format));
        if (in_array($format, array('avif', 'webp'), true)) {
            $path = ultracache_uploads_public_path('ultracache/images/' . $format);
        }
    }

    return trailingslashit('/' . ltrim(str_replace('\\', '/', $path), '/'));
}

function ultracache_dropin_exists($basename)
{
    $filesystem = ultracache_get_wp_filesystem();
    $path = ultracache_dropin_path($basename);
    return (bool) ($filesystem && '' !== $path && $filesystem->exists($path) && $filesystem->is_file($path));
}

function ultracache_read_dropin($basename)
{
    $filesystem = ultracache_get_wp_filesystem();
    $path = ultracache_dropin_path($basename);
    if (!$filesystem || '' === $path || !$filesystem->exists($path) || !$filesystem->is_file($path)) {
        return false;
    }

    return $filesystem->get_contents($path);
}

/**
 * Atomically replace a supported WordPress cache drop-in through WP_Filesystem.
 *
 * @param string $basename Drop-in basename.
 * @param string $contents Complete PHP drop-in contents.
 * @return bool
 */
function ultracache_write_dropin($basename, $contents)
{
    $filesystem = ultracache_get_wp_filesystem();
    $target = ultracache_dropin_path($basename);
    if (!$filesystem || '' === $target || !is_string($contents) || '' === $contents) {
        return false;
    }

    $temporary = $target . '.tmp-' . wp_generate_password(12, false, false);
    if (!$filesystem->put_contents($temporary, $contents, FS_CHMOD_FILE)) {
        return false;
    }

    if (!$filesystem->move($temporary, $target, true)) {
        $filesystem->delete($temporary, false, 'f');
        return false;
    }

    return $filesystem->exists($target) && $filesystem->is_file($target);
}

function ultracache_delete_dropin($basename)
{
    $filesystem = ultracache_get_wp_filesystem();
    $path = ultracache_dropin_path($basename);
    if (!$filesystem || '' === $path) {
        return false;
    }

    if (!$filesystem->exists($path)) {
        return true;
    }

    return (bool) ($filesystem->delete($path, false, 'f') || !$filesystem->exists($path));
}

function ultracache_dropin_filesize($basename)
{
    $filesystem = ultracache_get_wp_filesystem();
    $path = ultracache_dropin_path($basename);
    return ($filesystem && '' !== $path && $filesystem->exists($path)) ? max(0, (int) $filesystem->size($path)) : 0;
}

function ultracache_dropin_filemtime($basename)
{
    $filesystem = ultracache_get_wp_filesystem();
    $path = ultracache_dropin_path($basename);
    return ($filesystem && '' !== $path && $filesystem->exists($path)) ? max(0, (int) $filesystem->mtime($path)) : 0;
}

function ultracache_get_wp_filesystem()
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

    if (!function_exists('WP_Filesystem')
        && !ultracache_require_wordpress_admin_include('file.php', 'WP_Filesystem')) {
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

function ultracache_path_is_writable($path)
{
    $filesystem = ultracache_get_wp_filesystem();
    if ($filesystem && method_exists($filesystem, 'is_writable')) {
        return (bool) $filesystem->is_writable($path);
    }

    if (function_exists('wp_is_writable')) {
        return wp_is_writable($path);
    }

    return false;
}
