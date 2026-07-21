<?php
/**
 * UltraCache uninstall cleanup.
 *
 * Conservative policy: remove the UltraCache-managed wp-config.php block, plugin settings,
 * runtime/cache files, generated cache files, scheduled events, UltraCache-managed
 * drop-ins, and UltraCache queue tables.
 * Do not delete public optimized image derivatives from uploads automatically.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('ULTRACACHE_UNINSTALL_IN_PROGRESS')) {
    define('ULTRACACHE_UNINSTALL_IN_PROGRESS', true);
}

function ultracache_uninstall_normalize_path($path)
{
    $path = str_replace('\\', '/', (string) $path);
    $real = realpath($path);
    return false !== $real ? str_replace('\\', '/', $real) : $path;
}

function ultracache_uninstall_path_is_under($path, $root)
{
    $path = rtrim(ultracache_uninstall_normalize_path($path), '/');
    $root = rtrim(ultracache_uninstall_normalize_path($root), '/');
    return '' !== $path && '' !== $root && ($path === $root || 0 === strpos($path . '/', $root . '/'));
}




/**
 * Return an UltraCache directory under the WordPress uploads root during uninstall.
 *
 * @param string $relative Relative path below uploads.
 * @return string
 */
function ultracache_uninstall_uploads_storage_dir($relative)
{
    $uploads = wp_get_upload_dir();
    if (!is_array($uploads) || empty($uploads['basedir'])) {
        return '';
    }

    $relative = trim(str_replace('\\', '/', (string) $relative), '/');
    return trailingslashit(wp_normalize_path((string) $uploads['basedir'])) . $relative . '/';
}

/**
 * Return the WordPress core root for uninstall-only core include resolution.
 * WordPress exposes no public function for this root, so ABSPATH is isolated
 * in this one uninstall resolver.
 *
 * @return string
 */
function ultracache_uninstall_wordpress_core_root_dir()
{
    return defined('ABSPATH')
        ? trailingslashit(wp_normalize_path((string) ABSPATH))
        : '';
}

/**
 * Return an approved WordPress admin include path during uninstall.
 *
 * @param string $filename WordPress admin include filename.
 * @return string
 */
function ultracache_uninstall_wordpress_admin_include_path($filename)
{
    $filename = wp_basename((string) $filename);
    if ('file.php' !== $filename) {
        return '';
    }

    $core_root = ultracache_uninstall_wordpress_core_root_dir();

    return '' !== $core_root
        ? $core_root . 'wp-admin/includes/' . $filename
        : '';
}

/**
 * Resolve the active WordPress content directory through WP_Filesystem.
 *
 * @return string Normalized, trailing-slashed path or an empty string.
 */
function ultracache_uninstall_wordpress_content_dir()
{
    $filesystem = ultracache_uninstall_get_wp_filesystem();
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
 * Return the WordPress-required drop-in path during uninstall cleanup.
 *
 * advanced-cache.php and object-cache.php are intentionally located directly
 * under the active WordPress content directory because WordPress only loads
 * drop-ins from that required location. Uninstall cannot rely on the normal
 * plugin bootstrap helpers, so it keeps a small local equivalent.
 *
 * @param string $basename Drop-in basename.
 * @return string
 */
function ultracache_uninstall_dropin_path($basename)
{
    $basename = basename((string) $basename);
    if (!in_array($basename, array('advanced-cache.php', 'object-cache.php'), true)) {
        return '';
    }

    $content_dir = ultracache_uninstall_wordpress_content_dir();
    return '' !== $content_dir ? $content_dir . $basename : '';
}

function ultracache_uninstall_get_wp_filesystem()
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

    if (!function_exists('WP_Filesystem')) {
        $file_api = ultracache_uninstall_wordpress_admin_include_path('file.php');
        if ('' === $file_api) {
            return false;
        }
        require_once $file_api;
    }

    if (!function_exists('WP_Filesystem') || !WP_Filesystem() || !is_object($wp_filesystem)) {
        return false;
    }

    $initialized = true;
    return $wp_filesystem;
}

function ultracache_uninstall_get_contents($path)
{
    $path = (string) $path;
    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if ('' === $path || !$filesystem || !$filesystem->exists($path) || !$filesystem->is_file($path)) {
        return false;
    }

    return $filesystem->get_contents($path);
}

