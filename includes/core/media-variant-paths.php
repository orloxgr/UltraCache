<?php
/**
 * Generated-media path and upload filename helpers for UltraCache.
 *
 * Generated variants use the long-standing stem-based identity used by the
 * public plugin baseline: photo.jpg and photo.png both map to photo.avif or
 * photo.webp. New image filenames are made unique across supported source
 * extensions before WordPress writes them, preventing new ambiguous stems.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return source extensions supported by the generated-media format.
 *
 * @param string $format Optional target format: avif or webp.
 * @return string[]
 */
function ultracache_get_generated_media_source_extensions($format = '')
{
    $format = strtolower(trim((string) $format));

    if ('avif' === $format) {
        return array('jpg', 'jpeg', 'png', 'webp');
    }

    if ('webp' === $format) {
        return array('jpg', 'jpeg', 'png', 'avif');
    }

    if ('' === $format) {
        return array('jpg', 'jpeg', 'png', 'webp', 'avif');
    }

    return array();
}

/**
 * Normalize and validate an uploads-relative source image path.
 *
 * @param string $relative_path Uploads-relative source path.
 * @param string $format        Optional target format used to enforce source compatibility.
 * @return string|false
 */
function ultracache_normalize_media_source_relative_path($relative_path, $format = '')
{
    $relative_path = rawurldecode((string) $relative_path);
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
    $format = strtolower(trim((string) $format));

    if ('' === $relative_path || false !== strpos($relative_path, "\0")) {
        return false;
    }

    foreach (explode('/', $relative_path) as $segment) {
        if ('' === $segment || '.' === $segment || '..' === $segment) {
            return false;
        }
    }

    if (false !== strpos('/' . $relative_path . '/', '/ultracache/images/')) {
        return false;
    }

    $extensions = ultracache_get_generated_media_source_extensions($format);
    if (empty($extensions)) {
        return false;
    }

    return preg_match('/\.(?:' . implode('|', array_map('preg_quote', $extensions)) . ')$/i', $relative_path)
        ? $relative_path
        : false;
}

/**
 * Build the canonical uploads-relative generated variant path.
 *
 * @param string $source_relative_path Uploads-relative source path.
 * @param string $format               Target format: avif or webp.
 * @return string|false
 */
function ultracache_build_optimized_media_relative_path($source_relative_path, $format)
{
    $format = strtolower(trim((string) $format));
    if (!in_array($format, array('avif', 'webp'), true)) {
        return false;
    }

    $source_relative_path = ultracache_normalize_media_source_relative_path($source_relative_path, $format);
    if (false === $source_relative_path) {
        return false;
    }

    $extensions = ultracache_get_generated_media_source_extensions($format);
    $optimized_relative_path = preg_replace(
        '/\.(?:' . implode('|', array_map('preg_quote', $extensions)) . ')$/i',
        '.' . $format,
        $source_relative_path
    );

    return is_string($optimized_relative_path) && '' !== $optimized_relative_path
        ? $optimized_relative_path
        : false;
}

/**
 * Normalize a canonical non-upload local image source descriptor.
 *
 * @param array  $source Canonical descriptor from ultracache_local_public_source_descriptor().
 * @param string $format Target format: avif or webp.
 * @return array{source_scope:string,source_owner:string,source_relative_path:string,source_identity:string,source_filename:string}|array{}
 */
function ultracache_normalize_local_asset_media_source_descriptor(array $source, $format)
{
    $format = strtolower(trim((string) $format));
    if (!in_array($format, array('avif', 'webp'), true)) {
        return array();
    }

    $scope = sanitize_key((string) ($source['source_scope'] ?? ''));
    if (!in_array($scope, array('theme', 'plugin', 'mu-plugin', 'wordpress-includes'), true)) {
        return array();
    }

    $owner = sanitize_key((string) ($source['source_owner'] ?? ''));
    if ('' === $owner) {
        return array();
    }

    $relative = ultracache_normalize_media_source_relative_path(
        (string) ($source['source_relative_path'] ?? ''),
        $format
    );
    if (false === $relative) {
        return array();
    }

    $identity = hash('sha256', $scope . '|' . $owner . '|' . str_replace('\\', '/', $relative));
    $filename = sanitize_file_name((string) wp_basename($relative));
    if ('' === $filename) {
        return array();
    }

    return array(
        'source_scope'         => $scope,
        'source_owner'         => $owner,
        'source_relative_path' => $relative,
        'source_identity'      => $identity,
        'source_filename'      => $filename,
    );
}

