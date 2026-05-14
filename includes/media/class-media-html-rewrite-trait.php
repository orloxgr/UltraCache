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

			// UltraCache 2.56.219: final full-page media rewriting is now part of the
			// engine-owned unified final output pipeline. Keeping a separate template
			// enhancement callback here made final HTML transforms diverge between
			// browser output, warm output, and cached storage. Content/image filters
			// above still run normally; the full HTML pass is called explicitly by the
			// engine finalizer.
			return;
		}

		public function final_html_rewrite_callback($html) {
			if (!is_string($html) || '' === $html) {
				return $html;
			}

			return $this->rewrite_html_image_urls($html);
		}

		/**
		 * Rewrite full-document image URLs using an explicit, scoped Accept header.
		 *
		 * This is used by warm/cache-storage paths where the cache bucket is known
		 * from the loopback request, but the current PHP process does not have the
		 * same HTTP_ACCEPT value as the browser/loopback request.
		 *
		 * @param string $html          Full frontend HTML.
		 * @param string $accept_header HTTP Accept header for the target cache bucket.
		 * @return string
		 */
		public function rewrite_html_image_urls_with_accept($html, $accept_header) {
			$previous_accept = $this->media_rewrite_accept_context;
			$this->media_rewrite_accept_context = is_string($accept_header) ? $accept_header : '';

			try {
				$rewritten = $this->rewrite_html_image_urls($html);
			} finally {
				$this->media_rewrite_accept_context = $previous_accept;
			}

			return is_string($rewritten) ? $rewritten : $html;
		}

		/**
		 * Rewrite local upload image URLs in full frontend HTML/cache-storage output.
		 *
		 * Full-document cache rewrites use a request-local replacement map so builder
		 * markup, scripts, and JSON-like payloads keep their original structure.
		 * Content/render filters use rewrite_filtered_content_image_urls() instead,
		 * where every rewritten attribute value is escaped for its output context.
		 *
		 * @param string $html Existing frontend HTML.
		 * @return string HTML with validated UltraCache image URL replacements.
		 */
		public function rewrite_html_image_urls($html) {
			if (!$this->is_media_optimization_enabled()) {
				return $html;
			}

			if (!is_string($html) || '' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl'])) {
				return $html;
			}

			// 2.56.180: do not require the full uploads base URL to be present. Many optimized
			// cached pages use root-relative /wp-content/uploads/... image URLs, especially behind
			// HTTPS offload or reverse proxies. The per-URL resolver below validates same-site
			// uploads paths safely, so the final HTML pass can scan the current page HTML without
			// doing a global media-library walk.
			if (!$this->html_contains_upload_candidate($html, $uploads)) {
				return $html;
			}

			// 2.57.137: avoid stacking multiple full-document regex/tag passes during
			// cache STORE, warm and frontend snippet filters. Discover local uploads
			// once, resolve each source URL through the request-level lookup manifest,
			// then apply the accumulated replacement map in one str_replace pass.
			return $this->rewrite_html_upload_image_urls_with_single_pass_map($html, $uploads);
		}

		/**
		 * Rewrite image URLs returned by WordPress content/block filters.
		 *
		 * These callbacks return markup that WordPress renders immediately, so each
		 * UltraCache-generated replacement is inserted through tag/attribute-aware
		 * paths instead of the full-document str_replace map used by cache storage.
		 *
		 * @param string $html Existing filtered HTML fragment.
		 * @return string HTML fragment with context-escaped image URL replacements.
		 */
		public function rewrite_filtered_content_image_urls($html) {
			if (!$this->is_media_optimization_enabled()) {
				return $html;
			}

			if (!is_string($html) || '' === $html) {
				return $html;
			}

			$uploads = wp_get_upload_dir();
			if (empty($uploads['baseurl']) || !$this->html_contains_upload_candidate($html, $uploads)) {
				return $html;
			}

			if (class_exists('WP_HTML_Tag_Processor')) {
				return $this->rewrite_html_image_urls_with_tag_processor($html);
			}

			return $this->rewrite_html_image_urls_with_regex($html);
		}

		private function html_contains_upload_candidate($html, array $uploads) {
			$html = (string) $html;
			if ('' === $html) {
				return false;
			}

			$baseurl = !empty($uploads['baseurl']) ? untrailingslashit($this->normalize_public_url($uploads['baseurl'])) : '';
			if ('' !== $baseurl && false !== strpos($html, $baseurl)) {
				return true;
			}

			$base_path = '';
			if ('' !== $baseurl) {
				$base_path = (string) wp_parse_url($baseurl, PHP_URL_PATH);
			}
			if ('' === $base_path) {
				$content_path = (string) wp_parse_url(content_url('uploads/'), PHP_URL_PATH);
				$base_path = rtrim($content_path, '/');
			}

			$base_path = '/' . ltrim(str_replace('\\', '/', (string) $base_path), '/');
			$base_path = rtrim($base_path, '/');
			if ('' !== $base_path && '/' !== $base_path && false !== strpos($html, $base_path . '/')) {
				return true;
			}

			return false !== strpos($html, '/wp-content/uploads/');
		}

		private function rewrite_html_upload_image_urls_with_single_pass_map($html, array $uploads) {
			$html = (string) $html;
			if ('' === $html) {
				return $html;
			}

			$base_path = $this->get_uploads_public_base_path_for_html_rewrite($uploads);
			if ('' === $base_path || '/' === $base_path) {
				return $html;
			}

			$tokens = $this->discover_upload_image_url_tokens_for_rewrite($html, $base_path);
			if (empty($tokens)) {
				return $html;
			}

			foreach ($tokens as $token => $slash_escaped) {
				$this->rewrite_single_upload_image_url_token($token, (bool) $slash_escaped);
			}

			return $this->apply_optimized_image_url_rewrite_map($html);
		}

		private function get_uploads_public_base_path_for_html_rewrite(array $uploads) {
			$baseurl = !empty($uploads['baseurl']) ? untrailingslashit($this->normalize_public_url($uploads['baseurl'])) : '';
			$base_path = '' !== $baseurl ? (string) wp_parse_url($baseurl, PHP_URL_PATH) : '';
			if ('' === $base_path) {
				$base_path = (string) wp_parse_url(content_url('uploads/'), PHP_URL_PATH);
			}

			$base_path = '/' . ltrim(str_replace('\\', '/', (string) $base_path), '/');
			return rtrim($base_path, '/');
		}

		private function discover_upload_image_url_tokens_for_rewrite($html, $base_path) {
			$html = (string) $html;
			$base_path = '/' . trim(str_replace('\\', '/', (string) $base_path), '/');
			if ('' === $html || '' === $base_path || '/' === $base_path) {
				return array();
			}

			$tokens = array();
			$unescaped = '~(?<![A-Za-z0-9_./:-])((?:https?://[^/\s"\x27<>\)]+)?' . preg_quote($base_path, '~') . '/[^\s"\x27<>\),]+\.(?:jpe?g|png|webp)(?:\?[^\s"\x27<>\),]*)?)~iu';
			if (preg_match_all($unescaped, $html, $matches)) {
				foreach ((array) $matches[1] as $match) {
					$match = trim((string) $match);
					if ('' !== $match && false === strpos($match, '/uc-images/')) {
						$tokens[$match] = false;
					}
				}
			}

			if (false !== strpos($html, '\/')) {
				$decoded_html = str_replace('\/', '/', $html);
				if (preg_match_all($unescaped, $decoded_html, $matches)) {
					foreach ((array) $matches[1] as $match) {
						$match = trim((string) $match);
						if ('' === $match || false !== strpos($match, '/uc-images/')) {
							continue;
						}

						$escaped_match = str_replace('/', '\/', $match);
						if ('' !== $escaped_match && false !== strpos($html, $escaped_match)) {
							$tokens[$escaped_match] = true;
						}
					}
				}
			}

			if (count($tokens) > 500) {
				$tokens = array_slice($tokens, 0, 500, true);
			}

			return $tokens;
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
					$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
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
					$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
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
							$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
							if ($replacement && $replacement !== $content) {
								$processor->set_attribute('content', $replacement);
							}
						}
					}
				}
			}

			return $processor->get_updated_html();
		}


		private function sanitize_rewritten_public_url_raw($url) {
			$url = trim((string) $url);
			if ('' === $url) {
				return '';
			}

			$url = html_entity_decode(str_replace('\/', '/', $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$url = trim($url);
			if ('' === $url || false === strpos($url, '/uc-images/')) {
				return '';
			}

			$url = esc_url_raw($url);
			if ('' === $url || false === strpos($url, '/uc-images/')) {
				return '';
			}

			if (preg_match("/[\x00-\x1F\x7F\s<>\"']/", $url)) {
				return '';
			}

			return $url;
		}

		private function escape_rewritten_public_url_for_html_attribute($url) {
			$url = $this->sanitize_rewritten_public_url_raw($url);
			return '' !== $url ? esc_url($url) : '';
		}

		private function escape_rewritten_public_url_for_css_url($url) {
			$url = $this->sanitize_rewritten_public_url_raw($url);
			if ('' === $url || preg_match("/[\x00-\x1F\x7F\s()\"']/", $url)) {
				return '';
			}

			return $url;
		}

		private function sanitize_srcset_descriptor($descriptor) {
			$descriptor = trim((string) $descriptor);
			if ('' === $descriptor) {
				return '';
			}

			if (preg_match('/^(?:[1-9][0-9]*w|(?:[0-9]+(?:\.[0-9]+)?)x)$/', $descriptor)) {
				return $descriptor;
			}

			return '';
		}

		private function rewrite_html_image_urls_with_regex($html) {
			$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'href', 'data-href', 'data-lg-src', 'data-mfp-src');
			foreach ($single_url_attributes as $attribute) {
				$pattern = "/(" . preg_quote($attribute, '/') . "=[\"'])([^\"']+)([\"'])/i";
				$html    = preg_replace_callback(
					$pattern,
					function ($matches) {
						$replacement = $this->get_best_url_from_public_url($matches[2]);
						$replacement = $this->escape_rewritten_public_url_for_html_attribute($replacement);
						return $replacement ? $matches[1] . $replacement . $matches[3] : (string) ($matches[0] ?? '');
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
						$rewritten = $this->rewrite_srcset_string($matches[2]);
						return $rewritten !== $matches[2] ? $matches[1] . esc_attr($rewritten) . $matches[3] : (string) ($matches[0] ?? '');
					},
					$html
				);
			}

			$html = preg_replace_callback(
				"/(style=[\"'])(.*?)([\"'])/is",
				function ($matches) {
					$style = $this->rewrite_inline_style_urls($matches[2]);
					return $style !== $matches[2] ? $matches[1] . esc_attr($style) . $matches[3] : (string) ($matches[0] ?? '');
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
						$replacement = $this->escape_rewritten_public_url_for_html_attribute($replacement);
						return $replacement ? $matches[1] . $replacement . $matches[3] : (string) ($matches[0] ?? '');
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
						$replacement = $this->escape_rewritten_public_url_for_html_attribute($replacement);
						return $replacement ? $matches[1] . $replacement . $matches[3] : (string) ($matches[0] ?? '');
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
					return $this->rewrite_single_css_url_function_match($matches[0], $matches[1]);
				},
				$style
			);
		}


		/**
		 * Rewrite local upload background-image URLs inside a stylesheet body.
		 *
		 * External CSS is shared across cache variants, so this method does not rely on
		 * the current browser Accept header. In Automatic mode it emits a safe fallback
		 * declaration followed by image-set() when AVIF/WebP variants are available;
		 * older browsers keep the original declaration, while modern browsers choose the
		 * best optimized source they support. For explicit AVIF/WebP modes it rewrites
		 * the URL directly to the requested optimized format when available.
		 *
		 * @param string $css        CSS content.
		 * @param string $source_url Public URL of the source stylesheet.
		 * @param array  $stats      Optional stats array passed by reference.
		 * @return string
		 */
		public function rewrite_css_image_urls_for_stylesheet($css, $source_url = '', &$stats = array()) {
			$css = (string) $css;
			if (!$this->is_media_optimization_enabled() || '' === $css || false === stripos($css, 'url(')) {
				return $css;
			}

			if (!is_array($stats)) {
				$stats = array();
			}
			foreach (array('cssImageUrlsScanned', 'cssImageUrlsRewritten', 'cssImageUrlsImageSet', 'cssImageUrlsSkipped') as $key) {
				if (!isset($stats[$key])) {
					$stats[$key] = 0;
				}
			}

			$mode = $this->get_media_output_mode();
			$rewritten = preg_replace_callback(
				'/(\bbackground(?:-image)?\s*:\s*)([^;{}]*url\([^;{}]+?\)[^;{}]*)(;?)/i',
				function ($matches) use ($source_url, $mode, &$stats) {
					$prefix = isset($matches[1]) ? (string) $matches[1] : '';
					$value = isset($matches[2]) ? (string) $matches[2] : '';
					$suffix = isset($matches[3]) ? (string) $matches[3] : '';
					if ('' === $prefix || '' === $value || false === stripos($value, 'url(')) {
						return (string) ($matches[0] ?? '');
					}

					$changed = false;
					$used_image_set = false;
					$new_value = preg_replace_callback(
						'/url\(([^)]+)\)/i',
						function ($url_matches) use ($source_url, $mode, &$stats, &$changed, &$used_image_set) {
							$stats['cssImageUrlsScanned']++;
							$original_match = isset($url_matches[0]) ? (string) $url_matches[0] : '';
							$inner = isset($url_matches[1]) ? (string) $url_matches[1] : '';
							$candidate = $this->resolve_css_image_url_candidate($inner, $source_url);
							if ('' === $candidate) {
								$stats['cssImageUrlsSkipped']++;
								return $original_match;
							}

							$avif = false;
							$webp = false;
							if ('webp' !== $mode && $this->media_output_mode_allows('avif')) {
								$avif = $this->get_avif_url_from_public_url($candidate);
							}
							if (!$avif && 'avif' !== $mode && $this->media_output_mode_allows('webp')) {
								$webp = $this->get_webp_url_from_public_url($candidate);
							}
							$original = $this->sanitize_css_image_original_url($candidate);
							if (!$avif && !$webp) {
								$stats['cssImageUrlsSkipped']++;
								return $original_match;
							}

							if ('auto' === $mode) {
								$image_set = $this->build_css_image_set_value($avif, $webp, $original);
								if ('' === $image_set) {
									$stats['cssImageUrlsSkipped']++;
									return $original_match;
								}
								$changed = true;
								$used_image_set = true;
								$stats['cssImageUrlsRewritten']++;
								$stats['cssImageUrlsImageSet']++;
								return $image_set;
							}

							$replacement = ('avif' === $mode) ? $avif : $webp;
							if (!$replacement) {
								$replacement = $avif ? $avif : $webp;
							}
							$replacement = $this->escape_rewritten_public_url_for_css_url($replacement);
							if ('' === $replacement) {
								$stats['cssImageUrlsSkipped']++;
								return $original_match;
							}

							$changed = true;
							$stats['cssImageUrlsRewritten']++;
							return 'url("' . $replacement . '")';
						},
						$value
					);

					if (!$changed || !is_string($new_value) || '' === $new_value) {
						return (string) ($matches[0] ?? '');
					}

					$optimized_declaration = $prefix . $new_value . ('' !== $suffix ? $suffix : ';');
					if ($used_image_set) {
						$original_declaration = $prefix . $value . ('' !== $suffix ? $suffix : ';');
						return $original_declaration . $optimized_declaration;
					}

					return $optimized_declaration;
				},
				$css
			);

			return is_string($rewritten) ? $rewritten : $css;
		}

		private function resolve_css_image_url_candidate($inner, $source_url = '') {
			$raw = trim((string) $inner);
			if ('' === $raw) {
				return '';
			}

			$quote_tokens = array('&quot;', '&#039;', '&#39;', '&#x27;', '&#X27;', '"', "'");
			foreach ($quote_tokens as $token) {
				$length = strlen($token);
				if ($length > 0 && strlen($raw) >= ($length * 2) && 0 === strpos($raw, $token) && substr($raw, -$length) === $token) {
					$raw = substr($raw, $length, -$length);
					break;
				}
			}

			$candidate = html_entity_decode(str_replace('\/', '/', $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$candidate = trim($candidate);
			if ('' === $candidate || false !== strpos($candidate, '/uc-images/')) {
				return '';
			}

			$lower = strtolower($candidate);
			foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
				if (0 === strpos($lower, $prefix)) {
					return '';
				}
			}

			if (!preg_match('~\.(?:jpe?g|png|webp)(?:[?#].*)?$~iu', $candidate)) {
				return '';
			}

			if (preg_match('~^https?://~i', $candidate) || 0 === strpos($candidate, '/')) {
				return $candidate;
			}

			if (0 === strpos($candidate, '//')) {
				$scheme = (string) wp_parse_url((string) $source_url, PHP_URL_SCHEME);
				if ('' === $scheme) {
					$scheme = is_ssl() ? 'https' : 'http';
				}
				return $scheme . ':' . $candidate;
			}

			$base = '' !== (string) $source_url ? (string) $source_url : home_url('/');
			$parts = wp_parse_url($base);
			if (!is_array($parts) || empty($parts['host'])) {
				return '';
			}

			$scheme = !empty($parts['scheme']) ? (string) $parts['scheme'] : (is_ssl() ? 'https' : 'http');
			$host = (string) $parts['host'];
			$port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';
			$path = !empty($parts['path']) ? (string) $parts['path'] : '/';
			$dir = preg_replace('~/[^/]*$~', '/', $path);
			$dir = is_string($dir) && '' !== $dir ? $dir : '/';
			$combined = $this->normalize_css_path_segments($dir . $candidate);

			return $scheme . '://' . $host . $port . $combined;
		}

		private function normalize_css_path_segments($path) {
			$path = '/' . ltrim(str_replace('\\', '/', (string) $path), '/');
			$segments = explode('/', $path);
			$out = array();
			foreach ($segments as $segment) {
				if ('' === $segment || '.' === $segment) {
					continue;
				}
				if ('..' === $segment) {
					array_pop($out);
					continue;
				}
				$out[] = $segment;
			}

			return '/' . implode('/', $out);
		}

		private function sanitize_css_image_original_url($url) {
			$url = esc_url_raw((string) $url);
			if ('' === $url || preg_match("/[\x00-\x1F\x7F\s()\"']/", $url)) {
				return '';
			}
			return $url;
		}

		private function build_css_image_set_value($avif_url, $webp_url, $original_url) {
			$candidates = array();
			$avif_url = $this->escape_rewritten_public_url_for_css_url($avif_url);
			$webp_url = $this->escape_rewritten_public_url_for_css_url($webp_url);
			$original_url = $this->sanitize_css_image_original_url($original_url);

			if ('' !== $avif_url) {
				$candidates[] = 'url("' . $avif_url . '") type("image/avif")';
			}
			if ('' !== $webp_url && $webp_url !== $avif_url) {
				$candidates[] = 'url("' . $webp_url . '") type("image/webp")';
			}
			if ('' !== $original_url) {
				$extension = strtolower((string) pathinfo((string) wp_parse_url($original_url, PHP_URL_PATH), PATHINFO_EXTENSION));
				$mime = 'png' === $extension ? 'image/png' : ('webp' === $extension ? 'image/webp' : 'image/jpeg');
				$candidates[] = 'url("' . $original_url . '") type("' . $mime . '")';
			}

			if (count($candidates) < 2) {
				return '';
			}

			return 'image-set(' . implode(', ', $candidates) . ')';
		}

		private function rewrite_css_url_upload_image_urls_globally($html) {
			$html = (string) $html;
			if ('' === $html || false === stripos($html, 'url(')) {
				return $html;
			}

			$rewritten = preg_replace_callback(
				'/url\(([^)]+)\)/i',
				function ($matches) {
					// 2.56.184: entity-aware generic CSS url(...) rewrite for local upload image candidates.
					return $this->rewrite_single_css_url_function_match($matches[0], $matches[1]);
				},
				$html
			);

			return is_string($rewritten) ? $rewritten : $html;
		}

		private function rewrite_single_css_url_function_match($original_match, $inner) {
			$original_match = (string) $original_match;
			$raw = trim((string) $inner);
			if ('' === $raw) {
				return $original_match;
			}

			$quote = '';
			$quote_tokens = array('&quot;', '&#039;', '&#39;', '&#x27;', '&#X27;', '"', "'");
			foreach ($quote_tokens as $token) {
				$length = strlen($token);
				if ($length > 0 && strlen($raw) >= ($length * 2) && 0 === strpos($raw, $token) && substr($raw, -$length) === $token) {
					$quote = $token;
					$raw = substr($raw, $length, -$length);
					break;
				}
			}

			$had_escaped_slashes = (false !== strpos($raw, '\/'));
			$candidate = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$candidate = str_replace('\/', '/', $candidate);
			$candidate = trim($candidate);

			if ('' === $candidate || false !== strpos($candidate, '/uc-images/')) {
				return $original_match;
			}

			if (!preg_match('~(?:^https?://|^/).+\.(?:jpe?g|png|webp)(?:\?.*)?$~iu', $candidate)) {
				return $original_match;
			}

			$replacement = $this->get_best_url_from_public_url($candidate);
			if (!$replacement) {
				return $original_match;
			}

			$replacement = $this->escape_rewritten_public_url_for_css_url($replacement);
			if (!$replacement) {
				return $original_match;
			}

			if ($had_escaped_slashes) {
				$replacement = str_replace('/', '\/', $replacement);
			}

			return 'url(' . $quote . $replacement . $quote . ')';
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
				$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
				$descriptor = $this->sanitize_srcset_descriptor($descriptor);
				$parts[$index] = $replacement ? trim($replacement . ('' !== $descriptor ? ' ' . $descriptor : '')) : $part;
			}

			return implode(', ', $parts);
		}

		private function remember_optimized_image_url_rewrite($source_url, $optimized_url) {
			$source_url = (string) $source_url;
			$optimized_url = (string) $optimized_url;
			if ('' === $source_url || '' === $optimized_url) {
				return;
			}

			$source_url = html_entity_decode(str_replace('\/', '/', $source_url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$source_url = trim($source_url);
			$optimized_url = html_entity_decode(str_replace('\/', '/', $optimized_url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$optimized_url = $this->sanitize_rewritten_public_url_raw($optimized_url);
			if ('' === $source_url || '' === $optimized_url || false === strpos($optimized_url, '/uc-images/')) {
				return;
			}

			$optimized_is_avif = (bool) preg_match('/\.avif(?:$|\?)/i', $optimized_url);
			foreach ($this->build_upload_image_url_rewrite_variants($source_url) as $variant) {
				if ('' === $variant) {
					continue;
				}

				$existing = isset($this->optimized_image_url_rewrite_map[$variant]) ? (string) $this->optimized_image_url_rewrite_map[$variant] : '';
				if ('' !== $existing && $existing !== $optimized_url && !$optimized_is_avif) {
					// Do not downgrade an AVIF decision to WebP later in the same request.
					continue;
				}

				$this->optimized_image_url_rewrite_map[$variant] = $optimized_url;
				$escaped_variant = str_replace('/', '\/', $variant);
				if ($escaped_variant !== $variant) {
					$this->optimized_image_url_rewrite_map[$escaped_variant] = str_replace('/', '\/', $optimized_url);
				}
			}
		}

		private function build_upload_image_url_rewrite_variants($url) {
			$url = trim((string) $url);
			if ('' === $url) {
				return array();
			}

			$variants = array($url);
			$normalized = $this->normalize_public_url($url);
			if ('' !== $normalized) {
				$variants[] = $normalized;
			}

			$parts = wp_parse_url($normalized ?: $url);
			$path = is_array($parts) && !empty($parts['path']) ? '/' . ltrim(rawurldecode((string) $parts['path']), '/') : '';
			if ('' !== $path && preg_match('~/wp-content/uploads/.+\.(?:jpe?g|png|webp)(?:$|\?)~i', $path)) {
				$variants[] = $path;

				$hosts = array();
				if (is_array($parts) && !empty($parts['host'])) {
					$hosts[] = strtolower((string) $parts['host']);
				}
				foreach (array(home_url('/'), site_url('/'), content_url('/')) as $candidate_base) {
					$host = strtolower((string) wp_parse_url((string) $candidate_base, PHP_URL_HOST));
					if ('' !== $host) {
						$hosts[] = $host;
					}
				}
				foreach (array_unique(array_filter($hosts)) as $host) {
					$variants[] = 'https://' . $host . $path;
					$variants[] = 'http://' . $host . $path;
				}
			}

			$clean = array();
			foreach ($variants as $variant) {
				$variant = trim((string) $variant);
				if ('' === $variant || false !== strpos($variant, '/uc-images/')) {
					continue;
				}
				$clean[$variant] = $variant;
			}

			return array_values($clean);
		}

		private function apply_optimized_image_url_rewrite_map($html) {
			$html = (string) $html;
			if ('' === $html || empty($this->optimized_image_url_rewrite_map) || !is_array($this->optimized_image_url_rewrite_map)) {
				return $html;
			}

			$map = array();
			foreach ($this->optimized_image_url_rewrite_map as $from => $to) {
				$from = (string) $from;
				$slash_escaped = false !== strpos($from, '\/');
				$to = $this->sanitize_rewritten_public_url_raw($to);
				if ('' === $from || '' === $to || $from === $to) {
					continue;
				}
				$map[$from] = $slash_escaped ? str_replace('/', '\/', $to) : $to;
			}

			if (empty($map)) {
				return $html;
			}

			uksort($map, static function ($a, $b) {
				return strlen((string) $b) <=> strlen((string) $a);
			});

			// Replacement values are validated by sanitize_rewritten_public_url_raw()
			// before the str_replace map is built; the original HTML is otherwise
			// preserved to avoid breaking blocks, builders, shortcodes, or scripts.
			return str_replace(array_keys($map), array_values($map), $html);
		}

        private function rewrite_root_relative_upload_image_urls_globally($html) {
            $html = (string) $html;
            if ('' === $html) {
                return $html;
            }

            $uploads = wp_get_upload_dir();
            if (empty($uploads['baseurl'])) {
                return $html;
            }

            $baseurl = untrailingslashit($this->normalize_public_url($uploads['baseurl']));
            $base_path = '' !== $baseurl ? (string) wp_parse_url($baseurl, PHP_URL_PATH) : '';
            if ('' === $base_path) {
                $base_path = (string) wp_parse_url(content_url('uploads/'), PHP_URL_PATH);
            }

            $base_path = '/' . ltrim(str_replace('\\', '/', (string) $base_path), '/');
            $base_path = rtrim($base_path, '/');
            if ('' === $base_path || '/' === $base_path) {
                return $html;
            }

            // 2.56.185: final candidate-token sweep. Earlier passes already discover normal
            // HTML attributes, srcset, style/url(...) and common data-* image attributes. This
            // sweep reuses the same resolver for remaining local upload image URL tokens, including
            // builder/slider JSON strings with escaped slashes, without introducing a plugin-specific
            // parser. It never rewrites to a missing optimized URL; the original URL is preserved if
            // the resolver cannot find or generate the persistent variant in the current context.
            $root_relative_pattern = '~(?<![A-Za-z0-9_./:-])(' . preg_quote($base_path, '~') . '/[^\s"\'<>\\)]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\\)]*)?)~iu';
            $rewritten = preg_replace_callback(
                $root_relative_pattern,
                function ($matches) {
                    return $this->rewrite_single_upload_image_url_token(isset($matches[1]) ? (string) $matches[1] : '');
                },
                $html
            );
            $html = is_string($rewritten) ? $rewritten : $html;

            $absolute_pattern = '~(?<![A-Za-z0-9_./:-])(https?://[^/\s"\'<>\\)]+'. preg_quote($base_path, '~') . '/[^\s"\'<>\\)]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\\)]*)?)~iu';
            $rewritten = preg_replace_callback(
                $absolute_pattern,
                function ($matches) {
                    return $this->rewrite_single_upload_image_url_token(isset($matches[1]) ? (string) $matches[1] : '');
                },
                $html
            );
            $html = is_string($rewritten) ? $rewritten : $html;

            $escaped_base_path = str_replace('/', '\\/', $base_path);
            if ('' !== $escaped_base_path) {
                $escaped_root_pattern = '~(?<![A-Za-z0-9_.:-])(' . preg_quote($escaped_base_path, '~') . '\\/[^\s"\'<>\\)]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\\)]*)?)~iu';
                $rewritten = preg_replace_callback(
                    $escaped_root_pattern,
                    function ($matches) {
                        return $this->rewrite_single_upload_image_url_token(isset($matches[1]) ? (string) $matches[1] : '', true);
                    },
                    $html
                );
                $html = is_string($rewritten) ? $rewritten : $html;

                // 2.56.185: escaped absolute JSON/config tokens, e.g.
                // https:\/\/example.com\/wp-content\/uploads\/2025\/03\/image.png
                $escaped_base_path_regex = str_replace('/', '\\\\/', preg_quote($base_path, '~'));
                $escaped_absolute_pattern = '~(?<![A-Za-z0-9_.:-])(https?:\\/\\/[^\\\s"\'<>\\)]+'. $escaped_base_path_regex . '\\/[^\s"\'<>\\)]+\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>\\)]*)?)~iu';
                $rewritten = preg_replace_callback(
                    $escaped_absolute_pattern,
                    function ($matches) {
                        return $this->rewrite_single_upload_image_url_token(isset($matches[1]) ? (string) $matches[1] : '', true);
                    },
                    $html
                );
                $html = is_string($rewritten) ? $rewritten : $html;
            }

            return $html;
        }

        private function rewrite_single_upload_image_url_token($current, $slash_escaped = false) {
            $current = (string) $current;
            if ('' === $current) {
                return $current;
            }

            $already_optimized = $slash_escaped
                ? (false !== strpos($current, '\\/uc-images\\/'))
                : (false !== strpos($current, '/uc-images/'));
            if ($already_optimized) {
                return $current;
            }

            $candidate = $slash_escaped ? str_replace('\\/', '/', $current) : $current;
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = trim($candidate);
            if ('' === $candidate || !preg_match('~(?:^https?://|^/).+\.(?:jpe?g|png|webp)(?:\?.*)?$~iu', $candidate)) {
                return $current;
            }

            $replacement = $this->get_best_url_from_public_url($candidate);
            if (!$replacement) {
                return $current;
            }

            $replacement = $this->sanitize_rewritten_public_url_raw($replacement);
	            if (!$replacement) {
	                return $current;
	            }

	            return $slash_escaped ? str_replace('/', '\/', $replacement) : $replacement;
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
					$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
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

			$accept = null !== $this->media_rewrite_accept_context
				? (string) $this->media_rewrite_accept_context
				: ucwp_server_value('HTTP_ACCEPT');
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

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings', array());
			return !empty($settings['mediaOptimizationEnabled']);
		}

		private function get_media_output_mode() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				$mode = isset($settings['media_output_mode']) ? strtolower(trim((string) $settings['media_output_mode'])) : 'auto';
				return in_array($mode, array('auto', 'avif', 'webp'), true) ? $mode : 'auto';
			}

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings', array());
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

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings', array());
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

			$settings = get_option(defined('UCWP_SETTINGS_KEY') ? UCWP_SETTINGS_KEY : 'ultracache_settings', array());
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

		private function get_media_generation_context() {
			$context = strtolower((string) $this->media_generation_context);
			if (in_array($context, array('warm', 'cron', 'stale', 'manual'), true)) {
				return $context;
			}

			if ('' !== trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_WARM'))) {
				return 'warm';
			}

			if ('' !== trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_CRON_WARM'))) {
				return 'cron';
			}

			if ('' !== trim((string) ucwp_server_value('HTTP_X_ULTRACACHE_REVALIDATE'))) {
				return 'stale';
			}

			return 'frontend';
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

}