/**
 * Return the exact wp-config.php loaded by the current WordPress execution.
 *
 * Normal requests expose the configuration through PHP's included-files
 * list. WP-CLI evaluates wp-config.php, so CLI uninstall uses WP-CLI's own
 * authoritative locator. No WordPress-root or parent-directory guessing is
 * performed.
 *
 * @return string Normalized absolute path, or an empty string.
 */
function ultracache_uninstall_loaded_wp_config_path()
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
 * Remove only the uniquely delimited UltraCache constants block.
 *
 * Constants declared outside this block are intentionally untouched.
 *
 * @param string $contents Existing wp-config.php contents.
 * @return string|false Updated contents, or false for malformed markers.
 */
function ultracache_uninstall_strip_managed_constants_block($contents)
{
    $contents = (string) $contents;
    $start = '/* UltraCache managed constants start */';
    $end = '/* UltraCache managed constants end */';
    $start_count = substr_count($contents, $start);
    $end_count = substr_count($contents, $end);

    if (0 === $start_count && 0 === $end_count) {
        return $contents;
    }

    if (1 !== $start_count || 1 !== $end_count) {
        return false;
    }

    // Remove the managed block and adjacent blank lines. Keep a single newline
    // so surrounding PHP statements cannot be joined after uninstall cleanup.
    $pattern = '#(?:[ \t]*\R)*/\* UltraCache managed constants start \*/\R.*?/\* UltraCache managed constants end \*/(?:[ \t]*\R)*#s';
    $updated = preg_replace($pattern, "\n", $contents, 1, $replacements);

    return is_string($updated) && 1 === $replacements ? $updated : false;
}

/**
 * Validate complete PHP file contents before writing wp-config.php.
 *
 * @param string $contents Candidate PHP contents.
 * @return bool
 */
function ultracache_uninstall_validate_php_contents($contents)
{
    try {
        $tokens = token_get_all((string) $contents, TOKEN_PARSE);
    } catch (ParseError $error) {
        return false;
    }

    foreach ($tokens as $token) {
        if (!is_array($token) || T_OPEN_TAG !== $token[0]) {
            continue;
        }

        return 1 === preg_match('/^<\?php(?:\s|$)/i', (string) $token[1]);
    }

    return false;
}

/**
 * Write wp-config.php through WP_Filesystem with read-back verification.
 *
 * The original contents are retained only in memory and are restored if
 * the updated file cannot be verified.
 *
 * @param string $config_path      Exact loaded wp-config.php path.
 * @param string $updated_contents Updated contents.
 * @param string $original_contents Original contents.
 * @return bool
 */
function ultracache_uninstall_write_wp_config($config_path, $updated_contents, $original_contents)
{
    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if (
        '' === (string) $config_path
        || !$filesystem
        || !method_exists($filesystem, 'put_contents')
        || !method_exists($filesystem, 'get_contents')
    ) {
        return false;
    }

    if (method_exists($filesystem, 'is_writable') && !$filesystem->is_writable($config_path)) {
        return false;
    }

    $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
    $written = $filesystem->put_contents($config_path, (string) $updated_contents, $mode);
    $read_back = false !== $written ? $filesystem->get_contents($config_path) : false;
    $verified = is_string($read_back)
        && hash_equals(hash('sha256', (string) $updated_contents), hash('sha256', $read_back));

    if ($verified) {
        return true;
    }

    $restored = $filesystem->put_contents($config_path, (string) $original_contents, $mode);
    $restored_contents = false !== $restored ? $filesystem->get_contents($config_path) : false;

    $rollback_verified = is_string($restored_contents)
        && hash_equals(hash('sha256', (string) $original_contents), hash('sha256', $restored_contents));

    if (!$rollback_verified) {
        return false;
    }

    return false;
}

/**
 * Remove UltraCache's managed constants block from the loaded wp-config.php.
 *
 * @return bool True when absent or removed and verified; false on failure.
 */
