<?php
/**
 * Guarded filesystem read, write, path, and generated-asset helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

function ultracache_php_string_literal($value)
{
    return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
}

function ultracache_php_float_literal($value)
{
    return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
}


/**
 * Internal file read primitive. Do not call this with user/configurable paths.
 * Use ultracache_guarded_file_get_contents() or ultracache_safe_file_get_contents() instead.
 */
function ultracache_internal_file_get_contents($path, $context = '', $suppress_warnings = false)
{
    $path = (string) $path;
    $context = (string) $context;
    $filesystem = ultracache_get_wp_filesystem();

    if (!$filesystem || !$filesystem->exists($path) || !$filesystem->is_file($path)) {
        ultracache_debug_log('file_get_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'wp-filesystem-unavailable-or-file-missing'));
        return false;
    }

    $data = $filesystem->get_contents($path);
    if (false === $data) {
        ultracache_debug_log('file_get_contents failed', array('path' => $path, 'context' => $context));
    }

    return $data;
}

function ultracache_guarded_file_get_contents($path, $context = '', $suppress_warnings = false, $allowed_roots = array())
{
    $path = (string) $path;
    $context = (string) $context;

    if (!function_exists('ultracache_is_allowed_readable_path') || !ultracache_is_allowed_readable_path($path, $context, is_array($allowed_roots) ? $allowed_roots : array())) {
        ultracache_debug_log('file_get_contents blocked: path outside allowed read roots', array('path' => $path, 'context' => $context));
        return false;
    }

    return ultracache_internal_file_get_contents($path, $context, $suppress_warnings);
}

/**
 * Back-compatible guarded read helper. The name is retained for existing code,
 * but reads now pass through UltraCache's readable-path allowlist.
 */
function ultracache_safe_file_get_contents($path, $context = '', $suppress_warnings = false, $allowed_roots = array())
{
    return ultracache_guarded_file_get_contents($path, $context, $suppress_warnings, $allowed_roots);
}

/**
 * Return narrowly scoped filesystem roots for local CSS/JS/font asset reads.
 * These guards are for optimization reads only; blocked reads must leave the
 * original frontend asset untouched rather than breaking the page.
 */
function ultracache_get_asset_readable_roots($type = '')
{
    $type = strtolower(trim((string) $type));
    $roots = array();

    if ('generated-css' === $type && defined('ULTRACACHE_CACHE_DIR')) {
        $roots = array(ULTRACACHE_CACHE_DIR);
    } elseif ('cached-html' === $type && defined('ULTRACACHE_CACHE_DIR')) {
        $roots = array(ULTRACACHE_CACHE_DIR);
    } else {
        foreach (array('ULTRACACHE_CACHE_DIR', 'ULTRACACHE_OPTIMIZED_IMAGES_DIR', 'ULTRACACHE_AVIF_DIR', 'ULTRACACHE_WEBP_DIR') as $constant) {
            if (defined($constant)) {
                $roots[] = constant($constant);
            }
        }
        $plugins_root = ultracache_plugins_root_dir();
        if ('' !== $plugins_root) {
            $roots[] = $plugins_root;
        }
        $mu_plugins_root = ultracache_mu_plugins_root_dir();
        if ('' !== $mu_plugins_root) {
            $roots[] = $mu_plugins_root;
        }
        if (function_exists('get_theme_root')) {
            $roots[] = get_theme_root();
        }
        $uploads = ultracache_uploads_base_info();
        if (!empty($uploads['basedir'])) {
            $roots[] = $uploads['basedir'];
        }
        $includes_root = ultracache_wordpress_includes_dir();
        if ('' !== $includes_root) {
            $roots[] = $includes_root;
        }
    }

    $plugin_root = ultracache_plugin_dir();
    if (is_string($plugin_root) && '' !== $plugin_root) {
        $roots[] = $plugin_root;
    }

    $normalized = array();
    foreach ($roots as $root) {
        $root = ultracache_normalize_filesystem_path_for_guard($root);
        if ('' !== $root && !in_array($root, $normalized, true)) {
            $normalized[] = $root;
        }
    }

    return (array) apply_filters('ultracache_asset_readable_roots', $normalized, $type);
}

function ultracache_asset_read_allowed_extensions($type = '')
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

/**
 * Guarded local asset read for optimization pipelines.
 * This never controls frontend delivery; if a read is blocked or fails, callers
 * should skip optimization and keep the original asset reference.
 */
function ultracache_guarded_asset_file_get_contents($path, $type = '', $context = '', $suppress_warnings = false)
{
    $path = (string) $path;
    $type = strtolower(trim((string) $type));
    $context = '' !== (string) $context ? (string) $context : ('asset_read_' . $type);

    $allowed_extensions = ultracache_asset_read_allowed_extensions($type);
    if (!empty($allowed_extensions)) {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ('' === $extension || !in_array($extension, $allowed_extensions, true)) {
            ultracache_debug_log('asset file_get_contents blocked: extension not allowed', array(
                'path' => $path,
                'type' => $type,
                'context' => $context,
                'extension' => $extension,
            ));
            return false;
        }
    }

    return ultracache_guarded_file_get_contents($path, $context, (bool) $suppress_warnings, ultracache_get_asset_readable_roots($type));
}




/**
 * Remove source map reference comments from generated assets.
 *
 * Production-generated UltraCache CSS/JS does not ship matching .map files.
 * Keeping sourceMappingURL comments causes browsers/devtools to request missing
 * .map files and can trigger noisy 404 burst firewall alerts during testing.
 */
function ultracache_strip_source_mapping_url_comments($contents)
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

/**
 * Return whether a PHP write is the inert directory index used below
 * UltraCache-owned generated-data directories.
 *
 * Generic guarded writes must never create executable runtime PHP. Managed
 * WordPress cache drop-ins use ultracache_write_dropin() instead.
 *
 * @param string $path     Destination path.
 * @param string $contents Proposed file contents.
 * @return bool
 */
function ultracache_is_inert_directory_index_php($path, $contents)
{
    if ('index.php' !== strtolower((string) wp_basename((string) $path))) {
        return false;
    }

    $normalized = str_replace(array("\r\n", "\r"), "\n", trim((string) $contents));
    return "<?php\n// Silence is golden." === $normalized;
}

function ultracache_safe_file_put_contents($path, $contents, $flags = 0, $context = '')
{
    $path = (string) $path;
    $context = (string) $context;
    if ('' === $path) {
        ultracache_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'empty-path'));
        return false;
    }

    if (!ultracache_is_allowed_writable_path($path, $context)) {
        ultracache_debug_log('file_put_contents blocked: path outside allowed write roots', array('path' => $path, 'context' => $context));
        return false;
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $php_like_extensions = array('php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc');
    if (in_array($extension, $php_like_extensions, true)
        && (FILE_APPEND === ($flags & FILE_APPEND) || !ultracache_is_inert_directory_index_php($path, $contents))) {
        ultracache_debug_log('file_put_contents blocked: executable PHP is not permitted through the generic write helper', array('path' => $path, 'context' => $context));
        return false;
    }

    $dir = dirname($path);
    if ('' !== $dir && '.' !== $dir && !is_dir($dir)) {
        ultracache_safe_mkdir($dir, 0755, true, $context . ' parent mkdir');
    }

    if ('' !== $dir && '.' !== $dir && (!is_dir($dir) || !ultracache_path_is_writable($dir))) {
        if (!is_dir($dir) || !ultracache_path_is_writable($dir)) {
            ultracache_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'parent-not-writable'));
            return false;
        }
    }

    $filesystem = ultracache_get_wp_filesystem();
    if (!$filesystem || !method_exists($filesystem, 'put_contents')) {
        ultracache_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context, 'reason' => 'wp-filesystem-unavailable'));
        return false;
    }

    $existing = '';
    if (FILE_APPEND === ($flags & FILE_APPEND) && $filesystem->exists($path)) {
        $existing = (string) $filesystem->get_contents($path);
    }

    $data = $existing . (string) $contents;
    $result = $filesystem->put_contents($path, $data, FS_CHMOD_FILE);
    if (false === $result) {
        ultracache_debug_log('file_put_contents failed', array('path' => $path, 'context' => $context));
        return false;
    }

    return strlen($data);
}

