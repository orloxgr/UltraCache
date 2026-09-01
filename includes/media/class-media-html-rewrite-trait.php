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
			if ((function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) || !$this->is_media_optimization_enabled()) {
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
			if ((function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) || !$this->is_media_optimization_enabled()) {
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

			$method = strtoupper(ultracache_server_value('REQUEST_METHOD'));
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
			return $this->rewrite_html_image_urls_with_context($html, array(
				'accept' => is_string($accept_header) ? $accept_header : '',
			));
		}

		/**
		 * Rewrite one final HTML document with the explicit cache-storage context.
		 *
		 * Background warm/cron/CLI workers do not inherit the public page's
		 * REQUEST_URI or Accept header. Keep both values scoped to this rewrite so
		 * missing image variants are queued against the correct affected page and
		 * the generated HTML uses the intended orig/WebP/AVIF bucket.
		 *
		 * @param string $html    Full frontend HTML.
		 * @param array  $context Cache-storage context.
		 * @return string
		 */
		public function rewrite_html_image_urls_with_context($html, array $context = array()) {
			$previous_accept = $this->media_rewrite_accept_context;
			$previous_generation_context = $this->media_generation_context;
			$previous_page_url = $this->media_rewrite_page_url_context;
			$previous_cache_storage_context = $this->media_rewrite_cache_storage_context;

			// Warm workers can be authenticated administrators while finalizing
			// anonymous public cache output. Only an explicit warm/storage caller may
			// override the normal logged-in frontend bypass; regular frontend/store
			// callbacks and runtime scans keep their existing authentication policy.
			$this->media_rewrite_cache_storage_context = !empty($context['public_cache_storage']);
			$this->media_rewrite_accept_context = isset($context['accept']) && is_string($context['accept'])
				? (string) $context['accept']
				: '';
			$this->media_generation_context = $this->normalize_media_rewrite_generation_context(
				(string) ($context['source'] ?? '')
			);
			$this->media_rewrite_page_url_context = $this->normalize_media_rewrite_page_url_context(
				(string) ($context['url'] ?? ($context['request_url'] ?? ''))
			);
			$this->reset_media_discovery_scope_for_page($this->media_rewrite_page_url_context);

			try {
				$rewritten = $this->rewrite_html_image_urls($html);
			} finally {
				$this->media_rewrite_accept_context = $previous_accept;
				$this->media_generation_context = $previous_generation_context;
				$this->media_rewrite_page_url_context = $previous_page_url;
				$this->media_rewrite_cache_storage_context = $previous_cache_storage_context;
			}

			return is_string($rewritten) ? $rewritten : $html;
		}

		private function reset_media_discovery_scope_for_page($page_url) {
			$page_url = esc_url_raw((string) $page_url);
			if ('' === $page_url || $page_url === (string) $this->media_rewrite_discovery_page_context) {
				return;
			}

			$this->media_rewrite_discovery_page_context = $page_url;
			$this->on_demand_queue_discovery_seen = array();
			$this->on_demand_queue_discovery_count = 0;
		}

		private function normalize_media_rewrite_generation_context($source) {
			$source = sanitize_key((string) $source);
			if (in_array($source, array('warm', 'warm_url', 'warm-after-flush', 'targeted-purge', 'affected-save'), true)) {
				return 'warm';
			}
			if (in_array($source, array('cron', 'scheduled-cleanup'), true)) {
				return 'cron';
			}
			if (in_array($source, array('stale', 'revalidate', 'refresh-ahead'), true)) {
				return 'stale';
			}
			if (in_array($source, array('manual', 'cli'), true)) {
				return 'manual';
			}

			return 'frontend';
		}

		private function normalize_media_rewrite_page_url_context($url) {
			$url = trim((string) $url);
			if ('' === $url) {
				return '';
			}
			if (0 === strpos($url, '/') && 0 !== strpos($url, '//')) {
				$url = home_url($url);
			}
			if (method_exists($this, 'normalize_on_demand_affected_page_url')) {
				$url = $this->normalize_on_demand_affected_page_url($url);
			} else {
				$url = remove_query_arg(
					array('ultracache_action', '_wpnonce', 'ultracache_odq_test', 'ultracache_cache_bust', 'ultracache_revalidate', 'ultracache_rt', 'ultracache_rv', 'ultracache_bucket'),
					$url
				);
				$url = esc_url_raw($url);
			}
			if ('' === $url) {
				return '';
			}
			if (function_exists('ultracache_is_strict_frontend_loopback_url') && !ultracache_is_strict_frontend_loopback_url($url)) {
				return '';
			}

			return $url;
		}

		/**
		 * Rewrite canonical local image URLs in full frontend HTML/cache-storage output.
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

			if (!$this->html_contains_local_image_candidate($html)) {
				return $html;
			}

			// Full-document output uses the same tag-aware policy as filtered fragments.
			// Do not apply a global URL map: identical local image URLs can legitimately
			// appear in image markup, download links, social metadata, and JSON with
			// different semantics. Only explicitly supported media attributes and CSS
			// background declarations inside real <style> blocks change.
			$rewritten = $this->rewrite_html_image_urls_with_tag_processor($html);
			$rewritten = $this->finalize_slider_revolution_image_lists_for_media_bucket($rewritten);
			return $this->rewrite_inline_style_block_image_urls($rewritten);
		}

		/**
		 * Rewrite image URLs returned by WordPress content/block filters.
		 *
		 * These callbacks return markup that WordPress renders immediately, so each
		 * UltraCache-generated replacement is inserted through tag/attribute-aware
		 * paths. Full-document and filtered-fragment output share this same semantic policy.
		 *
		 * @param string $html Existing filtered HTML fragment.
		 * @return string HTML fragment with context-escaped image URL replacements.
		 */
		public function rewrite_filtered_content_image_urls($html) {
			if ((function_exists('ultracache_should_bypass_logged_in_frontend_optimizations') && ultracache_should_bypass_logged_in_frontend_optimizations()) || !$this->is_media_optimization_enabled()) {
				return $html;
			}

			if (!is_string($html) || '' === $html) {
				return $html;
			}

			if (!$this->html_contains_local_image_candidate($html)) {
				return $html;
			}

			$rewritten = $this->rewrite_html_image_urls_with_tag_processor($html);
			return $this->rewrite_inline_style_block_image_urls($rewritten);
		}

		private function html_contains_local_image_candidate($html) {
			$html = (string) $html;
			if ('' === $html || !preg_match('~\.(?:jpe?g|png|webp|avif)(?:[?#][^\s<>"\']*)?(?:[\s<>"\'(),;]|$)~iu', $html)) {
				return false;
			}

			$markers = array();
			if (function_exists('ultracache_uploads_public_path')) {
				$markers[] = ultracache_uploads_public_path();
			}
			if (function_exists('ultracache_plugins_public_path')) {
				$markers[] = ultracache_plugins_public_path();
			}
			if (function_exists('ultracache_mu_plugins_public_path')) {
				$markers[] = ultracache_mu_plugins_public_path();
			}
			if (function_exists('ultracache_wordpress_includes_public_path')) {
				$markers[] = ultracache_wordpress_includes_public_path();
			}
			if (function_exists('ultracache_theme_public_root_mappings')) {
				foreach (ultracache_theme_public_root_mappings() as $mapping) {
					$markers[] = (string) ($mapping['public_path'] ?? '');
				}
			}

			$decoded_html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			foreach (array_unique(array_filter(array_map('strval', $markers))) as $marker) {
				$marker = rtrim(str_replace('\\', '/', $marker), '/');
				if ('' !== $marker && (false !== strpos($html, $marker . '/') || false !== strpos($decoded_html, $marker . '/'))) {
					return true;
				}
			}

			return false;
		}



		private function rewrite_html_image_urls_with_tag_processor($html) {
			$processor = new WP_HTML_Tag_Processor($html);
			$srcset_attributes = array('srcset', 'data-srcset', 'data-lazy-srcset', 'data-lazyload-srcset');
			$background_attributes = array('data-bg', 'data-background', 'data-bg-image', 'data-background-image');
			$lightbox_attributes = array('data-lg-src', 'data-mfp-src');
			$image_tags = array('IMG', 'AMP-IMG');
			$slider_image_attributes = array('data-dbsrc', 'data-lazyload', 'data-image', 'data-origin');
			$srcset_tags = array('IMG', 'SOURCE', 'AMP-IMG');
			$background_excluded_tags = array('META', 'LINK', 'SCRIPT', 'STYLE');

			while ($processor->next_tag()) {
				$tag = strtoupper((string) $processor->get_tag());
				$single_url_attributes = array();

				if ('LINK' === $tag && $this->is_ultracache_lcp_image_preload_tag($processor)) {
					$this->rewrite_ultracache_lcp_image_preload_tag($processor);
				}

				if (in_array($tag, $image_tags, true)) {
					$single_url_attributes = array('src', 'data-src', 'data-lazy-src', 'data-lazyload', 'data-image', 'data-origin');
				} elseif ('INPUT' === $tag && 'image' === strtolower(trim((string) $processor->get_attribute('type')))) {
					$single_url_attributes = array('src');
				} elseif ('VIDEO' === $tag) {
					$single_url_attributes = array('poster');
				} elseif (preg_match('/^(?:SR7|RS)-/', $tag)) {
					$single_url_attributes = $slider_image_attributes;
				}

				foreach ($single_url_attributes as $attribute) {
					$this->rewrite_tag_processor_single_url_attribute($processor, $attribute);
				}

				if (in_array($tag, $srcset_tags, true)) {
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
				}

				if (!in_array($tag, $background_excluded_tags, true)) {
					foreach ($lightbox_attributes as $attribute) {
						$this->rewrite_tag_processor_single_url_attribute($processor, $attribute);
					}
				}

				if (!in_array($tag, $background_excluded_tags, true)) {
					foreach ($background_attributes as $attribute) {
						$this->rewrite_tag_processor_single_url_attribute($processor, $attribute);
					}

					$style = $processor->get_attribute('style');
					if (is_string($style) && '' !== $style) {
						$rewritten_style = $this->rewrite_inline_style_urls($style);
						if ($rewritten_style !== $style) {
							$processor->set_attribute('style', $rewritten_style);
						}
					}
				}
			}

			return $processor->get_updated_html();
		}


		/**
		 * Rewrite local upload background URLs inside rendered inline CSS blocks.
		 *
		 * Page builders such as WPBakery emit row and category backgrounds in
		 * document-level <style> elements instead of style attributes or linked
		 * stylesheets. Those declarations must use the same generated media
		 * variants as the rest of the final cached document.
		 *
		 * @param string $html Full frontend HTML.
		 * @return string
		 */
		private function rewrite_inline_style_block_image_urls($html) {
			if (!is_string($html) || '' === $html || false === stripos($html, '<style') || false === stripos($html, 'url(')) {
				return $html;
			}

			$source_url = '' !== (string) $this->media_rewrite_page_url_context
				? (string) $this->media_rewrite_page_url_context
				: home_url('/');
			$updated = preg_replace_callback(
				'/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
				function ($matches) use ($source_url) {
					$attrs = isset($matches[1]) ? (string) $matches[1] : '';
					$css = isset($matches[2]) ? (string) $matches[2] : '';
					if ('' === $css || false === stripos($css, 'url(')) {
						return (string) ($matches[0] ?? '');
					}
					if (preg_match('/\btype\s*=\s*(["\'])(.*?)\1/i', $attrs, $type_match)) {
						$type = strtolower(trim((string) ($type_match[2] ?? '')));
						if ('' !== $type && 'text/css' !== $type) {
							return (string) ($matches[0] ?? '');
						}
					}

					$stats = array();
					$rewritten_css = $this->rewrite_css_image_urls_for_stylesheet($css, $source_url, $stats);
					if (!is_string($rewritten_css) || $rewritten_css === $css || empty($stats['cssImageUrlsRewritten'])) {
						return (string) ($matches[0] ?? '');
					}

					return '<style' . $attrs . '>' . $rewritten_css . '</style>';
				},
				$html
			);

			return is_string($updated) && '' !== $updated ? $updated : $html;
		}


		private function is_ultracache_lcp_image_preload_tag($processor) {
			if ('1' !== (string) $processor->get_attribute('data-ultracache-lcp-preload')) {
				return false;
			}

			$rel = strtolower(trim((string) $processor->get_attribute('rel')));
			$as = strtolower(trim((string) $processor->get_attribute('as')));
			if ('image' !== $as || '' === $rel) {
				return false;
			}

			return in_array('preload', preg_split('/\\s+/', $rel), true);
		}

		private function rewrite_ultracache_lcp_image_preload_tag($processor) {
			$current = $processor->get_attribute('href');
			if (!is_string($current) || '' === $current) {
				return;
			}

			$replacement = $this->sanitize_rewritten_public_url_raw(
				$this->get_best_url_from_public_url($current)
			);
			if ('' === $replacement || $replacement === $current) {
				return;
			}

			$processor->set_attribute('href', $replacement);
			$mime_type = $this->get_rewritten_image_mime_type_from_url($replacement);
			if ('' !== $mime_type) {
				$processor->set_attribute('type', $mime_type);
			}
		}

		private function get_rewritten_image_mime_type_from_url($url) {
			$path = strtolower((string) wp_parse_url((string) $url, PHP_URL_PATH));
			$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

			switch ($extension) {
				case 'avif':
					return 'image/avif';
				case 'webp':
					return 'image/webp';
				case 'jpg':
				case 'jpeg':
					return 'image/jpeg';
				case 'png':
					return 'image/png';
				case 'gif':
					return 'image/gif';
				case 'svg':
					return 'image/svg+xml';
			}

			return '';
		}

		private function rewrite_tag_processor_single_url_attribute($processor, $attribute) {
			$current = $processor->get_attribute($attribute);
			if (!is_string($current) || '' === $current) {
				return;
			}

			$replacement = $this->get_best_url_from_public_url($current);
			$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
			if ($replacement && $replacement !== $current) {
				$processor->set_attribute($attribute, $replacement);
			}
		}


		private function sanitize_rewritten_public_url_raw($url) {
			$url = trim((string) $url);
			if ('' === $url) {
				return '';
			}

			$url = html_entity_decode(str_replace('\/', '/', $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$url = trim($url);
			if ('' === $url || false === strpos($url, '/ultracache/images/')) {
				return '';
			}

			$url = esc_url_raw($url);
			if ('' === $url || false === strpos($url, '/ultracache/images/')) {
				return '';
			}

			if (preg_match("/[\x00-\x1F\x7F\s<>\"']/", $url)) {
				return '';
			}

			return $url;
		}

		private function escape_rewritten_public_url_for_css_url($url) {
			$url = $this->sanitize_rewritten_public_url_raw($url);
			if ('' === $url || preg_match("/[\x00-\x1F\x7F\s()\"']/", $url)) {
				return '';
			}

			return $url;
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
							if (!$avif && $this->media_output_mode_allows('webp')) {
								$webp = $this->get_webp_url_from_public_url($candidate);
							}
							$original = $this->sanitize_css_image_original_url($candidate);
							if (!$avif && !$webp) {
								$stats['cssImageUrlsSkipped']++;
								return $original_match;
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
			if ('' === $candidate || false !== strpos($candidate, '/ultracache/images/')) {
				return '';
			}

			$lower = strtolower($candidate);
			foreach (array('data:', 'blob:', 'about:', 'javascript:', '#') as $prefix) {
				if (0 === strpos($lower, $prefix)) {
					return '';
				}
			}

			if (!preg_match('~\.(?:jpe?g|png|webp|avif)(?:[?#].*)?$~iu', $candidate)) {
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
				$mime = 'png' === $extension ? 'image/png' : ('webp' === $extension ? 'image/webp' : ('avif' === $extension ? 'image/avif' : 'image/jpeg'));
				$candidates[] = 'url("' . $original_url . '") type("' . $mime . '")';
			}

			if (count($candidates) < 2) {
				return '';
			}

			return 'image-set(' . implode(', ', $candidates) . ')';
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

			if ('' === $candidate || false !== strpos($candidate, '/ultracache/images/')) {
				return $original_match;
			}

			if (!preg_match('~(?:^https?://|^/).+\.(?:jpe?g|png|webp|avif)(?:\?.*)?$~iu', $candidate)) {
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

			return ultracache_rewrite_srcset_urls(
				$srcset,
				function ($url) {
					$replacement = $this->get_best_url_from_public_url($url);
					$replacement = $this->sanitize_rewritten_public_url_raw($replacement);
					return '' !== $replacement ? $replacement : false;
				}
			);
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
				: ultracache_server_value('HTTP_ACCEPT');
			if ('' === $accept) {
				return false;
			}

			$memo_key = $format . '|' . md5(substr((string) $accept, 0, 8192));
			if (array_key_exists($memo_key, $this->media_accept_support_memo)) {
				return (bool) $this->media_accept_support_memo[$memo_key];
			}

			$allowed = function_exists('ultracache_accept_header_allows_media_type')
				&& ultracache_accept_header_allows_media_type($accept, 'image/' . $format);
			if (count($this->media_accept_support_memo) >= 12) {
				$this->media_accept_support_memo = array();
			}
			$this->media_accept_support_memo[$memo_key] = $allowed;

			return $allowed;
		}

		private function is_media_optimization_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				return !empty($settings['media_optimization_enabled']);
			}

			$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
			return !empty($settings['mediaOptimizationEnabled']);
		}

		private function get_media_output_mode() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				$mode = isset($settings['media_output_mode']) ? strtolower(trim((string) $settings['media_output_mode'])) : 'webp';
			} else {
				$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
				$mode = isset($settings['mediaOutputMode']) ? strtolower(trim((string) $settings['mediaOutputMode'])) : 'webp';
			}

			return in_array($mode, array('avif', 'webp'), true) ? $mode : 'webp';
		}

		private function get_media_fallback_format() {
			$mode = $this->get_media_output_mode();
			if ('avif' !== $mode) {
				return 'original';
			}

			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				$fallback = isset($settings['media_fallback_format']) ? strtolower(trim((string) $settings['media_fallback_format'])) : 'webp';
			} else {
				$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
				$fallback = isset($settings['mediaFallbackFormat']) ? strtolower(trim((string) $settings['mediaFallbackFormat'])) : 'webp';
			}

			return ('webp' === $fallback) ? 'webp' : 'original';
		}

		private function is_generate_on_upload_enabled() {
			if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'get_settings')) {
				$settings = Ultra_Cache_WP::get_settings();
				if (array_key_exists('media_generate_on_upload', $settings)) {
					return !empty($settings['media_generate_on_upload']);
				}
			}

			$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
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

			$settings = get_option(defined('ULTRACACHE_SETTINGS_KEY') ? ULTRACACHE_SETTINGS_KEY : 'ultracache_settings', array());
			if (array_key_exists('mediaGenerateOnDemandEnabled', $settings)) {
				return !empty($settings['mediaGenerateOnDemandEnabled']);
			}
			return true;
		}

		private function media_output_mode_allows($format) {
			$format = strtolower((string) $format);
			$mode = $this->get_media_output_mode();

			if ('avif' === $format) {
				return 'avif' === $mode && $this->supports_avif();
			}

			if ('webp' === $format) {
				if ('webp' === $mode) {
					return true;
				}

				return 'avif' === $mode && 'webp' === $this->get_media_fallback_format();
			}

			return false;
		}

		private function get_media_generation_context() {
			$context = strtolower((string) $this->media_generation_context);
			if (in_array($context, array('warm', 'cron', 'stale', 'manual'), true)) {
				return $context;
			}

			if ('' !== trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_WARM'))) {
				return 'warm';
			}

			if ('' !== trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_CRON_WARM'))) {
				return 'cron';
			}

			if ('' !== trim((string) ultracache_server_value('HTTP_X_ULTRACACHE_REVALIDATE'))) {
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

			$method = strtoupper(ultracache_server_value('REQUEST_METHOD'));
			return in_array($method, array('GET', 'HEAD'), true);
		}

}
