<?php
/**
 * Ultra Cache Media Path Url Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Path_Url_Trait
{

		private function get_avif_path_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path) {
				return false;
			}

			$relative_path = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $relative_path);

			if (!$relative_path) {
				return false;
			}

			return trailingslashit(UCWP_AVIF_DIR) . $relative_path;
		}

		private function get_webp_path_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path) {
				return false;
			}

			if (!$this->is_webp_fallback_source_file($source_file)) {
				return false;
			}

			$relative_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $relative_path);

			if (!$relative_path) {
				return false;
			}

			return trailingslashit(UCWP_WEBP_DIR) . $relative_path;
		}

		private function optimized_storage_filesystem() {
			return function_exists('ucwp_get_wp_filesystem') ? ucwp_get_wp_filesystem() : false;
		}

		private function optimized_storage_path_exists($path, $refresh = false) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			if ('' === $path) {
				return false;
			}

			$key = 'exists|' . $path;
			if (!$refresh && isset($this->optimized_variant_exists_memo[$key])) {
				return (bool) $this->optimized_variant_exists_memo[$key];
			}

			$filesystem = $this->optimized_storage_filesystem();
			if ($filesystem && method_exists($filesystem, 'exists')) {
				$exists = (bool) $filesystem->exists($path);
				if ($exists && method_exists($filesystem, 'is_file')) {
					$exists = (bool) $filesystem->is_file($path);
				}
			} else {
				// Encapsulated fallback only. Most installations use the direct WP_Filesystem transport,
				// but frontend rendering must fail open and keep original JPG/PNG when filesystem access is unavailable.
				$exists = @file_exists($path) && @is_file($path);
			}

			$this->optimized_variant_exists_memo[$key] = (bool) $exists;
			return (bool) $exists;
		}

		private function optimized_storage_readable_source_exists($path) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			if ('' === $path) {
				return false;
			}

			$filesystem = $this->optimized_storage_filesystem();
			if ($filesystem && method_exists($filesystem, 'exists')) {
				$exists = (bool) $filesystem->exists($path);
				if ($exists && method_exists($filesystem, 'is_file')) {
					$exists = (bool) $filesystem->is_file($path);
				}
				if ($exists && method_exists($filesystem, 'is_readable')) {
					$exists = (bool) $filesystem->is_readable($path);
				}
				return (bool) $exists;
			}

			// Encapsulated fallback. Image libraries require a real local path, so this helper is
			// the only place this touched media path uses native readability checks.
			return @file_exists($path) && @is_file($path) && @is_readable($path);
		}

		private function optimized_storage_ensure_directory($dir) {
			$dir = is_string($dir) ? wp_normalize_path($dir) : '';
			if ('' === $dir) {
				return false;
			}

			$filesystem = $this->optimized_storage_filesystem();
			if ($filesystem && method_exists($filesystem, 'is_dir') && $filesystem->is_dir($dir)) {
				return true;
			}
			if (function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
				return true;
			}
			if ($filesystem && method_exists($filesystem, 'mkdir')) {
				return (bool) $filesystem->mkdir($dir, FS_CHMOD_DIR);
			}

			return false;
		}

		private function optimized_storage_forget_path($path) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			if ('' !== $path) {
				unset($this->optimized_variant_exists_memo['exists|' . $path]);
			}
		}

		private function normalize_uploads_relative_image_path($relative_path) {
			$relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
			if ('' === $relative_path || false !== strpos($relative_path, "\0")) {
				return false;
			}

			$relative_path = rawurldecode($relative_path);
			$relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
			if ('' === $relative_path || false !== strpos($relative_path, '/uc-images/')) {
				return false;
			}

			foreach (explode('/', $relative_path) as $segment) {
				if ('' === $segment || '.' === $segment || '..' === $segment) {
					return false;
				}
			}

			if (!preg_match('/\.(?:jpe?g|png|webp)$/i', $relative_path)) {
				return false;
			}

			return $relative_path;
		}

		private function get_existing_optimized_url_from_uploads_relative_path($relative_path, $format) {
			$format = strtolower((string) $format);
			$relative_path = $this->normalize_uploads_relative_image_path($relative_path);
			if (!$relative_path || !in_array($format, array('avif', 'webp'), true)) {
				return false;
			}

			if ('avif' === $format) {
				$optimized_relative_path = preg_replace('/\.(?:jpe?g|png|webp)$/i', '.avif', $relative_path);
				$base_dir = defined('UCWP_AVIF_DIR') ? UCWP_AVIF_DIR : '';
			} else {
				if (!preg_match('/\.(?:jpe?g|png)$/i', $relative_path)) {
					return false;
				}
				$optimized_relative_path = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $relative_path);
				$base_dir = defined('UCWP_WEBP_DIR') ? UCWP_WEBP_DIR : '';
			}

			if (!is_string($optimized_relative_path) || '' === $optimized_relative_path || '' === (string) $base_dir) {
				return false;
			}

			$optimized_path = trailingslashit((string) $base_dir) . $optimized_relative_path;
			if (!$this->optimized_storage_path_exists($optimized_path)) {
				return false;
			}

			return $this->get_root_relative_optimized_media_url($format, $optimized_relative_path);
		}

		private function get_public_url_lookup_cache_key($format, $public_url) {
			$format = strtolower((string) $format);
			$normalized = $this->normalize_public_url($public_url);
			if ('' === $normalized) {
				$normalized = trim((string) $public_url);
			}

			$context = method_exists($this, 'get_media_generation_context') ? $this->get_media_generation_context() : 'frontend';
			return $format . '|' . $context . '|lookup|' . md5($normalized);
		}

		private function memoize_public_url_lookup($key, $value) {
			if (is_string($key) && '' !== $key) {
				$this->optimized_public_url_lookup_memo[$key] = $value ? (string) $value : false;
			}

			return $value ? (string) $value : false;
		}

		private function get_avif_url_from_source($source_file) {
			$avif_path = $this->get_avif_path_from_source($source_file);

			if (!$avif_path) {
				return false;
			}

			if (!$this->optimized_storage_path_exists($avif_path)) {
				return false;
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_AVIF_DIR), '', $avif_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return $this->get_root_relative_optimized_media_url('avif', $relative_path);
		}

		private function get_webp_url_from_source($source_file) {
			$webp_path = $this->get_webp_path_from_source($source_file);

			if (!$webp_path) {
				return false;
			}

			if (!$this->optimized_storage_path_exists($webp_path)) {
				return false;
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_WEBP_DIR), '', $webp_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return $this->get_root_relative_optimized_media_url('webp', $relative_path);
		}

		private function get_root_relative_optimized_media_url($format, $relative_path) {
			$format = strtolower((string) $format);
			$relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
			if ('' === $relative_path || !in_array($format, array('avif', 'webp'), true)) {
				return false;
			}

			foreach (explode('/', $relative_path) as $segment) {
				if ('' === $segment || '.' === $segment || '..' === $segment) {
					return false;
				}
			}

			$base_url = ('avif' === $format && defined('UCWP_AVIF_URL')) ? UCWP_AVIF_URL : (defined('UCWP_WEBP_URL') ? UCWP_WEBP_URL : '');
			$base_path = (string) wp_parse_url((string) $base_url, PHP_URL_PATH);
			if ('' === $base_path) {
				$base_path = (string) wp_parse_url(content_url('uploads/uc-images/' . $format . '/'), PHP_URL_PATH);
			}

			$base_path = '/' . ltrim(str_replace('\\', '/', (string) $base_path), '/');
			return trailingslashit($base_path) . $relative_path;
		}

		private function get_avif_url_from_attachment_context($attachment, $size) {
			if (empty($attachment->ID)) {
				return false;
			}

			$image = wp_get_attachment_image_src($attachment->ID, $size);

			if (empty($image[0])) {
				return false;
			}

			return $this->get_avif_url_from_public_url($image[0]);
		}

		private function get_webp_url_from_attachment_context($attachment, $size) {
			if (empty($attachment->ID)) {
				return false;
			}

			$image = wp_get_attachment_image_src($attachment->ID, $size);

			if (empty($image[0])) {
				return false;
			}

			return $this->get_webp_url_from_public_url($image[0]);
		}

		private function get_avif_url_from_public_url($public_url) {
			$key = $this->get_public_url_lookup_cache_key('avif', $public_url);
			if (isset($this->optimized_public_url_lookup_memo[$key])) {
				return $this->optimized_public_url_lookup_memo[$key];
			}

			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return $this->memoize_public_url_lookup($key, false);
			}

			$existing_url = $this->get_existing_optimized_url_from_uploads_relative_path($relative_path, 'avif');
			if ($existing_url) {
				return $this->memoize_public_url_lookup($key, $existing_url);
			}

			$this->maybe_queue_missing_optimized_media_from_public_url($public_url, 'avif');
			return $this->memoize_public_url_lookup($key, false);
		}

		private function get_webp_url_from_public_url($public_url) {
			$key = $this->get_public_url_lookup_cache_key('webp', $public_url);
			if (isset($this->optimized_public_url_lookup_memo[$key])) {
				return $this->optimized_public_url_lookup_memo[$key];
			}

			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return $this->memoize_public_url_lookup($key, false);
			}

			$existing_url = $this->get_existing_optimized_url_from_uploads_relative_path($relative_path, 'webp');
			if ($existing_url) {
				return $this->memoize_public_url_lookup($key, $existing_url);
			}

			$this->maybe_queue_missing_optimized_media_from_public_url($public_url, 'webp');
			return $this->memoize_public_url_lookup($key, false);
		}

		private function normalize_local_path_for_compare($path) {
			return rtrim(str_replace('\\', '/', (string) $path), '/');
		}

		private function path_is_within_root($path, $root) {
			$path = $this->normalize_local_path_for_compare($path);
			$root = $this->normalize_local_path_for_compare($root);

			if ('' === $path || '' === $root) {
				return false;
			}

			return $path === $root || 0 === strpos($path, $root . '/');
		}

		private function get_uploads_relative_path_from_source($source_file) {
			$uploads = wp_get_upload_dir();

			if (empty($uploads['basedir'])) {
				return false;
			}

			$uploads_root = realpath($uploads['basedir']);
			$source_real  = realpath($source_file);

			if (!is_string($uploads_root) || '' === $uploads_root || !is_string($source_real) || '' === $source_real) {
				return false;
			}

			if (!$this->path_is_within_root($source_real, $uploads_root)) {
				return false;
			}

			$relative_path = ltrim(substr($this->normalize_local_path_for_compare($source_real), strlen($this->normalize_local_path_for_compare($uploads_root))), '/');
			return '' !== $relative_path ? $relative_path : false;
		}

		private function get_uploads_relative_path_from_public_url($public_url) {
			$public_url = $this->normalize_public_url($public_url);
			if ('' === $public_url) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
				return false;
			}

			$baseurl = untrailingslashit($this->normalize_public_url($uploads['baseurl']));
			if ('' === $baseurl) {
				return false;
			}

			$public_parts = wp_parse_url($public_url);
			$base_parts   = wp_parse_url($baseurl);
			if (!is_array($public_parts) || empty($public_parts['path']) || !is_array($base_parts) || empty($base_parts['path'])) {
				return false;
			}

			$public_path = rawurldecode((string) $public_parts['path']);
			$base_path   = rawurldecode((string) $base_parts['path']);
			$public_path = '/' . ltrim(str_replace('\\', '/', $public_path), '/');
			$base_path   = '/' . ltrim(str_replace('\\', '/', $base_path), '/');
			$base_path   = rtrim($base_path, '/');
			if ('' === $public_path || '' === $base_path) {
				return false;
			}

			// 2.56.178: optimized AVIF/WebP files live under uploads/uc-images.
			// They are generated targets, not source uploads, so never feed them back
			// into the converter and create nested uc-images/avif/uc-images paths.
			$optimized_images_prefix = $base_path . '/uc-images/';
			if (0 === strpos($public_path, $optimized_images_prefix)) {
				return false;
			}

			$public_host = isset($public_parts['host']) ? strtolower((string) $public_parts['host']) : '';
			$base_host   = isset($base_parts['host']) ? strtolower((string) $base_parts['host']) : '';
			$home_host   = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
			$site_host   = strtolower((string) wp_parse_url(site_url('/'), PHP_URL_HOST));
			$allowed_hosts = array_filter(array_unique(array($base_host, $home_host, $site_host)));
			if ('' !== $public_host && !empty($allowed_hosts)) {
				$normalized_public_host = preg_replace('/^www\./', '', $public_host);
				$normalized_allowed = array_map(
					static function ($host) {
						return preg_replace('/^www\./', '', (string) $host);
					},
					$allowed_hosts
				);
				if (!in_array($normalized_public_host, $normalized_allowed, true)) {
					return false;
				}
			}

			if ($public_path !== $base_path && 0 !== strpos($public_path, $base_path . '/')) {
				return false;
			}

			$relative_path = ltrim(substr($public_path, strlen($base_path)), '/');
			if ('' === $relative_path) {
				return false;
			}

			foreach (explode('/', str_replace('\\', '/', $relative_path)) as $segment) {
				if ('' === $segment || '.' === $segment || '..' === $segment) {
					return false;
				}
			}

			return $relative_path;
		}

		private function normalize_public_url($public_url) {
			$public_url = trim((string) $public_url);
			if ('' === $public_url) {
				return '';
			}

			$public_url = preg_replace('/[#?].*$/', '', $public_url);
			if (!is_string($public_url) || '' === $public_url) {
				return '';
			}

			$parts = wp_parse_url($public_url);
			if (!is_array($parts) || empty($parts['path'])) {
				return $public_url;
			}

			$decoded_path = rawurldecode((string) $parts['path']);
			if ('' === $decoded_path) {
				return $public_url;
			}

			$normalized = '';
			if (!empty($parts['scheme'])) {
				$normalized .= $parts['scheme'] . '://';
			} elseif (0 === strpos($public_url, '//')) {
				$normalized .= '//';
			}
			if (!empty($parts['user'])) {
				$normalized .= $parts['user'];
				if (isset($parts['pass'])) {
					$normalized .= ':' . $parts['pass'];
				}
				$normalized .= '@';
			}
			if (!empty($parts['host'])) {
				$normalized .= $parts['host'];
			}
			if (!empty($parts['port'])) {
				$normalized .= ':' . $parts['port'];
			}
			$normalized .= $decoded_path;

			return $normalized ?: $public_url;
		}

		private function get_best_url_from_attachment_context($attachment, $size) {
			if ($this->can_serve_avif()) {
				$avif_url = $this->get_avif_url_from_attachment_context($attachment, $size);
				if ($avif_url) {
					return $avif_url;
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_attachment_context($attachment, $size);
				if ($webp_url) {
					return $webp_url;
				}
			}

			return false;
		}

		private function get_best_url_from_public_url($public_url) {
			$accept_key = ($this->can_serve_avif() ? 'a1' : 'a0') . '|' . ($this->can_serve_webp() ? 'w1' : 'w0');
			$normalized = $this->normalize_public_url($public_url);
			$lookup_key = 'best|' . $accept_key . '|' . md5('' !== $normalized ? $normalized : (string) $public_url);
			if (isset($this->optimized_public_url_lookup_memo[$lookup_key])) {
				return $this->optimized_public_url_lookup_memo[$lookup_key];
			}

			if ($this->can_serve_avif()) {
				$avif_url = $this->get_avif_url_from_public_url($public_url);
				if ($avif_url) {
					$this->remember_optimized_image_url_rewrite($public_url, $avif_url);
					return $this->memoize_public_url_lookup($lookup_key, $avif_url);
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_public_url($public_url);
				if ($webp_url) {
					$this->remember_optimized_image_url_rewrite($public_url, $webp_url);
					return $this->memoize_public_url_lookup($lookup_key, $webp_url);
				}
			}

			return $this->memoize_public_url_lookup($lookup_key, false);
		}
}
