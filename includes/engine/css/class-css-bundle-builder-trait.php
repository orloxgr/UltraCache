<?php
/**
 * CSS bundle discovery, source collection, stylesheet preparation, and bundle construction.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_CSS_Bundle_Builder_Trait
{

private function is_frontpage_css_scan_mode()
    {
        return '1' === sanitize_text_field(ultracache_query_value('ultracache_frontpage_css_scan'))
            && function_exists('ultracache_is_authenticated_internal_request')
            && ultracache_is_authenticated_internal_request('css');
    }

private function get_css_bundle_scope(array $settings = array())
    {
        $scope = isset($settings['css_bundle_scope']) ? strtolower(trim((string) $settings['css_bundle_scope'])) : 'homepage';
        return in_array($scope, array('homepage', 'shared', 'per-page'), true) ? $scope : 'homepage';
    }

private function is_frontpage_request_url($url = '')
    {
        $current_url = '' !== (string) $url ? (string) $url : $this->get_current_request_url();
        $normalized_current = $this->normalize_url($current_url);
        $normalized_home = $this->normalize_url(home_url('/'));

        return '' !== $normalized_current && '' !== $normalized_home && $normalized_current === $normalized_home;
    }

public function warm_frontpage_html(array $args = array())
    {
        $frontpage_url = home_url('/');
        $result = $this->warm_url($frontpage_url, $args);

        if (!empty($result['success'])) {
            $result['message'] = 'Front page HTML cache warmed.';
            if (!empty($result['files']) && is_array($result['files'])) {
                $result['message'] .= ' Generated ' . count($result['files']) . ' cache file(s).';
            }
        }

        return $result;
    }

public function warm_frontpage_html_with_css(array $args = array())
    {
        return $this->build_frontpage_css_bundle(home_url('/'), $args);
    }

public function build_frontpage_css_bundle($url = '', array $args = array())
    {
        $args = is_array($args) ? $args : array();
        $skip_final_warm = !empty($args['skip_final_warm']);
        $ignore_runtime_bypass = !empty($args['ignore_runtime_bypass']);
        $frontpage_url = '' !== (string) $url ? esc_url_raw((string) $url) : home_url('/');
        $result = array(
            'success' => false,
            'skipped' => false,
            'url' => $frontpage_url,
            'message' => '',
            'bundleCount' => 0,
            'bundleFile' => '',
            'bundleUrl' => '',
            'sourceUrls' => array(),
            'stats' => $this->get_default_frontpage_css_stats(),
            'warmResult' => array(),
        );

        if (!$this->is_cacheable_local_url($frontpage_url)) {
            $result['message'] = 'Page is not a local cacheable URL.';
            $this->record_analytics_frontpage_css_warm($result);
            return $result;
        }

        $lock_name = 'css-bundle-' . md5($this->normalize_url($frontpage_url));
        if (!$this->acquire_runtime_lock($lock_name, 180)) {
            $result['skipped'] = true;
            $result['message'] = 'CSS bundle build skipped because another CSS/frontpage build is already running for this URL.';
            $this->record_analytics_frontpage_css_warm($result);
            return $result;
        }

        try {
            $scan = $this->fetch_frontpage_css_source_html($frontpage_url);
            if (empty($scan['success']) || empty($scan['html'])) {
                $result['message'] = !empty($scan['message']) ? (string) $scan['message'] : 'Could not fetch page HTML.';
                $result['retryable'] = !empty($scan['retryable']);
                $result['terminal'] = empty($scan['retryable']);
                $result['failureClass'] = sanitize_key((string) ($scan['failureClass'] ?? 'css-source-fetch'));
                $this->record_analytics_frontpage_css_warm($result);
                return $result;
            }

            $prepared = $this->build_frontpage_css_bundle_from_html((string) $scan['html'], $frontpage_url);
            if (!empty($prepared['stats']) && is_array($prepared['stats'])) {
                $result['stats'] = $prepared['stats'];
            }

            if (empty($prepared['success'])) {
                $result['skipped'] = !empty($prepared['skipped']);
                $result['message'] = !empty($prepared['message']) ? (string) $prepared['message'] : 'Could not build CSS bundle.';
                $this->record_analytics_frontpage_css_warm($result);
                return $result;
            }

            $manifest = $this->read_frontpage_css_manifest();
            $manifest['version'] = 3;
            $manifest['updatedAt'] = current_time('timestamp');
            $manifest['updatedAtMysql'] = current_time('mysql');
            if (!isset($manifest['entries']) || !is_array($manifest['entries'])) {
                $manifest['entries'] = array();
            }
            $entry = $this->build_frontpage_css_manifest_entry($frontpage_url, $prepared);
            $key = $this->get_css_bundle_manifest_key($frontpage_url);
            if ('' !== $key) {
                $manifest['entries'][$key] = $entry;
            }
            if ($this->is_frontpage_request_url($frontpage_url)) {
                $manifest['entry'] = $entry;
            }
            $this->write_frontpage_css_manifest($manifest);
            $this->cleanup_orphan_frontpage_css_bundles($manifest);

            $warm_result = $skip_final_warm ? array('success' => true, 'skipped' => true, 'message' => __('Final HTML warm skipped because the caller will warm the page after the CSS bundle is available.', 'ultracache')) : $this->warm_url($frontpage_url, array(
                'force_refresh'         => true,
                'ignore_runtime_bypass' => $ignore_runtime_bypass,
            ));
            $verification = $skip_final_warm ? array(
                'checked' => false,
                'cachedHtmlAvailable' => false,
                'containsCssBundle' => false,
                'cssBundleRefs' => 0,
                'stylesheetLinks' => 0,
                'inspectedFile' => '',
                'message' => __('Final HTML warm skipped; caller must verify after writing cached HTML.', 'ultracache'),
            ) : $this->inspect_css_bundle_html_after_warm($frontpage_url, is_array($warm_result) ? $warm_result : array());
            $bundle_bytes = (!empty($prepared['bundleFile']) && is_readable((string) $prepared['bundleFile'])) ? (int) filesize((string) $prepared['bundleFile']) : 0;
            $warm_success = $skip_final_warm || !empty($warm_result['success']);
            $injection_verified = $skip_final_warm || !empty($verification['containsCssBundle']);

            $result['bundleCount'] = 1;
            $result['bundleFile'] = (string) $prepared['bundleFile'];
            $result['bundleUrl'] = (string) $prepared['bundleUrl'];
            $result['bundleBytes'] = $bundle_bytes;
            $result['delayedFontFile'] = (string) ($prepared['delayedFontFile'] ?? '');
            $result['delayedFontUrl'] = (string) ($prepared['delayedFontUrl'] ?? '');
            $result['delayedFontBytes'] = isset($prepared['delayedFontBytes']) ? (int) $prepared['delayedFontBytes'] : 0;
            $result['delayedFontFaceBlocks'] = isset($prepared['delayedFontFaceBlocks']) ? (int) $prepared['delayedFontFaceBlocks'] : 0;
            $result['delayedFontFamilies'] = isset($prepared['delayedFontFamilies']) && is_array($prepared['delayedFontFamilies']) ? $prepared['delayedFontFamilies'] : array();
            $result['delayedFontPatterns'] = isset($prepared['delayedFontPatterns']) && is_array($prepared['delayedFontPatterns']) ? $prepared['delayedFontPatterns'] : array();
            $result['sourceUrls'] = array_values(array_unique(array_map('strval', (array) ($prepared['sourceUrls'] ?? array()))));
            $result['sourceDetails'] = isset($prepared['sourceDetails']) && is_array($prepared['sourceDetails']) ? $prepared['sourceDetails'] : array();
            $result['sourceBytesTotal'] = isset($prepared['sourceBytesTotal']) ? (int) $prepared['sourceBytesTotal'] : 0;
            $result['warmResult'] = is_array($warm_result) ? $warm_result : array();
            $result['warmVerification'] = is_array($verification) ? $verification : array();

            $verified_cached_html = !$skip_final_warm && !empty($verification['cachedHtmlAvailable']) && !empty($verification['containsCssBundle']);
            $result['warmVerifiedAfterTimeout'] = (!$skip_final_warm && !$warm_success && $verified_cached_html);

            // A heavy loopback warm can time out at the HTTP client while the generated page
            // still reaches the cache write path. Treat that state as a verified success only
            // when the post-warm cache inspection proves the cached HTML exists and contains
            // the freshly generated CSS bundle marker/reference. This keeps automation stable
            // without hiding genuine failed warms or missing bundle injection.
            $result['success'] = ($skip_final_warm || $warm_success || !empty($result['warmVerifiedAfterTimeout'])) && $injection_verified;

            $warm_status = $skip_final_warm ? 'skipped' : (!empty($warm_result['success']) ? 'success' : (!empty($result['warmVerifiedAfterTimeout']) ? 'verified-after-timeout' : 'failed'));
            $warm_message = !$skip_final_warm && !empty($warm_result['message']) ? ' (' . (string) $warm_result['message'] . ')' : '';
            $contains_label = $skip_final_warm ? 'not checked' : (!empty($verification['containsCssBundle']) ? 'yes' : 'no');
            $result['message'] = 'Built 1 CSS bundle from ' . max(0, (int) ($result['stats']['bundled'] ?? 0)) . ' stylesheet(s).'
                . ' Bundle bytes: ' . $bundle_bytes . '.'
                . (!empty($result['delayedFontFaceBlocks']) ? ' Delayed icon font-face blocks: ' . (int) $result['delayedFontFaceBlocks'] . ' (' . (int) $result['delayedFontBytes'] . ' bytes).' : '')
                . ' Final page warm: ' . $warm_status . $warm_message . '.'
                . ' Cached HTML contains CSS bundle: ' . $contains_label . '.'
                . (!$skip_final_warm ? ' CSS bundle refs in cached HTML: ' . (int) ($verification['cssBundleRefs'] ?? 0) . '. Stylesheet links in cached HTML: ' . (int) ($verification['stylesheetLinks'] ?? 0) . '.' : '');
            if (!empty($result['warmVerifiedAfterTimeout'])) {
                $result['message'] .= ' Warning: the loopback HTTP client timed out, but cached HTML was readable and contains the CSS bundle, so the warm is treated as verified.';
            } elseif (!$skip_final_warm && !$warm_success) {
                $result['message'] .= ' Warning: final page warm did not complete and cached HTML could not be verified, so cached HTML may remain stale.';
            } elseif (!$skip_final_warm && !$injection_verified) {
                $result['message'] .= ' Warning: CSS bundle was built but was not found in cached HTML.';
            }
            $this->record_cache_event('page-css-bundle-build', array(
                'url' => $frontpage_url,
                'bundleFile' => $result['bundleFile'],
                'sourceCount' => count($result['sourceUrls']),
                'delayedFontFaceBlocks' => (int) ($result['delayedFontFaceBlocks'] ?? 0),
            ));
            $this->record_analytics_frontpage_css_warm($result);

            return $result;
        } finally {
            $this->release_runtime_lock($lock_name);
        }
    }

private function inspect_css_bundle_html_after_warm($url, array $warm_result = array())
    {
        $verification = array(
            'checked' => true,
            'cachedHtmlAvailable' => false,
            'containsCssBundle' => false,
            'cssBundleRefs' => 0,
            'stylesheetLinks' => 0,
            'inspectedFile' => '',
            'inspectedFiles' => array(),
            'inspectedFileCount' => 0,
            'filesWithCssBundle' => 0,
            'filesWithoutCssBundle' => 0,
            'mixedCssBundleState' => false,
            'message' => '',
        );

        $files = array();
        if (!empty($warm_result['files']) && is_array($warm_result['files'])) {
            foreach ($warm_result['files'] as $file) {
                $file = (string) $file;
                if ('' !== $file) {
                    $files[] = $file;
                }
            }
        }

        $files = array_values(array_unique($files));
        if (empty($files)) {
            $verification['message'] = 'Warm generated files list was empty; CSS bundle verification cannot be performed accurately.';
            return $verification;
        }

        $max_files = 10;
        $files = array_slice($files, 0, $max_files);

        foreach ($files as $file) {
            $file_result = array(
                'file' => $file,
                'readable' => false,
                'containsCssBundle' => false,
                'cssBundleRefs' => 0,
                'stylesheetLinks' => 0,
            );

            $html = ultracache_safe_file_get_contents($file);
            if (!is_string($html) || '' === $html) {
                $verification['inspectedFiles'][] = $file_result;
                continue;
            }

            $file_result['readable'] = true;
            $verification['cachedHtmlAvailable'] = true;
            if ('' === (string) $verification['inspectedFile']) {
                $verification['inspectedFile'] = $file;
            }

            $link_scan = $this->collect_css_bundle_link_tags_from_html($html, false);
            foreach ((array) ($link_scan['linkTags'] ?? array()) as $tag_html) {
                if ($this->html_tag_rel_contains_stylesheet((string) $tag_html)) {
                    $file_result['stylesheetLinks']++;
                }
            }

            $lower_html = strtolower($html);
            $css_bundle_marker = function_exists('ultracache_generated_asset_public_path') ? strtolower(ultracache_generated_asset_public_path('css-bundles')) : '';
            $path_refs = '' !== $css_bundle_marker ? substr_count($lower_html, $css_bundle_marker) : 0;
            $marker_refs = substr_count($lower_html, 'data-ultracache-page-css-bundle=') + substr_count($lower_html, 'id="ultracache-page-css-bundle"') + substr_count($lower_html, "id='ultracache-page-css-bundle'");
            $file_result['cssBundleRefs'] = max((int) $path_refs, (int) $marker_refs);
            $file_result['containsCssBundle'] = $file_result['cssBundleRefs'] > 0;

            $verification['cssBundleRefs'] = max((int) $verification['cssBundleRefs'], (int) $file_result['cssBundleRefs']);
            $verification['stylesheetLinks'] = max((int) $verification['stylesheetLinks'], (int) $file_result['stylesheetLinks']);
            if (!empty($file_result['containsCssBundle'])) {
                $verification['containsCssBundle'] = true;
                $verification['filesWithCssBundle']++;
                $verification['inspectedFile'] = $file;
            } else {
                $verification['filesWithoutCssBundle']++;
            }

            $verification['inspectedFiles'][] = $file_result;
        }

        $verification['inspectedFileCount'] = count($verification['inspectedFiles']);
        $verification['mixedCssBundleState'] = $verification['filesWithCssBundle'] > 0 && $verification['filesWithoutCssBundle'] > 0;

        if (empty($verification['cachedHtmlAvailable'])) {
            $verification['message'] = 'No readable generated cache HTML file was available after warm.';
            return $verification;
        }

        if (!empty($verification['containsCssBundle'])) {
            $verification['message'] = 'Generated cached HTML contains a CSS bundle reference.';
            if (!empty($verification['mixedCssBundleState'])) {
                $verification['message'] .= ' Mixed cache variants detected: ' . (int) $verification['filesWithCssBundle'] . ' with bundle, ' . (int) $verification['filesWithoutCssBundle'] . ' without bundle.';
            }
        } else {
            $verification['message'] = 'Generated cached HTML files do not contain a CSS bundle reference.';
        }

        return $verification;
    }

private function build_css_bundle_link_tag_from_processor($processor)
    {
        if (!is_object($processor) || !method_exists($processor, 'get_attribute')) {
            return '';
        }

        $attributes = array(
            'rel',
            'href',
            'media',
            'onload',
            'disabled',
            'data-href',
            'data-src',
            'data-ultracache-frontpage-css',
            'data-ultracache-page-css-bundle',
            'data-ultracache-async-css',
        );

        // This rebuilds an existing stylesheet tag from final rendered HTML for diagnostics/manifest comparison.
        // wp_enqueue_style() cannot be used here because the original stylesheet has already been printed.
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Reconstructs an already-rendered stylesheet tag for internal diagnostics and manifest comparison.
        $tag = '<link';
        foreach ($attributes as $attribute) {
            $value = $processor->get_attribute($attribute);
            if (null === $value || false === $value) {
                continue;
            }

            if (true === $value) {
                $value = 'disabled' === $attribute ? 'disabled' : '1';
            }

            $tag .= ' ' . $attribute . '="' . esc_attr((string) $value) . '"';
        }

        return $tag . ' />';
    }

private function collect_css_bundle_link_tags_from_html($html, $head_only = true)
    {
        $result = array(
            'headFound' => false,
            'linkTags' => array(),
        );

        if (!$this->html_tag_processor_available()) {
            return $result;
        }

        try {
            $processor = new WP_HTML_Tag_Processor((string) $html);
            $inside_head = false;

            while ($processor->next_tag(array('tag_closers' => 'visit'))) {
                $tag_name = strtoupper((string) $processor->get_tag());
                $is_closer = $processor->is_tag_closer();

                if ('HEAD' === $tag_name) {
                    $result['headFound'] = true;
                    $inside_head = !$is_closer;
                    continue;
                }

                if ($head_only && !$inside_head) {
                    if (!empty($result['headFound']) && 'BODY' === $tag_name && !$is_closer) {
                        break;
                    }
                    continue;
                }

                if ('LINK' !== $tag_name || $is_closer) {
                    continue;
                }

                $tag_html = $this->build_css_bundle_link_tag_from_processor($processor);
                if ('' !== $tag_html) {
                    $result['linkTags'][] = $tag_html;
                }
            }
        } catch (\Throwable $e) {
            return $result;
        }

        return $result;
    }

private function fetch_frontpage_css_source_html($url)
    {
        $scan_url = add_query_arg(
            array(
                'ultracache_frontpage_css_scan' => 1,
                'ultracache_css_v' => rawurlencode(ULTRACACHE_VERSION),
            ),
            $url
        );

        $runtime_token = function_exists('ultracache_create_runtime_control_token')
            ? ultracache_create_runtime_control_token()
            : '';
        if ('' === $runtime_token) {
            return array(
                'success' => false,
                'retryable' => false,
                'failureClass' => 'css-source-authentication',
                'message' => __('Could not authenticate the internal CSS source request.', 'ultracache'),
                'html' => '',
            );
        }

        $response = ultracache_safe_loopback_remote_request(
            $scan_url,
            array(
                'method' => 'GET',
                'timeout' => 10,
                'redirection' => 0,
                'sslverify' => $this->should_verify_loopback_ssl($scan_url),
                'user-agent' => 'Mozilla/5.0 (compatible; UltraCache-CSSBundle/' . ULTRACACHE_VERSION . '; +https://wordpress.org)',
                'headers' => array(
                    'Cache-Control' => 'no-cache',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'PageSpeed' => 'off',
                    'ModPagespeed' => 'off',
                    'X-UltraCache-CSS-Bundle' => '1',
                    'X-UltraCache-Internal-Request' => '1',
                    'X-UltraCache-Token' => $runtime_token,
                ),
            ),
            'css_bundle_scan'
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'retryable' => true,
                'failureClass' => 'css-source-network',
                'message' => $response->get_error_message(),
                'html' => '',
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);
        if (200 !== $code || '' === $html) {
            $is_redirect = in_array($code, array(301, 302, 303, 307, 308), true);
            $retryable = 0 === $code || 408 === $code || 425 === $code || 429 === $code || $code >= 500 || (200 === $code && '' === $html);
            return array(
                'success' => false,
                'retryable' => $retryable,
                'failureClass' => $is_redirect ? 'css-source-canonical-redirect' : ($retryable ? 'css-source-http-transient' : 'css-source-http-terminal'),
                'httpCode' => $code,
                'message' => $is_redirect
                    ? 'Remote page redirected; the exact CSS source URL was not scanned.'
                    : (200 !== $code ? 'Remote page did not return HTTP 200.' : 'Remote page returned an empty body.'),
                'html' => '',
            );
        }
        if (!$this->is_html_loopback_response($response, $html)) {
            return array(
                'success' => false,
                'retryable' => false,
                'failureClass' => 'css-source-non-html',
                'message' => __('Remote page did not return an HTML Content-Type.', 'ultracache'),
                'html' => '',
            );
        }

        return array('success' => true, 'message' => '', 'html' => $html);
    }

private function build_frontpage_css_bundle_from_html($html, $page_url, $mode = '')
    {
        $settings = $this->get_settings();
        $mode = in_array((string) $mode, array('safe', 'aggressive', 'full'), true) ? (string) $mode : (string) ($settings['homepage_css_bundle_mode'] ?? 'safe');
        $mode = in_array($mode, array('safe', 'aggressive', 'full'), true) ? $mode : 'safe';
        $stats = $this->get_default_frontpage_css_stats();
        $html = $this->normalize_protocol_relative_urls_in_html((string) $html);
        if ('' === $html || false === stripos($html, '<head') || false === stripos($html, '<link')) {
            return array('success' => false, 'skipped' => true, 'message' => __('No stylesheet links were found on the page.', 'ultracache'), 'stats' => $stats);
        }

        $link_scan = $this->collect_css_bundle_link_tags_from_html($html, true);
        if (empty($link_scan['headFound'])) {
            return array('success' => false, 'skipped' => true, 'message' => __('No <head> element was found on the page.', 'ultracache'), 'stats' => $stats);
        }

        if (empty($link_scan['linkTags']) || !is_array($link_scan['linkTags'])) {
            return array('success' => false, 'skipped' => true, 'message' => __('No <link> tags were found on the page.', 'ultracache'), 'stats' => $stats);
        }

        $assets = array();
        foreach ((array) $link_scan['linkTags'] as $tag_html) {
            $tag_html = (string) $tag_html;
            if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
                continue;
            }

            $stats['scanned']++;
            $asset = $this->get_safe_frontpage_stylesheet_asset($tag_html, $page_url, $mode);
            if (!empty($asset)) {
                $assets[] = $asset;
            } else {
                $href = $this->extract_attribute_from_html_tag($tag_html, 'href');
                if ('' === $href) {
                    $stats['unresolved']++;
                } else {
                    $stats['skipped']++;
                }
            }
        }

        if (count($assets) < 2) {
            return array('success' => false, 'skipped' => true, 'message' => __('Not enough eligible local stylesheets were found for CSS bundling.', 'ultracache'), 'stats' => $stats);
        }

        $bundle = $this->build_frontpage_css_bundle_file($page_url, $assets, $mode);
        if (!empty($bundle['stats']) && is_array($bundle['stats'])) {
            $stats['bundled'] += max(0, (int) ($bundle['stats']['bundled'] ?? 0));
            $stats['skipped'] += max(0, (int) ($bundle['stats']['skipped'] ?? 0));
            $stats['unresolved'] += max(0, (int) ($bundle['stats']['unresolved'] ?? 0));
            $stats['delayedFontFaceBlocks'] += max(0, (int) ($bundle['stats']['delayedFontFaceBlocks'] ?? 0));
            $stats['delayedFontFamilies'] = array_values(array_unique(array_merge((array) ($stats['delayedFontFamilies'] ?? array()), (array) ($bundle['stats']['delayedFontFamilies'] ?? array()))));
            $stats['delayedFontPatterns'] = array_values(array_unique(array_merge((array) ($stats['delayedFontPatterns'] ?? array()), (array) ($bundle['stats']['delayedFontPatterns'] ?? array()))));
            foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $css_image_stat_key) {
                $stats[$css_image_stat_key] = max(0, (int) ($stats[$css_image_stat_key] ?? 0)) + max(0, (int) ($bundle['stats'][$css_image_stat_key] ?? 0));
            }
        }
        if (empty($bundle['success'])) {
            return array('success' => false, 'skipped' => !empty($bundle['skipped']), 'message' => !empty($bundle['message']) ? (string) $bundle['message'] : 'Could not write the CSS bundle.', 'stats' => $stats);
        }

        return array(
            'success' => true,
            'skipped' => false,
            'message' => !empty($bundle['message']) ? (string) $bundle['message'] : 'Prepared CSS bundle.',
            'bundleFile' => (string) $bundle['file'],
            'bundleUrl' => (string) $bundle['url'],
            'sourceUrls' => array_values(array_unique(array_map('strval', (array) ($bundle['sourceUrls'] ?? wp_list_pluck($assets, 'url'))))),
            'mode' => $mode,
            'bundleSignature' => (string) ($bundle['signature'] ?? ''),
            'bundleContentHash' => (string) ($bundle['contentHash'] ?? ''),
            'delayedFontFile' => (string) ($bundle['delayedFontFile'] ?? ''),
            'delayedFontUrl' => (string) ($bundle['delayedFontUrl'] ?? ''),
            'delayedFontBytes' => isset($bundle['delayedFontBytes']) ? (int) $bundle['delayedFontBytes'] : 0,
            'delayedFontFaceBlocks' => isset($bundle['delayedFontFaceBlocks']) ? (int) $bundle['delayedFontFaceBlocks'] : 0,
            'delayedFontFamilies' => isset($bundle['delayedFontFamilies']) && is_array($bundle['delayedFontFamilies']) ? $bundle['delayedFontFamilies'] : array(),
            'delayedFontPatterns' => isset($bundle['delayedFontPatterns']) && is_array($bundle['delayedFontPatterns']) ? $bundle['delayedFontPatterns'] : array(),
            'sourceDetails' => isset($bundle['sourceDetails']) && is_array($bundle['sourceDetails']) ? $bundle['sourceDetails'] : array(),
            'sourceBytesTotal' => isset($bundle['sourceBytesTotal']) ? (int) $bundle['sourceBytesTotal'] : 0,
            'stats' => $stats,
        );
    }

private function is_homepage_css_bundle_allowed_media($media, $mode = 'safe')
    {
        $mode = strtolower(trim((string) $mode));
        $media = strtolower(trim((string) $media));
        if ('' === $media || 'all' === $media) {
            return true;
        }

        // Full CSS Bundle accepts every normal local stylesheet media value.
        // Non-all media are wrapped with the original media query in the bundle,
        // so print/speech/responsive semantics remain preserved.
        if ('full' === $mode) {
            return true;
        }

        if (!in_array($mode, array('aggressive', 'leftover'), true)) {
            return false;
        }

        if (false !== strpos($media, 'print') || false !== strpos($media, 'speech')) {
            return false;
        }

        return (0 === strpos($media, 'screen') || 0 === strpos($media, 'all '));
    }

private function get_safe_frontpage_stylesheet_asset($tag_html, $page_url = '', $mode = 'safe')
    {
        $tag_html = (string) $tag_html;
        if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
            return array();
        }

        if (false !== stripos($tag_html, 'data-ultracache-frontpage-css=') || false !== stripos($tag_html, 'data-ultracache-page-css-bundle=') || false !== stripos($tag_html, 'data-ultracache-async-css=')) {
            return array();
        }

        foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
            if (preg_match('/\b' . preg_quote($attribute, '/') . '\b/i', $tag_html)) {
                return array();
            }
        }

        $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES);
        if ('' === $href) {
            return array();
        }

        $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
        if (!$this->is_homepage_css_bundle_allowed_media($media, $mode)) {
            return array();
        }

        $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
        if ('' === $absolute_url) {
            return array();
        }

        if ($this->should_async_external_css_win_bundle_for_url($absolute_url)) {
            return array();
        }

        if ($this->should_exclude_stylesheet_url_by_fragments($absolute_url, $this->get_homepage_css_bundle_exclude_fragments())) {
            return array();
        }

        $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
            return array();
        }

        $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
        if ('' === $path || '.css' !== strtolower(substr($path, -4))) {
            return array();
        }
        if ($this->is_ultracache_generated_css_output_url($absolute_url)) {
            return array();
        }

        $local_path = $this->resolve_local_path_from_public_url($absolute_url);
        if ('' === $local_path || !is_readable($local_path)) {
            return array();
        }

        return array(
            'url' => $absolute_url,
            'path' => $local_path,
            'media' => $media,
        );
    }

private function is_css_bundle_media_wrapper_safe($media)
    {
        $media = trim((string) $media);
        if ('' === $media || 'all' === strtolower($media)) {
            return false;
        }

        return !preg_match('/[{}<>]/', $media);
    }

private function normalize_icon_font_pattern($pattern)
    {
        $pattern = strtolower(trim((string) $pattern));
        $pattern = preg_replace('/\s+/', ' ', $pattern);
        return is_string($pattern) ? $pattern : '';
    }

private function normalize_icon_font_pattern_list(array $patterns)
    {
        $normalized = array();
        foreach ($patterns as $pattern) {
            $pattern = $this->normalize_icon_font_pattern($pattern);
            if ('' !== $pattern) {
                $normalized[$pattern] = true;
            }
        }

        return array_keys($normalized);
    }

private function icon_font_text_matches_patterns($text, array $patterns, &$matched_pattern = '')
    {
        $text = $this->normalize_icon_font_pattern($text);
        if ('' === $text || empty($patterns)) {
            return false;
        }

        $compact_text = preg_replace('/[\s_-]+/', '', $text);
        $compact_text = is_string($compact_text) ? $compact_text : '';

        foreach ($patterns as $pattern) {
            $pattern = $this->normalize_icon_font_pattern($pattern);
            if ('' === $pattern) {
                continue;
            }

            $compact_pattern = preg_replace('/[\s_-]+/', '', $pattern);
            $compact_pattern = is_string($compact_pattern) ? $compact_pattern : '';
            if (false !== strpos($text, $pattern)
                || ('' !== $compact_text && '' !== $compact_pattern && false !== strpos($compact_text, $compact_pattern))) {
                $matched_pattern = $pattern;
                return true;
            }
        }

        return false;
    }

private function extract_font_family_from_font_face_block($block)
    {
        $block = (string) $block;
        if (preg_match('/font-family\s*:\s*([^;]+);/i', $block, $matches)) {
            return trim(trim((string) $matches[1]), "\"' \t\r\n");
        }

        return '';
    }

private function should_delay_css_font_face_block($block, $css_context, array $settings, &$meta = array())
    {
        $block = (string) $block;
        $css_context = (string) $css_context;
        $family = $this->extract_font_family_from_font_face_block($block);
        $combined = strtolower($family . "\n" . $block);
        $meta = array(
            'family' => $family,
            'matchedPattern' => '',
            'reason' => '',
        );

        $exclude_patterns = $this->normalize_icon_font_pattern_list((array) ($settings['delay_icon_fonts_exclude_list'] ?? array()));
        $include_patterns = $this->normalize_icon_font_pattern_list((array) ($settings['delay_icon_fonts_list'] ?? array()));

        $matched = '';
        if ($this->icon_font_text_matches_patterns($combined, $exclude_patterns, $matched)) {
            $meta['matchedPattern'] = $matched;
            $meta['reason'] = 'excluded';
            return false;
        }

        if ($this->icon_font_text_matches_patterns($combined, $include_patterns, $matched)) {
            $meta['matchedPattern'] = $matched;
            $meta['reason'] = 'user-pattern';
            return true;
        }

        if (empty($settings['delay_icon_fonts_auto_detect'])) {
            return false;
        }

        unset($css_context);
        /*
         * Hidden broad icon-font auto-detection is disabled. The frontend
         * scanner can append detected font family/file fragments into the
         * visible Delay These Fonts / Patterns list.
         */
        return false;
    }

