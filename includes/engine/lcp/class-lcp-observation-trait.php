<?php
/**
 * Browser-observed LCP element/resource learning and page-specific preload helpers.
 *
 * @package UltraCache
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Ultra_Cache_Engine_LCP_Observation_Trait
{

    private function get_lcp_observation_selector_hash($selector)
    {
        $selector = $this->normalize_manual_lcp_hero_selector($selector);
        return '' === $selector ? '' : hash('sha256', $selector);
    }

    private function get_automatic_lcp_observation_selector()
    {
        return '__ultracache_automatic_lcp__';
    }

    private function get_automatic_lcp_observation_selector_hash()
    {
        return hash('sha256', $this->get_automatic_lcp_observation_selector());
    }

    private function lcp_observation_url_has_query_string($url)
    {
        $parts = wp_parse_url((string) $url);
        return is_array($parts) && array_key_exists('query', $parts) && '' !== trim((string) $parts['query']);
    }

    private function is_lcp_frontend_discovery_active(?array $settings = null)
    {
        $settings = null === $settings ? $this->get_settings() : $settings;
        if (empty($settings['lcp_image_priority']) || empty($settings['lcp_frontend_discovery'])) {
            return false;
        }

        $duration = sanitize_key((string) ($settings['lcp_frontend_discovery_duration'] ?? 'indefinitely'));
        if ('indefinitely' === $duration) {
            return true;
        }

        $expires_at = absint($settings['lcp_frontend_discovery_expires_at'] ?? 0);
        return $expires_at > time();
    }

    private function is_lcp_frontend_discovery_audience_allowed(array $settings)
    {
        $is_admin_user = is_user_logged_in() && current_user_can('manage_options');
        if (!empty($settings['lcp_frontend_discovery_admins_only'])) {
            return $is_admin_user;
        }

        // Anonymous public visitors and full administrators represent the
        // cacheable public page. Other logged-in roles may see private output.
        return !is_user_logged_in() || $is_admin_user;
    }

    private function create_lcp_observation_token($page_url, $selector_hash)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        $selector_hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $selector_hash));
        if ('' === $page_url || 64 !== strlen($selector_hash)) {
            return '';
        }

        return hash_hmac('sha256', $page_url . '|' . $selector_hash, wp_salt('nonce'));
    }

    private function validate_lcp_observation_token($token, $page_url, $selector_hash)
    {
        $token = strtolower(trim((string) $token));
        if (64 !== strlen($token)) {
            return false;
        }

        $expected = $this->create_lcp_observation_token($page_url, $selector_hash);
        return '' !== $expected && hash_equals($expected, $token);
    }

    private function normalize_lcp_learning_candidate(array $candidate)
    {
        $resource_type = sanitize_key((string) ($candidate['resource_type'] ?? 'unknown'));
        if (!in_array($resource_type, array('text', 'image', 'background', 'poster', 'unknown'), true)) {
            $resource_type = 'unknown';
        }
        $resource_url = in_array($resource_type, array('image', 'background', 'poster'), true)
            ? $this->normalize_public_resource_url((string) ($candidate['resource_url'] ?? ''))
            : '';
        $element_tag = substr(sanitize_key((string) ($candidate['element_tag'] ?? '')), 0, 80);
        $selector = substr(sanitize_text_field((string) ($candidate['selector'] ?? '')), 0, 512);
        if ('' === $selector) {
            $selector = '' !== $element_tag ? $element_tag : 'unknown';
        }

        $fingerprint = hash('sha256', wp_json_encode(array(
            'resource_type' => $resource_type,
            'resource_url'  => $resource_url,
            'element_tag'   => $element_tag,
            'selector'      => $selector,
        )));

        return array(
            'fingerprint'   => $fingerprint,
            'resource_type' => $resource_type,
            'resource_url'  => $resource_url,
            'element_tag'   => $element_tag,
            'selector'      => $selector,
            'observed_at'   => max(1, absint($candidate['observed_at'] ?? time())),
        );
    }

    private function get_lcp_candidate_from_row(array $row)
    {
        if (empty($row)) {
            return array();
        }
        return $this->normalize_lcp_learning_candidate(array(
            'resource_type' => (string) ($row['resource_type'] ?? 'unknown'),
            'resource_url'  => (string) ($row['resource_url'] ?? ''),
            'element_tag'   => (string) ($row['element_tag'] ?? ''),
            'selector'      => (string) ($row['selector'] ?? ''),
            'observed_at'   => absint($row['last_seen'] ?? time()),
        ));
    }

    private function decode_lcp_candidate_window($value)
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string) $value, true);
        }
        if (!is_array($decoded)) {
            return array();
        }

        $window = array();
        foreach (array_slice($decoded, -3) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $normalized = $this->normalize_lcp_learning_candidate($candidate);
            if (64 === strlen((string) ($normalized['fingerprint'] ?? ''))) {
                $window[] = $normalized;
            }
        }
        return $window;
    }

    private function evaluate_lcp_candidate_window(array $existing, array $candidate)
    {
        $candidate = $this->normalize_lcp_learning_candidate($candidate);
        $window = $this->decode_lcp_candidate_window($existing['candidate_window'] ?? '');
        $had_learning_observation = !empty($window) || absint($existing['confirmation_count'] ?? 0) > 0;
        $window[] = $candidate;
        $window = array_slice($window, -3);

        $counts = array();
        foreach ($window as $item) {
            $fingerprint = (string) ($item['fingerprint'] ?? '');
            if (64 === strlen($fingerprint)) {
                $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
            }
        }

        $winner_fingerprint = '';
        foreach ($counts as $fingerprint => $count) {
            if ($count >= 2) {
                $winner_fingerprint = $fingerprint;
                break;
            }
        }

        $active = $this->get_lcp_candidate_from_row($existing);
        if (!$had_learning_observation || empty($active)) {
            $active = $candidate;
        }
        if ('' !== $winner_fingerprint) {
            foreach (array_reverse($window) as $item) {
                if (hash_equals($winner_fingerprint, (string) ($item['fingerprint'] ?? ''))) {
                    $active = $item;
                    break;
                }
            }
        }

        $active_fingerprint = (string) ($active['fingerprint'] ?? '');
        $confirmation_count = 0;
        foreach ($window as $item) {
            if (64 === strlen($active_fingerprint) && hash_equals($active_fingerprint, (string) ($item['fingerprint'] ?? ''))) {
                $confirmation_count++;
            }
        }

        return array(
            'active'             => $active,
            'window'             => $window,
            'learning_state'     => '' !== $winner_fingerprint ? 'locked' : 'learning',
            'confirmation_count' => max(1, $confirmation_count),
            'locked_at'          => '' !== $winner_fingerprint ? time() : 0,
        );
    }

    private function lcp_observation_candidate_matches_record(array $record, $resource_type, $resource_url, $tag, $element_selector)
    {
        return (string) ($record['resource_type'] ?? '') === (string) $resource_type
            && $this->normalize_public_resource_url((string) ($record['resource_url'] ?? '')) === $this->normalize_public_resource_url((string) $resource_url)
            && (string) ($record['element_tag'] ?? '') === (string) $tag
            && (string) ($record['selector'] ?? '') === (string) $element_selector;
    }

    private function normalize_lcp_observation_page_url($url)
    {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            return '';
        }

        $parts = wp_parse_url($url);
        $home_parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home_parts) || empty($parts['host']) || empty($home_parts['host'])) {
            return '';
        }

        if (strtolower((string) $parts['host']) !== strtolower((string) $home_parts['host'])) {
            return '';
        }

        $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : (!empty($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'https');
        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }

        if (!empty($parts['query'])) {
            return '';
        }

        $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
        $port = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        return esc_url_raw($scheme . '://' . strtolower((string) $parts['host']) . $port . $path);
    }

    /**
     * Confirm that an LCP observation URL maps to a page-cacheable frontend URL.
     *
     * @param string $url Candidate page URL.
     * @return bool
     */
    public function is_lcp_observation_page_cacheable_url($url)
    {
        static $decisions = array();

        $url = $this->normalize_lcp_observation_page_url($url);
        if ('' === $url) {
            return false;
        }
        if (array_key_exists($url, $decisions)) {
            return (bool) $decisions[$url];
        }

        $parts = wp_parse_url($url);
        $path = is_array($parts) && isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
        $path = strtolower($path);
        if (
            '/wp-admin' === $path
            || 0 === strpos($path, '/wp-admin/')
            || '/wp-login.php' === $path
            || false !== strpos($path, '/search/')
            || preg_match('#/feed(?:/|$)#', $path)
        ) {
            $decisions[$url] = false;
            return false;
        }

        $query_vars = array();
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string) $parts['query'], $query_vars);
        }
        $non_cacheable_query_keys = array(
            's',
            'preview',
            'preview_id',
            'preview_nonce',
            'customize_changeset_uuid',
            'elementor-preview',
            'wc-ajax',
            'add-to-cart',
            'rest_route',
        );
        foreach ($non_cacheable_query_keys as $query_key) {
            if (array_key_exists($query_key, $query_vars)) {
                $decisions[$url] = false;
                return false;
            }
        }

        if (method_exists($this, 'inspect_url')) {
            $inspection = $this->inspect_url($url);
            $decisions[$url] = is_array($inspection) && !empty($inspection['cacheable']);
            return (bool) $decisions[$url];
        }

        $decisions[$url] = method_exists($this, 'is_cacheable_local_url') && $this->is_cacheable_local_url($url);
        return (bool) $decisions[$url];
    }

    /**
     * Return the validated hosts allowed for browser-observed LCP resources.
     *
     * WordPress/CDN integrations commonly rewrite the public content, plugin,
     * theme, or uploads base URL. Those filtered public bases are accepted in
     * addition to UltraCache's trusted site hosts.
     *
     * @param string $page_url Observed page URL.
     * @return array<int,string>
     */
    private function get_lcp_observation_allowed_resource_hosts($page_url)
    {
        $urls = array(
            home_url('/'),
            site_url('/'),
            content_url('/'),
            plugins_url('/'),
        );

        if (function_exists('get_template_directory_uri')) {
            $urls[] = get_template_directory_uri();
        }
        if (function_exists('get_stylesheet_directory_uri')) {
            $urls[] = get_stylesheet_directory_uri();
        }
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir(null, false);
            if (is_array($uploads) && !empty($uploads['baseurl'])) {
                $urls[] = (string) $uploads['baseurl'];
            }
        }

        $hosts = function_exists('ultracache_get_trusted_hosts')
            ? (array) ultracache_get_trusted_hosts()
            : array();
        foreach ($urls as $url) {
            $host = wp_parse_url((string) $url, PHP_URL_HOST);
            $host = function_exists('ultracache_normalize_host')
                ? ultracache_normalize_host($host)
                : strtolower(rtrim(trim((string) $host), '.'));
            if ('' !== $host) {
                $hosts[] = $host;
            }
        }

        $hosts = (array) apply_filters('ultracache_lcp_observation_allowed_resource_hosts', $hosts, $page_url);
        $hosts = array_values(array_unique(array_filter(array_map(static function ($host) {
            return function_exists('ultracache_normalize_host')
                ? ultracache_normalize_host($host)
                : strtolower(rtrim(trim((string) $host), '.'));
        }, $hosts))));

        return $hosts;
    }

    private function get_lcp_observation_records_for_page($page_url, array $selectors)
    {
        $page_url = $this->normalize_lcp_observation_page_url($page_url);
        if ('' === $page_url || !$this->is_lcp_observation_page_cacheable_url($page_url)) {
            return array();
        }

        if (empty($selectors)) {
            $rows = $this->get_automatic_lcp_observation_records_for_page_from_db($page_url);
            $records = array();
            $viewport_winners = array();
            foreach ($rows as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $hash = (string) ($record['selector_hash'] ?? '');
                $viewport = (string) ($record['viewport'] ?? '');
                if (
                    64 !== strlen($hash)
                    || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)
                    || isset($viewport_winners[$viewport])
                ) {
                    continue;
                }

                $record['tag'] = (string) ($record['element_tag'] ?? '');
                $record['hits'] = absint($record['observation_count'] ?? 0);
                $records[$hash][$viewport] = $record;
                $viewport_winners[$viewport] = true;
            }

            return $records;
        }

        $valid_hashes = array();
        foreach ($selectors as $selector) {
            $hash = $this->get_lcp_observation_selector_hash($selector);
            if ('' !== $hash) {
                $valid_hashes[$hash] = true;
            }
        }
        if (empty($valid_hashes)) {
            return array();
        }

        $rows = $this->get_lcp_observation_records_for_page_from_db($page_url, array_keys($valid_hashes));
        $records = array();
        $viewport_winners = array();
        foreach ($rows as $record) {
            if (!is_array($record)) {
                continue;
            }

            $hash = (string) ($record['selector_hash'] ?? '');
            $viewport = (string) ($record['viewport'] ?? '');
            if (
                !isset($valid_hashes[$hash])
                || !in_array($viewport, array('mobile', 'tablet', 'desktop'), true)
                || isset($viewport_winners[$viewport])
            ) {
                continue;
            }

            $record['tag'] = (string) ($record['element_tag'] ?? '');
            $record['hits'] = absint($record['observation_count'] ?? 0);
            $records[$hash][$viewport] = $record;
            $viewport_winners[$viewport] = true;
        }

        return $records;
    }

    private function get_lcp_observation_records_for_current_request()
    {
        static $records_by_key = array();

        $settings = $this->get_settings();
        $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])
            ? $settings['manual_lcp_hero_selector_list']
            : array();
        $page_url = $this->normalize_lcp_observation_page_url($this->get_current_request_url());

        $cache_key = md5($page_url . '|' . wp_json_encode(array_values($selectors)));
        if (isset($records_by_key[$cache_key])) {
            return $records_by_key[$cache_key];
        }

        $records_by_key[$cache_key] = $this->get_lcp_observation_records_for_page($page_url, $selectors);
        return $records_by_key[$cache_key];
    }

    private function has_confirmed_lcp_observation_for_current_request()
    {
        return !empty($this->get_lcp_observation_records_for_current_request());
    }

    private function observed_lcp_attribute_contains_url($value, array $resource_urls, $page_url)
    {
        $value = trim((string) $value);
        if ('' === $value || empty($resource_urls)) {
            return false;
        }

        foreach (preg_split('/\s*,\s*/', $value) as $candidate) {
            $parts = preg_split('/\s+/', trim((string) $candidate));
            $url = isset($parts[0]) ? $this->absolutize_public_resource_url($parts[0], $page_url) : '';
            $url = $this->normalize_public_resource_url($url);
            if ('' !== $url && isset($resource_urls[$url])) {
                return true;
            }
        }

        return false;
    }

    private function apply_observed_lcp_priority_markup($html)
    {
        if (!is_string($html) || '' === $html || !$this->html_tag_processor_available()) {
            return $html;
        }

        $records = $this->get_lcp_observation_records_for_current_request();
        if (empty($records)) {
            return $html;
        }

        $image_urls = array();
        foreach ($records as $selector_records) {
            foreach ($selector_records as $record) {
                if ('image' !== (string) ($record['resource_type'] ?? '')) {
                    continue;
                }
                $resource_url = $this->normalize_public_resource_url((string) ($record['resource_url'] ?? ''));
                if ('' !== $resource_url) {
                    $image_urls[$resource_url] = true;
                }
            }
        }
        if (empty($image_urls)) {
            return $html;
        }

        $page_url = $this->normalize_lcp_observation_page_url($this->get_current_request_url());

        try {
            $processor = new WP_HTML_Tag_Processor($html);
            $changed = false;
            while ($processor->next_tag('IMG')) {
                $src = $this->absolutize_public_resource_url((string) $processor->get_attribute('src'), $page_url);
                $src = $this->normalize_public_resource_url($src);
                $srcset = (string) $processor->get_attribute('srcset');
                if (!isset($image_urls[$src]) && !$this->observed_lcp_attribute_contains_url($srcset, $image_urls, $page_url)) {
                    continue;
                }

                $processor->set_attribute('fetchpriority', 'high');
                $processor->set_attribute('loading', 'eager');
                $processor->set_attribute('data-ultracache-lcp', '1');
                $changed = true;
            }

            return $changed ? $processor->get_updated_html() : $html;
        } catch (Throwable $error) {
            return $html;
        }
    }

    private function get_lcp_observation_viewport_media($viewport)
    {
        if ('mobile' === $viewport) {
            return '(max-width: 767px)';
        }
        if ('tablet' === $viewport) {
            return '(min-width: 768px) and (max-width: 1024px)';
        }
        if ('desktop' === $viewport) {
            return '(min-width: 1025px)';
        }

        return '';
    }

    private function html_has_observed_lcp_preload($html, $url, $media)
    {
        $url = $this->normalize_public_resource_url($url);
        if ('' === $url || false === stripos((string) $html, '<link')) {
            return false;
        }

        if (!preg_match_all('/<link\b[^>]*>/i', (string) $html, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            $rel = strtolower($this->extract_attribute_from_html_tag($tag, 'rel'));
            $as = strtolower($this->extract_attribute_from_html_tag($tag, 'as'));
            $href = $this->normalize_public_resource_url($this->extract_attribute_from_html_tag($tag, 'href'));
            $existing_media = trim($this->extract_attribute_from_html_tag($tag, 'media'));
            if (false !== strpos($rel, 'preload') && 'image' === $as && $href === $url && ('' === $existing_media || $existing_media === (string) $media)) {
                return true;
            }
        }

        return false;
    }

    private function inject_observed_lcp_priority_preloads($html)
    {
        if (!is_string($html) || '' === $html || false === stripos($html, '</head>')) {
            return $html;
        }

        $settings = $this->get_settings();
        $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list']) ? $settings['manual_lcp_hero_selector_list'] : array();

        $page_url = $this->normalize_lcp_observation_page_url($this->get_current_request_url());
        $records = $this->get_lcp_observation_records_for_page($page_url, $selectors);
        if (empty($records)) {
            return $html;
        }

        $resource_records = array();
        foreach ($records as $selector_records) {
            foreach ($selector_records as $viewport => $record) {
                $resource_type = (string) ($record['resource_type'] ?? '');
                $resource_url = $this->normalize_public_resource_url((string) ($record['resource_url'] ?? ''));
                if (!in_array($resource_type, array('image', 'background', 'poster'), true) || '' === $resource_url || !$this->is_lcp_candidate_image_url($resource_url)) {
                    continue;
                }
                $resource_records[$resource_url][$viewport] = true;
            }
        }
        if (empty($resource_records)) {
            return $html;
        }

        $tags = array();
        foreach ($resource_records as $resource_url => $viewports) {
            $viewports = array_keys($viewports);
            $media_values = array();
            if (count($viewports) >= 3) {
                $media_values[] = '';
            } else {
                foreach ($viewports as $viewport) {
                    $media_values[] = $this->get_lcp_observation_viewport_media($viewport);
                }
            }

            foreach (array_unique($media_values) as $media) {
                if ($this->html_has_observed_lcp_preload($html, $resource_url, $media)) {
                    continue;
                }
                $href = esc_url($resource_url);
                if ('' === $href) {
                    continue;
                }
                $tag = '<link rel="preload" as="image" href="' . $href . '"';
                $mime = $this->get_lcp_preload_image_type($resource_url);
                if ('' !== $mime) {
                    $tag .= ' type="' . esc_attr($mime) . '"';
                }
                $tag .= ' fetchpriority="high" data-ultracache-lcp-preload="1" data-ultracache-lcp-preload-reason="browser-observed"';
                if ('' !== $media) {
                    $tag .= ' media="' . esc_attr($media) . '"';
                }
                if (!$this->is_same_origin_public_resource_url($resource_url)) {
                    $tag .= ' crossorigin="anonymous"';
                }
                $tag .= '>';
                $tags[] = $tag;
            }
        }

        return empty($tags) ? $html : $this->insert_html_before_closing_head($html, implode("\n", $tags));
    }

    public function enqueue_lcp_observer_runtime_helper()
    {
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();
        if (!$this->is_lcp_frontend_discovery_active($settings) || !$this->is_lcp_frontend_discovery_audience_allowed($settings)) {
            return;
        }

        $request_url = $this->get_current_request_url();
        if ($this->lcp_observation_url_has_query_string($request_url)) {
            return;
        }
        $page_url = $this->normalize_lcp_observation_page_url($request_url);
        if ('' === $page_url || !$this->is_lcp_observation_page_cacheable_url($page_url)) {
            return;
        }

        $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])
            ? $settings['manual_lcp_hero_selector_list']
            : array();
        $known = $this->get_lcp_observation_records_for_page($page_url, $selectors);
        $selector_data = array();
        $all_locked = true;

        $manual_viewport_locked = array('mobile' => false, 'tablet' => false, 'desktop' => false);
        if (!empty($selectors)) {
            foreach ($known as $viewport_records) {
                if (!is_array($viewport_records)) {
                    continue;
                }
                foreach ($viewport_records as $viewport => $record) {
                    if (isset($manual_viewport_locked[$viewport]) && is_array($record) && 'locked' === sanitize_key((string) ($record['learning_state'] ?? ''))) {
                        $manual_viewport_locked[$viewport] = true;
                    }
                }
            }
        }

        foreach ($selectors as $selector) {
            $selector = $this->normalize_manual_lcp_hero_selector($selector);
            $hash = $this->get_lcp_observation_selector_hash($selector);
            if ('' === $selector || '' === $hash) {
                continue;
            }
            $locked = array();
            foreach (array('mobile', 'tablet', 'desktop') as $viewport) {
                // One LCP winner is authoritative per page and viewport. Once
                // any configured selector is locked, no other selector for the
                // same viewport should keep the observer running.
                $locked[$viewport] = !empty($manual_viewport_locked[$viewport]);
                if (!$locked[$viewport]) {
                    $all_locked = false;
                }
            }
            $selector_data[] = array(
                'selector' => $selector,
                'hash'     => $hash,
                'token'    => $this->create_lcp_observation_token($page_url, $hash),
                'locked'   => $locked,
            );
        }

        $automatic_data = array();
        if (empty($selector_data)) {
            $automatic_hash = $this->get_automatic_lcp_observation_selector_hash();
            $locked = array();
            $all_locked = true;
            foreach (array('mobile', 'tablet', 'desktop') as $viewport) {
                $record = isset($known[$automatic_hash][$viewport]) && is_array($known[$automatic_hash][$viewport]) ? $known[$automatic_hash][$viewport] : array();
                $locked[$viewport] = 'locked' === sanitize_key((string) ($record['learning_state'] ?? ''));
                if (!$locked[$viewport]) {
                    $all_locked = false;
                }
            }
            $automatic_data = array(
                'hash'   => $automatic_hash,
                'token'  => $this->create_lcp_observation_token($page_url, $automatic_hash),
                'locked' => $locked,
            );
        }

        if ($all_locked) {
            return;
        }

        $handle = 'ultracache-lcp-observer';
        if (!$this->ultracache_enqueue_frontend_js_helper($handle, 'lcp-observer.js', array(), false)) {
            return;
        }

        $this->ultracache_add_frontend_js_helper_data($handle, 'ultracacheLcpObserverConfig', array(
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'action'          => 'ultracache_lcp_observation',
            'pageUrl'         => $page_url,
            'mode'            => empty($selector_data) ? 'automatic' : 'manual',
            'expiresAt'       => absint($settings['lcp_frontend_discovery_expires_at'] ?? 0),
            'manualSelectors' => $selector_data,
            'automatic'       => $automatic_data,
        ));
    }

    public function handle_lcp_observation_ajax()
    {
        // The discovery token is public page-scoped telemetry, not user
        // authentication. Fixed rows and 2-of-3 locking bound its state.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public page-scoped LCP telemetry uses a bounded signed discovery token instead of a logged-in user nonce.
        $request = isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : array();
        $settings = $this->get_settings();
        if (!$this->is_lcp_frontend_discovery_active($settings)) {
            wp_send_json_error(array('message' => __('LCP frontend discovery is disabled or expired.', 'ultracache')), 403);
        }
        if (!$this->is_lcp_frontend_discovery_audience_allowed($settings)) {
            wp_send_json_error(array('message' => __('LCP frontend discovery is limited to administrators.', 'ultracache')), 403);
        }

        $raw_page_url = isset($request['pageUrl']) ? (string) $request['pageUrl'] : '';
        if ($this->lcp_observation_url_has_query_string($raw_page_url)) {
            wp_send_json_error(array('message' => __('LCP frontend discovery does not accept URLs with query parameters.', 'ultracache')), 400);
        }
        $page_url = $this->normalize_lcp_observation_page_url($raw_page_url);
        $selector_hash = isset($request['selectorHash']) ? strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $request['selectorHash'])) : '';
        $token = isset($request['token']) ? sanitize_text_field($request['token']) : '';
        $mode = isset($request['mode']) ? sanitize_key($request['mode']) : '';
        $viewport = isset($request['viewport']) ? sanitize_key($request['viewport']) : '';
        $resource_type = isset($request['resourceType']) ? sanitize_key($request['resourceType']) : '';
        $resource_url = isset($request['resourceUrl']) ? esc_url_raw($request['resourceUrl']) : '';
        $tag = isset($request['tag']) ? sanitize_key($request['tag']) : '';
        $element_selector = isset($request['elementSelector']) ? substr(sanitize_text_field($request['elementSelector']), 0, 512) : '';

        $source_url = ultracache_server_value('HTTP_ORIGIN');
        if ('' === $source_url) {
            $source_url = ultracache_server_value('HTTP_REFERER');
        }
        $source_host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' === $source_host || '' === $home_host || $source_host !== $home_host) {
            wp_send_json_error(array('message' => __('Invalid LCP observation origin.', 'ultracache')), 403);
        }

        if ('' === $page_url || 64 !== strlen($selector_hash) || !$this->validate_lcp_observation_token($token, $page_url, $selector_hash)) {
            wp_send_json_error(array('message' => __('Invalid LCP observation token.', 'ultracache')), 403);
        }
        if (!$this->is_lcp_observation_page_cacheable_url($page_url)) {
            wp_send_json_error(array('message' => __('LCP frontend discovery requires a cacheable URL without query parameters.', 'ultracache')), 400);
        }
        if (!in_array($viewport, array('mobile', 'tablet', 'desktop'), true) || !in_array($resource_type, array('text', 'image', 'background', 'poster', 'unknown'), true)) {
            wp_send_json_error(array('message' => __('Invalid LCP observation payload.', 'ultracache')), 400);
        }

        $selectors = isset($settings['manual_lcp_hero_selector_list']) && is_array($settings['manual_lcp_hero_selector_list'])
            ? $settings['manual_lcp_hero_selector_list']
            : array();
        $matched_selector = '';
        $observation_source = 'automatic';
        $row_selector_hash = $this->get_automatic_lcp_observation_selector_hash();
        if (!empty($selectors)) {
            if ('manual' !== $mode) {
                wp_send_json_error(array('message' => __('Manual LCP selectors take precedence over automatic discovery.', 'ultracache')), 409);
            }
            foreach ($selectors as $selector) {
                if ($this->get_lcp_observation_selector_hash($selector) === $selector_hash) {
                    $matched_selector = $this->normalize_manual_lcp_hero_selector($selector);
                    break;
                }
            }
            if ('' === $matched_selector) {
                wp_send_json_error(array('message' => __('Unknown manual LCP selector.', 'ultracache')), 400);
            }
            $observation_source = 'manual';
            $row_selector_hash = $selector_hash;
        } elseif ('automatic' !== $mode || !hash_equals($row_selector_hash, $selector_hash)) {
            wp_send_json_error(array('message' => __('Invalid automatic LCP discovery scope.', 'ultracache')), 400);
        }

        if (in_array($resource_type, array('image', 'background', 'poster'), true)) {
            $resource_url = $this->absolutize_public_resource_url($resource_url, $page_url);
            $resource_host = wp_parse_url($resource_url, PHP_URL_HOST);
            $resource_host = function_exists('ultracache_normalize_host')
                ? ultracache_normalize_host($resource_host)
                : strtolower(rtrim(trim((string) $resource_host), '.'));
            $allowed_hosts = $this->get_lcp_observation_allowed_resource_hosts($page_url);
            if ('' === $resource_url || !$this->is_lcp_candidate_image_url($resource_url) || '' === $resource_host || !in_array($resource_host, $allowed_hosts, true)) {
                wp_send_json_error(array('message' => __('Invalid LCP resource URL.', 'ultracache')), 400);
            }
        } else {
            $resource_url = '';
        }

        if ('automatic' === $observation_source) {
            if ('' === $element_selector) {
                $element_selector = '' !== $tag ? $tag : 'unknown';
            }
            $matched_selector = $element_selector;
        }

        $viewport_winner = $this->get_confirmed_lcp_observation_winner_for_page_viewport($page_url, $viewport);
        if ('confirmed' === sanitize_key((string) ($viewport_winner['status'] ?? '')) && 'locked' === sanitize_key((string) ($viewport_winner['learning_state'] ?? ''))) {
            wp_send_json_success(array(
                'stored'            => false,
                'changed'           => false,
                'status'            => 'locked',
                'observationCount'  => absint($viewport_winner['observation_count'] ?? 0),
                'confirmationCount' => absint($viewport_winner['confirmation_count'] ?? 2),
                'purged'            => false,
                'refreshQueued'     => false,
            ));
        }

        $existing = $this->get_lcp_observation_row($page_url, $row_selector_hash, $viewport);
        if ('confirmed' !== sanitize_key((string) ($existing['status'] ?? ''))) {
            $existing = array();
        }

        $candidate = $this->normalize_lcp_learning_candidate(array(
            'resource_type' => $resource_type,
            'resource_url'  => $resource_url,
            'element_tag'   => $tag,
            'selector'      => $matched_selector,
            'observed_at'   => time(),
        ));
        $evaluation = $this->evaluate_lcp_candidate_window($existing, $candidate);
        $active = isset($evaluation['active']) && is_array($evaluation['active']) ? $evaluation['active'] : $candidate;
        $previous_active = $this->get_lcp_candidate_from_row($existing);
        $active_changed = empty($previous_active) || !hash_equals(
            (string) ($previous_active['fingerprint'] ?? ''),
            (string) ($active['fingerprint'] ?? '')
        );
        $was_locked = 'locked' === sanitize_key((string) ($existing['learning_state'] ?? ''));
        $is_locked = 'locked' === sanitize_key((string) ($evaluation['learning_state'] ?? 'learning'));
        $lock_transition = !$was_locked && $is_locked;
        $now = time();
        $should_refresh = $active_changed || $lock_transition;
        $last_refresh_at = absint($existing['last_refresh_at'] ?? 0);

        $stored = $this->upsert_lcp_observation_row(array(
            'page_url'           => $page_url,
            'selector_hash'      => $row_selector_hash,
            'selector'           => (string) ($active['selector'] ?? $matched_selector),
            'viewport'           => $viewport,
            'resource_type'      => (string) ($active['resource_type'] ?? $resource_type),
            'resource_url'       => (string) ($active['resource_url'] ?? $resource_url),
            'element_tag'        => (string) ($active['element_tag'] ?? $tag),
            'observation_source' => $observation_source,
            'observation_count'  => max(1, absint($existing['observation_count'] ?? 0) + 1),
            'status'             => 'confirmed',
            'learning_state'     => $is_locked ? 'locked' : 'learning',
            'confirmation_count' => max(1, absint($evaluation['confirmation_count'] ?? 1)),
            'candidate_window'   => wp_json_encode($evaluation['window'] ?? array()),
            'locked_at'          => $is_locked ? max(1, absint($evaluation['locked_at'] ?? $now)) : 0,
            'last_refresh_at'    => $should_refresh ? $now : $last_refresh_at,
            'observed_at'        => $now,
        ));
        if (!$stored) {
            wp_send_json_error(array('message' => __('Unable to store the LCP discovery result.', 'ultracache')), 500);
        }

        $purged = false;
        $refresh_queued = false;
        if ($should_refresh) {
            if (method_exists($this, 'purge_page_cache_url_only')) {
                $purged = (bool) $this->purge_page_cache_url_only($page_url);
            } elseif (method_exists($this, 'purge_url')) {
                $purged = (bool) $this->purge_url($page_url);
            }
            if (class_exists('Ultra_Cache_WP') && method_exists('Ultra_Cache_WP', 'enqueue_lcp_refresh_url')) {
                $refresh_queued = (bool) Ultra_Cache_WP::enqueue_lcp_refresh_url($page_url);
            }
        }

        wp_send_json_success(array(
            'stored'            => true,
            'changed'           => $active_changed,
            'status'            => $is_locked ? 'locked' : 'learning',
            'observationCount'  => max(1, absint($existing['observation_count'] ?? 0) + 1),
            'confirmationCount' => max(1, absint($evaluation['confirmation_count'] ?? 1)),
            'observationSource' => $observation_source,
            'purged'            => $purged,
            'refreshQueued'     => $refresh_queued,
        ));
    }

}