function ultracache_normalize_filesystem_path_for_guard($path)
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

function ultracache_path_has_dir_prefix($path, $dir)
{
    $path = ultracache_normalize_filesystem_path_for_guard($path);
    $dir = ultracache_normalize_filesystem_path_for_guard($dir);
    if ('' === $path || '' === $dir) {
        return false;
    }

    $dir = rtrim($dir, '/') . '/';
    return 0 === strpos($path, $dir) || rtrim($path, '/') === rtrim($dir, '/');
}


function ultracache_get_default_readable_roots($context = '')
{
    $roots = array();

    foreach (array('ULTRACACHE_CACHE_DIR', 'ULTRACACHE_OPTIMIZED_IMAGES_DIR', 'ULTRACACHE_AVIF_DIR', 'ULTRACACHE_WEBP_DIR', 'ULTRACACHE_OBJECT_CACHE_DIR') as $constant) {
        if (defined($constant)) {
            $roots[] = constant($constant);
        }
    }
    $plugins_root = ultracache_plugins_root_dir();
    if ('' !== $plugins_root) {
        $roots[] = $plugins_root;
    }
    $mu_plugins_root = ultracache_mu_plugins_root_dir();
    if ('' !== $mu_plugins_root) {
        $roots[] = $mu_plugins_root;
    }
    if (function_exists('get_theme_root')) {
        $roots[] = get_theme_root();
    }
    $uploads = ultracache_uploads_base_info();
    if (!empty($uploads['basedir'])) {
        $roots[] = $uploads['basedir'];
    }
    $includes_root = ultracache_wordpress_includes_dir();
    if ('' !== $includes_root) {
        $roots[] = $includes_root;
    }

    $plugin_root = ultracache_plugin_dir();
    if (is_string($plugin_root) && '' !== $plugin_root) {
        $roots[] = $plugin_root;
    }

    $normalized = array();
    foreach ($roots as $root) {
        $root = ultracache_normalize_filesystem_path_for_guard($root);
        if ('' !== $root && !in_array($root, $normalized, true)) {
            $normalized[] = $root;
        }
    }

    return (array) apply_filters('ultracache_default_readable_roots', $normalized, (string) $context);
}