function ultracache_uninstall_remove_managed_constants_block()
{
    $config_path = ultracache_uninstall_loaded_wp_config_path();
    if ('' === $config_path) {
        return false;
    }

    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if (
        !$filesystem
        || !$filesystem->exists($config_path)
        || !$filesystem->is_file($config_path)
    ) {
        return false;
    }

    $original_contents = $filesystem->get_contents($config_path);
    if (!is_string($original_contents)) {
        return false;
    }

    $updated_contents = ultracache_uninstall_strip_managed_constants_block($original_contents);
    if (false === $updated_contents) {
        return false;
    }

    if ($updated_contents === $original_contents) {
        return true;
    }

    if (!ultracache_uninstall_validate_php_contents($updated_contents)) {
        return false;
    }

    return ultracache_uninstall_write_wp_config(
        $config_path,
        $updated_contents,
        $original_contents
    );
}

function ultracache_uninstall_wordpress_home_path()
{
    if (!function_exists('get_home_path')) {
        $file_api = ultracache_uninstall_wordpress_admin_include_path('file.php');
        if ('' !== $file_api) {
            require_once $file_api;
        }
    }

    if (function_exists('get_home_path')) {
        $home_path = get_home_path();
        if (is_string($home_path) && '' !== trim($home_path)) {
            return trailingslashit(wp_normalize_path($home_path));
        }
    }

    return defined('ABSPATH') ? trailingslashit(wp_normalize_path((string) ABSPATH)) : '';
}

function ultracache_uninstall_strip_htaccess_managed_blocks($contents)
{
    $contents = (string) $contents;
    foreach (array(
        array('# BEGIN UltraCache Browser Cache', '# END UltraCache Browser Cache'),
        array('# BEGIN UltraCache Apache Static HTML', '# END UltraCache Apache Static HTML'),
    ) as $markers) {
        $begin = $markers[0];
        $end = $markers[1];
        $pattern = '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
        $contents = (string) preg_replace($pattern, '', $contents);
    }

    return '' === trim($contents) ? '' : (rtrim($contents) . "\n");
}

function ultracache_uninstall_write_text_file($path, $updated_contents, $original_contents)
{
    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if (
        '' === (string) $path
        || !$filesystem
        || !method_exists($filesystem, 'put_contents')
        || !method_exists($filesystem, 'get_contents')
    ) {
        return false;
    }

    if (method_exists($filesystem, 'is_writable') && !$filesystem->is_writable($path)) {
        return false;
    }

    $mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
    $written = $filesystem->put_contents($path, (string) $updated_contents, $mode);
    $read_back = false !== $written ? $filesystem->get_contents($path) : false;
    if (is_string($read_back) && hash_equals(hash('sha256', (string) $updated_contents), hash('sha256', $read_back))) {
        return true;
    }

    $filesystem->put_contents($path, (string) $original_contents, $mode);
    return false;
}

function ultracache_uninstall_remove_htaccess_managed_blocks()
{
    $home_path = ultracache_uninstall_wordpress_home_path();
    if ('' === $home_path) {
        return false;
    }

    $path = trailingslashit($home_path) . '.htaccess';
    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if (!$filesystem || !$filesystem->exists($path)) {
        return true;
    }

    if (!$filesystem->is_file($path)) {
        return false;
    }

    $original_contents = $filesystem->get_contents($path);
    if (!is_string($original_contents)) {
        return false;
    }

    $updated_contents = ultracache_uninstall_strip_htaccess_managed_blocks($original_contents);
    if ($updated_contents === $original_contents) {
        return true;
    }

    return ultracache_uninstall_write_text_file($path, $updated_contents, $original_contents);
}

function ultracache_uninstall_delete_path($path, array $allowed_roots)
{
    $path = (string) $path;
    if ('' === $path) {
        return true;
    }

    $filesystem = ultracache_uninstall_get_wp_filesystem();
    if (!$filesystem) {
        return false;
    }

    if (!$filesystem->exists($path)) {
        return true;
    }

    if (is_link($path)) {
        return true;
    }

    $allowed = false;
    foreach ($allowed_roots as $root) {
        if (ultracache_uninstall_path_is_under($path, $root)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        return false;
    }

    $type = $filesystem->is_dir($path) ? 'd' : 'f';
    $recursive = 'd' === $type;
    $result = $filesystem->delete($path, $recursive, $type);

    return $result || !$filesystem->exists($path);
}

/**
 * Delete UltraCache-owned option/transient rows by targeted option_name prefixes.
 *
 * @param array $patterns SQL LIKE patterns such as "_transient_ultracache_%".
 * @return void
 */
function ultracache_uninstall_delete_options_by_like_patterns(array $patterns)
{
    global $wpdb;

    if (!($wpdb instanceof wpdb)) {
        return;
    }

    foreach ($patterns as $pattern) {
        $pattern = (string) $pattern;
        if ('' === $pattern) {
            continue;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned option/transient prefixes.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, $pattern));
    }

    if (is_multisite()) {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ('' === $pattern) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes only UltraCache-owned site option/transient prefixes.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE meta_key LIKE %s", $wpdb->sitemeta, $pattern));
        }
    }
}

