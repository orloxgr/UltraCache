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
			$optimized_relative_path = function_exists('ultracache_build_optimized_media_relative_path')
				? ultracache_build_optimized_media_relative_path($relative_path, 'avif')
				: false;

			if (!$optimized_relative_path) {
				return false;
			}

			return trailingslashit(ULTRACACHE_AVIF_DIR) . $optimized_relative_path;
		}

		private function get_webp_path_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			if (!$relative_path || !$this->is_webp_fallback_source_file($source_file)) {
				return false;
			}

			$optimized_relative_path = function_exists('ultracache_build_optimized_media_relative_path')
				? ultracache_build_optimized_media_relative_path($relative_path, 'webp')
				: false;

			if (!$optimized_relative_path) {
				return false;
			}

			return trailingslashit(ULTRACACHE_WEBP_DIR) . $optimized_relative_path;
		}

		private function optimized_storage_filesystem() {
			return function_exists('ultracache_get_wp_filesystem') ? ultracache_get_wp_filesystem() : false;
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
			$exists = $filesystem && method_exists($filesystem, 'exists') && $filesystem->exists($path);
			if ($exists && method_exists($filesystem, 'is_file')) {
				$exists = (bool) $filesystem->is_file($path);
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
			if (!$filesystem || !method_exists($filesystem, 'exists')) {
				return false;
			}

			$exists = (bool) $filesystem->exists($path);
			if ($exists && method_exists($filesystem, 'is_file')) {
				$exists = (bool) $filesystem->is_file($path);
			}
			if ($exists && method_exists($filesystem, 'is_readable')) {
				$exists = (bool) $filesystem->is_readable($path);
			}

			return (bool) $exists;
		}

		private function optimized_storage_ensure_directory($dir) {
			$dir = is_string($dir) ? wp_normalize_path($dir) : '';
			if ('' === $dir) {
				return false;
			}

			$filesystem = $this->optimized_storage_filesystem();
			$ready = false;
			if ($filesystem && method_exists($filesystem, 'is_dir') && $filesystem->is_dir($dir)) {
				$ready = true;
			} elseif (function_exists('wp_mkdir_p') && wp_mkdir_p($dir)) {
				$ready = true;
			} elseif ($filesystem && method_exists($filesystem, 'mkdir')) {
				$ready = (bool) $filesystem->mkdir($dir, defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755);
			}

			if ($ready) {
				$this->optimized_storage_harden_upload_permissions($dir, 'directory');
			}

			return (bool) $ready;
		}

		private function optimized_storage_get_uploads_basedir() {
			$uploads = function_exists('ultracache_uploads_base_info') ? ultracache_uploads_base_info() : wp_upload_dir(null, false);
			if (empty($uploads['basedir'])) {
				return '';
			}

			return untrailingslashit(wp_normalize_path((string) $uploads['basedir']));
		}

		private function optimized_storage_path_is_inside_uploads($path) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			$base = $this->optimized_storage_get_uploads_basedir();
			if ('' === $path || '' === $base) {
				return false;
			}

			$path = untrailingslashit($path);
			return $path === $base || 0 === strpos($path . '/', trailingslashit($base));
		}

		private function optimized_storage_chmod_path($path, $mode) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			$mode = absint($mode);
			if ('' === $path || $mode <= 0) {
				return false;
			}

			$filesystem = $this->optimized_storage_filesystem();
			if ($filesystem && method_exists($filesystem, 'chmod')) {
				return (bool) $filesystem->chmod($path, $mode);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Fallback only when WP_Filesystem does not expose chmod().
			return @chmod($path, $mode);
		}

		private function optimized_storage_harden_upload_permissions($path, $type = 'file') {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			$type = ('directory' === $type) ? 'directory' : 'file';
			if ('' === $path || !$this->optimized_storage_path_is_inside_uploads($path)) {
				return false;
			}

			$base = $this->optimized_storage_get_uploads_basedir();
			$dir = ('directory' === $type) ? untrailingslashit($path) : dirname($path);
			$dir_mode = defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755;
			$file_mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;

			if ('' !== $base && $this->optimized_storage_path_is_inside_uploads($dir)) {
				$current = $base;
				$this->optimized_storage_chmod_path($current, $dir_mode);
				$relative_dir = ltrim(substr(untrailingslashit($dir), strlen($base)), '/');
				foreach (array_filter(explode('/', $relative_dir), 'strlen') as $segment) {
					$current = trailingslashit($current) . $segment;
					if (is_dir($current)) {
						$this->optimized_storage_chmod_path($current, $dir_mode);
					}
				}
			}

			if ('file' === $type && is_file($path)) {
				$this->optimized_storage_chmod_path($path, $file_mode);
			}

			return true;
		}

		private function optimized_storage_forget_path($path) {
			$path = is_string($path) ? wp_normalize_path($path) : '';
			if ('' !== $path) {
				unset($this->optimized_variant_exists_memo['exists|' . $path]);
				$this->optimized_variant_freshness_memo = array();
				$this->optimized_source_fingerprint_memo = array();
			}
		}

		private function normalize_uploads_relative_image_path($relative_path) {
			return function_exists('ultracache_normalize_media_source_relative_path')
				? ultracache_normalize_media_source_relative_path($relative_path)
				: false;
		}

		private function get_uploads_source_path_from_relative_path($relative_path) {
			$relative_path = $this->normalize_uploads_relative_image_path($relative_path);
			$uploads = ultracache_uploads_base_info();
			if (!$relative_path || empty($uploads['basedir'])) {
				return false;
			}

			$uploads_root = realpath((string) $uploads['basedir']);
			$source_real = realpath(trailingslashit((string) $uploads['basedir']) . $relative_path);
			if (!is_string($uploads_root) || '' === $uploads_root || !is_string($source_real) || '' === $source_real) {
				return false;
			}

			return $this->path_is_within_root($source_real, $uploads_root) ? wp_normalize_path($source_real) : false;
		}

		private function get_optimized_source_fingerprint($source_file, $refresh = false) {
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			if ('' === $source_file) {
				return array('exists' => false, 'mtime' => 0, 'size' => 0);
			}

			$key = 'source|' . md5($source_file);
			if (!$refresh && isset($this->optimized_source_fingerprint_memo[$key])) {
				return (array) $this->optimized_source_fingerprint_memo[$key];
			}

			if (!$this->optimized_storage_readable_source_exists($source_file)) {
				$fingerprint = array('exists' => false, 'mtime' => 0, 'size' => 0);
				$this->optimized_source_fingerprint_memo[$key] = $fingerprint;
				return $fingerprint;
			}

			if ($refresh) {
				clearstatcache(true, $source_file);
			}

			$mtime = function_exists('ultracache_safe_filemtime')
				? ultracache_safe_filemtime($source_file, 'media_variant_source_fingerprint_mtime')
				: false;
			$size = function_exists('ultracache_safe_filesize')
				? ultracache_safe_filesize($source_file, 'media_variant_source_fingerprint_size')
				: false;
			$fingerprint = array(
				'exists' => true,
				'mtime' => false === $mtime ? 0 : max(0, (int) $mtime),
				'size' => false === $size ? 0 : max(0, (int) $size),
			);
			$this->optimized_source_fingerprint_memo[$key] = $fingerprint;
			return $fingerprint;
		}

		private function get_optimized_variant_freshness_state($source_file, $optimized_path, $refresh = false) {
			$source_file = is_string($source_file) ? wp_normalize_path($source_file) : '';
			$optimized_path = is_string($optimized_path) ? wp_normalize_path($optimized_path) : '';
			if ('' === $source_file || '' === $optimized_path) {
				return 'invalid';
			}

			$key = 'freshness|' . md5($source_file . '|' . $optimized_path);
			if (!$refresh && isset($this->optimized_variant_freshness_memo[$key])) {
				return (string) $this->optimized_variant_freshness_memo[$key];
			}

			$source_fingerprint = $this->get_optimized_source_fingerprint($source_file, $refresh);
			if (empty($source_fingerprint['exists'])) {
				$this->optimized_variant_freshness_memo[$key] = 'source_missing';
				return 'source_missing';
			}

			if (!$this->optimized_storage_path_exists($optimized_path, $refresh)) {
				$this->optimized_variant_freshness_memo[$key] = 'missing';
				return 'missing';
			}

			if ($refresh) {
				clearstatcache(true, $optimized_path);
			}

			$source_mtime = (int) ($source_fingerprint['mtime'] ?? 0);
			$variant_mtime = function_exists('ultracache_safe_filemtime')
				? ultracache_safe_filemtime($optimized_path, 'media_variant_serving_output_freshness')
				: false;

			if ($source_mtime <= 0 || false === $variant_mtime || (int) $variant_mtime <= 0) {
				$this->optimized_variant_freshness_memo[$key] = 'indeterminate';
				return 'indeterminate';
			}

			$state = ((int) $variant_mtime >= $source_mtime) ? 'fresh' : 'stale';
			$this->optimized_variant_freshness_memo[$key] = $state;
			return $state;
		}

		private function get_optimized_media_variant_lookup($relative_path, $format) {
			$format = strtolower((string) $format);
			$relative_path = $this->normalize_uploads_relative_image_path($relative_path);
			if (!$relative_path || !in_array($format, array('avif', 'webp'), true)) {
				return array('status' => 'invalid', 'url' => false);
			}

			$optimized_relative_path = function_exists('ultracache_build_optimized_media_relative_path')
				? ultracache_build_optimized_media_relative_path($relative_path, $format)
				: false;
			$base_dir = ('avif' === $format)
				? (defined('ULTRACACHE_AVIF_DIR') ? ULTRACACHE_AVIF_DIR : '')
				: (defined('ULTRACACHE_WEBP_DIR') ? ULTRACACHE_WEBP_DIR : '');
			$source_file = $this->get_uploads_source_path_from_relative_path($relative_path);

			if (!is_string($optimized_relative_path) || '' === $optimized_relative_path || '' === (string) $base_dir || !$source_file) {
				return array('status' => $source_file ? 'invalid' : 'source_missing', 'url' => false);
			}

			$optimized_path = trailingslashit((string) $base_dir) . $optimized_relative_path;
			$status = $this->get_optimized_variant_freshness_state($source_file, $optimized_path);
			$source_fingerprint = $this->get_optimized_source_fingerprint($source_file);
			$lookup = array(
				'status' => $status,
				'url' => false,
				'sourcePath' => $source_file,
				'optimizedPath' => $optimized_path,
				'sourceMtime' => (int) ($source_fingerprint['mtime'] ?? 0),
				'sourceSize' => (int) ($source_fingerprint['size'] ?? 0),
			);
			if ('fresh' !== $status) {
				return $lookup;
			}

			$lookup['url'] = $this->get_root_relative_optimized_media_url($format, $optimized_relative_path);
			return $lookup;
		}

		private function get_existing_optimized_url_from_uploads_relative_path($relative_path, $format) {
			$lookup = $this->get_optimized_media_variant_lookup($relative_path, $format);
			return !empty($lookup['url']) ? (string) $lookup['url'] : false;
		}

		private function get_local_asset_optimized_media_variant_lookup(array $source, $format) {
			$format = strtolower((string) $format);
			$relative_path = function_exists('ultracache_build_local_asset_optimized_media_relative_path')
				? ultracache_build_local_asset_optimized_media_relative_path($source, $format)
				: false;
			$base_dir = function_exists('ultracache_local_asset_optimized_images_storage_dir')
				? ultracache_local_asset_optimized_images_storage_dir($format)
				: '';
			$source_file = isset($source['local_path']) ? wp_normalize_path((string) $source['local_path']) : '';

			if (!is_string($relative_path) || '' === $relative_path || '' === (string) $base_dir || '' === $source_file) {
				return array('status' => '' === $source_file ? 'source_missing' : 'invalid', 'url' => false);
			}

			$optimized_path = trailingslashit((string) $base_dir) . $relative_path;
			$status = $this->get_optimized_variant_freshness_state($source_file, $optimized_path);
			$source_fingerprint = $this->get_optimized_source_fingerprint($source_file);
			$lookup = array(
				'status' => $status,
				'url' => false,
				'sourcePath' => $source_file,
				'optimizedPath' => $optimized_path,
				'optimizedRelativePath' => $relative_path,
				'sourceScope' => (string) ($source['source_scope'] ?? ''),
				'sourceOwner' => (string) ($source['source_owner'] ?? ''),
				'sourceIdentity' => (string) ($source['source_identity'] ?? ''),
				'sourceMtime' => (int) ($source_fingerprint['mtime'] ?? 0),
				'sourceSize' => (int) ($source_fingerprint['size'] ?? 0),
			);
			if ('fresh' !== $status) {
				return $lookup;
			}

			$base_path = function_exists('ultracache_local_asset_optimized_images_storage_url_path')
				? ultracache_local_asset_optimized_images_storage_url_path($format)
				: '';
			if ('' === $base_path) {
				$lookup['status'] = 'invalid';
				return $lookup;
			}

			$lookup['url'] = trailingslashit($base_path) . implode('/', array_map('rawurlencode', explode('/', $relative_path)));
			return $lookup;
		}

		private function get_optimized_media_variant_lookup_from_source_descriptor(array $source, $format) {
			if (function_exists('ultracache_get_optimized_media_variant_lookup_for_source')) {
				return ultracache_get_optimized_media_variant_lookup_for_source($source, $format);
			}

			$scope = (string) ($source['source_scope'] ?? '');
			if ('uploads' === $scope) {
				$relative_path = (string) ($source['source_relative_path'] ?? '');
				return '' !== $relative_path
					? $this->get_optimized_media_variant_lookup($relative_path, $format)
					: array('status' => 'invalid', 'url' => false);
			}

			return $this->get_local_asset_optimized_media_variant_lookup($source, $format);
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
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			return $relative_path ? $this->get_existing_optimized_url_from_uploads_relative_path($relative_path, 'avif') : false;
		}

		private function get_webp_url_from_source($source_file) {
			$relative_path = $this->get_uploads_relative_path_from_source($source_file);
			return $relative_path ? $this->get_existing_optimized_url_from_uploads_relative_path($relative_path, 'webp') : false;
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

			$base_url = ('avif' === $format && defined('ULTRACACHE_AVIF_URL')) ? ULTRACACHE_AVIF_URL : (defined('ULTRACACHE_WEBP_URL') ? ULTRACACHE_WEBP_URL : '');
			$base_path = (string) wp_parse_url((string) $base_url, PHP_URL_PATH);
			if ('' === $base_path) {
				$base_path = ultracache_optimized_images_storage_url_path($format);
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

		/**
		 * Return a DB-authoritative terminal lookup before touching source/variant files.
		 *
		 * `known=false` means the caller must use the normal filesystem discovery path.
		 * `known=true` means the queue/unit inventory is authoritative for this source:
		 * DONE may return its recorded generated URL; FAILED/SKIPPED return the original.
		 *
		 * @param string $public_url Public source URL.
		 * @param string $format     avif or webp.
		 * @return array{known:bool,status:string,url:string|false}
		 */
		private function get_terminal_media_variant_lookup_from_public_url($public_url, $format) {
			$format = strtolower(trim((string) $format));
			$normalized = $this->normalize_public_url($public_url);
			$memo_key = $format . '|terminal|' . md5('' !== $normalized ? $normalized : (string) $public_url);
			if (isset($this->terminal_public_media_lookup_memo[$memo_key])) {
				return (array) $this->terminal_public_media_lookup_memo[$memo_key];
			}

			$unknown = array('known' => false, 'status' => '', 'url' => false);
			if (!in_array($format, array('avif', 'webp'), true)
				|| !function_exists('ultracache_local_public_source_identity_descriptor')
				|| self::MEDIA_QUEUE_DB_VERSION !== (string) get_option(self::MEDIA_QUEUE_DB_VERSION_OPTION, '')
				|| self::MEDIA_QUEUE_UNITS_DB_VERSION !== (string) get_option(self::MEDIA_QUEUE_UNITS_DB_VERSION_OPTION, '')) {
				$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
				return $unknown;
			}

			$source = ultracache_local_public_source_identity_descriptor(
				$public_url,
				array('jpg', 'jpeg', 'png', 'webp', 'avif')
			);
			if (empty($source)) {
				$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
				return $unknown;
			}

			$scope = (string) ($source['source_scope'] ?? '');
			if ('uploads' === $scope) {
				$relative_path = function_exists('ultracache_normalize_media_source_relative_path')
					? ultracache_normalize_media_source_relative_path((string) ($source['source_relative_path'] ?? ''), $format)
					: false;
				if (!$relative_path) {
					$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
					return $unknown;
				}

				$row = method_exists($this, 'get_latest_media_queue_unit_state_by_source_path')
					? $this->get_latest_media_queue_unit_state_by_source_path($relative_path, $format)
					: array();
				if (!is_array($row)) {
					$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
					return $unknown;
				}

				$status = strtolower((string) ($row['status'] ?? ''));
				if (!in_array($status, array('done', 'failed', 'skipped'), true)) {
					$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
					return $unknown;
				}
				$url = false;
				if ('done' === $status) {
					$target_relative_path = ltrim(str_replace('\\', '/', (string) ($row['target_relative_path'] ?? '')), '/');
					if ('' !== $target_relative_path) {
						$url = $this->get_root_relative_optimized_media_url($format, $target_relative_path);
					}
				}
				$result = array('known' => true, 'status' => $status, 'url' => $url ?: false);
				$this->terminal_public_media_lookup_memo[$memo_key] = $result;
				return $result;
			}

			$source_identity = strtolower(trim((string) ($source['source_identity'] ?? '')));
			if (!preg_match('/^[a-f0-9]{64}$/', $source_identity)) {
				$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
				return $unknown;
			}
			$status = method_exists($this, 'get_existing_on_demand_media_queue_status')
				? $this->get_existing_on_demand_media_queue_status('local_asset', $source_identity, 'best', $format, 0)
				: '';
			if (!in_array($status, array('done', 'failed', 'skipped'), true)) {
				$this->terminal_public_media_lookup_memo[$memo_key] = $unknown;
				return $unknown;
			}
			$url = false;
			if ('done' === $status && function_exists('ultracache_build_local_asset_optimized_media_relative_path')) {
				$target_relative_path = ultracache_build_local_asset_optimized_media_relative_path($source, $format);
				$base_path = function_exists('ultracache_local_asset_optimized_images_storage_url_path')
					? ultracache_local_asset_optimized_images_storage_url_path($format)
					: '';
				if (is_string($target_relative_path) && '' !== $target_relative_path && '' !== $base_path) {
					$url = trailingslashit($base_path) . implode('/', array_map('rawurlencode', explode('/', $target_relative_path)));
				}
			}
			$result = array('known' => true, 'status' => $status, 'url' => $url ?: false);
			$this->terminal_public_media_lookup_memo[$memo_key] = $result;
			return $result;
		}

		private function get_local_image_source_descriptor_from_public_url($public_url) {
			$normalized = $this->normalize_public_url($public_url);
			$key = md5('' !== $normalized ? $normalized : (string) $public_url);
			if (isset($this->local_public_image_source_memo[$key])) {
				return $this->local_public_image_source_memo[$key];
			}

			$descriptor = function_exists('ultracache_local_public_source_descriptor')
				? ultracache_local_public_source_descriptor(
					$public_url,
					array('jpg', 'jpeg', 'png', 'webp', 'avif')
				)
				: array();
			$this->local_public_image_source_memo[$key] = is_array($descriptor) ? $descriptor : array();
			return $this->local_public_image_source_memo[$key];
		}

		private function maybe_queue_missing_optimized_media_for_source($public_url, array $source, $format, $reason, array $fingerprint = array()) {
			$reason = strtolower(trim((string) $reason));
			if (!in_array($reason, array('missing', 'stale'), true)) {
				return false;
			}

			// 3.11.05: bound attempted discovery work, not successful queue inserts.
			// This common choke point covers uploads, local assets and Slider Revolution.
			if (!method_exists($this, 'consume_on_demand_queue_discovery_attempt') || !$this->consume_on_demand_queue_discovery_attempt()) {
				return false;
			}

			if ('uploads' === (string) ($source['source_scope'] ?? '')) {
				return $this->maybe_queue_missing_optimized_media_from_public_url($public_url, $format, $reason, $fingerprint);
			}

			return method_exists($this, 'maybe_queue_missing_local_asset_media')
				? $this->maybe_queue_missing_local_asset_media($public_url, $source, $format, $reason, $fingerprint)
				: false;
		}

		private function get_avif_url_from_public_url($public_url) {
			$key = $this->get_public_url_lookup_cache_key('avif', $public_url);
			if (isset($this->optimized_public_url_lookup_memo[$key])) {
				return $this->optimized_public_url_lookup_memo[$key];
			}

			$terminal = $this->get_terminal_media_variant_lookup_from_public_url($public_url, 'avif');
			if (!empty($terminal['known'])) {
				return $this->memoize_public_url_lookup($key, !empty($terminal['url']) ? (string) $terminal['url'] : false);
			}

			$source = $this->get_local_image_source_descriptor_from_public_url($public_url);
			if (empty($source)) {
				return $this->memoize_public_url_lookup($key, false);
			}

			$lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, 'avif');
			if (!empty($lookup['url'])) {
				return $this->memoize_public_url_lookup($key, (string) $lookup['url']);
			}

			if (in_array((string) ($lookup['status'] ?? ''), array('missing', 'stale'), true)) {
				$this->maybe_queue_missing_optimized_media_for_source(
					$public_url,
					$source,
					'avif',
					(string) $lookup['status'],
					array(
						'mtime' => (int) ($lookup['sourceMtime'] ?? 0),
						'size' => (int) ($lookup['sourceSize'] ?? 0),
					)
				);
			}
			return $this->memoize_public_url_lookup($key, false);
		}

		private function get_webp_url_from_public_url($public_url) {
			$key = $this->get_public_url_lookup_cache_key('webp', $public_url);
			if (isset($this->optimized_public_url_lookup_memo[$key])) {
				return $this->optimized_public_url_lookup_memo[$key];
			}

			$terminal = $this->get_terminal_media_variant_lookup_from_public_url($public_url, 'webp');
			if (!empty($terminal['known'])) {
				return $this->memoize_public_url_lookup($key, !empty($terminal['url']) ? (string) $terminal['url'] : false);
			}

			$source = $this->get_local_image_source_descriptor_from_public_url($public_url);
			if (empty($source)) {
				return $this->memoize_public_url_lookup($key, false);
			}

			$lookup = $this->get_optimized_media_variant_lookup_from_source_descriptor($source, 'webp');
			if (!empty($lookup['url'])) {
				return $this->memoize_public_url_lookup($key, (string) $lookup['url']);
			}

			if (in_array((string) ($lookup['status'] ?? ''), array('missing', 'stale'), true)) {
				$this->maybe_queue_missing_optimized_media_for_source(
					$public_url,
					$source,
					'webp',
					(string) $lookup['status'],
					array(
						'mtime' => (int) ($lookup['sourceMtime'] ?? 0),
						'size' => (int) ($lookup['sourceSize'] ?? 0),
					)
				);
			}
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
			$uploads = ultracache_uploads_base_info();

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
			$source = $this->get_local_image_source_descriptor_from_public_url($public_url);
			if (empty($source) || 'uploads' !== (string) ($source['source_scope'] ?? '')) {
				return false;
			}

			$relative_path = (string) ($source['source_relative_path'] ?? '');
			return '' !== $relative_path ? $relative_path : false;
		}

		private function normalize_public_url($public_url) {
			return function_exists('ultracache_normalize_public_url') ? ultracache_normalize_public_url($public_url) : trim((string) $public_url);
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
					return $this->memoize_public_url_lookup($lookup_key, $avif_url);
				}
			}

			if ($this->can_serve_webp()) {
				$webp_url = $this->get_webp_url_from_public_url($public_url);
				if ($webp_url) {
					return $this->memoize_public_url_lookup($lookup_key, $webp_url);
				}
			}

			return $this->memoize_public_url_lookup($lookup_key, false);
		}
}
