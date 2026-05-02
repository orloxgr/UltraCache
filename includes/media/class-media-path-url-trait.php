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

		private function get_avif_url_from_source($source_file) {
			$avif_path = $this->get_avif_path_from_source($source_file);

			if (!$avif_path) {
				return false;
			}

			if (!file_exists($avif_path)) {
				$generated = $this->ensure_generated_variant($source_file, 'avif');
				if (!$generated || !file_exists($avif_path)) {
					return false;
				}
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_AVIF_DIR), '', $avif_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return trailingslashit(UCWP_AVIF_URL) . str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
		}

		private function get_webp_url_from_source($source_file) {
			$webp_path = $this->get_webp_path_from_source($source_file);

			if (!$webp_path) {
				return false;
			}

			if (!file_exists($webp_path)) {
				$generated = $this->ensure_generated_variant($source_file, 'webp');
				if (!$generated || !file_exists($webp_path)) {
					return false;
				}
			}

			$relative_path = ltrim(str_replace(trailingslashit(UCWP_WEBP_DIR), '', $webp_path), '/\\');

			if ('' === $relative_path) {
				return false;
			}

			return trailingslashit(UCWP_WEBP_URL) . str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);
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
			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			$uploads_root = realpath($uploads['basedir']);
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_path = trailingslashit($uploads_root) . $relative_path;

			return $this->get_avif_url_from_source($source_path);
		}

		private function get_webp_url_from_public_url($public_url) {
			$relative_path = $this->get_uploads_relative_path_from_public_url($public_url);
			if (!$relative_path) {
				return false;
			}

			$uploads = wp_get_upload_dir();
			$uploads_root = realpath($uploads['basedir']);
			if (!is_string($uploads_root) || '' === $uploads_root) {
				return false;
			}

			$source_path = trailingslashit($uploads_root) . $relative_path;

			return $this->get_webp_url_from_source($source_path);
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
			if ($this->can_serve_avif()) {
				$avif_url = $this->get_avif_url_from_public_url($public_url);
				if ($avif_url) {
					return $avif_url;
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_public_url($public_url);
				if ($webp_url) {
					return $webp_url;
				}
			}

			return false;
		}
}