function ultracache_read_context_allows_wp_config($context)
{
    $context = strtolower((string) $context);
    foreach (array('wp_config', 'wp-cache', 'set_wp_cache_flag', 'get_wp_cache_define_status') as $token) {
        if (false !== strpos($context, $token)) {
            return true;
        }
    }
    return false;
}

function ultracache_read_context_allows_root_server_config($context)
{
    $context = strtolower((string) $context);
    foreach (array('sync_browser_cache_rules', 'sync_apache_static_html_delivery_rules', 'browser_cache', 'apache_static_html_delivery', 'dashboard diagnostics', 'dashboard path diagnostic', 'path_diagnostic') as $token) {
        if (false !== strpos($context, $token)) {
            return true;
        }
    }
    return false;
}

function ultracache_is_allowed_readable_path($path, $context = '', $allowed_roots = array())
{
    $path = is_string($path) ? trim($path) : '';
    $context = (string) $context;
    if ('' === $path) {
        return false;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        return false;
    }

    $normalized = ultracache_normalize_filesystem_path_for_guard($path);
    if ('' === $normalized) {
        return false;
    }

    $roots = is_array($allowed_roots) && !empty($allowed_roots) ? $allowed_roots : ultracache_get_default_readable_roots($context);
    foreach ($roots as $root) {
        $root = ultracache_normalize_filesystem_path_for_guard($root);
        if ('' !== $root && ultracache_path_has_dir_prefix($normalized, $root)) {
            return true;
        }
    }

    $base = basename($normalized);

    if ('wp-config.php' === $base && ultracache_read_context_allows_wp_config($context)) {
        return ultracache_is_loaded_wp_config_path($normalized);
    }

    if (ultracache_read_context_allows_root_server_config($context) && in_array($base, array('.htaccess', 'web.config'), true)) {
        $root = ultracache_normalize_filesystem_path_for_guard(ultracache_get_wordpress_home_path());
        if ('' !== $root && ultracache_path_has_dir_prefix($normalized, $root)) {
            return true;
        }
    }

    foreach (array('advanced-cache.php', 'object-cache.php') as $dropin_file) {
        $dropin_path = function_exists('ultracache_dropin_path') ? ultracache_dropin_path($dropin_file) : '';
        if ('' !== $dropin_path && $normalized === ultracache_normalize_filesystem_path_for_guard($dropin_path)) {
            // WordPress requires these two drop-ins directly under its content directory; allow read access only to these exact managed file paths for owner/conflict detection.
            return true;
        }
    }

    return (bool) apply_filters('ultracache_is_allowed_readable_path', false, $normalized, $context, $allowed_roots);
}