private function split_delayed_icon_font_faces_from_css($css, $source_url, array $settings)
    {
        $css = (string) $css;
        $result = array(
            'body' => $css,
            'delayedCss' => '',
            'delayedCount' => 0,
            'families' => array(),
            'patterns' => array(),
        );

        if ('' === $css || empty($settings['delay_icon_fonts'])) {
            return $result;
        }

        if (false === stripos($css, '@font-face')) {
            return $result;
        }

        $delayed_blocks = array();
        $result['body'] = (string) preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($matches) use (&$delayed_blocks, &$result, $css, $source_url, $settings) {
            $block = (string) ($matches[0] ?? '');
            $meta = array();
            if (!$this->should_delay_css_font_face_block($block, $css, $settings, $meta)) {
                return $block;
            }

            $family = isset($meta['family']) ? trim((string) $meta['family']) : '';
            $pattern = isset($meta['matchedPattern']) ? trim((string) $meta['matchedPattern']) : '';
            if ('' !== $family) {
                $result['families'][] = $family;
            }
            if ('' !== $pattern) {
                $result['patterns'][] = $pattern;
            }
            $delayed_block = $this->normalize_protocol_relative_urls_in_css($block, $source_url);
            if (false !== stripos($delayed_block, '.ttf')) {
                // 2.56.197: delayed icon-font CSS must also prefer same-path
                // WOFF2/WOFF siblings. The generic inline cleanup is not enough
                // for quoted src() values and multi-source icon font declarations.
                $preferred_delayed_block = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_block, $source_url);
                if (is_string($preferred_delayed_block) && '' !== trim($preferred_delayed_block)) {
                    $delayed_block = $preferred_delayed_block;
                }
            }
            if (function_exists('ultracache_optimize_font_face_block')) {
                $delayed_stats = array(
                    'fontDisplayAdded' => 0,
                    'duplicateSrcRemoved' => 0,
                    'ttfSourcesRemoved' => 0,
                    'fontFaceBlocksChanged' => 0,
                );
                $optimized_delayed_block = ultracache_optimize_font_face_block($delayed_block, $delayed_stats);
                if (is_string($optimized_delayed_block) && '' !== trim($optimized_delayed_block)) {
                    $delayed_block = $optimized_delayed_block;
                }
            } else {
                $delayed_block = $this->normalize_font_face_display_in_css($delayed_block);
            }

            $delayed_blocks[] = "/* UltraCache Delayed Font Source: " . (string) $source_url . ('' !== $family ? ' | ' . $family : '') . " */\n" . $delayed_block;
            $result['delayedCount']++;
            return "\n/* UltraCache delayed icon font-face removed: " . ('' !== $family ? $family : 'matched font') . " */\n";
        }, $css);

        if (!is_string($result['body'])) {
            $result['body'] = $css;
            return $result;
        }

        if (!empty($delayed_blocks)) {
            $delayed_css = trim(implode("\n\n", $delayed_blocks)) . "\n";
            if (false !== stripos($delayed_css, '.ttf')) {
                $delayed_css = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_css, $source_url);
            }
            $result['delayedCss'] = trim((string) $delayed_css) . "\n";
            $result['families'] = array_values(array_unique(array_map('strval', $result['families'])));
            $result['patterns'] = array_values(array_unique(array_map('strval', $result['patterns'])));
        }

        return $result;
    }

