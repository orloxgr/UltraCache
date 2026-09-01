<?php
/**
 * Slider Revolution media adapter for UltraCache media optimization.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Slider_Revolution_Trait
{
    /**
     * Rewrite Slider Revolution's canonical image list after its own optimizer.
     *
     * Slider Revolution preserves the original image in `orig` and may replace
     * `src` with its own generated WebP at priority 10 on `sr_get_image_lists`.
     * UltraCache runs later and uses `orig` as the stable canonical source.
     * Existing UltraCache AVIF/WebP variants win when available; if no existing
     * variant is available, Slider Revolution's current `src` remains untouched.
     * A real `src2` is treated as its own high-DPI source and is never synthesized
     * when absent.
     *
     * Missing canonical Slider Revolution images are inventoried into the normal
     * UltraCache background media queue. The current render remains lookup-only:
     * this callback never performs synchronous image conversion or waits for a
     * queued conversion to finish.
     *
     * @param mixed $images Slider Revolution image-list records.
     * @param mixed $output Slider Revolution output object.
     * @return mixed
     */
    public function filter_slider_revolution_image_lists($images, $output = null) {
        unset($output);

        if (!$this->is_slider_revolution_media_adapter_enabled() || !is_array($images) || empty($images)) {
            return $images;
        }

        $rewritten = array();
        $seen = array();

        foreach ($images as $image) {
            if (!is_array($image)) {
                $rewritten[] = $image;
                continue;
            }

            $current_src = isset($image['src']) && is_string($image['src']) ? trim($image['src']) : '';
            $original_src = isset($image['orig']) && is_string($image['orig']) ? trim($image['orig']) : '';

            // `orig` is the stable source identity. Never replace it with an
            // optimized URL because SR7 uses it to map JSON/PMH references.
            if ('' !== $original_src) {
                $this->maybe_inventory_slider_revolution_image_source($original_src);
                $best_src = $this->get_existing_slider_revolution_variant_url($original_src);
                if ($best_src) {
                    $image['src'] = $best_src;
                } elseif ('' === $current_src) {
                    // Defensive fallback for malformed third-party image-list
                    // filters: preserve Slider Revolution's original source.
                    $image['src'] = $original_src;
                }
            }

            // Preserve true Retina/high-DPI semantics. Only rewrite src2 when
            // Slider Revolution (or another integration) supplied it already.
            if (isset($image['src2']) && is_string($image['src2']) && '' !== trim($image['src2'])) {
                $src2 = trim($image['src2']);
                if ($src2 !== $original_src) {
                    $this->maybe_inventory_slider_revolution_image_source($src2);
                }
                $best_src2 = $this->get_existing_slider_revolution_variant_url($src2);
                if ($best_src2) {
                    $image['src2'] = $best_src2;
                }
            }

            // Remove only records that are now byte-for-byte identical in all
            // fields. Different orig/src2/lib/alt/title metadata is preserved.
            $fingerprint = md5(serialize($image));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $rewritten[] = $image;
        }

        return $rewritten;
    }


    /**
     * Finalize Slider Revolution image-list URLs for the current HTML cache bucket.
     *
     * Warm/cache storage can render WordPress once with the `orig` Accept bucket and
     * derive AVIF/WebP HTML buckets from that shared source document. The normal
     * `sr_get_image_lists` filter therefore cannot be the final authority for those
     * derived buckets: it ran while the source render still had the orig Accept
     * context. This pass runs during UltraCache's final full-document media rewrite,
     * where `media_rewrite_accept_context` is already scoped to the target bucket.
     *
     * Slider Revolution preserves the canonical original URL as base64 in
     * `data-dbsrc`. Decode that identity, resolve an already-existing UltraCache
     * variant for the target bucket, and update only `data-src`. A real `data-src2`
     * is resolved independently from its own URL and is never synthesized when
     * absent. `data-dbsrc` itself stays byte-for-byte unchanged.
     *
     * @param string $html Full frontend HTML.
     * @return string
     */
    private function finalize_slider_revolution_image_lists_for_media_bucket($html) {
        if (
            !$this->is_slider_revolution_media_adapter_enabled()
            || !is_string($html)
            || '' === $html
            || false === stripos($html, '<image_lists')
            || false === stripos($html, 'data-dbsrc')
        ) {
            return $html;
        }

        $updated = preg_replace_callback(
            '~<image_lists\b[^>]*>.*?</image_lists>~is',
            function ($matches) {
                $block = isset($matches[0]) ? (string) $matches[0] : '';
                if ('' === $block || false === stripos($block, '<img')) {
                    return $block;
                }

                try {
                    $processor = new WP_HTML_Tag_Processor($block);
                    $changed = false;

                    while ($processor->next_tag('IMG')) {
                        $encoded_original = $processor->get_attribute('data-dbsrc');
                        $current_src = $processor->get_attribute('data-src');
                        if (
                            !is_string($encoded_original)
                            || '' === trim($encoded_original)
                            || !is_string($current_src)
                            || '' === trim($current_src)
                        ) {
                            continue;
                        }

                        $canonical_original = $this->decode_slider_revolution_data_dbsrc($encoded_original);
                        if ('' === $canonical_original) {
                            continue;
                        }

                        // Keep the background inventory path additive. On normal
                        // warm pages this is usually already a no-op because the
                        // canonical image was inventoried during sr_get_image_lists.
                        $this->maybe_inventory_slider_revolution_image_source($canonical_original);

                        $resolved_src = $this->get_existing_slider_revolution_variant_url($canonical_original);
                        $resolved_src = $this->sanitize_rewritten_public_url_raw($resolved_src);
                        if ('' !== $resolved_src && $resolved_src !== $current_src) {
                            $processor->set_attribute('data-src', $resolved_src);
                            $changed = true;
                        }

                        // Preserve true Retina/high-DPI semantics. There is no
                        // encoded orig2 field in SR7 image_lists, so an existing
                        // src2 is treated as its own canonical public source.
                        $current_src2 = $processor->get_attribute('data-src2');
                        if (is_string($current_src2) && '' !== trim($current_src2)) {
                            $resolved_src2 = $this->get_existing_slider_revolution_variant_url($current_src2);
                            $resolved_src2 = $this->sanitize_rewritten_public_url_raw($resolved_src2);
                            if ('' !== $resolved_src2 && $resolved_src2 !== $current_src2) {
                                $processor->set_attribute('data-src2', $resolved_src2);
                                $changed = true;
                            }
                        }
                    }

                    if (!$changed) {
                        return $block;
                    }

                    $rewritten_block = $processor->get_updated_html();
                    return is_string($rewritten_block) && '' !== $rewritten_block ? $rewritten_block : $block;
                } catch (\Throwable $e) {
                    return $block;
                }
            },
            $html
        );

        return is_string($updated) && '' !== $updated ? $updated : $html;
    }

    /**
     * Decode Slider Revolution's base64 `data-dbsrc` canonical source URL.
     *
     * @param string $encoded Encoded data-dbsrc attribute value.
     * @return string Normalized local public URL, or an empty string when invalid.
     */
    private function decode_slider_revolution_data_dbsrc($encoded) {
        $encoded = html_entity_decode(trim((string) $encoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ('' === $encoded) {
            return '';
        }

        $encoded = preg_replace('/\s+/', '', $encoded);
        if (!is_string($encoded) || '' === $encoded) {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || '' === trim($decoded)) {
            return '';
        }

        $decoded = trim($decoded);
        $normalized = $this->normalize_public_url($decoded);
        if ('' === $normalized) {
            return '';
        }

        $source = $this->get_local_image_source_descriptor_from_public_url($normalized);
        if (empty($source)) {
            return '';
        }

        return $normalized;
    }

    /**
     * Whether the Slider Revolution media adapter is active for this request.
     *
     * The adapter is intentionally tied to the existing "Fix sliders / hero
     * sections" switch and Media Optimization. Real logged-in frontend requests
     * still bypass SR-specific rewriting, but scoped public cache-storage rewrites
     * must not inherit the authentication state of the admin/worker that launched
     * the warm job. Generic image rewriting remains independent.
     *
     * @return bool
     */
    private function is_slider_revolution_media_adapter_enabled() {
        if (!$this->is_media_optimization_enabled()) {
            return false;
        }

        $is_cache_storage_context = isset($this->media_rewrite_cache_storage_context)
            && true === $this->media_rewrite_cache_storage_context;

        if (
            !$is_cache_storage_context
            && function_exists('ultracache_should_bypass_logged_in_frontend_optimizations')
            && ultracache_should_bypass_logged_in_frontend_optimizations()
        ) {
            return false;
        }

        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
            $settings = Ultra_Cache_WP::get_settings();
            return !empty($settings['slider_safe_mode']);
        }

        $settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
        return !empty($settings['sliderSafeModeEnabled']);
    }


    /**
     * Inventory a canonical Slider Revolution source into the normal media queue.
     *
     * The parent queue format is `best`, so one queued attachment produces the
     * current output policy (AVIF and optional WebP fallback, or WebP-only). The
     * first missing/stale concrete format is only the discovery reason used by
     * the existing affected-page queue logic; no separate Slider queue exists.
     *
     * @param string $public_url Canonical local public image URL.
     * @return bool True when this call queued work, otherwise false.
     */
    private function maybe_inventory_slider_revolution_image_source($public_url) {
        $public_url = trim((string) $public_url);
        if ('' === $public_url || !method_exists($this, 'maybe_queue_missing_optimized_media_for_source')) {
            return false;
        }

        $formats = method_exists($this, 'get_best_media_conversion_formats')
            ? (array) $this->get_best_media_conversion_formats()
            : array('avif', 'webp');

        $needs_filesystem_lookup = false;
        foreach ($formats as $format) {
            $format = strtolower(trim((string) $format));
            if (!in_array($format, array('avif', 'webp'), true) || !$this->media_output_mode_allows($format)) {
                continue;
            }
            $terminal = method_exists($this, 'get_terminal_media_variant_lookup_from_public_url')
                ? $this->get_terminal_media_variant_lookup_from_public_url($public_url, $format)
                : array('known' => false);
            if (empty($terminal['known'])) {
                $needs_filesystem_lookup = true;
                break;
            }
        }
        if (!$needs_filesystem_lookup) {
            return false;
        }

        $source = $this->get_local_image_source_descriptor_from_public_url($public_url);
        if (empty($source)) {
            return false;
        }

        foreach ($formats as $format) {
            $format = strtolower(trim((string) $format));
            if (!in_array($format, array('avif', 'webp'), true) || !$this->media_output_mode_allows($format)) {
                continue;
            }

            $lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, $format);
            if (!empty($lookup['url'])) {
                continue;
            }

            $status = (string) ($lookup['status'] ?? 'missing');
            if (!in_array($status, array('missing', 'stale'), true)) {
                continue;
            }

            $queued = $this->maybe_queue_missing_optimized_media_for_source(
                $public_url,
                $source,
                $format,
                $status,
                array(
                    'mtime' => (int) ($lookup['sourceMtime'] ?? 0),
                    'size'  => (int) ($lookup['sourceSize'] ?? 0),
                )
            );
            if ($queued) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an already-existing UltraCache variant without queueing/generating.
     *
     * @param string $public_url Canonical local public image URL.
     * @return string|false
     */
    private function get_existing_slider_revolution_variant_url($public_url) {
        $public_url = trim((string) $public_url);
        if ('' === $public_url) {
            return false;
        }

        $avif_terminal = $this->can_serve_avif() && method_exists($this, 'get_terminal_media_variant_lookup_from_public_url')
            ? $this->get_terminal_media_variant_lookup_from_public_url($public_url, 'avif')
            : array('known' => false, 'url' => false);
        if (!empty($avif_terminal['known']) && !empty($avif_terminal['url'])) {
            return (string) $avif_terminal['url'];
        }
        $webp_terminal = $this->can_serve_webp() && method_exists($this, 'get_terminal_media_variant_lookup_from_public_url')
            ? $this->get_terminal_media_variant_lookup_from_public_url($public_url, 'webp')
            : array('known' => false, 'url' => false);
        if (!empty($webp_terminal['known']) && !empty($webp_terminal['url'])) {
            return (string) $webp_terminal['url'];
        }
        if ((!$this->can_serve_avif() || !empty($avif_terminal['known'])) && (!$this->can_serve_webp() || !empty($webp_terminal['known']))) {
            return false;
        }

        $source = $this->get_local_image_source_descriptor_from_public_url($public_url);
        if (empty($source)) {
            return false;
        }

        if ($this->can_serve_avif() && empty($avif_terminal['known'])) {
            $lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, 'avif');
            if (!empty($lookup['url'])) {
                return (string) $lookup['url'];
            }
        }

        if ($this->can_serve_webp() && empty($webp_terminal['known'])) {
            $lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, 'webp');
            if (!empty($lookup['url'])) {
                return (string) $lookup['url'];
            }
        }

        return false;
    }
}