function ultracache_is_allowed_destructive_path($path, $context = '')
{
    $normalized = ultracache_normalize_filesystem_path_for_guard($path);
    if ('' === $normalized) {
        return false;
    }

    $allowed_dirs = array();
    foreach (array('ULTRACACHE_CACHE_DIR', 'ULTRACACHE_AVIF_DIR', 'ULTRACACHE_WEBP_DIR', 'ULTRACACHE_OBJECT_CACHE_DIR') as $constant) {
        if (defined($constant)) {
            $allowed_dirs[] = constant($constant);
        }
    }

    $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : array();
    $uploads_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    if ('' !== $uploads_base && function_exists('ultracache_storage_join_path')) {
        $allowed_dirs[] = ultracache_storage_join_path($uploads_base, 'ultracache');
    }

    foreach ($allowed_dirs as $dir) {
        if (ultracache_path_has_dir_prefix($normalized, $dir)) {
            return true;
        }
    }

    if (false !== strpos((string) $context, 'media_library_replacement_cleanup_original')) {
        $uploads_base = '';
        $uploads      = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : array();
        if (isset($uploads['basedir']) && is_string($uploads['basedir'])) {
            $uploads_base = ultracache_normalize_filesystem_path_for_guard($uploads['basedir']);
        }

        if ('' !== $uploads_base && ultracache_path_has_dir_prefix($normalized, $uploads_base) && preg_match('/\.(?:jpe?g|png)$/i', $normalized)) {
            return true;
        }
    }

    $allowed_files = array_filter(array(
        function_exists('ultracache_dropin_path') ? ultracache_dropin_path('advanced-cache.php') : '',
        function_exists('ultracache_dropin_path') ? ultracache_dropin_path('object-cache.php') : '',
    ));

    foreach ($allowed_files as $file) {
        if ($normalized === ultracache_normalize_filesystem_path_for_guard($file)) {
            return true;
        }
    }


    return false;
}

function ultracache_is_allowed_writable_path($path, $context = '')
{
    $normalized = ultracache_normalize_filesystem_path_for_guard($path);
    $context = (string) $context;
    if ('' === $normalized) {
        return false;
    }

    $allowed_dirs = array();
    foreach (array('ULTRACACHE_CACHE_DIR', 'ULTRACACHE_OPTIMIZED_IMAGES_DIR', 'ULTRACACHE_AVIF_DIR', 'ULTRACACHE_WEBP_DIR', 'ULTRACACHE_OBJECT_CACHE_DIR') as $constant) {
        if (defined($constant)) {
            $allowed_dirs[] = constant($constant);
        }
    }

    $uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : array();
    $uploads_base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    if ('' !== $uploads_base && function_exists('ultracache_storage_join_path')) {
        $allowed_dirs[] = ultracache_storage_join_path($uploads_base, 'ultracache');
    }

    foreach ($allowed_dirs as $dir) {
        if (ultracache_path_has_dir_prefix($normalized, $dir)) {
            return true;
        }
    }

    $base = basename($normalized);
    $dir = dirname($normalized);

    foreach (array('advanced-cache.php', 'object-cache.php') as $managed_file) {
        $target = function_exists('ultracache_dropin_path') ? ultracache_dropin_path($managed_file) : '';
        if ('' === $target) {
            continue;
        }
        $dropin_dir = dirname(wp_normalize_path($target));
        if ($normalized === ultracache_normalize_filesystem_path_for_guard($target)) {
            return true;
        }
        if (0 === strpos($base, $managed_file . '.tmp-') && ultracache_path_has_dir_prefix($normalized, $dropin_dir)) {
            return true;
        }
    }


    if (false !== strpos($context, 'sync_browser_cache_rules') || false !== strpos($context, 'sync_apache_static_html_delivery_rules')) {
        $root = wp_normalize_path(ultracache_get_wordpress_home_path());
        if (ultracache_path_has_dir_prefix($normalized, $root) && '.htaccess' === $base) {
            return true;
        }
    }

    if (false !== strpos($context, 'media_library_replacement_theme_css')) {
        $theme_roots = array();
        if (function_exists('get_stylesheet_directory')) {
            $theme_roots[] = get_stylesheet_directory();
        }
        if (function_exists('get_template_directory')) {
            $theme_roots[] = get_template_directory();
        }

        foreach (array_filter(array_unique($theme_roots)) as $theme_root) {
            $theme_root = ultracache_normalize_filesystem_path_for_guard($theme_root);
            if ('' !== $theme_root && ultracache_path_has_dir_prefix($normalized, $theme_root) && preg_match('/\.css$/i', $normalized)) {
                return true;
            }
        }
    }

    return (bool) apply_filters('ultracache_is_allowed_writable_path', false, $normalized, $context);
}