/**
 * Build a collision-resistant path relative to the local-asset format root.
 *
 * The full source filename is retained before the target extension, while the
 * canonical scope/owner/relative identity selects an isolated SHA-256 directory.
 * Upload sources continue using ultracache_build_optimized_media_relative_path().
 *
 * @param array  $source Canonical local source descriptor.
 * @param string $format Target format: avif or webp.
 * @return string|false
 */
function ultracache_build_local_asset_optimized_media_relative_path(array $source, $format)
{
    $format = strtolower(trim((string) $format));
    $normalized = ultracache_normalize_local_asset_media_source_descriptor($source, $format);
    if (empty($normalized)) {
        return false;
    }

    $identity = (string) $normalized['source_identity'];
    $relative = implode(
        '/',
        array(
            (string) $normalized['source_scope'],
            (string) $normalized['source_owner'],
            substr($identity, 0, 2),
            $identity,
            (string) $normalized['source_filename'] . '.' . $format,
        )
    );

    return ultracache_storage_clean_relative_path($relative);
}

/**
 * Resolve one fresh generated AVIF/WebP variant from a canonical local source.
 *
 * Uploads retain the established stem-based generated identity. Theme, plugin,
 * mu-plugin, and WordPress-includes sources use the isolated local-asset identity.
 * This lookup is side-effect free and is shared by HTML/CSS rewrite and LCP.
 *
 * @param array  $source Canonical descriptor from ultracache_local_public_source_descriptor().
 * @param string $format Target format: avif or webp.
 * @return array{status:string,url:string|false,sourcePath:string,optimizedPath:string,optimizedRelativePath:string,sourceScope:string,sourceOwner:string,sourceIdentity:string,sourceMtime:int,sourceSize:int}
 */
function ultracache_get_optimized_media_variant_lookup_for_source(array $source, $format)
{
    $format = strtolower(trim((string) $format));
    if (!in_array($format, array('avif', 'webp'), true)) {
        return array('status' => 'invalid', 'url' => false);
    }

    $scope = (string) ($source['source_scope'] ?? '');
    $source_path = isset($source['local_path']) ? wp_normalize_path((string) $source['local_path']) : '';
    $source_relative = (string) ($source['source_relative_path'] ?? '');
    if ('' === $scope || '' === $source_path || '' === $source_relative || !is_file($source_path) || !is_readable($source_path)) {
        return array('status' => 'source_missing', 'url' => false);
    }

    if ('uploads' === $scope) {
        $optimized_relative = ultracache_build_optimized_media_relative_path($source_relative, $format);
        $optimized_root = function_exists('ultracache_optimized_images_storage_dir')
            ? ultracache_optimized_images_storage_dir($format)
            : '';
        $public_root = function_exists('ultracache_optimized_images_storage_url_path')
            ? ultracache_optimized_images_storage_url_path($format)
            : '';
    } else {
        $optimized_relative = ultracache_build_local_asset_optimized_media_relative_path($source, $format);
        $optimized_root = function_exists('ultracache_local_asset_optimized_images_storage_dir')
            ? ultracache_local_asset_optimized_images_storage_dir($format)
            : '';
        $public_root = function_exists('ultracache_local_asset_optimized_images_storage_url_path')
            ? ultracache_local_asset_optimized_images_storage_url_path($format)
            : '';
    }

    if (!is_string($optimized_relative) || '' === $optimized_relative || '' === $optimized_root || '' === $public_root) {
        return array('status' => 'invalid', 'url' => false);
    }

    $optimized_relative = ultracache_storage_clean_relative_path($optimized_relative);
    if ('' === $optimized_relative) {
        return array('status' => 'invalid', 'url' => false);
    }

    $optimized_path = wp_normalize_path(trailingslashit($optimized_root) . $optimized_relative);
    $source_mtime = function_exists('ultracache_safe_filemtime')
        ? ultracache_safe_filemtime($source_path, 'shared_media_variant_source_mtime')
        : filemtime($source_path);
    $source_size = function_exists('ultracache_safe_filesize')
        ? ultracache_safe_filesize($source_path, 'shared_media_variant_source_size')
        : filesize($source_path);
    $variant_mtime = is_file($optimized_path) && is_readable($optimized_path)
        ? (function_exists('ultracache_safe_filemtime')
            ? ultracache_safe_filemtime($optimized_path, 'shared_media_variant_output_mtime')
            : filemtime($optimized_path))
        : false;

    $source_mtime = false === $source_mtime ? 0 : max(0, (int) $source_mtime);
    $source_size = false === $source_size ? 0 : max(0, (int) $source_size);
    $status = 'missing';
    if (false !== $variant_mtime) {
        $variant_mtime = max(0, (int) $variant_mtime);
        $status = ($source_mtime > 0 && $variant_mtime > 0)
            ? ($variant_mtime >= $source_mtime ? 'fresh' : 'stale')
            : 'indeterminate';
    }

    $lookup = array(
        'status'                => $status,
        'url'                   => false,
        'sourcePath'            => $source_path,
        'optimizedPath'         => $optimized_path,
        'optimizedRelativePath' => $optimized_relative,
        'sourceScope'           => $scope,
        'sourceOwner'           => (string) ($source['source_owner'] ?? ''),
        'sourceIdentity'        => (string) ($source['source_identity'] ?? ''),
        'sourceMtime'           => $source_mtime,
        'sourceSize'            => $source_size,
    );
    if ('fresh' !== $status) {
        return $lookup;
    }

    $lookup['url'] = trailingslashit('/' . ltrim(str_replace('\\', '/', $public_root), '/'))
        . implode('/', array_map('rawurlencode', explode('/', $optimized_relative)));
    return $lookup;
}

