<?php
/**
 * Ultra Cache Media Html Rewrite Trait for UltraCache media converter.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Media_Html_Rewrite_Trait
{

		public function filter_attachment_image_attributes($attr, $attachment, $size) {
			if (!$this->is_media_optimization_enabled()) {
				return $attr;
			}

			if (empty($attr['src'])) {
				return $attr;
			}

			$replacement_url = $this->get_best_url_from_attachment_context($attachment, $size);

			if ($replacement_url) {
				$attr['src'] = $replacement_url;
			}

			return $attr;
		}

		public function filter_attachment_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
			if (!$this->is_media_optimization_enabled()) {
				return $sources;
			}

			if (empty($sources) || !is_array($sources)) {
				return $sources;
			}

			foreach ($sources as $width => $source) {
				if (empty($source['url'])) {
					continue;
				}

				$replacement_url = $this->get_best_url_from_public_url($source['url']);

				if ($replacement_url) {
					$sources[$width]['url'] = $replacement_url;
				}
			}

			return $sources;
		}

		public function maybe_start_final_html_buffer() {
			if ($this->final_buffering) {
				return;
			}

			if (!$this->is_media_optimization_enabled()) {
				return;
			}

			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return;
			}

			if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || is_feed() || is_robots() || is_trackback()) {
				return;
			}

			if (!isset($_SERVER['REQUEST_METHOD'])) {
				return;
			}

			$method = strtoupper(ucwp_server_value('REQUEST_METHOD'));
			if (!in_array($method, array('GET', 'HEAD'), true)) {
				return;
			}

			ob_start(array($this, 'final_html_rewrite_callback'));
			$this->final_buffering = true;
		}

		public function final_html_rewrite_callback($html) {
			if (!is_string($html) || '' === $html) {
				return $html;
			}

			return $this->rewrite_html_image_urls($html);
		}

		public function rewrite_html_image_urls($html) {
			if (!$this->is_media_optimization_enabled()) {
				return $html;
			}

			if (!is_string($html) || '' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl']) || false === strpos($html, $uploads['baseurl'])) {
				return $html;
			}

			if (class_exists('WP_HTML_Tag_Processor')) {
				$html = $this->rewrite_html_image_urls_with_tag_processor($html);
			}

			$html = $this->rewrite_html_image_urls_with_regex($html);

			return $this->rewrite_html_upload_urls_globally($html);
		}

		private function rewrite_html_image_urls_with_tag_processor($html) {
			$processor = new WP_HTML_Tag_Processor($html);
			$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'href', 'data-href', 'data-lg-src', 'data-mfp-src');
			$srcset_attributes = array('srcset', 'data-srcset', 'data-lazy-srcset');
			$background_attributes = array('data-bg', 'data-background', 'data-bg-image', 'data-background-image');
			$meta_keys = array('og:image', 'twitter:image', 'twitter:image:src');

			while ($processor->next_tag()) {
				foreach ($single_url_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					if ($replacement && $replacement !== $current) {
						$processor->set_attribute($attribute, $replacement);
					}
				}

				foreach ($srcset_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$rewritten = $this->rewrite_srcset_string($current);
					if ($rewritten !== $current) {
						$processor->set_attribute($attribute, $rewritten);
					}
				}

				foreach ($background_attributes as $attribute) {
					$current = $processor->get_attribute($attribute);
					if (!is_string($current) || '' === $current) {
						continue;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					if ($replacement && $replacement !== $current) {
						$processor->set_attribute($attribute, $replacement);
					}
				}

				$style = $processor->get_attribute('style');
				if (is_string($style) && '' !== $style) {
					$rewritten_style = $this->rewrite_inline_style_urls($style);
					if ($rewritten_style !== $style) {
						$processor->set_attribute('style', $rewritten_style);
					}
				}

				if ('META' === strtoupper((string) $processor->get_tag())) {
					$property = strtolower(trim((string) $processor->get_attribute('property')));
					$name = strtolower(trim((string) $processor->get_attribute('name')));
					if (in_array($property, $meta_keys, true) || in_array($name, $meta_keys, true)) {
						$content = $processor->get_attribute('content');
						if (is_string($content) && '' !== $content) {
							$replacement = $this->get_best_url_from_public_url($content);
							if ($replacement && $replacement !== $content) {
								$processor->set_attribute('content', $replacement);
							}
						}
					}
				}
			}

			return $processor->get_updated_html();
		}

		private function rewrite_html_image_urls_with_regex($html) {
			$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'href', 'data-href', 'data-lg-src', 'data-mfp-src');
			foreach ($single_url_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			$srcset_attributes = array('srcset', 'data-srcset', 'data-lazy-srcset');
			foreach ($srcset_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						return $matches[1] . $this->rewrite_srcset_string($matches[2]) . $matches[3];
					},
					$html
				);
			}

			$html = preg_replace_callback(
				"/(style=[\"'])(.*?)([\"'])/is",
				function ($matches) {
					$style = $this->rewrite_inline_style_urls($matches[2]);
					return $matches[1] . $style . $matches[3];
				},
				$html
			);

			$background_attributes = array('data-bg', 'data-background', 'data-bg-image', 'data-background-image');
			foreach ($background_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			$meta_patterns = array(
				"/(<meta\b[^>]*\b(?:property|name)=[\"'](?:og:image|twitter:image|twitter:image:src)[\"'][^>]*\bcontent=[\"'])([^\"']+)([\"'][^>]*>)/i",
				"/(<meta\b[^>]*\bcontent=[\"'])([^\"']+)([\"'][^>]*\b(?:property|name)=[\"'](?:og:image|twitter:image|twitter:image:src)[\"'][^>]*>)/i",
			);

			foreach ($meta_patterns as $pattern) {
				$html = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						return $matches[1] . ($replacement ? $replacement : $matches[2]) . $matches[3];
					},
					$html
				);
			}

			return $html;
		}

		private function rewrite_inline_style_urls($style) {
			$style = (string) $style;
			if ('' === $style) {
				return $style;
			}

			return preg_replace_callback(
				'/url\(([^)]+)\)/i',
				function ($matches) {
					$raw   = trim((string) $matches[1]);
					$quote = '';
					if (strlen($raw) >= 2) {
						$first = substr($raw, 0, 1);
						$last  = substr($raw, -1);
						if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
							$quote = $first;
							$raw   = substr($raw, 1, -1);
						}
					}

					$replacement = $this->get_best_url_from_public_url($raw);
					if (!$replacement) {
						return $matches[0];
					}

					return 'url(' . $quote . $replacement . $quote . ')';
				},
				$style
			);
		}

		private function rewrite_srcset_string($srcset) {
			$srcset = (string) $srcset;
			if ('' === trim($srcset)) {
				return $srcset;
			}

			$parts = array_map('trim', explode(',', $srcset));
			foreach ($parts as $index => $part) {
				if ('' === $part) {
					continue;
				}

				$segments = preg_split('/\s+/', $part, 2);
				$url      = isset($segments[0]) ? $segments[0] : '';
				$descriptor = isset($segments[1]) ? $segments[1] : '';
				$replacement = $this->get_best_url_from_public_url($url);
				$parts[$index] = $replacement ? trim($replacement . ' ' . $descriptor) : $part;
			}

			return implode(', ', $parts);
		}

		private function rewrite_html_upload_urls_globally($html) {
			$html = (string) $html;
			if ('' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl'])) {
				return $html;
			}

			$baseurl = untrailingslashit($this->normalize_public_url($uploads['baseurl']));
			if ('' === $baseurl) {
				return $html;
			}

			$pattern = "/" . preg_quote($baseurl, "/") . "\/[^\"'\s<>()]+/u";
			$rewritten = preg_replace_callback(
				$pattern,
				function ($matches) {
					$current = isset($matches[0]) ? (string) $matches[0] : '';
					if ('' === $current) {
						return $current;
					}

					$replacement = $this->get_best_url_from_public_url($current);
					return $replacement ? $replacement : $current;
				},
				$html
			);

			return is_string($rewritten) ? $rewritten : $html;
		}

		private function can_serve_avif() {
			if (!$this->is_media_optimization_enabled() || !$this->media_output_mode_allows('avif')) {
				return false;
			}

			return $this->browser_accepts_image_format('avif');
		}

		private function can_serve_webp() {
			if (!$this->is_media_optimization_enabled() || !$this->media_output_mode_allows('webp')) {
				return false;
			}

			return $this->browser_accepts_image_format('webp');
		}

		private function browser_accepts_image_format($format) {
			$format = strtolower((string) $format);
			if ('' === $format) {
				return false;
			}

			$accept = ucwp_server_value('HTTP_ACCEPT');
			if ('' === $accept) {
				return false;
			}

			return (false !== stripos($accept, 'image/' . $format));
		}

		private function is_media_optimization_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				return !empty($settings['media_optimization_enabled']);
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			return !empty($settings['mediaOptimizationEnabled']);
		}

		private function get_media_output_mode() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				$mode = isset($settings['media_output_mode']) ? strtolower(trim((string) $settings['media_output_mode'])) : 'auto';
				return in_array($mode, array('auto', 'avif', 'webp'), true) ? $mode : 'auto';
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			$mode = isset($settings['mediaOutputMode']) ? strtolower(trim((string) $settings['mediaOutputMode'])) : 'auto';
			return in_array($mode, array('auto', 'avif', 'webp'), true) ? $mode : 'auto';
		}

		private function is_generate_on_upload_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_generate_on_upload', $settings)) {
					return !empty($settings['media_generate_on_upload']);
				}
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			if (array_key_exists('mediaGenerateOnUploadEnabled', $settings)) {
				return !empty($settings['mediaGenerateOnUploadEnabled']);
			}
			return true;
		}

		private function is_generate_on_demand_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_generate_on_demand', $settings)) {
					return !empty($settings['media_generate_on_demand']);
				}
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ucwp_settings', array());
			if (array_key_exists('mediaGenerateOnDemandEnabled', $settings)) {
				return !empty($settings['mediaGenerateOnDemandEnabled']);
			}
			return true;
		}

		private function media_output_mode_allows($format) {
			$format = strtolower((string) $format);
			$mode = $this->get_media_output_mode();
			if ('auto' === $mode) {
				return in_array($format, array('avif', 'webp'), true);
			}
			return $mode === $format;
		}

		private function get_on_demand_request_started_at() {
			if (null !== $this->on_demand_request_started_at) {
				return $this->on_demand_request_started_at;
			}

			if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
				$this->on_demand_request_started_at = (float) $_SERVER['REQUEST_TIME_FLOAT'];
			} else {
				$this->on_demand_request_started_at = microtime(true);
			}

			return $this->on_demand_request_started_at;
		}

		private function get_on_demand_max_conversions_per_request() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_MAX_PER_REQUEST') ? (int) UCWP_MEDIA_ON_DEMAND_MAX_PER_REQUEST : 3;
			$limit = (int) apply_filters('ucwp_media_on_demand_max_conversions_per_request', $default);
			return max(0, min(25, $limit));
		}

		private function get_on_demand_timeout_seconds() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_TIMEOUT_SECONDS') ? (float) UCWP_MEDIA_ON_DEMAND_TIMEOUT_SECONDS : 3.0;
			$timeout = (float) apply_filters('ucwp_media_on_demand_timeout_seconds', $default);
			return max(0.1, min(30.0, $timeout));
		}

		private function get_on_demand_lock_ttl_seconds() {
			$default = defined('UCWP_MEDIA_ON_DEMAND_LOCK_TTL') ? (int) UCWP_MEDIA_ON_DEMAND_LOCK_TTL : 120;
			$ttl = (int) apply_filters('ucwp_media_on_demand_lock_ttl_seconds', $default);
			return max(10, min(DAY_IN_SECONDS, $ttl));
		}

		private function is_frontend_on_demand_request() {
			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return false;
			}

			if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || is_feed() || is_robots() || is_trackback()) {
				return false;
			}

			$method = strtoupper(ucwp_server_value('REQUEST_METHOD'));
			return in_array($method, array('GET', 'HEAD'), true);
		}

		private function can_start_on_demand_conversion() {
			if (!$this->is_frontend_on_demand_request()) {
				return false;
			}

			$max = $this->get_on_demand_max_conversions_per_request();
			if ($max <= 0 || $this->on_demand_conversions_started >= $max) {
				return false;
			}

			$elapsed = microtime(true) - $this->get_on_demand_request_started_at();
			return $elapsed < $this->get_on_demand_timeout_seconds();
		}

		private function get_on_demand_lock_dir() {
			if (!defined('UCWP_CACHE_DIR') || '' === (string) UCWP_CACHE_DIR) {
				return false;
			}

			$dir = trailingslashit(UCWP_CACHE_DIR) . 'media-locks/';
			if (!file_exists($dir)) {
				wp_mkdir_p($dir);
			}

			if (!is_dir($dir) || !is_writable($dir)) {
				return false;
			}

			$index = trailingslashit($dir) . 'index.php';
			if (!file_exists($index)) {
				ucwp_safe_file_put_contents($index, "<?php\n// Silence is golden.\n", 0, 'media_on_demand_lock_index');
			}

			return trailingslashit($dir);
		}

		private function acquire_on_demand_image_lock($source_file, $format) {
			$lock_dir = $this->get_on_demand_lock_dir();
			if (!$lock_dir) {
				return false;
			}

			$source_real = realpath($source_file);
			if (!is_string($source_real) || '' === $source_real) {
				return false;
			}

			$key = hash('sha256', strtolower((string) $format) . '|' . $this->normalize_local_path_for_compare($source_real));
			$lock_file = $lock_dir . 'image-' . $key . '.lock';
			$ttl = $this->get_on_demand_lock_ttl_seconds();

			if (file_exists($lock_file)) {
				$mtime = (int) @filemtime($lock_file);
				if ($mtime > 0 && (time() - $mtime) > $ttl) {
					ucwp_safe_unlink($lock_file);
				}
			}

			$handle = @fopen($lock_file, 'x');
			if (!is_resource($handle)) {
				return false;
			}

			@fwrite($handle, json_encode(array(
				'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
				'time' => time(),
				'format' => strtolower((string) $format),
				'source' => $source_real,
			)) . "\n");
			@fclose($handle);

			return $lock_file;
		}

		private function release_on_demand_image_lock($lock_file) {
			if (!is_string($lock_file) || '' === $lock_file || !file_exists($lock_file)) {
				return;
			}

			$lock_dir = $this->get_on_demand_lock_dir();
			if (!$lock_dir || !$this->path_is_within_root($lock_file, $lock_dir)) {
				return;
			}

			ucwp_safe_unlink($lock_file);
		}

		private function ensure_generated_variant($source_file, $format) {
			$source_file = (string) $source_file;
			$format      = strtolower((string) $format);

			if (!$this->is_media_optimization_enabled() || !$this->is_generate_on_demand_enabled() || !$this->media_output_mode_allows($format)) {
				return false;
			}

			if ('' === $source_file || !in_array($format, array('avif', 'webp'), true) || !file_exists($source_file) || !is_readable($source_file)) {
				return false;
			}

			if (!$this->is_allowed_source_file($source_file)) {
				return false;
			}

			$existing = ('avif' === $format)
				? $this->get_avif_path_from_source($source_file)
				: $this->get_webp_path_from_source($source_file);

			if (!$existing) {
				return false;
			}

			if (file_exists($existing)) {
				return true;
			}

			if (!$this->can_start_on_demand_conversion()) {
				return false;
			}

			$lock_file = $this->acquire_on_demand_image_lock($source_file, $format);
			if (!$lock_file) {
				return false;
			}

			try {
				if (file_exists($existing)) {
					return true;
				}

				if (!$this->can_start_on_demand_conversion()) {
					return false;
				}

				$this->on_demand_conversions_started++;

				return ('avif' === $format)
					? (bool) $this->to_avif($source_file)
					: (bool) $this->to_webp($source_file);
			} finally {
				$this->release_on_demand_image_lock($lock_file);
			}
		}
}
