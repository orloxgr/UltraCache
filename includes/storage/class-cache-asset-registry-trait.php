<?php
/**
 * Cache asset reference and CSS rewrite-map registries.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Cache_Asset_Registry_Trait
{
    /** Per-request memo for UltraCache custom-table existence/schema readiness. */
    private static $custom_table_schema_request_memo = array();

    private static function reset_custom_table_schema_request_memo($table = '')
    {
        $table = (string) $table;
        if ('' === $table) {
            self::$custom_table_schema_request_memo = array();
            return;
        }
        unset(self::$custom_table_schema_request_memo['exists:' . $table]);
    }

    private static function plugin_custom_table_exists($table)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !is_string($table) || '' === $table) {
            return false;
        }

        if (function_exists('ultracache_validate_custom_table_name')) {
            $table = ultracache_validate_custom_table_name($table, 'custom_table_exists');
            if ('' === $table) {
                return false;
            }
        }

        $memo_key = 'exists:' . $table;
        if (array_key_exists($memo_key, self::$custom_table_schema_request_memo)) {
            return (bool) self::$custom_table_schema_request_memo[$memo_key];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check for a validated UltraCache-owned custom table.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        self::$custom_table_schema_request_memo[$memo_key] = ((string) $found === (string) $table);
        return (bool) self::$custom_table_schema_request_memo[$memo_key];
    }

    public static function get_cache_asset_refs_table_name()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ultracache_cache_asset_refs';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'cache_asset_refs') : $table;
    }

    private static function get_cache_asset_refs_db_version()
    {
        return '1.0.0';
    }

    private static function get_cache_asset_refs_db_version_option_key()
    {
        return 'ultracache_cache_asset_refs_db_version';
    }

    public static function ensure_cache_asset_refs_table($force_schema_verify = false)
    {
        global $wpdb;

        if ((defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_cache_asset_refs_table_name();
        $version = (string) get_option(self::get_cache_asset_refs_db_version_option_key(), '');
        $force_schema_verify = (bool) $force_schema_verify;
        if (!$force_schema_verify && self::get_cache_asset_refs_db_version() === $version) {
            return true;
        }
        if ($force_schema_verify && self::get_cache_asset_refs_db_version() === $version && self::plugin_custom_table_exists($table)) {
            return true;
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }
        self::reset_custom_table_schema_request_memo($table);
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_hash char(40) NOT NULL DEFAULT '',
            cache_rel_path varchar(512) NOT NULL DEFAULT '',
            asset_bucket varchar(32) NOT NULL DEFAULT '',
            asset_basename varchar(191) NOT NULL DEFAULT '',
            asset_hash char(40) NOT NULL DEFAULT '',
            active tinyint(1) NOT NULL DEFAULT 1,
            first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            protect_until datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY cache_asset (cache_hash, asset_hash),
            KEY asset_protection (asset_bucket, active, protect_until),
            KEY asset_hash (asset_hash),
            KEY cache_hash (cache_hash),
            KEY protect_until (protect_until)
        ) {$charset_collate};";

        dbDelta($sql);
        self::reset_custom_table_schema_request_memo($table);
        if (self::plugin_custom_table_exists($table)) {
            update_option(self::get_cache_asset_refs_db_version_option_key(), self::get_cache_asset_refs_db_version(), false);
            return true;
        }

        return false;
    }

    public static function get_css_rewrite_map_table_name()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ultracache_css_rewrite_map';
        return function_exists('ultracache_validate_custom_table_name') ? ultracache_validate_custom_table_name($table, 'css_rewrite_map') : $table;
    }

    private static function get_css_rewrite_map_db_version()
    {
        return '1.0.0';
    }

    private static function get_css_rewrite_map_db_version_option_key()
    {
        return 'ultracache_css_rewrite_map_db_version';
    }

    public static function ensure_css_rewrite_map_table($force_schema_verify = false)
    {
        global $wpdb;

        if ((defined('ULTRACACHE_UNINSTALL_IN_PROGRESS') && ULTRACACHE_UNINSTALL_IN_PROGRESS) || !($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return false;
        }

        $version = (string) get_option(self::get_css_rewrite_map_db_version_option_key(), '');
        $force_schema_verify = (bool) $force_schema_verify;
        if (!$force_schema_verify && self::get_css_rewrite_map_db_version() === $version) {
            return true;
        }
        if ($force_schema_verify && self::get_css_rewrite_map_db_version() === $version && self::plugin_custom_table_exists($table)) {
            return true;
        }

        if (!ultracache_require_wordpress_admin_include('upgrade.php', 'dbDelta')) {
            return false;
        }
        self::reset_custom_table_schema_request_memo($table);
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_url_hash char(40) NOT NULL DEFAULT '',
            generated_url_hash char(40) NOT NULL DEFAULT '',
            source_url text NOT NULL,
            source_path varchar(512) NOT NULL DEFAULT '',
            source_handle varchar(191) NOT NULL DEFAULT '',
            generated_url text NOT NULL,
            generated_path varchar(512) NOT NULL DEFAULT '',
            generated_basename varchar(191) NOT NULL DEFAULT '',
            optimization_type varchar(32) NOT NULL DEFAULT '',
            content_hash char(32) NOT NULL DEFAULT '',
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY source_type (source_url_hash, optimization_type),
            KEY generated_url_hash (generated_url_hash),
            KEY generated_basename (generated_basename),
            KEY active_last_seen (active, last_seen),
            KEY optimization_type (optimization_type)
        ) {$charset_collate};";

        dbDelta($sql);
        self::reset_custom_table_schema_request_memo($table);
        if (self::plugin_custom_table_exists($table)) {
            update_option(self::get_css_rewrite_map_db_version_option_key(), self::get_css_rewrite_map_db_version(), false);
            return true;
        }

        return false;
    }

    private static function normalize_css_rewrite_map_url($url)
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }

        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        } elseif (0 === strpos($url, '/')) {
            $url = home_url($url);
        }

        return esc_url_raw($url);
    }

    private static function get_css_rewrite_map_cache_group()
    {
        return 'ultracache_css_rewrite_map';
    }

    private static function get_css_rewrite_map_cache_key($kind, $url)
    {
        return 'ultracache_css_rewrite_map_' . sanitize_key((string) $kind) . '_' . sha1(self::normalize_css_rewrite_map_url($url));
    }

    private static function clear_css_rewrite_map_cache_for_urls($source_url, $generated_url, $optimization_type = '')
    {
        wp_cache_delete(self::get_css_rewrite_map_cache_key('source', $source_url), self::get_css_rewrite_map_cache_group());
        $optimization_type = sanitize_key((string) $optimization_type);
        if ('' !== $optimization_type) {
            wp_cache_delete(self::get_css_rewrite_map_cache_key('source_' . $optimization_type, $source_url), self::get_css_rewrite_map_cache_group());
        }
        wp_cache_delete(self::get_css_rewrite_map_cache_key('generated', $generated_url), self::get_css_rewrite_map_cache_group());
    }

    public static function record_css_rewrite_map($source_url, $generated_url, array $args = array())
    {
        global $wpdb;

        $source_url = self::normalize_css_rewrite_map_url($source_url);
        $generated_url = self::normalize_css_rewrite_map_url($generated_url);
        if ('' === $source_url || '' === $generated_url || $source_url === $generated_url || !($wpdb instanceof wpdb) || !self::ensure_css_rewrite_map_table()) {
            return false;
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return false;
        }

        $generated_path = isset($args['generated_path']) ? wp_normalize_path((string) $args['generated_path']) : '';
        $source_path = isset($args['source_path']) ? wp_normalize_path((string) $args['source_path']) : '';
        $generated_url_path = (string) wp_parse_url($generated_url, PHP_URL_PATH);
        $generated_basename = sanitize_file_name((string) wp_basename(rawurldecode($generated_url_path)));
        $optimization_type = sanitize_key((string) ($args['optimization_type'] ?? 'css-font-mix'));
        if ('' === $optimization_type) {
            $optimization_type = 'css-font-mix';
        }
        $content_hash = preg_replace('/[^a-f0-9]/i', '', (string) ($args['content_hash'] ?? ''));
        $content_hash = substr(strtolower((string) $content_hash), 0, 32);
        $source_handle = sanitize_key((string) ($args['source_handle'] ?? ''));
        $now = current_time('mysql', true);

        $row = array(
            'source_url_hash'    => sha1($source_url),
            'generated_url_hash' => sha1($generated_url),
            'source_url'         => $source_url,
            'source_path'        => substr($source_path, 0, 512),
            'source_handle'      => substr($source_handle, 0, 191),
            'generated_url'      => $generated_url,
            'generated_path'     => substr($generated_path, 0, 512),
            'generated_basename' => substr($generated_basename, 0, 191),
            'optimization_type'  => substr($optimization_type, 0, 32),
            'content_hash'       => $content_hash,
            'active'             => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
            'last_seen'          => $now,
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UltraCache-owned CSS rewrite-map upsert. The table has a plugin-owned unique key on source_url_hash + optimization_type; cache keys are cleared immediately after the atomic write.
        $result = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i
                    (source_url_hash, generated_url_hash, source_url, source_path, source_handle, generated_url, generated_path, generated_basename, optimization_type, content_hash, active, created_at, updated_at, last_seen)
                 VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    generated_url_hash = VALUES(generated_url_hash),
                    source_url = VALUES(source_url),
                    source_path = VALUES(source_path),
                    source_handle = VALUES(source_handle),
                    generated_url = VALUES(generated_url),
                    generated_path = VALUES(generated_path),
                    generated_basename = VALUES(generated_basename),
                    content_hash = VALUES(content_hash),
                    active = VALUES(active),
                    updated_at = VALUES(updated_at),
                    last_seen = VALUES(last_seen)',
                $table,
                $row['source_url_hash'],
                $row['generated_url_hash'],
                $row['source_url'],
                $row['source_path'],
                $row['source_handle'],
                $row['generated_url'],
                $row['generated_path'],
                $row['generated_basename'],
                $row['optimization_type'],
                $row['content_hash'],
                $row['active'],
                $row['created_at'],
                $row['updated_at'],
                $row['last_seen']
            )
        );
        self::clear_css_rewrite_map_cache_for_urls($source_url, $generated_url, $row['optimization_type']);
        return false !== $result;
    }

    /**
     * Return active CSS rewrite-map rows for one optimization type.
     *
     * The UltraCache-owned table is authoritative; callers may keep a
     * request-local memoized copy but must not mirror the map into transients.
     *
     * @param string $optimization_type Optimization type.
     * @param int    $limit             Maximum rows.
     * @return array
     */
    public static function get_css_rewrite_maps_by_optimization_type($optimization_type, $limit = 5000)
    {
        global $wpdb;

        $optimization_type = sanitize_key((string) $optimization_type);
        $limit = max(1, min(5000, absint($limit)));
        if ('' === $optimization_type || !($wpdb instanceof wpdb) || !self::ensure_css_rewrite_map_table()) {
            return array();
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded authoritative read from the UltraCache-owned CSS rewrite-map table; the engine memoizes the normalized result only for the current request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_url, source_path, generated_url, generated_path, optimization_type, content_hash, updated_at, last_seen FROM %i WHERE optimization_type = %s AND active = 1 ORDER BY last_seen DESC, id DESC LIMIT %d',
                $table,
                $optimization_type,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Mark one CSS rewrite-map entry inactive.
     *
     * @param string $source_url        Original stylesheet URL.
     * @param string $optimization_type Optimization type.
     * @return bool
     */
    public static function deactivate_css_rewrite_map($source_url, $optimization_type)
    {
        global $wpdb;

        $source_url = self::normalize_css_rewrite_map_url($source_url);
        $optimization_type = sanitize_key((string) $optimization_type);
        if ('' === $source_url || '' === $optimization_type || !($wpdb instanceof wpdb) || !self::ensure_css_rewrite_map_table()) {
            return false;
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the generated URL required to invalidate the precise object-cache key before mutating the authoritative row.
        $generated_url = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT generated_url FROM %i WHERE source_url_hash = %s AND optimization_type = %s LIMIT 1',
                $table,
                sha1($source_url),
                $optimization_type
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative mutation of one UltraCache-owned rewrite-map row; related object-cache keys are invalidated immediately below.
        $updated = $wpdb->update(
            $table,
            array('active' => 0, 'updated_at' => current_time('mysql', true)),
            array('source_url_hash' => sha1($source_url), 'optimization_type' => $optimization_type),
            array('%d', '%s'),
            array('%s', '%s')
        );
        self::clear_css_rewrite_map_cache_for_urls($source_url, $generated_url, $optimization_type);
        return false !== $updated;
    }

    /**
     * Mark every active CSS rewrite-map entry for one type inactive.
     *
     * @param string $optimization_type Optimization type.
     * @return int
     */
    public static function deactivate_css_rewrite_maps_by_optimization_type($optimization_type)
    {
        global $wpdb;

        $optimization_type = sanitize_key((string) $optimization_type);
        if ('' === $optimization_type || !($wpdb instanceof wpdb) || !self::ensure_css_rewrite_map_table()) {
            return 0;
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cache-key inventory for authoritative rows that will be deactivated below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_url, generated_url FROM %i WHERE optimization_type = %s AND active = 1 LIMIT 5000',
                $table,
                $optimization_type
            ),
            ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authoritative cleanup in the UltraCache-owned rewrite-map table.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET active = 0, updated_at = %s WHERE optimization_type = %s AND active = 1',
                $table,
                current_time('mysql', true),
                $optimization_type
            )
        );
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::get_css_rewrite_map_cache_group());
        } else {
            foreach ((array) $rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                self::clear_css_rewrite_map_cache_for_urls(
                    (string) ($row['source_url'] ?? ''),
                    (string) ($row['generated_url'] ?? ''),
                    $optimization_type
                );
            }
        }
        return is_numeric($updated) ? max(0, (int) $updated) : 0;
    }

    public static function get_css_rewrite_map_by_generated_url($generated_url)
    {
        global $wpdb;

        $generated_url = self::normalize_css_rewrite_map_url($generated_url);
        if ('' === $generated_url || !($wpdb instanceof wpdb)) {
            return array();
        }

        // Runtime hot path: consult the object/request cache before touching schema readiness.
        // Negative lookups are cached as an empty array, so a cache hit can return with zero SQL.
        $cache_key = self::get_css_rewrite_map_cache_key('generated', $generated_url);
        $cache_found = false;
        $cached = wp_cache_get($cache_key, self::get_css_rewrite_map_cache_group(), false, $cache_found);
        if ($cache_found) {
            return is_array($cached) ? $cached : array();
        }

        if (!self::ensure_css_rewrite_map_table()) {
            return array();
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- UltraCache-owned CSS rewrite map lookup with wp_cache result cache; table name is passed through the WP 6.2+ identifier placeholder.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE generated_url_hash = %s AND active = 1 ORDER BY last_seen DESC LIMIT 1', $table, sha1($generated_url)), ARRAY_A);
        $row = is_array($row) ? $row : array();
        wp_cache_set($cache_key, $row, self::get_css_rewrite_map_cache_group(), HOUR_IN_SECONDS);
        return $row;
    }

    public static function get_css_rewrite_map_by_source_url($source_url, $optimization_type = '')
    {
        global $wpdb;

        $source_url = self::normalize_css_rewrite_map_url($source_url);
        if ('' === $source_url || !($wpdb instanceof wpdb)) {
            return array();
        }

        $optimization_type = sanitize_key((string) $optimization_type);
        $cache_key = self::get_css_rewrite_map_cache_key('source' . ('' !== $optimization_type ? '_' . $optimization_type : ''), $source_url);
        $cache_found = false;
        $cached = wp_cache_get($cache_key, self::get_css_rewrite_map_cache_group(), false, $cache_found);
        if ($cache_found) {
            return is_array($cached) ? $cached : array();
        }

        // Only a real cache miss reaches schema readiness and the indexed authoritative SELECT.
        if (!self::ensure_css_rewrite_map_table()) {
            return array();
        }

        $table = self::get_css_rewrite_map_table_name();
        if ('' === $table) {
            return array();
        }

        if ('' !== $optimization_type) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- UltraCache-owned CSS rewrite map lookup with wp_cache result cache; table name is passed through the WP 6.2+ identifier placeholder.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE source_url_hash = %s AND optimization_type = %s AND active = 1 ORDER BY last_seen DESC LIMIT 1', $table, sha1($source_url), $optimization_type), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- UltraCache-owned CSS rewrite map lookup with wp_cache result cache; table name is passed through the WP 6.2+ identifier placeholder.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE source_url_hash = %s AND active = 1 ORDER BY last_seen DESC LIMIT 1', $table, sha1($source_url)), ARRAY_A);
        }

        $row = is_array($row) ? $row : array();
        wp_cache_set($cache_key, $row, self::get_css_rewrite_map_cache_group(), HOUR_IN_SECONDS);
        return $row;
    }

    private static function get_cache_asset_refs_protection_seconds()
    {
        $settings = self::get_settings();
        $css_grace_hours = isset($settings['css_bundle_cleanup_grace_hours']) ? (int) $settings['css_bundle_cleanup_grace_hours'] : 48;
        $stale_minutes = isset($settings['cache_max_stale_minutes']) ? (int) $settings['cache_max_stale_minutes'] : 2880;
        $default = max(48 * HOUR_IN_SECONDS, $css_grace_hours * HOUR_IN_SECONDS, $stale_minutes * MINUTE_IN_SECONDS);
        $seconds = (int) apply_filters('ultracache_cache_asset_ref_protection_seconds', $default);
        return max(HOUR_IN_SECONDS, min(30 * DAY_IN_SECONDS, $seconds));
    }

    private static function get_cache_asset_refs_per_cache_file_cap()
    {
        $cap = (int) apply_filters('ultracache_cache_asset_refs_per_cache_file_cap', 64);
        return max(8, min(256, $cap));
    }

    private static function normalize_cache_asset_cache_rel_path($cache_file)
    {
        $cache_file = wp_normalize_path((string) $cache_file);
        $root = defined('ULTRACACHE_CACHE_DIR') ? wp_normalize_path(trailingslashit(ULTRACACHE_CACHE_DIR)) : '';
        if ('' === $cache_file || '' === $root || 0 !== strpos($cache_file, $root)) {
            return '';
        }

        $relative = ltrim(substr($cache_file, strlen($root)), '/');
        $relative = preg_replace('#/+#', '/', (string) $relative);
        if ('' === $relative || strlen($relative) > 512 || false !== strpos($relative, '..')) {
            return '';
        }

        return $relative;
    }

    public static function extract_generated_css_asset_refs($html)
    {
        $html = (string) $html;
        $refs = array();
        $generated_base_path = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '';
        if ('' === $html || '' === $generated_base_path || false === stripos($html, trim($generated_base_path, '/'))) {
            return $refs;
        }

        $generated_base_pattern = preg_quote(trailingslashit($generated_base_path), '~');
        $patterns = array(
            '~(?:https?:)?//[^\s"\'<>]+' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s"\'<>?#)]+\.css~i',
            '~' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s"\'<>?#)]+\.css~i',
        );


        foreach ($patterns as $pattern) {
            $matches = array();
            $matched = preg_match_all($pattern, $html, $matches);
            if (false === $matched || empty($matches[0]) || !is_array($matches[0])) {
                continue;
            }

            foreach ($matches[0] as $ref) {
                $path = (string) wp_parse_url((string) $ref, PHP_URL_PATH);
                if ('' === $path) {
                    $path = (string) $ref;
                }

                $path = rawurldecode((string) $path);
                $generated_ref_pattern = '#^' . preg_quote(trailingslashit($generated_base_path), '#') . '(css-bundles|font-css|optimized-css)/([^/]+\.css)$#i';
                if (!preg_match($generated_ref_pattern, $path, $match)) {
                    continue;
                }

                $bucket = strtolower((string) $match[1]);
                $basename = basename((string) $match[2]);
                if (!in_array($bucket, array('css-bundles', 'font-css', 'optimized-css'), true) || '' === $basename || !preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    continue;
                }

                $refs[$bucket . '/' . $basename] = array(
                    'asset_bucket' => $bucket,
                    'asset_basename' => $basename,
                );

                if ('css-bundles' === $bucket && preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    $companion = preg_match('/-delayed-fonts\.css$/i', $basename)
                        ? (string) preg_replace('/-delayed-fonts\.css$/i', '.css', $basename)
                        : (string) preg_replace('/\.css$/i', '-delayed-fonts.css', $basename);
                    if ('' !== $companion && $companion !== $basename && preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $companion)) {
                        $refs['css-bundles/' . $companion] = array(
                            'asset_bucket' => 'css-bundles',
                            'asset_basename' => $companion,
                        );
                    }
                }
            }
        }

        return array_slice(array_values($refs), 0, self::get_cache_asset_refs_per_cache_file_cap());
    }

    public static function track_cache_asset_refs_for_file($cache_file, $html)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return 0;
        }

        $cache_rel_path = self::normalize_cache_asset_cache_rel_path($cache_file);
        if ('' === $cache_rel_path) {
            return 0;
        }

        $refs = self::extract_generated_css_asset_refs($html);
        $table = self::get_cache_asset_refs_table_name();
        $cache_hash = sha1($cache_rel_path);
        $now = current_time('mysql');
        $protect_until = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() + self::get_cache_asset_refs_protection_seconds()));

        if (empty($refs)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache build cleanup updates only UltraCache-owned asset refs for one cache file.
            $wpdb->query($wpdb->prepare('UPDATE %i SET active = 0, last_seen = %s, protect_until = %s WHERE cache_hash = %s AND active = 1', $table, $now, $protect_until, $cache_hash));
            return 0;
        }

        $seen = array();
        foreach ($refs as $ref) {
            $bucket = isset($ref['asset_bucket']) ? (string) $ref['asset_bucket'] : '';
            $basename = isset($ref['asset_basename']) ? (string) $ref['asset_basename'] : '';
            if (!in_array($bucket, array('css-bundles', 'font-css', 'optimized-css'), true) || '' === $basename || !preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
                continue;
            }

            $seen[] = sha1($bucket . '/' . $basename);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache build metadata upsert writes one bounded UltraCache-owned ref row per generated CSS asset.
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO %i (cache_hash, cache_rel_path, asset_bucket, asset_basename, asset_hash, active, first_seen, last_seen, protect_until) VALUES (%s, %s, %s, %s, %s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE cache_rel_path = VALUES(cache_rel_path), asset_bucket = VALUES(asset_bucket), asset_basename = VALUES(asset_basename), active = 1, last_seen = VALUES(last_seen), protect_until = VALUES(protect_until)',
                    $table,
                    $cache_hash,
                    $cache_rel_path,
                    $bucket,
                    $basename,
                    sha1($bucket . '/' . $basename),
                    $now,
                    $now,
                    $protect_until
                )
            );
        }

        if (!empty($seen)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache build cleanup reads active UltraCache-owned refs for one generated cache file.
            $active_hashes = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT asset_hash FROM %i WHERE cache_hash = %s AND active = 1',
                    $table,
                    $cache_hash
                )
            );

            $seen_lookup = array_fill_keys($seen, true);
            foreach ((array) $active_hashes as $asset_hash) {
                $asset_hash = is_scalar($asset_hash) ? (string) $asset_hash : '';
                if ('' === $asset_hash || isset($seen_lookup[$asset_hash])) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache build cleanup deactivates stale refs only for the rewritten cache file.
                $wpdb->update(
                    $table,
                    array(
                        'active'        => 0,
                        'last_seen'     => $now,
                        'protect_until' => $protect_until,
                    ),
                    array(
                        'cache_hash' => $cache_hash,
                        'asset_hash' => $asset_hash,
                    ),
                    array('%d', '%s', '%s'),
                    array('%s', '%s')
                );
            }
        }

        return count($seen);
    }

    public static function mark_cache_asset_refs_inactive_for_cache_file($cache_file)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return 0;
        }

        $cache_rel_path = self::normalize_cache_asset_cache_rel_path($cache_file);
        if ('' === $cache_rel_path) {
            return 0;
        }

        $table = self::get_cache_asset_refs_table_name();
        $now = current_time('mysql');
        $protect_until = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() + self::get_cache_asset_refs_protection_seconds()));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache purge updates only UltraCache-owned asset refs for one cache file.
        $updated = $wpdb->query($wpdb->prepare('UPDATE %i SET active = 0, last_seen = %s, protect_until = %s WHERE cache_hash = %s', $table, $now, $protect_until, sha1($cache_rel_path)));
        return is_numeric($updated) ? max(0, (int) $updated) : 0;
    }

    public static function mark_all_cache_asset_refs_inactive()
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return 0;
        }

        $table = self::get_cache_asset_refs_table_name();
        $now = current_time('mysql');
        $protect_until = get_date_from_gmt(gmdate('Y-m-d H:i:s', time() + self::get_cache_asset_refs_protection_seconds()));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Purge-all deactivates only UltraCache-owned generated CSS ref rows while preserving stale-proxy protection.
        $updated = $wpdb->query($wpdb->prepare('UPDATE %i SET active = 0, last_seen = %s, protect_until = %s WHERE active = 1', $table, $now, $protect_until));
        return is_numeric($updated) ? max(0, (int) $updated) : 0;
    }

    public static function get_protected_generated_css_basenames($bucket = 'css-bundles')
    {
        global $wpdb;

        $bucket = strtolower(trim((string) $bucket));
        if (!in_array($bucket, array('css-bundles', 'font-css', 'optimized-css'), true) || !($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return array();
        }

        $table = self::get_cache_asset_refs_table_name();
        $now = current_time('mysql');
        $limit = (int) apply_filters('ultracache_cache_asset_ref_lookup_limit', 5000);
        $limit = max(100, min(20000, $limit));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup reads bounded UltraCache-owned generated CSS refs outside the frontend HIT path.
        $rows = $wpdb->get_col($wpdb->prepare('SELECT asset_basename FROM %i WHERE asset_bucket = %s AND (active = 1 OR protect_until >= %s) GROUP BY asset_basename LIMIT %d', $table, $bucket, $now, $limit));
        $protected = array();
        foreach ((array) $rows as $basename) {
            $basename = basename((string) $basename);
            if ('' !== $basename && preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
                $protected[$basename] = true;
            }
        }

        return $protected;
    }

    public static function prune_cache_asset_refs_table($limit = 1000)
    {
        global $wpdb;

        if (!($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return 0;
        }

        $limit = max(25, min(5000, (int) $limit));
        $table = self::get_cache_asset_refs_table_name();
        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention cleanup deletes only expired inactive UltraCache-owned generated CSS ref rows in index order.
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE active = 0 AND protect_until < %s ORDER BY protect_until ASC, id ASC LIMIT %d', $table, $now, $limit));
        return is_numeric($deleted) ? max(0, (int) $deleted) : 0;
    }

    public static function prune_cache_asset_refs_table_batched($max_delete = 25000, $batch_size = 5000, $time_budget_seconds = 3.0)
    {
        global $wpdb;

        $max_delete = max(1000, min(100000, (int) $max_delete));
        $batch_size = max(250, min(5000, (int) $batch_size));
        $time_budget_seconds = max(0.25, min(10.0, (float) $time_budget_seconds));
        $started = microtime(true);
        $deleted_total = 0;
        $batches = 0;
        $last_batch = 0;

        if (!($wpdb instanceof wpdb) || !self::ensure_cache_asset_refs_table()) {
            return array(
                'deleted' => 0,
                'batches' => 0,
                'lastBatchDeleted' => 0,
                'maxDelete' => $max_delete,
                'batchSize' => $batch_size,
                'elapsedSeconds' => round(max(0.0, microtime(true) - $started), 4),
                'backlogLikely' => false,
            );
        }

        $table = self::get_cache_asset_refs_table_name();
        $now = current_time('mysql');
        while ($deleted_total < $max_delete && (microtime(true) - $started) < $time_budget_seconds) {
            $remaining = $max_delete - $deleted_total;
            $requested = min($batch_size, $remaining);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Catch-up retention cleanup deletes only expired inactive UltraCache-owned generated CSS refs in bounded batches.
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE active = 0 AND protect_until < %s ORDER BY protect_until ASC, id ASC LIMIT %d', $table, $now, $requested));
            $last_batch = is_numeric($deleted) ? max(0, (int) $deleted) : 0;
            $deleted_total += $last_batch;
            $batches++;

            if ($last_batch < $requested) {
                break;
            }
        }

        $elapsed = max(0.0, microtime(true) - $started);
        $budget_exhausted = $deleted_total >= $max_delete || $elapsed >= $time_budget_seconds;
        $backlog_likely = $budget_exhausted && $last_batch > 0;

        return array(
            'deleted' => $deleted_total,
            'batches' => $batches,
            'lastBatchDeleted' => $last_batch,
            'maxDelete' => $max_delete,
            'batchSize' => $batch_size,
            'elapsedSeconds' => round($elapsed, 4),
            'backlogLikely' => $backlog_likely,
        );
    }
}
