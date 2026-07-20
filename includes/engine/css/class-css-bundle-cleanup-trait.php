<?php
/**
 * CSS bundle temporary-file, orphan, companion-file, and manifest cleanup.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_CSS_Bundle_Cleanup_Trait
{

private function cleanup_css_manifest_tmp_files($age_seconds = null, $max_delete = 10)
    {
        $dir = $this->get_frontpage_css_dir();
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }

        $age_seconds = null === $age_seconds ? $this->get_css_bundle_manifest_tmp_cleanup_age_seconds() : (int) $age_seconds;
        $age_seconds = max(60, $age_seconds);
        $max_delete = max(1, min(100, (int) $max_delete));
        $now = time();
        $deleted = 0;
        $files = (array) glob(trailingslashit($dir) . 'manifest.json.tmp-*');

        foreach ($files as $file) {
            $file = wp_normalize_path((string) $file);
            if ('' === $file || !is_file($file) || 'manifest.json.tmp-' !== substr(basename($file), 0, 18)) {
                continue;
            }
            $mtime = @filemtime($file);
            if (!$mtime || ($now - (int) $mtime) < $age_seconds) {
                continue;
            }
            if (ultracache_safe_unlink($file)) {
                $deleted++;
            }
            if ($deleted >= $max_delete) {
                break;
            }
        }

        if ($deleted > 0) {
            $this->record_cache_event('css-manifest-tmp-cleanup', array(
                'deleted' => $deleted,
                'age_seconds' => $age_seconds,
            ));
        }

        return $deleted;
    }

private function get_css_bundle_cleanup_grace_seconds()
    {
        $settings = $this->get_settings();
        $default_seconds = 48 * HOUR_IN_SECONDS;
        $seconds = isset($settings['css_bundle_cleanup_grace_seconds'])
            ? (int) $settings['css_bundle_cleanup_grace_seconds']
            : $default_seconds;

        /**
         * Keep this filter as an advanced server-side override. The dashboard setting
         * supplies the default value, while the filter can still tighten or extend the
         * policy for managed hosting or custom deployments.
         */
        $seconds = (int) apply_filters('ultracache_css_bundle_cleanup_grace_seconds', $seconds);
        return max(HOUR_IN_SECONDS, min(WEEK_IN_SECONDS, $seconds));
    }

private function get_css_bundle_cleanup_max_deletes_per_run()
    {
        $settings = $this->get_settings();
        $max = isset($settings['css_bundle_cleanup_delete_limit'])
            ? (int) $settings['css_bundle_cleanup_delete_limit']
            : 60;

        /**
         * Advanced server-side override. Dashboard value is the default; filter may
         * override it for hosts that need stricter filesystem cleanup limits.
         */
        $max = (int) apply_filters('ultracache_css_bundle_cleanup_max_deletes_per_run', $max);
        return max(5, min(500, $max));
    }

private function is_css_bundle_file_recently_protected($file)
    {
        $file = (string) $file;
        if ('' === $file || !is_file($file)) {
            return false;
        }

        $mtime = (int) filemtime($file);
        if ($mtime <= 0) {
            return true;
        }

        return (time() - $mtime) < $this->get_css_bundle_cleanup_grace_seconds();
    }

private function get_css_bundle_pair_basename($basename)
    {
        $basename = (string) $basename;
        if ('' === $basename) {
            return '';
        }

        return (string) preg_replace('/-delayed-fonts\.css$/i', '.css', $basename);
    }

private function get_css_bundle_companion_basename($basename)
    {
        $basename = (string) $basename;
        if ('' === $basename || !preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
            return '';
        }

        if (preg_match('/-delayed-fonts\.css$/i', $basename)) {
            return $this->get_css_bundle_pair_basename($basename);
        }

        return (string) preg_replace('/\.css$/i', '-delayed-fonts.css', $basename);
    }

private function get_css_bundle_cached_html_ref_basenames($max_files = 800)
    {
        // 2.57.167: generated CSS refs are tracked in an UltraCache DB table during cache STORE.
        // Do not scan cached HTML here; this runs in cleanup/warm flows and must stay bounded.
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_protected_generated_css_basenames')) {
            $protected = Ultra_Cache_WP::get_protected_generated_css_basenames('css-bundles');
            return is_array($protected) ? $protected : array();
        }

        return array();
    }