private function build_frontpage_css_bundle_file($page_url, array $assets, $mode = 'safe')
    {
        $dir = $this->get_frontpage_css_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $signature_parts = array();
        $bundle_body = '';
        $bundle_charset = '';
        $bundle_imports = array();
        $bundle_import_keys = array();
        $used_urls = array();
        $source_details = array();
        $source_bytes_total = 0;
        $delayed_font_css = '';
        $delayed_font_families = array();
        $delayed_font_patterns = array();
        $settings = $this->get_settings();
        $font_policy = $this->get_font_optimization_policy($settings);
        $stats = array(
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

        foreach ($assets as $asset) {
            $path = (string) ($asset['path'] ?? '');
            $url = (string) ($asset['url'] ?? '');
            $media = strtolower(trim((string) ($asset['media'] ?? '')));
            if ('' === $path || '' === $url || !is_readable($path)) {
                return array(
                    'success' => false,
                    'skipped' => false,
                    'message' => __('A stylesheet could not be read.', 'ultracache'),
                    'stats' => $stats,
                );
            }

            $css = ultracache_guarded_asset_file_get_contents($path, 'css', 'frontpage_css_bundle_asset', false);
            if (!is_string($css) || '' === $css) {
                $stats['skipped']++;
                continue;
            }

            $original_bytes = strlen($css);
            $signature_parts[] = $url . '|' . (string) ultracache_safe_filemtime($path, 'frontpage_css_bundle_signature') . '|' . $original_bytes;
            $prepared_css = $this->prepare_css_asset_for_bundle($css, $url);
            $prepared_body = isset($prepared_css['body']) ? (string) $prepared_css['body'] : '';
            $css_image_stats = array();
            $prepared_body = $this->rewrite_stylesheet_css_image_urls_for_media_optimization($prepared_body, $url, $css_image_stats);
            foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $css_image_stat_key) {
                $stats[$css_image_stat_key] = max(0, (int) ($stats[$css_image_stat_key] ?? 0)) + max(0, (int) ($css_image_stats[$css_image_stat_key] ?? 0));
            }
            $font_display_stats = array();
            if (!empty($font_policy['bundle_font_display'])) {
                $prepared_body = $this->normalize_font_face_display_in_css($prepared_body, $font_display_stats);
                $stats['fontDisplayAdded'] += max(0, (int) ($font_display_stats['fontDisplayAdded'] ?? 0));
                $stats['fontFaceBlocksScanned'] += max(0, (int) ($font_display_stats['fontFaceBlocksScanned'] ?? 0));
            }
            $font_split = !empty($font_policy['delay_icon_fonts']) ? $this->split_delayed_icon_font_faces_from_css($prepared_body, $url, $settings) : array('body' => $prepared_body, 'delayedCss' => '', 'delayedCount' => 0, 'families' => array(), 'patterns' => array());
            if (!empty($font_split['delayedCount'])) {
                $prepared_body = (string) ($font_split['body'] ?? $prepared_body);
                $delayed_font_css .= "\n" . (string) ($font_split['delayedCss'] ?? '');
                $delayed_font_families = array_values(array_unique(array_merge($delayed_font_families, (array) ($font_split['families'] ?? array()))));
                $delayed_font_patterns = array_values(array_unique(array_merge($delayed_font_patterns, (array) ($font_split['patterns'] ?? array()))));
                $stats['delayedFontFaceBlocks'] += max(0, (int) ($font_split['delayedCount'] ?? 0));
            }
            $source_bytes_total += $original_bytes;
            $source_details[] = array(
                'url' => $url,
                'bytes' => $original_bytes,
                'preparedBytes' => strlen($prepared_body),
                'type' => $this->get_css_bundle_source_type($url),
                'media' => $media,
                'delayedFontFaceBlocks' => max(0, (int) ($font_split['delayedCount'] ?? 0)),
                'cssImageUrlsScanned' => max(0, (int) ($css_image_stats['cssImageUrlsScanned'] ?? 0)),
                'cssImageUrlsRewritten' => max(0, (int) ($css_image_stats['cssImageUrlsRewritten'] ?? 0)),
                'cssImageUrlsImageSet' => max(0, (int) ($css_image_stats['cssImageUrlsImageSet'] ?? 0)),
                'cssImageUrlsSkipped' => max(0, (int) ($css_image_stats['cssImageUrlsSkipped'] ?? 0)),
            );
            if ('' === $bundle_charset && !empty($prepared_css['charset'])) {
                $bundle_charset = (string) $prepared_css['charset'];
            }
            foreach ((array) ($prepared_css['imports'] ?? array()) as $import_rule) {
                $import_rule = trim((string) $import_rule);
                if ('' === $import_rule) {
                    continue;
                }
                $import_key = strtolower(preg_replace('/\s+/', ' ', $import_rule));
                if (!isset($bundle_import_keys[$import_key])) {
                    $bundle_import_keys[$import_key] = true;
                    $bundle_imports[] = $import_rule;
                }
            }

            $bundle_body .= "
/* UltraCache CSS Bundle Source: " . $url . " */
";
            if ('' !== $media && 'all' !== $media && $this->is_css_bundle_media_wrapper_safe($media)) {
                $bundle_body .= "@media " . $media . " {\n" . $prepared_body . "\n}\n";
            } else {
                $bundle_body .= $prepared_body . "
";
            }
            $used_urls[] = $url;
            $stats['bundled']++;
        }

        if ($stats['bundled'] < 2 || '' === trim($bundle_body)) {
            return array(
                'success' => false,
                'skipped' => true,
                'message' => __('Not enough non-empty stylesheets were eligible for bundling.', 'ultracache'),
                'stats' => $stats,
                'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
            );
        }

        $bundle_prelude = '';
        if ('' !== trim($bundle_charset)) {
            $bundle_prelude .= trim($bundle_charset) . "
";
        }
        if (!empty($bundle_imports)) {
            $bundle_prelude .= implode("
", $bundle_imports) . "

";
        }

        $mode = in_array((string) $mode, array('safe', 'aggressive', 'full', 'leftover'), true) ? (string) $mode : 'safe';
        $bundle_content = trim($bundle_prelude . trim($bundle_body)) . "
";
        $bundle_font_display_stats = array();
        if (!empty($font_policy['bundle_font_display'])) {
            $bundle_content = $this->normalize_font_face_display_in_css($bundle_content, $bundle_font_display_stats);
            $stats['fontDisplayAdded'] += max(0, (int) ($bundle_font_display_stats['fontDisplayAdded'] ?? 0));
            $stats['fontFaceBlocksScanned'] += max(0, (int) ($bundle_font_display_stats['fontFaceBlocksScanned'] ?? 0));
        }
        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $bundle_content = trim(ultracache_strip_source_mapping_url_comments($bundle_content)) . "
";
        }
        $content_hash = md5($bundle_content);
        $signature = md5($mode . '|' . implode('||', $signature_parts) . '|' . $content_hash);
        $filename = 'bundle-' . $mode . '-' . $signature . '.css';
        $file = $dir . $filename;
        clearstatcache(true, $file);
        $existing_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
        if ($existing_hash !== $content_hash) {
            if (!$this->write_cache_variant_atomically($file, $bundle_content)) {
                return array(
                    'success' => false,
                    'skipped' => true,
                    'message' => __('Could not write the generated CSS bundle file.', 'ultracache'),
                    'stats' => $stats,
                );
            }
        }
        clearstatcache(true, $file);
        $verified_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
        if ($verified_hash !== $content_hash) {
            return array(
                'success' => false,
                'skipped' => true,
                'message' => __('Generated CSS bundle file failed verification.', 'ultracache'),
                'stats' => $stats,
            );
        }

        $delayed_font_file = '';
        $delayed_font_url = '';
        $delayed_font_bytes = 0;
        if ('' !== trim($delayed_font_css)) {
            $delayed_font_content = trim($delayed_font_css) . "\n";
            if (function_exists('ultracache_strip_source_mapping_url_comments')) {
                $delayed_font_content = trim(ultracache_strip_source_mapping_url_comments($delayed_font_content)) . "\n";
            }
            if (false !== stripos($delayed_font_content, '.ttf')) {
                $delayed_font_content = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_font_content, home_url('/'));
                $delayed_font_content = trim((string) $delayed_font_content) . "\n";
            }
            $delayed_font_display_stats = array();
            if (!empty($font_policy['bundle_font_display'])) {
                $delayed_font_content = $this->normalize_font_face_display_in_css($delayed_font_content, $delayed_font_display_stats);
                $stats['fontDisplayAdded'] += max(0, (int) ($delayed_font_display_stats['fontDisplayAdded'] ?? 0));
                $stats['fontFaceBlocksScanned'] += max(0, (int) ($delayed_font_display_stats['fontFaceBlocksScanned'] ?? 0));
            }
            $delayed_font_hash = md5($delayed_font_content);
            $delayed_font_filename = 'bundle-' . $mode . '-' . $signature . '-delayed-fonts.css';
            $delayed_font_file = $dir . $delayed_font_filename;
            clearstatcache(true, $delayed_font_file);
            $existing_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
            if ($existing_delayed_hash !== $delayed_font_hash) {
                if (!$this->write_cache_variant_atomically($delayed_font_file, $delayed_font_content)) {
                    return array(
                        'success' => false,
                        'skipped' => true,
                        'message' => __('Could not write the delayed icon-font CSS companion file.', 'ultracache'),
                        'stats' => $stats,
                    );
                }
            }
            clearstatcache(true, $delayed_font_file);
            $verified_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
            if ($verified_delayed_hash !== $delayed_font_hash) {
                return array(
                    'success' => false,
                    'skipped' => true,
                    'message' => __('Delayed icon-font CSS companion file failed verification.', 'ultracache'),
                    'stats' => $stats,
                );
            }
            $delayed_font_bytes = (int) filesize($delayed_font_file);
            $delayed_font_url = ultracache_generated_asset_url('css-bundles', $delayed_font_filename);
            $delayed_font_url = $this->normalize_public_resource_url($delayed_font_url);
        }

        $stats['delayedFontFamilies'] = array_values(array_unique(array_map('strval', $delayed_font_families)));
        $stats['delayedFontPatterns'] = array_values(array_unique(array_map('strval', $delayed_font_patterns)));

        $message = 'Prepared ' . $mode . ' CSS bundle.';
        if (!empty($bundle_imports)) {
            $message .= ' Hoisted ' . count($bundle_imports) . ' @import rule(s).';
        }
        if ($stats['delayedFontFaceBlocks'] > 0) {
            $message .= ' Delayed ' . (int) $stats['delayedFontFaceBlocks'] . ' icon font-face block(s).';
        }
        if (!empty($stats['cssImageUrlsRewritten'])) {
            $message .= ' Rewrote ' . (int) $stats['cssImageUrlsRewritten'] . ' CSS background image URL(s).';
        }
        if ($stats['skipped'] > 0) {
            $message .= ' Skipped ' . (int) $stats['skipped'] . ' empty stylesheet(s).';
        }

        $bundle_url = ultracache_generated_asset_url('css-bundles', $filename);
        $bundle_url = $this->normalize_public_resource_url($bundle_url);

        return array(
            'success' => true,
            'file' => $file,
            'url' => $bundle_url,
            'message' => $message,
            'stats' => $stats,
            'mode' => $mode,
            'signature' => $signature,
            'contentHash' => $content_hash,
            'delayedFontFile' => $delayed_font_file,
            'delayedFontUrl' => $delayed_font_url,
            'delayedFontBytes' => $delayed_font_bytes,
            'delayedFontFaceBlocks' => (int) ($stats['delayedFontFaceBlocks'] ?? 0),
            'delayedFontFamilies' => (array) ($stats['delayedFontFamilies'] ?? array()),
            'delayedFontPatterns' => (array) ($stats['delayedFontPatterns'] ?? array()),
            'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
            'sourceDetails' => $this->normalize_css_bundle_source_details($source_details),
            'sourceBytesTotal' => (int) $source_bytes_total,
        );
    }

