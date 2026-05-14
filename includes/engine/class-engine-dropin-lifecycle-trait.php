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
                UCWP_CACHE_DIR,
                UCWP_AVIF_DIR,
                UCWP_WEBP_DIR,
                trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/',
            );

            foreach ($dirs as $dir) {
                if (!file_exists($dir)) {
                    wp_mkdir_p($dir);
                }

                $index_file = trailingslashit($dir) . 'index.php';
                if (!file_exists($index_file)) {
                    ucwp_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
                }
            }
        }

        public static function setup_advanced_cache($profile = false)
        {
            $profile = (bool) $profile;
            $checkpoint = function ($stage, array $extra = array()) use ($profile) {
                if ($profile && function_exists('ucwp_request_profile_checkpoint')) {
                    ucwp_request_profile_checkpoint('advanced_cache_setup_' . $stage, $extra);
                }
            };

            if (!defined('WP_CONTENT_DIR')) {
                $checkpoint('skipped', array('reason' => 'wp_content_dir_missing'));
                return;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $marker = 'UltraCache advanced-cache drop-in';

            $checkpoint('template_read_start');
            $dropin = self::get_advanced_cache_dropin_contents();
            $checkpoint('template_read_end', array('dropin_bytes' => strlen((string) $dropin)));
            if ('' === $dropin) {
                $checkpoint('skipped', array('reason' => 'template_empty'));
                return;
            }

            if (file_exists($target) && is_readable($target)) {
                $checkpoint('existing_read_start', array('target' => basename((string) $target)));
                $existing = (string) ucwp_safe_file_get_contents($target, 'advanced_cache_existing_read');
                $checkpoint('existing_read_end', array('existing_bytes' => strlen((string) $existing)));
                if ('' !== $existing && $existing === $dropin) {
                    $checkpoint('unchanged', array('result' => 'already_current'));
                    return;
                }

                if ('' !== $existing && false === strpos($existing, $marker)) {
                    $checkpoint('skipped', array('reason' => 'foreign_dropin'));
                    return;
                }
            }

            $tmp = $target . '.tmp-' . uniqid('', true);
            $checkpoint('write_temp_start');
            if (false === ucwp_safe_file_put_contents($tmp, $dropin, LOCK_EX, 'advanced_cache_dropin_write')) {
                $checkpoint('write_temp_failed');
                ucwp_safe_unlink($tmp);
                return;
            }
            $checkpoint('write_temp_end');

            $checkpoint('rename_start');
            if (!ucwp_safe_rename($tmp, $target)) {
                $checkpoint('rename_failed');
                ucwp_safe_unlink($tmp);
                return;
            }
            $checkpoint('rename_end', array('result' => 'written'));
        }

        public static function get_advanced_cache_dropin_status()
        {
            $status = array(
                'exists' => false,
                'readable' => false,
                'has_marker' => false,
                'build' => '',
                'expected_build' => defined('UCWP_VERSION') ? (string) UCWP_VERSION : '',
                'healthy' => false,
                'reason' => '',
            );

            if (!defined('WP_CONTENT_DIR')) {
                $status['reason'] = 'wp_content_dir_missing';
                return $status;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $status['exists'] = file_exists($target);
            $status['readable'] = $status['exists'] && is_readable($target) && is_file($target);

            if (!$status['exists']) {
                $status['reason'] = 'missing';
                return $status;
            }

            if (!$status['readable']) {
                $status['reason'] = 'not_readable';
                return $status;
            }

            // Frontend health checks are read-only and intentionally avoid
            // WP_Filesystem initialization. All writes/repairs are handled in
            // admin, activation, settings-save, or WP-CLI contexts.
            $contents = ucwp_safe_file_get_contents($target, 'advanced_cache_status_read', true);
            if (!is_string($contents) || '' === $contents) {
                $status['reason'] = 'read_failed';
                return $status;
            }

            $status['has_marker'] = false !== strpos($contents, 'UltraCache advanced-cache drop-in');
            if (preg_match('/Drop-in Build:\s*([^\r\n*]+)/', $contents, $matches)) {
                $status['build'] = trim((string) $matches[1]);
            }

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

            $status['healthy'] = true;
            $status['reason'] = 'current';
            return $status;
        }

        public static function get_advanced_cache_dropin_contents()
        {
            if (!defined('WP_CONTENT_DIR')) {
                return '';
            }

            $template = trailingslashit(UCWP_PATH) . 'templates/advanced-cache.php.tpl';
            if (!file_exists($template) || !is_readable($template)) {
                return '';
            }

            $dropin = (string) ucwp_safe_file_get_contents($template, 'advanced_cache_template');
            if ('' === $dropin) {
                return '';
            }

            return str_replace('__UCWP_DROPIN_BUILD__', UCWP_VERSION, $dropin);
        }

        public static function maybe_remove_advanced_cache()
        {
            if (!defined('WP_CONTENT_DIR')) {
                return;
            }

            $target = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            if (!file_exists($target)) {
                return;
            }

            $contents = (string) ucwp_safe_file_get_contents($target);
            if (false !== strpos($contents, 'UltraCache advanced-cache drop-in')) {
                ucwp_safe_unlink($target);
            }
        }

        private function recursive_delete($dir)
        {
            if (!is_dir($dir)) {
                return;
            }

            $items = function_exists('ucwp_safe_scandir') ? ucwp_safe_scandir($dir, 'page_cache_recursive_delete scandir') : scandir($dir);
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
                    ucwp_safe_unlink($path);
                }
            }

            ucwp_safe_rmdir($dir);
        }

}
