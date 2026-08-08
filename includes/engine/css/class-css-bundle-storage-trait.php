<?php
/**
 * CSS bundle paths, manifests, runtime statistics, and persisted bundle metadata.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_CSS_Bundle_Storage_Trait
{

/** @var array<string,bool> */
private $frontpage_css_manifest_freshness_cache_current_request = array();

private function get_frontpage_css_dir()
    {
        return ultracache_generated_asset_dir('css-bundles');
    }

private function get_frontpage_css_manifest_file()
    {
        return $this->get_frontpage_css_dir() . 'manifest.json';
    }

private function get_css_bundle_manifest_max_entries()
    {
        /**
         * Caps per-page CSS bundle manifest growth. The manifest is a runtime lookup file,
         * not a diagnostics archive; old entries are safe to rebuild on demand.
         */
        $max = (int) apply_filters('ultracache_css_bundle_manifest_max_entries', 500);
        return max(50, min(5000, $max));
    }

private function get_css_bundle_manifest_tmp_cleanup_age_seconds()
    {
        $seconds = (int) apply_filters('ultracache_css_bundle_manifest_tmp_cleanup_age_seconds', 10 * MINUTE_IN_SECONDS);
        return max(60, min(DAY_IN_SECONDS, $seconds));
    }

private function normalize_frontpage_css_source_urls_for_manifest(array $source_urls)
    {
        $normalized = array();
        foreach ($source_urls as $source_url) {
            $url = trim((string) $source_url);
            if ('' === $url) {
                continue;
            }
            $normalized[$url] = true;
        }
        return array_keys($normalized);
    }

private function ultracache_build_frontpage_css_source_fingerprint($source_url)
    {
        $url = trim((string) $source_url);
        if ('' === $url) {
            return array();
        }

        $path = $this->resolve_local_path_from_public_url($url);
        if ('' === (string) $path || !is_readable((string) $path)) {
            return array();
        }

        $resolved_path = realpath((string) $path);
        $canonical_path = false !== $resolved_path ? (string) $resolved_path : (string) $path;
        $canonical_path = wp_normalize_path($canonical_path);
        if ('' === $canonical_path) {
            return array();
        }

        clearstatcache(true, (string) $path);
        $bytes = function_exists('ultracache_safe_filesize')
            ? (int) ultracache_safe_filesize((string) $path, 'frontpage_css_manifest_source_fingerprint')
            : (int) filesize((string) $path);
        $mtime = function_exists('ultracache_safe_filemtime')
            ? (int) ultracache_safe_filemtime((string) $path, 'frontpage_css_manifest_source_fingerprint')
            : (int) filemtime((string) $path);
        if ($bytes < 0 || $mtime <= 0) {
            return array();
        }

        return array(
            'url' => $url,
            'pathHash' => hash('sha256', $canonical_path),
            'mtime' => $mtime,
            'bytes' => $bytes,
        );
    }

