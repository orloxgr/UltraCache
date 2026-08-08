<?php
/**
 * UltraCache media source orientation inspection and pixel normalization.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Source_Orientation_Trait
{
    /**
     * Per-request orientation inspection memo, keyed by source identity.
     *
     * @var array<string,array<string,mixed>>
     */
    private $media_source_orientation_inspection_memo = array();

    /**
     * Return the normalized EXIF orientation for a supported source.
     *
     * The bounded JPEG parser is independent of the optional PHP EXIF extension.
     * Other source types retain top-left unless the active decoder reports a
     * native orientation after decode.
     *
     * @param string $source_file Source image path.
     * @return int Orientation in the EXIF range 1..8.
     */
    private function get_media_source_orientation($source_file) {
        $inspection = $this->inspect_media_source_orientation($source_file);
        return max(1, min(8, (int) ($inspection['orientation'] ?? 1)));
    }

    /**
     * Inspect source orientation without decoding pixels.
     *
     * @param string $source_file Source image path.
     * @return array<string,mixed>
     */
    private function inspect_media_source_orientation($source_file) {
        $source_file = (string) $source_file;
        $mtime = function_exists('ultracache_safe_filemtime') ? (int) ultracache_safe_filemtime($source_file, 'media_orientation_source_mtime') : 0;
        $size = function_exists('ultracache_safe_filesize') ? (int) ultracache_safe_filesize($source_file, 'media_orientation_source_size') : 0;
        $key = str_replace('\\', '/', $source_file) . '|' . $mtime . '|' . $size;
        if (isset($this->media_source_orientation_inspection_memo[$key])) {
            return $this->media_source_orientation_inspection_memo[$key];
        }

        $mime = $this->get_source_image_mime($source_file);
        $result = array(
            'orientation' => 1,
            'determinate' => true,
            'detector'    => 'not-applicable',
            'mime'        => $mime,
            'sourceFile'  => $source_file,
        );

        if ('image/jpeg' === $mime) {
            $orientation = $this->parse_media_jpeg_exif_orientation($source_file);
            $result['orientation'] = $orientation > 0 ? $orientation : 1;
            $result['determinate'] = $orientation > 0;
            $result['detector'] = $orientation > 0 ? 'jpeg-exif' : 'jpeg-no-orientation';
        }

        $this->media_source_orientation_inspection_memo[$key] = $result;
        return $result;
    }

    /**
     * Parse IFD0 Orientation from bounded JPEG APP1/Exif segments.
     *
     * @param string $source_file JPEG source path.
     * @return int Orientation 1..8, or 0 when no valid tag is available.
     */
    private function parse_media_jpeg_exif_orientation($source_file) {
        if (!function_exists('ultracache_safe_stream_read_chunk') || !function_exists('ultracache_safe_filesize')) {
            return 0;
        }

        $file_size = max(0, (int) ultracache_safe_filesize($source_file, 'media_orientation_jpeg_filesize'));
        if ($file_size < 4) {
            return 0;
        }

        $header_read = ultracache_safe_stream_read_chunk($source_file, 0, 2, 'media_orientation_jpeg_header');
        $header = is_array($header_read) ? (string) ($header_read['data'] ?? '') : '';
        if ("\xFF\xD8" !== $header) {
            return 0;
        }

        $offset = 2;
        $segments = 0;
        $max_segments = (int) apply_filters('ultracache_media_orientation_max_jpeg_segments', 256, $source_file, $file_size);
        $max_segments = max(16, min(2048, $max_segments));

        while ($offset < $file_size && ++$segments <= $max_segments) {
            $marker_read = ultracache_safe_stream_read_chunk($source_file, $offset, 2, 'media_orientation_jpeg_marker');
            $marker_bytes = is_array($marker_read) ? (string) ($marker_read['data'] ?? '') : '';
            if (2 !== strlen($marker_bytes) || "\xFF" !== $marker_bytes[0]) {
                return 0;
            }

            $marker = ord($marker_bytes[1]);
            $offset += 2;
            while (0xFF === $marker && $offset < $file_size) {
                $fill_read = ultracache_safe_stream_read_chunk($source_file, $offset, 1, 'media_orientation_jpeg_fill_marker');
                $fill = is_array($fill_read) ? (string) ($fill_read['data'] ?? '') : '';
                if (1 !== strlen($fill)) {
                    return 0;
                }
                $marker = ord($fill);
                ++$offset;
            }

            if (0xD9 === $marker || 0xDA === $marker) {
                return 0;
            }

            if (0x01 === $marker || ($marker >= 0xD0 && $marker <= 0xD8)) {
                continue;
            }

            if ($offset > $file_size - 2) {
                return 0;
            }
            $length_read = ultracache_safe_stream_read_chunk($source_file, $offset, 2, 'media_orientation_jpeg_segment_length');
            $length_bytes = is_array($length_read) ? (string) ($length_read['data'] ?? '') : '';
            if (2 !== strlen($length_bytes)) {
                return 0;
            }
            $segment_length_data = unpack('nlength', $length_bytes);
            $segment_length = is_array($segment_length_data) ? (int) ($segment_length_data['length'] ?? 0) : 0;
            if ($segment_length < 2) {
                return 0;
            }

            $payload_length = $segment_length - 2;
            $payload_offset = $offset + 2;
            if ($payload_offset > $file_size || $payload_length > $file_size - $payload_offset) {
                return 0;
            }

            if (0xE1 === $marker && $payload_length >= 14) {
                $payload_read = ultracache_safe_stream_read_chunk($source_file, $payload_offset, $payload_length, 'media_orientation_jpeg_app1');
                $payload = is_array($payload_read) ? (string) ($payload_read['data'] ?? '') : '';
                if ($payload_length === strlen($payload) && 0 === strpos($payload, "Exif\x00\x00")) {
                    $orientation = $this->parse_media_tiff_orientation(substr($payload, 6));
                    if ($orientation >= 1 && $orientation <= 8) {
                        return $orientation;
                    }
                }
            }

            $offset = $payload_offset + $payload_length;
        }

        return 0;
    }

    /**
     * Parse an EXIF TIFF payload and return IFD0 Orientation.
     *
     * @param string $tiff TIFF bytes beginning with byte-order marker.
     * @return int Orientation 1..8, or 0.
     */
    private function parse_media_tiff_orientation($tiff) {
        $tiff = (string) $tiff;
        $length = strlen($tiff);
        if ($length < 8) {
            return 0;
        }

        $byte_order = substr($tiff, 0, 2);
        if ('II' === $byte_order) {
            $little_endian = true;
        } elseif ('MM' === $byte_order) {
            $little_endian = false;
        } else {
            return 0;
        }

        if (42 !== $this->read_media_tiff_uint16($tiff, 2, $little_endian)) {
            return 0;
        }

        $ifd_offset = $this->read_media_tiff_uint32($tiff, 4, $little_endian);
        if ($ifd_offset < 8 || $ifd_offset > $length - 2) {
            return 0;
        }

        $entry_count = $this->read_media_tiff_uint16($tiff, $ifd_offset, $little_endian);
        if ($entry_count < 0 || $entry_count > 1024) {
            return 0;
        }

        $entries_offset = $ifd_offset + 2;
        if ($entry_count > intdiv(max(0, $length - $entries_offset), 12)) {
            return 0;
        }

        for ($index = 0; $index < $entry_count; ++$index) {
            $entry_offset = $entries_offset + ($index * 12);
            $tag = $this->read_media_tiff_uint16($tiff, $entry_offset, $little_endian);
            if (0x0112 !== $tag) {
                continue;
            }

            $type = $this->read_media_tiff_uint16($tiff, $entry_offset + 2, $little_endian);
            $count = $this->read_media_tiff_uint32($tiff, $entry_offset + 4, $little_endian);
            if (3 !== $type || 1 !== $count) {
                return 0;
            }

            $orientation = $this->read_media_tiff_uint16($tiff, $entry_offset + 8, $little_endian);
            return ($orientation >= 1 && $orientation <= 8) ? $orientation : 0;
        }

        return 0;
    }

    private function read_media_tiff_uint16($bytes, $offset, $little_endian) {
        $bytes = (string) $bytes;
        $offset = (int) $offset;
        if ($offset < 0 || $offset > strlen($bytes) - 2) {
            return -1;
        }
        $data = unpack($little_endian ? 'vvalue' : 'nvalue', substr($bytes, $offset, 2));
        return is_array($data) ? (int) ($data['value'] ?? -1) : -1;
    }

    private function read_media_tiff_uint32($bytes, $offset, $little_endian) {
        $bytes = (string) $bytes;
        $offset = (int) $offset;
        if ($offset < 0 || $offset > strlen($bytes) - 4) {
            return -1;
        }
        $data = unpack($little_endian ? 'Vvalue' : 'Nvalue', substr($bytes, $offset, 4));
        $value = is_array($data) ? (int) ($data['value'] ?? -1) : -1;
        return $value >= 0 ? $value : -1;
    }

    /**
     * Normalize an Imagick image to top-left orientation.
     *
     * @param object $image       Decoded Imagick image.
     * @param string $source_file Source path used for bounded fallback metadata.
     * @return bool
     */
    private function normalize_media_imagick_orientation($image, $source_file) {
        if (!is_object($image)) {
            return false;
        }

        $orientation = 0;
        if (method_exists($image, 'getImageOrientation')) {
            $orientation = (int) $image->getImageOrientation();
        }
        if ($orientation < 1 || $orientation > 8) {
            $orientation = $this->get_media_source_orientation($source_file);
            if ($orientation > 1 && method_exists($image, 'setImageOrientation')) {
                if (false === $image->setImageOrientation($orientation)) {
                    return false;
                }
            }
        }

        if ($orientation <= 1) {
            return $this->set_media_imagick_top_left_orientation($image);
        }

        if (method_exists($image, 'autoOrientImage')) {
            if (false === $image->autoOrientImage()) {
                return false;
            }
        } elseif (!$this->normalize_media_imagick_orientation_manually($image, $orientation)) {
            return false;
        }

        if (method_exists($image, 'setImagePage')) {
            $image->setImagePage(0, 0, 0, 0);
        }

        return $this->set_media_imagick_top_left_orientation($image);
    }

    private function set_media_imagick_top_left_orientation($image) {
        if (!method_exists($image, 'setImageOrientation')) {
            return true;
        }
        $top_left = defined('Imagick::ORIENTATION_TOPLEFT') ? (int) constant('Imagick::ORIENTATION_TOPLEFT') : 1;
        return false !== $image->setImageOrientation($top_left);
    }

    /**
     * Apply the complete EXIF orientation matrix on older Imagick versions.
     *
     * @param object $image       Decoded Imagick image.
     * @param int    $orientation EXIF orientation 1..8.
     * @return bool
     */
    private function normalize_media_imagick_orientation_manually($image, $orientation) {
        $orientation = max(1, min(8, (int) $orientation));
        switch ($orientation) {
            case 2:
                return method_exists($image, 'flopImage') && false !== $image->flopImage();
            case 3:
                return $this->rotate_media_imagick_image($image, 180.0);
            case 4:
                return method_exists($image, 'flipImage') && false !== $image->flipImage();
            case 5:
                if (method_exists($image, 'transposeImage')) {
                    return false !== $image->transposeImage();
                }
                return $this->rotate_media_imagick_image($image, 90.0) && method_exists($image, 'flopImage') && false !== $image->flopImage();
            case 6:
                return $this->rotate_media_imagick_image($image, 90.0);
            case 7:
                if (method_exists($image, 'transverseImage')) {
                    return false !== $image->transverseImage();
                }
                return method_exists($image, 'flopImage') && false !== $image->flopImage() && $this->rotate_media_imagick_image($image, 90.0);
            case 8:
                return $this->rotate_media_imagick_image($image, -90.0);
            default:
                return true;
        }
    }

    private function rotate_media_imagick_image($image, $degrees) {
        if (!method_exists($image, 'rotateImage') || !class_exists('ImagickPixel')) {
            return false;
        }
        $background = new ImagickPixel('none');
        return false !== $image->rotateImage($background, (float) $degrees);
    }

    /**
     * Normalize a GD image to top-left orientation.
     *
     * @param mixed $image                 GD image, replaced when rotation allocates a new canvas.
     * @param int   $orientation           EXIF orientation 1..8.
     * @param bool  $source_can_have_alpha Whether alpha must be preserved.
     * @return bool
     */
    private function normalize_media_gd_orientation(&$image, $orientation, $source_can_have_alpha = false) {
        $orientation = max(1, min(8, (int) $orientation));
        switch ($orientation) {
            case 2:
                return $this->flip_media_gd_image($image, defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1);
            case 3:
                return $this->rotate_media_gd_image($image, 180.0, $source_can_have_alpha);
            case 4:
                return $this->flip_media_gd_image($image, defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2);
            case 5:
                return $this->rotate_media_gd_image($image, -90.0, $source_can_have_alpha)
                    && $this->flip_media_gd_image($image, defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1);
            case 6:
                return $this->rotate_media_gd_image($image, -90.0, $source_can_have_alpha);
            case 7:
                return $this->flip_media_gd_image($image, defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1)
                    && $this->rotate_media_gd_image($image, -90.0, $source_can_have_alpha);
            case 8:
                return $this->rotate_media_gd_image($image, 90.0, $source_can_have_alpha);
            default:
                return true;
        }
    }

    private function flip_media_gd_image(&$image, $mode) {
        return function_exists('imageflip') && false !== imageflip($image, (int) $mode);
    }

    private function rotate_media_gd_image(&$image, $degrees, $preserve_alpha) {
        if (!function_exists('imagerotate')) {
            return false;
        }

        $background = 0;
        if ($preserve_alpha && function_exists('imagecolorallocatealpha')) {
            $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        }

        $rotated = imagerotate($image, (float) $degrees, $background);
        if (false === $rotated) {
            return false;
        }

        if ($preserve_alpha) {
            if (function_exists('imagealphablending')) {
                imagealphablending($rotated, false);
            }
            if (function_exists('imagesavealpha')) {
                imagesavealpha($rotated, true);
            }
        }

        if (function_exists('imagedestroy')) {
            imagedestroy($image);
        }
        $image = $rotated;
        return true;
    }
}