function ultracache_safe_unlink($path, $context = '')
{
    $path = is_string($path) ? $path : '';
    if ('' === $path) {
        return true;
    }

    $filesystem = ultracache_get_wp_filesystem();
    if (!$filesystem) {
        return false;
    }

    if (!$filesystem->exists($path)) {
        return true;
    }

    if (!ultracache_is_allowed_destructive_path($path, $context)) {
        ultracache_debug_log('unlink blocked: path outside allowed roots', array('path' => $path, 'context' => (string) $context));
        return false;
    }

    $result = $filesystem->delete($path, false, 'f');
    if (!$result && $filesystem->exists($path)) {
        ultracache_debug_log('unlink failed', array('path' => $path, 'context' => (string) $context));
        return false;
    }

    return true;
}

function ultracache_safe_rename($from, $to, $context = '')
{
    $from = is_string($from) ? $from : '';
    $to = is_string($to) ? $to : '';
    $filesystem = ultracache_get_wp_filesystem();
    if ('' === $from || '' === $to || !$filesystem || !method_exists($filesystem, 'move')) {
        ultracache_debug_log('rename failed', array('from' => $from, 'to' => $to, 'context' => (string) $context, 'reason' => 'invalid-path-or-wp-filesystem-unavailable'));
        return false;
    }

    if ($from === $to) {
        return $filesystem->exists($to);
    }

    $target_extension = strtolower((string) pathinfo($to, PATHINFO_EXTENSION));
    if (in_array($target_extension, array('php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc'), true)) {
        ultracache_debug_log('rename blocked: executable PHP targets are not permitted through the generic rename helper', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        return false;
    }

    if (!ultracache_is_allowed_writable_path($from, $context) || !ultracache_is_allowed_writable_path($to, $context)) {
        ultracache_debug_log('rename blocked: path outside allowed write roots', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        return false;
    }

    if (!$filesystem->exists($from)) {
        $already_moved = $filesystem->exists($to);
        if (!$already_moved) {
            ultracache_debug_log('rename failed: source missing', array('from' => $from, 'to' => $to, 'context' => (string) $context));
        }
        return $already_moved;
    }

    $result = $filesystem->move($from, $to, true);
    if ($result || ($filesystem->exists($to) && !$filesystem->exists($from))) {
        return true;
    }

    ultracache_debug_log('rename failed', array('from' => $from, 'to' => $to, 'context' => (string) $context));
    return false;
}

function ultracache_safe_mkdir($dir, $mode = 0755, $recursive = true, $context = '')
{
    $dir = is_string($dir) ? $dir : '';
    if ('' === $dir || !ultracache_is_allowed_writable_path($dir, $context)) {
        ultracache_debug_log('mkdir blocked: directory outside allowed write roots', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
        return false;
    }

    if (is_dir($dir)) {
        return true;
    }

    $filesystem = ultracache_get_wp_filesystem();
    if ($recursive && function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
        return true;
    }

    if ($filesystem) {
        $result = $filesystem->mkdir($dir, $mode);
        if ($result || is_dir($dir)) {
            return true;
        }
    }

    ultracache_debug_log('mkdir failed', array('dir' => $dir, 'mode' => $mode, 'recursive' => (bool) $recursive, 'context' => (string) $context));
    return is_dir($dir);
}



function ultracache_safe_rmdir_empty($dir, $context = '')
{
    $dir = is_string($dir) ? $dir : '';
    if ('' === $dir) {
        return true;
    }

    $filesystem = ultracache_get_wp_filesystem();
    if (!$filesystem) {
        return false;
    }

    if (!$filesystem->exists($dir)) {
        return true;
    }

    if (!$filesystem->is_dir($dir) || is_link($dir)) {
        ultracache_debug_log('rmdir empty blocked: path is not a real directory', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    if (!ultracache_is_allowed_destructive_path($dir, $context)) {
        ultracache_debug_log('rmdir empty blocked: path outside allowed roots', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    $result = $filesystem->delete($dir, false, 'd');
    return $result || !$filesystem->exists($dir);
}

function ultracache_safe_rmdir($dir, $context = '')
{
    $dir = is_string($dir) ? $dir : '';
    if ('' === $dir) {
        return true;
    }

    $filesystem = ultracache_get_wp_filesystem();
    if (!$filesystem) {
        return false;
    }

    if (!$filesystem->exists($dir)) {
        return true;
    }

    if (!$filesystem->is_dir($dir) || is_link($dir)) {
        ultracache_debug_log('rmdir blocked: path is not a real directory', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    if (!ultracache_is_allowed_destructive_path($dir, $context)) {
        ultracache_debug_log('rmdir blocked: path outside allowed roots', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    $result = $filesystem->delete($dir, true, 'd');
    if (!$result && $filesystem->exists($dir)) {
        ultracache_debug_log('rmdir failed', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    return true;
}

function ultracache_safe_filemtime($path, $context = '')
{
    $path = (string) $path;
    $filesystem = ultracache_get_wp_filesystem();
    if ('' === $path || !$filesystem || !$filesystem->exists($path)) {
        return false;
    }

    $result = $filesystem->mtime($path);
    if (false === $result) {
        ultracache_debug_log('filemtime failed', array('path' => $path, 'context' => (string) $context));
    }

    return $result;
}

function ultracache_safe_filesize($path, $context = '')
{
    $path = (string) $path;
    $filesystem = ultracache_get_wp_filesystem();
    if ('' === $path || !$filesystem || !$filesystem->exists($path) || !$filesystem->is_file($path)) {
        return false;
    }

    $result = $filesystem->size($path);
    if (false === $result) {
        ultracache_debug_log('filesize failed', array('path' => $path, 'context' => (string) $context));
    }

    return $result;
}

function ultracache_safe_tempnam($dir, $prefix = 'ultracache', $context = '')
{
    $dir = (string) $dir;
    $prefix = (string) $prefix;
    if ('' === $dir || !ultracache_is_allowed_writable_path($dir, $context) || !is_dir($dir) || !ultracache_path_is_writable($dir)) {
        ultracache_debug_log('tempnam directory unavailable or blocked', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    $sanitized_prefix = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix);
    if (!is_string($sanitized_prefix) || '' === $sanitized_prefix) {
        $sanitized_prefix = 'ultracache';
    }

    ultracache_get_wp_filesystem();
    if (!function_exists('wp_tempnam')) {
        ultracache_debug_log('wp_tempnam unavailable', array('dir' => $dir, 'prefix' => $sanitized_prefix, 'context' => (string) $context));
        return false;
    }

    $result = wp_tempnam(substr($sanitized_prefix, 0, 32), $dir);
    if (!is_string($result) || '' === $result || !ultracache_path_has_dir_prefix($result, $dir)) {
        ultracache_debug_log('wp_tempnam failed or returned path outside requested directory', array('dir' => $dir, 'prefix' => $sanitized_prefix, 'context' => (string) $context));
        return false;
    }

    return $result;
}

function ultracache_safe_fread($stream, $length, $context = '')
{
    $length = max(0, (int) $length);
    if ($length <= 0) {
        return '';
    }

    if (!is_resource($stream)) {
        ultracache_debug_log('fread failed: invalid stream', array('context' => (string) $context, 'length' => $length));
        return false;
    }

    $result = stream_get_contents($stream, $length);
    if (false === $result) {
        ultracache_debug_log('stream_get_contents failed', array('context' => (string) $context, 'length' => $length));
    }

    return $result;
}

/**
 * Read a bounded byte range from a path that passed UltraCache's readable-path guard.
 *
 * WP_Filesystem does not expose offset-based reads, so the guarded native stream is
 * intentionally confined to this helper.
 *
 * @param string $path    Source path.
 * @param int    $offset  Byte offset.
 * @param int    $length  Maximum bytes to read.
 * @param string $context Diagnostic context.
 * @return array{data:string,eof:bool}|false
 */
function ultracache_safe_stream_read_chunk($path, $offset, $length, $context = '')
{
    $path = wp_normalize_path((string) $path);
    $offset = max(0, (int) $offset);
    $length = max(1, (int) $length);
    if ('' === $path || !ultracache_is_allowed_readable_path($path, $context)) {
        ultracache_debug_log('stream read blocked', array('path' => $path, 'offset' => $offset, 'length' => $length, 'context' => (string) $context));
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Guarded offset-based reads are unavailable through WP_Filesystem.
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        ultracache_debug_log('stream read open failed', array('path' => $path, 'context' => (string) $context));
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Guarded offset-based reads are unavailable through WP_Filesystem.
    if ($offset > 0 && 0 !== @fseek($handle, $offset)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
        fclose($handle);
        return false;
    }
    $data = ultracache_safe_fread($handle, $length, $context);
    $eof = feof($handle);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
    fclose($handle);
    if (false === $data) {
        return false;
    }
    return array('data' => (string) $data, 'eof' => (bool) $eof || strlen((string) $data) < $length);
}

/**
 * Create or truncate a guarded writable stream file.
 *
 * @param string $path    Target path.
 * @param string $context Diagnostic context.
 * @return bool
 */
function ultracache_safe_stream_initialize_file($path, $context = '')
{
    $path = wp_normalize_path((string) $path);
    if ('' === $path || !ultracache_is_allowed_writable_path($path, $context)) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !ultracache_safe_mkdir($dir, 0755, true, $context)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Guarded offset-based writes are unavailable through WP_Filesystem.
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- Truncates only the guarded UltraCache temporary path.
    $result = ftruncate($handle, 0);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
    fclose($handle);
    return (bool) $result;
}

/**
 * Write a byte range to a path that passed UltraCache's writable-path guard.
 *
 * @param string $path    Target path.
 * @param int    $offset  Byte offset.
 * @param string $data    Bytes to write.
 * @param string $context Diagnostic context.
 * @return int|false
 */
function ultracache_safe_stream_write_chunk($path, $offset, $data, $context = '')
{
    $path = wp_normalize_path((string) $path);
    $offset = max(0, (int) $offset);
    $data = (string) $data;
    if ('' === $path || !ultracache_is_allowed_writable_path($path, $context)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Guarded offset-based writes are unavailable through WP_Filesystem.
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Guarded offset-based writes are unavailable through WP_Filesystem.
    if ($offset > 0 && 0 !== @fseek($handle, $offset)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
        fclose($handle);
        return false;
    }
    $written = 0;
    $length = strlen($data);
    while ($written < $length) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to the guarded UltraCache temporary path.
        $result = fwrite($handle, substr($data, $written));
        if (false === $result || 0 === $result) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
            fclose($handle);
            return false;
        }
        $written += $result;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes only the guarded UltraCache temporary stream.
    fflush($handle);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the guarded native stream opened above.
    fclose($handle);
    return $written;
}

function ultracache_safe_scandir($dir, $context = '')
{
    $dir = (string) $dir;
    $filesystem = ultracache_get_wp_filesystem();
    if ('' === $dir || !$filesystem || !$filesystem->exists($dir) || !$filesystem->is_dir($dir)) {
        return false;
    }

    $entries = $filesystem->dirlist($dir, true, false);
    if (!is_array($entries)) {
        ultracache_debug_log('scandir failed', array('dir' => $dir, 'context' => (string) $context));
        return false;
    }

    $items = array_keys($entries);
    sort($items, SORT_STRING);
    return $items;
}

