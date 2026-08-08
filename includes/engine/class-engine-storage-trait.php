<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_Storage_Trait
{
    private $last_cache_write_error = array();
    private $last_atomic_write_error = array();

    private function reset_cache_write_error()
    {
        $this->last_cache_write_error = array();
    }

    private function set_cache_write_error($code, $message, array $context = array())
    {
        $this->last_cache_write_error = array_merge(
            array(
                'code'    => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
            ),
            $context
        );
    }

    private function get_last_cache_write_error()
    {
        return is_array($this->last_cache_write_error) ? $this->last_cache_write_error : array();
    }

    private function get_last_cache_write_error_message()
    {
        $error = $this->get_last_cache_write_error();
        if (empty($error['message'])) {
            return '';
        }

        $message = (string) $error['message'];
        if (!empty($error['code'])) {
            $message .= ' [' . (string) $error['code'] . ']';
        }

        if (!empty($error['missing']) && is_array($error['missing'])) {
            $message .= ' Missing: ' . implode(', ', array_slice(array_map('strval', $error['missing']), 0, 5));
        }

        if (!empty($error['file'])) {
            $message .= ' File: ' . basename((string) $error['file']);
        }

        return $message;
    }

    private function reset_atomic_write_error()
    {
        $this->last_atomic_write_error = array();
    }

    private function set_atomic_write_error($code, $message, array $context = array())
    {
        $this->last_atomic_write_error = array_merge(
            array(
                'code'    => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
            ),
            $context
        );
    }

    private function get_last_atomic_write_error()
    {
        return is_array($this->last_atomic_write_error) ? $this->last_atomic_write_error : array();
    }

    private function wait_for_page_cache_file($file_path, $timeout_seconds = 12.0)
    {
        $file_path = (string) $file_path;
        if ('' === $file_path) {
            return false;
        }

        $deadline = microtime(true) + max(0.5, (float) $timeout_seconds);
        do {
            clearstatcache(true, $file_path);
            if (is_readable($file_path) && filesize($file_path) > 255) {
                return true;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function is_force_refresh_loopback_request()
    {
        $force_refresh = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_FORCE_REFRESH'));
        if ('1' !== $force_refresh && 'true' !== strtolower((string) $force_refresh)) {
            return false;
        }

        $internal = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_INTERNAL_REQUEST'));
        $warm = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_WARM'));
        $profile = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_STORE_PROFILE'));
        if ('1' !== $internal || ('1' !== $warm && '1' !== $profile)) {
            return false;
        }

        $token = sanitize_text_field(ultracache_server_value('HTTP_X_ULTRACACHE_TOKEN'));
        return '' !== $token
            && function_exists('ultracache_validate_runtime_control_token')
            && ultracache_validate_runtime_control_token($token);
    }

    private function maybe_serve_cached_file_during_wp_boot($reason = 'early-hit')
    {
        if ($this->is_force_refresh_loopback_request()) {
            if (!headers_sent()) {
                header('X-Ultra-Cache-Force-Refresh: wp-engine');
            }
            return false;
        }

        if ($this->is_profile_bypass_request()) {
            if (!headers_sent()) {
                header('X-Ultra-Cache-Profile-Bypass: wp-engine');
            }
            return false;
        }

        $this->profile_request_checkpoint('early_hit_before_current_url');
        $url = $this->get_current_request_url();
        $this->profile_request_checkpoint('early_hit_after_current_url', array('url_length' => strlen((string) $url)));
        if ('' === $url) {
            return false;
        }

        $this->profile_request_checkpoint('early_hit_before_cache_path');
        $file_path = $this->get_cache_path($url);
        $this->profile_request_checkpoint('early_hit_after_cache_path', array('file_path_empty' => '' === (string) $file_path ? 'yes' : 'no'));
        $this->profile_request_checkpoint('early_hit_before_file_stat');
        if ('' === $file_path || !is_readable($file_path) || filesize($file_path) <= 255) {
            $this->profile_request_checkpoint('early_hit_no_file_return');
            return false;
        }
        $this->profile_request_checkpoint('early_hit_after_file_stat');

        return $this->maybe_serve_cache_file_path($file_path, 'HIT', $reason);
    }

    private function get_accept_encoding_quality($header_value, $encoding)
    {
        $header_value = strtolower((string) $header_value);
        $encoding = strtolower(trim((string) $encoding));
        if ('' === $header_value || '' === $encoding) {
            return 0.0;
        }

        $wildcard_quality = null;
        foreach (explode(',', $header_value) as $item) {
            $parts = array_map('trim', explode(';', (string) $item));
            $token = strtolower((string) array_shift($parts));
            if ('' === $token) {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (1 === preg_match('/\Aq\s*=\s*(0(?:\.\d+)?|1(?:\.0+)?)\z/i', (string) $parameter, $matches)) {
                    $quality = max(0.0, min(1.0, (float) $matches[1]));
                    break;
                }
            }

            if ($token === $encoding) {
                return $quality;
            }

            if ('*' === $token) {
                $wildcard_quality = $quality;
            }
        }

        return null === $wildcard_quality ? 0.0 : (float) $wildcard_quality;
    }

    private function select_cached_html_encoding_variant($file_path, $cache_root, $esi_parent = false)
    {
        $selection = array(
            'file'     => (string) $file_path,
            'encoding' => 'identity',
            'header'   => '',
        );

        $accept_encoding = (string) ultracache_server_value('HTTP_ACCEPT_ENCODING');
        $brotli_quality = $this->get_accept_encoding_quality($accept_encoding, 'br');
        $gzip_quality = $this->get_accept_encoding_quality($accept_encoding, 'gzip');
        $candidates = array();

        if (!$esi_parent && $brotli_quality > 0.0) {
            $candidates[] = array(
                'file'     => (string) $file_path . '.br',
                'encoding' => 'brotli',
                'header'   => 'br',
                'quality'  => $brotli_quality,
                'priority' => 2,
            );
        }

        if ($gzip_quality > 0.0) {
            $candidates[] = array(
                'file'     => (string) $file_path . '.gz',
                'encoding' => 'gzip',
                'header'   => 'gzip',
                'quality'  => $gzip_quality,
                'priority' => 1,
            );
        }

        usort(
            $candidates,
            static function ($left, $right) {
                if ((float) $left['quality'] === (float) $right['quality']) {
                    return (int) $right['priority'] <=> (int) $left['priority'];
                }

                return (float) $right['quality'] <=> (float) $left['quality'];
            }
        );

        foreach ($candidates as $candidate) {
            $candidate_file = ultracache_normalize_filesystem_path_for_guard((string) $candidate['file']);
            $valid_name = 1 === preg_match(
                '/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html(?:\.gz|\.br)\z/',
                basename($candidate_file)
            );
            if (
                !$valid_name
                || !ultracache_path_is_within_root($candidate_file, $cache_root)
                || !is_readable($candidate_file)
            ) {
                continue;
            }

            return array(
                'file'     => $candidate_file,
                'encoding' => (string) $candidate['encoding'],
                'header'   => (string) $candidate['header'],
            );
        }

        return $selection;
    }

    private function maybe_serve_cache_file_path($file_path, $status = 'HIT', $reason = '')
    {
        $file_path = (string) $file_path;
        $cache_root = defined('ULTRACACHE_CACHE_DIR') ? ultracache_normalize_filesystem_path_for_guard(ULTRACACHE_CACHE_DIR) : '';
        $resolved_file = '' !== $file_path ? ultracache_normalize_filesystem_path_for_guard($file_path) : '';
        $valid_name = '' !== $resolved_file
            && 1 === preg_match('/\Aindex-(?:orig|avif|webp)-[a-f0-9]{32}\.html\z/', basename($resolved_file));

        if (
            '' === $cache_root
            || '' === $resolved_file
            || !$valid_name
            || !ultracache_path_is_within_root($resolved_file, $cache_root)
            || !is_readable($resolved_file)
        ) {
            return false;
        }

        $base_file_path = $resolved_file;
        $esi_metadata = $this->get_page_cache_esi_metadata($base_file_path);
        $variant = $this->select_cached_html_encoding_variant($base_file_path, $cache_root, !empty($esi_metadata));
        $serve_file_path = (string) $variant['file'];
        $encoding_bucket = (string) $variant['encoding'];
        $content_encoding = (string) $variant['header'];
        $status = strtoupper((string) $status);
        $is_esi_parent = !empty($esi_metadata);
        $validator_metadata = $is_esi_parent
            ? array()
            : $this->get_cached_html_validator_metadata($serve_file_path, $encoding_bucket);
        $not_modified = !$is_esi_parent
            && !headers_sent()
            && 'STALE' !== $status
            && !empty($validator_metadata)
            && $this->cached_html_request_is_not_modified($validator_metadata);

        $html = '';
        if (!$not_modified) {
            $this->profile_request_checkpoint('early_hit_before_file_read', array(
                'file'     => basename($serve_file_path),
                'encoding' => $encoding_bucket,
            ));
            $html = ultracache_safe_file_get_contents($serve_file_path);
            $this->profile_request_checkpoint('early_hit_after_file_read', array(
                'html_bytes' => is_string($html) ? strlen($html) : 0,
                'encoding'   => $encoding_bucket,
            ));
            if (!is_string($html) || '' === $html) {
                return false;
            }
        }

        $freshness_mtime = $this->get_page_cache_freshness_mtime($base_file_path);
        $age = $freshness_mtime ? max(0, time() - (int) $freshness_mtime) : 0;
        if (!headers_sent()) {
            if (!$not_modified) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            $html_bucket = $this->infer_bucket_from_cache_path($base_file_path);
            $this->send_html_variant_headers($html_bucket);
            $this->send_shared_html_cache_headers('STALE' !== $status, $html_bucket, '', $esi_metadata);
            $this->send_cached_html_validator_headers($validator_metadata);
            if ($is_esi_parent && $this->should_send_source_debug_header()) {
                header('X-UltraCache-ESI-Validators: disabled');
            }
            if (!$not_modified && '' !== $content_encoding) {
                header('Content-Encoding: ' . $content_encoding);
                header('X-UltraCache-Encoding: ' . $encoding_bucket);
            } elseif ($this->should_send_source_debug_header()) {
                header('X-UltraCache-Encoding: ' . $encoding_bucket);
            }
            header('X-Ultra-Cache: ' . $status);
            if ($this->should_send_source_debug_header()) {
                header('X-Ultra-Cache-Source: wp-engine');
            }
            header('X-Ultra-Cache-Age: ' . (string) $age);
            if ('' !== (string) $reason) {
                header('X-Ultra-Cache-Reason: ' . substr(preg_replace('/[^A-Za-z0-9_. -]/', '-', (string) $reason), 0, 120));
            }
            if ($not_modified) {
                $this->send_cached_html_not_modified_status();
            }
        }

        $visit_url = $this->get_current_request_url();
        if (
            '' !== (string) $visit_url
            && class_exists('Ultra_Cache_WP')
            && method_exists('Ultra_Cache_WP', 'has_pending_canonical_warm_url_hint')
            && Ultra_Cache_WP::has_pending_canonical_warm_url_hint($visit_url)
        ) {
            $this->defer_store_post_response_action('visit_cache_satisfaction', array(
                'url' => $visit_url,
                'source' => 'visit-hit',
            ));
        }

        $this->record_analytics_hit();
        if ($not_modified || 'HEAD' === strtoupper((string) ultracache_server_value('REQUEST_METHOD'))) {
            return true;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Full-page cache payload; canonical cache-root and strict UltraCache filename validation are enforced immediately before output.
        echo $html;
        return true;
    }

    private function validate_cached_html_css_bundle_refs($html, $cache_file = '')
    {
        $html = (string) $html;
        if ('' === $html || !ultracache_generated_asset_reference_matches($html)) {
            return true;
        }

        $has_generated_css = ultracache_generated_asset_reference_matches($html, array('css-bundles', 'font-css', 'optimized-css'));
        if (!$has_generated_css) {
            return true;
        }

        $this->profile_request_checkpoint('css_bundle_ref_validation_before_scan');
        $missing = $this->get_missing_css_bundle_refs_from_html($html);
        $this->profile_request_checkpoint('css_bundle_ref_validation_after_scan', array('missing_count' => count($missing)));
        if (empty($missing)) {
            return true;
        }

        $cache_file = (string) $cache_file;
        if ('' !== $cache_file) {
            ultracache_safe_unlink($cache_file);
            ultracache_safe_unlink($cache_file . '.gz');
            ultracache_safe_unlink($cache_file . '.br');
            ultracache_safe_unlink($cache_file . '.fresh');
            ultracache_safe_unlink($cache_file . '.esi');
        }

        $this->record_cache_event('stale-generated-css-html-invalidated', array(
            'file' => $cache_file,
            'missing' => array_slice(array_values($missing), 0, 20),
            'missing_count' => count($missing),
        ));

        if (!headers_sent()) {
            header('X-Ultra-Cache-Stale-Generated-CSS: invalidated');
        }

        return false;
    }

    private function get_missing_css_bundle_refs_from_html($html)
    {
        $html = (string) $html;
        $missing = array();
        $generated_base_path = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '';
        if ('' === $html || '' === $generated_base_path || false === stripos($html, trim($generated_base_path, '/'))) {
            return $missing;
        }

        $generated_base_pattern = preg_quote(trailingslashit($generated_base_path), '~');
        $generated_asset_patterns = array(
            '~(?:https?:)?//[^\s\"\'<>]+' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s\"\'<>?#)]+\.css~i',
            '~' . $generated_base_pattern . '(?:css-bundles|font-css|optimized-css)/[^\s\"\'<>?#)]+\.css~i',
        );


        $refs = array();
        $collect_generated_refs = function ($value) use (&$refs, $generated_asset_patterns) {
            $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
            if ('' === $value || !ultracache_generated_asset_reference_matches($value)) {
                return;
            }

            foreach ($generated_asset_patterns as $generated_asset_pattern) {
                $matches = array();
                $matched = preg_match_all($generated_asset_pattern, $value, $matches);
                if (false === $matched || empty($matches[0]) || !is_array($matches[0])) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $refs[] = (string) $match;
                }
            }
        };

        /*
         * Only validate load-bearing generated CSS references. UltraCache stores
         * internal source/debug metadata in data-* attributes such as
         * data-ultracache-css-bundle-original-href. Those metadata URLs are not browser
         * requests and must not block page-cache writes when their generated
         * intermediate files have already been bundled/removed.
         */
        $link_tags = array();
        if (preg_match_all('#<link\b[^>]*>#i', $html, $link_tags) && !empty($link_tags[0])) {
            foreach ($link_tags[0] as $link_tag) {
                $attr_matches = array();
                if (!preg_match_all('/\s([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', (string) $link_tag, $attr_matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($attr_matches as $attr_match) {
                    $attr_name = strtolower((string) $attr_match[1]);
                    if ('href' !== $attr_name) {
                        continue;
                    }

                    $attr_value = isset($attr_match[3]) && '' !== $attr_match[3]
                        ? (string) $attr_match[3]
                        : (isset($attr_match[4]) && '' !== $attr_match[4] ? (string) $attr_match[4] : (string) $attr_match[5]);
                    $collect_generated_refs($attr_value);
                }
            }
        }

        $style_blocks = array();
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $style_blocks) && !empty($style_blocks[1])) {
            foreach ($style_blocks[1] as $style_block) {
                $collect_generated_refs($style_block);
            }
        }

        $style_attr_tags = array();
        if (preg_match_all('#<[^>]+\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>#i', $html, $style_attr_tags) && !empty($style_attr_tags[0])) {
            foreach ($style_attr_tags[0] as $tag_with_style) {
                $attr_matches = array();
                if (!preg_match_all('/\s([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', (string) $tag_with_style, $attr_matches, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($attr_matches as $attr_match) {
                    $attr_name = strtolower((string) $attr_match[1]);
                    if ('style' !== $attr_name) {
                        continue;
                    }

                    $attr_value = isset($attr_match[3]) && '' !== $attr_match[3]
                        ? (string) $attr_match[3]
                        : (isset($attr_match[4]) && '' !== $attr_match[4] ? (string) $attr_match[4] : (string) $attr_match[5]);
                    $collect_generated_refs($attr_value);
                }
            }
        }

        $refs = array_values(array_unique(array_map('strval', $refs)));
        $allowed_dirs = array(
            'css-bundles' => wp_normalize_path(ultracache_generated_asset_dir('css-bundles')),
            'font-css' => wp_normalize_path(ultracache_generated_asset_dir('font-css')),
            'optimized-css' => wp_normalize_path(ultracache_generated_asset_dir('optimized-css')),
        );
        foreach ($refs as $ref) {
            $path = (string) wp_parse_url($ref, PHP_URL_PATH);
            if ('' === $path) {
                $path = $ref;
            }

            $generated_base_path = function_exists('ultracache_generated_asset_public_path') ? ultracache_generated_asset_public_path() : '';
            $generated_ref_pattern = '' !== $generated_base_path ? '#^' . preg_quote(trailingslashit($generated_base_path), '#') . '(css-bundles|font-css|optimized-css)/([^/]+\.css)$#i' : '';
            if ('' === $generated_ref_pattern || !preg_match($generated_ref_pattern, rawurldecode($path), $match)) {
                continue;
            }

            $bucket = strtolower((string) $match[1]);
            $basename = basename((string) $match[2]);
            if ('' === $basename || empty($allowed_dirs[$bucket]) || false === preg_match('/^[A-Za-z0-9_.-]+\.css$/', $basename)) {
                continue;
            }

            $file = wp_normalize_path($allowed_dirs[$bucket] . $basename);
            clearstatcache(true, $file);
            if (!is_readable($file) || filesize($file) <= 0) {
                $missing[$bucket . '/' . $basename] = $bucket . '/' . $basename;
            }
        }

        return array_values($missing);
    }

    private function write_cache_file($file_path, $html, $url = '')
    {
        $file_path = (string) $file_path;
        $this->reset_cache_write_error();
        $this->set_cache_write_error('started', 'Cache write started.', array('file' => $file_path));

        $html = $this->profile_store_stage('final_google_fonts_rewrite_inside_write', $html, function ($html) {
            return $this->apply_final_google_fonts_rewrite_before_cache_store($html);
        });
        $html = $this->profile_store_stage('final_font_display_rewrite_inside_write', $html, function ($html) {
            return $this->apply_final_font_display_rewrite_before_cache_store($html);
        });
        if (method_exists($this, 'remove_hrefless_ultracache_link_placeholders')) {
            $html = $this->profile_store_stage('remove_hrefless_link_placeholders_inside_write', $html, function ($html) {
                return $this->remove_hrefless_ultracache_link_placeholders($html);
            });
        }

        $dir = dirname($file_path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
            $this->set_cache_write_error('mkdir_failed', 'Could not create cache directory.', array('file' => $file_path, 'dir' => $dir));
            $this->record_cache_event('store-mkdir-failed', array('file' => $file_path, 'dir' => $dir));
            return false;
        }

        if (!ultracache_path_is_writable($dir)) {
            $this->set_cache_write_error('dir_not_writable', 'Cache directory is not writable.', array('file' => $file_path, 'dir' => $dir));
            $this->record_cache_event('store-dir-not-writable', array('file' => $file_path, 'dir' => $dir));
            return false;
        }

        $family_lock = $this->acquire_page_cache_variant_family_lock($file_path);
        if (!empty($family_lock['required']) && empty($family_lock['acquired'])) {
            $this->set_cache_write_error(
                'variant_family_lock_busy',
                'Page-cache variant-family lock is busy.',
                array('file' => $file_path, 'lock' => (string) ($family_lock['name'] ?? ''))
            );
            $this->record_cache_event('variant-family-lock-busy', $this->get_last_cache_write_error());
            return false;
        }

        try {
            $capacity = $this->ensure_page_cache_variant_family_capacity($file_path);
            if (empty($capacity['allowed'])) {
                $this->set_cache_write_error(
                    'variant_cap_reached',
                    'Page-cache variant-family cap could not be satisfied.',
                    array_merge(array('file' => $file_path), is_array($capacity) ? $capacity : array())
                );
                $this->record_cache_event('variant-family-cap-failed', $this->get_last_cache_write_error());
                return false;
            }

            $write_lock_name = 'page-cache-write-' . md5((string) $file_path);
            if (!$this->acquire_runtime_lock($write_lock_name, 90)) {
                $this->set_cache_write_error('write_lock_busy', 'Page cache write lock is busy.', array('file' => $file_path));
                $this->record_cache_event('store-write-lock-busy', array('file' => $file_path));
                return false;
            }

            try {
                $missing_css_refs = $this->get_missing_css_bundle_refs_from_html($html);
                if (!empty($missing_css_refs)) {
                    $this->set_cache_write_error('missing_generated_css_refs', 'Cached HTML references generated CSS files that are missing on disk.', array(
                        'file' => $file_path,
                        'missing' => array_slice(array_values($missing_css_refs), 0, 20),
                        'missing_count' => count($missing_css_refs),
                    ));
                    $this->record_cache_event('skip-store-missing-css-bundle-ref', array(
                        'file' => $file_path,
                        'missing' => array_slice(array_values($missing_css_refs), 0, 20),
                        'missing_count' => count($missing_css_refs),
                    ));
                    return false;
                }

                $esi_metadata = method_exists($this, 'get_esi_parent_metadata_from_html')
                    ? $this->get_esi_parent_metadata_from_html($html)
                    : array();
                $is_esi_parent = !empty($esi_metadata);
                $previous_esi_marker = $this->get_page_cache_esi_metadata($file_path);

                if ($is_esi_parent) {
                    if (!$this->write_page_cache_esi_marker($file_path, $esi_metadata)) {
                        $atomic_error = $this->get_last_atomic_write_error();
                        $this->set_cache_write_error(
                            'esi_marker_failed',
                            'Could not write the page-cache ESI marker.',
                            array_merge(array('file' => $file_path), is_array($atomic_error) ? $atomic_error : array())
                        );
                        $this->record_cache_event('store-esi-marker-failed', $this->get_last_cache_write_error());
                        return false;
                    }

                    // Remove representations that can bypass or cannot participate
                    // in Varnish ESI processing before the new parent is visible.
                    ultracache_safe_unlink($file_path . '.br');
                    $this->delete_apache_static_html_aliases_for_cache_file($file_path, true);
                }

                if (!$this->write_cache_variant_atomically($file_path, $html)) {
                    if ($is_esi_parent) {
                        $this->restore_page_cache_esi_marker($file_path, $previous_esi_marker);
                    }

                    $atomic_error = $this->get_last_atomic_write_error();
                    $this->set_cache_write_error(
                        !empty($atomic_error['code']) ? (string) $atomic_error['code'] : 'atomic_write_failed',
                        !empty($atomic_error['message']) ? (string) $atomic_error['message'] : 'Atomic cache write failed.',
                        array_merge(array('file' => $file_path), is_array($atomic_error) ? $atomic_error : array())
                    );
                    $this->record_cache_event('store-atomic-write-failed', $this->get_last_cache_write_error());
                    return false;
                }

                if (!$is_esi_parent) {
                    ultracache_safe_unlink($this->get_page_cache_esi_marker_path($file_path));
                }

                $settings = $this->get_settings();
                if (!empty($settings['gzip_enabled']) && function_exists('gzencode')) {
                    $compressed = gzencode($html, 9);
                    if (false === $compressed || !$this->write_cache_variant_atomically($file_path . '.gz', $compressed)) {
                        ultracache_safe_unlink($file_path . '.gz');
                    }
                } else {
                    ultracache_safe_unlink($file_path . '.gz');
                }

                if (!$is_esi_parent && !empty($settings['brotli_enabled']) && function_exists('brotli_compress')) {
                    $compressed = brotli_compress($html, 5, BROTLI_TEXT);
                    if (false === $compressed || !$this->write_cache_variant_atomically($file_path . '.br', $compressed)) {
                        ultracache_safe_unlink($file_path . '.br');
                    }
                } else {
                    ultracache_safe_unlink($file_path . '.br');
                }

                if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'track_cache_asset_refs_for_file')) {
                    Ultra_Cache_WP::track_cache_asset_refs_for_file($file_path, $html);
                }

                if ($is_esi_parent) {
                    $this->delete_apache_static_html_aliases_for_cache_file($file_path, true);
                } else {
                    $this->write_apache_static_html_alias_for_cache_file($file_path, $html, $url, $settings);
                }

                if (!$this->write_page_cache_freshness_marker($file_path)) {
                    $atomic_error = $this->get_last_atomic_write_error();
                    $marker_context = array('file' => $file_path);
                    if (!empty($atomic_error['code'])) {
                        $marker_context['atomic_code'] = (string) $atomic_error['code'];
                    }
                    if (!empty($atomic_error['message'])) {
                        $marker_context['atomic_message'] = (string) $atomic_error['message'];
                    }
                    if (!empty($atomic_error['path'])) {
                        $marker_context['path'] = (string) $atomic_error['path'];
                    }
                    $this->set_cache_write_error(
                        'freshness_marker_failed',
                        'Could not update the page-cache freshness marker.',
                        $marker_context
                    );
                    $this->record_cache_event('store-freshness-marker-failed', $this->get_last_cache_write_error());
                    return false;
                }

                $this->set_cache_write_error('ok', 'Cache file written successfully.', array(
                    'file' => $file_path,
                    'variant_family' => (string) ($capacity['hash'] ?? ''),
                    'rotated_families' => array_values(array_map('strval', (array) ($capacity['rotatedFamilies'] ?? array()))),
                ));
                return true;
            } finally {
                $this->release_runtime_lock($write_lock_name);
            }
        } finally {
            $this->release_page_cache_variant_family_lock($family_lock);
        }
    }

    /**
     * Return the canonical ESI sidecar path for an identity cache file.
     *
     * @param string $file_path Identity HTML cache file.
     * @return string
     */
    private function get_page_cache_esi_marker_path($file_path)
    {
        $file_path = (string) $file_path;
        if ('' === $file_path || 1 !== preg_match('/^index-(orig|webp|avif)-[a-f0-9]{32}\.html$/', basename($file_path))) {
            return '';
        }

        $cache_root = defined('ULTRACACHE_CACHE_DIR') ? ultracache_normalize_filesystem_path_for_guard(ULTRACACHE_CACHE_DIR) : '';
        $resolved = ultracache_normalize_filesystem_path_for_guard($file_path);
        if ('' === $cache_root || '' === $resolved || !ultracache_path_is_within_root($resolved, $cache_root)) {
            return '';
        }

        return $resolved . '.esi';
    }

    /**
     * Normalize ESI parent metadata read from HTML or a sidecar.
     *
     * @param mixed $metadata Candidate metadata.
     * @return array
     */
    private function normalize_page_cache_esi_metadata($metadata)
    {
        if (!is_array($metadata)) {
            return array();
        }

        $version = (int) ($metadata['version'] ?? 0);
        if (!in_array($version, array(1, 2, 3, 4), true)) {
            return array();
        }

        $fragment_count = max(0, min(64, (int) ($metadata['fragmentCount'] ?? 0)));
        $public_count = max(0, min($fragment_count, (int) ($metadata['publicCount'] ?? $fragment_count)));
        $private_count = max(0, min($fragment_count - $public_count, (int) ($metadata['privateCount'] ?? 0)));
        if ($fragment_count <= 0 || ($public_count + $private_count) <= 0) {
            return array();
        }

        $unique_fragment_count = max(0, min($fragment_count, (int) ($metadata['uniqueFragmentCount'] ?? $fragment_count)));
        $min_ttl = max(0, min(WEEK_IN_SECONDS, (int) ($metadata['minTtl'] ?? 0)));
        $max_ttl = max($min_ttl, min(WEEK_IN_SECONDS, (int) ($metadata['maxTtl'] ?? $min_ttl)));

        return array(
            'version'                 => 4,
            'fragmentCount'           => $fragment_count,
            'publicCount'             => $public_count,
            'privateCount'            => $private_count,
            'uniqueFragmentCount'     => $unique_fragment_count,
            'woocommerceMiniCart'     => 4 === $version && !empty($metadata['woocommerceMiniCart']) && $private_count > 0,
            'minTtl'                  => $min_ttl,
            'maxTtl'                  => $max_ttl,
        );
    }

    /**
     * Read and validate ESI metadata for one cached parent.
     *
     * @param string $file_path Identity HTML cache file.
     * @return array
     */
    private function get_page_cache_esi_metadata($file_path)
    {
        $marker_path = $this->get_page_cache_esi_marker_path($file_path);
        if ('' === $marker_path || !is_readable($marker_path)) {
            return array();
        }

        $size = ultracache_safe_filesize($marker_path, 'page_cache_esi_marker_size');
        if (false === $size || (int) $size <= 0 || (int) $size > 2048) {
            return array();
        }

        $raw = ultracache_safe_file_get_contents($marker_path, 'page_cache_esi_marker_read');
        if (!is_string($raw) || '' === trim($raw)) {
            return array();
        }

        return $this->normalize_page_cache_esi_metadata(json_decode($raw, true));
    }

    /**
     * Write ESI metadata before publishing a parent containing ESI includes.
     *
     * @param string $file_path Identity HTML cache file.
     * @param array  $metadata  Canonical ESI metadata.
     * @return bool
     */
    private function write_page_cache_esi_marker($file_path, array $metadata)
    {
        $marker_path = $this->get_page_cache_esi_marker_path($file_path);
        $metadata = $this->normalize_page_cache_esi_metadata($metadata);
        if ('' === $marker_path || empty($metadata)) {
            return false;
        }

        $payload = wp_json_encode($metadata, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || '' === $payload) {
            return false;
        }

        return $this->write_cache_variant_atomically($marker_path, $payload . PHP_EOL);
    }

    /**
     * Restore the previous ESI marker after an identity write failure.
     *
     * @param string $file_path Identity HTML cache file.
     * @param array  $metadata  Previous normalized metadata.
     * @return void
     */
    private function restore_page_cache_esi_marker($file_path, array $metadata)
    {
        $marker_path = $this->get_page_cache_esi_marker_path($file_path);
        if ('' === $marker_path) {
            return;
        }

        if (empty($metadata)) {
            ultracache_safe_unlink($marker_path);
            return;
        }

        $this->write_page_cache_esi_marker($file_path, $metadata);
    }

    /**
     * Return cache freshness without changing representation validators.
     *
     * The HTML payload mtime represents the last content change. A separate
     * bounded marker advances after every successful regeneration, including
     * byte-identical origin refreshes, so local TTL renewal does not alter
     * ETag or Last-Modified.
     *
     * @param string $file_path Identity HTML cache file.
     * @return int
     */
    private function get_page_cache_freshness_mtime($file_path)
    {
        $file_path = (string) $file_path;
        if ('' === $file_path) {
            return 0;
        }

        $freshness_marker = $file_path . '.fresh';
        $freshness_mtime = is_readable($freshness_marker)
            ? ultracache_safe_filemtime($freshness_marker, 'page_cache_freshness_marker')
            : false;
        if (false !== $freshness_mtime && (int) $freshness_mtime > 0) {
            return (int) $freshness_mtime;
        }

        $payload_mtime = is_readable($file_path)
            ? ultracache_safe_filemtime($file_path, 'page_cache_payload_freshness_fallback')
            : false;

        return false !== $payload_mtime ? max(0, (int) $payload_mtime) : 0;
    }

    /**
     * Advance the freshness marker after a complete page-cache write.
     *
     * @param string $file_path Identity HTML cache file.
     * @return bool
     */
    private function write_page_cache_freshness_marker($file_path)
    {
        $file_path = (string) $file_path;
        if ('' === $file_path) {
            return false;
        }

        $marker_payload = sprintf('%.6F', microtime(true)) . PHP_EOL;
        return $this->write_cache_variant_atomically($file_path . '.fresh', $marker_payload);
    }

    private function should_write_apache_static_html_alias($url, array $settings)
    {
        $url = trim((string) $url);
        if ('' === $url || empty($settings['apache_static_html_delivery'])) {
            return false;
        }

        $original_parts = wp_parse_url($url);
        if (!is_array($original_parts) || !empty($original_parts['query'])) {
            return false;
        }

        if (method_exists($this, 'is_cacheable_local_url') && !$this->is_cacheable_local_url($url)) {
            return false;
        }

        $normalized = method_exists($this, 'normalize_url') ? $this->normalize_url($url) : $url;
        if ('' === $normalized) {
            return false;
        }

        $normalized_parts = wp_parse_url($normalized);
        return is_array($normalized_parts) && empty($normalized_parts['query']);
    }

    private function get_apache_static_html_alias_path_for_cache_file($file_path)
    {
        $file_path = (string) $file_path;
        $basename = basename($file_path);
        if (!preg_match('/^index-(orig|webp|avif)-[a-f0-9]{32}\.html$/', $basename, $matches)) {
            return '';
        }

        return trailingslashit(dirname($file_path)) . 'index-' . (string) $matches[1] . '.html';
    }

    private function write_apache_static_html_alias_for_cache_file($file_path, $html, $url, array $settings)
    {
        if (!$this->should_write_apache_static_html_alias($url, $settings)) {
            return false;
        }

        $alias_path = $this->get_apache_static_html_alias_path_for_cache_file($file_path);
        if ('' === $alias_path) {
            return false;
        }

        if (!$this->write_cache_variant_atomically($alias_path, $html)) {
            $this->record_cache_event('apache-static-alias-write-failed', array(
                'file'  => (string) $file_path,
                'alias' => $alias_path,
            ));
            return false;
        }

        if (!empty($settings['gzip_enabled']) && function_exists('gzencode')) {
            $compressed = gzencode($html, 9);
            if (false === $compressed || !$this->write_cache_variant_atomically($alias_path . '.gz', $compressed)) {
                ultracache_safe_unlink($alias_path . '.gz');
            }
        } else {
            ultracache_safe_unlink($alias_path . '.gz');
        }

        if (!empty($settings['brotli_enabled']) && function_exists('brotli_compress')) {
            $compressed = brotli_compress($html, 5, BROTLI_TEXT);
            if (false === $compressed || !$this->write_cache_variant_atomically($alias_path . '.br', $compressed)) {
                ultracache_safe_unlink($alias_path . '.br');
            }
        } else {
            ultracache_safe_unlink($alias_path . '.br');
        }

        $this->write_cache_variant_atomically($alias_path . '.source', basename((string) $file_path));
        $this->record_cache_event('apache-static-alias-written', array(
            'file'  => (string) $file_path,
            'alias' => $alias_path,
        ));

        return true;
    }

    private function delete_apache_static_html_aliases_for_cache_file($file, $force = false)
    {
        $alias_path = $this->get_apache_static_html_alias_path_for_cache_file($file);
        if ('' === $alias_path) {
            return;
        }

        $source_path = $alias_path . '.source';
        $source = file_exists($source_path) ? trim((string) ultracache_safe_file_get_contents($source_path, 'delete_apache_static_html_alias')) : '';
        if (!$force && '' !== $source && $source !== basename((string) $file)) {
            return;
        }

        foreach (array($alias_path, $alias_path . '.gz', $alias_path . '.br', $source_path) as $alias_variant) {
            if (file_exists($alias_variant)) {
                ultracache_safe_unlink($alias_variant);
            }
        }
    }

    private function get_page_cache_variant_family_cap()
    {
        /**
         * Safety cap for distinct normalized URL hashes within one cache path.
         * One family may contain orig, WebP, and AVIF HTML representations.
         */
        $cap = (int) apply_filters('ultracache_page_cache_variant_family_cap', 8);
        return max(3, min(50, $cap));
    }

    private function parse_page_cache_variant_file($file_path)
    {
        $file_path = wp_normalize_path((string) $file_path);
        if ('' === $file_path) {
            return array();
        }

        $basename = basename($file_path);
        if (1 !== preg_match('/^index-(orig|webp|avif)-([a-f0-9]{32})\.html$/', $basename, $matches)) {
            return array();
        }

        $dir = wp_normalize_path(dirname($file_path));
        $cache_root = defined('ULTRACACHE_CACHE_DIR') ? ultracache_normalize_filesystem_path_for_guard(ULTRACACHE_CACHE_DIR) : '';
        if ('' === $cache_root || '' === $dir || !ultracache_path_is_within_root($dir, $cache_root)) {
            return array();
        }

        return array(
            'file' => $file_path,
            'dir' => $dir,
            'bucket' => (string) $matches[1],
            'hash' => (string) $matches[2],
        );
    }

    private function acquire_page_cache_variant_family_lock($file_path)
    {
        $parsed = $this->parse_page_cache_variant_file($file_path);
        if (empty($parsed)) {
            return array('required' => false, 'acquired' => true, 'name' => '', 'token' => '');
        }

        $lock_name = 'ultracache_page_cache_variant_family_' . sha1((string) $parsed['dir']);
        $token = 'variant-family-' . wp_generate_password(32, false, false);
        $acquired = function_exists('ultracache_acquire_lock')
            && ultracache_acquire_lock(
                $lock_name,
                $token,
                120,
                array('dir' => (string) $parsed['dir'], 'hash' => (string) $parsed['hash'])
            );

        return array(
            'required' => true,
            'acquired' => (bool) $acquired,
            'name' => $lock_name,
            'token' => $token,
        );
    }

    private function release_page_cache_variant_family_lock(array $lock)
    {
        if (
            empty($lock['required'])
            || empty($lock['acquired'])
            || empty($lock['name'])
            || empty($lock['token'])
            || !function_exists('ultracache_release_lock')
        ) {
            return;
        }

        ultracache_release_lock((string) $lock['name'], (string) $lock['token']);
    }

    private function collect_page_cache_variant_families($dir)
    {
        $dir = wp_normalize_path((string) $dir);
        $families = array();
        if ('' === $dir || !is_dir($dir) || !is_readable($dir)) {
            return $families;
        }

        foreach (array('orig', 'webp', 'avif') as $bucket) {
            $matches = glob(trailingslashit($dir) . 'index-' . $bucket . '-*.html');
            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $file) {
                $parsed = $this->parse_page_cache_variant_file($file);
                if (empty($parsed) || (string) $parsed['dir'] !== $dir) {
                    continue;
                }

                $hash = (string) $parsed['hash'];
                $mtime = ultracache_safe_filemtime((string) $parsed['file'], 'page_cache_variant_family_scan');
                if (!isset($families[$hash])) {
                    $families[$hash] = array(
                        'hash' => $hash,
                        'lastModified' => 0,
                        'files' => array(),
                    );
                }

                $families[$hash]['files'][(string) $parsed['bucket']] = (string) $parsed['file'];
                $families[$hash]['lastModified'] = max(
                    (int) $families[$hash]['lastModified'],
                    false === $mtime ? 0 : (int) $mtime
                );
            }
        }

        return $families;
    }

    private function delete_page_cache_variant_family($dir, $hash)
    {
        $dir = wp_normalize_path((string) $dir);
        $hash = strtolower((string) $hash);
        if ('' === $dir || 1 !== preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return false;
        }

        $remaining = array();
        foreach (array('orig', 'webp', 'avif') as $bucket) {
            $file = trailingslashit($dir) . 'index-' . $bucket . '-' . $hash . '.html';
            $this->delete_cache_variants($file);
            foreach (array($file, $file . '.gz', $file . '.br', $file . '.fresh', $file . '.esi') as $variant) {
                clearstatcache(true, $variant);
                if (file_exists($variant)) {
                    $remaining[] = $variant;
                }
            }
        }

        return empty($remaining);
    }

    private function ensure_page_cache_variant_family_capacity($file_path)
    {
        $parsed = $this->parse_page_cache_variant_file($file_path);
        if (empty($parsed)) {
            return array('allowed' => true, 'hash' => '', 'familyExists' => false, 'rotatedFamilies' => array());
        }

        $families = $this->collect_page_cache_variant_families((string) $parsed['dir']);
        $current_hash = (string) $parsed['hash'];
        if (isset($families[$current_hash])) {
            return array(
                'allowed' => true,
                'hash' => $current_hash,
                'familyExists' => true,
                'familyCount' => count($families),
                'familyCap' => $this->get_page_cache_variant_family_cap(),
                'rotatedFamilies' => array(),
            );
        }

        $cap = $this->get_page_cache_variant_family_cap();
        $family_count = count($families);
        if ($family_count < $cap) {
            return array(
                'allowed' => true,
                'hash' => $current_hash,
                'familyExists' => false,
                'familyCount' => $family_count,
                'familyCap' => $cap,
                'rotatedFamilies' => array(),
            );
        }

        uasort($families, static function ($left, $right) {
            $left_mtime = (int) ($left['lastModified'] ?? 0);
            $right_mtime = (int) ($right['lastModified'] ?? 0);
            if ($left_mtime === $right_mtime) {
                return strcmp((string) ($left['hash'] ?? ''), (string) ($right['hash'] ?? ''));
            }
            return $left_mtime <=> $right_mtime;
        });

        $victim_count = max(1, $family_count - $cap + 1);
        $rotated = array();
        foreach (array_slice(array_keys($families), 0, $victim_count) as $victim_hash) {
            if (!$this->delete_page_cache_variant_family((string) $parsed['dir'], (string) $victim_hash)) {
                return array(
                    'allowed' => false,
                    'hash' => $current_hash,
                    'familyExists' => false,
                    'familyCount' => $family_count,
                    'familyCap' => $cap,
                    'rotationFailedHash' => (string) $victim_hash,
                    'rotatedFamilies' => $rotated,
                );
            }
            $rotated[] = (string) $victim_hash;
        }

        $this->record_cache_event('variant-family-rotated', array(
            'dir' => (string) $parsed['dir'],
            'new_hash' => $current_hash,
            'rotated_families' => $rotated,
            'family_count_before' => $family_count,
            'family_cap' => $cap,
        ));

        return array(
            'allowed' => true,
            'hash' => $current_hash,
            'familyExists' => false,
            'familyCount' => $family_count,
            'familyCap' => $cap,
            'rotatedFamilies' => $rotated,
        );
    }

    private function write_cache_variant_atomically($path, $contents)
    {
        $path = (string) $path;
        $contents = (string) $contents;
        $this->reset_atomic_write_error();

        if (is_readable($path)) {
            $existing_size = ultracache_safe_filesize($path, 'atomic_write_compare_size');
            if (false !== $existing_size && (int) $existing_size === strlen($contents)) {
                $existing = ultracache_safe_file_get_contents($path, 'atomic_write_compare_contents');
                if (is_string($existing) && hash_equals(hash('sha256', $existing), hash('sha256', $contents))) {
                    $this->set_atomic_write_error(
                        'unchanged',
                        'Atomic write skipped because the existing cache representation is identical.',
                        array('path' => $path)
                    );
                    return true;
                }
            }
        }

        $dir = dirname($path);
        if (!file_exists($dir) && !ultracache_safe_mkdir($dir, 0755, true) && !file_exists($dir)) {
            $this->set_atomic_write_error('atomic_mkdir_failed', 'Could not create cache directory for atomic write.', array('path' => $path, 'dir' => $dir));
            return false;
        }

        if (!ultracache_path_is_writable($dir)) {
            $this->set_atomic_write_error('atomic_dir_not_writable', 'Cache directory is not writable for atomic write.', array('path' => $path, 'dir' => $dir));
            return false;
        }

        $tmp = $path . '.tmp-' . uniqid('', true);
        $result = ultracache_safe_file_put_contents($tmp, $contents, LOCK_EX);
        if (false === $result) {
            $this->set_atomic_write_error('atomic_tmp_write_failed', 'Could not write temporary cache file.', array('path' => $path, 'tmp' => $tmp));
            ultracache_safe_unlink($tmp);
            return false;
        }

        if (!ultracache_safe_rename($tmp, $path)) {
            $this->set_atomic_write_error('atomic_rename_failed', 'Could not rename temporary cache file into place.', array('path' => $path, 'tmp' => $tmp));
            ultracache_safe_unlink($tmp);
            return false;
        }

        clearstatcache(true, $tmp);
        clearstatcache(true, $path);
        if (!file_exists($path) || file_exists($tmp)) {
            $this->set_atomic_write_error('atomic_final_missing', 'Atomic write finished but final cache file was not found.', array('path' => $path, 'tmp' => $tmp));
            ultracache_safe_unlink($tmp);
            return false;
        }

        $this->set_atomic_write_error('ok', 'Atomic write completed.', array('path' => $path));
        return true;
    }

    private function normalize_path_value($path)
    {
        $path = '/' . ltrim((string) $path, '/');
        return '/' === $path ? '/' : trailingslashit(rtrim($path, '/'));
    }

    private function matches_path_rule($path, $rule)
    {
        $path = $this->normalize_path_value($path);
        $rule = trim((string) $rule);
        if ('' === $rule) {
            return false;
        }

        $wildcard = false;
        if ('*' === substr($rule, -1)) {
            $wildcard = true;
            $rule = substr($rule, 0, -1);
        }

        $rule = $this->normalize_path_value($rule);
        if ('/' === $rule) {
            return '/' === $path;
        }

        if ($path === $rule) {
            return true;
        }

        return $wildcard || 0 === strpos($path, $rule);
    }

    private function path_matches_any_rule($path, array $rules)
    {
        foreach ($rules as $rule) {
            if ($this->matches_path_rule($path, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function get_revalidate_lock_path($url)
    {
        $file = $this->get_cache_path($url, 'orig');
        return $file ? $file . '.revalidate.lock' : '';
    }

    private function get_runtime_lock_file($name)
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $name));
        $safe = trim((string) $safe, '-');
        if ('' === $safe) {
            $safe = 'runtime';
        }

        $dir = trailingslashit(ULTRACACHE_CACHE_DIR) . 'locks/';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir . $safe . '.lock';
    }

    private function delete_cache_variants($file)
    {
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'mark_cache_asset_refs_inactive_for_cache_file')) {
            Ultra_Cache_WP::mark_cache_asset_refs_inactive_for_cache_file($file);
        }
        $this->delete_apache_static_html_aliases_for_cache_file($file);

        foreach (array($file, $file . '.gz', $file . '.br', $file . '.fresh', $file . '.esi') as $variant) {
            if (file_exists($variant)) {
                ultracache_safe_unlink($variant);
            }
        }
    }

    public function get_cache_path($url, $bucket = null)
    {
        $normalized = $this->normalize_url($url);
        if (empty($normalized)) {
            return '';
        }

        $parts = wp_parse_url($normalized);
        $host = function_exists('ultracache_normalize_cache_host_segment')
            ? ultracache_normalize_cache_host_segment((string) ($parts['host'] ?? ''))
            : 'site';
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        $path = preg_replace('#[^A-Za-z0-9/_-]#', '-', $path);
        $path = trim((string) $path, '/');
        if ('' === $path) {
            $path = 'index';
        }

        if (null === $bucket) {
            $bucket = $this->get_request_image_bucket();
        }

        $bucket = in_array((string) $bucket, array('avif', 'webp', 'orig'), true) ? (string) $bucket : 'orig';
        $hash = md5($normalized);
        $base_dir = trailingslashit(ULTRACACHE_CACHE_DIR) . $host . '/' . $path;

        return trailingslashit($base_dir) . 'index-' . $bucket . '-' . $hash . '.html';
    }

    /**
     * Verify whether every active HTML variant for one frontend URL exists.
     *
     * A normal visit stores only the variant requested by that browser. The
     * canonical HTML stage is satisfied only when all currently enabled warm
     * buckets are present, preventing one orig/WebP/AVIF visit from hiding
     * missing variants from the background queue.
     *
     * @param string $url Local public URL.
     * @return array
     */
    private function get_frontend_visit_cache_satisfaction($url)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return array('htmlComplete' => false, 'requiredBuckets' => array(), 'cachedBuckets' => array(), 'files' => array());
        }

        $settings = $this->get_settings();
        $required_buckets = method_exists($this, 'get_allowed_warm_buckets')
            ? $this->get_allowed_warm_buckets(is_array($settings) ? $settings : array())
            : array('orig');
        $required_buckets = array_values(array_unique(array_intersect(array('orig', 'webp', 'avif'), array_map('strval', $required_buckets))));
        if (empty($required_buckets)) {
            $required_buckets = array('orig');
        }

        $cached_buckets = array();
        $files = array();
        foreach ($required_buckets as $bucket) {
            $file = $this->get_cache_path($url, $bucket);
            if ('' === (string) $file) {
                continue;
            }
            clearstatcache(true, $file);
            if (is_readable($file) && filesize($file) > 255) {
                $cached_buckets[] = $bucket;
                $files[] = $file;
            }
        }

        return array(
            'htmlComplete' => count($cached_buckets) === count($required_buckets),
            'requiredBuckets' => $required_buckets,
            'cachedBuckets' => $cached_buckets,
            'files' => $files,
        );
    }

    private function get_cache_paths_for_all_buckets($url)
    {
        $files = array();
        foreach (array('orig', 'avif', 'webp') as $bucket) {
            $file = $this->get_cache_path($url, $bucket);
            if ($file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function infer_bucket_from_cache_path($path)
    {
        $path = (string) $path;
        if ('' === $path) {
            return '';
        }

        if (false !== strpos($path, 'index-avif-')) {
            return 'avif';
        }

        if (false !== strpos($path, 'index-webp-')) {
            return 'webp';
        }

        if (false !== strpos($path, 'index-orig-')) {
            return 'orig';
        }

        return '';
    }
}