private function rewrite_stylesheet_css_image_urls_for_media_optimization($css, $source_url, array &$stats = array())
    {
        $css = (string) $css;
        if ('' === $css || false === stripos($css, 'url(')) {
            return $css;
        }

        if (!class_exists('Ultra_Cache_Media_Converter') || !method_exists('Ultra_Cache_Media_Converter', 'get_instance')) {
            return $css;
        }

        $converter = Ultra_Cache_Media_Converter::get_instance();
        if (!is_object($converter) || !method_exists($converter, 'rewrite_css_image_urls_for_stylesheet')) {
            return $css;
        }

        $media_stats = array();
        try {
            $rewritten = $converter->rewrite_css_image_urls_for_stylesheet($css, (string) $source_url, $media_stats);
        } catch (\Throwable $e) {
            return $css;
        }

        if (is_array($media_stats)) {
            foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $key) {
                $stats[$key] = max(0, (int) ($stats[$key] ?? 0)) + max(0, (int) ($media_stats[$key] ?? 0));
            }
        }

        return is_string($rewritten) && '' !== $rewritten ? $rewritten : $css;
    }

private function prepare_css_asset_for_bundle($css, $source_url)
    {
        $css = (string) $css;
        if ('' === $css) {
            return array('body' => '', 'imports' => array(), 'charset' => '');
        }

        $css = preg_replace('/^\xEF\xBB\xBF/', '', $css);
        $comments = array();
        $masked_css = (string) preg_replace_callback('/\/\*[\s\S]*?\*\//', function ($matches) use (&$comments) {
            $key = '___ULTRACACHE_CSS_COMMENT_' . count($comments) . '___';
            $comments[$key] = (string) $matches[0];
            return $key;
        }, $css);

        $charset = '';
        $masked_css = (string) preg_replace_callback('/@charset\s+(["\'])([^"\']+)\1\s*;/i', function ($matches) use (&$charset) {
            if ('' === $charset) {
                $charset = '@charset "' . addcslashes((string) $matches[2], "\\\"") . '";';
            }
            return "\n";
        }, $masked_css);

        $imports = array();
        $masked_css = (string) preg_replace_callback('/@import\s+(?:url\(\s*"([^"]+)"\s*\)|url\(\s*\'([^\']+)\'\s*\)|url\(\s*([^)]+?)\s*\)|"([^"]+)"|\'([^\']+)\')([^;]*);/i', function ($matches) use (&$imports, $source_url) {
            $import_url = '';
            for ($i = 1; $i <= 5; $i++) {
                if (isset($matches[$i]) && '' !== trim((string) $matches[$i])) {
                    $import_url = trim((string) $matches[$i]);
                    break;
                }
            }
            $suffix = isset($matches[6]) ? trim((string) $matches[6]) : '';
            $rewritten = $this->rewrite_css_import_rule_for_bundle($import_url, $suffix, (string) $matches[0], $source_url);
            if ('' !== $rewritten) {
                $imports[] = $rewritten;
            }
            return "\n";
        }, $masked_css);

        if (!empty($comments)) {
            $masked_css = strtr($masked_css, $comments);
        }

        return array(
            'body' => $this->rewrite_frontpage_css_urls_for_bundle($masked_css, $source_url),
            'imports' => $imports,
            'charset' => $charset,
        );
    }