/**
 * Resolve one fresh generated variant from a local public image URL.
 *
 * @param string $url    Public or root-relative source URL.
 * @param string $format Target format: avif or webp.
 * @return array
 */
function ultracache_get_optimized_media_variant_lookup_for_public_url($url, $format)
{
    $source = function_exists('ultracache_local_public_source_descriptor')
        ? ultracache_local_public_source_descriptor($url, array('jpg', 'jpeg', 'png', 'webp', 'avif'))
        : array();
    if (empty($source)) {
        return array('status' => 'invalid_source', 'url' => false);
    }

    return ultracache_get_optimized_media_variant_lookup_for_source($source, $format);
}

/**
 * Prefer an existing fresh AVIF, then WebP, for one exact local image source.
 *
 * @param string $url Public or root-relative source URL.
 * @return string Original URL or a fresh optimized root-relative URL.
 */
function ultracache_prefer_existing_nextgen_public_image_url($url)
{
    $url = trim((string) $url);
    if ('' === $url) {
        return '';
    }

    foreach (array('avif', 'webp') as $format) {
        $lookup = ultracache_get_optimized_media_variant_lookup_for_public_url($url, $format);
        if (!empty($lookup['url'])) {
            return (string) $lookup['url'];
        }
    }

    return $url;
}

/**
 * Resolve an optimized stem-based path back to an existing uploads source.
 *
 * Existing same-stem files from different source extensions are inherently
 * ambiguous. Preserve the public baseline's deterministic extension order;
 * new ambiguity is prevented by cross-extension upload filename uniqueness.
 *
 * @param string $optimized_relative_path Generated uploads-relative path under the format directory.
 * @param string $format                  Generated format: avif or webp.
 * @return string|false
 */