private function cleanup_orphan_frontpage_css_bundles(array $manifest)
    {
        $dir = $this->get_frontpage_css_dir();
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }

        $active = $this->get_frontpage_css_manifest_bundle_files($manifest);
        $cached_html_refs = $this->get_css_bundle_cached_html_ref_basenames();
        $deleted = 0;
        $protected_by_ref_index = 0;
        $max_deletes = $this->get_css_bundle_cleanup_max_deletes_per_run();
        $files = (array) glob(trailingslashit($dir) . '*.css');

        foreach ($files as $file) {
            $file = (string) $file;
            if ('' === $file || !is_file($file)) {
                continue;
            }

            $basename = basename($file);
            $pair_basename = $this->get_css_bundle_pair_basename($basename);
            if (isset($active[$basename]) || ('' !== $pair_basename && isset($active[$pair_basename]))) {
                continue;
            }

            $companion_basename = $this->get_css_bundle_companion_basename($basename);
            if (isset($cached_html_refs[$basename]) || ('' !== $pair_basename && isset($cached_html_refs[$pair_basename])) || ('' !== $companion_basename && isset($cached_html_refs[$companion_basename]))) {
                $protected_by_ref_index++;
                continue;
            }

            // Proxy-stale-safe lifecycle: Varnish/browser/CDN can still serve older cached HTML
            // after the manifest changed. Keep recent bundle files around long enough for stale
            // HTML refs to keep working instead of returning 404 and breaking CSS.
            if ($this->is_css_bundle_file_recently_protected($file)) {
                continue;
            }

            if (ultracache_safe_unlink($file)) {
                $deleted++;
            }

            if ($deleted >= $max_deletes) {
                break;
            }
        }

        if ($deleted > 0 || $protected_by_ref_index > 0) {
            $this->record_cache_event('page-css-bundle-cleanup', array(
                'deleted' => $deleted,
                'max' => $max_deletes,
                'grace_seconds' => $this->get_css_bundle_cleanup_grace_seconds(),
                'protected_by_ref_index' => $protected_by_ref_index,
            ));
        }

        return $deleted;
    }

private function delete_all_frontpage_css_bundle_files($force = false)
    {
        $dir = $this->get_frontpage_css_dir();
        if (!is_dir($dir) || !is_readable($dir)) {
            return 0;
        }

        $deleted = 0;
        $cached_html_refs = $force ? array() : $this->get_css_bundle_cached_html_ref_basenames();
        $max_deletes = $force ? PHP_INT_MAX : $this->get_css_bundle_cleanup_max_deletes_per_run();
        foreach ((array) glob(trailingslashit($dir) . '*.css') as $file) {
            $file = (string) $file;
            if ('' === $file || !is_file($file)) {
                continue;
            }
            if (!$force) {
                $basename = basename($file);
                $pair_basename = $this->get_css_bundle_pair_basename($basename);
                $companion_basename = $this->get_css_bundle_companion_basename($basename);
                if (isset($cached_html_refs[$basename]) || ('' !== $pair_basename && isset($cached_html_refs[$pair_basename])) || ('' !== $companion_basename && isset($cached_html_refs[$companion_basename]))) {
                    continue;
                }
                if ($this->is_css_bundle_file_recently_protected($file)) {
                    continue;
                }
            }
            if (ultracache_safe_unlink($file)) {
                $deleted++;
            }
            if ($deleted >= $max_deletes) {
                break;
            }
        }
        return $deleted;
    }

private function delete_frontpage_css_bundle($url = '')
    {
        $manifest = $this->read_frontpage_css_manifest();

        if ('' !== (string) $url) {
            $key = $this->get_css_bundle_manifest_key($url);
            if ('' !== $key && isset($manifest['entries'][$key])) {
                unset($manifest['entries'][$key]);
            }
            if ($this->is_frontpage_request_url($url)) {
                $manifest['entry'] = array();
            }
            $this->write_frontpage_css_manifest($manifest);
            // Do not run orphan cleanup immediately after a single-URL purge.
            // Reverse proxies or browser caches can still serve stale HTML for that URL
            // after the local cache file is removed; cleanup will age out retired bundles
            // through the normal grace-window path instead.
            return;
        }

        // Do not remove recent CSS bundles immediately on purge/flush: reverse proxies can
        // still serve stale HTML that references those files. Cleanup will remove aged files.
        $this->delete_all_frontpage_css_bundle_files(false);

        $file = $this->get_frontpage_css_manifest_file();
        if (file_exists($file)) {
            ultracache_safe_unlink($file);
        }
    }
}
