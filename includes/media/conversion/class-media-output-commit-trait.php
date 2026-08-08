<?php
/**
 * Atomic generated-media output helpers for UltraCache.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Output_Commit_Trait
{
    /**
     * Build a same-directory temporary output path that preserves the real format extension.
     *
     * @return string|false
     */
    private function create_media_output_temp_path($dest_file, $format)
    {
        $dest_file = (string) $dest_file;
        $format = strtolower(trim((string) $format));
        if ('' === $dest_file || !in_array($format, array('avif', 'webp'), true)) {
            return false;
        }

        $dest_dir = dirname($dest_file);
        if (!is_dir($dest_dir) || is_link($dest_dir) || !ultracache_path_is_writable($dest_dir)) {
            return false;
        }

        $token = function_exists('wp_generate_password')
            ? wp_generate_password(12, false, false)
            : str_replace('.', '', uniqid('', true));
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        if (!is_string($token) || '' === $token) {
            return false;
        }

        $stem = (string) pathinfo(basename($dest_file), PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9._-]/', '-', $stem);
        if (!is_string($stem) || '' === $stem) {
            $stem = 'generated-media';
        }

        return trailingslashit($dest_dir) . '.' . $stem . '.uc-tmp-' . $token . '.' . $format;
    }

    /**
     * Remove a temporary generated-media output and clear storage memoization.
     */
    private function cleanup_media_output_temp_file($temp_file)
    {
        $temp_file = (string) $temp_file;
        if ('' === $temp_file) {
            return;
        }

        if ($this->optimized_storage_path_exists($temp_file, true)) {
            ultracache_safe_unlink($temp_file, 'media_converter_atomic_temp_cleanup');
        }
        $this->optimized_storage_forget_path($temp_file);
        clearstatcache(true, $temp_file);
    }

    /**
     * Compare two output paths after separator normalization.
     */
    private function media_output_paths_match($left, $right)
    {
        $normalize = static function ($path) {
            $path = str_replace('\\', '/', (string) $path);
            return function_exists('wp_normalize_path') ? wp_normalize_path($path) : $path;
        };

        return $normalize($left) === $normalize($right);
    }

    /**
     * Validate and atomically commit a generated-media temporary file.
     */
    private function commit_media_output_temp_file($temp_file, $dest_file, $format, $engine, $source_file = '')
    {
        $temp_file = (string) $temp_file;
        $dest_file = (string) $dest_file;
        $format = strtolower(trim((string) $format));
        $engine = sanitize_key((string) $engine);
        $source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
        if ('' === $engine) {
            $engine = 'encoder';
        }

        if ('' === $temp_file || '' === $dest_file || !$this->media_output_paths_match(dirname($temp_file), dirname($dest_file))) {
            $this->record_media_conversion_failure($engine, 'invalid_atomic_output_path', __('The generated image temporary path is not in the destination directory.', 'ultracache'), 'storage');
            $this->cleanup_media_output_temp_file($temp_file);
            return false;
        }

        if (!$this->is_valid_generated_media_file($temp_file, $format, 'media_converter_atomic_temp_validate')) {
            $this->record_media_conversion_failure($engine, 'invalid_generated_file', __('The encoder produced a temporary image that failed format validation.', 'ultracache'), 'validation');
            $this->cleanup_media_output_temp_file($temp_file);
            return false;
        }

        $this->optimized_storage_harden_upload_permissions($temp_file, 'file');
        $this->optimized_storage_forget_path($temp_file);
        $this->optimized_storage_forget_path($dest_file);
        clearstatcache(true, $temp_file);
        clearstatcache(true, $dest_file);

        if (!ultracache_safe_rename($temp_file, $dest_file, 'media_converter_atomic_commit')) {
            $this->record_media_conversion_failure($engine, 'atomic_commit_failed', __('The generated image could not be atomically committed.', 'ultracache'), 'storage');
            $this->cleanup_media_output_temp_file($temp_file);
            $this->optimized_storage_forget_path($dest_file);
            return false;
        }

        $this->optimized_storage_forget_path($temp_file);
        $this->optimized_storage_forget_path($dest_file);
        clearstatcache(true, $temp_file);
        clearstatcache(true, $dest_file);

        if ($this->optimized_storage_path_exists($temp_file, true) || !$this->optimized_storage_path_exists($dest_file, true)) {
            $this->record_media_conversion_failure($engine, 'atomic_commit_incomplete', __('The generated image commit did not produce the expected final file state.', 'ultracache'), 'storage');
            $this->cleanup_media_output_temp_file($temp_file);
            return false;
        }

        if (!$this->is_valid_generated_media_file($dest_file, $format, 'media_converter_atomic_final_validate')) {
            $this->record_media_conversion_failure($engine, 'invalid_generated_file', __('The atomically committed image failed final format validation.', 'ultracache'), 'validation');
            ultracache_safe_unlink($dest_file, 'media_converter_invalid_atomic_final_cleanup');
            $this->optimized_storage_forget_path($dest_file);
            return false;
        }

        if ('' !== $source_file) {
            $source_mtime = function_exists('ultracache_safe_filemtime')
                ? ultracache_safe_filemtime($source_file, 'media_converter_atomic_source_mtime')
                : false;
            $dest_mtime = function_exists('ultracache_safe_filemtime')
                ? ultracache_safe_filemtime($dest_file, 'media_converter_atomic_destination_mtime')
                : false;
            if (false === $source_mtime || (int) $source_mtime <= 0 || false === $dest_mtime || (int) $dest_mtime <= 0) {
                $this->record_media_conversion_failure($engine, 'freshness_timestamp_unavailable', __('The generated image freshness timestamp could not be verified.', 'ultracache'), 'storage');
                ultracache_safe_unlink($dest_file, 'media_converter_unverifiable_freshness_cleanup');
                $this->optimized_storage_forget_path($dest_file);
                return false;
            }

            if ((int) $dest_mtime < (int) $source_mtime) {
                if (!function_exists('ultracache_safe_touch') || !ultracache_safe_touch($dest_file, (int) $source_mtime, 'media_converter_align_output_freshness')) {
                    $this->record_media_conversion_failure($engine, 'freshness_timestamp_alignment_failed', __('The generated image timestamp could not be aligned with its source file.', 'ultracache'), 'storage');
                    ultracache_safe_unlink($dest_file, 'media_converter_unaligned_freshness_cleanup');
                    $this->optimized_storage_forget_path($dest_file);
                    return false;
                }

                $dest_mtime = ultracache_safe_filemtime($dest_file, 'media_converter_aligned_destination_mtime');
                if (false === $dest_mtime || (int) $dest_mtime < (int) $source_mtime) {
                    $this->record_media_conversion_failure($engine, 'freshness_timestamp_alignment_failed', __('The generated image timestamp remained older than its source file.', 'ultracache'), 'storage');
                    ultracache_safe_unlink($dest_file, 'media_converter_stale_output_cleanup');
                    $this->optimized_storage_forget_path($dest_file);
                    return false;
                }
            }
        }

        $this->optimized_storage_harden_upload_permissions($dest_file, 'file');
        $this->optimized_storage_forget_path($dest_file);
        return true;
    }
}