private function rewrite_css_import_rule_for_bundle($import_url, $suffix, $fallback_rule, $source_url)
    {
        $import_url = trim((string) $import_url);
        $suffix = trim((string) $suffix);
        if ('' === $import_url) {
            return trim((string) $fallback_rule);
        }

        $lower = strtolower($import_url);
        foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
            if (0 === strpos($lower, $prefix)) {
                return trim((string) $fallback_rule);
            }
        }

        $absolute = $this->absolutize_public_resource_url($import_url, $source_url);
        if ('' === $absolute) {
            return trim((string) $fallback_rule);
        }

        $rule = '@import url("' . esc_url_raw($absolute) . '")' . ('' !== $suffix ? ' ' . $suffix : '') . ';';
        if (false !== stripos($absolute, 'fonts.googleapis.com') && method_exists($this, 'rewrite_google_fonts_imports_in_css')) {
            $google_import_stats = array();
            $rewritten_rule = $this->rewrite_google_fonts_imports_in_css($rule, $source_url, $google_import_stats);
            if (is_string($rewritten_rule) && '' !== $rewritten_rule) {
                return trim($rewritten_rule);
            }
        }

        return $rule;
    }

private function rewrite_frontpage_css_urls_for_bundle($css, $source_url)
    {
        $css = (string) $css;
        if ('' === $css) {
            return '';
        }

        $css = (string) preg_replace_callback('/url\(([^)]+)\)/i', function ($matches) use ($source_url) {
            $raw = trim((string) $matches[1]);
            $trimmed = trim($raw, " \t\n\r\0\x0B\"'");
            if ('' === $trimmed) {
                return (string) $matches[0];
            }

            $lower = strtolower($trimmed);
            foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
                if (0 === strpos($lower, $prefix)) {
                    return (string) $matches[0];
                }
            }

            $absolute = $this->absolutize_public_resource_url($trimmed, $source_url);
            if ('' === $absolute) {
                return (string) $matches[0];
            }

            $local_google_font = $this->normalize_google_fonts_cache_url_for_css($absolute);
            if ('' !== $local_google_font) {
                return 'url("' . esc_url_raw($local_google_font) . '")';
            }

            return 'url("' . esc_url_raw($absolute) . '")';
        }, $css);

        $css = $this->normalize_google_fonts_cache_urls_in_css($css);
        $policy = $this->get_font_optimization_policy();

        return !empty($policy['bundle_font_display']) ? $this->normalize_font_face_display_in_css($css) : $css;
    }

