<?php
/**
 * One-to-one local stylesheet mirrors for media background URL rewriting.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_CSS_Media_Rewrite_Trait
{
    private function rewrite_linked_local_stylesheet_media_urls_in_html($html)
    {
        $html = is_string($html) ? $html : '';
        if ('' === $html || false === stripos($html, '<link') || false === stripos($html, '.css') || !$this->html_tag_processor_available()) {
            return $html;
        }

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $changed = false;

            while ($processor->next_tag('LINK')) {
                if (!$this->html_rel_attribute_contains_stylesheet($processor->get_attribute('rel'))) {
                    continue;
                }

                if (null !== $processor->get_attribute('data-ultracache-css-media-rewritten')) {
                    continue;
                }

                $href = $processor->get_attribute('href');
                if (!is_string($href) || '' === trim($href)) {
                    continue;
                }

                $asset = $this->build_media_rewritten_css_asset_for_current_request($href);
                if (empty($asset['css_url'])) {
                    continue;
                }

                $processor->set_attribute('href', esc_url((string) $asset['css_url']));
                $processor->set_attribute('data-ultracache-css-role', 'optimized-css');
                $processor->set_attribute('data-ultracache-css-blocking-reason', 'optimized-css-layout-risk');
                $processor->set_attribute('data-ultracache-css-media-rewritten', '1');
                $changed = true;
            }

            if (!$changed) {
                return $html;
            }

            $updated = $processor->get_updated_html();
            return is_string($updated) && '' !== $updated ? $updated : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function build_media_rewritten_css_asset_for_current_request($url)
    {
        static $request_assets = array();

        $absolute_url = $this->absolutize_public_resource_url((string) $url, home_url('/'));
        $source_url = $this->normalize_public_resource_url($absolute_url);
        if ('' === $source_url) {
            return array();
        }

        if (array_key_exists($source_url, $request_assets)) {
            return is_array($request_assets[$source_url]) ? $request_assets[$source_url] : array();
        }

        $request_assets[$source_url] = array();

        $source_host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $source_url_path = strtolower((string) wp_parse_url($source_url, PHP_URL_PATH));
        if ('' === $source_host || '' === $home_host || $source_host !== $home_host || '.css' !== substr($source_url_path, -4)) {
            return array();
        }

        if ($this->is_ultracache_generated_css_output_url($source_url)) {
            return array();
        }

        $source_path = $this->resolve_local_path_from_public_url($source_url);
        if ('' === $source_path || !is_readable($source_path)) {
            return array();
        }

        $css = ultracache_guarded_asset_file_get_contents($source_path, 'optimized-css', 'build_media_rewritten_css_asset', true);
        if (!is_string($css) || '' === $css || false === stripos($css, 'url(')) {
            return array();
        }

        $prepared = $this->prepare_css_asset_for_bundle($css, $source_url, '', false);
        if (empty($prepared['eligible'])) {
            return array();
        }

        $parts = array();
        if (!empty($prepared['charset'])) {
            $parts[] = trim((string) $prepared['charset']);
        }
        foreach ((array) ($prepared['imports'] ?? array()) as $import_rule) {
            $import_rule = trim((string) $import_rule);
            if ('' !== $import_rule) {
                $parts[] = $import_rule;
            }
        }
        $body = trim((string) ($prepared['body'] ?? ''));
        if ('' !== $body) {
            $parts[] = $body;
        }

        $normalized_css = implode("\n", $parts);
        if ('' === trim($normalized_css)) {
            return array();
        }

        $stats = array();
        $rewritten_css = $this->rewrite_stylesheet_css_image_urls_for_media_optimization($normalized_css, $source_url, $stats);
        if (!is_string($rewritten_css) || '' === trim($rewritten_css) || empty($stats['cssImageUrlsRewritten'])) {
            return array();
        }

        if (function_exists('ultracache_strip_source_mapping_url_comments')) {
            $rewritten_css = trim((string) ultracache_strip_source_mapping_url_comments($rewritten_css));
        }
        if ('' === $rewritten_css) {
            return array();
        }
        $rewritten_css .= "\n";

        $dir = ultracache_generated_asset_dir('optimized-css');
        if ('' === $dir) {
            return array();
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            return array();
        }

        $index_file = $dir . 'index.php';
        if (!file_exists($index_file)) {
            ultracache_safe_file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $content_hash = md5($rewritten_css);
        $filename = 'css-media-' . md5($source_url . '|' . $content_hash) . '.css';
        $file = $dir . $filename;
        $existing_hash = is_readable($file) && filesize($file) > 0 ? md5_file($file) : '';
        if ($existing_hash !== $content_hash && !$this->write_cache_variant_atomically($file, $rewritten_css)) {
            return array();
        }

        clearstatcache(true, $file);
        $verified_hash = is_readable($file) && filesize($file) > 0 ? md5_file($file) : '';
        if ($verified_hash !== $content_hash) {
            return array();
        }

        $asset = array(
            'css_url' => ultracache_generated_asset_url('optimized-css', $filename),
            'file' => $file,
            'sourceUrl' => $source_url,
            'sourceBytes' => strlen($css),
            'cssBytes' => strlen($rewritten_css),
            'cssImageUrlOptimization' => array(
                'sourceUrl' => $source_url,
                'scanned' => max(0, (int) ($stats['cssImageUrlsScanned'] ?? 0)),
                'rewritten' => max(0, (int) ($stats['cssImageUrlsRewritten'] ?? 0)),
                'imageSet' => max(0, (int) ($stats['cssImageUrlsImageSet'] ?? 0)),
                'skipped' => max(0, (int) ($stats['cssImageUrlsSkipped'] ?? 0)),
            ),
        );

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'record_css_rewrite_map')) {
            Ultra_Cache_WP::record_css_rewrite_map($source_url, (string) $asset['css_url'], array(
                'source_path' => $source_path,
                'generated_path' => $file,
                'optimization_type' => 'css-media-rewrite',
                'content_hash' => $content_hash,
            ));
        }

        $request_assets[$source_url] = $asset;
        return $asset;
    }
}