/**
 * Return public generated runtime asset directories that are safe to remove.
 *
 * Persistent optimized media under uploads/ultracache/images is intentionally excluded.
 *
 * @return array<int, string>
 */
function ultracache_uninstall_generated_runtime_asset_dirs()
{
    $base = ultracache_uninstall_uploads_storage_dir('ultracache');
    if ('' === $base) {
        return array();
    }

    return array(
        $base . 'css-bundles/',
        $base . 'font-css/',
        $base . 'google-fonts/',
        $base . 'optimized-css/',
        $base . 'deferred-inline-js/',
        $base . 'theme-css-temp/',
        $base . 'theme-css-backups/',
    );
}

/**
 * Drop UltraCache-owned custom tables and verify the requested tables are gone.
 *
 * @param array $table_basenames Table basenames without the WordPress prefix.
 * @return bool
 */
function ultracache_uninstall_drop_custom_tables(array $table_basenames)
{
    if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof wpdb)) {
        return false;
    }

    global $wpdb;
    $success = true;

    foreach ($table_basenames as $table_basename) {
        $table_basename = (string) $table_basename;
        if (!preg_match('/^ultracache_[A-Za-z0-9_]+$/', $table_basename)) {
            $success = false;
            continue;
        }

        $table = (string) $wpdb->prefix . $table_basename;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $success = false;
            continue;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup drops only validated UltraCache-owned custom tables.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final uninstall verification checks only a validated UltraCache-owned custom table name.
        $remaining = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ((string) $remaining === $table) {
            $success = false;
        }
    }

    return $success;
}

function ultracache_uninstall_sanitize_cleanup_policy($policy)
{
    $policy = strtolower(trim((string) $policy));
    $allowed = array('plugin_only', 'keep_settings', 'keep_settings_tables', 'delete_everything');
    return in_array($policy, $allowed, true) ? $policy : 'plugin_only';
}

function ultracache_uninstall_get_cleanup_policy()
{
    $settings = get_option('ultracache_settings', array());
    if (is_array($settings) && isset($settings['uninstallCleanupPolicy'])) {
        return ultracache_uninstall_sanitize_cleanup_policy($settings['uninstallCleanupPolicy']);
    }

    return 'delete_everything';
}