private function get_leftover_css_bundle_default_stats()
    {
        return array(
            'enabled' => true,
            'success' => false,
            'candidate_count' => 0,
            'replaced_link_count' => 0,
            'skipped_protected_count' => 0,
            'skipped_nonlocal_count' => 0,
            'skipped_unreadable_count' => 0,
            'skipped_async_count' => 0,
            'skipped_media_count' => 0,
            'skipped_existing_bundle_count' => 0,
            'skipped_reason' => '',
            'bundle_url' => '',
            'bundle_file' => '',
            'bundle_bytes' => 0,
            'source_bytes_total' => 0,
            'source_urls' => array(),
            'protected_urls' => array(),
        );
    }

private function record_leftover_css_bundle_profile(array $stats)
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }
        $this->store_profile['leftover_css_bundle'] = $stats;
    }

private function get_leftover_css_bundle_candidate_from_tag($tag_html, $page_url, array $settings = array())
    {
        $tag_html = (string) $tag_html;
        if (!$this->html_tag_rel_contains_stylesheet($tag_html)) {
            return array('asset' => array(), 'skip' => 'not-stylesheet');
        }

        if (false !== stripos($tag_html, 'data-ultracache-frontpage-css=') || false !== stripos($tag_html, 'data-ultracache-page-css-bundle=') || false !== stripos($tag_html, 'data-ultracache-leftover-css-bundle=') || false !== stripos($tag_html, 'data-ultracache-async-css=')) {
            return array('asset' => array(), 'skip' => 'existing-bundle');
        }

        foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
            if (preg_match('/\b' . preg_quote($attribute, '/') . '\b/i', $tag_html)) {
                return array('asset' => array(), 'skip' => 'async');
            }
        }

        $href = html_entity_decode((string) $this->extract_attribute_from_html_tag($tag_html, 'href'), ENT_QUOTES | ENT_HTML5);
        if ('' === $href) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        $media = strtolower(trim((string) $this->extract_attribute_from_html_tag($tag_html, 'media')));
        if (!$this->is_homepage_css_bundle_allowed_media($media, 'leftover')) {
            return array('asset' => array(), 'skip' => 'media');
        }

        $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
        if ('' === $absolute_url) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        if ($this->should_async_external_css_win_bundle_for_url($absolute_url)) {
            return array('asset' => array(), 'skip' => 'external-css-async-wins-bundle', 'url' => $absolute_url, 'reason' => 'external_css_async_wins_bundle');
        }

        if ($this->should_exclude_stylesheet_url_by_fragments($absolute_url, $this->get_homepage_css_bundle_exclude_fragments())) {
            return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => __('CSS Bundle Exclusions matched', 'ultracache'));
        }

        $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $absolute_url, $tag_html, $this->get_slider_hero_protected_fragments()) : '';
        if ('' !== $slider_fragment) {
            return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => sprintf(
					/* translators: %s: matched slider/hero stylesheet fragment. */
					__('slider/hero stylesheet fragment: %s', 'ultracache'),
					$slider_fragment
				));
        }

        $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
            return array('asset' => array(), 'skip' => 'nonlocal');
        }

        $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
        if ('' === $path || '.css' !== strtolower(substr($path, -4))) {
            return array('asset' => array(), 'skip' => 'nonlocal');
        }
        if ($this->is_ultracache_generated_css_output_url($absolute_url)) {
            return array('asset' => array(), 'skip' => 'ultracache-generated-css');
        }

        $local_path = $this->resolve_local_path_from_public_url($absolute_url);
        if ('' === $local_path || !is_readable($local_path)) {
            return array('asset' => array(), 'skip' => 'unreadable');
        }

        return array(
            'asset' => array(
                'url' => $absolute_url,
                'path' => $local_path,
            ),
            'skip' => '',
        );
    }

