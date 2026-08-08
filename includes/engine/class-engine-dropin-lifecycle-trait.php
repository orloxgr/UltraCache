<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activation, cache directory, advanced-cache drop-in, and recursive cleanup helpers for the engine.
 */
trait Ultra_Cache_Engine_Dropin_Lifecycle_Trait
{
        public static function activate()
        {
            self::ensure_cache_directories();
            self::setup_advanced_cache();
        }

        public static function deactivate()
        {
            self::maybe_remove_advanced_cache();
        }

        public static function ensure_cache_directories()
        {
            $dirs = array(
                ULTRACACHE_CACHE_DIR,
                ULTRACACHE_AVIF_DIR,
                ULTRACACHE_WEBP_DIR,
                ultracache_generated_asset_dir('google-fonts'),
            );

            foreach ($dirs as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index_file = trailingslashit($dir) . 'index.php';
                if (!file_exists($index_file)) {
                    ultracache_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
                }
            }
        }

        public static function setup_advanced_cache($profile = false)
        {
            $profile = (bool) $profile;
            $checkpoint = function ($stage, array $extra = array()) use ($profile) {
                if ($profile && function_exists('ultracache_request_profile_checkpoint')) {
                    ultracache_request_profile_checkpoint('advanced_cache_setup_' . $stage, $extra);
                }
            };

            $target = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('advanced-cache.php') : '';
            if ('' === $target) {
                $checkpoint('skipped', array('reason' => 'filesystem_unavailable'));
                return;
            }
            $marker = 'UltraCache advanced-cache drop-in';

            $checkpoint('template_read_start');
            $dropin = self::get_advanced_cache_dropin_contents();
            $checkpoint('template_read_end', array('dropin_bytes' => strlen((string) $dropin)));
            if ('' === $dropin) {
                $checkpoint('skipped', array('reason' => 'template_empty'));
                return;
            }

            if (ultracache_dropin_exists('advanced-cache.php')) {
                $checkpoint('existing_read_start', array('target' => basename((string) $target)));
                $existing = ultracache_read_dropin('advanced-cache.php');
                $checkpoint('existing_read_end', array('existing_bytes' => is_string($existing) ? strlen($existing) : 0));
                if (is_string($existing) && $existing === $dropin) {
                    $checkpoint('unchanged', array('result' => 'already_current'));
                    return;
                }

                if (is_string($existing) && '' !== $existing && false === strpos($existing, $marker)) {
                    $checkpoint('skipped', array('reason' => 'foreign_dropin'));
                    return;
                }
            }

            $checkpoint('write_start');
            if (!ultracache_write_dropin('advanced-cache.php', $dropin)) {
                $checkpoint('write_failed');
                return;
            }
            $checkpoint('write_end', array('result' => 'written'));
        }

        public static function get_advanced_cache_dropin_status()
        {
            $status = array(
                'exists' => false,
                'readable' => false,
                'has_marker' => false,
                'build' => '',
                'expected_build' => defined('ULTRACACHE_VERSION') ? (string) ULTRACACHE_VERSION : '',
                'config_hash' => '',
                'expected_config_hash' => self::get_embedded_runtime_config_hash(),
                'config_in_sync' => false,
                'healthy' => false,
                'reason' => '',
            );

            $target = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('advanced-cache.php') : '';
            if ('' === $target) {
                $status['reason'] = 'filesystem_unavailable';
                return $status;
            }
            $status['exists'] = ultracache_dropin_exists('advanced-cache.php');
            $contents = $status['exists'] ? ultracache_read_dropin('advanced-cache.php') : false;
            $status['readable'] = is_string($contents);

            if (!$status['exists']) {
                $status['reason'] = 'missing';
                return $status;
            }

            if (!is_string($contents) || '' === $contents) {
                $status['reason'] = 'read_failed';
                return $status;
            }

            $status['has_marker'] = false !== strpos($contents, 'UltraCache advanced-cache drop-in');
            if (preg_match('/Drop-in Build:\s*([^\r\n*]+)/', $contents, $matches)) {
                $status['build'] = trim((string) $matches[1]);
            }
            if (preg_match('/Embedded Runtime Config Hash:\s*([a-f0-9]{64})/i', $contents, $matches)) {
                $status['config_hash'] = strtolower(trim((string) $matches[1]));
            }
            $status['config_in_sync'] = '' !== $status['config_hash']
                && hash_equals((string) $status['expected_config_hash'], (string) $status['config_hash']);

            if (!$status['has_marker']) {
                $status['reason'] = 'foreign_dropin';
                return $status;
            }

            if ('' !== $status['expected_build'] && '' !== $status['build'] && $status['build'] !== $status['expected_build']) {
                $status['reason'] = 'stale_build';
                return $status;
            }

            if ('' === $status['build']) {
                $status['reason'] = 'build_marker_missing';
                return $status;
            }

            if (!$status['config_in_sync']) {
                $status['reason'] = 'embedded_config_stale';
                return $status;
            }

            $status['healthy'] = true;
            $status['reason'] = 'current';
            return $status;
        }

        private static function get_embedded_runtime_config_json()
        {
            $runtime = array();
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_embedded_runtime_config')) {
                $runtime = Ultra_Cache_WP::get_embedded_runtime_config();
            }

            $json = wp_json_encode(is_array($runtime) ? $runtime : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return is_string($json) ? $json : '{}';
        }

        private static function get_embedded_runtime_config_hash()
        {
            return hash('sha256', self::get_embedded_runtime_config_json());
        }

        public static function get_advanced_cache_dropin_contents()
        {
            $template = ultracache_plugin_dir('templates/advanced-cache-template.php');
            if (!file_exists($template) || !is_readable($template)) {
                return '';
            }

            $dropin = (string) ultracache_safe_file_get_contents($template, 'advanced_cache_template');
            if ('' === $dropin) {
                return '';
            }

            return str_replace(
                array(
                    '__ULTRACACHE_DROPIN_BUILD__',
                    '__ULTRACACHE_SITE_NAMESPACE_SEED__',
                    '__ULTRACACHE_CACHE_DIR__',
                    '__ULTRACACHE_RUNTIME_CONFIG_JSON__',
                    '__ULTRACACHE_RUNTIME_CONFIG_HASH__',
                ),
                array(
                    ULTRACACHE_VERSION,
                    ultracache_php_string_literal(ultracache_site_namespace_seed()),
                    ultracache_php_string_literal(untrailingslashit(ULTRACACHE_CACHE_DIR)),
                    ultracache_php_string_literal(self::get_embedded_runtime_config_json()),
                    self::get_embedded_runtime_config_hash(),
                ),
                $dropin
            );
        }

        public static function maybe_remove_advanced_cache()
        {
            $target = function_exists('ultracache_dropin_path') ? ultracache_dropin_path('advanced-cache.php') : '';
            if ('' === $target) {
                return;
            }
            $contents = ultracache_read_dropin('advanced-cache.php');
            if (is_string($contents) && false !== strpos($contents, 'UltraCache advanced-cache drop-in')) {
                ultracache_delete_dropin('advanced-cache.php');
            }
        }

        private function recursive_delete($dir)
        {
            if (!is_dir($dir)) {
                return;
            }

            $items = ultracache_safe_scandir($dir, 'page_cache_recursive_delete scandir');
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path) && !is_link($path)) {
                    $this->recursive_delete($path);
                } else {
                    ultracache_safe_unlink($path);
                }
            }

            ultracache_safe_rmdir($dir);
        }

}
