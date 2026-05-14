<?php
/**
 * Dashboard diagnostics and cache storage diagnostic helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_WP_Diagnostics_Trait
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

            $dir = defined('UCWP_CACHE_DIR') ? trailingslashit(UCWP_CACHE_DIR) . 'google-fonts/' : trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/google-fonts/';
            $fallback['path'] = $dir;
            if (is_dir($dir)) {
                $items = function_exists('ucwp_safe_scandir') ? ucwp_safe_scandir($dir, 'google_fonts_dashboard_fallback scandir') : scandir($dir);
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
                        $size = function_exists('ucwp_safe_filesize') ? ucwp_safe_filesize($path, 'google_fonts_dashboard_fallback') : @filesize($path);
                        if (false !== $size) {
                            $fallback['bytes'] += max(0, (int) $size);
                        }
                        $mtime = function_exists('ucwp_safe_filemtime') ? ucwp_safe_filemtime($path, 'google_fonts_dashboard_fallback') : @filemtime($path);
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
            $cache_dir = defined('UCWP_CACHE_DIR') ? trailingslashit(UCWP_CACHE_DIR) : trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/';
            $font_css_dir = trailingslashit($cache_dir) . 'font-css/';
            $optimized_css_dir = trailingslashit($cache_dir) . 'optimized-css/';
            $css_bundle_dir = trailingslashit($cache_dir) . 'css-bundles/';
            $google_fonts_dir = trailingslashit($cache_dir) . 'google-fonts/';
            $manifest_file = $css_bundle_dir . 'manifest.json';

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
                    $size = ucwp_safe_filesize($file, 'font_pipeline_diagnostic');
                    if (false !== $size) {
                        $bytes += max(0, (int) $size);
                    }
                    $mtime = ucwp_safe_filemtime($file, 'font_pipeline_diagnostic');
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
                    $css = ucwp_safe_file_get_contents($file, 'font_pipeline_source_diagnostics', true);
                    if (!is_string($css)) {
                        $css = '';
                    }
                    $font_faces = preg_match_all('/@font-face\s*{/i', $css);
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
                    $file_bytes = max(0, (int) ucwp_safe_filesize($file, 'font_pipeline_source_diagnostics'));
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
                $raw = ucwp_safe_file_get_contents($manifest_file, 'font pipeline manifest diagnostics');
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
                    'delayIconFontsAutoDetectEnabled' => !empty($settings['delayIconFontsAutoDetectEnabled']),
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
                'message' => 'Font diagnostics are read-only. They report generated local font CSS, delayed icon-font CSS, and CSS bundle delayed-font metadata.',
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
                array('key' => 'cacheExceptionPaths', 'label' => 'Exclude Paths From Caching', 'area' => 'Cache bypass', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'cacheExceptionQueryArgs', 'label' => 'Excluded query-string args from Caching', 'area' => 'Cache bypass', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'cacheQueryStringAllowlist', 'label' => 'Query-string args whitelist', 'area' => 'Cache query strings', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'deferJsForceList', 'label' => 'Defer those scripts', 'area' => 'JavaScript', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'deferJsExcludeList', 'label' => 'JS Delay / Defer Exclusions', 'area' => 'JavaScript', 'kind' => 'Shared final override', 'shared' => true),
                array('key' => 'delaySafeThirdPartyJsPatterns', 'label' => 'Safe third-party delay patterns', 'area' => 'JavaScript', 'kind' => 'Pattern list', 'shared' => false),
                array('key' => 'delayFunctionalThirdPartyJsPatterns', 'label' => 'Known functional third-party delay patterns', 'area' => 'JavaScript', 'kind' => 'Pattern list', 'shared' => false),
                array('key' => 'criticalRequestChainDelayList', 'label' => 'Delay Non-Critical Request Chains', 'area' => 'JavaScript/CSS', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'criticalResourcePreloadList', 'label' => 'Priority Preloads', 'area' => 'Critical chain', 'kind' => 'Textarea', 'shared' => true),
                array('key' => 'homepageCssBundleExcludeList', 'label' => 'CSS Bundle Exclusions', 'area' => 'CSS bundles', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'asyncCssExcludeList', 'label' => 'Async CSS Exclude List', 'area' => 'CSS async', 'kind' => 'Shared final override', 'shared' => true),
                array('key' => 'assetCleanupExcludeList', 'label' => 'Asset Cleanup Exclusions', 'area' => 'Asset cleanup', 'kind' => 'Textarea', 'shared' => false),
                array('key' => 'delayIconFontsList', 'label' => 'Delay These Fonts / Patterns', 'area' => 'Fonts', 'kind' => 'Pattern list', 'shared' => false),
                array('key' => 'delayIconFontsExcludeList', 'label' => 'Never Delay These Fonts / Patterns', 'area' => 'Fonts', 'kind' => 'Pattern list', 'shared' => false),
                array('key' => 'manualLcpHeroSelector', 'label' => 'Manual LCP selector', 'area' => 'LCP', 'kind' => 'Image URL / CSS selector list', 'shared' => true),
                array('key' => 'googleFontsAdditionalScanUrls', 'label' => 'Additional Google Fonts Scan URLs', 'area' => 'Fonts', 'kind' => 'Textarea', 'shared' => false),
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
                array('label' => 'Absolute JS dependency floor', 'area' => 'JavaScript', 'editable' => false, 'reason' => 'Core WordPress/jQuery globals stay protected to avoid site-wide runtime failures.', 'examples' => array('jquery', 'jquery-migrate', 'wp-i18n', 'wp-hooks', 'wp-util', 'api-fetch', 'underscore')),
                array('label' => 'Admin/internal paths never cached', 'area' => 'Cache bypass', 'editable' => false, 'reason' => 'WordPress admin/login/API flows must remain uncached even if the visible path list is edited.', 'examples' => array('/wp-admin/', '/wp-login.php', '/wp-json/')),
                array('label' => 'Logged-in and personalized requests bypass', 'area' => 'Cache poisoning protection', 'editable' => false, 'reason' => 'User cookies, cart/checkout/account flows, and unsafe methods must not be page-cached.', 'examples' => array('logged-in cookies', 'POST', 'cart', 'checkout', 'account')),
                array('label' => 'CSS bundle stale-ref protection', 'area' => 'CSS bundles', 'editable' => false, 'reason' => 'Main bundle files and delayed-font companion files are retained/validated to protect stale proxy HTML.', 'examples' => array('48h bundle grace period', 'delayed-font pair lifecycle', 'missing bundle invalidation')),
                array('label' => 'Varnish endpoint safety validation', 'area' => 'Reverse proxy', 'editable' => false, 'reason' => 'Obvious public frontend endpoints are blocked while explicitly configured Varnish infrastructure endpoints remain supported.', 'examples' => array('external Varnish infrastructure allowed', 'public frontend :80/:443 blocked')),
            );

            $legacy_lists = array(
                array('key' => 'delayNonCriticalJsExcludeList', 'label' => 'Legacy Delay Non-Critical JS Exclusions', 'mappedTo' => 'deferJsExcludeList', 'active' => false, 'message' => 'Legacy values are merged into the visible JS Delay / Defer Exclusions field and then cleared.'),
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
                'message' => 'Settings transparency diagnostics are read-only. User-editable safeguards are listed separately from engine-only safety floors.',
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
            $seconds = (int) apply_filters('ucwp_css_bundle_cleanup_grace_seconds', $seconds);
            return max(HOUR_IN_SECONDS, min(WEEK_IN_SECONDS, $seconds));
        }

        private static function get_storage_cleanup_max_deletes_per_run()
        {
            $max = (int) self::get_storage_cleanup_dashboard_setting('cssBundleCleanupDeleteLimit', 60);
            $max = (int) apply_filters('ucwp_css_bundle_cleanup_max_deletes_per_run', $max);
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
            if ('' === $html || false === stripos($html, '/cache/ultracache/css-bundles/')) {
                return $refs;
            }

            preg_match_all('~(?:https?:)?//[^\s"\'<>]+/wp-content/cache/ultracache/css-bundles/[^\s"\'<>?#)]+\.css~i', $html, $absolute_matches);
            preg_match_all('~/wp-content/cache/ultracache/css-bundles/[^\s"\'<>?#)]+\.css~i', $html, $path_matches);

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

                    $html = ucwp_safe_file_get_contents($path, 'css bundle cached html ref diagnostics scan');
                    if (!is_string($html) || false === stripos($html, '/cache/ultracache/css-bundles/')) {
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
                'cleanupGraceFilter' => 'ucwp_css_bundle_cleanup_grace_seconds',
                'cleanupDeleteLimitFilter' => 'ucwp_css_bundle_cleanup_max_deletes_per_run',
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
                $size = ucwp_safe_filesize($file, 'css_bundle_storage_diagnostic');
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

                $mtime = (int) ucwp_safe_filemtime($file, 'css_bundle_storage_diagnostic');
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

        private static function get_cache_storage_diagnostics($settings = array(), $css_summary = null, $force_refresh = false)
        {
            $cache_dir = defined('UCWP_CACHE_DIR') ? trailingslashit(UCWP_CACHE_DIR) : trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/';
            $css_bundle_dir = trailingslashit($cache_dir) . 'css-bundles/';
            $object_dir = defined('UCWP_OBJECT_CACHE_DIR') ? trailingslashit(UCWP_OBJECT_CACHE_DIR) : trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache-objects/';
            $avif_dir = defined('UCWP_AVIF_DIR') ? trailingslashit(UCWP_AVIF_DIR) : trailingslashit(WP_CONTENT_DIR) . 'uploads/uc-images/avif/';
            $webp_dir = defined('UCWP_WEBP_DIR') ? trailingslashit(UCWP_WEBP_DIR) : trailingslashit(WP_CONTENT_DIR) . 'uploads/uc-images/webp/';
            $storage_cache_key = 'ucwp_cache_storage_diagnostics_v2';

            if (!$force_refresh) {
                $cached_storage = get_transient($storage_cache_key);
                if (is_array($cached_storage) && isset($cached_storage['total'])) {
                    $cached_storage['cached'] = true;
                    $cached_storage['scanSkipped'] = false;
                    $cached_storage['message'] = isset($cached_storage['message']) && '' !== (string) $cached_storage['message']
                        ? (string) $cached_storage['message']
                        : self::maybe_translate('Storage diagnostics are cached. Use Refresh storage diagnostics for a capped filesystem rescan.');
                    return $cached_storage;
                }

                return array(
                    'enabled' => true,
                    'warningLevel' => 'notice',
                    'warnings' => array(self::maybe_translate('Storage diagnostics are passive until manually refreshed; normal dashboard refresh does not scan cache/media directories.')),
                    'scanLimit' => 0,
                    'cached' => false,
                    'scanSkipped' => true,
                    'scannedAt' => 0,
                    'message' => self::maybe_translate('Use Refresh storage diagnostics to run a capped filesystem scan.'),
                    'total' => array('files' => 0, 'bytes' => 0, 'truncated' => false),
                    'pageCache' => array('files' => 0, 'bytes' => 0, 'truncated' => false),
                    'cssBundles' => array('files' => 0, 'bytes' => 0, 'recognizedBundleFiles' => 0, 'warningLevel' => 'notice', 'message' => self::maybe_translate('CSS bundle storage has not been scanned yet.')),
                    'objectCacheDisk' => array('files' => 0, 'bytes' => 0, 'truncated' => false, 'exists' => is_dir($object_dir)),
                    'mediaCache' => array(
                        'storageRoot' => 'uploads/uc-images',
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
                    'storageRoot' => 'uploads/uc-images',
                    'persistent' => true,
                    'message' => self::maybe_translate('Optimized AVIF/WebP media is stored under uploads/uc-images so normal cache cleanup does not remove persistent generated image assets.'),
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

            set_transient($storage_cache_key, $diagnostics, 10 * MINUTE_IN_SECONDS);
            return $diagnostics;
        }

        private static function get_css_bundle_summary_diagnostics($settings = array())
        {
            $settings = is_array($settings) ? $settings : array();
            $cache_dir = defined('UCWP_CACHE_DIR') ? trailingslashit(UCWP_CACHE_DIR) : trailingslashit(WP_CONTENT_DIR) . 'cache/ultracache/';
            $css_bundle_dir = trailingslashit($cache_dir) . 'css-bundles/';
            $manifest_file = $css_bundle_dir . 'manifest.json';
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
                    $size = ucwp_safe_filesize($file, 'css_bundle_summary_diagnostic');
                    if (false !== $size) {
                        $bytes += max(0, (int) $size);
                    }
                    $mtime = ucwp_safe_filemtime($file, 'css_bundle_summary_diagnostic');
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
                $raw = ucwp_safe_file_get_contents($manifest_file, 'css bundle summary manifest diagnostics');
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
                                $size = ucwp_safe_filesize($bundle_file, 'css_bundle_summary_manifest_bundle');
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
                                    $size = ucwp_safe_filesize($delayed_file, 'css_bundle_summary_manifest_delayed');
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
                'message' => 'CSS Bundle Summary is independent from Cache Stats. It reads the last bundle warm snapshot and current manifest/file integrity.',
            );
        }

        private static function redact_path_for_diagnostics($path)
        {
            $path = is_string($path) ? wp_normalize_path($path) : '';
            if ('' === $path) {
                return '';
            }

            $basename = wp_basename($path);
            $content_dir = defined('WP_CONTENT_DIR') ? wp_normalize_path(WP_CONTENT_DIR) : '';
            $plugin_dir = defined('UCWP_PATH') ? wp_normalize_path(UCWP_PATH) : '';
            $abspath = defined('ABSPATH') ? wp_normalize_path(ABSPATH) : '';

            if ('' !== $content_dir && 0 === strpos($path, $content_dir)) {
                $relative = ltrim(substr($path, strlen($content_dir)), '/');
                return 'WP_CONTENT_DIR/' . $relative;
            }

            if ('' !== $plugin_dir && 0 === strpos($path, $plugin_dir)) {
                $relative = ltrim(substr($path, strlen($plugin_dir)), '/');
                return 'UCWP_PATH/' . $relative;
            }

            if ('' !== $abspath && 0 === strpos($path, $abspath)) {
                $relative = ltrim(substr($path, strlen($abspath)), '/');
                return 'ABSPATH/' . $relative;
            }

            return '[outside-webroot]/' . $basename;
        }

        private static function get_security_hard_query_args()
        {
            return array(
                '_wpnonce', '_ajax_nonce', 'nonce', 'security', 'token', 'auth', 'auth_token', 'access_token',
                'key', 'order_key', 'password', 'pass', 'pwd', 'redirect_to', 'customer-logout', 'logout',
                'pay_for_order', 'cancel_order', 'download_file'
            );
        }

        private static function get_security_cache_correctness_diagnostics(array $settings)
        {
            $runtime_config_path = self::get_runtime_config_path();
            $runtime_secret_path = self::get_runtime_secret_path();
            $object_secret_path = $runtime_secret_path;
            $legacy_object_secret_path = trailingslashit(UCWP_OBJECT_CACHE_DIR) . '.redis-auth.php';
            $runtime_secret_values = self::load_runtime_secret_file();
            $settings_option_raw = get_option(UCWP_SETTINGS_KEY, array());
            $redis_secret_in_settings = is_array($settings_option_raw) && isset($settings_option_raw['redisPassword']) && '' !== trim((string) $settings_option_raw['redisPassword']);
            $varnish_secret_in_settings = is_array($settings_option_raw) && isset($settings_option_raw['varnishCliKey']) && '' !== trim((string) $settings_option_raw['varnishCliKey']);
            $dangerous_query_args = self::get_security_hard_query_args();
            $configured_query_args = self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($settings['cacheExceptionQueryArgs'] ?? array())));
            $configured_lookup = array_fill_keys(array_map('sanitize_key', $configured_query_args), true);
            $missing_visible = array();
            foreach ($dangerous_query_args as $arg) {
                $key = sanitize_key($arg);
                if ('' !== $key && empty($configured_lookup[$key])) {
                    $missing_visible[] = $key;
                }
            }

            $secret_paths = array(
                'runtimeSecret' => $runtime_secret_path,
                'objectCacheRedisSecret' => $object_secret_path,
                'legacyObjectCacheRedisSecret' => $legacy_object_secret_path,
            );
            $secret_files = array();
            foreach ($secret_paths as $key => $path) {
                if (!is_string($path) || '' === trim($path)) {
                    continue;
                }
                $secret_files[$key] = array(
                    'exists' => file_exists($path),
                    'readable' => is_readable($path),
                    'insideDocumentRoot' => (0 === strpos(wp_normalize_path($path), wp_normalize_path(ABSPATH))),
                    'displayPath' => self::redact_path_for_diagnostics($path),
                    'basename' => wp_basename($path),
                );
            }

            $runtime_config_protection = array(
                'runtimeConfigExists' => file_exists($runtime_config_path),
                'runtimeConfigReadable' => is_readable($runtime_config_path),
                'htaccessProtectionFile' => file_exists(trailingslashit(dirname($runtime_config_path)) . '.htaccess'),
                'webConfigProtectionFile' => file_exists(trailingslashit(dirname($runtime_config_path)) . 'web.config'),
            );

            return array(
                'enabled' => true,
                'summary' => array(
                    'loggedInBypass' => true,
                    'woocommerceSafeModeEnabled' => !empty($settings['woocommerceSafeModeEnabled']),
                    'queryStringsEnabled' => !empty($settings['cacheQueryStringsEnabled']),
                    'queryAllowlistCount' => count(self::parse_textarea_setting(self::sanitize_setting_key_list((array) ($settings['cacheQueryStringAllowlist'] ?? array())))),
                    'configuredExcludedQueryArgs' => count($configured_query_args),
                    'hardSensitiveQueryArgs' => count($dangerous_query_args),
                    'hardSensitiveQueryArgsMissingFromVisibleList' => count($missing_visible),
                    'secretsRedactedFromClientSettings' => true,
                    'debugContextRedactionEnabled' => function_exists('ucwp_redact_sensitive_debug_context'),
                    'redisSecretConfigured' => !empty($runtime_secret_values['redis_password']),
                    'redisSecretLocation' => 'runtime-secrets-file',
                    'redisSecretInSettingsOption' => $redis_secret_in_settings,
                    'legacyObjectCacheRedisSecretExists' => file_exists($legacy_object_secret_path),
                    'varnishSecretConfigured' => !empty($runtime_secret_values['varnish_admin_secret']),
                    'varnishSecretLocation' => 'runtime-secrets-file',
                    'varnishSecretInSettingsOption' => $varnish_secret_in_settings,
                    'runtimeSecretsFileOutsideDocroot' => !(0 === strpos(wp_normalize_path($runtime_secret_path), wp_normalize_path(ABSPATH))),
                ),
                'hardSensitiveQueryArgs' => $dangerous_query_args,
                'hardSensitiveQueryArgsMissingFromVisibleList' => array_values($missing_visible),
                'cookieBypassPrefixes' => array('wordpress_logged_in_', 'wordpress_sec_', 'comment_author_', 'wp-postpass_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_'),
                'engineOnlySafeguards' => array(
                    array('label' => 'Logged-in users bypass page cache', 'status' => 'enforced'),
                    array('label' => 'Sensitive query args always bypass cache', 'status' => 'enforced'),
                    array('label' => 'WooCommerce cart/checkout/account bypass rules', 'status' => !empty($settings['woocommerceSafeModeEnabled']) ? 'enabled' : 'available'),
                    array('label' => 'Runtime and Redis secrets redacted from REST/dashboard settings', 'status' => 'enforced'),
                    array('label' => 'Debug context secret redaction', 'status' => function_exists('ucwp_redact_sensitive_debug_context') ? 'enabled' : 'missing'),
                ),
                'runtimeConfigProtection' => $runtime_config_protection,
                'secretFiles' => $secret_files,
                'redisSecret' => array(
                    'configured' => !empty($runtime_secret_values['redis_password']),
                    'location' => 'runtime-secrets-file',
                    'insideDocumentRoot' => (0 === strpos(wp_normalize_path($runtime_secret_path), wp_normalize_path(ABSPATH))),
                    'inSettingsOption' => $redis_secret_in_settings,
                    'legacyFileExists' => file_exists($legacy_object_secret_path),
                    'legacyDisplayPath' => self::redact_path_for_diagnostics($legacy_object_secret_path),
                    'displayPath' => self::redact_path_for_diagnostics($runtime_secret_path),
                ),
                'varnishSecret' => array(
                    'configured' => !empty($runtime_secret_values['varnish_admin_secret']),
                    'location' => 'runtime-secrets-file',
                    'insideDocumentRoot' => (0 === strpos(wp_normalize_path($runtime_secret_path), wp_normalize_path(ABSPATH))),
                    'inSettingsOption' => $varnish_secret_in_settings,
                    'displayPath' => self::redact_path_for_diagnostics($runtime_secret_path),
                ),
                'rest' => array(
                    'adminCapability' => 'manage_options',
                    'routesUsePermissionCallback' => true,
                    'dangerousActionsRequireAdmin' => true,
                ),
                'message' => 'Security diagnostics are read-only. Sensitive query args are enforced as an engine safety floor even if not present in the visible exclusion list.',
            );
        }
        private static function redact_diagnostics_for_output($value, $key = '', $depth = 0)
        {
            if (function_exists('ucwp_redact_sensitive_debug_value')) {
                return ucwp_redact_sensitive_debug_value($key, $value, $depth);
            }

            if ($depth > 8) {
                return is_scalar($value) || null === $value ? $value : '[truncated]';
            }

            if (is_array($value)) {
                $redacted = array();
                foreach ($value as $child_key => $child_value) {
                    $redacted[$child_key] = self::redact_diagnostics_for_output($child_value, (string) $child_key, $depth + 1);
                }
                return $redacted;
            }

            return $value;
        }

        private static function get_object_cache_status_diagnostic_lite()
        {
            $settings = self::get_dashboard_settings();
            $object_cache_path = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
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
            if (!in_array($active, array('redis', 'apcu', 'disk', 'runtime'), true)) {
                $active = $selected;
            }

            $configured_fallback = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
            $fallback = isset($backend_status['fallback']) ? strtolower(trim((string) $backend_status['fallback'])) : ('none' === $configured_fallback ? 'runtime' : $configured_fallback);
            if (!in_array($fallback, array('apcu', 'disk', 'runtime'), true)) {
                $fallback = 'none' === $configured_fallback ? 'runtime' : $configured_fallback;
            }

            $fallback_active = isset($backend_status['fallbackActive'])
                ? (bool) $backend_status['fallbackActive']
                : ($selected !== $active);

            $active_runtime_only = ('runtime' === $active);
            $active_persistent = in_array($active, array('redis', 'apcu', 'disk'), true);
            $active = $active_runtime_only || $active_persistent ? $active : $selected;

            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'is_dropin_active')) {
                $dropin_active = (bool) Ultra_Cache_Object_Cache_Manager::is_dropin_active();
            } else {
                $dropin_active = (bool) (
                    function_exists('wp_using_ext_object_cache')
                    && wp_using_ext_object_cache()
                    && file_exists($object_cache_path)
                );
            }

            $selected_supported = true;
            if ('redis' === $selected) {
                $redis_support = self::get_redis_support_status();
                $selected_supported = !empty($redis_support['available']);
            } elseif ('apcu' === $selected) {
                $selected_supported = !empty($support['apcu']['available']);
            }

            $redis_dropin = isset($backend_status['redis']) && is_array($backend_status['redis']) ? $backend_status['redis'] : array();
            $apcu_dropin = isset($backend_status['apcu']) && is_array($backend_status['apcu']) ? $backend_status['apcu'] : array();

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
                            'prefix' => self::sanitize_redis_prefix($settings['redisPrefix'] ?? 'ucwp:'),
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
                )
            );
        }

        public static function get_dashboard_diagnostics($force_storage_refresh = false)
        {
            $settings             = self::get_dashboard_settings();
            $support              = self::get_media_support_status();
            $compression          = self::get_compression_support_status();
            $last                 = get_transient('ultracache_last_cache_event');
            $advanced_cache_path  = trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php';
            $object_cache_path    = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
            $runtime_config_path  = self::get_runtime_config_path();
            $browser_cache_path   = self::get_browser_cache_htaccess_path();
            $object_cache_support  = self::get_object_cache_support_status(false);
            $object_backend_status = array();
            if (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_backend_status')) {
                $object_backend_status = Ultra_Cache_Object_Cache_Manager::get_backend_status();
            }
            if (!is_array($object_backend_status)) {
                $object_backend_status = array();
            }
            $selected_object_backend = isset($object_backend_status['selected']) ? self::sanitize_object_cache_backend($object_backend_status['selected']) : self::sanitize_object_cache_backend($settings['objectCacheBackend']);
            $active_object_backend = isset($object_backend_status['active']) ? strtolower(trim((string) $object_backend_status['active'])) : $selected_object_backend;
            if (!in_array($active_object_backend, array('redis', 'apcu', 'disk', 'runtime'), true)) {
                $active_object_backend = $selected_object_backend;
            }
            $configured_object_fallback = self::sanitize_object_cache_fallback_backend($settings['objectCacheFallbackBackend'] ?? 'apcu');
            $fallback_object_backend = isset($object_backend_status['fallback']) ? strtolower(trim((string) $object_backend_status['fallback'])) : ('none' === $configured_object_fallback ? 'runtime' : $configured_object_fallback);
            if (!in_array($fallback_object_backend, array('apcu', 'disk', 'runtime'), true)) {
                $fallback_object_backend = 'none' === $configured_object_fallback ? 'runtime' : $configured_object_fallback;
            }
            $selected_object_backend_supported = true;
            if ('redis' === $selected_object_backend) {
                $selected_object_backend_supported = !empty(self::get_redis_support_status()['available']);
            } elseif ('apcu' === $selected_object_backend) {
                $selected_object_backend_supported = !empty($object_cache_support['apcu']['available']);
            }
            $object_fallback_active = isset($object_backend_status['fallbackActive'])
                ? (bool) $object_backend_status['fallbackActive']
                : ($selected_object_backend !== $active_object_backend);
            $object_active_runtime_only = 'runtime' === $active_object_backend;
            $object_active_persistent = in_array($active_object_backend, array('redis', 'apcu', 'disk'), true);

            $css_bundle_summary_diagnostics = self::get_css_bundle_summary_diagnostics($settings);
            $cache_storage_diagnostics = self::get_cache_storage_diagnostics($settings, $css_bundle_summary_diagnostics, (bool) $force_storage_refresh);

            $diagnostics = array(
                'pageCache' => array(
                    'enabled' => !empty($settings['pageCacheEnabled']),
                    'active'  => (bool) (defined('WP_CACHE') && WP_CACHE && file_exists($advanced_cache_path)),
                ),
                'objectCache' => array_merge(
                    $object_cache_support,
                    array(
                        'enabled'         => !empty($settings['objectCacheEnabled']),
                        'active'          => (bool) (
                            class_exists('Ultra_Cache_Object_Cache_Manager')
                            && method_exists('Ultra_Cache_Object_Cache_Manager', 'is_dropin_active')
                            ? Ultra_Cache_Object_Cache_Manager::is_dropin_active()
                            : (function_exists('wp_using_ext_object_cache')
                                && wp_using_ext_object_cache()
                                && file_exists($object_cache_path))
                        ),
                        'selectedBackend' => $selected_object_backend,
                        'fallbackBackend' => $object_fallback_active ? $active_object_backend : $fallback_object_backend,
                        'configuredFallbackBackend' => $configured_object_fallback,
                        'fallbackActive'  => (bool) $object_fallback_active,
                        'activeFallbackBackend' => $object_fallback_active ? $active_object_backend : '',
                        'activeFallbackKind' => $object_fallback_active ? ($object_active_runtime_only ? 'runtime-only' : 'persistent') : '',
                        'fallbackPersistent' => $object_fallback_active && $object_active_persistent,
                        'fallbackReason'  => (string) ($object_backend_status['fallbackReason'] ?? ''),
                        'fallbackMessage' => (string) ($object_backend_status['fallbackMessage'] ?? ''),
                        'activeBackend'   => $active_object_backend,
                        'selectedBackendSupported' => (bool) $selected_object_backend_supported,
                        'activeBackendPersistent' => (bool) $object_active_persistent,
                        'activeBackendRuntimeOnly' => (bool) $object_active_runtime_only,
                        'passiveStatusOnly' => true,
                        'manualTestsOnly' => true,
                        'backendStatus'   => $object_backend_status,
                        'redis'           => array_merge(
                            self::get_redis_support_status(),
                            array(
                                'host'             => self::sanitize_redis_host($settings['redisHost']),
                                'port'             => self::sanitize_bounded_integer_setting($settings['redisPort'], 6379, 1, 65535),
                                'database'         => self::sanitize_redis_database($settings['redisDatabase']),
                                'prefix'           => self::sanitize_redis_prefix($settings['redisPrefix']),
                                'useTls'           => !empty($settings['redisUseTls']),
                                'persistent'       => !empty($settings['redisPersistent']),
                                'connectTimeoutMs' => self::sanitize_bounded_integer_setting($settings['redisConnectTimeoutMs'], 200, 50, 15000),
                                'readTimeoutMs'    => self::sanitize_bounded_integer_setting($settings['redisReadTimeoutMs'], 200, 50, 15000),
                            ),
                            isset($object_backend_status['redis']) && is_array($object_backend_status['redis'])
                                ? array(
                                    'dropinEnabled' => !empty($object_backend_status['redis']['enabled']),
                                    'dropinError' => (string) ($object_backend_status['redis']['error'] ?? ''),
                                    'payloadSkipReason' => (string) ($object_backend_status['redis']['payloadSkipReason'] ?? ''),
                                )
                                : array(),
                            array(
                                'passiveStatusOnly' => true,
                                'manualTestsOnly' => true,
                            ),
                            (class_exists('Ultra_Cache_Object_Cache_Manager') && method_exists('Ultra_Cache_Object_Cache_Manager', 'get_last_flush_report'))
                                ? array('lastFlush' => Ultra_Cache_Object_Cache_Manager::get_last_flush_report())
                                : array()
                        ),
                    )
                ),
                'formats' => array(
                    'avif' => !empty($support['imagick_avif']) || !empty($support['gd_avif']),
                    'webp' => !empty($support['imagick_webp']) || !empty($support['gd_webp']),
                ),
                'compression' => array(
                    'brotli' => array(
                        'available' => !empty($compression['brotli']),
                        'enabled'   => !empty($settings['brotliEnabled']),
                    ),
                    'gzip' => array(
                        'available' => !empty($compression['gzip']),
                        'enabled'   => !empty($settings['gzipEnabled']),
                    ),
                    'preferred' => (string) $compression['preferred'],
                    'message'   => (string) $compression['message'],
                    'serverDefault' => self::get_frontend_compression_probe_status(false),
                ),
                'wpCache' => self::get_wp_cache_define_status(),
                'googleFonts' => self::get_google_fonts_cache_diagnostics(),
                'fontPipeline' => self::get_font_pipeline_diagnostics($settings),
                'settingsTransparency' => self::get_settings_transparency_diagnostics($settings),
                'cssBundleSummary' => $css_bundle_summary_diagnostics,
                'cacheStorage' => $cache_storage_diagnostics,
                'securityCorrectness' => self::get_security_cache_correctness_diagnostics($settings),
                'browserCache' => array(
                    'enabled' => !empty($settings['browserCacheRulesEnabled']),
                    'path'    => $browser_cache_path,
                    'active'  => file_exists($browser_cache_path) && false !== strpos((string) ucwp_safe_file_get_contents($browser_cache_path, 'dashboard diagnostics'), '# BEGIN UltraCache Browser Cache'),
                ),
                'varnish' => array_merge(
                    self::get_varnish_support_status(),
                    array(
                        'enabled' => !empty($settings['varnishCliEnabled']),
                        'mode'    => self::sanitize_varnish_mode($settings['varnishCliMode']),
                        'configuredMode' => self::sanitize_varnish_mode($settings['varnishCliMode']),
                        'servers' => self::sanitize_varnish_servers_string($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'endpointCount' => count(array_values(array_filter(array_map('trim', preg_split('/\s+/', self::sanitize_varnish_servers_string($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))))))),
                        'method'  => ('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN',
                        'effectiveMethod' => ('admin' === self::sanitize_varnish_mode($settings['varnishCliMode'])) ? 'admin BAN' : (('PURGE' === strtoupper(trim((string) $settings['varnishCliMethod']))) ? 'PURGE' : 'BAN'),
                        'adminModeUsed' => ('admin' === self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'httpEndpointModeUsed' => ('http' === self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'secretConfigured' => !empty($settings['varnishCliKey']),
                        'timeout' => max(1, min(15, absint($settings['varnishCliTimeoutSeconds']))),
                        'last'    => self::get_varnish_last_result(),
                        'endpointDiagnostics' => self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode'])),
                        'hasUnsafeEndpoints' => !empty(self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['unsafe']),
                        'unsafeEndpointMessage' => !empty(self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['messages'][0]) ? (string) self::get_varnish_endpoint_diagnostics($settings['varnishCliServers'], self::sanitize_varnish_mode($settings['varnishCliMode']))['messages'][0] : '',
                    )
                ),
                'reverseProxy' => self::get_reverse_proxy_status(),
                'loopbackSsl' => ucwp_get_loopback_ssl_status(),
                'legacyCacheConflicts' => self::get_legacy_cache_conflict_status(),
                'analytics' => self::get_analytics_hit_backend_diagnostic($settings),
                'environment' => self::get_advanced_environment_diagnostic(),
                'mediaRuntime' => self::get_media_runtime_diagnostic(),
                'cronWarm' => self::get_cron_warm_status(),
                'paths' => array(
                    'cacheDir'          => self::get_path_diagnostic(UCWP_CACHE_DIR, 'dir'),
                    'objectCacheDir'    => self::get_path_diagnostic(UCWP_OBJECT_CACHE_DIR, 'dir'),
                    'optimizedImagesDir' => defined('UCWP_OPTIMIZED_IMAGES_DIR') ? self::get_path_diagnostic(UCWP_OPTIMIZED_IMAGES_DIR, 'dir') : array(),
                    'avifDir'           => self::get_path_diagnostic(UCWP_AVIF_DIR, 'dir'),
                    'webpDir'           => self::get_path_diagnostic(UCWP_WEBP_DIR, 'dir'),
                    'advancedCache'     => self::get_path_diagnostic($advanced_cache_path, 'file', 'UltraCache advanced-cache drop-in'),
                    'objectCache'       => self::get_path_diagnostic($object_cache_path, 'file', 'UltraCache generated object-cache drop-in'),
                    'runtimeConfig'     => self::get_runtime_config_diagnostic($runtime_config_path),
                    'analytics'         => self::get_analytics_diagnostic(),
                    'browserCacheRules' => self::get_path_diagnostic($browser_cache_path, 'file', '# BEGIN UltraCache Browser Cache'),
                ),
                'lastCacheWrite' => self::get_page_cache_activity_snapshot(),
                'lastEvent' => self::normalize_last_cache_event($last),
            );

            return self::redact_diagnostics_for_output($diagnostics, 'diagnostics', 0);
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
            $writable        = $exists ? ucwp_path_is_writable($path) : ($parent && file_exists($parent) ? ucwp_path_is_writable($parent) : false);
            $parent_writable = ($parent && file_exists($parent)) ? ucwp_path_is_writable($parent) : false;

            if ($exists) {
                $modified = ucwp_safe_filemtime($path, 'path_diagnostic');
                if (!$is_dir) {
                    $size = (int) ucwp_safe_filesize($path, 'path_diagnostic');
                }

                if (!$is_dir && $managed_marker && $readable) {
                    $contents = ucwp_safe_file_get_contents($path, 'dashboard path diagnostic');
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

        private static function redact_runtime_config_for_diagnostics(array $runtime)
        {
            if (isset($runtime['revalidate_secret']) && '' !== (string) $runtime['revalidate_secret']) {
                $runtime['revalidate_secret'] = '[redacted]';
            }

            return $runtime;
        }

        private static function get_runtime_config_diagnostic($path)
        {
            $diag = self::get_path_diagnostic($path, 'file');
            $diag['valid'] = false;
            $diag['keys'] = array();
            $diag['inSync'] = false;
            $diag['loaded'] = array();
            $diag['secretPath'] = self::get_runtime_secret_path();
            $diag['secretStorage'] = 'file_outside_webroot_per_site';
            $diag['secretPresent'] = false;

            $expected_runtime = self::build_runtime_config();
            $expected_public_runtime = $expected_runtime;
            unset($expected_public_runtime['revalidate_secret'], $expected_public_runtime['redis_password'], $expected_public_runtime['varnish_admin_secret']);
            $diag['expected'] = self::redact_diagnostics_for_output($expected_public_runtime, 'expected', 0);

            if (!empty($diag['exists']) && !empty($diag['readable'])) {
                $loaded = self::load_runtime_config_public_file($path);
                if (is_wp_error($loaded)) {
                    $diag['readError'] = $loaded->get_error_message();
                } elseif (!is_array($loaded)) {
                    $diag['readError'] = self::maybe_translate('Invalid runtime config');
                } else {
                    $normalized_public = self::normalize_runtime_config(array_merge($expected_public_runtime, $loaded));
                    unset($normalized_public['revalidate_secret'], $normalized_public['redis_password'], $normalized_public['varnish_admin_secret']);
                    $diag['valid'] = true;
                    $diag['keys'] = array_values(array_keys($loaded));
                    $diag['loaded'] = self::redact_diagnostics_for_output($normalized_public, 'loaded', 0);
                    $diag['inSync'] = ($normalized_public === $expected_public_runtime);
                }
            }

            $secret_runtime = self::load_runtime_secret_file();
            $diag['secretPresent'] = !empty($secret_runtime['revalidate_secret']);

            return $diag;
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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics check only UltraCache-owned analytics table metadata.
            $exists = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === (string) $table);
            $diag['exists'] = (bool) $exists;
            if (!$exists) {
                $diag['message'] = self::maybe_translate('Analytics DB table is missing.');
                return $diag;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics count only UltraCache-owned analytics rows.
            $diag['rows'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics preview only UltraCache-owned analytics metric keys.
            $keys = $wpdb->get_col($wpdb->prepare('SELECT metric_key FROM %i ORDER BY metric_type ASC, metric_key ASC LIMIT 12', $table));
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
                $probe_key = 'ultracache_analytics_probe_' . md5(uniqid('ucwp', true));
                $probe_value = 'ucwp:' . md5($probe_key . '|' . microtime(true));
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

            if (!is_dir(UCWP_CACHE_DIR)) {
                set_transient($cache_key, $snapshot, MINUTE_IN_SECONDS);
                return $snapshot;
            }

            $max_scan_files = (int) apply_filters('ucwp_page_cache_activity_snapshot_max_scan_files', 5000);
            $max_scan_files = max(250, min(50000, $max_scan_files));
            $deadline_seconds = (float) apply_filters('ucwp_page_cache_activity_snapshot_timeout', 1);
            $deadline_seconds = max(0.1, min(3, $deadline_seconds));
            $deadline = microtime(true) + $deadline_seconds;
            $snapshot['scanLimit'] = $max_scan_files;

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(UCWP_CACHE_DIR, FilesystemIterator::SKIP_DOTS)
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

                    if (in_array($name, array('index.php', 'runtime-config.php', 'runtime-config.json', 'analytics.json'), true)) {
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
            $dropin_installable = class_exists('Ultra_Cache_Object_Cache_Manager');

            return array(
                // Kept for compatibility with the dashboard JS. This means the drop-in can be installed, not that Redis is connected.
                'available'                  => $dropin_installable,
                'dropinInstallable'          => $dropin_installable,
                'persistentBackendAvailable' => $redis_available,
                'localBackendAvailable'      => $apcu_available,
                'message'                    => $dropin_installable ? '' : self::maybe_translate('Object cache helper not available.'),
                'apcu'      => array(
                    'available' => $apcu_available,
                    'message'   => $apcu_available ? '' : self::maybe_translate('APCu extension is not loaded or not enabled.'),
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

        private static function get_mysql_query_cache_size()
        {
            global $wpdb;

            if (!($wpdb instanceof wpdb)) {
                return '';
            }

            $value = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only server diagnostic shown only to administrators.
            $result = $wpdb->get_var("SHOW VARIABLES LIKE 'query_cache_size'", 1);
            if (null === $result || false === $result || '' === $result) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only server diagnostic shown only to administrators.
                $result = $wpdb->get_var("SELECT @@query_cache_size");
            }
            if (null === $result || false === $result) {
                return '';
            }
            return (string) $result;
        }

        private static function get_mysql_max_allowed_packet_size()
        {
            global $wpdb;
            if (!($wpdb instanceof wpdb)) {
                return '';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only server diagnostic shown only to administrators.
            $result = $wpdb->get_var("SHOW VARIABLES LIKE 'max_allowed_packet'", 1);
            if (null === $result || false === $result || '' === $result) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only server diagnostic shown only to administrators.
                $result = $wpdb->get_var("SELECT @@max_allowed_packet");
            }
            if (null === $result || false === $result) {
                return '';
            }
            return (string) $result;
        }

        private static function get_advanced_environment_diagnostic()
        {
            $host = function_exists('gethostname') ? (string) @gethostname() : '';

            $site_url = function_exists('home_url') ? (string) home_url('/') : '';
            $site_parts = $site_url ? wp_parse_url($site_url) : array();
            $default_port = (!empty($site_parts['scheme']) && 'http' === strtolower((string) $site_parts['scheme'])) ? '80' : '443';
            $server_addr = (string) ucwp_server_value('SERVER_ADDR');
            $server_port = (string) ucwp_server_value('SERVER_PORT');

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

            $document_root = (string) ucwp_server_value('DOCUMENT_ROOT');
            if ('' === $document_root && defined('ABSPATH')) {
                $document_root = untrailingslashit((string) ABSPATH);
            }

            $query_cache_raw = self::get_mysql_query_cache_size();
            $query_cache_size = '';
            if ('' !== $query_cache_raw && is_numeric($query_cache_raw)) {
                $query_cache_size = size_format((int) $query_cache_raw);
            } elseif ('' !== $query_cache_raw) {
                $query_cache_size = (string) $query_cache_raw;
            }

            $max_packet_raw = self::get_mysql_max_allowed_packet_size();
            $max_packet_size = '';
            if ('' !== $max_packet_raw && is_numeric($max_packet_raw)) {
                $max_packet_size = size_format((int) $max_packet_raw);
            } elseif ('' !== $max_packet_raw) {
                $max_packet_size = (string) $max_packet_raw;
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