private function get_leftover_css_bundle_candidate_from_link_processor($processor, $page_url, array $settings = array())
    {
        $rel = is_object($processor) && method_exists($processor, 'get_attribute') ? $processor->get_attribute('rel') : null;
        if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
            return array('asset' => array(), 'skip' => 'not-stylesheet');
        }

        foreach (array('data-ultracache-frontpage-css', 'data-ultracache-page-css-bundle', 'data-ultracache-leftover-css-bundle', 'data-ultracache-async-css') as $attribute) {
            if (null !== $processor->get_attribute($attribute)) {
                return array('asset' => array(), 'skip' => 'existing-bundle');
            }
        }

        foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
            if (null !== $processor->get_attribute($attribute)) {
                return array('asset' => array(), 'skip' => 'async');
            }
        }

        $href = $processor->get_attribute('href');
        $href = is_string($href) ? html_entity_decode($href, ENT_QUOTES | ENT_HTML5) : '';
        if ('' === $href) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        $media = $processor->get_attribute('media');
        $media = strtolower(trim(is_string($media) ? $media : ''));
        if (!$this->is_homepage_css_bundle_allowed_media($media, 'leftover')) {
            return array('asset' => array(), 'skip' => 'media');
        }

        $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
        if ('' === $absolute_url) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        if ($this->should_async_external_css_win_bundle_for_url($absolute_url)) {
            return array('asset' => array(), 'skip' => 'external-css-async-wins-bundle', 'url' => $absolute_url, 'reason' => 'external_css_async_wins_bundle');
        }

        if ($this->should_exclude_stylesheet_url_by_fragments($absolute_url, $this->get_homepage_css_bundle_exclude_fragments())) {
            return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => __('CSS Bundle Exclusions matched', 'ultracache'));
        }

        $tag_context = $this->get_leftover_css_bundle_link_context_from_processor($processor);
        $slider_fragment = !empty($settings['slider_safe_mode']) ? $this->get_matching_fragment('', $absolute_url, $tag_context, $this->get_slider_hero_protected_fragments()) : '';
        if ('' !== $slider_fragment) {
            return array('asset' => array(), 'skip' => 'protected', 'url' => $absolute_url, 'reason' => sprintf(
                /* translators: %s: matched slider/hero stylesheet fragment. */
                __('slider/hero stylesheet fragment: %s', 'ultracache'),
                $slider_fragment
            ));
        }

        $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
            return array('asset' => array(), 'skip' => 'nonlocal');
        }

        $path = (string) wp_parse_url($absolute_url, PHP_URL_PATH);
        if ('' === $path || '.css' !== strtolower(substr($path, -4))) {
            return array('asset' => array(), 'skip' => 'nonlocal');
        }
        if ($this->is_ultracache_generated_css_output_url($absolute_url)) {
            return array('asset' => array(), 'skip' => 'ultracache-generated-css');
        }

        $local_path = $this->resolve_local_path_from_public_url($absolute_url);
        if ('' === $local_path || !is_readable($local_path)) {
            return array('asset' => array(), 'skip' => 'unreadable');
        }

        return array(
            'asset' => array(
                'url' => $absolute_url,
                'path' => $local_path,
            ),
            'skip' => '',
        );
    }

private function get_leftover_css_bundle_link_context_from_processor($processor)
    {
        if (!is_object($processor) || !method_exists($processor, 'get_attribute')) {
            return '';
        }

        $parts = array();
        foreach (array('id', 'class', 'href', 'media', 'data-ultracache-css-role') as $attribute) {
            $value = $processor->get_attribute($attribute);
            if (is_string($value) && '' !== $value) {
                $parts[] = $attribute . '=' . $value;
            }
        }

        return implode(' ', $parts);
    }

private function get_font_mix_css_bundle_default_stats()
    {
        return array(
            'enabled' => true,
            'success' => false,
            'candidate_count' => 0,
            'replaced_link_count' => 0,
            'skipped_nonlocal_count' => 0,
            'skipped_unreadable_count' => 0,
            'skipped_async_count' => 0,
            'skipped_media_count' => 0,
            'skipped_existing_bundle_count' => 0,
            'skipped_reason' => '',
            'bundle_url' => '',
            'bundle_file' => '',
            'bundle_bytes' => 0,
            'source_bytes_total' => 0,
            'source_urls' => array(),
            'source_details' => array(),
        );
    }

private function record_font_mix_css_bundle_profile(array $stats)
    {
        if (!$this->is_store_profiler_enabled()) {
            return;
        }
        $this->store_profile['font_mix_css_bundle'] = $stats;
    }

private function get_font_mix_css_bundle_candidate_from_link_processor($processor, $page_url)
    {
        $rel = is_object($processor) && method_exists($processor, 'get_attribute') ? $processor->get_attribute('rel') : null;
        if (!$this->html_rel_attribute_contains_stylesheet($rel)) {
            return array('asset' => array(), 'skip' => 'not-stylesheet');
        }

        foreach (array('data-ultracache-frontpage-css', 'data-ultracache-page-css-bundle', 'data-ultracache-leftover-css-bundle', 'data-ultracache-font-mix-css-bundle', 'data-ultracache-async-css') as $attribute) {
            if (null !== $processor->get_attribute($attribute)) {
                return array('asset' => array(), 'skip' => 'existing-bundle');
            }
        }

        foreach (array('onload', 'disabled', 'data-href', 'data-src') as $attribute) {
            if (null !== $processor->get_attribute($attribute)) {
                return array('asset' => array(), 'skip' => 'async');
            }
        }

        $href = $processor->get_attribute('href');
        $href = is_string($href) ? html_entity_decode($href, ENT_QUOTES | ENT_HTML5) : '';
        if ('' === $href) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        $media = $processor->get_attribute('media');
        $media = strtolower(trim(is_string($media) ? $media : ''));
        if (!$this->is_homepage_css_bundle_allowed_media($media, 'leftover')) {
            return array('asset' => array(), 'skip' => 'media');
        }

        $absolute_url = $this->absolutize_public_resource_url($href, '' !== (string) $page_url ? (string) $page_url : home_url('/'));
        if ('' === $absolute_url) {
            return array('asset' => array(), 'skip' => 'unresolved');
        }

        $host = (string) wp_parse_url($absolute_url, PHP_URL_HOST);
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $host || '' === $home_host || strtolower($host) !== strtolower($home_host)) {
            return array('asset' => array(), 'skip' => 'nonlocal');
        }

        $url_path = strtolower((string) wp_parse_url($absolute_url, PHP_URL_PATH));
        if (!$this->is_font_mix_css_bundle_source_url_path($url_path)) {
            return array('asset' => array(), 'skip' => 'not-font-mix');
        }

        $local_path = $this->resolve_local_path_from_public_url($absolute_url);
        $local_path_lc = strtolower(str_replace('\\', '/', (string) $local_path));
        if ('' === $local_path || !is_readable($local_path) || !function_exists('ultracache_generated_asset_local_path_matches') || !ultracache_generated_asset_local_path_matches($local_path_lc, array('optimized-css'))) {
            return array('asset' => array(), 'skip' => 'unreadable');
        }

        return array(
            'asset' => array(
                'url' => $this->normalize_public_resource_url($absolute_url),
                'path' => $local_path,
                'media' => $media,
            ),
            'skip' => '',
        );
    }

private function is_font_mix_css_bundle_source_url_path($path)
    {
        $path = strtolower((string) $path);
        if ('' === $path || '.css' !== substr($path, -4)) {
            return false;
        }

        if (!function_exists('ultracache_generated_asset_reference_matches') || !ultracache_generated_asset_reference_matches($path, array('optimized-css'))) {
            return false;
        }

        return 0 === strpos((string) basename($path), 'css-font-mix-');
    }