function ultracache_run_uninstall_cleanup()
{
    ultracache_uninstall_remove_managed_constants_block();
    ultracache_uninstall_remove_htaccess_managed_blocks();

    $ultracache_policy = ultracache_uninstall_get_cleanup_policy();
    $ultracache_keep_settings = in_array($ultracache_policy, array('plugin_only', 'keep_settings', 'keep_settings_tables'), true);
    $ultracache_keep_tables = in_array($ultracache_policy, array('plugin_only', 'keep_settings_tables'), true);
    $ultracache_delete_cache_files = ('plugin_only' !== $ultracache_policy);
    $ultracache_delete_runtime_options = ('plugin_only' !== $ultracache_policy);

    $ultracache_scheduled_hooks = array(
        'ultracache_scheduled_cache_cleanup',
        'ultracache_cron_warm_tick',
        'ultracache_cron_warm_tick_kickoff',
        'ultracache_process_media_conversion_queue',
    );

    foreach ($ultracache_scheduled_hooks as $ultracache_hook) {
        wp_clear_scheduled_hook($ultracache_hook);
    }

    delete_option('ultracache_manual_warm_state');
    delete_site_option('ultracache_manual_warm_state');

    if ($ultracache_delete_runtime_options) {
        $ultracache_options = array(
            'ultracache_cron_warm_state',
            'ultracache_crawl_scope_summary',
            'ultracache_cron_warm_lock_atomic',
            'ultracache_wp_cache_managed',
            'ultracache_media_diagnostics_v1',
            'ultracache_media_library_conversion_test_v1',
            'ultracache_media_library_conversion_test_sample_v1',
            'ultracache_media_file_counts',
            'ultracache_media_storage_stats_v2',
            'ultracache_avif_encoder_self_test_v1',
            'ultracache_media_queue_build_state_v1',
            'ultracache_media_background_paused_v1',
            'ultracache_media_stale_worker_state_v1',
            'ultracache_media_queue_rebuild_generation_v1',
            'ultracache_media_replacement_active_job_v1',
            'ultracache_media_replacement_active_job_v2',
            'ultracache_media_replacement_ref_index_scan_v1',
            'ultracache_media_replacement_ref_index_specs_v1',
            'ultracache_media_replacement_intermediate_expand_v1',
            'ultracache_media_replacement_theme_css_scan_state',
            'ultracache_media_replacement_theme_css_stream_state_v1',
            'ultracache_media_replacement_theme_css_scan_manifest_v1',
            'ultracache_media_replacement_readiness_v1',
            'ultracache_media_replacement_cli_pause_request_v1',
            'ultracache_object_cache_last_flush_report',
            'ultracache_last_css_bundle_summary',
            'ultracache_settings_google_fonts_last_scan',
            'ultracache_opcache_last_flush_at',
            'ultracache_external_cache_detection',
            'ultracache_action_queue_heavy_lock_v1',
            'ultracache_warmup_generation',
            'ultracache_varnish_refresh_ahead_state_v1',
            'ultracache_varnish_refresh_candidates_v1',
            'ultracache_varnish_metrics_v1',
            'ultracache_varnish_diagnostic_basic_v1',
            'ultracache_varnish_diagnostic_flush_scope_v1',
            'ultracache_varnish_diagnostic_validators_v1',
            'ultracache_varnish_diagnostic_accept_vcl_v1',
            'ultracache_varnish_diagnostic_soft_purge_v1',
            'ultracache_varnish_diagnostic_multi_endpoint_v1',
            'ultracache_varnish_html_ttl_default_migration_v1',
            'ultracache_lcp_last_refresh',
            'ultracache_manual_warm_state',
        );

        if (!$ultracache_keep_settings) {
            array_unshift($ultracache_options, 'ultracache_settings');
        }

        if (!$ultracache_keep_tables) {
            $ultracache_options[] = 'ultracache_media_queue_db_version';
            $ultracache_options[] = 'ultracache_media_page_refs_db_version';
            $ultracache_options[] = 'ultracache_action_jobs_db_version';
            $ultracache_options[] = 'ultracache_js_diagnostic_queue_db_version';
            $ultracache_options[] = 'ultracache_cron_warm_queue_db_version';
            $ultracache_options[] = 'ultracache_analytics_db_version';
            $ultracache_options[] = 'ultracache_lcp_observations_db_version';
            $ultracache_options[] = 'ultracache_cache_asset_refs_db_version';
            $ultracache_options[] = 'ultracache_css_rewrite_map_db_version';
            $ultracache_options[] = 'ultracache_locks_db_version';
        }

        foreach ($ultracache_options as $ultracache_option) {
            delete_option($ultracache_option);
            delete_site_option($ultracache_option);
        }

        if (!$ultracache_keep_settings && function_exists('delete_metadata')) {
            delete_metadata('user', 0, 'ultracache_admin_theme', '', true);
        }

        $ultracache_transients = array(
            'ultracache_admin_notice',
            'ultracache_cron_warm_lock',
            'ultracache_dashboard_cache_activity_v1',
            'ultracache_frontend_compression_probe_v1',
            'ultracache_last_cache_event',
            'ultracache_loopback_ssl_status_v1',
            'ultracache_media_conversion_queue_lock',
            'ultracache_media_queue_process_lock_v1',
            'ultracache_media_page_refs_cleanup_lock',
            'ultracache_media_work_summary_v1',
            'ultracache_object_cache_support_status_v1',
            'ultracache_reverse_proxy_status_v2',
            'ultracache_varnish_last_result',
            'ultracache_varnish_html_flush_capability_v1',
            'ultracache_varnish_two_stage_refill_v1',
            'ultracache_varnish_soft_purge_capability_v1',
            'ultracache_varnish_refresh_ahead_capability_v1',
            'ultracache_runtime_font_css_url_map_v3',
        );

        foreach ($ultracache_transients as $ultracache_transient) {
            delete_transient($ultracache_transient);
            delete_site_transient($ultracache_transient);
        }

        ultracache_uninstall_delete_options_by_like_patterns(array(
            '_transient_ultracache_%',
            '_transient_timeout_ultracache_%',
            '_site_transient_ultracache_%',
            '_site_transient_timeout_ultracache_%',
            'ultracache_google_fonts_lock_%',
        ));
    }

    $ultracache_custom_table_basenames = array(
        'ultracache_media_queue',
        'ultracache_media_page_refs',
        'ultracache_media_replacement_items',
        'ultracache_media_replacement_refs',
        'ultracache_media_replacement_ref_index',
        'ultracache_media_replacement_file_refs',
        'ultracache_media_replacement_theme_css_files',
        'ultracache_action_jobs',
        'ultracache_js_diagnostic_jobs',
        'ultracache_cron_warm_queue',
        'ultracache_analytics',
        'ultracache_lcp_observations',
        'ultracache_cache_asset_refs',
        'ultracache_css_rewrite_map',
        'ultracache_locks',
    );

    if (!$ultracache_keep_tables) {
        ultracache_uninstall_drop_custom_tables($ultracache_custom_table_basenames);
    }


    $ultracache_cache_root = ultracache_uninstall_uploads_storage_dir('ultracache/cache');
    $ultracache_object_root = ultracache_uninstall_uploads_storage_dir('ultracache/object-cache');
    $ultracache_allowed_roots = array_filter(array($ultracache_cache_root, $ultracache_object_root));

    if ($ultracache_delete_cache_files) {
        foreach ($ultracache_allowed_roots as $ultracache_root) {
            ultracache_uninstall_delete_path($ultracache_root, $ultracache_allowed_roots);
        }

        $ultracache_generated_dirs = ultracache_uninstall_generated_runtime_asset_dirs();
        $ultracache_generated_roots = array_filter(array_map('dirname', $ultracache_generated_dirs));
        foreach ($ultracache_generated_dirs as $ultracache_generated_dir) {
            ultracache_uninstall_delete_path($ultracache_generated_dir, $ultracache_generated_roots);
        }
    }

    foreach (array('advanced-cache.php', 'object-cache.php') as $ultracache_dropin_name) {
        $ultracache_dropin = ultracache_uninstall_dropin_path($ultracache_dropin_name);
        if ('' === $ultracache_dropin || !is_readable($ultracache_dropin)) {
            continue;
        }

        $ultracache_contents = (string) ultracache_uninstall_get_contents($ultracache_dropin);
        if (false !== strpos($ultracache_contents, 'UltraCache')) {
            ultracache_uninstall_delete_path($ultracache_dropin, array(dirname($ultracache_dropin)));
        }
    }

    // Run the critical full-cleanup targets last. Loaded shutdown callbacks from the
    // current request must not be able to leave these tables/options behind.
    if ($ultracache_delete_runtime_options && !$ultracache_keep_tables) {
        foreach (array(
            'ultracache_object_cache_last_flush_report',
            'ultracache_crawl_scope_summary',
            'ultracache_cache_asset_refs_db_version',
            'ultracache_css_rewrite_map_db_version',
            'ultracache_locks_db_version',
            'ultracache_media_replacement_db_version',
            'ultracache_media_replacement_schema_lock_v1',
        ) as $ultracache_final_option) {
            delete_option($ultracache_final_option);
            delete_site_option($ultracache_final_option);

            $ultracache_missing_option = new stdClass();
            if ($ultracache_missing_option !== get_option($ultracache_final_option, $ultracache_missing_option)) {
                delete_option($ultracache_final_option);
            }
            if ($ultracache_missing_option !== get_site_option($ultracache_final_option, $ultracache_missing_option)) {
                delete_site_option($ultracache_final_option);
            }
        }

        ultracache_uninstall_drop_custom_tables($ultracache_custom_table_basenames);
    }

}

ultracache_run_uninstall_cleanup();
