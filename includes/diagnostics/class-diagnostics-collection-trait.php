<?php
/**
 * Dashboard diagnostics collection, storage, font, settings transparency, and security helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Diagnostics_Collection_Trait
{
private static function get_google_fonts_cache_diagnostics()
        {
            $settings = self::get_dashboard_settings();
            $fallback = array(
                'enabled' => !empty($settings['googleFontsLocalOptimizationEnabled']),
                'built' => false,
                'cssFiles' => 0,
                'fontFiles' => 0,
                'totalFiles' => 0,
                'bytes' => 0,
                'lastBuilt' => 0,
                'message' => !empty($settings['googleFontsLocalOptimizationEnabled']) ? 'Google Fonts cache status unavailable.' : 'Local Google Fonts Optimization is disabled.',
            );

            if (class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_instance')) {
                try {
                    $engine = Ultra_Cache_Engine::get_instance();
                    if ($engine && method_exists($engine, 'get_google_fonts_cache_summary')) {
                        $summary = $engine->get_google_fonts_cache_summary();
                        if (is_array($summary)) {
                            return array_merge($fallback, $summary);
                        }
                    }
                } catch (Throwable $e) {
                    $fallback['message'] = 'Google Fonts cache status fallback used after diagnostics error.';
                } catch (Exception $e) {
                    $fallback['message'] = 'Google Fonts cache status fallback used after diagnostics error.';
                }
            }

            $dir = ultracache_generated_asset_dir('google-fonts');
            $fallback['path'] = $dir;
            if (is_dir($dir)) {
                $items = ultracache_safe_scandir($dir, 'google_fonts_dashboard scandir');
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if ('.' === $item || '..' === $item || 'index.php' === $item) {
                            continue;
                        }
                        $path = trailingslashit($dir) . $item;
                        if (!is_file($path)) {
                            continue;
                        }
                        $fallback['totalFiles']++;
                        $size = ultracache_safe_filesize($path, 'google_fonts_dashboard');
                        if (false !== $size) {
                            $fallback['bytes'] += max(0, (int) $size);
                        }
                        $mtime = ultracache_safe_filemtime($path, 'google_fonts_dashboard');
                        if (false !== $mtime) {
                            $fallback['lastBuilt'] = max((int) $fallback['lastBuilt'], (int) $mtime);
                        }
                        if (preg_match('/\.css$/i', $item)) {
                            $fallback['cssFiles']++;
                        } elseif (preg_match('/\.(?:woff2?|ttf|otf)$/i', $item)) {
                            $fallback['fontFiles']++;
                        }
                    }
                }
            }

            $fallback['built'] = $fallback['cssFiles'] > 0 && $fallback['fontFiles'] > 0;
            if ($fallback['built']) {
                $fallback['message'] = sprintf('Google Fonts cache built: %d stylesheet(s), %d font file(s).', (int) $fallback['cssFiles'], (int) $fallback['fontFiles']);
            } elseif (!empty($fallback['enabled']) && $fallback['totalFiles'] > 0) {
                $fallback['message'] = 'Google Fonts cache contains partial files. Rebuild the Google Fonts cache to refresh it.';
            }

            return $fallback;
        }

private static function get_font_pipeline_diagnostics($settings = array())
        {
            $settings = is_array($settings) ? $settings : array();
            $font_css_dir = ultracache_generated_asset_dir('font-css');
            $optimized_css_dir = ultracache_generated_asset_dir('optimized-css');
            $css_bundle_dir = ultracache_generated_asset_dir('css-bundles');
            $google_fonts_dir = ultracache_generated_asset_dir('google-fonts');
            $manifest_file = ultracache_generated_asset_dir('css-bundles', 'manifest.json');

            $count_files = static function ($pattern) {
                $files = glob($pattern);
                if (!is_array($files)) {
                    return array('count' => 0, 'bytes' => 0, 'latestModified' => 0);
                }
                $count = 0;
                $bytes = 0;
                $latest = 0;
                foreach ($files as $file) {
                    if (!is_string($file) || !is_file($file)) {
                        continue;
                    }
                    $count++;
                    $size = ultracache_safe_filesize($file, 'font_pipeline_diagnostic');
                    if (false !== $size) {
                        $bytes += max(0, (int) $size);
                    }
                    $mtime = ultracache_safe_filemtime($file, 'font_pipeline_diagnostic');
                    if (false !== $mtime) {
                        $latest = max($latest, (int) $mtime);
                    }
                }
                return array('count' => $count, 'bytes' => $bytes, 'latestModified' => $latest);
            };

            $font_css = $count_files($font_css_dir . '*.css');
            $font_css_delayed = $count_files($font_css_dir . 'delayed-*.css');
            $scan_css_font_sources = static function ($pattern, $limit = 8) {
                $files = glob($pattern);
                $summary = array(
                    'fontFaceBlocks' => 0,
                    'woff2Sources' => 0,
                    'woffSources' => 0,
                    'ttfSources' => 0,
                    'filesWithTtfSources' => 0,
                    'filesWithoutFontFace' => 0,
                    'nonFontCssFiles' => 0,
                    'nonFontCssBytes' => 0,
                    'delayedIconActiveCssFiles' => 0,
                    'delayedIconActiveCssBytes' => 0,
                    'fontDisplayPatchFiles' => 0,
                    'fontDisplayPatchBytes' => 0,
                    'fontDisplayPatchFontFaces' => 0,
                    'fontDisplaySwapDeclarations' => 0,
                    'googleImportRules' => 0,
                    'localGoogleImportRules' => 0,
                    'remoteGoogleImportRules' => 0,
                    'filesWithGoogleImportRules' => 0,
                    'largestFiles' => array(),
                );
                if (!is_array($files)) {
                    return $summary;
                }
                foreach ($files as $file) {
                    if (!is_string($file) || !is_file($file) || !is_readable($file)) {
                        continue;
                    }
                    $css = ultracache_safe_file_get_contents($file, 'font_pipeline_source_diagnostics', true);
                    if (!is_string($css)) {
                        $css = '';
                    }
                    $font_face_scan = ultracache_css_scan_font_face_blocks($css);
                    $font_faces = empty($font_face_scan['malformed']) ? count((array) $font_face_scan['blocks']) : 0;
                    $woff2 = preg_match_all('/\.woff2(?:[\?#][^"\')\s]*)?/i', $css);
                    $woff = preg_match_all('/\.woff(?!2)(?:[\?#][^"\')\s]*)?/i', $css);
                    $ttf = preg_match_all('/\.ttf(?:[\?#][^"\')\s]*)?/i', $css);
                    $font_display_swap = preg_match_all('/font-display\s*:\s*swap\b/i', $css);
                    $google_imports = preg_match_all('/@import[^;]+(?:google-fonts|fonts\.googleapis\.com)[^;]*;/i', $css);
                    $remote_google_imports = preg_match_all('/@import[^;]+fonts\.googleapis\.com[^;]*;/i', $css);
                    $local_google_imports = max(0, (int) $google_imports - (int) $remote_google_imports);
                    $summary['fontFaceBlocks'] += max(0, (int) $font_faces);
                    $summary['woff2Sources'] += max(0, (int) $woff2);
                    $summary['woffSources'] += max(0, (int) $woff);
                    $summary['ttfSources'] += max(0, (int) $ttf);
                    $summary['googleImportRules'] += max(0, (int) $google_imports);
                    $summary['localGoogleImportRules'] += max(0, (int) $local_google_imports);
                    $summary['remoteGoogleImportRules'] += max(0, (int) $remote_google_imports);
                    if ($google_imports > 0) {
                        $summary['filesWithGoogleImportRules']++;
                    }
                    $file_bytes = max(0, (int) ultracache_safe_filesize($file, 'font_pipeline_source_diagnostics'));
                    $basename = basename($file);
                    $is_font_display_patch = (0 === strpos((string) $basename, 'font-display-'));
                    $is_delayed_icon_active_css = (false !== strpos($css, 'UltraCache delayed icon font active CSS'));
                    if ($is_delayed_icon_active_css) {
                        $summary['delayedIconActiveCssFiles']++;
                        $summary['delayedIconActiveCssBytes'] += $file_bytes;
                    }
                    if ($is_font_display_patch) {
                        $summary['fontDisplayPatchFiles']++;
                        $summary['fontDisplayPatchBytes'] += $file_bytes;
                        $summary['fontDisplayPatchFontFaces'] += max(0, (int) $font_faces);
                        $summary['fontDisplaySwapDeclarations'] += max(0, (int) $font_display_swap);
                    }
                    if ($ttf > 0) {
                        $summary['filesWithTtfSources']++;
                    }
                    if ($font_faces <= 0) {
                        $summary['filesWithoutFontFace']++;
                        if ($file_bytes > 0 && !$is_delayed_icon_active_css) {
                            $summary['nonFontCssFiles']++;
                            $summary['nonFontCssBytes'] += $file_bytes;
                        }
                    }
                    $summary['largestFiles'][] = array(
                        'file' => (string) $basename,
                        'bytes' => $file_bytes,
                        'fontFaceBlocks' => max(0, (int) $font_faces),
                        'woff2Sources' => max(0, (int) $woff2),
                        'ttfSources' => max(0, (int) $ttf),
                        'googleImportRules' => max(0, (int) $google_imports),
                        'remoteGoogleImportRules' => max(0, (int) $remote_google_imports),
                        'nonFontCss' => ($font_faces <= 0 && $file_bytes > 0 && !$is_delayed_icon_active_css),
                        'delayedIconActiveCss' => (bool) $is_delayed_icon_active_css,
                    );
                }
                usort($summary['largestFiles'], static function ($a, $b) {
                    return (int) ($b['bytes'] ?? 0) <=> (int) ($a['bytes'] ?? 0);
                });
                $summary['largestFiles'] = array_slice($summary['largestFiles'], 0, max(1, (int) $limit));
                return $summary;
            };
            $font_css_source_stats = $scan_css_font_sources($font_css_dir . '*.css');
            $optimized_css_source_stats = $scan_css_font_sources($optimized_css_dir . '*.css');
            $optimized_css_files = $count_files($optimized_css_dir . '*.css');
            $bundle_css = $count_files($css_bundle_dir . 'bundle-*.css');
            $bundle_delayed = $count_files($css_bundle_dir . 'bundle-*-delayed-fonts.css');
            $google_font_css = $count_files($google_fonts_dir . '*.css');
            $google_font_files = $count_files($google_fonts_dir . '*.{woff2,woff,ttf,otf}');
            if (0 === (int) $google_font_files['count']) {
                $google_font_files = $count_files($google_fonts_dir . '*');
            }

            $manifest_entries = 0;
            $manifest_entries_with_delayed = 0;
            $manifest_delayed_blocks = 0;
            $manifest_delayed_families = array();
            $manifest_missing_bundle_files = 0;
            $manifest_missing_delayed_files = 0;
            $manifest_exists = file_exists($manifest_file);
            $manifest_readable = $manifest_exists && is_readable($manifest_file);

            if ($manifest_readable) {
                $raw = ultracache_safe_file_get_contents($manifest_file, 'font pipeline manifest diagnostics');
                $manifest = is_string($raw) && '' !== $raw ? json_decode($raw, true) : array();
                if (is_array($manifest)) {
                    $entries = array();
                    if (!empty($manifest['entry']) && is_array($manifest['entry'])) {
                        $entries[] = $manifest['entry'];
                    }
                    if (!empty($manifest['entries']) && is_array($manifest['entries'])) {
                        foreach ($manifest['entries'] as $entry) {
                            if (is_array($entry)) {
                                $entries[] = $entry;
                            }
                        }
                    }
                    $seen = array();
                    foreach ($entries as $entry) {
                        $key = (string) ($entry['key'] ?? ($entry['url'] ?? ($entry['bundleFile'] ?? md5(wp_json_encode($entry)))));
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $manifest_entries++;
                        $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '';
                        if ('' !== $bundle_file && !is_file($bundle_file)) {
                            $manifest_missing_bundle_files++;
                        }
                        $delayed_blocks = max(0, (int) ($entry['delayedFontFaceBlocks'] ?? 0));
                        $delayed_file = isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '';
                        $delayed_url = isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '';
                        if ($delayed_blocks > 0 || '' !== $delayed_file || '' !== $delayed_url) {
                            $manifest_entries_with_delayed++;
                            $manifest_delayed_blocks += $delayed_blocks;
                            if ('' !== $delayed_file && !is_file($delayed_file)) {
                                $manifest_missing_delayed_files++;
                            }
                            foreach ((array) ($entry['delayedFontFamilies'] ?? array()) as $family) {
                                $family = trim((string) $family);
                                if ('' !== $family) {
                                    $manifest_delayed_families[strtolower($family)] = $family;
                                }
                            }
                        }
                    }
                }
            }

            return array(
                'enabled' => !empty($settings['selfHostedFontCssOptimizationEnabled']) || !empty($settings['googleFontsLocalOptimizationEnabled']) || !empty($settings['delayIconFontsEnabled']),
                'settings' => array(
                    'googleFontsSwapEnabled' => !empty($settings['googleFontsSwapEnabled']),
                    'googleFontsLocalOptimizationEnabled' => !empty($settings['googleFontsLocalOptimizationEnabled']),
                    'selfHostedFontCssOptimizationEnabled' => !empty($settings['selfHostedFontCssOptimizationEnabled']),
                    'selfHostedFontRuntimeRewriteEnabled' => !empty($settings['selfHostedFontRuntimeRewriteEnabled']),
                    'delayIconFontsEnabled' => !empty($settings['delayIconFontsEnabled']),
                    'delayIconFontsAutoDetectEnabled' => !empty($settings['delayIconFontsEnabled']),
                    'cssBundlingEnabled' => !empty($settings['homepageCssBundleEnabled']),
                    'cssBundleScope' => (string) ($settings['cssBundleScope'] ?? ($settings['css_bundle_scope'] ?? 'homepage')),
                ),
                'fontCss' => array(
                    'dirExists' => is_dir($font_css_dir),
                    'files' => (int) $font_css['count'],
                    'bytes' => (int) $font_css['bytes'],
                    'delayedFiles' => (int) $font_css_delayed['count'],
                    'delayedBytes' => (int) $font_css_delayed['bytes'],
                    'latestModified' => (int) max($font_css['latestModified'], $font_css_delayed['latestModified']),
                    'fontFaceBlocks' => (int) ($font_css_source_stats['fontFaceBlocks'] ?? 0),
                    'woff2Sources' => (int) ($font_css_source_stats['woff2Sources'] ?? 0),
                    'woffSources' => (int) ($font_css_source_stats['woffSources'] ?? 0),
                    'ttfSources' => (int) ($font_css_source_stats['ttfSources'] ?? 0),
                    'filesWithTtfSources' => (int) ($font_css_source_stats['filesWithTtfSources'] ?? 0),
                    'filesWithoutFontFace' => (int) ($font_css_source_stats['filesWithoutFontFace'] ?? 0),
                    'nonFontCssFiles' => (int) ($font_css_source_stats['nonFontCssFiles'] ?? 0),
                    'nonFontCssBytes' => (int) ($font_css_source_stats['nonFontCssBytes'] ?? 0),
                    'delayedIconActiveCssFiles' => (int) ($font_css_source_stats['delayedIconActiveCssFiles'] ?? 0),
                    'delayedIconActiveCssBytes' => (int) ($font_css_source_stats['delayedIconActiveCssBytes'] ?? 0),
                    'fontDisplayPatchFiles' => (int) ($font_css_source_stats['fontDisplayPatchFiles'] ?? 0),
                    'fontDisplayPatchBytes' => (int) ($font_css_source_stats['fontDisplayPatchBytes'] ?? 0),
                    'fontDisplayPatchFontFaces' => (int) ($font_css_source_stats['fontDisplayPatchFontFaces'] ?? 0),
                    'fontDisplaySwapDeclarations' => (int) ($font_css_source_stats['fontDisplaySwapDeclarations'] ?? 0),
                    'largestFiles' => isset($font_css_source_stats['largestFiles']) && is_array($font_css_source_stats['largestFiles']) ? $font_css_source_stats['largestFiles'] : array(),
                    'googleImportRules' => (int) ($font_css_source_stats['googleImportRules'] ?? 0),
                    'localGoogleImportRules' => (int) ($font_css_source_stats['localGoogleImportRules'] ?? 0),
                    'remoteGoogleImportRules' => (int) ($font_css_source_stats['remoteGoogleImportRules'] ?? 0),
                    'filesWithGoogleImportRules' => (int) ($font_css_source_stats['filesWithGoogleImportRules'] ?? 0),
                    'cleanupMode' => 'font-face-only-generated-css-skip-mixed-sources-except-delayed-icon-active-css',
                ),
                'optimizedCss' => array(
                    'dirExists' => is_dir($optimized_css_dir),
                    'files' => (int) $optimized_css_files['count'],
                    'bytes' => (int) $optimized_css_files['bytes'],
                    'googleImportRules' => (int) ($optimized_css_source_stats['googleImportRules'] ?? 0),
                    'localGoogleImportRules' => (int) ($optimized_css_source_stats['localGoogleImportRules'] ?? 0),
                    'remoteGoogleImportRules' => (int) ($optimized_css_source_stats['remoteGoogleImportRules'] ?? 0),
                    'filesWithGoogleImportRules' => (int) ($optimized_css_source_stats['filesWithGoogleImportRules'] ?? 0),
                ),
                'cssBundles' => array(
                    'dirExists' => is_dir($css_bundle_dir),
                    'files' => (int) $bundle_css['count'],
                    'bytes' => (int) $bundle_css['bytes'],
                    'delayedFontFiles' => (int) $bundle_delayed['count'],
                    'delayedFontBytes' => (int) $bundle_delayed['bytes'],
                    'manifestExists' => (bool) $manifest_exists,
                    'manifestReadable' => (bool) $manifest_readable,
                    'manifestEntries' => (int) $manifest_entries,
                    'entriesWithDelayedFonts' => (int) $manifest_entries_with_delayed,
                    'delayedFontFaceBlocks' => (int) $manifest_delayed_blocks,
                    'delayedFontFamilies' => array_values(array_slice($manifest_delayed_families, 0, 12, true)),
                    'missingBundleFiles' => (int) $manifest_missing_bundle_files,
                    'missingDelayedFontFiles' => (int) $manifest_missing_delayed_files,
                ),
                'googleFontsLocal' => array(
                    'dirExists' => is_dir($google_fonts_dir),
                    'cssFiles' => (int) $google_font_css['count'],
                    'cssBytes' => (int) $google_font_css['bytes'],
                    'fontFilesOrAssets' => (int) $google_font_files['count'],
                    'fontBytesOrAssetBytes' => (int) $google_font_files['bytes'],
                ),
                'message' => __('Font diagnostics are read-only. They report generated local font CSS, delayed icon-font CSS, and CSS bundle delayed-font metadata.', 'ultracache'),
            );
        }

private static function count_textarea_lines_for_diagnostics($value)
        {
            return count(self::parse_textarea_setting(self::normalize_textarea_setting($value)));
        }

private static function get_settings_transparency_diagnostics($settings = array())
        {
            $settings = is_array($settings) ? $settings : array();
            $defaults = self::get_dashboard_defaults();

            $visible_lists = array(
                array('key' => 'cacheExceptionPaths', 'label' => __('Exclude Paths From Caching', 'ultracache'), 'area' => __('Cache bypass', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'cacheExceptionQueryArgs', 'label' => __('Excluded query-string args from Caching', 'ultracache'), 'area' => __('Cache bypass', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'cacheQueryStringAllowlist', 'label' => __('Query-string args whitelist', 'ultracache'), 'area' => __('Cache query strings', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'deferJsForceList', 'label' => __('Defer Instead of Delay', 'ultracache'), 'area' => __('JavaScript', 'ultracache'), 'kind' => __('Speed-first textarea', 'ultracache'), 'shared' => false),
                array('key' => 'deferJsExcludeList', 'label' => __('Do Not Defer or Delay', 'ultracache'), 'area' => __('JavaScript', 'ultracache'), 'kind' => __('Compatibility exclusion textarea', 'ultracache'), 'shared' => true),
                array('key' => 'delaySafeThirdPartyJsPatterns', 'label' => __('Delay third-party JS patterns', 'ultracache'), 'area' => __('JavaScript', 'ultracache'), 'kind' => __('Pattern list', 'ultracache'), 'shared' => false),
                array('key' => 'delayFunctionalThirdPartyJsPatterns', 'label' => __('Known functional third-party delay patterns', 'ultracache'), 'area' => __('JavaScript', 'ultracache'), 'kind' => __('Pattern list', 'ultracache'), 'shared' => false),
                array('key' => 'criticalRequestChainDelayList', 'label' => __('Delay Non-Critical Request Chains', 'ultracache'), 'area' => __('JavaScript/CSS', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'criticalResourcePreloadList', 'label' => __('Priority Preloads', 'ultracache'), 'area' => __('Critical chain', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => true),
                array('key' => 'homepageCssBundleExcludeList', 'label' => __('CSS Bundle Exclusions', 'ultracache'), 'area' => __('CSS bundles', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'asyncCssExcludeList', 'label' => __('Async CSS Exclude List', 'ultracache'), 'area' => __('CSS async', 'ultracache'), 'kind' => __('Shared final override', 'ultracache'), 'shared' => true),
                array('key' => 'asyncExternalCssExcludeList', 'label' => __('Never async these external CSS URLs / patterns', 'ultracache'), 'area' => __('External CSS async', 'ultracache'), 'kind' => __('Pattern list', 'ultracache'), 'shared' => false),
                array('key' => 'assetCleanupExcludeList', 'label' => __('Asset Cleanup Exclusions', 'ultracache'), 'area' => __('Asset cleanup', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
                array('key' => 'delayIconFontsList', 'label' => __('Delay These Fonts / Icon Font', 'ultracache'), 'area' => __('Fonts', 'ultracache'), 'kind' => __('Pattern list', 'ultracache'), 'shared' => false),
                array('key' => 'delayIconFontsExcludeList', 'label' => __('Never Delay These Fonts / Patterns', 'ultracache'), 'area' => __('Fonts', 'ultracache'), 'kind' => __('Pattern list', 'ultracache'), 'shared' => false),
                array('key' => 'manualLcpHeroSelector', 'label' => __('Manual LCP selector', 'ultracache'), 'area' => __('LCP', 'ultracache'), 'kind' => __('Image URL / CSS selector list', 'ultracache'), 'shared' => true),
                array('key' => 'googleFontsAdditionalScanUrls', 'label' => __('Additional Google Fonts Scan URLs', 'ultracache'), 'area' => __('Fonts', 'ultracache'), 'kind' => __('Textarea', 'ultracache'), 'shared' => false),
            );

            $list_rows = array();
            $with_defaults = 0;
            $with_current = 0;
            foreach ($visible_lists as $entry) {
                $key = (string) $entry['key'];
                $default_value = array_key_exists($key, $defaults) ? $defaults[$key] : '';
                $current_value = array_key_exists($key, $settings) ? $settings[$key] : '';
                $default_count = self::count_textarea_lines_for_diagnostics($default_value);
                $current_count = self::count_textarea_lines_for_diagnostics($current_value);
                if ($default_count > 0) {
                    $with_defaults++;
                }
                if ($current_count > 0) {
                    $with_current++;
                }
                $list_rows[] = array(
                    'key' => $key,
                    'label' => (string) $entry['label'],
                    'area' => (string) $entry['area'],
                    'kind' => (string) $entry['kind'],
                    'shared' => !empty($entry['shared']),
                    'visible' => true,
                    'editable' => true,
                    'resetRestoresDefault' => array_key_exists($key, $defaults),
                    'populateDefaultAvailable' => $default_count > 0,
                    'defaultCount' => (int) $default_count,
                    'currentCount' => (int) $current_count,
                );
            }

            $engine_only_safeguards = array(
                array('label' => __('Absolute JS dependency floor', 'ultracache'), 'area' => __('JavaScript', 'ultracache'), 'editable' => false, 'reason' => __('Core WordPress/jQuery globals stay protected to avoid site-wide runtime failures.', 'ultracache'), 'examples' => array('jquery', 'jquery-migrate', 'wp-i18n', 'wp-hooks', 'wp-util', 'api-fetch', 'underscore')),
                array('label' => __('Admin/internal paths never cached', 'ultracache'), 'area' => __('Cache bypass', 'ultracache'), 'editable' => false, 'reason' => __('WordPress admin/login/API flows must remain uncached even if the visible path list is edited.', 'ultracache'), 'examples' => array_values(array_filter(array(function_exists('ultracache_wordpress_admin_public_path') ? ultracache_wordpress_admin_public_path() : '', '/wp-login.php', '/wp-json/')))),
                array('label' => __('Logged-in and personalized requests bypass', 'ultracache'), 'area' => __('Cache poisoning protection', 'ultracache'), 'editable' => false, 'reason' => __('User cookies, cart/checkout/account flows, and unsafe methods must not be page-cached.', 'ultracache'), 'examples' => array('logged-in cookies', 'POST', 'cart', 'checkout', 'account')),
                array('label' => __('CSS bundle stale-ref protection', 'ultracache'), 'area' => __('CSS bundles', 'ultracache'), 'editable' => false, 'reason' => __('Main bundle files and delayed-font companion files are retained/validated to protect stale proxy HTML.', 'ultracache'), 'examples' => array('48h bundle grace period', 'delayed-font pair lifecycle', 'missing bundle invalidation')),
                array('label' => __('Varnish endpoint safety validation', 'ultracache'), 'area' => __('Reverse proxy', 'ultracache'), 'editable' => false, 'reason' => __('Obvious public frontend endpoints are blocked while explicitly configured Varnish infrastructure endpoints remain supported.', 'ultracache'), 'examples' => array('external Varnish infrastructure allowed', 'public frontend :80/:443 blocked')),
            );

            $legacy_lists = array(
                array('key' => 'delayNonCriticalJsExcludeList', 'label' => __('Legacy Delay Non-Critical JS Exclusions', 'ultracache'), 'mappedTo' => 'deferJsExcludeList', 'active' => false, 'message' => __('Legacy values are merged into the visible Do Not Defer or Delay field and then cleared.', 'ultracache')),
            );

            return array(
                'enabled' => true,
                'visibleLists' => $list_rows,
                'engineOnlySafeguards' => $engine_only_safeguards,
                'legacyLists' => $legacy_lists,
                'summary' => array(
                    'visibleEditableLists' => count($list_rows),
                    'listsWithDefaults' => (int) $with_defaults,
                    'listsWithCurrentValues' => (int) $with_current,
                    'engineOnlySafeguards' => count($engine_only_safeguards),
                    'legacyLists' => count($legacy_lists),
                    'resetUsesDashboardDefaults' => true,
                ),
                'message' => __('Settings transparency diagnostics are read-only. User-editable safeguards are listed separately from engine-only safety floors.', 'ultracache'),
            );
        }

private static function get_storage_cleanup_dashboard_setting($key, $default)
        {
            $settings = method_exists('Ultra_Cache_WP', 'get_dashboard_settings') ? self::get_dashboard_settings() : array();
            return isset($settings[$key]) ? $settings[$key] : $default;
        }

private static function get_storage_cleanup_grace_seconds()
        {
            $hours = (int) self::get_storage_cleanup_dashboard_setting('cssBundleCleanupGraceHours', 48);
            $seconds = max(1, min(168, $hours)) * HOUR_IN_SECONDS;
            $seconds = (int) apply_filters('ultracache_css_bundle_cleanup_grace_seconds', $seconds);
            return max(HOUR_IN_SECONDS, min(WEEK_IN_SECONDS, $seconds));
        }

private static function get_storage_cleanup_max_deletes_per_run()
        {
            $max = (int) self::get_storage_cleanup_dashboard_setting('cssBundleCleanupDeleteLimit', 60);
            $max = (int) apply_filters('ultracache_css_bundle_cleanup_max_deletes_per_run', $max);
            return max(5, min(500, $max));
        }

private static function format_storage_duration_seconds_for_diagnostics($seconds)
        {
            $seconds = max(0, (int) $seconds);
            if ($seconds <= 0) {
                return '0 seconds';
            }

            if (0 === $seconds % DAY_IN_SECONDS) {
                $days = (int) ($seconds / DAY_IN_SECONDS);
                return sprintf('%d day%s', $days, 1 === $days ? '' : 's');
            }

            if (0 === $seconds % HOUR_IN_SECONDS) {
                $hours = (int) ($seconds / HOUR_IN_SECONDS);
                return sprintf('%d hour%s', $hours, 1 === $hours ? '' : 's');
            }

            if (0 === $seconds % MINUTE_IN_SECONDS) {
                $minutes = (int) ($seconds / MINUTE_IN_SECONDS);
                return sprintf('%d minute%s', $minutes, 1 === $minutes ? '' : 's');
            }

            return sprintf('%d seconds', $seconds);
        }

private static function scan_storage_path_for_diagnostics($path, array $args = array())
        {
            $path = is_string($path) ? wp_normalize_path($path) : '';
            $defaults = array(
                'recursive' => true,
                'maxFiles' => 5000,
                'includeExtensions' => array(),
                'excludePathContains' => array(),
            );
            $args = array_merge($defaults, $args);
            $max_files = max(100, min(50000, (int) $args['maxFiles']));
            $include_extensions = array();
            foreach ((array) $args['includeExtensions'] as $ext) {
                $ext = strtolower(ltrim((string) $ext, '.'));
                if ('' !== $ext) {
                    $include_extensions[$ext] = true;
                }
            }
            $exclude_contains = array();
            foreach ((array) $args['excludePathContains'] as $needle) {
                $needle = wp_normalize_path((string) $needle);
                if ('' !== $needle) {
                    $exclude_contains[] = $needle;
                }
            }

            $summary = array(
                'path' => $path,
                'exists' => '' !== $path && is_dir($path),
                'readable' => '' !== $path && is_readable($path),
                'files' => 0,
                'dirs' => 0,
                'bytes' => 0,
                'latestModified' => 0,
                'truncated' => false,
                'maxFiles' => $max_files,
                'error' => '',
            );

            if (empty($summary['exists']) || empty($summary['readable'])) {
                return $summary;
            }

            try {
                if (!empty($args['recursive'])) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                } else {
                    $iterator = new DirectoryIterator($path);
                }

                foreach ($iterator as $file_info) {
                    if (!$file_info instanceof SplFileInfo) {
                        continue;
                    }
                    $pathname = wp_normalize_path((string) $file_info->getPathname());
                    if ('' === $pathname) {
                        continue;
                    }

                    $excluded = false;
                    foreach ($exclude_contains as $needle) {
                        if ('' !== $needle && false !== strpos($pathname, $needle)) {
                            $excluded = true;
                            break;
                        }
                    }
                    if ($excluded) {
                        continue;
                    }

                    if ($file_info->isDir()) {
                        $summary['dirs']++;
                        continue;
                    }

                    if (!$file_info->isFile()) {
                        continue;
                    }

                    if (!empty($include_extensions)) {
                        $ext = strtolower(pathinfo($pathname, PATHINFO_EXTENSION));
                        if (!isset($include_extensions[$ext])) {
                            continue;
                        }
                    }

                    $summary['files']++;
                    $summary['bytes'] += max(0, (int) $file_info->getSize());
                    $mtime = (int) $file_info->getMTime();
                    if ($mtime > $summary['latestModified']) {
                        $summary['latestModified'] = $mtime;
                    }

                    if ($summary['files'] >= $max_files) {
                        $summary['truncated'] = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                $summary['error'] = (string) $e->getMessage();
            }

            return $summary;
        }

private static function extract_css_bundle_ref_basenames_from_html_for_diagnostics($html)
        {
            $html = (string) $html;
            $refs = array();
            $marker = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path('css-bundles') : '';
            if ('' === $html || '' === $marker || false === stripos($html, trim($marker, '/'))) {
                return $refs;
            }

            $marker_pattern = preg_quote(trailingslashit($marker), '~');
            preg_match_all('~(?:https?:)?//[^\s"\'<>]+' . $marker_pattern . '[^\s"\'<>?#)]+\.css~i', $html, $absolute_matches);
            preg_match_all('~' . $marker_pattern . '[^\s"\'<>?#)]+\.css~i', $html, $path_matches);

            $matches = array_merge(
                isset($absolute_matches[0]) && is_array($absolute_matches[0]) ? $absolute_matches[0] : array(),
                isset($path_matches[0]) && is_array($path_matches[0]) ? $path_matches[0] : array()
            );

            foreach (array_unique(array_map('strval', $matches)) as $ref) {
                $path = (string) wp_parse_url($ref, PHP_URL_PATH);
                if ('' === $path) {
                    $path = $ref;
                }

                $basename = basename(rawurldecode($path));
                if ('' === $basename || !preg_match('/^bundle-[A-Za-z0-9_.-]+\.css$/', $basename)) {
                    continue;
                }

                $refs[$basename] = true;
                if (preg_match('/-delayed-fonts\.css$/i', $basename)) {
                    $pair = (string) preg_replace('/-delayed-fonts\.css$/i', '.css', $basename);
                    if ('' !== $pair) {
                        $refs[$pair] = true;
                    }
                } else {
                    $companion = (string) preg_replace('/\.css$/i', '-delayed-fonts.css', $basename);
                    if ('' !== $companion) {
                        $refs[$companion] = true;
                    }
                }
            }

            return $refs;
        }

private static function scan_cached_html_css_bundle_refs_for_diagnostics($cache_dir, $max_files = 800)
        {
            $cache_dir = trailingslashit(wp_normalize_path((string) $cache_dir));
            $max_files = max(100, min(10000, (int) $max_files));
            $summary = array(
                'refs' => array(),
                'filesScanned' => 0,
                'filesWithRefs' => 0,
                'truncated' => false,
                'maxFiles' => $max_files,
                'error' => '',
                'timedOut' => false,
            );
            $deadline = microtime(true) + 1.5;

            if (!is_dir($cache_dir) || !is_readable($cache_dir)) {
                return $summary;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cache_dir, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file_info) {
                    if (microtime(true) >= $deadline) {
                        $summary['truncated'] = true;
                        $summary['timedOut'] = true;
                        break;
                    }
                    if (!$file_info->isFile()) {
                        continue;
                    }

                    $path = wp_normalize_path((string) $file_info->getPathname());
                    $name = strtolower((string) $file_info->getFilename());

                    if (false !== strpos($path, '/css-bundles/') || false !== strpos($path, '/google-fonts/') || false !== strpos($path, '/font-css/') || false !== strpos($path, '/optimized-css/') || false !== strpos($path, '/diagnostics/') || false !== strpos($path, '/locks/')) {
                        continue;
                    }

                    if (!preg_match('/\.html$/i', $name)) {
                        continue;
                    }

                    $summary['filesScanned']++;
                    $size = (int) $file_info->getSize();
                    if ($size <= 0 || $size > 2097152) {
                        if ($summary['filesScanned'] >= $max_files) {
                            $summary['truncated'] = true;
                            break;
                        }
                        continue;
                    }

                    $html = ultracache_safe_file_get_contents($path, 'css bundle cached html ref diagnostics scan');
                    if (!is_string($html) || !ultracache_generated_asset_reference_matches($html, array('css-bundles'))) {
                        if ($summary['filesScanned'] >= $max_files) {
                            $summary['truncated'] = true;
                            break;
                        }
                        continue;
                    }

                    $refs = self::extract_css_bundle_ref_basenames_from_html_for_diagnostics($html);
                    if (!empty($refs)) {
                        $summary['filesWithRefs']++;
                        foreach ($refs as $basename => $enabled) {
                            if (!empty($enabled)) {
                                $summary['refs'][$basename] = true;
                            }
                        }
                    }

                    if ($summary['filesScanned'] >= $max_files) {
                        $summary['truncated'] = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                $summary['error'] = (string) $e->getMessage();
            }

            return $summary;
        }

private static function analyze_css_bundle_storage_for_diagnostics($css_bundle_dir, array $manifest_active_files = array(), $cache_dir = '')
        {
            $css_bundle_dir = trailingslashit(wp_normalize_path((string) $css_bundle_dir));
            $grace_seconds = self::get_storage_cleanup_grace_seconds();
            $delete_limit = self::get_storage_cleanup_max_deletes_per_run();
            $now = time();
            $active = array();
            foreach ($manifest_active_files as $file) {
                $file = wp_normalize_path((string) $file);
                if ('' !== $file) {
                    $active[basename($file)] = true;
                }
            }

            $cache_dir = '' !== (string) $cache_dir ? (string) $cache_dir : dirname($css_bundle_dir);
            $cached_html_refs = self::scan_cached_html_css_bundle_refs_for_diagnostics($cache_dir);
            $cached_html_ref_files = isset($cached_html_refs['refs']) && is_array($cached_html_refs['refs']) ? $cached_html_refs['refs'] : array();

            $summary = array(
                'dirExists' => is_dir($css_bundle_dir),
                'graceSeconds' => $grace_seconds,
                'graceSecondsLabel' => self::format_storage_duration_seconds_for_diagnostics($grace_seconds),
                'cleanupDeleteLimit' => $delete_limit,
                'cleanupDeleteLimitLabel' => sprintf('%d files per cleanup run', $delete_limit),
                'cleanupPolicySource' => 'dashboard/filter',
                'cleanupGraceFilter' => 'ultracache_css_bundle_cleanup_grace_seconds',
                'cleanupDeleteLimitFilter' => 'ultracache_css_bundle_cleanup_max_deletes_per_run',
                'cleanupGraceDefaultSeconds' => 172800,
                'cleanupGraceDefaultHours' => 48,
                'cleanupGraceDefaultLabel' => self::format_storage_duration_seconds_for_diagnostics(172800),
                'cleanupGraceMinSeconds' => 3600,
                'cleanupGraceMinHours' => 1,
                'cleanupGraceMinLabel' => self::format_storage_duration_seconds_for_diagnostics(3600),
                'cleanupGraceMaxSeconds' => 604800,
                'cleanupGraceMaxHours' => 168,
                'cleanupGraceMaxLabel' => self::format_storage_duration_seconds_for_diagnostics(604800),
                'cleanupDeleteLimitDefault' => 60,
                'cleanupDeleteLimitMin' => 5,
                'cleanupDeleteLimitMax' => 500,
                'dashboardEditable' => true,
                'cleanupPolicyMessage' => '',
                'recentProtectedMessage' => '',
                'oldEligibleMessage' => '',
                'files' => 0,
                'bytes' => 0,
                'allDirectoryFiles' => 0,
                'recognizedBundleFiles' => 0,
                'totalFiles' => 0,
                'totalBytes' => 0,
                'mainBundleFiles' => 0,
                'mainBundleBytes' => 0,
                'delayedFontFiles' => 0,
                'delayedFontBytes' => 0,
                'leftoverFiles' => 0,
                'safeFiles' => 0,
                'aggressiveFiles' => 0,
                'fullFiles' => 0,
                'recentFiles' => 0,
                'oldFiles' => 0,
                'activeManifestFiles' => count($active),
                'cachedHtmlRefFiles' => count($cached_html_ref_files),
                'cachedHtmlRefFilesScanned' => max(0, (int) ($cached_html_refs['filesScanned'] ?? 0)),
                'cachedHtmlRefFilesWithRefs' => max(0, (int) ($cached_html_refs['filesWithRefs'] ?? 0)),
                'cachedHtmlRefScanLimit' => max(0, (int) ($cached_html_refs['maxFiles'] ?? 0)),
                'cachedHtmlRefScanTruncated' => !empty($cached_html_refs['truncated']),
                'cachedHtmlRefScanTimedOut' => !empty($cached_html_refs['timedOut']),
                'cachedHtmlRefScanError' => isset($cached_html_refs['error']) ? (string) $cached_html_refs['error'] : '',
                'orphanLikeFiles' => 0,
                'oldOrphanLikeFiles' => 0,
                'recentOrphanLikeFiles' => 0,
                'protectedByCachedHtmlRefs' => 0,
                'oldProtectedByCachedHtmlRefs' => 0,
                'recentProtectedByCachedHtmlRefs' => 0,
                'largestFiles' => array(),
                'warningLevel' => 'ok',
                'message' => '',
            );

            if (!is_dir($css_bundle_dir) || !is_readable($css_bundle_dir)) {
                $summary['warningLevel'] = 'notice';
                $summary['message'] = self::maybe_translate('CSS bundle directory does not exist or is not readable yet.');
                return $summary;
            }

            foreach ((array) glob($css_bundle_dir . '*.css') as $file) {
                if (is_string($file) && is_file($file)) {
                    $summary['allDirectoryFiles']++;
                }
            }

            $largest = array();
            foreach ((array) glob($css_bundle_dir . 'bundle-*.css') as $file) {
                $file = (string) $file;
                if ('' === $file || !is_file($file)) {
                    continue;
                }

                $summary['totalFiles']++;
                $summary['recognizedBundleFiles']++;
                $summary['files']++;
                $basename = basename($file);
                $size = ultracache_safe_filesize($file, 'css_bundle_storage_diagnostic');
                $size = false !== $size ? max(0, (int) $size) : 0;
                $summary['totalBytes'] += $size;
                $summary['bytes'] += $size;
                $is_delayed = (bool) preg_match('/-delayed-fonts\.css$/i', $basename);
                if ($is_delayed) {
                    $summary['delayedFontFiles']++;
                    $summary['delayedFontBytes'] += $size;
                } else {
                    $summary['mainBundleFiles']++;
                    $summary['mainBundleBytes'] += $size;
                }
                if (0 === strpos($basename, 'bundle-leftover-')) {
                    $summary['leftoverFiles']++;
                } elseif (0 === strpos($basename, 'bundle-safe-')) {
                    $summary['safeFiles']++;
                } elseif (0 === strpos($basename, 'bundle-aggressive-')) {
                    $summary['aggressiveFiles']++;
                } elseif (0 === strpos($basename, 'bundle-full-')) {
                    $summary['fullFiles']++;
                }

                $mtime = (int) ultracache_safe_filemtime($file, 'css_bundle_storage_diagnostic');
                $recent = ($mtime <= 0) || (($now - $mtime) < $grace_seconds);
                if ($recent) {
                    $summary['recentFiles']++;
                } else {
                    $summary['oldFiles']++;
                }

                $pair = (string) preg_replace('/-delayed-fonts\.css$/i', '.css', $basename);
                $companion = preg_match('/-delayed-fonts\.css$/i', $basename)
                    ? $pair
                    : (string) preg_replace('/\.css$/i', '-delayed-fonts.css', $basename);
                $is_active = isset($active[$basename]) || ('' !== $pair && isset($active[$pair]));
                $is_referenced_by_cached_html = isset($cached_html_ref_files[$basename]) || ('' !== $pair && isset($cached_html_ref_files[$pair])) || ('' !== $companion && isset($cached_html_ref_files[$companion]));
                if (!$is_active) {
                    $summary['orphanLikeFiles']++;
                    if ($is_referenced_by_cached_html) {
                        $summary['protectedByCachedHtmlRefs']++;
                        if ($recent) {
                            $summary['recentProtectedByCachedHtmlRefs']++;
                        } else {
                            $summary['oldProtectedByCachedHtmlRefs']++;
                        }
                    } elseif ($recent) {
                        $summary['recentOrphanLikeFiles']++;
                    } else {
                        $summary['oldOrphanLikeFiles']++;
                    }
                }

                $largest[] = array(
                    'name' => $basename,
                    'bytes' => $size,
                    'modified' => $mtime,
                );
            }

            usort($largest, static function ($a, $b) {
                return (int) ($b['bytes'] ?? 0) <=> (int) ($a['bytes'] ?? 0);
            });
            $summary['largestFiles'] = array_slice($largest, 0, 5);

            $summary['cleanupPolicyMessage'] = sprintf(
                'Cleanup keeps orphan-like CSS bundle files for %s before deletion, protects files still referenced by cached HTML, and removes at most %d file(s) per run. These values come from Automation & Scheduling settings and can still be overridden by filters.',
                $summary['graceSecondsLabel'],
                (int) $delete_limit
            );
            if ($summary['recentOrphanLikeFiles'] > 0) {
                $summary['recentProtectedMessage'] = sprintf(
                    '%s orphan-like CSS bundle file(s) are protected by the %s cleanup grace window and are not eligible yet.',
                    number_format_i18n((int) $summary['recentOrphanLikeFiles']),
                    $summary['graceSecondsLabel']
                );
            }
            if ($summary['oldOrphanLikeFiles'] > 0) {
                $summary['oldEligibleMessage'] = sprintf(
                    '%s orphan-like CSS bundle file(s) are older than the grace window and eligible for scheduled cleanup, limited to %d file(s) per run.',
                    number_format_i18n((int) $summary['oldOrphanLikeFiles']),
                    (int) $delete_limit
                );
            }
            if ($summary['protectedByCachedHtmlRefs'] > 0) {
                $summary['cachedHtmlProtectedMessage'] = sprintf(
                    '%s orphan-like CSS bundle file(s) are protected because cached HTML still references them.',
                    number_format_i18n((int) $summary['protectedByCachedHtmlRefs'])
                );
            } else {
                $summary['cachedHtmlProtectedMessage'] = '';
            }

            if ($summary['totalFiles'] >= 500 || $summary['oldOrphanLikeFiles'] >= 120) {
                $summary['warningLevel'] = 'warning';
                $summary['message'] = self::maybe_translate('CSS bundle storage is high. Cleanup removes only orphan-like files older than the grace window and is limited per run for safety.');
            } elseif ($summary['totalFiles'] >= 150 || $summary['recentOrphanLikeFiles'] >= 50) {
                $summary['warningLevel'] = 'notice';
                $summary['message'] = self::maybe_translate('Many CSS bundle files are present. Recent orphan-like files are protected by the cleanup grace window and are not eligible yet.');
            } else {
                $summary['warningLevel'] = 'ok';
                $summary['message'] = self::maybe_translate('CSS bundle storage is within the expected range.');
            }

            return $summary;
        }

private static function get_cache_storage_diagnostics_state_name()
        {
            return 'ultracache_state:dashboard.storage_diagnostics';
        }

private static function get_cache_storage_diagnostics_fingerprint()
        {
            $payload = array(
                'contract' => 1,
                'cacheDir' => ultracache_content_cache_storage_dir(),
                'cssBundleDir' => ultracache_generated_asset_dir('css-bundles'),
                'objectDir' => ultracache_object_cache_storage_dir(),
                'avifDir' => ultracache_optimized_images_storage_dir('avif'),
                'webpDir' => ultracache_optimized_images_storage_dir('webp'),
            );
            return hash('sha256', (string) wp_json_encode($payload));
        }

private static function get_cache_storage_diagnostics($settings = array(), $css_summary = null, $force_refresh = false)
        {
            $cache_dir = ultracache_content_cache_storage_dir();
            $css_bundle_dir = ultracache_generated_asset_dir('css-bundles');
            $object_dir = ultracache_object_cache_storage_dir();
            $avif_dir = ultracache_optimized_images_storage_dir('avif');
            $webp_dir = ultracache_optimized_images_storage_dir('webp');
            $state_name = self::get_cache_storage_diagnostics_state_name();
            $fingerprint = self::get_cache_storage_diagnostics_fingerprint();

            if (!$force_refresh) {
                $record = function_exists('ultracache_get_state_record_read_only')
                    ? ultracache_get_state_record_read_only($state_name)
                    : array();
                $stored = isset($record['payload']) && is_array($record['payload']) ? $record['payload'] : array();
                if (isset($stored['total']) && is_array($stored['total'])) {
                    $scanned_at = max(0, (int) ($stored['scannedAt'] ?? ($record['updatedAt'] ?? 0)));
                    $configuration_changed = !hash_equals((string) ($stored['fingerprint'] ?? ''), $fingerprint);
                    $stale = $scanned_at > 0 && (time() - $scanned_at) > DAY_IN_SECONDS;
                    $stored['cached'] = true;
                    $stored['persistent'] = true;
                    $stored['scanSkipped'] = true;
                    $stored['configurationChanged'] = $configuration_changed;
                    $stored['stale'] = $stale;
                    $stored['state'] = $configuration_changed ? 'configuration-changed' : ($stale ? 'stale' : 'current');
                    if ($configuration_changed) {
                        $stored['message'] = self::maybe_translate('Storage paths or the diagnostics contract changed. The persisted scan remains visible as historical evidence; refresh storage diagnostics before treating it as current.');
                    } elseif ($stale) {
                        $stored['message'] = self::maybe_translate('Storage diagnostics are persistent but older than 24 hours. Refresh storage diagnostics for current bounded counts.');
                    } else {
                        $stored['message'] = self::maybe_translate('Storage diagnostics are loaded from persistent state. Use Refresh storage diagnostics for a new capped filesystem scan.');
                    }
                    return $stored;
                }

                return array(
                    'enabled' => true,
                    'warningLevel' => 'notice',
                    'warnings' => array(self::maybe_translate('Storage diagnostics are passive until manually refreshed; normal dashboard refresh does not scan cache/media directories.')),
                    'scanLimit' => 0,
                    'cached' => false,
                    'persistent' => true,
                    'scanSkipped' => true,
                    'scannedAt' => 0,
                    'computedAt' => 0,
                    'state' => 'not-measured',
                    'message' => self::maybe_translate('Use Refresh storage diagnostics to create the persistent capped filesystem snapshot.'),
                    'total' => array('files' => 0, 'bytes' => 0, 'truncated' => false),
                    'pageCache' => array('files' => 0, 'bytes' => 0, 'truncated' => false),
                    'cacheRoot' => array('files' => 0, 'bytes' => 0, 'truncated' => false),
                    'cssBundles' => array('files' => 0, 'bytes' => 0, 'recognizedBundleFiles' => 0, 'warningLevel' => 'notice', 'message' => self::maybe_translate('CSS bundle storage has not been scanned yet.')),
                    'objectCacheDisk' => array('files' => 0, 'bytes' => 0, 'truncated' => false, 'exists' => is_dir($object_dir)),
                    'mediaCache' => array(
                        'storageRoot' => 'uploads/ultracache/images',
                        'persistent' => true,
                        'message' => self::maybe_translate('Generated media storage has not been scanned yet. Use Refresh storage diagnostics.'),
                        'files' => 0,
                        'bytes' => 0,
                        'avifFiles' => 0,
                        'avifBytes' => 0,
                        'avifTruncated' => false,
                        'webpFiles' => 0,
                        'webpBytes' => 0,
                        'webpTruncated' => false,
                        'truncated' => false,
                    ),
                );
            }

            $css_summary = is_array($css_summary) ? $css_summary : self::get_css_bundle_summary_diagnostics(is_array($settings) ? $settings : array());
            $manifest = isset($css_summary['manifest']) && is_array($css_summary['manifest']) ? $css_summary['manifest'] : array();
            $active_files = array();
            foreach ((array) ($manifest['bundleFiles'] ?? array()) as $file) {
                $active_files[] = (string) $file;
            }
            foreach ((array) ($manifest['delayedFontFiles'] ?? array()) as $file) {
                $active_files[] = (string) $file;
            }

            $css_storage = self::analyze_css_bundle_storage_for_diagnostics($css_bundle_dir, $active_files, $cache_dir);
            $page_cache = self::scan_storage_path_for_diagnostics($cache_dir, array(
                'recursive' => true,
                'maxFiles' => 8000,
                'includeExtensions' => array('html', 'gz', 'br'),
                'excludePathContains' => array('/css-bundles/', '/google-fonts/', '/font-css/', '/optimized-css/'),
            ));
            $cache_root = self::scan_storage_path_for_diagnostics($cache_dir, array('recursive' => true, 'maxFiles' => 8000));
            $object_cache = self::scan_storage_path_for_diagnostics($object_dir, array('recursive' => true, 'maxFiles' => 8000));
            $avif = self::scan_storage_path_for_diagnostics($avif_dir, array('recursive' => true, 'maxFiles' => 8000));
            $webp = self::scan_storage_path_for_diagnostics($webp_dir, array('recursive' => true, 'maxFiles' => 8000));

            $css_files = isset($css_summary['files']) && is_array($css_summary['files']) ? $css_summary['files'] : array();
            $css_recognized_files = max(0, (int) ($css_storage['recognizedBundleFiles'] ?? ($css_storage['totalFiles'] ?? 0)));
            $css_bytes = max(0, (int) ($css_storage['totalBytes'] ?? ($css_storage['bytes'] ?? 0)));
            $total_files = (int) $cache_root['files'] + (int) $object_cache['files'] + (int) $avif['files'] + (int) $webp['files'];
            $total_bytes = (int) $cache_root['bytes'] + (int) $object_cache['bytes'] + (int) $avif['bytes'] + (int) $webp['bytes'];

            $warnings = array();
            if (!empty($cache_root['truncated']) || !empty($object_cache['truncated']) || !empty($avif['truncated']) || !empty($webp['truncated'])) {
                $warnings[] = self::maybe_translate('Storage scan was capped to avoid expensive filesystem reads. Counts are minimum values.');
            }
            if ($total_files >= 10000) {
                $warnings[] = self::maybe_translate('UltraCache file count is high. This may matter on inode-limited hosting.');
            }
            if ($total_bytes >= 536870912) {
                $warnings[] = self::maybe_translate('UltraCache storage is above 512 MB. Review generated cache/media files and cleanup cadence.');
            }
            if (isset($css_storage['warningLevel']) && 'ok' !== $css_storage['warningLevel'] && !empty($css_storage['message'])) {
                $warnings[] = (string) $css_storage['message'];
            }

            $warning_level = 'ok';
            if ($total_files >= 20000 || $total_bytes >= 1073741824 || (isset($css_storage['warningLevel']) && 'warning' === $css_storage['warningLevel'])) {
                $warning_level = 'warning';
            } elseif (!empty($warnings)) {
                $warning_level = 'notice';
            }

            $diagnostics = array(
                'enabled' => true,
                'warningLevel' => $warning_level,
                'warnings' => array_values(array_unique($warnings)),
                'scanLimit' => 8000,
                'cached' => false,
                'scanSkipped' => false,
                'scannedAt' => time(),
                'message' => self::maybe_translate('Storage diagnostics were refreshed with capped filesystem scans.'),
                'total' => array(
                    'files' => $total_files,
                    'bytes' => $total_bytes,
                    'truncated' => !empty($cache_root['truncated']) || !empty($object_cache['truncated']) || !empty($avif['truncated']) || !empty($webp['truncated']),
                ),
                'pageCache' => array(
                    'files' => (int) $page_cache['files'],
                    'bytes' => (int) $page_cache['bytes'],
                    'truncated' => !empty($page_cache['truncated']),
                ),
                'cacheRoot' => array(
                    'files' => (int) $cache_root['files'],
                    'bytes' => (int) $cache_root['bytes'],
                    'truncated' => !empty($cache_root['truncated']),
                ),
                'cssBundles' => array_merge(
                    $css_storage,
                    array(
                        'files' => $css_recognized_files,
                        'bytes' => $css_bytes,
                        'recognizedBundleFiles' => $css_recognized_files,
                        'legacySummaryFiles' => max(0, (int) ($css_files['bundleFiles'] ?? 0)) + max(0, (int) ($css_files['delayedFontFiles'] ?? 0)),
                        'legacySummaryNote' => self::maybe_translate('Legacy summary counts delayed-font CSS separately; storage diagnostics uses recognized bundle files.'),
                    )
                ),
                'objectCacheDisk' => array(
                    'files' => (int) $object_cache['files'],
                    'bytes' => (int) $object_cache['bytes'],
                    'truncated' => !empty($object_cache['truncated']),
                    'exists' => !empty($object_cache['exists']),
                ),
                'mediaCache' => array(
                    'storageRoot' => 'uploads/ultracache/images',
                    'persistent' => true,
                    'message' => self::maybe_translate('Optimized AVIF/WebP media is stored under uploads/ultracache/images so normal cache cleanup does not remove persistent generated image assets.'),
                    'files' => (int) $avif['files'] + (int) $webp['files'],
                    'bytes' => (int) $avif['bytes'] + (int) $webp['bytes'],
                    'avifFiles' => (int) $avif['files'],
                    'avifBytes' => (int) $avif['bytes'],
                    'avifTruncated' => !empty($avif['truncated']),
                    'webpFiles' => (int) $webp['files'],
                    'webpBytes' => (int) $webp['bytes'],
                    'webpTruncated' => !empty($webp['truncated']),
                    'truncated' => !empty($avif['truncated']) || !empty($webp['truncated']),
                ),
            );

            $diagnostics['fingerprint'] = $fingerprint;
            $diagnostics['state'] = 'current';
            $diagnostics['persistent'] = true;
            $diagnostics['computedAt'] = max(0, (int) ($diagnostics['scannedAt'] ?? time()));

            if (function_exists('ultracache_mutate_state_record')) {
                $mutation = ultracache_mutate_state_record(
                    $state_name,
                    static function () use ($diagnostics) {
                        return $diagnostics;
                    },
                    3,
                    $diagnostics
                );
                if (empty($mutation['success'])) {
                    $diagnostics['persistent'] = false;
                    $diagnostics['persistenceError'] = (string) ($mutation['reason'] ?? 'write_failed');
                }
            } else {
                $diagnostics['persistent'] = false;
                $diagnostics['persistenceError'] = 'state_storage_unavailable';
            }

            return $diagnostics;
        }

private static function get_css_bundle_summary_diagnostics($settings = array())
        {
            $settings = is_array($settings) ? $settings : array();
            $css_bundle_dir = ultracache_generated_asset_dir('css-bundles');
            $manifest_file = ultracache_generated_asset_dir('css-bundles', 'manifest.json');
            $last = get_option('ultracache_last_css_bundle_summary', array());
            if (!is_array($last)) {
                $last = array();
            }

            $count_files = static function ($pattern) {
                $files = glob($pattern);
                if (!is_array($files)) {
                    return array('count' => 0, 'bytes' => 0, 'latestModified' => 0);
                }
                $count = 0;
                $bytes = 0;
                $latest = 0;
                foreach ($files as $file) {
                    if (!is_string($file) || !is_file($file)) {
                        continue;
                    }
                    $count++;
                    $size = ultracache_safe_filesize($file, 'css_bundle_summary_diagnostic');
                    if (false !== $size) {
                        $bytes += max(0, (int) $size);
                    }
                    $mtime = ultracache_safe_filemtime($file, 'css_bundle_summary_diagnostic');
                    if (false !== $mtime) {
                        $latest = max($latest, (int) $mtime);
                    }
                }
                return array('count' => $count, 'bytes' => $bytes, 'latestModified' => $latest);
            };

            $bundle_files = $count_files($css_bundle_dir . 'bundle-*.css');
            $delayed_files = $count_files($css_bundle_dir . 'bundle-*-delayed-fonts.css');
            $manifest_exists = file_exists($manifest_file);
            $manifest_readable = $manifest_exists && is_readable($manifest_file);

            $manifest_entries = 0;
            $entries_with_delayed = 0;
            $missing_bundle_files = 0;
            $missing_delayed_files = 0;
            $manifest_bundle_urls = array();
            $manifest_delayed_urls = array();
            $manifest_bundle_files = array();
            $manifest_delayed_files = array();
            $manifest_source_count = 0;
            $manifest_source_samples = array();
            $manifest_bundle_bytes = 0;
            $manifest_delayed_bytes = 0;
            $manifest_delayed_blocks = 0;

            if ($manifest_readable) {
                $raw = ultracache_safe_file_get_contents($manifest_file, 'css bundle summary manifest diagnostics');
                $manifest = is_string($raw) && '' !== $raw ? json_decode($raw, true) : array();
                if (is_array($manifest)) {
                    $entries = array();
                    if (!empty($manifest['entry']) && is_array($manifest['entry'])) {
                        $entries[] = $manifest['entry'];
                    }
                    if (!empty($manifest['entries']) && is_array($manifest['entries'])) {
                        foreach ($manifest['entries'] as $entry) {
                            if (is_array($entry)) {
                                $entries[] = $entry;
                            }
                        }
                    }
                    $seen = array();
                    foreach ($entries as $entry) {
                        $key = (string) ($entry['key'] ?? ($entry['url'] ?? ($entry['bundleFile'] ?? md5(wp_json_encode($entry)))));
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $manifest_entries++;

                        $bundle_file = isset($entry['bundleFile']) ? (string) $entry['bundleFile'] : '';
                        $bundle_url = isset($entry['bundleUrl']) ? (string) $entry['bundleUrl'] : '';
                        if ('' !== $bundle_url) {
                            $manifest_bundle_urls[] = $bundle_url;
                        }
                        if ('' !== $bundle_file) {
                            $manifest_bundle_files[] = $bundle_file;
                            if (!is_file($bundle_file)) {
                                $missing_bundle_files++;
                            } else {
                                $size = ultracache_safe_filesize($bundle_file, 'css_bundle_summary_manifest_bundle');
                                if (false !== $size) {
                                    $manifest_bundle_bytes += max(0, (int) $size);
                                }
                            }
                        }

                        $delayed_file = isset($entry['delayedFontFile']) ? (string) $entry['delayedFontFile'] : '';
                        $delayed_url = isset($entry['delayedFontUrl']) ? (string) $entry['delayedFontUrl'] : '';
                        $delayed_blocks = max(0, (int) ($entry['delayedFontFaceBlocks'] ?? 0));
                        if ('' !== $delayed_url) {
                            $manifest_delayed_urls[] = $delayed_url;
                        }
                        if ($delayed_blocks > 0 || '' !== $delayed_file || '' !== $delayed_url) {
                            $entries_with_delayed++;
                            $manifest_delayed_blocks += $delayed_blocks;
                            if ('' !== $delayed_file) {
                                $manifest_delayed_files[] = $delayed_file;
                                if (!is_file($delayed_file)) {
                                    $missing_delayed_files++;
                                } else {
                                    $size = ultracache_safe_filesize($delayed_file, 'css_bundle_summary_manifest_delayed');
                                    if (false !== $size) {
                                        $manifest_delayed_bytes += max(0, (int) $size);
                                    }
                                }
                            }
                        }

                        $source_urls = isset($entry['sourceUrls']) && is_array($entry['sourceUrls']) ? $entry['sourceUrls'] : array();
                        $manifest_source_count += count($source_urls);
                        foreach ($source_urls as $source_url) {
                            $source_url = trim((string) $source_url);
                            if ('' !== $source_url && count($manifest_source_samples) < 12) {
                                $manifest_source_samples[] = $source_url;
                            }
                        }
                    }
                }
            }

            $last_verification = isset($last['warmVerification']) && is_array($last['warmVerification']) ? $last['warmVerification'] : array();
            $last_warm = array(
                'success' => !empty($last['success']),
                'message' => isset($last['message']) ? (string) $last['message'] : '',
                'bundleCount' => max(0, (int) ($last['bundleCount'] ?? 0)),
                'stylesBundled' => max(0, (int) ($last['stylesBundled'] ?? 0)),
                'stylesScanned' => max(0, (int) ($last['stylesScanned'] ?? 0)),
                'stylesSkipped' => max(0, (int) ($last['stylesSkipped'] ?? 0)),
                'stylesUnresolved' => max(0, (int) ($last['stylesUnresolved'] ?? 0)),
                'bundleUrl' => isset($last['bundleUrl']) ? (string) $last['bundleUrl'] : '',
                'delayedFontUrl' => isset($last['delayedFontUrl']) ? (string) $last['delayedFontUrl'] : '',
                'bundleBytes' => max(0, (int) ($last['bundleBytes'] ?? 0)),
                'delayedFontBytes' => max(0, (int) ($last['delayedFontBytes'] ?? 0)),
                'cssBundleRefs' => max(0, (int) ($last_verification['cssBundleRefs'] ?? 0)),
                'stylesheetLinks' => max(0, (int) ($last_verification['stylesheetLinks'] ?? 0)),
                'time' => max(0, (int) ($last['time'] ?? 0)),
                'time_mysql' => isset($last['time_mysql']) ? (string) $last['time_mysql'] : '',
            );

            return array(
                'enabled' => !empty($settings['homepageCssBundleEnabled']),
                'scope' => (string) ($settings['cssBundleScope'] ?? ($settings['css_bundle_scope'] ?? 'homepage')),
                'mode' => (string) ($settings['homepageCssBundleMode'] ?? 'safe'),
                'cacheStatsEnabled' => !empty($settings['cacheStatsEnabled']),
                'summarySource' => 'independent-option-and-manifest',
                'lastWarm' => $last_warm,
                'bundlesBuilt' => (int) ($last_warm['bundleCount'] ?: 0),
                'stylesBundled' => (int) $last_warm['stylesBundled'],
                'stylesScanned' => (int) $last_warm['stylesScanned'],
                'stylesSkipped' => (int) $last_warm['stylesSkipped'],
                'stylesUnresolved' => (int) $last_warm['stylesUnresolved'],
                'files' => array(
                    'dirExists' => is_dir($css_bundle_dir),
                    'bundleFiles' => (int) $bundle_files['count'],
                    'bundleBytes' => (int) $bundle_files['bytes'],
                    'delayedFontFiles' => (int) $delayed_files['count'],
                    'delayedFontBytes' => (int) $delayed_files['bytes'],
                    'latestModified' => (int) max($bundle_files['latestModified'], $delayed_files['latestModified']),
                ),
                'manifest' => array(
                    'exists' => (bool) $manifest_exists,
                    'readable' => (bool) $manifest_readable,
                    'entries' => (int) $manifest_entries,
                    'entriesWithDelayedFonts' => (int) $entries_with_delayed,
                    'sourceUrls' => (int) $manifest_source_count,
                    'sourceSamples' => array_values(array_unique($manifest_source_samples)),
                    'bundleUrls' => array_values(array_slice(array_unique($manifest_bundle_urls), 0, 8)),
                    'delayedFontUrls' => array_values(array_slice(array_unique($manifest_delayed_urls), 0, 8)),
                    'bundleFiles' => array_values(array_slice(array_unique($manifest_bundle_files), 0, 20)),
                    'delayedFontFiles' => array_values(array_slice(array_unique($manifest_delayed_files), 0, 20)),
                    'bundleBytes' => (int) $manifest_bundle_bytes,
                    'delayedFontBytes' => (int) $manifest_delayed_bytes,
                    'delayedFontFaceBlocks' => (int) $manifest_delayed_blocks,
                    'missingBundleFiles' => (int) $missing_bundle_files,
                    'missingDelayedFontFiles' => (int) $missing_delayed_files,
                ),
                'integrityOk' => (0 === (int) $missing_bundle_files && 0 === (int) $missing_delayed_files),
                'message' => __('CSS Bundle Summary is independent from Cache Stats. It reads the last bundle warm snapshot and current manifest/file integrity.', 'ultracache'),
            );
        }

private static function get_security_hard_query_args()
        {
            return function_exists('ultracache_get_query_cache_hard_blocked_defaults')
                ? ultracache_get_query_cache_hard_blocked_defaults()
                : array();
        }

private static function get_security_cache_correctness_diagnostics(array $settings)
        {
            $advanced_cache_status = class_exists('Ultra_Cache_Engine') && method_exists('Ultra_Cache_Engine', 'get_advanced_cache_dropin_status')
                ? Ultra_Cache_Engine::get_advanced_cache_dropin_status()
                : array();
            $redis_credentials = function_exists('ultracache_get_redis_credentials')
                ? ultracache_get_redis_credentials()
                : array('username' => '', 'password' => '', 'configured' => false, 'acl' => false);
            $secret_statuses = method_exists(__CLASS__, 'get_wp_config_secret_statuses')
                ? self::get_wp_config_secret_statuses()
                : array();
            $redis_status = isset($secret_statuses['redis']) && is_array($secret_statuses['redis']) ? $secret_statuses['redis'] : array();
            $varnish_status = isset($secret_statuses['varnish']) && is_array($secret_statuses['varnish']) ? $secret_statuses['varnish'] : array();
            $redis_secret_configured = !empty($redis_status['configured']) || !empty($redis_credentials['configured']);
            $varnish_secret_configured = !empty($varnish_status['configured']) || '' !== (function_exists('ultracache_get_varnish_password') ? ultracache_get_varnish_password() : '');
            $redis_secret_location = !empty($redis_status['external']) ? 'wp-config-external' : (!empty($redis_status['managed']) ? 'wp-config-managed' : 'wp-config-constant');
            $varnish_secret_location = !empty($varnish_status['external']) ? 'wp-config-external' : (!empty($varnish_status['managed']) ? 'wp-config-managed' : 'wp-config-constant');
            $settings_option_raw = get_option(ULTRACACHE_SETTINGS_KEY, array());
            $redis_secret_in_settings = is_array($settings_option_raw) && isset($settings_option_raw['redisPassword']) && '' !== trim((string) $settings_option_raw['redisPassword']);
            $varnish_secret_in_settings = is_array($settings_option_raw) && isset($settings_option_raw['varnishCliKey']) && '' !== trim((string) $settings_option_raw['varnishCliKey']);
            $dangerous_query_args = self::get_security_hard_query_args();
            $configured_query_args = self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($settings['cacheExceptionQueryArgs'] ?? array())));
            $query_policy = function_exists('ultracache_build_query_cache_policy')
                ? ultracache_build_query_cache_policy(
                    !empty($settings['cacheQueryStringsEnabled']),
                    self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($settings['cacheQueryStringAllowlist'] ?? array()))),
                    $configured_query_args
                )
                : array();
            $configured_lookup = array_fill_keys(array_map('sanitize_key', $configured_query_args), true);
            $missing_visible = array();
            foreach ($dangerous_query_args as $arg) {
                $key = sanitize_key($arg);
                if ('' !== $key && empty($configured_lookup[$key])) {
                    $missing_visible[] = $key;
                }
            }

            $secret_files = array();

            $runtime_config_protection = array(
                'embeddedInAdvancedCache' => true,
                'advancedCacheExists' => !empty($advanced_cache_status['exists']),
                'advancedCacheReadable' => !empty($advanced_cache_status['readable']),
                'configInSync' => !empty($advanced_cache_status['config_in_sync']),
            );

            return array(
                'enabled' => true,
                'summary' => array(
                    'loggedInBypass' => true,
                    'woocommerceSafeModeEnabled' => !empty($settings['woocommerceSafeModeEnabled']),
                    'queryStringsEnabled' => !empty($settings['cacheQueryStringsEnabled']),
                    'queryAllowlistCount' => count((array) ($query_policy['allowlist'] ?? array())),
                    'queryPolicyFingerprint' => (string) ($query_policy['fingerprint'] ?? ''),
                    'configuredExcludedQueryArgs' => count($configured_query_args),
                    'hardSensitiveQueryArgs' => count($dangerous_query_args),
                    'hardSensitiveQueryArgsMissingFromVisibleList' => count($missing_visible),
                    'secretsRedactedFromClientSettings' => true,
                    'debugContextRedactionEnabled' => function_exists('ultracache_redact_sensitive_debug_context'),
                    'redisSecretConfigured' => $redis_secret_configured,
                    'redisSecretLocation' => $redis_secret_location,
                    'redisSecretInSettingsOption' => $redis_secret_in_settings,
                    'varnishSecretConfigured' => $varnish_secret_configured,
                    'varnishSecretLocation' => $varnish_secret_location,
                    'varnishSecretInSettingsOption' => $varnish_secret_in_settings,
                ),
                'queryPolicy' => $query_policy,
                'hardSensitiveQueryArgs' => $dangerous_query_args,
                'hardSensitiveQueryArgsMissingFromVisibleList' => array_values($missing_visible),
                'cookieBypassPrefixes' => array('wordpress_logged_in_', 'wordpress_sec_', 'comment_author_', 'wp-postpass_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_'),
                'engineOnlySafeguards' => array(
                    array('label' => __('Logged-in users bypass page cache', 'ultracache'), 'status' => 'enforced'),
                    array('label' => __('Sensitive query args always bypass cache', 'ultracache'), 'status' => 'enforced'),
                    array('label' => __('WooCommerce cart/checkout/account bypass rules', 'ultracache'), 'status' => !empty($settings['woocommerceSafeModeEnabled']) ? 'enabled' : 'available'),
                    array('label' => __('Redis and Varnish secrets are read from wp-config.php constants and redacted from REST/dashboard settings', 'ultracache'), 'status' => 'enforced'),
                    array('label' => __('Debug context secret redaction', 'ultracache'), 'status' => function_exists('ultracache_redact_sensitive_debug_context') ? 'enabled' : 'missing'),
                ),
                'runtimeConfigProtection' => $runtime_config_protection,
                'secretFiles' => $secret_files,
                'redisSecret' => array(
                    'configured' => $redis_secret_configured,
                    'location' => $redis_secret_location,
                    'constantName' => 'WP_REDIS_PASSWORD',
                    'managed' => !empty($redis_status['managed']),
                    'external' => !empty($redis_status['external']),
                    'acl' => !empty($redis_credentials['acl']),
                    'inSettingsOption' => $redis_secret_in_settings,
                ),
                'varnishSecret' => array(
                    'configured' => $varnish_secret_configured,
                    'location' => $varnish_secret_location,
                    'constantName' => 'ULTRACACHE_VARNISH_PASSWORD',
                    'managed' => !empty($varnish_status['managed']),
                    'external' => !empty($varnish_status['external']),
                    'inSettingsOption' => $varnish_secret_in_settings,
                ),
                'rest' => array(
                    'adminCapability' => 'manage_options',
                    'routesUsePermissionCallback' => true,
                    'dangerousActionsRequireAdmin' => true,
                ),
                'message' => __('Security diagnostics are read-only. Sensitive query args are enforced as an engine safety floor even if not present in the visible exclusion list.', 'ultracache'),
            );
        }

}