private function build_font_mix_css_bundle_file(array $assets)
    {
        $dir = $this->get_frontpage_css_dir();
        if ('' === $dir) {
            return array('success' => false, 'message' => 'css-bundle-dir-unavailable');
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $signature_parts = array();
        $bundle_body = '';
        $bundle_charset = '';
        $bundle_imports = array();
        $bundle_import_keys = array();
        $source_details = array();
        $source_bytes_total = 0;
        $used_urls = array();
        $delayed_font_css = '';
        $delayed_font_families = array();
        $delayed_font_patterns = array();
        $delayed_font_face_blocks = 0;
        $settings = $this->get_settings();
        $font_policy = $this->get_font_optimization_policy($settings);

        foreach ($assets as $asset) {
            $path = (string) ($asset['path'] ?? '');
            $url = (string) ($asset['url'] ?? '');
            $media = strtolower(trim((string) ($asset['media'] ?? '')));
            if ('' === $path || '' === $url || !is_readable($path)) {
                return array('success' => false, 'message' => __('A generated font-mix stylesheet could not be read.', 'ultracache'));
            }

            $css = ultracache_guarded_asset_file_get_contents($path, 'css', 'font_mix_css_bundle_asset', true);
            if (!is_string($css) || '' === trim($css)) {
                continue;
            }

            $original_bytes = strlen($css);
            $signature_parts[] = $url . '|' . (string) ultracache_safe_filemtime($path, 'font_mix_css_bundle_signature') . '|' . $original_bytes;
            $prepared_css = $this->prepare_css_asset_for_bundle($css, $url);
            $prepared_body = isset($prepared_css['body']) ? (string) $prepared_css['body'] : '';
            $font_split = !empty($font_policy['delay_icon_fonts']) ? $this->split_delayed_icon_font_faces_from_css($prepared_body, $url, $settings) : array('body' => $prepared_body, 'delayedCss' => '', 'delayedCount' => 0, 'families' => array(), 'patterns' => array());
            if (!empty($font_split['delayedCount'])) {
                $prepared_body = (string) ($font_split['body'] ?? $prepared_body);
                $delayed_font_css .= "\n" . (string) ($font_split['delayedCss'] ?? '');
                $delayed_font_families = array_values(array_unique(array_merge($delayed_font_families, (array) ($font_split['families'] ?? array()))));
                $delayed_font_patterns = array_values(array_unique(array_merge($delayed_font_patterns, (array) ($font_split['patterns'] ?? array()))));
                $delayed_font_face_blocks += max(0, (int) ($font_split['delayedCount'] ?? 0));
            }
            if ('' === trim($prepared_body)) {
                continue;
            }

            $source_bytes_total += $original_bytes;
            $source_details[] = array(
                'url' => $url,
                'bytes' => $original_bytes,
                'preparedBytes' => strlen($prepared_body),
                'type' => 'font-mix-css',
                'media' => $media,
                'delayedFontFaceBlocks' => max(0, (int) ($font_split['delayedCount'] ?? 0)),
            );

            if ('' === $bundle_charset && !empty($prepared_css['charset'])) {
                $bundle_charset = (string) $prepared_css['charset'];
            }
            foreach ((array) ($prepared_css['imports'] ?? array()) as $import_rule) {
                $import_rule = trim((string) $import_rule);
                if ('' === $import_rule) {
                    continue;
                }
                $import_key = strtolower(preg_replace('/\s+/', ' ', $import_rule));
                if (!isset($bundle_import_keys[$import_key])) {
                    $bundle_import_keys[$import_key] = true;
                    $bundle_imports[] = $import_rule;
                }
            }

            $bundle_body .= "\n/* UltraCache Font-Mix CSS Bundle Source: " . $url . " */\n";
            if ('' !== $media && 'all' !== $media && $this->is_css_bundle_media_wrapper_safe($media)) {
                $bundle_body .= "@media " . $media . " {\n" . $prepared_body . "\n}\n";
            } else {
                $bundle_body .= $prepared_body . "\n";
            }
            $used_urls[] = $url;
        }

        if (count($used_urls) < 2 || '' === trim($bundle_body)) {
            return array(
                'success' => false,
                'skipped' => true,
                'message' => 'not-enough-non-empty-font-mix-css',
                'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
            );
        }

        $bundle_prelude = '';
        if ('' !== trim($bundle_charset)) {
            $bundle_prelude .= trim($bundle_charset) . "\n";
        }
        if (!empty($bundle_imports)) {
            $bundle_prelude .= implode("\n", $bundle_imports) . "\n\n";
        }

        $bundle_content = trim($bundle_prelude . trim($bundle_body)) . "\n";
        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $bundle_content = trim(ultracache_strip_source_mapping_url_comments($bundle_content)) . "\n";
        }
        $content_hash = md5($bundle_content);
        $signature = md5('font-mix|' . implode('||', $signature_parts) . '|' . $content_hash);
        $filename = 'bundle-font-mix-' . $signature . '.css';
        $file = $dir . $filename;
        clearstatcache(true, $file);
        $existing_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
        if ($existing_hash !== $content_hash) {
            if (!$this->write_cache_variant_atomically($file, $bundle_content)) {
                return array('success' => false, 'skipped' => true, 'message' => __('Could not write the generated font-mix CSS bundle file.', 'ultracache'));
            }
        }

        clearstatcache(true, $file);
        $verified_hash = (is_readable($file) && filesize($file) > 0) ? md5_file($file) : '';
        if ($verified_hash !== $content_hash) {
            return array('success' => false, 'skipped' => true, 'message' => __('Generated font-mix CSS bundle file failed verification.', 'ultracache'));
        }

        $bundle_url = ultracache_generated_asset_url('css-bundles', $filename);
        $bundle_url = $this->normalize_public_resource_url($bundle_url);

        $delayed_font_file = '';
        $delayed_font_url = '';
        $delayed_font_bytes = 0;
        if ('' !== trim($delayed_font_css)) {
            $delayed_font_content = trim($delayed_font_css) . "\n";
            if (function_exists('ultracache_strip_source_mapping_url_comments')) {
                $delayed_font_content = trim(ultracache_strip_source_mapping_url_comments($delayed_font_content)) . "\n";
            }
            if (false !== stripos($delayed_font_content, '.ttf')) {
                $delayed_font_content = $this->rewrite_font_face_ttf_sources_to_preferred_formats($delayed_font_content, home_url('/'));
                $delayed_font_content = trim((string) $delayed_font_content) . "\n";
            }
            $delayed_font_display_stats = array();
            if (!empty($font_policy['bundle_font_display'])) {
                $delayed_font_content = $this->normalize_font_face_display_in_css($delayed_font_content, $delayed_font_display_stats);
            }

            if ('' !== trim($delayed_font_content) && false !== stripos($delayed_font_content, '@font-face')) {
                $delayed_dir = ultracache_generated_asset_dir('font-css');
                if ('' === $delayed_dir) {
                    return array('success' => false, 'skipped' => true, 'message' => __('Delayed icon-font directory is unavailable.', 'ultracache'));
                }
                if (!is_dir($delayed_dir)) {
                    wp_mkdir_p($delayed_dir);
                }
                $delayed_index_file = $delayed_dir . 'index.php';
                if (!file_exists($delayed_index_file)) {
                    ultracache_safe_file_put_contents($delayed_index_file, "<?php\n// Silence is golden.\n");
                }

                $delayed_font_hash = md5('font-mix-delayed|' . implode('||', $signature_parts) . '|' . md5($delayed_font_content));
                $delayed_font_filename = 'delayed-font-mix-' . $delayed_font_hash . '.css';
                $delayed_font_file = $delayed_dir . $delayed_font_filename;
                clearstatcache(true, $delayed_font_file);
                $existing_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
                $delayed_content_hash = md5($delayed_font_content);
                if ($existing_delayed_hash !== $delayed_content_hash) {
                    ultracache_safe_file_put_contents($delayed_font_file, $delayed_font_content, LOCK_EX, 'delayed font-mix icon font css');
                }
                clearstatcache(true, $delayed_font_file);
                $verified_delayed_hash = (is_readable($delayed_font_file) && filesize($delayed_font_file) > 0) ? md5_file($delayed_font_file) : '';
                if ($verified_delayed_hash !== $delayed_content_hash) {
                    return array('success' => false, 'skipped' => true, 'message' => __('Delayed font-mix icon-font CSS file failed verification.', 'ultracache'));
                }

                $delayed_font_bytes = (int) filesize($delayed_font_file);
                $delayed_font_url = ultracache_generated_asset_url('font-css', $delayed_font_filename);
                $delayed_font_url = $this->normalize_public_resource_url($delayed_font_url);
            }
        }

        return array(
            'success' => true,
            'file' => $file,
            'url' => $bundle_url,
            'message' => 'Prepared font-mix CSS bundle.',
            'signature' => $signature,
            'contentHash' => $content_hash,
            'delayedFontFile' => $delayed_font_file,
            'delayedFontUrl' => $delayed_font_url,
            'delayedFontBytes' => $delayed_font_bytes,
            'delayedFontFaceBlocks' => (int) $delayed_font_face_blocks,
            'delayedFontFamilies' => array_values(array_unique(array_map('strval', $delayed_font_families))),
            'delayedFontPatterns' => array_values(array_unique(array_map('strval', $delayed_font_patterns))),
            'sourceUrls' => array_values(array_unique(array_map('strval', $used_urls))),
            'sourceDetails' => $this->normalize_css_bundle_source_details($source_details),
            'sourceBytesTotal' => (int) $source_bytes_total,
        );
    }
}