function ultracache_get_source_relative_path_from_optimized_media_path($optimized_relative_path, $format)
{
    $format = strtolower(trim((string) $format));
    if (!in_array($format, array('avif', 'webp'), true)) {
        return false;
    }

    $optimized_relative_path = rawurldecode((string) $optimized_relative_path);
    $optimized_relative_path = ltrim(str_replace('\\', '/', $optimized_relative_path), '/');
    $suffix = '.' . $format;

    if (
        strlen($optimized_relative_path) <= strlen($suffix)
        || 0 !== strcasecmp(substr($optimized_relative_path, -strlen($suffix)), $suffix)
        || false !== strpos($optimized_relative_path, "\0")
    ) {
        return false;
    }

    foreach (explode('/', $optimized_relative_path) as $segment) {
        if ('' === $segment || '.' === $segment || '..' === $segment) {
            return false;
        }
    }

    $relative_stem = substr($optimized_relative_path, 0, -strlen($suffix));
    if ('' === $relative_stem) {
        return false;
    }

    $uploads = function_exists('ultracache_uploads_base_info')
        ? ultracache_uploads_base_info()
        : (function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array());
    if (empty($uploads['basedir'])) {
        return false;
    }

    $base_dir = untrailingslashit(wp_normalize_path((string) $uploads['basedir']));
    $relative_dir = wp_normalize_path(dirname($relative_stem));
    $relative_dir = ('.' === $relative_dir || '/' === $relative_dir) ? '' : trim($relative_dir, '/');
    $stem = basename($relative_stem);
    if ('' === $stem) {
        return false;
    }

    $source_dir = $base_dir . ('' !== $relative_dir ? '/' . $relative_dir : '');
    $extensions = ultracache_get_generated_media_source_extensions($format);

    foreach ($extensions as $extension) {
        $candidate_relative = ('' !== $relative_dir ? trailingslashit($relative_dir) : '') . $stem . '.' . $extension;
        $candidate_file = $base_dir . '/' . $candidate_relative;
        if (is_file($candidate_file) && is_readable($candidate_file)) {
            return $candidate_relative;
        }
    }

    if (!is_dir($source_dir)) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Exact source spelling is required for reverse URL mapping.
    $files = scandir($source_dir);
    if (!is_array($files)) {
        return false;
    }

    $matches = array();
    foreach ($files as $file) {
        if ('.' === $file || '..' === $file) {
            continue;
        }

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $candidate_stem = (string) pathinfo($file, PATHINFO_FILENAME);
        if (!in_array($extension, $extensions, true) || 0 !== strcasecmp($candidate_stem, $stem)) {
            continue;
        }

        $candidate_file = $source_dir . '/' . $file;
        if (is_file($candidate_file) && is_readable($candidate_file)) {
            $matches[$extension] = $file;
        }
    }

    foreach ($extensions as $extension) {
        if (!isset($matches[$extension])) {
            continue;
        }

        return ('' !== $relative_dir ? trailingslashit($relative_dir) : '') . $matches[$extension];
    }

    return false;
}

/**
 * Make an image filename unique across all generated-media source extensions.
 *
 * WordPress already makes the complete filename unique. This supplements that
 * contract by treating photo.jpg and photo.png as the same generated-media stem
 * and continuing WordPress-style numbering: photo-2.png, photo-3.png, and so on.
 *
 * @param string $dir      Destination directory.
 * @param string $filename WordPress-selected filename.
 * @return string
 */
function ultracache_get_cross_extension_unique_image_filename($dir, $filename)
{
    $dir = untrailingslashit(wp_normalize_path((string) $dir));
    $filename = sanitize_file_name((string) $filename);
    if ('' === $dir || '' === $filename || !is_dir($dir)) {
        return $filename;
    }

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $extensions = ultracache_get_generated_media_source_extensions();
    if (!in_array($extension, $extensions, true)) {
        return $filename;
    }

    $candidate_stem = (string) pathinfo($filename, PATHINFO_FILENAME);
    if ('' === $candidate_stem) {
        return $filename;
    }

    static $occupied_stems_by_dir = array();
    if (!isset($occupied_stems_by_dir[$dir])) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Upload filename selection needs one request-local directory inventory.
        $files = scandir($dir);
        if (!is_array($files)) {
            return $filename;
        }

        $occupied_stems_by_dir[$dir] = array();
        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }

            $existing_extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($existing_extension, $extensions, true)) {
                continue;
            }

            $existing_stem = strtolower((string) pathinfo($file, PATHINFO_FILENAME));
            if ('' !== $existing_stem) {
                $occupied_stems_by_dir[$dir][$existing_stem] = true;
            }
        }
    }

    $occupied_stems = &$occupied_stems_by_dir[$dir];
    if (!isset($occupied_stems[strtolower($candidate_stem)])) {
        $occupied_stems[strtolower($candidate_stem)] = true;
        return $filename;
    }

    $base_stem = $candidate_stem;
    $number = 1;
    if (preg_match('/^(.*)-(\d+)$/', $candidate_stem, $matches) && '' !== (string) $matches[1]) {
        $base_stem = (string) $matches[1];
        $number = max(1, (int) $matches[2]);
    }

    $limit = count($occupied_stems) + 2;
    for ($attempt = 0; $attempt < $limit; $attempt++) {
        ++$number;
        $next_stem = $base_stem . '-' . $number;
        if (!isset($occupied_stems[strtolower($next_stem)])) {
            $occupied_stems[strtolower($next_stem)] = true;
            return $next_stem . '.' . $extension;
        }
    }

    return $filename;
}
