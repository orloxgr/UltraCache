<?php
/**
 * UltraCache source color-profile inspection and Imagick color management.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Source_Color_Profile_Trait
{
    /** @var array<string,array<string,mixed>> */
    private $media_source_color_profile_inspection_memo = array();

    /**
     * Inspect supported containers for embedded color-profile metadata without decoding pixels.
     *
     * @param string $source_file Source image path.
     * @return array<string,mixed>
     */
    private function inspect_media_source_color_profile($source_file) {
        $source_file = (string) $source_file;
        $mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($source_file, 'media_color_profile_source_mtime') : 0;
        $size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($source_file, 'media_color_profile_source_size') : 0;
        $key = str_replace('\\', '/', $source_file) . '|' . $mtime . '|' . $size;
        if (isset($this->media_source_color_profile_inspection_memo[$key])) {
            return $this->media_source_color_profile_inspection_memo[$key];
        }

        $mime = $this->get_source_image_mime($source_file);
        $result = array(
            'hasProfile' => false,
            'determinate' => false,
            'detector' => 'unsupported',
            'mime' => $mime,
            'sourceFile' => $source_file,
        );

        if ('image/jpeg' === $mime) {
            $detected = $this->media_jpeg_has_icc_profile($source_file);
            $result['detector'] = 'jpeg-app2-icc';
        } elseif ('image/png' === $mime) {
            $detected = $this->media_png_has_icc_profile($source_file);
            $result['detector'] = 'png-iccp';
        } elseif ('image/webp' === $mime) {
            $detected = $this->media_webp_has_icc_profile($source_file);
            $result['detector'] = 'webp-iccp';
        } elseif ('image/avif' === $mime) {
            $detected = $this->media_avif_has_icc_profile($source_file);
            $result['detector'] = 'avif-colr';
        } else {
            $detected = null;
        }

        if (is_bool($detected)) {
            $result['hasProfile'] = $detected;
            $result['determinate'] = true;
        }

        $this->media_source_color_profile_inspection_memo[$key] = $result;
        return $result;
    }

    private function should_ignore_media_color_profile_preservation() {
        if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
            $settings = Ultra_Cache_WP::get_settings();
            if (array_key_exists('media_ignore_color_profile_preservation', $settings)) {
                return !empty($settings['media_ignore_color_profile_preservation']);
            }
        }

        $settings = function_exists('get_option')
            ? get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array())
            : array();
        return !empty($settings['mediaIgnoreColorProfilePreservation']);
    }

    private function media_source_requires_color_managed_encoder($source_file) {
        if ($this->should_ignore_media_color_profile_preservation()) {
            return false;
        }

        $inspection = $this->inspect_media_source_color_profile($source_file);
        return empty($inspection['determinate']) || !empty($inspection['hasProfile']);
    }

    private function get_color_profile_encoder_skip_message() {
        return __('The source image contains color-profile metadata that this encoder cannot preserve safely.', 'ultracache');
    }

    /** @return bool|null */
    private function media_jpeg_has_icc_profile($source_file) {
        if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
            return null;
        }
        $size = max(0, (int) ultracache_safe_filesize($source_file, 'media_color_profile_jpeg_size'));
        if ($size < 4) {
            return null;
        }
        $head = ultracache_safe_stream_read_chunk($source_file, 0, 2, 'media_color_profile_jpeg_header');
        if (!is_array($head) || "\xFF\xD8" !== (string) ($head['data'] ?? '')) {
            return null;
        }
        $offset = 2;
        $segments = 0;
        $limit = max(16, min(2048, (int) apply_filters('ultracache_media_color_profile_max_jpeg_segments', 256, $source_file, $size)));
        while ($offset < $size && ++$segments <= $limit) {
            $marker_read = ultracache_safe_stream_read_chunk($source_file, $offset, 2, 'media_color_profile_jpeg_marker');
            $marker_bytes = is_array($marker_read) ? (string) ($marker_read['data'] ?? '') : '';
            if (2 !== strlen($marker_bytes) || "\xFF" !== $marker_bytes[0]) {
                return null;
            }
            $marker = ord($marker_bytes[1]);
            $offset += 2;
            while (0xFF === $marker && $offset < $size) {
                $fill_read = ultracache_safe_stream_read_chunk($source_file, $offset, 1, 'media_color_profile_jpeg_fill');
                $fill = is_array($fill_read) ? (string) ($fill_read['data'] ?? '') : '';
                if (1 !== strlen($fill)) {
                    return null;
                }
                $marker = ord($fill);
                ++$offset;
            }
            if (0xD9 === $marker || 0xDA === $marker) {
                return false;
            }
            if (0x01 === $marker || ($marker >= 0xD0 && $marker <= 0xD8)) {
                continue;
            }
            if ($offset > $size - 2) {
                return null;
            }
            $length_read = ultracache_safe_stream_read_chunk($source_file, $offset, 2, 'media_color_profile_jpeg_length');
            $length_bytes = is_array($length_read) ? (string) ($length_read['data'] ?? '') : '';
            if (2 !== strlen($length_bytes)) {
                return null;
            }
            $length_data = unpack('nlength', $length_bytes);
            $segment_length = is_array($length_data) ? (int) ($length_data['length'] ?? 0) : 0;
            if ($segment_length < 2) {
                return null;
            }
            $payload_length = $segment_length - 2;
            $payload_offset = $offset + 2;
            if ($payload_offset > $size || $payload_length > $size - $payload_offset) {
                return null;
            }
            if (0xE2 === $marker && $payload_length >= 14) {
                $probe = ultracache_safe_stream_read_chunk($source_file, $payload_offset, min(32, $payload_length), 'media_color_profile_jpeg_app2');
                $bytes = is_array($probe) ? (string) ($probe['data'] ?? '') : '';
                if (0 === strpos($bytes, "ICC_PROFILE\x00")) {
                    return true;
                }
            }
            $offset = $payload_offset + $payload_length;
        }
        return $segments > $limit ? null : false;
    }

    /** @return bool|null */
    private function media_png_has_icc_profile($source_file) {
        if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
            return null;
        }
        $size = max(0, (int) ultracache_safe_filesize($source_file, 'media_color_profile_png_size'));
        $signature = ultracache_safe_stream_read_chunk($source_file, 0, 8, 'media_color_profile_png_signature');
        if (!is_array($signature) || "\x89PNG\x0D\x0A\x1A\x0A" !== (string) ($signature['data'] ?? '')) {
            return null;
        }
        $offset = 8;
        $chunks = 0;
        $limit = max(16, min(4096, (int) apply_filters('ultracache_media_color_profile_max_png_chunks', 256, $source_file, $size)));
        while ($offset <= $size - 12 && ++$chunks <= $limit) {
            $header = ultracache_safe_stream_read_chunk($source_file, $offset, 8, 'media_color_profile_png_chunk');
            $bytes = is_array($header) ? (string) ($header['data'] ?? '') : '';
            if (8 !== strlen($bytes)) {
                return null;
            }
            $length_data = unpack('Nlength', substr($bytes, 0, 4));
            $length = is_array($length_data) ? (int) ($length_data['length'] ?? -1) : -1;
            $type = substr($bytes, 4, 4);
            if ($length < 0 || $length > $size - $offset - 12) {
                return null;
            }
            if ('iCCP' === $type) {
                return true;
            }
            if ('IDAT' === $type || 'IEND' === $type) {
                return false;
            }
            $offset += 12 + $length;
        }
        return $chunks > $limit ? null : false;
    }

    /** @return bool|null */
    private function media_webp_has_icc_profile($source_file) {
        if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
            return null;
        }
        $size = max(0, (int) ultracache_safe_filesize($source_file, 'media_color_profile_webp_size'));
        $header = ultracache_safe_stream_read_chunk($source_file, 0, 12, 'media_color_profile_webp_header');
        $bytes = is_array($header) ? (string) ($header['data'] ?? '') : '';
        if (12 !== strlen($bytes) || 'RIFF' !== substr($bytes, 0, 4) || 'WEBP' !== substr($bytes, 8, 4)) {
            return null;
        }
        $declared = unpack('Vlength', substr($bytes, 4, 4));
        $declared_end = 8 + max(0, (int) ($declared['length'] ?? 0));
        if ($declared_end < 12 || $declared_end > $size) {
            return null;
        }
        $riff_end = $declared_end;
        $offset = 12;
        $chunks = 0;
        $limit = max(16, min(4096, (int) apply_filters('ultracache_media_color_profile_max_webp_chunks', 256, $source_file, $size)));
        while ($offset <= $riff_end - 8 && ++$chunks <= $limit) {
            $chunk = ultracache_safe_stream_read_chunk($source_file, $offset, 8, 'media_color_profile_webp_chunk');
            $chunk_bytes = is_array($chunk) ? (string) ($chunk['data'] ?? '') : '';
            if (8 !== strlen($chunk_bytes)) {
                return null;
            }
            $type = substr($chunk_bytes, 0, 4);
            $length_data = unpack('Vlength', substr($chunk_bytes, 4, 4));
            $length = is_array($length_data) ? (int) ($length_data['length'] ?? -1) : -1;
            $padded = $length + ($length % 2);
            if ($length < 0 || $padded > $riff_end - $offset - 8) {
                return null;
            }
            if ('ICCP' === $type) {
                return true;
            }
            $offset += 8 + $padded;
        }
        return $chunks > $limit ? null : false;
    }

    /** @return bool|null */
    private function media_avif_has_icc_profile($source_file) {
        if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
            return null;
        }
        $size = max(0, (int) ultracache_safe_filesize($source_file, 'media_color_profile_avif_size'));
        if ($size < 16) {
            return null;
        }
        $header = ultracache_safe_stream_read_chunk($source_file, 0, 16, 'media_color_profile_avif_header');
        $bytes = is_array($header) ? (string) ($header['data'] ?? '') : '';
        if (16 !== strlen($bytes) || 'ftyp' !== substr($bytes, 4, 4)) {
            return null;
        }

        $box_count = 0;
        $max_boxes = max(64, min(65536, (int) apply_filters('ultracache_media_color_profile_max_avif_boxes', 4096, $source_file, $size)));
        return $this->media_avif_box_range_has_icc_profile($source_file, 0, $size, 0, $box_count, $max_boxes);
    }

    /**
     * Inspect a bounded ISO-BMFF box range for an AVIF colr property carrying
     * an embedded ICC profile. Unknown boxes are skipped by their declared size.
     *
     * @return bool|null
     */
    private function media_avif_box_range_has_icc_profile($source_file, $start, $end, $depth, &$box_count, $max_boxes) {
        $start = max(0, (int) $start);
        $end = max($start, (int) $end);
        $depth = max(0, (int) $depth);
        if ($depth > 8) {
            return null;
        }

        $offset = $start;
        while ($offset < $end) {
            if (++$box_count > $max_boxes || $offset > $end - 8) {
                return null;
            }

            $header_read = ultracache_safe_stream_read_chunk($source_file, $offset, 8, 'media_color_profile_avif_box_header');
            $header = is_array($header_read) ? (string) ($header_read['data'] ?? '') : '';
            if (8 !== strlen($header)) {
                return null;
            }

            $size_data = unpack('Nsize', substr($header, 0, 4));
            $box_size = is_array($size_data) ? (int) ($size_data['size'] ?? 0) : 0;
            $box_type = substr($header, 4, 4);
            $header_size = 8;

            if (1 === $box_size) {
                if ($offset > $end - 16) {
                    return null;
                }
                $large_read = ultracache_safe_stream_read_chunk($source_file, $offset + 8, 8, 'media_color_profile_avif_large_box_size');
                $large = is_array($large_read) ? (string) ($large_read['data'] ?? '') : '';
                if (8 !== strlen($large)) {
                    return null;
                }
                $parts = unpack('Nhigh/Nlow', $large);
                $high = is_array($parts) ? max(0, (int) ($parts['high'] ?? 0)) : 0;
                $low = is_array($parts) ? max(0, (int) ($parts['low'] ?? 0)) : 0;
                if ($high > intdiv(PHP_INT_MAX - $low, 4294967296)) {
                    return null;
                }
                $box_size = ($high * 4294967296) + $low;
                $header_size = 16;
            } elseif (0 === $box_size) {
                $box_size = $end - $offset;
            }

            if ($box_size < $header_size || $box_size > $end - $offset) {
                return null;
            }

            $payload_start = $offset + $header_size;
            $box_end = $offset + $box_size;
            if ('colr' === $box_type) {
                if ($payload_start > $box_end - 4) {
                    return null;
                }
                $type_read = ultracache_safe_stream_read_chunk($source_file, $payload_start, 4, 'media_color_profile_avif_colr_type');
                $color_type = is_array($type_read) ? (string) ($type_read['data'] ?? '') : '';
                if ('rICC' === $color_type || 'prof' === $color_type) {
                    return true;
                }
            }

            $child_start = 0;
            if ('meta' === $box_type && $payload_start <= $box_end - 4) {
                $child_start = $payload_start + 4;
            } elseif (in_array($box_type, array('iprp', 'ipco'), true)) {
                $child_start = $payload_start;
            }
            if ($child_start > 0 && $child_start < $box_end) {
                $nested = $this->media_avif_box_range_has_icc_profile($source_file, $child_start, $box_end, $depth + 1, $box_count, $max_boxes);
                if (true === $nested || null === $nested) {
                    return $nested;
                }
            }

            $offset = $box_end;
        }

        return $offset === $end ? false : null;
    }

    /** @return array<string,string>|false */
    private function capture_media_imagick_color_profiles($image) {
        if (!is_object($image) || !method_exists($image, 'getImageProfiles')) {
            return false;
        }
        $profiles = array();
        $max_bytes = max(65536, min(67108864, (int) apply_filters('ultracache_media_color_profile_max_bytes', 16777216)));
        try {
            foreach (array('icc', 'icm') as $type) {
                $found = $image->getImageProfiles($type, true);
                if (!is_array($found)) {
                    continue;
                }
                foreach ($found as $name => $data) {
                    $data = (string) $data;
                    if ('' === $data || strlen($data) > $max_bytes) {
                        continue;
                    }
                    $key = strtolower((string) $name);
                    if ('icc' !== $key && 'icm' !== $key) {
                        $key = $type;
                    }
                    $profiles[$key] = $data;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return $profiles;
    }

    private function strip_and_restore_media_imagick_color_profiles($image, array $profiles) {
        if (method_exists($image, 'stripImage')) {
            $image->stripImage();
        }
        if (empty($profiles)) {
            return true;
        }
        $has_setter = method_exists($image, 'setImageProfile');
        $has_profile = method_exists($image, 'profileImage');
        if (!$has_setter && !$has_profile) {
            return false;
        }
        foreach ($profiles as $type => $profile) {
            $restored = $has_setter
                ? $image->setImageProfile((string) $type, (string) $profile)
                : $image->profileImage((string) $type, (string) $profile);
            if (false === $restored) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int,string> */
    private function get_media_color_profile_hashes(array $profiles) {
        $hashes = array();
        foreach ($profiles as $profile) {
            $profile = (string) $profile;
            if ('' !== $profile) {
                $hashes[] = hash('sha256', $profile);
            }
        }
        $hashes = array_values(array_unique($hashes));
        sort($hashes, SORT_STRING);
        return $hashes;
    }

    private function verify_media_imagick_output_color_profiles($output_file, array $expected_profiles, array $admission) {
        if (empty($expected_profiles)) {
            return true;
        }
        $decoded = null;
        $resource_state = array('applied' => false, 'previous' => array());
        try {
            $decoded = new Imagick();
            $resource_state = $this->apply_media_imagick_resource_limits($decoded, $admission);
            if (false === $resource_state) {
                return false;
            }
            $loaded = method_exists($decoded, 'pingImage') ? $decoded->pingImage($output_file) : $decoded->readImage($output_file);
            if (!$loaded) {
                return false;
            }
            $actual = $this->capture_media_imagick_color_profiles($decoded);
            if (false === $actual) {
                return false;
            }
            $expected_hashes = $this->get_media_color_profile_hashes($expected_profiles);
            $actual_hashes = $this->get_media_color_profile_hashes($actual);
            return empty(array_diff($expected_hashes, $actual_hashes));
        } catch (\Throwable $e) {
            return false;
        } finally {
            if (is_object($decoded)) {
                if (!empty($resource_state['applied']) && !empty($resource_state['previous']) && is_array($resource_state['previous'])) {
                    $this->restore_media_imagick_resource_limits($decoded, $resource_state['previous']);
                }
                if (method_exists($decoded, 'clear')) {
                    $decoded->clear();
                }
                if (method_exists($decoded, 'destroy')) {
                    $decoded->destroy();
                }
            }
        }
    }

    private function load_media_srgb_profile() {
        $path = ultracache_plugin_dir('assets/diagnostics/ultracache-srgb.icc');
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $profile = (string) ultracache_safe_file_get_contents($path, 'media_color_profile_srgb_asset', true);
        if (strlen($profile) < 128 || 'acsp' !== substr($profile, 36, 4)) {
            return '';
        }
        return $profile;
    }

    private function convert_media_imagick_image_to_srgb($image, array $source_profiles) {
        if (!is_object($image) || empty($source_profiles) || !method_exists($image, 'profileImage')) {
            return false;
        }
        $source_profile = reset($source_profiles);
        $srgb_profile = $this->load_media_srgb_profile();
        if (!is_string($source_profile) || '' === $source_profile || '' === $srgb_profile) {
            return false;
        }
        try {
            if (method_exists($image, 'stripImage')) {
                $image->stripImage();
            }
            if (false === $image->profileImage('icc', $source_profile)) {
                return false;
            }
            if (false === $image->profileImage('icc', $srgb_profile)) {
                return false;
            }
            if (method_exists($image, 'stripImage')) {
                $image->stripImage();
            }
            if (defined('Imagick::COLORSPACE_SRGB') && method_exists($image, 'setImageColorspace')) {
                $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
