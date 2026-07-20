<?php
/**
 * Runtime, environment, cache, analytics, database, and media diagnostic helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Diagnostics_Runtime_Trait
{
private static function get_object_cache_status_diagnostic_lite()
        {
            $settings = self::get_dashboard_settings();
            $object_cache_path = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('object-cache.php') : '';
            $support = self::get_object_cache_support_status(false);
            $backend_status = array();

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_backend_status')) {
                $backend_status = Ultra_Cache_Object_Cache_Manager::get_backend_status();
            }
            if (!is_array($backend_status)) {
                $backend_status = array();
            }

            $selected = isset($backend_status['selected']) ? self::sanitize_object_cache_backend($backend_status['selected']) : self::sanitize_object_cache_backend($settings['objectCacheBackend'] ?? 'redis');
            $active = isset($backend_status['active']) ? strtolower(trim((string) $backend_status['active'])) : $selected;
            if (!in_array($active, array('redis', 'apcu', 'sqlite', 'disk', 'runtime'), true)) {
                $active = $selected;
            }

            $configured_fallback = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
            $fallback = isset($backend_status['fallback']) ? strtolower(trim((string) $backend_status['fallback'])) : ('none' === $configured_fallback ? 'runtime' : $configured_fallback);
            if (!in_array($fallback, array('apcu', 'sqlite', 'disk', 'runtime'), true)) {
                $fallback = 'none' === $configured_fallback ? 'runtime' : $configured_fallback;
            }

            $fallback_active = isset($backend_status['fallbackActive'])
                ? (bool) $backend_status['fallbackActive']
                : ($selected !== $active);

            $active_runtime_only = ('runtime' === $active);
            $active_persistent = in_array($active, array('redis', 'apcu', 'sqlite', 'disk'), true);
            $active = $active_runtime_only || $active_persistent ? $active : $selected;

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'is_dropin_active')) {
                $dropin_active = (bool) Ultra_Cache_Object_Cache_Manager::is_dropin_active();
            } else {
                $dropin_active = (bool) (
                    function_exists('wp_using_ext_object_cache')
                    && wp_using_ext_object_cache()
                    && ultracache_dropin_exists('object-cache.php')
                );
            }

            $selected_supported = true;
            if ('redis' === $selected) {
                $redis_support = self::get_redis_support_status();
                $selected_supported = !empty($redis_support['available']);
            } elseif ('apcu' === $selected) {
                $selected_supported = !empty($support['apcu']['available']);
            } elseif ('sqlite' === $selected) {
                $selected_supported = !empty($support['sqlite']['available']);
            }

            $redis_dropin = isset($backend_status['redis']) && is_array($backend_status['redis']) ? $backend_status['redis'] : array();
            $apcu_dropin = isset($backend_status['apcu']) && is_array($backend_status['apcu']) ? $backend_status['apcu'] : array();
            $sqlite_dropin = isset($backend_status['sqlite']) && is_array($backend_status['sqlite']) ? $backend_status['sqlite'] : array();

            return array_merge(
                $support,
                array(
                    'enabled' => !empty($settings['objectCacheEnabled']),
                    'active' => $dropin_active,
                    'selectedBackend' => $selected,
                    'activeBackend' => $active,
                    'configuredFallbackBackend' => $configured_fallback,
                    'fallbackBackend' => $fallback_active ? $active : $fallback,
                    'fallbackActive' => (bool) $fallback_active,
                    'activeFallbackBackend' => $fallback_active ? $active : '',
                    'activeFallbackKind' => $fallback_active ? ($active_runtime_only ? 'runtime-only' : 'persistent') : '',
                    'fallbackPersistent' => $fallback_active && $active_persistent,
                    'fallbackReason' => (string) ($backend_status['fallbackReason'] ?? ''),
                    'fallbackMessage' => (string) ($backend_status['fallbackMessage'] ?? ''),
                    'selectedBackendSupported' => (bool) $selected_supported,
                    'activeBackendPersistent' => (bool) $active_persistent,
                    'activeBackendRuntimeOnly' => (bool) $active_runtime_only,
                    'runtimeStatusUsed' => !empty($backend_status['runtimeStatusUsed']),
                    'runtimeConfigStale' => !empty($backend_status['runtimeConfigStale']),
                    'backendStatus' => $backend_status,
                    'passiveStatusOnly' => true,
                    'manualTestsOnly' => true,
                    'redis' => array_merge(
                        self::get_redis_support_status(),
                        array(
                            'host' => self::sanitize_redis_host($settings['redisHost'] ?? '127.0.0.1'),
                            'port' => self::sanitize_bounded_integer_setting($settings['redisPort'] ?? 6379, 6379, 1, 65535),
                            'database' => self::sanitize_redis_database($settings['redisDatabase'] ?? 0),
                            'prefix' => self::sanitize_redis_prefix($settings['redisPrefix'] ?? 'ultracache:'),
                            'useTls' => !empty($settings['redisUseTls']),
                            'persistent' => !empty($settings['redisPersistent']),
                            'dropinEnabled' => !empty($redis_dropin['enabled']),
                            'dropinError' => (string) ($redis_dropin['error'] ?? ''),
                            'payloadSkipReason' => (string) ($redis_dropin['payloadSkipReason'] ?? ''),
                        )
                    ),
                    'apcu' => array_merge(
                        isset($support['apcu']) && is_array($support['apcu']) ? $support['apcu'] : array(),
                        array(
                            'dropinEnabled' => !empty($apcu_dropin['enabled']),
                            'dropinAvailable' => isset($apcu_dropin['available']) ? (bool) $apcu_dropin['available'] : (!empty($support['apcu']['available'])),
                        )
                    ),
                    'sqlite' => array_merge(
                        isset($support['sqlite']) && is_array($support['sqlite']) ? $support['sqlite'] : array(),
                        array(
                            'dropinEnabled' => !empty($sqlite_dropin['enabled']),
                            'dropinAvailable' => isset($sqlite_dropin['available']) ? (bool) $sqlite_dropin['available'] : (!empty($support['sqlite']['available'])),
                            'journalMode' => (string) ($sqlite_dropin['journalMode'] ?? ''),
                            'path' => (string) ($sqlite_dropin['path'] ?? ''),
                            'error' => (string) ($sqlite_dropin['error'] ?? ''),
                        )
                    ),
                )
            );
        }

private static function get_path_diagnostic($path, $type = 'file', $managed_marker = '')
        {
            $exists          = file_exists($path);
            $is_dir          = ('dir' === $type);
            $parent          = dirname($path);
            $modified        = 0;
            $size            = 0;
            $managed         = false;
            $drop_in_build   = '';
            $storage_format  = '';
            $read_error      = '';
            $readable        = $exists ? is_readable($path) : false;
            $writable        = $exists ? ultracache_path_is_writable($path) : ($parent && file_exists($parent) ? ultracache_path_is_writable($parent) : false);
            $parent_writable = ($parent && file_exists($parent)) ? ultracache_path_is_writable($parent) : false;

            if ($exists) {
                $modified = ultracache_safe_filemtime($path, 'path_diagnostic');
                if (!$is_dir) {
                    $size = (int) ultracache_safe_filesize($path, 'path_diagnostic');
                }

                if (!$is_dir && $managed_marker && $readable) {
                    $contents = ultracache_safe_file_get_contents($path, 'dashboard path diagnostic');
                    if (false === $contents) {
                        $read_error = self::maybe_translate('Read failed');
                    } else {
                        $contents_string = (string) $contents;
                        $managed = false !== strpos($contents_string, $managed_marker);

                        if (preg_match('/Drop-in Build:\s*([^\r\n]+)/i', $contents_string, $matches)) {
                            $drop_in_build = trim((string) $matches[1]);
                        }

                        if (preg_match('/Storage format:\s*([^\r\n]+)/i', $contents_string, $matches)) {
                            $storage_format = trim((string) $matches[1]);
                        }
                    }
                }
            }

            return array(
                'path'           => (string) $path,
                'type'           => $is_dir ? 'dir' : 'file',
                'exists'         => (bool) $exists,
                'readable'       => (bool) $readable,
                'writable'       => (bool) $writable,
                'parentWritable' => (bool) $parent_writable,
                'size'           => (int) max(0, (int) $size),
                'modified'       => (int) max(0, (int) $modified),
                'managed'        => (bool) $managed,
                'dropInBuild'    => (string) $drop_in_build,
                'storageFormat'  => (string) $storage_format,
                'readError'      => (string) $read_error,
            );
        }

private static function get_embedded_runtime_config_diagnostic()
        {
            $runtime = class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_embedded_runtime_config')
                ? Ultra_Cache_WP::get_embedded_runtime_config()
                : array();
            $status = class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_advanced_cache_dropin_status')
                ? Ultra_Cache_Engine::get_advanced_cache_dropin_status()
                : array();

            return array(
                'path' => '',
                'type' => 'embedded',
                'exists' => !empty($status['exists']),
                'readable' => !empty($status['readable']),
                'writable' => false,
                'parentWritable' => false,
                'size' => 0,
                'modified' => 0,
                'managed' => !empty($status['has_marker']),
                'valid' => !empty($status['has_marker']) && !empty($status['config_hash']),
                'inSync' => !empty($status['config_in_sync']),
                'keys' => array_values(array_keys(is_array($runtime) ? $runtime : array())),
                'loaded' => self::redact_diagnostics_for_output(is_array($runtime) ? $runtime : array(), 'embeddedRuntime', 0),
                'expected' => self::redact_diagnostics_for_output(is_array($runtime) ? $runtime : array(), 'expectedRuntime', 0),
                'storageFormat' => 'embedded_in_advanced_cache',
                'configHash' => isset($status['config_hash']) ? (string) $status['config_hash'] : '',
                'expectedConfigHash' => isset($status['expected_config_hash']) ? (string) $status['expected_config_hash'] : '',
                'runtimeControlSecretSource' => 'wordpress-authentication-salts',
                'runtimeControlSecretAvailable' => '' !== (function_exists('ultracache_runtime_control_secret') ? ultracache_runtime_control_secret() : ''),
                'readError' => '',
            );
        }

private static function get_analytics_diagnostic()
        {
            $diag = array(
                'storage' => 'db',
                'table' => '',
                'exists' => false,
                'valid' => false,
                'rows' => 0,
                'keys' => array(),
                'message' => self::maybe_translate('Aggregate analytics are stored in the UltraCache analytics DB table.'),
            );

            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'ensure_analytics_table')) {
                Ultra_Cache_Engine::ensure_analytics_table();
            }

            global $wpdb;
            if (!($wpdb instanceof wpdb)) {
                $diag['message'] = self::maybe_translate('Database connection is unavailable.');
                return $diag;
            }

            $table = method_exists('Ultra_Cache_Engine', 'get_analytics_table_name') ? Ultra_Cache_Engine::get_analytics_table_name() : $wpdb->prefix . 'ultracache_analytics';
            $diag['table'] = $table;

            $previous_suppress = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional read-only diagnostics must return unavailable instead of showing database errors.
            $existing_table = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
            );
            $exists = ((string) $existing_table === (string) $table);
            $diag['exists'] = (bool) $exists;
            if (!$exists) {
                $wpdb->suppress_errors($previous_suppress);
                $diag['message'] = self::maybe_translate('Analytics DB table is missing.');
                return $diag;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional read-only analytics diagnostics.
            $row_count = $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
            );
            $diag['rows'] = null === $row_count || false === $row_count || '' === $row_count ? 0 : (int) $row_count;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional read-only analytics diagnostics.
            $keys = $wpdb->get_col(
                $wpdb->prepare('SELECT metric_key FROM %i ORDER BY metric_type ASC, metric_key ASC LIMIT 12', $table)
            );
            $wpdb->suppress_errors($previous_suppress);
            $diag['keys'] = is_array($keys) ? array_values(array_map('strval', $keys)) : array();
            $diag['valid'] = true;
            $diag['message'] = self::maybe_translate('Analytics DB table is ready.');

            return $diag;
        }

private static function get_analytics_hit_backend_diagnostic(array $settings = array())
        {
            if (empty($settings)) {
                $settings = self::get_dashboard_settings();
            }

            $apcu_available = function_exists('apcu_fetch')
                && function_exists('apcu_inc')
                && function_exists('apcu_dec')
                && function_exists('apcu_delete')
                && (!function_exists('apcu_enabled') || apcu_enabled());

            if ($apcu_available) {
                $probe_key = 'ultracache_analytics_probe_' . md5(uniqid('ultracache', true));
                $probe_value = 'ultracache:' . md5($probe_key . '|' . microtime(true));
                $probe_ok = false;
                $probe_message = self::maybe_translate('APCu read/write probe failed.');

                try {
                    $stored = @apcu_store($probe_key, $probe_value, 30);
                    $fetch_success = false;
                    $fetched = @apcu_fetch($probe_key, $fetch_success);
                    @apcu_delete($probe_key);
                    $probe_ok = (bool) $stored && (bool) $fetch_success && ((string) $fetched === (string) $probe_value);
                    if ($probe_ok) {
                        $probe_message = self::maybe_translate('APCu read/write probe passed.');
                    }
                } catch (Throwable $e) {
                    $probe_message = $e->getMessage();
                }

                return array(
                    'enabled' => true,
                    'activeBackend' => 'apcu',
                    'apcuAvailable' => true,
                    'redisAvailable' => class_exists('Redis') || extension_loaded('redis'),
                    'readWrite' => $probe_ok,
                    'probeStatus' => $probe_ok ? 'passed' : 'failed',
                    'message' => $probe_ok ? self::maybe_translate('Realtime cache-hit analytics are stored in APCu. Read/write probe passed.') : $probe_message,
                );
            }

            $redis_selected = !empty($settings['objectCacheEnabled']) && 'redis' === self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $redis_support = self::get_redis_support_status();
            $redis_available = !empty($redis_support['available']);
            $redis_connected = false;
            $redis_message = !empty($redis_support['message']) ? (string) $redis_support['message'] : '';

            $redis_read_write = false;
            if ($redis_selected && $redis_available && class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_read_write')) {
                    $redis_test = Ultra_Cache_Object_Cache_Manager::test_redis_read_write();
                    $redis_connected = !empty($redis_test['connected']);
                    $redis_read_write = !empty($redis_test['readWrite']);
                    if (empty($redis_test['success']) && !empty($redis_test['message'])) {
                        $redis_message = (string) $redis_test['message'];
                    }
                } elseif (method_exists('Ultra_Cache_Object_Cache_Manager', 'test_redis_connection')) {
                    $redis_test = Ultra_Cache_Object_Cache_Manager::test_redis_connection();
                    $redis_connected = !empty($redis_test['success']);
                    $redis_read_write = $redis_connected;
                    if (!$redis_connected && !empty($redis_test['message'])) {
                        $redis_message = (string) $redis_test['message'];
                    }
                }
            }

            if ($redis_selected && $redis_available && $redis_connected && $redis_read_write) {
                return array(
                    'enabled' => true,
                    'activeBackend' => 'redis',
                    'apcuAvailable' => false,
                    'redisAvailable' => true,
                    'redisSelected' => true,
                    'redisConnected' => true,
                    'readWrite' => true,
                    'probeStatus' => 'passed',
                    'message' => self::maybe_translate('Realtime cache-hit analytics are stored in Redis because APCu is unavailable. Read/write probe passed.'),
                );
            }

            $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not connected as the active backend.');
            if ($redis_selected && $redis_available && '' !== $redis_message) {
                $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not connected.') . ' ' . $redis_message;
            } elseif (!$redis_selected && $redis_available) {
                $message = self::maybe_translate('Realtime cache-hit analytics are disabled because APCu is unavailable and Redis is not selected as the object cache backend.');
            }

            return array(
                'enabled' => false,
                'activeBackend' => 'disabled',
                'apcuAvailable' => false,
                'redisAvailable' => $redis_available,
                'redisSelected' => $redis_selected,
                'redisConnected' => $redis_connected,
                'readWrite' => $redis_read_write,
                'probeStatus' => $redis_read_write ? 'passed' : 'failed',
                'message' => $message,
            );
        }

private static function get_page_cache_activity_snapshot()
        {
            $cache_key = 'ultracache_dashboard_cache_activity_v1';
            $cached    = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }

            $snapshot = array(
                'path'          => '',
                'modified'      => 0,
                'size'          => 0,
                'pageFiles'     => 0,
                'scannedFiles'  => 0,
                'partial'       => false,
                'partialReason' => '',
            );

            if (!is_dir(ULTRACACHE_CACHE_DIR)) {
                set_transient($cache_key, $snapshot, MINUTE_IN_SECONDS);
                return $snapshot;
            }

            $max_scan_files = (int) apply_filters('ultracache_page_cache_activity_snapshot_max_scan_files', 5000);
            $max_scan_files = max(250, min(50000, $max_scan_files));
            $deadline_seconds = (float) apply_filters('ultracache_page_cache_activity_snapshot_timeout', 1);
            $deadline_seconds = max(0.1, min(3, $deadline_seconds));
            $deadline = microtime(true) + $deadline_seconds;
            $snapshot['scanLimit'] = $max_scan_files;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(ULTRACACHE_CACHE_DIR, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file_info) {
                    if (microtime(true) > $deadline) {
                        $snapshot['partial'] = true;
                        $snapshot['partialReason'] = 'deadline';
                        break;
                    }

                    if (!$file_info->isFile()) {
                        continue;
                    }

                    $snapshot['scannedFiles']++;
                    if ($snapshot['scannedFiles'] >= $max_scan_files) {
                        $snapshot['partial'] = true;
                        $snapshot['partialReason'] = 'limit';
                        break;
                    }

                    $path = str_replace('\\', '/', (string) $file_info->getPathname());
                    $name = strtolower((string) $file_info->getFilename());

                    if (false !== strpos($path, '/font-css/')) {
                        continue;
                    }

                    if (in_array($name, array('index.php', 'analytics.json'), true)) {
                        continue;
                    }

                    if (!preg_match('/\.html(?:\.(?:gz|br))?$/', $name)) {
                        continue;
                    }

                    $snapshot['pageFiles']++;
                    $mtime = (int) $file_info->getMTime();
                    if ($mtime > $snapshot['modified']) {
                        $snapshot['modified'] = $mtime;
                        $snapshot['path']     = $path;
                        $snapshot['size']     = (int) $file_info->getSize();
                    }
                }
            } catch (Exception $e) {
                $snapshot['error'] = (string) $e->getMessage();
                $snapshot['partial'] = true;
                $snapshot['partialReason'] = 'error';
            }

            set_transient($cache_key, $snapshot, MINUTE_IN_SECONDS);
            return $snapshot;
        }

private static function get_object_cache_support_status($allow_live_check = true)
        {
            $cache_key = 'ultracache_object_cache_support_status_v1';
            $allow_live_check = (bool) $allow_live_check;

            if (!$allow_live_check) {
                $cached = get_transient($cache_key);
                if (is_array($cached)) {
                    $cached['source'] = 'cached';
                    return $cached;
                }

                $light = self::get_object_cache_support_status_light();
                $light['source'] = 'light_frontend_default';
                return $light;
            }

            $dropin_installable = true;
            $message   = '';

            if (class_exists('Ultra_Cache_Object_Cache_Manager')) {
                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'supports_dropin')) {
                    $dropin_installable = (bool) Ultra_Cache_Object_Cache_Manager::supports_dropin();
                }

                if (method_exists('Ultra_Cache_Object_Cache_Manager', 'get_unavailable_reason')) {
                    $message = (string) Ultra_Cache_Object_Cache_Manager::get_unavailable_reason();
                }
            }

            $status = self::get_object_cache_support_status_light();
            $status['available'] = $dropin_installable;
            $status['dropinInstallable'] = $dropin_installable;
            $status['message'] = $message;
            $status['source'] = 'live';

            set_transient($cache_key, $status, 5 * MINUTE_IN_SECONDS);

            return $status;
        }

private static function get_object_cache_support_status_light()
        {
            $apcu_available  = function_exists('apcu_fetch') && function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled());
            $redis_available = (bool) (class_exists('Redis') || extension_loaded('redis'));
            $sqlite_available = (bool) (class_exists('SQLite3') && extension_loaded('sqlite3'));
            $sqlite_version = '';
            if ($sqlite_available) {
                $sqlite_version_data = SQLite3::version();
                $sqlite_version = is_array($sqlite_version_data) && isset($sqlite_version_data['versionString']) ? (string) $sqlite_version_data['versionString'] : '';
            }
            $dropin_installable = class_exists('Ultra_Cache_Object_Cache_Manager');

            return array(
                // Kept for compatibility with the dashboard JS. This means the drop-in can be installed, not that Redis is connected.
                'available'                  => $dropin_installable,
                'dropinInstallable'          => $dropin_installable,
                'persistentBackendAvailable' => $redis_available || $sqlite_available,
                'localBackendAvailable'      => $apcu_available,
                'message'                    => $dropin_installable ? '' : self::maybe_translate('Object cache helper not available.'),
                'apcu'      => array(
                    'available' => $apcu_available,
                    'message'   => $apcu_available ? '' : self::maybe_translate('APCu extension is not loaded or not enabled.'),
                ),
                'sqlite'    => array(
                    'available' => $sqlite_available,
                    'version' => $sqlite_version,
                    'message' => $sqlite_available ? '' : self::maybe_translate('SQLite3 extension is not loaded.'),
                ),
                'source' => 'light',
            );
        }

private static function normalize_last_cache_event($last)
        {
            if (!is_array($last)) {
                return array();
            }

            $time = 0;
            if (isset($last['time']) && is_numeric($last['time'])) {
                $time = (int) $last['time'];
            } elseif (!empty($last['time'])) {
                $time = (int) strtotime((string) $last['time']);
            } elseif (!empty($last['time_mysql'])) {
                $time = (int) strtotime((string) $last['time_mysql']);
            }

            if (empty($last['time_mysql']) && !empty($last['time']) && !is_numeric($last['time'])) {
                $last['time_mysql'] = (string) $last['time'];
            }

            $bucket = '';
            if (!empty($last['bucket'])) {
                $bucket = (string) $last['bucket'];
            } elseif (!empty($last['payload']['bucket'])) {
                $bucket = (string) $last['payload']['bucket'];
            } else {
                $paths = array();
                if (!empty($last['file'])) {
                    $paths[] = (string) $last['file'];
                }
                if (!empty($last['files']) && is_array($last['files'])) {
                    $paths = array_merge($paths, array_map('strval', $last['files']));
                }

                foreach ($paths as $path) {
                    if (false !== strpos($path, 'index-avif-')) {
                        $bucket = 'avif';
                        break;
                    }
                    if (false !== strpos($path, 'index-webp-')) {
                        $bucket = 'webp';
                        break;
                    }
                    if (false !== strpos($path, 'index-orig-')) {
                        $bucket = 'orig';
                        break;
                    }
                }
            }

            $last['status'] = !empty($last['status']) ? (string) $last['status'] : (!empty($last['type']) ? (string) $last['type'] : '');
            $last['bucket'] = $bucket;
            $last['time']   = $time > 0 ? $time : 0;

            return $last;
        }

private static function get_mysql_variable_value($variable_name)
        {
            global $wpdb;

            if (!($wpdb instanceof wpdb)) {
                return '';
            }

            $variable_name = sanitize_key((string) $variable_name);
            if ('' === $variable_name) {
                return '';
            }

            $previous_suppress = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optional read-only diagnostics must return unavailable instead of showing database errors.
            $result = $wpdb->get_var(
                $wpdb->prepare('SHOW VARIABLES LIKE %s', $variable_name),
                1
            );

            if ((null === $result || false === $result || '' === $result) && 'max_allowed_packet' === $variable_name) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed read-only server capability query with no user input.
                $result = $wpdb->get_var('SELECT @@max_allowed_packet');
            }

            $wpdb->suppress_errors($previous_suppress);

            if (null === $result || false === $result || '' === $result) {
                return '';
            }

            return (string) $result;
        }

private static function get_mysql_query_cache_size()
        {
            return self::get_mysql_variable_value('query_cache_size');
        }

private static function get_mysql_max_allowed_packet_size()
        {
            return self::get_mysql_variable_value('max_allowed_packet');
        }

private static function get_advanced_environment_diagnostic()
        {
            $host = function_exists('gethostname') ? (string) @gethostname() : '';

            $site_url = function_exists('home_url') ? (string) home_url('/') : '';
            $site_parts = $site_url ? wp_parse_url($site_url) : array();
            $default_port = (!empty($site_parts['scheme']) && 'http' === strtolower((string) $site_parts['scheme'])) ? '80' : '443';
            $server_addr = (string) ultracache_server_value('SERVER_ADDR');
            $server_port = (string) ultracache_server_value('SERVER_PORT');

            if ('' === $server_addr && !empty($site_parts['host'])) {
                $resolved = @gethostbyname((string) $site_parts['host']);
                if (is_string($resolved) && '' !== $resolved) {
                    $server_addr = $resolved;
                }
            }

            if ('' === $server_port) {
                if (!empty($site_parts['port'])) {
                    $server_port = (string) $site_parts['port'];
                } else {
                    $server_port = $default_port;
                }
            }

            $ip_port = $server_addr;
            if ('' !== $server_addr && '' !== $server_port) {
                $ip_port .= ':' . $server_port;
            }

            $document_root = function_exists('ultracache_get_server_document_root_path')
                ? ultracache_get_server_document_root_path()
                : '';

            $query_cache_raw = self::get_mysql_query_cache_size();
            $query_cache_size = '';
            if ('' !== $query_cache_raw && is_numeric($query_cache_raw)) {
                $query_cache_size = size_format((int) $query_cache_raw);
            } elseif ('' !== $query_cache_raw) {
                $query_cache_size = (string) $query_cache_raw;
            } else {
                $query_cache_size = self::maybe_translate('Unavailable');
            }

            $max_packet_raw = self::get_mysql_max_allowed_packet_size();
            $max_packet_size = '';
            if ('' !== $max_packet_raw && is_numeric($max_packet_raw)) {
                $max_packet_size = size_format((int) $max_packet_raw);
            } elseif ('' !== $max_packet_raw) {
                $max_packet_size = (string) $max_packet_raw;
            } else {
                $max_packet_size = self::maybe_translate('Unavailable');
            }

            return array(
                'serverHostname' => $host,
                'originIpPort' => $ip_port,
                'serverDocumentRoot' => $document_root,
                'phpVersion' => (string) PHP_VERSION,
                'phpSapi' => function_exists('php_sapi_name') ? (string) php_sapi_name() : '',
                'phpMaxExecutionTime' => (string) ini_get('max_execution_time'),
                'phpMemoryLimit' => (string) ini_get('memory_limit'),
                'phpMaxUploadSize' => (string) ini_get('upload_max_filesize'),
                'phpMaxPostSize' => (string) ini_get('post_max_size'),
                'phpMaxInputVars' => (string) ini_get('max_input_vars'),
                'wpMemoryLimit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
                'mysqlQueryCacheSize' => $query_cache_size,
                'mysqlMaxAllowedPacket' => $max_packet_size,
            );
        }

private static function get_media_runtime_diagnostic()

        {
            $support = self::get_media_support_status();
            $queue_status = array('enabled' => false);
            $media = self::get_media_instance();
            if ($media && method_exists($media, 'get_media_queue_status')) {
                $queue_status = $media->get_media_queue_status('best');
            }
            return array(
                'preferredEditor' => (string) ($support['preferred_editor'] ?? ''),
                'lastImageEditorClass' => (string) ($support['last_image_editor_class'] ?? ''),
                'lastAvifEncodeEngine' => (string) ($support['last_avif_encode_engine'] ?? ''),
                'lastAvifEncodeError' => (string) ($support['last_avif_encode_error'] ?? ''),
                'lastAvifEncodeFile' => (string) ($support['last_avif_encode_file'] ?? ''),
                'lastAvifEncodeAt' => (int) ($support['last_avif_encode_at'] ?? 0),
                'gdAvif' => !empty($support['gd_avif']),
                'gdWebp' => !empty($support['gd_webp']),
                'imagickAvif' => !empty($support['imagick_avif']),
                'imagickWebp' => !empty($support['imagick_webp']),
                'queue' => is_array($queue_status) ? $queue_status : array('enabled' => false),
            );
        }

}