private function ultracache_normalize_frontpage_css_source_fingerprints_for_manifest(array $fingerprints)
    {
        $normalized = array();
        foreach ($fingerprints as $fingerprint) {
            if (!is_array($fingerprint)) {
                continue;
            }

            $url = trim((string) ($fingerprint['url'] ?? ''));
            $path_hash = strtolower(trim((string) ($fingerprint['pathHash'] ?? '')));
            $mtime = isset($fingerprint['mtime']) ? (int) $fingerprint['mtime'] : 0;
            $bytes = isset($fingerprint['bytes']) ? (int) $fingerprint['bytes'] : -1;
            if ('' === $url || 1 !== preg_match('/^[a-f0-9]{64}$/', $path_hash) || $mtime <= 0 || $bytes < 0) {
                continue;
            }

            $normalized[$url] = array(
                'url' => $url,
                'pathHash' => $path_hash,
                'mtime' => $mtime,
                'bytes' => $bytes,
            );
        }

        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

private function ultracache_build_frontpage_css_source_fingerprints(array $source_urls)
    {
        $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest($source_urls);
        $fingerprints = array();
        foreach ($source_urls as $source_url) {
            $fingerprint = $this->ultracache_build_frontpage_css_source_fingerprint($source_url);
            if (empty($fingerprint)) {
                return array();
            }
            $fingerprints[] = $fingerprint;
        }

        return $this->ultracache_normalize_frontpage_css_source_fingerprints_for_manifest($fingerprints);
    }

private function ultracache_is_frontpage_css_manifest_source_fresh(array $entry)
    {
        $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest((array) ($entry['sourceUrls'] ?? array()));
        $expected = $this->ultracache_normalize_frontpage_css_source_fingerprints_for_manifest((array) ($entry['sourceFingerprints'] ?? array()));
        if (empty($source_urls) || count($source_urls) !== count($expected)) {
            return false;
        }

        $cache_payload = wp_json_encode($expected);
        $cache_key = is_string($cache_payload) ? hash('sha256', $cache_payload) : '';
        if ('' !== $cache_key && array_key_exists($cache_key, $this->frontpage_css_manifest_freshness_cache_current_request)) {
            return (bool) $this->frontpage_css_manifest_freshness_cache_current_request[$cache_key];
        }

        $current = $this->ultracache_build_frontpage_css_source_fingerprints($source_urls);
        $fresh = !empty($current) && $expected === $current;
        if ('' !== $cache_key) {
            $this->frontpage_css_manifest_freshness_cache_current_request[$cache_key] = $fresh;
        }
        return $fresh;
    }

private function build_frontpage_css_manifest_entry($url, array $prepared)
    {
        $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest((array) ($prepared['sourceUrls'] ?? array()));
        $source_fingerprints = $this->ultracache_build_frontpage_css_source_fingerprints($source_urls);
        return array(
            'normalizedUrl' => $this->normalize_url((string) $url),
            'bundleFile' => (string) ($prepared['bundleFile'] ?? ''),
            'bundleUrl' => (string) ($prepared['bundleUrl'] ?? ''),
            'sourceUrls' => $source_urls,
            'sourceFingerprints' => $source_fingerprints,
            'sourceCount' => count($source_urls),
            'bundleCount' => 1,
            'mode' => (string) ($prepared['mode'] ?? 'safe'),
            'bundleSignature' => (string) ($prepared['bundleSignature'] ?? ''),
            'bundleContentHash' => (string) ($prepared['bundleContentHash'] ?? ''),
            'delayedFontFile' => (string) ($prepared['delayedFontFile'] ?? ''),
            'delayedFontUrl' => (string) ($prepared['delayedFontUrl'] ?? ''),
            'delayedFontBytes' => isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0,
            'delayedFontFaceBlocks' => isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0,
            'sourceBytesTotal' => isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0,
            'time' => current_time('timestamp'),
            'time_mysql' => current_time('mysql'),
        );
    }

private function compact_frontpage_css_manifest_entry(array $entry)
    {
        if (empty($entry)) {
            return array();
        }

        $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest((array) ($entry['sourceUrls'] ?? array()));
        if (empty($source_urls) && !empty($entry['sourceDetails']) && is_array($entry['sourceDetails'])) {
            foreach ((array) $entry['sourceDetails'] as $detail) {
                if (is_array($detail) && !empty($detail['url'])) {
                    $source_urls[] = (string) $detail['url'];
                }
            }
            $source_urls = $this->normalize_frontpage_css_source_urls_for_manifest($source_urls);
        }

        $source_fingerprints = $this->ultracache_normalize_frontpage_css_source_fingerprints_for_manifest((array) ($entry['sourceFingerprints'] ?? array()));

        $compact = array(
            'normalizedUrl' => isset($entry['normalizedUrl']) ? (string) $entry['normalizedUrl'] : '',
            'bundleFile' => isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '',
            'bundleUrl' => isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '',
            'sourceUrls' => $source_urls,
            'sourceFingerprints' => $source_fingerprints,
            'sourceCount' => isset($entry['sourceCount']) ? max(0, (int) $entry['sourceCount']) : count($source_urls),
            'bundleCount' => isset($entry['bundleCount']) ? max(0, (int) $entry['bundleCount']) : 1,
            'mode' => isset($entry['mode']) ? (string) $entry['mode'] : 'safe',
            'bundleSignature' => isset($entry['bundleSignature']) ? (string) $entry['bundleSignature'] : '',
            'bundleContentHash' => isset($entry['bundleContentHash']) ? (string) $entry['bundleContentHash'] : '',
            'delayedFontFile' => isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '',
            'delayedFontUrl' => isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '',
            'delayedFontBytes' => isset($entry['delayedFontBytes']) ? max(0, (int) $entry['delayedFontBytes']) : 0,
            'delayedFontFaceBlocks' => isset($entry['delayedFontFaceBlocks']) ? max(0, (int) $entry['delayedFontFaceBlocks']) : 0,
            'sourceBytesTotal' => isset($entry['sourceBytesTotal']) ? max(0, (int) $entry['sourceBytesTotal']) : 0,
            'time' => isset($entry['time']) ? max(0, (int) $entry['time']) : 0,
            'time_mysql' => isset($entry['time_mysql']) ? (string) $entry['time_mysql'] : '',
        );

        if ('' === $compact['normalizedUrl'] && !empty($entry['url'])) {
            $compact['normalizedUrl'] = $this->normalize_url((string) $entry['url']);
        }
        if ($compact['sourceCount'] <= 0) {
            $compact['sourceCount'] = count($source_urls);
        }

        return $compact;
    }

private function compact_frontpage_css_manifest(array $manifest)
    {
        $manifest['version'] = 4;
        if (empty($manifest['entry']) || !is_array($manifest['entry'])) {
            $manifest['entry'] = array();
        } else {
            $manifest['entry'] = $this->compact_frontpage_css_manifest_entry($manifest['entry']);
        }
        if (empty($manifest['entries']) || !is_array($manifest['entries'])) {
            $manifest['entries'] = array();
        }

        $entries = array();
        foreach ((array) $manifest['entries'] as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $compact = $this->compact_frontpage_css_manifest_entry($entry);
            if (empty($compact['bundleFile']) || empty($compact['bundleUrl']) || empty($compact['sourceUrls'])) {
                continue;
            }
            $entries[(string) $key] = $compact;
        }

        if (count($entries) > $this->get_css_bundle_manifest_max_entries()) {
            uasort($entries, function ($a, $b) {
                $at = isset($a['time']) ? (int) $a['time'] : 0;
                $bt = isset($b['time']) ? (int) $b['time'] : 0;
                if ($at === $bt) {
                    return 0;
                }
                return ($at < $bt) ? 1 : -1;
            });
            $entries = array_slice($entries, 0, $this->get_css_bundle_manifest_max_entries(), true);
        }

        $manifest['entries'] = $entries;
        $manifest['updatedAt'] = isset($manifest['updatedAt']) ? (int) $manifest['updatedAt'] : current_time('timestamp');
        $manifest['updatedAtMysql'] = isset($manifest['updatedAtMysql']) ? (string) $manifest['updatedAtMysql'] : current_time('mysql');

        return $manifest;
    }

private function get_default_frontpage_css_stats()
    {
        return array(
            'scanned' => 0,
            'bundled' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'delayedFontFaceBlocks' => 0,
            'delayedFontFamilies' => array(),
            'delayedFontPatterns' => array(),
            'cssImageUrlsScanned' => 0,
            'cssImageUrlsRewritten' => 0,
            'cssImageUrlsImageSet' => 0,
            'cssImageUrlsSkipped' => 0,
            'fontDisplayAdded' => 0,
            'fontFaceBlocksScanned' => 0,
        );
    }

private function read_frontpage_css_manifest()
    {
        $file = $this->get_frontpage_css_manifest_file();
        if (!file_exists($file) || !is_readable($file)) {
            return array(
                'version' => 1,
                'entry' => array(),
            );
        }

        $raw = ultracache_safe_file_get_contents($file);
        $decoded = is_string($raw) && '' !== $raw ? json_decode($raw, true) : array();
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if (empty($decoded['version'])) {
            $decoded['version'] = 1;
        }
        if (empty($decoded['entry']) || !is_array($decoded['entry'])) {
            $decoded['entry'] = array();
        }
        if (empty($decoded['entries']) || !is_array($decoded['entries'])) {
            $decoded['entries'] = array();
        }

        return $this->compact_frontpage_css_manifest($decoded);
    }

private function write_frontpage_css_manifest(array $manifest)
    {
        $dir = $this->get_frontpage_css_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $this->cleanup_css_manifest_tmp_files(null, 5);
        $manifest = $this->compact_frontpage_css_manifest($manifest);
        $json = wp_json_encode($manifest);
        if (!is_string($json)) {
            return false;
        }

        $written = $this->write_cache_variant_atomically($this->get_frontpage_css_manifest_file(), $json);
        $this->cleanup_css_manifest_tmp_files(null, 5);
        return $written;
    }

private function get_frontpage_css_manifest_bundle_files(array $manifest)
    {
        $files = array();
        foreach ((array) ($manifest['entries'] ?? array()) as $entry) {
            if (is_array($entry) && !empty($entry['bundleFile'])) {
                $files[] = (string) $entry['bundleFile'];
            }
            if (is_array($entry) && !empty($entry['delayedFontFile'])) {
                $files[] = (string) $entry['delayedFontFile'];
            }
        }

        if (!empty($manifest['entry']) && is_array($manifest['entry']) && !empty($manifest['entry']['bundleFile'])) {
            $files[] = (string) $manifest['entry']['bundleFile'];
        }
        if (!empty($manifest['entry']) && is_array($manifest['entry']) && !empty($manifest['entry']['delayedFontFile'])) {
            $files[] = (string) $manifest['entry']['delayedFontFile'];
        }

        $dir = wp_normalize_path($this->get_frontpage_css_dir());
        $active = array();
        foreach ($files as $file) {
            $file = wp_normalize_path((string) $file);
            if ('' === $file || 0 !== strpos($file, $dir)) {
                continue;
            }
            $active[basename($file)] = true;
        }

        return $active;
    }

private function normalize_css_bundle_entry_for_manifest(array $entry)
    {
        if (empty($entry['bundleFile']) || !is_readable((string) $entry['bundleFile']) || filesize((string) $entry['bundleFile']) <= 0) {
            return array();
        }

        if (!empty($entry['delayedFontUrl']) || !empty($entry['delayedFontFile']) || !empty($entry['delayedFontFaceBlocks'])) {
            $delayed_file = isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '';
            if ('' === $delayed_file || !is_readable($delayed_file) || filesize($delayed_file) <= 0) {
                return array();
            }
        }

        return $entry;
    }

private function get_css_bundle_manifest_key($url)
    {
        $normalized = $this->normalize_url((string) $url);
        return '' === $normalized ? '' : md5($normalized);
    }

private function get_frontpage_css_manifest_entry($url = '')
    {
        $url = '' !== (string) $url ? (string) $url : $this->get_current_request_url();
        $key = $this->get_css_bundle_manifest_key($url);
        $manifest = $this->read_frontpage_css_manifest();
        $entry = array();

        if ('' !== $key && isset($manifest['entries'][$key]) && is_array($manifest['entries'][$key])) {
            $entry = $manifest['entries'][$key];
        } elseif ($this->is_frontpage_request_url($url) && isset($manifest['entry']) && is_array($manifest['entry'])) {
            $entry = $manifest['entry'];
        }

        $entry = $this->normalize_css_bundle_entry_for_manifest($entry);
        if (empty($entry)) {
            return array();
        }
        if (empty($entry['bundleUrl']) || empty($entry['sourceUrls']) || !is_array($entry['sourceUrls'])) {
            return array();
        }
        if (!$this->ultracache_is_frontpage_css_manifest_source_fresh($entry)) {
            return array();
        }

        return $entry;
    }
}
